<?php
/**
 * A5: 新規申込 Stripe Webhook
 *
 * Stripe が checkout.session.completed を送信 → metadata.new_signup='1' を検出 →
 *   - tenants.is_active = 1
 *   - tenants.subscription_status = 'trialing'
 *   - stores.is_active = 1
 *   - users.is_active = 1
 *   - onboarding request を ready_for_cell に更新
 *   - 専用環境準備中メール送信
 *
 * 専用cell作成とログインURL送信は本番 provisioner ホストが行う。
 *
 * Stripe ダッシュボードで設定する URL:
 *   https://meal.posla.jp/api/signup/webhook.php
 *
 * 購読イベント: checkout.session.completed
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/tenant-onboarding.php';
require_once __DIR__ . '/../lib/provisioner-trigger.php';
require_once __DIR__ . '/../lib/mail.php';
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo '{"ok":false}'; exit;
}

$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$pdo = get_db();

// webhook secret
$secret = null;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM posla_settings WHERE setting_key IN ('stripe_webhook_secret_signup','stripe_webhook_secret')");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $r) {
        if ($r['setting_value']) $secret = $r['setting_value']; // 後勝ち OK
    }
} catch (PDOException $e) {
    error_log('[A5][webhook] secret_load_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// S3 #2: secret 未設定時は fail-close (偽 webhook で勝手にテナントを開通されるのを防ぐ)
if (!$secret) {
    error_log('[A5][webhook] FAIL_CLOSE: stripe_webhook_secret_signup not configured — request rejected', 3, POSLA_PHP_ERROR_LOG);
    http_response_code(503); echo '{"ok":false,"error":"webhook_secret_not_configured"}'; exit;
}
if (!_a5_verify_stripe_sig($payload, $sig, $secret)) {
    http_response_code(401); echo '{"ok":false,"error":"bad_sig"}'; exit;
}

$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) { http_response_code(400); echo '{"ok":false}'; exit; }

$type = $event['type'];
$obj = $event['data']['object'] ?? [];

// 新規申込以外のイベントは既存 subscription/webhook.php に任せる
$meta = $obj['metadata'] ?? [];
$subMeta = $obj['subscription_details']['metadata'] ?? ($obj['metadata'] ?? []);
$isNewSignup = (isset($meta['new_signup']) && $meta['new_signup'] === '1')
            || (isset($subMeta['new_signup']) && $subMeta['new_signup'] === '1');

if (!$isNewSignup) {
    // 他 Webhook への委譲先が必要なら呼び出し、今は 200 で抜ける
    http_response_code(200); echo '{"ok":true,"note":"not_new_signup"}'; exit;
}

$tenantId = $meta['tenant_id'] ?? ($subMeta['tenant_id'] ?? null);
$signupToken = $meta['signup_token'] ?? ($subMeta['signup_token'] ?? null);
if (!$tenantId) { http_response_code(200); echo '{"ok":true,"note":"no_tenant"}'; exit; }

if ($type === 'checkout.session.completed') {
    $subscriptionId = $obj['subscription'] ?? null;
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE tenants SET subscription_status = 'trialing', stripe_subscription_id = ?, is_active = 1, updated_at = NOW() WHERE id = ?")
            ->execute([$subscriptionId ?: null, $tenantId]);
        $pdo->prepare('UPDATE stores SET is_active = 1, updated_at = NOW() WHERE tenant_id = ?')->execute([$tenantId]);
        $pdo->prepare("UPDATE users SET is_active = 1, updated_at = NOW() WHERE tenant_id = ? AND role = 'owner'")->execute([$tenantId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('[A5][webhook] activation_failed tenant=' . $tenantId . ' err=' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        http_response_code(200); echo '{"ok":true,"note":"activation_retry_needed"}'; exit;
    }

    try {
        posla_update_tenant_onboarding_status($pdo, (string)$tenantId, 'ready_for_cell', [
            'stripe_subscription_id' => $subscriptionId ?: null,
            'payment_confirmed_at' => date('Y-m-d H:i:s'),
            'activated_at' => date('Y-m-d H:i:s'),
            'notes' => 'Stripe checkout completed. Ready for cell provisioning.',
        ]);
        posla_trigger_cell_provisioner($pdo, (string)$tenantId, 'signup_webhook');
    } catch (Throwable $e) {
        error_log('[A5][webhook] onboarding_update_failed tenant=' . $tenantId . ' err=' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }

    // メール送信
    $tStmt = $pdo->prepare('SELECT t.name, u.email, u.display_name, u.username FROM tenants t JOIN users u ON u.tenant_id = t.id AND u.role = \'owner\' WHERE t.id = ? LIMIT 1');
    $tStmt->execute([$tenantId]);
    $row = $tStmt->fetch();
    if ($row && $row['email']) {
        _a5_send_welcome_mail($row['email'], $row['display_name'], $row['username'], $row['name'], $signupToken);
    }
}

http_response_code(200);
echo '{"ok":true}';

function _a5_verify_stripe_sig($payload, $header, $secret) {
    if (!$header) return false;
    // S3 #16: t=, v1= は複数現れうるため、v1 を配列で集める
    $timestamp = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') {
            $timestamp = $kv[1];
        } elseif ($kv[0] === 'v1') {
            $sigs[] = $kv[1];
        }
    }
    if (!$timestamp || empty($sigs)) return false;
    // S3 #16: Stripe 推奨の 5 分タイムスタンプ許容 (replay 攻撃対策)
    if (abs(time() - (int)$timestamp) > 300) {
        error_log('[S3#16][a5-webhook] timestamp_out_of_tolerance t=' . $timestamp . ' now=' . time(), 3, POSLA_PHP_ERROR_LOG);
        return false;
    }
    $signed = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

function _a5_send_welcome_mail($to, $displayName, $username, $storeName, $signupToken) {
    $subject = '【POSLA】お申込みありがとうございます — 専用環境を準備しています';
    $statusUrl = app_url('/signup-complete.html') . '?t=' . urlencode((string)$signupToken);
    $body = "{$displayName} 様\n\n"
          . "POSLA にご登録いただきありがとうございます。\n"
          . "30日間無料トライアルを開始しました。\n"
          . "現在、専用環境を準備しています。通常 3〜5 分ほどで完了します。\n\n"
          . "■ 店舗名: {$storeName}\n"
          . "■ ユーザー名: {$username}\n"
          . "■ パスワード: ご登録時に設定されたもの\n"
          . "■ 準備状況: {$statusUrl}\n\n"
          . "準備完了後、ログインURLをメールでお送りします。\n"
          . "期間内のキャンセルで請求は発生しません。\n\n"
          . "ご不明な点は " . posla_mail_default_support_email() . " までお気軽にお問い合わせください。\n\n"
          . "--\nPOSLA 運営チーム";
    $result = posla_send_mail($to, $subject, $body, [
        'from_name' => posla_mail_default_from_name(),
        'from_email' => posla_mail_default_from_email(),
    ]);
    if (empty($result['success'])) {
        error_log('[A5][webhook] welcome_mail_failed: ' . ($result['error'] ?? 'unknown'), 3, POSLA_PHP_ERROR_LOG);
    }
}
