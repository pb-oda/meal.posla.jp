<?php
/**
 * AI最適シフト提案 — データ集計 API（L-3 Phase 2）
 *
 * GET ?store_id=xxx&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 *
 * テンプレート、スタッフ希望、スタッフ一覧（時給付き）、
 * 過去4週間の時間帯別売上、勤怠実績を集約して返す。
 * フロント側でGemini APIに送信してシフト提案を取得する。
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';

start_auth_session();
handle_preflight();
$method = require_method(['GET']);
$user   = require_auth();
require_role('manager');

$pdo      = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    json_error('INVALID_DATE', 'start_date, end_date を YYYY-MM-DD 形式で指定してください', 400);
}

// 過去4週間の基準日
$fourWeeksAgo = date('Y-m-d', strtotime($startDate . ' -28 days'));

// ── 1. テンプレート ──
$stmt = $pdo->prepare(
    'SELECT id, name, day_of_week, start_time, end_time, required_staff, role_hint
     FROM shift_templates
     WHERE store_id = ? AND tenant_id = ? AND is_active = 1
     ORDER BY day_of_week, start_time'
);
$stmt->execute([$storeId, $tenantId]);
$templates = [];
foreach ($stmt->fetchAll() as $t) {
    $templates[] = [
        'id'             => $t['id'],
        'name'           => $t['name'],
        'day_of_week'    => (int)$t['day_of_week'],
        'start_time'     => $t['start_time'],
        'end_time'       => $t['end_time'],
        'required_staff' => (int)$t['required_staff'],
        'role_hint'      => $t['role_hint'],
    ];
}

// ── 2. スタッフ希望（対象期間） ──
$stmt = $pdo->prepare(
    'SELECT sa.user_id, sa.target_date, sa.availability,
            sa.preferred_start, sa.preferred_end
     FROM shift_availabilities sa
     WHERE sa.store_id = ? AND sa.tenant_id = ?
       AND sa.target_date BETWEEN ? AND ?
     ORDER BY sa.target_date, sa.user_id'
);
$stmt->execute([$storeId, $tenantId, $startDate, $endDate]);
$availabilities = [];
foreach ($stmt->fetchAll() as $a) {
    $availabilities[] = [
        'user_id'         => $a['user_id'],
        'target_date'     => $a['target_date'],
        'availability'    => $a['availability'],
        'preferred_start' => $a['preferred_start'],
        'preferred_end'   => $a['preferred_end'],
    ];
}

// ── 3. スタッフ一覧（時給 + 直近4週間の勤怠実績） ──
// 3a: スタッフ基本情報 + 個別時給
$stmt = $pdo->prepare(
    'SELECT u.id, u.display_name, u.username, us.hourly_rate
     FROM users u
     INNER JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
     WHERE u.tenant_id = ? AND u.role IN (\'staff\', \'manager\') AND u.is_active = 1
     ORDER BY u.display_name'
);
$stmt->execute([$storeId, $tenantId]);
$staffRows = $stmt->fetchAll();

// 店舗デフォルト時給
$stmtSettings = $pdo->prepare(
    'SELECT default_hourly_rate FROM shift_settings WHERE store_id = ? AND tenant_id = ?'
);
$stmtSettings->execute([$storeId, $tenantId]);
$settingsRow = $stmtSettings->fetch();
$defaultHourlyRate = ($settingsRow && $settingsRow['default_hourly_rate'] !== null)
    ? (int)$settingsRow['default_hourly_rate'] : null;

$stmtPositions = $pdo->prepare(
    'SELECT code, label
     FROM shift_work_positions
     WHERE tenant_id = ? AND store_id = ? AND is_active = 1
     ORDER BY sort_order, label'
);
$stmtPositions->execute([$tenantId, $storeId]);
$positions = $stmtPositions->fetchAll();
if (count($positions) === 0) {
    $positions = [
        ['code' => 'hall', 'label' => 'ホール'],
        ['code' => 'kitchen', 'label' => 'キッチン'],
    ];
}

// 3b: 直近4週間の勤怠実績（スタッフ別集計）
$stmt = $pdo->prepare(
    'SELECT a.user_id,
            COUNT(*) AS days_worked,
            SUM(CASE WHEN a.status = \'late\' THEN 1 ELSE 0 END) AS late_count,
            SUM(TIMESTAMPDIFF(MINUTE, a.clock_in, COALESCE(a.clock_out, NOW())) - a.break_minutes) AS total_minutes
     FROM attendance_logs a
     WHERE a.tenant_id = ? AND a.store_id = ?
       AND DATE(a.clock_in) BETWEEN ? AND ?
       AND a.status IN (\'completed\', \'working\')
     GROUP BY a.user_id'
);
$stmt->execute([$tenantId, $storeId, $fourWeeksAgo, $startDate]);
$attMap = [];
foreach ($stmt->fetchAll() as $att) {
    $attMap[$att['user_id']] = $att;
}

// 3c: 連続勤務日数（直近14日）
$twoWeeksAgo = date('Y-m-d', strtotime($startDate . ' -14 days'));
$stmt = $pdo->prepare(
    'SELECT a.user_id, DATE(a.clock_in) AS work_date
     FROM attendance_logs a
     WHERE a.tenant_id = ? AND a.store_id = ?
       AND DATE(a.clock_in) BETWEEN ? AND ?
       AND a.status IN (\'completed\', \'working\')
     ORDER BY a.user_id, a.clock_in'
);
$stmt->execute([$tenantId, $storeId, $twoWeeksAgo, $startDate]);
$consecutiveMap = []; // { user_id: consecutive_days_current }
$userDates = [];
foreach ($stmt->fetchAll() as $r) {
    $uid = $r['user_id'];
    if (!isset($userDates[$uid])) $userDates[$uid] = [];
    $userDates[$uid][] = $r['work_date'];
}
foreach ($userDates as $uid => $dates) {
    $dates = array_unique($dates);
    sort($dates);
    $consecutive = 1;
    // 末尾からの連続日数を計算
    for ($i = count($dates) - 1; $i > 0; $i--) {
        $curr = new DateTime($dates[$i]);
        $prev = new DateTime($dates[$i - 1]);
        if ($curr->diff($prev)->days === 1) {
            $consecutive++;
        } else {
            break;
        }
    }
    $consecutiveMap[$uid] = $consecutive;
}

// スタッフリスト組み立て
$staffList = [];
foreach ($staffRows as $sr) {
    $uid = $sr['id'];
    $rate = $sr['hourly_rate'] !== null ? (int)$sr['hourly_rate'] : $defaultHourlyRate;
    $att = isset($attMap[$uid]) ? $attMap[$uid] : null;
    $totalMins = $att ? max(0, (int)$att['total_minutes']) : 0;

    $staffList[] = [
        'id'                        => $uid,
        'display_name'              => $sr['display_name'] ?: $sr['username'],
        'hourly_rate'               => $rate,
        'recent_total_hours'        => round($totalMins / 60, 1),
        'recent_days_worked'        => $att ? (int)$att['days_worked'] : 0,
        'late_count'                => $att ? (int)$att['late_count'] : 0,
        'consecutive_days_current'  => isset($consecutiveMap[$uid]) ? $consecutiveMap[$uid] : 0,
    ];
}

// ── 4. 時間帯別売上（過去4週間） ──
$stmt = $pdo->prepare(
    'SELECT DAYOFWEEK(created_at) - 1 AS weekday,
            HOUR(created_at) AS hour_of_day,
            COUNT(*) AS order_count,
            SUM(total_amount) AS total_revenue
     FROM orders
     WHERE store_id = ? AND status = \'paid\'
       AND DATE(created_at) BETWEEN ? AND ?
     GROUP BY weekday, hour_of_day
     ORDER BY weekday, hour_of_day'
);
$stmt->execute([$storeId, $fourWeeksAgo, $startDate]);
$salesRows = $stmt->fetchAll();

$weekdayNames = ['日', '月', '火', '水', '木', '金', '土'];
// 4週間で割って平均化
$salesByDayHour = [];
foreach ($salesRows as $sr) {
    $wd = (int)$sr['weekday'];
    $salesByDayHour[] = [
        'weekday'      => $wd,
        'weekday_name' => $weekdayNames[$wd],
        'hour'         => (int)$sr['hour_of_day'],
        'avg_orders'   => round((int)$sr['order_count'] / 4, 1),
        'avg_revenue'  => (int)round((int)$sr['total_revenue'] / 4),
    ];
}

// ── 5. 店舗名 ──
$storeName = '';
try {
    $stmt = $pdo->prepare('SELECT receipt_store_name FROM store_settings WHERE store_id = ? LIMIT 1');
    $stmt->execute([$storeId]);
    $storeRow = $stmt->fetch();
    $storeName = $storeRow ? ($storeRow['receipt_store_name'] ?: '') : '';
} catch (PDOException $e) {
    error_log('[P1-12][api/store/shift/ai-suggest-data.php:213] fetch_store_name: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

json_response([
    'templates'      => $templates,
    'availabilities' => $availabilities,
    'staffList'      => $staffList,
    'salesByDayHour' => $salesByDayHour,
    'positions'      => $positions,
    'targetPeriod'   => [
        'start_date' => $startDate,
        'end_date'   => $endDate,
    ],
    'storeName'      => $storeName,
]);
