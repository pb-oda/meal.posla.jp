<?php
/**
 * 緊急会計 注文紐付き転記 void API (PWA Phase 4d-5b / 2026-04-21)
 *
 * POST /api/store/emergency-payment-transfer-void.php
 *
 * body: {
 *   store_id: "<uuid>",
 *   emergency_payment_id: "<uuid>",
 *   reason: "必須、1-255 文字"
 * }
 *
 * 目的:
 *   emergency-payment-transfer.php (Phase 4c-2) で作成された注文紐付き payments を
 *   論理取消する。手入力計上 (emergency-payment-manual-transfer.php 由来) は 4d-5a の
 *   emergency-payment-manual-void.php で扱うため、本 API では 409 で弾く。
 *   通常会計 payments (process-payment.php 由来) も対象外。
 *
 * 対象条件 (三重ガード):
 *   - emergency_payments.synced_payment_id IS NOT NULL
 *   - emergency_payments.order_ids_json の JSON_LENGTH > 0 (= 注文紐付き)
 *   - 紐づく payments が存在 (store_id 境界一致)
 *   - payments.order_ids の JSON_LENGTH > 0
 *   - payments.note LIKE '%emergency_transfer src=%'
 *   - payments.note NOT LIKE '%emergency_manual_transfer%'  (manual は 4d-5a へ)
 *   - payments.void_status = 'active'
 *
 * 処理 (accounting void / operational reopen を分離):
 *   1. TX + FOR UPDATE で payments 対象行をロック
 *   2. 既に voided → idempotent:true で即返却
 *   3. UPDATE payments SET void_status='voided', voided_at/by/reason
 *   4. payment_method='cash' の場合のみ cash_log に cash_out 相殺行 INSERT
 *      (note='緊急会計転記取消 payment_void:<payment_id>')
 *   5. emergency_payments.transfer_note に '[voided at YYYY-MM-DD HH:MM:SS]' を追記 (255 文字制限)
 *   6. emergency_payments.synced_payment_id は NULL に戻さない (履歴保持)
 *
 * 絶対にやらない (accounting void の責務から明示的に除外):
 *   - orders.status / orders.paid_at / orders.payment_method の変更 → KDS 再表示・卓状態復活の副作用を避ける
 *   - table_sessions.status / closed_at の変更 → 退店済み卓を再オープンしない
 *   - tables.session_token / session_token_expires_at の変更 → 旧 QR の有効化を避ける
 *   - emergency_payments.synced_payment_id の NULL 戻し
 *   - process-payment.php / emergency-payment-transfer.php / receipt.php / refund-payment.php / sales-report.php の呼び出し
 *   - 物理 DELETE
 *
 * 残リスク (仕様):
 *   sales-report.php / turnover-report.php は orders.status='paid' ベース集計なので void 後も残る。
 *   4d-5b では UI / docs で店舗スタッフに明示。4d-5c 以降で sales-report 統合時に解消予定。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
@require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);
$user = require_auth();

if (!in_array($user['role'], ['manager', 'owner'], true)) {
    json_error('FORBIDDEN', '注文紐付き緊急会計転記の取消は manager / owner のみ可能です', 403);
}

$body = get_json_body();
$storeId            = isset($body['store_id']) ? (string)$body['store_id'] : '';
$emergencyPaymentId = isset($body['emergency_payment_id']) ? (string)$body['emergency_payment_id'] : '';
$reasonIn           = isset($body['reason']) ? (string)$body['reason'] : '';

if (!$storeId)            json_error('VALIDATION', 'store_id が必要です', 400);
if (!$emergencyPaymentId) json_error('VALIDATION', 'emergency_payment_id が必要です', 400);

$reason = trim($reasonIn);
if ($reason === '') {
    json_error('VALIDATION', 'reason (取消理由) は必須です', 400);
}
$reason = mb_substr($reason, 0, 255);

require_store_access($storeId);
$tenantId = $user['tenant_id'];

$pdo = get_db();

// void カラム (4d-5a の migration) が適用済みか事前チェック
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

// 取消実行者名 (監査ログ / transfer_note 追記用)
$voidByUserId = isset($user['user_id']) ? (string)$user['user_id'] : null;
$voidByName   = isset($user['username']) ? (string)$user['username'] : '';
try {
    if ($voidByUserId) {
        $uStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
        $uStmt->execute([$voidByUserId, $tenantId]);
        $uRow = $uStmt->fetch();
        if ($uRow && !empty($uRow['display_name'])) {
            $voidByName = (string)$uRow['display_name'];
        }
    }
} catch (PDOException $e) {
    // username fallback
}
$voidByName = mb_substr($voidByName, 0, 100);

// ======================== トランザクション ========================
$inTx = false;
try {
    $pdo->beginTransaction();
    $inTx = true;

    // 1. emergency_payments を取得 (tenant/store/id 三重境界)
    $eStmt = $pdo->prepare(
        'SELECT id, tenant_id, store_id, local_emergency_payment_id,
                synced_payment_id, transfer_note,
                payment_method, total_amount, order_ids_json
           FROM emergency_payments
          WHERE id = ? AND tenant_id = ? AND store_id = ?
          LIMIT 1 FOR UPDATE'
    );
    $eStmt->execute([$emergencyPaymentId, $tenantId, $storeId]);
    $eRow = $eStmt->fetch(PDO::FETCH_ASSOC);

    if (!$eRow) {
        $pdo->rollBack(); $inTx = false;
        json_error('NOT_FOUND', '指定された緊急会計が見つかりません', 404);
    }

    if (empty($eRow['synced_payment_id'])) {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', 'まだ売上転記されていません (synced_payment_id が未設定)', 409);
    }

    // ガード 1: emergency_payments.order_ids_json の JSON_LENGTH > 0
    //   手入力 emergency_payments (orderIds=0) は対象外 (4d-5a manual-void で扱う)
    $eOrderIdsLen = 0;
    if (!empty($eRow['order_ids_json'])) {
        $decoded = json_decode((string)$eRow['order_ids_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $oid) {
                if (is_string($oid) && strlen($oid) > 0) $eOrderIdsLen++;
            }
        }
    }
    if ($eOrderIdsLen === 0) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5B: 手入力計上は 4d-5a の emergency-payment-manual-void.php で取り消してください',
            409
        );
    }

    $paymentId = (string)$eRow['synced_payment_id'];

    // 2. 紐づく payments を FOR UPDATE で取得
    $pStmt = $pdo->prepare(
        'SELECT id, store_id, order_ids, total_amount, payment_method,
                void_status, note
           FROM payments
          WHERE id = ? AND store_id = ?
          LIMIT 1 FOR UPDATE'
    );
    $pStmt->execute([$paymentId, $storeId]);
    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pRow) {
        $pdo->rollBack(); $inTx = false;
        json_error('NOT_FOUND', '紐づく payments が見つかりません (store_id 境界不一致の可能性)', 404);
    }

    // ガード 2: payments.order_ids の JSON_LENGTH > 0
    $pOrderIdsLen = 0;
    $orderIdsRaw = $pRow['order_ids'];
    if ($orderIdsRaw !== null && $orderIdsRaw !== '') {
        $decoded = json_decode((string)$orderIdsRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $oid) {
                if (is_string($oid) && strlen($oid) > 0) $pOrderIdsLen++;
            }
        }
    }
    if ($pOrderIdsLen === 0) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5B: 注文紐付きの payments のみ取消可能です (emergency_manual_transfer は 4d-5a で扱う)',
            409
        );
    }

    // ガード 3: note に emergency_transfer src= を含む AND emergency_manual_transfer を含まない
    //   通常会計 payments (process-payment.php) と手入力計上 payments (emergency-payment-manual-transfer.php) を
    //   確実に除外する。
    $payNote = (string)($pRow['note'] === null ? '' : $pRow['note']);
    if (strpos($payNote, 'emergency_transfer src=') === false) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5B: 通常会計 payments は 4d-5b の取消対象外です (4d-5c で対応予定)',
            409
        );
    }
    if (strpos($payNote, 'emergency_manual_transfer') !== false) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5B: 手入力計上は 4d-5a の emergency-payment-manual-void.php で取り消してください',
            409
        );
    }

    $curVoidStatus = (string)($pRow['void_status'] === null ? 'active' : $pRow['void_status']);

    // 3. idempotent: 既に voided
    if ($curVoidStatus === 'voided') {
        $pdo->commit(); $inTx = false;
        json_response([
            'emergencyPaymentId' => $emergencyPaymentId,
            'paymentId'          => $paymentId,
            'voided'             => false,
            'idempotent'         => true,
        ]);
    }

    if ($curVoidStatus !== 'active') {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', 'void_status が想定外の値です (' . mb_substr($curVoidStatus, 0, 30) . ')', 409);
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

    // 5. cash の場合のみ cash_log に cash_out 相殺行 INSERT (ENUM 流用、note で識別)
    $paymentMethodOnPayments = (string)$pRow['payment_method'];
    $paymentTotal = (int)$pRow['total_amount'];
    $cashLogId = null;
    if ($paymentMethodOnPayments === 'cash' && $paymentTotal > 0) {
        $cashLogId = generate_uuid();
        $cashNote = '緊急会計転記取消 payment_void:' . mb_substr($paymentId, 0, 40);
        $pdo->prepare(
            "INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at)
             VALUES (?, ?, ?, 'cash_out', ?, ?, NOW())"
        )->execute([
            $cashLogId, $storeId, $voidByUserId, $paymentTotal, mb_substr($cashNote, 0, 200)
        ]);
    }

    // 6. emergency_payments.transfer_note に void 情報を追記 (255 文字以内)
    $curTransferNote = (string)($eRow['transfer_note'] === null ? '' : $eRow['transfer_note']);
    $voidSuffix = ' [voided at ' . date('Y-m-d H:i:s') . ' by ' . ($voidByName ?: $voidByUserId) . ']';
    $newTransferNote = mb_substr($curTransferNote . $voidSuffix, 0, 255);
    $pdo->prepare(
        'UPDATE emergency_payments
            SET transfer_note = ?, updated_at = NOW()
          WHERE id = ? AND tenant_id = ? AND store_id = ?'
    )->execute([$newTransferNote, $emergencyPaymentId, $tenantId, $storeId]);

    $pdo->commit();
    $inTx = false;

    // 7. 監査ログ (optional)
    if (function_exists('write_audit_log')) {
        @write_audit_log(
            $pdo, $user, $storeId,
            'emergency_transfer_void', 'payment', $paymentId,
            ['void_status' => 'active'],
            [
                'void_status' => 'voided',
                'void_reason' => $reason,
                'emergency_payment_id' => $emergencyPaymentId,
                'cash_log_id' => $cashLogId,
                'total_amount' => $paymentTotal,
                'payment_method' => $paymentMethodOnPayments,
                'orders_status_preserved' => 'paid',
                'table_sessions_preserved' => true,
            ]
        );
    }

    json_response([
        'emergencyPaymentId' => $emergencyPaymentId,
        'paymentId'          => $paymentId,
        'voided'             => true,
        'idempotent'         => false,
        'cashLogId'          => $cashLogId,
        // 注: orders.status / table_sessions / session_token は意図的に維持している
        'ordersStatusPreserved' => true,
    ]);
} catch (PDOException $e) {
    if ($inTx) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        $inTx = false;
    }
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/emergency-payment-transfer-void.php] db_error: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    json_error('DB_ERROR', '注文紐付き緊急会計転記の取消に失敗しました', 500);
}
