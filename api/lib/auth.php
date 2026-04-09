<?php
/**
 * 認証・認可ライブラリ
 *
 * セッション構造 ($_SESSION):
 *   'user_id'    => UUID
 *   'tenant_id'  => UUID
 *   'role'       => 'owner'|'manager'|'staff'|'device' (P1a)
 *   'username'   => string
 *   'email'      => string
 *   'store_ids'  => array of UUIDs（ownerは空配列=全店舗暗黙アクセス）
 *   'login_time' => int (timestamp)
 */

require_once __DIR__ . '/response.php';

/**
 * セッション開始
 */
function start_auth_session(): void
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
 * 認証済みユーザーを要求（未認証なら401）
 * @return array セッションのユーザー情報
 */
function require_auth(): array
{
    start_auth_session();

    if (empty($_SESSION['user_id'])) {
        json_error('UNAUTHORIZED', 'ログインが必要です', 401);
    }

    // S-3: セッション有効性チェック + last_active_at 更新
    _check_session_validity();

    return [
        'user_id'   => $_SESSION['user_id'],
        'tenant_id' => $_SESSION['tenant_id'],
        'role'      => $_SESSION['role'],
        'username'  => $_SESSION['username'] ?? '',
        'email'     => $_SESSION['email'] ?? '',
        'store_ids' => $_SESSION['store_ids'] ?? [],
    ];
}

/**
 * 最低ロールレベルを要求
 * ロール階層: owner(3) > manager(2) > staff(1) > device(0)
 * P1a: device は KDS/レジ端末専用アカウント。staff より下位扱い。
 */
function require_role(string $minimum_role): array
{
    $user = require_auth();
    $hierarchy = ['device' => 0, 'staff' => 1, 'manager' => 2, 'owner' => 3];

    $user_level    = $hierarchy[$user['role']] ?? 0;
    $required_level = $hierarchy[$minimum_role] ?? 99;

    if ($user_level < $required_level) {
        json_error('FORBIDDEN', '権限が不足しています', 403);
    }

    return $user;
}

/**
 * 指定店舗へのアクセス権を検証
 * ownerはテナント内全店舗に暗黙アクセス可能
 * manager/staffは user_stores の明示的な行が必要
 */
function require_store_access(string $store_id): array
{
    $user = require_auth();

    if ($user['role'] === 'owner') {
        // ownerはテナント内の店舗か検証
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'SELECT id FROM stores WHERE id = ? AND tenant_id = ? AND is_active = 1'
        );
        $stmt->execute([$store_id, $user['tenant_id']]);
        if (!$stmt->fetch()) {
            json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
        }
        return $user;
    }

    // manager/staff は明示的アサインが必要
    if (!in_array($store_id, $user['store_ids'], true)) {
        json_error('FORBIDDEN', 'この店舗へのアクセス権がありません', 403);
    }

    return $user;
}

/**
 * 認証なしで現在ユーザーを取得（null = 未ログイン）
 */
function get_logged_in_user(): ?array
{
    start_auth_session();
    if (empty($_SESSION['user_id'])) return null;
    return [
        'user_id'   => $_SESSION['user_id'],
        'tenant_id' => $_SESSION['tenant_id'],
        'role'      => $_SESSION['role'],
        'store_ids' => $_SESSION['store_ids'] ?? [],
    ];
}

/**
 * store_id パラメータを取得・検証するヘルパー
 * GETまたはPOSTボディから取得し、アクセス権を検証
 */
function require_store_param(): string
{
    $store_id = $_GET['store_id'] ?? '';
    if (empty($store_id)) {
        json_error('MISSING_STORE', 'store_id は必須です', 400);
    }
    require_store_access($store_id);
    return $store_id;
}

/**
 * D-1: セッションアイドルタイムアウト（30分）
 */
define('SESSION_IDLE_TIMEOUT', 1800);

/**
 * S-3: セッション有効性チェック + last_active_at 更新
 * user_sessions テーブルで現セッションが無効化されていないか確認。
 * D-1: アイドルタイムアウト判定を追加。
 * 5分に1回 last_active_at を更新。
 * テーブル未作成時や DB 未接続時はスキップ。
 */
