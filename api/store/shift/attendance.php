<?php
/**
 * 勤怠打刻 API（L-3）
 *
 * POST   ?action=clock-in  body:{store_id}                            → 出勤打刻
 * POST   ?action=clock-out body:{store_id}                            → 退勤打刻
 * GET    ?store_id=xxx&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD     → 勤怠履歴
 * PATCH  ?id=xxx body:{clock_in,clock_out,break_minutes,note}         → 勤怠修正（manager+）
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

// L-3b: Haversine距離計算（メートル）
function _haversine_distance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST', 'PATCH']);
$user   = require_auth();

$pdo      = get_db();
$tenantId = $user['tenant_id'];

// プランチェック
if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'シフト管理はProプラン以上で利用できます', 403);
}

$storeId = require_store_param();
require_store_access($storeId);
$action = $_GET['action'] ?? '';

// =============================================
// POST ?action=clock-in: 出勤打刻
// =============================================
if ($method === 'POST' && $action === 'clock-in') {
    $userId = $user['user_id'];

    // 二重打刻チェック
    $stmt = $pdo->prepare(
        'SELECT id, clock_in FROM attendance_logs
         WHERE tenant_id = ? AND store_id = ? AND user_id = ? AND status = \'working\'
         LIMIT 1'
    );
    $stmt->execute([$tenantId, $storeId, $userId]);
    $existing = $stmt->fetch();
    if ($existing) {
        json_error('ALREADY_CLOCKED_IN', '既に出勤中です（' . $existing['clock_in'] . '〜）', 400);
    }

    // L-3b: GPS出退勤検証
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $stmtGps = $pdo->prepare(
        'SELECT store_lat, store_lng, gps_radius_meters, gps_required
         FROM shift_settings WHERE store_id = ? AND tenant_id = ?'
    );
    $stmtGps->execute([$storeId, $tenantId]);
    $gpsSetting = $stmtGps->fetch();
    if ($gpsSetting && (int)$gpsSetting['gps_required'] === 1
        && $gpsSetting['store_lat'] !== null && $gpsSetting['store_lng'] !== null) {
        if (!isset($body['lat']) || !isset($body['lng'])) {
            json_error('GPS_REQUIRED', '位置情報の取得が必要です。ブラウザの位置情報を許可してください', 400);
        }
        $dist = _haversine_distance(
            (float)$gpsSetting['store_lat'], (float)$gpsSetting['store_lng'],
            (float)$body['lat'], (float)$body['lng']
        );
        if ($dist > (int)$gpsSetting['gps_radius_meters']) {
            json_error('OUT_OF_RANGE', '店舗から' . round($dist) . 'm離れています（許容: ' . $gpsSetting['gps_radius_meters'] . 'm）', 400);
        }
    }

    $now   = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    // 当日のシフト割当を検索して紐付け
    $stmtShift = $pdo->prepare(
        'SELECT id, start_time FROM shift_assignments
         WHERE tenant_id = ? AND store_id = ? AND user_id = ? AND shift_date = ?
         LIMIT 1'
    );
    $stmtShift->execute([$tenantId, $storeId, $userId, $today]);
    $shift = $stmtShift->fetch();
    $shiftAssignmentId = $shift ? $shift['id'] : null;

    // 早出チェック（警告レベル、打刻自体は許可）
    $earlyWarning = false;
    if ($shift) {
        $stmtSettings = $pdo->prepare(
            'SELECT early_clock_in_minutes FROM shift_settings
             WHERE store_id = ? AND tenant_id = ?'
        );
        $stmtSettings->execute([$storeId, $tenantId]);
        $settings = $stmtSettings->fetch();
        $earlyMinutes = $settings ? (int)$settings['early_clock_in_minutes'] : 15;

        $shiftStart = new DateTime($today . ' ' . $shift['start_time']);
        $clockIn    = new DateTime($now);
        $diff       = $shiftStart->getTimestamp() - $clockIn->getTimestamp();
        if ($diff > $earlyMinutes * 60) {
            $earlyWarning = true;
        }
    }

    $id = generate_uuid();

    $stmt = $pdo->prepare(
        'INSERT INTO attendance_logs
            (id, tenant_id, store_id, user_id, shift_assignment_id,
             clock_in, status, clock_in_method)
         VALUES (?, ?, ?, ?, ?, ?, \'working\', \'manual\')'
    );
    $stmt->execute([$id, $tenantId, $storeId, $userId, $shiftAssignmentId, $now]);

    write_audit_log(
        $pdo, $user, $storeId,
        'attendance_clock_in', 'attendance', $id,
        null,
        ['clock_in' => $now, 'shift_assignment_id' => $shiftAssignmentId]
    );

    $response = [
        'id'                  => $id,
        'clock_in'            => $now,
        'status'              => 'working',
        'shift_assignment_id' => $shiftAssignmentId,
    ];
    if ($earlyWarning) {
        $response['warning'] = 'シフト開始時刻よりかなり早い出勤です';
    }

    json_response($response, 201);
}

// =============================================
// POST ?action=clock-out: 退勤打刻
// =============================================
if ($method === 'POST' && $action === 'clock-out') {
    $userId = $user['user_id'];

    // working レコードを検索
    $stmt = $pdo->prepare(
        'SELECT id, clock_in, shift_assignment_id FROM attendance_logs
         WHERE tenant_id = ? AND store_id = ? AND user_id = ? AND status = \'working\'
         ORDER BY clock_in DESC LIMIT 1'
    );
    $stmt->execute([$tenantId, $storeId, $userId]);
    $record = $stmt->fetch();
    if (!$record) {
        json_error('NOT_CLOCKED_IN', '出勤打刻がありません', 400);
    }

    // L-3b: GPS出退勤検証（退勤）
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $stmtGps = $pdo->prepare(
        'SELECT store_lat, store_lng, gps_radius_meters, gps_required
         FROM shift_settings WHERE store_id = ? AND tenant_id = ?'
    );
    $stmtGps->execute([$storeId, $tenantId]);
    $gpsSetting = $stmtGps->fetch();
    if ($gpsSetting && (int)$gpsSetting['gps_required'] === 1
        && $gpsSetting['store_lat'] !== null && $gpsSetting['store_lng'] !== null) {
        if (!isset($body['lat']) || !isset($body['lng'])) {
            json_error('GPS_REQUIRED', '位置情報の取得が必要です。ブラウザの位置情報を許可してください', 400);
        }
        $dist = _haversine_distance(
            (float)$gpsSetting['store_lat'], (float)$gpsSetting['store_lng'],
            (float)$body['lat'], (float)$body['lng']
        );
        if ($dist > (int)$gpsSetting['gps_radius_meters']) {
            json_error('OUT_OF_RANGE', '店舗から' . round($dist) . 'm離れています（許容: ' . $gpsSetting['gps_radius_meters'] . 'm）', 400);
        }
    }

    $now = date('Y-m-d H:i:s');

    // デフォルト休憩時間を取得
    $stmtSettings = $pdo->prepare(
        'SELECT default_break_minutes FROM shift_settings
         WHERE store_id = ? AND tenant_id = ?'
    );
    $stmtSettings->execute([$storeId, $tenantId]);
    $settings = $stmtSettings->fetch();
    $defaultBreak = $settings ? (int)$settings['default_break_minutes'] : 60;

    // 6時間未満の勤務は休憩0
    $clockIn  = new DateTime($record['clock_in']);
    $clockOut = new DateTime($now);
    $workedMinutes = ($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60;
    $breakMinutes = ($workedMinutes >= 360) ? $defaultBreak : 0;

    $stmt = $pdo->prepare(
        'UPDATE attendance_logs
         SET clock_out = ?, break_minutes = ?, status = \'completed\', clock_out_method = \'manual\'
         WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$now, $breakMinutes, $record['id'], $tenantId]);

    write_audit_log(
        $pdo, $user, $storeId,
        'attendance_clock_out', 'attendance', $record['id'],
        ['status' => 'working'],
        ['clock_out' => $now, 'break_minutes' => $breakMinutes, 'status' => 'completed']
    );

    json_response([
        'id'            => $record['id'],
        'clock_in'      => $record['clock_in'],
        'clock_out'     => $now,
        'break_minutes' => $breakMinutes,
        'status'        => 'completed',
    ]);
}

// =============================================
// GET: 勤怠履歴
// =============================================
if ($method === 'GET') {
    $startDate = $_GET['start_date'] ?? '';
    $endDate   = $_GET['end_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_error('INVALID_DATE', 'start_date, end_date を YYYY-MM-DD 形式で指定してください', 400);
    }

    // staff は自分のみ
    if ($user['role'] === 'staff') {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.user_id, a.shift_assignment_id,
                    a.clock_in, a.clock_out, a.break_minutes,
                    a.status, a.clock_in_method, a.clock_out_method, a.note,
                    u.display_name, u.username
             FROM attendance_logs a
             JOIN users u ON u.id = a.user_id
             WHERE a.tenant_id = ? AND a.store_id = ?
               AND DATE(a.clock_in) BETWEEN ? AND ?
               AND a.user_id = ?
             ORDER BY a.clock_in DESC'
        );
        $stmt->execute([$tenantId, $storeId, $startDate, $endDate, $user['user_id']]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.user_id, a.shift_assignment_id,
                    a.clock_in, a.clock_out, a.break_minutes,
                    a.status, a.clock_in_method, a.clock_out_method, a.note,
                    u.display_name, u.username
             FROM attendance_logs a
             JOIN users u ON u.id = a.user_id
             WHERE a.tenant_id = ? AND a.store_id = ?
               AND DATE(a.clock_in) BETWEEN ? AND ?
             ORDER BY a.clock_in DESC'
        );
        $stmt->execute([$tenantId, $storeId, $startDate, $endDate]);
    }

    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['break_minutes'] = (int)$r['break_minutes'];
    }
    unset($r);

    // 現在勤務中のレコード（打刻ボタン状態表示用）
    $stmtWorking = $pdo->prepare(
        'SELECT id, clock_in, shift_assignment_id FROM attendance_logs
         WHERE tenant_id = ? AND store_id = ? AND user_id = ? AND status = \'working\'
         LIMIT 1'
    );
    $stmtWorking->execute([$tenantId, $storeId, $user['user_id']]);
    $working = $stmtWorking->fetch();

    json_response([
        'attendance' => $rows,
        'working'    => $working ?: null,
    ]);
}

// =============================================
// PATCH: 勤怠修正（manager+のみ）
// =============================================
if ($method === 'PATCH') {
    require_role('manager');

    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM attendance_logs
         WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$id, $tenantId, $storeId]);
    $old = $stmt->fetch();
    if (!$old) {
        json_error('NOT_FOUND', '勤怠レコードが見つかりません', 404);
    }

    $body    = get_json_body();
    $updates = [];
    $params  = [];

    if (isset($body['clock_in'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $body['clock_in'])) {
            json_error('INVALID_DATETIME', 'clock_in は YYYY-MM-DD HH:MM 形式で指定してください', 400);
        }
        $updates[] = 'clock_in = ?';
        $params[]  = $body['clock_in'];
    }

    if (isset($body['clock_out'])) {
        if ($body['clock_out'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $body['clock_out'])) {
            json_error('INVALID_DATETIME', 'clock_out は YYYY-MM-DD HH:MM 形式で指定してください', 400);
        }
        $updates[] = 'clock_out = ?';
        $params[]  = $body['clock_out'];
        // clock_out が設定されたら status を completed に
        if ($body['clock_out'] !== null) {
            $updates[] = 'status = \'completed\'';
        }
    }

    if (isset($body['break_minutes'])) {
        $bm = (int)$body['break_minutes'];
        if ($bm < 0 || $bm > 480) {
            json_error('INVALID_BREAK', 'break_minutes は 0〜480 の範囲で指定してください', 400);
        }
        $updates[] = 'break_minutes = ?';
        $params[]  = $bm;
    }

    if (array_key_exists('note', $body)) {
        $updates[] = 'note = ?';
        $params[]  = $body['note'];
    }

    if (empty($updates)) {
        json_error('NO_FIELDS', '更新するフィールドが指定されていません', 400);
    }

    $params[] = $id;
    $params[] = $tenantId;
    $params[] = $storeId;

    $sql = 'UPDATE attendance_logs SET ' . implode(', ', $updates) .
           ' WHERE id = ? AND tenant_id = ? AND store_id = ?';
    $stmtU = $pdo->prepare($sql);
    $stmtU->execute($params);

    write_audit_log(
        $pdo, $user, $storeId,
        'attendance_edit', 'attendance', $id,
        $old, $body,
        $body['note'] ?? null
    );

    json_response(['updated' => true]);
}
