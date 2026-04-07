<?php
/**
 * カテゴリ管理 API（オーナー専用、テナント単位）
 *
 * GET    /api/owner/categories.php            — 一覧
 * POST   /api/owner/categories.php            — 新規
 * PATCH  /api/owner/categories.php?id=xxx     — 更新
 * DELETE /api/owner/categories.php?id=xxx     — 削除
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, name, name_en, sort_order FROM categories WHERE tenant_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$tenantId]);
    json_response(['categories' => $stmt->fetchAll()]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    if (!$name) json_error('MISSING_FIELDS', 'カテゴリ名は必須です', 400);

    // 次のsort_order
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $nextOrder = (int)$stmt->fetchColumn();

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO categories (id, tenant_id, name, name_en, sort_order) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $tenantId, $name, trim($data['name_en'] ?? ''), $nextOrder]);

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'カテゴリが見つかりません', 404);

    $data = get_json_body();
    $fields = [];
    $params = [];

    if (isset($data['name'])) {
        $fields[] = 'name = ?';
        $params[] = trim($data['name']);
    }
    if (isset($data['name_en'])) {
        $fields[] = 'name_en = ?';
        $params[] = trim($data['name_en']);
    }
    if (isset($data['sort_order'])) {
        $fields[] = 'sort_order = ?';
        $params[] = (int)$data['sort_order'];
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

    $params[] = $id;
    $pdo->prepare('UPDATE categories SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'カテゴリが見つかりません', 404);

    // メニューが紐付いていないか確認
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM menu_templates WHERE category_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) json_error('HAS_REFERENCES', 'メニューが紐付いているカテゴリは削除できません', 409);

    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);

    json_response(['ok' => true]);
}
