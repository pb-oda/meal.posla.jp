<?php
/**
 * 仮締めログ API
 *
 * GET  /api/kds/register-pre-close.php?store_id=xxx — 当日の仮締めログ
 * POST /api/kds/register-pre-close.php              — 仮締めログ追加
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['GET', 'POST']);
$user = require_auth();
$pdo = get_db();

function pre_close_json_encode($value, $maxLen = 8000) {
    if ($value === null || $value === '') return null;
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($json === false) return null;
    if (strlen($json) > $maxLen) {
        if (function_exists('mb_substr')) return mb_substr($json, 0, $maxLen);
        return substr($json, 0, $maxLen);
    }
    return $json;
}

function pre_close_json_decode_col($value) {
    if ($value === null || $value === '') return null;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function pre_close_trim($value, $maxLen) {
    $value = trim((string)$value);
    if (function_exists('mb_substr')) return mb_substr($value, 0, $maxLen);
    return substr($value, 0, $maxLen);
}

function pre_close_require_cashier_pin(PDO $pdo, array $user, string $storeId, string $staffPin): array {
    if ($staffPin === '') {
        json_error('PIN_REQUIRED', '仮締め保存には担当スタッフ PIN の入力が必要です', 400);
    }
    if (!preg_match('/^\d{4,8}$/', $staffPin)) {
        json_error('INVALID_PIN', '担当 PIN は 4〜8 桁の数字で入力してください', 400);
    }

    try {
        $today = date('Y-m-d');
        $pinStmt = $pdo->prepare(
            "SELECT u.id, u.display_name, u.cashier_pin_hash
               FROM users u
               INNER JOIN attendance_logs ar
                 ON ar.user_id = u.id AND ar.store_id = ?
                    AND DATE(ar.clock_in) = ? AND ar.clock_out IS NULL
              WHERE u.tenant_id = ? AND u.is_active = 1
                    AND u.role IN ('staff', 'manager', 'owner')
                    AND u.cashier_pin_hash IS NOT NULL"
        );
        $pinStmt->execute([$storeId, $today, $user['tenant_id']]);
        $candidates = $pinStmt->fetchAll();
    } catch (PDOException $e) {
        json_error('MIGRATION', 'PIN 機能のマイグレーションが未適用です (migration-cp1-cashier-pin.sql / migration-l3-shift-management.sql)', 500);
    }

    foreach ($candidates as $cand) {
        if ($cand['cashier_pin_hash'] && password_verify($staffPin, $cand['cashier_pin_hash'])) {
            return [
                'id' => $cand['id'],
                'displayName' => $cand['display_name'],
            ];
        }
    }

    @write_audit_log($pdo, $user, $storeId, 'cashier_pin_failed', 'register_pre_close_log', null, null,
        ['pin_length' => strlen($staffPin), 'context' => 'register_pre_close'], null);
    json_error('PIN_INVALID', 'PIN が一致しないか、対象スタッフが出勤中ではありません', 401);
}

function pre_close_format_row(array $row): array {
    return [
        'id' => $row['id'],
        'businessDay' => $row['business_day'],
        'createdAt' => $row['created_at'],
        'userName' => $row['user_name'] ?? null,
        'actualCashAmount' => $row['actual_cash_amount'] !== null ? (int)$row['actual_cash_amount'] : null,
        'expectedCashAmount' => $row['expected_cash_amount'] !== null ? (int)$row['expected_cash_amount'] : null,
        'differenceAmount' => $row['difference_amount'] !== null ? (int)$row['difference_amount'] : null,
        'cashSalesAmount' => $row['cash_sales_amount'] !== null ? (int)$row['cash_sales_amount'] : null,
        'cardSalesAmount' => $row['card_sales_amount'] !== null ? (int)$row['card_sales_amount'] : null,
        'qrSalesAmount' => $row['qr_sales_amount'] !== null ? (int)$row['qr_sales_amount'] : null,
        'reconciliationNote' => $row['reconciliation_note'] ?? null,
        'handoverNote' => $row['handover_note'] ?? null,
        'cashDenomination' => pre_close_json_decode_col($row['cash_denomination_json'] ?? null),
        'externalReconciliation' => pre_close_json_decode_col($row['external_reconciliation_json'] ?? null),
        'closeCheck' => pre_close_json_decode_col($row['close_check_json'] ?? null),
        'closeAssist' => pre_close_json_decode_col($row['close_assist_json'] ?? null),
        'status' => $row['status'] ?? 'open',
        'resolvedAt' => $row['resolved_at'] ?? null,
        'resolvedByName' => $row['resolved_by_name'] ?? null,
        'resolutionNote' => $row['resolution_note'] ?? null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);
    $businessDay = get_business_day($pdo, $storeId);

    try {
        $stmt = $pdo->prepare(
            'SELECT l.*, u.display_name AS user_name, ru.display_name AS resolved_by_name
               FROM register_pre_close_logs l
               LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
               LEFT JOIN users ru ON ru.id = l.resolved_by AND ru.tenant_id = l.tenant_id
              WHERE l.tenant_id = ?
                AND l.store_id = ?
                AND l.business_day = ?
              ORDER BY l.created_at DESC'
        );
        $stmt->execute([$user['tenant_id'], $storeId, $businessDay['date']]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        json_error('MIGRATION', '仮締めログのマイグレーションが未適用です (migration-p1-50-register-pre-close-logs.sql)', 500);
    }

    $logs = [];
    foreach ($rows as $row) $logs[] = pre_close_format_row($row);

    $carryovers = [];
    try {
        $carryStmt = $pdo->prepare(
            'SELECT l.*, u.display_name AS user_name, ru.display_name AS resolved_by_name
               FROM register_pre_close_logs l
               LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
               LEFT JOIN users ru ON ru.id = l.resolved_by AND ru.tenant_id = l.tenant_id
              WHERE l.tenant_id = ?
                AND l.store_id = ?
                AND l.business_day < ?
                AND l.status = "open"
                AND l.difference_amount IS NOT NULL
                AND l.difference_amount <> 0
              ORDER BY l.business_day DESC, l.created_at DESC
              LIMIT 5'
        );
        $carryStmt->execute([$user['tenant_id'], $storeId, $businessDay['date']]);
        foreach ($carryStmt->fetchAll() as $row) $carryovers[] = pre_close_format_row($row);
    } catch (PDOException $e) {
        $carryovers = [];
    }

    json_response([
        'businessDay' => $businessDay['date'],
        'logs' => $logs,
        'carryovers' => $carryovers,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_id は必須です', 400);
    require_store_access($storeId);

    $businessDay = get_business_day($pdo, $storeId);
    $cashier = pre_close_require_cashier_pin($pdo, $user, $storeId, trim((string)($data['staff_pin'] ?? '')));

    $id = generate_uuid();
    $actualCash = isset($data['actual_cash_amount']) ? (int)$data['actual_cash_amount'] : null;
    $expectedCash = isset($data['expected_cash_amount']) ? (int)$data['expected_cash_amount'] : null;
    $difference = isset($data['difference_amount']) ? (int)$data['difference_amount'] : null;
    $cashSales = isset($data['cash_sales_amount']) ? (int)$data['cash_sales_amount'] : null;
    $cardSales = isset($data['card_sales_amount']) ? (int)$data['card_sales_amount'] : null;
    $qrSales = isset($data['qr_sales_amount']) ? (int)$data['qr_sales_amount'] : null;
    $reconciliationNote = pre_close_trim($data['reconciliation_note'] ?? '', 255);
    $handoverNote = pre_close_trim($data['handover_note'] ?? '', 255);

    $status = ($difference !== null && $difference === 0) ? 'resolved' : 'open';

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO register_pre_close_logs
                (id, tenant_id, store_id, user_id, business_day,
                 actual_cash_amount, expected_cash_amount, difference_amount,
                 cash_sales_amount, card_sales_amount, qr_sales_amount,
                 reconciliation_note, handover_note,
                 cash_denomination_json, external_reconciliation_json, close_check_json, close_assist_json,
                 status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $id,
            $user['tenant_id'],
            $storeId,
            $cashier['id'],
            $businessDay['date'],
            $actualCash,
            $expectedCash,
            $difference,
            $cashSales,
            $cardSales,
            $qrSales,
            $reconciliationNote !== '' ? $reconciliationNote : null,
            $handoverNote !== '' ? $handoverNote : null,
            pre_close_json_encode($data['cash_denomination'] ?? null),
            pre_close_json_encode($data['external_reconciliation'] ?? null),
            pre_close_json_encode($data['close_check'] ?? null),
            pre_close_json_encode($data['close_assist'] ?? null),
            $status,
        ]);
    } catch (PDOException $e) {
        json_error('MIGRATION', '仮締めログのマイグレーションが未適用です (migration-p1-50-register-pre-close-logs.sql)', 500);
    }

    @write_audit_log($pdo, $user, $storeId, 'register_pre_close', 'register_pre_close_log', $id, null, [
        'actual_cash_amount' => $actualCash,
        'expected_cash_amount' => $expectedCash,
        'difference_amount' => $difference,
        'cashier_user_id' => $cashier['id'],
        'cashier_user_name' => $cashier['displayName'],
        'reconciliation_note' => $reconciliationNote,
        'handover_note' => $handoverNote,
    ], $reconciliationNote !== '' ? $reconciliationNote : null);

    json_response([
        'id' => $id,
        'cashierUserId' => $cashier['id'],
        'cashierUserName' => $cashier['displayName'],
        'businessDay' => $businessDay['date'],
    ], 201);
}
