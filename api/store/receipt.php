<?php
/**
 * L-5: 領収書/インボイス レコード管理 API
 *
 * POST /api/store/receipt.php
 *   body: { payment_id, receipt_type: "receipt"|"invoice", addressee: "株式会社XX"(任意) }
 *   → receipts テーブルにレコード作成 + 印刷用データを返却
 *
 * GET /api/store/receipt.php?id=xxx&detail=1
 *   → 領収書詳細（receipt + items + store + payment）再印刷用
 *
 * GET /api/store/receipt.php?payment_id=xxx
 *   → payment_id に紐づく領収書一覧
 *
 * GET /api/store/receipt.php?store_id=xxx&date=YYYY-MM-DD
 *   → 日付指定で領収書一覧
 *
 * 認証: staff 以上
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$method = require_method(['GET', 'POST']);
$user = require_auth();
$pdo = get_db();
$tenantId = $user['tenant_id'];

// ============================================================
// GET: 領収書取得
// ============================================================
if ($method === 'GET') {

    // ── 詳細取得（再印刷用） ──
    if (!empty($_GET['id']) && !empty($_GET['detail'])) {
        $receiptId = $_GET['id'];

        // receipts レコード取得
        $stmt = $pdo->prepare(
            'SELECT id, payment_id, store_id, receipt_number, receipt_type, addressee,
                    subtotal_10, tax_10, subtotal_8, tax_8, total_amount, issued_at
             FROM receipts WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$receiptId, $tenantId]);
        $receipt = $stmt->fetch();
        if (!$receipt) {
            json_error('RECEIPT_NOT_FOUND', '領収書が見つかりません', 404);
        }
        require_store_access($receipt['store_id']);

        // payment 取得（明細用）
        $paymentId = $receipt['payment_id'];
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            json_error('PAYMENT_NOT_FOUND', '支払い情報が見つかりません', 404);
        }

        // 明細取得（paid_items → order_items フォールバック）
        $paidItems = json_decode($payment['paid_items'], true);
        if (empty($paidItems)) {
            $orderIds = json_decode($payment['order_ids'], true);
            if (!empty($orderIds)) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $stmt = $pdo->prepare(
                    "SELECT name, price, qty, options FROM order_items
                     WHERE order_id IN ($placeholders) AND status != 'cancelled'"
                );
                $stmt->execute($orderIds);
                $paidItems = $stmt->fetchAll();
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

        // 店舗設定取得
        $storeId = $receipt['store_id'];
        $stmt = $pdo->prepare(
            'SELECT receipt_store_name, receipt_address, receipt_phone, receipt_footer,
                    registration_number, business_name
             FROM store_settings WHERE store_id = ?'
        );
        $stmt->execute([$storeId]);
        $settings = $stmt->fetch();
        if (!$settings) {
            $settings = [
                'receipt_store_name' => '', 'receipt_address' => '',
                'receipt_phone' => '', 'receipt_footer' => '',
                'registration_number' => null, 'business_name' => null,
            ];
        }

        json_response([
            'receipt' => [
                'id'             => $receipt['id'],
                'receipt_number' => $receipt['receipt_number'],
                'receipt_type'   => $receipt['receipt_type'],
                'addressee'      => $receipt['addressee'],
                'total_amount'   => (int)$receipt['total_amount'],
                'subtotal_10'    => (int)$receipt['subtotal_10'],
                'tax_10'         => (int)$receipt['tax_10'],
                'subtotal_8'     => (int)$receipt['subtotal_8'],
                'tax_8'          => (int)$receipt['tax_8'],
                'issued_at'      => $receipt['issued_at'],
            ],
            'store' => [
                'receipt_store_name'  => $settings['receipt_store_name'] ?: '',
                'receipt_address'     => $settings['receipt_address'] ?: '',
                'receipt_phone'       => $settings['receipt_phone'] ?: '',
                'receipt_footer'      => $settings['receipt_footer'] ?: '',
                'registration_number' => $settings['registration_number'],
                'business_name'       => $settings['business_name'],
            ],
            'items' => $responseItems,
            'payment' => [
                'payment_method'  => $payment['payment_method'],
                'received_amount' => isset($payment['received_amount']) ? (int)$payment['received_amount'] : null,
                'change_amount'   => isset($payment['change_amount']) ? (int)$payment['change_amount'] : null,
                'paid_at'         => $payment['paid_at'],
            ],
        ]);
    }

    // ── payment_id で取得 ──
    if (!empty($_GET['payment_id'])) {
        $paymentId = $_GET['payment_id'];

        // payment の store_id を取得してアクセス権限確認
        $pStmt = $pdo->prepare('SELECT store_id FROM payments WHERE id = ?');
        $pStmt->execute([$paymentId]);
        $pRow = $pStmt->fetch();
        if (!$pRow) {
            json_error('PAYMENT_NOT_FOUND', '支払い情報が見つかりません', 404);
        }
        require_store_access($pRow['store_id']);

        $stmt = $pdo->prepare(
            'SELECT id, receipt_number, receipt_type, addressee, total_amount, issued_at
             FROM receipts WHERE payment_id = ? AND tenant_id = ?
             ORDER BY issued_at DESC'
        );
        $stmt->execute([$paymentId, $tenantId]);
        $receipts = $stmt->fetchAll();

        json_response(['receipts' => $receipts]);
    }

    // ── 日付指定で一覧 ──
    if (!empty($_GET['store_id'])) {
        $storeId = $_GET['store_id'];
        require_store_access($storeId);

        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_error('INVALID_DATE', '日付形式が不正です (YYYY-MM-DD)', 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, receipt_number, receipt_type, addressee, total_amount, issued_at
             FROM receipts WHERE store_id = ? AND tenant_id = ? AND DATE(issued_at) = ?
             ORDER BY issued_at DESC'
        );
        $stmt->execute([$storeId, $tenantId, $date]);
        $receipts = $stmt->fetchAll();

        json_response(['receipts' => $receipts, 'date' => $date]);
    }

    json_error('MISSING_PARAMS', 'payment_id または store_id+date を指定してください', 400);
}

// ============================================================
// POST: 領収書発行（レコード作成 + 印刷用データ返却）
// ============================================================
if ($method === 'POST') {
    $data = get_json_body();
    $paymentId   = $data['payment_id'] ?? null;
    $receiptType = $data['receipt_type'] ?? 'receipt';
    $addressee   = $data['addressee'] ?? null;

    if (!$paymentId) {
        json_error('MISSING_PAYMENT', 'payment_id は必須です', 400);
    }
    if (!in_array($receiptType, ['receipt', 'invoice'])) {
        json_error('INVALID_TYPE', 'receipt_type は receipt または invoice を指定してください', 400);
    }

    // ── 1. payment 取得 ──
    $stmt = $pdo->prepare(
        'SELECT p.*, s.tenant_id
         FROM payments p
         JOIN stores s ON s.id = p.store_id
         WHERE p.id = ? AND s.tenant_id = ?'
    );
    $stmt->execute([$paymentId, $tenantId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        json_error('PAYMENT_NOT_FOUND', '支払い情報が見つかりません', 404);
    }

    $storeId = $payment['store_id'];
    require_store_access($storeId);

    // ── 2. 明細取得 (paid_items から) ──
    $paidItems = json_decode($payment['paid_items'], true);
    if (empty($paidItems)) {
        // paid_items が無い場合は order_items から取得
        $orderIds = json_decode($payment['order_ids'], true);
        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT name, price, qty, options FROM order_items
                 WHERE order_id IN ($placeholders) AND status != 'cancelled'"
            );
            $stmt->execute($orderIds);
            $paidItems = $stmt->fetchAll();
        }
    }

    if (empty($paidItems)) {
        json_error('NO_ITEMS', '明細がありません', 400);
    }

    // ── 3. 店舗設定取得 ──
    $stmt = $pdo->prepare(
        'SELECT receipt_store_name, receipt_address, receipt_phone, receipt_footer,
                registration_number, business_name, tax_rate
         FROM store_settings WHERE store_id = ?'
    );
    $stmt->execute([$storeId]);
    $settings = $stmt->fetch();

    if (!$settings) {
        $settings = [
            'receipt_store_name' => '', 'receipt_address' => '',
            'receipt_phone' => '', 'receipt_footer' => '',
            'registration_number' => null, 'business_name' => null,
            'tax_rate' => '10.00'
        ];
    }

    // インボイスの場合、登録番号は必須
    if ($receiptType === 'invoice' && empty($settings['registration_number'])) {
        json_error('NO_REG_NUMBER', '適格簡易請求書の発行には登録番号の設定が必要です', 400);
    }

    // ── 4. 領収書番号の採番（トランザクション内） ──
    $pdo->beginTransaction();
    try {
        $today = date('Ymd');

        // 当日の最大連番を取得（FOR UPDATE でロック）
        $stmt = $pdo->prepare(
            "SELECT receipt_number FROM receipts
             WHERE store_id = ? AND receipt_number LIKE ?
             ORDER BY receipt_number DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$storeId, 'R-' . $today . '-%']);
        $lastRow = $stmt->fetch();

        $seq = 1;
        if ($lastRow) {
            $parts = explode('-', $lastRow['receipt_number']);
            $lastSeq = (int)end($parts);
            $seq = $lastSeq + 1;
        }

        $receiptNumber = 'R-' . $today . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

        // ── 5. DB INSERT（pdf_path は NULL） ──
        $receiptId = generate_uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO receipts
             (id, tenant_id, store_id, payment_id, receipt_number, receipt_type, addressee,
              subtotal_10, tax_10, subtotal_8, tax_8, total_amount, pdf_path, issued_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NOW())'
        );
        $stmt->execute([
            $receiptId,
            $tenantId,
            $storeId,
            $paymentId,
            $receiptNumber,
            $receiptType,
            $addressee,
            (int)$payment['subtotal_10'],
            (int)$payment['tax_10'],
            (int)$payment['subtotal_8'],
            (int)$payment['tax_8'],
            (int)$payment['total_amount'],
        ]);

        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        error_log('[L-5 receipt] ' . $e->getMessage());
        json_error('GENERATE_FAILED', '領収書の作成に失敗しました: ' . $e->getMessage(), 500);
    }

    // ── 6. 印刷用データをレスポンスに含める ──
    // items の整形（options が JSON文字列の場合をデコード）
    $responseItems = [];
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

    json_response([
        'receipt' => [
            'id'             => $receiptId,
            'receipt_number' => $receiptNumber,
            'receipt_type'   => $receiptType,
            'addressee'      => $addressee,
            'total_amount'   => (int)$payment['total_amount'],
            'subtotal_10'    => (int)$payment['subtotal_10'],
            'tax_10'         => (int)$payment['tax_10'],
            'subtotal_8'     => (int)$payment['subtotal_8'],
            'tax_8'          => (int)$payment['tax_8'],
            'issued_at'      => date('Y-m-d H:i:s'),
        ],
        'store' => [
            'receipt_store_name'  => $settings['receipt_store_name'] ?: '',
            'receipt_address'     => $settings['receipt_address'] ?: '',
            'receipt_phone'       => $settings['receipt_phone'] ?: '',
            'receipt_footer'      => $settings['receipt_footer'] ?: '',
            'registration_number' => $settings['registration_number'],
            'business_name'       => $settings['business_name'],
        ],
        'items' => $responseItems,
        'payment' => [
            'payment_method'  => $payment['payment_method'],
            'received_amount' => isset($payment['received_amount']) ? (int)$payment['received_amount'] : null,
            'change_amount'   => isset($payment['change_amount']) ? (int)$payment['change_amount'] : null,
            'paid_at'         => $payment['paid_at'],
        ],
    ]);
}
