<?php
/**
 * レシピ CSV エクスポート/インポート API
 *
 * GET  /api/owner/recipes-csv.php  — CSV エクスポート（全メニューのレシピ一覧）
 * POST /api/owner/recipes-csv.php  — CSV インポート（メニュー名 + 原材料名でマッチ）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];

try {
    $pdo->query('SELECT 1 FROM recipes LIMIT 0');
    $pdo->query('SELECT 1 FROM ingredients LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'recipes/ingredients テーブルが未作成です', 500);
}

// ----- GET: CSV エクスポート -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT r.id AS recipe_id, mt.name AS menu_name, i.name AS ingredient_name,
                i.unit AS ingredient_unit, r.quantity
         FROM recipes r
         JOIN menu_templates mt ON mt.id = r.menu_template_id
         JOIN ingredients i ON i.id = r.ingredient_id
         WHERE mt.tenant_id = ?
         ORDER BY mt.name, i.name'
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="recipes.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['recipe_id', 'menu_name', 'ingredient_name', 'ingredient_unit', 'quantity']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['recipe_id'], $row['menu_name'], $row['ingredient_name'],
            $row['ingredient_unit'], $row['quantity']
        ]);
    }
    fclose($out);
    exit;
}

// ----- POST: CSV インポート -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        json_error('NO_FILE', 'CSVファイルをアップロードしてください', 400);
    }

    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$handle) json_error('FILE_READ_ERROR', 'ファイルの読み込みに失敗しました', 500);

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $header = fgetcsv($handle);
    if (!$header) { fclose($handle); json_error('EMPTY_CSV', 'CSVが空です', 400); }
    $header = array_map('trim', $header);

    foreach (['menu_name', 'ingredient_name', 'quantity'] as $req) {
        if (!in_array($req, $header)) {
            fclose($handle);
            json_error('MISSING_COLUMN', '必須カラム "' . $req . '" がありません', 400);
        }
    }

    // メニュー名→IDマップ
    $stmt = $pdo->prepare('SELECT id, name FROM menu_templates WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $menuMap = [];
    foreach ($stmt->fetchAll() as $row) $menuMap[$row['name']] = $row['id'];

    // 原材料名→IDマップ
    $stmt = $pdo->prepare('SELECT id, name FROM ingredients WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $ingMap = [];
    foreach ($stmt->fetchAll() as $row) $ingMap[$row['name']] = $row['id'];

    // 既存レシピマップ（menu_id:ing_id → recipe_id）
    $stmt = $pdo->prepare(
        'SELECT r.id, r.menu_template_id, r.ingredient_id
         FROM recipes r
         JOIN menu_templates mt ON mt.id = r.menu_template_id
         WHERE mt.tenant_id = ?'
    );
    $stmt->execute([$tenantId]);
    $existingRecipes = [];
    foreach ($stmt->fetchAll() as $row) {
        $existingRecipes[$row['menu_template_id'] . ':' . $row['ingredient_id']] = $row['id'];
    }

    $created = 0; $updated = 0; $errors = []; $lineNum = 1;

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) < count($header)) $row = array_pad($row, count($header), '');
            $data = array_combine($header, array_slice($row, 0, count($header)));

            $menuName = trim($data['menu_name'] ?? '');
            $ingName  = trim($data['ingredient_name'] ?? '');
            $qty      = (float)($data['quantity'] ?? 0);

            if (!$menuName) { $errors[] = "行{$lineNum}: menu_name が空です"; continue; }
            if (!$ingName)  { $errors[] = "行{$lineNum}: ingredient_name が空です"; continue; }

            $menuId = $menuMap[$menuName] ?? null;
            if (!$menuId) { $errors[] = "行{$lineNum}: メニュー \"{$menuName}\" が見つかりません"; continue; }

            $ingId = $ingMap[$ingName] ?? null;
            if (!$ingId) { $errors[] = "行{$lineNum}: 原材料 \"{$ingName}\" が見つかりません"; continue; }

            $key = $menuId . ':' . $ingId;
            if (isset($existingRecipes[$key])) {
                $pdo->prepare('UPDATE recipes SET quantity = ? WHERE id = ?')
                    ->execute([$qty, $existingRecipes[$key]]);
                $updated++;
            } else {
                $newId = generate_uuid();
                $pdo->prepare('INSERT INTO recipes (id, menu_template_id, ingredient_id, quantity) VALUES (?,?,?,?)')
                    ->execute([$newId, $menuId, $ingId, $qty]);
                $existingRecipes[$key] = $newId;
                $created++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($handle);
        // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
        error_log('[H-14][api/owner/recipes-csv.php] csv_import_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        json_error('IMPORT_FAILED', 'インポートに失敗しました', 500);
    }

    fclose($handle);
    json_response(['created' => $created, 'updated' => $updated, 'errors' => $errors]);
}
