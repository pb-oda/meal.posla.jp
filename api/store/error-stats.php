<?php
/**
 * Phase B (CB1): エラー統計 API
 *
 * GET /api/store/error-stats.php?store_id=xxx&hours=24
 *
 * レスポンス:
 *   {
 *     period_hours: 24,
 *     total: 42,
 *     top: [
 *       { errorNo, code, message, http_status, count, last_seen }
 *     ],
 *     by_status: { 400: 12, 401: 3, ... },
 *     by_category: { E1: 1, E2: 12, E3: 5, ... }
 *   }
 *
 * manager 以上のみ。owner はテナント全体、manager は自店舗のみ。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET']);
$user = require_role('manager');
$pdo = get_db();

$storeId = require_store_param();
require_store_access($storeId);

$hours = isset($_GET['hours']) ? max(1, min(168, (int)$_GET['hours'])) : 24;
$tenantId = $user['tenant_id'];

// テーブル存在チェック (graceful degradation)
try {
    $pdo->query('SELECT 1 FROM error_log LIMIT 0');
} catch (PDOException $e) {
    json_response([
        'period_hours' => $hours,
        'total'        => 0,
        'top'          => [],
        'by_status'    => new stdClass(),
        'by_category'  => new stdClass(),
        'unmigrated'   => true,
    ]);
}

// owner はテナント全体、manager は自店舗
$where  = ['created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)', 'tenant_id = ?'];
$params = [$hours, $tenantId];
if ($user['role'] !== 'owner') {
    $where[]  = '(store_id = ? OR store_id IS NULL)';
    $params[] = $storeId;
}
$whereSql = implode(' AND ', $where);

// 件数合計
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM error_log WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// トップ10 (頻度順)
$topStmt = $pdo->prepare(
    "SELECT error_no, code, http_status,
            SUBSTRING(MAX(message), 1, 200) AS message,
            COUNT(*) AS cnt,
            MAX(created_at) AS last_seen
     FROM error_log
     WHERE $whereSql
     GROUP BY error_no, code, http_status
     ORDER BY cnt DESC
     LIMIT 10"
);
$topStmt->execute($params);
$top = [];
foreach ($topStmt->fetchAll() as $r) {
    $top[] = [
        'errorNo'     => $r['error_no'],
        'code'        => $r['code'],
        'http_status' => (int)$r['http_status'],
        'message'     => $r['message'],
        'count'       => (int)$r['cnt'],
        'last_seen'   => $r['last_seen'],
    ];
}

// HTTP ステータス別
$statusStmt = $pdo->prepare(
    "SELECT http_status, COUNT(*) AS cnt FROM error_log WHERE $whereSql GROUP BY http_status"
);
$statusStmt->execute($params);
$byStatus = [];
foreach ($statusStmt->fetchAll() as $r) {
    $byStatus[(string)$r['http_status']] = (int)$r['cnt'];
}

// カテゴリ別 (errorNo の先頭1桁)
$catStmt = $pdo->prepare(
    "SELECT SUBSTRING(error_no, 1, 2) AS cat, COUNT(*) AS cnt
     FROM error_log
     WHERE $whereSql AND error_no IS NOT NULL
     GROUP BY cat"
);
$catStmt->execute($params);
$byCategory = [];
foreach ($catStmt->fetchAll() as $r) {
    $byCategory[$r['cat']] = (int)$r['cnt'];
}

json_response([
    'period_hours' => $hours,
    'total'        => $total,
    'top'          => $top,
    'by_status'    => empty($byStatus) ? new stdClass() : $byStatus,
    'by_category'  => empty($byCategory) ? new stdClass() : $byCategory,
]);
