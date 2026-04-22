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

json_response([
    'paymentBreakdown' => $paymentBreakdown,
    'externalMethodBreakdown' => $externalMethodBreakdown,
    'cashLog'          => $cashLog,
    'registerSummary'  => [
        'openAmount'      => $openAmount,
        'cashSales'       => $cashSales,
        'cashIn'          => $cashIn,
        'cashOut'         => $cashOut,
        'expectedBalance' => $expectedBalance,
        'closeAmount'     => $closeAmount,
        'overshort'       => $overshort,
    ],
    'period' => ['from' => $from, 'to' => $to],
]);
