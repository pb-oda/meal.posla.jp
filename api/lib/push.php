<?php
/**
 * Web Push 共通ヘルパ (PWA Phase 2b / 2026-04-20 実送信版)
 *
 * 提供 API:
 *   - get_push_vapid_public_key($pdo)           : フロント返却用の公開鍵取得
 *   - push_is_available($pdo)                   : VAPID 公開鍵設定済み判定
 *   - push_save_subscription($pdo, $params)     : 購読 UPSERT
 *   - push_disable_subscription($pdo, $hash, $userId?) : 購読 soft delete
 *   - push_send_to_store($pdo, $storeId, $type, $payload)            : 店舗全員
 *   - push_send_to_roles($pdo, $storeId, $roles, $type, $payload)    : 店舗 + ロール
 *   - push_send_to_user ($pdo, $userId,  $type, $payload)            : 単一ユーザー
 *
 * 送信実装:
 *   - VAPID JWT (ES256) 署名 → web-push-vapid.php
 *   - aes128gcm ペイロード暗号化 (RFC 8291) → web-push-encrypt.php
 *   - cURL POST + 410/404 で購読 soft delete → web-push-sender.php
 *
 * 設計方針:
 *   - 業務 API は push_send_to_*() の戻り値 (送信件数) を見ず、呼び捨てで使う
 *   - VAPID 未設定 / 暗号化失敗 / cURL エラーは全て内部で握りつぶし、業務処理を止めない
 *     (fail-open: 通知が飛ばないだけで会計や注文は通る)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/posla-settings.php';

if (!function_exists('get_push_vapid_public_key')) {
    /**
     * VAPID 公開鍵を取得する。未設定なら null。
     * フロントへ返すのはこの公開鍵のみ。秘密鍵は絶対に返さない。
     */
    function get_push_vapid_public_key($pdo)
    {
        return get_posla_setting($pdo, 'web_push_vapid_public');
    }
}

if (!function_exists('push_is_available')) {
    /**
     * Web Push が「設定上有効」か判定する。
     * 公開鍵 + 秘密鍵が両方設定されていれば true。
     * Phase 2a では公開鍵だけでも true 扱い (購読自体は始められる)。
     */
    function push_is_available($pdo)
    {
        $public = get_posla_setting($pdo, 'web_push_vapid_public');
        return $public !== null && $public !== '';
    }
}

if (!function_exists('_push_endpoint_hash')) {
    /**
     * endpoint URL の SHA-256 を取得する。
     * 一意制約 + ログ用 (生 endpoint をログに残すと個人端末特定可能になるため)。
     */
    function _push_endpoint_hash($endpoint)
    {
        return hash('sha256', (string)$endpoint);
    }
}

if (!function_exists('push_save_subscription')) {
    /**
     * 購読を保存 (UPSERT)。endpoint_hash 一意制約で重複防止。
     * 引数の tenant_id / user_id / role はサーバ側 (require_auth) から渡すこと。
     * クライアントから来た値は信用しない。
     *
     * @return string 保存した push_subscriptions.id
     */
    function push_save_subscription($pdo, $params)
    {
        $endpoint     = $params['endpoint'];
        $endpointHash = _push_endpoint_hash($endpoint);

        // 既存レコード確認
        $stmt = $pdo->prepare('SELECT id FROM push_subscriptions WHERE endpoint_hash = ? LIMIT 1');
        $stmt->execute([$endpointHash]);
        $row = $stmt->fetch();

        if ($row) {
            // 既存: 再有効化 + メタデータ更新
            $stmt = $pdo->prepare(
                'UPDATE push_subscriptions
                    SET tenant_id = ?, store_id = ?, user_id = ?, role = ?, scope = ?,
                        p256dh = ?, auth_key = ?, user_agent = ?, device_label = ?,
                        enabled = 1, revoked_at = NULL, last_seen_at = NOW()
                  WHERE id = ?'
            );
            $stmt->execute([
                $params['tenant_id'], $params['store_id'], $params['user_id'],
                $params['role'], $params['scope'],
                $params['p256dh'], $params['auth_key'],
                mb_substr((string)($params['user_agent'] ?? ''), 0, 255),
                mb_substr((string)($params['device_label'] ?? ''), 0, 100),
                $row['id']
            ]);
            return $row['id'];
        }

        // 新規
        $id = generate_uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO push_subscriptions
              (id, tenant_id, store_id, user_id, role, scope,
               endpoint, endpoint_hash, p256dh, auth_key, user_agent, device_label,
               enabled, created_at, updated_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())'
        );
        $stmt->execute([
            $id, $params['tenant_id'], $params['store_id'], $params['user_id'],
            $params['role'], $params['scope'],
            $endpoint, $endpointHash, $params['p256dh'], $params['auth_key'],
            mb_substr((string)($params['user_agent'] ?? ''), 0, 255),
            mb_substr((string)($params['device_label'] ?? ''), 0, 100)
        ]);
        return $id;
    }
}

