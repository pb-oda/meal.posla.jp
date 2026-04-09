<?php
/**
 * ABC分析・売上ダッシュボード API（オーナー専用）
 *
 * GET /api/owner/analytics-abc.php?store_id=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * ・メニュー品目ごとの販売数量・売上金額・原価・粗利を算出
 * ・累積構成比でABC分類（A: 0-70%, B: 70-90%, C: 90-100%）
 * ・サマリー（総売上, 総粗利, 注文単価, 注文件数）
 * ・カテゴリ別集計
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';

require_method(['GET']);
$user = require_role('manager'); // manager以上（managerは自店舗のみ）
$pdo = get_db();
$tenantId = $user['tenant_id'];
$userRole = $user['role'];

$storeId = $_GET['store_id'] ?? null;
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// 店舗指定がある場合はアクセスチェック
if ($storeId) {
    require_store_access($storeId);
}

// managerは店舗指定必須（全店舗合算はownerのみ）
if ($userRole === 'manager' && !$storeId) {
    // managerのアクセス可能店舗のうち最初の1つを使用
    $accessStores = $user['store_ids'] ?? [];
    if (!empty($accessStores)) {
        $storeId = $accessStores[0];
    } else {
        json_error('FORBIDDEN', 'アクセス可能な店舗がありません', 403);
    }
}

// ── テナントの全店舗取得（store_id 未指定時は全店舗合算 = ownerのみ） ──
$storeIds = [];
if ($storeId) {
    $storeIds = [$storeId];
} else {
    $stmt = $pdo->prepare('SELECT id FROM stores WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $storeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

if (empty($storeIds)) {
    json_response([
        'summary'    => ['totalRevenue' => 0, 'totalCost' => 0, 'grossProfit' => 0, 'orderCount' => 0, 'avgOrderValue' => 0],
        'items'      => [],
        'categories' => [],
        'period'     => ['from' => $from, 'to' => $to],
    ]);
}

// ── 営業日レンジ計算 ──
$rangeStart = $from . ' 00:00:00';
$rangeEnd   = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';

// 店舗指定時は営業日カットオフを使用
if ($storeId) {
    $range = get_business_day_range($pdo, $storeId, $from, $to);
    $rangeStart = $range['start'];
    $rangeEnd   = $range['end'];
}

// ── paid 注文取得 ──
$ph = implode(',', array_fill(0, count($storeIds), '?'));
$stmt = $pdo->prepare(
    "SELECT id, store_id, total_amount, items, created_at
     FROM orders
     WHERE store_id IN ($ph) AND status = 'paid'
       AND created_at >= ? AND created_at < ?
     ORDER BY created_at"
);
$stmt->execute(array_merge($storeIds, [$rangeStart, $rangeEnd]));
$orders = $stmt->fetchAll();

// ── メニューテンプレート辞書（id → {name, category_name, base_price}） ──
$stmt = $pdo->prepare(
    'SELECT mt.id, mt.name, mt.base_price, c.name AS category_name
     FROM menu_templates mt
     JOIN categories c ON c.id = mt.category_id
     WHERE mt.tenant_id = ?'
);
$stmt->execute([$tenantId]);
$templateDict = [];
foreach ($stmt->fetchAll() as $row) {
    $templateDict[$row['id']] = $row;
}

// ── レシピ原価辞書（menu_template_id → 1品あたり原価） ──
$recipeCostDict = [];
try {
    $pdo->query('SELECT 1 FROM recipes LIMIT 0');
    $pdo->query('SELECT 1 FROM ingredients LIMIT 0');

    $stmt = $pdo->prepare(
        'SELECT r.menu_template_id, SUM(r.quantity * i.cost_price) AS unit_cost
         FROM recipes r
         JOIN ingredients i ON i.id = r.ingredient_id
         WHERE i.tenant_id = ?
         GROUP BY r.menu_template_id'
    );
    $stmt->execute([$tenantId]);
    foreach ($stmt->fetchAll() as $row) {
        $recipeCostDict[$row['menu_template_id']] = (float)$row['unit_cost'];
    }
} catch (PDOException $e) {
    // recipes/ingredients 未作成時はスキップ（原価 = 0で計算）
    error_log('[P1-12][api/owner/analytics-abc.php:116] load_recipe_costs: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

// ── 品目ごとの集計 ──
$itemMap = [];    // item_id => {name, category, qty, revenue, cost}
$totalRevenue = 0;
$orderCount = count($orders);

foreach ($orders as $o) {
    $totalRevenue += (int)$o['total_amount'];

    $items = json_decode($o['items'] ?? '[]', true);
    if (!is_array($items)) continue;

    foreach ($items as $item) {
        $itemId = $item['id'] ?? null;
        $name   = $item['name'] ?? '不明';
        $price  = (int)($item['price'] ?? 0);
        $qty    = (int)($item['qty'] ?? 1);
        $lineRevenue = $price * $qty;

        // テンプレート情報
        $tpl = $itemId ? ($templateDict[$itemId] ?? null) : null;
        $category = $tpl ? $tpl['category_name'] : '(未分類)';

        // 1品あたり原価
        $unitCost = $itemId ? ($recipeCostDict[$itemId] ?? 0) : 0;
        $lineCost = $unitCost * $qty;

        $key = $itemId ?: ('name:' . $name);
        if (!isset($itemMap[$key])) {
            $itemMap[$key] = [
                'itemId'    => $itemId,
                'name'      => $name,
                'category'  => $category,
                'qty'       => 0,
                'revenue'   => 0,
                'cost'      => 0,
                'unitCost'  => round($unitCost, 2),
            ];
        }
        $itemMap[$key]['qty']     += $qty;
        $itemMap[$key]['revenue'] += $lineRevenue;
        $itemMap[$key]['cost']    += round($lineCost, 2);
    }
}

// ── 粗利計算 + 売上順ソート ──
$itemList = array_values($itemMap);
foreach ($itemList as &$it) {
    $it['profit']     = round($it['revenue'] - $it['cost'], 2);
    $it['profitRate'] = $it['revenue'] > 0 ? round($it['profit'] / $it['revenue'] * 100, 1) : 0;
}
unset($it);

usort($itemList, function ($a, $b) {
    return $b['revenue'] - $a['revenue'];
});

// ── ABC分類（売上ベースの累積構成比） ──
$grandRevenue = 0;
foreach ($itemList as $it) {
    $grandRevenue += $it['revenue'];
}

$cumulative = 0;
foreach ($itemList as &$it) {
    $pct = $grandRevenue > 0 ? ($it['revenue'] / $grandRevenue * 100) : 0;
    $cumulative += $pct;
    $it['revenuePct']    = round($pct, 1);
    $it['cumulativePct'] = round($cumulative, 1);

    if ($cumulative <= 70) {
        $it['rank'] = 'A';
    } elseif ($cumulative <= 90) {
        $it['rank'] = 'B';
    } else {
        $it['rank'] = 'C';
    }
}
unset($it);

// ── カテゴリ別集計 ──
$categoryMap = [];
foreach ($itemList as $it) {
    $cat = $it['category'];
    if (!isset($categoryMap[$cat])) {
        $categoryMap[$cat] = ['name' => $cat, 'qty' => 0, 'revenue' => 0, 'cost' => 0, 'profit' => 0, 'itemCount' => 0];
    }
    $categoryMap[$cat]['qty']       += $it['qty'];
    $categoryMap[$cat]['revenue']   += $it['revenue'];
    $categoryMap[$cat]['cost']      += $it['cost'];
    $categoryMap[$cat]['profit']    += $it['profit'];
    $categoryMap[$cat]['itemCount'] += 1;
}
usort($categoryMap, function ($a, $b) { return $b['revenue'] - $a['revenue']; });
$categoryMap = array_values($categoryMap);

// ── サマリー ──
$totalCost = 0;
foreach ($itemList as $it) {
    $totalCost += $it['cost'];
}
$grossProfit = $totalRevenue - $totalCost;
$avgOrderValue = $orderCount > 0 ? (int)round($totalRevenue / $orderCount) : 0;
$grossProfitRate = $totalRevenue > 0 ? round($grossProfit / $totalRevenue * 100, 1) : 0;

// ── ランク別サマリー ──
$rankSummary = ['A' => ['count' => 0, 'revenue' => 0, 'profit' => 0],
                'B' => ['count' => 0, 'revenue' => 0, 'profit' => 0],
                'C' => ['count' => 0, 'revenue' => 0, 'profit' => 0]];
foreach ($itemList as $it) {
    $r = $it['rank'];
    $rankSummary[$r]['count']++;
    $rankSummary[$r]['revenue'] += $it['revenue'];
    $rankSummary[$r]['profit']  += $it['profit'];
}

json_response([
    'summary' => [
        'totalRevenue'    => $totalRevenue,
        'totalCost'       => round($totalCost, 2),
        'grossProfit'     => round($grossProfit, 2),
        'grossProfitRate' => $grossProfitRate,
        'orderCount'      => $orderCount,
        'avgOrderValue'   => $avgOrderValue,
        'uniqueItems'     => count($itemList),
    ],
    'rankSummary' => $rankSummary,
    'items'       => $itemList,
    'categories'  => $categoryMap,
    'period'      => ['from' => $from, 'to' => $to],
]);
