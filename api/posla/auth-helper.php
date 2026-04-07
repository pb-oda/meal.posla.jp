<?php
/**
 * POSLA管理者 認証ヘルパー
 *
 * セッション構造 ($_SESSION):
 *   'posla_admin_id'           => UUID
 *   'posla_admin_email'        => string
 *   'posla_admin_display_name' => string
 *   'posla_admin_login_time'   => int (timestamp)
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

/**
 * POSLA管理者用セッション開始
 */
function start_posla_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 28800, // 8時間
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * POSLA管理者認証を要求（未認証なら401）
 * @return array ['admin_id', 'email', 'display_name']
 */
function require_posla_admin(): array
{
    start_posla_session();

    if (empty($_SESSION['posla_admin_id'])) {
        json_error('UNAUTHORIZED', 'POSLA管理者ログインが必要です', 401);
    }

    return [
        'admin_id'     => $_SESSION['posla_admin_id'],
        'email'        => $_SESSION['posla_admin_email'] ?? '',
        'display_name' => $_SESSION['posla_admin_display_name'] ?? '',
    ];
}
