<?php
/**
 * A5: 申込アクティベーション (Webhook フォールバック)
 *
 * POST /api/signup/activate.php
 *  body: { signup_token }
 *
 * 成功 URL (signup-complete.html) 到達時にクライアントから呼ばれる:
 *  - signup_token を tenants.stripe_subscription_id='pending:{token}' で検索
 *  - Stripe API で subscription の実態を確認 (任意)
 *  - is_active=1 + subscription_status='trialing' に更新
 *  - onboarding request を ready_for_cell に更新
 *  - Webhook と併用しても冪等 (is_active=1 ならスキップ)
 *
 * 専用cell作成とログインURL送信は本番 provisioner ホストが行う。
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/stripe-billing.php';
require_once __DIR__ . '/../lib/tenant-onboarding.php';
require_once __DIR__ . '/../lib/provisioner-trigger.php';
require_once __DIR__ . '/../lib/mail.php';
require_once __DIR__ . '/../config/app.php';

require_method(['POST']);
check_rate_limit('signup-activate:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 10, 3600);

$pdo = get_db();
$body = get_json_body();
$token = trim((string)($body['signup_token'] ?? ''));
if (!$token) json_error('MISSING_TOKEN', 'signup_token が必要です', 400);

// token で tenants 検索
//   pending:{token}      → webhook 未着 (= まだ決済未完了の可能性大)
//   sub_xxx              → webhook 完了後に書き換わるが、token から逆引きはできない (signup_token 専用カラム未設置)
// したがってここでは pending:{token} のみ検索する。webhook 完了後にユーザーが再度 activate を叩く
// シナリオは is_active=1 で既に処理済みのため、フロント側は signup-complete.html で完了状態として扱える。
$stmt = $pdo->prepare("SELECT id, name, is_active, subscription_status, stripe_subscription_id, stripe_customer_id
                         FROM tenants
                        WHERE stripe_subscription_id = ?
                        LIMIT 1");
$stmt->execute(['pending:' . $token]);
$tenant = $stmt->fetch();

// S3 #12: トークン不一致は 404 INVALID_TOKEN を返す (success 偽装を防ぐ)
if (!$tenant) {
    json_error('INVALID_TOKEN', '不明なトークンです', 404);
}

if ((int)$tenant['is_active'] === 1) {
    // 既に有効化済み (webhook 経由で更新済み等)
    posla_trigger_cell_provisioner($pdo, (string)$tenant['id'], 'signup_activate_already_active');
    json_response(['ok' => true, 'already_activated' => true, 'tenant_name' => $tenant['name']]);
}

// ── S3 #1: Stripe 決済確認 ──
// stripe_subscription_id が pending:{token} の段階では実 Subscription ID が分からないため、
// Customer の subscriptions list を取得し、metadata.signup_token が一致するものを探す。
$stripeConfig = get_stripe_config($pdo);
$secretKey = $stripeConfig['stripe_secret_key'] ?? '';
if (!$secretKey) {
    error_log('[A5][activate] STRIPE_NOT_CONFIGURED tenant=' . $tenant['id'], 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_NOT_CONFIGURED', '決済設定が未完了です。運営までお問い合わせください', 503);
}

$customerId = (string)($tenant['stripe_customer_id'] ?? '');
if (!$customerId) {
    json_error('PAYMENT_NOT_CONFIRMED', '決済情報が見つかりません', 402);
}

$subscription = _a5_find_subscription_by_token($secretKey, $customerId, $token);
if (!$subscription) {
    json_error('PAYMENT_NOT_CONFIRMED', '決済が完了していません。Stripe決済画面で支払いを完了してください', 402);
}

$subStatus = isset($subscription['status']) ? (string)$subscription['status'] : '';
if ($subStatus !== 'active' && $subStatus !== 'trialing') {
    error_log('[A5][activate] PAYMENT_NOT_CONFIRMED tenant=' . $tenant['id'] . ' status=' . $subStatus, 3, POSLA_PHP_ERROR_LOG);
    json_error('PAYMENT_NOT_CONFIRMED', '決済が完了していません (status=' . $subStatus . ')', 402);
}

$realSubId = isset($subscription['id']) ? (string)$subscription['id'] : '';
if (!$realSubId) {
    json_error('PAYMENT_NOT_CONFIRMED', '決済情報が不正です', 402);
}

// 有効化 (Stripe 側 status を反映 + pending:{token} を実 sub_xxx に置換)
$dbStatus = ($subStatus === 'trialing') ? 'trialing' : 'active';
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE tenants SET is_active = 1, subscription_status = ?, stripe_subscription_id = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$dbStatus, $realSubId, $tenant['id']]);
    $pdo->prepare('UPDATE stores SET is_active = 1, updated_at = NOW() WHERE tenant_id = ?')->execute([$tenant['id']]);
    $pdo->prepare("UPDATE users SET is_active = 1, updated_at = NOW() WHERE tenant_id = ? AND role = 'owner'")->execute([$tenant['id']]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[A5][activate] failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('ACTIVATE_FAILED', '有効化に失敗しました', 500);
}

try {
    posla_update_tenant_onboarding_status($pdo, (string)$tenant['id'], 'ready_for_cell', [
        'stripe_subscription_id' => $realSubId,
        'payment_confirmed_at' => date('Y-m-d H:i:s'),
        'activated_at' => date('Y-m-d H:i:s'),
        'notes' => 'Signup activated by fallback. Ready for cell provisioning.',
    ]);
    posla_trigger_cell_provisioner($pdo, (string)$tenant['id'], 'signup_activate');
} catch (Throwable $e) {
    error_log('[A5][activate] onboarding_update_failed tenant=' . $tenant['id'] . ' err=' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// メール送信 (ベストエフォート)
try {
    $uStmt = $pdo->prepare("SELECT u.email, u.username, u.display_name FROM users u WHERE u.tenant_id = ? AND u.role = 'owner' LIMIT 1");
    $uStmt->execute([$tenant['id']]);
    $u = $uStmt->fetch();
    if ($u && $u['email']) {
        _a5_send_welcome($u['email'], $u['display_name'] ?: $u['username'], $u['username'], $tenant['name'], $token);
    }
} catch (Exception $e) {
    error_log('[A5][activate] mail_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

json_response(['ok' => true, 'tenant_name' => $tenant['name']]);

/**
 * S3 #1: 指定 Customer の subscription 一覧を取得し、metadata.signup_token が一致するものを返す。
 * 見つからない / API エラー時は null を返す。
 */
