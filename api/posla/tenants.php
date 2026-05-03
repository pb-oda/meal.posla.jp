<?php
/**
 * POSLA管理者 テナントCRUD API
 *
 * GET   /api/posla/tenants.php          — 全テナント一覧
 * GET   /api/posla/tenants.php?id=xxx   — テナント詳細
 * PATCH  /api/posla/tenants.php         — テナント更新
 * POST   /api/posla/tenants.php         — テナント新規作成
 * DELETE /api/posla/tenants.php         — テナント削除（実運用データがない場合のみ）
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/tenant-insights-helper.php';
require_once __DIR__ . '/../lib/tenant-onboarding.php';
require_once __DIR__ . '/../lib/provisioner-trigger.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH', 'POST', 'DELETE']);
$pdo = get_db();

function normalize_tenant_contract(array $tenant): array
{
    $tenant['plan_compat'] = $tenant['plan'] ?? 'standard';
    $tenant['plan'] = 'standard';
    $tenant['contract_label'] = 'POSLA標準';
    $tenant['hq_menu_broadcast'] = !empty($tenant['hq_menu_broadcast']) ? 1 : 0;

    return $tenant;
}

function build_bootstrap_username(PDO $pdo, string $slug, string $suffix): string
{
    $maxLength = 50;
    $suffixPart = '-' . $suffix;
    $counter = 0;
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');

    while (true) {
        $counterSuffix = $counter > 0 ? ('-' . $counter) : '';
        $baseLimit = $maxLength - strlen($suffixPart) - strlen($counterSuffix);
        $base = substr($slug, 0, max(1, $baseLimit));
        $candidate = $base . $suffixPart . $counterSuffix;

        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }

        $counter++;
    }
}

function build_bootstrap_password(int $length = 16): string
{
    $groups = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnopqrstuvwxyz',
        '23456789',
    ];
    $alphabet = implode('', $groups);
    $passwordChars = [];
    $targetLength = max($length, count($groups));

    foreach ($groups as $group) {
        $passwordChars[] = $group[random_int(0, strlen($group) - 1)];
    }

    for ($i = count($passwordChars); $i < $targetLength; $i++) {
        $passwordChars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    for ($i = count($passwordChars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $passwordChars[$i];
        $passwordChars[$i] = $passwordChars[$j];
        $passwordChars[$j] = $tmp;
    }

    return implode('', $passwordChars);
}

function build_tenant_audit_value(array $tenant): array
{
    return [
        'name' => $tenant['name'] ?? null,
        'slug' => $tenant['slug'] ?? null,
        'is_active' => !empty($tenant['is_active']) ? 1 : 0,
        'hq_menu_broadcast' => !empty($tenant['hq_menu_broadcast']) ? 1 : 0,
        'plan' => $tenant['plan'] ?? 'standard',
    ];
}

function posla_tenant_table_exists(PDO $pdo, string $tableName): bool
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
        $cache[$tableName] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function posla_tenant_count_query(PDO $pdo, string $tableName, string $sql, array $params): int
{
    if (!posla_tenant_table_exists($pdo, $tableName)) {
        return 0;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function posla_tenant_delete_query(PDO $pdo, string $tableName, string $sql, array $params, array &$deletedCounts): void
{
    if (!posla_tenant_table_exists($pdo, $tableName)) {
        return;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    if ($count > 0) {
        if (!isset($deletedCounts[$tableName])) {
            $deletedCounts[$tableName] = 0;
        }
        $deletedCounts[$tableName] += $count;
    }
}

function posla_tenant_delete_blockers(PDO $pdo, string $tenantId): array
{
    $checks = [
        [
            'table' => 'orders',
            'label' => '注文',
            'sql' => 'SELECT COUNT(*) FROM orders o INNER JOIN stores s ON s.id = o.store_id WHERE s.tenant_id = ?',
        ],
        [
            'table' => 'payments',
            'label' => '会計',
            'sql' => 'SELECT COUNT(*) FROM payments p INNER JOIN stores s ON s.id = p.store_id WHERE s.tenant_id = ?',
        ],
        [
            'table' => 'emergency_payments',
            'label' => '緊急会計',
            'sql' => 'SELECT COUNT(*) FROM emergency_payments WHERE tenant_id = ?',
        ],
        [
            'table' => 'reservations',
            'label' => '予約',
            'sql' => 'SELECT COUNT(*) FROM reservations WHERE tenant_id = ?',
        ],
        [
            'table' => 'table_sessions',
            'label' => '来店セッション',
            'sql' => 'SELECT COUNT(*) FROM table_sessions ts INNER JOIN stores s ON s.id = ts.store_id WHERE s.tenant_id = ?',
        ],
        [
            'table' => 'cash_log',
            'label' => 'レジ金ログ',
            'sql' => 'SELECT COUNT(*) FROM cash_log cl INNER JOIN stores s ON s.id = cl.store_id WHERE s.tenant_id = ?',
        ],
        [
            'table' => 'receipts',
            'label' => '領収書',
            'sql' => 'SELECT COUNT(*) FROM receipts WHERE tenant_id = ?',
        ],
        [
            'table' => 'attendance_logs',
            'label' => '勤怠ログ',
            'sql' => 'SELECT COUNT(*) FROM attendance_logs WHERE tenant_id = ?',
        ],
        [
            'table' => 'shift_assignments',
            'label' => 'シフト',
            'sql' => 'SELECT COUNT(*) FROM shift_assignments WHERE tenant_id = ?',
        ],
    ];

    $blockers = [];
    foreach ($checks as $check) {
        $count = posla_tenant_count_query($pdo, $check['table'], $check['sql'], [$tenantId]);
        if ($count > 0) {
            $blockers[] = [
                'label' => $check['label'],
                'table' => $check['table'],
                'count' => $count,
            ];
        }
    }

    return $blockers;
}

function posla_tenant_fetch_cell_ids(PDO $pdo, string $tenantId): array
{
    if (!posla_tenant_table_exists($pdo, 'posla_cell_registry')) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT cell_id FROM posla_cell_registry WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();
    $ids = [];
    foreach ($rows as $row) {
        if (!empty($row['cell_id'])) {
            $ids[] = (string)$row['cell_id'];
        }
    }
    return $ids;
}

function posla_tenant_delete_data(PDO $pdo, string $tenantId): array
{
    $deleted = [];
    $cellIds = posla_tenant_fetch_cell_ids($pdo, $tenantId);

    // Control-plane records and feature flag overrides.
    posla_tenant_delete_query(
        $pdo,
        'posla_feature_flag_overrides',
        "DELETE FROM posla_feature_flag_overrides WHERE scope_type = 'tenant' AND scope_id = ?",
        [$tenantId],
        $deleted
    );
    foreach ($cellIds as $cellId) {
        posla_tenant_delete_query(
            $pdo,
            'posla_feature_flag_overrides',
            "DELETE FROM posla_feature_flag_overrides WHERE scope_type = 'cell' AND scope_id = ?",
            [$cellId],
            $deleted
        );
    }
    posla_tenant_delete_query($pdo, 'posla_tenant_onboarding_requests', 'DELETE FROM posla_tenant_onboarding_requests WHERE tenant_id = ?', [$tenantId], $deleted);
    posla_tenant_delete_query($pdo, 'posla_cell_registry', 'DELETE FROM posla_cell_registry WHERE tenant_id = ?', [$tenantId], $deleted);

    // Records that reference users/help requests must be removed before users.
    posla_tenant_delete_query(
        $pdo,
        'shift_open_shift_applications',
        'DELETE FROM shift_open_shift_applications WHERE tenant_id = ?',
        [$tenantId],
        $deleted
    );
    posla_tenant_delete_query(
        $pdo,
        'shift_help_assignments',
        'DELETE sha FROM shift_help_assignments sha INNER JOIN shift_help_requests shr ON shr.id = sha.help_request_id WHERE shr.tenant_id = ?',
        [$tenantId],
        $deleted
    );
    posla_tenant_delete_query(
        $pdo,
        'shift_help_assignments',
        'DELETE sha FROM shift_help_assignments sha INNER JOIN users u ON u.id = sha.user_id WHERE u.tenant_id = ?',
        [$tenantId],
        $deleted
    );

    $tenantScopedTables = [
        'takeout_order_line_link_tokens',
        'takeout_order_line_links',
        'reservation_customer_line_links',
        'reservation_customer_line_link_tokens',
        'tenant_line_settings',
        'subscription_events',
        'push_send_log',
        'push_subscriptions',
        'monitor_events',
        'error_log',
        'audit_log',
        'device_registration_tokens',
        'attendance_logs',
        'shift_availabilities',
        'shift_task_assignments',
        'shift_task_templates',
        'shift_position_required_skills',
        'shift_staff_skill_tags',
        'shift_skill_tags',
        'shift_open_shifts',
        'shift_swap_requests',
        'shift_assignments',
        'shift_help_requests',
        'shift_work_positions',
        'shift_templates',
        'shift_settings',
        'emergency_payment_orders',
        'emergency_payments',
        'smaregi_product_mapping',
        'smaregi_store_mapping',
        'inventory_consumption_log',
        'menu_translations',
    ];

    foreach ($tenantScopedTables as $table) {
        posla_tenant_delete_query($pdo, $table, 'DELETE FROM `' . $table . '` WHERE tenant_id = ?', [$tenantId], $deleted);
    }

    // Store-scoped operational/support tables. Business tables are already guarded above.
    $storeScopedTables = [
        'reservation_notifications_log',
        'reservation_holds',
        'reservation_courses',
        'reservation_settings',
        'table_sub_sessions',
        'table_sessions',
        'satisfaction_ratings',
        'order_rate_log',
        'cart_events',
        'call_alerts',
        'cash_log',
        'daily_recommendations',
        'store_menu_overrides',
        'store_option_overrides',
        'store_local_items',
        'kds_stations',
        'course_templates',
        'time_limit_plans',
        'tables',
        'store_settings',
    ];

    foreach ($storeScopedTables as $table) {
        posla_tenant_delete_query(
            $pdo,
            $table,
            'DELETE t FROM `' . $table . '` t INNER JOIN stores s ON s.id = t.store_id WHERE s.tenant_id = ?',
            [$tenantId],
            $deleted
        );
    }

    // Tenant-scoped master data. Order matters because several child tables reference these rows.
    $masterTables = [
        'menu_templates',
        'option_groups',
        'ingredients',
        'categories',
        'reservation_customers',
    ];

    foreach ($masterTables as $table) {
        posla_tenant_delete_query($pdo, $table, 'DELETE FROM `' . $table . '` WHERE tenant_id = ?', [$tenantId], $deleted);
    }

    posla_tenant_delete_query(
        $pdo,
        'user_sessions',
        'DELETE FROM user_sessions WHERE tenant_id = ?',
        [$tenantId],
        $deleted
    );
    posla_tenant_delete_query(
        $pdo,
        'user_stores',
        'DELETE us FROM user_stores us INNER JOIN users u ON u.id = us.user_id WHERE u.tenant_id = ?',
        [$tenantId],
        $deleted
    );
    posla_tenant_delete_query(
        $pdo,
        'user_stores',
        'DELETE us FROM user_stores us INNER JOIN stores s ON s.id = us.store_id WHERE s.tenant_id = ?',
        [$tenantId],
        $deleted
    );
    posla_tenant_delete_query($pdo, 'users', 'DELETE FROM users WHERE tenant_id = ?', [$tenantId], $deleted);
    posla_tenant_delete_query($pdo, 'stores', 'DELETE FROM stores WHERE tenant_id = ?', [$tenantId], $deleted);
    posla_tenant_delete_query($pdo, 'tenants', 'DELETE FROM tenants WHERE id = ?', [$tenantId], $deleted);

    return $deleted;
}

// ============================================================
// GET — 一覧 / 詳細
// ============================================================
if ($method === 'GET') {
    $id = $_GET['id'] ?? '';

    if ($id !== '') {
        $insights = posla_apply_op_monitoring_delegation_to_tenants(posla_fetch_tenant_insights($pdo, $id, false));
        $tenant = !empty($insights) ? $insights[0] : null;

        if (!$tenant) {
            json_error('NOT_FOUND', 'テナントが見つかりません', 404);
        }

        $tenant['incident_timeline'] = [];
        $tenant['ops_timeline'] = posla_fetch_tenant_ops_timeline($pdo, $id, 20, false);
        $tenant['investigation_view'] = posla_fetch_tenant_investigation_view($pdo, $id, false);

        json_response(['tenant' => normalize_tenant_contract($tenant)]);
    }

    // 全件一覧
    $tenants = array_map('normalize_tenant_contract', posla_apply_op_monitoring_delegation_to_tenants(posla_fetch_tenant_insights($pdo, null, false)));

    json_response(['tenants' => $tenants]);
}

// ============================================================
// PATCH — 更新
// ============================================================
if ($method === 'PATCH') {
    $input = get_json_body();
    $id = $input['id'] ?? '';

    if (empty($id)) {
        json_error('MISSING_ID', 'テナントIDは必須です', 400);
    }

    // 対象テナント存在確認
    $stmt = $pdo->prepare('SELECT id, slug, name, plan, is_active, hq_menu_broadcast FROM tenants WHERE id = ?');
    $stmt->execute([$id]);
    $existingTenant = $stmt->fetch();
    if (!$existingTenant) {
        json_error('NOT_FOUND', 'テナントが見つかりません', 404);
    }

    $sets = [];
    $params = [];

    // plan
    if (isset($input['plan'])) {
        $plan = (string)$input['plan'];
        if ($plan !== 'standard') {
            json_error('LEGACY_PLAN_UNSUPPORTED', '単一プラン運用です。契約差分は hq_menu_broadcast のみを更新してください', 400);
        }
        $sets[] = 'plan = ?';
        $params[] = 'standard';
    }

    // is_active
    if (isset($input['is_active'])) {
        $sets[] = 'is_active = ?';
        $params[] = $input['is_active'] ? 1 : 0;
    }

    // name
    if (isset($input['name']) && trim($input['name']) !== '') {
        $sets[] = 'name = ?';
        $params[] = trim($input['name']);
    }

    if (isset($input['hq_menu_broadcast'])) {
        $sets[] = 'hq_menu_broadcast = ?';
        $params[] = $input['hq_menu_broadcast'] ? 1 : 0;
    }

    if (empty($sets)) {
        json_error('NO_CHANGES', '更新項目がありません', 400);
    }

    $params[] = $id;
    $sql = 'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);

    $stmt = $pdo->prepare('SELECT id, slug, name, plan, is_active, hq_menu_broadcast FROM tenants WHERE id = ?');
    $stmt->execute([$id]);
    $updatedTenant = $stmt->fetch();
    posla_admin_write_audit_log(
        $pdo,
        $admin,
        'tenant_update',
        'tenant',
        $id,
        build_tenant_audit_value($existingTenant),
        build_tenant_audit_value($updatedTenant),
        null,
        generate_uuid()
    );

    json_response(['message' => 'テナントを更新しました']);
}

// ============================================================
// DELETE — 削除
// ============================================================
if ($method === 'DELETE') {
    $input = get_json_body();
    $id = trim((string)($input['id'] ?? ''));
    $confirmSlug = trim((string)($input['confirm_slug'] ?? ''));
    $billingAck = !empty($input['acknowledge_external_billing']);

    if ($id === '') {
        json_error('MISSING_ID', 'テナントIDは必須です', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT id, slug, name, plan, is_active, hq_menu_broadcast,
                stripe_customer_id, stripe_subscription_id, subscription_status
           FROM tenants
          WHERE id = ?
          LIMIT 1'
    );
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    if (!$tenant) {
        json_error('NOT_FOUND', 'テナントが見つかりません', 404);
    }

    if ($confirmSlug !== (string)$tenant['slug']) {
        json_error('CONFIRM_SLUG_MISMATCH', '削除確認としてテナント slug を正確に入力してください', 400);
    }

    $hasStripeRef = !empty($tenant['stripe_customer_id']) || !empty($tenant['stripe_subscription_id']);
    $activeBilling = in_array((string)($tenant['subscription_status'] ?? ''), ['active', 'trialing', 'past_due'], true);
    if (($hasStripeRef || $activeBilling) && !$billingAck) {
        json_error('EXTERNAL_BILLING_ACK_REQUIRED', 'Stripe側の契約・顧客情報は自動削除されません。外部課金の確認後に再実行してください', 409);
    }

    $blockers = posla_tenant_delete_blockers($pdo, $id);
    if (!empty($blockers)) {
        $labels = [];
        foreach ($blockers as $blocker) {
            $labels[] = $blocker['label'] . ':' . $blocker['count'];
        }
        json_error(
            'TENANT_HAS_OPERATIONAL_DATA',
            '実運用データがあるテナントは削除できません。先に無効化または個別バックアップ/退会手順を実施してください（' . implode(', ', $labels) . '）',
            409
        );
    }

    $oldValue = build_tenant_audit_value($tenant);
    $batchId = generate_uuid();

    $pdo->beginTransaction();
    try {
        $deletedCounts = posla_tenant_delete_data($pdo, $id);
        if (empty($deletedCounts['tenants'])) {
            throw new RuntimeException('tenant row was not deleted');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[api/posla/tenants.php] delete tenant failed: ' . $e->getMessage());
        json_error('DELETE_TENANT_FAILED', 'テナント削除に失敗しました。関連データが残っている可能性があります', 500);
    }

    posla_admin_write_audit_log(
        $pdo,
        $admin,
        'tenant_delete',
        'tenant',
        $id,
        $oldValue,
        [
            'deleted' => true,
            'deleted_counts' => $deletedCounts,
            'external_billing_acknowledged' => $billingAck ? 1 : 0,
        ],
        'POSLA管理画面からテナントを削除',
        $batchId
    );

    json_response([
        'message' => 'テナントを削除しました',
        'deleted_counts' => $deletedCounts,
    ]);
}

// ============================================================
// POST — 新規作成
// ============================================================
if ($method === 'POST') {
    $input = get_json_body();
    $slug = trim($input['slug'] ?? '');
    $name = trim($input['name'] ?? '');
    $plan = 'standard';
    $hqMenuBroadcast = !empty($input['hq_menu_broadcast']) ? 1 : 0;

    if (empty($slug)) {
        json_error('MISSING_SLUG', 'slugは必須です', 400);
    }
    if (strlen($slug) > 50) {
        json_error('SLUG_TOO_LONG', 'slugは50文字以内で入力してください', 400);
    }
    if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
        json_error('INVALID_SLUG', 'slugは半角英小文字・数字・ハイフンのみ使用できます', 400);
    }
    if (empty($name)) {
        json_error('MISSING_NAME', 'テナント名は必須です', 400);
    }

    // slug重複チェック
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        json_error('DUPLICATE_SLUG', 'このslugは既に使用されています', 409);
    }

    $tenantId = generate_uuid();
    $storeId = generate_uuid();
    $storeSlug = 'main';
    $storeName = $name . ' 本店';
    $loginUrl = '/admin/';
    $commonPassword = build_bootstrap_password();
    $passwordHash = password_hash($commonPassword, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        json_error('PASSWORD_HASH_FAILED', '初期パスワードの生成に失敗しました', 500);
    }

    $accounts = [
        [
            'id' => generate_uuid(),
            'role' => 'owner',
            'username' => build_bootstrap_username($pdo, $slug, 'owner'),
            'display_name' => $name . ' オーナー',
            'store_linked' => false,
            'visible_tools' => null,
        ],
        [
            'id' => generate_uuid(),
            'role' => 'manager',
            'username' => build_bootstrap_username($pdo, $slug, 'manager'),
            'display_name' => $name . ' 店長',
            'store_linked' => true,
            'visible_tools' => null,
        ],
        [
            'id' => generate_uuid(),
            'role' => 'staff',
            'username' => build_bootstrap_username($pdo, $slug, 'staff'),
            'display_name' => $name . ' スタッフ',
            'store_linked' => true,
            'visible_tools' => null,
        ],
        [
            'id' => generate_uuid(),
            'role' => 'device',
            'username' => build_bootstrap_username($pdo, $slug, 'kds01'),
            'display_name' => $name . ' KDS',
            'store_linked' => true,
            'visible_tools' => 'kds,register',
        ],
    ];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO tenants (id, slug, name, plan, hq_menu_broadcast) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tenantId, $slug, $name, $plan, $hqMenuBroadcast]);

        $stmt = $pdo->prepare(
            'INSERT INTO stores (id, tenant_id, slug, name, timezone, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$storeId, $tenantId, $storeSlug, $storeName, 'Asia/Tokyo']);

        $stmt = $pdo->prepare('INSERT INTO store_settings (store_id) VALUES (?)');
        $stmt->execute([$storeId]);

        $userStmt = $pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, username, password_hash, display_name, role, is_active)
             VALUES (?, ?, NULL, ?, ?, ?, ?, 1)'
        );
        $linkStmt = $pdo->prepare(
            'INSERT INTO user_stores (user_id, store_id, visible_tools) VALUES (?, ?, ?)'
        );

        foreach ($accounts as $account) {
            $userStmt->execute([
                $account['id'],
                $tenantId,
                $account['username'],
                $passwordHash,
                $account['display_name'],
                $account['role'],
            ]);

            if ($account['store_linked']) {
                $linkStmt->execute([
                    $account['id'],
                    $storeId,
                    $account['visible_tools'],
                ]);
            }
        }

        posla_record_tenant_onboarding_request($pdo, [
            'request_source' => 'posla_admin',
            'status' => 'ready_for_cell',
            'tenant_id' => $tenantId,
            'tenant_slug' => $slug,
            'tenant_name' => $name,
            'store_id' => $storeId,
            'store_slug' => $storeSlug,
            'store_name' => $storeName,
            'owner_user_id' => $accounts[0]['id'],
            'owner_username' => $accounts[0]['username'],
            'owner_display_name' => $accounts[0]['display_name'],
            'requested_store_count' => 1,
            'hq_menu_broadcast' => $hqMenuBroadcast,
            'payload' => [
                'created_by_admin_id' => $admin['id'] ?? null,
                'created_by_admin_email' => $admin['email'] ?? null,
                'entrypoint' => 'public/posla-admin/dashboard.html',
            ],
            'notes' => 'Created from POSLA admin. Ready for cell provisioning.',
        ]);

        $pdo->commit();
        posla_trigger_cell_provisioner($pdo, (string)$tenantId, 'posla_admin_create_tenant');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[api/posla/tenants.php] create tenant failed: ' . $e->getMessage());
        json_error('CREATE_TENANT_FAILED', 'テナント初期構成の作成に失敗しました', 500);
    }

    $stmt = $pdo->prepare('SELECT id, slug, name, plan, is_active, hq_menu_broadcast FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $createdTenant = $stmt->fetch();

    posla_admin_write_audit_log(
        $pdo,
        $admin,
        'tenant_create',
        'tenant',
        $tenantId,
        null,
        build_tenant_audit_value($createdTenant ?: [
            'name' => $name,
            'slug' => $slug,
            'plan' => $plan,
            'is_active' => 1,
            'hq_menu_broadcast' => $hqMenuBroadcast,
        ]),
        null,
        generate_uuid()
    );

    json_response([
        'tenant' => normalize_tenant_contract([
            'id' => $tenantId,
            'slug' => $slug,
            'name' => $name,
            'plan' => $plan,
            'hq_menu_broadcast' => $hqMenuBroadcast,
        ]),
        'bootstrap' => [
            'login_url' => $loginUrl,
            'store' => [
                'id' => $storeId,
                'slug' => $storeSlug,
                'name' => $storeName,
            ],
            'common_password' => $commonPassword,
            'accounts' => array_map(function ($account) {
                return [
                    'role' => $account['role'],
                    'username' => $account['username'],
                    'display_name' => $account['display_name'],
                ];
            }, $accounts),
        ],
    ], 201);
}
