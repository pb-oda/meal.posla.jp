<?php
/**
 * L-5: 領収書/インボイス設定 API
 *
 * GET  /api/store/receipt-settings.php?store_id=xxx
 * PATCH /api/store/receipt-settings.php
 *   body: { store_id, registration_number, business_name, receipt_store_name, ... }
 *
 * 認証: owner / manager 必須（PATCHのみ）、GET は staff 以上
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

$method = require_method(['GET', 'PATCH']);

if ($method === 'PATCH') {
    $user = require_role('manager');
} else {
    $user = require_auth();
}

$pdo = get_db();

// ============================================================
// GET: 設定取得
// ============================================================
if ($method === 'GET') {
    $storeId = require_store_param();

    $stmt = $pdo->prepare(
        'SELECT registration_number, business_name,
                receipt_store_name, receipt_address, receipt_phone,
                receipt_footer, tax_rate
         FROM store_settings WHERE store_id = ?'
    );
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();

    if (!$row) {
        // auto-create
        $pdo->prepare('INSERT IGNORE INTO store_settings (store_id) VALUES (?)')->execute([$storeId]);
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();
    }

    json_response($row ?: []);
}

// ============================================================
// PATCH: 設定更新
// ============================================================
if ($method === 'PATCH') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) {
        json_error('MISSING_STORE', 'store_id は必須です', 400);
    }
    require_store_access($storeId);

    // registration_number バリデーション
    if (array_key_exists('registration_number', $data)) {
        $regNum = $data['registration_number'];
        if ($regNum !== null && $regNum !== '') {
            // T + 13桁数字
            if (!preg_match('/^T[0-9]{13}$/', $regNum)) {
                json_error('INVALID_REG_NUMBER', '登録番号は T + 13桁の数字で入力してください（例: T1234567890123）', 400);
            }
        } else {
            $data['registration_number'] = null;
        }
    }

    $allowed = [
        'registration_number', 'business_name',
        'receipt_store_name', 'receipt_address', 'receipt_phone',
        'receipt_footer'
    ];

    // 変更前の値を取得（監査ログ用）
    $oldStmt = $pdo->prepare(
        'SELECT registration_number, business_name,
                receipt_store_name, receipt_address, receipt_phone, receipt_footer
         FROM store_settings WHERE store_id = ?'
    );
    $oldStmt->execute([$storeId]);
    $oldSettings = $oldStmt->fetch();

    $fields = [];
    $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            $params[] = $data[$col];
        }
    }

    if (empty($fields)) {
        json_error('NO_FIELDS', '更新項目がありません', 400);
    }

    $params[] = $storeId;
    $sql = 'UPDATE store_settings SET ' . implode(', ', $fields) . ' WHERE store_id = ?';
    $pdo->prepare($sql)->execute($params);

    // 監査ログ
    $auditOld = [];
    $auditNew = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $data)) {
            $auditOld[$col] = $oldSettings[$col] ?? null;
            $auditNew[$col] = $data[$col];
        }
    }
    write_audit_log($pdo, $user, $storeId, 'receipt_settings_update', 'store_settings', $storeId, $auditOld, $auditNew, null);

    json_response(['message' => '設定を更新しました']);
}
