<?php
/**
 * ハンディ席移動 API
 *
 * POST /api/store/table-session-move.php
 * Body: { store_id, session_id, to_table_id }
 *
 * セッション、未会計注文、QRトークン、メモ、呼び出し、個別QRをまとめて移動する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

require_method(['POST']);
$user = require_auth();
$data = get_json_body();
$storeId = isset($data['store_id']) ? trim($data['store_id']) : '';
$sessionId = isset($data['session_id']) ? trim($data['session_id']) : '';
$toTableId = isset($data['to_table_id']) ? trim($data['to_table_id']) : '';

if (!$storeId || !$sessionId || !$toTableId) {
    json_error('VALIDATION', 'store_id, session_id, to_table_id は必須です', 400);
}
require_store_access($storeId);

$pdo = get_db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT ts.*, t.table_code AS from_table_code,
                t.session_token, t.session_token_expires_at,
                t.next_session_token, t.next_session_token_expires_at,
                t.next_session_opened_by_user_id, t.next_session_opened_at
           FROM table_sessions ts
           JOIN tables t ON t.id = ts.table_id AND t.store_id = ts.store_id
          WHERE ts.id = ? AND ts.store_id = ?
          FOR UPDATE'
    );
    $stmt->execute([$sessionId, $storeId]);
    $session = $stmt->fetch();
    if (!$session) {
        $pdo->rollBack();
        json_error('NOT_FOUND', '移動元セッションが見つかりません', 404);
    }
    if (in_array($session['status'], ['paid', 'closed', 'cleaning'], true)) {
        $pdo->rollBack();
        json_error('SESSION_CLOSED', '終了済みまたは清掃待ちの卓は移動できません', 409);
    }

    $fromTableId = $session['table_id'];
    if ($fromTableId === $toTableId) {
        $pdo->rollBack();
        json_error('SAME_TABLE', '同じ卓には移動できません', 400);
    }

    $toStmt = $pdo->prepare(
        'SELECT id, table_code, next_session_token, next_session_token_expires_at
           FROM tables
          WHERE id = ? AND store_id = ? AND is_active = 1
          FOR UPDATE'
    );
    $toStmt->execute([$toTableId, $storeId]);
    $toTable = $toStmt->fetch();
    if (!$toTable) {
        $pdo->rollBack();
        json_error('TABLE_NOT_FOUND', '移動先の卓が見つかりません', 404);
    }

    $activeStmt = $pdo->prepare(
        'SELECT id FROM table_sessions
          WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "closed")
          LIMIT 1 FOR UPDATE'
    );
    $activeStmt->execute([$storeId, $toTableId]);
    if ($activeStmt->fetch()) {
        $pdo->rollBack();
        json_error('TABLE_IN_USE', '移動先の卓は使用中です', 409);
    }

    $targetQrOpen = !empty($toTable['next_session_token'])
        && !empty($toTable['next_session_token_expires_at'])
        && strtotime($toTable['next_session_token_expires_at']) > time();
    if ($targetQrOpen) {
        $pdo->rollBack();
        json_error('TABLE_QR_OPEN', '移動先の卓はQR開放中です', 409);
    }

    $statusChangedSql = table_move_has_column($pdo, 'table_sessions', 'status_changed_at')
        ? ', status_changed_at = NOW()'
        : '';
    $pdo->prepare(
        'UPDATE table_sessions
            SET table_id = ?' . $statusChangedSql . '
          WHERE id = ? AND store_id = ?'
    )->execute([$toTableId, $sessionId, $storeId]);

    $pdo->prepare(
        'UPDATE orders
            SET table_id = ?, updated_at = NOW()
          WHERE store_id = ? AND table_id = ? AND status NOT IN ("paid", "cancelled")'
    )->execute([$toTableId, $storeId, $fromTableId]);

    $sourceReplacementToken = bin2hex(random_bytes(16));
    try {
        $pdo->prepare(
            'UPDATE tables
                SET session_token = ?,
                    session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR),
                    next_session_token = NULL,
                    next_session_token_expires_at = NULL,
                    next_session_opened_by_user_id = NULL,
                    next_session_opened_at = NULL
              WHERE id = ? AND store_id = ?'
        )->execute([$sourceReplacementToken, $fromTableId, $storeId]);

        $pdo->prepare(
            'UPDATE tables
                SET session_token = ?,
                    session_token_expires_at = ?,
                    next_session_token = ?,
                    next_session_token_expires_at = ?,
                    next_session_opened_by_user_id = ?,
                    next_session_opened_at = ?
              WHERE id = ? AND store_id = ?'
        )->execute([
            $session['session_token'],
            $session['session_token_expires_at'],
            $session['next_session_token'],
            $session['next_session_token_expires_at'],
            $session['next_session_opened_by_user_id'],
            $session['next_session_opened_at'],
            $toTableId,
            $storeId,
        ]);
    } catch (PDOException $e) {
        $pdo->prepare(
            'UPDATE tables
                SET session_token = ?,
                    session_token_expires_at = DATE_ADD(NOW(), INTERVAL 4 HOUR)
              WHERE id = ? AND store_id = ?'
        )->execute([$sourceReplacementToken, $fromTableId, $storeId]);
        $pdo->prepare(
            'UPDATE tables
                SET session_token = ?, session_token_expires_at = ?
              WHERE id = ? AND store_id = ?'
        )->execute([$session['session_token'], $session['session_token_expires_at'], $toTableId, $storeId]);
    }

    try {
        $pdo->prepare(
            'UPDATE call_alerts
                SET table_id = ?, table_code = ?
              WHERE store_id = ? AND table_id = ? AND status IN ("pending", "in_progress")'
        )->execute([$toTableId, $toTable['table_code'], $storeId, $fromTableId]);
    } catch (PDOException $e) {
        error_log('[table-session-move] call_alerts_move_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }

    try {
        $pdo->prepare(
            'UPDATE table_sub_sessions
                SET table_id = ?
              WHERE table_session_id = ? AND store_id = ? AND closed_at IS NULL'
        )->execute([$toTableId, $sessionId, $storeId]);
    } catch (PDOException $e) {
        error_log('[table-session-move] sub_sessions_move_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }

    write_audit_log($pdo, $user, $storeId, 'table_session_move', 'table_session', $sessionId, [
        'table_id' => $fromTableId,
        'table_code' => $session['from_table_code'],
    ], [
        'table_id' => $toTableId,
        'table_code' => $toTable['table_code'],
        'qr_moved' => true,
        'orders_moved' => true,
        'alerts_moved' => true,
    ], null);

    $pdo->commit();
    json_response([
        'session_id' => $sessionId,
        'from_table_id' => $fromTableId,
        'from_table_code' => $session['from_table_code'],
        'to_table_id' => $toTableId,
        'to_table_code' => $toTable['table_code'],
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[api/store/table-session-move.php] failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('MOVE_FAILED', '席移動に失敗しました', 500);
}

function table_move_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}