function _check_session_validity(): void
{
    if (!function_exists('get_db')) return;

    try {
        $pdo = get_db();
        $sid = session_id();
        if (!$sid) return;

        // テーブル存在チェック（リクエスト内キャッシュ）
        static $tableExists = null;
        if ($tableExists === null) {
            try {
                $pdo->query('SELECT 1 FROM user_sessions LIMIT 0');
                $tableExists = true;
            } catch (PDOException $e) {
                $tableExists = false;
            }
        }
        if (!$tableExists) return;

        $tenantId = $_SESSION['tenant_id'] ?? '';

        // セッション有効性 + アイドル秒数チェック
        $stmt = $pdo->prepare(
            'SELECT is_active, UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(last_active_at) AS idle_secs
             FROM user_sessions WHERE session_id = ? AND tenant_id = ?'
        );
        $stmt->execute([$sid, $tenantId]);
        $row = $stmt->fetch();

        if ($row && !$row['is_active']) {
            // セッションが無効化されている → 強制ログアウト
            $_SESSION = [];
            session_destroy();
            json_error('SESSION_REVOKED', 'セッションが無効化されました。再ログインしてください。', 401);
        }

        // D-1: アイドルタイムアウト判定
        if ($row && (int)$row['idle_secs'] > SESSION_IDLE_TIMEOUT) {
            // セッション無効化
            $pdo->prepare('UPDATE user_sessions SET is_active = 0 WHERE session_id = ? AND tenant_id = ?')
                ->execute([$sid, $tenantId]);

            // 監査ログ（audit-log.php が読み込まれている場合のみ）
            if (function_exists('write_audit_log')) {
                $auditUser = [
                    'user_id'   => $_SESSION['user_id'] ?? '',
                    'tenant_id' => $tenantId,
                    'username'  => $_SESSION['username'] ?? '',
                    'role'      => $_SESSION['role'] ?? '',
                ];
                write_audit_log($pdo, $auditUser, null, 'auto_logout', 'user', $auditUser['user_id'], null, null, null);
            }

            $_SESSION = [];
            session_destroy();
            json_error('SESSION_TIMEOUT', 'セッションがタイムアウトしました。再度ログインしてください。', 401);
        }

        // last_active_at 更新（5分に1回）
        $now = time();
        $lastUpdate = $_SESSION['last_activity_update'] ?? 0;
        if ($now - $lastUpdate > 300) {
            $pdo->prepare('UPDATE user_sessions SET last_active_at = NOW() WHERE session_id = ? AND tenant_id = ?')
                ->execute([$sid, $tenantId]);
            $_SESSION['last_activity_update'] = $now;
        }
    } catch (Exception $e) {
        // DB接続エラー等は認証自体を阻害しない
        error_log('[P1-12][api/lib/auth.php:216] session_last_active_update: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }
}

/**
 * L-16: テナントのプラン機能チェック
 * @param PDO $pdo
 * @param string $tenantId
 * @param string $featureKey
 * @return bool
 */
function check_plan_feature($pdo, $tenantId, $featureKey) {
    $stmt = $pdo->prepare(
        'SELECT pf.enabled
         FROM plan_features pf
         INNER JOIN tenants t ON t.plan = pf.plan
         WHERE t.id = ? AND pf.feature_key = ?'
    );
    $stmt->execute([$tenantId, $featureKey]);
    $row = $stmt->fetch();
    return $row && (int)$row['enabled'] === 1;
}

/**
 * L-16: テナントのプラン名取得
 * @param PDO $pdo
 * @param string $tenantId
 * @return string
 */
function get_tenant_plan($pdo, $tenantId) {
    $stmt = $pdo->prepare('SELECT plan FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    return $row ? $row['plan'] : 'standard';
}

/**
 * L-16: テナントの全機能フラグ取得
 * @param PDO $pdo
 * @param string $tenantId
 * @return array ['feature_key' => bool, ...]
 */
function get_plan_features($pdo, $tenantId) {
    $stmt = $pdo->prepare(
        'SELECT pf.feature_key, pf.enabled
         FROM plan_features pf
         INNER JOIN tenants t ON t.plan = pf.plan
         WHERE t.id = ?'
    );
    $stmt->execute([$tenantId]);
    $features = [];
    while ($row = $stmt->fetch()) {
        $features[$row['feature_key']] = (int)$row['enabled'] === 1;
    }
    return $features;
}
