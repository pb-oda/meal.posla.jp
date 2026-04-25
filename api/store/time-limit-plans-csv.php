<?php
/**
 * 食べ放題プラン CSV エクスポート/インポート API
 *
 * GET  /api/store/time-limit-plans-csv.php?store_id=xxx  — CSV エクスポート
 * POST /api/store/time-limit-plans-csv.php               — CSV インポート
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST']);
$user = require_role('manager');
$pdo = get_db();

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM time_limit_plans LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'time_limit_plans テーブルが未作成です', 500);
}

// ----- GET: CSV エクスポート -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $stmt = $pdo->prepare(
        'SELECT id, name, name_en, duration_min, last_order_min, price, description, sort_order, is_active
         FROM time_limit_plans WHERE store_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$storeId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time-limit-plans.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'name', 'name_en', 'duration_min', 'last_order_min', 'price', 'description', 'sort_order', 'is_active']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'], $row['name'], $row['name_en'],
            $row['duration_min'], $row['last_order_min'], $row['price'],
            $row['description'], $row['sort_order'], $row['is_active']
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

    foreach (['name', 'duration_min', 'price'] as $req) {
        if (!in_array($req, $header)) {
            fclose($handle);
            json_error('MISSING_COLUMN', '必須カラム "' . $req . '" がありません', 400);
        }
    }

    // 既存IDセット
    $stmt = $pdo->prepare('SELECT id FROM time_limit_plans WHERE store_id = ?');
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
            $durationMin = (int)($data['duration_min'] ?? 0);
            $price = (int)($data['price'] ?? 0);

            if (!$name) { $errors[] = "行{$lineNum}: name が空です"; continue; }
            if ($durationMin <= 0) { $errors[] = "行{$lineNum}: duration_min は正の整数が必要です"; continue; }

            $id = trim($data['id'] ?? '');

            $vals = [
                $name,
                trim($data['name_en'] ?? ''),
                $durationMin,
                (int)($data['last_order_min'] ?? 15),
                $price,
                trim($data['description'] ?? ''),
                (int)($data['sort_order'] ?? 0),
                isset($data['is_active']) ? (int)$data['is_active'] : 1
            ];

            if ($id && isset($existingIds[$id])) {
                $pdo->prepare(
                    'UPDATE time_limit_plans SET name=?, name_en=?, duration_min=?, last_order_min=?, price=?, description=?, sort_order=?, is_active=? WHERE id=? AND store_id=?'
                )->execute(array_merge($vals, [$id, $storeId]));
                $updated++;
            } else {
                $newId = ($id && !isset($existingIds[$id])) ? $id : generate_uuid();
                $pdo->prepare(
                    'INSERT INTO time_limit_plans (id, store_id, name, name_en, duration_min, last_order_min, price, description, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute(array_merge([$newId, $storeId], $vals));
                $created++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($handle);
        // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
        error_log('[H-14][api/store/time-limit-plans-csv.php] csv_import_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        json_error('IMPORT_FAILED', 'インポートに失敗しました', 500);
    }

    fclose($handle);
    json_response(['created' => $created, 'updated' => $updated, 'errors' => $errors]);
}
