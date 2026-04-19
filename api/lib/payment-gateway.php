<?php
/**
 * 決済ゲートウェイライブラリ
 * C-3 / P1-2: Stripe 連携（Square は P1-2 で削除）
 *
 * process-payment.php から呼び出される
 */

/**
 * テナントの決済ゲートウェイ設定を取得
 * @return array|null ['gateway' => 'stripe'|'none', 'token' => '...']
 */
function get_payment_gateway_config($pdo, $tenantId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT payment_gateway, stripe_secret_key FROM tenants WHERE id = ?'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $gw = $row['payment_gateway'] ?: 'none';
        if ($gw === 'none') return ['gateway' => 'none', 'token' => null];

        if ($gw !== 'stripe') return ['gateway' => 'none', 'token' => null];

        $token = $row['stripe_secret_key'];
        if (!$token) return ['gateway' => 'none', 'token' => null];

        return ['gateway' => 'stripe', 'token' => $token];
    } catch (PDOException $e) {
        // カラム未存在時（マイグレーション未適用）
        return null;
    }
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
 * Stripe Checkout Session を作成（オンライン決済用）
 * P0 #5: $metadata 引数を追加。Session レベル metadata と PaymentIntent metadata の両方に埋め込む
 *        (Stripe Checkout の metadata は Session オブジェクトのみ。PaymentIntent metadata は別パラメータ)
 * @param array $metadata  [key => value] 形式。例: ['tenant_id' => 't1', 'expected_amount' => 1500]
 * @return array ['success' => bool, 'checkout_url' => string|null, 'session_id' => string|null, 'error' => string|null]
 */
