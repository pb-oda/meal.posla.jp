<?php
/**
 * テイクアウト オンライン決済確認 API（顧客向け・認証不要）
 *
 * GET ?order_id=xxx&session_id=xxx（Stripe用）
 * P1-2: Square 削除済み
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/payment-gateway.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

$method = require_method(['GET']);

// H-03: 決済確認ポーリング濫用防御 — 1IP あたり 30 回 / 5 分
// 正常完了は数回で終わるため brute-force 検出の基準として設定。
check_rate_limit('takeout-payment', 30, 300);

$pdo = get_db();

$orderId = $_GET['order_id'] ?? null;
$sessionId = $_GET['session_id'] ?? null;

if (!$orderId) json_error('MISSING_ORDER', 'order_idが必要です', 400);

// 注文取得（pending_payment のもののみ）
$stmt = $pdo->prepare(
    "SELECT o.id, o.store_id, o.status, o.total_amount, o.memo,
            s.tenant_id
     FROM orders o
     JOIN stores s ON s.id = o.store_id
     WHERE o.id = ? AND o.order_type = 'takeout'"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) json_error('NOT_FOUND', '注文が見つかりません', 404);

// 既に決済確認済みの場合
if ($order['status'] !== 'pending_payment') {
    json_response([
        'order_id' => $orderId,
        'payment_confirmed' => ($order['status'] !== 'cancelled'),
        'status' => $order['status'],
    ]);
}

$verified = false;
$gatewayName = '';
$externalPaymentId = '';
$gatewayStatus = '';
$verifyResult = null;  // P0 #5: metadata 照合のため verify 結果を保持

// L-13: Stripe Connect 経由の決済検証（プラットフォームキーで検証）
$connectUsed = false;
try {
    require_once __DIR__ . '/../lib/stripe-connect.php';
    $connectInfo = get_tenant_connect_info($pdo, $order['tenant_id']);
    if ($connectInfo && (int)$connectInfo['connect_onboarding_complete'] === 1) {
        $platformConfig = get_platform_stripe_config($pdo);
        if ($platformConfig['secret_key']) {
            // session_id 取得（URLパラメータ or 注文メモ）
            if (!$sessionId) {
                if (preg_match('/\[stripe_session:([^\]]+)\]/', $order['memo'] ?? '', $m)) {
                    $sessionId = $m[1];
                }
            }
            if ($sessionId) {
                // 2026-04-21: Connect Checkout session retrieve context の修正。
                // takeout-orders.php は create_stripe_connect_checkout_session で
                // destination charges パターン (payment_intent_data[transfer_data][destination])
                // を使うため、session は **Platform account 側** に存在する (create 時に
                // Stripe-Account header なし)。従って retrieve / verify も Platform context
                // で叩く必要がある。第 3 引数 (Stripe-Account) を付けると "No such checkout.session"
                // の 404 が返り、PAYMENT_NOT_CONFIRMED 402 で orders.status 遷移と payments 行
                // INSERT が走らなかった。create 側と対称にして第 3 引数を省略する。
                // なお checkout-confirm.php:70 の同パターンは今回スコープ外 (別論点)。
                $result = verify_stripe_checkout($platformConfig['secret_key'], $sessionId);
                if ($result['success']) {
                    $verified = true;
                    $gatewayName = 'stripe_connect';
                    $externalPaymentId = $result['payment_intent_id'] ?: $sessionId;
                    // 4d-5c-bb-D: refund-payment.php:152 は gateway_status==='succeeded' を要求。
                    // Stripe Checkout session の payment_status は 'paid' を返すため、
                    // 'paid' のまま保存すると refund 時に INVALID_STATUS 400 で弾かれていた。
                    // checkout-confirm.php:77 と同じく 'succeeded' にハードコード正規化する。
                    $gatewayStatus = 'succeeded';
                    $connectUsed = true;
                    $verifyResult = $result;
                } else {
                    json_error('PAYMENT_NOT_CONFIRMED', $result['error'] ?: '決済が確認できませんでした', 402);
                }
            }
        }
    }
} catch (Exception $e) {
    // stripe-connect.php 未存在やカラム未存在時はスキップ（C-3にフォールスルー）
}

// C-3 / P1-2: 従来の直接決済検証（Connect 未使用 + Stripe のみ）
if (!$connectUsed) {
    $gwConfig = get_payment_gateway_config($pdo, $order['tenant_id']);
    if (!$gwConfig || $gwConfig['gateway'] !== 'stripe') {
        json_error('NO_GATEWAY', '決済ゲートウェイが設定されていません', 500);
    }

    // Stripe: セッションID で確認
    // URLパラメータのsession_idが無い場合、注文メモから取得
    if (!$sessionId) {
        if (preg_match('/\[stripe_session:([^\]]+)\]/', $order['memo'] ?? '', $m)) {
            $sessionId = $m[1];
        }
    }
    if (!$sessionId) json_error('MISSING_SESSION', 'session_idが必要です', 400);

    $result = verify_stripe_checkout($gwConfig['token'], $sessionId);
    if ($result['success']) {
        $verified = true;
        $gatewayName = 'stripe';
        $externalPaymentId = $result['payment_intent_id'] ?: $sessionId;
        // 4d-5c-bb-D: Connect 経路と同じく 'succeeded' に正規化 (refund-payment.php:152 ガード対応)
        $gatewayStatus = 'succeeded';
        $verifyResult = $result;
    } else {
        json_error('PAYMENT_NOT_CONFIRMED', $result['error'] ?: '決済が確認できませんでした', 402);
    }
}

if (!$verified) {
    json_error('VERIFICATION_FAILED', '決済の確認に失敗しました', 500);
}

// ── P0 #5: metadata 照合 (別注文 session の流用攻撃を阻止) ──
$md = (isset($verifyResult['metadata']) && is_array($verifyResult['metadata'])) ? $verifyResult['metadata'] : array();
$mdTenant = isset($md['tenant_id']) ? (string)$md['tenant_id'] : '';
$mdStore  = isset($md['store_id']) ? (string)$md['store_id'] : '';
$mdExpAmt = isset($md['expected_amount']) ? (int)$md['expected_amount'] : -1;
$mdExpCur = isset($md['expected_currency']) ? strtolower((string)$md['expected_currency']) : '';
$mdOrderIds = isset($md['order_ids']) ? (string)$md['order_ids'] : '';
$mdPurpose = isset($md['purpose']) ? (string)$md['purpose'] : '';
$amountTotal = isset($verifyResult['amount_total']) ? (int)$verifyResult['amount_total'] : -1;
$verifiedCurrency = isset($verifyResult['currency']) ? strtolower((string)$verifyResult['currency']) : '';

if ($mdTenant === '' || $mdStore === '' || $mdExpAmt < 0 || $mdExpCur === '' || $mdOrderIds === '') {
    error_log('[P0#5][takeout-payment] STRIPE_MISMATCH metadata_missing session=' . $sessionId, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (metadata 不足)', 403);
}
if ($mdTenant !== (string)$order['tenant_id'] || $mdStore !== (string)$order['store_id']) {
    error_log('[P0#5][takeout-payment] STRIPE_MISMATCH context tenant_md=' . $mdTenant . ' store_md=' . $mdStore, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (テナント/店舗)', 403);
}
if ($mdPurpose !== 'takeout') {
    error_log('[P0#5][takeout-payment] STRIPE_MISMATCH purpose=' . $mdPurpose, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (用途)', 403);
}
if ($mdExpCur !== 'jpy' || $verifiedCurrency !== 'jpy') {
    error_log('[P0#5][takeout-payment] STRIPE_MISMATCH currency md=' . $mdExpCur . ' stripe=' . $verifiedCurrency, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (通貨)', 403);
}
if ($mdExpAmt !== $amountTotal || $mdExpAmt !== (int)$order['total_amount']) {
    error_log('[P0#5][takeout-payment] STRIPE_MISMATCH amount db=' . (int)$order['total_amount'] . ' md=' . $mdExpAmt . ' stripe=' . $amountTotal, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (金額)', 403);
}
$mdOrderIdList = array_filter(array_map('trim', explode(',', $mdOrderIds)), 'strlen');
if (!in_array($orderId, $mdOrderIdList, true)) {
    error_log('[P0#5][takeout-payment] STRIPE_MISMATCH order_id_not_in_md md=' . $mdOrderIds . ' req=' . $orderId, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (注文ID)', 403);
}

// 4d-5c-bb-F: 決済成功後の DB 永続化 (orders.status UPDATE + payments INSERT) を
//   単一 transaction に閉じ込める。旧実装は INSERT 失敗を try/catch で握りつぶして
//   `payment_confirmed:true` の success response を返していたため、payments 欠落の
//   false positive success (売上漏れ / refund 不可 / receipt 不可) が発生していた。
//   新実装は永続化失敗時に rollBack + json_error('PAYMENT_RECORD_FAILED', ..., 500)
//   に切り替える。external_payment_id はログのみに記録し、customer-facing response
//   には露出させない (運営のリカバリ手掛かりは php_errors.log を参照)。
//
// 決済 payload:
//   payment_method は payments ENUM('cash','card','qr') の範囲で記録する必要がある。
//   Stripe Checkout によるオンライン takeout 決済は本質的にカード決済なので、既に
//   checkout-confirm.php:276 で採用されている `payment_method='card'` に揃える。
//   gateway_name (stripe / stripe_connect) と external_payment_id で「オンライン由来」を
//   識別できる (旧値 "online" は ENUM 外で 4d-5c-bb-A-post1 で 'card' に是正済)。

// ゲートウェイ情報カラム存在チェック (TX 外の read-only チェック、失敗しても問題ない)
$hasGwCols = false;
try {
    $pdo->query('SELECT gateway_name FROM payments LIMIT 0');
    $hasGwCols = true;
} catch (PDOException $e) {}

$paymentId = bin2hex(random_bytes(16));
$pdo->beginTransaction();
try {
    // UPDATE orders: status='pending_payment' 条件を付けて冪等 + 並行遷移検知
    $upd = $pdo->prepare(
        "UPDATE orders SET status = 'pending', updated_at = NOW()
         WHERE id = ? AND status = 'pending_payment'"
    );
    $upd->execute([$orderId]);
    if ($upd->rowCount() !== 1) {
        // 別リクエストで既に遷移済み (通常運用では L34 の早期 return 分岐で弾かれるので稀)
        throw new RuntimeException('CONFLICT: orders.status transitioned concurrently');
    }

    // payments INSERT
    $cols = 'id, store_id, table_id, total_amount, payment_method, received_amount, change_amount, order_ids, created_at';
    $vals = '?, ?, NULL, ?, "card", ?, 0, ?, NOW()';
    $params = [
        $paymentId,
        $order['store_id'],
        (int)$order['total_amount'],
        (int)$order['total_amount'],
        json_encode([$orderId]),
    ];
    if ($hasGwCols) {
        $cols .= ', gateway_name, external_payment_id, gateway_status';
        $vals .= ', ?, ?, ?';
        $params[] = $gatewayName;
        $params[] = $externalPaymentId;
        $params[] = $gatewayStatus;
    }
    $pdo->prepare('INSERT INTO payments (' . $cols . ') VALUES (' . $vals . ')')->execute($params);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $e2) {}
    }
    // 運営リカバリ用の手掛かりはログのみに残す (external_payment_id / gateway_name / order_id)。
    // customer-facing response には pi_id 等の Stripe 内部識別子を露出させない。
    error_log(
        '[4d-5c-bb-F][takeout-payment] persistence_failed '
        . 'order=' . $orderId
        . ' pi=' . $externalPaymentId
        . ' gw=' . $gatewayName
        . ' err=' . $e->getMessage(),
        3,
        POSLA_PHP_ERROR_LOG
    );
    json_error(
        'PAYMENT_RECORD_FAILED',
        '決済は完了しましたが注文の記録に失敗しました。お手数ですが店舗へ直接お問い合わせください。',
        500
    );
}

json_response([
    'order_id' => $orderId,
    'payment_confirmed' => true,
    'status' => 'pending',
]);
