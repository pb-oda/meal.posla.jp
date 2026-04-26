<?php
/**
 * POSLA tenant onboarding ledger.
 *
 * This records customer creation requests separately from cell provisioning.
 * Web requests never deploy or create Docker cells directly.
 */

function posla_onboarding_table_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute(['posla_tenant_onboarding_requests']);
        $exists = (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        $exists = false;
    }

    return $exists;
}

function posla_onboarding_generate_request_id(): string
{
    if (function_exists('generate_uuid')) {
        return generate_uuid();
    }

    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function posla_onboarding_status(string $status): string
{
    $allowed = [
        'received',
        'payment_pending',
        'ready_for_cell',
        'cell_provisioning',
        'active',
        'failed',
        'canceled',
    ];
    return in_array($status, $allowed, true) ? $status : 'received';
}

function posla_onboarding_string(array $data, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return $default;
    }
    return trim((string)$data[$key]);
}

function posla_onboarding_nullable(array $data, string $key): ?string
{
    $value = posla_onboarding_string($data, $key);
    return $value === '' ? null : $value;
}

function posla_record_tenant_onboarding_request(PDO $pdo, array $data): ?string
{
    if (!posla_onboarding_table_exists($pdo)) {
        return null;
    }

    $requestId = posla_onboarding_string($data, 'request_id');
    if ($requestId === '') {
        $requestId = posla_onboarding_generate_request_id();
    }

    $tenantSlug = posla_onboarding_string($data, 'tenant_slug');
    $tenantName = posla_onboarding_string($data, 'tenant_name');
    if ($tenantSlug === '' || $tenantName === '') {
        throw new InvalidArgumentException('tenant_slug and tenant_name are required for onboarding request');
    }

    $status = posla_onboarding_status(posla_onboarding_string($data, 'status', 'received'));
    $source = posla_onboarding_string($data, 'request_source', 'manual');
    if (!in_array($source, ['lp_signup', 'posla_admin', 'manual'], true)) {
        $source = 'manual';
    }

    $payloadJson = null;
    if (isset($data['payload']) && is_array($data['payload'])) {
        $encoded = json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadJson = $encoded === false ? null : $encoded;
    } elseif (isset($data['payload_json'])) {
        $payloadJson = (string)$data['payload_json'];
    }

    $signupToken = posla_onboarding_string($data, 'signup_token');
    $signupTokenHash = $signupToken !== ''
        ? hash('sha256', $signupToken)
        : posla_onboarding_nullable($data, 'signup_token_sha256');

    $stmt = $pdo->prepare(
        'INSERT INTO posla_tenant_onboarding_requests
            (request_id, request_source, status,
             tenant_id, tenant_slug, tenant_name,
             store_id, store_slug, store_name,
             owner_user_id, owner_username, owner_email, owner_display_name,
             requested_store_count, hq_menu_broadcast, cell_id,
             signup_token_sha256, stripe_customer_id, stripe_subscription_id,
             payload_json, notes, last_error,
             payment_confirmed_at, provisioned_at, activated_at)
         VALUES
            (?, ?, ?,
             ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             request_source = VALUES(request_source),
             status = VALUES(status),
             tenant_slug = VALUES(tenant_slug),
             tenant_name = VALUES(tenant_name),
             store_id = VALUES(store_id),
             store_slug = VALUES(store_slug),
             store_name = VALUES(store_name),
             owner_user_id = VALUES(owner_user_id),
             owner_username = VALUES(owner_username),
             owner_email = VALUES(owner_email),
             owner_display_name = VALUES(owner_display_name),
             requested_store_count = VALUES(requested_store_count),
             hq_menu_broadcast = VALUES(hq_menu_broadcast),
             cell_id = COALESCE(VALUES(cell_id), cell_id),
             signup_token_sha256 = COALESCE(VALUES(signup_token_sha256), signup_token_sha256),
             stripe_customer_id = COALESCE(VALUES(stripe_customer_id), stripe_customer_id),
             stripe_subscription_id = COALESCE(VALUES(stripe_subscription_id), stripe_subscription_id),
             payload_json = COALESCE(VALUES(payload_json), payload_json),
             notes = COALESCE(VALUES(notes), notes),
             last_error = VALUES(last_error),
             payment_confirmed_at = COALESCE(VALUES(payment_confirmed_at), payment_confirmed_at),
             provisioned_at = COALESCE(VALUES(provisioned_at), provisioned_at),
             activated_at = COALESCE(VALUES(activated_at), activated_at),
             updated_at = CURRENT_TIMESTAMP'
    );

    $stmt->execute([
        $requestId,
        $source,
        $status,
        posla_onboarding_nullable($data, 'tenant_id'),
        $tenantSlug,
        $tenantName,
        posla_onboarding_nullable($data, 'store_id'),
        posla_onboarding_nullable($data, 'store_slug'),
        posla_onboarding_nullable($data, 'store_name'),
        posla_onboarding_nullable($data, 'owner_user_id'),
        posla_onboarding_nullable($data, 'owner_username'),
        posla_onboarding_nullable($data, 'owner_email'),
        posla_onboarding_nullable($data, 'owner_display_name'),
        max(1, (int)($data['requested_store_count'] ?? 1)),
        !empty($data['hq_menu_broadcast']) ? 1 : 0,
        posla_onboarding_nullable($data, 'cell_id'),
        $signupTokenHash,
        posla_onboarding_nullable($data, 'stripe_customer_id'),
        posla_onboarding_nullable($data, 'stripe_subscription_id'),
        $payloadJson,
        posla_onboarding_nullable($data, 'notes'),
        posla_onboarding_nullable($data, 'last_error'),
        posla_onboarding_nullable($data, 'payment_confirmed_at'),
        posla_onboarding_nullable($data, 'provisioned_at'),
        posla_onboarding_nullable($data, 'activated_at'),
    ]);

    return $requestId;
}

function posla_update_tenant_onboarding_status(PDO $pdo, string $tenantId, string $status, array $data = []): void
{
    if ($tenantId === '' || !posla_onboarding_table_exists($pdo)) {
        return;
    }

    $sets = ['status = ?', 'updated_at = CURRENT_TIMESTAMP'];
    $params = [posla_onboarding_status($status)];

    $fields = [
        'cell_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'notes',
        'last_error',
        'payment_confirmed_at',
        'provisioned_at',
        'activated_at',
    ];
    foreach ($fields as $field) {
        if (array_key_exists($field, $data)) {
            $sets[] = $field . ' = ?';
            $params[] = $data[$field] === null ? null : (string)$data[$field];
        }
    }

    $params[] = $tenantId;
    $stmt = $pdo->prepare(
        'UPDATE posla_tenant_onboarding_requests
         SET ' . implode(', ', $sets) . '
         WHERE tenant_id = ?'
    );
    $stmt->execute($params);
}

function posla_fetch_onboarding_snapshot(PDO $pdo): array
{
    if (!posla_onboarding_table_exists($pdo)) {
        return ['available' => false, 'by_status' => [], 'pending' => []];
    }

    try {
        $statusRows = $pdo->query(
            'SELECT status, COUNT(*) AS count
             FROM posla_tenant_onboarding_requests
             GROUP BY status
             ORDER BY status'
        )->fetchAll();

        $pendingStmt = $pdo->query(
            "SELECT request_id, request_source, status,
                    tenant_id, tenant_slug, tenant_name,
                    store_id, store_slug, store_name,
                    requested_store_count, hq_menu_broadcast,
                    cell_id, requested_at, payment_confirmed_at, updated_at
             FROM posla_tenant_onboarding_requests
             WHERE status IN ('received','payment_pending','ready_for_cell','cell_provisioning','failed')
             ORDER BY
               CASE status
                 WHEN 'failed' THEN 1
                 WHEN 'ready_for_cell' THEN 2
                 WHEN 'cell_provisioning' THEN 3
                 WHEN 'payment_pending' THEN 4
                 ELSE 5
               END,
               updated_at DESC
             LIMIT 50"
        );

        return [
            'available' => true,
            'by_status' => $statusRows ?: [],
            'pending' => $pendingStmt ? $pendingStmt->fetchAll() : [],
        ];
    } catch (PDOException $e) {
        return ['available' => true, 'by_status' => [], 'pending' => []];
    }
}
