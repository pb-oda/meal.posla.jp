<?php
/**
 * POSLA管理者 Release Plan API
 *
 * GET   /api/posla/release-plan.php
 * PATCH /api/posla/release-plan.php
 *
 * This stores deployment target cells in POSLA, separately from Feature Flags.
 * GitHub Actions reads the saved plan through /api/deploy/release-plan.php.
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/../lib/release-plan.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH']);
$pdo = get_db();
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
            'actions_api' => 'GitHub Actionsは /api/deploy/release-plan.php をBearer Token付きで読み、保存済み計画だけを候補にします。',
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
