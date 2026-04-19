<?php
/**
 * L-9 予約管理 — Stripe デポジット (前金決済) ライブラリ
 *
 * - Stripe Checkout (mode=payment, capture_method=manual) でカード authorize のみ
 * - no-show 時: payment_intents/{id}/capture で課金確定
 * - キャンセル/来店完了時: payment_intents/{id}/cancel で releaseの
 * - Stripe Connect or 直接決済 のどちらにも対応 (既存 payment-gateway.php / stripe-connect.php に整合)
 */

require_once __DIR__ . '/payment-gateway.php';
require_once __DIR__ . '/stripe-connect.php';

if (!function_exists('reservation_deposit_is_available')) {
    /**
     * 店舗で前金徴収が利用可能か (settings + 決済設定の両方が揃ってる)
     */
    function reservation_deposit_is_available($pdo, $storeId, $tenantId, $settings = null) {
        if ($settings === null) {
            $settings = get_reservation_settings($pdo, $storeId);
        }
        if ((int)$settings['deposit_enabled'] !== 1) return false;
        if ((int)$settings['deposit_per_person'] <= 0) return false;

        // Stripe Connect 完了 OR 直接決済キーあり、いずれかが揃ってればOK
        $gw = get_payment_gateway_config($pdo, $tenantId);
        if ($gw && $gw['gateway'] === 'stripe' && !empty($gw['token'])) return true;

        $connect = get_tenant_connect_info($pdo, $tenantId);
        if ($connect && (int)$connect['connect_onboarding_complete'] === 1) return true;

        return false;
    }
}

if (!function_exists('reservation_deposit_amount')) {
    /**
     * 前金金額を計算 (円)
     * - party_size >= deposit_min_party_size の場合のみ徴収
     */
    function reservation_deposit_amount($settings, $partySize) {
        $perPerson = (int)$settings['deposit_per_person'];
        $minParty = (int)$settings['deposit_min_party_size'];
        if ($perPerson <= 0 || (int)$partySize < $minParty) return 0;
        return $perPerson * (int)$partySize;
    }
}

if (!function_exists('_l9_stripe_request')) {
    function _l9_stripe_request($method, $endpoint, $secretKey, $params = array(), $headers = array()) {
        $url = 'https://api.stripe.com/v1' . $endpoint;
        $ch = curl_init();
        $allHeaders = array_merge(array(
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ), $headers);
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_CUSTOMREQUEST => $method,
        ));
        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return array('success' => false, 'error' => $err, 'data' => null);
        $data = json_decode($body, true);
        if ($code >= 400) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'stripe_error';
            return array('success' => false, 'error' => $msg, 'data' => $data);
        }
        return array('success' => true, 'error' => null, 'data' => $data);
    }
}

