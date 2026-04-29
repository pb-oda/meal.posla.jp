<?php
/**
 * L-8: セルフレジ — 決済確認 + 注文完了処理
 *
 * POST /api/customer/checkout-confirm.php
 * body: { store_id, table_id, session_token, stripe_session_id }
 *
 * Stripe Checkout 完了後にフロントから呼ばれる。
 * 決済を検証し、注文を paid にし、セッションを閉じる。
 *
 * 認証不要（session_token + stripe_session_id で検証）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/payment-gateway.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

function checkout_confirm_supports_table_session_status(PDO $pdo, string $status): bool
{
    static $allowed = null;
    if ($allowed === null) {
        $allowed = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM table_sessions LIKE 'status'");
            $row = $stmt ? $stmt->fetch() : null;
            if ($row && isset($row['Type']) && preg_match("/^enum\\((.*)\\)$/", $row['Type'], $m)) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $matches);
                $allowed = $matches[1] ?? [];
            }
        } catch (Exception $e) {
            $allowed = ['seated', 'eating', 'bill_requested', 'paid', 'closed'];
        }
    }
    return in_array($status, $allowed, true);
}

function checkout_confirm_session_close_status(PDO $pdo): string
{
    return checkout_confirm_supports_table_session_status($pdo, 'cleaning') ? 'cleaning' : 'paid';
}

require_method(['POST']);

// H-03: checkout 二重確認濫用 / session_id ブルート防御 — 1IP あたり 20 回 / 5 分
check_rate_limit('customer-checkout-confirm', 20, 300);

$data = get_json_body();
$storeId        = $data['store_id'] ?? null;
$tableId        = $data['table_id'] ?? null;
$sessionToken   = $data['session_token'] ?? null;
$stripeSessionId = $data['stripe_session_id'] ?? null;

if (!$storeId || !$tableId || !$sessionToken || !$stripeSessionId) {
    json_error('MISSING_FIELDS', 'store_id, table_id, session_token, stripe_session_id は必須です', 400);
}

$pdo = get_db();
$sessionCloseStatus = checkout_confirm_session_close_status($pdo);

// ── session_token 検証 ──
$stmt = $pdo->prepare(
    'SELECT session_token, session_token_expires_at FROM tables WHERE id = ? AND store_id = ? AND is_active = 1'
);
$stmt->execute([$tableId, $storeId]);
$tableRow = $stmt->fetch();

if (!$tableRow || !$tableRow['session_token'] || !hash_equals($tableRow['session_token'], (string)$sessionToken)) {
    json_error('INVALID_SESSION', 'セッションが無効です', 403);
}
if ($tableRow['session_token_expires_at'] && strtotime($tableRow['session_token_expires_at']) < time()) {
    json_error('INVALID_SESSION', 'セッションの有効期限が切れています', 403);
}

try {
    $activeStmt = $pdo->prepare(
        "SELECT id FROM table_sessions
         WHERE table_id = ? AND store_id = ?
           AND status IN ('seated', 'eating', 'bill_requested')
         LIMIT 1"
    );
    $activeStmt->execute([$tableId, $storeId]);
    if (!$activeStmt->fetch()) {
        json_error('INVALID_SESSION', 'セッションが無効です', 403);
    }
} catch (PDOException $e) {
    // table_sessions 未存在時は従来動作
}

// ── テナント情報取得 ──
$stmt = $pdo->prepare('SELECT tenant_id FROM stores WHERE id = ?');
$stmt->execute([$storeId]);
$storeInfo = $stmt->fetch();
if (!$storeInfo) {
    json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
}
$tenantId = $storeInfo['tenant_id'];

// ── Stripe 決済検証 ──
$verified = false;
$gatewayName = null;
$externalPaymentId = null;
$gatewayStatus = null;
$verifyResult = null;  // P0 #5: metadata 照合のため verify 結果を保持

// 1. Stripe Connect を確認
try {
    require_once __DIR__ . '/../lib/stripe-connect.php';
    $connectInfo = get_tenant_connect_info($pdo, $tenantId);
    if ($connectInfo && (int)$connectInfo['connect_onboarding_complete'] === 1
        && !empty($connectInfo['stripe_connect_account_id'])) {
        $platformConfig = get_platform_stripe_config($pdo);
        if (!empty($platformConfig['secret_key'])) {
            // 2026-04-21 (Phase 4d-5c-bb-G): Connect Checkout session retrieve の context 不一致修正。
            //   checkout-session.php:139 は create_stripe_connect_checkout_session を使い、
            //   destination charges パターン (payment_intent_data[transfer_data][destination]) で
            //   session を作成するため、session は **Platform account 側** に存在する。
            //   従って verify/retrieve も Platform context (Stripe-Account header なし) で
            //   行う必要がある。旧コードは Connect account ID を第 3 引数で渡して
            //   Stripe-Account header 付き retrieve をしており、Stripe 側で 404
            //   "No such checkout.session" になっていた。
            //   これは takeout-payment.php の 4d-5c-bb-D 修正と完全同型の bug。
            //   本番に既存 stripe_connect 13 件あるが、それらは過去コード時代 (2026-04-14 まで)
            //   の産物で、2026-04-21 test mode で本 bug を実証済み。
            $result = verify_stripe_checkout($platformConfig['secret_key'], $stripeSessionId);
            if ($result['success']) {
                $pStatus = $result['payment_status'] ?? '';
                if ($pStatus === 'paid') {
                    $verified = true;
                    $gatewayName = 'stripe_connect';
                    $externalPaymentId = $result['payment_intent_id'] ?: $stripeSessionId;
                    $gatewayStatus = 'succeeded';
                    $verifyResult = $result;
                } else {
                    json_error('PAYMENT_NOT_CONFIRMED', '決済が完了していません (status: ' . $pStatus . ')', 402);
                }
            }
        }
    }
} catch (Exception $e) {
    // stripe-connect.php 未存在時はフォールスルー
}

// 2. Connect で検証できなければ直接 Stripe
if (!$verified) {
    $stmt = $pdo->prepare('SELECT stripe_secret_key FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $tenantRow = $stmt->fetch();
    $stripeKey = $tenantRow ? ($tenantRow['stripe_secret_key'] ?? null) : null;

    if (!$stripeKey) {
        json_error('PAYMENT_GATEWAY_ERROR', 'Stripe設定が見つかりません', 502);
    }

    $result = verify_stripe_checkout($stripeKey, $stripeSessionId);
    if (!$result['success']) {
        json_error('PAYMENT_GATEWAY_ERROR', $result['error'] ?: '決済検証に失敗しました', 502);
    }

    $pStatus = $result['payment_status'] ?? '';
    if ($pStatus !== 'paid') {
        json_error('PAYMENT_NOT_CONFIRMED', '決済が完了していません (status: ' . $pStatus . ')', 402);
    }

    $verified = true;
    $gatewayName = 'stripe';
    $externalPaymentId = $result['payment_intent_id'] ?: $stripeSessionId;
    $gatewayStatus = 'succeeded';
    $verifyResult = $result;
}

// ── P0 #5: metadata 照合 (別注文 session の流用攻撃を阻止) ──
// verifyResult は payment-gateway.php の verify_stripe_checkout() が返す配列
// ['amount_total'=>int, 'currency'=>string, 'metadata'=>['tenant_id'=>..., 'store_id'=>..., 'table_id'=>..., 'expected_amount'=>..., 'order_ids'=>..., 'purpose'=>...]]
$md = (isset($verifyResult['metadata']) && is_array($verifyResult['metadata'])) ? $verifyResult['metadata'] : array();
$mdTenant = isset($md['tenant_id']) ? (string)$md['tenant_id'] : '';
$mdStore  = isset($md['store_id']) ? (string)$md['store_id'] : '';
$mdTable  = isset($md['table_id']) ? (string)$md['table_id'] : '';
$mdExpAmt = isset($md['expected_amount']) ? (int)$md['expected_amount'] : -1;
$mdExpCur = isset($md['expected_currency']) ? strtolower((string)$md['expected_currency']) : '';
$mdOrderIds = isset($md['order_ids']) ? (string)$md['order_ids'] : '';
$mdPurpose = isset($md['purpose']) ? (string)$md['purpose'] : '';
$amountTotal = isset($verifyResult['amount_total']) ? (int)$verifyResult['amount_total'] : -1;
$verifiedCurrency = isset($verifyResult['currency']) ? strtolower((string)$verifyResult['currency']) : '';

// 必須 metadata 欠落チェック (旧 session の流用も拒否)
if ($mdTenant === '' || $mdStore === '' || $mdTable === '' || $mdExpAmt < 0 || $mdExpCur === '' || $mdOrderIds === '') {
    error_log('[P0#5][checkout-confirm] STRIPE_MISMATCH metadata_missing session=' . $stripeSessionId, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (metadata 不足)', 403);
}
// テナント/店舗/テーブル境界
if ($mdTenant !== (string)$tenantId || $mdStore !== (string)$storeId || $mdTable !== (string)$tableId) {
    error_log('[P0#5][checkout-confirm] STRIPE_MISMATCH context tenant_md=' . $mdTenant . ' store_md=' . $mdStore . ' table_md=' . $mdTable, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (テナント/店舗/テーブル)', 403);
}
// purpose 一致
if ($mdPurpose !== 'dine_in_self_payment') {
    error_log('[P0#5][checkout-confirm] STRIPE_MISMATCH purpose=' . $mdPurpose, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (用途)', 403);
}
// 通貨一致
if ($mdExpCur !== 'jpy' || $verifiedCurrency !== 'jpy') {
    error_log('[P0#5][checkout-confirm] STRIPE_MISMATCH currency md=' . $mdExpCur . ' stripe=' . $verifiedCurrency, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (通貨)', 403);
}
// expected_amount === Stripe amount_total
if ($mdExpAmt !== $amountTotal) {
    error_log('[P0#5][checkout-confirm] STRIPE_MISMATCH amount expected=' . $mdExpAmt . ' stripe=' . $amountTotal, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (金額)', 403);
}
// metadata.order_ids を後段の paid 化フィルタに使う (ホワイトリスト)
$mdOrderIdList = array_filter(array_map('trim', explode(',', $mdOrderIds)), 'strlen');

// ── 二重処理防止（冪等性） ──
// S3 #3: トランザクション外の冪等チェックは TOCTOU が残るため
//        事前チェック（早期リターン）+ 後段でトランザクション内に再チェックを行う
$hasGatewayCols = false;
try {
    $pdo->query('SELECT gateway_name FROM payments LIMIT 0');
    $hasGatewayCols = true;
} catch (PDOException $e) {}

if ($hasGatewayCols && $externalPaymentId) {
    $stmt = $pdo->prepare('SELECT id FROM payments WHERE external_payment_id = ?');
    $stmt->execute([$externalPaymentId]);
    $existingPayment = $stmt->fetch();
    if ($existingPayment) {
        // 既に処理済み → 既存の payment_id を返す
        json_response([
            'payment_id'     => $existingPayment['id'],
            'already_processed' => true,
        ]);
    }
}

// ── 未払い注文を取得 ──
// P0 #5: metadata.order_ids にあるものだけを paid 化対象とする (ホワイトリスト)
$stmt = $pdo->prepare(
    'SELECT id, items, total_amount
     FROM orders
     WHERE table_id = ? AND store_id = ? AND session_token = ?
       AND status NOT IN (\'paid\', \'cancelled\')
     ORDER BY created_at ASC'
);
$stmt->execute([$tableId, $storeId, $sessionToken]);
$allOrders = $stmt->fetchAll();

if (empty($allOrders)) {
    json_error('NO_UNPAID_ORDERS', '未払いの注文がありません', 404);
}

// metadata.order_ids でフィルタ
$orders = array();
foreach ($allOrders as $o) {
    if (in_array($o['id'], $mdOrderIdList, true)) {
        $orders[] = $o;
    }
}
if (empty($orders)) {
    error_log('[P0#5][checkout-confirm] STRIPE_MISMATCH no_matching_orders md_ids=' . $mdOrderIds, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (注文ID)', 403);
}
// 件数も完全一致を要求 (metadata の order_ids 全件が DB に存在し未払いであること)
if (count($orders) !== count($mdOrderIdList)) {
    error_log('[P0#5][checkout-confirm] STRIPE_MISMATCH order_count md=' . count($mdOrderIdList) . ' db=' . count($orders), 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (注文ID不一致)', 403);
}

// ── 税込合計算出 ──
$subtotal10 = 0;
$tax10 = 0;
$subtotal8 = 0;
$tax8 = 0;
$totalAmount = 0;
$orderIdList = [];

foreach ($orders as $order) {
    $totalAmount += (int)$order['total_amount'];
    $orderIdList[] = $order['id'];

    $items = json_decode($order['items'] ?? '[]', true);
    if (!is_array($items)) $items = [];

    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? 1);
        $price = (int)($item['price'] ?? 0);
        $lineTotal = $price * $qty;
        $taxRate = isset($item['taxRate']) ? (int)$item['taxRate'] : 10;

        if ($taxRate === 8) {
            $sub = (int)floor($lineTotal / 1.08);
            $subtotal8 += $sub;
            $tax8 += $lineTotal - $sub;
        } else {
            $sub = (int)floor($lineTotal / 1.10);
            $subtotal10 += $sub;
            $tax10 += $lineTotal - $sub;
        }
    }
}

// P0 #5: DB 上の合計が Stripe 側の amount_total/expected_amount と一致することを最終確認
if ($totalAmount !== $mdExpAmt || $totalAmount !== $amountTotal) {
    error_log('[P0#5][checkout-confirm] STRIPE_MISMATCH final_amount db=' . $totalAmount . ' md=' . $mdExpAmt . ' stripe=' . $amountTotal, 3, POSLA_PHP_ERROR_LOG);
    json_error('STRIPE_MISMATCH', '決済情報が一致しません (合計金額)', 403);
}

// ── session_id を取得 ──
$sessionId = null;
try {
    $stmt = $pdo->prepare(
        'SELECT id FROM table_sessions WHERE store_id = ? AND table_id = ? AND status NOT IN (\'paid\', \'closed\') ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$storeId, $tableId]);
    $sessRow = $stmt->fetch();
    $sessionId = $sessRow ? $sessRow['id'] : null;
} catch (PDOException $e) {
    // table_sessions 未存在時はスキップ
    error_log('[P1-12][api/customer/checkout-confirm.php:191] fetch_session_id: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// ── カラム存在チェック ──
$hasMergedCols = false;
try {
    $pdo->query('SELECT merged_table_ids FROM payments LIMIT 0');
    $hasMergedCols = true;
} catch (PDOException $e) {}

// ── トランザクション ──
$paymentId = generate_uuid();
$paymentMethod = 'card';
$receivedAmount = $totalAmount;
$changeAmount = 0;

$pdo->beginTransaction();
try {
    // ── S3 #3: トランザクション内 idempotency 再確認 (TOCTOU 防止) ──
    if ($hasGatewayCols && $externalPaymentId) {
        $dupStmt = $pdo->prepare('SELECT id FROM payments WHERE external_payment_id = ? FOR UPDATE');
        $dupStmt->execute([$externalPaymentId]);
        $dupRow = $dupStmt->fetch();
        if ($dupRow) {
            $pdo->commit();
            json_response([
                'payment_id'        => $dupRow['id'],
                'already_processed' => true,
            ]);
        }
    }

    // ── S3 #3: 対象注文を FOR UPDATE で再ロック → status 再確認 ──
    if (!empty($orderIdList)) {
        $lockPh = implode(',', array_fill(0, count($orderIdList), '?'));
        $lockStmt = $pdo->prepare(
            'SELECT id, status FROM orders
             WHERE id IN (' . $lockPh . ')
               AND store_id = ?
             FOR UPDATE'
        );
        $lockStmt->execute(array_merge($orderIdList, [$storeId]));
        $lockedRows = $lockStmt->fetchAll();
        if (count($lockedRows) !== count($orderIdList)) {
            throw new RuntimeException('CONFLICT: order_count_mismatch');
        }
        foreach ($lockedRows as $lr) {
            if (in_array($lr['status'], ['paid', 'cancelled'], true)) {
                throw new RuntimeException('CONFLICT: order_already_finalized id=' . $lr['id']);
            }
        }
    }

    // payments レコード作成
    if ($hasMergedCols) {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, merged_table_ids, merged_session_ids, session_id, order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8, total_amount, payment_method, received_amount, change_amount, is_partial, user_id, paid_at, created_at)
             VALUES (?, ?, ?, NULL, NULL, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NOW(), NOW())'
        )->execute([
            $paymentId, $storeId, $tableId, $sessionId,
            json_encode($orderIdList),
            $subtotal10, $tax10, $subtotal8, $tax8,
            $totalAmount, $paymentMethod, $receivedAmount, $changeAmount,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, session_id, order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8, total_amount, payment_method, received_amount, change_amount, is_partial, user_id, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NOW(), NOW())'
        )->execute([
            $paymentId, $storeId, $tableId, $sessionId,
            json_encode($orderIdList),
            $subtotal10, $tax10, $subtotal8, $tax8,
            $totalAmount, $paymentMethod, $receivedAmount, $changeAmount,
        ]);
    }

    // gateway 情報を UPDATE
    if ($hasGatewayCols && $gatewayName) {
        $pdo->prepare(
            'UPDATE payments SET gateway_name = ?, external_payment_id = ?, gateway_status = ? WHERE id = ?'
        )->execute([$gatewayName, $externalPaymentId, $gatewayStatus, $paymentId]);
    }

    // 注文ステータスを paid に更新
    // S3 #3: 既 paid/cancelled の order は除外し、影響件数が一致しなければ CONFLICT で ROLLBACK
    if (!empty($orderIdList)) {
        $placeholders = implode(',', array_fill(0, count($orderIdList), '?'));
        $updOrders = $pdo->prepare(
            'UPDATE orders SET status = ?, payment_method = ?, received_amount = ?, change_amount = ?,
                    paid_at = NOW(), updated_at = NOW()
             WHERE id IN (' . $placeholders . ')
               AND store_id = ?
               AND status NOT IN (\'paid\', \'cancelled\')'
        );
        $updOrders->execute(array_merge(['paid', $paymentMethod, $receivedAmount, $changeAmount], $orderIdList, [$storeId]));
        if ($updOrders->rowCount() !== count($orderIdList)) {
            throw new RuntimeException('CONFLICT: order_update_rowcount_mismatch expected=' . count($orderIdList) . ' actual=' . $updOrders->rowCount());
        }
    }

    // テーブルセッションを閉じる + トークン再生成
    // S3 #15: store_id 境界
    $newToken = bin2hex(random_bytes(16));
    $pdo->prepare(
        'UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ? AND store_id = ?'
    )->execute([$newToken, $tableId, $storeId]);

    try {
        $pdo->prepare(
            'UPDATE table_sessions
                SET status = ?,
                    closed_at = CASE WHEN ? = "paid" THEN NOW() ELSE closed_at END
             WHERE store_id = ? AND table_id = ? AND status NOT IN (\'paid\', \'closed\')'
        )->execute([$sessionCloseStatus, $sessionCloseStatus, $storeId, $tableId]);
    } catch (PDOException $e) {
        // table_sessions 未存在時はスキップ
        error_log('[P1-12][api/customer/checkout-confirm.php:261] checkout_mark_paid: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }

    // ── レシピ連動在庫引き落とし ──
    // INV-P1-5: 冪等性マーカー + 消費履歴ログで二重消費防止と audit trail 追加
    try {
        $pdo->query('SELECT 1 FROM recipes LIMIT 0');
        $pdo->query('SELECT 1 FROM ingredients LIMIT 0');

        // INV-P1-5: column / table 存在チェック (migration 未適用でも既存挙動)
        $hasStockConsumedCol = false;
        try { $pdo->query('SELECT stock_consumed_at FROM orders LIMIT 0'); $hasStockConsumedCol = true; }
        catch (PDOException $e) {}
        $hasConsumeLog = false;
        try { $pdo->query('SELECT 1 FROM inventory_consumption_log LIMIT 0'); $hasConsumeLog = true; }
        catch (PDOException $e) {}

        // INV-P1-5: orders レベルで冪等性マーカーを atomic claim。
        //   self-checkout は常に全額会計なので partial 分岐は不要。
        //   stock_consumed_at IS NULL の orders だけ消費対象にする。
        $consumeOrderIds = [];
        if ($hasStockConsumedCol && !empty($orderIdList)) {
            $markStmt = $pdo->prepare(
                'UPDATE orders SET stock_consumed_at = NOW()
                 WHERE id = ? AND store_id = ? AND stock_consumed_at IS NULL'
            );
            foreach ($orderIdList as $oid) {
                $markStmt->execute([$oid, $storeId]);
                if ($markStmt->rowCount() === 1) $consumeOrderIds[$oid] = true;
            }
        } else {
            // migration 未適用: 既存通り全 orders を対象
            foreach (($orders ?? []) as $o) {
                if (isset($o['id'])) $consumeOrderIds[$o['id']] = true;
            }
        }

        // 対象品目の集計: {menu_item_id => qty}
        // INV-P1-5: per-(order, menu_item) も保持して log 作成に使う
        $itemQtyMap = [];
        $perOrderQtyMap = []; // { order_id => { menu_item_id => qty } }
        foreach ($orders as $order) {
            $oid = $order['id'] ?? null;
            // INV-P1-5: マーカーが取れた orders のみ消費対象
            if ($hasStockConsumedCol && $oid && empty($consumeOrderIds[$oid])) {
                continue;
            }
            $oItems = json_decode($order['items'] ?? '[]', true);
            if (!is_array($oItems)) continue;
            foreach ($oItems as $oi) {
                $itemId = $oi['id'] ?? null;
                if (!$itemId) continue;
                $q = (int)($oi['qty'] ?? 1);
                $itemQtyMap[$itemId] = ($itemQtyMap[$itemId] ?? 0) + $q;
                if ($oid) {
                    if (!isset($perOrderQtyMap[$oid])) $perOrderQtyMap[$oid] = [];
                    $perOrderQtyMap[$oid][$itemId] = ($perOrderQtyMap[$oid][$itemId] ?? 0) + $q;
                }
            }
        }

        if (!empty($itemQtyMap)) {
            $menuIds = array_keys($itemQtyMap);
            $ph = implode(',', array_fill(0, count($menuIds), '?'));
            // S3 #6: tenant_id 境界 — menu_templates / ingredients を JOIN して同テナントのレシピのみ取得
            $recipeStmt = $pdo->prepare(
                "SELECT r.menu_template_id, r.ingredient_id, r.quantity
                 FROM recipes r
                 INNER JOIN menu_templates mt ON mt.id = r.menu_template_id
                 INNER JOIN ingredients ing ON ing.id = r.ingredient_id
                 WHERE r.menu_template_id IN ($ph)
                   AND mt.tenant_id = ?
                   AND ing.tenant_id = ?"
            );
            $recipeStmt->execute(array_merge($menuIds, [$tenantId, $tenantId]));
            $recipeRows = $recipeStmt->fetchAll();

            $deductions = [];
            foreach ($recipeRows as $rr) {
                $menuId = $rr['menu_template_id'];
                $ingId = $rr['ingredient_id'];
                $recipeQty = (float)$rr['quantity'];
                $orderQty = $itemQtyMap[$menuId] ?? 0;
                $deductions[$ingId] = ($deductions[$ingId] ?? 0) + ($recipeQty * $orderQty);
            }

            // S3 #6: tenant_id 境界 — 同テナントの原材料のみ更新
            $updateStmt = $pdo->prepare(
                'UPDATE ingredients SET stock_quantity = stock_quantity - ? WHERE id = ? AND tenant_id = ?'
            );
            foreach ($deductions as $ingId => $amount) {
                $updateStmt->execute([$amount, $ingId, $tenantId]);
            }

            // INV-P1-5: 消費履歴ログ (per-(order, ingredient))
            if ($hasConsumeLog && !empty($perOrderQtyMap)) {
                $logStmt = $pdo->prepare(
                    'INSERT INTO inventory_consumption_log
                     (id, tenant_id, store_id, order_id, ingredient_id, quantity, consumed_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                $recipeByMenu = [];
                foreach ($recipeRows as $rr) {
                    $mId = $rr['menu_template_id'];
                    if (!isset($recipeByMenu[$mId])) $recipeByMenu[$mId] = [];
                    $recipeByMenu[$mId][] = [
                        'ingredient_id' => $rr['ingredient_id'],
                        'quantity' => (float)$rr['quantity'],
                    ];
                }
                foreach ($perOrderQtyMap as $ordId => $menuMap) {
                    foreach ($menuMap as $mId => $qty) {
                        if (empty($recipeByMenu[$mId])) continue;
                        foreach ($recipeByMenu[$mId] as $r) {
                            $logStmt->execute([
                                generate_uuid(),
                                $tenantId,
                                $storeId,
                                $ordId,
                                $r['ingredient_id'],
                                $r['quantity'] * $qty,
                            ]);
                        }
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // recipes/ingredients テーブル未作成時はスキップ
        error_log('[P1-12][api/customer/checkout-confirm.php:307] recipe_deduct_stock: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    // S3 #11: 内部エラーメッセージはログのみ、レスポンスは固定文言
    error_log('[L-8 checkout-confirm] ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    // S3 #3: CONFLICT は 409 で返す (二重決済確定検知)
    if (strpos($e->getMessage(), 'CONFLICT') === 0) {
        json_error('CONFLICT', 'この注文は既に他の操作で確定されました。画面を更新してご確認ください。', 409);
    }
    json_error('PAYMENT_GATEWAY_ERROR', '決済処理に失敗しました。時間を置いて再試行してください。', 502);
}

json_response([
    'payment_id'     => $paymentId,
    'total'          => $totalAmount,
    'subtotal_10'    => $subtotal10,
    'tax_10'         => $tax10,
    'subtotal_8'     => $subtotal8,
    'tax_8'          => $tax8,
    'payment_method' => $paymentMethod,
    'gateway_name'   => $gatewayName,
    'paid_at'        => date('Y-m-d H:i:s'),
]);
