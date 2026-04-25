<?php
/**
 * L-9 予約管理 — 空席計算共通ライブラリ
 *
 * - 店舗の reservation_settings + tables + 既存 reservations + 有効 holds から
 *   指定日の各時間スロットの空席状況を計算
 * - シングルテーブル割当を基本、必要なら複数テーブル連結対応
 *
 * 注意: マルチテナント境界 — 呼び出し側で store_id を必ず authorize すること
 */

// REL-HOTFIX-20260423-SERVER-READY: POSLA_PHP_ERROR_LOG constant を確実にロードする
// (本 file は response.php 等を require しないため、app.php を explicit require)
require_once __DIR__ . '/../config/app.php';

if (!function_exists('get_reservation_settings')) {
    /**
     * 店舗の予約設定を取得 (未設定ならデフォルト値を返す)
     */
    function get_reservation_settings($pdo, $storeId) {
        $defaults = array(
            'store_id' => $storeId,
            'online_enabled' => 0,
            'lead_time_hours' => 2,
            'max_advance_days' => 60,
            'default_duration_min' => 90,
            'slot_interval_min' => 30,
            'max_party_size' => 10,
            'min_party_size' => 1,
            'open_time' => '11:00:00',
            'close_time' => '22:00:00',
            'last_order_offset_min' => 60,
            'weekly_closed_days' => null,
            'require_phone' => 1,
            'require_email' => 0,
            'buffer_before_min' => 0,
            'buffer_after_min' => 10,
            'notes_to_customer' => null,
            'cancel_deadline_hours' => 3,
            'deposit_enabled' => 0,
            'deposit_per_person' => 0,
            'deposit_min_party_size' => 4,
            'reminder_24h_enabled' => 1,
            'reminder_2h_enabled' => 1,
            'ai_chat_enabled' => 1,
            'notification_email' => null,
        );
        try {
            $stmt = $pdo->prepare('SELECT * FROM reservation_settings WHERE store_id = ?');
            $stmt->execute(array($storeId));
            $row = $stmt->fetch();
            if ($row) {
                return array_merge($defaults, $row);
            }
        } catch (PDOException $e) {
            error_log('[L-9][reservation-availability] get_settings: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        }
        return $defaults;
    }
}

if (!function_exists('get_active_tables_for_reservation')) {
    /**
     * 予約割当に使えるテーブル一覧 (capacity 順)
     */
    function get_active_tables_for_reservation($pdo, $storeId) {
        $stmt = $pdo->prepare(
            'SELECT id, table_code AS label, capacity, floor AS area FROM tables WHERE store_id = ? AND is_active = 1 ORDER BY capacity ASC, table_code ASC'
        );
        $stmt->execute(array($storeId));
        $rows = $stmt->fetchAll();
        return $rows ? $rows : array();
    }
}

if (!function_exists('get_reservations_in_range')) {
    /**
     * 指定日付範囲の有効な予約を取得 (cancelled/no_show 除外)
     */
    function get_reservations_in_range($pdo, $storeId, $fromDateTime, $toDateTime) {
        $stmt = $pdo->prepare(
            "SELECT id, customer_name, party_size, reserved_at, duration_min, status, assigned_table_ids, source, memo, tags, course_name, customer_phone, customer_email, customer_id, deposit_status, language, table_session_id
             FROM reservations
             WHERE store_id = ? AND status NOT IN ('cancelled','no_show')
               AND reserved_at >= ? AND reserved_at < ?
             ORDER BY reserved_at ASC"
        );
        $stmt->execute(array($storeId, $fromDateTime, $toDateTime));
        $rows = $stmt->fetchAll();
        return $rows ? $rows : array();
    }
}

if (!function_exists('get_active_holds_in_range')) {
    /**
     * 期限内の在庫ホールドを取得
     */
    function get_active_holds_in_range($pdo, $storeId, $fromDateTime, $toDateTime, $excludeFingerprint = null) {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT id, reserved_at, party_size, duration_min, expires_at, client_fingerprint
                FROM reservation_holds
                WHERE store_id = ? AND expires_at > ?
                  AND reserved_at >= ? AND reserved_at < ?";
        $params = array($storeId, $now, $fromDateTime, $toDateTime);
        if ($excludeFingerprint !== null) {
            $sql .= " AND (client_fingerprint IS NULL OR client_fingerprint <> ?)";
            $params[] = $excludeFingerprint;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return $rows ? $rows : array();
    }
}

if (!function_exists('purge_expired_holds')) {
    /**
     * 期限切れのホールドを削除 (cron / on-demand 両用)
     */
    function purge_expired_holds($pdo, $storeId = null) {
        try {
            if ($storeId) {
                $pdo->prepare('DELETE FROM reservation_holds WHERE store_id = ? AND expires_at <= NOW()')->execute(array($storeId));
            } else {
                $pdo->exec('DELETE FROM reservation_holds WHERE expires_at <= NOW()');
            }
        } catch (PDOException $e) {
            error_log('[L-9][reservation-availability] purge_holds: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        }
    }
}

if (!function_exists('compute_slot_availability')) {
    /**
     * 1日分のスロット別空席状況を計算
     *
     * @return array [
     *   ['time'=>'12:00','available'=>true,'remaining_capacity'=>4,'tables'=>[...]],
     *   ...
     * ]
     */
    function compute_slot_availability($pdo, $storeId, $date, $partySize, $excludeReservationId = null, $excludeFingerprint = null) {
        $settings = get_reservation_settings($pdo, $storeId);
        $tables = get_active_tables_for_reservation($pdo, $storeId);
        if (empty($tables)) {
            return array();
        }

        // 営業時間
        $openTime = isset($settings['open_time']) ? substr($settings['open_time'], 0, 5) : '11:00';
        $closeTime = isset($settings['close_time']) ? substr($settings['close_time'], 0, 5) : '22:00';
        $slotInterval = max(15, (int)$settings['slot_interval_min']);
        $duration = max(15, (int)$settings['default_duration_min']);
        $bufferBefore = (int)$settings['buffer_before_min'];
        $bufferAfter = (int)$settings['buffer_after_min'];
        $lastOrderOffset = (int)$settings['last_order_offset_min'];

        // 定休日チェック
        $weekday = (int)date('w', strtotime($date)); // 0=日, 6=土
        $closedDays = $settings['weekly_closed_days'] ?: '';
        if ($closedDays !== '') {
            $closedArr = array_filter(array_map('trim', explode(',', $closedDays)), 'strlen');
            if (in_array((string)$weekday, $closedArr, true)) {
                return array();
            }
        }

        // スロット生成
        $openTs = strtotime($date . ' ' . $openTime . ':00');
        $closeTs = strtotime($date . ' ' . $closeTime . ':00');
        $lastSlotTs = $closeTs - $lastOrderOffset * 60;
        if ($lastSlotTs <= $openTs) {
            return array();
        }

        // 範囲内の予約とホールドを一括取得
        $rangeFrom = date('Y-m-d 00:00:00', strtotime($date . ' -1 day'));
        $rangeTo = date('Y-m-d 23:59:59', strtotime($date . ' +1 day'));
        $reservations = get_reservations_in_range($pdo, $storeId, $rangeFrom, $rangeTo);
        $holds = get_active_holds_in_range($pdo, $storeId, $rangeFrom, $rangeTo, $excludeFingerprint);

        // 編集モード: 自分の予約は除外
        if ($excludeReservationId) {
            $filtered = array();
            foreach ($reservations as $r) {
                if ($r['id'] !== $excludeReservationId) $filtered[] = $r;
            }
            $reservations = $filtered;
        }

        // 占有計算用データ整形
        $occupations = array(); // [{from_ts, to_ts, table_ids[], party_size}]
        foreach ($reservations as $r) {
            $rTs = strtotime($r['reserved_at']);
            $occupations[] = array(
                'from_ts' => $rTs - $bufferBefore * 60,
                'to_ts' => $rTs + ((int)$r['duration_min'] + $bufferAfter) * 60,
                'table_ids' => _decode_table_ids($r['assigned_table_ids']),
                'party_size' => (int)$r['party_size'],
            );
        }
        foreach ($holds as $h) {
            $hTs = strtotime($h['reserved_at']);
            $occupations[] = array(
                'from_ts' => $hTs - $bufferBefore * 60,
                'to_ts' => $hTs + ((int)$h['duration_min'] + $bufferAfter) * 60,
                'table_ids' => array(),
                'party_size' => (int)$h['party_size'],
            );
        }

        // 各スロット計算
        $slots = array();
        $now = time();
        $leadCutoff = $now + (int)$settings['lead_time_hours'] * 3600;

        for ($t = $openTs; $t <= $lastSlotTs; $t += $slotInterval * 60) {
            $slotFromTs = $t - $bufferBefore * 60;
            $slotToTs = $t + ($duration + $bufferAfter) * 60;

            // リードタイム未満は不可
            if ($t < $leadCutoff) {
                $slots[] = array(
                    'time' => date('H:i', $t),
                    'available' => false,
                    'reason' => 'lead_time',
                    'remaining_capacity' => 0,
                );
                continue;
            }

            // この時間帯に占有されているテーブル ID 集合
            $occupiedTableIds = array();
            $occupiedSeatsHolds = 0;
            foreach ($occupations as $occ) {
                if ($occ['from_ts'] < $slotToTs && $occ['to_ts'] > $slotFromTs) {
                    if (!empty($occ['table_ids'])) {
                        foreach ($occ['table_ids'] as $tid) {
                            $occupiedTableIds[$tid] = true;
                        }
                    } else {
                        // テーブル未割当のホールド/予約 → 席数換算で控除
                        $occupiedSeatsHolds += $occ['party_size'];
                    }
                }
            }

            // 空きテーブル
            $freeTables = array();
            $freeCapacityTotal = 0;
            foreach ($tables as $tbl) {
                if (!isset($occupiedTableIds[$tbl['id']])) {
                    $freeTables[] = $tbl;
                    $freeCapacityTotal += (int)$tbl['capacity'];
                }
            }
            $freeCapacityTotal = max(0, $freeCapacityTotal - $occupiedSeatsHolds);

            // party_size 収容可能なテーブル組み合わせを探索
            $assigned = _find_table_assignment($freeTables, $partySize);
            $available = !empty($assigned);
            $slots[] = array(
                'time' => date('H:i', $t),
                'available' => $available,
                'remaining_capacity' => $freeCapacityTotal,
                'suggested_tables' => $assigned,
            );
        }

        return $slots;
    }
}

if (!function_exists('_decode_table_ids')) {
    function _decode_table_ids($json) {
        if (!$json) return array();
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : array();
    }
}

if (!function_exists('_find_table_assignment')) {
    /**
     * 指定 party_size を満たす最小の空きテーブル組み合わせを返す
     * 1. シングルテーブルで収まるもの優先
     * 2. なければ最大 3 テーブル連結まで探索
     */
    function _find_table_assignment($freeTables, $partySize) {
        if ($partySize <= 0 || empty($freeTables)) return array();

        // 1. シングル: capacity >= partySize の最小
        $best = null;
        foreach ($freeTables as $t) {
            $cap = (int)$t['capacity'];
            if ($cap >= $partySize) {
                if ($best === null || $cap < (int)$best['capacity']) {
                    $best = $t;
                }
            }
        }
        if ($best) return array($best['id']);

        // 2. ペア (2テーブル連結): capacity_a + capacity_b >= partySize
        $n = count($freeTables);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $sum = (int)$freeTables[$i]['capacity'] + (int)$freeTables[$j]['capacity'];
                if ($sum >= $partySize) {
                    return array($freeTables[$i]['id'], $freeTables[$j]['id']);
                }
            }
        }

        // 3. トリプル
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                for ($k = $j + 1; $k < $n; $k++) {
                    $sum = (int)$freeTables[$i]['capacity'] + (int)$freeTables[$j]['capacity'] + (int)$freeTables[$k]['capacity'];
                    if ($sum >= $partySize) {
                        return array($freeTables[$i]['id'], $freeTables[$j]['id'], $freeTables[$k]['id']);
                    }
                }
            }
        }

        return array();
    }
}

if (!function_exists('compute_daily_heatmap')) {
    /**
     * 日付ごとの混雑度ヒート (0=空き多/1=やや/2=満) を計算
     * カレンダー UI 用に最大 max_advance_days 分を一括取得
     */
    function compute_daily_heatmap($pdo, $storeId, $fromDate, $days, $partySize) {
        $settings = get_reservation_settings($pdo, $storeId);
        $tables = get_active_tables_for_reservation($pdo, $storeId);
        if (empty($tables)) return array();

        $totalCapacity = 0;
        foreach ($tables as $t) $totalCapacity += (int)$t['capacity'];
        if ($totalCapacity <= 0) return array();

        $rangeFrom = $fromDate . ' 00:00:00';
        $rangeTo = date('Y-m-d 23:59:59', strtotime($fromDate . ' +' . max(1, (int)$days) . ' days'));

        $stmt = $pdo->prepare(
            "SELECT DATE(reserved_at) AS d, SUM(party_size) AS total_party
             FROM reservations
             WHERE store_id = ? AND status NOT IN ('cancelled','no_show')
               AND reserved_at >= ? AND reserved_at < ?
             GROUP BY DATE(reserved_at)"
        );
        $stmt->execute(array($storeId, $rangeFrom, $rangeTo));
        $rows = $stmt->fetchAll();
        $byDate = array();
        foreach ($rows as $r) {
            $byDate[$r['d']] = (int)$r['total_party'];
        }

        $closedDays = $settings['weekly_closed_days'] ?: '';
        $closedArr = $closedDays !== '' ? array_filter(array_map('trim', explode(',', $closedDays)), 'strlen') : array();

        $result = array();
        for ($i = 0; $i < (int)$days; $i++) {
            $d = date('Y-m-d', strtotime($fromDate . ' +' . $i . ' day'));
            $weekday = (int)date('w', strtotime($d));
            if (in_array((string)$weekday, $closedArr, true)) {
                $result[] = array('date' => $d, 'level' => 'closed', 'load_percent' => 0);
                continue;
            }
            $usedSeats = isset($byDate[$d]) ? $byDate[$d] : 0;
            // 1日の最大席稼働 = total_capacity * (open_minutes / default_duration_min)
            $openMin = (strtotime($d . ' ' . $settings['close_time']) - strtotime($d . ' ' . $settings['open_time'])) / 60;
            $rotations = max(1, $openMin / max(60, (int)$settings['default_duration_min']));
            $maxSeats = max(1, (int)round($totalCapacity * $rotations));
            $loadPct = (int)round(($usedSeats / $maxSeats) * 100);
            $level = 'low';
            if ($loadPct >= 80) $level = 'high';
            elseif ($loadPct >= 50) $level = 'mid';
            $result[] = array('date' => $d, 'level' => $level, 'load_percent' => $loadPct);
        }
        return $result;
    }
}
