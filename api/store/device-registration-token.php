<?php
/**
 * F-DA1: デバイス登録トークン生成 API
 *
 * POST /api/store/device-registration-token.php
 * Body: { store_id, display_name, visible_tools, expires_hours? }
 *
 * manager 以上のみ。ワンタイム登録トークンを発行。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['POST']);
$user = require_role('manager');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) json_error('VALIDATION', 'リクエストボディが不正です', 400);

$storeId      = $data['store_id'] ?? '';
$displayName  = trim($data['display_name'] ?? '');
$visibleTools = trim($data['visible_tools'] ?? 'kds');
$expiresHours = (int)($data['expires_hours'] ?? 24);

if (!$storeId || !$displayName) {
    json_error('VALIDATION', 'store_id と display_name は必須です', 400);
}
if ($expiresHours < 1 || $expiresHours > 168) {
    $expiresHours = 24; // 1時間〜7日、デフォルト24時間
}

require_store_access($storeId);

$pdo = get_db();

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM device_registration_tokens LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION_REQUIRED', 'デバイス登録トークン機能のマイグレーションが未適用です', 500);
}

// トークン生成
$plainToken = 'reg_' . bin2hex(random_bytes(24)); // 52文字 (4 + 48)
$tokenHash = hash('sha256', $plainToken);
$tokenId = generate_uuid();

$pdo->prepare(
    'INSERT INTO device_registration_tokens
     (id, tenant_id, store_id, token_hash, display_name, visible_tools, created_by, expires_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))'
)->execute([
    $tokenId,
    $user['tenant_id'],
    $storeId,
    $tokenHash,
    $displayName,
    $visibleTools,
    $user['user_id'],
    $expiresHours
]);

// 擬似本番では canonical URL を返す
$setupUrl = app_url('/admin/device-setup.html') . '?token=' . urlencode($plainToken);

json_response([
    'token'       => $plainToken,
    'displayName' => $displayName,
    'setupUrl'    => $setupUrl,
    'expiresHours' => $expiresHours,
]);
