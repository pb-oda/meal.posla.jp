<?php
/**
 * L-15: スマレジ店舗一覧取得
 *
 * GET /api/smaregi/stores.php
 * owner認証必須。スマレジの店舗一覧 + POSLA店舗 + 現在のマッピングを返す。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/smaregi-client.php';

$user = require_role('owner');
require_method(['GET']);

$pdo = get_db();
$tenantId = $user['tenant_id'];

// スマレジ連携チェック
$smaregi = get_tenant_smaregi($pdo, $tenantId);
if (!$smaregi) {
    json_error('NOT_CONNECTED', 'スマレジ未連携です', 400);
}

// スマレジAPI: 店舗一覧取得
$result = smaregi_api_request($pdo, $tenantId, 'GET', '/pos/stores', null);
if (!$result['ok']) {
    $errMsg = isset($result['data']['error']) ? $result['data']['error'] : 'スマレジAPI呼び出し失敗';
    $detail = json_encode($result['data'], JSON_UNESCAPED_UNICODE);
    json_error('SMAREGI_API_ERROR', $errMsg . ' (HTTP=' . $result['status'] . ' detail=' . $detail . ')', 502);
}

$smaregiStores = [];
if (is_array($result['data'])) {
    foreach ($result['data'] as $s) {
        $smaregiStores[] = [
            'storeId'   => isset($s['storeId']) ? $s['storeId'] : null,
            'storeName' => isset($s['storeName']) ? $s['storeName'] : '',
        ];
    }
}

// POSLA店舗一覧
$stmt = $pdo->prepare(
    'SELECT id, name FROM stores WHERE tenant_id = ? AND is_active = 1 ORDER BY name'
);
$stmt->execute([$tenantId]);
$poslaStores = $stmt->fetchAll();

// 現在のマッピング
$stmt = $pdo->prepare(
    'SELECT id, store_id, smaregi_store_id, sync_enabled, last_menu_sync
     FROM smaregi_store_mapping WHERE tenant_id = ?'
);
$stmt->execute([$tenantId]);
$mappings = $stmt->fetchAll();

json_response([
    'smaregi_stores' => $smaregiStores,
    'posla_stores'   => $poslaStores,
    'mappings'       => $mappings,
]);
