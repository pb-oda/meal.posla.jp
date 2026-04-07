<?php
/**
 * テーブル管理 API
 *
 * GET    /api/store/tables.php?store_id=xxx       — 一覧
 * POST   /api/store/tables.php                    — 新規
 * PATCH  /api/store/tables.php?id=xxx             — 更新
 * DELETE /api/store/tables.php?id=xxx             — 削除
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);

// GETはスタッフ以上（ハンディPOSでテーブル一覧が必要）、書込みはマネージャー以上
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = require_auth();
} else {
    $user = require_role('manager');
}

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $stmt = $pdo->prepare('SELECT * FROM tables WHERE store_id = ? ORDER BY table_code');
    $stmt->execute([$storeId]);
    json_response(['tables' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $tableCode = trim($data['table_code'] ?? '');

    if (!$storeId || !$tableCode) json_error('MISSING_FIELDS', 'store_idとテーブルコードは必須です', 400);
    require_store_access($storeId);

    // 重複チェック
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tables WHERE store_id = ? AND table_code = ?');
    $stmt->execute([$storeId, $tableCode]);
    if ($stmt->fetchColumn() > 0) json_error('DUPLICATE_CODE', 'このテーブルコードは既に使用されています', 409);

    $id = generate_uuid();
    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        'INSERT INTO tables (id, store_id, table_code, capacity, is_active, session_token, session_token_expires_at) VALUES (?, ?, ?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 4 HOUR))'
    );
    $stmt->execute([$id, $storeId, $tableCode, (int)($data['capacity'] ?? 4), $token]);

    json_response(['ok' => true, 'id' => $id], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    require_store_access($storeId);

    $stmt = $pdo->prepare('SELECT id FROM tables WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'テーブルが見つかりません', 404);

    $fields = [];
    $params = [];
    if (isset($data['table_code'])) { $fields[] = 'table_code = ?'; $params[] = trim($data['table_code']); }
    if (isset($data['capacity'])) { $fields[] = 'capacity = ?'; $params[] = (int)$data['capacity']; }
    if (isset($data['is_active'])) { $fields[] = 'is_active = ?'; $params[] = $data['is_active'] ? 1 : 0; }
    if (isset($data['pos_x'])) { $fields[] = 'pos_x = ?'; $params[] = (int)$data['pos_x']; }
    if (isset($data['pos_y'])) { $fields[] = 'pos_y = ?'; $params[] = (int)$data['pos_y']; }
    if (isset($data['width'])) { $fields[] = 'width = ?'; $params[] = (int)$data['width']; }
    if (isset($data['height'])) { $fields[] = 'height = ?'; $params[] = (int)$data['height']; }
    if (isset($data['shape'])) {
        $allowedShapes = ['square', 'round', 'rectangle', 'oval'];
        if (!in_array($data['shape'], $allowedShapes, true)) {
            json_error('INVALID_SHAPE', '無効なshapeです（' . implode('/', $allowedShapes) . '）', 400);
        }
        $fields[] = 'shape = ?'; $params[] = $data['shape'];
    }
    if (isset($data['floor'])) { $fields[] = 'floor = ?'; $params[] = trim($data['floor']); }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);
    $params[] = $id;
    $pdo->prepare('UPDATE tables SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id || !$storeId) json_error('MISSING_FIELDS', 'idとstore_idが必要です', 400);
    require_store_access($storeId);

    $pdo->prepare('DELETE FROM tables WHERE id = ? AND store_id = ?')->execute([$id, $storeId]);
    json_response(['ok' => true]);
}
