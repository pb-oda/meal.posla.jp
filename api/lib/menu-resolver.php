<?php
/**
 * メニュー解決ロジック
 *
 * 本部テンプレート + 店舗オーバーライド + 店舗限定メニューを統合し、
 * 顧客向けの統一メニューを返す。
 *
 * Sprint A-1: オプション/トッピング対応追加
 *   - 各メニューアイテムに optionGroups 配列を付加
 *   - 店舗別オプション上書き（価格変更・非表示）対応
 *   - categoryId を各アイテムに付加（KDSルーティング準備）
 *   - オプションテーブル未作成でもグレースフルデグレード
 */

/**
 * 指定店舗の有効メニューを解決する
 *
 * @param PDO    $pdo
 * @param string $store_id
 * @return array カテゴリ配列（各カテゴリ内にitems配列）
 */
function resolve_store_menu(PDO $pdo, string $store_id): array
{
    // テナントIDを取得
    $stmt = $pdo->prepare('SELECT tenant_id FROM stores WHERE id = ? AND is_active = 1');
    $stmt->execute([$store_id]);
    $store = $stmt->fetch();
    if (!$store) return [];
    $tenant_id = $store['tenant_id'];

    // 1. カテゴリ
    $stmt = $pdo->prepare(
        'SELECT id, name, name_en, sort_order
         FROM categories WHERE tenant_id = ? ORDER BY sort_order ASC'
    );
    $stmt->execute([$tenant_id]);
    $categories = $stmt->fetchAll();

    // 2. テンプレート + オーバーライド（LEFT JOIN）
    // migration-e2 で smo.image_url が追加される（未適用時はフォールバック）
    $hasOverrideImage = true;
    try {
        $pdo->query('SELECT image_url FROM store_menu_overrides LIMIT 0');
    } catch (PDOException $e) {
        $hasOverrideImage = false;
    }
    $ovrImageCol = $hasOverrideImage ? ', smo.image_url AS override_image_url' : '';

    // N-7: カロリー・アレルギーカラム存在チェック
    $hasNutrition = false;
    try { $pdo->query('SELECT calories FROM menu_templates LIMIT 0'); $hasNutrition = true; } catch (PDOException $e) {}
    $nutritionCols = $hasNutrition ? ', mt.calories, mt.allergens' : '';

    // SELF-MENU-4: 検索/絞り込み用の構造化属性（未適用環境は従来表示を維持）
    $hasTemplateAttrs = false;
    try { $pdo->query('SELECT spice_level FROM menu_templates LIMIT 0'); $hasTemplateAttrs = true; } catch (PDOException $e) {}
    $attrCols = $hasTemplateAttrs ? ', mt.spice_level, mt.is_vegetarian, mt.is_kids_friendly, mt.is_quick_serve, mt.prep_time_min' : '';

    $stmt = $pdo->prepare(
        'SELECT mt.id, mt.category_id, mt.name, mt.name_en,
                mt.base_price, mt.description, mt.description_en,
                mt.image_url, mt.sort_order,
                smo.price AS override_price,
                smo.is_hidden, COALESCE(smo.is_sold_out, mt.is_sold_out) AS is_sold_out,
                smo.sort_order AS override_sort' . $ovrImageCol . $nutritionCols . $attrCols . '
         FROM menu_templates mt
         LEFT JOIN store_menu_overrides smo
           ON smo.template_id = mt.id AND smo.store_id = ?
         WHERE mt.tenant_id = ? AND mt.is_active = 1
         ORDER BY COALESCE(smo.sort_order, mt.sort_order) ASC'
    );
    $stmt->execute([$store_id, $tenant_id]);
    $templates = $stmt->fetchAll();

    // 3. 店舗限定メニュー
    $localNutritionCols = $hasNutrition ? ', calories, allergens' : '';
    $hasLocalAttrs = false;
    try { $pdo->query('SELECT spice_level FROM store_local_items LIMIT 0'); $hasLocalAttrs = true; } catch (PDOException $e) {}
    $localAttrCols = $hasLocalAttrs ? ', spice_level, is_vegetarian, is_kids_friendly, is_quick_serve, prep_time_min' : '';
    $stmt = $pdo->prepare(
        'SELECT id, category_id, name, name_en, price,
                description, description_en, image_url,
                is_sold_out, sort_order' . $localNutritionCols . $localAttrCols . '
         FROM store_local_items
         WHERE store_id = ?
         ORDER BY sort_order ASC'
    );
    $stmt->execute([$store_id]);
    $localItems = $stmt->fetchAll();

    // 4. 表示対象アイテムIDを収集
    $visibleTemplateIds = [];
    foreach ($templates as $t) {
        if (empty($t['is_hidden'])) {
            $visibleTemplateIds[] = $t['id'];
        }
    }
    $localItemIds = array_column($localItems, 'id');

    // 5. オプション取得（テーブル未作成でも既存機能に影響なし）
    $optionMap = [];
    try {
        $optionMap = load_item_options($pdo, $visibleTemplateIds, $localItemIds, $store_id);
    } catch (PDOException $e) {
        // オプションテーブルが未作成の場合はスキップ（グレースフルデグレード）
        $optionMap = [];
    }

    // 6. カテゴリマップ構築
    $catMap = [];
    foreach ($categories as $cat) {
        $catMap[$cat['id']] = [
            'categoryId'     => $cat['id'],
            'categoryName'   => $cat['name'],
            'categoryNameEn' => $cat['name_en'] ?? '',
            'items'          => [],
        ];
    }

    // 7. テンプレートをマージ（非表示を除外）
    foreach ($templates as $t) {
        if (!empty($t['is_hidden'])) continue;

        $catId = $t['category_id'];
        if (!isset($catMap[$catId])) continue;

        $catMap[$catId]['items'][] = [
            'menuItemId'    => $t['id'],
            'source'        => 'template',
            'categoryId'    => $catId,
            'name'          => $t['name'],
            'nameEn'        => $t['name_en'] ?? '',
            'price'         => (int)($t['override_price'] ?? $t['base_price']),
            'description'   => $t['description'] ?? '',
            'descriptionEn' => $t['description_en'] ?? '',
            'imageUrl'      => ($t['override_image_url'] ?? '') ?: ($t['image_url'] ?? ''),
            'soldOut'       => (bool)($t['is_sold_out'] ?? false),
            'calories'      => $hasNutrition && isset($t['calories']) ? (int)$t['calories'] : null,
            'allergens'     => $hasNutrition && !empty($t['allergens']) ? json_decode($t['allergens'], true) : null,
            'spiceLevel'    => $hasTemplateAttrs ? (int)($t['spice_level'] ?? 0) : 0,
            'isVegetarian'  => $hasTemplateAttrs ? (bool)($t['is_vegetarian'] ?? false) : false,
            'isKidsFriendly'=> $hasTemplateAttrs ? (bool)($t['is_kids_friendly'] ?? false) : false,
            'isQuickServe'  => $hasTemplateAttrs ? (bool)($t['is_quick_serve'] ?? false) : false,
            'prepTimeMin'   => $hasTemplateAttrs && isset($t['prep_time_min']) ? (int)$t['prep_time_min'] : null,
            'optionGroups'  => $optionMap[$t['id']] ?? [],
        ];
    }

    // 8. 店舗限定メニューをマージ
    foreach ($localItems as $li) {
        $catId = $li['category_id'];
        if (!isset($catMap[$catId])) continue;

        $catMap[$catId]['items'][] = [
            'menuItemId'    => $li['id'],
            'source'        => 'local',
            'categoryId'    => $catId,
            'name'          => $li['name'],
            'nameEn'        => $li['name_en'] ?? '',
            'price'         => (int)$li['price'],
            'description'   => $li['description'] ?? '',
            'descriptionEn' => $li['description_en'] ?? '',
            'imageUrl'      => $li['image_url'] ?? '',
            'soldOut'       => (bool)$li['is_sold_out'],
            'calories'      => $hasNutrition && isset($li['calories']) ? (int)$li['calories'] : null,
            'allergens'     => $hasNutrition && !empty($li['allergens']) ? json_decode($li['allergens'], true) : null,
            'spiceLevel'    => $hasLocalAttrs ? (int)($li['spice_level'] ?? 0) : 0,
            'isVegetarian'  => $hasLocalAttrs ? (bool)($li['is_vegetarian'] ?? false) : false,
            'isKidsFriendly'=> $hasLocalAttrs ? (bool)($li['is_kids_friendly'] ?? false) : false,
            'isQuickServe'  => $hasLocalAttrs ? (bool)($li['is_quick_serve'] ?? false) : false,
            'prepTimeMin'   => $hasLocalAttrs && isset($li['prep_time_min']) ? (int)$li['prep_time_min'] : null,
            'optionGroups'  => $optionMap[$li['id']] ?? [],
        ];
    }

    // 9. L-4: 多言語翻訳データを付加（テーブル未作成時はスキップ）
    try {
        $catMap = attach_translations($pdo, $tenant_id, $catMap);
    } catch (PDOException $e) {
        // menu_translations テーブル未作成 → グレースフルデグレード
    }

    // 10. 品目がないカテゴリを除外
    return array_values(array_filter($catMap, function ($c) {
        return !empty($c['items']);
    }));
}

