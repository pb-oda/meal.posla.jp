<?php
/**
 * GitHub Actions / deploy automation Release Plan API.
 *
 * GET /api/deploy/release-plan.php
 *
 * Read-only. This endpoint never changes Feature Flags or deployment state.
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/release-plan.php';

$method = require_method(['GET']);
$pdo = get_db();

function deploy_release_plan_request_token(): string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (is_string($authorization) && preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) === 1) {
        return trim((string)$matches[1]);
    }

    $headerToken = $_SERVER['HTTP_X_POSLA_RELEASE_PLAN_TOKEN'] ?? '';
    return is_string($headerToken) ? trim($headerToken) : '';
}

function deploy_release_plan_configured_token(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM posla_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([RELEASE_PLAN_ACTIONS_TOKEN_KEY]);
    $dbToken = trim((string)($stmt->fetchColumn() ?: ''));
    if ($dbToken !== '') {
        return $dbToken;
    }

    $envToken = getenv('POSLA_RELEASE_PLAN_READ_TOKEN');
    return $envToken === false ? '' : trim((string)$envToken);
}

function deploy_release_plan_require_token(PDO $pdo): void
{
    $expected = deploy_release_plan_configured_token($pdo);
    if ($expected === '') {
        json_error('RELEASE_PLAN_TOKEN_NOT_CONFIGURED', 'Release Plan Actions Token が未設定です', 503);
    }

    $provided = deploy_release_plan_request_token();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        json_error('UNAUTHORIZED', 'Release Plan API token が不正です', 401);
    }
}

deploy_release_plan_require_token($pdo);

$cellData = release_plan_fetch_cells($pdo);
$settings = release_plan_fetch_settings($pdo);
$plan = release_plan_build($settings, $cellData);
$decision = release_plan_deploy_decision($plan);

json_response([
    'available' => true,
    'deploy_allowed' => $decision['allowed'],
    'decision' => $decision,
    'plan' => [
        'status' => $plan['status'],
        'summary' => $plan['summary'],
        'target_scope' => $plan['target_scope'],
        'target_cell_id' => $plan['target_cell_id'],
        'target_cells' => $plan['target_cells'],
        'target_count' => $plan['target_count'],
        'automation_mode' => $plan['automation_mode'],
        'actions_can_run' => $plan['actions_can_run'],
        'note' => $plan['note'],
        'updated_at' => $plan['updated_at'],
    ],
    'policy' => [
        'source_of_truth' => 'POSLA Release Plan',
        'manual_only' => 'Actions must stop.',
        'single_cell' => 'Deploy only the saved target cell.',
        'all_active_cells' => 'Deploy the same code to every active cell returned by POSLA.',
        'feature_flags' => 'Do not change Feature Flags from this endpoint.',
    ],
]);
