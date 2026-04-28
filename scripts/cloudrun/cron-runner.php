#!/usr/bin/env php
<?php
/**
 * Cloud Run Job entrypoint for POSLA cron workloads.
 *
 * Usage:
 *   php scripts/cloudrun/cron-runner.php every-5-minutes
 *   POSLA_CRON_TASK=hourly php scripts/cloudrun/cron-runner.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(2);
}

$rootDir = dirname(__DIR__, 2);

$tasks = [
    'monitor-health' => $rootDir . '/api/cron/monitor-health.php',
    'reservation-reminders' => $rootDir . '/api/cron/reservation-reminders.php',
    'reservation-cleanup' => $rootDir . '/api/cron/reservation-cleanup.php',
    'auto-clock-out' => $rootDir . '/api/cron/auto-clock-out.php',
];

$groups = [
    'every-5-minutes' => ['monitor-health', 'reservation-reminders', 'auto-clock-out'],
    'hourly' => ['reservation-cleanup'],
    'all' => ['monitor-health', 'reservation-reminders', 'auto-clock-out', 'reservation-cleanup'],
];

$taskName = '';
if (!empty($argv[1])) {
    $taskName = trim((string)$argv[1]);
}
if ($taskName === '') {
    $taskName = trim((string)(getenv('POSLA_CRON_TASK') ?: 'every-5-minutes'));
}

if (isset($groups[$taskName])) {
    $taskList = $groups[$taskName];
} elseif (isset($tasks[$taskName])) {
    $taskList = [$taskName];
} else {
    fwrite(STDERR, "Unknown POSLA cron task: {$taskName}\n");
    fwrite(STDERR, "Available: " . implode(', ', array_merge(array_keys($groups), array_keys($tasks))) . "\n");
    exit(2);
}

$failed = 0;
$startedAt = date('c');
echo "[posla-cloudrun-cron] start task={$taskName} at={$startedAt}\n";

foreach ($taskList as $task) {
    $path = $tasks[$task] ?? '';
    if ($path === '' || !is_file($path)) {
        fwrite(STDERR, "[posla-cloudrun-cron] missing task file task={$task} path={$path}\n");
        $failed++;
        continue;
    }

    $code = run_php_task($task, $path);
    if ($code !== 0) {
        $failed++;
    }
}

$finishedAt = date('c');
echo "[posla-cloudrun-cron] finish task={$taskName} failed={$failed} at={$finishedAt}\n";
exit($failed > 0 ? 1 : 0);

function run_php_task($task, $path)
{
    $started = microtime(true);
    echo "[posla-cloudrun-cron] run {$task}\n";

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open([PHP_BINARY, $path], $descriptors, $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "[posla-cloudrun-cron] proc_open failed task={$task}\n");
        return 1;
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($stdout !== '') {
        echo rtrim($stdout) . "\n";
    }
    if ($stderr !== '') {
        fwrite(STDERR, rtrim($stderr) . "\n");
    }

    $elapsedMs = (int)round((microtime(true) - $started) * 1000);
    echo "[posla-cloudrun-cron] done {$task} code={$code} elapsed_ms={$elapsedMs}\n";
    return (int)$code;
}
