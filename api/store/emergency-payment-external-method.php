<?php
/**
 * 緊急会計 外部分類編集 API (PWA Phase 4d-3 / 2026-04-21)
 *
 * POST /api/store/emergency-payment-external-method.php
 *
 * body: {
 *   store_id: "<uuid>",
 *   emergency_payment_id: "<uuid>",
 *   external_method_type: "voucher" | "bank_transfer" | "accounts_receivable" | "point" | "other"
 * }
 *
 * 目的:
 *   Phase 4d-2 以前に保存された「分類未設定の other_external」レコードや、
 *   クライアントが分類を後から訂正したい場合に、管理画面から external_method_type を
 *   更新できるようにする。分類済みになれば Phase 4d-3 の transfer 経路で転記可能になる。
 *
 * 認証:
 *   require_auth() + require_store_access($storeId) + role IN ('manager', 'owner')
 *   staff / device は 403 FORBIDDEN
 *
 * バリデーション:
 *   - payment_method = 'other_external' 以外の行は 409 (silent に更新しない)
 *   - synced_payment_id が既に入っている行は 409 (転記済みの分類は変えない)
 *   - external_method_type が allowlist 外は 400
 *
 * 影響範囲:
 *   - emergency_payments の external_method_type と updated_at のみ UPDATE
 *   - payments / orders / cash_log / resolution_* / transferred_* は一切変更しない
 *   - 監査ログは write_audit_log があれば記録 (既存同様の optional)
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
@require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);
$user = require_auth();

if (!in_array($user['role'], ['manager', 'owner'], true)) {
    json_error('FORBIDDEN', '外部分類の編集は manager / owner のみ可能です', 403);
}

$body = get_json_body();
$storeId            = isset($body['store_id']) ? (string)$body['store_id'] : '';
$emergencyPaymentId = isset($body['emergency_payment_id']) ? (string)$body['emergency_payment_id'] : '';
$extType            = isset($body['external_method_type']) ? (string)$body['external_method_type'] : '';

if (!$storeId)            json_error('VALIDATION', 'store_id が必要です', 400);
if (!$emergencyPaymentId) json_error('VALIDATION', 'emergency_payment_id が必要です', 400);

$allowed = ['voucher', 'bank_transfer', 'accounts_receivable', 'point', 'other'];
if (!in_array($extType, $allowed, true)) {
    json_error('VALIDATION', '外部分類が不正です (voucher / bank_transfer / accounts_receivable / point / other)', 400);
}

require_store_access($storeId);
$tenantId = $user['tenant_id'];

$pdo = get_db();

// カラム有無チェック
$hasExtCol = true;
try { $pdo->query('SELECT external_method_type FROM emergency_payments LIMIT 0'); }
catch (PDOException $e) { $hasExtCol = false; }
if (!$hasExtCol) {
    json_error('CONFLICT', 'external_method_type カラムが未適用です (migration-pwa4d2a 未実行)', 409);
}

// 対象行を FOR UPDATE でロック
$inTx = false;
try {
    $pdo->beginTransaction();
    $inTx = true;

    $stmt = $pdo->prepare(
        'SELECT id, payment_method, synced_payment_id, external_method_type
           FROM emergency_payments
          WHERE id = ? AND tenant_id = ? AND store_id = ?
          LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$emergencyPaymentId, $tenantId, $storeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack(); $inTx = false;
        json_error('NOT_FOUND', '指定された緊急会計が見つかりません', 404);
    }

    if ((string)$row['payment_method'] !== 'other_external') {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', 'payment_method=other_external の記録にのみ設定できます (現在: ' . mb_substr((string)$row['payment_method'], 0, 30) . ')', 409);
    }

    if (!empty($row['synced_payment_id'])) {
        $pdo->rollBack(); $inTx = false;
        json_error('CONFLICT', '既に売上転記済みの記録は外部分類を変更できません (payment_id=' . mb_substr((string)$row['synced_payment_id'], 0, 40) . ')', 409);
    }

    // 同値 idempotent: 既存値と同じなら UPDATE しない
    $prevExt = array_key_exists('external_method_type', $row) ? $row['external_method_type'] : null;
    if ((string)$prevExt === $extType) {
        $pdo->commit(); $inTx = false;
        json_response([
            'emergencyPaymentId' => $emergencyPaymentId,
            'externalMethodType' => $extType,
            'idempotent'         => true,
        ]);
    }

    $updStmt = $pdo->prepare(
        'UPDATE emergency_payments
            SET external_method_type = ?, updated_at = NOW()
          WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $updStmt->execute([$extType, $emergencyPaymentId, $tenantId, $storeId]);

    $pdo->commit();
    $inTx = false;

    // 監査ログ (存在すれば)
    if (function_exists('write_audit_log')) {
        @write_audit_log(
            $pdo, $user, $storeId,
            'emergency_external_method_set', 'emergency_payment', $emergencyPaymentId,
            ['external_method_type' => $prevExt],
            ['external_method_type' => $extType]
        );
    }

    json_response([
        'emergencyPaymentId' => $emergencyPaymentId,
        'externalMethodType' => $extType,
        'idempotent'         => false,
    ]);
} catch (PDOException $e) {
    if ($inTx) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
    }
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/emergency-payment-external-method.php] db_error: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    json_error('DB_ERROR', '外部分類の更新に失敗しました', 500);
}
