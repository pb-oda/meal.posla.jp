<?php
/**
 * POSLA管理者 テナントCRUD API
 *
 * GET   /api/posla/tenants.php          — 全テナント一覧
 * GET   /api/posla/tenants.php?id=xxx   — テナント詳細
 * PATCH /api/posla/tenants.php          — テナント更新
 * POST  /api/posla/tenants.php          — テナント新規作成
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/tenant-insights-helper.php';
require_once __DIR__ . '/../lib/tenant-onboarding.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH', 'POST']);
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

// ============================================================
// GET — 一覧 / 詳細
// ============================================================
if ($method === 'GET') {
    $id = $_GET['id'] ?? '';

    if ($id !== '') {
        $insights = posla_fetch_tenant_insights($pdo, $id);
        $tenant = !empty($insights) ? $insights[0] : null;

        if (!$tenant) {
            json_error('NOT_FOUND', 'テナントが見つかりません', 404);
        }

        $tenant['incident_timeline'] = posla_fetch_tenant_incident_timeline($pdo, $id);
        $tenant['ops_timeline'] = posla_fetch_tenant_ops_timeline($pdo, $id);
        $tenant['investigation_view'] = posla_fetch_tenant_investigation_view($pdo, $id);

        json_response(['tenant' => normalize_tenant_contract($tenant)]);
    }

    // 全件一覧
    $tenants = array_map('normalize_tenant_contract', posla_fetch_tenant_insights($pdo));

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
    $commonPassword = 'Demo1234';
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
