<?php
/**
 * Web Push 購読登録 API (PWA Phase 2a)
 *
 * POST /api/push/subscribe.php
 *   Body: {
 *     scope:        'admin' | 'owner' | 'kds' | 'cashier' | 'handy' | 'pos-register',
 *     subscription: { endpoint, keys: { p256dh, auth } },   // PushSubscription.toJSON() そのまま
 *     store_id:     (任意) 業務端末側で使う店舗紐付け,
 *     device_label: (任意) スタッフが付けるラベル (例: 厨房 KDS タブレット)
 *   }
 *
 * セキュリティ:
 *   - 認証必須 (require_auth)
 *   - tenant_id / user_id / role はサーバ側セッションから確定 (クライアント値を信用しない)
 *   - store_id はクライアントから受け取るが require_store_access で検証
 *   - scope は許可リストで検証
 *
 * 顧客画面からは呼び出されない (セッション必須・/api/customer/ ではない)。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/push.php';

require_method(['POST']);
$user = require_auth();
$pdo = get_db();

$data = get_json_body();

$scope        = isset($data['scope']) ? (string)$data['scope'] : '';
$subscription = isset($data['subscription']) && is_array($data['subscription']) ? $data['subscription'] : null;
$storeIdIn    = isset($data['store_id']) ? (string)$data['store_id'] : '';
$deviceLabel  = isset($data['device_label']) ? (string)$data['device_label'] : '';

// scope 許可リスト検証
$allowedScopes = ['admin', 'owner', 'kds', 'cashier', 'handy', 'pos-register'];
if (!in_array($scope, $allowedScopes, true)) {
    json_error('INVALID_SCOPE', 'scope の指定が正しくありません', 400);
}

if (!$subscription || empty($subscription['endpoint']) || empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
    json_error('MISSING_FIELDS', 'subscription / endpoint / keys は必須です', 400);
}

// scope=owner は owner ロール限定 (manager/staff が POST で scope=owner を詐称できないようにする)
//   旧実装では $scope==='owner' を role 不問で許容していたため、非 owner が
//   store_id=NULL の購読を作れる権限漏れがあった (Phase 2b 修正 #2 で塞ぐ)。
if ($scope === 'owner' && $user['role'] !== 'owner') {
    json_error('FORBIDDEN_SCOPE', 'scope=owner は owner ロールのみ指定可能です', 403);
}

// store_id の検証
//   許容: scope=owner (= owner ロール) のときだけ store_id NULL OK = 全店共通通知
//          (push_send_to_roles で同 tenant の owner NULL 購読を OR 条件で拾う)
//   それ以外 — owner ロールでも scope=admin/handy/kds 等で購読する場合 — は store_id 必須
//   店舗紐付けがないと push_send_to_store() の WHERE store_id = ? に乗らず通知が届かない
$storeId = null;
if ($storeIdIn !== '') {
    require_store_access($storeIdIn);   // 失敗時は内部で 403
    $storeId = $storeIdIn;
}
if ($storeId === null && $scope !== 'owner') {
    json_error('STORE_REQUIRED', 'store_id は必須です (scope=owner のみ NULL 許容)', 400);
}

// 文字長の安全側カット
$endpoint = mb_substr((string)$subscription['endpoint'], 0, 2048);
$p256dh   = mb_substr((string)$subscription['keys']['p256dh'], 0, 255);
$authKey  = mb_substr((string)$subscription['keys']['auth'], 0, 64);
$ua       = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

try {
    $id = push_save_subscription($pdo, [
        'tenant_id'    => $user['tenant_id'],
        'store_id'     => $storeId,
        'user_id'      => $user['user_id'],
        'role'         => $user['role'],
        'scope'        => $scope,
        'endpoint'     => $endpoint,
        'p256dh'       => $p256dh,
        'auth_key'     => $authKey,
        'user_agent'   => $ua,
        'device_label' => $deviceLabel,
    ]);
} catch (PDOException $e) {
    json_error('DB_ERROR', '購読の保存に失敗しました (push_subscriptions テーブルを確認してください)', 500);
}

json_response([
    'id'      => $id,
    'enabled' => true,
], 201);
