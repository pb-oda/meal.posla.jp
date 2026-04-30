<?php
/**
 * テイクアウト注文管理 API（店舗向け・認証必須）
 *
 * GET   ?store_id=xxx&date=YYYY-MM-DD&status=xxx — テイクアウト注文一覧
 * PATCH                                          — ステータス更新
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';
// L-17 Phase 3B-1: status='ready' 時に LINE push を並行。ヘルパ未同梱でも落ちない
if (file_exists(__DIR__ . '/../lib/line-messaging.php')) {
    require_once __DIR__ . '/../lib/line-messaging.php';
}
if (file_exists(__DIR__ . '/../lib/takeout-line-link.php')) {
    require_once __DIR__ . '/../lib/takeout-line-link.php';
}

require_method(['GET', 'PATCH']);
$user = require_auth();
$pdo = get_db();

function takeout_pack_defaults(): array
{
    return [
        'items' => 0,
        'chopsticks' => 0,
        'bag' => 0,
        'sauce' => 0,
        'receipt' => 0,
    ];
}

function takeout_pack_normalize($checklist): array
{
    if (is_string($checklist)) {
        $checklist = json_decode($checklist, true);
    }
    if (!is_array($checklist)) {
        $checklist = [];
    }
    $normalized = takeout_pack_defaults();
    foreach ($normalized as $key => $unused) {
        $normalized[$key] = !empty($checklist[$key]) ? 1 : 0;
    }
    return $normalized;
}

function takeout_pack_complete(array $checklist): bool
{
    foreach (takeout_pack_defaults() as $key => $unused) {
        if (empty($checklist[$key])) return false;
    }
    return true;
}

function takeout_item_summary($items): array
{
    if (is_string($items)) {
        $items = json_decode($items, true);
    }
    if (!is_array($items)) $items = [];
    $summaryParts = [];
    $qtyTotal = 0;
    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? 1);
        $qtyTotal += $qty;
        $summaryParts[] = ($item['name'] ?? '?') . ' x' . $qty;
    }
    return ['summary' => implode(', ', $summaryParts), 'qty' => $qtyTotal];
}

function takeout_sla_warning_minutes(PDO $pdo, string $storeId): int
{
    try {
        $stmt = $pdo->prepare('SELECT takeout_sla_warning_minutes FROM store_settings WHERE store_id = ?');
        $stmt->execute([$storeId]);
        $value = $stmt->fetchColumn();
        if ($value !== false) return max(1, (int)$value);
    } catch (PDOException $e) {}
    return 10;
}

function takeout_compute_sla(array $order, int $warningMinutes): array
{
    $pickupTs = !empty($order['pickup_at']) ? strtotime($order['pickup_at']) : false;
    $nowTs = time();
    $status = (string)$order['status'];
    if (!$pickupTs) {
        return ['state' => 'unknown', 'label' => '受取時刻未設定', 'minutes' => null];
    }
    if ($status === 'cancelled') {
        return ['state' => 'cancelled', 'label' => 'キャンセル', 'minutes' => null];
    }
    if ($status === 'ready' || $status === 'served' || $status === 'paid') {
        $readyTs = !empty($order['ready_at']) ? strtotime($order['ready_at']) : false;
        if ($readyTs && $readyTs > $pickupTs) {
            return ['state' => 'late_resolved', 'label' => '遅延後に準備完了', 'minutes' => (int)ceil(($readyTs - $pickupTs) / 60)];
        }
        return ['state' => 'ready', 'label' => 'SLA内', 'minutes' => null];
    }
    $minutesLeft = (int)floor(($pickupTs - $nowTs) / 60);
    if ($minutesLeft < 0) {
        return ['state' => 'late', 'label' => '受取時刻超過 ' . abs($minutesLeft) . '分', 'minutes' => abs($minutesLeft)];
    }
    if ($minutesLeft <= $warningMinutes) {
        return ['state' => 'risk', 'label' => '遅れ注意 あと' . $minutesLeft . '分', 'minutes' => $minutesLeft];
    }
    return ['state' => 'ok', 'label' => 'あと' . $minutesLeft . '分', 'minutes' => $minutesLeft];
}

function takeout_payment_summary(PDO $pdo, string $storeId, string $orderId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT id, refund_status
               FROM payments
              WHERE store_id = ? AND order_ids LIKE ?
              ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$storeId, '%' . $orderId . '%']);
        $payment = $stmt->fetch();
        if ($payment) {
            return [
                'payment_id' => $payment['id'],
                'refund_status' => $payment['refund_status'] ?? 'none',
            ];
        }
    } catch (PDOException $e) {}
    return ['payment_id' => null, 'refund_status' => 'none'];
}

function takeout_send_ready_notification(PDO $pdo, string $orderId, string $storeId): array
{
    $result = ['attempted' => false, 'success' => false, 'error' => 'LINE_HELPER_MISSING'];
    if (function_exists('takeout_notify_ready_line')) {
        try {
            $result = takeout_notify_ready_line($pdo, $orderId, $storeId);
        } catch (Exception $e) {
            $result = ['attempted' => true, 'success' => false, 'error' => $e->getMessage()];
            error_log('[P1-65 takeout_ready_notify] order=' . $orderId . ': ' . $e->getMessage());
        }
    }
    $status = !empty($result['success']) ? 'sent' : (!empty($result['attempted']) ? 'failed' : 'skipped');
    $error = !empty($result['success']) ? null : substr((string)($result['error'] ?? 'UNKNOWN'), 0, 255);
    $stmt = $pdo->prepare(
        "UPDATE orders
            SET takeout_ready_notification_status = ?,
                takeout_ready_notification_error = ?,
                takeout_ready_notified_at = CASE WHEN ? = 'sent' THEN NOW() ELSE takeout_ready_notified_at END,
                updated_at = NOW()
          WHERE id = ? AND store_id = ? AND order_type = 'takeout'"
    );
    $stmt->execute([$status, $error, $status, $orderId, $storeId]);
    return [
        'attempted' => !empty($result['attempted']),
        'success' => !empty($result['success']),
        'status' => $status,
        'error' => $error,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();
    require_store_access($storeId);

    $date = $_GET['date'] ?? date('Y-m-d');
    $statusFilter = $_GET['status'] ?? null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('INVALID_DATE', '日付形式が不正です', 400);

    $sql = "SELECT o.id AS order_id, o.customer_name, o.customer_phone, o.pickup_at,
                   o.status, o.total_amount, o.items, o.memo, o.created_at,
                   o.prepared_at, o.ready_at, o.served_at,
                   o.takeout_pack_checklist, o.takeout_pack_checked_at, o.takeout_pack_checked_by_user_id,
                   o.takeout_ready_notified_at, o.takeout_ready_notification_status, o.takeout_ready_notification_error,
                   o.takeout_ops_status, o.takeout_ops_note, o.takeout_ops_updated_at, o.takeout_ops_updated_by_user_id,
                   o.takeout_arrived_at, o.takeout_arrival_type, o.takeout_arrival_note
              FROM orders o
             WHERE o.store_id = ? AND o.order_type = 'takeout'
               AND DATE(COALESCE(o.pickup_at, o.created_at)) = ?";
    $params = [$storeId, $date];

    if ($statusFilter && $statusFilter !== 'all') {
        $sql .= " AND o.status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY COALESCE(o.pickup_at, o.created_at) ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $warningMinutes = takeout_sla_warning_minutes($pdo, $storeId);
    $result = [];
    $summary = [
        'total' => 0,
        'sla_risk' => 0,
        'sla_late' => 0,
        'packing_incomplete' => 0,
        'notify_failed' => 0,
        'refund_attention' => 0,
        'arrived_waiting' => 0,
    ];

    foreach ($orders as $o) {
        $items = takeout_item_summary($o['items']);
        $checklist = takeout_pack_normalize($o['takeout_pack_checklist']);
        $packComplete = takeout_pack_complete($checklist);
        $sla = takeout_compute_sla($o, $warningMinutes);
        $payment = takeout_payment_summary($pdo, $storeId, $o['order_id']);

        $summary['total'] += 1;
        if ($sla['state'] === 'risk') $summary['sla_risk'] += 1;
        if ($sla['state'] === 'late') $summary['sla_late'] += 1;
        if (!$packComplete && !in_array($o['status'], ['cancelled', 'served', 'paid'], true)) $summary['packing_incomplete'] += 1;
        if ($o['takeout_ready_notification_status'] === 'failed') $summary['notify_failed'] += 1;
        if (in_array($o['takeout_ops_status'], ['refund_pending', 'refunded'], true)) $summary['refund_attention'] += 1;
        $arrivalWaiting = !empty($o['takeout_arrived_at']) && !in_array($o['status'], ['cancelled', 'served', 'paid'], true);
        if ($arrivalWaiting) $summary['arrived_waiting'] += 1;

        $result[] = [
            'order_id' => $o['order_id'],
            'customer_name' => $o['customer_name'],
            'customer_phone' => $o['customer_phone'],
            'pickup_at' => $o['pickup_at'],
            'status' => $o['status'],
            'total_amount' => (int)$o['total_amount'],
            'item_summary' => $items['summary'],
            'item_qty' => $items['qty'],
            'memo' => $o['memo'],
            'created_at' => $o['created_at'],
            'prepared_at' => $o['prepared_at'],
            'ready_at' => $o['ready_at'],
            'served_at' => $o['served_at'],
            'sla' => $sla,
            'pack_checklist' => $checklist,
            'pack_complete' => $packComplete ? 1 : 0,
            'pack_checked_at' => $o['takeout_pack_checked_at'],
            'ready_notified_at' => $o['takeout_ready_notified_at'],
            'ready_notification_status' => $o['takeout_ready_notification_status'] ?? 'not_requested',
            'ready_notification_error' => $o['takeout_ready_notification_error'],
            'ops_status' => $o['takeout_ops_status'] ?? 'normal',
            'ops_note' => $o['takeout_ops_note'],
            'ops_updated_at' => $o['takeout_ops_updated_at'],
            'arrived_at' => $o['takeout_arrived_at'],
            'arrival_type' => $o['takeout_arrival_type'],
            'arrival_note' => $o['takeout_arrival_note'],
            'arrival_waiting' => $arrivalWaiting ? 1 : 0,
            'payment_id' => $payment['payment_id'],
            'refund_status' => $payment['refund_status'],
        ];
    }

    json_response(['orders' => $result, 'summary' => $summary, 'sla_warning_minutes' => $warningMinutes]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $orderId = $data['order_id'] ?? null;
    $action = $data['action'] ?? (isset($data['status']) ? 'status' : null);

    if (!$storeId) json_error('MISSING_STORE', 'store_idが必要です', 400);
    if (!$orderId) json_error('MISSING_ORDER', 'order_idが必要です', 400);
    if (!$action) json_error('MISSING_ACTION', 'actionが必要です', 400);
    require_store_access($storeId);

    $stmt = $pdo->prepare(
        "SELECT id, status, takeout_pack_checklist, takeout_ops_status, takeout_ops_note
           FROM orders
          WHERE id = ? AND store_id = ? AND order_type = 'takeout'"
    );
    $stmt->execute([$orderId, $storeId]);
    $order = $stmt->fetch();
    if (!$order) json_error('NOT_FOUND', '注文が見つかりません', 404);

    if ($action === 'status') {
        $newStatus = $data['status'] ?? null;
        if (!$newStatus) json_error('MISSING_STATUS', 'statusが必要です', 400);

        $allowed = [
            'pending' => ['preparing'],
            'pending_payment' => ['pending', 'cancelled'],
            'preparing' => ['ready'],
            'ready' => ['served'],
        ];
        $current = $order['status'];
        if (!isset($allowed[$current]) || !in_array($newStatus, $allowed[$current], true)) {
            json_error('INVALID_TRANSITION', $current . ' から ' . $newStatus . ' への遷移はできません', 400);
        }

        $sets = ['status = ?', 'updated_at = NOW()'];
        $params = [$newStatus];
        if ($newStatus === 'preparing') $sets[] = 'prepared_at = COALESCE(prepared_at, NOW())';
        if ($newStatus === 'ready') $sets[] = 'ready_at = COALESCE(ready_at, NOW())';
        if ($newStatus === 'served') $sets[] = 'served_at = COALESCE(served_at, NOW())';
        $params[] = $orderId;
        $params[] = $storeId;
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND store_id = ? AND order_type = "takeout"')
            ->execute($params);

        $note = null;
        $notification = null;
        if ($newStatus === 'ready') {
            $notification = takeout_send_ready_notification($pdo, $orderId, $storeId);
            if ($notification['status'] === 'sent') {
                $note = '準備完了通知を送信しました';
            } elseif ($notification['status'] === 'skipped') {
                $note = '準備完了にしました。LINE未連携のため通知はスキップされました';
            } else {
                $note = '準備完了にしました。通知送信は失敗しました';
            }
        } elseif ($newStatus === 'served') {
            $note = '受取完了にしました';
        }

        @write_audit_log($pdo, $user, $storeId, 'takeout_status', 'order', $orderId, ['status' => $current], ['status' => $newStatus], null);
        json_response(['ok' => true, 'order_id' => $orderId, 'status' => $newStatus, 'note' => $note, 'notification' => $notification]);
    }

    if ($action === 'pack') {
        $checklist = takeout_pack_normalize($data['checklist'] ?? []);
        $complete = takeout_pack_complete($checklist);
        $pdo->prepare(
            'UPDATE orders
                SET takeout_pack_checklist = ?,
                    takeout_pack_checked_at = ' . ($complete ? 'NOW()' : 'NULL') . ',
                    takeout_pack_checked_by_user_id = ?,
                    updated_at = NOW()
              WHERE id = ? AND store_id = ? AND order_type = "takeout"'
        )->execute([
            json_encode($checklist, JSON_UNESCAPED_UNICODE),
            $complete ? $user['user_id'] : null,
            $orderId,
            $storeId,
        ]);
        @write_audit_log($pdo, $user, $storeId, 'takeout_pack', 'order', $orderId, takeout_pack_normalize($order['takeout_pack_checklist']), $checklist, null);
        json_response(['ok' => true, 'order_id' => $orderId, 'pack_checklist' => $checklist, 'pack_complete' => $complete ? 1 : 0]);
    }

    if ($action === 'ops') {
        $opsStatus = $data['ops_status'] ?? null;
        $allowedOps = ['normal', 'late_risk', 'late', 'customer_delayed', 'cancel_requested', 'cancelled', 'refund_pending', 'refunded'];
        if (!$opsStatus || !in_array($opsStatus, $allowedOps, true)) {
            json_error('INVALID_OPS_STATUS', '運用ステータスが不正です', 400);
        }
        $opsNote = isset($data['ops_note']) ? trim((string)$data['ops_note']) : null;
        if ($opsNote !== null && mb_strlen($opsNote) > 255) {
            $opsNote = mb_substr($opsNote, 0, 255);
        }
        $sets = [
            'takeout_ops_status = ?',
            'takeout_ops_note = ?',
            'takeout_ops_updated_at = NOW()',
            'takeout_ops_updated_by_user_id = ?',
            'updated_at = NOW()',
        ];
        $params = [$opsStatus, $opsNote ?: null, $user['user_id']];
        if ($opsStatus === 'cancelled' && !in_array($order['status'], ['served', 'paid'], true)) {
            $sets[] = "status = 'cancelled'";
        }
        $params[] = $orderId;
        $params[] = $storeId;
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND store_id = ? AND order_type = "takeout"')
            ->execute($params);

        @write_audit_log(
            $pdo,
            $user,
            $storeId,
            'takeout_ops',
            'order',
            $orderId,
            ['ops_status' => $order['takeout_ops_status'], 'ops_note' => $order['takeout_ops_note']],
            ['ops_status' => $opsStatus, 'ops_note' => $opsNote],
            null
        );
        json_response(['ok' => true, 'order_id' => $orderId, 'ops_status' => $opsStatus, 'ops_note' => $opsNote]);
    }

    if ($action === 'notify_ready') {
        if ($order['status'] === 'preparing') {
            $pdo->prepare('UPDATE orders SET status = "ready", ready_at = COALESCE(ready_at, NOW()), updated_at = NOW() WHERE id = ? AND store_id = ? AND order_type = "takeout"')
                ->execute([$orderId, $storeId]);
        } elseif ($order['status'] !== 'ready') {
            json_error('INVALID_TRANSITION', '準備完了済みの注文のみ再通知できます', 400);
        }
        $notification = takeout_send_ready_notification($pdo, $orderId, $storeId);
        @write_audit_log($pdo, $user, $storeId, 'takeout_ready_notify', 'order', $orderId, null, $notification, null);
        json_response(['ok' => true, 'order_id' => $orderId, 'status' => 'ready', 'notification' => $notification]);
    }

    json_error('INVALID_ACTION', 'actionが不正です', 400);
}
