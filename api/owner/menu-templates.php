<?php
/**
 * 本部メニューテンプレート API（オーナー専用）
 *
 * GET    /api/owner/menu-templates.php                 — 一覧
 * POST   /api/owner/menu-templates.php                 — 新規
 * PATCH  /api/owner/menu-templates.php?id=xxx          — 更新
 * DELETE /api/owner/menu-templates.php?id=xxx          — 削除
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];

function menu_template_has_self_menu_attrs(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT spice_level FROM menu_templates LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function normalize_self_menu_attrs(array $data): array
{
    $spice = isset($data['spice_level']) ? (int)$data['spice_level'] : 0;
    if ($spice < 0) $spice = 0;
    if ($spice > 3) $spice = 3;

    $prep = null;
    if (array_key_exists('prep_time_min', $data) && $data['prep_time_min'] !== null && $data['prep_time_min'] !== '') {
        $prep = (int)$data['prep_time_min'];
        if ($prep <= 0) $prep = null;
        if ($prep !== null && $prep > 999) $prep = 999;
    }

    return [
        'spice_level'      => $spice,
        'is_vegetarian'    => !empty($data['is_vegetarian']) ? 1 : 0,
        'is_kids_friendly' => !empty($data['is_kids_friendly']) ? 1 : 0,
        'is_quick_serve'   => !empty($data['is_quick_serve']) ? 1 : 0,
        'prep_time_min'    => $prep,
    ];
}

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $categoryId = $_GET['category_id'] ?? null;

    $sql = 'SELECT mt.*, c.name AS category_name
            FROM menu_templates mt
            JOIN categories c ON c.id = mt.category_id
            WHERE mt.tenant_id = ?';
    $params = [$tenantId];

    if ($categoryId) {
        $sql .= ' AND mt.category_id = ?';
        $params[] = $categoryId;
    }

    $sql .= ' ORDER BY c.sort_order, mt.sort_order, mt.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['templates' => $stmt->fetchAll()]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // P1-29: enterprise (hq_menu_broadcast=true) では owner のみ本部マスタを変更可能
    if (check_plan_feature($pdo, $tenantId, 'hq_menu_broadcast') && $user['role'] !== 'owner') {
        json_error('FORBIDDEN_HQ_MENU', '本部メニューは owner ロールでのみ編集できます', 403);
    }
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    $categoryId = $data['category_id'] ?? '';
    $basePrice = isset($data['base_price']) ? (int)$data['base_price'] : 0;

    if (!$name) json_error('MISSING_FIELDS', 'メニュー名は必須です', 400);
    if (!$categoryId) json_error('MISSING_FIELDS', 'カテゴリは必須です', 400);

    // カテゴリがテナントのものか確認
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$categoryId, $tenantId]);
    if (!$stmt->fetch()) json_error('INVALID_CATEGORY', '無効なカテゴリです', 400);

    $nextOrder = 0;
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_templates WHERE category_id = ?');
    $stmt->execute([$categoryId]);
    $nextOrder = (int)$stmt->fetchColumn();

    $id = generate_uuid();
    $cols = ['id', 'tenant_id', 'category_id', 'name', 'name_en', 'description', 'description_en', 'base_price', 'image_url', 'sort_order'];
    $values = [
        $id, $tenantId, $categoryId,
        $name,
        trim($data['name_en'] ?? ''),
        trim($data['description'] ?? ''),
        trim($data['description_en'] ?? ''),
        $basePrice,
        trim($data['image_url'] ?? ''),
        $nextOrder
    ];
    if (menu_template_has_self_menu_attrs($pdo)) {
        $attrs = normalize_self_menu_attrs($data);
        foreach ($attrs as $col => $val) {
            $cols[] = $col;
            $values[] = $val;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO menu_templates (' . implode(', ', $cols) . ')
         VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
    );
    $stmt->execute($values);

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    // P1-29: enterprise (hq_menu_broadcast=true) では owner のみ本部マスタを変更可能
    if (check_plan_feature($pdo, $tenantId, 'hq_menu_broadcast') && $user['role'] !== 'owner') {
        json_error('FORBIDDEN_HQ_MENU', '本部メニューは owner ロールでのみ編集できます', 403);
    }
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM menu_templates WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューが見つかりません', 404);

    $data = get_json_body();
    $fields = [];
    $params = [];

    $stringCols = ['name', 'name_en', 'description', 'description_en', 'image_url'];
    foreach ($stringCols as $col) {
        if (isset($data[$col])) {
            $fields[] = "$col = ?";
            $params[] = trim($data[$col]);
        }
    }
    if (isset($data['base_price'])) {
        $fields[] = 'base_price = ?';
        $params[] = (int)$data['base_price'];
    }
    if (isset($data['category_id'])) {
        $chk = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND tenant_id = ?');
        $chk->execute([$data['category_id'], $tenantId]);
        if (!$chk->fetch()) json_error('INVALID_CATEGORY', '無効なカテゴリです', 400);
        $fields[] = 'category_id = ?';
        $params[] = $data['category_id'];
    }
    if (isset($data['sort_order'])) {
        $fields[] = 'sort_order = ?';
        $params[] = (int)$data['sort_order'];
    }
    if (isset($data['is_sold_out'])) {
        $fields[] = 'is_sold_out = ?';
        $params[] = $data['is_sold_out'] ? 1 : 0;
    }
    if (menu_template_has_self_menu_attrs($pdo)) {
        $attrInputMap = [
            'spice_level'      => 'spice_level',
            'is_vegetarian'    => 'is_vegetarian',
            'is_kids_friendly' => 'is_kids_friendly',
            'is_quick_serve'   => 'is_quick_serve',
            'prep_time_min'    => 'prep_time_min',
        ];
        $attrs = normalize_self_menu_attrs($data);
        foreach ($attrInputMap as $inputKey => $col) {
            if (array_key_exists($inputKey, $data)) {
                $fields[] = $col . ' = ?';
                $params[] = $attrs[$col];
            }
        }
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

    $params[] = $id;
    $pdo->prepare('UPDATE menu_templates SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // P1-29: enterprise (hq_menu_broadcast=true) では owner のみ本部マスタを変更可能
    if (check_plan_feature($pdo, $tenantId, 'hq_menu_broadcast') && $user['role'] !== 'owner') {
        json_error('FORBIDDEN_HQ_MENU', '本部メニューは owner ロールでのみ編集できます', 403);
    }
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM menu_templates WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'メニューが見つかりません', 404);

    $pdo->beginTransaction();
    try {
        // オーバーライドも削除
        $pdo->prepare('DELETE FROM store_menu_overrides WHERE template_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM menu_templates WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('DELETE_FAILED', '削除に失敗しました', 500);
    }

    json_response(['ok' => true]);
}
