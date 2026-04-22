<?php
/**
 * Web Push 設定取得 API (PWA Phase 2a)
 *
 * GET /api/push/config.php
 *   → { ok: true, data: { available, vapidPublicKey } }
 *
 * 認証必須。VAPID 公開鍵だけを返す (秘密鍵は絶対に返さない)。
 * available=false のときフロントは「Push 未設定」と判定して購読 UI を案内に切替える。
 *
 * 顧客画面 (public/customer/) からは呼び出されない。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/push.php';

require_method(['GET']);
$user = require_auth();
$pdo = get_db();

$publicKey = get_push_vapid_public_key($pdo);

json_response([
    'available'      => $publicKey !== null && $publicKey !== '',
    'vapidPublicKey' => $publicKey,   // 未設定時は null
]);
