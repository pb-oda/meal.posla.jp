<?php
/**
 * カート操作ログ API（認証なし）
 *
 * POST /api/customer/cart-event.php
 *
 * Body: { store_id, table_id?, session_token?, item_id, item_name, item_price?, action }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

require_method(['POST']);

$data = get_json_body();
$storeId      = $data['store_id'] ?? null;
$tableId      = $data['table_id'] ?? null;
$sessionToken = $data['session_token'] ?? null;
$itemId       = $data['item_id'] ?? null;
$itemName     = $data['item_name'] ?? null;
$itemPrice    = (int)($data['item_price'] ?? 0);
$action       = $data['action'] ?? null;

if (!$storeId || !$itemId || !$itemName || !$action) {
    json_error('MISSING_FIELDS', 'store_id, item_id, item_name, action は必須です', 400);
}

if ($action !== 'add' && $action !== 'remove') {
    json_error('INVALID_ACTION', 'action は add または remove のみ有効です', 400);
}

$pdo = get_db();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO cart_events (store_id, table_id, session_token, item_id, item_name, item_price, action)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$storeId, $tableId, $sessionToken, $itemId, $itemName, $itemPrice, $action]);
} catch (Exception $e) {
    error_log('cart-event INSERT failed: ' . $e->getMessage());
    json_error('CART_LOG_FAILED', 'カートログ記録に失敗しました', 503);
}

json_response(['ok' => true]);
