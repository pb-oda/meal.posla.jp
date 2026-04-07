<?php
/**
 * 統合シフトビュー API（L-3 Phase 3）
 *
 * GET ?period=weekly&date=2026-04-14  → 全店舗横断シフトサマリー
 *
 * owner 限定 / enterprise プラン限定
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';

start_auth_session();
handle_preflight();
$method = require_method(['GET']);
$user   = require_auth();
require_role('owner');

$pdo      = get_db();
$tenantId = $user['tenant_id'];

// プランチェック
if (!check_plan_feature($pdo, $tenantId, 'shift_help_request')) {
    json_error('PLAN_REQUIRED', 'この機能はenterpriseプランで利用可能です', 403);
}

$period = $_GET['period'] ?? 'weekly';
$date   = $_GET['date'] ?? '';

// 期間算出
if ($period === 'weekly') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_error('INVALID_DATE', 'date を YYYY-MM-DD 形式で指定してください', 400);
    }
    $startDate = $date;
    $dt = new DateTime($date);
    $dt->modify('+6 days');
    $endDate = $dt->format('Y-m-d');
    $periodLabel = $startDate . ' ~ ' . $endDate;
} elseif ($period === 'monthly') {
    if (!preg_match('/^\d{4}-\d{2}$/', $date)) {
        json_error('INVALID_DATE', 'date を YYYY-MM 形式で指定してください', 400);
    }
    $startDate = $date . '-01';
    $dt = new DateTime($startDate);
    $endDate = $dt->format('Y-m-t');
    $periodLabel = $date;
} else {
    json_error('INVALID_PERIOD', 'period は weekly / monthly のいずれかです', 400);
}

// テナント内の全店舗
$stmtStores = $pdo->prepare(
    'SELECT id, name FROM stores WHERE tenant_id = ? AND is_active = 1 ORDER BY name'
);
$stmtStores->execute([$tenantId]);
$allStores = $stmtStores->fetchAll();

// 各店舗の集計
$storesData = [];

foreach ($allStores as $store) {
    $sid = $store['id'];

    // 1. シフト割当集計
    $stmtSA = $pdo->prepare(
        'SELECT COUNT(DISTINCT sa.user_id) AS staff_count,
                SUM(
                    TIMESTAMPDIFF(MINUTE, sa.start_time, sa.end_time) - sa.break_minutes
                ) AS total_minutes
         FROM shift_assignments sa
         WHERE sa.tenant_id = ? AND sa.store_id = ?
           AND sa.shift_date BETWEEN ? AND ?'
    );
    $stmtSA->execute([$tenantId, $sid, $startDate, $endDate]);
    $saRow = $stmtSA->fetch();
    $staffCount   = (int)($saRow['staff_count'] ?? 0);
    $totalMinutes = max(0, (int)($saRow['total_minutes'] ?? 0));
    $totalHours   = round($totalMinutes / 60, 1);

    // 2. 時給取得
    $stmtSettings = $pdo->prepare(
        'SELECT default_hourly_rate FROM shift_settings WHERE store_id = ? AND tenant_id = ?'
    );
    $stmtSettings->execute([$sid, $tenantId]);
    $settingsRow = $stmtSettings->fetch();
    $defaultRate = ($settingsRow && $settingsRow['default_hourly_rate'] !== null) ? (int)$settingsRow['default_hourly_rate'] : null;

    // 個別時給
    $stmtRates = $pdo->prepare(
        'SELECT user_id, hourly_rate FROM user_stores WHERE store_id = ? AND hourly_rate IS NOT NULL'
    );
    $stmtRates->execute([$sid]);
    $rateMap = [];
    foreach ($stmtRates->fetchAll() as $rr) {
        $rateMap[$rr['user_id']] = (int)$rr['hourly_rate'];
    }

    // 3. 人件費計算（簡略版: 各割当レコードごとに計算）
    $laborCostTotal = 0;
    $nightPremiumTotal = 0;
    if ($defaultRate !== null || count($rateMap) > 0) {
        $stmtDetail = $pdo->prepare(
            'SELECT user_id, start_time, end_time, break_minutes
             FROM shift_assignments
             WHERE tenant_id = ? AND store_id = ?
               AND shift_date BETWEEN ? AND ?'
        );
        $stmtDetail->execute([$tenantId, $sid, $startDate, $endDate]);
        foreach ($stmtDetail->fetchAll() as $d) {
            $rate = isset($rateMap[$d['user_id']]) ? $rateMap[$d['user_id']] : $defaultRate;
            if ($rate === null) continue;

            $startParts = explode(':', $d['start_time']);
            $endParts   = explode(':', $d['end_time']);
            $startMin   = (int)$startParts[0] * 60 + (int)$startParts[1];
            $endMin     = (int)$endParts[0] * 60 + (int)$endParts[1];
            $workMin    = $endMin - $startMin - (int)$d['break_minutes'];
            if ($workMin < 0) $workMin = 0;

            // 深夜時間帯 22:00-05:00
            $nightMins = 0;
            $bands = [[0, 300], [1320, 1440]];
            foreach ($bands as $band) {
                $os = max($startMin, $band[0]);
                $oe = min($endMin, $band[1]);
                if ($oe > $os) $nightMins += $oe - $os;
            }
            $normalMins = $workMin - $nightMins;
            if ($normalMins < 0) $normalMins = 0;

            $cost = (int)round(($normalMins / 60) * $rate + ($nightMins / 60) * $rate * 1.25);
            $nightPremium = (int)round(($nightMins / 60) * $rate * 0.25);
            $laborCostTotal += $cost;
            $nightPremiumTotal += $nightPremium;
        }
    }

    // 4. テンプレート充足率
    $stmtTpl = $pdo->prepare(
        'SELECT SUM(required_staff) AS total_required
         FROM shift_templates
         WHERE tenant_id = ? AND store_id = ? AND is_active = 1'
    );
    $stmtTpl->execute([$tenantId, $sid]);
    $tplRow = $stmtTpl->fetch();
    $templateRequired = (int)($tplRow['total_required'] ?? 0);

    // 実配置数
    $stmtAssignCount = $pdo->prepare(
        'SELECT COUNT(*) AS cnt FROM shift_assignments
         WHERE tenant_id = ? AND store_id = ? AND shift_date BETWEEN ? AND ?'
    );
    $stmtAssignCount->execute([$tenantId, $sid, $startDate, $endDate]);
    $assignCount = (int)$stmtAssignCount->fetch()['cnt'];

    // 日数
    $dt1 = new DateTime($startDate);
    $dt2 = new DateTime($endDate);
    $days = $dt1->diff($dt2)->days + 1;
    $expectedTotal = $templateRequired * $days;
    $fulfillmentRate = ($expectedTotal > 0) ? round(($assignCount / $expectedTotal) * 100, 1) : 0;

    // 5. ヘルプ要請数
    $stmtHelp = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN from_store_id = ? AND status = \'pending\' THEN 1 ELSE 0 END) AS help_sent_pending,
            SUM(CASE WHEN from_store_id = ? AND status = \'approved\' THEN 1 ELSE 0 END) AS help_sent_approved,
            SUM(CASE WHEN to_store_id = ? AND status = \'pending\' THEN 1 ELSE 0 END) AS help_received_pending,
            SUM(CASE WHEN to_store_id = ? AND status = \'approved\' THEN 1 ELSE 0 END) AS help_received_approved
         FROM shift_help_requests
         WHERE tenant_id = ? AND requested_date BETWEEN ? AND ?'
    );
    $stmtHelp->execute([$sid, $sid, $sid, $sid, $tenantId, $startDate, $endDate]);
    $helpRow = $stmtHelp->fetch();

    $storesData[] = [
        'store_id'              => $sid,
        'store_name'            => $store['name'],
        'total_staff_count'     => $staffCount,
        'total_hours'           => $totalHours,
        'labor_cost_total'      => $laborCostTotal,
        'night_premium_total'   => $nightPremiumTotal,
        'assigned_days'         => $days,
        'template_required_total' => $expectedTotal,
        'fulfillment_rate'      => $fulfillmentRate,
        'help_sent_pending'     => (int)($helpRow['help_sent_pending'] ?? 0),
        'help_sent_approved'    => (int)($helpRow['help_sent_approved'] ?? 0),
        'help_received_pending' => (int)($helpRow['help_received_pending'] ?? 0),
        'help_received_approved' => (int)($helpRow['help_received_approved'] ?? 0),
    ];
}

// ヘルプ要請サマリー
$stmtHelpSummary = $pdo->prepare(
    'SELECT status, COUNT(*) AS cnt
     FROM shift_help_requests
     WHERE tenant_id = ? AND requested_date BETWEEN ? AND ?
     GROUP BY status'
);
$stmtHelpSummary->execute([$tenantId, $startDate, $endDate]);
$helpSummary = ['total_pending' => 0, 'total_approved' => 0, 'total_rejected' => 0];
foreach ($stmtHelpSummary->fetchAll() as $hs) {
    if ($hs['status'] === 'pending')  $helpSummary['total_pending']  = (int)$hs['cnt'];
    if ($hs['status'] === 'approved') $helpSummary['total_approved'] = (int)$hs['cnt'];
    if ($hs['status'] === 'rejected') $helpSummary['total_rejected'] = (int)$hs['cnt'];
}

// ヘルプ要請一覧（pending）
$stmtHelpPending = $pdo->prepare(
    'SELECT shr.id, shr.from_store_id, shr.to_store_id, shr.requested_date,
            shr.start_time, shr.end_time, shr.requested_staff_count,
            shr.role_hint, shr.status,
            fs.name AS from_store_name, ts.name AS to_store_name
     FROM shift_help_requests shr
     JOIN stores fs ON fs.id = shr.from_store_id
     JOIN stores ts ON ts.id = shr.to_store_id
     WHERE shr.tenant_id = ? AND shr.requested_date BETWEEN ? AND ?
       AND shr.status IN (\'pending\', \'approved\')
     ORDER BY shr.requested_date, shr.start_time'
);
$stmtHelpPending->execute([$tenantId, $startDate, $endDate]);
$helpRequests = $stmtHelpPending->fetchAll();
foreach ($helpRequests as &$hr) {
    $hr['requested_staff_count'] = (int)$hr['requested_staff_count'];
}
unset($hr);

json_response([
    'period'                => $periodLabel,
    'stores'                => $storesData,
    'help_requests_summary' => $helpSummary,
    'help_requests'         => $helpRequests,
]);
