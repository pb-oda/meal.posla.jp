<?php
/**
 * 食べ放題プラン専用メニュー API
 *
 * GET    /api/store/plan-menu-items.php?plan_id=xxx&store_id=xxx — プランのメニュー一覧
 * POST   /api/store/plan-menu-items.php                         — メニュー追加
 * PATCH  /api/store/plan-menu-items.php?id=xxx                  — メニュー更新
 * DELETE /api/store/plan-menu-items.php?id=xxx&store_id=xxx     — メニュー削除
 *
 * マネージャー以上。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();

// テーブル存在チェック（migration未適用時のフォールバック）
try {
    $pdo->query('SELECT 1 FROM plan_menu_items LIMIT 0');
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['items' => []]);
    }
    json_error('MIGRATION_REQUIRED', 'この機能にはデータベースの更新が必要です', 500);
}

// ---- GET: プランメニュー一覧 ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    $planId = $_GET['plan_id'] ?? null;
    if (!$planId) json_error('MISSING_PLAN', 'plan_idが必要です', 400);

    // プランの存在確認（store_idスコープ）
    $stmt = $pdo->prepare('SELECT id FROM time_limit_plans WHERE id = ? AND store_id = ?');
    $stmt->execute([$planId, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'プランが見つかりません', 404);

    $stmt = $pdo->prepare(
        'SELECT pmi.*, c.name AS category_name, c.name_en AS category_name_en
         FROM plan_menu_items pmi
         LEFT JOIN categories c ON c.id = pmi.category_id
         WHERE pmi.plan_id = ?
         ORDER BY pmi.sort_order, pmi.name'
    );
    $stmt->execute([$planId]);
    json_response(['items' => $stmt->fetchAll()]);
}

// ---- POST: メニュー追加 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $planId = $data['plan_id'] ?? null;
    $name = trim($data['name'] ?? '');

    if (!$storeId || !$planId || !$name) {
        json_error('VALIDATION', 'store_id, plan_id, name は必須です', 400);
    }
    require_store_access($storeId);

    // プランの存在確認
    $stmt = $pdo->prepare('SELECT id FROM time_limit_plans WHERE id = ? AND store_id = ?');
    $stmt->execute([$planId, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'プランが見つかりません', 404);

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO plan_menu_items (id, plan_id, category_id, name, name_en, description, image_url, sort_order, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $planId,
        $data['category_id'] ?? null,
        $name,
        trim($data['name_en'] ?? ''),
        $data['description'] ?? null,
        $data['image_url'] ?? null,
        (int)($data['sort_order'] ?? 0),
        1
    ]);

    json_response(['id' => $id], 201);
}

// ---- PATCH: メニュー更新 ----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    require_store_access($storeId);

    // 存在確認（store_idスコープ: plan経由で検証）
    $stmt = $pdo->prepare(
        'SELECT pmi.id FROM plan_menu_items pmi
         JOIN time_limit_plans tlp ON tlp.id = pmi.plan_id
         WHERE pmi.id = ? AND tlp.store_id = ?'
    );
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューが見つかりません', 404);

    $allowed = ['name', 'name_en', 'category_id', 'description', 'image_url', 'sort_order', 'is_active'];
    $fields = [];
    $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            $params[] = $data[$col];
        }
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);
    $params[] = $id;
    $pdo->prepare('UPDATE plan_menu_items SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ---- DELETE ----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id || !$storeId) json_error('MISSING_PARAMS', 'idとstore_idが必要です', 400);
    require_store_access($storeId);

    // store_idスコープ
    $stmt = $pdo->prepare(
        'DELETE pmi FROM plan_menu_items pmi
         JOIN time_limit_plans tlp ON tlp.id = pmi.plan_id
         WHERE pmi.id = ? AND tlp.store_id = ?'
    );
    $stmt->execute([$id, $storeId]);

    json_response(['ok' => true]);
}
