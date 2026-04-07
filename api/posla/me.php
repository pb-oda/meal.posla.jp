<?php
/**
 * POSLA管理者 認証チェックAPI
 *
 * GET /api/posla/me.php
 */

require_once __DIR__ . '/auth-helper.php';

require_method(['GET']);

$admin = require_posla_admin();

json_response([
    'admin' => [
        'id'          => $admin['admin_id'],
        'email'       => $admin['email'],
        'displayName' => $admin['display_name'],
    ]
]);
