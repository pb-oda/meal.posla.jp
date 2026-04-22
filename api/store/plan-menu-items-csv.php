<?php
/**
 * プラン専用メニュー CSV エクスポート/インポート API
 *
 * GET  /api/store/plan-menu-items-csv.php?store_id=xxx  — 全プラン分エクスポート
 * POST /api/store/plan-menu-items-csv.php               — CSV インポート
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST']);
$user = require_role('manager');
$pdo = get_db();

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM plan_menu_items LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'plan_menu_items テーブルが未作成です', 500);
}

// ----- GET: CSV エクスポート -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $stmt = $pdo->prepare(
        'SELECT pmi.id, tlp.name AS plan_name, c.name AS category_name,
                pmi.name, pmi.name_en, pmi.description, pmi.image_url,
                pmi.sort_order, pmi.is_active
         FROM plan_menu_items pmi
         JOIN time_limit_plans tlp ON tlp.id = pmi.plan_id
         LEFT JOIN categories c ON c.id = pmi.category_id
         WHERE tlp.store_id = ?
         ORDER BY tlp.sort_order, tlp.name, pmi.sort_order, pmi.name'
    );
    $stmt->execute([$storeId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plan-menu-items.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'plan_name', 'category_name', 'name', 'name_en', 'description', 'image_url', 'sort_order', 'is_active']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'], $row['plan_name'], $row['category_name'],
            $row['name'], $row['name_en'], $row['description'],
            $row['image_url'], $row['sort_order'], $row['is_active']
        ]);
    }
    fclose($out);
    exit;
}

// ----- POST: CSV インポート -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $storeId = $_POST['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_id は必須です', 400);
    require_store_access($storeId);

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

    foreach (['plan_name', 'name'] as $req) {
        if (!in_array($req, $header)) {
            fclose($handle);
            json_error('MISSING_COLUMN', '必須カラム "' . $req . '" がありません', 400);
        }
    }

    // プラン名→IDマップ
    $stmt = $pdo->prepare('SELECT id, name FROM time_limit_plans WHERE store_id = ?');
    $stmt->execute([$storeId]);
    $planMap = [];
    foreach ($stmt->fetchAll() as $p) $planMap[$p['name']] = $p['id'];

    // カテゴリ名→IDマップ
    $tenantId = $user['tenant_id'];
    $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $catMap = [];
    foreach ($stmt->fetchAll() as $cat) $catMap[$cat['name']] = $cat['id'];

    // 既存IDセット
    $stmt = $pdo->prepare(
        'SELECT pmi.id FROM plan_menu_items pmi
         JOIN time_limit_plans tlp ON tlp.id = pmi.plan_id
         WHERE tlp.store_id = ?'
    );
    $stmt->execute([$storeId]);
    $existingIds = [];
    foreach ($stmt->fetchAll() as $row) $existingIds[$row['id']] = true;

    $created = 0; $updated = 0; $errors = []; $lineNum = 1;

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) < count($header)) $row = array_pad($row, count($header), '');
            $data = array_combine($header, array_slice($row, 0, count($header)));

            $planName = trim($data['plan_name'] ?? '');
            $name = trim($data['name'] ?? '');

            if (!$name) { $errors[] = "行{$lineNum}: name が空です"; continue; }
            if (!$planName || !isset($planMap[$planName])) {
                $errors[] = "行{$lineNum}: プラン \"{$planName}\" が見つかりません";
                continue;
            }

            $planId = $planMap[$planName];
            $catName = trim($data['category_name'] ?? '');
            $catId = ($catName && isset($catMap[$catName])) ? $catMap[$catName] : null;
            $id = trim($data['id'] ?? '');

            $vals = [
                $catId, $name, trim($data['name_en'] ?? ''),
                trim($data['description'] ?? ''), trim($data['image_url'] ?? ''),
                (int)($data['sort_order'] ?? 0),
                isset($data['is_active']) ? (int)$data['is_active'] : 1
            ];

            if ($id && isset($existingIds[$id])) {
                $pdo->prepare(
                    'UPDATE plan_menu_items SET category_id=?, name=?, name_en=?, description=?, image_url=?, sort_order=?, is_active=? WHERE id=?'
                )->execute(array_merge($vals, [$id]));
                $updated++;
            } else {
                $newId = ($id && !isset($existingIds[$id])) ? $id : generate_uuid();
                $pdo->prepare(
                    'INSERT INTO plan_menu_items (id, plan_id, category_id, name, name_en, description, image_url, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute(array_merge([$newId, $planId], $vals));
                $created++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($handle);
        // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
        error_log('[H-14][api/store/plan-menu-items-csv.php] csv_import_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        json_error('IMPORT_FAILED', 'インポートに失敗しました', 500);
    }

    fclose($handle);
    json_response(['created' => $created, 'updated' => $updated, 'errors' => $errors]);
}
