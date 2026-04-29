<?php
/**
 * レジ開け/締め API
 *
 * GET  /api/kds/cash-log.php?store_id=xxx    — 当日のログ
 * POST /api/kds/cash-log.php                 — エントリ追加
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';
require_once __DIR__ . '/../lib/audit-log.php';
require_once __DIR__ . '/../lib/register-close-reminder.php';
require_once __DIR__ . '/../lib/register-close-lock.php';

require_method(['GET', 'POST']);
$user = require_auth();
$pdo = get_db();

function encode_cash_log_close_json($value, $maxLen = 8000) {
    if ($value === null || $value === '') return null;
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($json === false) return null;
    if (strlen($json) > $maxLen) {
        if (function_exists('mb_substr')) return mb_substr($json, 0, $maxLen);
        return substr($json, 0, $maxLen);
    }
    return $json;
}

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $businessDay = get_business_day($pdo, $storeId);

    $stmt = $pdo->prepare(
        'SELECT cl.*, u.display_name AS user_name
         FROM cash_log cl
         LEFT JOIN users u ON u.id = cl.user_id
         WHERE cl.store_id = ? AND cl.created_at >= ? AND cl.created_at < ?
         ORDER BY cl.created_at ASC'
    );
    $stmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);
    $entries = $stmt->fetchAll();

    $hasVoidCols = false;
    try { $pdo->query('SELECT void_status FROM payments LIMIT 0'); $hasVoidCols = true; }
    catch (PDOException $e) {}

    $hasRefundCols = false;
    try { $pdo->query('SELECT refund_status FROM payments LIMIT 0'); $hasRefundCols = true; }
    catch (PDOException $e) {}

    $hasPaymentExternalNoteCol = false;
    try { $pdo->query('SELECT external_payment_note FROM payments LIMIT 0'); $hasPaymentExternalNoteCol = true; }
    catch (PDOException $e) {}

    $paymentSummary = ['cash' => 0, 'card' => 0, 'qr' => 0];
    try {
        $voidClause = $hasVoidCols ? " AND (void_status IS NULL OR void_status <> 'voided')" : '';
        $payStmt = $pdo->prepare(
            'SELECT payment_method, COALESCE(SUM(total_amount), 0) AS total
               FROM payments
              WHERE store_id = ?
                AND paid_at >= ? AND paid_at < ?' . $voidClause . '
              GROUP BY payment_method'
        );
        $payStmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);
        foreach ($payStmt->fetchAll() as $row) {
            $m = $row['payment_method'];
            if (isset($paymentSummary[$m])) $paymentSummary[$m] = (int)$row['total'];
        }
    } catch (PDOException $e) {}

    $activeOrderCount = 0;
    $activeOrderTotal = 0;
    try {
        $activeStmt = $pdo->prepare(
            'SELECT COUNT(*) AS order_count, COALESCE(SUM(total_amount), 0) AS total_amount
               FROM orders
              WHERE store_id = ?
                AND status NOT IN ("paid", "cancelled")'
        );
        $activeStmt->execute([$storeId]);
        $activeRow = $activeStmt->fetch();
        if ($activeRow) {
            $activeOrderCount = (int)$activeRow['order_count'];
            $activeOrderTotal = (int)$activeRow['total_amount'];
        }
    } catch (PDOException $e) {}

    $missingExternalNoteCount = 0;
    $missingExternalNoteTotal = 0;
    if ($hasPaymentExternalNoteCol) {
        try {
            $voidClause = $hasVoidCols ? " AND (void_status IS NULL OR void_status <> 'voided')" : '';
            $missingStmt = $pdo->prepare(
                'SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
                   FROM payments
                  WHERE store_id = ?
                    AND payment_method IN ("card", "qr")
                    AND paid_at >= ? AND paid_at < ?
                    AND (external_payment_note IS NULL OR external_payment_note = "")' . $voidClause
            );
            $missingStmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);
            $missingRow = $missingStmt->fetch();
            if ($missingRow) {
                $missingExternalNoteCount = (int)$missingRow['cnt'];
                $missingExternalNoteTotal = (int)$missingRow['total'];
            }
        } catch (PDOException $e) {}
    }

    $voidReviewCount = 0;
    $refundReviewCount = 0;
    try {
        if ($hasVoidCols) {
            $voidStmt = $pdo->prepare(
                'SELECT COUNT(*) AS cnt
                   FROM payments
                  WHERE store_id = ?
                    AND void_status = "voided"
                    AND voided_at >= ? AND voided_at < ?'
            );
            $voidStmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);
            $voidRow = $voidStmt->fetch();
            $voidReviewCount = $voidRow ? (int)$voidRow['cnt'] : 0;
        }
        if ($hasRefundCols) {
            $refundStmt = $pdo->prepare(
                'SELECT COUNT(*) AS cnt
                   FROM payments
                  WHERE store_id = ?
                    AND refund_status IS NOT NULL
                    AND refund_status <> "none"
                    AND refunded_at >= ? AND refunded_at < ?'
            );
            $refundStmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);
            $refundRow = $refundStmt->fetch();
            $refundReviewCount = $refundRow ? (int)$refundRow['cnt'] : 0;
        }
    } catch (PDOException $e) {}

    $warnings = [];
    if ($activeOrderCount > 0) {
        $warnings[] = [
            'level' => 'alert',
            'code' => 'active_orders',
            'message' => '未会計の注文が残っています',
            'count' => $activeOrderCount,
            'amount' => $activeOrderTotal,
        ];
    }
    if (($voidReviewCount + $refundReviewCount) > 0) {
        $warnings[] = [
            'level' => 'notice',
            'code' => 'adjustments',
            'message' => '会計取消・返金があります。理由と端末側処理を確認してください',
            'count' => $voidReviewCount + $refundReviewCount,
            'voidCount' => $voidReviewCount,
            'refundCount' => $refundReviewCount,
        ];
    }
    if ($missingExternalNoteCount > 0) {
        $warnings[] = [
            'level' => 'notice',
            'code' => 'missing_external_note',
            'message' => '外部決済の控えメモが未入力の取引があります',
            'count' => $missingExternalNoteCount,
            'amount' => $missingExternalNoteTotal,
        ];
    }

    $isRegisterOpenForReminder = false;
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        if ($entries[$i]['type'] === 'open') { $isRegisterOpenForReminder = true; break; }
        if ($entries[$i]['type'] === 'close') { $isRegisterOpenForReminder = false; break; }
    }
    $closeReminder = get_register_close_reminder($pdo, $storeId, $businessDay, $isRegisterOpenForReminder);
    if (!empty($closeReminder['isOverdue'])) {
        $warnings[] = [
            'level' => 'alert',
            'code' => 'register_close_overdue',
            'message' => 'レジ締め予定時刻を過ぎています',
            'count' => 1,
            'amount' => null,
            'dueAt' => $closeReminder['dueAt'] ?? null,
            'alertAt' => $closeReminder['alertAt'] ?? null,
        ];
    }

    $previousClose = null;
    try {
        $prevStmt = $pdo->prepare(
            'SELECT cl.*, u.display_name AS user_name
               FROM cash_log cl
               LEFT JOIN users u ON u.id = cl.user_id
              WHERE cl.store_id = ?
                AND cl.type = "close"
                AND cl.created_at < ?
              ORDER BY cl.created_at DESC
              LIMIT 1'
        );
        $prevStmt->execute([$storeId, $businessDay['start']]);
        $prev = $prevStmt->fetch();
        if ($prev) {
            $previousClose = [
                'createdAt' => $prev['created_at'],
                'userName' => $prev['user_name'] ?? null,
                'actualAmount' => isset($prev['amount']) ? (int)$prev['amount'] : null,
                'expectedAmount' => array_key_exists('expected_amount', $prev) && $prev['expected_amount'] !== null ? (int)$prev['expected_amount'] : null,
                'differenceAmount' => array_key_exists('difference_amount', $prev) && $prev['difference_amount'] !== null ? (int)$prev['difference_amount'] : null,
                'reconciliationNote' => array_key_exists('reconciliation_note', $prev) ? $prev['reconciliation_note'] : null,
                'handoverNote' => array_key_exists('handover_note', $prev) ? $prev['handover_note'] : null,
            ];
        }
    } catch (PDOException $e) {
        $previousClose = null;
    }

    json_response([
        'entries'     => $entries,
        'businessDay' => $businessDay['date'],
        'paymentSummary' => $paymentSummary,
        'previousClose' => $previousClose,
        'registerCloseReminder' => $closeReminder,
        'closeAssist' => [
            'activeOrderCount' => $activeOrderCount,
            'activeOrderTotal' => $activeOrderTotal,
            'missingExternalNoteCount' => $missingExternalNoteCount,
            'missingExternalNoteTotal' => $missingExternalNoteTotal,
            'adjustmentReviewCount' => $voidReviewCount + $refundReviewCount,
            'voidReviewCount' => $voidReviewCount,
            'refundReviewCount' => $refundReviewCount,
            'warnings' => $warnings,
        ],
    ]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId  = $data['store_id'] ?? null;
    $type     = $data['type'] ?? null;
    $amount   = isset($data['amount']) ? (int)$data['amount'] : 0;
    $note     = trim($data['note'] ?? '');
    $staffPin = isset($data['staff_pin']) ? trim((string)$data['staff_pin']) : '';
    $expectedAmount = isset($data['expected_amount']) ? (int)$data['expected_amount'] : null;
    $differenceAmount = isset($data['difference_amount']) ? (int)$data['difference_amount'] : null;
    $cashSalesAmount = isset($data['cash_sales_amount']) ? (int)$data['cash_sales_amount'] : null;
    $cardSalesAmount = isset($data['card_sales_amount']) ? (int)$data['card_sales_amount'] : null;
    $qrSalesAmount = isset($data['qr_sales_amount']) ? (int)$data['qr_sales_amount'] : null;
    $reconciliationNote = trim((string)($data['reconciliation_note'] ?? ''));
    $handoverNote = trim((string)($data['handover_note'] ?? ''));
    $cashDenominationJson = encode_cash_log_close_json($data['cash_denomination'] ?? null);
    $externalReconciliationJson = encode_cash_log_close_json($data['external_reconciliation'] ?? null);
    $closeCheckJson = encode_cash_log_close_json($data['close_check'] ?? null);
    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 200);
        $reconciliationNote = mb_substr($reconciliationNote, 0, 255);
        $handoverNote = mb_substr($handoverNote, 0, 255);
    } else {
        $note = substr($note, 0, 200);
        $reconciliationNote = substr($reconciliationNote, 0, 255);
        $handoverNote = substr($handoverNote, 0, 255);
    }

    if (!$storeId || !$type) json_error('MISSING_FIELDS', 'store_id と type は必須です', 400);
    require_store_access($storeId);

    // S2 P1 #7: cash_sale は process-payment.php から直接 INSERT される自動記録専用。
    // フロント API から偽装 INSERT できないように拒否する。
    $validTypes = ['open', 'close', 'cash_in', 'cash_out'];
    if (!in_array($type, $validTypes)) json_error('INVALID_TYPE', '無効な種別です', 400);

    $postCloseLock = require_register_close_override($pdo, $storeId, $data, 'レジ操作');
    if (!empty($postCloseLock['locked'])) {
        $suffix = ' 締め後理由: ' . ($postCloseLock['reason'] ?? '');
        $note = trim($note . $suffix);
        if (function_exists('mb_substr')) {
            $note = mb_substr($note, 0, 200);
        } else {
            $note = substr($note, 0, 200);
        }
    }

    // CB1d: 現金を扱う操作 (open/close/cash_in/cash_out) は担当スタッフ PIN 必須
    //   cash_sale は process-payment.php からの自動記録なので PIN 検証は不要
    $cashierUserId   = $user['user_id'];
    $cashierUserName = null;
    $pinVerified     = false;
    if ($type !== 'cash_sale') {
        if ($staffPin === '') {
            json_error('PIN_REQUIRED', 'レジ金庫の操作には担当スタッフ PIN の入力が必要です', 400);
        }
        if (!preg_match('/^\d{4,8}$/', $staffPin)) {
            json_error('INVALID_PIN', '担当 PIN は 4〜8 桁の数字で入力してください', 400);
        }
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
            @write_audit_log($pdo, $user, $storeId, 'cashier_pin_failed', 'cash_log', null, null,
                ['pin_length' => strlen($staffPin), 'context' => 'cash_log:' . $type], null);
            json_error('PIN_INVALID', 'PIN が一致しないか、対象スタッフが出勤中ではありません', 401);
        }
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$id, $storeId, $cashierUserId, $type, $amount, $note]);

    $hasReconciliationCols = false;
    try {
        $pdo->query('SELECT expected_amount, difference_amount, cash_sales_amount, card_sales_amount, qr_sales_amount, reconciliation_note FROM cash_log LIMIT 0');
        $hasReconciliationCols = true;
    } catch (PDOException $e) {}

    if ($type === 'close' && $hasReconciliationCols) {
        $reconcileStmt = $pdo->prepare(
            'UPDATE cash_log
                SET expected_amount = ?,
                    difference_amount = ?,
                    cash_sales_amount = ?,
                    card_sales_amount = ?,
                    qr_sales_amount = ?,
                    reconciliation_note = ?
              WHERE id = ? AND store_id = ?'
        );
        $reconcileStmt->execute([
            $expectedAmount,
            $differenceAmount,
            $cashSalesAmount,
            $cardSalesAmount,
            $qrSalesAmount,
            $reconciliationNote !== '' ? $reconciliationNote : null,
            $id,
            $storeId,
        ]);
    }

    $hasHandoverCol = false;
    try {
        $pdo->query('SELECT handover_note FROM cash_log LIMIT 0');
        $hasHandoverCol = true;
    } catch (PDOException $e) {}

    if ($type === 'close' && $hasHandoverCol) {
        $handoverStmt = $pdo->prepare(
            'UPDATE cash_log
                SET handover_note = ?
              WHERE id = ? AND store_id = ?'
        );
        $handoverStmt->execute([
            $handoverNote !== '' ? $handoverNote : null,
            $id,
            $storeId,
        ]);
    }

    $hasCloseAssistCols = false;
    try {
        $pdo->query('SELECT cash_denomination_json, external_reconciliation_json, close_check_json FROM cash_log LIMIT 0');
        $hasCloseAssistCols = true;
    } catch (PDOException $e) {}

    if (($type === 'open' || $type === 'close') && $hasCloseAssistCols) {
        $assistStmt = $pdo->prepare(
            'UPDATE cash_log
                SET cash_denomination_json = ?,
                    external_reconciliation_json = ?,
                    close_check_json = ?
              WHERE id = ? AND store_id = ?'
        );
        $assistStmt->execute([
            $cashDenominationJson,
            $type === 'close' ? $externalReconciliationJson : null,
            $type === 'close' ? $closeCheckJson : null,
            $id,
            $storeId,
        ]);
    }

    $resolvedPreCloseCount = 0;
    if ($type === 'close' && $differenceAmount !== null && (int)$differenceAmount === 0) {
        try {
            $businessDay = get_business_day($pdo, $storeId);
            $hasPreCloseResolutionCols = false;
            try {
                $pdo->query('SELECT resolved_by, resolved_at, resolution_note FROM register_pre_close_logs LIMIT 0');
                $hasPreCloseResolutionCols = true;
            } catch (PDOException $e) {}
            if ($hasPreCloseResolutionCols) {
                $resolveStmt = $pdo->prepare(
                    'UPDATE register_pre_close_logs
                        SET status = "resolved",
                            resolved_by = ?,
                            resolved_at = NOW(),
                            resolution_note = COALESCE(resolution_note, "本締め差額0円のため自動解決")
                      WHERE tenant_id = ?
                        AND store_id = ?
                        AND business_day = ?
                        AND status = "open"'
                );
                $resolveStmt->execute([$cashierUserId, $user['tenant_id'], $storeId, $businessDay['date']]);
            } else {
                $resolveStmt = $pdo->prepare(
                    'UPDATE register_pre_close_logs
                        SET status = "resolved"
                      WHERE tenant_id = ?
                        AND store_id = ?
                        AND business_day = ?
                        AND status = "open"'
                );
                $resolveStmt->execute([$user['tenant_id'], $storeId, $businessDay['date']]);
            }
            $resolvedPreCloseCount = (int)$resolveStmt->rowCount();
        } catch (PDOException $e) {
            $resolvedPreCloseCount = 0;
        }
    }

    // 監査ログ（cash_sale は process-payment.php 側で payment_complete として記録されるので除外）
    if ($type !== 'cash_sale') {
        $actionMap = [
            'open'     => 'register_open',
            'close'    => 'register_close',
            'cash_in'  => 'cash_in',
            'cash_out' => 'cash_out',
        ];
        if (isset($actionMap[$type])) {
            $auditPayload = [
                'amount'            => $amount,
                'note'              => $note,
                'type'              => $type,
                'cashier_user_id'   => $cashierUserId,
                'cashier_user_name' => $cashierUserName,
                'pin_verified'      => 1,
            ];
            if ($type === 'open') {
                $auditPayload['cash_denomination'] = $data['cash_denomination'] ?? null;
            }
            if ($type === 'close') {
                $auditPayload['expected_amount'] = $expectedAmount;
                $auditPayload['difference_amount'] = $differenceAmount;
                $auditPayload['cash_sales_amount'] = $cashSalesAmount;
                $auditPayload['card_sales_amount'] = $cardSalesAmount;
                $auditPayload['qr_sales_amount'] = $qrSalesAmount;
                $auditPayload['reconciliation_note'] = $reconciliationNote;
                $auditPayload['handover_note'] = $handoverNote;
                $auditPayload['cash_denomination'] = $data['cash_denomination'] ?? null;
                $auditPayload['external_reconciliation'] = $data['external_reconciliation'] ?? null;
                $auditPayload['close_check'] = $data['close_check'] ?? null;
                $auditPayload['resolved_pre_close_count'] = $resolvedPreCloseCount;
            }
            $auditPayload['register_close_lock'] = register_close_override_audit_payload($postCloseLock);
            @write_audit_log($pdo, $user, $storeId, $actionMap[$type], 'cash_log', $id, null, $auditPayload, $note ?: null);
        }
    }

    json_response([
        'ok' => true,
        'id' => $id,
        'cashierUserId'   => $cashierUserId,
        'cashierUserName' => $cashierUserName,
        'expectedAmount' => $expectedAmount,
        'differenceAmount' => $differenceAmount,
        'resolvedPreCloseCount' => $resolvedPreCloseCount,
        'cashDenomination' => $data['cash_denomination'] ?? null,
        'externalReconciliation' => $data['external_reconciliation'] ?? null,
        'postCloseOverride' => !empty($postCloseLock['locked']),
    ], 201);
}
