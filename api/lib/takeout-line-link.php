<?php
/**
 * L-17 Phase 3A: テイクアウト LINE ひも付け helper
 *
 * reservation_customer_line_links と責務は分離し、takeout の注文単位で LINE
 * user を管理する。1 order 1 active link の invariant は upsert 側で app-layer
 * 保証する (reservation 側と同じパターン)。
 *
 * - table_exists: migration 未適用時 silent skip
 * - upsert: (tenant_id, order_id) UNIQUE を軸にした insert-or-update
 * - get_by_order: takeout_notify_ready_line() 等が送信先解決に使う
 * - unlink_by_line_user: webhook unfollow 等の副作用に使える
 * - touch_interaction: webhook message での interaction 更新
 *
 * takeout_notify_ready_line() は takeout-management.php の status='ready'
 * 更新直後に呼ばれる。送信失敗は log のみで webhook / PATCH 応答は成功維持。
 */

require_once __DIR__ . '/db.php';

if (!function_exists('takeout_link_table_exists')) {
    function takeout_link_table_exists($pdo)
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $pdo->query('SELECT id FROM takeout_order_line_links LIMIT 0');
            $exists = true;
        } catch (PDOException $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('takeout_link_get_by_order')) {
    function takeout_link_get_by_order($pdo, $tenantId, $orderId)
    {
        if (!takeout_link_table_exists($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM takeout_order_line_links
              WHERE tenant_id = ?
                AND order_id = ?
                AND link_status = \'linked\'
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('takeout_link_upsert')) {
    /**
     * 1 order 1 active link 保証: UNIQUE (tenant_id, order_id) で DB でも保証
     * されるが、既存 linked 行を再利用して linked_at / profile を更新する。
     */
    function takeout_link_upsert($pdo, $tenantId, $storeId, $orderId, $lineUserId, $profile = [])
    {
        if (!takeout_link_table_exists($pdo)) {
            return null;
        }
        $display = isset($profile['display_name']) ? (string)$profile['display_name'] : null;
        $picture = isset($profile['picture_url']) ? (string)$profile['picture_url'] : null;

        $existing = takeout_link_get_by_order($pdo, $tenantId, $orderId);
        // 別の line_user でリンクされていた行も含めて拾う (link_status 問わず)
        $stmt = $pdo->prepare(
            'SELECT * FROM takeout_order_line_links WHERE tenant_id = ? AND order_id = ? LIMIT 1'
        );
        $stmt->execute([$tenantId, $orderId]);
        $anyRow = $stmt->fetch();

        if ($anyRow) {
            // UPDATE in place: 再リンク時に status='linked' 復活、line_user 差し替え
            $pdo->prepare(
                'UPDATE takeout_order_line_links
                    SET store_id = ?,
                        line_user_id = ?,
                        display_name = COALESCE(?, display_name),
                        picture_url = COALESCE(?, picture_url),
                        link_status = \'linked\',
                        linked_at = CURRENT_TIMESTAMP,
                        unlinked_at = NULL
                  WHERE id = ?'
            )->execute([$storeId, $lineUserId, $display, $picture, $anyRow['id']]);
            $id = $anyRow['id'];
        } else {
            $id = generate_uuid();
            $pdo->prepare(
                'INSERT INTO takeout_order_line_links
                    (id, tenant_id, store_id, order_id, line_user_id,
                     display_name, picture_url, link_status, linked_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, \'linked\', CURRENT_TIMESTAMP)'
            )->execute([$id, $tenantId, $storeId, $orderId, $lineUserId, $display, $picture]);
        }

        $stmt = $pdo->prepare('SELECT * FROM takeout_order_line_links WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('takeout_link_unlink_by_line_user')) {
    /**
     * LINE user が unfollow 等した時に全ての linked 行を soft unlink にする。
     */
    function takeout_link_unlink_by_line_user($pdo, $tenantId, $lineUserId)
    {
        if (!takeout_link_table_exists($pdo)) {
            return 0;
        }
        $stmt = $pdo->prepare(
            'UPDATE takeout_order_line_links
                SET link_status = \'unlinked\',
                    unlinked_at = CURRENT_TIMESTAMP
              WHERE tenant_id = ? AND line_user_id = ? AND link_status = \'linked\''
        );
        $stmt->execute([$tenantId, $lineUserId]);
        return $stmt->rowCount();
    }
}

if (!function_exists('takeout_link_touch_interaction')) {
    function takeout_link_touch_interaction($pdo, $tenantId, $lineUserId)
    {
        if (!takeout_link_table_exists($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare(
            'UPDATE takeout_order_line_links
                SET last_interaction_at = CURRENT_TIMESTAMP
              WHERE tenant_id = ? AND line_user_id = ?'
        );
        $stmt->execute([$tenantId, $lineUserId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('takeout_notify_ready_line')) {
    /**
     * L-17 Phase 3B-1: takeout が ready になったタイミングで LINE push を試みる
     *
     * 以下の全てを満たす場合のみ push を実行する:
     *   1. takeout_order_line_links が適用済
     *   2. tenant_line_settings.is_enabled = 1
     *   3. tenant_line_settings.notify_takeout_ready = 1
     *   4. tenant_line_settings.channel_access_token あり
     *   5. この order に対する linked な takeout_order_line_links 行あり
     *
     * 送信結果に関わらず reservation_notifications_log に channel='line' で
     * 1 行残す (takeout 用の専用 log テーブルは新設しない、既存 log を再利用)。
     * 送信失敗でも caller (takeout-management PATCH) の処理は成功維持。
     *
     * @return array ['attempted'=>bool, 'success'=>bool, 'error'=>?string]
     */
    function takeout_notify_ready_line($pdo, $orderId, $storeId)
    {
        // ヘルパ不同梱環境では何もしない
        if (!function_exists('line_push_message') || !function_exists('line_text_message')) {
            return ['attempted' => false, 'success' => false, 'error' => 'LINE_HELPERS_MISSING'];
        }
        // store から tenant_id 解決
        $stmt = $pdo->prepare('SELECT id, name, tenant_id FROM stores WHERE id = ?');
        $stmt->execute([$storeId]);
        $store = $stmt->fetch();
        if (!$store) {
            return ['attempted' => false, 'success' => false, 'error' => 'STORE_NOT_FOUND'];
        }
        $tenantId = $store['tenant_id'];

        // tenant_line_settings を確認
        try {
            $stmt = $pdo->prepare(
                'SELECT channel_access_token, is_enabled, notify_takeout_ready
                   FROM tenant_line_settings WHERE tenant_id = ?'
            );
            $stmt->execute([$tenantId]);
            $settings = $stmt->fetch();
        } catch (PDOException $e) {
            return ['attempted' => false, 'success' => false, 'error' => 'LINE_SETTINGS_TABLE_MISSING'];
        }
        if (!$settings) {
            return ['attempted' => false, 'success' => false, 'error' => 'NO_LINE_SETTINGS'];
        }
        if ((int)$settings['is_enabled'] !== 1) {
            return ['attempted' => false, 'success' => false, 'error' => 'LINE_NOT_ENABLED'];
        }
        if ((int)$settings['notify_takeout_ready'] !== 1) {
            return ['attempted' => false, 'success' => false, 'error' => 'NOTIFY_FLAG_OFF'];
        }
        if (empty($settings['channel_access_token'])) {
            return ['attempted' => false, 'success' => false, 'error' => 'NO_ACCESS_TOKEN'];
        }

        // linked order -> line_user_id 解決 (未連携なら skip)
        $link = takeout_link_get_by_order($pdo, $tenantId, $orderId);
        if (!$link || empty($link['line_user_id'])) {
            return ['attempted' => false, 'success' => false, 'error' => 'NOT_LINKED'];
        }

        // 注文情報を取得してメッセージ本文に含める
        $stmt = $pdo->prepare(
            'SELECT customer_name, pickup_at FROM orders WHERE id = ?'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $storeName = $store['name'];
        $name = ($order && !empty($order['customer_name'])) ? $order['customer_name'] : 'お客';
        $pickup = ($order && !empty($order['pickup_at'])) ? $order['pickup_at'] : '';
        $shortId = substr($orderId, 0, 8);

        $subject = '【受取のご案内】' . $storeName;
        $body = $name . " 様\n\n"
              . "ご注文の準備が完了しました。カウンターでお受け取りください。\n\n"
              . '■店舗' . "\n" . $storeName . "\n\n"
              . '■注文番号' . "\n" . strtoupper($shortId) . "\n\n"
              . ($pickup !== '' ? '■受取予定時刻' . "\n" . $pickup . "\n\n" : '')
              . 'お待ちしております。';

        $text = $subject . "\n\n" . $body;
        $messages = [line_text_message($text)];
        $r = line_push_message($settings['channel_access_token'], $link['line_user_id'], $messages);

        // reservation_notifications_log に記録 (takeout 用 log は既存 email 用の
        // reservation_id カラムが NOT NULL のため、order_id を reservation_id
        // 列に書き込むのは FK 衝突で不可。Phase 3B-1 では log を残す最小路線
        // として error_log にのみ出す。将来 takeout_notifications_log を別表で
        // 作る余地あり)。
        error_log(sprintf(
            '[L-17 3B-1 takeout_ready_line] order=%s tenant=%s success=%d error=%s',
            $orderId,
            $tenantId,
            $r['success'] ? 1 : 0,
            $r['success'] ? '-' : (string)($r['error'] ?? 'UNKNOWN')
        ));

        return [
            'attempted' => true,
            'success'   => (bool)$r['success'],
            'error'     => $r['success'] ? null : (isset($r['error']) ? $r['error'] : 'LINE_SEND_FAILED'),
        ];
    }
}
