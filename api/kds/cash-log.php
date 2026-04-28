<?php
/**
 * レジ開け/締め API
 *
 * GET  /api/kds/cash-log.php?store_id=xxx    — 当日のログ
 * POST /api/kds/cash-log.php                 — エントリ追加
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/business-day.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['GET', 'POST']);
$user = require_auth();
$pdo = get_db();

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $businessDay = get_business_day($pdo, $storeId);

    $stmt = $pdo->prepare(
        'SELECT cl.*, u.display_name AS user_name
         FROM cash_log cl
         LEFT JOIN users u ON u.id = cl.user_id
         WHERE cl.store_id = ? AND cl.created_at >= ? AND cl.created_at < ?
         ORDER BY cl.created_at ASC'
    );
    $stmt->execute([$storeId, $businessDay['start'], $businessDay['end']]);

    json_response([
        'entries'     => $stmt->fetchAll(),
        'businessDay' => $businessDay['date'],
    ]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId  = $data['store_id'] ?? null;
    $type     = $data['type'] ?? null;
    $amount   = isset($data['amount']) ? (int)$data['amount'] : 0;
    $note     = trim($data['note'] ?? '');
    $staffPin = isset($data['staff_pin']) ? trim((string)$data['staff_pin']) : '';
    $expectedAmount = isset($data['expected_amount']) ? (int)$data['expected_amount'] : null;
    $differenceAmount = isset($data['difference_amount']) ? (int)$data['difference_amount'] : null;
    $cashSalesAmount = isset($data['cash_sales_amount']) ? (int)$data['cash_sales_amount'] : null;
    $cardSalesAmount = isset($data['card_sales_amount']) ? (int)$data['card_sales_amount'] : null;
    $qrSalesAmount = isset($data['qr_sales_amount']) ? (int)$data['qr_sales_amount'] : null;
    $reconciliationNote = trim((string)($data['reconciliation_note'] ?? ''));
    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 200);
        $reconciliationNote = mb_substr($reconciliationNote, 0, 255);
    } else {
        $note = substr($note, 0, 200);
        $reconciliationNote = substr($reconciliationNote, 0, 255);
    }

    if (!$storeId || !$type) json_error('MISSING_FIELDS', 'store_id と type は必須です', 400);
    require_store_access($storeId);

    // S2 P1 #7: cash_sale は process-payment.php から直接 INSERT される自動記録専用。
    // フロント API から偽装 INSERT できないように拒否する。
    $validTypes = ['open', 'close', 'cash_in', 'cash_out'];
    if (!in_array($type, $validTypes)) json_error('INVALID_TYPE', '無効な種別です', 400);

    // CB1d: 現金を扱う操作 (open/close/cash_in/cash_out) は担当スタッフ PIN 必須
    //   cash_sale は process-payment.php からの自動記録なので PIN 検証は不要
    $cashierUserId   = $user['user_id'];
    $cashierUserName = null;
    $pinVerified     = false;
    if ($type !== 'cash_sale') {
        if ($staffPin === '') {
            json_error('PIN_REQUIRED', 'レジ金庫の操作には担当スタッフ PIN の入力が必要です', 400);
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
                $cashierUserId   = $cand['id'];
                $cashierUserName = $cand['display_name'];
                $pinVerified     = true;
                break;
            }
        }
        if (!$pinVerified) {
            @write_audit_log($pdo, $user, $storeId, 'cashier_pin_failed', 'cash_log', null, null,
                ['pin_length' => strlen($staffPin), 'context' => 'cash_log:' . $type], null);
            json_error('PIN_INVALID', 'PIN が一致しないか、対象スタッフが出勤中ではありません', 401);
        }
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO cash_log (id, store_id, user_id, type, amount, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$id, $storeId, $cashierUserId, $type, $amount, $note]);

    $hasReconciliationCols = false;
    try {
        $pdo->query('SELECT expected_amount, difference_amount, cash_sales_amount, card_sales_amount, qr_sales_amount, reconciliation_note FROM cash_log LIMIT 0');
        $hasReconciliationCols = true;
    } catch (PDOException $e) {}

    if ($type === 'close' && $hasReconciliationCols) {
        $reconcileStmt = $pdo->prepare(
            'UPDATE cash_log
                SET expected_amount = ?,
                    difference_amount = ?,
                    cash_sales_amount = ?,
                    card_sales_amount = ?,
                    qr_sales_amount = ?,
                    reconciliation_note = ?
              WHERE id = ? AND store_id = ?'
        );
        $reconcileStmt->execute([
            $expectedAmount,
            $differenceAmount,
            $cashSalesAmount,
            $cardSalesAmount,
            $qrSalesAmount,
            $reconciliationNote !== '' ? $reconciliationNote : null,
            $id,
            $storeId,
        ]);
    }

    // 監査ログ（cash_sale は process-payment.php 側で payment_complete として記録されるので除外）
    if ($type !== 'cash_sale') {
        $actionMap = [
            'open'     => 'register_open',
            'close'    => 'register_close',
            'cash_in'  => 'cash_in',
            'cash_out' => 'cash_out',
        ];
        if (isset($actionMap[$type])) {
            $auditPayload = [
                'amount'            => $amount,
                'note'              => $note,
                'type'              => $type,
                'cashier_user_id'   => $cashierUserId,
                'cashier_user_name' => $cashierUserName,
                'pin_verified'      => 1,
            ];
            if ($type === 'close') {
                $auditPayload['expected_amount'] = $expectedAmount;
                $auditPayload['difference_amount'] = $differenceAmount;
                $auditPayload['cash_sales_amount'] = $cashSalesAmount;
                $auditPayload['card_sales_amount'] = $cardSalesAmount;
                $auditPayload['qr_sales_amount'] = $qrSalesAmount;
                $auditPayload['reconciliation_note'] = $reconciliationNote;
            }
            @write_audit_log($pdo, $user, $storeId, $actionMap[$type], 'cash_log', $id, null, $auditPayload, $note ?: null);
        }
    }

    json_response([
        'ok' => true,
        'id' => $id,
        'cashierUserId'   => $cashierUserId,
        'cashierUserName' => $cashierUserName,
        'expectedAmount' => $expectedAmount,
        'differenceAmount' => $differenceAmount,
    ], 201);
}
