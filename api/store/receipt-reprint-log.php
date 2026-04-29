<?php
/**
 * 領収書再印刷ログ API
 *
 * POST /api/store/receipt-reprint-log.php
 * Body: { store_id, receipt_id }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);
$user = require_auth();
$pdo = get_db();

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$receiptId = isset($data['receipt_id']) ? trim((string)$data['receipt_id']) : '';

if (!$storeId || $receiptId === '') {
    json_error('MISSING_FIELDS', 'store_id と receipt_id は必須です', 400);
}
require_store_access($storeId);

try {
    $stmt = $pdo->prepare(
        'SELECT id, payment_id, receipt_number, receipt_type, addressee, total_amount, issued_at
           FROM receipts
          WHERE id = ? AND store_id = ? AND tenant_id = ?
          LIMIT 1'
    );
    $stmt->execute([$receiptId, $storeId, $user['tenant_id']]);
    $receipt = $stmt->fetch();
} catch (PDOException $e) {
    json_error('MIGRATION', '領収書テーブルが未作成です', 500);
}

if (!$receipt) {
    json_error('NOT_FOUND', '領収書が見つかりません', 404);
}

write_audit_log(
    $pdo,
    $user,
    $storeId,
    'receipt_reprint',
    'receipt',
    $receiptId,
    null,
    [
        'payment_id' => $receipt['payment_id'],
        'receipt_number' => $receipt['receipt_number'],
        'receipt_type' => $receipt['receipt_type'],
        'addressee' => $receipt['addressee'],
        'total_amount' => (int)$receipt['total_amount'],
        'issued_at' => $receipt['issued_at'],
    ],
    '領収書を再印刷'
);

json_response([
    'logged' => true,
    'receiptId' => $receiptId,
    'receiptNumber' => $receipt['receipt_number'],
]);
