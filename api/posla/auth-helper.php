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
require_once __DIR__ . '/../lib/auth.php';  // SEC-HOTFIX-20260423-B: _is_https_request() 共用

/**
 * POSLA管理者用セッション開始
 */
function start_posla_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        posla_configure_session_store();
        session_set_cookie_params([
            'lifetime' => 28800, // 8時間
            'path'     => '/',
            'secure'   => _is_https_request(), // SEC-HOTFIX-20260423-B: HTTPS 時のみ secure 付与
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
        posla_start_session_or_fail();
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

    // SEC-HOTFIX-20260423-B: posla_admin_sessions の失効チェック
    // パスワード変更で DELETE された session_id は見つからない → 401 SESSION_REVOKED
    // last_active_at は 5 分に 1 回更新（tenant 側 _check_session_validity と同じ方針）
    _check_posla_session_validity();

    return [
        'admin_id'     => $_SESSION['posla_admin_id'],
        'email'        => $_SESSION['posla_admin_email'] ?? '',
        'display_name' => $_SESSION['posla_admin_display_name'] ?? '',
    ];
}

/**
 * SEC-HOTFIX-20260423-B: POSLA管理者セッション有効性チェック
 *
 * 仕様:
 *  - posla_admin_sessions に現 session_id が存在しない → SESSION_REVOKED 401
 *    (パスワード変更 change-password.php で DELETE されたセッションが該当)
 *  - is_active=0 レコードが存在する → SESSION_REVOKED 401
 *  - last_active_at を 5 分に 1 回 UPDATE
 *  - テーブル未作成 / DB 接続失敗は fail-open (既存動作維持)
 */
function _check_posla_session_validity(): void
{
    if (!function_exists('get_db')) return;

    try {
        $pdo = get_db();
        $sid = session_id();
        if (!$sid) return;

        // テーブル存在チェック (リクエスト内キャッシュ)
        static $tableExists = null;
        if ($tableExists === null) {
            try {
                $pdo->query('SELECT 1 FROM posla_admin_sessions LIMIT 0');
                $tableExists = true;
            } catch (PDOException $e) {
                $tableExists = false;
            }
        }
        if (!$tableExists) return;

        $adminId = $_SESSION['posla_admin_id'] ?? '';

        $stmt = $pdo->prepare(
            'SELECT is_active FROM posla_admin_sessions
             WHERE session_id = ? AND admin_id = ? LIMIT 1'
        );
        $stmt->execute([$sid, $adminId]);
        $row = $stmt->fetch();

        // レコード非存在 (change-password で削除済み) または is_active=0 → revoke
        if (!$row || (int)$row['is_active'] === 0) {
            $_SESSION = [];
            session_destroy();
            json_error('SESSION_REVOKED', 'POSLA管理者セッションが無効化されました。再ログインしてください。', 401);
        }

        // last_active_at 更新 (5 分に 1 回)
        $now = time();
        $lastUpdate = $_SESSION['posla_admin_last_activity_update'] ?? 0;
        if ($now - $lastUpdate > 300) {
            $upd = $pdo->prepare(
                'UPDATE posla_admin_sessions SET last_active_at = NOW()
                 WHERE session_id = ? AND admin_id = ?'
            );
            $upd->execute([$sid, $adminId]);
            $_SESSION['posla_admin_last_activity_update'] = $now;
        }
    } catch (Exception $e) {
        // DB 接続エラー等は認証自体を阻害しない (fail-open)
        error_log('[SEC-HOTFIX-20260423-B] posla_session_check: ' . $e->getMessage());
    }
}
