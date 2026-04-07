<?php
/**
 * 原材料マスタ API（オーナー専用）
 *
 * GET    /api/owner/ingredients.php              — 一覧
 * GET    /api/owner/ingredients.php?id=xxx       — 単体取得
 * POST   /api/owner/ingredients.php              — 新規
 * PATCH  /api/owner/ingredients.php?id=xxx       — 更新
 * DELETE /api/owner/ingredients.php?id=xxx       — 削除
 *
 * 棚卸し（一括在庫更新）:
 * PATCH  /api/owner/ingredients.php?action=stocktake
 * Body: { items: [{id, stock_quantity}] }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/inventory-sync.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager'); // manager以上
$pdo = get_db();
$tenantId = $user['tenant_id'];

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM ingredients LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'ingredients テーブルが未作成です。migration-c5-inventory.sql を実行してください。', 500);
}

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare(
            'SELECT i.*, (SELECT COUNT(*) FROM recipes r WHERE r.ingredient_id = i.id) AS recipe_count
             FROM ingredients i WHERE i.id = ? AND i.tenant_id = ?'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        if (!$row) json_error('NOT_FOUND', '原材料が見つかりません', 404);
        json_response(['ingredient' => $row]);
    }

    $stmt = $pdo->prepare(
        'SELECT i.*, (SELECT COUNT(*) FROM recipes r WHERE r.ingredient_id = i.id) AS recipe_count
         FROM ingredients i WHERE i.tenant_id = ? ORDER BY i.sort_order, i.name'
    );
    $stmt->execute([$tenantId]);
    json_response(['ingredients' => $stmt->fetchAll()]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    if (!$name) json_error('MISSING_FIELDS', '原材料名は必須です', 400);

    $unit = trim($data['unit'] ?? '個');
    $stockQty = isset($data['stock_quantity']) ? (float)$data['stock_quantity'] : 0;
    $costPrice = isset($data['cost_price']) ? (float)$data['cost_price'] : 0;
    $lowThreshold = isset($data['low_stock_threshold']) ? (float)$data['low_stock_threshold'] : null;
    $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $tenantId, $name, $unit, $stockQty, $costPrice, $lowThreshold, $sortOrder]);

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $action = $_GET['action'] ?? null;

    // 棚卸し一括更新
    if ($action === 'stocktake') {
        $data = get_json_body();
        $items = $data['items'] ?? [];
        if (empty($items)) json_error('MISSING_FIELDS', 'items が必要です', 400);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE ingredients SET stock_quantity = ? WHERE id = ? AND tenant_id = ?'
            );
            $updated = 0;
            foreach ($items as $item) {
                $iid = $item['id'] ?? null;
                $qty = isset($item['stock_quantity']) ? (float)$item['stock_quantity'] : null;
                if (!$iid || $qty === null) continue;
                $stmt->execute([$qty, $iid, $tenantId]);
                $updated += $stmt->rowCount();
            }
            $pdo->commit();

            // 在庫→品切れ自動同期（S-5: 棚卸し後は品切れ解除も実行）
            try {
                sync_sold_out_from_inventory($pdo, $tenantId, null, true);
            } catch (Exception $e) {
                // sync失敗は棚卸し結果に影響しない
            }

            json_response(['ok' => true, 'updated' => $updated]);
        } catch (Exception $e) {
            $pdo->rollBack();
            json_error('DB_ERROR', '棚卸し更新に失敗しました', 500);
        }
    }

    // 単体更新
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'id が必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM ingredients WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', '原材料が見つかりません', 404);

    $data = get_json_body();
    $fields = [];
    $params = [];

    $stringCols = ['name', 'unit'];
    foreach ($stringCols as $col) {
        if (isset($data[$col])) {
            $fields[] = "$col = ?";
            $params[] = trim($data[$col]);
        }
    }
    $numCols = ['stock_quantity', 'cost_price', 'low_stock_threshold', 'sort_order'];
    foreach ($numCols as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            $params[] = $data[$col] === null ? null : (float)$data[$col];
        }
    }
    if (isset($data['is_active'])) {
        $fields[] = 'is_active = ?';
        $params[] = $data['is_active'] ? 1 : 0;
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

    $stockChanged = array_key_exists('stock_quantity', $data) || array_key_exists('low_stock_threshold', $data);

    $params[] = $id;
    $pdo->prepare('UPDATE ingredients SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    // 在庫→品切れ自動同期（S-5: 在庫関連フィールド変更時のみ）
    if ($stockChanged) {
        try {
            sync_sold_out_from_inventory($pdo, $tenantId, null, true);
        } catch (Exception $e) {
            // sync失敗は更新結果に影響しない
        }
    }

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'id が必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM ingredients WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', '原材料が見つかりません', 404);

    $pdo->beginTransaction();
    try {
        // レシピ紐付けも削除
        $pdo->prepare('DELETE FROM recipes WHERE ingredient_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ingredients WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('DELETE_FAILED', '削除に失敗しました', 500);
    }

    json_response(['ok' => true]);
}
