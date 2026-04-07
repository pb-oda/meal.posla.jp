<?php
/**
 * 食べ放題・時間制限プラン API
 *
 * GET    /api/store/time-limit-plans.php?store_id=xxx     — 一覧
 * POST   /api/store/time-limit-plans.php                  — 新規
 * PATCH  /api/store/time-limit-plans.php?id=xxx           — 更新
 * DELETE /api/store/time-limit-plans.php?id=xxx&store_id=xxx — 削除
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
    $pdo->query('SELECT 1 FROM time_limit_plans LIMIT 0');
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['plans' => []]);
    }
    json_error('MIGRATION_REQUIRED', 'この機能にはデータベースの更新が必要です', 500);
}

// ---- GET: 一覧 ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();

    $stmt = $pdo->prepare(
        'SELECT * FROM time_limit_plans WHERE store_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$storeId]);
    json_response(['plans' => $stmt->fetchAll()]);
}

// ---- POST: 新規作成 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $name = trim($data['name'] ?? '');
    $durationMin = (int)($data['duration_min'] ?? 0);

    if (!$storeId || !$name || $durationMin <= 0) {
        json_error('VALIDATION', 'store_id, name, duration_min（1以上）は必須です', 400);
    }
    require_store_access($storeId);

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO time_limit_plans (id, store_id, name, name_en, duration_min, last_order_min, price, description, sort_order, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $storeId,
        $name,
        trim($data['name_en'] ?? ''),
        $durationMin,
        (int)($data['last_order_min'] ?? 15),
        (int)($data['price'] ?? 0),
        $data['description'] ?? null,
        (int)($data['sort_order'] ?? 0),
        1
    ]);

    json_response(['id' => $id], 201);
}

// ---- PATCH: 更新 ----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    require_store_access($storeId);

    // 存在確認
    $stmt = $pdo->prepare('SELECT id FROM time_limit_plans WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'プランが見つかりません', 404);

    $allowed = ['name', 'name_en', 'duration_min', 'last_order_min', 'price', 'description', 'sort_order', 'is_active'];
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
    $pdo->prepare('UPDATE time_limit_plans SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ---- DELETE ----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id || !$storeId) json_error('MISSING_PARAMS', 'idとstore_idが必要です', 400);
    require_store_access($storeId);

    $pdo->prepare('DELETE FROM time_limit_plans WHERE id = ? AND store_id = ?')->execute([$id, $storeId]);
    json_response(['ok' => true]);
}
