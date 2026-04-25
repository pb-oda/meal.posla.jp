<?php
/**
 * 緊急会計 手入力売上計上 API (PWA Phase 4d-4a / 2026-04-21)
 *
 * POST /api/store/emergency-payment-manual-transfer.php
 *
 * body: {
 *   store_id: "<uuid>",
 *   emergency_payment_id: "<uuid>",
 *   note: "任意"
 * }
 *
 * 目的:
 *   緊急会計台帳の「手入力記録」(orderIds 空 / 注文紐付けなし) を通常 payments に INSERT し、
 *   取引ジャーナル (payments-journal)、レジ分析 (register-report の cashLog / externalMethodBreakdown)、
 *   レジ残高計算 (expectedBalance) に反映する。
 *
 *   sales-report.php は orders.status='paid' ベース集計のため、手入力売上は「出ない」(仕様)。
 *   4d-5 以降で sales-report を payments ベースに統合する際に解消する残リスク。
 *
 *   売上取消 / 転記取消は 4d-5 へ送る。4d-4a では synced_payment_id 付きの resolution 変更は
 *   UI 側でガードするが、サーバー側の変更は行わない。
 *
 * 認証:
 *   require_auth() + require_store_access($storeId) + role IN ('manager', 'owner')
 *   staff / device は 403 FORBIDDEN
 *
 * 対象条件 (全て AND):
 *   - resolution_status = 'confirmed'
 *   - synced_payment_id IS NULL
 *   - status != 'failed'
 *   - total_amount > 0
 *   - order_ids_json NULL または JSON_LENGTH(order_ids_json) = 0  (注文紐付きは transfer.php の方で扱う)
 *   - payment_method は cash / external_card / external_qr / other_external のいずれか
 *   - other_external は external_method_type が voucher/bank_transfer/accounts_receivable/point/other
 *
 * 絶対にやらない:
 *   - orders / order_items の変更 (手入力は注文が存在しないため、元から触る対象がない)
 *   - tables / table_sessions の変更 (table_id なしの手入力なので不要)
 *   - emergency_payment_orders の変更 (子テーブルも orderIds なしで存在しない)
 *   - payments.payment_method ENUM 拡張
 *   - cash_log.type ENUM 拡張
 *   - process-payment.php / refund-payment.php / receipt.php / sales-report.php の呼び出し
 *   - transfer.php の呼び出し (独立 INSERT 経路)
 *
 * Idempotency:
 *   synced_payment_id が既に入っていれば INSERT/UPDATE せず {idempotent:true} で 200 を返す。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
@require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);

// H-04: CSRF 対策 — 手入力売上計上経路に Origin / Referer 検証を追加（opt-in）
verify_origin();

$user = require_auth();

if (!in_array($user['role'], ['manager', 'owner'], true)) {
    json_error('FORBIDDEN', '緊急会計の手入力計上は manager / owner のみ可能です', 403);
}

$body = get_json_body();
$storeId            = isset($body['store_id']) ? (string)$body['store_id'] : '';
$emergencyPaymentId = isset($body['emergency_payment_id']) ? (string)$body['emergency_payment_id'] : '';
$noteIn             = isset($body['note']) ? (string)$body['note'] : '';

if (!$storeId)            json_error('VALIDATION', 'store_id が必要です', 400);
if (!$emergencyPaymentId) json_error('VALIDATION', 'emergency_payment_id が必要です', 400);

$transferNoteInput = trim($noteIn);
if ($transferNoteInput !== '') {
    $transferNoteInput = mb_substr($transferNoteInput, 0, 200);
}

require_store_access($storeId);
$tenantId = $user['tenant_id'];

$pdo = get_db();

// 転記実行者名の取得 (display_name → username フォールバック)
$transferByUserId = isset($user['user_id']) ? (string)$user['user_id'] : null;
$transferByName   = isset($user['username']) ? (string)$user['username'] : '';
try {
    if ($transferByUserId) {
        $uStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
        $uStmt->execute([$transferByUserId, $tenantId]);
        $uRow = $uStmt->fetch();
        if ($uRow && !empty($uRow['display_name'])) {
            $transferByName = (string)$uRow['display_name'];
        }
    }
} catch (PDOException $e) {
    // users 参照失敗は username フォールバックのまま
}
$transferByName = mb_substr($transferByName, 0, 100);

// 動的カラム検出 (transfer.php と同方針)
$hasMergedCols = false;
try { $pdo->query('SELECT merged_table_ids FROM payments LIMIT 0'); $hasMergedCols = true; }
catch (PDOException $e) {}

$hasTransferCols = true;
try { $pdo->query('SELECT transferred_by_user_id FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasTransferCols = false; }

$hasEmergencyExtCol = true;
try { $pdo->query('SELECT external_method_type FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasEmergencyExtCol = false; }

$hasPaymentsExtCol = true;
try { $pdo->query('SELECT external_method_type FROM payments LIMIT 0'); }
catch (PDOException $e) { $hasPaymentsExtCol = false; }

// ======================== トランザクション ========================
$inTx = false;
try {
    $pdo->beginTransaction();
    $inTx = true;

    // 1. emergency_payments 対象行を FOR UPDATE
    $selCols = 'id, tenant_id, store_id, local_emergency_payment_id,
                table_id, table_code, order_ids_json, item_snapshot_json,
                subtotal_amount, tax_amount, total_amount,
                payment_method, received_amount, change_amount,
                external_terminal_name, external_slip_no, external_approval_no, note,
                status, resolution_status, synced_payment_id,
                staff_name, staff_user_id, staff_pin_verified,
                client_created_at, server_received_at';
    if ($hasEmergencyExtCol) {
        $selCols .= ', external_method_type';
    }

    $selStmt = $pdo->prepare(
        'SELECT ' . $selCols . ' FROM emergency_payments
          WHERE id = ? AND tenant_id = ? AND store_id = ?
          LIMIT 1 FOR UPDATE'
    );
    $selStmt->execute([$emergencyPaymentId, $tenantId, $storeId]);
    $row = $selStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack(); $inTx = false;
        json_error('NOT_FOUND', '指定された緊急会計が見つかりません', 404);
    }

    // 2. idempotent: synced_payment_id 既存ならそのまま返す
    if (!empty($row['synced_payment_id'])) {
        $pdo->commit(); $inTx = false;
        json_response([
            'emergencyPaymentId' => $row['id'],
            'paymentId'          => $row['synced_payment_id'],
            'transferred'        => false,
            'idempotent'         => true,
            'manual'             => true,
        ]);
    }

    // 3. 前提条件チェック
    $resolutionStatus = $row['resolution_status'] === null ? 'unresolved' : $row['resolution_status'];
    if ($resolutionStatus !== 'confirmed') {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', '手入力計上には resolution_status=confirmed が必要です (現在: ' . $resolutionStatus . ')', 409);
    }
    if ($row['status'] === 'failed') {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', '同期失敗 (status=failed) の記録は計上できません', 409);
    }
    $totalAmount = (int)$row['total_amount'];
    if ($totalAmount <= 0) {
        $pdo->rollBack(); $inTx = false;
        json_error('VALIDATION', 'total_amount が 0 以下の記録は計上できません', 400);
    }

    // 4. 手入力モード判定: order_ids_json が NULL または JSON_LENGTH=0
    $orderIdsJsonRaw = $row['order_ids_json'];
    $orderIdsCount = 0;
    if (!empty($orderIdsJsonRaw)) {
        $tmp = json_decode($orderIdsJsonRaw, true);
        if (is_array($tmp)) {
            foreach ($tmp as $oid) {
                if (is_string($oid) && strlen($oid) > 0) $orderIdsCount++;
            }
        }
    }
    if ($orderIdsCount > 0) {
        $pdo->rollBack(); $inTx = false;
        json_error(
            'CONFLICT',
            'not_manual_entry: 注文紐付きの記録は emergency-payment-transfer.php で計上してください',
            409
        );
    }

    // 5. payment_method 許可判定
    $paymentMethodEmerg = (string)$row['payment_method'];
    if (!in_array($paymentMethodEmerg, ['cash', 'external_card', 'external_qr', 'other_external'], true)) {
        $pdo->rollBack(); $inTx = false;
        json_error('VALIDATION', '不正な payment_method です: ' . mb_substr($paymentMethodEmerg, 0, 40), 400);
    }

    // 6. other_external は分類済みのみ
    $allowedExternalMethodTypes = ['voucher', 'bank_transfer', 'accounts_receivable', 'point', 'other'];
    $emergencyExtType = (array_key_exists('external_method_type', $row) && $row['external_method_type'] !== null)
                         ? (string)$row['external_method_type'] : null;
    if ($paymentMethodEmerg === 'other_external') {
        if (!$hasEmergencyExtCol || !$hasPaymentsExtCol) {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'other_external の計上には migration-pwa4d2a / pwa4d2b が必要です (external_method_type カラム未適用)',
                409
            );
        }
        if ($emergencyExtType === null || !in_array($emergencyExtType, $allowedExternalMethodTypes, true)) {
            $pdo->rollBack(); $inTx = false;
            json_error(
                'CONFLICT',
                'unclassified_other_external: 外部分類 (voucher / bank_transfer / accounts_receivable / point / other) を先に記録してください',
                409
            );
        }
    }

    // 7. payment_method mapping
    //   cash → cash, external_card → card, external_qr → qr, other_external → qr
    //   other_external の分類は payments.external_method_type に保存 (既存 ENUM を壊さない)
    $methodForPayments = 'cash';
    $externalMethodTypeForPayments = null;
    if ($paymentMethodEmerg === 'cash') {
        $methodForPayments = 'cash';
    } elseif ($paymentMethodEmerg === 'external_card') {
        $methodForPayments = 'card';
    } elseif ($paymentMethodEmerg === 'external_qr') {
        $methodForPayments = 'qr';
    } elseif ($paymentMethodEmerg === 'other_external') {
        $methodForPayments = 'qr';
        $externalMethodTypeForPayments = $emergencyExtType;
    }

    // 8. paid_at: client_created_at → server_received_at → NOW
    $paidAt = null;
    if (!empty($row['client_created_at'])) {
        $paidAt = (string)$row['client_created_at'];
    } elseif (!empty($row['server_received_at'])) {
        $paidAt = (string)$row['server_received_at'];
    } else {
        $paidAt = date('Y-m-d H:i:s');
    }

    // 9. payments.user_id = 実レジ担当者 (Fix 3 と同ルール)
    $cashierUserIdForPayments = $transferByUserId;
    $staffUserIdCand    = !empty($row['staff_user_id']) ? (string)$row['staff_user_id'] : '';
    $staffPinVerified   = (int)(isset($row['staff_pin_verified']) ? $row['staff_pin_verified'] : 0);
    if ($staffUserIdCand !== '' && $staffPinVerified === 1) {
        try {
            $chkStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
            $chkStmt->execute([$staffUserIdCand, $tenantId]);
            if ($chkStmt->fetch()) {
                $cashierUserIdForPayments = $staffUserIdCand;
            }
        } catch (PDOException $e) {
            // 照合失敗時は転記者 fallback
        }
    }

    // 10. note 組み立て (VARCHAR(200))
    $mergedNoteParts = [];
    if ($transferNoteInput !== '') $mergedNoteParts[] = $transferNoteInput;
    $mergedNoteParts[] = 'emergency_manual_transfer src=' . mb_substr((string)$row['local_emergency_payment_id'], 0, 40);
    if (!empty($row['external_terminal_name'])) {
        $mergedNoteParts[] = 'term=' . mb_substr((string)$row['external_terminal_name'], 0, 24);
    }
    if (!empty($row['external_slip_no'])) {
        $mergedNoteParts[] = 'slip=' . mb_substr((string)$row['external_slip_no'], 0, 24);
    }
    if (!empty($row['external_approval_no'])) {
        $mergedNoteParts[] = 'appr=' . mb_substr((string)$row['external_approval_no'], 0, 20);
    }
    if ($paymentMethodEmerg === 'other_external' && $externalMethodTypeForPayments) {
        $mergedNoteParts[] = 'ext_type=' . $externalMethodTypeForPayments;
    }
    $paymentsNote = mb_substr(implode(' / ', $mergedNoteParts), 0, 200);

    // transfer_note (emergency_payments 側、VARCHAR(255))
    $transferNoteStored = ($transferNoteInput !== '') ? mb_substr($transferNoteInput, 0, 255) : null;

    // 11. itemSnapshot 抽出 (paid_items 用)
    $itemSnapshot = [];
    if (!empty($row['item_snapshot_json'])) {
        $tmp = json_decode($row['item_snapshot_json'], true);
        if (is_array($tmp)) $itemSnapshot = $tmp;
    }

    // 12. tax split: itemSnapshot があれば再集計、無ければ emergency.subtotal_amount/tax_amount を 10% 側に寄せる
    $subtotal10 = 0; $tax10 = 0;
    $subtotal8  = 0; $tax8  = 0;
    if (count($itemSnapshot) > 0) {
        $sumTaxIncl = 0;
        $missingTaxRate = false;
        $bufSub10 = 0; $bufTax10 = 0;
        $bufSub8  = 0; $bufTax8  = 0;
        foreach ($itemSnapshot as $it) {
            if (!is_array($it)) continue;
            $qty   = isset($it['qty']) ? (int)$it['qty'] : 0;
            $price = isset($it['price']) ? (int)$it['price'] : 0;
            $lineTotal = $qty * $price;
            $sumTaxIncl += $lineTotal;
            $taxRate = null;
            if (isset($it['taxRate']) && is_numeric($it['taxRate'])) {
                $taxRate = (int)$it['taxRate'];
            }
            if ($taxRate !== 10 && $taxRate !== 8) {
                $missingTaxRate = true;
                $taxRate = 10;
            }
            if ($taxRate === 8) {
                $sub = (int)floor($lineTotal / 1.08);
                $bufSub8 += $sub;
                $bufTax8 += $lineTotal - $sub;
            } else {
                $sub = (int)floor($lineTotal / 1.10);
                $bufSub10 += $sub;
                $bufTax10 += $lineTotal - $sub;
            }
        }
        if ($missingTaxRate || $sumTaxIncl !== $totalAmount) {
            $subtotal10 = (int)$row['subtotal_amount'];
            $tax10      = (int)$row['tax_amount'];
            if (($subtotal10 + $tax10) !== $totalAmount) {
                $subtotal10 = (int)floor($totalAmount / 1.10);
                $tax10      = $totalAmount - $subtotal10;
            }
        } else {
            $subtotal10 = $bufSub10;
            $tax10      = $bufTax10;
            $subtotal8  = $bufSub8;
            $tax8       = $bufTax8;
        }
    } else {
        $subtotal10 = (int)$row['subtotal_amount'];
        $tax10      = (int)$row['tax_amount'];
        if (($subtotal10 + $tax10) !== $totalAmount) {
            $subtotal10 = (int)floor($totalAmount / 1.10);
            $tax10      = $totalAmount - $subtotal10;
        }
    }

    // 13. payments INSERT
    $paymentId = generate_uuid();
    $receivedAmt = ($row['received_amount'] !== null && $row['received_amount'] !== '') ? (int)$row['received_amount'] : null;
    $changeAmt   = ($row['change_amount']   !== null && $row['change_amount']   !== '') ? (int)$row['change_amount']   : null;
    $orderIdsJson  = '[]';  // 手入力は常に空配列で INSERT (NOT NULL 制約は JSON 型で通る)
    $paidItemsJson = count($itemSnapshot) > 0 ? json_encode($itemSnapshot, JSON_UNESCAPED_UNICODE) : null;

    // external_method_type カラムを動的注入 (Phase 4d-2b 適用済みなら)
    $extMethodCol   = $hasPaymentsExtCol ? ', external_method_type' : '';
    $extMethodPh    = $hasPaymentsExtCol ? ', ?' : '';
    $extMethodParam = $hasPaymentsExtCol ? [$externalMethodTypeForPayments] : [];

    if ($hasMergedCols) {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, merged_table_ids, merged_session_ids, session_id,
                                   order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8,
                                   total_amount, payment_method' . $extMethodCol . ', received_amount, change_amount,
                                   is_partial, user_id, note, paid_at, created_at)
             VALUES (?, ?, NULL, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?' . $extMethodPh . ', ?, ?, 0, ?, ?, ?, NOW())'
        )->execute(array_merge(
            [$paymentId, $storeId,
             $orderIdsJson, $paidItemsJson,
             $subtotal10, $tax10, $subtotal8, $tax8,
             $totalAmount, $methodForPayments],
            $extMethodParam,
            [$receivedAmt, $changeAmt,
             $cashierUserIdForPayments, $paymentsNote, $paidAt]
        ));
    } else {
        $pdo->prepare(
            'INSERT INTO payments (id, store_id, table_id, session_id,
                                   order_ids, paid_items, subtotal_10, tax_10, subtotal_8, tax_8,
                                   total_amount, payment_method' . $extMethodCol . ', received_amount, change_amount,
                                   is_partial, user_id, note, paid_at, created_at)
             VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?' . $extMethodPh . ', ?, ?, 0, ?, ?, ?, NOW())'
        )->execute(array_merge(
            [$paymentId, $storeId,
             $orderIdsJson, $paidItemsJson,
             $subtotal10, $tax10, $subtotal8, $tax8,
             $totalAmount, $methodForPayments],
            $extMethodParam,
            [$receivedAmt, $changeAmt,
             $cashierUserIdForPayments, $paymentsNote, $paidAt]
        ));
    }

    // 14. cash の場合のみ cash_log.cash_sale INSERT
    //     created_at は転記時刻ではなく $paidAt (営業日保全)
    if ($methodForPayments === 'cash' && $totalAmount > 0) {
        $cashLogId = generate_uuid();
        $pdo->prepare(
            'INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $cashLogId, $storeId, $cashierUserIdForPayments, 'cash_sale', $totalAmount,
            '手入力売上計上', $paidAt
        ]);
    }

    // 15. emergency_payments の synced_payment_id / transferred_* を更新
    if ($hasTransferCols) {
        $pdo->prepare(
            'UPDATE emergency_payments
                SET synced_payment_id = ?,
                    transferred_by_user_id = ?,
                    transferred_by_name = ?,
                    transferred_at = NOW(),
                    transfer_note = ?,
                    updated_at = NOW()
              WHERE id = ? AND tenant_id = ? AND store_id = ?'
        )->execute([
            $paymentId, $transferByUserId, $transferByName, $transferNoteStored,
            $emergencyPaymentId, $tenantId, $storeId,
        ]);
    } else {
        $pdo->prepare(
            'UPDATE emergency_payments
                SET synced_payment_id = ?, updated_at = NOW()
              WHERE id = ? AND tenant_id = ? AND store_id = ?'
        )->execute([$paymentId, $emergencyPaymentId, $tenantId, $storeId]);
    }

    $pdo->commit();
    $inTx = false;

    // 監査ログ (存在すれば)
    if (function_exists('write_audit_log')) {
        @write_audit_log(
            $pdo, $user, $storeId,
            'emergency_manual_transfer', 'emergency_payment', $emergencyPaymentId,
            null,
            [
                'payment_id' => $paymentId,
                'payment_method' => $methodForPayments,
                'external_method_type' => $externalMethodTypeForPayments,
                'total_amount' => $totalAmount,
            ]
        );
    }

    json_response([
        'emergencyPaymentId' => $emergencyPaymentId,
        'paymentId'          => $paymentId,
        'transferred'        => true,
        'idempotent'         => false,
        'manual'             => true,
    ]);
} catch (PDOException $e) {
    if ($inTx) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        $inTx = false;
    }
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/emergency-payment-manual-transfer.php] db_error: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '手入力計上に失敗しました', 500);
}
