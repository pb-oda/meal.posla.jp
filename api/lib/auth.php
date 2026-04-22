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
 * P1-34 (α-1): プラン機能チェック
 *
 * 2026-04-09 以降のプラン構成 α-1 (単一プラン + アドオン) では、
 * 「本部一括メニュー配信」(hq_menu_broadcast) のみが差別化機能。
 * それ以外の全機能は全契約者に標準提供 → 常に true を返す。
 *
 * hq_menu_broadcast は tenants テーブルのカラムを直接参照する
 * (plan_features テーブルは論理的に廃止、SELECT しない)。
 *
 * @param PDO $pdo
 * @param string $tenantId
 * @param string $featureKey
 * @return bool
 */
function check_plan_feature($pdo, $tenantId, $featureKey) {
    // hq_menu_broadcast 以外は α-1 では全契約者に標準提供
    if ($featureKey !== 'hq_menu_broadcast') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT hq_menu_broadcast FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    return $row && (int)$row['hq_menu_broadcast'] === 1;
}

/**
 * P1-34 (α-1): テナントのプラン名取得
 *
 * α-1 では単一プラン構成だが、UI や既存コードとの互換性のため
 * 引き続き plan カラムを返す。フロント側は表示用途以外で使わない想定。
 *
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
 * P1-34 (α-1): テナントの全機能フラグ取得
 *
 * 既存 UI (dashboard.html / owner-app.js / menu-override-editor.js) の
 * `_planFeatures.xxx === false` / `=== true` 判定との互換性のため、
 * 旧 plan_features の全 feature_key を返す:
 *   - hq_menu_broadcast: tenants カラム値 (アドオン契約時のみ true)
 *   - その他全フラグ: 常に true (α-1 で全契約者に標準提供)
 *
 * @param PDO $pdo
 * @param string $tenantId
 * @return array ['feature_key' => bool, ...]
 */
function get_plan_features($pdo, $tenantId) {
    $stmt = $pdo->prepare('SELECT hq_menu_broadcast FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    $hqBroadcast = $row && (int)$row['hq_menu_broadcast'] === 1;

    // α-1: hq_menu_broadcast 以外の全フラグは常に true
    // 旧 plan_features に存在した全 feature_key を網羅 (UI 互換性のため)
    return [
        'self_order'            => true,
        'handy_pos'             => true,
        'floor_map'             => true,
        'table_merge_split'     => true,
        'inventory'             => true,
        'ai_waiter'             => true,
        'ai_voice'              => true,
        'ai_forecast'           => true,
        'takeout'               => true,
        'payment_gateway'       => true,
        'advanced_reports'      => true,
        'basket_analysis'       => true,
        'audit_log'             => true,
        'multi_session_control' => true,
        'offline_detection'     => true,
        'satisfaction_rating'   => true,
        'multilingual'          => true,
        'shift_management'      => true,
        'shift_help_request'    => true,
        'hq_menu_broadcast'     => $hqBroadcast,
    ];
}

/**
 * H-04: Origin / Referer 検証ヘルパー（CSRF 対策）
 *
 * Origin ヘッダー（fetch / XHR）または Referer ヘッダー（form 送信 / 一部 GET）
 * が自ドメインから来ているか検証する。
 *
 * 動作:
 *   - Origin があれば: host を allowlist と照合、不一致は 403 FORBIDDEN_ORIGIN
 *   - Origin なし + Referer があれば: 同様に host を照合
 *   - 両方なし: 通過（curl / サーバー間連携 / 一部プロキシ経由を許容）
 *
 * 用途: state-changing 系 POST / PATCH / DELETE で opt-in 呼び出し。
 * 全 endpoint 自動適用は customer 経路の互換性確認後に別スライスで判断。
 */
function verify_origin(): void
{
    $allowedHosts = ['eat.posla.jp', 'meal.posla.jp'];

    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        if (!in_array($host, $allowedHosts, true)) {
            json_error('FORBIDDEN_ORIGIN', 'リクエスト元が許可されていません', 403);
        }
        return;
    }

    if ($referer !== '') {
        $host = parse_url($referer, PHP_URL_HOST);
        if (!in_array($host, $allowedHosts, true)) {
            json_error('FORBIDDEN_ORIGIN', 'リクエスト元が許可されていません', 403);
        }
        return;
    }

    // Origin / Referer 両方なし — curl / 外部連携として通過
    // 必要なら呼び出し元で追加のトークン検証を行う
}
