<?php
/**
 * ハンディ運用ログ API
 *
 * GET /api/store/handy-ops-log.php?store_id=xxx
 *
 * 運用タブに直近の担当操作を表示する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET']);
$user = require_auth();
$storeId = require_store_param();

$pdo = get_db();

$actions = [
    'call_alert_start',
    'call_alert_ack',
    'table_session_update',
    'table_session_move',
    'table_qr_open',
    'table_qr_cancel',
    'order_cancel',
    'order_item_cancel',
    'order_item_qty',
    'item_status',
];
$ph = implode(',', array_fill(0, count($actions), '?'));
$params = array_merge([$storeId], $actions);

try {
    $stmt = $pdo->prepare(
        'SELECT id, username, action, entity_type, entity_id, old_value, new_value, reason, created_at
           FROM audit_log
          WHERE store_id = ? AND action IN (' . $ph . ')
          ORDER BY created_at DESC
          LIMIT 30'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[api/store/handy-ops-log.php] db_error: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
    json_error('DB_ERROR', '運用ログの取得に失敗しました', 500);
}

$now = time();
$logs = [];
foreach ($rows as $row) {
    $newValue = decode_ops_log_json($row['new_value'] ?? null);
    $oldValue = decode_ops_log_json($row['old_value'] ?? null);
    $label = build_ops_log_label($row['action'], $oldValue, $newValue, $row['reason']);
    $createdTs = strtotime($row['created_at']);
    $log = [
        'id' => (int)$row['id'],
        'action' => $row['action'],
        'username' => $row['username'] ?: 'unknown',
        'created_at' => $row['created_at'],
        'elapsed_seconds' => $createdTs ? max(0, $now - $createdTs) : 0,
        'title' => $label['title'],
        'detail' => $label['detail'],
        'reason' => $row['reason'] ?? null,
        'entity_type' => $row['entity_type'],
        'entity_id' => $row['entity_id'],
        'order_item_id' => null,
        'can_undo_served' => false,
    ];
    if ($row['action'] === 'call_alert_ack' && ($newValue['type'] ?? '') === 'product_ready' && !empty($newValue['order_item_id'])) {
        $log['order_item_id'] = $newValue['order_item_id'];
        $log['can_undo_served'] = true;
    } elseif ($row['action'] === 'item_status' && ($newValue['status'] ?? '') === 'served' && $row['entity_type'] === 'order_item') {
        $log['order_item_id'] = $row['entity_id'];
        $log['can_undo_served'] = true;
    }
    $logs[] = $log;
}

json_response(['logs' => $logs]);

function decode_ops_log_json($raw): array
{
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function build_ops_log_label(string $action, array $oldValue, array $newValue, ?string $reason): array
{
    $title = $action;
    $detail = '';
    if ($action === 'call_alert_start') {
        $title = '対応開始';
        $detail = ($newValue['table_code'] ?? '') . ' ' . (($newValue['type'] ?? '') === 'product_ready' ? '料理完成' : '呼び出し');
    } elseif ($action === 'call_alert_ack') {
        $title = (($newValue['type'] ?? '') === 'product_ready') ? '配膳完了' : '呼び出し対応済み';
        $detail = ($newValue['table_code'] ?? '') . (!empty($newValue['item_served']) ? ' 提供済み' : '');
    } elseif ($action === 'table_session_update') {
        $status = $newValue['status'] ?? '';
        if ($status === 'bill_requested') $title = '会計待ち';
        elseif ($status === 'eating') $title = '食事中へ戻し';
        elseif ($status === 'cleaning') $title = '清掃待ち';
        elseif ($status === 'closed') $title = '清掃完了';
        else $title = '卓状態変更';
        $detail = ($oldValue['status'] ?? '') . ' -> ' . $status;
    } elseif ($action === 'table_session_move') {
        $title = '席移動';
        $detail = ($oldValue['table_code'] ?? '-') . ' -> ' . ($newValue['table_code'] ?? '-');
    } elseif ($action === 'table_qr_open') {
        $title = 'QR開放';
        $detail = ($newValue['table_code'] ?? '') . ' ' . (($newValue['expires_minutes'] ?? '') ? $newValue['expires_minutes'] . '分' : '');
    } elseif ($action === 'table_qr_cancel') {
        $title = 'QR取消';
        $detail = ($newValue['table_code'] ?? '') . ' 開放を取り消し';
    } elseif ($action === 'order_cancel') {
        $title = '注文取消';
        $detail = $reason ?: (($oldValue['status'] ?? '') . ' -> ' . ($newValue['status'] ?? ''));
    } elseif ($action === 'order_item_cancel') {
        $title = '品目取消';
        $detail = $reason ?: '品目を取消';
    } elseif ($action === 'order_item_qty') {
        $title = '数量変更';
        $detail = (string)($oldValue['qty'] ?? '-') . ' -> ' . (string)($newValue['qty'] ?? '-');
    } elseif ($action === 'item_status') {
        $status = $newValue['status'] ?? '';
        if ($status === 'served') $title = '配膳完了';
        elseif ($status === 'ready') $title = '配膳取消';
        else $title = '品目状態変更';
        $detail = ($oldValue['status'] ?? '') . ' -> ' . $status;
    }
    return ['title' => trim($title), 'detail' => trim($detail)];
}