// ============================================================
// L-4: 多言語翻訳データ付加
// ============================================================

/**
 * menu_translations テーブルから翻訳を取得し、
 * カテゴリ・アイテム・オプションに translations フィールドを追加する。
 *
 * @param PDO    $pdo
 * @param string $tenantId
 * @param array  $catMap  カテゴリマップ（参照渡しではなく新配列を返す）
 * @return array 翻訳データ付きカテゴリマップ
 */
function attach_translations(PDO $pdo, string $tenantId, array $catMap): array
{
    // 全翻訳を一括取得（テナント単位）
    $stmt = $pdo->prepare(
        'SELECT entity_type, entity_id, lang, name, description
         FROM menu_translations WHERE tenant_id = ?'
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) return $catMap;

    // entity_type:entity_id -> [lang -> {name, description}]
    $transMap = [];
    foreach ($rows as $row) {
        $key = $row['entity_type'] . ':' . $row['entity_id'];
        if (!isset($transMap[$key])) $transMap[$key] = [];
        $transMap[$key][$row['lang']] = [
            'name'        => $row['name'],
            'description' => $row['description'],
        ];
    }

    // カテゴリに翻訳を付加
    foreach ($catMap as &$cat) {
        $catKey = 'category:' . $cat['categoryId'];
        if (isset($transMap[$catKey])) {
            $cat['translations'] = $transMap[$catKey];
        }

        // アイテムに翻訳を付加
        foreach ($cat['items'] as &$item) {
            $entityType = $item['source'] === 'local' ? 'local_item' : 'menu_item';
            $itemKey = $entityType . ':' . $item['menuItemId'];
            if (isset($transMap[$itemKey])) {
                $item['translations'] = $transMap[$itemKey];
            }

            // オプショングループ・チョイスに翻訳を付加
            if (!empty($item['optionGroups'])) {
                foreach ($item['optionGroups'] as &$og) {
                    $ogKey = 'option_group:' . $og['groupId'];
                    if (isset($transMap[$ogKey])) {
                        $og['translations'] = $transMap[$ogKey];
                    }
                    if (!empty($og['choices'])) {
                        foreach ($og['choices'] as &$ch) {
                            $chKey = 'option_choice:' . $ch['choiceId'];
                            if (isset($transMap[$chKey])) {
                                $ch['translations'] = $transMap[$chKey];
                            }
                        }
                        unset($ch);
                    }
                }
                unset($og);
            }
        }
        unset($item);
    }
    unset($cat);

    return $catMap;
}

