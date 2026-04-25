<?php
/**
 * A5: 新規テナント申込 API (未ログイン・認証不要)
 *
 * POST /api/signup/register.php
 *  body: { tenant_name, store_name, owner_name, phone, email, username, password, address?, store_count?, hq_broadcast? }
 *
 * 後方互換: tenant_name 未指定時は store_name を tenant.name にも流用 (旧 LP 互換)
 *
 * フロー:
 *  1. バリデーション (重複チェック / パスワード強度)
 *  2. tenant 仮作成 (subscription_status='incomplete')
 *  3. store 仮作成 (1店舗目)
 *  4. owner アカウント作成 (users + user_stores)
 *  5. Stripe Customer 作成
 *  6. Stripe Checkout Session 作成 (trial_period_days=30)
 *  7. checkout_url 返却 (客は Stripe 決済画面へ)
 *
 * Webhook (api/signup/webhook.php) で checkout.session.completed を受けて:
 *  - subscription_status='trialing' に更新
 *  - tenant/owner を is_active=1 に
 *  - ログイン案内メール送信
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/password-policy.php';
require_once __DIR__ . '/../lib/stripe-billing.php';

require_method(['POST']);

// 同一IPから大量申込を防止 (5回/時)
check_rate_limit('signup:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 5, 3600);

$pdo = get_db();
$body = get_json_body();

// ---------- バリデーション ----------
function _trim($v, $max = 0) {
    $s = trim((string)$v);
    if ($max && mb_strlen($s) > $max) return null;
    return $s;
}
$storeName  = _trim($body['store_name']  ?? '', 100);
// tenant_name は新規追加 (B 案)。後方互換: 未指定なら store_name を流用
$tenantName = _trim($body['tenant_name'] ?? '', 100) ?: $storeName;
$ownerName  = _trim($body['owner_name']  ?? '', 60);
$phone = _trim($body['phone'] ?? '', 20);
$email = _trim($body['email'] ?? '', 190);
$username = _trim($body['username'] ?? '', 40);
$password = (string)($body['password'] ?? '');
$address = _trim($body['address'] ?? '', 200) ?: null;
$storeCount = max(1, (int)($body['store_count'] ?? 1));
$hqBroadcast = !empty($body['hq_broadcast']);

if (!$storeName || !$ownerName || !$phone || !$email || !$username || !$password) {
    json_error('MISSING_FIELDS', '必須項目が未入力です', 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('INVALID_EMAIL', 'メールアドレスが不正です', 400);
if (!preg_match('/^[A-Za-z0-9_\-]{3,40}$/', $username)) json_error('INVALID_USERNAME', 'ユーザー名は半角英数3文字以上', 400);
if (!preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) json_error('INVALID_PHONE', '電話番号が不正です', 400);

validate_password_strength($password); // 不適合時は内部で json_error して終了

// ---------- 重複チェック ----------
$uStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$uStmt->execute([$username]);
if ($uStmt->fetch()) json_error('USERNAME_TAKEN', 'このユーザー名は既に使用されています', 409);

$eStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$eStmt->execute([$email]);
if ($eStmt->fetch()) json_error('EMAIL_TAKEN', 'このメールアドレスは既に登録されています', 409);

// ---------- Stripe 設定事前確認 ----------
$stripeConfig = get_stripe_config($pdo);
$secretKey = $stripeConfig['stripe_secret_key'] ?? '';
if (!$secretKey) json_error('STRIPE_NOT_CONFIGURED', '決済設定が未完了です。運営までお問い合わせください', 503);

$lineItems = build_alpha1_line_items($stripeConfig, $storeCount, $hqBroadcast);
if ($lineItems === null) json_error('PRICE_NOT_CONFIGURED', '料金プラン設定が未完了です', 503);

// ---------- DB レコード仮作成 ----------
$tenantId = bin2hex(random_bytes(18));
$storeId = bin2hex(random_bytes(18));
$userId = bin2hex(random_bytes(18));
$signupToken = bin2hex(random_bytes(24));

// テナント slug (ユーザー名を流用、ユニーク)
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($username));
if (!$slug) $slug = 't' . substr($tenantId, 0, 8);

$pdo->beginTransaction();
try {
    // tenants (B 案: tenant_name = 企業名)
    $pdo->prepare(
        'INSERT INTO tenants (id, name, slug, subscription_status, hq_menu_broadcast, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())'
    )->execute([$tenantId, $tenantName, $slug, 'none', $hqBroadcast ? 1 : 0]);

    // stores (1店舗目)
    $pdo->prepare(
        'INSERT INTO stores (id, tenant_id, slug, name, timezone, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())'
    )->execute([$storeId, $tenantId, 'default', $storeName, 'Asia/Tokyo']);

    // store_settings (receipt_phone, receipt_address 等)
    try {
        $pdo->prepare(
            'INSERT INTO store_settings (store_id, receipt_store_name, receipt_phone, receipt_address, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        )->execute([$storeId, $storeName, $phone, $address]);
    } catch (PDOException $e) {
        // store_settings テーブル未存在時はスキップ
    }

    // owner ユーザー (is_active=0、webhook で有効化)
    $pwHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare(
        'INSERT INTO users (id, tenant_id, username, email, password_hash, role, display_name, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())'
    )->execute([$userId, $tenantId, $username, $email, $pwHash, 'owner', $ownerName]);

    // user_stores (owner は全店舗暗黙アクセスだが明示でも記録)
    try {
        $pdo->prepare('INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)')->execute([$userId, $storeId]);
    } catch (PDOException $e) {
        // user_stores 未存在 or 重複時はスキップ
    }

    // signup_tokens 一時テーブル (webhook で使う) — なければ tenants.subscription_status に tokens を紐付ける
    // シンプルに tenants テーブルに stripe_customer_id を後で追加することで紐付け
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    // S3 #11: 内部エラーメッセージはログのみ、レスポンスは固定文言
    error_log('[A5][signup/register] db_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '処理に失敗しました。時間を置いて再試行してください。', 500);
}

// ---------- Stripe Customer 作成 ----------
$custResult = create_stripe_customer($secretKey, $storeName, $email, $tenantId);
if (!$custResult['success']) {
    // 失敗時: 作成済 DB レコードを削除 (ロールバック)
    _a5_cleanup_tenant($pdo, $tenantId, $storeId, $userId);
    json_error('STRIPE_ERROR', 'Stripe顧客登録に失敗: ' . $custResult['error'], 502);
}
$customerId = $custResult['customer_id'];
$pdo->prepare('UPDATE tenants SET stripe_customer_id = ? WHERE id = ?')->execute([$customerId, $tenantId]);

// ---------- Stripe Checkout Session 作成 ----------
$successUrl = app_url('/signup-complete.html') . '?t=' . urlencode($signupToken);
$cancelUrl = app_url('/index.html') . '?signup=cancel';

// trial_period_days=30 + metadata にテナント情報を埋めて webhook で識別
$extraParams = array(
    'subscription_data[trial_period_days]' => 30,
    'subscription_data[metadata][tenant_id]' => $tenantId,
    'subscription_data[metadata][signup_token]' => $signupToken,
    'subscription_data[metadata][new_signup]' => '1',
    'metadata[tenant_id]' => $tenantId,
    'metadata[signup_token]' => $signupToken,
    'metadata[new_signup]' => '1',
);

$checkoutResult = create_billing_checkout_session($secretKey, $customerId, $lineItems, $successUrl, $cancelUrl, $extraParams);
if (!$checkoutResult['success']) {
    _a5_cleanup_tenant($pdo, $tenantId, $storeId, $userId);
    json_error('STRIPE_ERROR', '決済セッション作成に失敗: ' . $checkoutResult['error'], 502);
}

// signup_token を tenants に記録 (webhook で識別)
try {
    $pdo->prepare('UPDATE tenants SET stripe_subscription_id = ? WHERE id = ?')->execute(['pending:' . $signupToken, $tenantId]);
} catch (PDOException $e) {
    error_log('[A5][signup/register] token_save_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

json_response([
    'tenant_id' => $tenantId,
    'checkout_url' => $checkoutResult['url'],
    'signup_token' => $signupToken,
]);

// ---------- クリーンアップヘルパ ----------
function _a5_cleanup_tenant($pdo, $tenantId, $storeId, $userId) {
    try {
        $pdo->prepare('DELETE FROM user_stores WHERE user_id = ?')->execute([$userId]);
    } catch (PDOException $e) {}
    try { $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]); } catch (PDOException $e) {}
    try { $pdo->prepare('DELETE FROM store_settings WHERE store_id = ?')->execute([$storeId]); } catch (PDOException $e) {}
    try { $pdo->prepare('DELETE FROM stores WHERE id = ?')->execute([$storeId]); } catch (PDOException $e) {}
    try { $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tenantId]); } catch (PDOException $e) {}
}
