<?php
/**
 * 決済ゲートウェイライブラリ
 * C-3: Square / Stripe 連携
 *
 * process-payment.php から呼び出される
 */

/**
 * テナントの決済ゲートウェイ設定を取得
 * @return array|null ['gateway' => 'square'|'stripe'|'none', 'token' => '...']
 */
function get_payment_gateway_config($pdo, $tenantId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT payment_gateway, square_access_token, stripe_secret_key FROM tenants WHERE id = ?'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $gw = $row['payment_gateway'] ?: 'none';
        if ($gw === 'none') return ['gateway' => 'none', 'token' => null];

        $token = null;
        if ($gw === 'square') {
            $token = $row['square_access_token'];
        } elseif ($gw === 'stripe') {
            $token = $row['stripe_secret_key'];
        }

        if (!$token) return ['gateway' => 'none', 'token' => null];

        return ['gateway' => $gw, 'token' => $token];
    } catch (PDOException $e) {
        // カラム未存在時（マイグレーション未適用）
        return null;
    }
}

/**
 * Square Payments API で決済を実行
 * @return array ['success' => bool, 'external_id' => string|null, 'status' => string|null, 'error' => string|null]
 */
function execute_square_payment($accessToken, $amountYen, $currency, $referenceId) {
    $url = 'https://connect.squareup.com/v2/payments';
    $payload = json_encode([
        'source_id'       => 'EXTERNAL',
        'idempotency_key' => $referenceId,
        'amount_money'    => [
            'amount'   => (int)$amountYen,
            'currency' => strtoupper($currency),
        ],
        'reference_id' => $referenceId,
        'note'         => 'POSLA POS Payment',
    ]);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Square-Version: 2024-01-18',
    ];

    $result = _gateway_curl_post($url, $payload, $headers);
    if ($result['curl_error']) {
        return ['success' => false, 'external_id' => null, 'status' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = '不明なエラー';
        if (isset($body['errors'][0]['detail'])) {
            $errMsg = $body['errors'][0]['detail'];
        } elseif (isset($body['errors'][0]['code'])) {
            $errMsg = $body['errors'][0]['code'];
        }
        return ['success' => false, 'external_id' => null, 'status' => null, 'error' => 'Square: ' . $errMsg];
    }

    $payment = isset($body['payment']) ? $body['payment'] : [];
    $status = isset($payment['status']) ? $payment['status'] : '';
    $payId = isset($payment['id']) ? $payment['id'] : '';

    if ($status === 'COMPLETED' || $status === 'APPROVED') {
        return ['success' => true, 'external_id' => $payId, 'status' => $status, 'error' => null];
    }

    return ['success' => false, 'external_id' => $payId, 'status' => $status, 'error' => 'Square: ステータス ' . $status];
}

/**
 * Stripe PaymentIntents API で決済を実行
 * @return array ['success' => bool, 'external_id' => string|null, 'status' => string|null, 'error' => string|null]
 */
