<?php
/**
 * 今日のおすすめ管理 API（スタッフ以上）
 *
 * GET    /api/store/daily-recommendations.php?store_id=xxx[&date=2026-04-02]
 * POST   /api/store/daily-recommendations.php
 * DELETE /api/store/daily-recommendations.php?id=xxx&store_id=xxx
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST', 'DELETE']);
$user = require_auth();
$pdo = get_db();

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = $_GET['store_id'] ?? null;
    if (!$storeId) json_error('VALIDATION', 'store_id は必須です', 400);
    require_store_access($storeId);

    $date = $_GET['date'] ?? date('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT dr.id, dr.menu_item_id, dr.source, dr.badge_type, dr.comment,
                dr.sort_order, dr.display_date,
                CASE dr.source
                    WHEN 'template' THEN mt.name
                    WHEN 'local' THEN li.name
                END AS item_name,
                CASE dr.source
                    WHEN 'template' THEN mt.name_en
                    WHEN 'local' THEN li.name_en
                END AS item_name_en
         FROM daily_recommendations dr
         LEFT JOIN menu_templates mt ON dr.source = 'template' AND mt.id = dr.menu_item_id
         LEFT JOIN store_local_items li ON dr.source = 'local' AND li.id = dr.menu_item_id
         WHERE dr.store_id = ? AND dr.display_date = ?
         ORDER BY dr.sort_order ASC"
    );
    $stmt->execute([$storeId, $date]);
    json_response(['recommendations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('VALIDATION', 'store_id は必須です', 400);
    require_store_access($storeId);

    $menuItemId  = $data['menu_item_id'] ?? null;
    $source      = $data['source'] ?? 'template';
    $badgeType   = $data['badge_type'] ?? 'recommend';
    $displayDate = $data['display_date'] ?? date('Y-m-d');
    $comment     = isset($data['comment']) ? mb_substr(trim($data['comment']), 0, 200) : null;
    $sortOrder   = (int)($data['sort_order'] ?? 0);

    if (!$menuItemId) json_error('VALIDATION', 'menu_item_id は必須です', 400);
    if (!in_array($source, ['template', 'local'])) json_error('VALIDATION', 'source が不正です', 400);
    if (!in_array($badgeType, ['recommend', 'popular', 'new', 'limited', 'today_only'])) {
        json_error('VALIDATION', 'badge_type が不正です', 400);
    }

    $id = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare(
        'INSERT INTO daily_recommendations (id, store_id, menu_item_id, source, badge_type, comment, display_date, sort_order, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $storeId, $menuItemId, $source, $badgeType, $comment, $displayDate, $sortOrder, $user['user_id']]);

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id || !$storeId) json_error('VALIDATION', 'id と store_id は必須です', 400);
    require_store_access($storeId);

    $stmt = $pdo->prepare('DELETE FROM daily_recommendations WHERE id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);

    json_response(['ok' => true]);
}
