<?php
/**
 * POSLA管理向け テナント健全性 / オンボーディング集計ヘルパー
 */

require_once __DIR__ . '/admin-audit-helper.php';

function posla_fetch_tenant_insights(PDO $pdo, ?string $tenantId = null, bool $includeCellSnapshots = true): array
{
    $where = '';
    $params = [];

    if ($tenantId !== null && $tenantId !== '') {
        $where = 'WHERE t.id = ?';
        $params[] = $tenantId;
    }

    $sql = "
        SELECT
            t.id, t.slug, t.name, t.name_en, t.plan, t.is_active, t.hq_menu_broadcast,
            t.subscription_status, t.current_period_end,
            t.stripe_connect_account_id, t.connect_onboarding_complete,
            t.payment_gateway, t.stripe_secret_key,
            t.created_at, t.updated_at,
            COALESCE(sc.store_count, 0) AS store_count,
            COALESCE(sc.active_store_count, 0) AS active_store_count,
            COALESCE(uc.user_count, 0) AS user_count,
            COALESCE(uc.active_user_count, 0) AS active_user_count,
            COALESCE(uc.owner_count, 0) AS owner_count,
            COALESCE(uc.manager_count, 0) AS manager_count,
            COALESCE(uc.staff_count, 0) AS staff_count,
            COALESCE(uc.device_count, 0) AS device_count,
            COALESCE(ssc.settings_count, 0) AS settings_count,
            COALESCE(cc.category_count, 0) AS category_count,
            COALESCE(mc.menu_count, 0) AS menu_count,
            COALESCE(mc.active_menu_count, 0) AS active_menu_count,
            COALESCE(tc.table_count, 0) AS table_count,
            COALESCE(tc.active_table_count, 0) AS active_table_count,
            COALESCE(me.incident_count_24h, 0) AS incident_count_24h,
            COALESCE(me.open_incident_count, 0) AS open_incident_count,
            COALESCE(me.critical_open_count, 0) AS critical_open_count,
            me.last_incident_at
        FROM tenants t
        LEFT JOIN (
            SELECT
                tenant_id,
                COUNT(*) AS store_count,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_store_count
            FROM stores
            GROUP BY tenant_id
        ) sc ON sc.tenant_id = t.id
        LEFT JOIN (
            SELECT
                tenant_id,
                COUNT(*) AS user_count,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_user_count,
                SUM(CASE WHEN role = 'owner' AND is_active = 1 THEN 1 ELSE 0 END) AS owner_count,
                SUM(CASE WHEN role = 'manager' AND is_active = 1 THEN 1 ELSE 0 END) AS manager_count,
                SUM(CASE WHEN role = 'staff' AND is_active = 1 THEN 1 ELSE 0 END) AS staff_count,
                SUM(CASE WHEN role = 'device' AND is_active = 1 THEN 1 ELSE 0 END) AS device_count
            FROM users
            GROUP BY tenant_id
        ) uc ON uc.tenant_id = t.id
        LEFT JOIN (
            SELECT
                s.tenant_id,
                COUNT(ss.store_id) AS settings_count
            FROM stores s
            LEFT JOIN store_settings ss ON ss.store_id = s.id
            GROUP BY s.tenant_id
        ) ssc ON ssc.tenant_id = t.id
        LEFT JOIN (
            SELECT tenant_id, COUNT(*) AS category_count
            FROM categories
            GROUP BY tenant_id
        ) cc ON cc.tenant_id = t.id
        LEFT JOIN (
            SELECT
                tenant_id,
                COUNT(*) AS menu_count,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_menu_count
            FROM menu_templates
            GROUP BY tenant_id
        ) mc ON mc.tenant_id = t.id
        LEFT JOIN (
            SELECT
                s.tenant_id,
                COUNT(tb.id) AS table_count,
                SUM(CASE WHEN tb.is_active = 1 THEN 1 ELSE 0 END) AS active_table_count
            FROM stores s
            LEFT JOIN tables tb ON tb.store_id = s.id
            GROUP BY s.tenant_id
        ) tc ON tc.tenant_id = t.id
        LEFT JOIN (
            SELECT
                tenant_id,
                SUM(CASE WHEN severity IN ('error', 'critical') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS incident_count_24h,
                SUM(CASE WHEN severity IN ('error', 'critical') AND resolved = 0 THEN 1 ELSE 0 END) AS open_incident_count,
                SUM(CASE WHEN severity = 'critical' AND resolved = 0 THEN 1 ELSE 0 END) AS critical_open_count,
                MAX(CASE WHEN severity IN ('error', 'critical') THEN created_at ELSE NULL END) AS last_incident_at
            FROM monitor_events
            WHERE tenant_id IS NOT NULL
            GROUP BY tenant_id
        ) me ON me.tenant_id = t.id
        $where
        ORDER BY t.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    $result = [];
    $i = 0;

    for ($i = 0; $i < count($rows); $i++) {
        $result[] = posla_build_tenant_insight($rows[$i]);
    }

    if ($includeCellSnapshots) {
        $result = posla_enrich_tenant_insights_from_cell_snapshots($pdo, $result);
    }

    return $result;
}

function posla_enrich_tenant_insights_from_cell_snapshots(PDO $pdo, array $tenants): array
{
    if (empty($tenants) || !posla_insights_table_exists($pdo, 'posla_cell_registry')) {
        return $tenants;
    }

    $registryByTenant = posla_fetch_cell_registry_by_tenant($pdo);
    if (empty($registryByTenant)) {
        return $tenants;
    }

    $secret = getenv('POSLA_OPS_READ_SECRET') ?: getenv('POSLA_CRON_SECRET') ?: '';
    $snapshotCache = [];
    $i = 0;
    for ($i = 0; $i < count($tenants); $i++) {
        $tenantId = (string)($tenants[$i]['id'] ?? '');
        if ($tenantId === '' || empty($registryByTenant[$tenantId])) {
            continue;
        }

        $registry = $registryByTenant[$tenantId];
        $tenants[$i] = posla_attach_cell_registry_to_tenant($tenants[$i], $registry);
        if ($secret === '' || empty($registry['app_base_url']) || ($registry['status'] ?? '') !== 'active') {
            continue;
        }

        $cellId = (string)($registry['cell_id'] ?? '');
        if ($cellId === '') {
            continue;
        }
        if (!array_key_exists($cellId, $snapshotCache)) {
            $snapshotCache[$cellId] = posla_fetch_cell_snapshot_for_insights((string)$registry['app_base_url'], $secret);
        }

        if (!is_array($snapshotCache[$cellId])) {
            $tenants[$i]['cell_snapshot_status'] = 'unavailable';
            continue;
        }

        $cellInsight = posla_find_snapshot_tenant_insight($snapshotCache[$cellId], $tenantId, (string)($tenants[$i]['slug'] ?? ''));
        if ($cellInsight) {
            $tenants[$i] = posla_merge_cell_tenant_insight($tenants[$i], $cellInsight, $snapshotCache[$cellId]);
        }
    }

    return $tenants;
}

function posla_insights_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function posla_fetch_cell_registry_by_tenant(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            'SELECT cell_id, tenant_id, tenant_slug, tenant_name, environment, status,
                    app_base_url, health_url, deploy_version, updated_at
             FROM posla_cell_registry
             WHERE tenant_id IS NOT NULL
               AND tenant_id <> \'\'
             ORDER BY
               CASE status
                 WHEN "active" THEN 1
                 WHEN "maintenance" THEN 2
                 WHEN "provisioning" THEN 3
                 WHEN "planned" THEN 4
                 ELSE 9
               END,
               updated_at DESC'
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
    } catch (PDOException $e) {
        return [];
    }

    $map = [];
    $i = 0;
    for ($i = 0; $i < count($rows); $i++) {
        $tenantId = (string)($rows[$i]['tenant_id'] ?? '');
        if ($tenantId !== '' && empty($map[$tenantId])) {
            $map[$tenantId] = $rows[$i];
        }
    }

    return $map;
}

