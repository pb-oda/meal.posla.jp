<?php
/**
 * POSLA管理者 ログアウトAPI
 *
 * DELETE /api/posla/logout.php
 */

require_once __DIR__ . '/auth-helper.php';

require_method(['DELETE']);

start_posla_session();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

json_response(['message' => 'ログアウトしました']);