function create_stripe_checkout_session($secretKey, $amountYen, $currency, $orderName, $successUrl, $cancelUrl, $metadata = array()) {
    $url = 'https://api.stripe.com/v1/checkout/sessions';
    $params = [
        'mode' => 'payment',
        'payment_method_types[0]' => 'card',
        'line_items[0][price_data][currency]' => strtolower($currency),
        'line_items[0][price_data][unit_amount]' => (int)$amountYen,
        'line_items[0][price_data][product_data][name]' => $orderName,
        'line_items[0][quantity]' => 1,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ];
    if (is_array($metadata)) {
        foreach ($metadata as $k => $v) {
            $params['metadata[' . $k . ']'] = (string)$v;
            $params['payment_intent_data[metadata][' . $k . ']'] = (string)$v;
        }
    }
    $postFields = http_build_query($params);

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
 * Stripe Checkout Session の決済状態を確認
 * P0 #5: 戻り値に amount_total / currency / metadata を含めて呼び出し側で照合できるようにする
 * @param string|null $stripeAccount  Connect の場合は acct_xxx を渡す (Stripe-Account ヘッダ付与)
 * @return array ['success' => bool, 'payment_status' => string|null, 'payment_intent_id' => string|null,
 *                'amount_total' => int|null, 'currency' => string|null, 'metadata' => array, 'error' => string|null]
 */
function verify_stripe_checkout($secretKey, $sessionId, $stripeAccount = null) {
    $url = 'https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId);

    $headers = ['Authorization: Bearer ' . $secretKey];
    if ($stripeAccount) {
        $headers[] = 'Stripe-Account: ' . $stripeAccount;
    }

    $result = _gateway_curl_get($url, $headers);
    if ($result['curl_error']) {
        return ['success' => false, 'payment_status' => null, 'payment_intent_id' => null,
                'amount_total' => null, 'currency' => null, 'metadata' => [],
                'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'payment_status' => null, 'payment_intent_id' => null,
                'amount_total' => null, 'currency' => null, 'metadata' => [],
                'error' => 'Stripe: ' . $errMsg];
    }

    $status = isset($body['payment_status']) ? $body['payment_status'] : '';
    $piId = isset($body['payment_intent']) ? $body['payment_intent'] : '';
    $amountTotal = isset($body['amount_total']) ? (int)$body['amount_total'] : null;
    $currency = isset($body['currency']) ? strtolower($body['currency']) : null;
    $metadata = (isset($body['metadata']) && is_array($body['metadata'])) ? $body['metadata'] : [];
    $paid = ($status === 'paid');

    return [
        'success' => $paid,
        'payment_status' => $status,
        'payment_intent_id' => $piId,
        'amount_total' => $amountTotal,
        'currency' => $currency,
        'metadata' => $metadata,
        'error' => $paid ? null : 'Stripe: 決済ステータス ' . $status
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
function create_stripe_connect_checkout_session($platformSecretKey, $connectAccountId, $amountYen, $currency, $applicationFee, $orderName, $successUrl, $cancelUrl, $metadata = array()) {
    $url = 'https://api.stripe.com/v1/checkout/sessions';
    $params = [
        'mode' => 'payment',
        'payment_method_types[0]' => 'card',
        'line_items[0][price_data][currency]' => strtolower($currency),
        'line_items[0][price_data][unit_amount]' => (int)$amountYen,
        'line_items[0][price_data][product_data][name]' => $orderName,
        'line_items[0][quantity]' => 1,
        'payment_intent_data[application_fee_amount]' => (int)$applicationFee,
        'payment_intent_data[transfer_data][destination]' => $connectAccountId,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ];
    if (is_array($metadata)) {
        foreach ($metadata as $k => $v) {
            $params['metadata[' . $k . ']'] = (string)$v;
            $params['payment_intent_data[metadata][' . $k . ']'] = (string)$v;
        }
    }
    $postFields = http_build_query($params);

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
/**
 * P1-1: Pattern A — テナント自前 stripe_secret_key で Terminal 用 PaymentIntent を作成
 * Connect ヘッダ・application_fee なし。Pattern B (create_stripe_connect_terminal_intent) と並列。
 *
 * @param string $secretKey   テナントの Stripe Secret Key
 * @param int $amountYen
 * @param string $currency
 * @param string $referenceId
 * @return array ['success' => bool, 'client_secret' => string|null, 'payment_intent_id' => string|null, 'error' => string|null]
 */
function create_stripe_terminal_intent($secretKey, $amountYen, $currency, $referenceId, $extraMetadata = []) {
    $url = 'https://api.stripe.com/v1/payment_intents';
    $params = [
        'amount'                     => (int)$amountYen,
        'currency'                   => strtolower($currency),
        'payment_method_types[0]'    => 'card_present',
        'capture_method'             => 'automatic',
        'metadata[posla_payment_id]' => $referenceId,
    ];
    foreach ($extraMetadata as $k => $v) {
        $params['metadata[' . $k . ']'] = (string)$v;
    }
    $postFields = http_build_query($params);

    $headers = [
        'Authorization: Bearer ' . $secretKey,
    ];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'client_secret' => null, 'payment_intent_id' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'client_secret' => null, 'payment_intent_id' => null, 'error' => 'Stripe Terminal: ' . $errMsg];
    }

    $piId = isset($body['id']) ? $body['id'] : '';
    $clientSecret = isset($body['client_secret']) ? $body['client_secret'] : '';

    if (!$clientSecret) {
        return ['success' => false, 'client_secret' => null, 'payment_intent_id' => $piId, 'error' => 'client_secretが取得できませんでした'];
    }

    return ['success' => true, 'client_secret' => $clientSecret, 'payment_intent_id' => $piId, 'error' => null];
}

/**
 * C-R1: Stripe Refund を作成（Pattern A: テナント自前キー）
 * @param string $secretKey テナントの Stripe Secret Key
 * @param string $paymentIntentId pi_xxx
 * @param int $amountYen 返金額（円）。null = 全額返金
 * @param string $reason 'requested_by_customer' | 'duplicate' | 'fraudulent'
 * @return array ['success' => bool, 'refund_id' => string|null, 'status' => string|null, 'error' => string|null]
 */
function create_stripe_refund($secretKey, $paymentIntentId, $amountYen, $reason) {
    $url = 'https://api.stripe.com/v1/refunds';
    $params = [
        'payment_intent' => $paymentIntentId,
        'reason'         => $reason ?: 'requested_by_customer',
    ];
    if ($amountYen !== null) {
        $params['amount'] = (int)$amountYen;
    }
    $postFields = http_build_query($params);

    $headers = [
        'Authorization: Bearer ' . $secretKey,
    ];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'refund_id' => null, 'status' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'refund_id' => null, 'status' => null, 'error' => 'Stripe: ' . $errMsg];
    }

    $status = isset($body['status']) ? $body['status'] : '';
    $refundId = isset($body['id']) ? $body['id'] : '';

    if ($status === 'succeeded' || $status === 'pending') {
        return ['success' => true, 'refund_id' => $refundId, 'status' => $status, 'error' => null];
    }

    return ['success' => false, 'refund_id' => $refundId, 'status' => $status, 'error' => 'Stripe: 返金ステータス ' . $status];
}

