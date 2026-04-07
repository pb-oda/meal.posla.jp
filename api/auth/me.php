<?php
/**
 * 現在ユーザー情報 API
 *
 * GET /api/auth/me.php
 *
 * セッション情報とアクセス可能店舗一覧を返す。
 * ページロード時の認証チェック + 店舗セレクター用データ取得に使用。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET']);

$user = require_auth();
$pdo = get_db();

// ユーザー詳細
$stmt = $pdo->prepare(
    'SELECT u.display_name, t.name AS tenant_name, t.slug AS tenant_slug
     FROM users u JOIN tenants t ON t.id = u.tenant_id
     WHERE u.id = ?'
);
$stmt->execute([$user['user_id']]);
$detail = $stmt->fetch();

// アクセス可能店舗（L-3b: LEFT JOIN shift_settings でGPS設定も取得）
if ($user['role'] === 'owner') {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.slug, s.name, s.name_en,
                ss.store_lat, ss.store_lng, ss.gps_radius_meters, ss.gps_required, ss.staff_visible_tools
         FROM stores s
         LEFT JOIN shift_settings ss ON ss.store_id = s.id AND ss.tenant_id = s.tenant_id
         WHERE s.tenant_id = ? AND s.is_active = 1 ORDER BY s.name'
    );
    $stmt->execute([$user['tenant_id']]);
} else {
    $storeIds = $user['store_ids'];
    if (count($storeIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT s.id, s.slug, s.name, s.name_en,
                    ss.store_lat, ss.store_lng, ss.gps_radius_meters, ss.gps_required, ss.staff_visible_tools,
                    us.visible_tools AS user_visible_tools
             FROM stores s
             LEFT JOIN shift_settings ss ON ss.store_id = s.id AND ss.tenant_id = s.tenant_id
             LEFT JOIN user_stores us ON us.store_id = s.id AND us.user_id = ?
             WHERE s.id IN (' . $placeholders . ') AND s.is_active = 1 ORDER BY s.name'
        );
        $stmt->execute(array_merge([$user['user_id']], $storeIds));
    } else {
        $stmt = null;
    }
}
$stores = $stmt ? $stmt->fetchAll() : [];

// L-16: プラン情報取得（plan_features テーブル未作成時はスキップ）
$plan = 'standard';
$features = [];
try {
    $plan = get_tenant_plan($pdo, $user['tenant_id']);
    $features = get_plan_features($pdo, $user['tenant_id']);
} catch (PDOException $e) {
    // マイグレーション未適用時はデフォルト値のまま
}

json_response([
    'user' => [
        'id'          => $user['user_id'],
        'email'       => $user['email'],
        'displayName' => $detail['display_name'] ?? '',
        'role'        => $user['role'],
        'tenantId'    => $user['tenant_id'],
        'tenantName'  => $detail['tenant_name'] ?? '',
    ],
    'stores' => array_map(function ($s) {
        return [
            'id'               => $s['id'],
            'slug'             => $s['slug'],
            'name'             => $s['name'],
            'nameEn'           => $s['name_en'] ?? '',
            'storeLat'         => $s['store_lat'] !== null ? (float)$s['store_lat'] : null,
            'storeLng'         => $s['store_lng'] !== null ? (float)$s['store_lng'] : null,
            'gpsRadiusMeters'  => $s['gps_radius_meters'] !== null ? (int)$s['gps_radius_meters'] : 200,
            'gpsRequired'      => $s['gps_required'] !== null ? (int)$s['gps_required'] : 0,
            'staffVisibleTools' => $s['staff_visible_tools'] ?? null,
            'userVisibleTools'  => $s['user_visible_tools'] ?? null,
        ];
    }, $stores),
    'plan' => $plan,
    'features' => $features,
]);
