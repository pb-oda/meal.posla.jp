<?php
/**
 * POSLA管理者 初期セットアップAPI
 *
 * POST /api/posla/setup.php
 * Body: { "email": "...", "password": "...", "display_name": "..." }
 *
 * posla_admins が0件の場合のみ実行可能。
 * ※ 本番環境では初期管理者作成後、このファイルの削除を推奨。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/password-policy.php';

require_method(['POST']);

$pdo = get_db();

// 既に管理者が存在する場合は拒否
$stmt = $pdo->prepare('SELECT COUNT(*) FROM posla_admins');
$stmt->execute();
$count = (int)$stmt->fetchColumn();

if ($count > 0) {
    json_error('ALREADY_SETUP', '初期セットアップは完了済みです', 403);
}

$input       = get_json_body();
$email       = trim($input['email'] ?? '');
$password    = $input['password'] ?? '';
$displayName = trim($input['display_name'] ?? '');

if (empty($email)) {
    json_error('MISSING_EMAIL', 'メールアドレスは必須です', 400);
}
if (empty($password)) {
    json_error('MISSING_PASSWORD', 'パスワードは必須です', 400);
}
validate_password_strength($password);
if (empty($displayName)) {
    json_error('MISSING_NAME', '表示名は必須です', 400);
}

$id = generate_uuid();
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    'INSERT INTO posla_admins (id, email, password_hash, display_name) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$id, $email, $hash, $displayName]);

json_response([
    'admin' => [
        'id'          => $id,
        'email'       => $email,
        'displayName' => $displayName,
    ],
    'message' => '初期管理者を作成しました',
], 201);
