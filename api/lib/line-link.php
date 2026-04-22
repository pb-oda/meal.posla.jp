<?php
/**
 * LINE 顧客ひも付け helper (L-17 Phase 2A-1)
 *
 * reservation_customer_line_links テーブルへの read / write を集約。
 * migration 未適用時は table_exists=false を返し、呼び出し側で controlled
 * response を返せるようにする。
 *
 * このファイルは予約 / 通知 / 既存 API からはまだ呼ばれない。
 * - api/line/webhook.php の dispatch_events から touch_interaction を呼ぶ
 * - api/owner/line-customer-links.php の GET / DELETE から read / unlink を呼ぶ
 *
 * 将来 Phase 2B (予約完了通知接続) で reservation-notifier.php から
 * line_link_get_by_customer() を呼んで送信先 LINE user ID を解決する。
 */

require_once __DIR__ . '/db.php';

function line_link_table_exists($pdo)
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $pdo->query('SELECT id FROM reservation_customer_line_links LIMIT 0');
        $exists = true;
    } catch (PDOException $e) {
        $exists = false;
    }
    return $exists;
}

/**
 * tenant 内の line_user_id で link を引く (link_status 問わず、現在の唯一の行)
 * UNIQUE (tenant_id, line_user_id) 保証済のため最大 1 行
 */
