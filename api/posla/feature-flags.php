<?php
/**
 * POSLA管理者 Feature Flag API
 *
 * GET   /api/posla/feature-flags.php
 * PATCH /api/posla/feature-flags.php
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/../lib/feature-flags.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH']);
$pdo = get_db();

function feature_flags_api_bool_input($value, string $field): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value)) {
        if ($value === 1) {
            return 1;
        }
        if ($value === 0) {
            return 0;
        }
        json_error('INVALID_BOOLEAN', $field . ' は true / false で指定してください', 400);
    }

    $text = strtolower(trim((string)$value));
    if (in_array($text, ['1', 'true', 'on', 'yes'], true)) {
        return 1;
    }
    if (in_array($text, ['0', 'false', 'off', 'no'], true)) {
        return 0;
    }

    json_error('INVALID_BOOLEAN', $field . ' は true / false で指定してください', 400);
}

function feature_flags_api_normalize_scope(PDO $pdo, string $scopeType, string $scopeId): array
{
    if (!in_array($scopeType, ['global', 'cell', 'tenant'], true)) {
        json_error('INVALID_SCOPE_TYPE', 'scope_type は global / cell / tenant のいずれかです', 400);
    }

    if ($scopeType === 'global') {
        return ['global', '*'];
    }

    if ($scopeType === 'cell') {
        $scopeId = $scopeId !== '' ? $scopeId : posla_feature_flag_current_cell_id();
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/', $scopeId)) {
            json_error('INVALID_CELL_ID', 'cell の scope_id が不正です', 400);
        }
        return ['cell', $scopeId];
    }

    if ($scopeId === '') {
        json_error('MISSING_TENANT_ID', 'tenant scope では scope_id が必須です', 400);
    }

    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$scopeId]);
    if (!$stmt->fetch()) {
        json_error('TENANT_NOT_FOUND', '対象テナントが見つかりません', 404);
    }

    return ['tenant', $scopeId];
}

function feature_flags_api_fetch_override(PDO $pdo, string $featureKey, string $scopeType, string $scopeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, feature_key, scope_type, scope_id, enabled, reason,
                created_by_admin_id, updated_by_admin_id, created_at, updated_at
         FROM posla_feature_flag_overrides
         WHERE feature_key = ? AND scope_type = ? AND scope_id = ?
         LIMIT 1'
    );
    $stmt->execute([$featureKey, $scopeType, $scopeId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row['enabled'] = !empty($row['enabled']) ? 1 : 0;
    return $row;
}

function feature_flags_api_visible_in_posla_admin(string $featureKey): bool
{
    return !in_array($featureKey, ['codex_ops_write'], true);
}

function feature_flags_api_require_feature(PDO $pdo, string $featureKey): array
{
    if (!posla_feature_flag_valid_key($featureKey)) {
        json_error('INVALID_FEATURE_KEY', 'feature_key が不正です', 400);
    }

    if (!feature_flags_api_visible_in_posla_admin($featureKey)) {
        json_error('FEATURE_FLAG_NOT_FOUND', 'Feature Flag が見つかりません', 404);
    }

    $stmt = $pdo->prepare(
        'SELECT feature_key, label, description, default_enabled, is_active
         FROM posla_feature_flags
         WHERE feature_key = ?
         LIMIT 1'
    );
    $stmt->execute([$featureKey]);
    $feature = $stmt->fetch();

    if (!$feature || empty($feature['is_active'])) {
        json_error('FEATURE_FLAG_NOT_FOUND', 'Feature Flag が見つかりません', 404);
    }

    return $feature;
}

function feature_flags_api_reason_required(string $scopeType): bool
{
    return $scopeType === 'global';
}

if (!posla_feature_flags_available($pdo)) {
    if ($method === 'GET') {
        json_response([
            'available' => false,
            'cell_id' => posla_feature_flag_current_cell_id(),
            'tenant_id' => null,
            'flags' => [],
        ]);
    }
    json_error('FEATURE_FLAGS_UNAVAILABLE', 'Feature Flag テーブルが未作成です', 503);
}

if ($method === 'GET') {
    $tenantId = trim((string)($_GET['tenant_id'] ?? ''));

    if ($tenantId !== '') {
        $stmt = $pdo->prepare('SELECT id FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        if (!$stmt->fetch()) {
            json_error('TENANT_NOT_FOUND', '対象テナントが見つかりません', 404);
        }
    }

    $flags = posla_feature_flag_resolve_all($pdo, $tenantId !== '' ? $tenantId : null);
    $flags = array_filter(
        $flags,
        function ($_flag, $featureKey) {
            return feature_flags_api_visible_in_posla_admin((string)$featureKey);
        },
        ARRAY_FILTER_USE_BOTH
    );

    json_response([
        'available' => true,
        'cell_id' => posla_feature_flag_current_cell_id(),
        'tenant_id' => $tenantId !== '' ? $tenantId : null,
        'flags' => array_values($flags),
    ]);
}

if ($method === 'PATCH') {
    $input = get_json_body();
    $featureKey = trim((string)($input['feature_key'] ?? ''));
    $scopeType = trim((string)($input['scope_type'] ?? ''));
    $scopeId = trim((string)($input['scope_id'] ?? ''));
    if ($scopeType === 'tenant' && $scopeId === '' && !empty($input['tenant_id'])) {
        $scopeId = trim((string)$input['tenant_id']);
    }

    feature_flags_api_require_feature($pdo, $featureKey);
    list($scopeType, $scopeId) = feature_flags_api_normalize_scope($pdo, $scopeType, $scopeId);

    $clear = !empty($input['clear']);
    $reason = trim((string)($input['reason'] ?? ''));
    if (strlen($reason) > 255) {
        json_error('REASON_TOO_LONG', 'reason は255文字以内で入力してください', 400);
    }
    if ($reason === '' && feature_flags_api_reason_required($scopeType)) {
        json_error('REASON_REQUIRED', 'global scope の変更では reason が必須です', 400);
    }
    $reasonValue = $reason !== '' ? $reason : null;
    $oldValue = feature_flags_api_fetch_override($pdo, $featureKey, $scopeType, $scopeId);

    if ($clear) {
        $stmt = $pdo->prepare(
            'DELETE FROM posla_feature_flag_overrides
             WHERE feature_key = ? AND scope_type = ? AND scope_id = ?'
        );
        $stmt->execute([$featureKey, $scopeType, $scopeId]);
        $newValue = null;
        $message = 'Feature Flag override を解除しました';
    } else {
        if (!array_key_exists('enabled', $input)) {
            json_error('MISSING_ENABLED', 'enabled は必須です', 400);
        }
        $enabled = feature_flags_api_bool_input($input['enabled'], 'enabled');
        $stmt = $pdo->prepare(
            'INSERT INTO posla_feature_flag_overrides
                (id, feature_key, scope_type, scope_id, enabled, reason,
                 created_by_admin_id, updated_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                reason = VALUES(reason),
                updated_by_admin_id = VALUES(updated_by_admin_id),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            generate_uuid(),
            $featureKey,
            $scopeType,
            $scopeId,
            $enabled,
            $reasonValue,
            $admin['admin_id'] ?? null,
            $admin['admin_id'] ?? null,
        ]);
        $newValue = feature_flags_api_fetch_override($pdo, $featureKey, $scopeType, $scopeId);
        $message = 'Feature Flag override を更新しました';
    }

    posla_admin_write_audit_log(
        $pdo,
        $admin,
        $clear ? 'feature_flag_clear' : 'feature_flag_update',
        'feature_flag',
        $featureKey . ':' . $scopeType . ':' . $scopeId,
        $oldValue,
        $newValue,
        $reasonValue,
        generate_uuid()
    );

    json_response([
        'message' => $message,
        'feature_key' => $featureKey,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
    ]);
}
