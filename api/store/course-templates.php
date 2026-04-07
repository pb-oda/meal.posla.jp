<?php
/**
 * コース料理テンプレート API
 *
 * GET    /api/store/course-templates.php?store_id=xxx         — 一覧（フェーズ付き）
 * POST   /api/store/course-templates.php                      — 新規
 * PATCH  /api/store/course-templates.php?id=xxx               — 更新
 * DELETE /api/store/course-templates.php?id=xxx&store_id=xxx  — 削除
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
    $pdo->query('SELECT 1 FROM course_templates LIMIT 0');
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['courses' => []]);
    }
    json_error('MIGRATION_REQUIRED', 'この機能にはデータベースの更新が必要です', 500);
}

// ---- GET ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();

    $stmt = $pdo->prepare(
        'SELECT * FROM course_templates WHERE store_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$storeId]);
    $courses = $stmt->fetchAll();

    // 各コースのフェーズも取得
    $courseIds = array_column($courses, 'id');
    $phaseMap = [];

    if (!empty($courseIds)) {
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT * FROM course_phases WHERE course_id IN (' . $placeholders . ') ORDER BY phase_number'
        );
        $stmt->execute($courseIds);
        foreach ($stmt->fetchAll() as $p) {
            $phaseMap[$p['course_id']][] = [
                'id'          => $p['id'],
                'phaseNumber' => (int)$p['phase_number'],
                'name'        => $p['name'],
                'nameEn'      => $p['name_en'],
                'items'       => json_decode($p['items'], true) ?: [],
                'autoFireMin' => $p['auto_fire_min'] !== null ? (int)$p['auto_fire_min'] : null,
                'sortOrder'   => (int)$p['sort_order'],
            ];
        }
    }

    $result = [];
    foreach ($courses as $c) {
        $result[] = [
            'id'         => $c['id'],
            'name'       => $c['name'],
            'nameEn'     => $c['name_en'],
            'price'      => (int)$c['price'],
            'description'=> $c['description'],
            'phaseCount' => (int)$c['phase_count'],
            'sortOrder'  => (int)$c['sort_order'],
            'isActive'   => (int)$c['is_active'],
            'phases'     => $phaseMap[$c['id']] ?? [],
        ];
    }

    json_response(['courses' => $result]);
}

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $name = trim($data['name'] ?? '');

    if (!$storeId || !$name) {
        json_error('VALIDATION', 'store_id と name は必須です', 400);
    }
    require_store_access($storeId);

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO course_templates (id, store_id, name, name_en, price, description, phase_count, sort_order, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        $id,
        $storeId,
        $name,
        trim($data['name_en'] ?? ''),
        (int)($data['price'] ?? 0),
        $data['description'] ?? null,
        (int)($data['phase_count'] ?? 1),
        (int)($data['sort_order'] ?? 0),
    ]);

    json_response(['id' => $id], 201);
}

// ---- PATCH ----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    require_store_access($storeId);

    $stmt = $pdo->prepare('SELECT id FROM course_templates WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'コースが見つかりません', 404);

    $allowed = ['name', 'name_en', 'price', 'description', 'phase_count', 'sort_order', 'is_active'];
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
    $pdo->prepare('UPDATE course_templates SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ---- DELETE ----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id || !$storeId) json_error('MISSING_PARAMS', 'idとstore_idが必要です', 400);
    require_store_access($storeId);

    $pdo->prepare('DELETE FROM course_templates WHERE id = ? AND store_id = ?')->execute([$id, $storeId]);
    json_response(['ok' => true]);
}
