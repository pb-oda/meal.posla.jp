<?php
/**
 * L-9 予約管理 — 顧客台帳
 * GET   ?store_id=xxx&q=検索語           … 顧客一覧 (リピーター順)
 * GET   ?store_id=xxx&id=xxx             … 単一顧客 + 来店履歴
 * PATCH { id, store_id, ... }            … タグ・好み・ブラックリスト等更新
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/auth.php';

$method = require_method(['GET','PATCH']);
$user = require_role('staff');
$pdo = get_db();

if ($method === 'GET') {
    $storeId = isset($_GET['store_id']) ? trim($_GET['store_id']) : '';
    if (!$storeId) json_error('MISSING_STORE', 'store_id が必要です', 400);
    require_store_access($storeId);

    if (!empty($_GET['id'])) {
        $cStmt = $pdo->prepare('SELECT * FROM reservation_customers WHERE id = ? AND store_id = ?');
        $cStmt->execute([$_GET['id'], $storeId]);
        $c = $cStmt->fetch();
        if (!$c) json_error('CUSTOMER_NOT_FOUND', '顧客が見つかりません', 404);
        $hStmt = $pdo->prepare('SELECT id, reserved_at, party_size, status, source, course_name, memo FROM reservations WHERE store_id = ? AND customer_id = ? ORDER BY reserved_at DESC LIMIT 50');
        $hStmt->execute([$storeId, $c['id']]);
        json_response(['customer' => $c, 'history' => $hStmt->fetchAll()]);
    }

    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $vipOnly = !empty($_GET['vip_only']);
    $blacklistOnly = !empty($_GET['blacklist_only']);
    $sql = 'SELECT * FROM reservation_customers WHERE store_id = ?';
    $params = [$storeId];
    if ($q !== '') {
        $sql .= ' AND (customer_name LIKE ? OR customer_phone LIKE ? OR customer_email LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($vipOnly) $sql .= ' AND is_vip = 1';
    if ($blacklistOnly) $sql .= ' AND is_blacklisted = 1';
    $sql .= ' ORDER BY visit_count DESC, last_visit_at DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['customers' => $stmt->fetchAll()]);
}

if ($method === 'PATCH') {
    $body = get_json_body();
    $id = isset($body['id']) ? trim($body['id']) : '';
    $storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
    if (!$id || !$storeId) json_error('MISSING_PARAM', 'id と store_id が必要です', 400);
    require_store_access($storeId);

    $allowed = ['customer_name','customer_phone','customer_email','preferences','allergies','tags','is_vip','is_blacklisted','blacklist_reason','internal_memo'];
    $sets = []; $params = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $body)) continue;
        $v = $body[$k];
        if ($k === 'is_vip' || $k === 'is_blacklisted') $v = ((int)$v === 1) ? 1 : 0;
        elseif ($v !== null) $v = (string)$v;
        // ブラックリスト操作は manager 以上
        if ($k === 'is_blacklisted' && $user['role'] === 'staff') json_error('FORBIDDEN', 'ブラックリスト操作は manager 以上', 403);
        $sets[] = $k . ' = ?';
        $params[] = $v;
    }
    if (empty($sets)) json_error('NO_FIELDS', '更新項目がありません', 400);
    $sets[] = 'updated_at = NOW()';
    $params[] = $id;
    $params[] = $storeId;
    $pdo->prepare('UPDATE reservation_customers SET ' . implode(', ', $sets) . ' WHERE id = ? AND store_id = ?')->execute($params);

    $stmt = $pdo->prepare('SELECT * FROM reservation_customers WHERE id = ?');
    $stmt->execute([$id]);
    json_response(['customer' => $stmt->fetch()]);
}