function line_link_get_by_line_user($pdo, $tenantId, $lineUserId)
{
    if (!line_link_table_exists($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM reservation_customer_line_links
           WHERE tenant_id = ? AND line_user_id = ?
           LIMIT 1'
    );
    $stmt->execute([$tenantId, $lineUserId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * tenant 内の reservation_customer_id で link を引く。
 * 将来 Phase 2B で notifier が「この予約客に LINE 通知を送るべき line_user_id
 * はあるか」を判定するための主ルート。link_status='linked' のもののみ返す。
 */
function line_link_get_by_customer($pdo, $tenantId, $reservationCustomerId)
{
    if (!line_link_table_exists($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM reservation_customer_line_links
           WHERE tenant_id = ?
             AND reservation_customer_id = ?
             AND link_status = \'linked\'
           ORDER BY linked_at DESC
           LIMIT 1'
    );
    $stmt->execute([$tenantId, $reservationCustomerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * upsert: (tenant_id, line_user_id) でユニークなので、既存行があれば update、
 * 無ければ insert。呼び出し側はこの 1 関数で「初回リンク」「再リンク」
 * どちらも扱える。
 *
 * $profile: ['display_name' => ..., 'picture_url' => ...] (任意)
 *
 * 戻り値: link 行全体 (最新状態)
 *
 * Invariant (2026-04-22 review fix): 1 顧客 1 active link
 *   同じ reservation_customer_id に対して、同時に linked な行は最大 1 つ。
 *   DB UNIQUE は (tenant_id, line_user_id) のみで、同一 customer を別の
 *   line_user_id でリンクする誤操作 / 再リンクは DB では防げないため、ここで
 *   先に「同 customer_id で自分以外の linked 行」を unlink してから続行する。
 *   履歴は link_status='unlinked' として残る。race 時のフォールバックとして
 *   notifier 側は ORDER BY linked_at DESC LIMIT 1 で最新のみ採用する。
 *   より強い保証が必要なら、将来 generated column + UNIQUE で DB 側に昇格可能。
 */
function line_link_upsert($pdo, $tenantId, $storeId, $reservationCustomerId, $lineUserId, $profile = [])
{
    if (!line_link_table_exists($pdo)) {
        return null;
    }
    $display = isset($profile['display_name']) ? (string)$profile['display_name'] : null;
    $picture = isset($profile['picture_url']) ? (string)$profile['picture_url'] : null;

    // invariant: 同 customer_id の別 line_user_id な linked 行を先に落とす
    $stmt = $pdo->prepare(
        'UPDATE reservation_customer_line_links
            SET link_status = \'unlinked\',
                unlinked_at = CURRENT_TIMESTAMP
          WHERE tenant_id = ?
            AND reservation_customer_id = ?
            AND link_status = \'linked\'
            AND line_user_id <> ?'
    );
    $stmt->execute([$tenantId, $reservationCustomerId, $lineUserId]);

    // 既存行の有無で insert / update を切り替える (ON DUPLICATE KEY UPDATE も
    // 使えるが、link_status / linked_at の "再リンク時復活" を明示したいので
    // 明示的に分岐)
    $existing = line_link_get_by_line_user($pdo, $tenantId, $lineUserId);
    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE reservation_customer_line_links
                SET store_id = ?,
                    reservation_customer_id = ?,
                    display_name = COALESCE(?, display_name),
                    picture_url = COALESCE(?, picture_url),
                    link_status = \'linked\',
                    linked_at = CURRENT_TIMESTAMP,
                    unlinked_at = NULL
              WHERE id = ?'
        );
        $stmt->execute([$storeId, $reservationCustomerId, $display, $picture, $existing['id']]);
        $id = $existing['id'];
    } else {
        $id = generate_uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO reservation_customer_line_links
                (id, tenant_id, store_id, reservation_customer_id, line_user_id,
                 display_name, picture_url, link_status, linked_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, \'linked\', CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$id, $tenantId, $storeId, $reservationCustomerId, $lineUserId, $display, $picture]);
    }

    $stmt = $pdo->prepare('SELECT * FROM reservation_customer_line_links WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * 解除: link_status を 'unlinked' に、unlinked_at を NOW() に。
 * 行が存在しない / 既に unlinked の場合も冪等 (UPDATE で 0 行でも OK)。
 */
function line_link_unlink_by_id($pdo, $tenantId, $linkId)
{
    if (!line_link_table_exists($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare(
        'UPDATE reservation_customer_line_links
            SET link_status = \'unlinked\',
                unlinked_at = CURRENT_TIMESTAMP
          WHERE id = ? AND tenant_id = ? AND link_status = \'linked\''
    );
    $stmt->execute([$linkId, $tenantId]);
    return $stmt->rowCount() > 0;
}

function line_link_unlink_by_line_user($pdo, $tenantId, $lineUserId)
{
    if (!line_link_table_exists($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare(
        'UPDATE reservation_customer_line_links
            SET link_status = \'unlinked\',
                unlinked_at = CURRENT_TIMESTAMP
          WHERE tenant_id = ? AND line_user_id = ? AND link_status = \'linked\''
    );
    $stmt->execute([$tenantId, $lineUserId]);
    return $stmt->rowCount() > 0;
}

/**
 * last_interaction_at を touch。LINE webhook の message イベント等で呼ぶ。
 * 未リンク LINE user なら 0 行 UPDATE で安全 (row を勝手に作らない)。
 */
function line_link_touch_interaction($pdo, $tenantId, $lineUserId)
{
    if (!line_link_table_exists($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare(
        'UPDATE reservation_customer_line_links
            SET last_interaction_at = CURRENT_TIMESTAMP
          WHERE tenant_id = ? AND line_user_id = ?'
    );
    $stmt->execute([$tenantId, $lineUserId]);
    return $stmt->rowCount() > 0;
}

/**
 * tenant 内の summary (linked / unlinked 件数、最終 interaction)。
 * owner UI の一覧ヘッダーで表示する軽量集計。
 */
function line_link_summary($pdo, $tenantId)
{
    if (!line_link_table_exists($pdo)) {
        return ['migration_applied' => false];
    }
    $stmt = $pdo->prepare(
        'SELECT
           SUM(CASE WHEN link_status = \'linked\' THEN 1 ELSE 0 END) AS linked_count,
           SUM(CASE WHEN link_status = \'unlinked\' THEN 1 ELSE 0 END) AS unlinked_count,
           MAX(last_interaction_at) AS last_interaction_at,
           MAX(linked_at) AS last_linked_at
         FROM reservation_customer_line_links
         WHERE tenant_id = ?'
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch() ?: [];
    return [
        'migration_applied'   => true,
        'linked_count'        => (int)($row['linked_count'] ?? 0),
        'unlinked_count'      => (int)($row['unlinked_count'] ?? 0),
        'last_interaction_at' => $row['last_interaction_at'] ?? null,
        'last_linked_at'      => $row['last_linked_at'] ?? null,
    ];
}

/**
 * tenant 内の最近 N 件リストを customer 名つきで返す (owner UI)。
 */
function line_link_recent_list($pdo, $tenantId, $limit = 20)
{
    if (!line_link_table_exists($pdo)) {
        return [];
    }
    $limit = max(1, min(100, (int)$limit));
    $stmt = $pdo->prepare(
        'SELECT
           l.id,
           l.store_id,
           l.reservation_customer_id,
           l.line_user_id,
           l.display_name,
           l.link_status,
           l.linked_at,
           l.unlinked_at,
           l.last_interaction_at,
           c.customer_name,
           c.customer_phone,
           s.name AS store_name
         FROM reservation_customer_line_links l
         LEFT JOIN reservation_customers c ON c.id = l.reservation_customer_id
         LEFT JOIN stores s ON s.id = l.store_id
         WHERE l.tenant_id = ?
         ORDER BY l.linked_at DESC
         LIMIT ' . $limit
    );
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll() ?: [];
}
