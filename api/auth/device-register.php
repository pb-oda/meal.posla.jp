<?php
/**
 * F-DA1: デバイス登録（ワンタイムトークン方式）
 *
 * POST /api/auth/device-register.php
 * Body: { token: "reg_..." }
 *
 * 認証不要。トークンを使って device ユーザーを自動作成 + セッション開始。
 * login.php と同じセッション構造を構築する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/password-policy.php';

require_method(['POST']);

$data = json_decode(file_get_contents('php://input'), true);
$plainToken = trim($data['token'] ?? '');

if (!$plainToken) {
    json_error('VALIDATION', 'token は必須です', 400);
}

$pdo = get_db();

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM device_registration_tokens LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION_REQUIRED', 'デバイス登録トークン機能のマイグレーションが未適用です', 500);
}

$tokenHash = hash('sha256', $plainToken);

$pdo->beginTransaction();
try {
    // トークン検索（FOR UPDATE でレース条件防止）
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, store_id, display_name, visible_tools, is_used, expires_at
         FROM device_registration_tokens
         WHERE token_hash = ?
         FOR UPDATE'
    );
    $stmt->execute([$tokenHash]);
    $token = $stmt->fetch();

    if (!$token) {
        $pdo->rollBack();
        json_error('INVALID_TOKEN', '無効なトークンです', 404);
    }

    if ($token['is_used']) {
        $pdo->rollBack();
        json_error('TOKEN_ALREADY_USED', 'このトークンは既に使用済みです', 409);
    }

    if (strtotime($token['expires_at']) < time()) {
        $pdo->rollBack();
        json_error('TOKEN_EXPIRED', 'このトークンは有効期限切れです', 410);
    }

    $tenantId    = $token['tenant_id'];
    $storeId     = $token['store_id'];
    $displayName = $token['display_name'];
    $visibleTools = $token['visible_tools'];

    // テナント・店舗の有効性チェック
    $tenantCheck = $pdo->prepare('SELECT is_active FROM tenants WHERE id = ?');
    $tenantCheck->execute([$tenantId]);
    $tenant = $tenantCheck->fetch();
    if (!$tenant || !$tenant['is_active']) {
        $pdo->rollBack();
        json_error('TENANT_INACTIVE', 'テナントが無効です', 403);
    }

    $storeCheck = $pdo->prepare('SELECT is_active FROM stores WHERE id = ? AND tenant_id = ?');
    $storeCheck->execute([$storeId, $tenantId]);
    $store = $storeCheck->fetch();
    if (!$store || !$store['is_active']) {
        $pdo->rollBack();
        json_error('STORE_INACTIVE', '店舗が無効です', 403);
    }

    // デバイスユーザー自動作成
    $userId = generate_uuid();
    $username = 'device_' . substr(str_replace('-', '', $userId), 0, 12);
    $randomPassword = bin2hex(random_bytes(16));
    $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

    // users レコード作成
    $pdo->prepare(
        'INSERT INTO users (id, tenant_id, username, password_hash, display_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
    )->execute([
        $userId, $tenantId, $username, $passwordHash, $displayName, 'device'
    ]);

    // user_stores 紐付け
    $pdo->prepare(
        'INSERT INTO user_stores (user_id, store_id, visible_tools, created_at)
         VALUES (?, ?, ?, NOW())'
    )->execute([$userId, $storeId, $visibleTools]);

    // トークンを使用済みに更新
    $pdo->prepare(
        'UPDATE device_registration_tokens SET is_used = 1, used_by_user_id = ?, used_at = NOW() WHERE id = ?'
    )->execute([$userId, $token['id']]);

    // 監査ログ記録
    try {
        $pdo->prepare(
            'INSERT INTO audit_log (id, tenant_id, user_id, role, action, target_type, target_id, detail, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            generate_uuid(), $tenantId, $userId, 'device', 'device_register',
            'user', $userId,
            json_encode(['display_name' => $displayName, 'store_id' => $storeId, 'visible_tools' => $visibleTools]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (PDOException $e) {
        // audit_log 未作成時はスキップ（graceful degradation）
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('[F-DA1] device-register failed: ' . $e->getMessage());
    json_error('DEVICE_CREATION_FAILED', 'デバイスアカウントの作成に失敗しました', 500);
}

// セッション開始（login.php と同じパターン）
start_auth_session();
session_regenerate_id(true);
$_SESSION['user_id']    = $userId;
$_SESSION['tenant_id']  = $tenantId;
$_SESSION['role']       = 'device';
$_SESSION['username']   = $username;
$_SESSION['email']      = '';
$_SESSION['store_ids']  = [$storeId];
$_SESSION['login_time'] = time();

// user_sessions テーブルに記録（graceful degradation）
try {
    $sessionId = session_id();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $pdo->prepare(
        'INSERT INTO user_sessions (user_id, tenant_id, session_id, ip_address, user_agent, device_label, login_at, last_active_at, is_active)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)'
    )->execute([
        $userId, $tenantId, $sessionId,
        $_SERVER['REMOTE_ADDR'] ?? null, $ua, 'DeviceSetup'
    ]);
} catch (PDOException $e) {
    // user_sessions 未作成時はスキップ
}

// last_login_at 更新
$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);

// 店舗情報取得
$storeStmt = $pdo->prepare('SELECT id, slug, name, name_en FROM stores WHERE id = ? AND is_active = 1');
$storeStmt->execute([$storeId]);
$store = $storeStmt->fetch();

json_response([
    'user' => [
        'id'           => $userId,
        'username'     => $username,
        'displayName'  => $displayName,
        'role'         => 'device',
        'tenantId'     => $tenantId,
    ],
    'stores' => $store ? [[
        'id'               => $store['id'],
        'slug'             => $store['slug'],
        'name'             => $store['name'],
        'nameEn'           => $store['name_en'] ?? '',
        'userVisibleTools' => $visibleTools,
    ]] : [],
]);
