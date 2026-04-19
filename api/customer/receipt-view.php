<?php
/**
 * L-8: セルフレジ — 顧客向け領収書データ取得
 *
 * GET /api/customer/receipt-view.php?payment_id=xxx&store_id=xxx
 *
 * 認証不要（payment_id + store_id + 30分以内の制限で安全性担保）
 * 決済後にお客さんのスマホで領収書を表示するためのAPI。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

require_method(['GET']);

$paymentId = $_GET['payment_id'] ?? null;
$storeId   = $_GET['store_id'] ?? null;

if (!$paymentId || !$storeId) {
    json_error('MISSING_FIELDS', 'payment_id, store_id は必須です', 400);
}

$pdo = get_db();

// ── payments テーブルから取得 ──
$stmt = $pdo->prepare(
    'SELECT * FROM payments WHERE id = ? AND store_id = ?'
);
$stmt->execute([$paymentId, $storeId]);
$payment = $stmt->fetch();

if (!$payment) {
    json_error('PAYMENT_NOT_FOUND', '支払い情報が見つかりません', 404);
}

// ── 30分以内チェック ──
$paidAt = strtotime($payment['paid_at']);
if (!$paidAt || (time() - $paidAt) > 1800) {
    json_error('RECEIPT_EXPIRED', '領収書の表示期限が切れています。スタッフにお声がけください', 403);
}

// ── 明細取得（paid_items → order_items フォールバック） ──
$paidItems = json_decode($payment['paid_items'], true);
if (empty($paidItems)) {
    $orderIds = json_decode($payment['order_ids'], true);
    if (!empty($orderIds)) {
        // orders.items から品目を取得
        // S3 #15: store_id 境界 — 同店舗の orders のみ取得
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT items FROM orders WHERE id IN ($placeholders) AND store_id = ?"
        );
        $stmt->execute(array_merge($orderIds, [$storeId]));
        $orderRows = $stmt->fetchAll();
        $paidItems = [];
        foreach ($orderRows as $oRow) {
            $oItems = json_decode($oRow['items'] ?? '[]', true);
            if (is_array($oItems)) {
                $paidItems = array_merge($paidItems, $oItems);
            }
        }
    }
}

// items 整形
$responseItems = [];
if (!empty($paidItems)) {
    foreach ($paidItems as $item) {
        $ri = [
            'name'    => $item['name'] ?? '',
            'qty'     => (int)($item['qty'] ?? 1),
            'price'   => (int)($item['price'] ?? 0),
            'taxRate' => (int)($item['taxRate'] ?? 10),
        ];
        if (!empty($item['options'])) {
            $opts = is_string($item['options']) ? json_decode($item['options'], true) : $item['options'];
            if (is_array($opts)) {
                $ri['options'] = $opts;
            }
        }
        $responseItems[] = $ri;
    }
}

// ── 税率別内訳を計算 ──
$subtotal10 = 0;
$tax10 = 0;
$subtotal8 = 0;
$tax8 = 0;
$totalAmount = (int)$payment['total_amount'];

foreach ($responseItems as $item) {
    $lineTotal = $item['price'] * $item['qty'];
    $taxRate = $item['taxRate'];

    if ($taxRate === 8) {
        $sub = (int)floor($lineTotal / 1.08);
        $subtotal8 += $sub;
        $tax8 += $lineTotal - $sub;
    } else {
        $sub = (int)floor($lineTotal / 1.10);
        $subtotal10 += $sub;
        $tax10 += $lineTotal - $sub;
    }
}

// ── store_settings から領収書表示情報を取得 ──
$stmt = $pdo->prepare(
    'SELECT receipt_store_name, receipt_address, receipt_phone, receipt_footer,
            registration_number, business_name
     FROM store_settings WHERE store_id = ?'
);
$stmt->execute([$storeId]);
$settings = $stmt->fetch();
if (!$settings) {
    // store名をフォールバックで取得
    $stmt = $pdo->prepare('SELECT name FROM stores WHERE id = ?');
    $stmt->execute([$storeId]);
    $sRow = $stmt->fetch();
    $settings = [
        'receipt_store_name' => $sRow ? $sRow['name'] : '',
        'receipt_address' => '',
        'receipt_phone' => '',
        'receipt_footer' => '',
        'registration_number' => null,
        'business_name' => null,
    ];
}

json_response([
    'store' => [
        'receipt_store_name'  => $settings['receipt_store_name'] ?: '',
        'receipt_address'     => $settings['receipt_address'] ?: '',
        'receipt_phone'       => $settings['receipt_phone'] ?: '',
        'receipt_footer'      => $settings['receipt_footer'] ?: '',
        'registration_number' => $settings['registration_number'],
        'business_name'       => $settings['business_name'],
    ],
    'payment' => [
        'payment_id'     => $payment['id'],
        'total_amount'   => $totalAmount,
        'payment_method' => $payment['payment_method'],
        'paid_at'        => $payment['paid_at'],
    ],
    'items' => $responseItems,
    'summary' => [
        'subtotal_10' => $subtotal10,
        'tax_10'      => $tax10,
        'subtotal_8'  => $subtotal8,
        'tax_8'       => $tax8,
        'total'       => $totalAmount,
    ],
]);
