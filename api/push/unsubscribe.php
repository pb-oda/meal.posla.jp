<?php
/**
 * Web Push 購読解除 API (PWA Phase 2a)
 *
 * POST /api/push/unsubscribe.php
 *   Body: { endpoint: "..." }
 *
 * 認証必須。endpoint の hash で対象を特定し、自分の購読 (user_id 一致) のみ無効化。
 * 物理削除はせず enabled=0 + revoked_at にする (履歴保全)。
 *
 * manager / owner が他スタッフの購読を強制解除する用途は今回のスコープ外。
 * (Phase 2b で必要なら追加 API を別途用意)。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/push.php';

require_method(['POST']);
$user = require_auth();
$pdo = get_db();

$data = get_json_body();
$endpoint = isset($data['endpoint']) ? (string)$data['endpoint'] : '';

if ($endpoint === '') {
    json_error('MISSING_FIELDS', 'endpoint は必須です', 400);
}

$hash = hash('sha256', $endpoint);

try {
    $count = push_disable_subscription($pdo, $hash, $user['user_id']);
} catch (PDOException $e) {
    json_error('DB_ERROR', '購読解除に失敗しました', 500);
}

json_response([
    'disabled' => $count,
]);
