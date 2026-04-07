<?php
/**
 * テーブル状態API（フロアマップ用）
 *
 * GET /api/store/tables-status.php?store_id=xxx
 *
 * スタッフ以上。各テーブルの現在のセッション状態を返す。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET']);
$user = require_auth();
$storeId = require_store_param();   // 内部で require_store_access 済み

$pdo = get_db();

// テーブル一覧（必須カラムのみ指定）
try {
    $stmt = $pdo->prepare(
        'SELECT id, table_code, capacity, is_active
         FROM tables WHERE store_id = ? AND is_active = 1
         ORDER BY table_code'
    );
    $stmt->execute([$storeId]);
    $tables = $stmt->fetchAll();
} catch (PDOException $e) {
    json_error('DB_ERROR', 'テーブル取得に失敗しました: ' . $e->getMessage(), 500);
}

// アクティブなセッション（table_sessions が存在する場合のみ）
$sessionMap = [];
$hasMemoCol = false;
try {
    // memo カラム存在チェック
    try {
        $pdo->query('SELECT memo FROM table_sessions LIMIT 0');
        $hasMemoCol = true;
    } catch (PDOException $e) {}

    $tsCols = 'ts.id, ts.table_id, ts.status, ts.guest_count, ts.started_at,
                ts.plan_id, ts.time_limit_min, ts.expires_at, ts.last_order_at,
                tlp.name AS plan_name, tlp.price AS plan_price,
                ts.course_id, ts.current_phase_number,
                ct.name AS course_name, ct.price AS course_price';
    if ($hasMemoCol) {
        $tsCols .= ', ts.memo';
    }

    $stmt = $pdo->prepare(
        'SELECT ' . $tsCols . '
         FROM table_sessions ts
         LEFT JOIN time_limit_plans tlp ON tlp.id = ts.plan_id
         LEFT JOIN course_templates ct ON ct.id = ts.course_id
         WHERE ts.store_id = ? AND ts.status NOT IN ("paid", "closed")'
    );
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() as $s) {
        $sessionMap[$s['table_id']] = $s;
    }
} catch (PDOException $e) {
    // table_sessions 未作成時はスキップ
}

// テーブルごとの未会計注文数・合計金額
$orderMap = [];
try {
    $stmt = $pdo->prepare(
        'SELECT table_id, COUNT(*) AS order_count, SUM(total_amount) AS total
         FROM orders
         WHERE store_id = ? AND table_id IS NOT NULL
           AND status NOT IN ("paid", "cancelled")
         GROUP BY table_id'
    );
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() as $row) {
        $orderMap[$row['table_id']] = [
            'orderCount'  => (int)$row['order_count'],
            'totalAmount' => (int)$row['total'],
        ];
    }
} catch (PDOException $e) {
    // skip
}

// O-1: テーブルごとの品目ステータス集計（order_items テーブルが存在する場合）
$itemStatusMap = []; // table_id => { pending, preparing, ready, served }
try {
    $stmt = $pdo->prepare(
        'SELECT o.table_id,
                SUM(CASE WHEN oi.status = "pending" THEN oi.qty ELSE 0 END) AS pending_qty,
                SUM(CASE WHEN oi.status = "preparing" THEN oi.qty ELSE 0 END) AS preparing_qty,
                SUM(CASE WHEN oi.status = "ready" THEN oi.qty ELSE 0 END) AS ready_qty,
                SUM(CASE WHEN oi.status = "served" THEN oi.qty ELSE 0 END) AS served_qty
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.store_id = ? AND o.table_id IS NOT NULL
           AND o.status NOT IN ("paid", "cancelled")
           AND oi.status != "cancelled"
         GROUP BY o.table_id'
    );
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() as $row) {
        $itemStatusMap[$row['table_id']] = [
            'pendingQty'   => (int)$row['pending_qty'],
            'preparingQty' => (int)$row['preparing_qty'],
            'readyQty'     => (int)$row['ready_qty'],
            'servedQty'    => (int)$row['served_qty'],
        ];
    }
} catch (PDOException $e) {
    // order_items テーブル未作成時はスキップ
}

$now = time();
$result = [];
foreach ($tables as $t) {
    $session = $sessionMap[$t['id']] ?? null;
    $orders  = $orderMap[$t['id']] ?? null;

    $sessionData = null;
    if ($session) {
        $elapsed = $now - strtotime($session['started_at']);
        $sessionData = [
            'id'           => $session['id'],
            'status'       => $session['status'],
            'guestCount'   => (int)($session['guest_count'] ?? 0),
            'startedAt'    => $session['started_at'],
            'elapsedMin'   => max(0, (int)floor($elapsed / 60)),
            'timeLimitMin' => isset($session['time_limit_min']) && $session['time_limit_min'] ? (int)$session['time_limit_min'] : null,
            'expiresAt'    => $session['expires_at'] ?? null,
            'lastOrderAt'  => $session['last_order_at'] ?? null,
            'planId'       => $session['plan_id'] ?? null,
            'planName'     => $session['plan_name'] ?? null,
            'planPrice'    => $session['plan_price'] !== null ? (int)$session['plan_price'] : null,
            'courseId'           => $session['course_id'] ?? null,
            'courseName'         => $session['course_name'] ?? null,
            'coursePrice'        => $session['course_price'] !== null ? (int)$session['course_price'] : null,
            'currentPhaseNumber' => $session['current_phase_number'] !== null ? (int)$session['current_phase_number'] : null,
            'memo'               => $hasMemoCol ? ($session['memo'] ?? null) : null,
        ];
    }

    $itemStatus = $itemStatusMap[$t['id']] ?? null;

    $result[] = [
        'id'         => $t['id'],
        'tableCode'  => $t['table_code'],
        'capacity'   => (int)$t['capacity'],
        'session'    => $sessionData,
        'orders'     => $orders,
        'itemStatus' => $itemStatus,
    ];
}

// O-3: ラストオーダー状態
$lastOrderData = ['active' => false, 'time' => null];
try {
    $loStmt = $pdo->prepare('SELECT last_order_time, last_order_active FROM store_settings WHERE store_id = ?');
    $loStmt->execute([$storeId]);
    $loRow = $loStmt->fetch();
    if ($loRow) {
        $lastOrderData['active'] = (int)($loRow['last_order_active'] ?? 0) === 1;
        $lastOrderData['time'] = $loRow['last_order_time'] ?? null;
    }
} catch (PDOException $e) {
    // カラム未存在時はデフォルト値
}

json_response([
    'tables'     => $result,
    'lastOrder'  => $lastOrderData,
]);