function posla_attach_cell_registry_to_tenant(array $tenant, array $registry): array
{
    $tenant['cell_id'] = $registry['cell_id'] ?? null;
    $tenant['cell_status'] = $registry['status'] ?? null;
    $tenant['cell_environment'] = $registry['environment'] ?? null;
    $tenant['cell_app_base_url'] = $registry['app_base_url'] ?? null;
    $tenant['cell_health_url'] = $registry['health_url'] ?? null;
    $tenant['cell_deploy_version'] = $registry['deploy_version'] ?? null;
    $tenant['cell_registry_updated_at'] = $registry['updated_at'] ?? null;
    $tenant['cell_snapshot_status'] = 'not_checked';

    return $tenant;
}

function posla_fetch_cell_snapshot_for_insights(string $appBaseUrl, string $secret): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $url = rtrim(posla_build_server_side_cell_base_url($appBaseUrl), '/') . '/api/monitor/cell-snapshot.php';
    $headers = [];
    $opsSecret = getenv('POSLA_OPS_READ_SECRET') ?: '';
    $cronSecret = getenv('POSLA_CRON_SECRET') ?: '';
    if ($opsSecret !== '') {
        $headers[] = 'X-POSLA-OPS-SECRET: ' . $opsSecret;
    }
    if ($cronSecret !== '') {
        $headers[] = 'X-POSLA-CRON-SECRET: ' . $cronSecret;
    }
    if (empty($headers)) {
        $headers[] = 'X-POSLA-CRON-SECRET: ' . $secret;
    }

    $ch = curl_init($url);
    if (!$ch) {
        return null;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 400);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 900);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        return null;
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json) || empty($json['ok']) || !isset($json['data']) || !is_array($json['data'])) {
        return null;
    }

    return $json['data'];
}

function posla_build_server_side_cell_base_url(string $appBaseUrl): string
{
    $parts = parse_url($appBaseUrl);
    if (!is_array($parts) || empty($parts['host'])) {
        return $appBaseUrl;
    }

    $host = (string)$parts['host'];
    if ($host !== '127.0.0.1' && $host !== 'localhost') {
        return $appBaseUrl;
    }

    $alias = getenv('POSLA_CELL_LOCAL_HOST_ALIAS') ?: 'host.docker.internal';
    $scheme = $parts['scheme'] ?? 'http';
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = $parts['path'] ?? '';

    return $scheme . '://' . $alias . $port . $path;
}

