<?php
/**
 * シフト設定 API（L-3）
 *
 * GET  ?store_id=xxx  → シフト設定取得
 * PATCH               → シフト設定更新
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'PATCH']);
$user   = require_auth();
require_role('manager');

$pdo      = get_db();
$tenantId = $user['tenant_id'];

// プランチェック
if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'シフト管理はProプラン以上で利用できます', 403);
}

$storeId = require_store_param();
require_store_access($storeId);

// ─── デフォルト値 ───
$defaults = [
    'submission_deadline_day'     => 5,
    'default_break_minutes'      => 60,
    'overtime_threshold_minutes'  => 480,
    'early_clock_in_minutes'     => 15,
    'auto_clock_out_hours'       => 12,
    'store_lat'                  => null,
    'store_lng'                  => null,
    'gps_radius_meters'          => 200,
    'gps_required'               => 0,
    'staff_visible_tools'        => null,
    'default_hourly_rate'        => null,
];

// =============================================
// GET: 設定取得
// =============================================
if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT submission_deadline_day, default_break_minutes,
                overtime_threshold_minutes, early_clock_in_minutes,
                auto_clock_out_hours,
                store_lat, store_lng, gps_radius_meters, gps_required,
                staff_visible_tools, default_hourly_rate
         FROM shift_settings
         WHERE store_id = ? AND tenant_id = ?'
    );
    $stmt->execute([$storeId, $tenantId]);
    $row = $stmt->fetch();

    if (!$row) {
        // 未設定の場合はデフォルト値を返す
        json_response(array_merge($defaults, [
            'store_id'  => $storeId,
            'is_default' => true,
        ]));
    }

    $row['store_id']   = $storeId;
    $row['is_default'] = false;
    // 数値型に変換
    $row['submission_deadline_day']    = (int)$row['submission_deadline_day'];
    $row['default_break_minutes']     = (int)$row['default_break_minutes'];
    $row['overtime_threshold_minutes'] = (int)$row['overtime_threshold_minutes'];
    $row['early_clock_in_minutes']    = (int)$row['early_clock_in_minutes'];
    $row['auto_clock_out_hours']      = (int)$row['auto_clock_out_hours'];
    $row['store_lat']                 = $row['store_lat'] !== null ? (float)$row['store_lat'] : null;
    $row['store_lng']                 = $row['store_lng'] !== null ? (float)$row['store_lng'] : null;
    $row['gps_radius_meters']         = (int)$row['gps_radius_meters'];
    $row['gps_required']              = (int)$row['gps_required'];
    // staff_visible_tools はそのまま（string|null）
    $row['default_hourly_rate']       = $row['default_hourly_rate'] !== null ? (int)$row['default_hourly_rate'] : null;

    json_response($row);
}

// =============================================
// PATCH: 設定更新
// =============================================
if ($method === 'PATCH') {
    $body = get_json_body();

    // バリデーション
    $fields = [];

    if (isset($body['submission_deadline_day'])) {
        $v = (int)$body['submission_deadline_day'];
        if ($v < 1 || $v > 28) {
            json_error('INVALID_VALUE', 'submission_deadline_day は 1〜28 の範囲で指定してください', 400);
        }
        $fields['submission_deadline_day'] = $v;
    }

    if (isset($body['default_break_minutes'])) {
        $v = (int)$body['default_break_minutes'];
        if ($v < 0 || $v > 120) {
            json_error('INVALID_VALUE', 'default_break_minutes は 0〜120 の範囲で指定してください', 400);
        }
        $fields['default_break_minutes'] = $v;
    }

    if (isset($body['overtime_threshold_minutes'])) {
        $v = (int)$body['overtime_threshold_minutes'];
        if ($v < 60 || $v > 720) {
            json_error('INVALID_VALUE', 'overtime_threshold_minutes は 60〜720 の範囲で指定してください', 400);
        }
        $fields['overtime_threshold_minutes'] = $v;
    }

    if (isset($body['early_clock_in_minutes'])) {
        $v = (int)$body['early_clock_in_minutes'];
        if ($v < 0 || $v > 60) {
            json_error('INVALID_VALUE', 'early_clock_in_minutes は 0〜60 の範囲で指定してください', 400);
        }
        $fields['early_clock_in_minutes'] = $v;
    }

    if (isset($body['auto_clock_out_hours'])) {
        $v = (int)$body['auto_clock_out_hours'];
        if ($v < 1 || $v > 24) {
            json_error('INVALID_VALUE', 'auto_clock_out_hours は 1〜24 の範囲で指定してください', 400);
        }
        $fields['auto_clock_out_hours'] = $v;
    }

    // L-3b: GPS出退勤制御
    if (array_key_exists('store_lat', $body)) {
        if ($body['store_lat'] !== null) {
            $v = (float)$body['store_lat'];
            if ($v < -90 || $v > 90) {
                json_error('INVALID_VALUE', 'store_lat は -90〜90 の範囲で指定してください', 400);
            }
            $fields['store_lat'] = $v;
        } else {
            $fields['store_lat'] = null;
        }
    }

    if (array_key_exists('store_lng', $body)) {
        if ($body['store_lng'] !== null) {
            $v = (float)$body['store_lng'];
            if ($v < -180 || $v > 180) {
                json_error('INVALID_VALUE', 'store_lng は -180〜180 の範囲で指定してください', 400);
            }
            $fields['store_lng'] = $v;
        } else {
            $fields['store_lng'] = null;
        }
    }

    if (isset($body['gps_radius_meters'])) {
        $v = (int)$body['gps_radius_meters'];
        if ($v < 50 || $v > 1000) {
            json_error('INVALID_VALUE', 'gps_radius_meters は 50〜1000 の範囲で指定してください', 400);
        }
        $fields['gps_radius_meters'] = $v;
    }

    if (isset($body['gps_required'])) {
        $v = (int)$body['gps_required'];
        if ($v !== 0 && $v !== 1) {
            json_error('INVALID_VALUE', 'gps_required は 0 または 1 で指定してください', 400);
        }
        $fields['gps_required'] = $v;
    }

    // L-3b: スタッフ表示ツール
    if (array_key_exists('staff_visible_tools', $body)) {
        if ($body['staff_visible_tools'] !== null && $body['staff_visible_tools'] !== '') {
            $allowed = ['handy', 'kds', 'register'];
            $parts = explode(',', $body['staff_visible_tools']);
            foreach ($parts as $p) {
                if (!in_array(trim($p), $allowed, true)) {
                    json_error('INVALID_VALUE', 'staff_visible_tools は handy,kds,register のみ指定可能です', 400);
                }
            }
            $fields['staff_visible_tools'] = $body['staff_visible_tools'];
        } else {
            $fields['staff_visible_tools'] = null;
        }
    }

    // L-3 Phase 2: デフォルト時給
    if (array_key_exists('default_hourly_rate', $body)) {
        if ($body['default_hourly_rate'] !== null && $body['default_hourly_rate'] !== '') {
            $v = (int)$body['default_hourly_rate'];
            if ($v < 1 || $v > 10000) {
                json_error('INVALID_VALUE', 'default_hourly_rate は 1〜10000 の範囲で指定してください', 400);
            }
            $fields['default_hourly_rate'] = $v;
        } else {
            $fields['default_hourly_rate'] = null;
        }
    }

    if (empty($fields)) {
        json_error('NO_FIELDS', '更新するフィールドが指定されていません', 400);
    }

    // 旧値を取得（audit_log 用）
    $stmtOld = $pdo->prepare(
        'SELECT submission_deadline_day, default_break_minutes,
                overtime_threshold_minutes, early_clock_in_minutes,
                auto_clock_out_hours,
                store_lat, store_lng, gps_radius_meters, gps_required,
                staff_visible_tools, default_hourly_rate
         FROM shift_settings
         WHERE store_id = ? AND tenant_id = ?'
    );
    $stmtOld->execute([$storeId, $tenantId]);
    $oldRow = $stmtOld->fetch();

    // UPSERT
    $allFields = array_merge($defaults, $fields);

    $stmt = $pdo->prepare(
        'INSERT INTO shift_settings
            (store_id, tenant_id,
             submission_deadline_day, default_break_minutes,
             overtime_threshold_minutes, early_clock_in_minutes,
             auto_clock_out_hours,
             store_lat, store_lng, gps_radius_meters, gps_required,
             staff_visible_tools, default_hourly_rate)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             submission_deadline_day    = VALUES(submission_deadline_day),
             default_break_minutes     = VALUES(default_break_minutes),
             overtime_threshold_minutes = VALUES(overtime_threshold_minutes),
             early_clock_in_minutes    = VALUES(early_clock_in_minutes),
             auto_clock_out_hours      = VALUES(auto_clock_out_hours),
             store_lat                 = VALUES(store_lat),
             store_lng                 = VALUES(store_lng),
             gps_radius_meters         = VALUES(gps_radius_meters),
             gps_required              = VALUES(gps_required),
             staff_visible_tools       = VALUES(staff_visible_tools),
             default_hourly_rate       = VALUES(default_hourly_rate)'
    );

    // 既存レコードがある場合は既存値とマージ
    if ($oldRow) {
        $merged = array_merge([
            'submission_deadline_day'    => (int)$oldRow['submission_deadline_day'],
            'default_break_minutes'     => (int)$oldRow['default_break_minutes'],
            'overtime_threshold_minutes' => (int)$oldRow['overtime_threshold_minutes'],
            'early_clock_in_minutes'    => (int)$oldRow['early_clock_in_minutes'],
            'auto_clock_out_hours'      => (int)$oldRow['auto_clock_out_hours'],
            'store_lat'                 => $oldRow['store_lat'] !== null ? (float)$oldRow['store_lat'] : null,
            'store_lng'                 => $oldRow['store_lng'] !== null ? (float)$oldRow['store_lng'] : null,
            'gps_radius_meters'         => (int)$oldRow['gps_radius_meters'],
            'gps_required'              => (int)$oldRow['gps_required'],
            'staff_visible_tools'       => $oldRow['staff_visible_tools'],
            'default_hourly_rate'       => $oldRow['default_hourly_rate'] !== null ? (int)$oldRow['default_hourly_rate'] : null,
        ], $fields);
    } else {
        $merged = $allFields;
    }

    $stmt->execute([
        $storeId,
        $tenantId,
        $merged['submission_deadline_day'],
        $merged['default_break_minutes'],
        $merged['overtime_threshold_minutes'],
        $merged['early_clock_in_minutes'],
        $merged['auto_clock_out_hours'],
        $merged['store_lat'],
        $merged['store_lng'],
        $merged['gps_radius_meters'],
        $merged['gps_required'],
        $merged['staff_visible_tools'],
        $merged['default_hourly_rate'],
    ]);

    // 監査ログ
    write_audit_log(
        $pdo,
        $user,
        $storeId,
        'settings_update',
        'shift_settings',
        $storeId,
        $oldRow ?: null,
        $merged
    );

    $merged['store_id']   = $storeId;
    $merged['is_default'] = false;

    json_response($merged);
}
