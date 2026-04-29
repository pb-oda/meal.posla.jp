<?php
/**
 * レジ締め忘れ判定ヘルパー
 */

function get_register_close_reminder(PDO $pdo, string $storeId, array $businessDay, bool $isRegisterOpen): array
{
    $settings = [
        'register_close_alert_enabled' => 1,
        'register_close_time' => null,
        'register_close_grace_min' => 30,
        'last_order_time' => null,
    ];

    try {
        $stmt = $pdo->prepare(
            'SELECT register_close_alert_enabled, register_close_time, register_close_grace_min, last_order_time
               FROM store_settings
              WHERE store_id = ?
              LIMIT 1'
        );
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();
        if ($row) {
            $settings = array_merge($settings, $row);
        }
    } catch (PDOException $e) {
        return [
            'enabled' => false,
            'configured' => false,
            'isOverdue' => false,
            'message' => null,
            'source' => 'migration_missing',
        ];
    }

    $enabled = (int)($settings['register_close_alert_enabled'] ?? 1) === 1;
    $closeTime = $settings['register_close_time'] ?? null;
    $source = 'register_close_time';
    $autoFromLastOrder = false;

    if (!$closeTime && !empty($settings['last_order_time'])) {
        $closeTime = date('H:i:s', strtotime('2000-01-01 ' . $settings['last_order_time'] . ' +60 minutes'));
        $source = 'last_order_time_plus_60';
        $autoFromLastOrder = true;
    }

    if (!$enabled || !$closeTime) {
        return [
            'enabled' => $enabled,
            'configured' => false,
            'isOverdue' => false,
            'businessDay' => $businessDay['date'] ?? null,
            'closeTime' => $closeTime,
            'dueAt' => null,
            'alertAt' => null,
            'graceMinutes' => (int)($settings['register_close_grace_min'] ?? 30),
            'source' => $source,
            'autoFromLastOrder' => $autoFromLastOrder,
            'message' => null,
        ];
    }

    $grace = max(0, (int)($settings['register_close_grace_min'] ?? 30));
    $businessDate = $businessDay['date'];
    $cutoff = $businessDay['cutoff'] ?? '05:00:00';
    $dueAt = $businessDate . ' ' . $closeTime;
    if ($cutoff !== '00:00:00' && $closeTime < $cutoff) {
        $dueAt = date('Y-m-d', strtotime($businessDate . ' +1 day')) . ' ' . $closeTime;
    }
    $alertAt = date('Y-m-d H:i:s', strtotime($dueAt . ' +' . $grace . ' minutes'));
    $now = date('Y-m-d H:i:s');
    $isOverdue = $isRegisterOpen && $now >= $alertAt;

    return [
        'enabled' => true,
        'configured' => true,
        'isOverdue' => $isOverdue,
        'businessDay' => $businessDate,
        'closeTime' => $closeTime,
        'dueAt' => $dueAt,
        'alertAt' => $alertAt,
        'graceMinutes' => $grace,
        'source' => $source,
        'autoFromLastOrder' => $autoFromLastOrder,
        'now' => $now,
        'message' => $isOverdue ? 'レジ締め予定時刻を過ぎています。営業終了後は本締めを完了してください。' : null,
    ];
}