function posla_find_snapshot_tenant_insight(array $snapshot, string $tenantId, string $tenantSlug): ?array
{
    $container = $snapshot['tenant_insights'] ?? [];
    $tenants = is_array($container) ? ($container['tenants'] ?? []) : [];
    if (!is_array($tenants)) {
        return null;
    }

    $i = 0;
    for ($i = 0; $i < count($tenants); $i++) {
        if (!is_array($tenants[$i])) {
            continue;
        }
        if ((string)($tenants[$i]['id'] ?? '') === $tenantId) {
            return $tenants[$i];
        }
        if ($tenantSlug !== '' && (string)($tenants[$i]['slug'] ?? '') === $tenantSlug) {
            return $tenants[$i];
        }
    }

    return null;
}

function posla_merge_cell_tenant_insight(array $tenant, array $cellInsight, array $snapshot): array
{
    $copyKeys = [
        'store_count',
        'active_store_count',
        'user_count',
        'active_user_count',
        'owner_count',
        'manager_count',
        'staff_count',
        'device_count',
        'settings_count',
        'category_count',
        'menu_count',
        'active_menu_count',
        'table_count',
        'active_table_count',
        'incident_count_24h',
        'open_incident_count',
        'critical_open_count',
        'last_incident_at',
    ];
    $i = 0;
    for ($i = 0; $i < count($copyKeys); $i++) {
        $key = $copyKeys[$i];
        if (array_key_exists($key, $cellInsight)) {
            $tenant[$key] = $cellInsight[$key];
        }
    }

    $tenant = posla_build_tenant_insight($tenant);
    $tenant['insight_source'] = 'cell_snapshot';
    $tenant['cell_snapshot_status'] = !empty($snapshot['ok']) ? 'ok' : 'warn';
    $tenant['cell_snapshot_at'] = $snapshot['time'] ?? null;
    $tenant['cell_tier0_status'] = $snapshot['tier0']['status'] ?? null;
    $tenant['cell_cron_status'] = $snapshot['cron']['status'] ?? null;
    $tenant['cell_cron_lag_sec'] = $snapshot['cron']['lag_sec'] ?? null;

    return $tenant;
}

function posla_build_tenant_insight(array $tenant): array
{
    $tenant['is_active'] = (int)$tenant['is_active'];
    $tenant['hq_menu_broadcast'] = (int)$tenant['hq_menu_broadcast'];
    $tenant['connect_onboarding_complete'] = (int)$tenant['connect_onboarding_complete'];
    $tenant['store_count'] = (int)$tenant['store_count'];
    $tenant['active_store_count'] = (int)$tenant['active_store_count'];
    $tenant['user_count'] = (int)$tenant['user_count'];
    $tenant['active_user_count'] = (int)$tenant['active_user_count'];
    $tenant['owner_count'] = (int)$tenant['owner_count'];
    $tenant['manager_count'] = (int)$tenant['manager_count'];
    $tenant['staff_count'] = (int)$tenant['staff_count'];
    $tenant['device_count'] = (int)$tenant['device_count'];
    $tenant['settings_count'] = (int)$tenant['settings_count'];
    $tenant['category_count'] = (int)$tenant['category_count'];
    $tenant['menu_count'] = (int)$tenant['menu_count'];
    $tenant['active_menu_count'] = (int)$tenant['active_menu_count'];
    $tenant['table_count'] = (int)$tenant['table_count'];
    $tenant['active_table_count'] = (int)$tenant['active_table_count'];
    $tenant['incident_count_24h'] = (int)$tenant['incident_count_24h'];
    $tenant['open_incident_count'] = (int)$tenant['open_incident_count'];
    $tenant['critical_open_count'] = (int)$tenant['critical_open_count'];

    $tenant['payment_ready'] = posla_is_tenant_payment_ready($tenant);
    $tenant['onboarding_steps'] = posla_build_onboarding_steps($tenant);
    $tenant['onboarding_total_steps'] = count($tenant['onboarding_steps']);
    $tenant['onboarding_completed_steps'] = posla_count_completed_steps($tenant['onboarding_steps']);
    $tenant['onboarding_progress'] = posla_calculate_progress(
        $tenant['onboarding_completed_steps'],
        $tenant['onboarding_total_steps']
    );
    $tenant['next_action'] = posla_suggest_tenant_next_action($tenant);
    $tenant['health_score'] = posla_calculate_health_score($tenant);
    $tenant['health_status'] = posla_get_health_status($tenant['health_score']);
    $tenant['health_label'] = posla_get_health_label($tenant['health_status']);
    $tenant['health_flags'] = posla_build_health_flags($tenant);
    $tenant['risk_priority'] = posla_calculate_risk_priority($tenant);
    $tenant['recent_incident_label'] = posla_build_recent_incident_label($tenant);

    return $tenant;
}

