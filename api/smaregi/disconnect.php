<?php
/**
 * L-15: スマレジ連携解除
 *
 * POST /api/smaregi/disconnect.php
 * owner認証必須。テナントのスマレジ連携情報をクリアする。
 * POSLAのメニューテンプレートや注文履歴はそのまま残る。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$user = require_role('owner');
require_method(['POST']);

$pdo = get_db();
$tenantId = $user['tenant_id'];

// テナントのスマレジ認証情報をクリア
$stmt = $pdo->prepare(
    'UPDATE tenants SET
        smaregi_contract_id = NULL,
        smaregi_access_token = NULL,
        smaregi_refresh_token = NULL,
        smaregi_token_expires_at = NULL,
        smaregi_connected_at = NULL
     WHERE id = ?'
);
$stmt->execute([$tenantId]);

// 店舗マッピングの同期を全無効化（マッピング自体は残す）
$stmt = $pdo->prepare(
    'UPDATE smaregi_store_mapping SET sync_enabled = 0 WHERE tenant_id = ?'
);
$stmt->execute([$tenantId]);

json_response(['message' => 'スマレジ連携を解除しました']);
