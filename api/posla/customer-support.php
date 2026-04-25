<?php
/**
 * POSLA管理者 顧客サポートビュー API
 *
 * GET /api/posla/customer-support.php
 *   - active table session 一覧
 *
 * GET /api/posla/customer-support.php?session_id=xxx
 *   - 1 セッション詳細
 *
 * 方針:
 * - POSLA管理者向け read-only 観測
 * - 顧客セッションの現況把握に必要な情報のみ返す
 * - 既存 customer 画面を壊さず、DB の事実から状態推定を返す
 */

require_once __DIR__ . '/auth-helper.php';

$admin = require_posla_admin();
$method = require_method(['GET']);
$pdo = get_db();

function _posla_support_mask_token($token): string
{
    $token = (string)$token;
    if ($token === '') {
        return '';
    }
    if (strlen($token) <= 12) {
        return $token;
    }
    return substr($token, 0, 8) . '...' . substr($token, -4);
}

function _posla_support_guess_state(array $row): array
{
    $sessionStatus = (string)($row['session_status'] ?? '');
    $pending = (int)($row['pending_order_count'] ?? 0);
    $preparing = (int)($row['preparing_order_count'] ?? 0);
    $ready = (int)($row['ready_order_count'] ?? 0);
    $openOrders = (int)($row['open_order_count'] ?? 0);
    $cartEvents = (int)($row['cart_event_count'] ?? 0);
    $lastCartAt = $row['last_cart_at'] ?? '';
    $lastOrderAt = $row['last_order_at'] ?? '';
    $lastActivityAt = $lastOrderAt ?: ($lastCartAt ?: ($row['started_at'] ?? ''));
    $inactiveMin = null;

    if ($lastActivityAt) {
        $ts = strtotime((string)$lastActivityAt);
        if ($ts) {
            $inactiveMin = (int)floor((time() - $ts) / 60);
        }
    }

    if ($sessionStatus === 'bill_requested') {
        return ['code' => 'bill_requested', 'label' => '会計待ち', 'note' => '会計依頼済みです。顧客は会計画面やスタッフ待ちで止まっている可能性があります。', 'inactive_min' => $inactiveMin];
    }
    if ($ready > 0) {
        return ['code' => 'ready', 'label' => '提供待ち', 'note' => '料理完成済みの注文があります。提供導線を確認してください。', 'inactive_min' => $inactiveMin];
    }
    if ($preparing > 0) {
        return ['code' => 'preparing', 'label' => '調理中', 'note' => '厨房進行中です。顧客は注文後の待機状態と推定されます。', 'inactive_min' => $inactiveMin];
    }
    if ($pending > 0) {
        return ['code' => 'pending', 'label' => '注文送信済み', 'note' => '注文は送信済みですが、まだ厨房着手前です。', 'inactive_min' => $inactiveMin];
    }
    if ($cartEvents > 0 && ($lastOrderAt === '' || ($lastCartAt !== '' && strtotime($lastCartAt) >= strtotime($lastOrderAt)))) {
        return ['code' => 'browsing', 'label' => 'メニュー操作中', 'note' => '直近操作はカート更新です。商品選択や画面遷移で迷っている可能性があります。', 'inactive_min' => $inactiveMin];
    }
    if ($openOrders > 0 || $sessionStatus === 'eating') {
        return ['code' => 'eating', 'label' => '食事中', 'note' => '注文済みで大きな異常は見えていません。', 'inactive_min' => $inactiveMin];
    }
    if ($inactiveMin !== null && $inactiveMin >= 15) {
        return ['code' => 'inactive', 'label' => '操作停止', 'note' => '直近 ' . $inactiveMin . ' 分操作がありません。画面停止や離席の可能性があります。', 'inactive_min' => $inactiveMin];
    }
    return ['code' => 'seated', 'label' => '着席直後', 'note' => 'まだ注文前です。QR読込直後の可能性があります。', 'inactive_min' => $inactiveMin];
}

function _posla_support_build_menu_url(array $row)
{
    $token = (string)($row['session_token'] ?? '');
    $expiresAt = $row['session_token_expires_at'] ?? '';
    if ($token === '') {
        return null;
    }
    if ($expiresAt !== '' && strtotime((string)$expiresAt) < time()) {
        return null;
    }
    return app_url('/customer/menu.html')
        . '?store_id=' . urlencode((string)$row['store_id'])
        . '&table_id=' . urlencode((string)$row['table_id'])
        . '&token=' . urlencode($token);
}

function _posla_support_build_preview_url(array $row)
{
    $menuUrl = _posla_support_build_menu_url($row);
    if (!$menuUrl) {
        return null;
    }
    return $menuUrl
        . '&support_preview=1'
        . '&support_session_id=' . urlencode((string)($row['id'] ?? ''));
}