function posla_is_tenant_payment_ready(array $tenant): int
{
    if (($tenant['payment_gateway'] ?? 'none') === 'stripe' && !empty($tenant['stripe_secret_key'])) {
        return 1;
    }
    if (!empty($tenant['stripe_connect_account_id']) && !empty($tenant['connect_onboarding_complete'])) {
        return 1;
    }

    return 0;
}

function posla_build_onboarding_steps(array $tenant): array
{
    return [
        [
            'key' => 'tenant_active',
            'label' => 'テナント有効化',
            'done' => !empty($tenant['is_active']),
        ],
        [
            'key' => 'store',
            'label' => '初期店舗',
            'done' => !empty($tenant['store_count']),
        ],
        [
            'key' => 'settings',
            'label' => '店舗設定',
            'done' => !empty($tenant['store_count']) && $tenant['settings_count'] >= $tenant['store_count'],
        ],
        [
            'key' => 'owner',
            'label' => 'owner',
            'done' => !empty($tenant['owner_count']),
        ],
        [
            'key' => 'ops_users',
            'label' => '運営ユーザー',
            'done' => !empty($tenant['manager_count']) && !empty($tenant['staff_count']) && !empty($tenant['device_count']),
        ],
        [
            'key' => 'tables',
            'label' => '卓設定',
            'done' => !empty($tenant['table_count']),
        ],
        [
            'key' => 'categories',
            'label' => 'カテゴリ',
            'done' => !empty($tenant['category_count']),
        ],
        [
            'key' => 'menus',
            'label' => 'メニュー',
            'done' => !empty($tenant['menu_count']) && !empty($tenant['active_menu_count']),
        ],
    ];
}

function posla_count_completed_steps(array $steps): int
{
    $count = 0;
    $i = 0;

    for ($i = 0; $i < count($steps); $i++) {
        if (!empty($steps[$i]['done'])) {
            $count++;
        }
    }

    return $count;
}

function posla_calculate_progress(int $done, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int)floor(($done / $total) * 100);
}

function posla_suggest_tenant_next_action(array $tenant): string
{
    if (empty($tenant['is_active'])) {
        return '有効化状態を確認';
    }
    if (empty($tenant['store_count'])) {
        return '初期店舗を作成';
    }
    if ($tenant['settings_count'] < $tenant['store_count']) {
        return '店舗設定を補完';
    }
    if (empty($tenant['owner_count'])) {
        return 'owner を作成';
    }
    if (empty($tenant['manager_count']) || empty($tenant['staff_count']) || empty($tenant['device_count'])) {
        return 'manager / staff / device を補完';
    }
    if (empty($tenant['table_count'])) {
        return '卓設定を登録';
    }
    if (empty($tenant['category_count'])) {
        return 'カテゴリを登録';
    }
    if (empty($tenant['menu_count']) || empty($tenant['active_menu_count'])) {
        return 'メニューを登録';
    }
    if (!empty($tenant['critical_open_count']) || !empty($tenant['open_incident_count'])) {
        return '監視イベントを確認';
    }
    if (!empty($tenant['stripe_connect_account_id']) && empty($tenant['connect_onboarding_complete'])) {
        return 'Connect 本人確認を完了';
    }

    return '安定稼働中';
}

function posla_calculate_health_score(array $tenant): int
{
    $score = 0;

    if (!empty($tenant['is_active'])) {
        $score += 15;
    }
    if (!empty($tenant['store_count'])) {
        $score += 15;
    }
    if (!empty($tenant['store_count']) && $tenant['settings_count'] >= $tenant['store_count']) {
        $score += 10;
    }
    if (!empty($tenant['owner_count'])) {
        $score += 6;
    }
    if (!empty($tenant['manager_count'])) {
        $score += 3;
    }
    if (!empty($tenant['staff_count'])) {
        $score += 3;
    }
    if (!empty($tenant['device_count'])) {
        $score += 3;
    }
    if (!empty($tenant['table_count'])) {
        $score += 10;
    }
    if (!empty($tenant['category_count'])) {
        $score += 10;
    }
    if (!empty($tenant['menu_count']) && !empty($tenant['active_menu_count'])) {
        $score += 15;
    }

    if (!empty($tenant['critical_open_count'])) {
        $score += 0;
    } elseif (!empty($tenant['open_incident_count'])) {
        $score += 4;
    } else {
        $score += 10;
    }

    if ($score < 0) {
        return 0;
    }
    if ($score > 100) {
        return 100;
    }

    return $score;
}

function posla_get_health_status(int $score): string
{
    if ($score >= 85) {
        return 'ok';
    }
    if ($score >= 60) {
        return 'warn';
    }

    return 'alert';
}

function posla_get_health_label(string $status): string
{
    if ($status === 'ok') {
        return '健全';
    }
    if ($status === 'warn') {
        return '要確認';
    }

    return '要対応';
}

