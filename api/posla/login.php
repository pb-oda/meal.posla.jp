<?php
/**
 * POSLA管理者 ログインAPI
 *
 * POST /api/posla/login.php
 * Body: { "email": "...", "password": "..." }
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['POST']);

$poslaLoginRateKey = 'posla-login';
if (rate_limit_exceeded($poslaLoginRateKey, 10, 300)) {
    json_error('RATE_LIMITED', 'ログイン試行回数の上限に達しました。5分後に再度お試しください。', 429);
}

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
    check_rate_limit($poslaLoginRateKey, 10, 300);
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

// P1-6d: posla_admin_sessions に記録（パスワード変更時の旧セッション無効化のため）
// 失敗してもログイン処理は止めない（既存挙動を壊さない）
try {
    $sessionId = session_id();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $pdo->prepare(
        'INSERT INTO posla_admin_sessions
         (admin_id, session_id, ip_address, user_agent, login_at, last_active_at, is_active)
         VALUES (?, ?, ?, ?, NOW(), NOW(), 1)'
    )->execute([$admin['id'], $sessionId, $ipAddress, $userAgent]);
} catch (Exception $e) {
    error_log('posla_admin_sessions insert failed: ' . $e->getMessage());
}

json_response([
    'admin' => [
        'id'          => $admin['id'],
        'email'       => $admin['email'],
        'displayName' => $admin['display_name'],
    ]
]);