function _posla_support_base_query(): string
{
    return "SELECT ts.id,
                   ts.store_id,
                   s.name AS store_name,
                   s.tenant_id,
                   t.name AS tenant_name,
                   ts.table_id,
                   tb.table_code,
                   ts.status AS session_status,
                   ts.guest_count,
                   ts.started_at,
                   ts.closed_at,
                   ts.reservation_id,
                   ts.plan_id,
                   pl.name AS plan_name,
                   tb.session_token,
                   tb.session_token_expires_at,
                   (SELECT MAX(ce.created_at)
                      FROM cart_events ce
                     WHERE ce.store_id = ts.store_id
                       AND ce.table_id = ts.table_id
                       AND ce.session_token = tb.session_token) AS last_cart_at,
                   (SELECT COUNT(*)
                      FROM cart_events ce
                     WHERE ce.store_id = ts.store_id
                       AND ce.table_id = ts.table_id
                       AND ce.session_token = tb.session_token) AS cart_event_count,
                   (SELECT COUNT(*)
                      FROM orders o
                     WHERE o.store_id = ts.store_id
                       AND o.table_id = ts.table_id
                       AND o.session_token = tb.session_token
                       AND o.status NOT IN ('cancelled', 'paid')) AS open_order_count,
                   (SELECT COUNT(*)
                      FROM orders o
                     WHERE o.store_id = ts.store_id
                       AND o.table_id = ts.table_id
                       AND o.session_token = tb.session_token
                       AND o.status = 'pending') AS pending_order_count,
                   (SELECT COUNT(*)
                      FROM orders o
                     WHERE o.store_id = ts.store_id
                       AND o.table_id = ts.table_id
                       AND o.session_token = tb.session_token
                       AND o.status = 'preparing') AS preparing_order_count,
                   (SELECT COUNT(*)
                      FROM orders o
                     WHERE o.store_id = ts.store_id
                       AND o.table_id = ts.table_id
                       AND o.session_token = tb.session_token
                       AND o.status = 'ready') AS ready_order_count,
                   (SELECT COALESCE(SUM(o.total_amount), 0)
                      FROM orders o
                     WHERE o.store_id = ts.store_id
                       AND o.table_id = ts.table_id
                       AND o.session_token = tb.session_token
                       AND o.status <> 'cancelled') AS total_amount,
                   (SELECT MAX(o.created_at)
                      FROM orders o
                     WHERE o.store_id = ts.store_id
                       AND o.table_id = ts.table_id
                       AND o.session_token = tb.session_token) AS last_order_at
              FROM table_sessions ts
              JOIN stores s ON s.id = ts.store_id
              JOIN tenants t ON t.id = s.tenant_id
              JOIN tables tb ON tb.id = ts.table_id
         LEFT JOIN time_limit_plans pl ON pl.id = ts.plan_id";
}

if (isset($_GET['session_id']) && trim((string)$_GET['session_id']) !== '') {
    $sessionId = trim((string)$_GET['session_id']);
    $sql = _posla_support_base_query() . ' WHERE ts.id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        json_error('NOT_FOUND', '対象セッションが見つかりません', 404);
    }

    $state = _posla_support_guess_state($session);
    $menuUrl = _posla_support_build_menu_url($session);

    $ordersStmt = $pdo->prepare(
        "SELECT id, status, total_amount, payment_method, created_at, updated_at, prepared_at, ready_at, served_at, paid_at, items, memo
           FROM orders
          WHERE store_id = ? AND table_id = ? AND session_token = ?
          ORDER BY created_at DESC
          LIMIT 50"
    );
    $ordersStmt->execute([$session['store_id'], $session['table_id'], $session['session_token']]);
    $orders = [];
    foreach ($ordersStmt->fetchAll() as $row) {
        $items = json_decode((string)($row['items'] ?? '[]'), true);
        $itemNames = [];
        $itemCount = 0;
        if (is_array($items)) {
            foreach ($items as $item) {
                $qty = (int)($item['qty'] ?? 1);
                $itemCount += $qty;
                $itemNames[] = ($item['name'] ?? '商品') . ' x' . $qty;
            }
        }
        $row['item_count'] = $itemCount;
        $row['item_summary'] = implode(' / ', array_slice($itemNames, 0, 4));
        $orders[] = $row;
    }

    $cartStmt = $pdo->prepare(
        "SELECT id, item_id, item_name, item_price, action, created_at
           FROM cart_events
          WHERE store_id = ? AND table_id = ? AND session_token = ?
          ORDER BY created_at DESC
          LIMIT 30"
    );
    $cartStmt->execute([$session['store_id'], $session['table_id'], $session['session_token']]);
    $cartEvents = $cartStmt->fetchAll();

    $itemStatusStmt = $pdo->prepare(
        "SELECT oi.status, COUNT(*) AS cnt
           FROM order_items oi
           JOIN orders o ON o.id = oi.order_id
          WHERE o.store_id = ? AND o.table_id = ? AND o.session_token = ?
          GROUP BY oi.status"
    );
    $itemStatusStmt->execute([$session['store_id'], $session['table_id'], $session['session_token']]);
    $itemStatusMap = [];
    foreach ($itemStatusStmt->fetchAll() as $row) {
        $itemStatusMap[$row['status']] = (int)$row['cnt'];
    }

    json_response([
        'session' => [
            'id' => $session['id'],
            'tenant_id' => $session['tenant_id'],
            'tenant_name' => $session['tenant_name'],
            'store_id' => $session['store_id'],
            'store_name' => $session['store_name'],
            'table_id' => $session['table_id'],
            'table_code' => $session['table_code'],
            'session_status' => $session['session_status'],
            'guest_count' => $session['guest_count'],
            'started_at' => $session['started_at'],
            'reservation_id' => $session['reservation_id'],
            'plan_name' => $session['plan_name'],
            'session_token_preview' => _posla_support_mask_token((string)$session['session_token']),
            'session_token_expires_at' => $session['session_token_expires_at'],
            'state' => $state,
            'cart_event_count' => (int)$session['cart_event_count'],
            'open_order_count' => (int)$session['open_order_count'],
            'total_amount' => (int)$session['total_amount'],
            'last_cart_at' => $session['last_cart_at'],
            'last_order_at' => $session['last_order_at'],
            'customer_menu_url' => $menuUrl,
            'support_preview_url' => _posla_support_build_preview_url($session),
            'customer_bill_api_url' => $menuUrl ? (app_url('/api/customer/get-bill.php')
                . '?store_id=' . urlencode((string)$session['store_id'])
                . '&table_id=' . urlencode((string)$session['table_id'])
                . '&session_token=' . urlencode((string)$session['session_token'])) : null,
            'customer_order_status_api_url' => $menuUrl ? (app_url('/api/customer/order-status.php')
                . '?store_id=' . urlencode((string)$session['store_id'])
                . '&table_id=' . urlencode((string)$session['table_id'])
                . '&session_token=' . urlencode((string)$session['session_token'])) : null,
        ],
        'orders' => $orders,
        'cart_events' => $cartEvents,
        'item_status' => $itemStatusMap,
    ]);
}

