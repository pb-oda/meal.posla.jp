<?php
/**
 * LINE ひも付け one-time token helper (L-17 Phase 2A-2)
 *
 * owner が reservation_customer に対して発行する short code を生成・検証・消費。
 *
 * - token は 6 文字 A-Z0-9 から紛らわしい文字 (O/0/I/1/L) を除外して生成
 * - UNIQUE 衝突時はリトライ (最大 10 回)
 * - 30 分デフォルト TTL
 * - 単回使用 (consume 時に used_at / used_by_line_user_id をセット)
 * - expire された token は活きた結果から除外されるだけで物理削除はしない (監査)
 *
 * 呼び出し元:
 *   - api/owner/line-link-tokens.php (issue / revoke / list_active)
 *   - api/line/webhook.php (consume_by_token 経由で line_link_upsert)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/line-link.php';

if (!function_exists('line_link_token_table_exists')) {
    function line_link_token_table_exists($pdo)
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $pdo->query('SELECT id FROM reservation_customer_line_link_tokens LIMIT 0');
            $exists = true;
        } catch (PDOException $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('line_link_token_generate_code')) {
    /**
     * 6 文字の短コード (A-Z 0-9 から紛らわしい O,0,I,1,L,B,8 を除外)
     */
    function line_link_token_generate_code($length = 6)
    {
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

if (!function_exists('line_link_token_issue')) {
    /**
     * 新規 token を発行。最大 10 回まで衝突リトライ。成功時は token 行を返す。
     *
     * @return array|null ['id','token','expires_at',...] or null if table missing
     */
    function line_link_token_issue($pdo, $tenantId, $storeId, $reservationCustomerId, $createdByUserId = null, $ttlMinutes = 30)
    {
        if (!line_link_token_table_exists($pdo)) {
            return null;
        }
        $ttl = max(1, min(180, (int)$ttlMinutes));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl * 60);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $token = line_link_token_generate_code();
            try {
                $id = generate_uuid();
                $stmt = $pdo->prepare(
                    'INSERT INTO reservation_customer_line_link_tokens
                        (id, tenant_id, store_id, reservation_customer_id, token,
                         issued_at, expires_at, created_by_user_id)
                      VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)'
                );
                $stmt->execute([
                    $id, $tenantId, $storeId, $reservationCustomerId, $token,
                    $expiresAt, $createdByUserId,
                ]);
                // fetch back
                $fetch = $pdo->prepare('SELECT * FROM reservation_customer_line_link_tokens WHERE id = ?');
                $fetch->execute([$id]);
                return $fetch->fetch() ?: null;
            } catch (PDOException $e) {
                // UNIQUE 衝突なら retry、それ以外はエラー伝播
                if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                    continue;
                }
                throw $e;
            }
        }
        return null;
    }
}

if (!function_exists('line_link_token_find_active_by_token')) {
    /**
     * tenant 内で有効な (未使用 / 未失効 / 未取消) token 行を引く。
     * webhook から "LINK:XXXXXX" を受け取った時にこれを呼ぶ。
     */
    function line_link_token_find_active_by_token($pdo, $tenantId, $token)
    {
        if (!line_link_token_table_exists($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM reservation_customer_line_link_tokens
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

if (!function_exists('line_link_token_consume')) {
    /**
     * token を単回消費して line_link_upsert へ引き渡す。戻り値:
     *   ['success'=>bool,'error'=>?,'link'=>?row,'token'=>?row,'customer_id'=>?]
     *
     * トランザクションを貼り、race condition 下でも used_at の単回セットを保証する。
     * 失敗時は何もコミットしない (既存リンクも触らない)。
     */
    function line_link_token_consume($pdo, $tenantId, $token, $lineUserId, $profile = [])
    {
        if (!line_link_token_table_exists($pdo)) {
            return ['success' => false, 'error' => 'TOKEN_TABLE_MISSING', 'link' => null, 'token' => null, 'customer_id' => null];
        }
        if (!is_string($token) || $token === '') {
            return ['success' => false, 'error' => 'EMPTY_TOKEN', 'link' => null, 'token' => null, 'customer_id' => null];
        }
        $normToken = strtoupper(trim($token));

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT * FROM reservation_customer_line_link_tokens
                  WHERE tenant_id = ? AND token = ?
                  FOR UPDATE'
            );
            $stmt->execute([$tenantId, $normToken]);
            $tokenRow = $stmt->fetch();
            if (!$tokenRow) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'TOKEN_NOT_FOUND', 'link' => null, 'token' => null, 'customer_id' => null];
            }
            if ($tokenRow['used_at'] !== null) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'TOKEN_ALREADY_USED', 'link' => null, 'token' => $tokenRow, 'customer_id' => null];
            }
            if ($tokenRow['revoked_at'] !== null) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'TOKEN_REVOKED', 'link' => null, 'token' => $tokenRow, 'customer_id' => null];
            }
            if (strtotime($tokenRow['expires_at']) < time()) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'TOKEN_EXPIRED', 'link' => null, 'token' => $tokenRow, 'customer_id' => null];
            }

            // consume (mark used + set used_by)
            $pdo->prepare(
                'UPDATE reservation_customer_line_link_tokens
                    SET used_at = CURRENT_TIMESTAMP,
                        used_by_line_user_id = ?
                  WHERE id = ?'
            )->execute([$lineUserId, $tokenRow['id']]);

            // upsert link (Phase 2A-1 の invariant (1 customer 1 active link) は upsert 側で保証)
            $link = line_link_upsert(
                $pdo,
                $tenantId,
                $tokenRow['store_id'],
                $tokenRow['reservation_customer_id'],
                $lineUserId,
                $profile
            );

            $pdo->commit();

            return [
                'success'     => true,
                'error'       => null,
                'link'        => $link,
                'token'       => $tokenRow,
                'customer_id' => $tokenRow['reservation_customer_id'],
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[L-17 2A-2 token_consume] ' . $e->getMessage());
            return ['success' => false, 'error' => 'TOKEN_CONSUME_FAILED', 'link' => null, 'token' => null, 'customer_id' => null];
        }
    }
}

if (!function_exists('line_link_token_revoke')) {
    /**
     * owner から明示的に token を失効させる。既使用 / 既失効なら冪等に no-op。
     */
    function line_link_token_revoke($pdo, $tenantId, $tokenId)
    {
        if (!line_link_token_table_exists($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare(
            'UPDATE reservation_customer_line_link_tokens
                SET revoked_at = CURRENT_TIMESTAMP
              WHERE id = ? AND tenant_id = ?
                AND used_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([$tokenId, $tenantId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('line_link_token_list_active')) {
    /**
     * tenant 内で現在有効な token 一覧。owner UI 用。
     */
    function line_link_token_list_active($pdo, $tenantId, $limit = 20)
    {
        if (!line_link_token_table_exists($pdo)) {
            return [];
        }
        $limit = max(1, min(100, (int)$limit));
        $stmt = $pdo->prepare(
            'SELECT
               t.id, t.token, t.reservation_customer_id, t.store_id,
               t.issued_at, t.expires_at, t.used_at, t.revoked_at,
               c.customer_name, c.customer_phone, s.name AS store_name
             FROM reservation_customer_line_link_tokens t
             LEFT JOIN reservation_customers c ON c.id = t.reservation_customer_id
             LEFT JOIN stores s ON s.id = t.store_id
             WHERE t.tenant_id = ?
               AND t.used_at IS NULL
               AND t.revoked_at IS NULL
               AND t.expires_at > NOW()
             ORDER BY t.issued_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll() ?: [];
    }
}
