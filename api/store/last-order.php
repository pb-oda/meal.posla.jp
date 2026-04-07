<?php
/**
 * ラストオーダー管理 API (O-3)
 *
 * GET  /api/store/last-order.php?store_id=xxx  — LO状態取得（認証不要・顧客画面用）
 * POST /api/store/last-order.php               — LO発動/解除（manager以上）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET', 'POST']);

$pdo = get_db();

// ── GET: ラストオーダー状態取得（認証不要） ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = $_GET['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_id は必須です', 400);

    try {
        $stmt = $pdo->prepare(
            'SELECT last_order_time, last_order_active, last_order_activated_at
             FROM store_settings WHERE store_id = ?'
        );
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();

        if (!$row) {
            json_response(['is_last_order' => false]);
        }

        $isLastOrder = false;
        $loTime = $row['last_order_time'] ?? null;
        $loActive = (int)($row['last_order_active'] ?? 0);

        // 手動発動中
        if ($loActive === 1) {
            $isLastOrder = true;
        }

        // 定時LO: 現在時刻 >= last_order_time
        if ($loTime && !$isLastOrder) {
            $now = date('H:i:s');
            if ($now >= $loTime) {
                $isLastOrder = true;
            }
        }

        json_response([
            'is_last_order'    => $isLastOrder,
            'last_order_time'  => $loTime,
            'last_order_active' => $loActive === 1,
            'activated_at'     => $row['last_order_activated_at'] ?? null,
        ]);
    } catch (PDOException $e) {
        // カラム未存在時（migration未適用）
        json_response(['is_last_order' => false]);
    }
}

// ── POST: ラストオーダー発動/解除（manager以上） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_auth();
    require_role('manager');

    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    $active = isset($data['active']) ? (int)$data['active'] : null;

    if (!$storeId || $active === null) {
        json_error('MISSING_FIELDS', 'store_id と active は必須です', 400);
    }
    require_store_access($storeId);

    try {
        if ($active) {
            $pdo->prepare(
                'UPDATE store_settings SET last_order_active = 1, last_order_activated_at = NOW() WHERE store_id = ?'
            )->execute([$storeId]);
        } else {
            $pdo->prepare(
                'UPDATE store_settings SET last_order_active = 0, last_order_activated_at = NULL WHERE store_id = ?'
            )->execute([$storeId]);
        }

        json_response(['ok' => true, 'active' => $active ? true : false]);
    } catch (PDOException $e) {
        json_error('MIGRATION_REQUIRED', 'ラストオーダー機能にはデータベースの更新が必要です: ' . $e->getMessage(), 500);
    }
}
