<?php
/**
 * 呼び出しアラート API（認証必要：device 以上）
 *
 * GET  /api/kds/call-alerts.php?store_id=X  → pending一覧
 * PATCH /api/kds/call-alerts.php             → 対応済みにする
 * POST /api/kds/call-alerts.php              → キッチン呼び出しアラート作成
 *
 * P1a: device ロール（KDS端末）からも呼び出せるように staff 制限を撤廃
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/push.php';
require_once __DIR__ . '/../lib/audit-log.php';

$method = require_method(['GET', 'PATCH', 'POST']);
$user = require_auth();

$pdo = get_db();

// ── GET: pending一覧 ──
if ($method === 'GET') {
    $storeId = $_GET['store_id'] ?? null;
    if (!$storeId) {
        json_error('MISSING_STORE', 'store_id は必須です', 400);
    }
    require_store_access($storeId);

    // call_alertsテーブルが存在しない場合のグレースフルデグレード
    try {
        // O-5: type, item_name カラム存在チェック
        $hasTypeCol = false;
        try {
            $pdo->query('SELECT type FROM call_alerts LIMIT 0');
            $hasTypeCol = true;
        } catch (PDOException $e) {}

        $selectCols = 'id, table_code, reason, status, created_at';
        if ($hasTypeCol) {
            $selectCols .= ', type, order_item_id, item_name';
        }
        if (call_alerts_has_column($pdo, 'in_progress_by_name')) {
            $selectCols .= ', in_progress_by_name, acknowledged_by_name';
        }

        $stmt = $pdo->prepare(
            'SELECT ' . $selectCols . '
             FROM call_alerts
             WHERE store_id = ? AND status IN ("pending", "in_progress")
             ORDER BY created_at ASC'
        );
        $stmt->execute([$storeId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        // テーブル未作成時は空配列を返す
        $rows = [];
    }

    $now = time();
    $alerts = [];
    for ($i = 0; $i < count($rows); $i++) {
        $r = $rows[$i];
        $createdTs = strtotime($r['created_at']);
        $alert = [
            'id'              => $r['id'],
            'table_code'      => $r['table_code'],
            'reason'          => $r['reason'],
            'created_at'      => $r['created_at'],
            'elapsed_seconds' => $now - $createdTs,
            'type'            => $r['type'] ?? 'staff_call',
            'item_name'       => $r['item_name'] ?? null,
            'status'          => $r['status'] ?? 'pending',
            'in_progress_by_name' => $r['in_progress_by_name'] ?? null,
            'acknowledged_by_name' => $r['acknowledged_by_name'] ?? null,
        ];
        $alerts[] = $alert;
    }

    json_response(['alerts' => $alerts]);
}

// ── PATCH: 対応済み ──
if ($method === 'PATCH') {
    $data = get_json_body();
    $alertId = $data['alert_id'] ?? null;
    $status  = $data['status']   ?? 'acknowledged';

    if (!$alertId) {
        json_error('MISSING_ALERT_ID', 'alert_id は必須です', 400);
    }

    if (!in_array($status, ['in_progress', 'acknowledged'], true)) {
        json_error('INVALID_STATUS', 'status は in_progress / acknowledged のみ指定可能です', 400);
    }
    if ($status === 'in_progress' && !call_alerts_supports_status($pdo, 'in_progress')) {
        json_error('MIGRATION_REQUIRED', '対応中にはデータベース更新が必要です', 500);
    }

    // O-5: アラート情報を先に取得（product_ready → 品目を提供済に連動）
    $alertType = null;
    $orderItemId = null;
    $alertStoreId = null;
    $alertTableCode = null;
    $oldStatus = null;
    try {
        $fetchStmt = $pdo->prepare(
            'SELECT type, order_item_id, store_id, table_code, status FROM call_alerts WHERE id = ?'
        );
        $fetchStmt->execute([$alertId]);
        $alertRow = $fetchStmt->fetch();
        if ($alertRow) {
            $alertType = $alertRow['type'] ?? null;
            $orderItemId = $alertRow['order_item_id'] ?? null;
            $alertStoreId = $alertRow['store_id'] ?? null;
            $alertTableCode = $alertRow['table_code'] ?? null;
            $oldStatus = $alertRow['status'] ?? null;
        }
    } catch (PDOException $e) {
        // type カラムが無い場合は従来通り
        error_log('[P1-12][api/kds/call-alerts.php:104] fetch_alert_info: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    }
    if (!$alertStoreId) {
        json_error('NOT_FOUND', 'アラートが見つかりません', 404);
    }
    require_store_access($alertStoreId);

    try {
        $fields = ['status = ?'];
        $params = [$status];
        $actorName = $user['display_name'] ?? $user['username'] ?? $user['email'] ?? null;
        if ($status === 'acknowledged') {
            $fields[] = 'acknowledged_at = NOW()';
            if (call_alerts_has_column($pdo, 'acknowledged_by_user_id')) {
                $fields[] = 'acknowledged_by_user_id = ?';
                $params[] = $user['user_id'];
                $fields[] = 'acknowledged_by_name = ?';
                $params[] = $actorName;
            }
        } else {
            if (call_alerts_has_column($pdo, 'in_progress_at')) {
                $fields[] = 'in_progress_at = COALESCE(in_progress_at, NOW())';
                $fields[] = 'in_progress_by_user_id = COALESCE(in_progress_by_user_id, ?)';
                $params[] = $user['user_id'];
                $fields[] = 'in_progress_by_name = COALESCE(in_progress_by_name, ?)';
                $params[] = $actorName;
            }
        }
        $params[] = $alertId;
        $params[] = $alertStoreId;
        $stmt = $pdo->prepare(
            'UPDATE call_alerts SET ' . implode(', ', $fields) . ' WHERE id = ? AND store_id = ?'
        );
        $stmt->execute($params);
    } catch (PDOException $e) {
        json_error('UPDATE_FAILED', 'アラートの更新に失敗しました', 500);
    }

    // O-5: product_ready 対応済み → order_item を served に連動更新
    $itemServed = false;
    $orderCompleted = false;
    if ($status === 'acknowledged' && $alertType === 'product_ready' && $orderItemId && $alertStoreId) {
        try {
            $updateItem = $pdo->prepare(
                'UPDATE order_items SET status = "served", served_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND store_id = ? AND status != "served" AND status != "cancelled"'
            );
            $updateItem->execute([$orderItemId, $alertStoreId]);
            $itemServed = $updateItem->rowCount() > 0;

            // 全品目 served/cancelled なら親注文も served に
            if ($itemServed) {
                $orderStmt = $pdo->prepare('SELECT order_id FROM order_items WHERE id = ? AND store_id = ?');
                $orderStmt->execute([$orderItemId, $alertStoreId]);
                $orderRow = $orderStmt->fetch();
                if ($orderRow) {
                    $oid = $orderRow['order_id'];
                    $cntStmt = $pdo->prepare(
                        'SELECT COUNT(*) AS total,
                                SUM(CASE WHEN status IN ("served", "cancelled") THEN 1 ELSE 0 END) AS done
                         FROM order_items WHERE order_id = ? AND store_id = ?'
                    );
                    $cntStmt->execute([$oid, $alertStoreId]);
                    $cnt = $cntStmt->fetch();
                    if ((int)$cnt['total'] > 0 && (int)$cnt['done'] >= (int)$cnt['total']) {
                        $pdo->prepare(
                            'UPDATE orders SET status = "served", served_at = NOW(), updated_at = NOW()
                             WHERE id = ? AND store_id = ?'
                        )->execute([$oid, $alertStoreId]);
                        $orderCompleted = true;
                    }
                }
            }
        } catch (PDOException $e) {
            // order_items テーブル未作成時はスキップ
            error_log('[P1-12][api/kds/call-alerts.php:152] kds_item_served_sync: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        }
    }

    write_audit_log($pdo, $user, $alertStoreId, $status === 'in_progress' ? 'call_alert_start' : 'call_alert_ack', 'call_alert', $alertId, [
        'status' => $oldStatus,
    ], [
        'status' => $status,
        'type' => $alertType,
        'table_code' => $alertTableCode,
        'order_item_id' => $orderItemId,
        'item_served' => $itemServed,
    ], null);

    json_response(['alert_id' => $alertId, 'status' => $status, 'item_served' => $itemServed, 'order_completed' => $orderCompleted]);
}

// ── POST: キッチン→スタッフ呼び出しアラート作成 ──
if ($method === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;

    if (!$storeId) {
        json_error('MISSING_STORE', 'store_id は必須です', 400);
    }
    require_store_access($storeId);

    $reason = $data['reason'] ?? 'キッチンから呼び出し';

    // 連打防止: 既存の未対応キッチン呼び出しを削除
    $pdo->prepare(
        'DELETE FROM call_alerts WHERE store_id = ? AND type = "kitchen_call" AND status = "pending"'
    )->execute([$storeId]);

    // 新規アラート作成
    $alertId = generate_uuid();
    $pdo->prepare(
        'INSERT INTO call_alerts (id, store_id, table_id, table_code, reason, type, status, created_at)
         VALUES (?, ?, "KITCHEN", "キッチン", ?, "kitchen_call", "pending", NOW())'
    )->execute([$alertId, $storeId, $reason]);

    // PWA Phase 2b: ハンディ・KDS・レジへ Web Push 通知 (送信失敗は無視: fail-open)
    try {
        // tag に alert_id を含めることで、同じユーザーが短時間に別の呼び出しを受けても
        // レート制限 (60 秒) に抑制されないようにする (Phase 2b レビュー指摘 #3)
        push_send_to_store($pdo, $storeId, 'call_alert', [
            'title' => 'キッチンから呼び出し',
            'body'  => $reason,
            'url'   => '/handy/index.html',
            'tag'   => 'kitchen_call_' . $alertId,
        ]);
    } catch (\Throwable $e) {
        // 業務処理を止めない
    }

    json_response(['alert_id' => $alertId]);
}

function call_alerts_has_column(PDO $pdo, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) return $cache[$column];
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = "call_alerts"
                AND COLUMN_NAME = ?'
        );
        $stmt->execute([$column]);
        $cache[$column] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        $cache[$column] = false;
    }
    return $cache[$column];
}

function call_alerts_supports_status(PDO $pdo, string $status): bool
{
    static $allowed = null;
    if ($allowed === null) {
        $allowed = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM call_alerts LIKE 'status'");
            $row = $stmt ? $stmt->fetch() : null;
            if ($row && isset($row['Type']) && preg_match("/^enum\\((.*)\\)$/", $row['Type'], $m)) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $matches);
                $allowed = $matches[1] ?? [];
            }
        } catch (Exception $e) {
            $allowed = ['pending', 'acknowledged'];
        }
    }
    return in_array($status, $allowed, true);
}
