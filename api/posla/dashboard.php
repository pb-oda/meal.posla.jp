<?php
/**
 * POSLA管理者 ダッシュボードAPI
 *
 * GET /api/posla/dashboard.php
 * 統計: テナント数・店舗数・ユーザー数・プラン分布・最近のテナント
 */

require_once __DIR__ . '/auth-helper.php';

require_method(['GET']);
$admin = require_posla_admin();
$pdo = get_db();

// テナント数
$stmt = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE is_active = 1');
$stmt->execute();
$totalTenants = (int)$stmt->fetchColumn();

// 店舗数
$stmt = $pdo->prepare('SELECT COUNT(*) FROM stores WHERE is_active = 1');
$stmt->execute();
$totalStores = (int)$stmt->fetchColumn();

// ユーザー数
$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE is_active = 1');
$stmt->execute();
$totalUsers = (int)$stmt->fetchColumn();

// プラン別分布
$stmt = $pdo->prepare(
    'SELECT plan, COUNT(*) AS count
     FROM tenants
     WHERE is_active = 1
     GROUP BY plan
     ORDER BY FIELD(plan, "standard", "pro", "enterprise")'
);
$stmt->execute();
$planDistribution = $stmt->fetchAll();

// 最近のテナント
$stmt = $pdo->prepare(
    'SELECT id, slug, name, plan, is_active, created_at
     FROM tenants
     ORDER BY created_at DESC
     LIMIT 5'
);
$stmt->execute();
$recentTenants = $stmt->fetchAll();

json_response([
    'totalTenants'     => $totalTenants,
    'totalStores'      => $totalStores,
    'totalUsers'       => $totalUsers,
    'planDistribution' => $planDistribution,
    'recentTenants'    => $recentTenants,
]);
