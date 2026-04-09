<?php
/**
 * L-15: スマレジ商品 → POSLAメニューテンプレート インポート
 *
 * POST /api/smaregi/import-menu.php
 * body: { "store_id": "POSLA店舗ID" }
 *
 * owner認証必須。指定店舗のスマレジ商品をPOSLAメニューテンプレートとして取り込む。
 * - smaregi_product_mapping に既存エントリがあればスキップ（重複防止）
 * - カテゴリは名前で既存チェック、なければ新規作成
 * - 既存のPOSLAメニューは一切削除しない（追加のみ）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/smaregi-client.php';

// P1-20: 自動翻訳トリガー用。translate-menu.php の POST ハンドラ起動を抑止して
// translate_menu_core() 関数のみを利用する。
if (!defined('TRANSLATE_MENU_CORE_ONLY')) {
    define('TRANSLATE_MENU_CORE_ONLY', true);
}
require_once __DIR__ . '/../store/translate-menu.php';

$user = require_role('owner');
require_method(['POST']);

$pdo = get_db();
$tenantId = $user['tenant_id'];
$input = get_json_body();

$storeId = isset($input['store_id']) ? $input['store_id'] : '';
if (!$storeId) {
    json_error('MISSING_STORE', 'store_id は必須です', 400);
}

// POSLA店舗の所属チェック
$stmt = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND tenant_id = ? AND is_active = 1');
$stmt->execute([$storeId, $tenantId]);
if (!$stmt->fetch()) {
    json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
}

// 店舗マッピングからスマレジ店舗IDを取得
$stmt = $pdo->prepare(
    'SELECT smaregi_store_id FROM smaregi_store_mapping
     WHERE tenant_id = ? AND store_id = ?'
);
$stmt->execute([$tenantId, $storeId]);
$mapping = $stmt->fetch();
if (!$mapping) {
    json_error('NO_MAPPING', 'この店舗はスマレジ店舗にマッピングされていません', 400);
}
$smaregiStoreId = $mapping['smaregi_store_id'];

// スマレジ連携チェック
$smaregi = get_tenant_smaregi($pdo, $tenantId);
if (!$smaregi) {
    json_error('NOT_CONNECTED', 'スマレジ未連携です', 400);
}

// スマレジAPI: 商品一覧取得（store_idでフィルタ）
$path = '/pos/products?limit=1000';
$result = smaregi_api_request($pdo, $tenantId, 'GET', $path, null);
if (!$result['ok']) {
    $errMsg = isset($result['data']['error']) ? $result['data']['error'] : 'スマレジ商品取得失敗';
    $detail = json_encode($result['data'], JSON_UNESCAPED_UNICODE);
    json_error('SMAREGI_API_ERROR', $errMsg . ' (HTTP=' . $result['status'] . ' detail=' . $detail . ')', 502);
}

$products = is_array($result['data']) ? $result['data'] : [];

// 既存マッピング取得（重複チェック用）
$stmt = $pdo->prepare(
    'SELECT smaregi_product_id FROM smaregi_product_mapping
     WHERE tenant_id = ? AND smaregi_store_id = ?'
);
$stmt->execute([$tenantId, $smaregiStoreId]);
$existingMappings = [];
while ($row = $stmt->fetch()) {
    $existingMappings[$row['smaregi_product_id']] = true;
}

// テナントの既存カテゴリを名前で索引
$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE tenant_id = ?');
$stmt->execute([$tenantId]);
$categoryByName = [];
while ($row = $stmt->fetch()) {
    $categoryByName[$row['name']] = $row['id'];
}

// カテゴリのsort_order最大値
$stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) AS max_order FROM categories WHERE tenant_id = ?');
$stmt->execute([$tenantId]);
$catNextOrder = (int)$stmt->fetch()['max_order'] + 1;

// メニューテンプレートのsort_order最大値（カテゴリ別に管理）
$menuNextOrders = [];

$imported = 0;
$skipped = 0;
$errors = 0;

foreach ($products as $product) {
    $productId = isset($product['productId']) ? $product['productId'] : null;
    if (!$productId) {
        $errors++;
        continue;
    }

    // 既にマッピング済みならスキップ
    if (isset($existingMappings[$productId])) {
        $skipped++;
        continue;
    }

    $productName = isset($product['productName']) ? trim($product['productName']) : '';
    $price = isset($product['price']) ? (int)$product['price'] : 0;
    $categoryName = isset($product['categoryName']) ? trim($product['categoryName']) : '';

    if (!$productName) {
        $errors++;
        continue;
    }

    // カテゴリ名が空の場合はデフォルトカテゴリ
    if (!$categoryName) {
        $categoryName = 'スマレジインポート';
    }

    try {
        $pdo->beginTransaction();

        // カテゴリ取得 or 作成
        $categoryId = null;
        if (isset($categoryByName[$categoryName])) {
            $categoryId = $categoryByName[$categoryName];
        } else {
            $categoryId = generate_uuid();
            $stmt = $pdo->prepare(
                'INSERT INTO categories (id, tenant_id, name, sort_order) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$categoryId, $tenantId, $categoryName, $catNextOrder]);
            $categoryByName[$categoryName] = $categoryId;
            $catNextOrder++;
        }

        // メニューテンプレートのsort_order
        if (!isset($menuNextOrders[$categoryId])) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) AS max_order FROM menu_templates WHERE tenant_id = ? AND category_id = ?'
            );
            $stmt->execute([$tenantId, $categoryId]);
            $menuNextOrders[$categoryId] = (int)$stmt->fetch()['max_order'] + 1;
        }
        $sortOrder = $menuNextOrders[$categoryId];
        $menuNextOrders[$categoryId]++;

        // メニューテンプレート作成
        $menuId = generate_uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO menu_templates (id, tenant_id, category_id, name, base_price, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$menuId, $tenantId, $categoryId, $productName, $price, $sortOrder]);

        // スマレジ商品マッピング作成
        $mappingId = generate_uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO smaregi_product_mapping (id, tenant_id, menu_template_id, smaregi_product_id, smaregi_store_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$mappingId, $tenantId, $menuId, $productId, $smaregiStoreId]);

        $pdo->commit();

        $existingMappings[$productId] = true;
        $imported++;
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors++;
    }
}

// last_menu_sync 更新
$stmt = $pdo->prepare(
    'UPDATE smaregi_store_mapping SET last_menu_sync = NOW()
     WHERE tenant_id = ? AND store_id = ?'
);
$stmt->execute([$tenantId, $storeId]);

// ===== P1-20: 自動翻訳トリガー =====
// 新規インポートが0件の場合は呼出スキップ（無駄なAPI呼出回避）
$autoTranslate = null;
if ($imported > 0) {
    try {
        $autoTranslate = translate_menu_core($pdo, $tenantId, $storeId, ['en'], false);
    } catch (\Exception $e) {
        error_log('[P1-20][api/smaregi/import-menu.php] auto_translate_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        $autoTranslate = ['ok' => false, 'warning' => 'auto_translate_failed'];
    }
}

json_response([
    'message'        => 'メニューインポートが完了しました',
    'imported'       => $imported,
    'skipped'        => $skipped,
    'errors'         => $errors,
    'auto_translate' => $autoTranslate,
]);
