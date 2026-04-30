<?php
/**
 * 管理画面オペレーション集約 API
 *
 * GET /api/store/admin-ops.php?store_id=xxx&action=cockpit
 * action:
 *   cockpit         今日の運営コックピット
 *   search          注文/予約/顧客/商品/スタッフ/エラー横断検索
 *   sales_drilldown 日別→商品→注文→会計履歴
 *   setup           初期設定チェックリスト
 *   terminals       端末稼働状況
 *   cart            カート投入後キャンセル/削除分析
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

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'cockpit';
$tenantId = $user['tenant_id'];

function aops_date($value, $fallback = null)
{
    $value = $value ?: ($fallback ?: date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
        json_error('INVALID_DATE', '日付形式が不正です', 400);
    }
    return $value;
}

function aops_table_exists(PDO $pdo, $table)
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function aops_column_exists(PDO $pdo, $table, $column)
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function aops_one(PDO $pdo, $sql, array $params)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: [];
    } catch (PDOException $e) {
        error_log('[admin-ops one] ' . $e->getMessage());
        return [];
    }
}

function aops_all(PDO $pdo, $sql, array $params)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[admin-ops all] ' . $e->getMessage());
        return [];
    }
}

function aops_count(PDO $pdo, $sql, array $params)
{
    $row = aops_one($pdo, $sql, $params);
    return (int)($row['cnt'] ?? 0);
}

function aops_money(PDO $pdo, $sql, array $params)
{
    $row = aops_one($pdo, $sql, $params);
    return [
        'count' => (int)($row['cnt'] ?? 0),
        'total' => (int)($row['total'] ?? 0),
    ];
}

function aops_range(PDO $pdo, $storeId, $date)
{
    return get_business_day_range($pdo, $storeId, $date, $date);
}

function aops_status_level($count, $warnAt = 1)
{
    return ((int)$count >= $warnAt) ? 'warn' : 'ok';
}

if ($action === 'cockpit') {
    $date = aops_date($_GET['date'] ?? null);
    $range = aops_range($pdo, $storeId, $date);

    $unpaid = aops_money(
        $pdo,
        'SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
           FROM orders
          WHERE store_id = ? AND status NOT IN ("paid", "cancelled")',
        [$storeId]
    );

    $activeTables = aops_count(
        $pdo,
        'SELECT COUNT(*) AS cnt FROM table_sessions WHERE store_id = ? AND status NOT IN ("paid", "closed")',
        [$storeId]
    );

    $register = aops_one(
        $pdo,
        'SELECT
            MAX(CASE WHEN type = "open" THEN created_at ELSE NULL END) AS latest_open_at,
            MAX(CASE WHEN type = "close" THEN created_at ELSE NULL END) AS latest_close_at
           FROM cash_log
          WHERE store_id = ? AND created_at >= ? AND created_at < ?',
        [$storeId, $range['start'], $range['end']]
    );
    $registerOpen = !empty($register['latest_open_at'])
        && (empty($register['latest_close_at']) || strtotime($register['latest_open_at']) > strtotime($register['latest_close_at']));

    $reservations = aops_one(
        $pdo,
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN status IN ("pending", "confirmed", "seated") THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN reserved_at < NOW() AND status IN ("pending", "confirmed") THEN 1 ELSE 0 END) AS late_count
           FROM reservations
          WHERE store_id = ? AND reserved_at >= ? AND reserved_at < ?',
        [$storeId, $range['start'], $range['end']]
    );

    $shift = ['assignmentCount' => 0, 'noClockInCount' => 0, 'pendingRequestCount' => 0];
    if (aops_table_exists($pdo, 'shift_assignments')) {
        $shift['assignmentCount'] = aops_count(
            $pdo,
            'SELECT COUNT(*) AS cnt FROM shift_assignments WHERE tenant_id = ? AND store_id = ? AND shift_date = ? AND status IN ("published", "confirmed")',
            [$tenantId, $storeId, $date]
        );
        if (aops_table_exists($pdo, 'attendance_logs')) {
            $shift['noClockInCount'] = aops_count(
                $pdo,
                'SELECT COUNT(*) AS cnt
                   FROM shift_assignments sa
                   LEFT JOIN attendance_logs a
                     ON a.tenant_id = sa.tenant_id
                    AND a.store_id = sa.store_id
                    AND a.shift_assignment_id = sa.id
                  WHERE sa.tenant_id = ? AND sa.store_id = ? AND sa.shift_date = ?
                    AND sa.status IN ("published", "confirmed")
                    AND TIMESTAMP(sa.shift_date, sa.start_time) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    AND a.id IS NULL',
                [$tenantId, $storeId, $date]
            );
        }
        if (aops_table_exists($pdo, 'shift_swap_requests')) {
            $shift['pendingRequestCount'] = aops_count(
                $pdo,
                'SELECT COUNT(*) AS cnt FROM shift_swap_requests WHERE tenant_id = ? AND store_id = ? AND status = "pending"',
                [$tenantId, $storeId]
            );
        }
    }

    $soldOutCount = 0;
    $soldOutCount += aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM store_menu_overrides WHERE store_id = ? AND is_sold_out = 1', [$storeId]);
    $soldOutCount += aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM store_local_items WHERE store_id = ? AND is_sold_out = 1', [$storeId]);

    $lowStockCount = aops_count(
        $pdo,
        'SELECT COUNT(*) AS cnt
           FROM ingredients
          WHERE tenant_id = ? AND (low_stock_threshold IS NOT NULL AND stock_quantity <= low_stock_threshold)',
        [$tenantId]
    );

    $lowRatings = aops_count(
        $pdo,
        'SELECT COUNT(*) AS cnt FROM satisfaction_ratings WHERE store_id = ? AND rating <= 2 AND created_at >= ? AND created_at < ?',
        [$storeId, $range['start'], $range['end']]
    );

    $errors = aops_count(
        $pdo,
        'SELECT COUNT(*) AS cnt FROM error_log WHERE tenant_id = ? AND (store_id = ? OR store_id IS NULL) AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
        [$tenantId, $storeId]
    );

    $takeoutRisk = 0;
    if (aops_column_exists($pdo, 'orders', 'pickup_at')) {
        $takeoutRisk = aops_count(
            $pdo,
            'SELECT COUNT(*) AS cnt
               FROM orders
              WHERE store_id = ? AND order_type = "takeout"
                AND status NOT IN ("ready", "served", "paid", "cancelled")
                AND pickup_at IS NOT NULL
                AND pickup_at <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)',
            [$storeId]
        );
    }

    $cards = [
        ['key' => 'unpaid', 'label' => '未会計', 'value' => $unpaid['count'], 'sub' => '未会計合計 ' . $unpaid['total'] . '円', 'level' => aops_status_level($unpaid['count'])],
        ['key' => 'register', 'label' => '未締め', 'value' => $registerOpen ? 1 : 0, 'sub' => $registerOpen ? 'レジ開局中' : '本日締め済み/未開局', 'level' => $registerOpen ? 'warn' : 'ok'],
        ['key' => 'reservations', 'label' => '予約', 'value' => (int)($reservations['active_count'] ?? 0), 'sub' => '遅刻注意 ' . (int)($reservations['late_count'] ?? 0), 'level' => aops_status_level((int)($reservations['late_count'] ?? 0))],
        ['key' => 'shift', 'label' => 'シフト不足', 'value' => $shift['noClockInCount'] + $shift['pendingRequestCount'], 'sub' => '未打刻 ' . $shift['noClockInCount'] . ' / 申請 ' . $shift['pendingRequestCount'], 'level' => aops_status_level($shift['noClockInCount'] + $shift['pendingRequestCount'])],
        ['key' => 'soldout', 'label' => '品切れ/低在庫', 'value' => $soldOutCount + $lowStockCount, 'sub' => '品切れ ' . $soldOutCount . ' / 低在庫 ' . $lowStockCount, 'level' => aops_status_level($soldOutCount + $lowStockCount)],
        ['key' => 'rating', 'label' => '低評価', 'value' => $lowRatings, 'sub' => '本日 1-2 点', 'level' => aops_status_level($lowRatings)],
        ['key' => 'errors', 'label' => '障害/エラー', 'value' => $errors, 'sub' => '直近24時間', 'level' => aops_status_level($errors)],
        ['key' => 'takeout', 'label' => 'テイクアウト遅延', 'value' => $takeoutRisk, 'sub' => '15分以内/超過リスク', 'level' => aops_status_level($takeoutRisk)],
    ];

    json_response([
        'date' => $date,
        'cards' => $cards,
        'register' => [
            'isOpen' => $registerOpen,
            'latestOpenAt' => $register['latest_open_at'] ?? null,
            'latestCloseAt' => $register['latest_close_at'] ?? null,
        ],
        'tables' => ['activeCount' => $activeTables],
        'shift' => $shift,
    ]);
}

if ($action === 'search') {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if (mb_strlen($q) < 2) {
        json_response(['q' => $q, 'groups' => []]);
    }
    $like = '%' . $q . '%';
    $groups = [];

    $orders = aops_all(
        $pdo,
        'SELECT id, status, total_amount, created_at, customer_name, customer_phone
           FROM orders
          WHERE store_id = ? AND (id LIKE ? OR items LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?)
          ORDER BY created_at DESC LIMIT 8',
        [$storeId, $like, $like, $like, $like]
    );
    $groups[] = ['type' => 'orders', 'label' => '注文', 'items' => $orders];

    $reservations = aops_all(
        $pdo,
        'SELECT id, customer_name, customer_phone, party_size, reserved_at, status
           FROM reservations
          WHERE store_id = ? AND (customer_name LIKE ? OR customer_phone LIKE ? OR customer_email LIKE ? OR memo LIKE ?)
          ORDER BY reserved_at DESC LIMIT 8',
        [$storeId, $like, $like, $like, $like]
    );
    $groups[] = ['type' => 'reservations', 'label' => '予約', 'items' => $reservations];

    $customers = aops_all(
        $pdo,
        'SELECT id, customer_name, customer_phone, customer_email, visit_count, no_show_count
           FROM reservation_customers
          WHERE tenant_id = ? AND store_id = ? AND (customer_name LIKE ? OR customer_phone LIKE ? OR customer_email LIKE ? OR preferences LIKE ? OR internal_memo LIKE ?)
          ORDER BY updated_at DESC LIMIT 8',
        [$tenantId, $storeId, $like, $like, $like, $like, $like]
    );
    $groups[] = ['type' => 'customers', 'label' => '顧客', 'items' => $customers];

    $menu = aops_all(
        $pdo,
        'SELECT mt.id, mt.name, mt.base_price AS price, c.name AS category_name, "master" AS source
           FROM menu_templates mt
           JOIN categories c ON c.id = mt.category_id
          WHERE mt.tenant_id = ? AND (mt.name LIKE ? OR mt.name_en LIKE ? OR mt.description LIKE ?)
          ORDER BY c.sort_order, mt.sort_order LIMIT 8',
        [$tenantId, $like, $like, $like]
    );
    $localMenu = aops_all(
        $pdo,
        'SELECT sli.id, sli.name, sli.price, c.name AS category_name, "local" AS source
           FROM store_local_items sli
           JOIN categories c ON c.id = sli.category_id
          WHERE sli.store_id = ? AND (sli.name LIKE ? OR sli.name_en LIKE ? OR sli.description LIKE ?)
          ORDER BY c.sort_order, sli.sort_order LIMIT 8',
        [$storeId, $like, $like, $like]
    );
    $groups[] = ['type' => 'menu', 'label' => '商品', 'items' => array_slice(array_merge($menu, $localMenu), 0, 12)];

    $staff = aops_all(
        $pdo,
        'SELECT u.id, u.username, u.display_name, u.role
           FROM users u
           JOIN user_stores us ON us.user_id = u.id
          WHERE u.tenant_id = ? AND us.store_id = ? AND (u.username LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)
          ORDER BY u.display_name, u.username LIMIT 8',
        [$tenantId, $storeId, $like, $like, $like]
    );
    $groups[] = ['type' => 'staff', 'label' => 'スタッフ', 'items' => $staff];

    $errors = [];
    if (aops_table_exists($pdo, 'error_log')) {
        $errors = aops_all(
            $pdo,
            'SELECT error_no, code, message, http_status, created_at
               FROM error_log
              WHERE tenant_id = ? AND (store_id = ? OR store_id IS NULL)
                AND (error_no LIKE ? OR code LIKE ? OR message LIKE ? OR request_path LIKE ?)
              ORDER BY created_at DESC LIMIT 8',
            [$tenantId, $storeId, $like, $like, $like, $like]
        );
    }
    $groups[] = ['type' => 'errors', 'label' => 'エラー', 'items' => $errors];

    json_response(['q' => $q, 'groups' => $groups]);
}

if ($action === 'sales_drilldown') {
    $from = aops_date($_GET['from'] ?? null, date('Y-m-d', strtotime('-13 days')));
    $to = aops_date($_GET['to'] ?? null, date('Y-m-d'));
    $date = isset($_GET['date']) && $_GET['date'] !== '' ? aops_date($_GET['date']) : null;
    $item = isset($_GET['item']) ? trim((string)$_GET['item']) : '';
    $orderId = isset($_GET['order_id']) ? trim((string)$_GET['order_id']) : '';

    if ($orderId !== '') {
        $order = aops_one($pdo, 'SELECT * FROM orders WHERE id = ? AND store_id = ?', [$orderId, $storeId]);
        if ($order && isset($order['items'])) {
            $decoded = json_decode($order['items'], true);
            $order['items'] = is_array($decoded) ? $decoded : [];
        }
        $payments = aops_all(
            $pdo,
            'SELECT id, total_amount, payment_method, paid_at, refund_status, refund_amount, gateway_name, external_payment_id
               FROM payments
              WHERE store_id = ? AND order_ids LIKE ?
              ORDER BY paid_at DESC',
            [$storeId, '%' . $orderId . '%']
        );
        json_response(['mode' => 'order', 'order' => $order, 'payments' => $payments]);
    }

    if ($date !== null && $item !== '') {
        $range = aops_range($pdo, $storeId, $date);
        $orders = aops_all(
            $pdo,
            'SELECT id, table_id, customer_name, status, total_amount, payment_method, created_at, paid_at, items
               FROM orders
              WHERE store_id = ? AND created_at >= ? AND created_at < ? AND items LIKE ?
              ORDER BY created_at DESC LIMIT 50',
            [$storeId, $range['start'], $range['end'], '%' . $item . '%']
        );
        foreach ($orders as &$order) {
            $decoded = json_decode($order['items'], true);
            $order['items'] = is_array($decoded) ? $decoded : [];
        }
        unset($order);
        json_response(['mode' => 'orders', 'date' => $date, 'item' => $item, 'orders' => $orders]);
    }

    if ($date !== null) {
        $range = aops_range($pdo, $storeId, $date);
        $orders = aops_all(
            $pdo,
            'SELECT id, items, total_amount, created_at
               FROM orders
              WHERE store_id = ? AND status = "paid" AND created_at >= ? AND created_at < ?
              ORDER BY created_at',
            [$storeId, $range['start'], $range['end']]
        );
        $items = [];
        foreach ($orders as $order) {
            $decoded = json_decode($order['items'], true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $it) {
                $name = (string)($it['name'] ?? '不明');
                if (!isset($items[$name])) $items[$name] = ['name' => $name, 'qty' => 0, 'revenue' => 0, 'orderCount' => 0];
                $qty = (int)($it['qty'] ?? 1);
                $price = (int)($it['price'] ?? 0);
                $items[$name]['qty'] += $qty;
                $items[$name]['revenue'] += $price * $qty;
                $items[$name]['orderCount'] += 1;
            }
        }
        usort($items, function ($a, $b) { return $b['revenue'] - $a['revenue']; });
        json_response(['mode' => 'items', 'date' => $date, 'items' => array_slice(array_values($items), 0, 50)]);
    }

    $fromRange = get_business_day_range($pdo, $storeId, $from, $to);
    $days = aops_all(
        $pdo,
        'SELECT DATE(created_at) AS sales_date, COUNT(*) AS order_count, COALESCE(SUM(total_amount), 0) AS revenue
           FROM orders
          WHERE store_id = ? AND status = "paid" AND created_at >= ? AND created_at < ?
          GROUP BY DATE(created_at)
          ORDER BY sales_date DESC',
        [$storeId, $fromRange['start'], $fromRange['end']]
    );
    json_response(['mode' => 'days', 'from' => $from, 'to' => $to, 'days' => $days]);
}

if ($action === 'setup') {
    $counts = [
        'store' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM stores WHERE id = ? AND tenant_id = ? AND is_active = 1', [$storeId, $tenantId]),
        'categories' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM categories WHERE tenant_id = ?', [$tenantId]),
        'menu' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM menu_templates WHERE tenant_id = ?', [$tenantId]),
        'localMenu' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM store_local_items WHERE store_id = ?', [$storeId]),
        'tables' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM tables WHERE store_id = ? AND is_active = 1', [$storeId]),
        'staff' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM users u JOIN user_stores us ON us.user_id = u.id WHERE u.tenant_id = ? AND us.store_id = ? AND u.role IN ("manager", "staff")', [$tenantId, $storeId]),
        'kds' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM kds_stations WHERE store_id = ? AND is_active = 1', [$storeId]),
        'plans' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM time_limit_plans WHERE store_id = ? AND is_active = 1', [$storeId]),
        'courses' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM course_templates WHERE store_id = ? AND is_active = 1', [$storeId]),
        'ingredients' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM ingredients WHERE tenant_id = ?', [$tenantId]),
        'recipes' => aops_count($pdo, 'SELECT COUNT(*) AS cnt FROM recipes r JOIN menu_templates mt ON mt.id = r.menu_template_id WHERE mt.tenant_id = ?', [$tenantId]),
    ];
    $settings = aops_one($pdo, 'SELECT takeout_enabled FROM store_settings WHERE store_id = ?', [$storeId]);
    $reservation = aops_one($pdo, 'SELECT online_enabled FROM reservation_settings WHERE store_id = ?', [$storeId]);
    $items = [
        ['key' => 'store', 'label' => '店舗基本情報', 'done' => $counts['store'] > 0, 'detail' => '店舗が有効'],
        ['key' => 'categories', 'label' => 'カテゴリ', 'done' => $counts['categories'] > 0, 'detail' => $counts['categories'] . '件'],
        ['key' => 'menu', 'label' => 'メニュー', 'done' => ($counts['menu'] + $counts['localMenu']) > 0, 'detail' => '本部 ' . $counts['menu'] . ' / 限定 ' . $counts['localMenu']],
        ['key' => 'tables', 'label' => '卓・QR', 'done' => $counts['tables'] > 0, 'detail' => $counts['tables'] . '卓'],
        ['key' => 'staff', 'label' => 'スタッフ', 'done' => $counts['staff'] > 0, 'detail' => $counts['staff'] . '人'],
        ['key' => 'kds', 'label' => 'KDS', 'done' => $counts['kds'] > 0, 'detail' => $counts['kds'] . 'ステーション'],
        ['key' => 'reservation', 'label' => '予約', 'done' => !empty($reservation), 'detail' => !empty($reservation['online_enabled']) ? 'オンライン予約ON' : '設定あり/オンラインOFF'],
        ['key' => 'takeout', 'label' => 'テイクアウト', 'done' => !empty($settings) && (int)($settings['takeout_enabled'] ?? 0) === 1, 'detail' => !empty($settings) ? '設定あり' : '未設定'],
        ['key' => 'inventory', 'label' => '在庫', 'done' => $counts['ingredients'] > 0, 'detail' => $counts['ingredients'] . '原材料'],
        ['key' => 'recipes', 'label' => 'レシピ', 'done' => $counts['recipes'] > 0, 'detail' => $counts['recipes'] . '件'],
        ['key' => 'plans', 'label' => 'プラン', 'done' => $counts['plans'] > 0, 'detail' => $counts['plans'] . '件'],
        ['key' => 'courses', 'label' => 'コース', 'done' => $counts['courses'] > 0, 'detail' => $counts['courses'] . '件'],
    ];
    json_response(['items' => $items, 'counts' => $counts]);
}

if ($action === 'terminals') {
    $terminals = [];
    if (aops_table_exists($pdo, 'handy_terminals')) {
        $rows = aops_all(
            $pdo,
            'SELECT device_uid, device_label, terminal_mode, last_seen_at, updated_at
               FROM handy_terminals
              WHERE tenant_id = ? AND store_id = ?
              ORDER BY last_seen_at DESC, updated_at DESC LIMIT 50',
            [$tenantId, $storeId]
        );
        foreach ($rows as $row) {
            $terminals[] = [
                'type' => $row['terminal_mode'],
                'label' => $row['device_label'] ?: $row['device_uid'],
                'deviceUid' => $row['device_uid'],
                'lastSeenAt' => $row['last_seen_at'],
                'status' => !empty($row['last_seen_at']) && strtotime($row['last_seen_at']) >= time() - 600 ? 'online' : 'stale',
            ];
        }
    }
    if (aops_table_exists($pdo, 'user_sessions')) {
        $deviceRows = aops_all(
            $pdo,
            'SELECT us.device_label, us.last_active_at, u.username, u.display_name, u.role
               FROM user_sessions us
               JOIN users u ON u.id = us.user_id
               LEFT JOIN user_stores ust ON ust.user_id = u.id AND ust.store_id = ?
              WHERE us.tenant_id = ? AND us.is_active = 1
                AND (u.role = "owner" OR ust.store_id IS NOT NULL)
              ORDER BY us.last_active_at DESC LIMIT 50',
            [$storeId, $tenantId]
        );
        foreach ($deviceRows as $row) {
            $terminals[] = [
                'type' => $row['role'] === 'device' ? 'device' : 'session',
                'label' => $row['device_label'] ?: ($row['display_name'] ?: $row['username']),
                'deviceUid' => null,
                'lastSeenAt' => $row['last_active_at'],
                'status' => !empty($row['last_active_at']) && strtotime($row['last_active_at']) >= time() - 600 ? 'online' : 'stale',
            ];
        }
    }
    $selfMenuLast = aops_one($pdo, 'SELECT MAX(created_at) AS last_seen_at FROM cart_events WHERE store_id = ?', [$storeId]);
    $summary = [
        ['type' => 'kds', 'label' => 'KDS', 'count' => 0],
        ['type' => 'handy', 'label' => 'ハンディ', 'count' => 0],
        ['type' => 'register', 'label' => 'レジ', 'count' => 0],
        ['type' => 'self_menu', 'label' => 'セルフメニュー', 'count' => !empty($selfMenuLast['last_seen_at']) ? 1 : 0, 'lastSeenAt' => $selfMenuLast['last_seen_at'] ?? null],
    ];
    foreach ($terminals as $terminal) {
        for ($i = 0; $i < count($summary); $i++) {
            if ($terminal['type'] === $summary[$i]['type'] || ($terminal['type'] === 'wall' && $summary[$i]['type'] === 'kds')) {
                $summary[$i]['count'] += 1;
                if (empty($summary[$i]['lastSeenAt']) || strtotime((string)$terminal['lastSeenAt']) > strtotime((string)$summary[$i]['lastSeenAt'])) {
                    $summary[$i]['lastSeenAt'] = $terminal['lastSeenAt'];
                }
            }
        }
    }
    json_response(['summary' => $summary, 'terminals' => $terminals]);
}

if ($action === 'cart') {
    $from = aops_date($_GET['from'] ?? null, date('Y-m-d', strtotime('-6 days')));
    $to = aops_date($_GET['to'] ?? null, date('Y-m-d'));
    $range = get_business_day_range($pdo, $storeId, $from, $to);
    $rows = aops_all(
        $pdo,
        'SELECT item_id, item_name,
                SUM(CASE WHEN action = "add" THEN 1 ELSE 0 END) AS add_count,
                SUM(CASE WHEN action = "remove" THEN 1 ELSE 0 END) AS remove_count,
                MAX(created_at) AS last_seen_at
           FROM cart_events
          WHERE store_id = ? AND created_at >= ? AND created_at < ?
          GROUP BY item_id, item_name
          ORDER BY remove_count DESC, add_count DESC
          LIMIT 50',
        [$storeId, $range['start'], $range['end']]
    );
    $summary = ['addCount' => 0, 'removeCount' => 0, 'items' => count($rows)];
    foreach ($rows as &$row) {
        $row['add_count'] = (int)$row['add_count'];
        $row['remove_count'] = (int)$row['remove_count'];
        $row['remove_rate'] = $row['add_count'] > 0 ? round($row['remove_count'] / $row['add_count'] * 100, 1) : 0;
        $summary['addCount'] += $row['add_count'];
        $summary['removeCount'] += $row['remove_count'];
    }
    unset($row);

    $removedFromOrders = [];
    if (aops_column_exists($pdo, 'orders', 'removed_items')) {
        $orders = aops_all(
            $pdo,
            'SELECT id, removed_items, created_at
               FROM orders
              WHERE store_id = ? AND removed_items IS NOT NULL AND created_at >= ? AND created_at < ?
              ORDER BY created_at DESC LIMIT 100',
            [$storeId, $range['start'], $range['end']]
        );
        foreach ($orders as $order) {
            $decoded = json_decode($order['removed_items'], true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $item) {
                $removedFromOrders[] = [
                    'orderId' => $order['id'],
                    'name' => $item['name'] ?? '不明',
                    'qty' => (int)($item['qty'] ?? 1),
                    'createdAt' => $order['created_at'],
                ];
            }
        }
    }

    json_response(['from' => $from, 'to' => $to, 'summary' => $summary, 'items' => $rows, 'removedFromOrders' => $removedFromOrders]);
}

json_error('INVALID_ACTION', 'action が不正です', 400);
