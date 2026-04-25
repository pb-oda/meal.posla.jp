<?php
/**
 * メニュー変更検知（軽量エンドポイント）
 *
 * GET /api/store/menu-version.php?store_id=xxx
 * → { ok: true, data: { version: "2026-03-31 15:30:45" } }
 *
 * store_menu_overrides と store_local_items の最新 updated_at を返す。
 * 顧客メニュー・ハンディが数秒ごとにポーリングし、
 * バージョンが変わったときだけフルメニュー取得する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

require_method(['GET']);

$storeId = $_GET['store_id'] ?? null;
if (!$storeId) {
    json_error('MISSING_STORE', 'store_id は必須です', 400);
}

$pdo = get_db();

$version = '1970-01-01 00:00:00';

try {
    $stmt = $pdo->prepare(
        'SELECT MAX(updated_at) AS latest FROM store_menu_overrides WHERE store_id = ?'
    );
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    if ($row && $row['latest'] && $row['latest'] > $version) {
        $version = $row['latest'];
    }
} catch (Exception $e) {
    // テーブル未存在時はスキップ
    error_log('[P1-12][api/store/menu-version.php:36] fetch_overrides_version: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

try {
    $stmt = $pdo->prepare(
        'SELECT MAX(updated_at) AS latest FROM store_local_items WHERE store_id = ?'
    );
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    if ($row && $row['latest'] && $row['latest'] > $version) {
        $version = $row['latest'];
    }
} catch (Exception $e) {
    // テーブル未存在時はスキップ
    error_log('[P1-12][api/store/menu-version.php:49] fetch_local_items_version: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}

json_response(['version' => $version]);
