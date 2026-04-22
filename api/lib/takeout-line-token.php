<?php
/**
 * L-17 Phase 3A: テイクアウト one-time link token helper
 *
 * 顧客自身が (order_id + phone) で本人確認した後、one-time token を発行する。
 * token を店舗 LINE OA に "LINK:XXXXXX" で送信すると webhook が consume し、
 * takeout_order_line_links に linked 行を作る。
 *
 * reservation 側の line-link-token.php と同じパターン。ただし:
 *   - issue は customer-scoped (reservation は owner-scoped)
 *   - active token が既に存在する場合は新規発行せずに既存を返す (spam 対策)
 *
 * 呼び出し元:
 *   - api/customer/takeout-line-token.php (POST issue)
 *   - api/line/webhook.php (consume by token)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/takeout-line-link.php';

if (!function_exists('takeout_token_table_exists')) {
    function takeout_token_table_exists($pdo)
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $pdo->query('SELECT id FROM takeout_order_line_link_tokens LIMIT 0');
            $exists = true;
        } catch (PDOException $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('takeout_token_generate_code')) {
    function takeout_token_generate_code($length = 6)
    {
        // reservation 側と同じ文字セット (紛らわしい O/0/I/1/L/B/8 を除外)
        $alphabet = 'ACDEFGHJKMNPQRSTUVWXYZ23456789';
        $aLen = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $idx = random_int(0, $aLen - 1);
            $out .= $alphabet[$idx];
        }
        return $out;
    }
}

if (!function_exists('takeout_token_find_active_by_order')) {
    /**
     * (tenant_id, order_id) で現在有効な token を返す (spam 防止用、再利用判定)
     */
    function takeout_token_find_active_by_order($pdo, $tenantId, $orderId)
    {
        if (!takeout_token_table_exists($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM takeout_order_line_link_tokens
              WHERE tenant_id = ?
                AND order_id = ?
                AND used_at IS NULL
                AND revoked_at IS NULL
                AND expires_at > NOW()
              ORDER BY issued_at DESC
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $orderId]);
        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('takeout_token_issue')) {
    /**
     * 発行: active token があれば再利用、無ければ新規発行。
     * 最大 10 回リトライで衝突を吸収。
     */
    function takeout_token_issue($pdo, $tenantId, $storeId, $orderId, $issuedByPhone = null, $ttlMinutes = 30)
    {
        if (!takeout_token_table_exists($pdo)) {
            return null;
        }
        // 既存 active token があれば再利用
        $existing = takeout_token_find_active_by_order($pdo, $tenantId, $orderId);
        if ($existing) {
            return $existing;
        }

        $ttl = max(1, min(180, (int)$ttlMinutes));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl * 60);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $token = takeout_token_generate_code();
            try {
                $id = generate_uuid();
                $stmt = $pdo->prepare(
                    'INSERT INTO takeout_order_line_link_tokens
                        (id, tenant_id, store_id, order_id, token,
                         issued_at, expires_at, issued_by_phone)
                      VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)'
                );
                $stmt->execute([$id, $tenantId, $storeId, $orderId, $token, $expiresAt, $issuedByPhone]);
                $fetch = $pdo->prepare('SELECT * FROM takeout_order_line_link_tokens WHERE id = ?');
                $fetch->execute([$id]);
                return $fetch->fetch() ?: null;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                    continue;
                }
                throw $e;
            }
        }
        return null;
    }
}

if (!function_exists('takeout_token_find_active_by_token')) {
    function takeout_token_find_active_by_token($pdo, $tenantId, $token)
    {
        if (!takeout_token_table_exists($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM takeout_order_line_link_tokens
              WHERE tenant_id = ?
                AND token = ?
                AND used_at IS NULL
                AND revoked_at IS NULL
                AND expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$tenantId, strtoupper(trim($token))]);
        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('takeout_token_consume')) {
    /**
     * webhook で "LINK:XXXXXX" を受信した時に呼ぶ消費ロジック。
     * トランザクション下で used_at を単回セットし、line_link_upsert へ引き渡す。
     *
     * @return array ['success','error','link','token','order_id']
     */
    function takeout_token_consume($pdo, $tenantId, $token, $lineUserId, $profile = [])
    {
        if (!takeout_token_table_exists($pdo)) {
            return ['success' => false, 'error' => 'TOKEN_TABLE_MISSING', 'link' => null, 'token' => null, 'order_id' => null];
        }
        if (!is_string($token) || $token === '') {
            return ['success' => false, 'error' => 'EMPTY_TOKEN', 'link' => null, 'token' => null, 'order_id' => null];
        }
        $normToken = strtoupper(trim($token));

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT * FROM takeout_order_line_link_tokens
                  WHERE tenant_id = ? AND token = ?
                  FOR UPDATE'
            );
            $stmt->execute([$tenantId, $normToken]);
            $tokenRow = $stmt->fetch();
            if (!$tokenRow) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'TOKEN_NOT_FOUND', 'link' => null, 'token' => null, 'order_id' => null];
            }
            if ($tokenRow['used_at'] !== null) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'TOKEN_ALREADY_USED', 'link' => null, 'token' => $tokenRow, 'order_id' => null];
            }
            if ($tokenRow['revoked_at'] !== null) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'TOKEN_REVOKED', 'link' => null, 'token' => $tokenRow, 'order_id' => null];
            }
            if (strtotime($tokenRow['expires_at']) < time()) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'TOKEN_EXPIRED', 'link' => null, 'token' => $tokenRow, 'order_id' => null];
            }

            $pdo->prepare(
                'UPDATE takeout_order_line_link_tokens
                    SET used_at = CURRENT_TIMESTAMP,
                        used_by_line_user_id = ?
                  WHERE id = ?'
            )->execute([$lineUserId, $tokenRow['id']]);

            $link = takeout_link_upsert(
                $pdo,
                $tenantId,
                $tokenRow['store_id'],
                $tokenRow['order_id'],
                $lineUserId,
                $profile
            );

            $pdo->commit();

            return [
                'success'  => true,
                'error'    => null,
                'link'     => $link,
                'token'    => $tokenRow,
                'order_id' => $tokenRow['order_id'],
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[L-17 3A takeout_token_consume] ' . $e->getMessage());
            return ['success' => false, 'error' => 'TOKEN_CONSUME_FAILED', 'link' => null, 'token' => null, 'order_id' => null];
        }
    }
}

if (!function_exists('takeout_token_revoke')) {
    function takeout_token_revoke($pdo, $tenantId, $tokenId)
    {
        if (!takeout_token_table_exists($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare(
            'UPDATE takeout_order_line_link_tokens
                SET revoked_at = CURRENT_TIMESTAMP
              WHERE id = ? AND tenant_id = ?
                AND used_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([$tokenId, $tenantId]);
        return $stmt->rowCount() > 0;
    }
}
