<?php
/**
 * ログイン API
 *
 * POST /api/auth/login.php
 * Body: { "username": "...", "password": "..." }
 *        emailでもログイン可能（後方互換）
 *
 * レスポンス: ユーザー情報 + アクセス可能店舗一覧
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['POST']);

// H-02: ログイン brute-force 防御 — 1IP あたり 10 回 / 5 分
check_rate_limit('login', 10, 300);

$input = get_json_body();
$login    = trim($input['username'] ?? $input['email'] ?? '');
$password = $input['password'] ?? '';

if (empty($login) || empty($password)) {
    json_error('MISSING_FIELDS', 'ユーザー名とパスワードは必須です', 400);
}

$pdo = get_db();

// ユーザー検索（username OR email、テナント情報を結合）
$stmt = $pdo->prepare(
    'SELECT u.id, u.tenant_id, u.username, u.email, u.display_name, u.password_hash, u.role, u.is_active,
            t.name AS tenant_name, t.slug AS tenant_slug, t.is_active AS tenant_active
     FROM users u
     JOIN tenants t ON t.id = u.tenant_id
     WHERE u.username = ? OR u.email = ?'
);
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_error('INVALID_CREDENTIALS', 'ユーザー名またはパスワードが正しくありません', 401);
}

if (!$user['is_active']) {
    json_error('ACCOUNT_DISABLED', 'このアカウントは無効化されています', 403);
}

if (!$user['tenant_active']) {
    json_error('TENANT_DISABLED', 'このテナントは無効化されています', 403);
}

// 割当店舗IDを取得（owner以外）
$storeIds = [];
if ($user['role'] !== 'owner') {
    $stmt = $pdo->prepare('SELECT store_id FROM user_stores WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $storeIds = array_column($stmt->fetchAll(), 'store_id');
}

// セッション設定
start_auth_session();
session_regenerate_id(true);
$_SESSION['user_id']    = $user['id'];
$_SESSION['tenant_id']  = $user['tenant_id'];
$_SESSION['role']       = $user['role'];
$_SESSION['username']   = $user['username'];
$_SESSION['email']      = $user['email'];
$_SESSION['store_ids']  = $storeIds;
$_SESSION['login_time'] = time();

// S-3: セッション記録 + 同時ログイン検知
$_multiDeviceWarning = null;
$_activeSessionCount = 0;
try {
    $pdo->query('SELECT 1 FROM user_sessions LIMIT 0');
    $sessionId = session_id();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $deviceLabel = _parse_device_label($ua);

    $pdo->prepare(
        'INSERT INTO user_sessions (user_id, tenant_id, session_id, ip_address, user_agent, device_label, login_at, last_active_at, is_active)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)'
    )->execute([
        $user['id'], $user['tenant_id'], $sessionId,
        $_SERVER['REMOTE_ADDR'] ?? null, $ua, $deviceLabel
    ]);

    // 同一ユーザーのアクティブセッション数を確認
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND tenant_id = ? AND is_active = 1');
    $countStmt->execute([$user['id'], $user['tenant_id']]);
    $_activeSessionCount = (int)$countStmt->fetchColumn();
    if ($_activeSessionCount > 1) {
        $_multiDeviceWarning = '他の端末でもログインしています';
    }
} catch (PDOException $e) {
    // user_sessions テーブル未作成時はスキップ
    error_log('[P1-12][api/auth/login.php:95] record_session: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

// アクセス可能店舗一覧を取得
if ($user['role'] === 'owner') {
    $stmt = $pdo->prepare(
        'SELECT id, slug, name, name_en FROM stores WHERE tenant_id = ? AND is_active = 1 ORDER BY name'
    );
    $stmt->execute([$user['tenant_id']]);
} else {
    $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
    if (count($storeIds) > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, slug, name, name_en FROM stores WHERE id IN (' . $placeholders . ') AND is_active = 1 ORDER BY name'
        );
        $stmt->execute($storeIds);
    } else {
        $stmt = null;
    }
}
$stores = $stmt ? $stmt->fetchAll() : [];

// last_login_at 更新
$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
    ->execute([$user['id']]);

// 監査ログ
$auditUser = ['user_id' => $user['id'], 'tenant_id' => $user['tenant_id'], 'username' => $user['username'], 'role' => $user['role']];
write_audit_log($pdo, $auditUser, null, 'login', 'user', $user['id'], null, null, null);

$responseData = [
    'user' => [
        'id'          => $user['id'],
        'username'    => $user['username'],
        'email'       => $user['email'],
        'displayName' => $user['display_name'],
        'role'        => $user['role'],
        'tenantId'    => $user['tenant_id'],
        'tenantName'  => $user['tenant_name'],
    ],
    'stores' => array_map(function ($s) {
        return [
            'id'     => $s['id'],
            'slug'   => $s['slug'],
            'name'   => $s['name'],
            'nameEn' => $s['name_en'] ?? '',
        ];
    }, $stores),
];

// S-3: 複数端末ログイン警告
if ($_multiDeviceWarning) {
    $responseData['warning'] = $_multiDeviceWarning;
    $responseData['active_sessions'] = $_activeSessionCount;
}

json_response($responseData);

// ── ヘルパー ──
function _parse_device_label(string $ua): string
{
    if ($ua === '') return 'Unknown';

    // ブラウザ検出
    $browser = 'Unknown';
    if (strpos($ua, 'Edg/') !== false || strpos($ua, 'Edge/') !== false) $browser = 'Edge';
    elseif (strpos($ua, 'Chrome/') !== false) $browser = 'Chrome';
    elseif (strpos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'Safari/') !== false) $browser = 'Safari';

    // OS検出
    $os = '';
    if (strpos($ua, 'iPhone') !== false) $os = 'iPhone';
    elseif (strpos($ua, 'iPad') !== false) $os = 'iPad';
    elseif (strpos($ua, 'Android') !== false) $os = 'Android';
    elseif (strpos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (strpos($ua, 'Macintosh') !== false || strpos($ua, 'Mac OS') !== false) $os = 'Mac';
    elseif (strpos($ua, 'Linux') !== false) $os = 'Linux';

    return $os ? $browser . '/' . $os : $browser;
}
