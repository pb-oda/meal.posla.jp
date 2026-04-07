<?php
/**
 * L-15: 店舗マッピング CRUD
 *
 * GET  /api/smaregi/store-mapping.php  — 現在のマッピング取得
 * POST /api/smaregi/store-mapping.php  — マッピング更新（delete-and-reinsert）
 *
 * owner認証必須。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$user = require_role('owner');
$method = require_method(['GET', 'POST']);

$pdo = get_db();
$tenantId = $user['tenant_id'];

// ============================================================
// GET — 現在のマッピング取得
// ============================================================
if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT sm.id, sm.store_id, s.name AS store_name,
                sm.smaregi_store_id, sm.sync_enabled, sm.last_menu_sync
         FROM smaregi_store_mapping sm
         INNER JOIN stores s ON s.id = sm.store_id
         WHERE sm.tenant_id = ?
         ORDER BY s.name'
    );
    $stmt->execute([$tenantId]);
    $mappings = $stmt->fetchAll();

    json_response(['mappings' => $mappings]);
}

// ============================================================
// POST — マッピング更新（delete-and-reinsert）
// ============================================================
if ($method === 'POST') {
    $input = get_json_body();

    if (!isset($input['mappings']) || !is_array($input['mappings'])) {
        json_error('INVALID_INPUT', 'mappings 配列が必要です', 400);
    }

    // テナントの店舗IDを事前検証用に取得
    $stmt = $pdo->prepare('SELECT id FROM stores WHERE tenant_id = ? AND is_active = 1');
    $stmt->execute([$tenantId]);
    $validStoreIds = [];
    while ($row = $stmt->fetch()) {
        $validStoreIds[$row['id']] = true;
    }

    $pdo->beginTransaction();
    try {
        // 既存マッピングを全削除
        $stmt = $pdo->prepare('DELETE FROM smaregi_store_mapping WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);

        // 新しいマッピングを挿入
        $insertStmt = $pdo->prepare(
            'INSERT INTO smaregi_store_mapping (id, tenant_id, store_id, smaregi_store_id, sync_enabled)
             VALUES (?, ?, ?, ?, ?)'
        );

        $inserted = 0;
        foreach ($input['mappings'] as $m) {
            $storeId = isset($m['store_id']) ? $m['store_id'] : '';
            $smaregiStoreId = isset($m['smaregi_store_id']) ? $m['smaregi_store_id'] : '';
            $syncEnabled = isset($m['sync_enabled']) ? (int)$m['sync_enabled'] : 1;

            if (!$storeId || !$smaregiStoreId) continue;

            // テナント所属検証
            if (!isset($validStoreIds[$storeId])) continue;

            $insertStmt->execute([
                generate_uuid(),
                $tenantId,
                $storeId,
                $smaregiStoreId,
                $syncEnabled,
            ]);
            $inserted++;
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('DB_ERROR', 'マッピング更新に失敗しました', 500);
    }

    json_response([
        'message'  => '店舗マッピングを更新しました',
        'inserted' => $inserted,
    ]);
}