function _a5_find_subscription_by_token($secretKey, $customerId, $token) {
    // status=all で trialing/incomplete/active/canceled を全て対象に
    $url = 'https://api.stripe.com/v1/subscriptions?customer=' . urlencode($customerId) . '&status=all&limit=20';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secretKey]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $code >= 400 || !$resp) {
        error_log('[A5][activate] stripe_list_subs_failed code=' . $code . ' err=' . $err, 3, POSLA_PHP_ERROR_LOG);
        return null;
    }
    $body = json_decode($resp, true);
    if (!$body || empty($body['data']) || !is_array($body['data'])) return null;
    foreach ($body['data'] as $sub) {
        $meta = isset($sub['metadata']) && is_array($sub['metadata']) ? $sub['metadata'] : [];
        if (isset($meta['signup_token']) && hash_equals((string)$meta['signup_token'], (string)$token)) {
            return $sub;
        }
    }
    return null;
}

function _a5_send_welcome($to, $displayName, $username, $storeName, $signupToken) {
    $subject = '【POSLA】お申込みありがとうございます';
    $statusUrl = app_url('/signup-complete.html') . '?t=' . urlencode((string)$signupToken);
    $body = "{$displayName} 様\n\nPOSLAにご登録ありがとうございます。\n\n"
          . "■ 店舗名: {$storeName}\n"
          . "■ ユーザー名: {$username}\n"
          . "■ パスワード: ご登録時のもの\n\n"
          . "30日間無料トライアルを開始しました。\n"
          . "現在、専用環境を準備しています。通常 3〜5 分ほどで完了します。\n\n"
          . "準備状況はこちらで確認できます:\n{$statusUrl}\n\n"
          . "準備完了後、ログインURLをメールでお送りします。\n\n--\nPOSLA 運営チーム";
    $result = posla_send_mail($to, $subject, $body, [
        'from_name' => posla_mail_default_from_name(),
        'from_email' => posla_mail_default_from_email(),
    ]);
    if (empty($result['success'])) {
        error_log('[A5][activate] welcome_mail_failed: ' . ($result['error'] ?? 'unknown'), 3, POSLA_PHP_ERROR_LOG);
    }
}
