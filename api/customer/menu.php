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

require_method(['GET']);

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
    error_log('[P1-12][customer/menu.php:169] fetch_recommendations: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
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
    error_log('[P1-12][customer/menu.php:182] fetch_google_place_id: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
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
    error_log('[P1-12][customer/menu.php:202] fetch_popularity: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
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
]);
