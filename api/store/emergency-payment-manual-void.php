<?php
/**
 * 緊急会計 手入力計上 void API (PWA Phase 4d-5a / 2026-04-21)
 *
 * POST /api/store/emergency-payment-manual-void.php
 *
 * body: {
 *   store_id: "<uuid>",
 *   emergency_payment_id: "<uuid>",
 *   reason: "必須、1-255 文字"
 * }
 *
 * 目的:
 *   emergency-payment-manual-transfer.php (Phase 4d-4a) で作成された手入力計上 payments を
 *   論理取消する。通常会計 payments と注文紐付き emergency transfer 分は対象外で 409。
 *
 * 対象条件 (全て AND):
 *   - emergency_payments.synced_payment_id IS NOT NULL
 *   - 紐づく payments が存在する (store_id で境界確認)
 *   - payments.order_ids が '[]' または JSON_LENGTH=0 (= 手入力計上)
 *   - payments.note に 'emergency_manual_transfer' を含む (= 4d-4a 由来)
 *   - payments.void_status = 'active' (未 void)
 *
 * 通常会計 payments や注文紐付き emergency transfer (orderIds>0) は
 * 全て 409 NOT_VOIDABLE_IN_4D5A で拒否。
 *
 * 処理:
 *   1. TX + FOR UPDATE で payments 対象行をロック
 *   2. 既に voided → idempotent:true で即返却 (UPDATE / cash_log INSERT なし)
 *   3. UPDATE payments SET void_status='voided', voided_at, voided_by, void_reason
 *   4. cash の場合のみ cash_log に cash_out 相殺行 INSERT
 *      (type='cash_out', note='手入力売上取消 payment_void:<payment_id>')
 *   5. emergency_payments.synced_payment_id は消さない (履歴保持)
 *      transfer_note に void 情報を追記 (VARCHAR(255) を超えないよう安全に追加)
 *   6. 監査ログ (function_exists で optional)
 *
 * やらないこと:
 *   - orders.status の変更
 *   - table_sessions / session_token の変更
 *   - emergency_payments.synced_payment_id の NULL 戻し
 *   - refund_status の変更
 *   - payments.payment_method ENUM 拡張
 *   - cash_log.type ENUM 拡張
 *   - 物理 DELETE
 *   - receipt.php / refund-payment.php / sales-report.php の呼び出し
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
@require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);
$user = require_auth();

if (!in_array($user['role'], ['manager', 'owner'], true)) {
    json_error('FORBIDDEN', '手入力計上の取消は manager / owner のみ可能です', 403);
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

// void カラムが適用済みか事前チェック
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

    // 1. emergency_payments を取得
    $eStmt = $pdo->prepare(
        'SELECT id, tenant_id, store_id, local_emergency_payment_id,
                synced_payment_id, transfer_note,
                payment_method, total_amount
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
        json_error('CONFLICT', 'まだ売上計上されていません (synced_payment_id が未設定)', 409);
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

    // 3. 対象条件チェック: order_ids が空 (手入力)
    $orderIdsRaw = $pRow['order_ids'];
    $orderIdsLen = 0;
    if ($orderIdsRaw !== null && $orderIdsRaw !== '') {
        $decoded = json_decode((string)$orderIdsRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $oid) {
                if (is_string($oid) && strlen($oid) > 0) $orderIdsLen++;
            }
        }
    }
    if ($orderIdsLen > 0) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5A: 注文紐付きの payments は 4d-5a の取消対象外です (4d-5b で対応予定)',
            409
        );
    }

    // 4. 対象条件チェック: note に emergency_manual_transfer が含まれる (4d-4a 由来)
    $payNote = (string)($pRow['note'] === null ? '' : $pRow['note']);
    if (strpos($payNote, 'emergency_manual_transfer') === false) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'NOT_VOIDABLE_IN_4D5A: 手入力計上 (emergency-payment-manual-transfer) 経由の payments のみ取消可能です',
            409
        );
    }

    $curVoidStatus = (string)($pRow['void_status'] === null ? 'active' : $pRow['void_status']);

    // 5. idempotent: 既に voided
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

    // 6. payments を voided に UPDATE
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
        // 並行 void の race. rollBack して再読込を促す
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', '別処理が並行で取消したため失敗しました。画面を再読込してください', 409);
    }

    // 7. cash の場合のみ cash_log に cash_out 相殺行 INSERT
    //    type ENUM は既存の 'cash_out' を流用 (ENUM 拡張しない)
    //    created_at は void 実行時刻 (取消が発生した営業日に相殺するため NOW でよい)
    $paymentMethodOnPayments = (string)$pRow['payment_method'];
    $paymentTotal = (int)$pRow['total_amount'];
    $cashLogId = null;
    if ($paymentMethodOnPayments === 'cash' && $paymentTotal > 0) {
        $cashLogId = generate_uuid();
        $cashNote = '手入力売上取消 payment_void:' . mb_substr($paymentId, 0, 40);
        $pdo->prepare(
            "INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at)
             VALUES (?, ?, ?, 'cash_out', ?, ?, NOW())"
        )->execute([
            $cashLogId, $storeId, $voidByUserId, $paymentTotal, mb_substr($cashNote, 0, 200)
        ]);
    }

    // 8. emergency_payments.transfer_note に void 情報を追記 (255 文字以内)
    //    既存 note を残したまま、末尾に追記。超えたら安全に切る。
    $curTransferNote = (string)($eRow['transfer_note'] === null ? '' : $eRow['transfer_note']);
    $voidSuffix = ' [voided at ' . date('Y-m-d H:i') . ' by ' . ($voidByName ?: $voidByUserId) . ']';
    $newTransferNote = mb_substr($curTransferNote . $voidSuffix, 0, 255);
    $pdo->prepare(
        'UPDATE emergency_payments
            SET transfer_note = ?, updated_at = NOW()
          WHERE id = ? AND tenant_id = ? AND store_id = ?'
    )->execute([$newTransferNote, $emergencyPaymentId, $tenantId, $storeId]);

    $pdo->commit();
    $inTx = false;

    // 9. 監査ログ (optional)
    if (function_exists('write_audit_log')) {
        @write_audit_log(
            $pdo, $user, $storeId,
            'emergency_manual_void', 'payment', $paymentId,
            ['void_status' => 'active'],
            [
                'void_status' => 'voided',
                'void_reason' => $reason,
                'emergency_payment_id' => $emergencyPaymentId,
                'cash_log_id' => $cashLogId,
                'total_amount' => $paymentTotal,
                'payment_method' => $paymentMethodOnPayments,
            ]
        );
    }

    json_response([
        'emergencyPaymentId' => $emergencyPaymentId,
        'paymentId'          => $paymentId,
        'voided'             => true,
        'idempotent'         => false,
        'cashLogId'          => $cashLogId,  // null または生成済 uuid
    ]);
} catch (PDOException $e) {
    if ($inTx) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        $inTx = false;
    }
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/emergency-payment-manual-void.php] db_error: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '手入力計上の取消に失敗しました', 500);
}
