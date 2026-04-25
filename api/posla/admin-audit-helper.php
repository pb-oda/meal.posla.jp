<?php
/**
 * POSLA管理者向け監査ログヘルパー
 */

require_once __DIR__ . '/../lib/db.php';

function posla_admin_audit_table_exists(PDO $pdo): bool
{
    static $checked = false;
    static $exists = false;

    if ($checked) {
        return $exists;
    }

    $checked = true;

    try {
        $pdo->query('SELECT 1 FROM posla_admin_audit_log LIMIT 0');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function posla_admin_write_audit_log(
    PDO $pdo,
    array $admin,
    string $action,
    string $entityType,
    ?string $entityId,
    $oldValue,
    $newValue,
    ?string $reason = null,
    ?string $batchId = null
): void {
    if (!posla_admin_audit_table_exists($pdo)) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO posla_admin_audit_log
                (id, batch_id, admin_id, admin_email, admin_display_name,
                 action, entity_type, entity_id, old_value, new_value, reason,
                 ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );

        $stmt->execute([
            generate_uuid(),
            $batchId,
            $admin['admin_id'] ?? '',
            $admin['email'] ?? null,
            $admin['display_name'] ?? null,
            $action,
            $entityType,
            $entityId,
            $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[posla_admin_audit_log] write failed: ' . $e->getMessage());
    }
}

function posla_admin_fetch_recent_audit_log(PDO $pdo, int $limit = 20, ?string $entityType = null): array
{
    if (!posla_admin_audit_table_exists($pdo)) {
        return [];
    }

    $limit = max(1, min(50, $limit));

    try {
        $sql = 'SELECT id, batch_id, admin_id, admin_email, admin_display_name,
                       action, entity_type, entity_id, old_value, new_value, reason, created_at
                FROM posla_admin_audit_log';
        $params = [];

        if ($entityType !== null && $entityType !== '') {
            $sql .= ' WHERE entity_type = ?';
            $params[] = $entityType;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $result = [];
        $i = 0;

        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $row['old_value'] = posla_admin_decode_audit_json($row['old_value'] ?? null);
            $row['new_value'] = posla_admin_decode_audit_json($row['new_value'] ?? null);
            $result[] = $row;
        }

        return $result;
    } catch (Throwable $e) {
        error_log('[posla_admin_audit_log] fetch failed: ' . $e->getMessage());
        return [];
    }
}

function posla_admin_fetch_entity_audit_log(PDO $pdo, string $entityType, string $entityId, int $limit = 20): array
{
    if (!posla_admin_audit_table_exists($pdo)) {
        return [];
    }

    $limit = max(1, min(50, $limit));

    try {
        $stmt = $pdo->prepare(
            'SELECT id, batch_id, admin_id, admin_email, admin_display_name,
                    action, entity_type, entity_id, old_value, new_value, reason, created_at
             FROM posla_admin_audit_log
             WHERE entity_type = ?
               AND entity_id = ?
             ORDER BY created_at DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute([$entityType, $entityId]);
        $rows = $stmt->fetchAll();
        $result = [];
        $i = 0;

        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $row['old_value'] = posla_admin_decode_audit_json($row['old_value'] ?? null);
            $row['new_value'] = posla_admin_decode_audit_json($row['new_value'] ?? null);
            $result[] = $row;
        }

        return $result;
    } catch (Throwable $e) {
        error_log('[posla_admin_audit_log] fetch entity failed: ' . $e->getMessage());
        return [];
    }
}

function posla_admin_decode_audit_json($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : null;
}

function posla_admin_build_audit_summary(array $rows): array
{
    $summary = [
        'last_changed_at' => null,
        'last_changed_by' => null,
        'changes_24h' => 0,
        'batches_24h' => 0,
    ];

    if (empty($rows)) {
        return $summary;
    }

    $summary['last_changed_at'] = $rows[0]['created_at'] ?? null;
    $summary['last_changed_by'] = $rows[0]['admin_display_name'] ?: ($rows[0]['admin_email'] ?? null);

    $batchMap = [];
    $i = 0;
    $threshold = strtotime('-24 hours');

    for ($i = 0; $i < count($rows); $i++) {
        $createdAt = $rows[$i]['created_at'] ?? null;
        if (!$createdAt || strtotime($createdAt) < $threshold) {
            continue;
        }
        $summary['changes_24h']++;
        if (!empty($rows[$i]['batch_id'])) {
            $batchMap[$rows[$i]['batch_id']] = true;
        }
    }

    $summary['batches_24h'] = count($batchMap);

    return $summary;
}
