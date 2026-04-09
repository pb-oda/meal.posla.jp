<?php
/**
 * Stripe Webhook 受信エンドポイント
 * L-11: Stripe からの非同期イベントを受信し DB を更新する
 *
 * POST /api/subscription/webhook.php
 * 認証なし（Stripe からの直接呼び出し）
 * Stripe-Signature ヘッダーで署名検証を行う
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/stripe-billing.php';

// OPTIONSプリフライト対応
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    handle_preflight();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'POST のみ対応しています', 405);
}

$pdo = get_db();

// 生のリクエストボディを取得
$payload = file_get_contents('php://input');
if (!$payload) {
    json_error('EMPTY_BODY', 'リクエストボディが空です', 400);
}

// Stripe 署名検証
$sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
$config = get_stripe_config($pdo);
$webhookSecret = isset($config['stripe_webhook_secret']) ? $config['stripe_webhook_secret'] : '';

if (!verify_webhook_signature($payload, $sigHeader, $webhookSecret)) {
    json_error('INVALID_SIGNATURE', 'Webhook署名の検証に失敗しました', 400);
}

// イベント解析
$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) {
    json_error('INVALID_PAYLOAD', 'イベントデータが不正です', 400);
}

$eventType = $event['type'];
$dataObject = isset($event['data']['object']) ? $event['data']['object'] : [];
$stripeEventId = isset($event['id']) ? $event['id'] : null;

// P1-11: 冪等性チェック — 同じ stripe_event_id を既に処理済みなら 200 即返却
// （Stripe は最大3日間 webhook をリトライするため、二重処理による plan 誤ロールバック防止）
if ($stripeEventId) {
    try {
        $checkStmt = $pdo->prepare('SELECT id FROM subscription_events WHERE stripe_event_id = ? LIMIT 1');
        $checkStmt->execute([$stripeEventId]);
        if ($checkStmt->fetch()) {
            error_log('[P1-25][api/subscription/webhook.php:58] webhook_duplicate_event_ignored: ' . $stripeEventId, 3, '/home/odah/log/php_errors.log');
            send_api_headers();
            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'data' => ['status' => 'duplicate_ignored'],
                'serverTime' => date('c'),
            ]);
            exit;
        }
    } catch (Exception $e) {
        error_log('[P1-25][api/subscription/webhook.php:69] idempotency_check_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        // チェック失敗時は通常処理に進ませる（既存挙動を壊さない）
    }
}

// テナント特定用関数
$findTenantByCustomerId = function($customerId) use ($pdo) {
    if (!$customerId) return null;
    $stmt = $pdo->prepare('SELECT id, plan FROM tenants WHERE stripe_customer_id = ?');
    $stmt->execute([$customerId]);
    return $stmt->fetch();
};

$findTenantBySubscriptionId = function($subscriptionId) use ($pdo) {
    if (!$subscriptionId) return null;
    $stmt = $pdo->prepare('SELECT id, plan FROM tenants WHERE stripe_subscription_id = ?');
    $stmt->execute([$subscriptionId]);
    return $stmt->fetch();
};

$tenantId = null;

// イベントタイプ別処理
// P1-25: switch 全体を try-catch で囲み、ハンドラ内の例外発生時は 500 を返して Stripe にリトライさせる
try {
switch ($eventType) {

    // ── checkout.session.completed ──
    case 'checkout.session.completed':
        $customerId = isset($dataObject['customer']) ? $dataObject['customer'] : null;
        $subscriptionId = isset($dataObject['subscription']) ? $dataObject['subscription'] : null;

        $tenant = $findTenantByCustomerId($customerId);
        if (!$tenant) {
            error_log('[P1-25][api/subscription/webhook.php:100] tenant_not_found: ' . $eventType . ' stripe_customer_id=' . $customerId, 3, '/home/odah/log/php_errors.log');
            break;
        }
        $tenantId = $tenant['id'];

        if ($subscriptionId) {
            // Stripe API でサブスクリプション詳細を取得
            $secretKey = isset($config['stripe_secret_key']) ? $config['stripe_secret_key'] : '';
            $newPlan = null;
            $periodEnd = null;

            if ($secretKey) {
                $subResult = get_subscription($secretKey, $subscriptionId);
                if ($subResult['success'] && $subResult['data']) {
                    $subData = $subResult['data'];

                    // price_id からプラン判定
                    $priceId = null;
                    if (isset($subData['items']['data'][0]['price']['id'])) {
                        $priceId = $subData['items']['data'][0]['price']['id'];
                    }
                    if ($priceId) {
                        $newPlan = resolve_plan_from_price($priceId, $config);
                    }

                    if (isset($subData['current_period_end'])) {
                        $periodEnd = date('Y-m-d H:i:s', (int)$subData['current_period_end']);
                    }
                }
            }

            // DB更新
            $sets = ['stripe_subscription_id = ?', 'subscription_status = ?'];
            $params = [$subscriptionId, 'active'];

            if ($newPlan) {
                $sets[] = 'plan = ?';
                $params[] = $newPlan;
            }
            if ($periodEnd) {
                $sets[] = 'current_period_end = ?';
                $params[] = $periodEnd;
            }

            $params[] = $tenantId;
            $sql = 'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute($params);
        }
        break;

    // ── customer.subscription.updated ──
    case 'customer.subscription.updated':
        $subscriptionId = isset($dataObject['id']) ? $dataObject['id'] : null;

        $tenant = $findTenantBySubscriptionId($subscriptionId);
        if (!$tenant) {
            error_log('[P1-25][api/subscription/webhook.php:153] tenant_not_found: ' . $eventType . ' stripe_subscription_id=' . $subscriptionId, 3, '/home/odah/log/php_errors.log');
            break;
        }
        $tenantId = $tenant['id'];

        $sets = [];
        $params = [];

        // status更新
        $stripeStatus = isset($dataObject['status']) ? $dataObject['status'] : null;
        if ($stripeStatus) {
            $statusMap = [
                'active'   => 'active',
                'past_due' => 'past_due',
                'canceled' => 'canceled',
                'trialing' => 'trialing',
            ];
            $mappedStatus = isset($statusMap[$stripeStatus]) ? $statusMap[$stripeStatus] : 'none';
            $sets[] = 'subscription_status = ?';
            $params[] = $mappedStatus;
        }

        // current_period_end更新
        if (isset($dataObject['current_period_end'])) {
            $sets[] = 'current_period_end = ?';
            $params[] = date('Y-m-d H:i:s', (int)$dataObject['current_period_end']);
        }

        // price_id からプラン判定
        $priceId = null;
        if (isset($dataObject['items']['data'][0]['price']['id'])) {
            $priceId = $dataObject['items']['data'][0]['price']['id'];
        }
        if ($priceId) {
            $newPlan = resolve_plan_from_price($priceId, $config);
            if ($newPlan) {
                $sets[] = 'plan = ?';
                $params[] = $newPlan;
            }
        }

        if (!empty($sets)) {
            $params[] = $tenantId;
            $sql = 'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute($params);
        }
        break;

    // ── customer.subscription.deleted ──
    case 'customer.subscription.deleted':
        $subscriptionId = isset($dataObject['id']) ? $dataObject['id'] : null;

        $tenant = $findTenantBySubscriptionId($subscriptionId);
        if (!$tenant) {
            error_log('[P1-25][api/subscription/webhook.php:204] tenant_not_found: ' . $eventType . ' stripe_subscription_id=' . $subscriptionId, 3, '/home/odah/log/php_errors.log');
            break;
        }
        $tenantId = $tenant['id'];

        // subscription_status = 'canceled' にする（planはそのまま）
        $stmt = $pdo->prepare('UPDATE tenants SET subscription_status = ? WHERE id = ?');
        $stmt->execute(['canceled', $tenantId]);
        break;

    // ── invoice.paid / invoice.payment_failed ──
    case 'invoice.paid':
    case 'invoice.payment_failed':
        // テナント特定（invoiceのcustomerから）
        $customerId = isset($dataObject['customer']) ? $dataObject['customer'] : null;
        $tenant = $findTenantByCustomerId($customerId);
        if ($tenant) {
            $tenantId = $tenant['id'];
        }
        // ログ記録のみ（下のイベントログ処理で保存）
        break;
}
} catch (\Exception $e) {
    // P1-25: ハンドラ内の例外は 500 を返し Stripe にリトライさせる
    error_log('[P1-25][api/subscription/webhook.php] event_dispatch_failed: ' . $eventType . ': ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    send_api_headers();
    http_response_code(500);
    echo json_encode(['error' => 'temporary_failure']);
    exit;
}

// ── 全イベントを subscription_events に記録 ──
// P1-25: tenant_id は NOT NULL のため、テナント未特定時は INSERT をスキップしてログに残す。
//       INSERT 失敗は 200 を返す（Stripe のリトライループを避け、恒久的なログ欠損は監視で検知する）
$eventId = generate_uuid();
$eventData = json_encode($dataObject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($tenantId) {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO subscription_events (id, tenant_id, event_type, stripe_event_id, data)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$eventId, $tenantId, $eventType, $stripeEventId, $eventData]);
    } catch (\PDOException $e) {
        error_log('[P1-25][api/subscription/webhook.php] event_log_insert_failed: ' . $eventType . ': ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }
} else {
    error_log('[P1-25][api/subscription/webhook.php] event_log_skipped_no_tenant: ' . $eventType . ' stripe_event_id=' . ($stripeEventId ?: 'none'), 3, '/home/odah/log/php_errors.log');
}

// Stripe にはリトライを防ぐため必ず 200 を返す
send_api_headers();
http_response_code(200);
echo json_encode(['received' => true]);
exit;
