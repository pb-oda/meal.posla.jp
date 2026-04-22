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

        $selectCols = 'id, table_code, reason, created_at';
        if ($hasTypeCol) {
            $selectCols .= ', type, order_item_id, item_name';
        }

        $stmt = $pdo->prepare(
            'SELECT ' . $selectCols . '
             FROM call_alerts
             WHERE store_id = ? AND status = ?
             ORDER BY created_at ASC'
        );
        $stmt->execute([$storeId, 'pending']);
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

    if ($status !== 'acknowledged') {
        json_error('INVALID_STATUS', 'status は acknowledged のみ指定可能です', 400);
    }

    // O-5: アラート情報を先に取得（product_ready → 品目を提供済に連動）
    $alertType = null;
    $orderItemId = null;
    $alertStoreId = null;
    try {
        $fetchStmt = $pdo->prepare(
            'SELECT type, order_item_id, store_id FROM call_alerts WHERE id = ?'
        );
        $fetchStmt->execute([$alertId]);
        $alertRow = $fetchStmt->fetch();
        if ($alertRow) {
            $alertType = $alertRow['type'] ?? null;
            $orderItemId = $alertRow['order_item_id'] ?? null;
            $alertStoreId = $alertRow['store_id'] ?? null;
        }
    } catch (PDOException $e) {
        // type カラムが無い場合は従来通り
        error_log('[P1-12][api/kds/call-alerts.php:104] fetch_alert_info: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE call_alerts SET status = ?, acknowledged_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $alertId]);
    } catch (PDOException $e) {
        json_error('UPDATE_FAILED', 'アラートの更新に失敗しました', 500);
    }

    // O-5: product_ready 対応済み → order_item を served に連動更新
    $itemServed = false;
    $orderCompleted = false;
    if ($alertType === 'product_ready' && $orderItemId && $alertStoreId) {
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
            error_log('[P1-12][api/kds/call-alerts.php:152] kds_item_served_sync: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        }
    }

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
            'url'   => '/public/handy/index.html',
            'tag'   => 'kitchen_call_' . $alertId,
        ]);
    } catch (\Throwable $e) {
        // 業務処理を止めない
    }

    json_response(['alert_id' => $alertId]);
}
