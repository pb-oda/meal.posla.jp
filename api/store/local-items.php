<?php
/**
 * 店舗限定メニュー API
 *
 * GET    /api/store/local-items.php?store_id=xxx       — 一覧
 * POST   /api/store/local-items.php                    — 新規
 * PATCH  /api/store/local-items.php?id=xxx             — 更新
 * DELETE /api/store/local-items.php?id=xxx             — 削除
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $stmt = $pdo->prepare(
        'SELECT sli.*, c.name AS category_name
         FROM store_local_items sli
         JOIN categories c ON c.id = sli.category_id
         WHERE sli.store_id = ?
         ORDER BY c.sort_order, sli.sort_order, sli.name'
    );
    $stmt->execute([$storeId]);
    json_response(['items' => $stmt->fetchAll()]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $name = trim($data['name'] ?? '');
    $categoryId = $data['category_id'] ?? '';

    if (!$storeId || !$name || !$categoryId) json_error('MISSING_FIELDS', 'store_id, メニュー名, カテゴリは必須です', 400);
    require_store_access($storeId);

    $id = generate_uuid();

    // N-7: カロリー・アレルギーカラム存在チェック
    $hasNutrition = false;
    try { $pdo->query('SELECT calories FROM store_local_items LIMIT 0'); $hasNutrition = true; } catch (PDOException $e) {}

    if ($hasNutrition) {
        $calories = array_key_exists('calories', $data) && $data['calories'] !== null && $data['calories'] !== '' ? (int)$data['calories'] : null;
        $allergens = !empty($data['allergens']) && is_array($data['allergens']) ? json_encode($data['allergens'], JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare(
            'INSERT INTO store_local_items (id, store_id, category_id, name, name_en, description, description_en, price, image_url, sort_order, calories, allergens)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $storeId, $categoryId,
            $name,
            trim($data['name_en'] ?? ''),
            trim($data['description'] ?? ''),
            trim($data['description_en'] ?? ''),
            isset($data['price']) ? (int)$data['price'] : 0,
            trim($data['image_url'] ?? ''),
            0,
            $calories,
            $allergens
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO store_local_items (id, store_id, category_id, name, name_en, description, description_en, price, image_url, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $storeId, $categoryId,
            $name,
            trim($data['name_en'] ?? ''),
            trim($data['description'] ?? ''),
            trim($data['description_en'] ?? ''),
            isset($data['price']) ? (int)$data['price'] : 0,
            trim($data['image_url'] ?? ''),
            0
        ]);
    }

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    require_store_access($storeId);

    $stmt = $pdo->prepare('SELECT id FROM store_local_items WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューが見つかりません', 404);

    $fields = [];
    $params = [];
    $stringCols = ['name', 'name_en', 'description', 'description_en', 'image_url', 'category_id'];
    foreach ($stringCols as $col) {
        if (isset($data[$col])) {
            $fields[] = "$col = ?";
            $params[] = trim($data[$col]);
        }
    }
    if (isset($data['price'])) {
        $fields[] = 'price = ?';
        $params[] = (int)$data['price'];
    }
    if (isset($data['is_sold_out'])) {
        $fields[] = 'is_sold_out = ?';
        $params[] = $data['is_sold_out'] ? 1 : 0;
    }
    if (isset($data['sort_order'])) {
        $fields[] = 'sort_order = ?';
        $params[] = (int)$data['sort_order'];
    }
    // N-7: カロリー・アレルギー
    try {
        $pdo->query('SELECT calories FROM store_local_items LIMIT 0');
        if (array_key_exists('calories', $data)) {
            $fields[] = 'calories = ?';
            $params[] = $data['calories'] !== null && $data['calories'] !== '' ? (int)$data['calories'] : null;
        }
        if (array_key_exists('allergens', $data)) {
            $fields[] = 'allergens = ?';
            $params[] = is_array($data['allergens']) ? json_encode($data['allergens'], JSON_UNESCAPED_UNICODE) : null;
        }
    } catch (PDOException $e) {
        // カラム未作成時はスキップ
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

    $params[] = $id;
    $pdo->prepare('UPDATE store_local_items SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id || !$storeId) json_error('MISSING_FIELDS', 'idとstore_idが必要です', 400);
    require_store_access($storeId);

    $stmt = $pdo->prepare('SELECT id FROM store_local_items WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューが見つかりません', 404);

    $pdo->prepare('DELETE FROM store_local_items WHERE id = ?')->execute([$id]);

    json_response(['ok' => true]);
}
