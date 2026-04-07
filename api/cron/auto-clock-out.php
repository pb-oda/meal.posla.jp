<?php
/**
 * 自動退勤 cron スクリプト（L-3）
 *
 * shift_settings.auto_clock_out_hours を超過した
 * working レコードを自動退勤させる。
 *
 * 推奨: 5分間隔でcron実行
 *   cron例: 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/api/cron/auto-clock-out.php
 */

// CLI実行チェック
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
}

require_once __DIR__ . '/../lib/db.php';

$pdo = get_db();
$now = date('Y-m-d H:i:s');

echo "[{$now}] auto-clock-out 開始\n";

// 全店舗のworking レコードを取得
$stmt = $pdo->prepare(
    'SELECT a.id, a.tenant_id, a.store_id, a.user_id, a.clock_in,
            COALESCE(s.auto_clock_out_hours, 12) AS auto_hours
     FROM attendance_logs a
     LEFT JOIN shift_settings s
       ON s.store_id = a.store_id AND s.tenant_id = a.tenant_id
     WHERE a.status = \'working\''
);
$stmt->execute();
$rows = $stmt->fetchAll();

$stmtUpdate = $pdo->prepare(
    'UPDATE attendance_logs
     SET clock_out = ?, status = \'completed\', clock_out_method = \'timeout\',
         note = CONCAT(COALESCE(note, \'\'), \'[自動退勤: 打刻忘れ]\')
     WHERE id = ?'
);

$count = 0;
foreach ($rows as $r) {
    $clockIn   = new DateTime($r['clock_in']);
    $threshold = clone $clockIn;
    $threshold->modify('+' . (int)$r['auto_hours'] . ' hours');
    $current   = new DateTime($now);

    if ($current >= $threshold) {
        // 自動退勤時刻はclock_in + auto_hours（実時刻ではない）
        $autoClockOut = $threshold->format('Y-m-d H:i:s');
        $stmtUpdate->execute([$autoClockOut, $r['id']]);
        $count++;
        echo "  自動退勤: user={$r['user_id']} clock_in={$r['clock_in']} → clock_out={$autoClockOut}\n";
    }
}

echo "[{$now}] 完了: {$count}件 自動退勤\n";
