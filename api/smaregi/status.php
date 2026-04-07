<?php
/**
 * L-15: スマレジ連携状態確認
 *
 * GET /api/smaregi/status.php
 * owner認証必須。テナントのスマレジ連携状態を返す。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/smaregi-client.php';

$user = require_role('owner');
require_method(['GET']);

$pdo = get_db();
$tenantId = $user['tenant_id'];

$smaregi = get_tenant_smaregi($pdo, $tenantId);

if (!$smaregi) {
    json_response([
        'connected'   => false,
        'contract_id' => null,
        'connected_at' => null,
        'token_status' => null,
    ]);
}

// トークン有効期限チェック
$tokenStatus = 'valid';
if ($smaregi['token_expires_at']) {
    $expiresTs = strtotime($smaregi['token_expires_at']);
    if ($expiresTs <= time()) {
        $tokenStatus = 'expired';
    } elseif ($expiresTs - time() < 300) {
        $tokenStatus = 'expiring_soon';
    }
}

// マッピング済み店舗数
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS cnt FROM smaregi_store_mapping WHERE tenant_id = ? AND sync_enabled = 1'
);
$stmt->execute([$tenantId]);
$mappedStores = (int)$stmt->fetch()['cnt'];

json_response([
    'connected'     => true,
    'contract_id'   => $smaregi['contract_id'],
    'connected_at'  => $smaregi['connected_at'],
    'token_status'  => $tokenStatus,
    'mapped_stores' => $mappedStores,
]);
