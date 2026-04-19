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
 *
 * P1-35: 多 line_items 対応化（後方互換維持）
 *  - $priceIdOrLineItems が文字列の場合は従来通り単一 price + quantity=1
 *  - 配列の場合は各要素 ['price' => 'price_xxx', 'quantity' => N] を line_items[i] にマップ
 *  - $extraParams で subscription_data[trial_period_days]=30 等を追加可能
 *
 * @param string $secretKey
 * @param string $customerId
 * @param string|array $priceIdOrLineItems  単一 price_id 文字列 or [['price'=>..,'quantity'=>..],..]
 * @param string $successUrl
 * @param string $cancelUrl
 * @param array $extraParams  追加パラメタ（フラットな key=>value、Stripe 流のドット記法可）
 * @return array ['success' => bool, 'url' => string|null, 'session_id' => string|null, 'error' => string|null]
 */
function create_billing_checkout_session($secretKey, $customerId, $priceIdOrLineItems, $successUrl, $cancelUrl, $extraParams = []) {
    $url = 'https://api.stripe.com/v1/checkout/sessions';

    $params = [
        'mode' => 'subscription',
        'payment_method_types[0]' => 'card',
        'customer' => $customerId,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ];

    // line_items の組み立て: 文字列 or 配列を分岐
    if (is_array($priceIdOrLineItems)) {
        $i = 0;
        foreach ($priceIdOrLineItems as $item) {
            if (!isset($item['price'])) continue;
            $qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;
            // quantity=0 は Stripe が拒否するためスキップ
            if ($qty <= 0) continue;
            $params['line_items[' . $i . '][price]'] = $item['price'];
            $params['line_items[' . $i . '][quantity]'] = $qty;
            $i++;
        }
    } else {
        $params['line_items[0][price]'] = $priceIdOrLineItems;
        $params['line_items[0][quantity]'] = 1;
    }

    // 追加パラメタ（trial_period_days など）をマージ
    if (!empty($extraParams) && is_array($extraParams)) {
        foreach ($extraParams as $k => $v) {
            $params[$k] = $v;
        }
    }

    $postFields = http_build_query($params);
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
 * @deprecated P1-35: α-1 移行で不要。webhook と status は extract_alpha1_state_from_subscription を使う。
 *             旧 standard/pro/enterprise 3プラン構造の遺物。残置のみで呼び出し元 0 件。
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

/**
 * P1-35: α-1 構成の line_items を組み立てる
 *
 * 構成:
 *   ['price' => stripe_price_base,             'quantity' => 1]                            (常に 1)
 *   ['price' => stripe_price_additional_store, 'quantity' => max(0, $storeCount - 1)]      (追加店舗。0 ならスキップ)
 *   ['price' => stripe_price_hq_broadcast,     'quantity' => $storeCount]                  ($hqBroadcast=true 時のみ)
 *
 * 必須 keys: stripe_price_base, stripe_price_additional_store
 * 任意 key:  stripe_price_hq_broadcast ($hqBroadcast=true 時のみ必須)
 *
 * @param array $config  get_stripe_config() の戻り値
 * @param int $storeCount  契約対象店舗数 (1以上)
 * @param bool $hqBroadcast  本部一括配信アドオンの有無
 * @return array|null  line_items 配列 or 必須 key 欠如時は null
 */
function build_alpha1_line_items($config, $storeCount, $hqBroadcast) {
    if (empty($config['stripe_price_base']) || empty($config['stripe_price_additional_store'])) {
        return null;
    }
    if ($hqBroadcast && empty($config['stripe_price_hq_broadcast'])) {
        return null;
    }
    $storeCount = (int)$storeCount;
    if ($storeCount < 1) $storeCount = 1;

    $items = [];
    $items[] = ['price' => $config['stripe_price_base'], 'quantity' => 1];

    $additional = $storeCount - 1;
    if ($additional > 0) {
        $items[] = ['price' => $config['stripe_price_additional_store'], 'quantity' => $additional];
    }

    if ($hqBroadcast) {
        $items[] = ['price' => $config['stripe_price_hq_broadcast'], 'quantity' => $storeCount];
    }

    return $items;
}

/**
 * P1-36: 既存サブスクリプションの items を α-1 構造に rebuild する
 *
 * 現在の (店舗数, hq_menu_broadcast) からあるべき line_items を計算し、
 * Stripe 側 subscription items との差分を update する。
 *
 * 用途:
 *   1. 店舗追加/削除時の自動同期 (api/owner/stores.php POST/DELETE/PATCH)
 *   2. 旧 POSLA Pro/Standard/Enterprise → α-1 への in-place migrate
 *      (旧 price は items から削除され、新 price が追加される)
 *
 * 動作:
 *   - サブスクなし (subscription_status='none' or 'canceled', stripe_subscription_id 空) → skip
 *   - 必要 price ID が posla_settings に未設定 → error
 *   - 差分なし → no_change
 *   - 差分あり → Stripe POST /v1/subscriptions/{id} を 1 回叩く
 *   - proration_behavior=create_prorations (active なら日割り課金、trialing なら charge なし)
 *
 * エラー時は例外を投げず ['ok' => false, 'error' => ...] を返す。
 * 呼び出し側 (stores.php) は店舗 CRUD 自体は成功させて error_log のみ残す。
 *
 * @param PDO $pdo
 * @param string $tenantId
 * @return array [
 *   'ok' => bool,
 *   'skipped' => string|null,    // 'no_subscription' 等
 *   'no_change' => bool|null,
 *   'updated' => bool|null,
 *   'error' => string|null,
 *   'desired_items' => array|null,  // デバッグ用
 * ]
 */
function sync_alpha1_subscription_quantity($pdo, $tenantId) {
    // 1. テナント取得
    $stmt = $pdo->prepare('SELECT stripe_subscription_id, subscription_status, hq_menu_broadcast FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant) {
        return ['ok' => false, 'error' => 'tenant_not_found'];
    }

    $subId = isset($tenant['stripe_subscription_id']) ? $tenant['stripe_subscription_id'] : null;
    $subStatus = isset($tenant['subscription_status']) ? $tenant['subscription_status'] : null;
    $hqBroadcast = ((int)$tenant['hq_menu_broadcast']) === 1;

    // 2. サブスクなし → skip
    if (empty($subId) || $subStatus === null || $subStatus === 'none' || $subStatus === 'canceled') {
        return ['ok' => true, 'skipped' => 'no_subscription'];
    }

    // 3. 店舗数取得
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM stores WHERE tenant_id = ? AND is_active = 1');
    $stmt->execute([$tenantId]);
    $storeCount = (int)$stmt->fetchColumn();
    if ($storeCount < 1) $storeCount = 1;

    // 4. 設定取得
    $config = get_stripe_config($pdo);
    if (empty($config['stripe_secret_key'])) {
        error_log('[P1-36 sync] stripe_secret_key not configured tenant=' . $tenantId);
        return ['ok' => false, 'error' => 'stripe_not_configured'];
    }

    // 5. ideal な line_items を計算
    $desired = build_alpha1_line_items($config, $storeCount, $hqBroadcast);
    if ($desired === null) {
        error_log('[P1-36 sync] price not configured tenant=' . $tenantId . ' hq=' . ($hqBroadcast ? '1' : '0'));
        return ['ok' => false, 'error' => 'price_not_configured'];
    }

    // 6. 既存 Stripe subscription を取得
    $secretKey = $config['stripe_secret_key'];
    $sub = get_subscription($secretKey, $subId);
    if (!$sub['success']) {
        error_log('[P1-36 sync] get_subscription failed: ' . $sub['error'] . ' tenant=' . $tenantId);
        return ['ok' => false, 'error' => $sub['error']];
    }
    $existingItems = isset($sub['data']['items']['data']) && is_array($sub['data']['items']['data'])
        ? $sub['data']['items']['data']
        : [];

    // 7. 差分計算
    $stripeParams = [];
    $idx = 0;
    $seenPrices = [];

    foreach ($desired as $d) {
        $foundItem = null;
        foreach ($existingItems as $e) {
            $ePriceId = isset($e['price']['id']) ? $e['price']['id'] : null;
            if ($ePriceId !== null && $ePriceId === $d['price']) {
                $foundItem = $e;
                break;
            }
        }

        if ($foundItem) {
            $existingQty = isset($foundItem['quantity']) ? (int)$foundItem['quantity'] : 0;
            if ($existingQty !== (int)$d['quantity']) {
                $stripeParams['items[' . $idx . '][id]'] = $foundItem['id'];
                $stripeParams['items[' . $idx . '][quantity]'] = (int)$d['quantity'];
                $idx++;
            }
            // qty 一致なら何もしない
        } else {
            // 新規追加
            $stripeParams['items[' . $idx . '][price]'] = $d['price'];
            $stripeParams['items[' . $idx . '][quantity]'] = (int)$d['quantity'];
            $idx++;
        }
        $seenPrices[] = $d['price'];
    }

    // desired にない既存 item は削除
    foreach ($existingItems as $e) {
        $ePriceId = isset($e['price']['id']) ? $e['price']['id'] : null;
        if ($ePriceId === null) continue;
        if (!in_array($ePriceId, $seenPrices, true)) {
            $stripeParams['items[' . $idx . '][id]'] = $e['id'];
            $stripeParams['items[' . $idx . '][deleted]'] = 'true';
            $idx++;
        }
    }

    // 8. 差分なし
    if (empty($stripeParams)) {
        return ['ok' => true, 'no_change' => true, 'desired_items' => $desired];
    }

    // 9. Stripe Subscription update
    $stripeParams['proration_behavior'] = 'create_prorations';

    $url = 'https://api.stripe.com/v1/subscriptions/' . urlencode($subId);
    $postFields = http_build_query($stripeParams);
    $headers = ['Authorization: Bearer ' . $secretKey];

    $result = _gateway_curl_post($url, $postFields, $headers, true);
    if ($result['curl_error']) {
        error_log('[P1-36 sync] curl error: ' . $result['curl_error'] . ' tenant=' . $tenantId);
        return ['ok' => false, 'error' => $result['curl_error']];
    }

    $body = json_decode($result['body'], true);
    if ($result['http_code'] >= 400 || !$body) {
        $errMsg = isset($body['error']['message']) ? $body['error']['message'] : 'unknown';
        error_log('[P1-36 sync] Stripe API error: ' . $errMsg . ' tenant=' . $tenantId);
        return ['ok' => false, 'error' => 'Stripe: ' . $errMsg];
    }

    return ['ok' => true, 'updated' => true, 'desired_items' => $desired];
}

/**
 * P1-35: Stripe subscription オブジェクトから α-1 状態を抽出
 *
 * @param array $subData  Stripe API レスポンスの subscription オブジェクト
 *                        (webhook payload の data.object でも GET /subscriptions/:id でも可)
 * @param array $config  get_stripe_config() の戻り値
 * @return array [
 *   'status' => 'trialing'|'active'|'past_due'|'canceled'|'none',
 *   'current_period_end' => 'YYYY-MM-DD HH:MM:SS' or null,
 *   'store_count' => int (additional_store quantity + 1),
 *   'has_hq_broadcast' => bool,
 * ]
 */
function extract_alpha1_state_from_subscription($subData, $config) {
    $statusMap = [
        'active'   => 'active',
        'past_due' => 'past_due',
        'canceled' => 'canceled',
        'trialing' => 'trialing',
    ];
    $rawStatus = isset($subData['status']) ? $subData['status'] : null;
    $status = isset($statusMap[$rawStatus]) ? $statusMap[$rawStatus] : 'none';

    // Stripe billing_mode=flexible では current_period_end が top-level ではなく
    // items[].current_period_end に移動している。top-level → 先頭 item の順で読む
    $periodEnd = null;
    if (isset($subData['current_period_end']) && $subData['current_period_end']) {
        $periodEnd = date('Y-m-d H:i:s', (int)$subData['current_period_end']);
    } elseif (isset($subData['items']['data'][0]['current_period_end']) && $subData['items']['data'][0]['current_period_end']) {
        $periodEnd = date('Y-m-d H:i:s', (int)$subData['items']['data'][0]['current_period_end']);
    }

    $priceBase       = isset($config['stripe_price_base']) ? $config['stripe_price_base'] : '';
    $priceAdditional = isset($config['stripe_price_additional_store']) ? $config['stripe_price_additional_store'] : '';
    $priceHqBroadcast = isset($config['stripe_price_hq_broadcast']) ? $config['stripe_price_hq_broadcast'] : '';

    $additionalQty = 0;
    $hasHqBroadcast = false;
    $hasBase = false;

    if (isset($subData['items']['data']) && is_array($subData['items']['data'])) {
        foreach ($subData['items']['data'] as $item) {
            $itemPriceId = isset($item['price']['id']) ? $item['price']['id'] : null;
            $itemQty = isset($item['quantity']) ? (int)$item['quantity'] : 0;
            if (!$itemPriceId) continue;

            if ($priceBase && $itemPriceId === $priceBase) {
                $hasBase = true;
            } elseif ($priceAdditional && $itemPriceId === $priceAdditional) {
                $additionalQty = $itemQty;
            } elseif ($priceHqBroadcast && $itemPriceId === $priceHqBroadcast) {
                $hasHqBroadcast = true;
            }
        }
    }

    // base がある = 1 店舗、additional の qty を足す
    $storeCount = ($hasBase ? 1 : 0) + $additionalQty;
    if ($storeCount < 1) $storeCount = 1;

    return [
        'status' => $status,
        'current_period_end' => $periodEnd,
        'store_count' => $storeCount,
        'has_hq_broadcast' => $hasHqBroadcast,
    ];
}