if (!function_exists('push_disable_subscription')) {
    /**
     * 購読を無効化 (soft delete)。endpoint_hash で対象を特定。
     * 自分の購読だけ無効化できるよう呼び出し側で user_id 一致を検証すること。
     */
    function push_disable_subscription($pdo, $endpointHash, $userId = null)
    {
        if ($userId !== null) {
            $stmt = $pdo->prepare(
                'UPDATE push_subscriptions
                    SET enabled = 0, revoked_at = NOW()
                  WHERE endpoint_hash = ? AND user_id = ?'
            );
            $stmt->execute([$endpointHash, $userId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE push_subscriptions
                    SET enabled = 0, revoked_at = NOW()
                  WHERE endpoint_hash = ?'
            );
            $stmt->execute([$endpointHash]);
        }
        return $stmt->rowCount();
    }
}

// ============================================================
// 送信関数 (Phase 2b: 実送信)
// VAPID JWT + AES-128-GCM 暗号化 + cURL POST で push エンドポイントへ配信
// ============================================================

require_once __DIR__ . '/web-push-sender.php';

if (!function_exists('_push_get_vapid_keys')) {
    /**
     * VAPID 公開鍵 + 秘密鍵 PEM を取得する。両方揃っていなければ null を返す。
     */
    function _push_get_vapid_keys($pdo)
    {
        $public = get_posla_setting($pdo, 'web_push_vapid_public');
        $privatePem = get_posla_setting($pdo, 'web_push_vapid_private_pem');
        if (!$public || !$privatePem) return null;
        return ['public' => $public, 'private_pem' => $privatePem];
    }
}

if (!function_exists('_push_build_payload')) {
    /**
     * 通知ペイロードを SW 側 push リスナが期待する形に正規化する。
     * SW は { type, title, body, url, tag, badge?, ts? } を読む。
     */
    function _push_build_payload($type, $payload)
    {
        $now = (int)(microtime(true) * 1000);
        return [
            'type'  => (string)$type,
            'title' => isset($payload['title']) ? (string)$payload['title'] : 'POSLA',
            'body'  => isset($payload['body'])  ? (string)$payload['body']  : '',
            'url'   => isset($payload['url'])   ? (string)$payload['url']   : '/',
            'tag'   => isset($payload['tag'])   ? (string)$payload['tag']   : $type,
            'ts'    => isset($payload['ts'])    ? (int)$payload['ts']       : $now,
        ];
    }
}

if (!function_exists('_push_get_tenant_id_for_store')) {
    /**
     * store_id から tenant_id を引く。
     * push_send_to_*() の WHERE 句に tenant_id を加えてマルチテナント境界を二重化するため。
     * 見つからなければ null を返す (送信側で 0 件にフォールバック)。
     */
    function _push_get_tenant_id_for_store($pdo, $storeId)
    {
        if (!$storeId) return null;
        $stmt = $pdo->prepare('SELECT tenant_id FROM stores WHERE id = ? LIMIT 1');
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();
        return $row ? $row['tenant_id'] : null;
    }
}

if (!function_exists('_push_get_tenant_id_for_user')) {
    /**
     * user_id から tenant_id を引く (push_send_to_user 用)。
     */
    function _push_get_tenant_id_for_user($pdo, $userId)
    {
        if (!$userId) return null;
        $stmt = $pdo->prepare('SELECT tenant_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? $row['tenant_id'] : null;
    }
}

if (!function_exists('_push_filter_subs_by_rate_limit')) {
    /**
     * レート制限: 直近 $windowSec 秒以内に同 user_id + type + tag で 2xx 送信済みの
     * user を除外した購読配列を返す (Phase 2b レビュー対応: 連打防止).
     *
     *   - tag が空文字 / null の場合はレート制限しない (テスト送信などで全件通す)
     *   - push_send_log テーブル未作成環境では何もフィルタしない (migration 未適用時の互換性)
     *   - user_id ごとに判定するので、同じユーザーが複数端末購読していても 1 ユーザー扱い
     */
    function _push_filter_subs_by_rate_limit($pdo, $subs, $type, $tag, $windowSec = 60)
    {
        if (!$subs || !$tag || $tag === '') return $subs;

        $userIds = [];
        foreach ($subs as $s) {
            if (!empty($s['user_id'])) $userIds[$s['user_id']] = true;
        }
        $userIds = array_keys($userIds);
        if (!$userIds) return $subs;

        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $sql = 'SELECT DISTINCT user_id
                      FROM push_send_log
                     WHERE type = ? AND tag = ?
                       AND status_code >= 200 AND status_code < 300
                       AND sent_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                       AND user_id IN (' . $placeholders . ')';
            $params = array_merge([$type, $tag, (int)$windowSec], $userIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $recent = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['user_id'])) $recent[$r['user_id']] = true;
            }
            if (!$recent) return $subs;
            $filtered = [];
            foreach ($subs as $s) {
                if (empty($s['user_id']) || !isset($recent[$s['user_id']])) $filtered[] = $s;
            }
            return $filtered;
        } catch (\Throwable $e) {
            // push_send_log 未作成環境: レート制限なしで通す
            return $subs;
        }
    }
}

