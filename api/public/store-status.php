<?php
/**
 * 公開API: 店舗ステータス（認証不要）
 *
 * GET /api/public/store-status.php?store_id=xxx
 *
 * 混雑状況・人気メニュー・品切れ情報を返す。
 * 個人情報は一切返さない。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/business-day.php';
require_once __DIR__ . '/../lib/menu-resolver.php';

require_method(['GET']);

$storeId = $_GET['store_id'] ?? null;
if (!$storeId) {
    json_error('MISSING_STORE', 'store_id は必須です', 400);
}

$pdo = get_db();

// ── 1. 店舗の存在確認 ──
$stmt = $pdo->prepare(
    'SELECT s.id, s.name, s.tenant_id,
            ss.receipt_store_name, ss.receipt_address, ss.receipt_phone
     FROM stores s
     LEFT JOIN store_settings ss ON ss.store_id = s.id
     WHERE s.id = ? AND s.is_active = 1'
);
$stmt->execute([$storeId]);
$store = $stmt->fetch();

if (!$store) {
    json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
}

$displayName = $store['receipt_store_name'] ?: $store['name'];

// ── 2. テーブル占有状況 ──
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM tables WHERE store_id = ? AND is_active = 1'
);
$stmt->execute([$storeId]);
$totalTables = (int) $stmt->fetchColumn();

// アクティブセッション数（table_sessions が未作成の場合はフォールバック）
$occupiedTables = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT ts.table_id)
         FROM table_sessions ts
         INNER JOIN tables t ON t.id = ts.table_id AND t.is_active = 1
         WHERE ts.store_id = ? AND ts.status NOT IN ('paid', 'closed')"
    );
    $stmt->execute([$storeId]);
    $occupiedTables = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    // table_sessions テーブル未作成 → 0
    error_log('[P1-12][api/public/store-status.php:60] count_occupied_tables: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

$availableTables = $totalTables - $occupiedTables;
if ($availableTables < 0) $availableTables = 0;

// ── 3. 混雑度計算 ──
if ($totalTables === 0) {
    $rate = 0;
} else {
    $rate = ($occupiedTables / $totalTables) * 100;
}

if ($rate <= 30) {
    $congestion      = 'empty';
    $congestionLabel = '空いています';
    $waitMinutes     = 0;
} elseif ($rate <= 60) {
    $congestion      = 'normal';
    $congestionLabel = '普通';
    $waitMinutes     = 0;
} elseif ($rate <= 80) {
    $congestion      = 'busy';
    $congestionLabel = '混んでいます';
    $waitMinutes     = 10;
} else {
    $congestion      = 'full';
    $congestionLabel = '満席に近い';
    $waitMinutes     = 20;
}

// ── 4. 本日の人気メニュー（売上上位3品） ──
$businessDay = get_business_day($pdo, $storeId);
$bdStart = $businessDay['start'];
$bdEnd   = $businessDay['end'];

$stmt = $pdo->prepare(
    "SELECT items FROM orders
     WHERE store_id = ? AND status != 'cancelled'
       AND created_at >= ? AND created_at < ?"
);
$stmt->execute([$storeId, $bdStart, $bdEnd]);
$orderRows = $stmt->fetchAll();

$itemCounts = [];
foreach ($orderRows as $row) {
    $items = json_decode($row['items'], true);
    if (!is_array($items)) continue;
    foreach ($items as $item) {
        $name  = $item['name'] ?? '';
        $price = (int) ($item['price'] ?? 0);
        $qty   = (int) ($item['qty'] ?? 1);
        if ($name === '') continue;
        $key = $name . '|' . $price;
        if (!isset($itemCounts[$key])) {
            $itemCounts[$key] = ['name' => $name, 'price' => $price, 'count' => 0];
        }
        $itemCounts[$key]['count'] += $qty;
    }
}

// 売上数量で降順ソート → 上位3件
usort($itemCounts, function ($a, $b) {
    return $b['count'] - $a['count'];
});
$popularItems = [];
$rank = 0;
foreach ($itemCounts as $ic) {
    if ($rank >= 3) break;
    $popularItems[] = [
        'name'     => $ic['name'],
        'price'    => $ic['price'],
        'sold_out' => false,
    ];
    $rank++;
}

// ── 5. 品切れ品目 ──
$categories   = resolve_store_menu($pdo, $storeId);
$soldOutItems = [];
$soldOutMap   = [];

foreach ($categories as $cat) {
    foreach ($cat['items'] as $mi) {
        $miName = $mi['name'] ?? '';
        // 人気メニューの品切れフラグも更新
        if (!empty($mi['soldOut'])) {
            $soldOutItems[] = [
                'name'  => $miName,
                'price' => (int) ($mi['price'] ?? 0),
            ];
            $soldOutMap[$miName] = true;
        }
    }
}

// 人気メニューの sold_out フラグを反映
foreach ($popularItems as &$pi) {
    if (isset($soldOutMap[$pi['name']])) {
        $pi['sold_out'] = true;
    }
}
unset($pi);

// ── レスポンス ──
json_response([
    'store' => [
        'name'           => $displayName,
        'address'        => $store['receipt_address'] ?: null,
        'phone'          => $store['receipt_phone'] ?: null,
        'business_hours' => null,
    ],
    'tables' => [
        'total'     => $totalTables,
        'occupied'  => $occupiedTables,
        'available' => $availableTables,
    ],
    'congestion'       => $congestion,
    'congestion_label' => $congestionLabel,
    'wait_minutes'     => $waitMinutes,
    'popular_items'    => $popularItems,
    'sold_out_items'   => $soldOutItems,
]);
