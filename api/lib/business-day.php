<?php
/**
 * 営業日計算ヘルパー（店舗別cutoff対応）
 */

/**
 * 店舗の day_cutoff_time を取得
 */
function get_cutoff_time(PDO $pdo, string $store_id): string
{
    static $cache = [];
    if (isset($cache[$store_id])) return $cache[$store_id];

    $stmt = $pdo->prepare(
        'SELECT day_cutoff_time FROM store_settings WHERE store_id = ? LIMIT 1'
    );
    $stmt->execute([$store_id]);
    $row = $stmt->fetch();

    $cache[$store_id] = ($row && !empty($row['day_cutoff_time']))
        ? $row['day_cutoff_time']
        : '05:00:00';

    return $cache[$store_id];
}

/**
 * 営業日を判定し、日付と datetime 範囲を返す
 */
function get_business_day(PDO $pdo, string $store_id, ?string $targetDate = null): array
{
    $cutoff = get_cutoff_time($pdo, $store_id);

    if ($targetDate !== null) {
        $start = $targetDate . ' ' . $cutoff;
        $end   = date('Y-m-d', strtotime($targetDate . ' +1 day')) . ' ' . $cutoff;
        return ['date' => $targetDate, 'start' => $start, 'end' => $end, 'cutoff' => $cutoff];
    }

    $now      = date('Y-m-d H:i:s');
    $today    = date('Y-m-d');
    $todayCutoff = $today . ' ' . $cutoff;

    if ($cutoff === '00:00:00') {
        $businessDate = $today;
    } elseif ($now < $todayCutoff) {
        $businessDate = date('Y-m-d', strtotime($today . ' -1 day'));
    } else {
        $businessDate = $today;
    }

    $start = $businessDate . ' ' . $cutoff;
    $end   = date('Y-m-d', strtotime($businessDate . ' +1 day')) . ' ' . $cutoff;

    if ($cutoff === '00:00:00') {
        $start = $businessDate . ' 00:00:00';
        $end   = date('Y-m-d', strtotime($businessDate . ' +1 day')) . ' 00:00:00';
    }

    return ['date' => $businessDate, 'start' => $start, 'end' => $end, 'cutoff' => $cutoff];
}

/**
 * 日付範囲を営業日ベースの datetime 範囲に変換
 */
function get_business_day_range(PDO $pdo, string $store_id, string $from, string $to): array
{
    $cutoff = get_cutoff_time($pdo, $store_id);

    $start = $from . ' ' . $cutoff;
    $end   = date('Y-m-d', strtotime($to . ' +1 day')) . ' ' . $cutoff;

    if ($cutoff === '00:00:00') {
        $start = $from . ' 00:00:00';
        $end   = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    }

    return ['start' => $start, 'end' => $end];
}