if (!function_exists('reservation_deposit_create_checkout')) {
    /**
     * 前金支払い用の Stripe Checkout Session を作成
     * @return array ['success'=>bool, 'checkout_url'=>string|null, 'session_id'=>string|null, 'error'=>string|null]
     */
    function reservation_deposit_create_checkout($pdo, $reservation, $store, $amount, $successUrl, $cancelUrl) {
        if ($amount <= 0) {
            return array('success' => false, 'error' => 'INVALID_AMOUNT');
        }

        // P0 #5: Session metadata と PaymentIntent metadata に同じ context を埋める
        // (webhook では PaymentIntent オブジェクトが来ることもあるため両方必須)
        $params = array(
            'mode' => 'payment',
            'payment_method_types[0]' => 'card',
            'line_items[0][price_data][currency]' => 'jpy',
            'line_items[0][price_data][unit_amount]' => (int)$amount,
            'line_items[0][price_data][product_data][name]' => '【予約金】' . $store['name'] . ' / ' . $reservation['party_size'] . '名',
            'line_items[0][quantity]' => 1,
            'payment_intent_data[capture_method]' => 'manual',
            'payment_intent_data[description]' => 'Reservation deposit ' . $reservation['id'],
            'payment_intent_data[metadata][reservation_id]' => $reservation['id'],
            'payment_intent_data[metadata][store_id]' => $store['id'],
            'payment_intent_data[metadata][tenant_id]' => $store['tenant_id'],
            'payment_intent_data[metadata][expected_amount]' => (int)$amount,
            'payment_intent_data[metadata][expected_currency]' => 'jpy',
            'payment_intent_data[metadata][purpose]' => 'reservation_deposit',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => time() + 30 * 60,
            'customer_email' => $reservation['customer_email'] ?: null,
            'metadata[reservation_id]' => $reservation['id'],
            'metadata[store_id]' => $store['id'],
            'metadata[tenant_id]' => $store['tenant_id'],
            'metadata[expected_amount]' => (int)$amount,
            'metadata[expected_currency]' => 'jpy',
            'metadata[purpose]' => 'reservation_deposit',
        );
        if (empty($params['customer_email'])) unset($params['customer_email']);

        // Connect 優先
        $connect = get_tenant_connect_info($pdo, $store['tenant_id']);
        if ($connect && (int)$connect['connect_onboarding_complete'] === 1) {
            $platformConfig = get_platform_stripe_config($pdo);
            if ($platformConfig && !empty($platformConfig['secret_key'])) {
                $appFee = calculate_application_fee((int)$amount, $platformConfig['fee_percent']);
                $params['payment_intent_data[application_fee_amount]'] = (int)$appFee;
                $headers = array('Stripe-Account: ' . $connect['stripe_connect_account_id']);
                $r = _l9_stripe_request('POST', '/checkout/sessions', $platformConfig['secret_key'], $params, $headers);
                if ($r['success']) {
                    return array(
                        'success' => true,
                        'checkout_url' => $r['data']['url'],
                        'session_id' => $r['data']['id'],
                        'error' => null,
                    );
                }
                return array('success' => false, 'checkout_url' => null, 'session_id' => null, 'error' => $r['error']);
            }
        }

        // 直接決済
        $gw = get_payment_gateway_config($pdo, $store['tenant_id']);
        if (!$gw || $gw['gateway'] !== 'stripe' || empty($gw['token'])) {
            return array('success' => false, 'error' => 'PAYMENT_NOT_CONFIGURED');
        }
        $r = _l9_stripe_request('POST', '/checkout/sessions', $gw['token'], $params);
        if (!$r['success']) {
            return array('success' => false, 'checkout_url' => null, 'session_id' => null, 'error' => $r['error']);
        }
        return array(
            'success' => true,
            'checkout_url' => $r['data']['url'],
            'session_id' => $r['data']['id'],
            'error' => null,
        );
    }
}

if (!function_exists('reservation_deposit_capture')) {
    /**
     * payment_intent を capture (no-show 時)
     */
    function reservation_deposit_capture($pdo, $reservation, $store) {
        if (empty($reservation['deposit_payment_intent_id'])) {
            return array('success' => false, 'error' => 'NO_PAYMENT_INTENT');
        }
        $piId = $reservation['deposit_payment_intent_id'];

        $connect = get_tenant_connect_info($pdo, $store['tenant_id']);
        if ($connect && (int)$connect['connect_onboarding_complete'] === 1) {
            $platformConfig = get_platform_stripe_config($pdo);
            $headers = array('Stripe-Account: ' . $connect['stripe_connect_account_id']);
            return _l9_stripe_request('POST', '/payment_intents/' . urlencode($piId) . '/capture', $platformConfig['secret_key'], array(), $headers);
        }
        $gw = get_payment_gateway_config($pdo, $store['tenant_id']);
        if (!$gw || empty($gw['token'])) return array('success' => false, 'error' => 'PAYMENT_NOT_CONFIGURED');
        return _l9_stripe_request('POST', '/payment_intents/' . urlencode($piId) . '/capture', $gw['token']);
    }
}

if (!function_exists('reservation_deposit_release')) {
    /**
     * payment_intent を cancel (release 相当: 来店完了/キャンセル無料時)
     */
    function reservation_deposit_release($pdo, $reservation, $store) {
        if (empty($reservation['deposit_payment_intent_id'])) {
            return array('success' => true, 'note' => 'no_intent_to_release');
        }
        $piId = $reservation['deposit_payment_intent_id'];

        $connect = get_tenant_connect_info($pdo, $store['tenant_id']);
        if ($connect && (int)$connect['connect_onboarding_complete'] === 1) {
            $platformConfig = get_platform_stripe_config($pdo);
            $headers = array('Stripe-Account: ' . $connect['stripe_connect_account_id']);
            return _l9_stripe_request('POST', '/payment_intents/' . urlencode($piId) . '/cancel', $platformConfig['secret_key'], array(), $headers);
        }
        $gw = get_payment_gateway_config($pdo, $store['tenant_id']);
        if (!$gw || empty($gw['token'])) return array('success' => false, 'error' => 'PAYMENT_NOT_CONFIGURED');
        return _l9_stripe_request('POST', '/payment_intents/' . urlencode($piId) . '/cancel', $gw['token']);
    }
}
