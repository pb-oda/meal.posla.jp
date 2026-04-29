<?php
/**
 * 顧客用メニュー取得 API（認証なし）
 *
 * GET /api/customer/menu.php?store_id=xxx
 *
 * menu-resolver を使用してテンプレート+オーバーライド+ローカルを統合。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/menu-resolver.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['GET']);

// H-03: DB 過負荷 DoS 防御 — 1IP あたり 60 回 / 5 分（メニュー閲覧頻度高）
check_rate_limit('customer-menu', 60, 300);

$storeId = $_GET['store_id'] ?? null;
if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);

$pdo = get_db();

// 店舗の存在確認
$stmt = $pdo->prepare('SELECT id, name, name_en, slug FROM stores WHERE id = ? AND is_active = 1');
$stmt->execute([$storeId]);
$store = $stmt->fetch();
if (!$store) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);

// 店舗設定取得
$stmt = $pdo->prepare(
    'SELECT max_items_per_order, max_amount_per_order, tax_rate FROM store_settings WHERE store_id = ?'
);
$stmt->execute([$storeId]);
$settings = $stmt->fetch() ?: [];

// プラン専用メニューモード
$planId = $_GET['plan_id'] ?? null;
if ($planId) {
    // プランの存在確認（store_idスコープ）
    $stmt = $pdo->prepare('SELECT id FROM time_limit_plans WHERE id = ? AND store_id = ? AND is_active = 1');
    $stmt->execute([$planId, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'プランが見つかりません', 404);

    // プラン専用メニューをカテゴリ別にグループ化
    $stmt = $pdo->prepare(
        'SELECT pmi.id AS menu_item_id, pmi.name, pmi.name_en, pmi.description, pmi.image_url,
                pmi.category_id, pmi.sort_order,
                c.name AS category_name, c.name_en AS category_name_en, c.sort_order AS cat_sort
         FROM plan_menu_items pmi
         LEFT JOIN categories c ON c.id = pmi.category_id
         WHERE pmi.plan_id = ? AND pmi.is_active = 1
         ORDER BY COALESCE(c.sort_order, 9999), pmi.sort_order, pmi.name'
    );
    $stmt->execute([$planId]);
    $rows = $stmt->fetchAll();

    $catMap = [];
    foreach ($rows as $row) {
        $catId = $row['category_id'] ?: '__none__';
        if (!isset($catMap[$catId])) {
            $catMap[$catId] = [
                'categoryId'     => $catId,
                'categoryName'   => $row['category_name'] ?: '未分類',
                'categoryNameEn' => $row['category_name_en'] ?: '',
                'items'          => [],
            ];
        }
        $catMap[$catId]['items'][] = [
            'menuItemId'  => $row['menu_item_id'],
            'name'        => $row['name'],
            'nameEn'      => $row['name_en'] ?? '',
            'description' => $row['description'] ?? '',
            'descriptionEn' => '',
            'imageUrl'    => $row['image_url'],
            'price'       => 0,
            'soldOut'     => false,
        ];
    }

    json_response([
        'store' => [
            'id'     => $store['id'],
            'name'   => $store['name'],
            'nameEn' => $store['name_en'] ?? '',
        ],
        'settings' => [
            'maxItems'  => (int)($settings['max_items_per_order'] ?? 10),
            'maxAmount' => 0,
            'taxRate'   => 0,
        ],
        'categories' => array_values($catMap),
        'planMode'   => true,
    ]);
    exit;
}

// コース専用メニューモード
$courseId = $_GET['course_id'] ?? null;
if ($courseId) {
    $stmt = $pdo->prepare('SELECT id, name, name_en FROM course_templates WHERE id = ? AND store_id = ? AND is_active = 1');
    $stmt->execute([$courseId, $storeId]);
    if (!$stmt->fetch()) json_error('COURSE_NOT_FOUND', 'コースが見つかりません', 404);

    // 全フェーズ取得しフェーズ別にカテゴリ化
    $stmt = $pdo->prepare(
        'SELECT phase_number, name, name_en, items
         FROM course_phases WHERE course_id = ? ORDER BY phase_number'
    );
    $stmt->execute([$courseId]);
    $phases = $stmt->fetchAll();

    $catMap = [];
    foreach ($phases as $ph) {
        $phNum = (int)$ph['phase_number'];
        $catKey = 'phase_' . $phNum;
        $items = json_decode($ph['items'], true) ?: [];
        $menuItems = [];
        foreach ($items as $item) {
            $menuItems[] = [
                'menuItemId'    => $item['id'] ?? ('course_' . $phNum . '_' . count($menuItems)),
                'name'          => $item['name'] ?? '',
                'nameEn'        => $item['name_en'] ?? '',
                'description'   => '',
                'descriptionEn' => '',
                'imageUrl'      => null,
                'price'         => 0,
                'soldOut'       => false,
                'qty'           => (int)($item['qty'] ?? 1),
            ];
        }
        $catMap[$catKey] = [
            'categoryId'     => $catKey,
            'categoryName'   => $ph['name'],
            'categoryNameEn' => $ph['name_en'] ?? '',
            'phaseNumber'    => $phNum,
            'items'          => $menuItems,
        ];
    }

    json_response([
        'store' => [
            'id'     => $store['id'],
            'name'   => $store['name'],
            'nameEn' => $store['name_en'] ?? '',
        ],
        'settings' => [
            'maxItems'  => 0,
            'maxAmount' => 0,
            'taxRate'   => 0,
        ],
        'categories' => array_values($catMap),
        'courseMode'  => true,
    ]);
    exit;
}

// 通常メニュー解決
$categories = resolve_store_menu($pdo, $storeId);

// N-1: 今日のおすすめ
$recommendations = [];
try {
    $recStmt = $pdo->prepare(
        "SELECT menu_item_id, source, badge_type, comment, sort_order
         FROM daily_recommendations
         WHERE store_id = ? AND display_date = CURDATE()
         ORDER BY sort_order ASC"
    );
    $recStmt->execute([$storeId]);
    $recommendations = $recStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // テーブル未作成時は空配列のまま
    error_log('[P1-12][customer/menu.php:169] fetch_recommendations: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// L-7: Google Place ID（レビュー誘導用）
$googlePlaceId = null;
try {
    $gpStmt = $pdo->prepare('SELECT google_place_id FROM store_settings WHERE store_id = ?');
    $gpStmt->execute([$storeId]);
    $gpRow = $gpStmt->fetch();
    if ($gpRow && !empty($gpRow['google_place_id'])) {
        $googlePlaceId = $gpRow['google_place_id'];
    }
} catch (PDOException $e) {
    // カラム未存在時はスキップ
    error_log('[P1-12][customer/menu.php:182] fetch_google_place_id: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// N-6: 人気ランキング（過去30日の注文数上位10）
$popularity = [];
try {
    $popStmt = $pdo->prepare(
        "SELECT menu_item_id, COUNT(*) AS order_count
         FROM order_items
         WHERE store_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status != 'cancelled'
         GROUP BY menu_item_id
         ORDER BY order_count DESC
         LIMIT 10"
    );
    $popStmt->execute([$storeId]);
    $popRows = $popStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($popRows as $i => $row) {
        $popularity[$row['menu_item_id']] = $i + 1;
    }
} catch (PDOException $e) {
    // order_items テーブル未作成時は空配列のまま
    error_log('[P1-12][customer/menu.php:202] fetch_popularity: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

// SELF-MENU-4: 品目別の目安提供時間（過去30日の完成/提供実績から算出）
$prepTimes = [];
try {
    $prepStmt = $pdo->prepare(
        "SELECT oi.menu_item_id,
                COUNT(*) AS sample_count,
                AVG(TIMESTAMPDIFF(MINUTE, oi.created_at, COALESCE(oi.ready_at, oi.served_at))) AS avg_min
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id AND o.store_id = oi.store_id
         WHERE oi.store_id = ?
           AND o.store_id = ?
           AND oi.menu_item_id IS NOT NULL
           AND oi.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND oi.status IN ('ready', 'served')
           AND (oi.ready_at IS NOT NULL OR oi.served_at IS NOT NULL)
         GROUP BY oi.menu_item_id
         HAVING sample_count >= 2 AND avg_min IS NOT NULL"
    );
    $prepStmt->execute([$storeId, $storeId]);
    $prepRows = $prepStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prepRows as $row) {
        $avgMin = (int)round((float)$row['avg_min']);
        if ($avgMin <= 0) continue;
        $prepTimes[$row['menu_item_id']] = [
            'avgMinutes'  => $avgMin,
            'sampleCount' => (int)$row['sample_count'],
        ];
    }
} catch (PDOException $e) {
    // order_items の運用前や旧環境では目安時間なしで表示を継続
    error_log('[SELF-MENU-4][customer/menu.php] fetch_prep_times: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

$alternativeMap = [];
try {
    $altStmt = $pdo->prepare(
        "SELECT source_item_id, alternative_item_id, alternative_source, sort_order
         FROM menu_alternatives
         WHERE store_id = ?
         ORDER BY source_item_id, sort_order ASC"
    );
    $altStmt->execute([$storeId]);
    $altRows = $altStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($altRows as $row) {
        $sourceId = (string)$row['source_item_id'];
        if (!isset($alternativeMap[$sourceId])) $alternativeMap[$sourceId] = [];
        $alternativeMap[$sourceId][] = [
            'itemId'    => $row['alternative_item_id'],
            'source'    => $row['alternative_source'],
            'sortOrder' => (int)$row['sort_order'],
        ];
    }
} catch (PDOException $e) {
    // migration 未適用環境では自動候補のみで継続
    error_log('[SELF-MENU-GUIDANCE][customer/menu.php] fetch_alternatives: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

$pairRecommendations = [];
try {
    $pairStmt = $pdo->prepare(
        "SELECT a.menu_item_id AS item_id,
                b.menu_item_id AS pair_item_id,
                COUNT(DISTINCT a.order_id) AS support
         FROM order_items a
         INNER JOIN order_items b
           ON b.order_id = a.order_id
          AND b.store_id = a.store_id
          AND b.menu_item_id <> a.menu_item_id
         WHERE a.store_id = ?
           AND a.menu_item_id IS NOT NULL
           AND b.menu_item_id IS NOT NULL
           AND a.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
           AND a.status != 'cancelled'
           AND b.status != 'cancelled'
         GROUP BY a.menu_item_id, b.menu_item_id
         HAVING support >= 2
         ORDER BY support DESC
         LIMIT 300"
    );
    $pairStmt->execute([$storeId]);
    $pairRows = $pairStmt->fetchAll(PDO::FETCH_ASSOC);
    $pairCounts = [];
    foreach ($pairRows as $row) {
        $itemId = (string)$row['item_id'];
        if (!isset($pairRecommendations[$itemId])) {
            $pairRecommendations[$itemId] = [];
            $pairCounts[$itemId] = 0;
        }
        if ($pairCounts[$itemId] >= 3) continue;
        $pairRecommendations[$itemId][] = [
            'itemId'  => $row['pair_item_id'],
            'support' => (int)$row['support'],
        ];
        $pairCounts[$itemId]++;
    }
} catch (PDOException $e) {
    // 注文実績がない環境では非表示で継続
    error_log('[SELF-MENU-GUIDANCE][customer/menu.php] fetch_pair_recommendations: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

json_response([
    'store' => [
        'id'     => $store['id'],
        'name'   => $store['name'],
        'nameEn' => $store['name_en'] ?? '',
    ],
    'settings' => [
        'maxItems'      => (int)($settings['max_items_per_order'] ?? 10),
        'maxAmount'     => (int)($settings['max_amount_per_order'] ?? 30000),
        'taxRate'       => (float)($settings['tax_rate'] ?? 10),
        'googlePlaceId' => $googlePlaceId,
    ],
    'categories'      => $categories,
    'recommendations' => $recommendations,
    'popularity'      => $popularity,
    'prepTimes'       => $prepTimes,
    'alternativeMap'  => $alternativeMap,
    'pairRecommendations' => $pairRecommendations,
]);
