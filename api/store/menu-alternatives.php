<?php
/**
 * 品切れ時の手動代替提案 API（manager以上）
 *
 * GET  /api/store/menu-alternatives.php?store_id=xxx
 * POST /api/store/menu-alternatives.php
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/menu-resolver.php';

require_method(['GET', 'POST']);
require_role('manager');
$pdo = get_db();

function menu_alt_flatten_items(PDO $pdo, string $storeId): array
{
    $categories = resolve_store_menu($pdo, $storeId);
    $items = [];
    foreach ($categories as $cat) {
        foreach (($cat['items'] ?? []) as $item) {
            $items[] = [
                'itemId'       => $item['menuItemId'],
                'source'       => $item['source'] ?? 'template',
                'name'         => $item['name'] ?? '',
                'nameEn'       => $item['nameEn'] ?? '',
                'categoryName' => $cat['categoryName'] ?? '',
                'price'        => (int)($item['price'] ?? 0),
                'soldOut'      => !empty($item['soldOut']),
            ];
        }
    }
    return $items;
}

function menu_alt_item_map(array $items): array
{
    $map = [];
    foreach ($items as $item) {
        $key = $item['source'] . ':' . $item['itemId'];
        $map[$key] = $item;
    }
    return $map;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $items = menu_alt_flatten_items($pdo, $storeId);
    $stmt = $pdo->prepare(
        'SELECT id, source_item_id, source_type, alternative_item_id, alternative_source, sort_order
         FROM menu_alternatives
         WHERE store_id = ?
         ORDER BY source_item_id, sort_order ASC'
    );
    $stmt->execute([$storeId]);

    json_response([
        'items'        => $items,
        'alternatives' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_id は必須です', 400);
    require_store_access($storeId);

    $sourceItemId = trim((string)($data['source_item_id'] ?? ''));
    $sourceType = $data['source_type'] ?? 'template';
    $alternatives = isset($data['alternatives']) && is_array($data['alternatives']) ? $data['alternatives'] : [];

    if ($sourceItemId === '') json_error('VALIDATION', '代替元メニューを選択してください', 400);
    if (!in_array($sourceType, ['template', 'local'], true)) json_error('VALIDATION', '代替元の種別が不正です', 400);

    $items = menu_alt_flatten_items($pdo, $storeId);
    $itemMap = menu_alt_item_map($items);
    $sourceKey = $sourceType . ':' . $sourceItemId;
    if (!isset($itemMap[$sourceKey])) json_error('NOT_FOUND', '代替元メニューが見つかりません', 404);

    $clean = [];
    $seen = [];
    foreach ($alternatives as $idx => $alt) {
        $altId = trim((string)($alt['item_id'] ?? $alt['alternative_item_id'] ?? ''));
        $altSource = $alt['source'] ?? $alt['alternative_source'] ?? 'template';
        if ($altId === '') continue;
        if (!in_array($altSource, ['template', 'local'], true)) json_error('VALIDATION', '代替候補の種別が不正です', 400);
        if ($altId === $sourceItemId) continue;
        $altKey = $altSource . ':' . $altId;
        if (!isset($itemMap[$altKey])) json_error('NOT_FOUND', '代替候補メニューが見つかりません', 404);
        if (isset($seen[$altKey])) continue;
        $seen[$altKey] = true;
        $clean[] = [
            'item_id' => $altId,
            'source'  => $altSource,
            'sort'    => count($clean),
        ];
        if (count($clean) >= 6) break;
    }

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare(
            'DELETE FROM menu_alternatives WHERE store_id = ? AND source_item_id = ? AND source_type = ?'
        );
        $delete->execute([$storeId, $sourceItemId, $sourceType]);

        if (!empty($clean)) {
            $insert = $pdo->prepare(
                'INSERT INTO menu_alternatives
                 (id, store_id, source_item_id, source_type, alternative_item_id, alternative_source, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($clean as $alt) {
                $insert->execute([
                    generate_uuid(),
                    $storeId,
                    $sourceItemId,
                    $sourceType,
                    $alt['item_id'],
                    $alt['source'],
                    $alt['sort'],
                ]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_response(['ok' => true, 'count' => count($clean)]);
}
