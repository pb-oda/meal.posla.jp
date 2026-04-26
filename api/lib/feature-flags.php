<?php
/**
 * POSLA operational feature flags.
 *
 * Contract features stay in check_plan_feature(). This helper is for rollout
 * control by global / cell / tenant scope.
 */

require_once __DIR__ . '/../config/app.php';

function posla_feature_flags_available(PDO $pdo): bool
{
    static $checked = false;
    static $available = false;

    if ($checked) {
        return $available;
    }

    $checked = true;

    try {
        $pdo->query('SELECT 1 FROM posla_feature_flags LIMIT 0');
        $pdo->query('SELECT 1 FROM posla_feature_flag_overrides LIMIT 0');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

function posla_feature_flag_current_cell_id(): string
{
    return defined('APP_CELL_ID') ? (string)APP_CELL_ID : 'posla-control-local';
}

function posla_feature_flag_valid_key($featureKey): bool
{
    if (!is_string($featureKey)) {
        return false;
    }

    return preg_match('/^[a-z0-9_][a-z0-9_\-]{1,79}$/', $featureKey) === 1;
}

function posla_feature_flag_fetch_definitions(PDO $pdo): array
{
    if (!posla_feature_flags_available($pdo)) {
        return [];
    }

    $stmt = $pdo->query(
        'SELECT feature_key, label, description, default_enabled, is_active, created_at, updated_at
         FROM posla_feature_flags
         WHERE is_active = 1
         ORDER BY feature_key'
    );
    $rows = $stmt->fetchAll();
    $definitions = [];

    foreach ($rows as $row) {
        $row['default_enabled'] = !empty($row['default_enabled']) ? 1 : 0;
        $row['is_active'] = !empty($row['is_active']) ? 1 : 0;
        $definitions[$row['feature_key']] = $row;
    }

    return $definitions;
}

function posla_feature_flag_fetch_override_map(PDO $pdo, ?string $tenantId = null, ?string $cellId = null): array
{
    if (!posla_feature_flags_available($pdo)) {
        return [];
    }

    $cellId = $cellId ?: posla_feature_flag_current_cell_id();
    $conditions = ['(scope_type = ? AND scope_id = ?)'];
    $params = ['global', '*'];

    if ($cellId !== '') {
        $conditions[] = '(scope_type = ? AND scope_id = ?)';
        $params[] = 'cell';
        $params[] = $cellId;
    }

    if ($tenantId !== null && $tenantId !== '') {
        $conditions[] = '(scope_type = ? AND scope_id = ?)';
        $params[] = 'tenant';
        $params[] = $tenantId;
    }

    $sql = 'SELECT id, feature_key, scope_type, scope_id, enabled, reason,
                   created_by_admin_id, updated_by_admin_id, created_at, updated_at
            FROM posla_feature_flag_overrides
            WHERE ' . implode(' OR ', $conditions) . '
            ORDER BY updated_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        $row['enabled'] = !empty($row['enabled']) ? 1 : 0;
        if (!isset($map[$row['feature_key']])) {
            $map[$row['feature_key']] = [];
        }
        $map[$row['feature_key']][$row['scope_type']] = $row;
    }

    return $map;
}

function posla_feature_flag_resolve_all(PDO $pdo, ?string $tenantId = null, ?string $cellId = null): array
{
    $cellId = $cellId ?: posla_feature_flag_current_cell_id();
    $definitions = posla_feature_flag_fetch_definitions($pdo);
    $overrides = posla_feature_flag_fetch_override_map($pdo, $tenantId, $cellId);
    $resolved = [];

    foreach ($definitions as $featureKey => $definition) {
        $enabled = !empty($definition['default_enabled']) ? 1 : 0;
        $source = 'default';
        $sourceScopeId = null;
        $flagOverrides = [
            'global' => null,
            'cell' => null,
            'tenant' => null,
        ];

        if (isset($overrides[$featureKey])) {
            foreach (['global', 'cell', 'tenant'] as $scopeType) {
                if (isset($overrides[$featureKey][$scopeType])) {
                    $flagOverrides[$scopeType] = $overrides[$featureKey][$scopeType];
                }
            }
        }

        foreach (['global', 'cell', 'tenant'] as $scopeType) {
            if ($flagOverrides[$scopeType] !== null) {
                $enabled = !empty($flagOverrides[$scopeType]['enabled']) ? 1 : 0;
                $source = $scopeType;
                $sourceScopeId = $flagOverrides[$scopeType]['scope_id'];
            }
        }

        $resolved[$featureKey] = [
            'feature_key' => $featureKey,
            'label' => $definition['label'],
            'description' => $definition['description'],
            'default_enabled' => !empty($definition['default_enabled']) ? 1 : 0,
            'resolved_enabled' => $enabled,
            'resolved_source' => $source,
            'resolved_scope_id' => $sourceScopeId,
            'overrides' => $flagOverrides,
            'updated_at' => $definition['updated_at'] ?? null,
        ];
    }

    return $resolved;
}

function posla_feature_flag_resolve(PDO $pdo, string $featureKey, ?string $tenantId = null, ?string $cellId = null): array
{
    if (!posla_feature_flag_valid_key($featureKey)) {
        return [
            'feature_key' => $featureKey,
            'resolved_enabled' => 0,
            'resolved_source' => 'invalid',
            'resolved_scope_id' => null,
        ];
    }

    $flags = posla_feature_flag_resolve_all($pdo, $tenantId, $cellId);
    if (isset($flags[$featureKey])) {
        return $flags[$featureKey];
    }

    return [
        'feature_key' => $featureKey,
        'resolved_enabled' => 0,
        'resolved_source' => 'missing',
        'resolved_scope_id' => null,
    ];
}

function posla_feature_flag_enabled(PDO $pdo, string $featureKey, ?string $tenantId = null, ?string $cellId = null): bool
{
    $flag = posla_feature_flag_resolve($pdo, $featureKey, $tenantId, $cellId);
    return !empty($flag['resolved_enabled']);
}
