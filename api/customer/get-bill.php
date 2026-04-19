<?php
/**
 * L-8: セルフレジ — 未払い注文一覧取得
 *
 * GET /api/customer/get-bill.php?store_id=xxx&table_id=xxx&session_token=xxx
 *
 * 認証不要（session_token で検証）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

require_method(['GET']);

$storeId      = $_GET['store_id'] ?? null;
$tableId      = $_GET['table_id'] ?? null;
$sessionToken = $_GET['session_token'] ?? null;

if (!$storeId || !$tableId || !$sessionToken) {
    json_error('MISSING_FIELDS', 'store_id, table_id, session_token は必須です', 400);
}

$pdo = get_db();

// ── session_token 検証 ──
$stmt = $pdo->prepare(
    'SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ? AND is_active = 1'
);
$stmt->execute([$tableId, $storeId]);
$tableRow = $stmt->fetch();

if (!$tableRow || !$tableRow['session_token'] || !hash_equals($tableRow['session_token'], (string)$sessionToken)) {
    json_error('INVALID_SESSION', 'セッションが無効です', 403);
}
if ($tableRow['session_token_expires_at'] && strtotime($tableRow['session_token_expires_at']) < time()) {
    json_error('INVALID_SESSION', 'セッションの有効期限が切れています', 403);
}

// ── 未払い注文を取得 ──
$stmt = $pdo->prepare(
    'SELECT id, items, total_amount, status, created_at
     FROM orders
     WHERE table_id = ? AND store_id = ? AND session_token = ?
       AND status NOT IN (\'paid\', \'cancelled\')
     ORDER BY created_at ASC'
);
$stmt->execute([$tableId, $storeId, $sessionToken]);
$orders = $stmt->fetchAll();

if (empty($orders)) {
    json_error('NO_UNPAID_ORDERS', '未払いの注文がありません', 404);
}

// ── store_settings から self_checkout_enabled + payment_gateway を取得 ──
$selfCheckoutEnabled = false;
$paymentAvailable = false;
try {
    $stmt = $pdo->prepare(
        'SELECT ss.self_checkout_enabled
         FROM store_settings ss
         WHERE ss.store_id = ?'
    );
    $stmt->execute([$storeId]);
    $ssRow = $stmt->fetch();
    $selfCheckoutEnabled = $ssRow ? (bool)(int)$ssRow['self_checkout_enabled'] : false;
} catch (PDOException $e) {
    // self_checkout_enabled カラム未存在時は false
    $selfCheckoutEnabled = false;
}

// payment_gateway をテナントから取得
$stmt = $pdo->prepare(
    'SELECT t.payment_gateway
     FROM stores s
     JOIN tenants t ON t.id = s.tenant_id
     WHERE s.id = ?'
);
$stmt->execute([$storeId]);
$gwRow = $stmt->fetch();
$paymentGateway = $gwRow ? ($gwRow['payment_gateway'] ?? 'none') : 'none';
$paymentAvailable = $selfCheckoutEnabled && $paymentGateway !== 'none';

// ── 税率計算 ──
$subtotal10 = 0;
$tax10 = 0;
$subtotal8 = 0;
$tax8 = 0;
$totalAmount = 0;

$responseOrders = [];
foreach ($orders as $order) {
    $totalAmount += (int)$order['total_amount'];

    $items = json_decode($order['items'] ?? '[]', true);
    if (!is_array($items)) $items = [];

    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? 1);
        $price = (int)($item['price'] ?? 0);
        $lineTotal = $price * $qty;
        $taxRate = isset($item['taxRate']) ? (int)$item['taxRate'] : 10;

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

    $responseOrders[] = [
        'id'           => $order['id'],
        'items'        => $items,
        'total_amount' => (int)$order['total_amount'],
        'created_at'   => $order['created_at'],
    ];
}

json_response([
    'orders'  => $responseOrders,
    'summary' => [
        'subtotal_10' => $subtotal10,
        'tax_10'      => $tax10,
        'subtotal_8'  => $subtotal8,
        'tax_8'       => $tax8,
        'total'       => $totalAmount,
    ],
    'self_checkout_enabled' => $selfCheckoutEnabled,
    'payment_available'     => $paymentAvailable,
]);