function posla_build_health_flags(array $tenant): array
{
    $flags = [];

    if (!empty($tenant['critical_open_count'])) {
        $flags[] = 'critical ' . $tenant['critical_open_count'] . ' 件';
    } elseif (!empty($tenant['open_incident_count'])) {
        $flags[] = '未解決異常 ' . $tenant['open_incident_count'] . ' 件';
    } elseif (!empty($tenant['incident_count_24h'])) {
        $flags[] = '24h 異常 ' . $tenant['incident_count_24h'] . ' 件';
    }

    if (empty($tenant['table_count'])) {
        $flags[] = '卓未設定';
    }
    if (empty($tenant['category_count'])) {
        $flags[] = 'カテゴリ未登録';
    }
    if (empty($tenant['menu_count']) || empty($tenant['active_menu_count'])) {
        $flags[] = 'メニュー未登録';
    }
    if (empty($tenant['manager_count']) || empty($tenant['staff_count']) || empty($tenant['device_count'])) {
        $flags[] = '運営ID不足';
    }
    if (!empty($tenant['stripe_connect_account_id']) && empty($tenant['connect_onboarding_complete'])) {
        $flags[] = 'Connect 登録中';
    }

    if (empty($flags)) {
        $flags[] = '安定稼働中';
    }

    return array_slice($flags, 0, 3);
}

function posla_calculate_risk_priority(array $tenant): int
{
    $priority = 100 - (int)$tenant['health_score'];
    $priority += ((int)$tenant['critical_open_count'] * 30);
    $priority += ((int)$tenant['open_incident_count'] * 10);
    $priority += ((int)$tenant['incident_count_24h'] * 3);

    if (empty($tenant['is_active'])) {
        $priority += 10;
    }

    return $priority;
}

function posla_build_recent_incident_label(array $tenant): string
{
    if (!empty($tenant['critical_open_count'])) {
        return 'critical ' . $tenant['critical_open_count'] . ' 件';
    }
    if (!empty($tenant['open_incident_count'])) {
        return '未解決 ' . $tenant['open_incident_count'] . ' 件';
    }
    if (!empty($tenant['incident_count_24h'])) {
        return '24h ' . $tenant['incident_count_24h'] . ' 件';
    }

    return '異常なし';
}

function posla_fetch_tenant_incident_timeline(PDO $pdo, string $tenantId, int $limit = 12, int $days = 14): array
{
    $limit = max(1, min(20, $limit));
    $days = max(1, min(30, $days));

    $sql = "
        SELECT
            me.id,
            me.event_type,
            me.severity,
            me.source,
            me.title,
            me.detail,
            me.store_id,
            COALESCE(s.name, '') AS store_name,
            me.resolved,
            me.resolved_at,
            me.notified_slack,
            me.notified_email,
            me.created_at
        FROM monitor_events me
        LEFT JOIN stores s
          ON s.id = me.store_id
         AND s.tenant_id = me.tenant_id
        WHERE me.tenant_id = ?
          AND me.created_at >= DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
        ORDER BY me.resolved ASC, me.created_at DESC
        LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tenantId]);

    $rows = $stmt->fetchAll();
    $timeline = [];
    $i = 0;

    for ($i = 0; $i < count($rows); $i++) {
        $timeline[] = [
            'id' => $rows[$i]['id'],
            'event_type' => $rows[$i]['event_type'],
            'severity' => $rows[$i]['severity'],
            'source' => $rows[$i]['source'],
            'title' => $rows[$i]['title'],
            'detail' => $rows[$i]['detail'],
            'store_id' => $rows[$i]['store_id'],
            'store_name' => $rows[$i]['store_name'],
            'resolved' => (int)$rows[$i]['resolved'],
            'resolved_at' => $rows[$i]['resolved_at'],
            'notified_slack' => (int)$rows[$i]['notified_slack'],
            'notified_email' => (int)$rows[$i]['notified_email'],
            'created_at' => $rows[$i]['created_at'],
        ];
    }

    return $timeline;
}

function posla_fetch_tenant_ops_timeline(PDO $pdo, string $tenantId, int $limit = 20): array
{
    $incidentRows = posla_fetch_tenant_incident_timeline($pdo, $tenantId, 12, 30);
    $auditRows = posla_admin_fetch_entity_audit_log($pdo, 'tenant', $tenantId, 12);
    $timeline = [];
    $i = 0;

    for ($i = 0; $i < count($incidentRows); $i++) {
        $timeline[] = [
            'timeline_type' => 'incident',
            'tone' => (!empty($incidentRows[$i]['resolved']) ? 'resolved' : ($incidentRows[$i]['severity'] ?: 'info')),
            'created_at' => $incidentRows[$i]['created_at'],
            'title' => $incidentRows[$i]['title'] ?: '監視イベント',
            'detail' => $incidentRows[$i]['detail'],
            'meta_label' => $incidentRows[$i]['source'] ?: ($incidentRows[$i]['event_type'] ?: 'monitor'),
            'status_label' => !empty($incidentRows[$i]['resolved']) ? '解消済み' : '障害',
            'actor_label' => null,
            'store_name' => $incidentRows[$i]['store_name'] ?: null,
            'resolved' => !empty($incidentRows[$i]['resolved']) ? 1 : 0,
            'resolved_at' => $incidentRows[$i]['resolved_at'] ?: null,
        ];
    }

    for ($i = 0; $i < count($auditRows); $i++) {
        $timeline[] = posla_build_tenant_admin_timeline_item($auditRows[$i]);
    }

    usort($timeline, function ($a, $b) {
        $at = strtotime((string)($a['created_at'] ?? ''));
        $bt = strtotime((string)($b['created_at'] ?? ''));
        if ($at === $bt) {
            return 0;
        }
        return ($at > $bt) ? -1 : 1;
    });

    return array_slice($timeline, 0, $limit);
}

