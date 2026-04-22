<?php
/**
 * LINE 顧客ひも付け owner API (L-17 Phase 2A-1)
 *
 * GET    /api/owner/line-customer-links.php
 *    → summary (linked / unlinked 件数、最終 interaction) + recent 20 links
 *
 * DELETE /api/owner/line-customer-links.php?id={link_id}
 *    → 該当 link を link_status='unlinked' に更新 (tenant 境界二重チェック)
 *
 * owner 専用。tenant_id は認証済 user セッションから取得。
 * migration 未適用時は migration_applied=false を返し UI を壊さない。
 *
 * Phase 2A-1 では POST (手動リンク作成) を意図的に実装しない。
 * - 誤リンクの影響が大きい (別人宛の LINE 通知が飛ぶ)
 * - 安全な作成は Account Linking (one-time token / nonce) が必要
 * - それは Phase 2A-2 で導入予定
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/line-link.php';

require_method(['GET', 'DELETE']);
$user = require_role('owner');
$pdo = get_db();

function mask_line_user_id($value)
{
    if (!$value) {
        return null;
    }
    $len = strlen($value);
    if ($len <= 6) {
        return str_repeat('●', $len);
    }
    return substr($value, 0, 6) . str_repeat('●', 6) . substr($value, -2);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!line_link_table_exists($pdo)) {
        json_response([
            'line_links' => [
                'migration_applied'   => false,
                'linked_count'        => 0,
                'unlinked_count'      => 0,
                'last_interaction_at' => null,
                'last_linked_at'      => null,
                'recent'              => [],
            ],
        ]);
    }

    $summary = line_link_summary($pdo, $user['tenant_id']);
    $rows = line_link_recent_list($pdo, $user['tenant_id'], 20);

    $recent = [];
    for ($i = 0; $i < count($rows); $i++) {
        $row = $rows[$i];
        $recent[] = [
            'id'                      => $row['id'],
            'store_id'                => $row['store_id'] ?? null,
            'store_name'              => $row['store_name'] ?? null,
            'reservation_customer_id' => $row['reservation_customer_id'],
            'customer_name'           => $row['customer_name'] ?? '',
            'customer_phone'          => $row['customer_phone'] ?? '',
            'line_user_id_masked'     => mask_line_user_id($row['line_user_id']),
            'display_name'            => $row['display_name'] ?? '',
            'link_status'             => $row['link_status'],
            'linked_at'               => $row['linked_at'],
            'unlinked_at'             => $row['unlinked_at'],
            'last_interaction_at'     => $row['last_interaction_at'],
        ];
    }

    json_response([
        'line_links' => array_merge($summary, ['recent' => $recent]),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!line_link_table_exists($pdo)) {
        json_error('NOT_CONFIGURED', 'LINE連携用マイグレーション (Phase 2A-1) が未適用です', 503);
    }

    $linkId = trim((string)($_GET['id'] ?? ''));
    if ($linkId === '') {
        json_error('MISSING_ID', '解除対象の link id は必須です', 400);
    }

    // tenant 境界二重チェック: tenant_id が一致する linked 行のみ更新
    $stmt = $pdo->prepare(
        'SELECT id, link_status FROM reservation_customer_line_links
           WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$linkId, $user['tenant_id']]);
    $existing = $stmt->fetch();
    if (!$existing) {
        json_error('NOT_FOUND', '対象の link が見つかりません', 404);
    }
    if ($existing['link_status'] !== 'linked') {
        json_error('ALREADY_UNLINKED', 'この link は既に解除済です', 409);
    }

    $ok = line_link_unlink_by_id($pdo, $user['tenant_id'], $linkId);
    if (!$ok) {
        json_error('UNLINK_FAILED', '解除に失敗しました', 500);
    }

    json_response([
        'unlinked' => true,
        'id'       => $linkId,
    ]);
}
