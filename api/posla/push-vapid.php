<?php
/**
 * POSLA 管理画面 PWA/Push タブ用情報 API (Phase 2b 拡張 / 2026-04-20)
 *
 * GET /api/posla/push-vapid.php
 *   POSLA 管理者専用 (require_posla_admin)。
 *
 * 返すもの (読み取り専用・情報表示のみ):
 *   - VAPID 公開鍵全文 (フロント露出は問題ない)
 *   - 秘密鍵は「設定有無」と「文字数」のみ (本体は絶対に返さない)
 *   - push_subscriptions の全体統計 (total / enabled / tenant 数 / role 別)
 *   - push_send_log の直近 24h 統計 (total / 2xx / gone / 5xx)
 *   - 直近 24h の type 別件数
 *
 * 実装しない (設計上除外):
 *   - 秘密鍵ダウンロード API — CLI + /tmp 経由 + 金庫運用を徹底するため
 *   - 鍵ローテーション API — 誤操作で全購読無効化するリスクが大きすぎる。CLI + SQL のみ
 *   - テスト送信 API — POSLA 管理者は users.role にない独自認証系で SW 非対応のため自分宛テストが成立しない
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/posla-settings.php';

require_method(['GET']);
require_posla_admin();
$pdo = get_db();

$publicKey = get_posla_setting($pdo, 'web_push_vapid_public');
$privatePem = get_posla_setting($pdo, 'web_push_vapid_private_pem');

// 購読統計
$subsStats = ['total' => 0, 'enabled' => 0, 'tenants_with_subs' => 0, 'by_role' => []];
try {
    $stmt = $pdo->query(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS enabled
           FROM push_subscriptions'
    );
    $row = $stmt->fetch();
    if ($row) {
        $subsStats['total'] = (int)$row['total'];
        $subsStats['enabled'] = (int)$row['enabled'];
    }
    $t = $pdo->query('SELECT COUNT(DISTINCT tenant_id) FROM push_subscriptions WHERE enabled = 1');
    $subsStats['tenants_with_subs'] = (int)$t->fetchColumn();
    $r = $pdo->query("SELECT role, COUNT(*) AS cnt FROM push_subscriptions WHERE enabled = 1 GROUP BY role");
    foreach ($r->fetchAll() as $rr) {
        $subsStats['by_role'][(string)$rr['role']] = (int)$rr['cnt'];
    }
} catch (PDOException $e) { /* push_subscriptions 未存在時は 0 のまま */ }

// 送信ログ統計 (直近 24 時間)
$sendStats = ['total' => 0, 'sent_ok' => 0, 'gone_disabled' => 0, 'transient' => 0, 'other_error' => 0, 'by_type' => []];
try {
    $stmt = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) AS sent_ok,
                SUM(CASE WHEN status_code IN (404, 410) THEN 1 ELSE 0 END) AS gone_disabled,
                SUM(CASE WHEN status_code = 429 OR status_code >= 500 THEN 1 ELSE 0 END) AS transient,
                SUM(CASE WHEN status_code = 401 OR status_code = 403 OR status_code = 413 THEN 1 ELSE 0 END) AS other_error
           FROM push_send_log
          WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $row = $stmt->fetch();
    if ($row) {
        foreach (['total', 'sent_ok', 'gone_disabled', 'transient', 'other_error'] as $k) {
            $sendStats[$k] = (int)$row[$k];
        }
    }
    $r = $pdo->query(
        "SELECT type, COUNT(*) AS cnt
           FROM push_send_log
          WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          GROUP BY type
          ORDER BY cnt DESC"
    );
    foreach ($r->fetchAll() as $rr) {
        $sendStats['by_type'][(string)$rr['type']] = (int)$rr['cnt'];
    }
} catch (PDOException $e) { /* push_send_log 未作成環境は 0 のまま */ }

json_response([
    'vapid' => [
        'public_key'         => $publicKey,
        'public_key_length'  => $publicKey ? strlen($publicKey) : 0,
        'private_pem_set'    => !!$privatePem,
        'private_pem_length' => $privatePem ? strlen($privatePem) : 0,
        'available'          => $publicKey && $privatePem ? true : false,
    ],
    'subscriptions' => $subsStats,
    'recent_24h'    => $sendStats,
]);
