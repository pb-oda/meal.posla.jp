<?php
/**
 * 通常会計 payments void API (PWA Phase 4d-5c-ba / 2026-04-21,
 *                             4d-5c-bb-A 2026-04-21, 4d-5c-bb-C 2026-04-21)
 *
 * POST /api/store/payment-void.php
 *
 * body: {
 *   store_id: "<uuid>",
 *   payment_id: "<uuid>",
 *   reason: "必須、1-255 文字"
 * }
 *
 * 目的:
 *   process-payment.php 経由で作成された通常会計 payments のうち、
 *     - payment_method ∈ {'cash','card','qr'}   (payments.payment_method ENUM 全値)
 *     - gateway_name IS NULL or ''              (ゲートウェイ通過なし = 手動入力含む)
 *     - is_partial = 0                          (分割会計でない)
 *     - 紐付き orders.order_type ∈ {'dine_in','handy','takeout'}   (4d-5c-bb-C で takeout 追加)
 *     - 紐付き orders.status = 'paid'           (会計済みのみ)
 *   の組合せを論理取消する。それ以外 (cash/card/qr + gateway / 分割 / takeout online /
 *   emergency_transfer / emergency_manual_transfer / pending・served・cancelled) は
 *   本 API では 409 で弾く。
 *   - 緊急会計系: 4d-5a (manual) / 4d-5b (transfer)
 *   - gateway 経由 / 分割 / takeout online (status != 'paid') / 返金チェーン: 別スライスで設計
 *
 * 対象条件 (三重ガード + 補正 + 4d-5c-ba/bb-A/bb-C hotfix):
 *   - payments.id 一致 + payments.store_id 一致 (テナント境界)
 *   - payments.order_ids が JSON で JSON_LENGTH > 0  (ガード1: 注文紐付き)
 *   - payments.note IS NULL or note に 'emergency_' で始まる接頭辞を含まない (ガード2)
 *   - payments.synced_payment_id IS NULL  (ガード3: 緊急会計転記由来でない)
 *   - payments.void_status = 'active'  (未 void)
 *   - payments.refund_status = 'none' or NULL  (返金済みは取消不可。別スライスで扱う)
 *   - 4d-5c-bb-A: payment_method は ENUM 制約に任せて追加ガードなし
 *   - hotfix: gateway_name IS NULL or ''
 *   - hotfix: is_partial != 1
 *   - 補正(2): order_ids 全件が orders に存在し、status='paid' かつ
 *              order_type IN ('dine_in','handy','takeout')。それ以外 (cancelled / pending /
 *              served / pending_payment) は本 API 範囲外として 409。
 *
 * 処理 (accounting void / operational reopen を分離):
 *   1. TX + FOR UPDATE で payments 対象行をロック
 *   2. 既に voided → idempotent:true で即返却
 *   3. 補正(2) の linked orders 検証
 *   4. UPDATE payments SET void_status='voided', voided_at, voided_by, void_reason
 *   5. payment_method='cash' の場合のみ cash_log に cash_out 相殺行 INSERT
 *      (note='通常会計取消 payment_void:<payment_id>')
 *   6. 監査ログ (function_exists で optional)
 *
 * 絶対にやらない (accounting void の責務から明示的に除外):
 *   - orders.status / orders.paid_at / orders.payment_method の変更
 *     → KDS 再表示・卓状態復活 (KDS, tables-status, process-payment 二重会計) の副作用回避
 *   - table_sessions / session_token の変更
 *   - refund_status の変更 → 返金は別経路 (refund-payment.php) で発火する
 *   - payments.payment_method ENUM 拡張
 *   - cash_log.type ENUM 拡張
 *   - 物理 DELETE
 *   - receipt.php / refund-payment.php / sales-report.php の呼び出し
 *
 * 売上集計への影響:
 *   sales-report / turnover-report / register-report は 4d-5c-a で
 *   void_status='voided' の payments に紐付く orders を集計から除外済み。
 *   takeout 注文のうち `status='paid'` + cash + no gateway + is_partial=0 のものは
 *   4d-5c-bb-C で取消可能となった (POS cashier で会計された takeout の誤計上修正用途)。
 *   online 決済 takeout (status != 'paid' かつ gateway 経由) は引き続き別スライスで扱う。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
@require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);

// H-04: CSRF 対策 — 金銭操作経路に Origin / Referer 検証を追加（opt-in）
verify_origin();

$user = require_auth();

if (!in_array($user['role'], ['manager', 'owner'], true)) {
    json_error('FORBIDDEN', '通常会計の取消は manager / owner のみ可能です', 403);
}

$body = get_json_body();
$storeId   = isset($body['store_id'])   ? (string)$body['store_id']   : '';
$paymentId = isset($body['payment_id']) ? (string)$body['payment_id'] : '';
$reasonIn  = isset($body['reason'])     ? (string)$body['reason']     : '';

if (!$storeId)   json_error('VALIDATION', 'store_id が必要です', 400);
if (!$paymentId) json_error('VALIDATION', 'payment_id が必要です', 400);

$reason = trim($reasonIn);
if ($reason === '') {
    json_error('VALIDATION', 'reason (取消理由) は必須です', 400);
}
$reason = mb_substr($reason, 0, 255);

require_store_access($storeId);
$tenantId = $user['tenant_id'];

$pdo = get_db();

// migration-pwa4d5a 適用済みかチェック
$hasVoidCols = true;
try { $pdo->query('SELECT void_status FROM payments LIMIT 0'); }
catch (PDOException $e) { $hasVoidCols = false; }
if (!$hasVoidCols) {
    json_error(
        'CONFLICT',
        '取消機能の migration が未適用です (migration-pwa4d5a-payment-void.sql)',
        409
    );
}

// refund / synced / gateway / is_partial カラム検出 (環境差吸収)
$hasRefundCols = true;
try { $pdo->query('SELECT refund_status FROM payments LIMIT 0'); }
catch (PDOException $e) { $hasRefundCols = false; }

$hasSyncedCol = true;
try { $pdo->query('SELECT synced_payment_id FROM payments LIMIT 0'); }
catch (PDOException $e) { $hasSyncedCol = false; }

$hasGatewayCol = true;
try { $pdo->query('SELECT gateway_name FROM payments LIMIT 0'); }
catch (PDOException $e) { $hasGatewayCol = false; }

$hasPartialCol = true;
try { $pdo->query('SELECT is_partial FROM payments LIMIT 0'); }
catch (PDOException $e) { $hasPartialCol = false; }

$voidByUserId = isset($user['user_id']) ? (string)$user['user_id'] : null;

// ======================== トランザクション ========================
$inTx = false;
try {
    $pdo->beginTransaction();
    $inTx = true;

    // 1. payments を FOR UPDATE で取得 (store_id 境界)
    $extraSelect = '';
    if ($hasRefundCols)  $extraSelect .= ', refund_status';
    if ($hasSyncedCol)   $extraSelect .= ', synced_payment_id';
    if ($hasGatewayCol)  $extraSelect .= ', gateway_name';
    if ($hasPartialCol)  $extraSelect .= ', is_partial';

    $pStmt = $pdo->prepare(
        'SELECT id, store_id, order_ids, total_amount, payment_method,
                void_status, note' . $extraSelect . '
           FROM payments
          WHERE id = ? AND store_id = ?
          LIMIT 1 FOR UPDATE'
    );
    $pStmt->execute([$paymentId, $storeId]);
    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pRow) {
        $pdo->rollBack(); $inTx = false;
        json_error('NOT_FOUND', '指定された payments が見つかりません (store_id 境界不一致の可能性)', 404);
    }

    // ガード 1: order_ids が JSON_LENGTH > 0
    $orderIds = [];
    $orderIdsRaw = $pRow['order_ids'];
    if ($orderIdsRaw !== null && $orderIdsRaw !== '') {
        $decoded = json_decode((string)$orderIdsRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $oid) {
                if (is_string($oid) && strlen($oid) > 0) $orderIds[] = $oid;
            }
        }
    }
    if (count($orderIds) === 0) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5C_BA: 注文紐付きの通常会計のみ取消可能です (手入力計上は 4d-5a で扱う)',
            409
        );
    }

    // ガード 2: note が emergency_ で始まる接頭辞を含まない
    //   process-payment.php は note を INSERT しないので通常は note IS NULL。
    //   緊急会計転記 (4c-2) と手入力計上 (4d-4a) は note に emergency_transfer src= /
    //   emergency_manual_transfer src= を持つので除外する。
    $payNote = (string)($pRow['note'] === null ? '' : $pRow['note']);
    if (strpos($payNote, 'emergency_transfer src=') !== false) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5C_BA: 注文紐付き緊急会計転記は 4d-5b の emergency-payment-transfer-void.php で取消してください',
            409
        );
    }
    if (strpos($payNote, 'emergency_manual_transfer') !== false) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5C_BA: 手入力計上は 4d-5a の emergency-payment-manual-void.php で取消してください',
            409
        );
    }

    // ガード 3: synced_payment_id が NULL (緊急会計転記元の payments でない)
    //   - 緊急会計の "synced 先" payments には note が付くのでガード2で弾けるが、
    //     念のため synced_payment_id の存在チェックは弱いシグナルとして実施しない。
    //   - synced_payment_id は emergency_payments 側にあり、payments 側にはほぼ無い (環境依存)。
    //   - $hasSyncedCol が真でも payments.synced_payment_id 列が NULL であることが通常会計の条件。
    if ($hasSyncedCol && !empty($pRow['synced_payment_id'])) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5C_BA: 緊急会計連携 payments は本 API で取消できません',
            409
        );
    }

    $curVoidStatus = (string)($pRow['void_status'] === null ? 'active' : $pRow['void_status']);

    // 2. idempotent: 既に voided
    if ($curVoidStatus === 'voided') {
        $pdo->commit(); $inTx = false;
        json_response([
            'paymentId'  => $paymentId,
            'voided'     => false,
            'idempotent' => true,
        ]);
    }

    if ($curVoidStatus !== 'active') {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', 'void_status が想定外の値です (' . mb_substr($curVoidStatus, 0, 30) . ')', 409);
    }

    // 返金済みは取消不可 (4d-5c-bb で取消→返金チェーンを設計予定)
    if ($hasRefundCols) {
        $rs = (string)($pRow['refund_status'] === null ? 'none' : $pRow['refund_status']);
        if ($rs !== 'none' && $rs !== '') {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'NOT_VOIDABLE_REFUNDED: 返金済みの payments は本 API で取消できません (refund_status=' .
                    mb_substr($rs, 0, 20) . ')',
                409
            );
        }
    }

    // 4d-5c-bb-A: payment_method = cash / card / qr (ENUM 全値) を許可
    //   payments.payment_method ENUM は ('cash','card','qr') で限定されているため、
    //   method 自体の追加ガードは不要。gateway 経由は次の gateway_name ガードで弾く。
    //   cash_log 相殺は後段で payment_method='cash' 限定なので手動 card / qr では発火しない。
    $payMethod = (string)$pRow['payment_method'];

    // 4d-5c-ba hotfix: gateway_name IS NULL / '' 限定 (gateway 通過は 4d-5c-bb 範囲外として残存)
    if ($hasGatewayCol) {
        $gwName = (string)($pRow['gateway_name'] === null ? '' : $pRow['gateway_name']);
        if ($gwName !== '') {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'NOT_VOIDABLE_GATEWAY: 決済ゲートウェイを経由した会計の取消は 4d-5c-bb で対応予定です (gateway=' .
                    mb_substr($gwName, 0, 30) . ')',
                409
            );
        }
    }

    // 4d-5c-ba hotfix: is_partial != 1 限定 (分割会計は 4d-5c-bb 範囲)
    //   分割払いは同一 order に複数 payments 行が紐付くため、部分 void の扱いを別途設計する必要あり。
    if ($hasPartialCol) {
        $isPartial = isset($pRow['is_partial']) ? (int)$pRow['is_partial'] : 0;
        if ($isPartial === 1) {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'NOT_VOIDABLE_PARTIAL: 分割会計の取消は 4d-5c-bb で対応予定です',
                409
            );
        }
    }

    // 3. 補正(2) — linked orders の検証
    //   全件 status='paid' かつ order_type IN ('dine_in','handy','takeout') を要求する。
    //   - dine_in / handy: 通常の店内会計 (4d-5c-ba から対応)
    //   - takeout: POS cashier 経由で会計済み (status='paid') のものだけ (4d-5c-bb-C で追加)。
    //     online 決済 takeout (status='served' 終端) は本 API では status='paid' ガードで弾かれる。
    //   cancelled / pending / preparing / served / pending_payment 等はそもそも会計済みでないので除外。
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $oParams = $orderIds;
    $oParams[] = $storeId;
    $oStmt = $pdo->prepare(
        'SELECT id, status, order_type
           FROM orders
          WHERE id IN (' . $placeholders . ') AND store_id = ?'
    );
    $oStmt->execute($oParams);
    $oRows = $oStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($oRows) !== count($orderIds)) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_ORDER_MISSING: 紐づく注文の一部が store_id 境界内に見つかりません',
            409
        );
    }

    foreach ($oRows as $oRow) {
        $oStatus = (string)$oRow['status'];
        $oType   = (string)$oRow['order_type'];
        if ($oStatus !== 'paid') {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'NOT_VOIDABLE_ORDER_STATUS: 紐づく注文の status が paid 以外です (id=' .
                    mb_substr((string)$oRow['id'], 0, 20) . ', status=' .
                    mb_substr($oStatus, 0, 20) . ')',
                409
            );
        }
        if ($oType !== 'dine_in' && $oType !== 'handy' && $oType !== 'takeout') {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'NOT_VOIDABLE_ORDER_TYPE: dine_in / handy / takeout 以外は本 API 範囲外です (id=' .
                    mb_substr((string)$oRow['id'], 0, 20) . ', order_type=' .
                    mb_substr($oType, 0, 20) . ')',
                409
            );
        }
    }

    // 4. payments を voided に UPDATE
    $updStmt = $pdo->prepare(
        "UPDATE payments
            SET void_status = 'voided',
                voided_at = NOW(),
                voided_by = ?,
                void_reason = ?
          WHERE id = ? AND store_id = ? AND void_status = 'active'"
    );
    $updStmt->execute([$voidByUserId, $reason, $paymentId, $storeId]);
    if ($updStmt->rowCount() !== 1) {
        // 並行 void の race
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', '別処理が並行で取消したため失敗しました。画面を再読込してください', 409);
    }

    // 5. cash の場合のみ cash_log に cash_out 相殺行 INSERT
    $paymentMethodOnPayments = (string)$pRow['payment_method'];
    $paymentTotal = (int)$pRow['total_amount'];
    $cashLogId = null;
    if ($paymentMethodOnPayments === 'cash' && $paymentTotal > 0) {
        $cashLogId = generate_uuid();
        $cashNote = '通常会計取消 payment_void:' . mb_substr($paymentId, 0, 40);
        $pdo->prepare(
            "INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at)
             VALUES (?, ?, ?, 'cash_out', ?, ?, NOW())"
        )->execute([
            $cashLogId, $storeId, $voidByUserId, $paymentTotal, mb_substr($cashNote, 0, 200)
        ]);
    }

    $pdo->commit();
    $inTx = false;

    // 6. 監査ログ (optional)
    if (function_exists('write_audit_log')) {
        @write_audit_log(
            $pdo, $user, $storeId,
            'payment_void', 'payment', $paymentId,
            ['void_status' => 'active'],
            [
                'void_status'              => 'voided',
                'void_reason'              => $reason,
                'cash_log_id'              => $cashLogId,
                'total_amount'             => $paymentTotal,
                'payment_method'           => $paymentMethodOnPayments,
                'order_ids_count'          => count($orderIds),
                'orders_status_preserved'  => 'paid',
                'table_sessions_preserved' => true,
            ]
        );
    }

    json_response([
        'paymentId'             => $paymentId,
        'voided'                => true,
        'idempotent'            => false,
        'cashLogId'             => $cashLogId,
        // 注: orders.status / table_sessions / session_token は意図的に維持している
        'ordersStatusPreserved' => true,
    ]);
} catch (PDOException $e) {
    if ($inTx) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        $inTx = false;
    }
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/payment-void.php] db_error: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    json_error('DB_ERROR', '通常会計の取消に失敗しました', 500);
}
