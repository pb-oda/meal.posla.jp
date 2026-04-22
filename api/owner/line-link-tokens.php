<?php
/**
 * LINE ひも付け one-time token owner API (L-17 Phase 2A-2)
 *
 * POST   /api/owner/line-link-tokens.php
 *   body: { reservation_customer_id } または { customer_phone, store_id }
 *   -> { token, expires_at, customer: {...} }
 *
 * GET    /api/owner/line-link-tokens.php
 *   -> { active: [ {id, token, customer_name, customer_phone, store_name, expires_at, issued_at }, ... ] }
 *
 * DELETE /api/owner/line-link-tokens.php?id={token_id}
 *   -> { revoked: true, id }
 *
 * owner 専用。tenant 境界を認証済 session から取り、customer もテナント内で解決する。
 *
 * 顧客は店舗 LINE 公式アカウントに "LINK:XXXXXX" を送信することで連携完結する
 * (処理は api/line/webhook.php の message 分岐)。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/line-link-token.php';

require_method(['GET', 'POST', 'DELETE']);
$user = require_role('owner');
$pdo = get_db();

function _l17_token_resolve_customer($pdo, $tenantId, $data)
{
    $cid = isset($data['reservation_customer_id']) ? trim((string)$data['reservation_customer_id']) : '';
    if ($cid !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, tenant_id, store_id, customer_name, customer_phone
               FROM reservation_customers
              WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$cid, $tenantId]);
        return $stmt->fetch() ?: null;
    }

    $phone = isset($data['customer_phone']) ? trim((string)$data['customer_phone']) : '';
    $storeId = isset($data['store_id']) ? trim((string)$data['store_id']) : '';
    if ($phone === '' || $storeId === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT c.id, c.tenant_id, c.store_id, c.customer_name, c.customer_phone
           FROM reservation_customers c
           JOIN stores s ON s.id = c.store_id AND s.tenant_id = ?
          WHERE c.store_id = ?
            AND c.customer_phone = ?
          ORDER BY c.last_visit_at DESC, c.created_at DESC
          LIMIT 1'
    );
    $stmt->execute([$tenantId, $storeId, $phone]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!line_link_token_table_exists($pdo)) {
        json_response([
            'tokens' => [
                'migration_applied' => false,
                'active'            => [],
            ],
        ]);
    }
    $rows = line_link_token_list_active($pdo, $user['tenant_id'], 20);
    $out = [];
    for ($i = 0; $i < count($rows); $i++) {
        $r = $rows[$i];
        $out[] = [
            'id'                      => $r['id'],
            'token'                   => $r['token'],
            'reservation_customer_id' => $r['reservation_customer_id'],
            'customer_name'           => $r['customer_name'] ?? '',
            'customer_phone'          => $r['customer_phone'] ?? '',
            'store_id'                => $r['store_id'] ?? null,
            'store_name'              => $r['store_name'] ?? null,
            'issued_at'               => $r['issued_at'],
            'expires_at'              => $r['expires_at'],
        ];
    }
    json_response([
        'tokens' => [
            'migration_applied' => true,
            'active'            => $out,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!line_link_token_table_exists($pdo)) {
        json_error('NOT_CONFIGURED', 'LINE ひも付けトークン用マイグレーション (Phase 2A-2) が未適用です', 503);
    }
    $data = get_json_body();
    $customer = _l17_token_resolve_customer($pdo, $user['tenant_id'], is_array($data) ? $data : []);
    if (!$customer) {
        json_error('CUSTOMER_NOT_FOUND', '該当の顧客が見つかりません (reservation_customer_id または store_id + customer_phone を確認してください)', 404);
    }

    try {
        // require_auth() / require_role() の返り値 key は 'user_id' (auth.php L49)
        $tokenRow = line_link_token_issue(
            $pdo,
            $user['tenant_id'],
            $customer['store_id'],
            $customer['id'],
            isset($user['user_id']) ? $user['user_id'] : null,
            30
        );
    } catch (Exception $e) {
        error_log('[L-17 2A-2 token_issue] ' . $e->getMessage());
        json_error('TOKEN_ISSUE_FAILED', 'トークン発行に失敗しました', 500);
    }
    if (!$tokenRow) {
        json_error('TOKEN_ISSUE_FAILED', 'トークン発行に失敗しました (衝突が続きました)', 500);
    }

    json_response([
        'token' => [
            'id'         => $tokenRow['id'],
            'token'      => $tokenRow['token'],
            'expires_at' => $tokenRow['expires_at'],
            'issued_at'  => $tokenRow['issued_at'],
            'customer'   => [
                'id'             => $customer['id'],
                'customer_name'  => $customer['customer_name'] ?? '',
                'customer_phone' => $customer['customer_phone'] ?? '',
                'store_id'       => $customer['store_id'],
            ],
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!line_link_token_table_exists($pdo)) {
        json_error('NOT_CONFIGURED', 'LINE ひも付けトークン用マイグレーション (Phase 2A-2) が未適用です', 503);
    }
    $tokenId = trim((string)($_GET['id'] ?? ''));
    if ($tokenId === '') {
        json_error('MISSING_ID', '失効対象の id は必須です', 400);
    }

    $ok = line_link_token_revoke($pdo, $user['tenant_id'], $tokenId);
    if (!$ok) {
        json_error('REVOKE_FAILED', '失効できませんでした (既使用 / 既失効 / 存在しない可能性)', 404);
    }
    json_response(['revoked' => true, 'id' => $tokenId]);
}
