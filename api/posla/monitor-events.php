<?php
/**
 * I-1 監視イベント一覧 API (POSLA管理者向け)
 *
 * GET /api/posla/monitor-events.php?since=YYYY-MM-DD&limit=100&resolved=0|1
 * PATCH { id, resolved: 1 } → 対応済にマーク
 */

require_once __DIR__ . '/auth-helper.php';

$method = require_method(['GET','PATCH']);
$admin = require_posla_admin();
$pdo = get_db();

if ($method === 'GET') {
    $since = isset($_GET['since']) ? $_GET['since'] : date('Y-m-d', strtotime('-7 days'));
    $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
    $resolved = isset($_GET['resolved']) ? (int)$_GET['resolved'] : null;

    $sql = 'SELECT id, event_type, severity, source, title, detail, tenant_id, store_id, resolved, resolved_at, notified_slack, notified_email, created_at FROM monitor_events WHERE created_at >= ?';
    $params = [$since . ' 00:00:00'];
    if ($resolved !== null) { $sql .= ' AND resolved = ?'; $params[] = $resolved; }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    // 概況サマリ
    $sumStmt = $pdo->prepare(
        "SELECT
           SUM(CASE WHEN severity = 'critical' AND resolved = 0 THEN 1 ELSE 0 END) AS critical,
           SUM(CASE WHEN severity = 'error' AND resolved = 0 THEN 1 ELSE 0 END) AS error,
           SUM(CASE WHEN severity = 'warn' AND resolved = 0 THEN 1 ELSE 0 END) AS warn,
           COUNT(*) AS total
         FROM monitor_events WHERE created_at >= ?"
    );
    $sumStmt->execute([$since . ' 00:00:00']);
    $summary = $sumStmt->fetch();

    json_response(['events' => $events, 'summary' => $summary]);
}

if ($method === 'PATCH') {
    $body = get_json_body();
    $id = $body['id'] ?? '';
    if (!$id) json_error('MISSING_ID', 'id が必要です', 400);
    if (!empty($body['resolved'])) {
        $pdo->prepare('UPDATE monitor_events SET resolved = 1, resolved_at = NOW(), resolved_by = ? WHERE id = ?')
            ->execute([$admin['admin_id'], $id]);
    }
    json_response(['ok' => true]);
}
