<?php
/**
 * Stripe Billing ヘルパーライブラリ
 * L-11: サブスクリプション課金基盤
 *
 * posla_settings テーブルの Stripe キーを使用し、
 * payment-gateway.php の curl ヘルパーで Stripe API を呼び出す。
 */

require_once __DIR__ . '/payment-gateway.php';

/**
 * posla_settings から stripe_ で始まるキーを取得
 * @param PDO $pdo
 * @return array ['stripe_secret_key' => '...', 'stripe_price_standard' => '...', ...]
 */
function get_stripe_config($pdo) {
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM posla_settings WHERE setting_key LIKE ?');
    $stmt->execute(['stripe_%']);
    $rows = $stmt->fetchAll();
    $config = [];
    foreach ($rows as $row) {
        $config[$row['setting_key']] = $row['setting_value'];
    }
    return $config;
}

/**
 * Stripe Customer を作成
 * @param string $secretKey
 * @param string $tenantName
 * @param string|null $tenantEmail
 * @param string $tenantId
 * @return array ['success' => bool, 'customer_id' => string|null, 'error' => string|null]
 */
function create_stripe_customer($secretKey, $tenantName, $tenantEmail, $tenantId) {
    $url = 'https://api.stripe.com/v1/customers';

    $params = [
        'name' => $tenantName,
        'metadata[tenant_id]' => $tenantId,
    ];
    if ($tenantEmail) {
        $params['email'] = $tenantEmail;
    }

    $postFields = http_build_query($params);
    $headers = ['Authorization: Bearer ' . $secretKey];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'customer_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'customer_id' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    $customerId = isset($body['id']) ? $body['id'] : null;
    return ['success' => true, 'customer_id' => $customerId, 'error' => null];
}

/**
 * Stripe Checkout Session を作成（サブスクリプション用）
 * @param string $secretKey
 * @param string $customerId
 * @param string $priceId
 * @param string $successUrl
 * @param string $cancelUrl
 * @return array ['success' => bool, 'url' => string|null, 'session_id' => string|null, 'error' => string|null]
 */
function create_billing_checkout_session($secretKey, $customerId, $priceId, $successUrl, $cancelUrl) {
    $url = 'https://api.stripe.com/v1/checkout/sessions';

    $postFields = http_build_query([
        'mode' => 'subscription',
        'customer' => $customerId,
        'line_items[0][price]' => $priceId,
        'line_items[0][quantity]' => 1,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ]);

    $headers = ['Authorization: Bearer ' . $secretKey];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'url' => null, 'session_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'url' => null, 'session_id' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    return [
        'success' => true,
        'url' => isset($body['url']) ? $body['url'] : null,
        'session_id' => isset($body['id']) ? $body['id'] : null,
        'error' => null,
    ];
}

/**
 * Stripe Customer Portal Session を作成
 * @param string $secretKey
 * @param string $customerId
 * @param string $returnUrl
 * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
 */
function create_portal_session($secretKey, $customerId, $returnUrl) {
    $url = 'https://api.stripe.com/v1/billing_portal/sessions';

    $postFields = http_build_query([
        'customer' => $customerId,
        'return_url' => $returnUrl,
    ]);

    $headers = ['Authorization: Bearer ' . $secretKey];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'url' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'url' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    return [
        'success' => true,
        'url' => isset($body['url']) ? $body['url'] : null,
        'error' => null,
    ];
}

/**
 * Stripe Subscription を取得
 * @param string $secretKey
 * @param string $subscriptionId
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
 */
function get_subscription($secretKey, $subscriptionId) {
    $url = 'https://api.stripe.com/v1/subscriptions/' . urlencode($subscriptionId);
    $headers = ['Authorization: Bearer ' . $secretKey];

    $result = _gateway_curl_get($url, $headers);
    if ($result['curl_error']) {
        return ['success' => false, 'data' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'data' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    return ['success' => true, 'data' => $body, 'error' => null];
}

/**
 * Stripe Webhook 署名検証
 * @param string $payload  生のリクエストボディ
 * @param string $sigHeader  Stripe-Signature ヘッダー値
 * @param string $webhookSecret  Webhook Secret (whsec_xxx)
 * @return bool
 */
function verify_webhook_signature($payload, $sigHeader, $webhookSecret) {
    if (!$sigHeader || !$webhookSecret) return false;

    // Stripe-Signature ヘッダーを解析 (t=...,v1=...)
    $parts = explode(',', $sigHeader);
    $timestamp = null;
    $signatures = [];

    foreach ($parts as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) continue;
        if ($pair[0] === 't') {
            $timestamp = $pair[1];
        } elseif ($pair[0] === 'v1') {
            $signatures[] = $pair[1];
        }
    }

    if (!$timestamp || empty($signatures)) return false;

    // タイムスタンプが5分以内か確認
    $now = time();
    if (abs($now - (int)$timestamp) > 300) return false;

    // 署名を計算して比較
    $signedPayload = $timestamp . '.' . $payload;
    $expectedSig = hash_hmac('sha256', $signedPayload, $webhookSecret);

    foreach ($signatures as $sig) {
        if (hash_equals($expectedSig, $sig)) {
            return true;
        }
    }

    return false;
}

/**
 * Stripe price_id から POSLA プラン名に変換
 * @param string $priceId
 * @param array $config  get_stripe_config() の戻り値
 * @return string|null  'standard'|'pro'|'enterprise' or null
 */
function resolve_plan_from_price($priceId, $config) {
    $map = [
        'stripe_price_standard'   => 'standard',
        'stripe_price_pro'        => 'pro',
        'stripe_price_enterprise' => 'enterprise',
    ];
    foreach ($map as $key => $plan) {
        if (isset($config[$key]) && $config[$key] === $priceId) {
            return $plan;
        }
    }
    return null;
}
