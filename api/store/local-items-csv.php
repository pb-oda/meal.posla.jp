<?php
/**
 * 店舗限定メニュー CSV エクスポート/インポート API
 *
 * GET  /api/store/local-items-csv.php?store_id=xxx  — CSV エクスポート
 * POST /api/store/local-items-csv.php               — CSV インポート（multipart/form-data, store_id必須）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];

// ----- GET: CSV エクスポート -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $stmt = $pdo->prepare(
        'SELECT sli.id, c.name AS category_name, sli.name, sli.name_en, sli.price,
                sli.description, sli.description_en, sli.image_url, sli.sort_order, sli.is_sold_out
         FROM store_local_items sli
         JOIN categories c ON c.id = sli.category_id
         WHERE sli.store_id = ?
         ORDER BY c.sort_order, sli.sort_order, sli.name'
    );
    $stmt->execute([$storeId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="local-items.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'category_name', 'name', 'name_en', 'price', 'description', 'description_en', 'image_url', 'sort_order', 'is_sold_out']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'], $row['category_name'], $row['name'], $row['name_en'],
            $row['price'], $row['description'], $row['description_en'],
            $row['image_url'], $row['sort_order'], $row['is_sold_out']
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

    foreach (['category_name', 'name', 'price'] as $req) {
        if (!in_array($req, $header)) {
            fclose($handle);
            json_error('MISSING_COLUMN', '必須カラム "' . $req . '" がありません', 400);
        }
    }

    // カテゴリ名→IDマップ
    $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $catMap = [];
    foreach ($stmt->fetchAll() as $cat) $catMap[$cat['name']] = $cat['id'];

    // 既存IDセット
    $stmt = $pdo->prepare('SELECT id FROM store_local_items WHERE store_id = ?');
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

            $name = trim($data['name'] ?? '');
            $catName = trim($data['category_name'] ?? '');
            $price = (int)($data['price'] ?? 0);

            if (!$name) { $errors[] = "行{$lineNum}: name が空です"; continue; }
            if (!$catName || !isset($catMap[$catName])) {
                $errors[] = "行{$lineNum}: カテゴリ \"{$catName}\" が見つかりません";
                continue;
            }

            $catId = $catMap[$catName];
            $id = trim($data['id'] ?? '');

            if ($id && isset($existingIds[$id])) {
                $pdo->prepare(
                    'UPDATE store_local_items SET category_id=?, name=?, name_en=?, price=?, description=?, description_en=?, image_url=?, sort_order=?, is_sold_out=? WHERE id=? AND store_id=?'
                )->execute([
                    $catId, $name, trim($data['name_en'] ?? ''), $price,
                    trim($data['description'] ?? ''), trim($data['description_en'] ?? ''),
                    trim($data['image_url'] ?? ''),
                    (int)($data['sort_order'] ?? 0),
                    (int)($data['is_sold_out'] ?? 0),
                    $id, $storeId
                ]);
                $updated++;
            } else {
                $newId = ($id && !isset($existingIds[$id])) ? $id : generate_uuid();
                $pdo->prepare(
                    'INSERT INTO store_local_items (id, store_id, category_id, name, name_en, price, description, description_en, image_url, sort_order, is_sold_out) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $newId, $storeId, $catId, $name, trim($data['name_en'] ?? ''), $price,
                    trim($data['description'] ?? ''), trim($data['description_en'] ?? ''),
                    trim($data['image_url'] ?? ''),
                    (int)($data['sort_order'] ?? 0),
                    (int)($data['is_sold_out'] ?? 0)
                ]);
                $created++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($handle);
        json_error('IMPORT_FAILED', 'インポートに失敗しました: ' . $e->getMessage(), 500);
    }

    fclose($handle);
    json_response(['created' => $created, 'updated' => $updated, 'errors' => $errors]);
}
