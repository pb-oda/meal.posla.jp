<?php
/**
 * 店舗管理 API（オーナー専用）
 *
 * GET    /api/owner/stores.php            — 一覧
 * GET    /api/owner/stores.php?id=xxx     — 詳細
 * POST   /api/owner/stores.php            — 新規作成
 * PATCH  /api/owner/stores.php?id=xxx     — 更新
 * DELETE /api/owner/stores.php?id=xxx     — 削除（論理）
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stripe-billing.php'; // P1-36: sync_alpha1_subscription_quantity

/**
 * P1-36: 店舗 CRUD 後に Stripe subscription quantity を同期する
 * 例外を投げず、エラー時は error_log のみ。店舗操作自体は止めない
 */
function _stores_sync_subscription($pdo, $tenantId, $context) {
    try {
        $result = sync_alpha1_subscription_quantity($pdo, $tenantId);
        if (!empty($result['error'])) {
            error_log('[P1-36 sync stores.php ' . $context . '] tenant=' . $tenantId . ' error=' . $result['error']);
        }
    } catch (Throwable $e) {
        error_log('[P1-36 sync stores.php ' . $context . '] tenant=' . $tenantId . ' exception=' . $e->getMessage());
    }
}

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('owner');
$pdo = get_db();
$tenantId = $user['tenant_id'];

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        $store = $stmt->fetch();
        if (!$store) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
        json_response(['store' => $store]);
    }

    $stmt = $pdo->prepare('SELECT * FROM stores WHERE tenant_id = ? ORDER BY name');
    $stmt->execute([$tenantId]);
    json_response(['stores' => $stmt->fetchAll()]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    $slug = trim($data['slug'] ?? '');

    if (!$name || !$slug) json_error('MISSING_FIELDS', '店舗名とスラッグは必須です', 400);
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) json_error('INVALID_SLUG', 'スラッグは英小文字・数字・ハイフンのみ使用可能です', 400);

    // slug重複チェック（テナント内）
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM stores WHERE tenant_id = ? AND slug = ?');
    $stmt->execute([$tenantId, $slug]);
    if ($stmt->fetchColumn() > 0) json_error('DUPLICATE_SLUG', 'このスラッグは既に使用されています', 409);

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO stores (id, tenant_id, slug, name, name_en, is_active) VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([$id, $tenantId, $slug, $name, trim($data['name_en'] ?? '')]);

    // store_settings デフォルト作成
    $pdo->prepare('INSERT INTO store_settings (store_id) VALUES (?)')->execute([$id]);

    // P1-36: Stripe subscription quantity 同期
    _stores_sync_subscription($pdo, $tenantId, 'POST');

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    // テナント所属確認
    $stmt = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);

    $data = get_json_body();
    $fields = [];
    $params = [];

    foreach (['name', 'name_en'] as $col) {
        if (isset($data[$col])) {
            $fields[] = "$col = ?";
            $params[] = trim($data[$col]);
        }
    }
    if (isset($data['slug'])) {
        $slug = trim($data['slug']);
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) json_error('INVALID_SLUG', 'スラッグは英小文字・数字・ハイフンのみ使用可能です', 400);
        $dup = $pdo->prepare('SELECT COUNT(*) FROM stores WHERE tenant_id = ? AND slug = ? AND id != ?');
        $dup->execute([$tenantId, $slug, $id]);
        if ($dup->fetchColumn() > 0) json_error('DUPLICATE_SLUG', 'このスラッグは既に使用されています', 409);
        $fields[] = 'slug = ?';
        $params[] = $slug;
    }
    if (isset($data['is_active'])) {
        $fields[] = 'is_active = ?';
        $params[] = $data['is_active'] ? 1 : 0;
    }

    if (empty($fields)) json_error('NO_FIELDS', '更新項目がありません', 400);

    $params[] = $id;
    $pdo->prepare('UPDATE stores SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    // P1-36: is_active が変更された場合のみ Stripe subscription quantity 同期
    if (isset($data['is_active'])) {
        _stores_sync_subscription($pdo, $tenantId, 'PATCH');
    }

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    $stmt = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    if (!$stmt->fetch()) json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);

    // 論理削除 (is_active = 0)
    $pdo->prepare('UPDATE stores SET is_active = 0 WHERE id = ?')->execute([$id]);

    // P1-36: Stripe subscription quantity 同期
    _stores_sync_subscription($pdo, $tenantId, 'DELETE');

    json_response(['ok' => true]);
}
