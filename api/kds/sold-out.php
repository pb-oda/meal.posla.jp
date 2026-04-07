<?php
/**
 * KDS 品切れ管理 API
 *
 * GET   /api/kds/sold-out.php?store_id=xxx   — メニュー品切れ状態一覧
 * PATCH /api/kds/sold-out.php                 — 品切れトグル
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/menu-resolver.php';

require_method(['GET', 'PATCH']);
$user = require_auth();
$pdo = get_db();

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();

    $menu = resolve_store_menu($pdo, $storeId);
    json_response(['categories' => $menu]);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $menuItemId = $data['menu_item_id'] ?? null;
    $source = $data['source'] ?? null;
    $isSoldOut = isset($data['is_sold_out']) ? ($data['is_sold_out'] ? 1 : 0) : null;

    if (!$storeId || !$menuItemId || !$source || $isSoldOut === null) {
        json_error('MISSING_FIELDS', 'store_id, menu_item_id, source, is_sold_out は必須です', 400);
    }
    require_store_access($storeId);

    if ($source === 'template') {
        // store_menu_overrides を UPSERT
        $stmt = $pdo->prepare('SELECT id FROM store_menu_overrides WHERE store_id = ? AND template_id = ?');
        $stmt->execute([$storeId, $menuItemId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $pdo->prepare('UPDATE store_menu_overrides SET is_sold_out = ? WHERE id = ?')
                ->execute([$isSoldOut, $existing['id']]);
        } else {
            $id = generate_uuid();
            $pdo->prepare(
                'INSERT INTO store_menu_overrides (id, store_id, template_id, is_sold_out) VALUES (?, ?, ?, ?)'
            )->execute([$id, $storeId, $menuItemId, $isSoldOut]);
        }
    } elseif ($source === 'local') {
        // store_local_items を直接 UPDATE
        $pdo->prepare('UPDATE store_local_items SET is_sold_out = ? WHERE id = ? AND store_id = ?')
            ->execute([$isSoldOut, $menuItemId, $storeId]);
    } else {
        json_error('INVALID_SOURCE', 'source は "template" または "local" です', 400);
    }

    json_response(['ok' => true]);
}