// ============================================================
// 以下 Sprint A-1 追加: オプション取得ヘルパー
// ============================================================

/**
 * テンプレートと店舗限定メニューのオプションを一括取得し、
 * メニューアイテムIDをキーとしたマップを返す。
 *
 * @param PDO    $pdo
 * @param array  $templateIds  表示対象テンプレートIDの配列
 * @param array  $localItemIds 表示対象ローカルアイテムIDの配列
 * @param string $store_id     店舗オーバーライド適用用
 * @return array [menuItemId => [ optionGroup, ... ]]
 */
function load_item_options(PDO $pdo, array $templateIds, array $localItemIds, string $store_id): array
{
    $allRows = [];

    // テンプレートのオプション
    if (!empty($templateIds)) {
        $ph = implode(',', array_fill(0, count($templateIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT
                mto.template_id AS menu_item_id,
                mto.is_required,
                mto.sort_order AS link_sort,
                og.id AS group_id,
                og.name AS group_name,
                og.name_en AS group_name_en,
                og.selection_type,
                og.min_select,
                og.max_select,
                oc.id AS choice_id,
                oc.name AS choice_name,
                oc.name_en AS choice_name_en,
                oc.price_diff,
                oc.is_default,
                oc.sort_order AS choice_sort,
                soo.price_diff AS override_price_diff,
                COALESCE(soo.is_available, 1) AS is_available
            FROM menu_template_options mto
            JOIN option_groups og
                ON og.id = mto.group_id AND og.is_active = 1
            JOIN option_choices oc
                ON oc.group_id = og.id AND oc.is_active = 1
            LEFT JOIN store_option_overrides soo
                ON soo.choice_id = oc.id AND soo.store_id = ?
            WHERE mto.template_id IN ({$ph})
            ORDER BY mto.sort_order, og.sort_order, oc.sort_order"
        );
        $stmt->execute(array_merge([$store_id], $templateIds));
        $allRows = array_merge($allRows, $stmt->fetchAll());
    }

    // 店舗限定メニューのオプション
    if (!empty($localItemIds)) {
        $ph = implode(',', array_fill(0, count($localItemIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT
                lio.local_item_id AS menu_item_id,
                lio.is_required,
                lio.sort_order AS link_sort,
                og.id AS group_id,
                og.name AS group_name,
                og.name_en AS group_name_en,
                og.selection_type,
                og.min_select,
                og.max_select,
                oc.id AS choice_id,
                oc.name AS choice_name,
                oc.name_en AS choice_name_en,
                oc.price_diff,
                oc.is_default,
                oc.sort_order AS choice_sort,
                soo.price_diff AS override_price_diff,
                COALESCE(soo.is_available, 1) AS is_available
            FROM local_item_options lio
            JOIN option_groups og
                ON og.id = lio.group_id AND og.is_active = 1
            JOIN option_choices oc
                ON oc.group_id = og.id AND oc.is_active = 1
            LEFT JOIN store_option_overrides soo
                ON soo.choice_id = oc.id AND soo.store_id = ?
            WHERE lio.local_item_id IN ({$ph})
            ORDER BY lio.sort_order, og.sort_order, oc.sort_order"
        );
        $stmt->execute(array_merge([$store_id], $localItemIds));
        $allRows = array_merge($allRows, $stmt->fetchAll());
    }

    return build_option_map($allRows);
}

/**
 * フラットな結果セットをネスト構造（menuItemId → optionGroups[]）に変換
 *
 * @param array $rows  JOINクエリの結果行
 * @return array [menuItemId => [ { groupId, groupName, ..., choices: [...] }, ... ]]
 */
function build_option_map(array $rows): array
{
    // menuItemId => groupId => group data（中間構造）
    $raw = [];

    foreach ($rows as $row) {
        $menuId  = $row['menu_item_id'];
        $groupId = $row['group_id'];

        if (!isset($raw[$menuId][$groupId])) {
            $raw[$menuId][$groupId] = [
                'groupId'       => $groupId,
                'groupName'     => $row['group_name'],
                'groupNameEn'   => $row['group_name_en'] ?? '',
                'selectionType' => $row['selection_type'],
                'minSelect'     => (int)$row['min_select'],
                'maxSelect'     => (int)$row['max_select'],
                'isRequired'    => (bool)$row['is_required'],
                'choices'       => [],
            ];
        }

        $raw[$menuId][$groupId]['choices'][] = [
            'choiceId'  => $row['choice_id'],
            'name'      => $row['choice_name'],
            'nameEn'    => $row['choice_name_en'] ?? '',
            'priceDiff' => (int)($row['override_price_diff'] ?? $row['price_diff']),
            'isDefault' => (bool)$row['is_default'],
            'isAvailable' => (bool)($row['is_available'] ?? true),
        ];
    }

    // groupId キーを除去して配列化
    $result = [];
    foreach ($raw as $menuId => $groups) {
        $result[$menuId] = array_values($groups);
    }

    return $result;
}