function posla_fetch_tenant_investigation_view(PDO $pdo, string $tenantId): array
{
    $incidentRows = posla_fetch_tenant_incident_timeline($pdo, $tenantId, 12, 30);
    $tenantAuditRows = posla_admin_fetch_entity_audit_log($pdo, 'tenant', $tenantId, 12);
    $settingAuditRows = posla_admin_fetch_recent_audit_log($pdo, 24, 'posla_setting');
    $focusIncident = !empty($incidentRows) ? $incidentRows[0] : null;
    $relatedChanges = posla_build_tenant_investigation_changes($tenantAuditRows, $settingAuditRows, $focusIncident);

    return [
        'headline' => posla_build_tenant_investigation_headline($focusIncident, $relatedChanges),
        'cards' => posla_build_tenant_investigation_cards($focusIncident, $relatedChanges, count($incidentRows)),
        'related_changes' => array_slice($relatedChanges, 0, 8),
    ];
}

function posla_build_tenant_investigation_changes(array $tenantAuditRows, array $settingAuditRows, ?array $focusIncident): array
{
    $changes = [];
    $focusTs = 0;
    $windowStart = 0;
    $windowEnd = 0;
    $i = 0;

    if (!empty($focusIncident['created_at'])) {
        $focusTs = strtotime((string)$focusIncident['created_at']);
        if ($focusTs > 0) {
            $windowStart = $focusTs - (24 * 60 * 60);
            $windowEnd = $focusTs + (6 * 60 * 60);
        }
    }

    for ($i = 0; $i < count($tenantAuditRows); $i++) {
        $item = posla_build_tenant_change_investigation_item($tenantAuditRows[$i], $focusTs);
        if (posla_should_include_investigation_change($item, $focusTs, $windowStart, $windowEnd)) {
            $changes[] = $item;
        }
    }

    for ($i = 0; $i < count($settingAuditRows); $i++) {
        $item = posla_build_setting_change_investigation_item($settingAuditRows[$i], $focusTs);
        if (posla_should_include_investigation_change($item, $focusTs, $windowStart, $windowEnd)) {
            $changes[] = $item;
        }
    }

    if ($focusTs > 0) {
        usort($changes, function ($a, $b) {
            $ad = (int)($a['relation_minutes'] ?? 999999);
            $bd = (int)($b['relation_minutes'] ?? 999999);
            if ($ad !== $bd) {
                return ($ad < $bd) ? -1 : 1;
            }

            $at = strtotime((string)($a['created_at'] ?? ''));
            $bt = strtotime((string)($b['created_at'] ?? ''));
            if ($at === $bt) {
                return 0;
            }

            return ($at > $bt) ? -1 : 1;
        });
    } else {
        usort($changes, function ($a, $b) {
            $at = strtotime((string)($a['created_at'] ?? ''));
            $bt = strtotime((string)($b['created_at'] ?? ''));
            if ($at === $bt) {
                return 0;
            }

            return ($at > $bt) ? -1 : 1;
        });
    }

    return $changes;
}

function posla_should_include_investigation_change(array $item, int $focusTs, int $windowStart, int $windowEnd): bool
{
    if (empty($item['created_at'])) {
        return false;
    }

    $createdTs = strtotime((string)$item['created_at']);
    if ($createdTs <= 0) {
        return false;
    }

    if ($focusTs <= 0) {
        return true;
    }

    return $createdTs >= $windowStart && $createdTs <= $windowEnd;
}

function posla_build_tenant_change_investigation_item(array $row, int $focusTs): array
{
    $action = $row['action'] ?? 'tenant_update';
    $newValue = is_array($row['new_value'] ?? null) ? $row['new_value'] : [];
    $oldValue = is_array($row['old_value'] ?? null) ? $row['old_value'] : [];
    $title = ($action === 'tenant_create') ? 'テナントを新規作成' : 'テナント設定を更新';
    $detail = ($action === 'tenant_create')
        ? 'POSLA 管理画面からテナントの初期登録を行いました。'
        : posla_build_tenant_update_diff_text($oldValue, $newValue);

    return posla_finalize_investigation_item([
        'timeline_type' => 'tenant',
        'created_at' => $row['created_at'] ?? null,
        'title' => $title,
        'detail' => $detail,
        'meta_label' => 'tenant',
        'status_label' => 'テナント設定',
        'actor_label' => $row['admin_display_name'] ?: ($row['admin_email'] ?? null),
    ], $focusTs);
}

