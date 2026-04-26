<?php
/**
 * Signup provisioning status.
 *
 * POST /api/signup/provisioning-status.php
 *   body: { signup_token }
 *
 * The token is the same opaque token attached to signup-complete.html.
 * It lets the applicant poll only their own onboarding request while the
 * dedicated cell is being created by the host-side provisioner.
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../config/app.php';

require_method(['POST']);
check_rate_limit('signup-provisioning-status:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 120, 600);

$body = get_json_body();
$token = trim((string)($body['signup_token'] ?? ''));
if ($token === '' || strlen($token) > 200) {
    json_error('MISSING_TOKEN', 'signup_token が必要です', 400);
}

$pdo = get_db();
$tokenHash = hash('sha256', $token);

$stmt = $pdo->prepare(
    'SELECT r.request_id, r.request_source, r.status AS onboarding_status,
            r.tenant_id, r.tenant_slug, r.tenant_name, r.cell_id,
            r.payment_confirmed_at, r.provisioned_at, r.activated_at,
            r.updated_at, r.last_error,
            c.status AS registry_status, c.app_base_url, c.health_url, c.last_ping_at
       FROM posla_tenant_onboarding_requests r
       LEFT JOIN posla_cell_registry c ON c.cell_id = r.cell_id
      WHERE r.signup_token_sha256 = ?
      LIMIT 1'
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

if (!$row) {
    json_error('INVALID_TOKEN', '不明なトークンです', 404);
}

$onboardingStatus = (string)($row['onboarding_status'] ?? '');
$registryStatus = (string)($row['registry_status'] ?? '');
$appBaseUrl = trim((string)($row['app_base_url'] ?? ''));
$publicStatus = 'preparing';
$message = '専用環境を準備しています。通常 3〜5 分ほどで完了します。';
$loginUrl = null;
$refreshAfterMs = 5000;

if ($onboardingStatus === 'payment_pending' || $onboardingStatus === 'received') {
    $publicStatus = 'payment_pending';
    $message = 'Stripe の決済完了を確認しています。画面を閉じずに少しお待ちください。';
    $refreshAfterMs = 3000;
} elseif ($onboardingStatus === 'ready_for_cell') {
    $publicStatus = 'queued';
    $message = '専用環境の作成待ちです。順番に自動準備しています。';
} elseif ($onboardingStatus === 'cell_provisioning') {
    $publicStatus = 'preparing';
    $message = '専用環境を作成しています。完了後、この画面にログインボタンが表示されます。';
} elseif ($onboardingStatus === 'active') {
    if ($registryStatus === 'active' && $appBaseUrl !== '') {
        $publicStatus = 'ready';
        $message = '専用環境の準備が完了しました。ログインして初期設定を始められます。';
        $loginUrl = rtrim($appBaseUrl, '/') . '/admin/index.html';
        $refreshAfterMs = 0;
    } else {
        $publicStatus = 'preparing';
        $message = 'アカウント作成は完了しています。ログインURLの反映を確認しています。';
    }
} elseif ($onboardingStatus === 'failed') {
    $publicStatus = 'delayed';
    $message = '専用環境の準備に時間がかかっています。POSLA運営が確認します。';
    $refreshAfterMs = 15000;
} elseif ($onboardingStatus === 'canceled') {
    $publicStatus = 'canceled';
    $message = '申込みが完了していません。もう一度お申込みください。';
    $refreshAfterMs = 0;
}

json_response([
    'status' => $publicStatus,
    'onboarding_status' => $onboardingStatus,
    'registry_status' => $registryStatus ?: null,
    'tenant_name' => $row['tenant_name'] ?? null,
    'tenant_slug' => $row['tenant_slug'] ?? null,
    'cell_id' => $row['cell_id'] ?? null,
    'message' => $message,
    'login_url' => $loginUrl,
    'health_url' => $row['health_url'] ?? null,
    'last_ping_at' => $row['last_ping_at'] ?? null,
    'payment_confirmed_at' => $row['payment_confirmed_at'] ?? null,
    'provisioned_at' => $row['provisioned_at'] ?? null,
    'activated_at' => $row['activated_at'] ?? null,
    'refresh_after_ms' => $refreshAfterMs,
]);
