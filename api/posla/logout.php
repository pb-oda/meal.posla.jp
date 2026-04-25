<?php
/**
 * POSLA管理者 ログアウトAPI
 *
 * DELETE /api/posla/logout.php
 */

require_once __DIR__ . '/auth-helper.php';

require_method(['DELETE']);

start_posla_session();

// SEC-HOTFIX-20260423-B: ログアウト時に posla_admin_sessions.is_active=0 を明示更新
// login.php で INSERT されるレコードと対で soft-delete する。
// 既存挙動 ($_SESSION クリア + session_destroy) は維持。失敗してもログアウト処理は止めない。
if (!empty($_SESSION['posla_admin_id'])) {
    try {
        $pdo = get_db();
        $pdo->prepare(
            'UPDATE posla_admin_sessions SET is_active = 0
             WHERE session_id = ? AND admin_id = ?'
        )->execute([session_id(), $_SESSION['posla_admin_id']]);
    } catch (Exception $e) {
        error_log('[SEC-HOTFIX-20260423-B] posla_admin_sessions logout update failed: ' . $e->getMessage());
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

json_response(['message' => 'ログアウトしました']);