/**
 * C-R1: Stripe Connect Refund を作成（Pattern B: プラットフォームキー + Connect）
 * @param string $platformSecretKey
 * @param string $connectAccountId acct_xxx
 * @param string $paymentIntentId pi_xxx
 * @param int $amountYen null=全額
 * @param string $reason
 * @param bool $refundApplicationFee プラットフォーム手数料も返金するか
 * @return array
 */
function create_stripe_connect_refund($platformSecretKey, $connectAccountId, $paymentIntentId, $amountYen, $reason, $refundApplicationFee) {
    $url = 'https://api.stripe.com/v1/refunds';
    $params = [
        'payment_intent'       => $paymentIntentId,
        'reason'               => $reason ?: 'requested_by_customer',
        'refund_application_fee' => $refundApplicationFee ? 'true' : 'false',
    ];
    if ($amountYen !== null) {
        $params['amount'] = (int)$amountYen;
    }
    $postFields = http_build_query($params);

    $headers = [
        'Authorization: Bearer ' . $platformSecretKey,
        'Stripe-Account: ' . $connectAccountId,
    ];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        return ['success' => false, 'refund_id' => null, 'status' => null, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'refund_id' => null, 'status' => null, 'error' => 'Stripe Connect: ' . $errMsg];
    }

    $status = isset($body['status']) ? $body['status'] : '';
    $refundId = isset($body['id']) ? $body['id'] : '';

    if ($status === 'succeeded' || $status === 'pending') {
        return ['success' => true, 'refund_id' => $refundId, 'status' => $status, 'error' => null];
    }

    return ['success' => false, 'refund_id' => $refundId, 'status' => $status, 'error' => 'Stripe Connect: 返金ステータス ' . $status];
}

function create_stripe_connect_terminal_intent($platformSecretKey, $connectAccountId, $amountYen, $currency, $applicationFee, $referenceId, $extraMetadata = []) {
    $url = 'https://api.stripe.com/v1/payment_intents';
    $params = [
        'amount'                              => (int)$amountYen,
        'currency'                            => strtolower($currency),
        'payment_method_types[0]'             => 'card_present',
        'capture_method'                      => 'automatic',
        'application_fee_amount'              => (int)$applicationFee,
        'transfer_data[destination]'          => $connectAccountId,
        'metadata[posla_payment_id]'          => $referenceId,
    ];
    foreach ($extraMetadata as $k => $v) {
        $params['metadata[' . $k . ']'] = (string)$v;
    }
    $postFields = http_build_query($params);

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

/**
 * S2 P0 #4: Stripe PaymentIntent を取得して状態・金額・metadata を検証可能にする (Pattern A)
 */
function retrieve_stripe_payment_intent($secretKey, $paymentIntentId) {
    $url = 'https://api.stripe.com/v1/payment_intents/' . urlencode($paymentIntentId);
    $headers = ['Authorization: Bearer ' . $secretKey];
    $result = _gateway_curl_get($url, $headers);
    if ($result['curl_error']) {
        return ['success' => false, 'intent' => null, 'error' => $result['curl_error']];
    }
    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'intent' => null, 'error' => 'Stripe: ' . $errMsg];
    }
    return ['success' => true, 'intent' => $body, 'error' => null];
}

/**
 * S2 P0 #4: Stripe Connect 経由で PaymentIntent を取得 (Pattern B)
 */
function retrieve_stripe_connect_payment_intent($platformSecretKey, $connectAccountId, $paymentIntentId) {
    $url = 'https://api.stripe.com/v1/payment_intents/' . urlencode($paymentIntentId);
    $headers = [
        'Authorization: Bearer ' . $platformSecretKey,
        'Stripe-Account: ' . $connectAccountId,
    ];
    $result = _gateway_curl_get($url, $headers);
    if ($result['curl_error']) {
        return ['success' => false, 'intent' => null, 'error' => $result['curl_error']];
    }
    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : '不明なエラー';
        return ['success' => false, 'intent' => null, 'error' => 'Stripe Connect: ' . $errMsg];
    }
    return ['success' => true, 'intent' => $body, 'error' => null];
}

// (既存の _gateway_curl_get を再利用)
