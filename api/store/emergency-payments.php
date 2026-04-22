<?php
/**
 * 緊急レジモード同期 API (PWA Phase 4 / 2026-04-20)
 *
 * POST /api/store/emergency-payments.php
 *   端末の IndexedDB に保存した緊急会計を POSLA 台帳 (emergency_payments) へ同期する。
 *   body: {
 *     payment: {
 *       localEmergencyPaymentId, storeId, deviceId?, staffName?, staffPin?, staffPinVerified?,
 *       tableId?, tableCode?, orderIds[]?, itemSnapshot[]?,
 *       subtotal, tax, totalAmount,
 *       paymentMethod = 'cash' | 'external_card' | 'external_qr' | 'other_external',
 *       receivedAmount?, changeAmount?,
 *       externalTerminalName?, externalSlipNo?, externalApprovalNo?,
 *       note?, clientCreatedAt?, appVersion?
 *     }
 *   }
 *
 *   Idempotent: UNIQUE (store_id, local_emergency_payment_id) により同一 localId 再送で
 *               二重登録されない。既存行があれば `idempotent: true` を付けて現状を返す。
 *
 *   セキュリティ:
 *     - require_auth + require_store_access
 *     - カード番号・有効期限・CVV に相当するフィールドは受け付けない (存在したら 400)
 *     - paymentMethod は allowlist
 *     - staffPin は本 API 内でのみ検証 (ハッシュ比較)、DB には平文保存しない
 *
 *   status 判定:
 *     - subtotal+tax と totalAmount の差異 > 1 → pending_review
 *     - orderIds に既存 payments.order_ids と重複があれば → conflict
 *     - 正常なら → synced (※ Phase 4 では「台帳に synced」まで。payments への自動反映は Phase 4b)
 *
 * GET /api/store/emergency-payments.php?store_id=X[&status=pending_review]
 *   manager/owner 限定。最近 200 件一覧を返す。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$method = require_method(['POST', 'GET']);
$user = require_auth();
$pdo = get_db();

// ===== GET: 管理者向け一覧 =====
if ($method === 'GET') {
    $storeId = isset($_GET['store_id']) ? (string)$_GET['store_id'] : '';
    if (!$storeId) json_error('VALIDATION', 'store_id が必要です', 400);
    require_store_access($storeId);
    if (!in_array($user['role'], ['manager', 'owner'], true)) {
        json_error('FORBIDDEN', '一覧閲覧は manager/owner のみ', 403);
    }

    $statusFilter = isset($_GET['status']) ? (string)$_GET['status'] : '';
    $allowedStatus = ['pending_sync', 'syncing', 'synced', 'conflict', 'pending_review', 'failed'];

    $sql = 'SELECT id, local_emergency_payment_id, table_id, table_code,
                   subtotal_amount, tax_amount, total_amount,
                   payment_method, received_amount, change_amount,
                   external_terminal_name, external_slip_no, external_approval_no,
                   status, conflict_reason, synced_payment_id,
                   staff_name, staff_pin_verified, note,
                   client_created_at, server_received_at
              FROM emergency_payments
             WHERE store_id = ? AND tenant_id = ?';
    $params = [$storeId, $user['tenant_id']];
    if ($statusFilter !== '' && in_array($statusFilter, $allowedStatus, true)) {
        $sql .= ' AND status = ?';
        $params[] = $statusFilter;
    }
    $sql .= ' ORDER BY server_received_at DESC LIMIT 200';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        json_error('DB_ERROR', 'emergency_payments テーブル未作成の可能性 (migration-pwa4-emergency-payments.sql)', 500);
    }
    json_response(['records' => $records]);
}

// ===== POST: 同期送信 =====
$body = get_json_body();
$payment = isset($body['payment']) && is_array($body['payment']) ? $body['payment'] : null;
if (!$payment) json_error('VALIDATION', 'payment が必要です', 400);

// --- セキュリティ: カード情報相当のフィールドを拒否 ---
// IndexedDB やクライアント側で万が一紛れ込んでも API で弾く
$forbiddenFields = [
    'card_number', 'cardNumber', 'pan',
    'card_expiry', 'cardExpiry', 'expiry', 'expiration',
    'cvv', 'cvc', 'security_code', 'securityCode',
    'cardholder', 'card_holder'
];
foreach ($forbiddenFields as $f) {
    if (array_key_exists($f, $payment)) {
        json_error('FORBIDDEN_FIELD', 'カード番号等の機密情報は受け付けません。外部端末で決済してください', 400);
    }
}

// --- 必須パラメータ ---
$storeId = isset($payment['storeId']) ? (string)$payment['storeId'] : '';
$localId = isset($payment['localEmergencyPaymentId']) ? (string)$payment['localEmergencyPaymentId'] : '';
$totalAmount = isset($payment['totalAmount']) ? (int)$payment['totalAmount'] : -1;
$paymentMethod = isset($payment['paymentMethod']) ? (string)$payment['paymentMethod'] : '';

if (!$storeId) json_error('VALIDATION', 'storeId 必須', 400);
if (!$localId || strlen($localId) > 80) json_error('VALIDATION', 'localEmergencyPaymentId 不正 (1-80 文字)', 400);
if ($totalAmount < 0) json_error('VALIDATION', 'totalAmount は 0 以上', 400);
$allowedMethods = ['cash', 'external_card', 'external_qr', 'other_external'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    json_error('VALIDATION', 'paymentMethod が不正です (cash / external_card / external_qr / other_external)', 400);
}

// Phase 4d-2: 外部分類 (other_external の場合のみ設定)
// 許可値: voucher / bank_transfer / accounts_receivable / point / other
// 不正値は silent に other に丸めず、400 VALIDATION で拒否する。
$allowedExternalMethodTypes = ['voucher', 'bank_transfer', 'accounts_receivable', 'point', 'other'];
$externalMethodType = null;
if ($paymentMethod === 'other_external') {
    if (isset($payment['externalMethodType']) && $payment['externalMethodType'] !== '' && $payment['externalMethodType'] !== null) {
        $cand = mb_substr((string)$payment['externalMethodType'], 0, 30);
        if (!in_array($cand, $allowedExternalMethodTypes, true)) {
            json_error('VALIDATION',
                '外部分類が不正です (voucher / bank_transfer / accounts_receivable / point / other)',
                400);
        }
        $externalMethodType = $cand;
    }
    // 未指定なら NULL のまま → 台帳で「その他外部（未分類）」表示
}
// paymentMethod !== 'other_external' のときは externalMethodType を silent drop (NULL 保存)

require_store_access($storeId);
$tenantId = $user['tenant_id'];

// --- 任意パラメータ (全て mb_substr で長さ制限) ---
$deviceId            = isset($payment['deviceId']) ? mb_substr((string)$payment['deviceId'], 0, 80) : null;
$staffName           = isset($payment['staffName']) ? mb_substr((string)$payment['staffName'], 0, 100) : null;
$tableId             = (isset($payment['tableId']) && $payment['tableId']) ? (string)$payment['tableId'] : null;
$tableCode           = isset($payment['tableCode']) ? mb_substr((string)$payment['tableCode'], 0, 40) : null;
$subtotal            = isset($payment['subtotal']) ? (int)$payment['subtotal'] : 0;
$tax                 = isset($payment['tax']) ? (int)$payment['tax'] : 0;
$receivedAmount      = (isset($payment['receivedAmount']) && $payment['receivedAmount'] !== null && $payment['receivedAmount'] !== '') ? (int)$payment['receivedAmount'] : null;
$changeAmount        = (isset($payment['changeAmount'])   && $payment['changeAmount']   !== null && $payment['changeAmount']   !== '') ? (int)$payment['changeAmount']   : null;
$externalTerminal    = isset($payment['externalTerminalName']) ? mb_substr((string)$payment['externalTerminalName'], 0, 100) : null;
$externalSlipNo      = isset($payment['externalSlipNo']) ? mb_substr((string)$payment['externalSlipNo'], 0, 100) : null;
$externalApproval    = isset($payment['externalApprovalNo']) ? mb_substr((string)$payment['externalApprovalNo'], 0, 100) : null;
$note                = isset($payment['note']) ? mb_substr((string)$payment['note'], 0, 255) : null;
$appVersion          = isset($payment['appVersion']) ? mb_substr((string)$payment['appVersion'], 0, 20) : null;

// clientCreatedAt: ISO 8601 → MySQL DATETIME に正規化
$clientCreatedAtSql = null;
if (isset($payment['clientCreatedAt']) && $payment['clientCreatedAt']) {
    $ts = strtotime((string)$payment['clientCreatedAt']);
    if ($ts) $clientCreatedAtSql = date('Y-m-d H:i:s', $ts);
}

// Phase 4 レビュー指摘 #4: 再同期で PIN が送られてこない場合に「PIN 入力はしたが検証前」を区別するフラグ。
// pinEnteredClient=true && staffPin なし → 再試行で PIN が失われたケース → pending_review に落とす
$pinEnteredClient = !empty($payment['pinEnteredClient']) || !empty($payment['staffPinVerified']);

// Phase 4d-1 (2026-04-20): DB 永続化用に NULL / 0 / 1 の 3 値を別途決定する。
//   - body に pinEnteredClient キーがあれば 0/1 (B' 以降の端末はこれに従う)
//   - body に staffPinVerified=true (旧互換) が来ていれば 1 とみなす
//   - どちらも欠けていれば NULL (旧クライアントのデータと区別)
// DEFAULT NULL を選ぶ理由: 既存行を後追いで「入力なし」と誤表示しないため。
$pinEnteredClientForDb = null;
if (array_key_exists('pinEnteredClient', $payment)) {
    $pinEnteredClientForDb = !empty($payment['pinEnteredClient']) ? 1 : 0;
}
if (!empty($payment['staffPinVerified'])) {
    $pinEnteredClientForDb = 1;
}

// orderIds 配列 (文字列のみ)
$orderIds = [];
if (isset($payment['orderIds']) && is_array($payment['orderIds'])) {
    foreach ($payment['orderIds'] as $oid) {
        if (is_string($oid) && strlen($oid) > 0 && strlen($oid) <= 64) $orderIds[] = $oid;
    }
}

// itemSnapshot 配列 (MEDIUMTEXT 相当だが JSON 型)
$itemSnapshot = [];
if (isset($payment['itemSnapshot']) && is_array($payment['itemSnapshot'])) {
    foreach ($payment['itemSnapshot'] as $it) {
        if (!is_array($it)) continue;
        $itemSnapshot[] = [
            'orderId'  => isset($it['orderId']) ? mb_substr((string)$it['orderId'], 0, 64) : null,
            'itemId'   => isset($it['itemId']) ? mb_substr((string)$it['itemId'], 0, 64) : null,
            'name'     => isset($it['name']) ? mb_substr((string)$it['name'], 0, 200) : null,
            'qty'      => isset($it['qty']) ? (int)$it['qty'] : 0,
            'price'    => isset($it['price']) ? (int)$it['price'] : 0,
            'taxRate'  => isset($it['taxRate']) ? (float)$it['taxRate'] : null,
            'status'   => isset($it['status']) ? mb_substr((string)$it['status'], 0, 20) : null,
        ];
    }
}

// Phase 4 レビュー指摘 (2回目) #1 / (3回目): 手入力モード判定。
// **必ず orderIds パース後に行う**。PHP の empty($undefined) は true になるため、パース前に判定すると
// tableId=null かつ orderIds あり (合流会計など) の正当な payload が manual 扱いに誤判定される。
// hasTableContext=false の場合、または tableId/orderIds 両方が空の場合は「会計根拠が端末側で特定できていない」ため、
// サーバー側の status 判定で必ず pending_review に落とす (管理者が手動で照合する必要あり)。
$hasTableContext = isset($payment['hasTableContext']) ? !!$payment['hasTableContext'] : null;
$isManualEntry = ($hasTableContext === false) || (!$tableId && empty($orderIds));

// --- 担当 PIN 検証 (任意。通っていれば staff_pin_verified=1) ---
$staffUserId = null;
$staffPinVerified = 0;
$staffPin = isset($payment['staffPin']) ? trim((string)$payment['staffPin']) : '';
if ($staffPin !== '' && preg_match('/^\d{4,8}$/', $staffPin)) {
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
        $pinStmt->execute([$storeId, $today, $tenantId]);
        $candidates = $pinStmt->fetchAll();
        foreach ($candidates as $c) {
            if ($c['cashier_pin_hash'] && password_verify($staffPin, $c['cashier_pin_hash'])) {
                $staffUserId = $c['id'];
                if (!$staffName) $staffName = $c['display_name'];
                $staffPinVerified = 1;
                break;
            }
        }
    } catch (PDOException $e) {
        // 出勤/PIN 検証に失敗しても緊急会計の記録自体は続行する (会計事実優先)
        $staffPinVerified = 0;
    }
}

// --- status 判定 + INSERT を単一トランザクションで実行 ---
// Phase 4 レビュー指摘 (2回目) #2: 別端末から同じ orderId を同時 POST された場合の race を防ぐ。
// orderIds がある場合は対象 orders を FOR UPDATE でロックし、status 判定 → INSERT を一連のトランザクション内で行う。
// orderIds が空 (手入力モード等) はロック対象がないので通常トランザクションなし。
//
// 優先度: conflict > pending_review > synced

$status = 'synced';
$conflictReason = null;
$inTx = false;

try {
    if (!empty($orderIds)) {
        $pdo->beginTransaction();
        $inTx = true;
        // FOR UPDATE: 別端末の同時 POST を待たせる
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $lockStmt = $pdo->prepare(
            'SELECT id FROM orders WHERE store_id = ? AND id IN (' . $placeholders . ') FOR UPDATE'
        );
        $lockStmt->execute(array_merge([$storeId], $orderIds));
        $foundOrderIds = $lockStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $missingOrderIds = array_diff($orderIds, $foundOrderIds);
        if (count($missingOrderIds) > 0) {
            $status = 'pending_review';
            $conflictReason = 'order_not_found_or_wrong_store: ' . mb_substr(implode(',', $missingOrderIds), 0, 150);
        }
    }

    // 1. 金額整合性
    if (abs(($subtotal + $tax) - $totalAmount) > 1) {
        if ($status === 'synced') {
            $status = 'pending_review';
            $conflictReason = 'amount_mismatch: subtotal+tax=' . ($subtotal + $tax) . ' total=' . $totalAmount;
        }
    }

    // 2. 手入力モード (hasTableContext=false または tableId/orderIds 空) → 必ず pending_review
    if ($status === 'synced' && $isManualEntry) {
        $status = 'pending_review';
        $conflictReason = 'manual_entry_without_context (tableId/orderIds 未指定。管理者が手動照合してください)';
    }

    // 3. tableId 存在確認
    if ($tableId) {
        $tStmt = $pdo->prepare('SELECT id FROM tables WHERE id = ? AND store_id = ? LIMIT 1');
        $tStmt->execute([$tableId, $storeId]);
        if (!$tStmt->fetch()) {
            if ($status === 'synced') $status = 'pending_review';
            $conflictReason = ($conflictReason ?: '') . ' table_not_found_or_wrong_store: ' . mb_substr($tableId, 0, 64);
            $conflictReason = ltrim($conflictReason);
        }
    }

    // 4. payments 既存重複 (トランザクション内で確認)
    if ($status !== 'conflict' && !empty($orderIds)) {
        foreach ($orderIds as $oid) {
            $pStmt = $pdo->prepare(
                "SELECT id FROM payments
                   WHERE store_id = ? AND JSON_CONTAINS(order_ids, JSON_QUOTE(?))
                   LIMIT 1"
            );
            $pStmt->execute([$storeId, $oid]);
            if ($pStmt->fetch()) {
                $status = 'conflict';
                $conflictReason = 'order_already_paid: ' . mb_substr($oid, 0, 64);
                break;
            }
        }
    }

    // 5. emergency_payments 内で別 localId が同じ orderId を既に保存していれば conflict
    //    (FOR UPDATE で orders を抑えているので、この SELECT は race-safe)
    if ($status !== 'conflict' && !empty($orderIds)) {
        foreach ($orderIds as $oid) {
            $eStmt = $pdo->prepare(
                "SELECT id, local_emergency_payment_id FROM emergency_payments
                   WHERE tenant_id = ? AND store_id = ?
                     AND local_emergency_payment_id <> ?
                     AND status IN ('synced', 'conflict', 'pending_review')
                     AND JSON_CONTAINS(order_ids_json, JSON_QUOTE(?))
                   LIMIT 1"
            );
            $eStmt->execute([$tenantId, $storeId, $localId, $oid]);
            $dupRow = $eStmt->fetch();
            if ($dupRow) {
                $status = 'conflict';
                $conflictReason = 'emergency_duplicate_order: order=' . mb_substr($oid, 0, 32)
                    . ' dup_local=' . mb_substr($dupRow['local_emergency_payment_id'], 0, 40);
                break;
            }
        }
    }

    // 6. PIN 未検証 (pinEnteredClient=true && staff_pin_verified=0) → pending_review
    if ($status === 'synced' && $pinEnteredClient && $staffPinVerified === 0) {
        $status = 'pending_review';
        $conflictReason = 'pin_entered_but_not_verified (初回同期失敗による再試行の可能性。管理者が担当者を確認してください)';
    }
} catch (PDOException $e) {
    // status 判定中のエラーは安全側で pending_review に倒す (ロールバックは UPSERT try-catch でまとめて処理)
    if ($status === 'synced') $status = 'pending_review';
    $conflictReason = ($conflictReason ?: '') . ' status_eval_failed: ' . mb_substr($e->getMessage(), 0, 120);
    $conflictReason = ltrim($conflictReason);
}

// --- Idempotent UPSERT ---
$orderIdsJson = json_encode($orderIds, JSON_UNESCAPED_UNICODE);
$itemSnapshotJson = json_encode($itemSnapshot, JSON_UNESCAPED_UNICODE);
if ($orderIdsJson === false) $orderIdsJson = '[]';
if ($itemSnapshotJson === false) $itemSnapshotJson = '[]';

try {
    // 既存 localId 検索 (マルチテナント境界も tenant_id で二重確認)
    $dup = $pdo->prepare(
        'SELECT id, status, conflict_reason, synced_payment_id
           FROM emergency_payments
          WHERE store_id = ? AND tenant_id = ? AND local_emergency_payment_id = ?
          LIMIT 1'
    );
    $dup->execute([$storeId, $tenantId, $localId]);
    $existing = $dup->fetch();

    if ($existing) {
        if ($inTx) $pdo->commit();   // ロック解放のみ。INSERT は不要
        json_response([
            'emergency_payment_id' => $existing['id'],
            'status'               => $existing['status'],
            'conflict_reason'      => $existing['conflict_reason'],
            'synced_payment_id'    => $existing['synced_payment_id'],
            'idempotent'           => true,
        ]);
    }

    $newId = generate_uuid();

    // Phase 4d-1: pin_entered_client カラム有無で INSERT 文を分岐 (migration 未適用環境でも 500 にしない)
    $hasPinEnteredClientCol = true;
    try { $pdo->query('SELECT pin_entered_client FROM emergency_payments LIMIT 0'); }
    catch (PDOException $e) { $hasPinEnteredClientCol = false; }

    // Phase 4d-2: external_method_type カラム有無も独立にチェック (4d-1 / 4d-2 の適用順に依存しない)
    $hasExternalMethodTypeCol = true;
    try { $pdo->query('SELECT external_method_type FROM emergency_payments LIMIT 0'); }
    catch (PDOException $e) { $hasExternalMethodTypeCol = false; }

    // external_method_type を INSERT 文に注入するためのフラグメントを事前計算。
    // payment_method の直後に追加する (DB 物理カラム順と合わせる)。
    $extMethodCol   = $hasExternalMethodTypeCol ? ', external_method_type' : '';
    $extMethodPh    = $hasExternalMethodTypeCol ? ', ?' : '';
    $extMethodParam = $hasExternalMethodTypeCol ? [$externalMethodType] : [];

    if ($hasPinEnteredClientCol) {
        $ins = $pdo->prepare(
            'INSERT INTO emergency_payments
               (id, tenant_id, store_id, local_emergency_payment_id, device_id,
                staff_user_id, staff_name, staff_pin_verified, pin_entered_client,
                table_id, table_code, order_ids_json, item_snapshot_json,
                subtotal_amount, tax_amount, total_amount,
                payment_method' . $extMethodCol . ', received_amount, change_amount,
                external_terminal_name, external_slip_no, external_approval_no, note,
                status, conflict_reason, app_version, client_created_at,
                server_received_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?,
                     ?' . $extMethodPh . ', ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     NOW(), NOW(), NOW())'
        );
        $ins->execute(array_merge(
            [$newId, $tenantId, $storeId, $localId, $deviceId,
             $staffUserId, $staffName, $staffPinVerified, $pinEnteredClientForDb,
             $tableId, $tableCode, $orderIdsJson, $itemSnapshotJson,
             $subtotal, $tax, $totalAmount,
             $paymentMethod],
            $extMethodParam,
            [$receivedAmount, $changeAmount,
             $externalTerminal, $externalSlipNo, $externalApproval, $note,
             $status, $conflictReason, $appVersion, $clientCreatedAtSql]
        ));
    } else {
        // 旧スキーマ fallback (migration-pwa4d 未適用)
        $ins = $pdo->prepare(
            'INSERT INTO emergency_payments
               (id, tenant_id, store_id, local_emergency_payment_id, device_id,
                staff_user_id, staff_name, staff_pin_verified,
                table_id, table_code, order_ids_json, item_snapshot_json,
                subtotal_amount, tax_amount, total_amount,
                payment_method' . $extMethodCol . ', received_amount, change_amount,
                external_terminal_name, external_slip_no, external_approval_no, note,
                status, conflict_reason, app_version, client_created_at,
                server_received_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?,
                     ?' . $extMethodPh . ', ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     NOW(), NOW(), NOW())'
        );
        $ins->execute(array_merge(
            [$newId, $tenantId, $storeId, $localId, $deviceId,
             $staffUserId, $staffName, $staffPinVerified,
             $tableId, $tableCode, $orderIdsJson, $itemSnapshotJson,
             $subtotal, $tax, $totalAmount,
             $paymentMethod],
            $extMethodParam,
            [$receivedAmount, $changeAmount,
             $externalTerminal, $externalSlipNo, $externalApproval, $note,
             $status, $conflictReason, $appVersion, $clientCreatedAtSql]
        ));
    }

    // Phase 4b-1: emergency_payment_orders への子 INSERT (order_id ごと)。
    //   目的: (store_id, order_id, status='active') UNIQUE 制約により DB レベルで二重登録を封じる。
    //
    // 挙動:
    //   - orderIds が空 (手入力モード) → 子 INSERT なしで commit
    //   - 親 status が既に 'conflict' (emergency_payments 重複検出で確定済み) → 子 INSERT は行わない
    //     (既に DUP が分かっているので子テーブルにも書き込まない方が整合する)
    //   - 子 INSERT 中に UNIQUE 違反 (ER_DUP_ENTRY=1062) → 親 status を 'conflict' に UPDATE して
    //     conflict_reason='child_unique_race: <order_id>' を記録。commit は通す
    //     (既に emergency_payments には台帳が残っており「会計事実を失わない」が最優先)
    //   - 子テーブル未作成 (42S02) → 親 status を 'pending_review' に UPDATE して
    //     conflict_reason='emergency_payment_orders_missing' を記録。migration 未適用で 500 にしない
    if (!empty($orderIds) && $status !== 'conflict') {
        $childIns = null;
        try {
            $childIns = $pdo->prepare(
                'INSERT INTO emergency_payment_orders
                   (id, tenant_id, store_id, emergency_payment_id, local_emergency_payment_id,
                    order_id, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            foreach ($orderIds as $oid) {
                $childId = generate_uuid();
                $childIns->execute([
                    $childId, $tenantId, $storeId, $newId, $localId,
                    $oid, 'active'
                ]);
            }
        } catch (PDOException $ec) {
            $sqlState = $ec->getCode();
            $driverCode = isset($ec->errorInfo[1]) ? (int)$ec->errorInfo[1] : 0;
            $failReason = null;
            $failStatus = null;
            if ($driverCode === 1062) {
                // UNIQUE 違反 (別 localId が既に同じ order_id を active で保持)
                $failStatus = 'conflict';
                $failReason = 'child_unique_race: ' . mb_substr($ec->getMessage(), 0, 150);
            } elseif ($sqlState === '42S02' || $driverCode === 1146) {
                // テーブル未作成 (migration 未適用)
                $failStatus = 'pending_review';
                $failReason = 'emergency_payment_orders_missing (migration-pwa4b-emergency-payment-orders.sql 未適用)';
            } else {
                // その他の DB エラーは安全側で pending_review
                $failStatus = 'pending_review';
                $failReason = 'child_insert_failed: ' . mb_substr($ec->getMessage(), 0, 150);
            }
            // 親 emergency_payments の status / conflict_reason を UPDATE
            $upd = $pdo->prepare(
                'UPDATE emergency_payments
                    SET status = ?, conflict_reason = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND store_id = ?'
            );
            $upd->execute([$failStatus, $failReason, $newId, $tenantId, $storeId]);
            $status = $failStatus;
            $conflictReason = $failReason;
        }
    }

    if ($inTx) $pdo->commit();

    json_response([
        'emergency_payment_id' => $newId,
        'status'               => $status,
        'conflict_reason'      => $conflictReason,
        'synced_payment_id'    => null,
        'idempotent'           => false,
    ]);
} catch (PDOException $e) {
    if ($inTx) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        $inTx = false;
    }
    // UNIQUE 制約違反 (並行送信で競合) → idempotent として既存を返す
    if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
        $dup = $pdo->prepare(
            'SELECT id, status, conflict_reason, synced_payment_id
               FROM emergency_payments
              WHERE store_id = ? AND tenant_id = ? AND local_emergency_payment_id = ?
              LIMIT 1'
        );
        $dup->execute([$storeId, $tenantId, $localId]);
        $row = $dup->fetch();
        if ($row) {
            json_response([
                'emergency_payment_id' => $row['id'],
                'status'               => $row['status'],
                'conflict_reason'      => $row['conflict_reason'],
                'synced_payment_id'    => $row['synced_payment_id'],
                'idempotent'           => true,
            ]);
        }
    }
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/emergency-payments.php] db_error: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    json_error('DB_ERROR', '緊急会計の保存に失敗しました', 500);
}
