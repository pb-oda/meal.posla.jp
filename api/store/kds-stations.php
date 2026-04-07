<?php
/**
 * KDSステーション管理 API（マネージャー以上、店舗単位）
 *
 * GET    /api/store/kds-stations.php?store_id=xxx             — 一覧
 * POST   /api/store/kds-stations.php                          — 新規
 * PATCH  /api/store/kds-stations.php?id=xxx&store_id=xxx      — 更新
 * DELETE /api/store/kds-stations.php?id=xxx&store_id=xxx      — 削除
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();
$storeId = require_store_param();
require_store_access($storeId);

// テーブル存在チェック（migration未適用時のフォールバック）
try {
    $pdo->query('SELECT 1 FROM kds_stations LIMIT 0');
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['stations' => []]);
    }
    json_error('MIGRATION_REQUIRED', 'この機能にはデータベースの更新が必要です', 500);
}

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, name, name_en, sort_order, is_active
         FROM kds_stations WHERE store_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$storeId]);
    $stations = $stmt->fetchAll();

    // 各ステーションのルーティングカテゴリを取得
    $routeStmt = $pdo->prepare(
        'SELECT r.station_id, r.category_id, c.name AS category_name
         FROM kds_routing_rules r
         JOIN categories c ON c.id = r.category_id
         JOIN kds_stations s ON s.id = r.station_id
         WHERE s.store_id = ?
         ORDER BY c.sort_order'
    );
    $routeStmt->execute([$storeId]);
    $routeMap = [];
    foreach ($routeStmt->fetchAll() as $row) {
        $routeMap[$row['station_id']][] = [
            'category_id' => $row['category_id'],
            'category_name' => $row['category_name']
        ];
    }

    foreach ($stations as &$s) {
        $s['categories'] = $routeMap[$s['id']] ?? [];
    }

    json_response(['stations' => $stations]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    if (!$name) json_error('MISSING_FIELDS', 'ステーション名は必須です', 400);

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM kds_stations WHERE store_id = ?');
    $stmt->execute([$storeId]);
    $nextOrder = (int)$stmt->fetchColumn();

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO kds_stations (id, store_id, name, name_en, sort_order, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([$id, $storeId, $name, trim($data['name_en'] ?? ''), $nextOrder]);

    // カテゴリ割り当て
    if (!empty($data['category_ids']) && is_array($data['category_ids'])) {
        $insertStmt = $pdo->prepare('INSERT INTO kds_routing_rules (station_id, category_id) VALUES (?, ?)');
        foreach ($data['category_ids'] as $catId) {
            $insertStmt->execute([$id, $catId]);
        }
    }

    json_response(['id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM kds_stations WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'ステーションが見つかりません', 404);

    $data = get_json_body();
    $fields = [];
    $params = [];

    foreach (['name' => 's', 'name_en' => 's', 'sort_order' => 'i', 'is_active' => 'b'] as $col => $type) {
        if (isset($data[$col])) {
            $fields[] = "$col = ?";
            if ($type === 'i') $params[] = (int)$data[$col];
            elseif ($type === 'b') $params[] = !empty($data[$col]) ? 1 : 0;
            else $params[] = trim($data[$col]);
        }
    }

    if (!empty($fields)) {
        $params[] = $id;
        $pdo->prepare('UPDATE kds_stations SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }

    // カテゴリ割り当て更新（指定があれば差し替え）
    if (isset($data['category_ids']) && is_array($data['category_ids'])) {
        $pdo->prepare('DELETE FROM kds_routing_rules WHERE station_id = ?')->execute([$id]);
        $insertStmt = $pdo->prepare('INSERT INTO kds_routing_rules (station_id, category_id) VALUES (?, ?)');
        foreach ($data['category_ids'] as $catId) {
            $insertStmt->execute([$id, $catId]);
        }
    }

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM kds_stations WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'ステーションが見つかりません', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM kds_routing_rules WHERE station_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM kds_stations WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        json_error('DELETE_FAILED', '削除に失敗しました', 500);
    }

    json_response(['ok' => true]);
}
