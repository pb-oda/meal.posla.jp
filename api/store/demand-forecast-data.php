<?php
/**
 * 需要予測用データ集計 API
 * M-6: AI需要予測・発注提案
 *
 * GET /api/store/demand-forecast-data.php?store_id=xxx
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
$tenantId = $user['tenant_id'];

$today = date('Y-m-d');
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$range = get_business_day_range($pdo, $storeId, $sevenDaysAgo, $today);

// ===== ドリンクカテゴリの品目名を取得（除外用） =====
$drinkNames = [];
try {
    $stmt = $pdo->prepare(
        'SELECT mt.name FROM menu_templates mt
         JOIN categories c ON c.id = mt.category_id
         WHERE mt.tenant_id = ? AND (c.name LIKE ? OR c.id LIKE ?)'
    );
    $stmt->execute([$tenantId, '%ドリンク%', '%drink%']);
    $drinkRows = $stmt->fetchAll();
    foreach ($drinkRows as $dr) {
        $drinkNames[$dr['name']] = true;
    }
} catch (PDOException $e) {}

// ===== A: 直近7日間の品目別日別売上（ドリンク除外） =====
$stmt = $pdo->prepare(
    'SELECT id, items, total_amount, DATE(created_at) AS order_date
     FROM orders
     WHERE store_id = ? AND status = ? AND created_at >= ? AND created_at < ?
     ORDER BY created_at'
);
$stmt->execute([$storeId, 'paid', $range['start'], $range['end']]);
$orders = $stmt->fetchAll();

$dailyItemMap = [];

foreach ($orders as $o) {
    $date = $o['order_date'];
    $items = json_decode($o['items'], true) ?: [];

    if (!isset($dailyItemMap[$date])) {
        $dailyItemMap[$date] = [];
    }

    foreach ($items as $item) {
        $name = isset($item['name']) ? $item['name'] : '不明';
        // ドリンクカテゴリの品目を除外
        if (isset($drinkNames[$name])) continue;
        $qty  = isset($item['qty']) ? (int)$item['qty'] : 1;
        if (!isset($dailyItemMap[$date][$name])) {
            $dailyItemMap[$date][$name] = 0;
        }
        $dailyItemMap[$date][$name] += $qty;
    }
}

// dailySales 形式に変換
$dailySales = [];
ksort($dailyItemMap);
foreach ($dailyItemMap as $date => $items) {
    $itemList = [];
    foreach ($items as $name => $qty) {
        $itemList[] = ['name' => $name, 'qty' => $qty];
    }
    usort($itemList, function ($a, $b) { return $b['qty'] - $a['qty']; });
    $dailySales[] = ['date' => $date, 'items' => $itemList];
}

// ===== B: 曜日別パターン =====
$weekdayOrdersByDate = [];
foreach ($orders as $o) {
    $date = $o['order_date'];
    $wdNum = (int)date('w', strtotime($date));
    if (!isset($weekdayOrdersByDate[$wdNum])) {
        $weekdayOrdersByDate[$wdNum] = [];
    }
    if (!isset($weekdayOrdersByDate[$wdNum][$date])) {
        $weekdayOrdersByDate[$wdNum][$date] = ['count' => 0, 'revenue' => 0];
    }
    $weekdayOrdersByDate[$wdNum][$date]['count']++;
    $weekdayOrdersByDate[$wdNum][$date]['revenue'] += (int)$o['total_amount'];
}

$weekdayNames = ['日', '月', '火', '水', '木', '金', '土'];
$weekdayPattern = [];
for ($w = 0; $w < 7; $w++) {
    if (!isset($weekdayOrdersByDate[$w])) continue;
    $dates = $weekdayOrdersByDate[$w];
    $dayCount = count($dates);
    $totalOrders = 0;
    $totalRevenue = 0;
    foreach ($dates as $d) {
        $totalOrders += $d['count'];
        $totalRevenue += $d['revenue'];
    }
    $weekdayPattern[] = [
        'weekday'     => $weekdayNames[$w],
        'weekdayNum'  => $w,
        'totalOrders' => $totalOrders,
        'avgRevenue'  => $dayCount > 0 ? round($totalRevenue / $dayCount) : 0,
    ];
}

// ===== C: 在庫状況（try-catch: テーブル不存在対応） =====
$inventory = [];
try {
    $pdo->query('SELECT 1 FROM ingredients LIMIT 0');
    $stmt = $pdo->prepare(
        'SELECT id, name, unit, stock_quantity, cost_price, low_stock_threshold
         FROM ingredients
         WHERE tenant_id = ? AND is_active = 1
         ORDER BY name'
    );
    $stmt->execute([$tenantId]);
    $invRows = $stmt->fetchAll();
    foreach ($invRows as $inv) {
        $inventory[] = [
            'id'                => $inv['id'],
            'name'              => $inv['name'],
            'unit'              => $inv['unit'],
            'stockQuantity'     => (float)$inv['stock_quantity'],
            'costPrice'         => (int)$inv['cost_price'],
            'lowStockThreshold' => (float)$inv['low_stock_threshold'],
        ];
    }
} catch (PDOException $e) {}

// ===== D: レシピ（BOM）情報（try-catch: テーブル不存在対応） =====
$recipes = [];
try {
    $pdo->query('SELECT 1 FROM recipes LIMIT 0');
    $stmt = $pdo->prepare(
        'SELECT mt.name AS menu_name, i.name AS ingredient_name, r.quantity, i.unit
         FROM recipes r
         JOIN menu_templates mt ON mt.id = r.menu_template_id
         JOIN ingredients i ON i.id = r.ingredient_id
         JOIN categories c ON c.id = mt.category_id
         WHERE mt.tenant_id = ? AND c.name NOT LIKE ? AND c.id NOT LIKE ?
         ORDER BY mt.name, i.name'
    );
    $stmt->execute([$tenantId, '%ドリンク%', '%drink%']);
    $recipeRows = $stmt->fetchAll();
    foreach ($recipeRows as $rr) {
        $recipes[] = [
            'menuName'        => $rr['menu_name'],
            'ingredientName'  => $rr['ingredient_name'],
            'quantityPerDish' => (float)$rr['quantity'],
            'unit'            => $rr['unit'],
        ];
    }
} catch (PDOException $e) {}

// ===== E: 本日の情報 =====
$todayWeekdayNum = (int)date('w');
$todayInfo = [
    'date'       => $today,
    'weekday'    => $weekdayNames[$todayWeekdayNum],
    'weekdayNum' => $todayWeekdayNum,
];

// 店舗名取得
$storeName = '';
try {
    $stmt = $pdo->prepare(
        'SELECT receipt_store_name FROM store_settings WHERE store_id = ? LIMIT 1'
    );
    $stmt->execute([$storeId]);
    $storeRow = $stmt->fetch();
    $storeName = $storeRow ? ($storeRow['receipt_store_name'] ?: '') : '';
} catch (PDOException $e) {}

json_response([
    'dailySales'     => $dailySales,
    'weekdayPattern' => $weekdayPattern,
    'inventory'      => $inventory,
    'recipes'        => $recipes,
    'today'          => $todayInfo,
    'storeName'      => $storeName,
]);