function posla_build_setting_change_investigation_item(array $row, int $focusTs): array
{
    $newValue = is_array($row['new_value'] ?? null) ? $row['new_value'] : [];
    $oldValue = is_array($row['old_value'] ?? null) ? $row['old_value'] : [];
    $label = $newValue['label'] ?? ($oldValue['label'] ?? ($row['entity_id'] ?? '共通設定'));
    $oldDisplay = $oldValue['display'] ?? '未設定';
    $newDisplay = $newValue['display'] ?? '未設定';
    $action = $row['action'] ?? 'settings_update';
    $title = '共通設定を更新: ' . $label;

    if ($action === 'settings_create') {
        $title = '共通設定を新規登録: ' . $label;
    } elseif ($action === 'settings_clear') {
        $title = '共通設定をクリア: ' . $label;
    }

    return posla_finalize_investigation_item([
        'timeline_type' => 'posla_setting',
        'created_at' => $row['created_at'] ?? null,
        'title' => $title,
        'detail' => '前: ' . $oldDisplay . ' / 後: ' . $newDisplay,
        'meta_label' => 'posla_setting',
        'status_label' => '共通設定',
        'actor_label' => $row['admin_display_name'] ?: ($row['admin_email'] ?? null),
    ], $focusTs);
}

function posla_finalize_investigation_item(array $item, int $focusTs): array
{
    $createdTs = !empty($item['created_at']) ? strtotime((string)$item['created_at']) : 0;
    $direction = '';
    $minutes = 999999;
    $relationLabel = '';
    $tone = 'info';

    if ($focusTs > 0 && $createdTs > 0) {
        $minutes = (int)floor(abs($focusTs - $createdTs) / 60);
        $direction = ($createdTs <= $focusTs) ? 'before' : 'after';
        $relationLabel = '異常の' . $minutes . '分' . ($direction === 'before' ? '前' : '後');

        if ($minutes <= 60) {
            $tone = 'warn';
        } elseif ($minutes <= 360) {
            $tone = 'info';
        } else {
            $tone = 'muted';
        }
    } elseif ($createdTs <= 0) {
        $tone = 'muted';
    }

    $item['relation_direction'] = $direction;
    $item['relation_minutes'] = $minutes;
    $item['relation_label'] = $relationLabel;
    $item['tone'] = $tone;

    return $item;
}

function posla_build_tenant_investigation_headline(?array $focusIncident, array $relatedChanges): string
{
    $nearestBefore = posla_find_investigation_change($relatedChanges, 'before');
    $nearestAfter = posla_find_investigation_change($relatedChanges, 'after');

    if (empty($focusIncident)) {
        if (empty($relatedChanges)) {
            return '直近 30 日は異常も設定変更もありません。いまは安定稼働です。';
        }

        return '異常は出ていません。直近のテナント設定変更と POSLA 共通設定変更を並べて確認できます。';
    }

    if ($nearestBefore && (int)($nearestBefore['relation_minutes'] ?? 999999) <= 360) {
        return '直前変更は「' . ($nearestBefore['title'] ?? '設定変更') . '」で、異常まで ' . (int)$nearestBefore['relation_minutes'] . ' 分でした。まずこの変更の影響範囲を確認してください。';
    }

    if ($nearestAfter && (int)($nearestAfter['relation_minutes'] ?? 999999) <= 60) {
        return '異常のあとに運用変更が入りました。一次切り分けでは「発生時刻」と「変更時刻」の順序を確認してください。';
    }

    if (empty($relatedChanges)) {
        return '直近異常の前後で、POSLA 側の関連変更は見つかっていません。店舗設定や外部連携先を優先して確認してください。';
    }

    return '直近異常の前後に複数の変更候補があります。時刻差が短いものから順に確認してください。';
}

