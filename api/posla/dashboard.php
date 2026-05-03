<?php
/**
 * POSLA管理者 ダッシュボードAPI
 *
 * GET /api/posla/dashboard.php
 * 統計: テナント数・店舗数・ユーザー数・契約構成・最近のテナント
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/tenant-insights-helper.php';
require_once __DIR__ . '/../lib/tenant-onboarding.php';

require_method(['GET']);
$admin = require_posla_admin();
$pdo = get_db();

function build_contract_distribution(int $totalTenants, int $hqAddonTenants): array
{
    return [
        [
            'plan' => 'standard',
            'label' => 'POSLA標準',
            'count' => $totalTenants,
        ],
        [
            'plan' => 'hq_menu_broadcast',
            'label' => '本部一括配信',
            'count' => $hqAddonTenants,
        ],
    ];
}

function normalize_recent_tenant(array $tenant): array
{
    $tenant['plan_compat'] = $tenant['plan'] ?? 'standard';
    $tenant['plan'] = 'standard';
    $tenant['contract_label'] = 'POSLA標準';
    $tenant['hq_menu_broadcast'] = !empty($tenant['hq_menu_broadcast']) ? 1 : 0;

    return $tenant;
}

function build_risky_tenants(array $tenants): array
{
    $items = array_values(array_filter($tenants, function ($tenant) {
        return !empty($tenant['is_active'])
            && (
                ($tenant['health_status'] ?? '') === 'alert'
                || !empty($tenant['open_incident_count'])
                || !empty($tenant['critical_open_count'])
            );
    }));

    usort($items, function ($a, $b) {
        $riskDiff = ($b['risk_priority'] ?? 0) <=> ($a['risk_priority'] ?? 0);
        if ($riskDiff !== 0) {
            return $riskDiff;
        }

        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    return array_slice($items, 0, 5);
}

function build_onboarding_watchlist(array $tenants): array
{
    $items = array_values(array_filter($tenants, function ($tenant) {
        return !empty($tenant['is_active']) && (int)($tenant['onboarding_progress'] ?? 0) < 100;
    }));

    usort($items, function ($a, $b) {
        $progressDiff = ((int)($a['onboarding_progress'] ?? 0)) <=> ((int)($b['onboarding_progress'] ?? 0));
        if ($progressDiff !== 0) {
            return $progressDiff;
        }

        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    return array_slice($items, 0, 5);
}

function build_release_readiness_check(string $key, string $label, string $status, string $value, string $detail, array $items = []): array
{
    $check = [
        'key' => $key,
        'label' => $label,
        'status' => $status,
        'value' => $value,
        'detail' => $detail,
    ];

    if (!empty($items)) {
        $check['items'] = $items;
    }

    return $check;
}

function count_tenants_by_callback(array $tenants, callable $predicate): int
{
    $count = 0;
    for ($i = 0; $i < count($tenants); $i++) {
        if ($predicate($tenants[$i])) {
            $count++;
        }
    }

    return $count;
}

function sum_tenant_int_field(array $tenants, string $field): int
{
    $total = 0;
    for ($i = 0; $i < count($tenants); $i++) {
        $total += (int)($tenants[$i][$field] ?? 0);
    }

    return $total;
}

function build_release_readiness_items(array $tenants, callable $predicate, int $limit = 4): array
{
    $items = [];
    for ($i = 0; $i < count($tenants); $i++) {
        $tenant = $tenants[$i];
        if (!$predicate($tenant)) {
            continue;
        }

        $items[] = [
            'tenant_id' => $tenant['id'] ?? null,
            'tenant_slug' => $tenant['slug'] ?? null,
            'tenant_name' => $tenant['name'] ?? null,
            'cell_id' => $tenant['cell_id'] ?? null,
            'cell_app_base_url' => $tenant['cell_app_base_url'] ?? null,
            'cell_snapshot_status' => $tenant['cell_snapshot_status'] ?? null,
            'cell_tier0_status' => $tenant['cell_tier0_status'] ?? null,
            'open_incident_count' => (int)($tenant['open_incident_count'] ?? 0),
            'critical_open_count' => (int)($tenant['critical_open_count'] ?? 0),
            'last_incident_at' => $tenant['last_incident_at'] ?? null,
            'recent_incident_label' => $tenant['recent_incident_label'] ?? null,
        ];

        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function build_release_readiness(array $activeTenants, array $cellOnboarding): array
{
    $total = count($activeTenants);
    $pendingCells = !empty($cellOnboarding['pending']) ? count($cellOnboarding['pending']) : 0;
    $missingCell = count_tenants_by_callback($activeTenants, function ($tenant) {
        return empty($tenant['cell_id']);
    });
    $snapshotBad = count_tenants_by_callback($activeTenants, function ($tenant) {
        return !empty($tenant['cell_id']) && ($tenant['cell_snapshot_status'] ?? '') !== 'ok';
    });
    $tier0Unknown = count_tenants_by_callback($activeTenants, function ($tenant) {
        return !empty($tenant['cell_id']) && ($tenant['cell_snapshot_status'] ?? '') !== 'ok';
    });
    $tier0Bad = count_tenants_by_callback($activeTenants, function ($tenant) {
        return !empty($tenant['cell_id'])
            && ($tenant['cell_snapshot_status'] ?? '') === 'ok'
            && ($tenant['cell_tier0_status'] ?? '') !== 'ok';
    });
    $onboardingIncomplete = count_tenants_by_callback($activeTenants, function ($tenant) {
        return (int)($tenant['onboarding_progress'] ?? 0) < 100;
    });
    $healthWarn = count_tenants_by_callback($activeTenants, function ($tenant) {
        return ($tenant['health_status'] ?? '') !== 'ok';
    });
    $openIncidentTenants = count_tenants_by_callback($activeTenants, function ($tenant) {
        return !empty($tenant['open_incident_count']) || !empty($tenant['critical_open_count']);
    });
    $openIncidentEvents = sum_tenant_int_field($activeTenants, 'open_incident_count');
    $snapshotItems = build_release_readiness_items($activeTenants, function ($tenant) {
        return !empty($tenant['cell_id']) && ($tenant['cell_snapshot_status'] ?? '') !== 'ok';
    });
    $tier0Items = build_release_readiness_items($activeTenants, function ($tenant) {
        return !empty($tenant['cell_id'])
            && (
                ($tenant['cell_snapshot_status'] ?? '') !== 'ok'
                || (($tenant['cell_snapshot_status'] ?? '') === 'ok' && ($tenant['cell_tier0_status'] ?? '') !== 'ok')
            );
    });
    $incidentItems = build_release_readiness_items($activeTenants, function ($tenant) {
        return !empty($tenant['open_incident_count']) || !empty($tenant['critical_open_count']);
    });

    $checks = [
        build_release_readiness_check(
            'active_tenants',
            '顧客cell',
            $total > 0 ? 'ok' : 'warn',
            (string)$total,
            $total > 0 ? 'active tenant を監視対象として確認しています。' : '本番前の検証用 tenant を少なくとも1件用意してください。'
        ),
        build_release_readiness_check(
            'cell_queue',
            'Cell作成待ち',
            $pendingCells === 0 ? 'ok' : 'warn',
            (string)$pendingCells,
            $pendingCells === 0 ? '配備待ちの顧客はありません。' : 'Cell配備タブで作成待ちを処理してください。'
        ),
        build_release_readiness_check(
            'cell_registry',
            '1 tenant / 1 cell',
            $missingCell === 0 ? 'ok' : 'fail',
            $missingCell === 0 ? 'OK' : (string)$missingCell,
            $missingCell === 0 ? 'active tenant は cell registry と紐づいています。' : 'cell_id が未連携の active tenant があります。'
        ),
        build_release_readiness_check(
            'cell_snapshot',
            'Cell snapshot',
            $snapshotBad === 0 ? 'ok' : 'warn',
            $snapshotBad === 0 ? 'OK' : (string)$snapshotBad,
            $snapshotBad === 0 ? 'control から各cellの read-only snapshot を取得できています。' : 'snapshot が未取得の cell があります。対象の Cell URL を確認してください。',
            $snapshotItems
        ),
        build_release_readiness_check(
            'tier0',
            'Tier0',
            $tier0Bad > 0 ? 'fail' : ($tier0Unknown > 0 ? 'warn' : 'ok'),
            $tier0Bad > 0 ? (string)$tier0Bad : ($tier0Unknown > 0 ? '未判定' : 'OK'),
            $tier0Bad > 0
                ? '決済・レジ系に要確認の cell があります。'
                : ($tier0Unknown > 0 ? 'snapshot 未取得のため Tier0 は未判定です。まず対象の Cell URL を確認してください。' : '決済・レジ系の監視ステータスは正常です。'),
            $tier0Items
        ),
        build_release_readiness_check(
            'onboarding',
            '導入完了',
            $onboardingIncomplete === 0 ? 'ok' : 'warn',
            $onboardingIncomplete === 0 ? '100%' : (string)$onboardingIncomplete,
            $onboardingIncomplete === 0 ? '初期店舗・ユーザー・卓・カテゴリ・メニューが揃っています。' : '初期設定が未完了の tenant があります。'
        ),
        build_release_readiness_check(
            'health',
            '健全性',
            $healthWarn === 0 ? 'ok' : 'warn',
            $healthWarn === 0 ? 'OK' : (string)$healthWarn,
            $healthWarn === 0 ? 'active tenant の健全性は ok です。' : '健全性が warn/alert の tenant があります。'
        ),
        build_release_readiness_check(
            'incidents',
            '未解決異常',
            $openIncidentTenants === 0 ? 'ok' : 'fail',
            $openIncidentEvents === 0 ? '0' : ((string)$openIncidentEvents . '件'),
            $openIncidentTenants === 0 ? '未解決の重大監視イベントはありません。' : '未解決イベントが残っています。原因確認後に解消済みにしてください。',
            $incidentItems
        ),
    ];

    $failures = count_tenants_by_callback($checks, function ($check) {
        return ($check['status'] ?? '') === 'fail';
    });
    $warnings = count_tenants_by_callback($checks, function ($check) {
        return ($check['status'] ?? '') === 'warn';
    });
    $okChecks = count_tenants_by_callback($checks, function ($check) {
        return ($check['status'] ?? '') === 'ok';
    });
    $status = $failures > 0 ? 'fail' : ($warnings > 0 ? 'warn' : 'ok');

    return [
        'status' => $status,
        'label' => $status === 'ok' ? 'リリース準備OK' : ($status === 'warn' ? '要確認あり' : '要対応あり'),
        'completed_checks' => $okChecks,
        'total_checks' => count($checks),
        'failure_count' => $failures,
        'warning_count' => $warnings,
        'checks' => $checks,
    ];
}

$tenantInsights = posla_fetch_tenant_insights($pdo);
$activeTenants = array_values(array_filter($tenantInsights, function ($tenant) {
    return !empty($tenant['is_active']);
}));

$totalTenants = count($activeTenants);
$totalStores = 0;
$totalUsers = 0;
$hqAddonTenants = 0;
$healthTotal = 0;
$alertTenantCount = 0;
$onboardingTenantCount = 0;
$i = 0;

for ($i = 0; $i < count($activeTenants); $i++) {
    $totalStores += (int)$activeTenants[$i]['store_count'];
    $totalUsers += (int)$activeTenants[$i]['user_count'];
    $hqAddonTenants += !empty($activeTenants[$i]['hq_menu_broadcast']) ? 1 : 0;
    $healthTotal += (int)$activeTenants[$i]['health_score'];
    if (($activeTenants[$i]['health_status'] ?? '') === 'alert' || !empty($activeTenants[$i]['open_incident_count'])) {
        $alertTenantCount++;
    }
    if ((int)($activeTenants[$i]['onboarding_progress'] ?? 0) < 100) {
        $onboardingTenantCount++;
    }
}

$planDistribution = build_contract_distribution($totalTenants, $hqAddonTenants);
$recentTenants = array_map('normalize_recent_tenant', array_slice($tenantInsights, 0, 5));
$averageHealthScore = $totalTenants > 0 ? (int)floor($healthTotal / $totalTenants) : 0;
$riskyTenants = array_map('normalize_recent_tenant', build_risky_tenants($tenantInsights));
$onboardingWatchlist = array_map('normalize_recent_tenant', build_onboarding_watchlist($tenantInsights));
$cellOnboarding = posla_fetch_onboarding_snapshot($pdo);
$cellOnboardingPendingCount = !empty($cellOnboarding['pending']) ? count($cellOnboarding['pending']) : 0;
$releaseReadiness = build_release_readiness($activeTenants, $cellOnboarding);

json_response([
    'totalTenants'     => $totalTenants,
    'totalStores'      => $totalStores,
    'totalUsers'       => $totalUsers,
    'averageHealthScore' => $averageHealthScore,
    'alertTenantCount' => $alertTenantCount,
    'onboardingTenantCount' => $onboardingTenantCount,
    'planDistribution' => $planDistribution,
    'hqAddonTenants'   => $hqAddonTenants,
    'recentTenants'    => $recentTenants,
    'riskyTenants'     => $riskyTenants,
    'onboardingWatchlist' => $onboardingWatchlist,
    'cellOnboardingPendingCount' => $cellOnboardingPendingCount,
    'cellOnboarding' => $cellOnboarding,
    'releaseReadiness' => $releaseReadiness,
]);
