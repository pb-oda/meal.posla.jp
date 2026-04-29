<?php
/**
 * ハンディ端末設定 API
 *
 * GET   /api/store/handy-terminal.php?store_id=xxx[&device_uid=yyy]
 * PATCH /api/store/handy-terminal.php
 *
 * 端末ごとの表示モード・通知音・再通知間隔・最終疎通時刻を保存する。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$method = require_method(['GET', 'PATCH']);
$user = require_auth();
$pdo = get_db();

if ($method === 'GET') {
    $storeId = require_store_param();
    ensure_handy_terminal_table($pdo);
    $deviceUid = isset($_GET['device_uid']) ? normalize_device_uid((string)$_GET['device_uid']) : '';

    if ($deviceUid !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, device_uid, device_label, terminal_mode, sound_enabled,
                    realert_enabled, realert_interval_sec, last_seen_at, updated_at
               FROM handy_terminals
              WHERE tenant_id = ? AND store_id = ? AND device_uid = ?
              LIMIT 1'
        );
        $stmt->execute([$user['tenant_id'], $storeId, $deviceUid]);
        $row = $stmt->fetch();
        json_response(['terminal' => $row ? format_terminal_row($row) : null]);
    }

    $stmt = $pdo->prepare(
        'SELECT id, device_uid, device_label, terminal_mode, sound_enabled,
                realert_enabled, realert_interval_sec, last_seen_at, updated_at
           FROM handy_terminals
          WHERE tenant_id = ? AND store_id = ?
          ORDER BY last_seen_at DESC, updated_at DESC
          LIMIT 50'
    );
    $stmt->execute([$user['tenant_id'], $storeId]);
    $rows = $stmt->fetchAll();
    $terminals = [];
    foreach ($rows as $row) {
        $terminals[] = format_terminal_row($row);
    }
    json_response(['terminals' => $terminals]);
}

$data = get_json_body();
$storeId = isset($data['store_id']) ? trim((string)$data['store_id']) : '';
if ($storeId === '') {
    json_error('MISSING_STORE', 'store_id は必須です', 400);
}
require_store_access($storeId);
ensure_handy_terminal_table($pdo);

$deviceUid = normalize_device_uid(isset($data['device_uid']) ? (string)$data['device_uid'] : '');
if ($deviceUid === '') {
    json_error('INVALID_DEVICE_UID', 'device_uid が不正です', 400);
}

$mode = isset($data['terminal_mode']) ? (string)$data['terminal_mode'] : 'handy';
if (!in_array($mode, ['handy', 'wall', 'register'], true)) {
    json_error('INVALID_MODE', 'terminal_mode が不正です', 400);
}

$label = isset($data['device_label']) ? trim((string)$data['device_label']) : '';
if ($label === '') {
    $label = default_terminal_label($mode);
}
$label = mb_substr($label, 0, 80);

$soundEnabled = !empty($data['sound_enabled']) ? 1 : 0;
$realertEnabled = array_key_exists('realert_enabled', $data) ? (!empty($data['realert_enabled']) ? 1 : 0) : 1;
$realertInterval = isset($data['realert_interval_sec']) ? (int)$data['realert_interval_sec'] : 180;
$realertInterval = max(60, min(900, $realertInterval));

$id = generate_uuid();
$stmt = $pdo->prepare(
    'INSERT INTO handy_terminals
        (id, tenant_id, store_id, device_uid, device_label, terminal_mode,
         sound_enabled, realert_enabled, realert_interval_sec,
         last_seen_at, created_by_user_id, updated_by_user_id, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
         device_label = VALUES(device_label),
         terminal_mode = VALUES(terminal_mode),
         sound_enabled = VALUES(sound_enabled),
         realert_enabled = VALUES(realert_enabled),
         realert_interval_sec = VALUES(realert_interval_sec),
         last_seen_at = NOW(),
         updated_by_user_id = VALUES(updated_by_user_id),
         updated_at = NOW()'
);
$stmt->execute([
    $id,
    $user['tenant_id'],
    $storeId,
    $deviceUid,
    $label,
    $mode,
    $soundEnabled,
    $realertEnabled,
    $realertInterval,
    $user['user_id'],
    $user['user_id'],
]);

$fetch = $pdo->prepare(
    'SELECT id, device_uid, device_label, terminal_mode, sound_enabled,
            realert_enabled, realert_interval_sec, last_seen_at, updated_at
       FROM handy_terminals
      WHERE tenant_id = ? AND store_id = ? AND device_uid = ?
      LIMIT 1'
);
$fetch->execute([$user['tenant_id'], $storeId, $deviceUid]);
$row = $fetch->fetch();

json_response(['terminal' => $row ? format_terminal_row($row) : null]);

function ensure_handy_terminal_table(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM handy_terminals LIMIT 0');
    } catch (PDOException $e) {
        json_error('MIGRATION_REQUIRED', 'ハンディ端末設定のマイグレーションが未適用です (migration-p1-55-handy-terminal-alerts.sql)', 500);
    }
}

function normalize_device_uid(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (!preg_match('/^[a-zA-Z0-9_-]{12,80}$/', $value)) return '';
    return $value;
}

function default_terminal_label(string $mode): string
{
    if ($mode === 'wall') return '壁掛け端末';
    if ($mode === 'register') return 'レジ横端末';
    return 'ハンディ端末';
}

function format_terminal_row(array $row): array
{
    return [
        'id' => $row['id'],
        'deviceUid' => $row['device_uid'],
        'deviceLabel' => $row['device_label'],
        'terminalMode' => $row['terminal_mode'],
        'soundEnabled' => (int)$row['sound_enabled'] === 1,
        'realertEnabled' => (int)$row['realert_enabled'] === 1,
        'realertIntervalSec' => (int)$row['realert_interval_sec'],
        'lastSeenAt' => $row['last_seen_at'],
        'updatedAt' => $row['updated_at'],
    ];
}
