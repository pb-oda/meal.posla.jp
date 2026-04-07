<?php
/**
 * L-15: スマレジOAuth認可開始
 *
 * GET /api/smaregi/auth.php
 * owner認証必須。スマレジの認可画面にリダイレクトする。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/smaregi-client.php';

$user = require_role('owner');
require_method(['GET']);

$pdo = get_db();
$tenantId = $user['tenant_id'];

// POSLA共通のClient ID取得
$config = get_smaregi_config($pdo);
if (!$config['client_id']) {
    json_error('SMAREGI_NOT_CONFIGURED', 'スマレジ Client ID が未設定です。POSLA管理者に連絡してください。', 500);
}

// CSRF state生成・セッション保存
$state = bin2hex(random_bytes(16));
$_SESSION['smaregi_oauth_state'] = $state;
$_SESSION['smaregi_oauth_tenant'] = $tenantId;

// スマレジ認可URL組み立て
$params = http_build_query([
    'response_type' => 'code',
    'client_id'     => $config['client_id'],
    'scope'         => 'openid offline_access pos.products:read pos.transactions:read pos.transactions:write pos.stores:read',
    'redirect_uri'  => 'https://eat.posla.jp/api/smaregi/callback.php',
    'state'         => $state,
]);

$authUrl = 'https://id.smaregi.dev/authorize?' . $params;

header('Location: ' . $authUrl);
exit;
