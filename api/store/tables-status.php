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
$hasNextSessionCols = false;
$hasNextSessionActorCol = false;
try {
    try {
        $pdo->query('SELECT next_session_token FROM tables LIMIT 0');
        $hasNextSessionCols = true;
    } catch (PDOException $e) {}
    try {
        $pdo->query('SELECT next_session_opened_by_user_id FROM tables LIMIT 0');
        $hasNextSessionActorCol = true;
    } catch (PDOException $e) {}
    $tableCols = 't.id, t.table_code, t.capacity, t.is_active';
    if ($hasNextSessionCols) {
        $tableCols .= ', t.next_session_token, t.next_session_token_expires_at, t.next_session_opened_at';
        if ($hasNextSessionActorCol) {
            $tableCols .= ', t.next_session_opened_by_user_id, u.display_name AS next_session_opened_by_display_name, u.username AS next_session_opened_by_username';
        }
    }
    $stmt = $pdo->prepare(
        'SELECT ' . $tableCols . '
         FROM tables t
         ' . ($hasNextSessionActorCol ? 'LEFT JOIN users u ON u.id = t.next_session_opened_by_user_id' : '') . '
         WHERE t.store_id = ? AND t.is_active = 1
         ORDER BY t.table_code'
    );
    $stmt->execute([$storeId]);
    $tables = $stmt->fetchAll();
} catch (PDOException $e) {
    // H-14: browser 応答から PDO 内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/tables-status.php] db_error: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', 'テーブル取得に失敗しました', 500);
}

