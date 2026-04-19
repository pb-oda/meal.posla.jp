<?php
/**
 * L-9 テーブル開放認証 — スタッフホワイトリスト方式
 *
 * POST { store_id, table_id, expires_minutes? }
 *  - スタッフが空きテーブルを開放 (5分有効のワンタイム next_session_token を発行)
 *  - 客が QR を読むと、このトークンと照合してセッション作成、即消費
 *
 * DELETE ?store_id=xxx&table_id=xxx
 *  - 開放を取り消し (誤開放のリカバリー)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';

$method = require_method(['POST', 'DELETE']);
$user = require_role('staff');
$pdo = get_db();

if ($method === 'POST') {
    $body = get_json_body();
    $storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
    $tableId = isset($body['table_id']) ? trim($body['table_id']) : '';
    $expiresMin = isset($body['expires_minutes']) ? max(1, min(30, (int)$body['expires_minutes'])) : 5;
    if (!$storeId || !$tableId) json_error('MISSING_PARAM', 'store_id と table_id が必要です', 400);
    require_store_access($storeId);

    // テーブル存在確認
    $tStmt = $pdo->prepare('SELECT id, table_code FROM tables WHERE id = ? AND store_id = ? AND is_active = 1');
    $tStmt->execute([$tableId, $storeId]);
    $tbl = $tStmt->fetch();
    if (!$tbl) json_error('TABLE_NOT_FOUND', 'テーブルが見つかりません', 404);

    // 既に使用中なら拒否
    $sStmt = $pdo->prepare("SELECT id FROM table_sessions WHERE table_id = ? AND store_id = ? AND status NOT IN ('paid', 'closed') LIMIT 1");
    $sStmt->execute([$tableId, $storeId]);
    if ($sStmt->fetch()) {
        json_error('TABLE_IN_USE', 'テーブルは使用中です', 409);
    }

    // ワンタイムトークン発行
    $token = bin2hex(random_bytes(24));
    $pdo->prepare(
        'UPDATE tables SET next_session_token = ?, next_session_token_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), next_session_opened_by_user_id = ?, next_session_opened_at = NOW() WHERE id = ? AND store_id = ?'
    )->execute([$token, $expiresMin, $user['user_id'], $tableId, $storeId]);

    json_response([
        'ok' => true,
        'table_id' => $tableId,
        'table_code' => $tbl['table_code'],
        'expires_minutes' => $expiresMin,
        'expires_at' => date('Y-m-d H:i:s', time() + $expiresMin * 60),
    ]);
}

if ($method === 'DELETE') {
    $storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
    $tableId = isset($_GET['table_id']) ? trim($_GET['table_id']) : '';
    if (!$storeId || !$tableId) json_error('MISSING_PARAM', 'store_id と table_id が必要です', 400);
    require_store_access($storeId);

    $pdo->prepare(
        'UPDATE tables SET next_session_token = NULL, next_session_token_expires_at = NULL, next_session_opened_by_user_id = NULL, next_session_opened_at = NULL WHERE id = ? AND store_id = ?'
    )->execute([$tableId, $storeId]);
    json_response(['ok' => true]);
}
