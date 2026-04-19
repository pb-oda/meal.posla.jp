<?php
/**
 * L-9 予約管理 — 予約レポート
 * GET ?store_id=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 *  - 件数 / no-show率 / キャンセル率 / 客単価 / 時間帯別ヒート / ソース別件数
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET']);
$user = require_role('staff');
$pdo = get_db();

$storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);
require_store_access($storeId);

$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    json_error('INVALID_DATE', '日付形式が不正です', 400);
}
$fromDt = $from . ' 00:00:00';
$toDt = date('Y-m-d 23:59:59', strtotime($to));

// 全体集計
$stmt = $pdo->prepare(
    "SELECT
       COUNT(*) AS total,
       SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_show,
       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
       SUM(CASE WHEN status = 'seated' OR status = 'completed' THEN 1 ELSE 0 END) AS seated,
       SUM(party_size) AS total_party_size,
       SUM(CASE WHEN source = 'web' THEN 1 ELSE 0 END) AS source_web,
       SUM(CASE WHEN source = 'phone' THEN 1 ELSE 0 END) AS source_phone,
       SUM(CASE WHEN source = 'walk_in' THEN 1 ELSE 0 END) AS source_walk_in,
       SUM(CASE WHEN source = 'ai_chat' THEN 1 ELSE 0 END) AS source_ai_chat
     FROM reservations
     WHERE store_id = ? AND reserved_at >= ? AND reserved_at <= ?"
);
$stmt->execute([$storeId, $fromDt, $toDt]);
$totals = $stmt->fetch();

// 日別件数
$dStmt = $pdo->prepare(
    "SELECT DATE(reserved_at) AS d, COUNT(*) AS cnt, SUM(party_size) AS guests
     FROM reservations
     WHERE store_id = ? AND reserved_at >= ? AND reserved_at <= ?
       AND status NOT IN ('cancelled')
     GROUP BY DATE(reserved_at)
     ORDER BY d ASC"
);
$dStmt->execute([$storeId, $fromDt, $toDt]);
$byDay = $dStmt->fetchAll();

// 時間帯ヒート
$hStmt = $pdo->prepare(
    "SELECT HOUR(reserved_at) AS h, COUNT(*) AS cnt
     FROM reservations
     WHERE store_id = ? AND reserved_at >= ? AND reserved_at <= ?
       AND status NOT IN ('cancelled')
     GROUP BY HOUR(reserved_at)
     ORDER BY h ASC"
);
$hStmt->execute([$storeId, $fromDt, $toDt]);
$byHour = $hStmt->fetchAll();

// 曜日別
$wStmt = $pdo->prepare(
    "SELECT DAYOFWEEK(reserved_at)-1 AS w, COUNT(*) AS cnt
     FROM reservations
     WHERE store_id = ? AND reserved_at >= ? AND reserved_at <= ?
       AND status NOT IN ('cancelled')
     GROUP BY DAYOFWEEK(reserved_at)
     ORDER BY w ASC"
);
$wStmt->execute([$storeId, $fromDt, $toDt]);
$byWeekday = $wStmt->fetchAll();

$total = (int)$totals['total'];
$noShowRate = $total > 0 ? round((int)$totals['no_show'] / $total * 100, 1) : 0;
$cancelRate = $total > 0 ? round((int)$totals['cancelled'] / $total * 100, 1) : 0;

json_response([
    'range' => ['from' => $from, 'to' => $to],
    'totals' => [
        'total' => $total,
        'no_show' => (int)$totals['no_show'],
        'cancelled' => (int)$totals['cancelled'],
        'seated' => (int)$totals['seated'],
        'no_show_rate' => $noShowRate,
        'cancel_rate' => $cancelRate,
        'total_party_size' => (int)$totals['total_party_size'],
    ],
    'by_source' => [
        'web' => (int)$totals['source_web'],
        'phone' => (int)$totals['source_phone'],
        'walk_in' => (int)$totals['source_walk_in'],
        'ai_chat' => (int)$totals['source_ai_chat'],
    ],
    'by_day' => $byDay,
    'by_hour' => $byHour,
    'by_weekday' => $byWeekday,
]);