if (!function_exists('_push_send_to_subscriptions')) {
    /**
     * 取得済みの購読配列に対して送信する内部共通関数。
     * Throwable はキャッチして 0 件を返す (業務 API を巻き込まない)。
     */
    function _push_send_to_subscriptions($pdo, $subs, $type, $payload)
    {
        if (!$subs || count($subs) === 0) return 0;
        $vapid = _push_get_vapid_keys($pdo);
        if (!$vapid) return 0;  // VAPID 未設定 = サイレント無効

        $tag = isset($payload['tag']) ? (string)$payload['tag'] : null;
        // Phase 2b レビュー対応: 同 user+type+tag で 2xx が直近 60 秒以内にあれば skip
        $subs = _push_filter_subs_by_rate_limit($pdo, $subs, $type, $tag, 60);
        if (!$subs) return 0;

        try {
            $body = _push_build_payload($type, $payload);
            $r = web_push_send_batch($pdo, $subs, $body, $vapid['public'], $vapid['private_pem'], 'mailto:contact@posla.jp', $type, $tag);
            return (int)$r['sent'];
        } catch (\Throwable $e) {
            error_log('[push_send] failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('push_send_to_store')) {
    /**
     * 店舗単位の送信。enabled=1 の購読だけが対象。
     *
     * @param string $storeId
     * @param string $type    'call_alert' / 'low_rating' / 'important_error'
     * @param array  $payload { title, body, url, tag, ... }
     * @return int 送信した件数
     */
    function push_send_to_store($pdo, $storeId, $type, $payload)
    {
        $tenantId = _push_get_tenant_id_for_store($pdo, $storeId);
        if (!$tenantId) return 0;
        $stmt = $pdo->prepare(
            'SELECT id, tenant_id, store_id, user_id, endpoint, endpoint_hash, p256dh, auth_key
               FROM push_subscriptions
              WHERE store_id = ? AND tenant_id = ? AND enabled = 1'
        );
        $stmt->execute([$storeId, $tenantId]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return _push_send_to_subscriptions($pdo, $subs, $type, $payload);
    }
}

if (!function_exists('push_send_to_roles')) {
    /**
     * 店舗 + ロール条件の送信。例: manager / owner だけに通知。
     *
     * 拾う購読:
     *   (a) tenant_id = $tenantId AND store_id = $storeId AND role IN ($roles)
     *       → 当該店舗に紐付いた manager / owner 等
     *   (b) $roles に 'owner' を含む場合のみ追加で:
     *       tenant_id = $tenantId AND scope = 'owner' AND role = 'owner' AND store_id IS NULL
     *       → owner-dashboard で全店共通通知を選んだ owner ユーザー (店舗未紐付け)
     *
     * 設計理由 (Phase 2b 修正 #2):
     *   owner-dashboard は store_id NULL で購読保存する仕様 (= 全店横断通知を希望)。
     *   旧実装は store_id = ? のみで AND していたため owner NULL 購読が low_rating の
     *   送信対象に入らず、通知が届かなかった。本店舗の出来事を全店オーナーに通知する
     *   のが本来の意図。
     *
     * @param string       $storeId
     * @param array|string $roles  ['manager','owner'] など
     */
    function push_send_to_roles($pdo, $storeId, $roles, $type, $payload)
    {
        if (!is_array($roles)) $roles = [$roles];
        $roles = array_values(array_filter($roles, function ($r) {
            return in_array($r, ['owner', 'manager', 'staff', 'device'], true);
        }));
        if (count($roles) === 0) return 0;

        $tenantId = _push_get_tenant_id_for_store($pdo, $storeId);
        if (!$tenantId) return 0;

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $includeOwnerNull = in_array('owner', $roles, true);

        $sql = 'SELECT id, tenant_id, store_id, user_id, endpoint, endpoint_hash, p256dh, auth_key
                  FROM push_subscriptions
                 WHERE tenant_id = ? AND enabled = 1
                   AND (
                         (store_id = ? AND role IN (' . $placeholders . '))';
        $params = array_merge([$tenantId, $storeId], $roles);

        if ($includeOwnerNull) {
            $sql .= '
                      OR (store_id IS NULL AND role = ? AND scope = ?)';
            $params[] = 'owner';
            $params[] = 'owner';
        }

        $sql .= '
                   )';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return _push_send_to_subscriptions($pdo, $subs, $type, $payload);
    }
}

if (!function_exists('push_send_to_user')) {
    /**
     * 特定ユーザーへ送信 (テスト通知 / 個別宛て通知用)。
     */
    function push_send_to_user($pdo, $userId, $type, $payload)
    {
        $tenantId = _push_get_tenant_id_for_user($pdo, $userId);
        if (!$tenantId) return 0;
        $stmt = $pdo->prepare(
            'SELECT id, tenant_id, store_id, user_id, endpoint, endpoint_hash, p256dh, auth_key
               FROM push_subscriptions
              WHERE user_id = ? AND tenant_id = ? AND enabled = 1'
        );
        $stmt->execute([$userId, $tenantId]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return _push_send_to_subscriptions($pdo, $subs, $type, $payload);
    }
}