$tenantId = trim((string)($_GET['tenant_id'] ?? ''));
$storeId = trim((string)($_GET['store_id'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'active'));
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 30;

$sql = _posla_support_base_query() . ' WHERE 1 = 1';
$params = [];

if ($status === 'active' || $status === '') {
    $sql .= " AND ts.status IN ('seated', 'eating', 'bill_requested')";
} elseif ($status === 'all') {
    // no-op
} else {
    $sql .= ' AND ts.status = ?';
    $params[] = $status;
}

if ($tenantId !== '') {
    $sql .= ' AND s.tenant_id = ?';
    $params[] = $tenantId;
}

if ($storeId !== '') {
    $sql .= ' AND ts.store_id = ?';
    $params[] = $storeId;
}

$sql .= ' ORDER BY COALESCE((SELECT MAX(ce.created_at)
                                FROM cart_events ce
                               WHERE ce.store_id = ts.store_id
                                 AND ce.table_id = ts.table_id
                                 AND ce.session_token = tb.session_token),
                              (SELECT MAX(o.created_at)
                                 FROM orders o
                                WHERE o.store_id = ts.store_id
                                  AND o.table_id = ts.table_id
                                  AND o.session_token = tb.session_token),
                              ts.started_at) DESC
          LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sessions = [];
foreach ($stmt->fetchAll() as $row) {
    $state = _posla_support_guess_state($row);
    $sessions[] = [
        'id' => $row['id'],
        'tenant_id' => $row['tenant_id'],
        'tenant_name' => $row['tenant_name'],
        'store_id' => $row['store_id'],
        'store_name' => $row['store_name'],
        'table_id' => $row['table_id'],
        'table_code' => $row['table_code'],
        'session_status' => $row['session_status'],
        'guest_count' => (int)($row['guest_count'] ?? 0),
        'started_at' => $row['started_at'],
        'plan_name' => $row['plan_name'],
        'last_cart_at' => $row['last_cart_at'],
        'last_order_at' => $row['last_order_at'],
        'cart_event_count' => (int)$row['cart_event_count'],
        'open_order_count' => (int)$row['open_order_count'],
        'pending_order_count' => (int)$row['pending_order_count'],
        'preparing_order_count' => (int)$row['preparing_order_count'],
        'ready_order_count' => (int)$row['ready_order_count'],
        'total_amount' => (int)$row['total_amount'],
        'session_token_preview' => _posla_support_mask_token((string)$row['session_token']),
        'state' => $state,
    ];
}

$summary = [
    'total' => count($sessions),
    'bill_requested' => 0,
    'needs_attention' => 0,
];

foreach ($sessions as $row) {
    if ($row['session_status'] === 'bill_requested') {
        $summary['bill_requested']++;
    }
    if (in_array($row['state']['code'], ['bill_requested', 'browsing', 'inactive'], true)) {
        $summary['needs_attention']++;
    }
}

json_response([
    'sessions' => $sessions,
    'summary' => $summary,
    'current_admin_id' => $admin['admin_id'],
]);
