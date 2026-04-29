<?php
/**
 * レジ分析 API
 *
 * GET /api/store/register-report.php?store_id=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';
require_once __DIR__ . '/../lib/register-close-reminder.php';

require_method(['GET']);
$user = require_role('manager');
$pdo = get_db();

$storeId = require_store_param();
require_store_access($storeId);

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$range = get_business_day_range($pdo, $storeId, $from, $to);

// 支払方法別集計
// Phase 4d-5c-a (2026-04-21): 注文に紐づく全 payments が voided な orders を集計から除外する。
//   有効判定: void_status IS NULL OR void_status <> 'voided'。
//   payments 未紐付 orders は除外しない (緊急会計外の通常運用を壊さない)。
//   分割会計の「一部 voided」は active が 1 件残れば集計に残る。
//   migration-pwa4d5a 未適用環境ではフォールバック (条件無し) で従来挙動。
$hasPaymentVoidColForReport = false;
try { $pdo->query('SELECT void_status FROM payments LIMIT 0'); $hasPaymentVoidColForReport = true; }
catch (PDOException $e) {}
$voidExcludeForOrders = $hasPaymentVoidColForReport
  ? ' AND NOT (EXISTS (SELECT 1 FROM payments p_any
                         WHERE p_any.store_id = o.store_id
                           AND JSON_CONTAINS(p_any.order_ids, JSON_QUOTE(o.id)))
              AND NOT EXISTS (SELECT 1 FROM payments p_act
                               WHERE p_act.store_id = o.store_id
                                 AND JSON_CONTAINS(p_act.order_ids, JSON_QUOTE(o.id))
                                 AND (p_act.void_status IS NULL OR p_act.void_status <> \'voided\')))'
  : '';

$stmt = $pdo->prepare(
    'SELECT o.payment_method, COUNT(*) AS count, COALESCE(SUM(o.total_amount), 0) AS total
     FROM orders o
     WHERE o.store_id = ? AND o.status = ? AND o.created_at >= ? AND o.created_at < ?' . $voidExcludeForOrders . '
     GROUP BY o.payment_method'
);
$stmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
$paymentBreakdown = $stmt->fetchAll();

$hasPaymentDetailColForReport = false;
try { $pdo->query('SELECT payment_method_detail FROM payments LIMIT 0'); $hasPaymentDetailColForReport = true; }
catch (PDOException $e) {}

$hasPaymentExternalNoteColForReport = false;
try { $pdo->query('SELECT external_payment_note FROM payments LIMIT 0'); $hasPaymentExternalNoteColForReport = true; }
catch (PDOException $e) {}

$hasPaymentNoteColForReport = false;
try { $pdo->query('SELECT note FROM payments LIMIT 0'); $hasPaymentNoteColForReport = true; }
catch (PDOException $e) {}

$hasGatewayColsForReport = false;
try { $pdo->query('SELECT gateway_name FROM payments LIMIT 0'); $hasGatewayColsForReport = true; }
catch (PDOException $e) {}

// Phase 4d-3: 外部分類別集計 (payments.external_method_type ベース)
//   Phase 4d-2b で追加した payments.external_method_type を使い、緊急会計転記分の
//   voucher / bank_transfer / accounts_receivable / point / other を内訳として別返却する。
//   既存 paymentBreakdown (orders 基準) には other_external 転記分が 'qr' に混ざるため、
//   本内訳で補完表示する (UI は register-report.js 側で新セクションとして描画)。
//
// Phase 4d-5a: void_status='voided' の payments は externalMethodBreakdown から除外する。
//   手入力 cash は cash_log.cash_out で相殺されるため期待残高には反映済み。
//   外部分類集計 (voucher 等) は void 後に件数/金額を戻すため WHERE 条件で除外する。
//   migration-pwa4d5a 未適用時は void_status カラムが存在しないので動的に除外を切る。
$hasVoidColsForReport = false;
try { $pdo->query('SELECT void_status FROM payments LIMIT 0'); $hasVoidColsForReport = true; }
catch (PDOException $e) {}

$hasRefundColsForReport = false;
try { $pdo->query('SELECT refund_status FROM payments LIMIT 0'); $hasRefundColsForReport = true; }
catch (PDOException $e) {}

$paymentTransactions = [];
try {
    $detailSelect = $hasPaymentDetailColForReport ? ', p.payment_method_detail' : ', NULL AS payment_method_detail';
    $noteSelect = $hasPaymentExternalNoteColForReport ? ', p.external_payment_note' : ', NULL AS external_payment_note';
    $paymentNoteSelect = $hasPaymentNoteColForReport ? ', p.note AS payment_note' : ', NULL AS payment_note';
    $gatewaySelect = $hasGatewayColsForReport ? ', p.gateway_name, p.external_payment_id, p.gateway_status' : ', NULL AS gateway_name, NULL AS external_payment_id, NULL AS gateway_status';
    $refundSelect = $hasRefundColsForReport
        ? ', p.refund_status, p.refund_amount, p.refunded_at'
        : ", 'none' AS refund_status, 0 AS refund_amount, NULL AS refunded_at";
    $voidSelect = $hasVoidColsForReport
        ? ', p.void_status, p.voided_at, p.void_reason'
        : ", 'active' AS void_status, NULL AS voided_at, NULL AS void_reason";
    $txStmt = $pdo->prepare(
        'SELECT p.id, p.table_id, t.table_code, p.total_amount, p.payment_method,
                p.received_amount, p.change_amount, p.is_partial, p.paid_at,
                u.display_name AS staff_name' . $detailSelect . $noteSelect . $paymentNoteSelect . $gatewaySelect . $refundSelect . $voidSelect . '
           FROM payments p
           LEFT JOIN users u ON u.id = p.user_id
           LEFT JOIN tables t ON t.id = p.table_id
          WHERE p.store_id = ?
            AND p.paid_at >= ? AND p.paid_at < ?
          ORDER BY p.paid_at DESC'
    );
    $txStmt->execute([$storeId, $range['start'], $range['end']]);
    $paymentTransactions = $txStmt->fetchAll();
} catch (PDOException $e) {
    $paymentTransactions = [];
}

$paymentDetailBreakdown = [];
if ($hasPaymentDetailColForReport) {
    try {
        $voidClause = $hasVoidColsForReport ? " AND void_status <> 'voided'" : '';
        $detailStmt = $pdo->prepare(
            'SELECT payment_method, payment_method_detail, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
               FROM payments
              WHERE store_id = ?
                AND payment_method_detail IS NOT NULL
                AND paid_at >= ? AND paid_at < ?' . $voidClause . '
              GROUP BY payment_method, payment_method_detail
              ORDER BY payment_method, total DESC'
        );
        $detailStmt->execute([$storeId, $range['start'], $range['end']]);
        $paymentDetailBreakdown = $detailStmt->fetchAll();
    } catch (PDOException $e) {
        $paymentDetailBreakdown = [];
    }
}

$externalMethodBreakdown = [];
try {
    $voidClause = $hasVoidColsForReport ? " AND void_status <> 'voided'" : '';
    $extStmt = $pdo->prepare(
        'SELECT external_method_type, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
           FROM payments
          WHERE store_id = ?
            AND external_method_type IS NOT NULL
            AND paid_at >= ? AND paid_at < ?' . $voidClause . '
          GROUP BY external_method_type'
    );
    $extStmt->execute([$storeId, $range['start'], $range['end']]);
    $externalMethodBreakdown = $extStmt->fetchAll();
} catch (PDOException $e) {
    // migration-pwa4d2b 未適用環境では external_method_type カラムがないので silent 空返却
    $externalMethodBreakdown = [];
}

$paymentAdjustments = [];
if ($hasVoidColsForReport || $hasRefundColsForReport) {
    $adjustConditions = [];
    $adjustParams = [$storeId];
    if ($hasVoidColsForReport) {
        $adjustConditions[] = "(p.void_status = 'voided' AND p.voided_at >= ? AND p.voided_at < ?)";
        $adjustParams[] = $range['start'];
        $adjustParams[] = $range['end'];
    }
    if ($hasRefundColsForReport) {
        $adjustConditions[] = "(p.refund_status IS NOT NULL AND p.refund_status <> 'none' AND p.refunded_at >= ? AND p.refunded_at < ?)";
        $adjustParams[] = $range['start'];
        $adjustParams[] = $range['end'];
    }
    if (!empty($adjustConditions)) {
        try {
            $detailSelect = $hasPaymentDetailColForReport ? ', p.payment_method_detail' : ", NULL AS payment_method_detail";
            $noteSelect = $hasPaymentExternalNoteColForReport ? ', p.external_payment_note' : ', NULL AS external_payment_note';
            $gatewaySelect = $hasGatewayColsForReport ? ', p.gateway_name' : ', NULL AS gateway_name';
            $refundSelect = $hasRefundColsForReport
                ? ', p.refund_status, p.refund_amount, p.refund_reason, p.refunded_at, refund_user.display_name AS refunded_by_name'
                : ", 'none' AS refund_status, 0 AS refund_amount, NULL AS refund_reason, NULL AS refunded_at, NULL AS refunded_by_name";
            $voidSelect = $hasVoidColsForReport
                ? ', p.void_status, p.voided_at, p.void_reason, void_user.display_name AS voided_by_name'
                : ", 'active' AS void_status, NULL AS voided_at, NULL AS void_reason, NULL AS voided_by_name";
            $refundJoin = $hasRefundColsForReport ? ' LEFT JOIN users refund_user ON refund_user.id = p.refunded_by' : '';
            $voidJoin = $hasVoidColsForReport ? ' LEFT JOIN users void_user ON void_user.id = p.voided_by' : '';
            $adjustOrderExpr = 'p.paid_at';
            if ($hasVoidColsForReport && $hasRefundColsForReport) {
                $adjustOrderExpr = 'COALESCE(p.voided_at, p.refunded_at, p.paid_at)';
            } elseif ($hasVoidColsForReport) {
                $adjustOrderExpr = 'COALESCE(p.voided_at, p.paid_at)';
            } elseif ($hasRefundColsForReport) {
                $adjustOrderExpr = 'COALESCE(p.refunded_at, p.paid_at)';
            }
            $adjustStmt = $pdo->prepare(
                'SELECT p.id, p.table_id, t.table_code, p.total_amount, p.payment_method' . $detailSelect . $noteSelect . ',
                        p.paid_at' . $gatewaySelect . $refundSelect . $voidSelect . '
                   FROM payments p
                   LEFT JOIN tables t ON t.id = p.table_id' . $refundJoin . $voidJoin . '
                  WHERE p.store_id = ?
                    AND (' . implode(' OR ', $adjustConditions) . ')
                  ORDER BY ' . $adjustOrderExpr . ' DESC'
            );
            $adjustStmt->execute($adjustParams);
            $adjustRows = $adjustStmt->fetchAll();
            foreach ($adjustRows as $row) {
                if (isset($row['void_status']) && $row['void_status'] === 'voided') {
                    $paymentAdjustments[] = [
                        'type' => 'void',
                        'paymentId' => $row['id'],
                        'tableCode' => $row['table_code'] ?? null,
                        'amount' => (int)$row['total_amount'],
                        'paymentMethod' => $row['payment_method'],
                        'paymentMethodDetail' => $row['payment_method_detail'] ?? null,
                        'externalPaymentNote' => $row['external_payment_note'] ?? null,
                        'gatewayName' => $row['gateway_name'] ?? null,
                        'paidAt' => $row['paid_at'],
                        'adjustedAt' => $row['voided_at'],
                        'reason' => $row['void_reason'] ?? null,
                        'userName' => $row['voided_by_name'] ?? null,
                    ];
                }
                $refundStatus = isset($row['refund_status']) ? (string)$row['refund_status'] : 'none';
                if ($refundStatus !== 'none' && $refundStatus !== '' && $row['refunded_at'] !== null) {
                    $refundAmount = isset($row['refund_amount']) ? (int)$row['refund_amount'] : 0;
                    if ($refundAmount <= 0) $refundAmount = (int)$row['total_amount'];
                    $paymentAdjustments[] = [
                        'type' => 'refund',
                        'paymentId' => $row['id'],
                        'tableCode' => $row['table_code'] ?? null,
                        'amount' => $refundAmount,
                        'paymentMethod' => $row['payment_method'],
                        'paymentMethodDetail' => $row['payment_method_detail'] ?? null,
                        'externalPaymentNote' => $row['external_payment_note'] ?? null,
                        'gatewayName' => $row['gateway_name'] ?? null,
                        'paidAt' => $row['paid_at'],
                        'adjustedAt' => $row['refunded_at'],
                        'reason' => $row['refund_reason'] ?? null,
                        'userName' => $row['refunded_by_name'] ?? null,
                        'refundStatus' => $refundStatus,
                    ];
                }
            }
            usort($paymentAdjustments, function ($a, $b) {
                return strcmp((string)($b['adjustedAt'] ?? ''), (string)($a['adjustedAt'] ?? ''));
            });
        } catch (PDOException $e) {
            $paymentAdjustments = [];
        }
    }
}

// cash_logエントリ
$stmt = $pdo->prepare(
    'SELECT cl.*, u.display_name AS user_name
     FROM cash_log cl LEFT JOIN users u ON u.id = cl.user_id
     WHERE cl.store_id = ? AND cl.created_at >= ? AND cl.created_at < ?
     ORDER BY cl.created_at'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
$cashLog = $stmt->fetchAll();

// レジ残高計算
$openAmount = 0;
$cashIn = 0;
$cashOut = 0;
$cashSales = 0;
foreach ($cashLog as $entry) {
    switch ($entry['type']) {
        case 'open': $openAmount = (int)$entry['amount']; break;
        case 'cash_in': $cashIn += (int)$entry['amount']; break;
        case 'cash_out': $cashOut += (int)$entry['amount']; break;
        case 'cash_sale': $cashSales += (int)$entry['amount']; break;
    }
}
$expectedBalance = $openAmount + $cashSales + $cashIn - $cashOut;

// close時の実際の金額を探す
$closeAmount = null;
foreach ($cashLog as $entry) {
    if ($entry['type'] === 'close') $closeAmount = (int)$entry['amount'];
}

$overshort = $closeAmount !== null ? $closeAmount - $expectedBalance : null;

$nullableInt = function ($row, $key) {
    return array_key_exists($key, $row) && $row[$key] !== null ? (int)$row[$key] : null;
};

$jsonColumn = function ($row, $key) {
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') return null;
    $decoded = json_decode($row[$key], true);
    return is_array($decoded) ? $decoded : null;
};

$closeReconciliations = [];
foreach ($cashLog as $entry) {
    if ($entry['type'] !== 'close') continue;

    $expectedAtClose = $nullableInt($entry, 'expected_amount');
    $differenceAtClose = $nullableInt($entry, 'difference_amount');
    if ($expectedAtClose === null && $entry['amount'] !== null) {
        $expectedAtClose = $expectedBalance;
    }
    if ($differenceAtClose === null && $expectedAtClose !== null) {
        $differenceAtClose = (int)$entry['amount'] - $expectedAtClose;
    }

    $closeReconciliations[] = [
        'createdAt'          => $entry['created_at'],
        'userName'           => $entry['user_name'] ?? null,
        'actualAmount'       => (int)$entry['amount'],
        'expectedAmount'     => $expectedAtClose,
        'differenceAmount'   => $differenceAtClose,
        'cashSalesAmount'    => $nullableInt($entry, 'cash_sales_amount'),
        'cardSalesAmount'    => $nullableInt($entry, 'card_sales_amount'),
        'qrSalesAmount'      => $nullableInt($entry, 'qr_sales_amount'),
        'reconciliationNote' => array_key_exists('reconciliation_note', $entry) ? $entry['reconciliation_note'] : null,
        'handoverNote'       => array_key_exists('handover_note', $entry) ? $entry['handover_note'] : null,
        'cashDenomination'   => $jsonColumn($entry, 'cash_denomination_json'),
        'externalReconciliation' => $jsonColumn($entry, 'external_reconciliation_json'),
        'closeCheck'         => $jsonColumn($entry, 'close_check_json'),
        'note'               => $entry['note'] ?? null,
    ];
}
$latestCloseReconciliation = !empty($closeReconciliations)
    ? $closeReconciliations[count($closeReconciliations) - 1]
    : null;

$hasPreCloseResolutionCols = false;
try {
    $pdo->query('SELECT resolved_by, resolved_at, resolution_note FROM register_pre_close_logs LIMIT 0');
    $hasPreCloseResolutionCols = true;
} catch (PDOException $e) {}

$preCloseLogs = [];
try {
    $preCloseResolveSelect = $hasPreCloseResolutionCols
        ? ', l.resolved_by, l.resolved_at, l.resolution_note, ru.display_name AS resolved_by_name'
        : ', NULL AS resolved_by, NULL AS resolved_at, NULL AS resolution_note, NULL AS resolved_by_name';
    $preCloseResolveJoin = $hasPreCloseResolutionCols
        ? ' LEFT JOIN users ru ON ru.id = l.resolved_by AND ru.tenant_id = l.tenant_id'
        : '';
    $preCloseStmt = $pdo->prepare(
        'SELECT l.*, u.display_name AS user_name' . $preCloseResolveSelect . '
           FROM register_pre_close_logs l
           LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id' . $preCloseResolveJoin . '
          WHERE l.tenant_id = ?
            AND l.store_id = ?
            AND l.created_at >= ? AND l.created_at < ?
          ORDER BY l.created_at DESC'
    );
    $preCloseStmt->execute([$user['tenant_id'], $storeId, $range['start'], $range['end']]);
    foreach ($preCloseStmt->fetchAll() as $row) {
        $preCloseLogs[] = [
            'id' => $row['id'],
            'businessDay' => $row['business_day'],
            'createdAt' => $row['created_at'],
            'userName' => $row['user_name'] ?? null,
            'actualCashAmount' => $nullableInt($row, 'actual_cash_amount'),
            'expectedCashAmount' => $nullableInt($row, 'expected_cash_amount'),
            'differenceAmount' => $nullableInt($row, 'difference_amount'),
            'cashSalesAmount' => $nullableInt($row, 'cash_sales_amount'),
            'cardSalesAmount' => $nullableInt($row, 'card_sales_amount'),
            'qrSalesAmount' => $nullableInt($row, 'qr_sales_amount'),
            'reconciliationNote' => $row['reconciliation_note'] ?? null,
            'handoverNote' => $row['handover_note'] ?? null,
            'cashDenomination' => $jsonColumn($row, 'cash_denomination_json'),
            'externalReconciliation' => $jsonColumn($row, 'external_reconciliation_json'),
            'closeCheck' => $jsonColumn($row, 'close_check_json'),
            'closeAssist' => $jsonColumn($row, 'close_assist_json'),
            'status' => $row['status'] ?? 'open',
            'resolvedAt' => $row['resolved_at'] ?? null,
            'resolvedByName' => $row['resolved_by_name'] ?? null,
            'resolutionNote' => $row['resolution_note'] ?? null,
        ];
    }
} catch (PDOException $e) {
    $preCloseLogs = [];
}

$unresolvedPreCloseLogs = [];
try {
    $preCloseResolveSelect = $hasPreCloseResolutionCols
        ? ', l.resolved_by, l.resolved_at, l.resolution_note, ru.display_name AS resolved_by_name'
        : ', NULL AS resolved_by, NULL AS resolved_at, NULL AS resolution_note, NULL AS resolved_by_name';
    $preCloseResolveJoin = $hasPreCloseResolutionCols
        ? ' LEFT JOIN users ru ON ru.id = l.resolved_by AND ru.tenant_id = l.tenant_id'
        : '';
    $unresolvedStmt = $pdo->prepare(
        'SELECT l.*, u.display_name AS user_name' . $preCloseResolveSelect . '
           FROM register_pre_close_logs l
           LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id' . $preCloseResolveJoin . '
          WHERE l.tenant_id = ?
            AND l.store_id = ?
            AND l.status = "open"
            AND l.difference_amount IS NOT NULL
            AND l.difference_amount <> 0
            AND l.created_at < ?
          ORDER BY l.business_day DESC, l.created_at DESC
          LIMIT 20'
    );
    $unresolvedStmt->execute([$user['tenant_id'], $storeId, $range['end']]);
    foreach ($unresolvedStmt->fetchAll() as $row) {
        $unresolvedPreCloseLogs[] = [
            'id' => $row['id'],
            'businessDay' => $row['business_day'],
            'createdAt' => $row['created_at'],
            'userName' => $row['user_name'] ?? null,
            'actualCashAmount' => $nullableInt($row, 'actual_cash_amount'),
            'expectedCashAmount' => $nullableInt($row, 'expected_cash_amount'),
            'differenceAmount' => $nullableInt($row, 'difference_amount'),
            'cashSalesAmount' => $nullableInt($row, 'cash_sales_amount'),
            'cardSalesAmount' => $nullableInt($row, 'card_sales_amount'),
            'qrSalesAmount' => $nullableInt($row, 'qr_sales_amount'),
            'reconciliationNote' => $row['reconciliation_note'] ?? null,
            'handoverNote' => $row['handover_note'] ?? null,
            'cashDenomination' => $jsonColumn($row, 'cash_denomination_json'),
            'externalReconciliation' => $jsonColumn($row, 'external_reconciliation_json'),
            'closeCheck' => $jsonColumn($row, 'close_check_json'),
            'closeAssist' => $jsonColumn($row, 'close_assist_json'),
            'status' => $row['status'] ?? 'open',
            'resolvedAt' => $row['resolved_at'] ?? null,
            'resolvedByName' => $row['resolved_by_name'] ?? null,
            'resolutionNote' => $row['resolution_note'] ?? null,
        ];
    }
} catch (PDOException $e) {
    $unresolvedPreCloseLogs = [];
}

$hasOpen = false;
$hasClose = false;
$latestOpenAt = null;
$latestCloseAt = null;
$lastRegisterAction = null;
$lastRegisterActionAt = null;
$lastRegisterActionUserName = null;
foreach ($cashLog as $entry) {
    if ($entry['type'] === 'open') {
        $hasOpen = true;
        $latestOpenAt = $entry['created_at'];
        $lastRegisterAction = 'open';
        $lastRegisterActionAt = $entry['created_at'];
        $lastRegisterActionUserName = $entry['user_name'] ?? null;
    } elseif ($entry['type'] === 'close') {
        $hasClose = true;
        $latestCloseAt = $entry['created_at'];
        $lastRegisterAction = 'close';
        $lastRegisterActionAt = $entry['created_at'];
        $lastRegisterActionUserName = $entry['user_name'] ?? null;
    }
}
$isRegisterOpen = $lastRegisterAction === 'open';
$needsClose = $hasOpen && $isRegisterOpen;
$targetBusinessDay = get_business_day($pdo, $storeId, $to);
$closeReminder = get_register_close_reminder($pdo, $storeId, $targetBusinessDay, $isRegisterOpen);
$latestDifference = $latestCloseReconciliation
    ? $latestCloseReconciliation['differenceAmount']
    : $overshort;
$overshortLevel = null;
if ($latestDifference !== null) {
    $absDiff = abs((int)$latestDifference);
    if ($absDiff >= 500) {
        $overshortLevel = 'alert';
    } elseif ($absDiff > 0) {
        $overshortLevel = 'notice';
    } else {
        $overshortLevel = 'ok';
    }
}

$postCloseTransactions = [];
$postCloseAdjustments = [];
$postClosePaymentTotal = 0;
if ($latestCloseAt !== null) {
    foreach ($paymentTransactions as $tx) {
        $paidAt = (string)($tx['paid_at'] ?? '');
        if ($paidAt !== '' && $paidAt > $latestCloseAt) {
            $postCloseTransactions[] = $tx;
            $postClosePaymentTotal += isset($tx['total_amount']) ? (int)$tx['total_amount'] : 0;
        }
    }
    foreach ($paymentAdjustments as $adj) {
        $adjustedAt = (string)($adj['adjustedAt'] ?? '');
        if ($adjustedAt !== '' && $adjustedAt > $latestCloseAt) {
            $postCloseAdjustments[] = $adj;
        }
    }
}

$activeOrderCount = 0;
$activeOrderTotal = 0;
$activeTableCount = 0;
try {
    $activeStmt = $pdo->prepare(
        'SELECT COUNT(*) AS order_count,
                COALESCE(SUM(total_amount), 0) AS total_amount,
                COUNT(DISTINCT table_id) AS table_count
           FROM orders
          WHERE store_id = ?
            AND status NOT IN ("paid", "cancelled")'
    );
    $activeStmt->execute([$storeId]);
    $activeRow = $activeStmt->fetch();
    if ($activeRow) {
        $activeOrderCount = (int)$activeRow['order_count'];
        $activeOrderTotal = (int)$activeRow['total_amount'];
        $activeTableCount = (int)$activeRow['table_count'];
    }
} catch (PDOException $e) {}

$missingExternalNoteCount = 0;
$missingExternalNoteTotal = 0;
if ($hasPaymentExternalNoteColForReport) {
    try {
        $voidClause = $hasVoidColsForReport ? " AND (void_status IS NULL OR void_status <> 'voided')" : '';
        $missingStmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
               FROM payments
              WHERE store_id = ?
                AND payment_method IN ("card", "qr")
                AND paid_at >= ? AND paid_at < ?
                AND (external_payment_note IS NULL OR external_payment_note = "")' . $voidClause
        );
        $missingStmt->execute([$storeId, $range['start'], $range['end']]);
        $missingRow = $missingStmt->fetch();
        if ($missingRow) {
            $missingExternalNoteCount = (int)$missingRow['cnt'];
            $missingExternalNoteTotal = (int)$missingRow['total'];
        }
    } catch (PDOException $e) {}
}

$adjustmentReviewCount = count($paymentAdjustments);
$voidReviewCount = 0;
$refundReviewCount = 0;
foreach ($paymentAdjustments as $adj) {
    if (($adj['type'] ?? '') === 'void') $voidReviewCount++;
    if (($adj['type'] ?? '') === 'refund') $refundReviewCount++;
}

$closeAssistWarnings = [];
if ($activeOrderCount > 0) {
    $closeAssistWarnings[] = [
        'level' => 'alert',
        'code' => 'active_orders',
        'message' => '未会計の注文が残っています',
        'count' => $activeOrderCount,
        'amount' => $activeOrderTotal,
    ];
}
if ($adjustmentReviewCount > 0) {
    $closeAssistWarnings[] = [
        'level' => 'notice',
        'code' => 'adjustments',
        'message' => '会計取消・返金があります。理由と端末側処理を確認してください',
        'count' => $adjustmentReviewCount,
        'voidCount' => $voidReviewCount,
        'refundCount' => $refundReviewCount,
    ];
}
if ($missingExternalNoteCount > 0) {
    $closeAssistWarnings[] = [
        'level' => 'notice',
        'code' => 'missing_external_note',
        'message' => '外部決済の控えメモが未入力の取引があります',
        'count' => $missingExternalNoteCount,
        'amount' => $missingExternalNoteTotal,
    ];
}
if ($latestDifference !== null && (int)$latestDifference !== 0) {
    $closeAssistWarnings[] = [
        'level' => abs((int)$latestDifference) >= 500 ? 'alert' : 'notice',
        'code' => 'cash_difference',
        'message' => 'レジ締め差額があります',
        'amount' => (int)$latestDifference,
    ];
}
if (!empty($unresolvedPreCloseLogs)) {
    $unresolvedTotal = 0;
    foreach ($unresolvedPreCloseLogs as $log) {
        $unresolvedTotal += isset($log['differenceAmount']) ? (int)$log['differenceAmount'] : 0;
    }
    $closeAssistWarnings[] = [
        'level' => 'alert',
        'code' => 'unresolved_pre_close',
        'message' => '未解決の仮締め差額があります',
        'count' => count($unresolvedPreCloseLogs),
        'amount' => $unresolvedTotal,
    ];
}
if (!empty($postCloseTransactions) || !empty($postCloseAdjustments)) {
    $closeAssistWarnings[] = [
        'level' => 'alert',
        'code' => 'post_close_activity',
        'message' => 'レジ締め後に会計・取消・返金が発生しています',
        'count' => count($postCloseTransactions) + count($postCloseAdjustments),
        'amount' => $postClosePaymentTotal,
    ];
}
if (!empty($closeReminder['isOverdue'])) {
    $closeAssistWarnings[] = [
        'level' => 'alert',
        'code' => 'register_close_overdue',
        'message' => 'レジ締め予定時刻を過ぎています',
        'count' => 1,
        'amount' => null,
        'dueAt' => $closeReminder['dueAt'] ?? null,
        'alertAt' => $closeReminder['alertAt'] ?? null,
    ];
}

$receiptReprints = [];
try {
    $pdo->query('SELECT 1 FROM audit_log LIMIT 0');
    $reprintStmt = $pdo->prepare(
        'SELECT al.created_at, al.user_id, al.username, al.reason,
                u.display_name AS user_name,
                r.id AS receipt_id, r.receipt_number, r.receipt_type,
                r.total_amount, r.payment_id
           FROM audit_log al
           LEFT JOIN users u ON u.id = al.user_id
           LEFT JOIN receipts r ON r.id = al.entity_id AND r.store_id = al.store_id
          WHERE al.tenant_id = ?
            AND al.store_id = ?
            AND al.action = "receipt_reprint"
            AND al.entity_type = "receipt"
            AND al.created_at >= ? AND al.created_at < ?
          ORDER BY al.created_at DESC'
    );
    $reprintStmt->execute([$user['tenant_id'], $storeId, $range['start'], $range['end']]);
    $receiptReprints = $reprintStmt->fetchAll();
} catch (PDOException $e) {
    $receiptReprints = [];
}

json_response([
    'paymentBreakdown' => $paymentBreakdown,
    'paymentDetailBreakdown' => $paymentDetailBreakdown,
    'externalMethodBreakdown' => $externalMethodBreakdown,
    'paymentAdjustments' => $paymentAdjustments,
    'paymentTransactions' => $paymentTransactions,
    'receiptReprints' => $receiptReprints,
    'cashLog'          => $cashLog,
    'closeReconciliations' => $closeReconciliations,
    'preCloseLogs' => $preCloseLogs,
    'unresolvedPreCloseLogs' => $unresolvedPreCloseLogs,
    'postCloseTransactions' => $postCloseTransactions,
    'postCloseAdjustments' => $postCloseAdjustments,
    'registerStatus' => [
        'hasOpen' => $hasOpen,
        'hasClose' => $hasClose,
        'isOpen' => $isRegisterOpen,
        'needsClose' => $needsClose,
        'latestOpenAt' => $latestOpenAt,
        'latestCloseAt' => $latestCloseAt,
        'lastAction' => $lastRegisterAction,
        'lastActionAt' => $lastRegisterActionAt,
        'lastActionUserName' => $lastRegisterActionUserName,
        'latestDifference' => $latestDifference,
        'overshortLevel' => $overshortLevel,
        'closeReminder' => $closeReminder,
    ],
    'registerSummary'  => [
        'openAmount'      => $openAmount,
        'cashSales'       => $cashSales,
        'cashIn'          => $cashIn,
        'cashOut'         => $cashOut,
        'expectedBalance' => $expectedBalance,
        'closeAmount'     => $closeAmount,
        'overshort'       => $overshort,
        'latestCloseReconciliation' => $latestCloseReconciliation,
    ],
    'closeAssist' => [
        'activeOrderCount' => $activeOrderCount,
        'activeOrderTotal' => $activeOrderTotal,
        'activeTableCount' => $activeTableCount,
        'missingExternalNoteCount' => $missingExternalNoteCount,
        'missingExternalNoteTotal' => $missingExternalNoteTotal,
        'adjustmentReviewCount' => $adjustmentReviewCount,
        'voidReviewCount' => $voidReviewCount,
        'refundReviewCount' => $refundReviewCount,
        'unresolvedPreCloseCount' => count($unresolvedPreCloseLogs),
        'postClosePaymentCount' => count($postCloseTransactions),
        'postClosePaymentTotal' => $postClosePaymentTotal,
        'postCloseAdjustmentCount' => count($postCloseAdjustments),
        'registerCloseReminder' => $closeReminder,
        'warnings' => $closeAssistWarnings,
    ],
    'period' => ['from' => $from, 'to' => $to],
]);
