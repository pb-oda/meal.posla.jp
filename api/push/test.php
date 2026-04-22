<?php
/**
 * Web Push テスト送信 API (PWA Phase 2b)
 *
 * POST /api/push/test.php
 *   Body: {
 *     target?: 'self' | 'store',     // 既定: 'self' (自分の購読端末だけ)
 *     store_id?: string,             // target=store のとき必須
 *     title?:  string,               // 任意 (既定: "POSLA テスト通知")
 *     body?:   string                // 任意
 *   }
 *
 * 用途:
 *   - VAPID 鍵を投入した直後の動作確認
 *   - 通知許可後にスタッフ自身が「届くか」を試す
 *
 * 権限:
 *   - target=self : require_auth (誰でも自分の端末には飛ばせる)
 *   - target=store: require_role(['manager','owner']) + require_store_access
 *
 * レスポンス:
 *   { sent: int, available: bool }
 *     - available=false なら VAPID 未設定 (POSLA 管理側でキー投入が必要)
 *     - sent=0 / available=true なら購読そのものが無い (端末側で通知許可していない)
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/push.php';

require_method(['POST']);
$user = require_auth();
$pdo = get_db();

$data    = get_json_body();
$target  = isset($data['target']) ? (string)$data['target'] : 'self';
$storeId = isset($data['store_id']) ? (string)$data['store_id'] : '';
$title   = isset($data['title']) && $data['title'] !== '' ? mb_substr((string)$data['title'], 0, 80) : 'POSLA テスト通知';
$body    = isset($data['body'])  && $data['body']  !== '' ? mb_substr((string)$data['body'],  0, 200) : 'これはテスト通知です。届いていれば設定 OK です。';

if (!in_array($target, ['self', 'store'], true)) {
    json_error('INVALID_TARGET', 'target は self / store のいずれかです', 400);
}

if (!push_is_available($pdo)) {
    json_response(['sent' => 0, 'available' => false]);
}

$payload = [
    'title' => $title,
    'body'  => $body,
    'url'   => '/',
    'tag'   => 'push_test',
];

$sent = 0;
if ($target === 'self') {
    $sent = push_send_to_user($pdo, $user['user_id'], 'push_test', $payload);
} else {
    if ($storeId === '') {
        json_error('MISSING_STORE', 'target=store のときは store_id が必須です', 400);
    }
    require_store_access($storeId);
    if (!in_array($user['role'], ['manager', 'owner'], true)) {
        json_error('FORBIDDEN', 'store 全体テスト送信は manager / owner のみ可能です', 403);
    }
    $sent = push_send_to_store($pdo, $storeId, 'push_test', $payload);
}

json_response(['sent' => (int)$sent, 'available' => true]);
