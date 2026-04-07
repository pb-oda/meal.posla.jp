<?php
/**
 * レシピ構成 API（オーナー専用）
 *
 * GET    /api/owner/recipes.php?menu_template_id=xxx  — メニュー品目のレシピ一覧
 * POST   /api/owner/recipes.php                       — レシピ行追加
 * PATCH  /api/owner/recipes.php?id=xxx                — 数量更新
 * DELETE /api/owner/recipes.php?id=xxx                — レシピ行削除
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager'); // manager以上
$pdo = get_db();
$tenantId = $user['tenant_id'];

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM recipes LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'recipes テーブルが未作成です。migration-c5-inventory.sql を実行してください。', 500);
}

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $menuTemplateId = $_GET['menu_template_id'] ?? null;
    if (!$menuTemplateId) json_error('MISSING_FIELDS', 'menu_template_id は必須です', 400);

    // テナントチェック
    $stmt = $pdo->prepare('SELECT id FROM menu_templates WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$menuTemplateId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューが見つかりません', 404);

    $stmt = $pdo->prepare(
        'SELECT r.id, r.menu_template_id, r.ingredient_id, r.quantity,
                i.name AS ingredient_name, i.unit AS ingredient_unit,
                i.stock_quantity, i.cost_price
         FROM recipes r
         JOIN ingredients i ON i.id = r.ingredient_id
         WHERE r.menu_template_id = ? AND i.tenant_id = ?
         ORDER BY i.name'
    );
    $stmt->execute([$menuTemplateId, $tenantId]);
    json_response(['recipes' => $stmt->fetchAll()]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $menuTemplateId = $data['menu_template_id'] ?? null;
    $ingredientId = $data['ingredient_id'] ?? null;
    $quantity = isset($data['quantity']) ? (float)$data['quantity'] : 1;

    if (!$menuTemplateId || !$ingredientId) {
        json_error('MISSING_FIELDS', 'menu_template_id と ingredient_id は必須です', 400);
    }

    // テナントチェック
    $stmt = $pdo->prepare('SELECT id FROM menu_templates WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$menuTemplateId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューが見つかりません', 404);

    $stmt = $pdo->prepare('SELECT id FROM ingredients WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$ingredientId, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', '原材料が見つかりません', 404);

    // 重複チェック
    $stmt = $pdo->prepare('SELECT id FROM recipes WHERE menu_template_id = ? AND ingredient_id = ?');
    $stmt->execute([$menuTemplateId, $ingredientId]);
    if ($stmt->fetch()) json_error('DUPLICATE', 'この原材料は既にレシピに登録されています', 409);

    $id = generate_uuid();
    $pdo->prepare(
        'INSERT INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES (?, ?, ?, ?)'
    )->execute([$id, $menuTemplateId, $ingredientId, $quantity]);

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'id が必要です', 400);

    // テナントチェック（recipes → ingredients.tenant_id）
    $stmt = $pdo->prepare(
        'SELECT r.id FROM recipes r
         JOIN ingredients i ON i.id = r.ingredient_id
         WHERE r.id = ? AND i.tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'レシピが見つかりません', 404);

    $data = get_json_body();
    if (!isset($data['quantity'])) json_error('MISSING_FIELDS', 'quantity は必須です', 400);

    $pdo->prepare('UPDATE recipes SET quantity = ? WHERE id = ?')
        ->execute([(float)$data['quantity'], $id]);

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'id が必要です', 400);

    $stmt = $pdo->prepare(
        'SELECT r.id FROM recipes r
         JOIN ingredients i ON i.id = r.ingredient_id
         WHERE r.id = ? AND i.tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'レシピが見つかりません', 404);

    $pdo->prepare('DELETE FROM recipes WHERE id = ?')->execute([$id]);

    json_response(['ok' => true]);
}
