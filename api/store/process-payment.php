<?php
/**
 * POSレジ会計処理 API
 *
 * POST /api/store/process-payment.php
 * Body: {
 *   store_id, table_id?, order_ids?,
 *   payment_method (cash|card|qr),
 *   received_amount? (現金時の預かり金),
 *   selected_items? [{name, qty, price, taxRate?}] (個別会計),
 *   total_override? (個別会計時の合計)
 * }
 *
 * 全額会計: 注文を paid に更新、セッション終了
 * 個別会計: payments に記録のみ（注文は open のまま）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/inventory-sync.php';
require_once __DIR__ . '/../lib/payment-gateway.php';
require_once __DIR__ . '/../lib/stripe-connect.php';
require_once __DIR__ . '/../lib/audit-log.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/register-close-lock.php';

function normalize_register_payment_detail($paymentMethod, $paymentDetail)
{
    $paymentDetail = trim((string)$paymentDetail);
    $allowed = [
        'cash' => ['', 'cash'],
        'card' => ['card_credit', 'card_debit', 'card_other'],
        'qr' => [
            'qr_paypay', 'qr_rakuten_pay', 'qr_dbarai', 'qr_au_pay',
            'qr_merpay', 'qr_line_pay', 'qr_alipay', 'qr_wechat_pay',
            'qr_other', 'emoney_transport_ic', 'emoney_id', 'emoney_quicpay',
            'emoney_other',
        ],
    ];

    if (!isset($allowed[$paymentMethod])) {
        json_error('VALIDATION', '不正な payment_method です', 400);
    }
    if ($paymentDetail === '') {
        if ($paymentMethod === 'card') return 'card_other';
        if ($paymentMethod === 'qr') return 'qr_other';
        return null;
    }
    if (!in_array($paymentDetail, $allowed[$paymentMethod], true)) {
        json_error('VALIDATION', '支払い方法の詳細が不正です', 400);
    }
    if ($paymentMethod === 'cash') {
        return null;
    }
    return $paymentDetail;
}

function process_payment_supports_table_session_status(PDO $pdo, string $status): bool
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

function process_payment_session_close_status(PDO $pdo): string
{
    return process_payment_supports_table_session_status($pdo, 'cleaning') ? 'cleaning' : 'paid';
}

require_method(['POST']);
$user = require_auth();

$data = get_json_body();
$storeId        = $data['store_id'] ?? null;
$tableId        = $data['table_id'] ?? null;
$orderIds       = $data['order_ids'] ?? [];
$paymentMethod  = $data['payment_method'] ?? 'cash';
$receivedAmount = isset($data['received_amount']) ? (int)$data['received_amount'] : null;
$selectedItems  = $data['selected_items'] ?? null;   // 個別会計
$totalOverride  = isset($data['total_override']) ? (int)$data['total_override'] : null;
$mergedTableIds  = $data['merged_table_ids'] ?? null;   // 合流テーブルID配列
$selectedItemIds = $data['selected_item_ids'] ?? null;   // 分割: order_item.id 配列
$staffPin        = isset($data['staff_pin']) ? trim((string)$data['staff_pin']) : '';   // CP1: 担当スタッフ PIN
$paymentEntryMode = isset($data['payment_entry_mode']) ? trim((string)$data['payment_entry_mode']) : '';
$paymentDetail    = isset($data['payment_method_detail']) ? trim((string)$data['payment_method_detail']) : '';
$externalPaymentNote = isset($data['external_payment_note']) ? trim((string)$data['external_payment_note']) : '';
if (function_exists('mb_substr')) {
    $externalPaymentNote = mb_substr($externalPaymentNote, 0, 120);
} else {
    $externalPaymentNote = substr($externalPaymentNote, 0, 120);
}

if (!$storeId) json_error('VALIDATION', 'store_id は必須です', 400);
if (!$tableId && empty($orderIds)) json_error('VALIDATION', 'table_id または order_ids が必要です', 400);
if (!in_array($paymentMethod, ['cash', 'card', 'qr'], true)) {
    json_error('VALIDATION', '不正な payment_method です', 400);
}
$paymentDetail = normalize_register_payment_detail($paymentMethod, $paymentDetail);
if ($paymentEntryMode !== '' && !in_array($paymentEntryMode, ['manual', 'gateway'], true)) {
    json_error('VALIDATION', '不正な payment_entry_mode です', 400);
}
if ($paymentEntryMode === 'gateway') {
    json_error('PAYMENT_NOT_AVAILABLE', '通常レジではPOSLA決済を実行できません。店舗の決済端末またはQR決済で完了後、POSLAには記録のみ残してください。', 400);
}
require_store_access($storeId);

$pdo = get_db();
$sessionCloseStatus = process_payment_session_close_status($pdo);
$postCloseLock = require_register_close_override($pdo, $storeId, $data, '会計記録');

// ── CP1 + S2: 担当スタッフ PIN 検証 (必須化) ──
// 全ての会計操作で PIN 必須。出勤中スタッフのみ照合可能。
// device 端末・個人アカウント問わず、必ず「誰が会計したか」を確定する。
$cashierUserId   = $user['user_id'];
$cashierUserName = null;
$pinVerified     = false;

if ($staffPin === '') {
    json_error('PIN_REQUIRED', '担当スタッフ PIN の入力が必要です', 400);
}
if (!preg_match('/^\d{4,8}$/', $staffPin)) {
    json_error('INVALID_PIN', '担当 PIN は 4〜8 桁の数字で入力してください', 400);
}
$cashierPinRateKey = 'cashier-pin:' . $storeId . ':' . ($user['user_id'] ?? 'unknown');
if (rate_limit_exceeded($cashierPinRateKey, 5, 600)) {
    json_error('PIN_RATE_LIMITED', 'PIN の試行回数が上限に達しました。10分後に再度お試しください。', 429);
}
// 出勤中スタッフの PIN ハッシュを取得
try {
    $today = date('Y-m-d');
    $pinStmt = $pdo->prepare(
        "SELECT u.id, u.display_name, u.cashier_pin_hash
         FROM users u
         INNER JOIN attendance_logs ar
           ON ar.user_id = u.id AND ar.store_id = ?
              AND DATE(ar.clock_in) = ? AND ar.clock_out IS NULL
         WHERE u.tenant_id = ? AND u.is_active = 1
               AND u.role IN ('staff', 'manager', 'owner')
               AND u.cashier_pin_hash IS NOT NULL"
    );
    $pinStmt->execute([$storeId, $today, $user['tenant_id']]);
    $candidates = $pinStmt->fetchAll();
} catch (PDOException $e) {
    json_error('MIGRATION', 'PIN 機能のマイグレーションが未適用です (migration-cp1-cashier-pin.sql / migration-l3-shift-management.sql)', 500);
}
foreach ($candidates as $cand) {
    if ($cand['cashier_pin_hash'] && password_verify($staffPin, $cand['cashier_pin_hash'])) {
        $cashierUserId   = $cand['id'];
        $cashierUserName = $cand['display_name'];
        $pinVerified     = true;
        break;
    }
}
if (!$pinVerified) {
    check_rate_limit($cashierPinRateKey, 5, 600);
    if (function_exists('write_audit_log')) {
        $auditUser = ['user_id' => $user['user_id'], 'tenant_id' => $user['tenant_id'], 'username' => $user['username'] ?? null, 'role' => $user['role'] ?? null];
        @write_audit_log($pdo, $auditUser, $storeId, 'cashier_pin_failed', 'payment', null, null, ['pin_length' => strlen($staffPin)], null);
    }
    json_error('PIN_INVALID', 'PIN が一致しないか、対象スタッフが出勤中ではありません', 401);
}

// payments テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM payments LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'payments テーブルが未作成です。migration-c4-payments.sql を実行してください。', 500);
}

// ── 注文取得 ──
// S3 #3: 未払い注文の取得は外部決済の前段。FOR UPDATE は paid 化直前の再取得で行う
//        (外部 API 呼出中は行ロックを保持しない方針)
$orders = [];
if (is_array($mergedTableIds) && count($mergedTableIds) > 0) {
    // 合流会計: 複数テーブルの注文をまとめて取得
    $allTableIds = $mergedTableIds;
    $ph = implode(',', array_fill(0, count($allTableIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, total_amount, table_id, items FROM orders
         WHERE store_id = ? AND table_id IN (' . $ph . ') AND status NOT IN ("paid", "cancelled")'
    );
    $stmt->execute(array_merge([$storeId], $allTableIds));
    $orders = $stmt->fetchAll();
    if (!$tableId) $tableId = $allTableIds[0];
    // order_ids を上書き（合流時はフロントから送られた order_ids を使うが、安全のため再構築）
    $orderIds = array_column($orders, 'id');
} elseif (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, total_amount, table_id, items FROM orders
         WHERE store_id = ? AND id IN (' . $placeholders . ') AND status NOT IN ("paid", "cancelled")'
    );
    $stmt->execute(array_merge([$storeId], $orderIds));
    $orders = $stmt->fetchAll();
    if (!$tableId && !empty($orders) && $orders[0]['table_id']) {
        $tableId = $orders[0]['table_id'];
    }
} elseif ($tableId) {
    $stmt = $pdo->prepare(
        'SELECT id, total_amount, items FROM orders
         WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "cancelled")'
    );
    $stmt->execute([$storeId, $tableId]);
    $orders = $stmt->fetchAll();
}

// ── セッション確認 ──
$sessionInfo = null;
$planInfo = null;
$mergedSessionIds = [];
if ($tableId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT ts.id AS session_id, ts.guest_count, ts.plan_id,
                    tlp.price AS plan_price, tlp.name AS plan_name
             FROM table_sessions ts
             LEFT JOIN time_limit_plans tlp ON tlp.id = ts.plan_id
             WHERE ts.store_id = ? AND ts.table_id = ? AND ts.status NOT IN ("paid", "closed")'
        );
        $stmt->execute([$storeId, $tableId]);
        $sessionInfo = $stmt->fetch();
        if ($sessionInfo && $sessionInfo['plan_id']) {
            $planInfo = $sessionInfo;
        }

        // 合流会計時: 全テーブルのセッションIDを収集
        if (is_array($mergedTableIds) && count($mergedTableIds) > 0) {
            $ph = implode(',', array_fill(0, count($mergedTableIds), '?'));
            $msStmt = $pdo->prepare(
                'SELECT id FROM table_sessions
                 WHERE store_id = ? AND table_id IN (' . $ph . ') AND status NOT IN ("paid", "closed")'
            );
            $msStmt->execute(array_merge([$storeId], $mergedTableIds));
            $mergedSessionIds = array_column($msStmt->fetchAll(), 'id');
        }
    } catch (PDOException $e) {
        // table_sessions/time_limit_plans 未作成時はスキップ
        error_log('[P1-12][api/store/process-payment.php:118] fetch_session_plan: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }
}

if (empty($orders) && !$sessionInfo) {
    json_error('NOT_FOUND', '未会計の注文またはアクティブなセッションがありません', 404);
}

// ── 個別会計 or 全額会計の判定 ──
$isPartial = is_array($selectedItems) && count($selectedItems) > 0;
$orderIdList = array_column($orders, 'id');

// ── 税額計算 ──
$subtotal10 = 0;
$tax10 = 0;
$subtotal8 = 0;
$tax8 = 0;
$totalAmount = 0;

if ($isPartial) {
    // ── S2 P0 #3: 個別会計の価格はサーバー側で order_items から再計算 ──
    // クライアントが送る $selectedItems の price は信用しない。
    // $selectedItemIds (order_items.id) を canonical source として、
    // store_id 境界つきで実価格を取得する。
    if (!is_array($selectedItemIds) || count($selectedItemIds) === 0) {
        json_error('VALIDATION', '個別会計には selected_item_ids が必要です', 400);
    }
    if (empty($orderIdList)) {
        json_error('VALIDATION', '対象注文がありません', 400);
    }
    $idsPh   = implode(',', array_fill(0, count($selectedItemIds), '?'));
    $ordPh   = implode(',', array_fill(0, count($orderIdList), '?'));
    // store_id は order_items テーブルにも存在するので二重チェック
    // INV-P1-5 hotfix: partial でも inventory_consumption_log に per-(order, ingredient)
    // で記録するため oi.order_id を SELECT に追加
    $itemStmt = $pdo->prepare(
        "SELECT oi.id, oi.order_id, oi.menu_item_id, oi.name, oi.price, oi.qty, oi.payment_id,
                o.order_type
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE oi.id IN ($idsPh)
           AND oi.order_id IN ($ordPh)
           AND oi.store_id = ?
           AND o.store_id = ?"
    );
    $itemStmt->execute(array_merge($selectedItemIds, $orderIdList, [$storeId, $storeId]));
    $rows = $itemStmt->fetchAll();
    if (count($rows) !== count($selectedItemIds)) {
        json_error('INVALID_ITEM', '指定された品目が現在の注文に存在しません', 400);
    }
    foreach ($rows as $row) {
        if (!empty($row['payment_id'])) {
            json_error('ALREADY_PAID', '指定された品目には既に支払済みのものが含まれています', 409);
        }
    }
    // 再計算
    $rebuiltSelected = [];
    foreach ($rows as $row) {
        $qty       = (int)$row['qty'];
        $price     = (int)$row['price'];
        $lineTotal = $price * $qty;
        // tax_rate は order_items に保存されないので order_type から推定
        $taxRate   = ($row['order_type'] === 'takeout') ? 8 : 10;

        if ($taxRate === 8) {
            $sub       = (int)floor($lineTotal / 1.08);
            $subtotal8 += $sub;
            $tax8      += $lineTotal - $sub;
        } else {
            $sub        = (int)floor($lineTotal / 1.10);
            $subtotal10 += $sub;
            $tax10      += $lineTotal - $sub;
        }
        $totalAmount += $lineTotal;
        $rebuiltSelected[] = [
            'id'           => $row['id'],
            'order_id'     => $row['order_id'],       // INV-P1-5 hotfix: partial の consumption_log 用
            'menu_item_id' => $row['menu_item_id'],   // S3 #5: 在庫減算で recipes.menu_template_id と突合するため必須
            'name'         => $row['name'],
            'price'        => $price,
            'qty'          => $qty,
            'taxRate'      => $taxRate,
        ];
    }
    // クライアントの selectedItems を canonical な再構築版で上書き
    // (DB INSERT / レシピ引落で参照される $selectedItems もこれに統一)
    $selectedItems = $rebuiltSelected;
    // total_override は P0 #3 の修正で完全に無視する (改ざん防止)
} else {
    // 全額会計
    $orderTotal = 0;
    foreach ($orders as $order) {
        $orderTotal += (int)$order['total_amount'];

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
    $totalAmount = $orderTotal;

    // プランがあればプラン料金×人数
    if ($planInfo && $planInfo['plan_price']) {
        $totalAmount = (int)$planInfo['plan_price'] * max(1, (int)$planInfo['guest_count']);
        $subtotal10 = (int)floor($totalAmount / 1.10);
        $tax10 = $totalAmount - $subtotal10;
        $subtotal8 = 0;
        $tax8 = 0;
    }
}

// 現金: お釣り計算
$changeAmount = null;
if ($paymentMethod === 'cash') {
    if ($receivedAmount === null) {
        json_error('VALIDATION', '現金決済では received_amount が必要です', 400);
    }
    if ($receivedAmount < $totalAmount) {
        json_error('VALIDATION', '預かり金が不足しています', 400);
    }
    $changeAmount = $receivedAmount - $totalAmount;
}

// ── 外部決済記録 ──
// 通常レジは店舗既存の現金 / カード端末 / QR 決済を受け入れ、POSLA では会計事実だけを記録する。
// POSLA 経由の Stripe 決済はセルフ会計・テイクアウト側に限定し、この API では実行しない。
$gatewayName = null;
$externalPaymentId = null;
$gatewayStatus = null;
if ($paymentMethod !== 'cash') {
    $paymentEntryMode = 'manual';
}

// ── トランザクション ──
$paymentId = generate_uuid();
$sessionId = $sessionInfo ? $sessionInfo['session_id'] : null;

// merged カラム存在チェック
$hasMergedCols = false;
try {
    $pdo->query('SELECT merged_table_ids FROM payments LIMIT 0');
    $hasMergedCols = true;
} catch (PDOException $e) {}

// gateway カラム存在チェック
$hasGatewayCols = false;
try {
    $pdo->query('SELECT gateway_name FROM payments LIMIT 0');
    $hasGatewayCols = true;
} catch (PDOException $e) {}

// 通常レジの支払い詳細カラム存在チェック (P1-46)
$hasPaymentDetailCol = false;
try {
    $pdo->query('SELECT payment_method_detail FROM payments LIMIT 0');
    $hasPaymentDetailCol = true;
} catch (PDOException $e) {}

$hasPaymentExternalNoteCol = false;
try {
    $pdo->query('SELECT external_payment_note FROM payments LIMIT 0');
    $hasPaymentExternalNoteCol = true;
} catch (PDOException $e) {}

$hasOrderPaymentDetailCol = false;
try {
    $pdo->query('SELECT payment_method_detail FROM orders LIMIT 0');
    $hasOrderPaymentDetailCol = true;
} catch (PDOException $e) {}

$hasOrderExternalNoteCol = false;
try {
    $pdo->query('SELECT external_payment_note FROM orders LIMIT 0');
    $hasOrderExternalNoteCol = true;
} catch (PDOException $e) {}

$hasPaymentNoteCol = false;
try {
    $pdo->query('SELECT note FROM payments LIMIT 0');
    $hasPaymentNoteCol = true;
} catch (PDOException $e) {}

// payment_id カラム存在チェック (order_items)
$hasOiPaymentId = false;
try {
    $pdo->query('SELECT payment_id FROM order_items LIMIT 0');
    $hasOiPaymentId = true;
} catch (PDOException $e) {}

$pdo->beginTransaction();
try {
    // ── S3 #3: 注文の二重 paid 化防止 ──
    // 全額会計時は対象 order を FOR UPDATE で再ロック → status 再確認 →
    // UPDATE 後に affected_rows == 件数一致 を検証する。
    // (個別会計は order_items.payment_id の WHERE payment_id IS NULL で同等の効果)
    if (!$isPartial && !empty($orderIdList)) {
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
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $paymentId, $storeId, $tableId,
            (is_array($mergedTableIds) && count($mergedTableIds) > 0) ? json_encode($mergedTableIds) : null,
            (!empty($mergedSessionIds)) ? json_encode($mergedSessionIds) : null,
            $sessionId,
            json_encode($orderIdList),
            $isPartial ? json_encode($selectedItems) : null,
            $subtotal10, $tax10, $subtotal8, $tax8,
            $totalAmount, $paymentMethod, $receivedAmount, $changeAmount,
            $isPartial ? 1 : 0,
            $cashierUserId   // CP1: PIN 検証されたスタッフ ID (PIN なしならログイン中ユーザー)
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, session_id, order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8, total_amount, payment_method, received_amount, change_amount, is_partial, user_id, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $paymentId, $storeId, $tableId, $sessionId,
            json_encode($orderIdList),
            $isPartial ? json_encode($selectedItems) : null,
            $subtotal10, $tax10, $subtotal8, $tax8,
            $totalAmount, $paymentMethod, $receivedAmount, $changeAmount,
            $isPartial ? 1 : 0,
            $cashierUserId   // CP1: PIN 検証されたスタッフ ID (PIN なしならログイン中ユーザー)
        ]);
    }

    // gateway情報をUPDATEで後付け（INSERTの分岐を増やさないため）
    if ($hasGatewayCols && $gatewayName) {
        $pdo->prepare(
            'UPDATE payments SET gateway_name = ?, external_payment_id = ?, gateway_status = ? WHERE id = ?'
        )->execute([$gatewayName, $externalPaymentId, $gatewayStatus, $paymentId]);
    }
    if ($hasPaymentDetailCol) {
        $pdo->prepare(
            'UPDATE payments SET payment_method_detail = ? WHERE id = ? AND store_id = ?'
        )->execute([$paymentDetail, $paymentId, $storeId]);
    }
    if ($hasPaymentExternalNoteCol) {
        $pdo->prepare(
            'UPDATE payments SET external_payment_note = ? WHERE id = ? AND store_id = ?'
        )->execute([$externalPaymentNote !== '' ? $externalPaymentNote : null, $paymentId, $storeId]);
    }
    if ($hasPaymentNoteCol && !empty($postCloseLock['locked'])) {
        $postCloseNote = '締め後会計: ' . ($postCloseLock['reason'] ?? '');
        $pdo->prepare(
            'UPDATE payments SET note = ? WHERE id = ? AND store_id = ?'
        )->execute([register_close_lock_trim($postCloseNote, 255), $paymentId, $storeId]);
    }

    // 分割会計時: 対象 order_items の payment_id を記録 (P1 #8: store_id 境界)
    // INV-P1-5 hotfix: rowCount 一致確認を追加。競合で一部が既に payment_id 付きだった場合、
    // 上流 (行 217-224) の ALREADY_PAID チェックとの間で race が起きる可能性があるため、
    // UPDATE の rowCount が selectedItemIds 数と一致しなければ CONFLICT で ROLLBACK する。
    // (非 partial 側 行 516 の order_update_rowcount_mismatch と対称)
    if ($isPartial && $hasOiPaymentId && is_array($selectedItemIds) && count($selectedItemIds) > 0) {
        $ph = implode(',', array_fill(0, count($selectedItemIds), '?'));
        $oiUpdStmt = $pdo->prepare(
            'UPDATE order_items SET payment_id = ?
             WHERE id IN (' . $ph . ')
               AND store_id = ?
               AND payment_id IS NULL'
        );
        $oiUpdStmt->execute(array_merge([$paymentId], $selectedItemIds, [$storeId]));
        if ($oiUpdStmt->rowCount() !== count($selectedItemIds)) {
            throw new RuntimeException('CONFLICT: order_items_payment_update_rowcount_mismatch expected=' . count($selectedItemIds) . ' actual=' . $oiUpdStmt->rowCount());
        }
    }

    // 全額会計の場合のみ、注文を paid に更新 + セッション終了
    if (!$isPartial) {
        if (!empty($orderIdList)) {
            $placeholders = implode(',', array_fill(0, count($orderIdList), '?'));
            // S3 #3 + P1 #8: store_id 境界 + 既 paid/cancelled の order は除外し、
            // 影響件数が一致しなければ二重決済とみなして CONFLICT で ROLLBACK
            $updOrders = $pdo->prepare(
                'UPDATE orders SET status = ?, payment_method = ?, received_amount = ?, change_amount = ?,
                        paid_at = NOW(), updated_at = NOW()
                 WHERE id IN (' . $placeholders . ')
                   AND store_id = ?
                   AND status NOT IN ("paid", "cancelled")'
            );
            $updOrders->execute(array_merge(['paid', $paymentMethod, $receivedAmount, $changeAmount], $orderIdList, [$storeId]));
            if ($updOrders->rowCount() !== count($orderIdList)) {
                throw new RuntimeException('CONFLICT: order_update_rowcount_mismatch expected=' . count($orderIdList) . ' actual=' . $updOrders->rowCount());
            }
            if ($hasOrderPaymentDetailCol) {
                $detailOrders = $pdo->prepare(
                    'UPDATE orders SET payment_method_detail = ?
                     WHERE id IN (' . $placeholders . ')
                       AND store_id = ?'
                );
                $detailOrders->execute(array_merge([$paymentDetail], $orderIdList, [$storeId]));
            }
            if ($hasOrderExternalNoteCol) {
                $externalNoteOrders = $pdo->prepare(
                    'UPDATE orders SET external_payment_note = ?
                     WHERE id IN (' . $placeholders . ')
                       AND store_id = ?'
                );
                $externalNoteOrders->execute(array_merge([$externalPaymentNote !== '' ? $externalPaymentNote : null], $orderIdList, [$storeId]));
            }
        }

        // 合流会計: 全テーブルのセッションクローズ + トークン再生成
        if (is_array($mergedTableIds) && count($mergedTableIds) > 0) {
            foreach ($mergedTableIds as $mTableId) {
                $newToken = bin2hex(random_bytes(16));
                // P1 #8: tables.id だけでなく store_id でも絞る
                $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ? AND store_id = ?')
                    ->execute([$newToken, $mTableId, $storeId]);
                try {
                    $pdo->prepare(
                        'UPDATE table_sessions
                            SET status = ?,
                                closed_at = CASE WHEN ? = "paid" THEN NOW() ELSE closed_at END
                         WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
                    )->execute([$sessionCloseStatus, $sessionCloseStatus, $storeId, $mTableId]);
                } catch (PDOException $e) {
                    error_log('[P1-12][api/store/process-payment.php:345] merged_payment_close: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
                }
            }
        } elseif ($tableId) {
            $newToken = bin2hex(random_bytes(16));
            // P1 #8: store_id 境界
            $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ? AND store_id = ?')->execute([$newToken, $tableId, $storeId]);

            try {
                $pdo->prepare(
                    'UPDATE table_sessions
                        SET status = ?,
                            closed_at = CASE WHEN ? = "paid" THEN NOW() ELSE closed_at END
                     WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
                )->execute([$sessionCloseStatus, $sessionCloseStatus, $storeId, $tableId]);
            } catch (PDOException $e) {
                // table_sessions 未作成時はスキップ
                error_log('[P1-12][api/store/process-payment.php:356] single_payment_close: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
            }
        }
    }

    // 分割会計の自動クローズ判定: 全品目に payment_id が付いたらクローズ
    if ($isPartial && $hasOiPaymentId && $tableId) {
        $unpaidStmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.table_id = ? AND o.store_id = ? AND o.status NOT IN ("paid","cancelled")
             AND oi.status != "cancelled" AND oi.payment_id IS NULL'
        );
        $unpaidStmt->execute([$tableId, $storeId]);
        $unpaid = $unpaidStmt->fetch();
        if ((int)$unpaid['cnt'] === 0) {
            // 全品支払済み → 自動クローズ
            $closeOrderStmt = $pdo->prepare(
                'UPDATE orders SET status = "paid", payment_method = ?, paid_at = NOW(), updated_at = NOW()
                 WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid","cancelled")'
            );
            $closeOrderStmt->execute([$paymentMethod, $storeId, $tableId]);

            $newToken = bin2hex(random_bytes(16));
            // P1 #8: store_id 境界
            $pdo->prepare('UPDATE tables SET session_token = ?, session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE id = ? AND store_id = ?')
                ->execute([$newToken, $tableId, $storeId]);
            try {
                $pdo->prepare(
                    'UPDATE table_sessions
                        SET status = ?,
                            closed_at = CASE WHEN ? = "paid" THEN NOW() ELSE closed_at END
                     WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")'
                )->execute([$sessionCloseStatus, $sessionCloseStatus, $storeId, $tableId]);
            } catch (PDOException $e) {
                error_log('[P1-12][api/store/process-payment.php:388] split_payment_close: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
            }
        }
    }

    // cash_log に売上記録
    if ($paymentMethod === 'cash' && $totalAmount > 0) {
        $logId = generate_uuid();
        $note = $isPartial ? 'POSレジ個別会計' : 'POSレジ会計';
        $pdo->prepare(
            'INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$logId, $storeId, $cashierUserId, 'cash_sale', $totalAmount, $note]);
    }

    // ── レシピ連動在庫引き落とし ──
    // INV-P1-5: 冪等性マーカー (orders.stock_consumed_at) + 消費履歴ログ
    // (inventory_consumption_log) で二重消費防止と audit trail を担保
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

        // INV-P1-5: 非 partial のみ orders レベルで冪等性マーカーを打つ。
        //   partial 会計は order_items.payment_id IS NULL が上流ガードなので再入不可。
        //   全額会計は orders UPDATE rowcount 一致チェックが上流ガードだが、
        //   ここでも stock_consumed_at IS NULL を atomic claim することで
        //   在庫引き落としだけが二重実行される事故を明示的に防ぐ。
        $consumeOrderIds = [];
        if ($hasStockConsumedCol && !$isPartial && !empty($orderIdList)) {
            $markStmt = $pdo->prepare(
                'UPDATE orders SET stock_consumed_at = NOW()
                 WHERE id = ? AND store_id = ? AND stock_consumed_at IS NULL'
            );
            foreach ($orderIdList as $oid) {
                $markStmt->execute([$oid, $storeId]);
                if ($markStmt->rowCount() === 1) $consumeOrderIds[$oid] = true;
            }
        } else {
            // partial or migration 未適用: 既存通り全 orders を対象
            foreach (($orders ?? []) as $o) {
                if (isset($o['id'])) $consumeOrderIds[$o['id']] = true;
            }
        }

        // 対象品目の集計: {menu_item_id => qty}
        // S3 #5: 部分会計時は order_items.id ではなく menu_item_id (= menu_templates.id)
        // を使う必要がある (recipes.menu_template_id と突合させるため)。
        // 旧コードは order_items.id を渡していたため recipes が一切ヒットせず、
        // 在庫が引き落とされない致命バグだった。
        // INV-P1-5: per-order-per-menu_item で記録も残すため二層 map
        $itemQtyMap = [];
        $perOrderQtyMap = []; // { order_id => { menu_item_id => qty } }
        if ($isPartial && is_array($selectedItems)) {
            foreach ($selectedItems as $si) {
                $menuItemId = $si['menu_item_id'] ?? null;
                if (!$menuItemId) continue;
                $qty = (int)($si['qty'] ?? 1);
                $itemQtyMap[$menuItemId] = ($itemQtyMap[$menuItemId] ?? 0) + $qty;
                // INV-P1-5 hotfix: partial でも per-(order, menu_item) で consumption_log に残す。
                // $rebuiltSelected で order_id を保持しているのでそのまま利用。
                $siOrderId = $si['order_id'] ?? null;
                if ($siOrderId) {
                    if (!isset($perOrderQtyMap[$siOrderId])) $perOrderQtyMap[$siOrderId] = [];
                    $perOrderQtyMap[$siOrderId][$menuItemId] = ($perOrderQtyMap[$siOrderId][$menuItemId] ?? 0) + $qty;
                }
            }
        } else {
            foreach ($orders as $order) {
                $oid = $order['id'] ?? null;
                // INV-P1-5: マーカーが取れた orders のみ消費対象にする (非 partial の二重消費防止)
                if ($hasStockConsumedCol && !$isPartial && $oid && empty($consumeOrderIds[$oid])) {
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
        }

        if (!empty($itemQtyMap)) {
            $menuIds = array_keys($itemQtyMap);
            $ph = implode(',', array_fill(0, count($menuIds), '?'));
            // P1 #8: tenant_id 境界 — menu_templates JOIN で同テナントのレシピのみ取得
            $recipeStmt = $pdo->prepare(
                "SELECT r.menu_template_id, r.ingredient_id, r.quantity
                 FROM recipes r
                 INNER JOIN menu_templates mt ON mt.id = r.menu_template_id
                 INNER JOIN ingredients ing ON ing.id = r.ingredient_id
                 WHERE r.menu_template_id IN ($ph)
                   AND mt.tenant_id = ?
                   AND ing.tenant_id = ?"
            );
            $recipeStmt->execute(array_merge($menuIds, [$user['tenant_id'], $user['tenant_id']]));
            $recipeRows = $recipeStmt->fetchAll();

            $deductions = [];
            foreach ($recipeRows as $rr) {
                $menuId = $rr['menu_template_id'];
                $ingId = $rr['ingredient_id'];
                $recipeQty = (float)$rr['quantity'];
                $orderQty = $itemQtyMap[$menuId] ?? 0;
                $deductions[$ingId] = ($deductions[$ingId] ?? 0) + ($recipeQty * $orderQty);
            }

            // P1 #8: tenant_id 境界 — 同テナントの原材料のみ更新
            $updateStmt = $pdo->prepare(
                'UPDATE ingredients SET stock_quantity = stock_quantity - ? WHERE id = ? AND tenant_id = ?'
            );
            foreach ($deductions as $ingId => $amount) {
                $updateStmt->execute([$amount, $ingId, $user['tenant_id']]);
            }

            // INV-P1-5: 消費履歴ログ (per-(order, ingredient) に分解)
            if ($hasConsumeLog && !empty($perOrderQtyMap)) {
                $logStmt = $pdo->prepare(
                    'INSERT INTO inventory_consumption_log
                     (id, tenant_id, store_id, order_id, ingredient_id, quantity, consumed_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                // recipe rows をローカルに (menu_id => [{ing_id, qty}, ...])
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
                                $user['tenant_id'],
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
        error_log('[P1-12][api/store/process-payment.php:455] recipe_deduct_stock_dine_in: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }

    // ── 在庫→品切れ自動同期（S-5） ──
    try {
        sync_sold_out_from_inventory($pdo, $user['tenant_id'], $storeId, false);
    } catch (Exception $e) {
        // 品切れ同期失敗は会計を阻害しない
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    // P3 #13: 内部エラーメッセージはログのみ、レスポンスは固定文言
    error_log('[process-payment] tx_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    // S3 #3: CONFLICT は 409 で返す (二重会計検知)
    if (strpos($e->getMessage(), 'CONFLICT') === 0) {
        json_error('CONFLICT', 'この注文は既に他のデバイスで会計処理されました。最新状態を再取得してください。', 409);
    }
    json_error('DB_ERROR', '会計処理に失敗しました。時間を置いて再試行してください。', 500);
}

// 会計成功を監査ログに記録（CP1b: PIN 有無に関わらず必ず記録）
if (function_exists('write_audit_log')) {
    $auditUser = ['user_id' => $user['user_id'], 'tenant_id' => $user['tenant_id'], 'username' => $user['username'] ?? null, 'role' => $user['role'] ?? null];
    @write_audit_log($pdo, $auditUser, $storeId, 'payment_complete', 'payment', $paymentId, null, [
        'cashier_user_id'   => $cashierUserId,
        'cashier_user_name' => $cashierUserName,
        'pin_verified'      => $pinVerified ? 1 : 0,
        'total'             => $totalAmount,
        'payment_method'    => $paymentMethod,
        'payment_method_detail' => $paymentDetail,
        'external_payment_note' => $externalPaymentNote,
        'payment_entry_mode'=> $paymentEntryMode ?: 'manual',
        'is_partial'        => $isPartial ? 1 : 0,
        'order_count'       => count($orders),
        'register_close_lock' => register_close_override_audit_payload($postCloseLock),
    ], null);
}

json_response([
    'payment_id'        => $paymentId,
    'totalAmount'       => $totalAmount,
    'subtotal10'        => $subtotal10,
    'tax10'             => $tax10,
    'subtotal8'         => $subtotal8,
    'tax8'              => $tax8,
    'changeAmount'      => $changeAmount,
    'orderCount'        => count($orders),
    'paymentMethod'     => $paymentMethod,
    'paymentMethodDetail' => $paymentDetail,
    'externalPaymentNote' => $externalPaymentNote,
    'cashierUserId'     => $cashierUserId,
    'cashierUserName'   => $cashierUserName,
    'isPartial'         => $isPartial,
    'paymentEntryMode'  => $paymentEntryMode ?: 'manual',
    'gatewayName'       => $gatewayName,
    'externalPaymentId' => $externalPaymentId,
    'postCloseOverride' => !empty($postCloseLock['locked']),
]);
