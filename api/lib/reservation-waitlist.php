<?php
/**
 * キャンセル待ち候補の登録・一覧・空席通知ヘルパ。
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/reservation-availability.php';
require_once __DIR__ . '/mail.php';

if (!function_exists('reservation_waitlist_uuid')) {
    function reservation_waitlist_uuid() {
        return bin2hex(random_bytes(18));
    }
}

if (!function_exists('reservation_waitlist_create')) {
    function reservation_waitlist_create($pdo, $store, $data) {
        $id = reservation_waitlist_uuid();
        $source = isset($data['source']) && in_array($data['source'], ['web','phone','walk_in','ai_chat'], true) ? $data['source'] : 'web';
        $language = isset($data['language']) ? (string)$data['language'] : 'ja';
        $pdo->prepare(
            'INSERT INTO reservation_waitlist_candidates
             (id, tenant_id, store_id, desired_at, party_size, customer_name, customer_phone, customer_email, memo, language, source, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "waiting", NOW(), NOW())'
        )->execute([
            $id,
            $store['tenant_id'],
            $store['id'],
            $data['desired_at'],
            (int)$data['party_size'],
            mb_substr((string)$data['customer_name'], 0, 120),
            isset($data['customer_phone']) ? mb_substr((string)$data['customer_phone'], 0, 40) : null,
            isset($data['customer_email']) ? mb_substr((string)$data['customer_email'], 0, 190) : null,
            isset($data['memo']) ? mb_substr((string)$data['memo'], 0, 1000) : null,
            mb_substr($language, 0, 10),
            $source,
        ]);
        return $id;
    }
}

if (!function_exists('reservation_waitlist_fetch_for_date')) {
    function reservation_waitlist_fetch_for_date($pdo, $storeId, $date) {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, store_id, desired_at, party_size, customer_name, customer_phone, customer_email, memo, source, status,
                        notification_count, notified_at, last_notification_error, booked_reservation_id, hold_id, hold_expires_at, created_at, updated_at
                 FROM reservation_waitlist_candidates
                 WHERE store_id = ? AND desired_at >= ? AND desired_at <= ?
                 ORDER BY FIELD(status, "waiting", "notified", "booked", "cancelled", "expired"), desired_at ASC, created_at ASC'
            );
            $stmt->execute([$storeId, $date . ' 00:00:00', $date . ' 23:59:59']);
            $rows = $stmt->fetchAll();
            return $rows ? $rows : [];
        } catch (PDOException $e) {
            error_log('[reservation-waitlist] fetch_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
            return [];
        }
    }
}

if (!function_exists('reservation_waitlist_mark_booked')) {
    function reservation_waitlist_mark_booked($pdo, $storeId, $reservationId, $reservedAt, $partySize, $phone, $email, $candidateId = null) {
        if (!$candidateId && !$phone && !$email) return 0;
        try {
            $contactSql = [];
            $params = [$storeId, (int)$partySize, $reservedAt, $reservedAt];
            if ($candidateId) {
                $contactSql[] = 'id = ?';
                $params[] = $candidateId;
            } elseif ($phone) {
                $contactSql[] = 'customer_phone = ?';
                $params[] = $phone;
            } elseif ($email) {
                $contactSql[] = 'customer_email = ?';
                $params[] = $email;
            }
            $stmt = $pdo->prepare(
                'SELECT id
                 FROM reservation_waitlist_candidates
                 WHERE store_id = ?
                   AND status IN ("waiting","notified")
                   AND party_size = ?
                   AND desired_at BETWEEN DATE_SUB(?, INTERVAL 60 MINUTE) AND DATE_ADD(?, INTERVAL 60 MINUTE)
                   AND (' . implode(' OR ', $contactSql) . ')
                 ORDER BY FIELD(status, "notified", "waiting"), ABS(TIMESTAMPDIFF(MINUTE, desired_at, ?)) ASC, created_at ASC
                 LIMIT 1'
            );
            $params[] = $reservedAt;
            $stmt->execute($params);
            $row = $stmt->fetch();
            if (!$row) return 0;
            $upd = $pdo->prepare(
                'UPDATE reservation_waitlist_candidates
                 SET status = "booked", booked_reservation_id = ?, updated_at = NOW()
                 WHERE id = ? AND store_id = ?'
            );
            $upd->execute([$reservationId, $row['id'], $storeId]);
            $pdo->prepare('DELETE FROM reservation_holds WHERE client_fingerprint = ? AND store_id = ?')
                ->execute(['waitlist:' . $row['id'], $storeId]);
            return $upd->rowCount();
        } catch (PDOException $e) {
            error_log('[reservation-waitlist] mark_booked_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
            return 0;
        }
    }
}

if (!function_exists('reservation_waitlist_notify_open_slot')) {
    function reservation_waitlist_notify_open_slot($pdo, $storeId, $reservedAt, $partySize, $reason) {
        try {
            $sStmt = $pdo->prepare('SELECT id, tenant_id, name FROM stores WHERE id = ?');
            $sStmt->execute([$storeId]);
            $store = $sStmt->fetch();
            if (!$store) return ['checked' => 0, 'notified' => 0, 'failed' => 0];
            $settings = get_reservation_settings($pdo, $storeId);
            $lockMinutes = max(5, min(120, (int)($settings['waitlist_lock_minutes'] ?? 15)));
            $duration = max(15, (int)($settings['default_duration_min'] ?? 90));

            $stmt = $pdo->prepare(
                'SELECT *
                 FROM reservation_waitlist_candidates
                 WHERE store_id = ?
                   AND status = "waiting"
                   AND party_size <= ?
                   AND desired_at BETWEEN DATE_SUB(?, INTERVAL 60 MINUTE) AND DATE_ADD(?, INTERVAL 60 MINUTE)
                 ORDER BY ABS(TIMESTAMPDIFF(MINUTE, desired_at, ?)) ASC, created_at ASC
                 LIMIT 10'
            );
            $stmt->execute([$storeId, (int)$partySize, $reservedAt, $reservedAt, $reservedAt]);
            $rows = $stmt->fetchAll();
            $result = ['checked' => count($rows), 'notified' => 0, 'failed' => 0, 'locked' => 0];
            foreach ($rows as $row) {
                if (empty($row['customer_email']) || !filter_var($row['customer_email'], FILTER_VALIDATE_EMAIL)) {
                    $pdo->prepare('UPDATE reservation_waitlist_candidates SET last_notification_error = ?, updated_at = NOW() WHERE id = ? AND store_id = ?')
                        ->execute(['NO_EMAIL', $row['id'], $storeId]);
                    $result['failed']++;
                    continue;
                }
                $holdId = reservation_waitlist_uuid();
                $fingerprint = 'waitlist:' . $row['id'];
                $expiresAt = date('Y-m-d H:i:s', time() + $lockMinutes * 60);
                $pdo->prepare(
                    'INSERT INTO reservation_holds (id, store_id, reserved_at, party_size, duration_min, expires_at, client_fingerprint, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
                )->execute([$holdId, $storeId, $row['desired_at'], (int)$row['party_size'], $duration, $expiresAt, $fingerprint]);
                $reserveUrl = app_url('/customer/reserve.html') . '?store_id=' . urlencode($storeId) . '&waitlist_id=' . urlencode($row['id']);
                $subject = '【空席が出ました】' . $store['name'];
                $body = $row['customer_name'] . " 様\n\nキャンセル待ち中の時間帯に空席が出ました。\n\n"
                    . "■店舗\n" . $store['name'] . "\n\n"
                    . "■ご希望日時\n" . date('Y年n月j日 H:i', strtotime($row['desired_at'])) . "\n\n"
                    . "■人数\n" . (int)$row['party_size'] . "名様\n\n"
                    . "下記ページから空き状況をご確認ください。\n" . $reserveUrl . "\n\n"
                    . "■優先確保期限\n" . date('Y年n月j日 H:i', strtotime($expiresAt)) . "まで\n\n"
                    . "※期限後は他のお客様にも開放されます。\n";
                $send = posla_send_mail($row['customer_email'], $subject, $body, [
                    'from_name' => $store['name'],
                    'from_email' => APP_FROM_EMAIL,
                ]);
                if (!empty($send['success'])) {
                    $pdo->prepare(
                        'UPDATE reservation_waitlist_candidates
                         SET status = "notified", notification_count = notification_count + 1, notified_at = NOW(), last_notification_error = NULL, hold_id = ?, hold_expires_at = ?, updated_at = NOW()
                         WHERE id = ? AND store_id = ?'
                    )->execute([$holdId, $expiresAt, $row['id'], $storeId]);
                    $result['notified']++;
                    $result['locked']++;
                    break;
                } else {
                    $pdo->prepare('DELETE FROM reservation_holds WHERE id = ? AND store_id = ?')->execute([$holdId, $storeId]);
                    $pdo->prepare(
                        'UPDATE reservation_waitlist_candidates
                         SET notification_count = notification_count + 1, last_notification_error = ?, updated_at = NOW()
                         WHERE id = ? AND store_id = ?'
                    )->execute([mb_substr((string)($send['error'] ?? 'SEND_FAILED'), 0, 255), $row['id'], $storeId]);
                    $result['failed']++;
                }
            }
            return $result;
        } catch (PDOException $e) {
            error_log('[reservation-waitlist] notify_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
            return ['checked' => 0, 'notified' => 0, 'failed' => 1];
        }
    }
}
