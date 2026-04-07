<?php
/**
 * Stripe Connect ヘルパーライブラリ
 * L-13: Stripe Connect（決済代行）
 *
 * POSLA がプラットフォームとして Stripe Connect Express を運用するための関数群。
 * payment-gateway.php の curl ヘルパーを再利用する。
 */

require_once __DIR__ . '/payment-gateway.php';

/**
 * posla_settings からプラットフォーム Stripe 設定を取得
 * @param PDO $pdo
 * @return array ['secret_key' => '...', 'publishable_key' => '...', 'fee_percent' => float]
 */
function get_platform_stripe_config($pdo) {
    $keys = ['stripe_secret_key', 'stripe_publishable_key', 'connect_application_fee_percent'];
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM posla_settings WHERE setting_key IN (' . $ph . ')');
    $stmt->execute($keys);
    $rows = $stmt->fetchAll();

    $config = [
        'secret_key' => null,
        'publishable_key' => null,
        'fee_percent' => 1.0,
    ];
    foreach ($rows as $row) {
        if ($row['setting_key'] === 'stripe_secret_key') {
            $config['secret_key'] = $row['setting_value'];
        } elseif ($row['setting_key'] === 'stripe_publishable_key') {
            $config['publishable_key'] = $row['setting_value'];
        } elseif ($row['setting_key'] === 'connect_application_fee_percent') {
            $config['fee_percent'] = $row['setting_value'] !== null ? (float)$row['setting_value'] : 1.0;
        }
    }
    return $config;
}

/**
 * Stripe Connect Express Account を作成
 * @return array ['success' => bool, 'account_id' => string|null, 'error' => string|null]
 */
function create_connect_account($secretKey, $tenantName, $tenantEmail) {
    $url = 'https://api.stripe.com/v1/accounts';

    $params = [
        'type' => 'express',
        'country' => 'JP',
        'business_type' => 'company',
        'capabilities[card_payments][requested]' => 'true',
        'capabilities[transfers][requested]' => 'true',
        'business_profile[name]' => $tenantName,
    ];
    if ($tenantEmail) {
        $params['email'] = $tenantEmail;
    }

    $postFields = http_build_query($params);
    $headers = ['Authorization: Bearer ' . $secretKey];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'account_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'account_id' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    return [
        'success' => true,
        'account_id' => isset($body['id']) ? $body['id'] : null,
        'error' => null,
    ];
}

/**
 * Stripe Account Link を作成（オンボーディング用）
 * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
 */
function create_account_link($secretKey, $accountId, $refreshUrl, $returnUrl) {
    $url = 'https://api.stripe.com/v1/account_links';

    $postFields = http_build_query([
        'account' => $accountId,
        'type' => 'account_onboarding',
        'refresh_url' => $refreshUrl,
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
 * Stripe Connect Account 情報を取得
 * @return array ['success' => bool, 'charges_enabled' => bool, 'payouts_enabled' => bool, 'details_submitted' => bool, 'error' => string|null]
 */
function get_connect_account($secretKey, $accountId) {
    $url = 'https://api.stripe.com/v1/accounts/' . urlencode($accountId);
    $headers = ['Authorization: Bearer ' . $secretKey];

    $result = _gateway_curl_get($url, $headers);
    if ($result['curl_error']) {
        return ['success' => false, 'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false, 'error' => 'Stripe: ' . $errMsg];
    }

    return [
        'success' => true,
        'charges_enabled' => !empty($body['charges_enabled']),
        'payouts_enabled' => !empty($body['payouts_enabled']),
        'details_submitted' => !empty($body['details_submitted']),
        'error' => null,
    ];
}

/**
 * Stripe Terminal Connection Token を Connect Account 用に発行
 * @return array ['success' => bool, 'secret' => string|null, 'error' => string|null]
 */
function create_terminal_connection_token($secretKey, $connectAccountId) {
    $url = 'https://api.stripe.com/v1/terminal/connection_tokens';

    $headers = [
        'Authorization: Bearer ' . $secretKey,
        'Stripe-Account: ' . $connectAccountId,
    ];

    $result = _gateway_curl_post($url, '', $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'secret' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'secret' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    return [
        'success' => true,
        'secret' => isset($body['secret']) ? $body['secret'] : null,
        'error' => null,
    ];
}

/**
 * Application Fee を計算（切り上げ、最低1円）
 * @param int $amount 決済金額（円）
 * @param float $feePercent 手数料率（%）
 * @return int 手数料（円）
 */
function calculate_application_fee($amount, $feePercent) {
    $fee = (int)ceil($amount * $feePercent / 100);
    return max(1, $fee);
}

/**
 * テナントの Connect 設定を取得
 * @param PDO $pdo
 * @param string $tenantId
 * @return array|null ['stripe_connect_account_id' => '...', 'connect_onboarding_complete' => int] or null
 */
function get_tenant_connect_info($pdo, $tenantId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT stripe_connect_account_id, connect_onboarding_complete FROM tenants WHERE id = ?'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['stripe_connect_account_id'])) return null;
        return $row;
    } catch (PDOException $e) {
        // カラム未存在時（マイグレーション未適用）
        return null;
    }
}
