<?php
/**
 * 希望シフト API（L-3）
 *
 * GET   ?store_id=xxx&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD  → 希望一覧
 * POST  body:{store_id, availabilities:[...]}                      → 希望一括提出
 * PATCH ?id=xxx body:{...}                                         → 希望修正
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

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

$validAvailability = ['available', 'preferred', 'unavailable'];

// =============================================
// GET: 希望一覧
// =============================================
if ($method === 'GET') {
    $startDate = $_GET['start_date'] ?? '';
    $endDate   = $_GET['end_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        json_error('INVALID_DATE', 'start_date, end_date を YYYY-MM-DD 形式で指定してください', 400);
    }

    // staff は自分の希望のみ
    if ($user['role'] === 'staff') {
        $stmt = $pdo->prepare(
            'SELECT sa.id, sa.user_id, sa.target_date, sa.availability,
                    sa.preferred_start, sa.preferred_end, sa.note, sa.submitted_at,
                    u.display_name, u.username
             FROM shift_availabilities sa
             JOIN users u ON u.id = sa.user_id
             WHERE sa.tenant_id = ? AND sa.store_id = ?
               AND sa.target_date BETWEEN ? AND ?
               AND sa.user_id = ?
             ORDER BY sa.target_date'
        );
        $stmt->execute([$tenantId, $storeId, $startDate, $endDate, $user['user_id']]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT sa.id, sa.user_id, sa.target_date, sa.availability,
                    sa.preferred_start, sa.preferred_end, sa.note, sa.submitted_at,
                    u.display_name, u.username
             FROM shift_availabilities sa
             JOIN users u ON u.id = sa.user_id
             WHERE sa.tenant_id = ? AND sa.store_id = ?
               AND sa.target_date BETWEEN ? AND ?
             ORDER BY sa.target_date, u.display_name'
        );
        $stmt->execute([$tenantId, $storeId, $startDate, $endDate]);
    }

    json_response(['availabilities' => $stmt->fetchAll()]);
}

// =============================================
// POST: 希望一括提出（1週間分まとめて）
// =============================================
if ($method === 'POST') {
    $body  = get_json_body();
    $items = $body['availabilities'] ?? [];

    if (empty($items)) {
        json_error('NO_DATA', 'availabilities 配列を指定してください', 400);
    }
    if (count($items) > 31) {
        json_error('TOO_MANY', '一度に提出できるのは最大31日分です', 400);
    }

    // バリデーション
    foreach ($items as $i => $item) {
        $date = $item['target_date'] ?? '';
        $avail = $item['availability'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_error('INVALID_DATE', "availabilities[$i].target_date が不正です", 400);
        }
        if (!in_array($avail, $validAvailability, true)) {
            json_error('INVALID_AVAILABILITY', "availabilities[$i].availability は available / preferred / unavailable のいずれかです", 400);
        }
        if ($avail !== 'unavailable') {
            $ps = $item['preferred_start'] ?? '';
            $pe = $item['preferred_end'] ?? '';
            if ($ps !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $ps)) {
                json_error('INVALID_TIME', "availabilities[$i].preferred_start が不正です", 400);
            }
            if ($pe !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $pe)) {
                json_error('INVALID_TIME', "availabilities[$i].preferred_end が不正です", 400);
            }
            if ($ps !== '' && $pe !== '' && $ps >= $pe) {
                json_error('INVALID_TIME_RANGE', "availabilities[$i]: 終了時刻は開始時刻より後にしてください", 400);
            }
        }
    }

    $userId = $user['user_id'];
    $results = [];

    // INSERT ... ON DUPLICATE KEY UPDATE で upsert
    $stmt = $pdo->prepare(
        'INSERT INTO shift_availabilities
            (id, tenant_id, store_id, user_id, target_date,
             availability, preferred_start, preferred_end, note, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
             availability    = VALUES(availability),
             preferred_start = VALUES(preferred_start),
             preferred_end   = VALUES(preferred_end),
             note            = VALUES(note),
             submitted_at    = NOW()'
    );

    foreach ($items as $item) {
        $id    = generate_uuid();
        $avail = $item['availability'];
        $ps    = ($avail !== 'unavailable' && isset($item['preferred_start']) && $item['preferred_start'] !== '')
                 ? $item['preferred_start'] : null;
        $pe    = ($avail !== 'unavailable' && isset($item['preferred_end']) && $item['preferred_end'] !== '')
                 ? $item['preferred_end'] : null;
        $note  = isset($item['note']) ? trim($item['note']) : null;

        $stmt->execute([
            $id, $tenantId, $storeId, $userId, $item['target_date'],
            $avail, $ps, $pe, $note,
        ]);

        $results[] = [
            'target_date'     => $item['target_date'],
            'availability'    => $avail,
            'preferred_start' => $ps,
            'preferred_end'   => $pe,
        ];
    }

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_availability_submit', 'shift_availability', null,
        null,
        ['count' => count($items), 'dates' => array_column($items, 'target_date')]
    );

    json_response(['submitted' => $results]);
}

// =============================================
// PATCH: 希望修正（個別）
// =============================================
if ($method === 'PATCH') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('MISSING_ID', 'id パラメータが必要です', 400);
    }

    // 自分の希望のみ修正可能（manager+は全員分）
    if ($user['role'] === 'staff') {
        $stmt = $pdo->prepare(
            'SELECT * FROM shift_availabilities
             WHERE id = ? AND tenant_id = ? AND store_id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $tenantId, $storeId, $user['user_id']]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM shift_availabilities
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmt->execute([$id, $tenantId, $storeId]);
    }

    $old = $stmt->fetch();
    if (!$old) {
        json_error('NOT_FOUND', '希望が見つかりません', 404);
    }

    $body    = get_json_body();
    $updates = [];
    $params  = [];

    if (isset($body['availability'])) {
        if (!in_array($body['availability'], $validAvailability, true)) {
            json_error('INVALID_AVAILABILITY', 'availability は available / preferred / unavailable のいずれかです', 400);
        }
        $updates[] = 'availability = ?';
        $params[]  = $body['availability'];
    }

    if (array_key_exists('preferred_start', $body)) {
        $updates[] = 'preferred_start = ?';
        $params[]  = ($body['preferred_start'] === '' ? null : $body['preferred_start']);
    }

    if (array_key_exists('preferred_end', $body)) {
        $updates[] = 'preferred_end = ?';
        $params[]  = ($body['preferred_end'] === '' ? null : $body['preferred_end']);
    }

    if (array_key_exists('note', $body)) {
        $updates[] = 'note = ?';
        $params[]  = $body['note'];
    }

    if (empty($updates)) {
        json_error('NO_FIELDS', '更新するフィールドが指定されていません', 400);
    }

    $updates[] = 'submitted_at = NOW()';

    $params[] = $id;
    $params[] = $tenantId;
    $params[] = $storeId;

    $sql = 'UPDATE shift_availabilities SET ' . implode(', ', $updates) .
           ' WHERE id = ? AND tenant_id = ? AND store_id = ?';
    $stmtU = $pdo->prepare($sql);
    $stmtU->execute($params);

    write_audit_log(
        $pdo, $user, $storeId,
        'shift_availability_update', 'shift_availability', $id,
        $old, $body
    );

    json_response(['updated' => true]);
}
