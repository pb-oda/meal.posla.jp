<?php
/**
 * コース料理 CSV エクスポート/インポート API
 *
 * GET  /api/store/course-csv.php?store_id=xxx  — CSV エクスポート（テンプレート + フェーズ）
 * POST /api/store/course-csv.php               — CSV インポート
 *
 * フォーマット: 1行 = 1フェーズ（コース情報はフェーズごとに重複記載）
 * コースのみ行（phase_number空）も対応。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST']);
$user = require_role('manager');
$pdo = get_db();

// テーブル存在チェック
try {
    $pdo->query('SELECT 1 FROM course_templates LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', 'course_templates テーブルが未作成です', 500);
}

// ----- GET: CSV エクスポート -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    // コーステンプレート
    $stmt = $pdo->prepare(
        'SELECT id, name, name_en, price, description, phase_count, sort_order, is_active
         FROM course_templates WHERE store_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$storeId]);
    $courses = $stmt->fetchAll();

    // フェーズ取得
    $phaseMap = []; // course_id => [phases]
    if (!empty($courses)) {
        $courseIds = array_column($courses, 'id');
        $ph = implode(',', array_fill(0, count($courseIds), '?'));
        try {
            $stmt = $pdo->prepare(
                "SELECT id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order
                 FROM course_phases WHERE course_id IN ($ph) ORDER BY phase_number"
            );
            $stmt->execute($courseIds);
            foreach ($stmt->fetchAll() as $p) {
                $phaseMap[$p['course_id']][] = $p;
            }
        } catch (PDOException $e) {
            // course_phases 未作成時はスキップ
            error_log('[P1-12][api/store/course-csv.php:54] fetch_course_phases: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="courses.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'course_name', 'course_name_en', 'course_price', 'course_description',
        'course_sort_order', 'course_is_active',
        'phase_number', 'phase_name', 'phase_name_en', 'phase_items', 'phase_auto_fire_min'
    ]);

    foreach ($courses as $c) {
        $phases = $phaseMap[$c['id']] ?? [];
        if (empty($phases)) {
            // フェーズなしのコース → 1行
            fputcsv($out, [
                $c['name'], $c['name_en'], $c['price'], $c['description'],
                $c['sort_order'], $c['is_active'],
                '', '', '', '', ''
            ]);
        } else {
            foreach ($phases as $p) {
                fputcsv($out, [
                    $c['name'], $c['name_en'], $c['price'], $c['description'],
                    $c['sort_order'], $c['is_active'],
                    $p['phase_number'], $p['name'], $p['name_en'],
                    $p['items'], // JSON文字列をそのまま出力
                    $p['auto_fire_min'] ?? ''
                ]);
            }
        }
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

    if (!in_array('course_name', $header)) {
        fclose($handle);
        json_error('MISSING_COLUMN', '必須カラム "course_name" がありません', 400);
    }

    // 既存コース名→IDマップ
    $stmt = $pdo->prepare('SELECT id, name FROM course_templates WHERE store_id = ?');
    $stmt->execute([$storeId]);
    $courseMap = [];
    foreach ($stmt->fetchAll() as $c) $courseMap[$c['name']] = $c['id'];

    // 既存フェーズ: course_id:phase_number → phase_id
    $existingPhases = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT cp.id, cp.course_id, cp.phase_number
             FROM course_phases cp
             JOIN course_templates ct ON ct.id = cp.course_id
             WHERE ct.store_id = ?'
        );
        $stmt->execute([$storeId]);
        foreach ($stmt->fetchAll() as $p) {
            $existingPhases[$p['course_id'] . ':' . $p['phase_number']] = $p['id'];
        }
    } catch (PDOException $e) {
        // course_phases 未作成時はスキップ
        error_log('[P1-12][api/store/course-csv.php:140] fetch_existing_phases: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }

    // CSV全行を読み込んでコースごとにグループ化
    $rows = [];
    $lineNum = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $lineNum++;
        if (count($row) < count($header)) $row = array_pad($row, count($header), '');
        $data = array_combine($header, array_slice($row, 0, count($header)));
        $data['_line'] = $lineNum;
        $rows[] = $data;
    }
    fclose($handle);

    $coursesCreated = 0; $coursesUpdated = 0;
    $phasesCreated = 0; $phasesUpdated = 0;
    $errors = [];

    $pdo->beginTransaction();
    try {
        // コースごとにグループ化（同じ course_name → 同一コース）
        $courseGroups = [];
        foreach ($rows as $r) {
            $cn = trim($r['course_name'] ?? '');
            if (!$cn) { $errors[] = "行{$r['_line']}: course_name が空です"; continue; }
            $courseGroups[$cn][] = $r;
        }

        foreach ($courseGroups as $courseName => $groupRows) {
            $first = $groupRows[0];

            // コーステンプレートの作成 or 更新
            if (isset($courseMap[$courseName])) {
                $courseId = $courseMap[$courseName];
                $pdo->prepare(
                    'UPDATE course_templates SET name_en=?, price=?, description=?, sort_order=?, is_active=? WHERE id=?'
                )->execute([
                    trim($first['course_name_en'] ?? ''),
                    (int)($first['course_price'] ?? 0),
                    trim($first['course_description'] ?? ''),
                    (int)($first['course_sort_order'] ?? 0),
                    isset($first['course_is_active']) ? (int)$first['course_is_active'] : 1,
                    $courseId
                ]);
                $coursesUpdated++;
            } else {
                $courseId = generate_uuid();
                $pdo->prepare(
                    'INSERT INTO course_templates (id, store_id, name, name_en, price, description, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    $courseId, $storeId, $courseName,
                    trim($first['course_name_en'] ?? ''),
                    (int)($first['course_price'] ?? 0),
                    trim($first['course_description'] ?? ''),
                    (int)($first['course_sort_order'] ?? 0),
                    isset($first['course_is_active']) ? (int)$first['course_is_active'] : 1
                ]);
                $courseMap[$courseName] = $courseId;
                $coursesCreated++;
            }

            // フェーズ処理
            $phaseCount = 0;
            foreach ($groupRows as $r) {
                $phaseNum = trim($r['phase_number'] ?? '');
                if ($phaseNum === '') continue; // フェーズ行でない

                $phaseNum = (int)$phaseNum;
                $phaseCount++;
                $phaseKey = $courseId . ':' . $phaseNum;
                $phaseName = trim($r['phase_name'] ?? 'フェーズ' . $phaseNum);

                // items JSON のバリデーション
                $itemsJson = trim($r['phase_items'] ?? '[]');
                $decoded = json_decode($itemsJson, true);
                if (!is_array($decoded)) $itemsJson = '[]';

                $autoFire = trim($r['phase_auto_fire_min'] ?? '');
                $autoFire = $autoFire !== '' ? (int)$autoFire : null;

                if (isset($existingPhases[$phaseKey])) {
                    $pdo->prepare(
                        'UPDATE course_phases SET name=?, name_en=?, items=?, auto_fire_min=? WHERE id=?'
                    )->execute([
                        $phaseName, trim($r['phase_name_en'] ?? ''),
                        $itemsJson, $autoFire,
                        $existingPhases[$phaseKey]
                    ]);
                    $phasesUpdated++;
                } else {
                    try {
                        $pdo->prepare(
                            'INSERT INTO course_phases (id, course_id, phase_number, name, name_en, items, auto_fire_min, sort_order) VALUES (?,?,?,?,?,?,?,?)'
                        )->execute([
                            generate_uuid(), $courseId, $phaseNum,
                            $phaseName, trim($r['phase_name_en'] ?? ''),
                            $itemsJson, $autoFire, $phaseNum
                        ]);
                        $phasesCreated++;
                    } catch (PDOException $e) {
                        // course_phases 未作成時はスキップ
                        $errors[] = "行{$r['_line']}: フェーズの作成に失敗しました";
                    }
                }
            }

            // phase_count 更新
            if ($phaseCount > 0) {
                $pdo->prepare('UPDATE course_templates SET phase_count = ? WHERE id = ?')
                    ->execute([$phaseCount, $courseId]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
        error_log('[H-14][api/store/course-csv.php] csv_import_failed: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        json_error('IMPORT_FAILED', 'インポートに失敗しました', 500);
    }

    json_response([
        'courses_created' => $coursesCreated,
        'courses_updated' => $coursesUpdated,
        'phases_created'  => $phasesCreated,
        'phases_updated'  => $phasesUpdated,
        'errors'          => $errors
    ]);
}
