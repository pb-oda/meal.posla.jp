<?php
/**
 * POSLA管理者 Release Plan API
 *
 * GET   /api/posla/release-plan.php
 * PATCH /api/posla/release-plan.php
 *
 * This stores the deployment target cells in POSLA, separately from
 * Feature Flags. Future automation can use this plan as the explicit target.
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH']);
$pdo = get_db();

const RELEASE_PLAN_TARGET_KEY = 'release_plan_target_cell_id';
const RELEASE_PLAN_SCOPE_KEY = 'release_plan_target_scope';
const RELEASE_PLAN_AUTOMATION_KEY = 'release_plan_automation_mode';
const RELEASE_PLAN_NOTE_KEY = 'release_plan_note';

function release_plan_table_exists(PDO $pdo, string $tableName): bool
{
    static $cache = [];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
        return false;
    }
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute([$tableName]);
        $cache[$tableName] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function release_plan_valid_cell_id(string $cellId): bool
{
    return preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $cellId) === 1;
}

function release_plan_fetch_settings(PDO $pdo): array
{
    $keys = [
        RELEASE_PLAN_SCOPE_KEY,
        RELEASE_PLAN_TARGET_KEY,
        RELEASE_PLAN_AUTOMATION_KEY,
        RELEASE_PLAN_NOTE_KEY,
    ];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare(
        'SELECT setting_key, setting_value, updated_at
         FROM posla_settings
         WHERE setting_key IN (' . $placeholders . ')'
    );
    $stmt->execute($keys);
    $rows = $stmt->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        $map[(string)$row['setting_key']] = [
            'value' => $row['setting_value'],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    return $map;
}

function release_plan_setting_value(array $settings, string $key): string
{
    if (!isset($settings[$key]) || $settings[$key]['value'] === null) {
        return '';
    }

    return trim((string)$settings[$key]['value']);
}

function release_plan_latest_updated_at(array $settings): ?string
{
    $latest = null;
    foreach ($settings as $row) {
        $updatedAt = $row['updated_at'] ?? null;
        if ($updatedAt !== null && ($latest === null || strcmp((string)$updatedAt, $latest) > 0)) {
            $latest = (string)$updatedAt;
        }
    }

    return $latest;
}

function release_plan_fetch_cells(PDO $pdo): array
{
    if (!release_plan_table_exists($pdo, 'posla_cell_registry')) {
        return [
            'available' => false,
            'cells' => [[
                'cell_id' => 'test-01',
                'label' => 'POSLA運用確認用 / test-01',
                'tenant_name' => 'POSLA運用確認用',
                'tenant_slug' => 'test-01',
                'environment' => 'test',
                'status' => 'recommended',
                'app_base_url' => null,
                'health_url' => null,
                'deploy_version' => null,
                'registry_known' => false,
            ]],
        ];
    }

    try {
        $stmt = $pdo->query(
            'SELECT cell_id, tenant_id, tenant_slug, tenant_name, environment, status,
                    app_base_url, health_url, deploy_version, updated_at
             FROM posla_cell_registry
             WHERE cell_id IS NOT NULL
               AND cell_id <> \'\'
             ORDER BY
               CASE
                 WHEN cell_id = \'test-01\' THEN 0
                 WHEN status = \'active\' THEN 1
                 WHEN status = \'provisioning\' THEN 2
                 ELSE 9
               END,
               updated_at DESC,
               cell_id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        return ['available' => false, 'cells' => []];
    }

    $cells = [];
    foreach ($rows as $row) {
        $tenantName = trim((string)($row['tenant_name'] ?? ''));
        $cellId = trim((string)($row['cell_id'] ?? ''));
        if ($cellId === '') {
            continue;
        }
        $cells[] = [
            'cell_id' => $cellId,
            'label' => ($tenantName !== '' ? $tenantName : $cellId) . ' / ' . $cellId,
            'tenant_id' => $row['tenant_id'] ?? null,
            'tenant_slug' => $row['tenant_slug'] ?? null,
            'tenant_name' => $row['tenant_name'] ?? null,
            'environment' => $row['environment'] ?? null,
            'status' => $row['status'] ?? null,
            'app_base_url' => $row['app_base_url'] ?? null,
            'health_url' => $row['health_url'] ?? null,
            'deploy_version' => $row['deploy_version'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'registry_known' => true,
        ];
    }

    return ['available' => true, 'cells' => $cells];
}

function release_plan_find_cell(array $cells, string $cellId): ?array
{
    foreach ($cells as $cell) {
        if ((string)($cell['cell_id'] ?? '') === $cellId) {
            return $cell;
        }
    }

    return null;
}

function release_plan_recommended_cell_id(array $cells): string
{
    foreach ($cells as $cell) {
        if ((string)($cell['cell_id'] ?? '') === 'test-01') {
            return 'test-01';
        }
    }
    foreach ($cells as $cell) {
        if ((string)($cell['status'] ?? '') === 'active') {
            return (string)$cell['cell_id'];
        }
    }

    return isset($cells[0]) ? (string)$cells[0]['cell_id'] : '';
}

function release_plan_active_cells(array $cells): array
{
    $active = [];
    foreach ($cells as $cell) {
        if ((string)($cell['status'] ?? '') === 'active') {
            $active[] = $cell;
        }
    }

    return $active;
}

function release_plan_build(array $settings, array $cellData): array
{
    $cells = $cellData['cells'] ?? [];
    $targetScope = release_plan_setting_value($settings, RELEASE_PLAN_SCOPE_KEY);
    $targetCellId = release_plan_setting_value($settings, RELEASE_PLAN_TARGET_KEY);
    $automationMode = release_plan_setting_value($settings, RELEASE_PLAN_AUTOMATION_KEY);
    $note = release_plan_setting_value($settings, RELEASE_PLAN_NOTE_KEY);
    if (!in_array($targetScope, ['single_cell', 'all_active_cells'], true)) {
        $targetScope = $targetCellId !== '' ? 'single_cell' : '';
    }
    if (!in_array($automationMode, ['manual_only', 'actions_allowed'], true)) {
        $automationMode = 'manual_only';
    }

    $targetCell = $targetCellId !== '' ? release_plan_find_cell($cells, $targetCellId) : null;
    $activeCells = release_plan_active_cells($cells);
    $targetCells = [];
    if ($targetScope === 'single_cell' && $targetCell !== null) {
        $targetCells = [$targetCell];
    } elseif ($targetScope === 'all_active_cells') {
        $targetCells = $activeCells;
    }
    $targetKnown = $targetScope === 'all_active_cells'
        ? count($targetCells) > 0
        : $targetCell !== null;
    $status = 'missing';
    $summary = 'デプロイ対象範囲が未設定です。Actionsなどの自動デプロイは停止扱いにしてください。';

    if ($targetScope === 'single_cell' && $targetCellId !== '') {
        if (!$targetKnown && !empty($cellData['available'])) {
            $status = 'warning';
            $summary = '保存済みのデプロイ対象cellが registry に見つかりません。Cell配備を確認してください。';
        } elseif ($automationMode === 'actions_allowed') {
            $status = 'ready';
            $summary = 'Actionsは保存済みの対象cellだけを候補にできます。Feature Flagsは別途確認します。';
        } else {
            $status = 'manual';
            $summary = '対象cellは保存済みです。Actionsからの自動実行は許可していません。';
        }
    } elseif ($targetScope === 'all_active_cells') {
        if (count($targetCells) === 0) {
            $status = 'warning';
            $summary = 'active cell が見つかりません。Cell配備の registry を確認してください。';
        } elseif ($automationMode === 'actions_allowed') {
            $status = 'ready';
            $summary = 'Actionsは全active cellへ同じコードを揃える候補にできます。Feature Flagsは別途確認します。';
        } else {
            $status = 'manual';
            $summary = '全active cellへコードを揃える計画です。Actionsからの自動実行は許可していません。';
        }
    }

    return [
        'status' => $status,
        'summary' => $summary,
        'target_scope' => $targetScope,
        'target_cell_id' => $targetCellId,
        'target_cell' => $targetCell,
        'target_cells' => $targetCells,
        'target_count' => count($targetCells),
        'target_known' => $targetKnown,
        'automation_mode' => $automationMode,
        'actions_can_run' => $status === 'ready',
        'note' => $note,
        'updated_at' => release_plan_latest_updated_at($settings),
        'recommended_cell_id' => release_plan_recommended_cell_id($cells),
    ];
}

function release_plan_save_setting(PDO $pdo, string $key, ?string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO posla_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$key, $value]);
}

$cellData = release_plan_fetch_cells($pdo);

if ($method === 'GET') {
    $settings = release_plan_fetch_settings($pdo);
    json_response([
        'available' => true,
        'cells_available' => !empty($cellData['available']),
        'cells' => $cellData['cells'],
        'plan' => release_plan_build($settings, $cellData),
        'guidance' => [
            'deployment_target' => 'デプロイ先cellはこのRelease Planを正にします。コード統一時は全active cellを選びます。',
            'feature_flags' => 'Feature Flagsはデプロイ後に誰へ見せるかだけを決めます。',
            'automation' => '未設定またはmanual_onlyの場合、Actionsは自動デプロイを実行しません。',
        ],
    ]);
}

if ($method === 'PATCH') {
    $input = get_json_body();
    $targetScope = trim((string)($input['target_scope'] ?? 'single_cell'));
    $targetCellId = trim((string)($input['target_cell_id'] ?? ''));
    $automationMode = trim((string)($input['automation_mode'] ?? 'manual_only'));
    $note = trim((string)($input['note'] ?? ''));

    if (!in_array($targetScope, ['single_cell', 'all_active_cells'], true)) {
        json_error('INVALID_TARGET_SCOPE', 'target_scope が不正です', 400);
    }
    if ($targetScope === 'single_cell' && $targetCellId === '') {
        json_error('MISSING_TARGET_CELL', 'デプロイ対象cellを選択してください', 400);
    }
    if ($targetCellId !== '' && !release_plan_valid_cell_id($targetCellId)) {
        json_error('INVALID_TARGET_CELL', 'デプロイ対象cellの形式が不正です', 400);
    }
    if (!in_array($automationMode, ['manual_only', 'actions_allowed'], true)) {
        json_error('INVALID_AUTOMATION_MODE', 'automation_mode が不正です', 400);
    }
    if (strlen($note) > 255) {
        json_error('NOTE_TOO_LONG', 'メモは255文字以内で入力してください', 400);
    }
    if ($automationMode === 'actions_allowed' && $note === '') {
        json_error('NOTE_REQUIRED', 'Actionsに許可する場合は変更理由を入力してください', 400);
    }

    $cells = $cellData['cells'] ?? [];
    if ($targetScope === 'single_cell' && !empty($cellData['available']) && !empty($cells) && release_plan_find_cell($cells, $targetCellId) === null) {
        json_error('TARGET_CELL_NOT_FOUND', 'デプロイ対象cellが registry に見つかりません', 404);
    }
    if ($targetScope === 'all_active_cells' && count(release_plan_active_cells($cells)) === 0) {
        json_error('NO_ACTIVE_CELLS', '全active cellを選ぶには active cell が必要です', 400);
    }

    $oldSettings = release_plan_fetch_settings($pdo);
    $oldPlan = release_plan_build($oldSettings, $cellData);

    release_plan_save_setting($pdo, RELEASE_PLAN_SCOPE_KEY, $targetScope);
    release_plan_save_setting($pdo, RELEASE_PLAN_TARGET_KEY, $targetScope === 'single_cell' ? $targetCellId : null);
    release_plan_save_setting($pdo, RELEASE_PLAN_AUTOMATION_KEY, $automationMode);
    release_plan_save_setting($pdo, RELEASE_PLAN_NOTE_KEY, $note !== '' ? $note : null);

    $newSettings = release_plan_fetch_settings($pdo);
    $newPlan = release_plan_build($newSettings, $cellData);

    posla_admin_write_audit_log(
        $pdo,
        $admin,
        'release_plan_update',
        'release_plan',
        $targetScope === 'all_active_cells' ? 'all_active_cells' : $targetCellId,
        $oldPlan,
        $newPlan,
        $note !== '' ? $note : null,
        generate_uuid()
    );

    json_response([
        'message' => 'Release Plan を更新しました',
        'cells_available' => !empty($cellData['available']),
        'cells' => $cellData['cells'],
        'plan' => $newPlan,
    ]);
}
