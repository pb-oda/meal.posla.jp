<?php
/**
 * 満足度・低評価分析レポート (R1)
 *
 * GET /api/store/rating-report.php?store_id=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * manager 以上、店舗単位。
 *
 * レスポンス data:
 *   summary       : { total, avg, lowCount, lowRate, highCount }
 *   reasonRanking : [{ code, label, count, ratio }, ...]   (rating <= 2 のみ集計)
 *   itemRanking   : [{ name, lowCount, avg, lastAt }, ...] (rating <= 2 が多い順)
 *   hourlyLow     : [{ hour, lowCount, totalCount, lowRate }, ...]  (0-23)
 *   recentLow     : [{ createdAt, itemName, rating, reasonCode, reasonLabel, reasonText, tableId, orderIdShort }, ...]
 *   period        : { from, to }
 *
 * 設計方針:
 *   - 低評価は店舗・品目・時間帯改善に活用するためのもの。スタッフ責任の断定はしない。
 *   - reason カラム未適用環境 (migration-r1 未実行) でも壊れないこと。
 *   - 既存 satisfaction_ratings の店舗境界 (store_id) を全クエリで遵守。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';
require_once __DIR__ . '/../lib/rating-reasons.php';

require_method(['GET']);
$user = require_role('manager');
$pdo = get_db();

$storeId = require_store_param();
require_store_access($storeId);

$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');

$range = get_business_day_range($pdo, $storeId, $from, $to);

$labels = rating_reason_labels();

// satisfaction_ratings テーブルが無い環境への防御
$hasTable = false;
try {
    $pdo->query('SELECT 1 FROM satisfaction_ratings LIMIT 0');
    $hasTable = true;
} catch (PDOException $e) {
    // 未作成 → 空レスポンス
}

if (!$hasTable) {
    json_response([
        'summary'       => ['total' => 0, 'avg' => 0, 'lowCount' => 0, 'lowRate' => 0, 'highCount' => 0],
        'reasonRanking' => [],
        'itemRanking'   => [],
        'hourlyLow'     => [],
        'recentLow'     => [],
        'period'        => ['from' => $from, 'to' => $to],
    ]);
    return;
}

// reason カラムの有無をチェック (migration-r1 未適用でも動くように)
$hasReasonCols = false;
try {
    $pdo->query('SELECT reason_code, reason_text FROM satisfaction_ratings LIMIT 0');
    $hasReasonCols = true;
} catch (PDOException $e) {
    // 未追加 — reason 関連は空配列で返す
}

// ===== 1. サマリー =====
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS total,
            COALESCE(AVG(rating), 0) AS avg_rating,
            SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) AS low_count,
            SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) AS high_count
     FROM satisfaction_ratings
     WHERE store_id = ? AND created_at >= ? AND created_at < ?'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
$s = $stmt->fetch();
$total = (int)($s['total'] ?? 0);
$lowCount = (int)($s['low_count'] ?? 0);
$summary = [
    'total'     => $total,
    'avg'       => $total > 0 ? round((float)$s['avg_rating'], 2) : 0,
    'lowCount'  => $lowCount,
    'lowRate'   => $total > 0 ? round($lowCount / $total * 100, 1) : 0,
    'highCount' => (int)($s['high_count'] ?? 0),
];

// ===== 2. 低評価理由ランキング =====
$reasonRanking = [];
if ($hasReasonCols && $lowCount > 0) {
    $stmt = $pdo->prepare(
        'SELECT reason_code, COUNT(*) AS cnt
         FROM satisfaction_ratings
         WHERE store_id = ? AND rating <= 2 AND reason_code IS NOT NULL
           AND created_at >= ? AND created_at < ?
         GROUP BY reason_code
         ORDER BY cnt DESC'
    );
    $stmt->execute([$storeId, $range['start'], $range['end']]);
    foreach ($stmt->fetchAll() as $row) {
        $code = $row['reason_code'];
        $cnt = (int)$row['cnt'];
        $reasonRanking[] = [
            'code'  => $code,
            'label' => $labels[$code] ?? $code,
            'count' => $cnt,
            'ratio' => $lowCount > 0 ? round($cnt / $lowCount * 100, 1) : 0,
        ];
    }
}

// ===== 3. 低評価品目ランキング =====
$itemRanking = [];
$stmt = $pdo->prepare(
    'SELECT item_name,
            COUNT(*) AS low_count,
            AVG(rating) AS avg_rating,
            MAX(created_at) AS last_at
     FROM satisfaction_ratings
     WHERE store_id = ? AND rating <= 2 AND item_name IS NOT NULL AND item_name <> ""
       AND created_at >= ? AND created_at < ?
     GROUP BY item_name
     ORDER BY low_count DESC, last_at DESC
     LIMIT 30'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
foreach ($stmt->fetchAll() as $row) {
    $itemRanking[] = [
        'name'     => $row['item_name'],
        'lowCount' => (int)$row['low_count'],
        'avg'      => round((float)$row['avg_rating'], 2),
        'lastAt'   => $row['last_at'],
    ];
}

// ===== 4. 時間帯別低評価 (0-23 全件) =====
//   仕様: 0-23 の全 24 行を返す。データの無い時間も lowCount=0/totalCount=0 で埋める。
//   フロントは欠損時間を考慮せず描画できる前提とする。
$hourlyLow = [];
$hourMap = [];
if ($total > 0) {
    $stmt = $pdo->prepare(
        'SELECT HOUR(created_at) AS h,
                SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) AS low_count,
                COUNT(*) AS total_count
         FROM satisfaction_ratings
         WHERE store_id = ? AND created_at >= ? AND created_at < ?
         GROUP BY HOUR(created_at)'
    );
    $stmt->execute([$storeId, $range['start'], $range['end']]);
    foreach ($stmt->fetchAll() as $row) {
        $hourMap[(int)$row['h']] = [
            'lowCount'   => (int)$row['low_count'],
            'totalCount' => (int)$row['total_count'],
        ];
    }
}
for ($h = 0; $h < 24; $h++) {
    $entry = $hourMap[$h] ?? ['lowCount' => 0, 'totalCount' => 0];
    $tc = $entry['totalCount'];
    $lc = $entry['lowCount'];
    $hourlyLow[] = [
        'hour'       => $h,
        'lowCount'   => $lc,
        'totalCount' => $tc,
        'lowRate'    => $tc > 0 ? round($lc / $tc * 100, 1) : 0,
    ];
}

// ===== 5. 直近の低評価一覧 =====
$recentLow = [];
$selectCols = 'created_at, item_name, rating, table_id, order_id';
if ($hasReasonCols) {
    $selectCols .= ', reason_code, reason_text';
}
$stmt = $pdo->prepare(
    'SELECT ' . $selectCols . '
     FROM satisfaction_ratings
     WHERE store_id = ? AND rating <= 2
       AND created_at >= ? AND created_at < ?
     ORDER BY created_at DESC
     LIMIT 50'
);
$stmt->execute([$storeId, $range['start'], $range['end']]);
foreach ($stmt->fetchAll() as $row) {
    $code = $hasReasonCols ? ($row['reason_code'] ?? null) : null;
    $recentLow[] = [
        'createdAt'    => $row['created_at'],
        'itemName'     => $row['item_name'],
        'rating'       => (int)$row['rating'],
        'reasonCode'   => $code,
        'reasonLabel'  => $code ? ($labels[$code] ?? $code) : null,
        'reasonText'   => $hasReasonCols ? ($row['reason_text'] ?? null) : null,
        'tableId'      => $row['table_id'],
        'orderIdShort' => $row['order_id'] ? substr($row['order_id'], 0, 8) : null,
    ];
}

json_response([
    'summary'       => $summary,
    'reasonRanking' => $reasonRanking,
    'itemRanking'   => $itemRanking,
    'hourlyLow'     => $hourlyLow,
    'recentLow'     => $recentLow,
    'period'        => ['from' => $from, 'to' => $to],
]);
