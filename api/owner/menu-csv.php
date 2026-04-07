<?php
/**
 * 本部メニューテンプレート CSV エクスポート/インポート API
 *
 * GET  /api/owner/menu-csv.php?tenant_id=xxx   — CSVエクスポート
 * POST /api/owner/menu-csv.php                  — CSVインポート（multipart/form-data）
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
    $stmt = $pdo->prepare(
        'SELECT mt.id, c.name AS category_name, mt.name, mt.name_en, mt.base_price,
                mt.description, mt.description_en, mt.image_url, mt.sort_order,
                mt.is_sold_out, mt.is_active
         FROM menu_templates mt
         JOIN categories c ON c.id = mt.category_id
         WHERE mt.tenant_id = ?
         ORDER BY c.sort_order, mt.sort_order, mt.name'
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="menu-templates.csv"');
    header('Cache-Control: no-store');

    // BOM付きUTF-8（Excel互換）
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    // ヘッダー行
    fputcsv($out, ['id', 'category_name', 'name', 'name_en', 'base_price', 'description', 'description_en', 'image_url', 'sort_order', 'is_sold_out', 'is_active']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            $row['category_name'],
            $row['name'],
            $row['name_en'],
            $row['base_price'],
            $row['description'],
            $row['description_en'],
            $row['image_url'],
            $row['sort_order'],
            $row['is_sold_out'],
            $row['is_active']
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

    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle) {
        json_error('FILE_READ_ERROR', 'ファイルの読み込みに失敗しました', 500);
    }

    // BOMスキップ
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    // ヘッダー行読み込み
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        json_error('EMPTY_CSV', 'CSVが空です', 400);
    }

    // ヘッダー正規化（前後空白除去）
    $header = array_map('trim', $header);
    $requiredCols = ['category_name', 'name', 'base_price'];
    foreach ($requiredCols as $col) {
        if (!in_array($col, $header)) {
            fclose($handle);
            json_error('MISSING_COLUMN', '必須カラム "' . $col . '" がありません', 400);
        }
    }

    // カテゴリ名→IDマップ構築
    $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $catMap = [];
    foreach ($stmt->fetchAll() as $cat) {
        $catMap[$cat['name']] = $cat['id'];
    }

    // 既存テンプレートIDセット
    $stmt = $pdo->prepare('SELECT id FROM menu_templates WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $existingIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $existingIds[$row['id']] = true;
    }

    $created = 0;
    $updated = 0;
    $errors = [];
    $lineNum = 1; // ヘッダーが1行目

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), '');
            }
            $data = array_combine($header, array_slice($row, 0, count($header)));

            $name = trim($data['name'] ?? '');
            $catName = trim($data['category_name'] ?? '');
            $basePrice = (int)($data['base_price'] ?? 0);

            if (!$name) {
                $errors[] = "行{$lineNum}: name が空です";
                continue;
            }
            if (!$catName || !isset($catMap[$catName])) {
                $errors[] = "行{$lineNum}: カテゴリ \"{$catName}\" が見つかりません";
                continue;
            }

            $catId = $catMap[$catName];
            $id = trim($data['id'] ?? '');

            if ($id && isset($existingIds[$id])) {
                // UPDATE
                $stmt = $pdo->prepare(
                    'UPDATE menu_templates SET
                        category_id = ?, name = ?, name_en = ?, base_price = ?,
                        description = ?, description_en = ?, image_url = ?,
                        sort_order = ?, is_sold_out = ?, is_active = ?
                     WHERE id = ? AND tenant_id = ?'
                );
                $stmt->execute([
                    $catId,
                    $name,
                    trim($data['name_en'] ?? ''),
                    $basePrice,
                    trim($data['description'] ?? ''),
                    trim($data['description_en'] ?? ''),
                    trim($data['image_url'] ?? ''),
                    (int)($data['sort_order'] ?? 0),
                    (int)($data['is_sold_out'] ?? 0),
                    isset($data['is_active']) && $data['is_active'] !== '' ? (int)$data['is_active'] : 1,
                    $id,
                    $tenantId
                ]);
                $updated++;
            } else {
                // INSERT
                $newId = $id && !isset($existingIds[$id]) ? $id : generate_uuid();
                $stmt = $pdo->prepare(
                    'INSERT INTO menu_templates (id, tenant_id, category_id, name, name_en, base_price, description, description_en, image_url, sort_order, is_sold_out, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $newId,
                    $tenantId,
                    $catId,
                    $name,
                    trim($data['name_en'] ?? ''),
                    $basePrice,
                    trim($data['description'] ?? ''),
                    trim($data['description_en'] ?? ''),
                    trim($data['image_url'] ?? ''),
                    (int)($data['sort_order'] ?? 0),
                    (int)($data['is_sold_out'] ?? 0),
                    isset($data['is_active']) && $data['is_active'] !== '' ? (int)$data['is_active'] : 1
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
    json_response([
        'created' => $created,
        'updated' => $updated,
        'errors'  => $errors
    ]);
}
