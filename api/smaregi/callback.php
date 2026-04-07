<?php
/**
 * L-15: スマレジOAuthコールバック
 *
 * GET /api/smaregi/callback.php?code=xxx&state=xxx
 * スマレジからのリダイレクト先。code→トークン交換→DB保存→owner-dashboardにリダイレクト。
 * 認証はセッションのCSRF stateで検証。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/smaregi-client.php';
require_once __DIR__ . '/../config/app.php';

// セッション開始（auth.phpで保存したstateを読み取る）
start_auth_session();

$dashboardUrl = '/public/admin/owner-dashboard.html';

// エラーチェック（スマレジ側でユーザーが拒否した場合など）
if (isset($_GET['error'])) {
    $errMsg = isset($_GET['error_description']) ? $_GET['error_description'] : $_GET['error'];
    header('Location: ' . $dashboardUrl . '?smaregi=error&message=' . urlencode($errMsg));
    exit;
}

// パラメータ取得
$code  = isset($_GET['code']) ? $_GET['code'] : '';
$state = isset($_GET['state']) ? $_GET['state'] : '';

if (!$code || !$state) {
    header('Location: ' . $dashboardUrl . '?smaregi=error&message=' . urlencode('パラメータ不足'));
    exit;
}

// CSRF検証
$savedState  = isset($_SESSION['smaregi_oauth_state']) ? $_SESSION['smaregi_oauth_state'] : '';
$savedTenant = isset($_SESSION['smaregi_oauth_tenant']) ? $_SESSION['smaregi_oauth_tenant'] : '';

if (!$savedState || !hash_equals($savedState, $state)) {
    header('Location: ' . $dashboardUrl . '?smaregi=error&message=' . urlencode('CSRF検証失敗'));
    exit;
}

if (!$savedTenant) {
    header('Location: ' . $dashboardUrl . '?smaregi=error&message=' . urlencode('テナント情報なし'));
    exit;
}

// state使い捨て
unset($_SESSION['smaregi_oauth_state']);
unset($_SESSION['smaregi_oauth_tenant']);

$pdo = get_db();

// Client ID/Secret取得
$config = get_smaregi_config($pdo);
if (!$config['client_id'] || !$config['client_secret']) {
    header('Location: ' . $dashboardUrl . '?smaregi=error&message=' . urlencode('スマレジ設定不備'));
    exit;
}

// code → トークン交換
$tokenUrl = 'https://id.smaregi.dev/authorize/token';
$postFields = http_build_query([
    'grant_type'   => 'authorization_code',
    'code'         => $code,
    'redirect_uri' => APP_BASE_URL . '/api/smaregi/callback.php',
]);

$authHeader = 'Authorization: Basic ' . base64_encode($config['client_id'] . ':' . $config['client_secret']);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $tokenUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_HTTPHEADER     => [
        $authHeader,
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_errno($ch) ? curl_error($ch) : null;
curl_close($ch);

if ($curlErr) {
    header('Location: ' . $dashboardUrl . '?smaregi=error&message=' . urlencode('通信エラー: ' . $curlErr));
    exit;
}

$json = json_decode($body, true);
if ($httpCode >= 400 || !$json || !isset($json['access_token'])) {
    $errMsg = isset($json['error_description']) ? $json['error_description'] : (isset($json['error']) ? $json['error'] : '不明なエラー');
    header('Location: ' . $dashboardUrl . '?smaregi=error&message=' . urlencode('トークン取得失敗: ' . $errMsg));
    exit;
}

$accessToken  = $json['access_token'];
$refreshToken = isset($json['refresh_token']) ? $json['refresh_token'] : null;
$expiresIn    = isset($json['expires_in']) ? (int)$json['expires_in'] : 3600;
$expiresAt    = date('Y-m-d H:i:s', time() + $expiresIn);

// contract_id取得（userinfoエンドポイント）
$contractId = null;
$uiJson = null;
$uiCh = curl_init();
curl_setopt_array($uiCh, [
    CURLOPT_URL            => 'https://id.smaregi.dev/userinfo',
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$uiBody = curl_exec($uiCh);
$uiCode = curl_getinfo($uiCh, CURLINFO_HTTP_CODE);
curl_close($uiCh);

error_log('[POSLA-Smaregi] userinfo HTTP=' . $uiCode . ' body=' . $uiBody);

if ($uiCode >= 200 && $uiCode < 300) {
    $uiJson = json_decode($uiBody, true);
    if ($uiJson) {
        // パターン1: contract.id
        if (isset($uiJson['contract']['id'])) {
            $contractId = $uiJson['contract']['id'];
        }
        // パターン2: contract_id（直接キー）
        if (!$contractId && isset($uiJson['contract_id'])) {
            $contractId = $uiJson['contract_id'];
        }
        // パターン3: sub（ユーザー識別子＝契約ID）
        if (!$contractId && isset($uiJson['sub'])) {
            $contractId = $uiJson['sub'];
        }
    }
}

// トークンレスポンス自体にcontract_idが含まれる場合（スマレジPlatform API仕様）
if (!$contractId && isset($json['contract_id'])) {
    $contractId = $json['contract_id'];
}

if (!$contractId) {
    error_log('[POSLA-Smaregi] contract_id not found. token_json=' . $body . ' userinfo_json=' . $uiBody);
    header('Location: ' . $dashboardUrl . '?smaregi=error&message=' . urlencode('契約IDが取得できませんでした（詳細はサーバーログ参照）'));
    exit;
}

// DB保存
$stmt = $pdo->prepare(
    'UPDATE tenants SET
        smaregi_contract_id = ?,
        smaregi_access_token = ?,
        smaregi_refresh_token = ?,
        smaregi_token_expires_at = ?,
        smaregi_connected_at = NOW()
     WHERE id = ?'
);
$stmt->execute([$contractId, $accessToken, $refreshToken, $expiresAt, $savedTenant]);

// 成功リダイレクト
header('Location: ' . $dashboardUrl . '?smaregi=success');
exit;
