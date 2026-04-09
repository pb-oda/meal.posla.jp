<?php
/**
 * 満足度評価送信 API（認証なし）
 *
 * POST /api/customer/satisfaction-rating.php
 *
 * Body: { store_id, table_id, session_token, order_id, order_item_id, menu_item_id, item_name, rating }
 *
 * N-4: 品目提供後にお客様が5段階評価を送信
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

require_method(['POST']);

$data = get_json_body();
$storeId      = $data['store_id']      ?? null;
$tableId      = $data['table_id']      ?? null;
$sessionToken = $data['session_token'] ?? null;
$orderId      = $data['order_id']      ?? null;
$orderItemId  = $data['order_item_id'] ?? null;
$menuItemId   = $data['menu_item_id']  ?? null;
$itemName     = $data['item_name']     ?? null;
$rating       = isset($data['rating']) ? (int)$data['rating'] : null;

if (!$storeId || !$tableId || !$sessionToken || !$orderId || $rating === null) {
    json_error('MISSING_FIELDS', '必須フィールドが不足しています', 400);
}

if ($rating < 1 || $rating > 5) {
    json_error('INVALID_RATING', '評価は1〜5の整数で指定してください', 400);
}

$pdo = get_db();

// セッショントークン検証
$stmt = $pdo->prepare('SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ?');
$stmt->execute([$tableId, $storeId]);
$tokenRow = $stmt->fetch();
if ($tokenRow) {
    $dbToken   = $tokenRow['session_token'];
    $expiresAt = $tokenRow['session_token_expires_at'];
    if (!$dbToken || $sessionToken !== $dbToken) {
        json_error('INVALID_SESSION', 'セッションが無効です', 403);
    }
    if ($expiresAt && strtotime($expiresAt) < time()) {
        json_error('INVALID_SESSION', 'セッションが期限切れです', 403);
    }
}

// 重複チェック: 同一 order_item_id + session_token
$existingId = null;
if ($orderItemId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM satisfaction_ratings
             WHERE order_item_id = ? AND session_token = ? AND store_id = ?'
        );
        $stmt->execute([$orderItemId, $sessionToken, $storeId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $existingId = $existing['id'];
        }
    } catch (PDOException $e) {
        // テーブル未作成 → INSERT で失敗する（下記 catch で処理）
        error_log('[P1-12][api/customer/satisfaction-rating.php:65] check_existing_rating: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }
}

try {
    if ($existingId) {
        // UPDATE（再評価）
        $stmt = $pdo->prepare(
            'UPDATE satisfaction_ratings SET rating = ?, created_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$rating, $existingId]);
        json_response(['id' => $existingId]);
    } else {
        // INSERT
        $id = generate_uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO satisfaction_ratings (id, store_id, order_id, order_item_id, menu_item_id, item_name, rating, session_token, table_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $id, $storeId, $orderId, $orderItemId,
            $menuItemId, $itemName ? mb_substr($itemName, 0, 200) : null,
            $rating, $sessionToken, $tableId
        ]);
        json_response(['id' => $id], 201);
    }
} catch (PDOException $e) {
    json_error('DB_ERROR', 'evaluation テーブルが未作成の可能性があります', 500);
}