function posla_build_tenant_investigation_cards(?array $focusIncident, array $relatedChanges, int $incidentCount): array
{
    $cards = [];
    $nearestBefore = posla_find_investigation_change($relatedChanges, 'before');
    $nearestAfter = posla_find_investigation_change($relatedChanges, 'after');
    $latestChange = !empty($relatedChanges) ? $relatedChanges[0] : null;
    $incidentTone = 'ok';
    $incidentPill = '異常なし';
    $incidentValue = '直近 30 日に重大イベントはありません';
    $incidentMeta = '監視イベントの切り分けは不要です。';
    $changePill = '変更なし';
    $changeTone = 'muted';
    $changeValue = '異常前 24 時間の変更なし';
    $changeMeta = 'テナント設定 / 共通設定ともに該当なし';
    $correlationTone = 'ok';
    $correlationPill = '平常';
    $correlationValue = '相関対象なし';
    $correlationMeta = '異常と設定変更の相関は発生していません。';

    if (!empty($focusIncident)) {
        if (!empty($focusIncident['resolved'])) {
            $incidentTone = 'muted';
            $incidentPill = '解消済み';
        } elseif (($focusIncident['severity'] ?? '') === 'critical' || ($focusIncident['severity'] ?? '') === 'error') {
            $incidentTone = 'danger';
            $incidentPill = '未解決';
        } else {
            $incidentTone = 'warn';
            $incidentPill = '要確認';
        }

        $incidentValue = $focusIncident['title'] ?? '監視イベント';
        $incidentMeta = '発生 ' . ($focusIncident['created_at'] ?? '-') . ' / 直近 30 日 ' . $incidentCount . ' 件';
    }

    if ($focusIncident && $nearestBefore) {
        $changePill = $nearestBefore['status_label'] ?? '設定変更';
        $changeTone = $nearestBefore['tone'] ?? 'info';
        $changeValue = $nearestBefore['title'] ?? '設定変更';
        $changeMeta = ($nearestBefore['relation_label'] ?: '時刻差未計算') . ' / 変更者: ' . ($nearestBefore['actor_label'] ?: 'POSLA管理');
    } elseif (!$focusIncident && $latestChange) {
        $changePill = $latestChange['status_label'] ?? '設定変更';
        $changeTone = $latestChange['tone'] ?? 'info';
        $changeValue = $latestChange['title'] ?? '設定変更';
        $changeMeta = '変更 ' . ($latestChange['created_at'] ?? '-') . ' / 変更者: ' . ($latestChange['actor_label'] ?: 'POSLA管理');
    }

    if ($nearestBefore && (int)($nearestBefore['relation_minutes'] ?? 999999) <= 360) {
        $correlationTone = 'warn';
        $correlationPill = '要確認';
        $correlationValue = '変更後 ' . (int)$nearestBefore['relation_minutes'] . ' 分で異常';
        $correlationMeta = $nearestBefore['title'] . ' / 変更者: ' . ($nearestBefore['actor_label'] ?: 'POSLA管理');
    } elseif ($nearestAfter && (int)($nearestAfter['relation_minutes'] ?? 999999) <= 60) {
        $correlationTone = 'info';
        $correlationPill = '対応後';
        $correlationValue = '異常後 ' . (int)$nearestAfter['relation_minutes'] . ' 分で変更';
        $correlationMeta = $nearestAfter['title'] . ' / 変更者: ' . ($nearestAfter['actor_label'] ?: 'POSLA管理');
    } elseif (!empty($focusIncident)) {
        $correlationTone = 'muted';
        $correlationPill = '参考';
        $correlationValue = '直前変更なし';
        $correlationMeta = '異常前 24 時間の POSLA 側変更は見つかっていません。';
    }

    $cards[] = [
        'label' => '直近異常',
        'pill_label' => $incidentPill,
        'tone' => $incidentTone,
        'value' => $incidentValue,
        'meta' => $incidentMeta,
    ];

    $cards[] = [
        'label' => '直前変更',
        'pill_label' => $changePill,
        'tone' => $changeTone,
        'value' => $changeValue,
        'meta' => $changeMeta,
    ];

    $cards[] = [
        'label' => '相関メモ',
        'pill_label' => $correlationPill,
        'tone' => $correlationTone,
        'value' => $correlationValue,
        'meta' => $correlationMeta,
    ];

    return $cards;
}

function posla_find_investigation_change(array $changes, string $direction): ?array
{
    $i = 0;

    for ($i = 0; $i < count($changes); $i++) {
        if (($changes[$i]['relation_direction'] ?? '') === $direction) {
            return $changes[$i];
        }
    }

    return null;
}

function posla_build_tenant_admin_timeline_item(array $row): array
{
    $action = $row['action'] ?? 'tenant_update';
    $newValue = is_array($row['new_value'] ?? null) ? $row['new_value'] : [];
    $oldValue = is_array($row['old_value'] ?? null) ? $row['old_value'] : [];
    $detail = '';

    if ($action === 'tenant_create') {
        $detail = 'テナントを新規作成しました。';
    } else {
        $detail = posla_build_tenant_update_diff_text($oldValue, $newValue);
    }

    return [
        'timeline_type' => 'admin',
        'tone' => 'info',
        'created_at' => $row['created_at'] ?? null,
        'title' => ($action === 'tenant_create') ? 'POSLA管理でテナントを作成' : 'POSLA管理でテナント情報を更新',
        'detail' => $detail,
        'meta_label' => 'posla_admin',
        'status_label' => '設定変更',
        'actor_label' => $row['admin_display_name'] ?: ($row['admin_email'] ?? null),
        'store_name' => null,
        'resolved' => 1,
        'resolved_at' => null,
    ];
}

function posla_build_tenant_update_diff_text(array $oldValue, array $newValue): string
{
    $parts = [];

    if (($oldValue['name'] ?? null) !== ($newValue['name'] ?? null)) {
        $parts[] = '名称: ' . ($oldValue['name'] ?? '未設定') . ' → ' . ($newValue['name'] ?? '未設定');
    }
    if ((string)($oldValue['is_active'] ?? '') !== (string)($newValue['is_active'] ?? '')) {
        $parts[] = '状態: ' . (!empty($oldValue['is_active']) ? '有効' : '無効') . ' → ' . (!empty($newValue['is_active']) ? '有効' : '無効');
    }
    if ((string)($oldValue['hq_menu_broadcast'] ?? '') !== (string)($newValue['hq_menu_broadcast'] ?? '')) {
        $parts[] = '本部一括配信: ' . (!empty($oldValue['hq_menu_broadcast']) ? 'ON' : 'OFF') . ' → ' . (!empty($newValue['hq_menu_broadcast']) ? 'ON' : 'OFF');
    }

    if (empty($parts)) {
        return 'テナント情報を更新しました。';
    }

    return implode(' / ', $parts);
}
