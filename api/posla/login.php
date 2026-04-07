<?php
/**
 * POSLA管理者 ログインAPI
 *
 * POST /api/posla/login.php
 * Body: { "email": "...", "password": "..." }
 */

require_once __DIR__ . '/auth-helper.php';

require_method(['POST']);

$input    = get_json_body();
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    json_error('MISSING_FIELDS', 'メールアドレスとパスワードは必須です', 400);
}

$pdo = get_db();

$stmt = $pdo->prepare(
    'SELECT id, email, password_hash, display_name, is_active
     FROM posla_admins
     WHERE email = ? AND is_active = 1'
);
$stmt->execute([$email]);
$admin = $stmt->fetch();

if (!$admin || !password_verify($password, $admin['password_hash'])) {
    json_error('INVALID_CREDENTIALS', 'メールアドレスまたはパスワードが正しくありません', 401);
}

// セッション設定
start_posla_session();
session_regenerate_id(true);
$_SESSION['posla_admin_id']           = $admin['id'];
$_SESSION['posla_admin_email']        = $admin['email'];
$_SESSION['posla_admin_display_name'] = $admin['display_name'];
$_SESSION['posla_admin_login_time']   = time();

// last_login_at 更新
$pdo->prepare('UPDATE posla_admins SET last_login_at = NOW() WHERE id = ?')
    ->execute([$admin['id']]);

json_response([
    'admin' => [
        'id'          => $admin['id'],
        'email'       => $admin['email'],
        'displayName' => $admin['display_name'],
    ]
]);