// アクティブなセッション（table_sessions が存在する場合のみ）
$sessionMap = [];
$hasMemoCol = false;
$hasPinCol = false;
$hasStatusChangedCol = false;
$hasReservationContext = false;
try {
    // memo カラム存在チェック
    try {
        $pdo->query('SELECT memo FROM table_sessions LIMIT 0');
        $hasMemoCol = true;
    } catch (PDOException $e) {}
    // S6: session_pin カラム存在チェック
    try {
        $pdo->query('SELECT session_pin FROM table_sessions LIMIT 0');
        $hasPinCol = true;
    } catch (PDOException $e) {}
    try {
        $pdo->query('SELECT status_changed_at FROM table_sessions LIMIT 0');
        $hasStatusChangedCol = true;
    } catch (PDOException $e) {}
    try {
        $pdo->query('SELECT reservation_id FROM table_sessions LIMIT 0');
        $pdo->query('SELECT id, customer_id FROM reservations LIMIT 0');
        $pdo->query('SELECT id, preferences FROM reservation_customers LIMIT 0');
        $hasReservationContext = true;
    } catch (PDOException $e) {}

    $tsCols = 'ts.id, ts.table_id, ts.status, ts.guest_count, ts.started_at,
                ts.plan_id, ts.time_limit_min, ts.expires_at, ts.last_order_at,
                tlp.name AS plan_name, tlp.price AS plan_price,
                ts.course_id, ts.current_phase_number,
                ct.name AS course_name, ct.price AS course_price';
    if ($hasReservationContext) {
        $tsCols .= ', ts.reservation_id,
                r.customer_name AS reservation_customer_name,
                r.customer_phone AS reservation_customer_phone,
                r.party_size AS reservation_party_size,
                r.reserved_at AS reservation_reserved_at,
                r.memo AS reservation_memo,
                r.tags AS reservation_tags,
                r.course_name AS reservation_course_name,
                rc.preferences AS reservation_customer_preferences,
                rc.allergies AS reservation_customer_allergies,
                rc.internal_memo AS reservation_customer_internal_memo,
                rc.tags AS reservation_customer_tags,
                rc.visit_count AS reservation_customer_visit_count,
                rc.no_show_count AS reservation_customer_no_show_count,
                rc.cancel_count AS reservation_customer_cancel_count,
                rc.last_visit_at AS reservation_customer_last_visit_at,
                rc.is_vip AS reservation_customer_is_vip';
    }
    if ($hasMemoCol) {
        $tsCols .= ', ts.memo';
    }
    if ($hasPinCol) {
        $tsCols .= ', ts.session_pin';
    }
    if ($hasStatusChangedCol) {
        $tsCols .= ', ts.status_changed_at';
    }

    $stmt = $pdo->prepare(
        'SELECT ' . $tsCols . '
         FROM table_sessions ts
         LEFT JOIN time_limit_plans tlp ON tlp.id = ts.plan_id
         LEFT JOIN course_templates ct ON ct.id = ts.course_id
         ' . ($hasReservationContext ? 'LEFT JOIN reservations r ON r.id = ts.reservation_id AND r.store_id = ts.store_id
         LEFT JOIN reservation_customers rc ON rc.id = r.customer_id AND rc.store_id = r.store_id AND rc.tenant_id = r.tenant_id' : '') . '
         WHERE ts.store_id = ? AND ts.status NOT IN ("paid", "closed")'
    );
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() as $s) {
        $sessionMap[$s['table_id']] = $s;
    }
} catch (PDOException $e) {
    // table_sessions 未作成時はスキップ
    error_log('[P1-12][tables-status.php:63] fetch_session_map: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// テーブルごとの未会計注文数・合計金額
$orderMap = [];
try {
    $stmt = $pdo->prepare(
        'SELECT table_id,
                COUNT(*) AS order_count,
                SUM(total_amount) AS total,
                SUM(CASE WHEN order_type = "dine_in" THEN 1 ELSE 0 END) AS self_order_count,
                SUM(CASE WHEN order_type = "handy" THEN 1 ELSE 0 END) AS handy_order_count,
                MAX(created_at) AS last_order_at
         FROM orders
         WHERE store_id = ? AND table_id IS NOT NULL
           AND status NOT IN ("paid", "cancelled")
         GROUP BY table_id'
    );
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() as $row) {
        $orderMap[$row['table_id']] = [
            'orderCount'      => (int)$row['order_count'],
            'totalAmount'     => (int)$row['total'],
            'selfOrderCount'  => (int)($row['self_order_count'] ?? 0),
            'handyOrderCount' => (int)($row['handy_order_count'] ?? 0),
            'lastOrderAt'     => $row['last_order_at'] ?? null,
        ];
    }
} catch (PDOException $e) {
    // skip
    error_log('[P1-12][tables-status.php:84] fetch_order_map: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
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
    error_log('[P1-12][tables-status.php:113] fetch_item_status_map: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

$now = time();
$result = [];
foreach ($tables as $t) {
    $session = $sessionMap[$t['id']] ?? null;
    $orders  = $orderMap[$t['id']] ?? null;

    $sessionData = null;
    if ($session) {
        $elapsed = $now - strtotime($session['started_at']);
        $statusChangedAt = $hasStatusChangedCol ? ($session['status_changed_at'] ?? null) : null;
        $statusChangedTs = $statusChangedAt ? strtotime($statusChangedAt) : false;
        $sessionData = [
            'id'           => $session['id'],
            'status'       => $session['status'],
            'guestCount'   => (int)($session['guest_count'] ?? 0),
            'startedAt'    => $session['started_at'],
            'elapsedMin'   => max(0, (int)floor($elapsed / 60)),
            'statusChangedAt' => $statusChangedAt,
            'statusElapsedSec' => $statusChangedTs ? max(0, $now - $statusChangedTs) : $elapsed,
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
            'sessionPin'         => $hasPinCol ? ($session['session_pin'] ?? null) : null,
        ];
        if ($hasReservationContext && !empty($session['reservation_id'])) {
            $sessionData['reservationContext'] = [
                'reservationId' => $session['reservation_id'],
                'customerName' => $session['reservation_customer_name'] ?? null,
                'customerPhone' => $session['reservation_customer_phone'] ?? null,
                'partySize' => isset($session['reservation_party_size']) ? (int)$session['reservation_party_size'] : null,
                'reservedAt' => $session['reservation_reserved_at'] ?? null,
                'memo' => $session['reservation_memo'] ?? null,
                'tags' => $session['reservation_tags'] ?? null,
                'courseName' => $session['reservation_course_name'] ?? null,
                'preferences' => $session['reservation_customer_preferences'] ?? null,
                'allergies' => $session['reservation_customer_allergies'] ?? null,
                'internalMemo' => $session['reservation_customer_internal_memo'] ?? null,
                'customerTags' => $session['reservation_customer_tags'] ?? null,
                'visitCount' => isset($session['reservation_customer_visit_count']) ? (int)$session['reservation_customer_visit_count'] : null,
                'noShowCount' => isset($session['reservation_customer_no_show_count']) ? (int)$session['reservation_customer_no_show_count'] : null,
                'cancelCount' => isset($session['reservation_customer_cancel_count']) ? (int)$session['reservation_customer_cancel_count'] : null,
                'lastVisitAt' => $session['reservation_customer_last_visit_at'] ?? null,
                'isVip' => isset($session['reservation_customer_is_vip']) ? (int)$session['reservation_customer_is_vip'] : null,
            ];
        }
    }

    $itemStatus = $itemStatusMap[$t['id']] ?? null;
    $qrOpen = null;
    if ($hasNextSessionCols && !$sessionData && !empty($t['next_session_token']) && !empty($t['next_session_token_expires_at'])) {
        $expiresTs = strtotime($t['next_session_token_expires_at']);
        if ($expiresTs && $expiresTs > $now) {
            $qrOpen = [
                'openedAt' => $t['next_session_opened_at'] ?? null,
                'openedByUserId' => $hasNextSessionActorCol ? ($t['next_session_opened_by_user_id'] ?? null) : null,
                'openedByName' => $hasNextSessionActorCol ? (($t['next_session_opened_by_display_name'] ?? '') ?: ($t['next_session_opened_by_username'] ?? null)) : null,
                'expiresAt' => $t['next_session_token_expires_at'],
                'remainingSec' => max(0, $expiresTs - $now),
            ];
        }
    }

    $result[] = [
        'id'         => $t['id'],
        'tableCode'  => $t['table_code'],
        'capacity'   => (int)$t['capacity'],
        'session'    => $sessionData,
        'orders'     => $orders,
        'itemStatus' => $itemStatus,
        'qrOpen'     => $qrOpen,
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
    error_log('[P1-12][tables-status.php:168] fetch_last_order_data: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

json_response([
    'tables'     => $result,
    'lastOrder'  => $lastOrderData,
]);
