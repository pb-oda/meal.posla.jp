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
$stmt = $pdo->prepare(
    'SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
     FROM orders
     WHERE store_id = ? AND status = ? AND created_at >= ? AND created_at < ?
     GROUP BY payment_method'
);
$stmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
$paymentBreakdown = $stmt->fetchAll();

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
