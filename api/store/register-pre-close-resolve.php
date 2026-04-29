<?php
/**
 * 仮締め差額の手動解決 API
 *
 * POST /api/store/register-pre-close-resolve.php
 * Body: { store_id, id, resolution_note }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);
$user = require_role('manager');
$pdo = get_db();

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$id = $data['id'] ?? null;
$note = trim((string)($data['resolution_note'] ?? ''));

if (!$storeId || !$id) json_error('MISSING_FIELDS', 'store_id と id は必須です', 400);
require_store_access($storeId);

if ($note === '') {
    json_error('MISSING_NOTE', '解決メモを入力してください', 400);
}
if (function_exists('mb_substr')) {
    $note = mb_substr($note, 0, 255);
} else {
    $note = substr($note, 0, 255);
}

try {
    $pdo->query('SELECT resolved_by, resolved_at, resolution_note FROM register_pre_close_logs LIMIT 0');
} catch (PDOException $e) {
    json_error('MIGRATION', '仮締め解決履歴のマイグレーションが未適用です (migration-p1-51-register-pre-close-resolution.sql)', 500);
}

$stmt = $pdo->prepare(
    'SELECT id, difference_amount, status, reconciliation_note, handover_note
       FROM register_pre_close_logs
      WHERE id = ?
        AND tenant_id = ?
        AND store_id = ?
      LIMIT 1'
);
$stmt->execute([$id, $user['tenant_id'], $storeId]);
$row = $stmt->fetch();
if (!$row) json_error('NOT_FOUND', '仮締めログが見つかりません', 404);

$upd = $pdo->prepare(
    'UPDATE register_pre_close_logs
        SET status = "resolved",
            resolved_by = ?,
            resolved_at = NOW(),
            resolution_note = ?
      WHERE id = ?
        AND tenant_id = ?
        AND store_id = ?'
);
$upd->execute([$user['user_id'], $note, $id, $user['tenant_id'], $storeId]);

@write_audit_log($pdo, $user, $storeId, 'register_pre_close_resolved', 'register_pre_close_log', $id, null, [
    'difference_amount' => $row['difference_amount'] !== null ? (int)$row['difference_amount'] : null,
    'previous_status' => $row['status'] ?? null,
    'resolution_note' => $note,
], $note);

json_response([
    'id' => $id,
    'status' => 'resolved',
    'resolutionNote' => $note,
]);
