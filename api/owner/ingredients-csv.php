<?php
/**
 * 原材料 CSV エクスポート/インポート API
 *
 * GET  /api/owner/ingredients-csv.php  — CSV エクスポート
 * POST /api/owner/ingredients-csv.php  — CSV インポート（multipart/form-data）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM ingredients LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'ingredients テーブルが未作成です', 500);
}

// ----- GET: CSV エクスポート -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order, is_active
         FROM ingredients WHERE tenant_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ingredients.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'name', 'unit', 'stock_quantity', 'cost_price', 'low_stock_threshold', 'sort_order', 'is_active']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'], $row['name'], $row['unit'],
            $row['stock_quantity'], $row['cost_price'],
            $row['low_stock_threshold'] ?? '',
            $row['sort_order'], $row['is_active']
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

    // BOMスキップ
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $header = fgetcsv($handle);
    if (!$header) { fclose($handle); json_error('EMPTY_CSV', 'CSVが空です', 400); }
    $header = array_map('trim', $header);

    if (!in_array('name', $header)) {
        fclose($handle);
        json_error('MISSING_COLUMN', '必須カラム "name" がありません', 400);
    }

    // 既存IDセット
    $stmt = $pdo->prepare('SELECT id FROM ingredients WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
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
            if (!$name) { $errors[] = "行{$lineNum}: name が空です"; continue; }

            $id = trim($data['id'] ?? '');
            $unit = trim($data['unit'] ?? '個') ?: '個';
            $stockQty = (float)($data['stock_quantity'] ?? 0);
            $costPrice = (float)($data['cost_price'] ?? 0);
            $threshold = isset($data['low_stock_threshold']) && $data['low_stock_threshold'] !== '' ? (float)$data['low_stock_threshold'] : null;
            $sortOrder = (int)($data['sort_order'] ?? 0);
            $isActive = isset($data['is_active']) && $data['is_active'] !== '' ? (int)$data['is_active'] : 1;

            if ($id && isset($existingIds[$id])) {
                $pdo->prepare(
                    'UPDATE ingredients SET name=?, unit=?, stock_quantity=?, cost_price=?, low_stock_threshold=?, sort_order=?, is_active=? WHERE id=? AND tenant_id=?'
                )->execute([$name, $unit, $stockQty, $costPrice, $threshold, $sortOrder, $isActive, $id, $tenantId]);
                $updated++;
            } else {
                $newId = ($id && !isset($existingIds[$id])) ? $id : generate_uuid();
                $pdo->prepare(
                    'INSERT INTO ingredients (id, tenant_id, name, unit, stock_quantity, cost_price, low_stock_threshold, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$newId, $tenantId, $name, $unit, $stockQty, $costPrice, $threshold, $sortOrder, $isActive]);
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
