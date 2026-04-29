<?php
/**
 * メニュー整備チェック API（manager以上）
 *
 * GET /api/store/menu-quality.php?store_id=xxx
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/menu-resolver.php';

require_method(['GET']);
require_role('manager');

$storeId = require_store_param();
require_store_access($storeId);
$pdo = get_db();

function mq_has_description(array $item): bool
{
    if (trim((string)($item['description'] ?? '')) !== '') return true;
    if (trim((string)($item['descriptionEn'] ?? '')) !== '') return true;
    if (!empty($item['translations']) && is_array($item['translations'])) {
        foreach ($item['translations'] as $tr) {
            if (trim((string)($tr['description'] ?? '')) !== '') return true;
        }
    }
    return false;
}

function mq_has_tags(array $item): bool
{
    if (!empty($item['isVegetarian'])) return true;
    if (!empty($item['isKidsFriendly'])) return true;
    if (!empty($item['isQuickServe'])) return true;
    if ((int)($item['spiceLevel'] ?? 0) > 0) return true;
    return isset($item['prepTimeMin']) && $item['prepTimeMin'] !== null && $item['prepTimeMin'] !== '';
}

$issueLabels = [
    'missing_photo'     => '写真なし',
    'missing_desc'      => '説明なし',
    'missing_allergens' => 'アレルゲン未設定',
    'missing_tags'      => 'タグ未設定',
    'missing_price'     => '価格未設定',
];

$summary = [
    'total'             => 0,
    'needsAttention'    => 0,
    'missing_photo'     => 0,
    'missing_desc'      => 0,
    'missing_allergens' => 0,
    'missing_tags'      => 0,
    'missing_price'     => 0,
];
$items = [];

$categories = resolve_store_menu($pdo, $storeId);
foreach ($categories as $cat) {
    foreach (($cat['items'] ?? []) as $item) {
        $summary['total']++;
        $issues = [];

        if (trim((string)($item['imageUrl'] ?? '')) === '') $issues[] = 'missing_photo';
        if (!mq_has_description($item)) $issues[] = 'missing_desc';
        if (empty($item['allergens']) || !is_array($item['allergens'])) $issues[] = 'missing_allergens';
        if (!mq_has_tags($item)) $issues[] = 'missing_tags';
        if ((int)($item['price'] ?? 0) <= 0) $issues[] = 'missing_price';

        if (!empty($issues)) {
            $summary['needsAttention']++;
            foreach ($issues as $issue) {
                $summary[$issue]++;
            }
        }

        $items[] = [
            'itemId'       => $item['menuItemId'],
            'source'       => $item['source'] ?? 'template',
            'name'         => $item['name'] ?? '',
            'nameEn'       => $item['nameEn'] ?? '',
            'categoryName' => $cat['categoryName'] ?? '',
            'price'        => (int)($item['price'] ?? 0),
            'soldOut'      => !empty($item['soldOut']),
            'issues'       => $issues,
        ];
    }
}

json_response([
    'summary'     => $summary,
    'issueLabels' => $issueLabels,
    'items'       => $items,
]);
