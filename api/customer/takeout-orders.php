<?php
/**
 * テイクアウト注文 API（顧客向け・認証不要）
 *
 * GET  ?action=settings&store_id=xxx   — テイクアウト設定取得
 * GET  ?action=slots&store_id=xxx&date=YYYY-MM-DD — 受取時間枠の空き状況
 * GET  ?action=status&order_id=xxx&phone=xxx       — 注文ステータス確認
 * POST                                             — テイクアウト注文作成
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/order-items.php';
require_once __DIR__ . '/../lib/payment-gateway.php';
require_once __DIR__ . '/../lib/order-validator.php';

require_method(['GET', 'POST']);
$pdo = get_db();

// ===== GET =====
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // --- テイクアウト設定取得 ---
    if ($action === 'settings') {
        $storeId = $_GET['store_id'] ?? null;
        if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);

        // 店舗存在確認
        $stmt = $pdo->prepare('SELECT s.id, s.name, s.name_en, s.tenant_id FROM stores s WHERE s.id = ? AND s.is_active = 1');
        $stmt->execute([$storeId]);
        $store = $stmt->fetch();
        if (!$store) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);

        // テイクアウト設定取得（カラム未存在時はデフォルト値）
        $takeoutSettings = [
            'takeout_enabled' => 0,
            'takeout_min_prep_minutes' => 30,
            'takeout_available_from' => '10:00:00',
            'takeout_available_to' => '20:00:00',
            'takeout_slot_capacity' => 5,
            'takeout_online_payment' => 0,
        ];
        try {
            $ssStmt = $pdo->prepare(
                'SELECT takeout_enabled, takeout_min_prep_minutes, takeout_available_from,
                        takeout_available_to, takeout_slot_capacity, takeout_online_payment,
                        brand_color, brand_logo_url, brand_display_name
                 FROM store_settings WHERE store_id = ?'
            );
            $ssStmt->execute([$storeId]);
            $ssRow = $ssStmt->fetch();
            if ($ssRow) {
                $takeoutSettings = $ssRow;
            }
        } catch (PDOException $e) {
            // マイグレーション未適用時はデフォルト値のまま
            error_log('[P1-12][customer/takeout-orders.php:56] fetch_takeout_settings: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        }

        // オンライン決済可否判定 (S3-strict): payment_gateway=stripe + 実キーが揃っている場合のみ true
        $onlinePaymentAvailable = false;
        if ((int)$takeoutSettings['takeout_online_payment'] === 1) {
            try {
                $gwStmt = $pdo->prepare(
                    'SELECT t.payment_gateway, t.stripe_secret_key, t.stripe_connect_account_id, t.connect_onboarding_complete
                     FROM tenants t JOIN stores s ON s.tenant_id = t.id WHERE s.id = ?'
                );
                $gwStmt->execute([$storeId]);
                $gwRow = $gwStmt->fetch();
                if ($gwRow && $gwRow['payment_gateway'] === 'stripe') {
                    $hasDirectKey = isset($gwRow['stripe_secret_key']) && $gwRow['stripe_secret_key'] !== '';
                    $hasConnectReady = !empty($gwRow['stripe_connect_account_id']) && (int)$gwRow['connect_onboarding_complete'] === 1;
                    if ($hasDirectKey || $hasConnectReady) {
                        $onlinePaymentAvailable = true;
                    }
                }
            } catch (PDOException $e) {
                error_log('[P1-12][customer/takeout-orders.php:72] check_payment_gateway: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
            }
        }

        json_response([
            'takeout_enabled' => (int)$takeoutSettings['takeout_enabled'],
            'min_prep_minutes' => (int)$takeoutSettings['takeout_min_prep_minutes'],
            'available_from' => $takeoutSettings['takeout_available_from'],
            'available_to' => $takeoutSettings['takeout_available_to'],
            'slot_capacity' => (int)$takeoutSettings['takeout_slot_capacity'],
            'online_payment_available' => $onlinePaymentAvailable,
            'store_name' => $store['name'],
            'store_name_en' => $store['name_en'] ?? '',
            'brand_color' => $takeoutSettings['brand_color'] ?? null,
            'brand_logo_url' => $takeoutSettings['brand_logo_url'] ?? null,
            'brand_display_name' => $takeoutSettings['brand_display_name'] ?? null,
        ]);
    }

    // --- 受取時間枠の空き状況 ---
    if ($action === 'slots') {
        $storeId = $_GET['store_id'] ?? null;
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('INVALID_DATE', '日付形式が不正です', 400);

        // テイクアウト設定取得
        $fromTime = '10:00:00';
        $toTime = '20:00:00';
        $capacity = 5;
        $minPrep = 30;
        try {
            $ssStmt = $pdo->prepare(
                'SELECT takeout_available_from, takeout_available_to, takeout_slot_capacity, takeout_min_prep_minutes
                 FROM store_settings WHERE store_id = ?'
            );
            $ssStmt->execute([$storeId]);
            $ssRow = $ssStmt->fetch();
            if ($ssRow) {
                $fromTime = $ssRow['takeout_available_from'] ?: '10:00:00';
                $toTime = $ssRow['takeout_available_to'] ?: '20:00:00';
                $capacity = (int)($ssRow['takeout_slot_capacity'] ?: 5);
                $minPrep = (int)($ssRow['takeout_min_prep_minutes'] ?: 30);
            }
        } catch (PDOException $e) {
            error_log('[P1-12][customer/takeout-orders.php:115] fetch_slot_settings: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        }

        // 各スロットの既存注文数を一括取得
        $countStmt = $pdo->prepare(
            "SELECT TIME_FORMAT(pickup_at, '%H:%i') AS slot_time, COUNT(*) AS cnt
             FROM orders
             WHERE store_id = ? AND order_type = 'takeout'
             AND DATE(pickup_at) = ? AND status NOT IN ('cancelled', 'paid')
             GROUP BY slot_time"
        );
        $countStmt->execute([$storeId, $date]);
        $countMap = [];
        while ($row = $countStmt->fetch()) {
            $countMap[$row['slot_time']] = (int)$row['cnt'];
        }

        // 15分刻みでスロット生成
        $now = new DateTime();
        $minPickup = (new DateTime())->modify('+' . $minPrep . ' minutes');
        $isToday = ($date === $now->format('Y-m-d'));

        $fromParts = explode(':', $fromTime);
        $toParts = explode(':', $toTime);
        $current = new DateTime($date . ' ' . $fromParts[0] . ':' . $fromParts[1] . ':00');
        $end = new DateTime($date . ' ' . $toParts[0] . ':' . $toParts[1] . ':00');

        $slots = [];
        while ($current < $end) {
            $slotKey = $current->format('H:i');

            // 当日かつ最短準備時間を過ぎていないスロットは除外
            if ($isToday && $current < $minPickup) {
                $current->modify('+15 minutes');
                continue;
            }

            $used = isset($countMap[$slotKey]) ? $countMap[$slotKey] : 0;
            $available = max(0, $capacity - $used);

            $slots[] = [
                'time' => $slotKey,
                'available' => $available,
            ];

            $current->modify('+15 minutes');
        }

        json_response(['slots' => $slots]);
    }

    // --- 注文ステータス確認 ---
    if ($action === 'status') {
        $orderId = $_GET['order_id'] ?? null;
        $phone = $_GET['phone'] ?? null;
        if (!$orderId) json_error('MISSING_ORDER', 'order_idが必要です', 400);
        if (!$phone) json_error('MISSING_PHONE', '電話番号が必要です', 400);

        $stmt = $pdo->prepare(
            "SELECT id, status, customer_name, customer_phone, pickup_at, total_amount, items, memo, created_at
             FROM orders
             WHERE id = ? AND customer_phone = ? AND order_type = 'takeout'"
        );
        $stmt->execute([$orderId, $phone]);
        $order = $stmt->fetch();
        if (!$order) json_error('NOT_FOUND', '注文が見つかりません', 404);

        // 支払い状況
        $paymentStatus = 'unpaid';
        if ($order['status'] === 'paid') {
            $paymentStatus = 'paid';
        } else {
            try {
                $pStmt = $pdo->prepare('SELECT id FROM payments WHERE order_ids LIKE ? LIMIT 1');
                $pStmt->execute(['%' . $orderId . '%']);
                if ($pStmt->fetch()) $paymentStatus = 'paid';
            } catch (PDOException $e) {
                error_log('[P1-12][customer/takeout-orders.php:190] check_payment_status: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
            }
        }

        json_response([
            'order_id' => $order['id'],
            'status' => $order['status'],
            'customer_name' => $order['customer_name'],
            'pickup_at' => $order['pickup_at'],
            'total_amount' => (int)$order['total_amount'],
            'items' => json_decode($order['items'], true) ?: [],
            'payment_status' => $paymentStatus,
            'created_at' => $order['created_at'],
        ]);
    }

    json_error('INVALID_ACTION', 'actionパラメータが不正です', 400);
}

// ===== POST: テイクアウト注文作成 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_rate_limit('takeout-order', 10, 3600);

    $data = get_json_body();

    $storeId = $data['store_id'] ?? null;
    $items = $data['items'] ?? [];
    $customerName = trim($data['customer_name'] ?? '');
    $customerPhone = trim($data['customer_phone'] ?? '');
    $pickupAt = $data['pickup_at'] ?? null;
    $memo = trim($data['memo'] ?? '');
    $paymentMethod = $data['payment_method'] ?? 'online';
    $idempotencyKey = $data['idempotency_key'] ?? null;

    // バリデーション
    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    if (empty($items)) json_error('EMPTY_CART', 'カートが空です', 400);
    if (!$customerName) json_error('MISSING_NAME', 'お名前を入力してください', 400);
    if (!$customerPhone) json_error('MISSING_PHONE', '電話番号を入力してください', 400);
    if (!preg_match('/^[0-9]{10,11}$/', $customerPhone)) json_error('INVALID_PHONE', '電話番号はハイフンなしの10〜11桁で入力してください', 400);
    if (!$pickupAt) json_error('MISSING_PICKUP', '受取時間を選択してください', 400);
    if ($paymentMethod !== 'online') json_error('ONLINE_PAYMENT_REQUIRED', 'テイクアウトはオンライン決済のみご利用いただけます', 400);

    // 店舗存在確認
    $stmt = $pdo->prepare('SELECT s.id, s.tenant_id FROM stores s WHERE s.id = ? AND s.is_active = 1');
    $stmt->execute([$storeId]);
    $store = $stmt->fetch();
    if (!$store) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);

    // テイクアウト有効チェック
    $takeoutEnabled = 0;
    try {
        $ssStmt = $pdo->prepare('SELECT takeout_enabled, takeout_min_prep_minutes, takeout_available_from, takeout_available_to, takeout_slot_capacity FROM store_settings WHERE store_id = ?');
        $ssStmt->execute([$storeId]);
        $ss = $ssStmt->fetch();
        if ($ss) $takeoutEnabled = (int)$ss['takeout_enabled'];
    } catch (PDOException $e) {
        error_log('[P1-12][customer/takeout-orders.php:245] check_takeout_enabled: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }
    if (!$takeoutEnabled) json_error('TAKEOUT_DISABLED', '現在テイクアウトは受け付けていません', 400);

    // 冪等キーチェック
    if ($idempotencyKey) {
        $idStmt = $pdo->prepare("SELECT id FROM orders WHERE idempotency_key = ? AND store_id = ?");
        $idStmt->execute([$idempotencyKey, $storeId]);
        $existing = $idStmt->fetch();
        if ($existing) {
            json_response(['order_id' => $existing['id'], 'status' => 'pending', 'duplicate' => true]);
        }
    }

    // 受取時間バリデーション
    $pickupDt = new DateTime($pickupAt);
    $nowDt = new DateTime();
    $minPrepMinutes = $ss ? (int)$ss['takeout_min_prep_minutes'] : 30;
    $minPickupDt = (clone $nowDt)->modify('+' . $minPrepMinutes . ' minutes');
    if ($pickupDt < $minPickupDt) {
        json_error('PICKUP_TOO_EARLY', '受取時間は現在から' . $minPrepMinutes . '分以降を指定してください', 400);
    }

    // スロット空き確認 (事前判定)
    $slotCapacity = $ss ? (int)$ss['takeout_slot_capacity'] : 5;
    $slotTime = $pickupDt->format('H:i');
    $slotDate = $pickupDt->format('Y-m-d');
    $slotStmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt FROM orders
         WHERE store_id = ? AND order_type = 'takeout'
         AND DATE(pickup_at) = ? AND TIME_FORMAT(pickup_at, '%H:%i') = ?
         AND status NOT IN ('cancelled', 'paid')"
    );
    $slotStmt->execute([$storeId, $slotDate, $slotTime]);
    $slotCount = (int)$slotStmt->fetch()['cnt'];
    if ($slotCount >= $slotCapacity) {
        json_error('SLOT_FULL', 'この時間枠は満席です。別の時間を選択してください', 409);
    }
    // S3 #8: 並列受注のレース検出は INSERT トランザクション内で再カウントする (下記)

    // 品数・金額チェック
    $maxItems = 10;
    $maxAmount = 30000;
    try {
        $limStmt = $pdo->prepare('SELECT max_items_per_order, max_amount_per_order FROM store_settings WHERE store_id = ?');
        $limStmt->execute([$storeId]);
        $limRow = $limStmt->fetch();
        if ($limRow) {
            $maxItems = (int)($limRow['max_items_per_order'] ?: 10);
            $maxAmount = (int)($limRow['max_amount_per_order'] ?: 30000);
        }
    } catch (PDOException $e) {
        error_log('[P1-12][customer/takeout-orders.php:294] fetch_max_limits: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }

    // P0 #1+#2: サーバー側で価格・商品存在・数量を強制再検証
    // (テイクアウトはプランセッション不適用 → $isPlanSession=false)
    $validated = validate_and_recompute_items($pdo, $storeId, $items, false, null);
    $items = $validated['items'];
    $totalAmount = $validated['total_amount'];

    $totalQty = 0;
    foreach ($items as $item) {
        $totalQty += (int)$item['qty'];
    }
    if ($totalQty > $maxItems) json_error('TOO_MANY_ITEMS', '品数が上限（' . $maxItems . '品）を超えています', 400);
    if ($totalAmount > $maxAmount) json_error('AMOUNT_EXCEEDED', '金額が上限（¥' . number_format($maxAmount) . '）を超えています', 400);

    // 注文ステータス決定
    $status = ($paymentMethod === 'online') ? 'pending_payment' : 'pending';

    // 注文作成
    $orderId = generate_uuid();
    $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
    $pickupAtFormatted = $pickupDt->format('Y-m-d H:i:00');

    // S3 #8: スロット容量超過の race を防止するため、既存件数の FOR UPDATE 再カウント →
    //        容量チェック → INSERT を atomic に実行する。
    try {
        $pdo->beginTransaction();

        // 同スロットの既存 takeout 注文を FOR UPDATE で行ロック
        $lockStmt = $pdo->prepare(
            "SELECT id FROM orders
             WHERE store_id = ? AND order_type = 'takeout'
               AND DATE(pickup_at) = ? AND TIME_FORMAT(pickup_at, '%H:%i') = ?
               AND status NOT IN ('cancelled','paid')
             FOR UPDATE"
        );
        $lockStmt->execute([$storeId, $slotDate, $slotTime]);
        $lockedRows = $lockStmt->fetchAll();
        if (count($lockedRows) >= $slotCapacity) {
            $pdo->rollBack();
            json_error('SLOT_FULL', 'この時間枠は満席になりました。別の時間を選択してください', 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO orders (
                id, store_id, table_id, items, total_amount, status,
                order_type, staff_id, customer_name, customer_phone, pickup_at,
                memo, idempotency_key, created_at, updated_at
             ) VALUES (?, ?, NULL, ?, ?, ?, "takeout", NULL, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $orderId,
            $storeId,
            $itemsJson,
            $totalAmount,
            $status,
            $customerName,
            $customerPhone,
            $pickupAtFormatted,
            $memo ?: null,
            $idempotencyKey
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // idempotency_key UNIQUE 衝突時は既存注文を返す (二重送信救済)
        if ($e->getCode() === '23000' && $idempotencyKey) {
            $idStmt = $pdo->prepare('SELECT id FROM orders WHERE idempotency_key = ? AND store_id = ?');
            $idStmt->execute([$idempotencyKey, $storeId]);
            $existing = $idStmt->fetch();
            if ($existing) {
                json_response(['order_id' => $existing['id'], 'status' => 'pending', 'duplicate' => true]);
            }
        }
        error_log('[S3#8][takeout-orders] insert_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        json_error('DB_ERROR', '注文作成に失敗しました', 500);
    }

    // order_items テーブル書き込み
    insert_order_items($pdo, $orderId, $storeId, $items);

    // オンライン決済の場合: チェックアウトURL生成
    if ($paymentMethod === 'online') {
        // 2026-04-21: success/cancel URL を本番 docroot (/public/customer/) 配下に合わせて固定。
        // 旧実装は dirname(dirname($_SERVER['SCRIPT_NAME'])) で /api/customer/ からの相対計算を
        // していたが、本番の実配置では takeout.html は /public/customer/takeout.html で配信される
        // ため、decision 成功後の Stripe success redirect が 404 になり、takeout.html onload 経由で
        // 呼ばれる takeout-payment.php のチェーンが動かず payments 行が作られない問題があった。
        // checkout-session.php:101 と同じく /public/customer/... を明示する。
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                 . '://' . $_SERVER['HTTP_HOST'];
        $takeoutPageUrl = $baseUrl . '/public/customer/takeout.html';
        $successUrl = $takeoutPageUrl . '?store_id=' . urlencode($storeId)
                    . '&order_id=' . urlencode($orderId)
                    . '&payment_status=success'
                    . '&phone=' . urlencode($customerPhone);
        $cancelUrl = $takeoutPageUrl . '?store_id=' . urlencode($storeId)
                   . '&order_id=' . urlencode($orderId)
                   . '&payment_status=cancel'
                   . '&phone=' . urlencode($customerPhone);
        $orderName = 'テイクアウト注文 #' . substr($orderId, 0, 8);

        // P0 #5: Stripe Session metadata (テイクアウト)
        $stripeMetadata = array(
            'tenant_id'        => (string)$store['tenant_id'],
            'store_id'         => (string)$storeId,
            'expected_amount'  => (string)$totalAmount,
            'expected_currency' => 'jpy',
            'order_ids'        => (string)$orderId,
            'purpose'          => 'takeout',
        );

        // L-13: Stripe Connect 判定（Connect が有効なら優先的に使用）
        $connectHandled = false;
        try {
            require_once __DIR__ . '/../lib/stripe-connect.php';
            $connectInfo = get_tenant_connect_info($pdo, $store['tenant_id']);
            if ($connectInfo && (int)$connectInfo['connect_onboarding_complete'] === 1) {
                $platformConfig = get_platform_stripe_config($pdo);
                if ($platformConfig['secret_key']) {
                    $appFee = calculate_application_fee($totalAmount, $platformConfig['fee_percent']);
                    $result = create_stripe_connect_checkout_session(
                        $platformConfig['secret_key'],
                        $connectInfo['stripe_connect_account_id'],
                        $totalAmount, 'JPY', $appFee, $orderName, $successUrl, $cancelUrl,
                        $stripeMetadata
                    );
                    if ($result['success'] && $result['checkout_url']) {
                        try {
                            $pdo->prepare("UPDATE orders SET memo = CONCAT(COALESCE(memo,''), '\n[stripe_session:', ?, ']') WHERE id = ?")
                                ->execute([$result['session_id'], $orderId]);
                        } catch (PDOException $e) {
                            error_log('[P1-12][api/customer/takeout-orders.php:373] stripe_connect_session_link: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
                        }
                        json_response(['order_id' => $orderId, 'status' => 'pending_payment', 'checkout_url' => $result['checkout_url']]);
                    }
                    // Connect決済URL生成失敗 → 注文をキャンセルしてエラー返却 (S3: 店頭払い廃止)
                    $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$orderId]);
                    error_log('[S3][takeout-orders.php] connect_checkout_failed: ' . ($result['error'] ?? 'unknown'), 3, '/home/odah/log/php_errors.log');
                    json_error('PAYMENT_NOT_AVAILABLE', '決済処理に失敗しました。お手数ですが店舗にお問い合わせください', 503);
                }
            }
        } catch (Exception $e) {
            // stripe-connect.php 未存在やカラム未存在時はスキップ（C-3にフォールスルー）
            error_log('[P1-12][api/customer/takeout-orders.php:381] stripe_connect_fallthrough: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        }

        // C-3 / P1-2: 従来の直接決済（Stripe のみ）
        $gwConfig = get_payment_gateway_config($pdo, $store['tenant_id']);
        if (!$gwConfig || $gwConfig['gateway'] !== 'stripe') {
            // 決済未設定 → 注文キャンセル + エラー返却 (S3: 店頭払い廃止)
            $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$orderId]);
            error_log('[S3][takeout-orders.php] gateway_not_configured store=' . $storeId, 3, '/home/odah/log/php_errors.log');
            json_error('PAYMENT_NOT_AVAILABLE', 'この店舗ではオンライン決済が設定されていません', 503);
        }

        $result = create_stripe_checkout_session(
            $gwConfig['token'], $totalAmount, 'JPY', $orderName, $successUrl, $cancelUrl,
            $stripeMetadata
        );
        if ($result['success'] && $result['checkout_url']) {
            // セッションIDを注文に紐付け（後で決済確認に使用）
            try {
                $pdo->prepare("UPDATE orders SET memo = CONCAT(COALESCE(memo,''), '\n[stripe_session:', ?, ']') WHERE id = ?")
                    ->execute([$result['session_id'], $orderId]);
            } catch (PDOException $e) {
                error_log('[P1-12][api/customer/takeout-orders.php:401] stripe_direct_session_link: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
            }
            json_response(['order_id' => $orderId, 'status' => 'pending_payment', 'checkout_url' => $result['checkout_url']]);
        }
        // 決済URL生成失敗 → 注文をキャンセルしてエラー返却 (S3: 店頭払い廃止)
        $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$orderId]);
        error_log('[S3][takeout-orders.php] direct_checkout_failed: ' . ($result['error'] ?? 'unknown'), 3, '/home/odah/log/php_errors.log');
        json_error('PAYMENT_NOT_AVAILABLE', '決済処理に失敗しました。お手数ですが店舗にお問い合わせください', 503);
    }

    json_response(['order_id' => $orderId, 'status' => 'pending']);
}
