<?php
/**
 * 予約変更履歴の共通ヘルパ。
 * migration 未適用環境では記録だけ quiet skip して本処理を止めない。
 */

require_once __DIR__ . '/../config/app.php';

if (!function_exists('reservation_history_uuid')) {
    function reservation_history_uuid() {
        return bin2hex(random_bytes(18));
    }
}

if (!function_exists('reservation_history_value')) {
    function reservation_history_value($value) {
        if ($value === null) return null;
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $text = (string)$value;
        if ($text === '') return null;
        return mb_substr($text, 0, 2000);
    }
}

if (!function_exists('reservation_history_same')) {
    function reservation_history_same($oldValue, $newValue) {
        return reservation_history_value($oldValue) === reservation_history_value($newValue);
    }
}

if (!function_exists('reservation_history_record')) {
    function reservation_history_record($pdo, $reservation, $actorType, $actorUserId, $actorName, $action, $fieldName, $oldValue, $newValue) {
        if (!$reservation || empty($reservation['id']) || empty($reservation['store_id']) || empty($reservation['tenant_id'])) return;
        if (reservation_history_same($oldValue, $newValue)) return;
        try {
            $pdo->prepare(
                'INSERT INTO reservation_change_logs
                 (id, reservation_id, tenant_id, store_id, actor_type, actor_user_id, actor_name, action, field_name, old_value, new_value, changed_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([
                reservation_history_uuid(),
                $reservation['id'],
                $reservation['tenant_id'],
                $reservation['store_id'],
                in_array($actorType, ['staff','customer','system'], true) ? $actorType : 'system',
                $actorUserId ?: null,
                $actorName ?: null,
                mb_substr((string)$action, 0, 40),
                mb_substr((string)$fieldName, 0, 80),
                reservation_history_value($oldValue),
                reservation_history_value($newValue),
            ]);
        } catch (PDOException $e) {
            error_log('[reservation-history] record_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        }
    }
}

if (!function_exists('reservation_history_record_fields')) {
    function reservation_history_record_fields($pdo, $oldReservation, $newReservation, $fields, $actorType, $actorUserId, $actorName, $action) {
        if (!$oldReservation || !$newReservation || !is_array($fields)) return;
        foreach ($fields as $fieldName) {
            $oldValue = array_key_exists($fieldName, $oldReservation) ? $oldReservation[$fieldName] : null;
            $newValue = array_key_exists($fieldName, $newReservation) ? $newReservation[$fieldName] : null;
            reservation_history_record($pdo, $newReservation, $actorType, $actorUserId, $actorName, $action, $fieldName, $oldValue, $newValue);
        }
    }
}

if (!function_exists('reservation_history_fetch')) {
    function reservation_history_fetch($pdo, $reservationId, $storeId, $limit = 30) {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, reservation_id, actor_type, actor_user_id, actor_name, action, field_name, old_value, new_value, changed_at
                 FROM reservation_change_logs
                 WHERE reservation_id = ? AND store_id = ?
                 ORDER BY changed_at DESC, id DESC
                 LIMIT ' . max(1, min(100, (int)$limit))
            );
            $stmt->execute([$reservationId, $storeId]);
            $rows = $stmt->fetchAll();
            return $rows ? $rows : [];
        } catch (PDOException $e) {
            error_log('[reservation-history] fetch_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
            return [];
        }
    }
}
