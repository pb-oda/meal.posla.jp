<?php
/**
 * セッショントークン検証 API（認証なし・軽量）
 *
 * GET /api/customer/validate-token.php?store_id=xxx&table_id=xxx&token=xxx
 *
 * メニュー画面がポーリングで呼び出し、
 * 会計済み（トークン変更）を検出する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

require_method(['GET']);

$storeId = $_GET['store_id'] ?? null;
$tableId = $_GET['table_id'] ?? null;
$token   = $_GET['token'] ?? null;

if (!$storeId || !$tableId || !$token) {
    json_response(['valid' => false]);
}

$pdo = get_db();

$stmt = $pdo->prepare(
    'SELECT session_token FROM tables WHERE id = ? AND store_id = ? AND is_active = 1'
);
$stmt->execute([$tableId, $storeId]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['valid' => false]);
}

$valid = ($row['session_token'] === $token);

json_response(['valid' => $valid]);
