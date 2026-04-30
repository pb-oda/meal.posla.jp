#!/usr/bin/env php
<?php
/**
 * Seed one POSLA cell/store with practical sample data.
 *
 * Idempotent: rerunning updates or reuses records by store/name where possible.
 *
 * Usage:
 *   php scripts/cell/seed-one-cell-sample.php [--store-id=<store_id>] [--dry-run]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(2);
}

$rootDir = dirname(__DIR__, 2);

function seed_usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/cell/seed-one-cell-sample.php [--store-id=<store_id>] [--dry-run]\n";
}

function seed_load_env_file(string $path): void
{
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1), " \t\n\r\0\x0B\"'");
        if ($key === '' || getenv($key) !== false) continue;
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function seed_running_inside_container(): bool
{
    return is_file('/.dockerenv');
}

function seed_parse_args(array $argv): array
{
    $opts = ['store_id' => '', 'dry_run' => false];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            seed_usage();
            exit(0);
        }
        if ($arg === '--dry-run') {
            $opts['dry_run'] = true;
            continue;
        }
        if (strpos($arg, '--store-id=') === 0) {
            $opts['store_id'] = trim(substr($arg, 11));
            continue;
        }
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        seed_usage();
        exit(2);
    }
    return $opts;
}

seed_load_env_file($rootDir . '/docker/env/app.env');
if (!seed_running_inside_container() && getenv('POSLA_DB_HOST') === 'db') {
    putenv('POSLA_DB_HOST=127.0.0.1');
    $_ENV['POSLA_DB_HOST'] = '127.0.0.1';
}
if (!seed_running_inside_container() && getenv('POSLA_LOCAL_DB_HOST') === 'db') {
    putenv('POSLA_LOCAL_DB_HOST=127.0.0.1');
    $_ENV['POSLA_LOCAL_DB_HOST'] = '127.0.0.1';
}
if (getenv('POSLA_ENV') === false) {
    putenv('POSLA_ENV=local');
    $_ENV['POSLA_ENV'] = 'local';
}

require_once $rootDir . '/api/lib/db.php';

$opts = seed_parse_args($argv);
$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function seed_one(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function seed_exec(PDO $pdo, string $sql, array $params): void
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function seed_find_or_insert(PDO $pdo, string $selectSql, array $selectParams, string $insertSql, array $insertParams): string
{
    $row = seed_one($pdo, $selectSql, $selectParams);
    if (!empty($row['id'])) return $row['id'];
    $id = generate_uuid();
    array_unshift($insertParams, $id);
    seed_exec($pdo, $insertSql, $insertParams);
    return $id;
}

function seed_json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function seed_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string)$value, '-');
    return $value !== '' ? substr($value, 0, 24) : 'store';
}

$store = [];
if ($opts['store_id'] !== '') {
    $store = seed_one($pdo, 'SELECT id, tenant_id, slug, name FROM stores WHERE id = ? AND is_active = 1 LIMIT 1', [$opts['store_id']]);
} else {
    $store = seed_one($pdo, 'SELECT id, tenant_id, slug, name FROM stores WHERE is_active = 1 ORDER BY created_at, id LIMIT 1', []);
}
if (!$store) {
    fwrite(STDERR, "No active store found.\n");
    exit(1);
}

if ($opts['dry_run']) {
    echo "Dry-run target store: {$store['name']} ({$store['id']})\n";
    exit(0);
}

$tenantId = $store['tenant_id'];
$storeId = $store['id'];
$storeSlug = seed_slug((string)$store['slug']);
$created = [];

$pdo->beginTransaction();
try {
    $categories = [];
    $categoryRows = [
        ['サラダ', 'Salad', 10],
        ['メイン', 'Main', 20],
        ['ドリンク', 'Drink', 30],
        ['デザート', 'Dessert', 40],
    ];
    foreach ($categoryRows as $row) {
        $id = seed_find_or_insert(
            $pdo,
            'SELECT id FROM categories WHERE tenant_id = ? AND name = ? LIMIT 1',
            [$tenantId, $row[0]],
            'INSERT INTO categories (id, tenant_id, name, name_en, sort_order) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $row[0], $row[1], $row[2]]
        );
        seed_exec($pdo, 'UPDATE categories SET name_en = ?, sort_order = ? WHERE id = ?', [$row[1], $row[2], $id]);
        $categories[$row[0]] = $id;
    }
    $created[] = 'categories';

    $optionGroupId = seed_find_or_insert(
        $pdo,
        'SELECT id FROM option_groups WHERE tenant_id = ? AND name = ? LIMIT 1',
        [$tenantId, 'ごはん量'],
        'INSERT INTO option_groups (id, tenant_id, name, name_en, selection_type, min_select, max_select, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$tenantId, 'ごはん量', 'Rice size', 'single', 0, 1, 10]
    );
    $choices = [
        ['普通', 'Regular', 0, 1, 10],
        ['大盛り', 'Large', 150, 0, 20],
        ['少なめ', 'Small', -50, 0, 30],
    ];
    foreach ($choices as $choice) {
        seed_find_or_insert(
            $pdo,
            'SELECT id FROM option_choices WHERE group_id = ? AND name = ? LIMIT 1',
            [$optionGroupId, $choice[0]],
            'INSERT INTO option_choices (id, group_id, name, name_en, price_diff, is_default, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$optionGroupId, $choice[0], $choice[1], $choice[2], $choice[3], $choice[4]]
        );
    }
    $created[] = 'options';

    $menuIds = [];
    $menuRows = [
        ['蒸し鶏のサラダ', 'Steamed Chicken Salad', 'サラダ', 980, '鶏むね肉と葉野菜の軽い前菜。', 10, ['chicken']],
        ['POSLAハンバーグ', 'POSLA Hamburg Steak', 'メイン', 1480, 'ランチ・ディナーで使える主力メニュー。', 20, ['egg', 'wheat']],
        ['季節のパスタ', 'Seasonal Pasta', 'メイン', 1320, '限定食材を使った季節替わりのパスタ。', 30, ['wheat']],
        ['レモンスカッシュ', 'Lemon Squash', 'ドリンク', 580, '自家製シロップのノンアルコールドリンク。', 40, []],
        ['自家製プリン', 'House Pudding', 'デザート', 520, '卵を使った定番デザート。', 50, ['egg', 'milk']],
    ];
    foreach ($menuRows as $row) {
        $id = seed_find_or_insert(
            $pdo,
            'SELECT id FROM menu_templates WHERE tenant_id = ? AND name = ? LIMIT 1',
            [$tenantId, $row[0]],
            'INSERT INTO menu_templates (id, tenant_id, category_id, name, name_en, base_price, description, allergens, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $categories[$row[2]], $row[0], $row[1], $row[3], $row[4], seed_json($row[6]), $row[5]]
        );
        seed_exec(
            $pdo,
            'UPDATE menu_templates SET category_id = ?, name_en = ?, base_price = ?, description = ?, allergens = ?, sort_order = ?, is_active = 1 WHERE id = ?',
            [$categories[$row[2]], $row[1], $row[3], $row[4], seed_json($row[6]), $row[5], $id]
        );
        $menuIds[$row[0]] = $id;
        if ($row[2] === 'メイン') {
            seed_exec(
                $pdo,
                'INSERT IGNORE INTO menu_template_options (template_id, group_id, is_required, sort_order) VALUES (?, ?, 0, 10)',
                [$id, $optionGroupId]
            );
        }
    }
    $created[] = 'menu';

    $localRows = [
        ['本日限定 魚介のカルパッチョ', 'Today Seafood Carpaccio', 'サラダ', 1280, '数量限定。売り切れ時は店舗メニューで品切れにします。', 100],
        ['17時限定 ハッピーアワーセット', 'Happy Hour Set', 'ドリンク', 980, '17時台の来店促進用セット。', 110],
    ];
    foreach ($localRows as $row) {
        $id = seed_find_or_insert(
            $pdo,
            'SELECT id FROM store_local_items WHERE store_id = ? AND name = ? LIMIT 1',
            [$storeId, $row[0]],
            'INSERT INTO store_local_items (id, store_id, category_id, name, name_en, price, description, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$storeId, $categories[$row[2]], $row[0], $row[1], $row[3], $row[4], $row[5]]
        );
        seed_exec($pdo, 'UPDATE store_local_items SET category_id = ?, name_en = ?, price = ?, description = ?, sort_order = ?, is_sold_out = 0 WHERE id = ?', [$categories[$row[2]], $row[1], $row[3], $row[4], $row[5], $id]);
    }
    $created[] = 'local menu';

    $ingredientIds = [];
    $ingredientRows = [
        ['鶏むね肉', 'g', 3200, 1.8, 800, 10],
        ['牛豚合挽肉', 'g', 2800, 2.4, 700, 20],
        ['生パスタ', 'g', 4200, 1.1, 1000, 30],
        ['レタス', '玉', 18, 180, 5, 40],
        ['卵', '個', 120, 28, 30, 50],
        ['レモン', '個', 70, 90, 15, 60],
    ];
    foreach ($ingredientRows as $row) {
        $id = seed_find_or_insert(
            $pdo,
            'SELECT id FROM ingredients WHERE tenant_id = ? AND name = ? LIMIT 1',
            [$tenantId, $row[0]],
            'INSERT INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $row[0], $row[1], $row[2], $row[3], $row[4], $row[5]]
        );
        seed_exec($pdo, 'UPDATE ingredients SET unit = ?, stock_quantity = ?, cost_price = ?, low_stock_threshold = ?, sort_order = ?, is_active = 1 WHERE id = ?', [$row[1], $row[2], $row[3], $row[4], $row[5], $id]);
        $ingredientIds[$row[0]] = $id;
    }
    $recipeRows = [
        ['蒸し鶏のサラダ', '鶏むね肉', 120],
        ['蒸し鶏のサラダ', 'レタス', 0.25],
        ['POSLAハンバーグ', '牛豚合挽肉', 180],
        ['POSLAハンバーグ', '卵', 1],
        ['季節のパスタ', '生パスタ', 140],
        ['レモンスカッシュ', 'レモン', 0.5],
        ['自家製プリン', '卵', 1],
    ];
    foreach ($recipeRows as $row) {
        seed_exec(
            $pdo,
            'INSERT INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
            [generate_uuid(), $menuIds[$row[0]], $ingredientIds[$row[1]], $row[2]]
        );
    }
    $created[] = 'inventory and recipes';

    $planId = seed_find_or_insert(
        $pdo,
        'SELECT id FROM time_limit_plans WHERE store_id = ? AND name = ? LIMIT 1',
        [$storeId, '90分カジュアルプラン'],
        'INSERT INTO time_limit_plans (id, store_id, name, name_en, duration_min, last_order_min, price, description, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$storeId, '90分カジュアルプラン', '90 min casual plan', 90, 20, 2980, '来店時の時間制プラン検証用サンプル。', 10]
    );
    $planItems = [
        ['枝豆', 'Edamame', 'サラダ', 10],
        ['唐揚げ', 'Fried Chicken', 'メイン', 20],
        ['ウーロン茶', 'Oolong Tea', 'ドリンク', 30],
    ];
    foreach ($planItems as $row) {
        seed_find_or_insert(
            $pdo,
            'SELECT id FROM plan_menu_items WHERE plan_id = ? AND name = ? LIMIT 1',
            [$planId, $row[0]],
            'INSERT INTO plan_menu_items (id, plan_id, category_id, name, name_en, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [$planId, $categories[$row[2]], $row[0], $row[1], $row[3]]
        );
    }
    $created[] = 'plans';

    $courseId = seed_find_or_insert(
        $pdo,
        'SELECT id FROM course_templates WHERE store_id = ? AND name = ? LIMIT 1',
        [$storeId, 'POSLAおすすめコース'],
        'INSERT INTO course_templates (id, store_id, name, name_en, price, description, phase_count, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$storeId, 'POSLAおすすめコース', 'POSLA recommended course', 3800, 'KDSフェーズ提供確認用のサンプルコース。', 3, 10]
    );
    $phases = [
        [1, '前菜', 'Starter', [['id' => $menuIds['蒸し鶏のサラダ'], 'name' => '蒸し鶏のサラダ', 'qty' => 1]], 0, 10],
        [2, 'メイン', 'Main', [['id' => $menuIds['POSLAハンバーグ'], 'name' => 'POSLAハンバーグ', 'qty' => 1]], 15, 20],
        [3, 'デザート', 'Dessert', [['id' => $menuIds['自家製プリン'], 'name' => '自家製プリン', 'qty' => 1]], 10, 30],
    ];
    foreach ($phases as $phase) {
        seed_exec(
            $pdo,
            'INSERT INTO course_phases (id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), name_en = VALUES(name_en), items = VALUES(items), auto_fire_min = VALUES(auto_fire_min), sort_order = VALUES(sort_order)',
            [generate_uuid(), $courseId, $phase[0], $phase[1], $phase[2], seed_json($phase[3]), $phase[4], $phase[5]]
        );
    }
    $created[] = 'courses';

    $tableRows = [
        ['T01', 2, 40, 40, 82, 82, 'rect', '1F'],
        ['T02', 4, 150, 40, 92, 82, 'rect', '1F'],
        ['T03', 4, 270, 40, 92, 82, 'rect', '1F'],
        ['T04', 6, 400, 40, 112, 86, 'rect', '1F'],
        ['C01', 1, 40, 170, 72, 72, 'circle', 'カウンター'],
        ['C02', 1, 130, 170, 72, 72, 'circle', 'カウンター'],
        ['P01', 4, 250, 170, 100, 86, 'rect', '個室'],
        ['P02', 6, 380, 170, 112, 86, 'rect', '個室'],
    ];
    foreach ($tableRows as $row) {
        $id = seed_find_or_insert(
            $pdo,
            'SELECT id FROM tables WHERE store_id = ? AND table_code = ? LIMIT 1',
            [$storeId, $row[0]],
            'INSERT INTO tables (id, store_id, table_code, capacity, pos_x, pos_y, width, height, shape, floor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$storeId, $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7]]
        );
        seed_exec($pdo, 'UPDATE tables SET capacity = ?, pos_x = ?, pos_y = ?, width = ?, height = ?, shape = ?, floor = ?, is_active = 1 WHERE id = ?', [$row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $id]);
    }
    $created[] = 'tables';

    $stations = [
        ['メインKDS', 'Main KDS', 10],
        ['ドリンクKDS', 'Drink KDS', 20],
    ];
    foreach ($stations as $row) {
        seed_find_or_insert(
            $pdo,
            'SELECT id FROM kds_stations WHERE store_id = ? AND name = ? LIMIT 1',
            [$storeId, $row[0]],
            'INSERT INTO kds_stations (id, store_id, name, name_en, sort_order) VALUES (?, ?, ?, ?, ?)',
            [$storeId, $row[0], $row[1], $row[2]]
        );
    }
    $created[] = 'kds';

    seed_exec(
        $pdo,
        'INSERT INTO store_settings (store_id, receipt_store_name, takeout_enabled, self_checkout_enabled, brand_display_name)
         VALUES (?, ?, 1, 1, ?)
         ON DUPLICATE KEY UPDATE
           receipt_store_name = COALESCE(receipt_store_name, VALUES(receipt_store_name)),
           takeout_enabled = 1,
           self_checkout_enabled = 1,
           brand_display_name = COALESCE(brand_display_name, VALUES(brand_display_name))',
        [$storeId, $store['name'], $store['name']]
    );

    $staffRows = [
        ['manager', 'sample-' . $storeSlug . '-submgr', 'サンプル副店長'],
        ['staff', 'sample-' . $storeSlug . '-staff1', 'サンプルスタッフA'],
        ['staff', 'sample-' . $storeSlug . '-staff2', 'サンプルスタッフB'],
        ['staff', 'sample-' . $storeSlug . '-staff3', 'サンプルスタッフC'],
    ];
    foreach ($staffRows as $row) {
        $baseUsername = substr($row[1], 0, 42);
        $username = $baseUsername;
        $user = seed_one($pdo, 'SELECT id, tenant_id FROM users WHERE username = ? LIMIT 1', [$username]);
        $attempt = 0;
        while (!empty($user['id']) && $user['tenant_id'] !== $tenantId && $attempt < 10) {
            $username = substr($baseUsername . '-' . substr(sha1($storeId . ':' . $attempt), 0, 7), 0, 50);
            $user = seed_one($pdo, 'SELECT id, tenant_id FROM users WHERE username = ? LIMIT 1', [$username]);
            $attempt++;
        }
        if (!empty($user['id']) && $user['tenant_id'] === $tenantId) {
            $userId = $user['id'];
            seed_exec($pdo, 'UPDATE users SET display_name = ?, role = ?, is_active = 1 WHERE id = ? AND tenant_id = ?', [$row[2], $row[0], $userId, $tenantId]);
        } else {
            $userId = generate_uuid();
            seed_exec(
                $pdo,
                'INSERT INTO users (id, tenant_id, username, email, password_hash, display_name, role, is_active) VALUES (?, ?, ?, NULL, ?, ?, ?, 1)',
                [$userId, $tenantId, $username, password_hash('Demo1234!', PASSWORD_DEFAULT), $row[2], $row[0]]
            );
        }
        seed_exec($pdo, 'INSERT IGNORE INTO user_stores (user_id, store_id, visible_tools, hourly_rate) VALUES (?, ?, NULL, ?)', [$userId, $storeId, $row[0] === 'staff' ? 1200 : null]);
    }
    $created[] = 'staff';

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Seed failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Seeded store: {$store['name']} ({$storeId})\n";
echo "Sections: " . implode(', ', $created) . "\n";
echo "Sample staff password: Demo1234!\n";