function execute_stripe_payment($secretKey, $amountYen, $currency, $referenceId) {
    $url = 'https://api.stripe.com/v1/payment_intents';
    $postFields = http_build_query([
        'amount'                    => (int)$amountYen,
        'currency'                  => strtolower($currency),
        'payment_method_types'      => ['card_present'],
        'capture_method'            => 'automatic',
        'metadata'                  => ['posla_payment_id' => $referenceId],
    ]);

    $headers = [
        'Authorization: Bearer ' . $secretKey,
    ];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'external_id' => null, 'status' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = '不明なエラー';
        if (isset($body['error']['message'])) {
            $errMsg = $body['error']['message'];
        } elseif (isset($body['error']['code'])) {
            $errMsg = $body['error']['code'];
        }
        return ['success' => false, 'external_id' => null, 'status' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    $status = isset($body['status']) ? $body['status'] : '';
    $piId = isset($body['id']) ? $body['id'] : '';

    if ($status === 'succeeded' || $status === 'requires_capture') {
        return ['success' => true, 'external_id' => $piId, 'status' => $status, 'error' => null];
    }

    return ['success' => false, 'external_id' => $piId, 'status' => $status, 'error' => 'Stripe: ステータス ' . $status];
}

/**
 * テナントの決済ゲートウェイ設定を取得（square_location_id 付き）
 * @return array|null ['gateway' => '...', 'token' => '...', 'location_id' => '...']
 */
function get_payment_gateway_config_full($pdo, $tenantId) {
    try {
        $cols = 'payment_gateway, square_access_token, stripe_secret_key';
        $hasLocId = false;
        try {
            $pdo->query('SELECT square_location_id FROM tenants LIMIT 0');
            $cols .= ', square_location_id';
            $hasLocId = true;
        } catch (PDOException $e) {}

        $stmt = $pdo->prepare('SELECT ' . $cols . ' FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $gw = $row['payment_gateway'] ?: 'none';
        if ($gw === 'none') return ['gateway' => 'none', 'token' => null, 'location_id' => null];

        $token = null;
        if ($gw === 'square') {
            $token = $row['square_access_token'];
        } elseif ($gw === 'stripe') {
            $token = $row['stripe_secret_key'];
        }

        if (!$token) return ['gateway' => 'none', 'token' => null, 'location_id' => null];

        return [
            'gateway' => $gw,
            'token' => $token,
            'location_id' => $hasLocId ? ($row['square_location_id'] ?? null) : null
        ];
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Stripe Checkout Session を作成（オンライン決済用）
 * @return array ['success' => bool, 'checkout_url' => string|null, 'session_id' => string|null, 'error' => string|null]
 */
function create_stripe_checkout_session($secretKey, $amountYen, $currency, $orderName, $successUrl, $cancelUrl) {
    $url = 'https://api.stripe.com/v1/checkout/sessions';
    $postFields = http_build_query([
        'mode' => 'payment',
        'line_items[0][price_data][currency]' => strtolower($currency),
        'line_items[0][price_data][unit_amount]' => (int)$amountYen,
        'line_items[0][price_data][product_data][name]' => $orderName,
        'line_items[0][quantity]' => 1,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ]);

    $headers = [
        'Authorization: Bearer ' . $secretKey,
    ];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'checkout_url' => null, 'session_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'checkout_url' => null, 'session_id' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    return [
        'success' => true,
        'checkout_url' => isset($body['url']) ? $body['url'] : null,
        'session_id' => isset($body['id']) ? $body['id'] : null,
        'error' => null
    ];
}

/**
 * Square Payment Link を作成（オンライン決済用）
 * @return array ['success' => bool, 'checkout_url' => string|null, 'link_id' => string|null, 'error' => string|null]
 */
function create_square_payment_link($accessToken, $amountYen, $currency, $orderName, $locationId, $redirectUrl) {
    $url = 'https://connect.squareup.com/v2/online-checkout/payment-links';
    $payload = json_encode([
        'idempotency_key' => uniqid('sq_', true),
        'quick_pay' => [
            'name' => $orderName,
            'price_money' => [
                'amount' => (int)$amountYen,
                'currency' => strtoupper($currency),
            ],
            'location_id' => $locationId,
        ],
        'checkout_options' => [
            'redirect_url' => $redirectUrl,
        ],
    ]);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Square-Version: 2024-01-18',
    ];

    $result = _gateway_curl_post($url, $payload, $headers);
    if ($result['curl_error']) {
        return ['success' => false, 'checkout_url' => null, 'link_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = '不明なエラー';
        if (isset($body['errors'][0]['detail'])) {
            $errMsg = $body['errors'][0]['detail'];
        } elseif (isset($body['errors'][0]['code'])) {
            $errMsg = $body['errors'][0]['code'];
        }
        return ['success' => false, 'checkout_url' => null, 'link_id' => null, 'error' => 'Square: ' . $errMsg];
    }

    $link = isset($body['payment_link']) ? $body['payment_link'] : [];
    return [
        'success' => true,
        'checkout_url' => isset($link['url']) ? $link['url'] : null,
        'link_id' => isset($link['id']) ? $link['id'] : null,
        'error' => null
    ];
}

/**
 * Stripe Checkout Session の決済状態を確認
 * @return array ['success' => bool, 'payment_status' => string|null, 'payment_intent_id' => string|null, 'error' => string|null]
 */
function verify_stripe_checkout($secretKey, $sessionId) {
    $url = 'https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId);

    $result = _gateway_curl_get($url, ['Authorization: Bearer ' . $secretKey]);
    if ($result['curl_error']) {
        return ['success' => false, 'payment_status' => null, 'payment_intent_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'payment_status' => null, 'payment_intent_id' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    $status = isset($body['payment_status']) ? $body['payment_status'] : '';
    $piId = isset($body['payment_intent']) ? $body['payment_intent'] : '';
    $paid = ($status === 'paid');

    return [
        'success' => $paid,
        'payment_status' => $status,
        'payment_intent_id' => $piId,
        'error' => $paid ? null : 'Stripe: 決済ステータス ' . $status
    ];
}

/**
 * Square Payment の決済状態を確認
 * @return array ['success' => bool, 'status' => string|null, 'payment_id' => string|null, 'error' => string|null]
 */
function verify_square_payment($accessToken, $transactionId) {
    $url = 'https://connect.squareup.com/v2/payments/' . urlencode($transactionId);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Square-Version: 2024-01-18',
    ];

    $result = _gateway_curl_get($url, $headers);
    if ($result['curl_error']) {
        return ['success' => false, 'status' => null, 'payment_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = '不明なエラー';
        if (isset($body['errors'][0]['detail'])) $errMsg = $body['errors'][0]['detail'];
        return ['success' => false, 'status' => null, 'payment_id' => null, 'error' => 'Square: ' . $errMsg];
    }

    $payment = isset($body['payment']) ? $body['payment'] : [];
    $status = isset($payment['status']) ? $payment['status'] : '';
    $payId = isset($payment['id']) ? $payment['id'] : '';
    $completed = ($status === 'COMPLETED' || $status === 'APPROVED');

    return [
        'success' => $completed,
        'status' => $status,
        'payment_id' => $payId,
        'error' => $completed ? null : 'Square: ステータス ' . $status
    ];
}

/**
 * cURL GET 共通ヘルパー
 * @return array ['http_code' => int, 'body' => string, 'curl_error' => string|null]
 */
function _gateway_curl_get($url, $headers) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    return ['http_code' => $httpCode, 'body' => $body, 'curl_error' => $curlErr];
}

/**
 * cURL POST 共通ヘルパー
 * @param bool $isFormUrlEncoded  true の場合 application/x-www-form-urlencoded
 * @return array ['http_code' => int, 'body' => string, 'curl_error' => string|null]
 */
function _gateway_curl_post($url, $data, $headers, $isFormUrlEncoded = false) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    return ['http_code' => $httpCode, 'body' => $body, 'curl_error' => $curlErr];
}

/**
 * L-13: Stripe Connect Destination Charges で対面決済を実行
 * @param string $platformSecretKey  POSLAプラットフォームの Secret Key
 * @param string $connectAccountId   テナントの Connect Account ID (acct_...)
 * @param int $amountYen             決済金額（円）
 * @param string $currency           通貨コード
 * @param int $applicationFee        手数料（円）
 * @param string $referenceId        POSLA決済ID
 * @return array ['success' => bool, 'external_id' => string|null, 'status' => string|null, 'error' => string|null]
 */
function execute_stripe_connect_payment($platformSecretKey, $connectAccountId, $amountYen, $currency, $applicationFee, $referenceId) {
    $url = 'https://api.stripe.com/v1/payment_intents';
    $postFields = http_build_query([
        'amount'                              => (int)$amountYen,
        'currency'                            => strtolower($currency),
        'payment_method_types'                => ['card_present'],
        'capture_method'                      => 'automatic',
        'application_fee_amount'              => (int)$applicationFee,
        'transfer_data[destination]'          => $connectAccountId,
        'metadata[posla_payment_id]'          => $referenceId,
    ]);

    $headers = [
        'Authorization: Bearer ' . $platformSecretKey,
    ];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'external_id' => null, 'status' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = '不明なエラー';
        if (isset($body['error']['message'])) {
            $errMsg = $body['error']['message'];
        } elseif (isset($body['error']['code'])) {
            $errMsg = $body['error']['code'];
        }
        return ['success' => false, 'external_id' => null, 'status' => null, 'error' => 'Stripe Connect: ' . $errMsg];
    }

    $status = isset($body['status']) ? $body['status'] : '';
    $piId = isset($body['id']) ? $body['id'] : '';

    if ($status === 'succeeded' || $status === 'requires_capture') {
        return ['success' => true, 'external_id' => $piId, 'status' => $status, 'error' => null];
    }

    return ['success' => false, 'external_id' => $piId, 'status' => $status, 'error' => 'Stripe Connect: ステータス ' . $status];
}

/**
 * L-13: Stripe Connect Checkout Session を作成（オンライン決済用）
 * @param string $platformSecretKey
 * @param string $connectAccountId
 * @param int $amountYen
 * @param string $currency
 * @param int $applicationFee
 * @param string $orderName
 * @param string $successUrl
 * @param string $cancelUrl
 * @return array ['success' => bool, 'checkout_url' => string|null, 'session_id' => string|null, 'error' => string|null]
 */
function create_stripe_connect_checkout_session($platformSecretKey, $connectAccountId, $amountYen, $currency, $applicationFee, $orderName, $successUrl, $cancelUrl) {
    $url = 'https://api.stripe.com/v1/checkout/sessions';
    $postFields = http_build_query([
        'mode' => 'payment',
        'line_items[0][price_data][currency]' => strtolower($currency),
        'line_items[0][price_data][unit_amount]' => (int)$amountYen,
        'line_items[0][price_data][product_data][name]' => $orderName,
        'line_items[0][quantity]' => 1,
        'payment_intent_data[application_fee_amount]' => (int)$applicationFee,
        'payment_intent_data[transfer_data][destination]' => $connectAccountId,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ]);

    $headers = [
        'Authorization: Bearer ' . $platformSecretKey,
    ];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'checkout_url' => null, 'session_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'checkout_url' => null, 'session_id' => null, 'error' => 'Stripe Connect: ' . $errMsg];
    }

    return [
        'success' => true,
        'checkout_url' => isset($body['url']) ? $body['url'] : null,
        'session_id' => isset($body['id']) ? $body['id'] : null,
        'error' => null
    ];
}

/**
 * L-13: Stripe Connect Terminal 用 PaymentIntent を作成（未確定状態で返す）
 * Terminal SDK の collectPaymentMethod() に渡す client_secret を取得するため
 *
 * @param string $platformSecretKey
 * @param string $connectAccountId
 * @param int $amountYen
 * @param string $currency
 * @param int $applicationFee
 * @param string $referenceId
 * @return array ['success' => bool, 'client_secret' => string|null, 'payment_intent_id' => string|null, 'error' => string|null]
 */
function create_stripe_connect_terminal_intent($platformSecretKey, $connectAccountId, $amountYen, $currency, $applicationFee, $referenceId) {
    $url = 'https://api.stripe.com/v1/payment_intents';
    $postFields = http_build_query([
        'amount'                              => (int)$amountYen,
        'currency'                            => strtolower($currency),
        'payment_method_types[0]'             => 'card_present',
        'capture_method'                      => 'automatic',
        'application_fee_amount'              => (int)$applicationFee,
        'transfer_data[destination]'          => $connectAccountId,
        'metadata[posla_payment_id]'          => $referenceId,
    ]);

    $headers = [
        'Authorization: Bearer ' . $platformSecretKey,
    ];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'client_secret' => null, 'payment_intent_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'client_secret' => null, 'payment_intent_id' => null, 'error' => 'Stripe Connect Terminal: ' . $errMsg];
    }

    $piId = isset($body['id']) ? $body['id'] : '';
    $clientSecret = isset($body['client_secret']) ? $body['client_secret'] : '';

    if (!$clientSecret) {
        return ['success' => false, 'client_secret' => null, 'payment_intent_id' => $piId, 'error' => 'client_secretが取得できませんでした'];
    }

    return ['success' => true, 'client_secret' => $clientSecret, 'payment_intent_id' => $piId, 'error' => null];
}
