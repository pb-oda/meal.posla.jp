<?php
/**
 * コースフェーズ API
 *
 * POST   /api/store/course-phases.php                      — フェーズ追加
 * PATCH  /api/store/course-phases.php?id=xxx               — フェーズ更新
 * DELETE /api/store/course-phases.php?id=xxx&store_id=xxx  — フェーズ削除
 *
 * マネージャー以上。コースに紐づくフェーズ（前菜→メイン→デザート等）を管理。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();

// テーブル存在チェック（migration未適用時のフォールバック）
try {
    $pdo->query('SELECT 1 FROM course_phases LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION_REQUIRED', 'この機能にはデータベースの更新が必要です', 500);
}

// ---- POST: フェーズ追加 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $courseId = $data['course_id'] ?? null;
    $name = trim($data['name'] ?? '');

    if (!$storeId || !$courseId || !$name) {
        json_error('VALIDATION', 'store_id, course_id, name は必須です', 400);
    }
    require_store_access($storeId);

    // コース存在確認
    $stmt = $pdo->prepare('SELECT id FROM course_templates WHERE id = ? AND store_id = ?');
    $stmt->execute([$courseId, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'コースが見つかりません', 404);

    // 次のフェーズ番号を取得
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(phase_number), 0) + 1 AS next_num FROM course_phases WHERE course_id = ?');
    $stmt->execute([$courseId]);
    $nextNum = (int)$stmt->fetchColumn();

    $items = $data['items'] ?? [];
    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO course_phases (id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $courseId,
        $nextNum,
        $name,
        trim($data['name_en'] ?? ''),
        json_encode($items, JSON_UNESCAPED_UNICODE),
        isset($data['auto_fire_min']) && $data['auto_fire_min'] !== '' ? (int)$data['auto_fire_min'] : null,
        (int)($data['sort_order'] ?? $nextNum),
    ]);

    // phase_count を更新
    $pdo->prepare(
        'UPDATE course_templates SET phase_count = (SELECT COUNT(*) FROM course_phases WHERE course_id = ?) WHERE id = ?'
    )->execute([$courseId, $courseId]);

    json_response(['id' => $id, 'phaseNumber' => $nextNum], 201);
}

// ---- PATCH: フェーズ更新 ----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    require_store_access($storeId);

    // フェーズ存在確認（store_idチェックはコース経由）
    $stmt = $pdo->prepare(
        'SELECT cp.id FROM course_phases cp
         JOIN course_templates ct ON ct.id = cp.course_id
         WHERE cp.id = ? AND ct.store_id = ?'
    );
    $stmt->execute([$id, $storeId]);
    if (!$stmt->fetch()) json_error('NOT_FOUND', 'フェーズが見つかりません', 404);

    $fields = [];
    $params = [];

    if (isset($data['name'])) { $fields[] = 'name = ?'; $params[] = trim($data['name']); }
    if (isset($data['name_en'])) { $fields[] = 'name_en = ?'; $params[] = trim($data['name_en']); }
    if (array_key_exists('items', $data)) {
        $fields[] = 'items = ?';
        $params[] = json_encode($data['items'] ?? [], JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('auto_fire_min', $data)) {
        $fields[] = 'auto_fire_min = ?';
        $params[] = ($data['auto_fire_min'] !== null && $data['auto_fire_min'] !== '') ? (int)$data['auto_fire_min'] : null;
    }
    if (isset($data['sort_order'])) { $fields[] = 'sort_order = ?'; $params[] = (int)$data['sort_order']; }
    if (isset($data['phase_number'])) { $fields[] = 'phase_number = ?'; $params[] = (int)$data['phase_number']; }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);
    $params[] = $id;
    $pdo->prepare('UPDATE course_phases SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    json_response(['ok' => true]);
}

// ---- DELETE ----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id || !$storeId) json_error('MISSING_PARAMS', 'idとstore_idが必要です', 400);
    require_store_access($storeId);

    // コースID取得（phase_count更新用）
    $stmt = $pdo->prepare(
        'SELECT cp.course_id FROM course_phases cp
         JOIN course_templates ct ON ct.id = cp.course_id
         WHERE cp.id = ? AND ct.store_id = ?'
    );
    $stmt->execute([$id, $storeId]);
    $row = $stmt->fetch();
    if (!$row) json_error('NOT_FOUND', 'フェーズが見つかりません', 404);
    $courseId = $row['course_id'];

    $pdo->prepare('DELETE FROM course_phases WHERE id = ?')->execute([$id]);

    // phase_count 更新
    $pdo->prepare(
        'UPDATE course_templates SET phase_count = (SELECT COUNT(*) FROM course_phases WHERE course_id = ?) WHERE id = ?'
    )->execute([$courseId, $courseId]);

    json_response(['ok' => true]);
}
