<?php
/**
 * 店舗メニューオーバーライド CSV エクスポート/インポート API
 *
 * GET  /api/store/menu-overrides-csv.php?store_id=xxx  — CSV エクスポート
 * POST /api/store/menu-overrides-csv.php               — CSV インポート（multipart/form-data）
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
        'SELECT mt.id AS template_id, c.name AS category_name, mt.name, mt.name_en,
                mt.price AS base_price, mt.is_sold_out AS template_sold_out,
                COALESCE(smo.price, mt.price) AS effective_price,
                COALESCE(smo.is_hidden, 0) AS is_hidden,
                COALESCE(smo.is_sold_out, 0) AS override_sold_out,
                COALESCE(smo.sort_order, mt.sort_order) AS effective_sort_order
         FROM menu_items mt
         JOIN categories c ON c.id = mt.category_id
         LEFT JOIN store_menu_overrides smo ON smo.template_id = mt.id AND smo.store_id = ?
         WHERE mt.tenant_id = ? AND mt.is_removed = 0
         ORDER BY c.sort_order, mt.sort_order, mt.name'
    );
    $stmt->execute([$storeId, $tenantId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="menu-overrides.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['template_id', 'category_name', 'name', 'name_en', 'base_price', 'effective_price', 'is_hidden', 'is_sold_out', 'sort_order']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['template_id'], $row['category_name'], $row['name'], $row['name_en'],
            $row['base_price'], $row['effective_price'],
            $row['is_hidden'], $row['override_sold_out'], $row['effective_sort_order']
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

    if (!in_array('template_id', $header)) {
        fclose($handle);
        json_error('MISSING_COLUMN', '必須カラム "template_id" がありません', 400);
    }

    // テンプレートID→存在確認マップ
    $stmt = $pdo->prepare('SELECT id FROM menu_items WHERE tenant_id = ? AND is_removed = 0');
    $stmt->execute([$tenantId]);
    $validTemplates = [];
    foreach ($stmt->fetchAll() as $row) $validTemplates[$row['id']] = true;

    $updated = 0; $errors = []; $lineNum = 1;

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) < count($header)) $row = array_pad($row, count($header), '');
            $data = array_combine($header, array_slice($row, 0, count($header)));

            $templateId = trim($data['template_id'] ?? '');
            if (!$templateId || !isset($validTemplates[$templateId])) {
                $errors[] = "行{$lineNum}: テンプレートID \"{$templateId}\" が見つかりません";
                continue;
            }

            $price = isset($data['effective_price']) && $data['effective_price'] !== '' ? (int)$data['effective_price'] : null;
            $isHidden = isset($data['is_hidden']) ? (int)$data['is_hidden'] : 0;
            $isSoldOut = isset($data['is_sold_out']) ? (int)$data['is_sold_out'] : 0;
            $sortOrder = isset($data['sort_order']) && $data['sort_order'] !== '' ? (int)$data['sort_order'] : null;

            // UPSERT
            $pdo->prepare(
                'INSERT INTO store_menu_overrides (id, store_id, template_id, price, is_hidden, is_sold_out, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE price=VALUES(price), is_hidden=VALUES(is_hidden), is_sold_out=VALUES(is_sold_out), sort_order=VALUES(sort_order)'
            )->execute([
                generate_uuid(), $storeId, $templateId,
                $price, $isHidden, $isSoldOut, $sortOrder
            ]);
            $updated++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($handle);
        json_error('IMPORT_FAILED', 'インポートに失敗しました: ' . $e->getMessage(), 500);
    }

    fclose($handle);
    json_response(['updated' => $updated, 'errors' => $errors]);
}
