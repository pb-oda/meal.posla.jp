<?php
/**
 * POSLA管理者 ダッシュボードAPI
 *
 * GET /api/posla/dashboard.php
 * 統計: テナント数・店舗数・ユーザー数・契約構成・最近のテナント
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/tenant-insights-helper.php';

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
]);
