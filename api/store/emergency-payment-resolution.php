<?php
/**
 * 緊急会計 管理者解決 API (PWA Phase 4c-1 / 2026-04-20)
 *
 * POST /api/store/emergency-payment-resolution.php
 *
 * body: {
 *   store_id: "<uuid>",
 *   emergency_payment_id: "<uuid>",
 *   action: "confirm" | "duplicate" | "reject" | "pending",
 *   note: "..."  (duplicate / reject は必須、confirm / pending は任意)
 * }
 *
 * action → resolution_status マップ:
 *   confirm   → 'confirmed'
 *   duplicate → 'duplicate'
 *   reject    → 'rejected'
 *   pending   → 'pending'
 *
 * 認証:
 *   require_auth() + require_store_access($storeId) + role IN ('manager', 'owner')
 *   staff / device / 顧客側は 403
 *
 * State machine (idempotent + 409 CONFLICT):
 *   現在 resolution_status == 新 resolution_status  → idempotent: true (UPDATE なし。既存値返却)
 *   現在 ∈ {confirmed, duplicate, rejected} && 新 ≠ 現在  → 409 CONFLICT
 *   現在 ∈ {unresolved, pending, NULL}          && 新 ≠ 現在  → UPDATE 成功
 *
 * 絶対にやらない (Phase 4c-1 スコープ外):
 *   - payments への INSERT
 *   - orders.status の変更
 *   - order_items.payment_id の変更
 *   - emergency_payments.status の上書き (Phase 4a 同期状態は維持)
 *   - synced_payment_id の記録 (Phase 4c-2 以降)
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['POST']);
$user = require_auth();

if (!in_array($user['role'], ['manager', 'owner'], true)) {
    json_error('FORBIDDEN', '緊急会計の解決記録は manager / owner のみ可能です', 403);
}

$body = get_json_body();
$storeId = isset($body['store_id']) ? (string)$body['store_id'] : '';
$emergencyPaymentId = isset($body['emergency_payment_id']) ? (string)$body['emergency_payment_id'] : '';
$action = isset($body['action']) ? (string)$body['action'] : '';
$noteIn = isset($body['note']) ? (string)$body['note'] : '';

if (!$storeId) json_error('VALIDATION', 'store_id が必要です', 400);
if (!$emergencyPaymentId) json_error('VALIDATION', 'emergency_payment_id が必要です', 400);

$actionMap = [
    'confirm'   => 'confirmed',
    'duplicate' => 'duplicate',
    'reject'    => 'rejected',
    'pending'   => 'pending',
];
if (!isset($actionMap[$action])) {
    json_error('VALIDATION', 'action は confirm / duplicate / reject / pending のいずれかです', 400);
}
$newResolution = $actionMap[$action];

// note 必須判定: duplicate / reject のみ必須
$note = trim($noteIn);
if ($note !== '') {
    // 255 文字超は切る
    $note = mb_substr($note, 0, 255);
}
if (($action === 'duplicate' || $action === 'reject') && $note === '') {
    json_error('VALIDATION', '「重複扱い」「無効扱い」には note の入力が必要です', 400);
}

require_store_access($storeId);
$tenantId = $user['tenant_id'];

$pdo = get_db();

// 解決者の display_name 取得 (取れなければ username)
$resolvedByUserId = isset($user['user_id']) ? (string)$user['user_id'] : null;
$resolvedByName = isset($user['username']) ? (string)$user['username'] : '';
try {
    if ($resolvedByUserId) {
        $uStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
        $uStmt->execute([$resolvedByUserId, $tenantId]);
        $uRow = $uStmt->fetch();
        if ($uRow && !empty($uRow['display_name'])) {
            $resolvedByName = (string)$uRow['display_name'];
        }
    }
} catch (PDOException $e) {
    // users テーブル読み取り失敗 → username フォールバック維持
}
$resolvedByName = mb_substr($resolvedByName, 0, 100);

// トランザクション + FOR UPDATE で state machine 判定
$inTx = false;
try {
    $pdo->beginTransaction();
    $inTx = true;

    $sel = $pdo->prepare(
        'SELECT id, resolution_status, resolution_note, resolved_by_user_id, resolved_by_name, resolved_at
           FROM emergency_payments
          WHERE id = ? AND tenant_id = ? AND store_id = ?
          LIMIT 1
          FOR UPDATE'
    );
    $sel->execute([$emergencyPaymentId, $tenantId, $storeId]);
    $row = $sel->fetch();

    if (!$row) {
        $pdo->rollBack();
        $inTx = false;
        json_error('NOT_FOUND', '指定された緊急会計が見つかりません', 404);
    }

    $currentResolution = $row['resolution_status'];
    if ($currentResolution === null || $currentResolution === '') {
        $currentResolution = 'unresolved';
    }

    // 同じ状態への再送 → idempotent (UPDATE なし、resolved_at は既存維持)
    if ($currentResolution === $newResolution) {
        $pdo->commit();
        $inTx = false;
        json_response([
            'emergencyPaymentId' => $row['id'],
            'resolutionStatus'   => $currentResolution,
            'resolutionNote'     => $row['resolution_note'],
            'resolvedByUserId'   => $row['resolved_by_user_id'],
            'resolvedByName'     => $row['resolved_by_name'],
            'resolvedAt'         => $row['resolved_at'],
            'idempotent'         => true,
        ]);
    }

    // 終結状態からの異なる遷移は禁止
    $terminal = ['confirmed', 'duplicate', 'rejected'];
    if (in_array($currentResolution, $terminal, true)) {
        $pdo->rollBack();
        $inTx = false;
        json_error(
            'CONFLICT',
            '既に解決済みです (現在: ' . $currentResolution . ')。変更する場合は Phase 4c-2 以降の取消フローを待ってください',
            409
        );
    }

    // unresolved / pending → 任意の action へ遷移: UPDATE 実行
    $noteForDb = $note === '' ? null : $note;
    $upd = $pdo->prepare(
        'UPDATE emergency_payments
            SET resolution_status     = ?,
                resolution_note       = ?,
                resolved_by_user_id   = ?,
                resolved_by_name      = ?,
                resolved_at           = NOW(),
                updated_at            = NOW()
          WHERE id = ? AND tenant_id = ? AND store_id = ?'
    );
    $upd->execute([
        $newResolution, $noteForDb, $resolvedByUserId, $resolvedByName,
        $emergencyPaymentId, $tenantId, $storeId
    ]);

    // 更新後の値を再取得 (NOW() の確定値を正確に返すため)
    $selAfter = $pdo->prepare(
        'SELECT id, resolution_status, resolution_note, resolved_by_user_id, resolved_by_name, resolved_at
           FROM emergency_payments
          WHERE id = ? AND tenant_id = ? AND store_id = ?
          LIMIT 1'
    );
    $selAfter->execute([$emergencyPaymentId, $tenantId, $storeId]);
    $after = $selAfter->fetch();

    $pdo->commit();
    $inTx = false;

    json_response([
        'emergencyPaymentId' => $after['id'],
        'resolutionStatus'   => $after['resolution_status'],
        'resolutionNote'     => $after['resolution_note'],
        'resolvedByUserId'   => $after['resolved_by_user_id'],
        'resolvedByName'     => $after['resolved_by_name'],
        'resolvedAt'         => $after['resolved_at'],
        'idempotent'         => false,
    ]);
} catch (PDOException $e) {
    if ($inTx) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        $inTx = false;
    }
    // カラム未存在 (migration 未適用) の検出
    $sqlState = $e->getCode();
    if ($sqlState === '42S22') {
        json_error(
            'DB_ERROR',
            '解決カラム未作成の可能性 (migration-pwa4c-emergency-payment-resolution.sql 未適用)',
            500
        );
    }
    // H-14: browser 応答から内部メッセージを排除、詳細は error_log にのみ残す
    error_log('[H-14][api/store/emergency-payment-resolution.php] db_error: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '解決記録の保存に失敗しました', 500);
}
