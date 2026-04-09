<?php
/**
 * ログアウト API
 *
 * DELETE /api/auth/logout.php
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['DELETE']);

start_auth_session();

// セッション破棄前に監査ログを記録
$logoutUser = [
    'user_id'   => $_SESSION['user_id'] ?? '',
    'tenant_id' => $_SESSION['tenant_id'] ?? '',
    'username'  => $_SESSION['username'] ?? '',
    'role'      => $_SESSION['role'] ?? '',
];
if ($logoutUser['user_id']) {
    $pdo = get_db();
    write_audit_log($pdo, $logoutUser, null, 'logout', 'user', $logoutUser['user_id'], null, null, null);

    // S-3: セッション無効化
    $currentSessionId = session_id();
    if ($currentSessionId) {
        try {
            $pdo->prepare('UPDATE user_sessions SET is_active = 0 WHERE session_id = ? AND tenant_id = ?')
                ->execute([$currentSessionId, $_SESSION['tenant_id'] ?? '']);
        } catch (PDOException $e) {
            // user_sessions テーブル未作成時はスキップ
            error_log('[P1-12][api/auth/logout.php:34] logout_invalidate_self: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        }
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

json_response(['message' => 'ログアウトしました']);
