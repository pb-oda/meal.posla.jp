<?php
/**
 * L-9 予約管理 — コース管理
 * GET    ?store_id=xxx                 … 一覧
 * POST   { store_id, name, ... }       … 新規
 * PATCH  { id, store_id, ... }         … 更新
 * DELETE ?id=xxx&store_id=xxx          … 削除
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';

$method = require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('staff');
$pdo = get_db();

function _l9_course_uuid() { return bin2hex(random_bytes(18)); }

if ($method === 'GET') {
    $storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
    if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);
    require_store_access($storeId);
    $only = isset($_GET['only_active']) ? (int)$_GET['only_active'] : 0;
    $sql = 'SELECT * FROM reservation_courses WHERE store_id = ?';
    $params = [$storeId];
    if ($only) { $sql .= ' AND is_active = 1'; }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['courses' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    if ($user['role'] === 'staff') json_error('FORBIDDEN', 'コース作成は manager 以上', 403);
    $body = get_json_body();
    $storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
    if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);
    require_store_access($storeId);
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') json_error('MISSING_NAME', 'コース名が必要です', 400);

    $id = _l9_course_uuid();
    $pdo->prepare(
        'INSERT INTO reservation_courses (id, store_id, name, name_en, description, description_en, price, duration_min, min_party_size, max_party_size, image_url, is_active, sort_order, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([
        $id, $storeId, $name,
        isset($body['name_en']) ? (string)$body['name_en'] : null,
        isset($body['description']) ? (string)$body['description'] : null,
        isset($body['description_en']) ? (string)$body['description_en'] : null,
        isset($body['price']) ? (int)$body['price'] : 0,
        isset($body['duration_min']) ? max(15, (int)$body['duration_min']) : 90,
        isset($body['min_party_size']) ? max(1, (int)$body['min_party_size']) : 1,
        isset($body['max_party_size']) ? max(1, (int)$body['max_party_size']) : 10,
        isset($body['image_url']) ? (string)$body['image_url'] : null,
        isset($body['is_active']) ? ((int)$body['is_active'] === 1 ? 1 : 0) : 1,
        isset($body['sort_order']) ? (int)$body['sort_order'] : 0,
    ]);
    $stmt = $pdo->prepare('SELECT * FROM reservation_courses WHERE id = ?');
    $stmt->execute([$id]);
    json_response(['course' => $stmt->fetch()]);
}

if ($method === 'PATCH') {
    if ($user['role'] === 'staff') json_error('FORBIDDEN', 'コース変更は manager 以上', 403);
    $body = get_json_body();
    $id = isset($body['id']) ? trim($body['id']) : '';
    $storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
    if (!$id || !$storeId) json_error('MISSING_PARAM', 'id と store_id が必要です', 400);
    require_store_access($storeId);

    $allowed = ['name','name_en','description','description_en','price','duration_min','min_party_size','max_party_size','image_url','is_active','sort_order'];
    $sets = []; $params = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $body)) continue;
        $v = $body[$k];
        if (in_array($k, ['price','duration_min','min_party_size','max_party_size','sort_order'], true)) $v = (int)$v;
        elseif ($k === 'is_active') $v = ((int)$v === 1) ? 1 : 0;
        elseif ($v !== null) $v = (string)$v;
        $sets[] = $k . ' = ?';
        $params[] = $v;
    }
    if (empty($sets)) json_error('NO_FIELDS', '更新項目がありません', 400);
    $sets[] = 'updated_at = NOW()';
    $params[] = $id;
    $params[] = $storeId;
    $pdo->prepare('UPDATE reservation_courses SET ' . implode(', ', $sets) . ' WHERE id = ? AND store_id = ?')->execute($params);

    $stmt = $pdo->prepare('SELECT * FROM reservation_courses WHERE id = ?');
    $stmt->execute([$id]);
    json_response(['course' => $stmt->fetch()]);
}

if ($method === 'DELETE') {
    if ($user['role'] === 'staff') json_error('FORBIDDEN', 'コース削除は manager 以上', 403);
    $id = isset($_GET['id']) ? trim($_GET['id']) : '';
    $storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
    if (!$id || !$storeId) json_error('MISSING_PARAM', 'id と store_id が必要です', 400);
    require_store_access($storeId);
    $pdo->prepare('DELETE FROM reservation_courses WHERE id = ? AND store_id = ?')->execute([$id, $storeId]);
    json_response(['ok' => true]);
}
