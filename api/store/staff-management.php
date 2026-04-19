<?php
/**
 * スタッフ管理 API（manager用）
 *
 * GET    /api/store/staff-management.php?store_id=xxx[&kind=staff|device]       — 自店舗のstaff/device一覧
 * POST   /api/store/staff-management.php[?kind=staff|device]                    — staff/device新規作成
 * PATCH  /api/store/staff-management.php?id=xxx[&kind=staff|device]             — staff/device更新
 * DELETE /api/store/staff-management.php?id=xxx&store_id=xxx[&kind=staff|device] — staff/device削除
 *
 * manager（レベル2）以上のみアクセス可能
 * 自店舗に紐づくstaff/deviceのみ操作可能
 *
 * P1a: kind パラメータで staff / device を切り替え。デフォルト staff。
 * device は KDS/レジ端末専用アカウント（dashboard.html には遷移しない）。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit-log.php';

// C-D1 / 監査#146: visible_tools ホワイトリスト検証
// 許可値: kds / register / handy (カンマ区切りで複数可)
// null/空文字 → null (店舗設定に従う)
// 不正値 → 400 エラーで即時終了
function _validate_visible_tools($input) {
    if ($input === null || $input === '') return null;
    $allowed = ['kds', 'register', 'handy'];
    $parts = array_filter(array_map('trim', explode(',', (string)$input)), 'strlen');
    $clean = [];
    foreach ($parts as $p) {
        if (!in_array($p, $allowed, true)) {
            json_error('INVALID_VISIBLE_TOOLS', 'visible_tools に不正な値: ' . $p . ' (kds/register/handy のみ可)', 400);
        }
        if (!in_array($p, $clean, true)) $clean[] = $p;
    }
    return empty($clean) ? null : implode(',', $clean);
}
require_once __DIR__ . '/../lib/password-policy.php';

require_method(['GET', 'POST', 'PATCH', 'DELETE']);
$user = require_role('manager');
$pdo = get_db();
$tenantId = $user['tenant_id'];

// P1a: kind パラメータ（staff or device のみ許可）
// POST 時は body 優先、その他は GET クエリ
$kind = 'staff';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $tmpBody = $rawBody ? json_decode($rawBody, true) : null;
    if (is_array($tmpBody) && isset($tmpBody['kind'])) {
        $kind = $tmpBody['kind'];
    } elseif (isset($_GET['kind'])) {
        $kind = $_GET['kind'];
    }
} elseif (isset($_GET['kind'])) {
    $kind = $_GET['kind'];
}
if ($kind !== 'staff' && $kind !== 'device') {
    json_error('INVALID_KIND', 'kind は staff または device のみ指定可能です', 400);
}

// ----- GET -----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $storeId = require_store_param();

    // CP1: cashier_pin_hash 列が存在するかチェック (マイグレーション未適用環境への配慮)
    $hasPinCol = false;
    try {
        $pdo->query('SELECT cashier_pin_hash FROM users LIMIT 0');
        $hasPinCol = true;
    } catch (PDOException $e) {
        // マイグレーション未適用 → cashier_pin_set を false 固定で返す
    }

    $pinSelect = $hasPinCol
        ? ', CASE WHEN u.cashier_pin_hash IS NOT NULL AND u.cashier_pin_hash != "" THEN 1 ELSE 0 END AS cashier_pin_set'
        : ', 0 AS cashier_pin_set';

    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.email, u.display_name, u.role, u.is_active, u.created_at,
                us.visible_tools, us.hourly_rate' . $pinSelect . ',
                GROUP_CONCAT(us2.store_id) AS store_ids,
                GROUP_CONCAT(s2.name) AS store_names
         FROM users u
         INNER JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
         LEFT JOIN user_stores us2 ON us2.user_id = u.id
         LEFT JOIN stores s2 ON s2.id = us2.store_id
         WHERE u.tenant_id = ? AND u.role = ?
         GROUP BY u.id
         ORDER BY u.display_name'
    );
    $stmt->execute([$storeId, $tenantId, $kind]);
    $users = $stmt->fetchAll();

    foreach ($users as &$u) {
        $u['store_ids'] = $u['store_ids'] ? explode(',', $u['store_ids']) : [];
        $u['store_names'] = $u['store_names'] ? explode(',', $u['store_names']) : [];
        // L-3 Phase 2: hourly_rate 型変換
        $u['hourly_rate'] = $u['hourly_rate'] !== null ? (int)$u['hourly_rate'] : null;
    }
    unset($u);

    json_response(['users' => $users]);
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json_body();
    $storeId = $data['store_id'] ?? null;
    if (!$storeId) json_error('MISSING_STORE', 'store_id は必須です', 400);
    require_store_access($storeId);

    $username = trim($data['username'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $displayName = trim($data['display_name'] ?? '');

    // username必須バリデーション
    if (!$username || !$password) json_error('MISSING_FIELDS', 'ユーザー名とパスワードは必須です', 400);
    if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) json_error('INVALID_USERNAME', 'ユーザー名は半角英数字・ハイフン・アンダースコア（3〜50文字）で入力してください', 400);
    validate_password_strength($password);

    // email任意バリデーション
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('INVALID_EMAIL', 'メールアドレスの形式が正しくありません', 400);

    // username重複チェック
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetchColumn() > 0) json_error('DUPLICATE_USERNAME', 'このユーザー名は既に使用されています', 409);

    // email重複チェック（空でなければ）
    if ($email) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) json_error('DUPLICATE_EMAIL', 'このメールアドレスは既に登録されています', 409);
    }

    $id = generate_uuid();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, tenant_id, username, email, password_hash, display_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $tenantId, $username, $email ?: null, $hash, $displayName, $kind]);

        // 店舗割当 (L-3b2: visible_tools対応, L-3 Phase 2: hourly_rate対応)
        $visibleTools = isset($data['visible_tools']) ? _validate_visible_tools($data['visible_tools']) : null;
        $hourlyRate = isset($data['hourly_rate']) ? (int)$data['hourly_rate'] : null;
        if ($hourlyRate !== null && ($hourlyRate < 1 || $hourlyRate > 10000)) $hourlyRate = null;
        $ins = $pdo->prepare('INSERT INTO user_stores (user_id, store_id, visible_tools, hourly_rate) VALUES (?, ?, ?, ?)');
        $chk = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND tenant_id = ?');
        $chk->execute([$storeId, $tenantId]);
        if ($chk->fetch()) {
            $ins->execute([$id, $storeId, $visibleTools, $hourlyRate]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('CREATE_FAILED', 'スタッフ作成に失敗しました', 500);
    }

    write_audit_log($pdo, $user, $storeId, $kind . '_create', 'user', $id, null, ['username' => $username, 'display_name' => $displayName, 'role' => $kind], null);

    json_response(['ok' => true, 'id' => $id], 201);
}

// ----- PATCH -----
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = $_GET['id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);

    // 対象ユーザーが指定 kind かつ同テナントか確認（監査ログ用に変更前の値も取得）
    $stmt = $pdo->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    $target = $stmt->fetch();
    if (!$target) json_error('NOT_FOUND', 'ユーザーが見つかりません', 404);
    if ($target['role'] !== $kind) {
        json_error('FORBIDDEN', ($kind === 'device' ? 'デバイス' : 'スタッフ') . 'のみ編集可能です', 403);
    }

    // 対象が自分の担当店舗に紐づいているか確認
    $myStoreIds = $user['store_ids'];
    if ($user['role'] !== 'owner') {
        $placeholders = implode(',', array_fill(0, count($myStoreIds), '?'));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_stores WHERE user_id = ? AND store_id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$id], $myStoreIds));
        if ($stmt->fetchColumn() == 0) json_error('FORBIDDEN', 'この店舗へのアクセス権がありません', 403);
    }

    $data = get_json_body();
    $fields = [];
    $params = [];

    // username更新
    if (isset($data['username'])) {
        $newUsername = trim($data['username']);
        if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $newUsername)) json_error('INVALID_USERNAME', 'ユーザー名は半角英数字・ハイフン・アンダースコア（3〜50文字）で入力してください', 400);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id != ?');
        $stmt->execute([$newUsername, $id]);
        if ($stmt->fetchColumn() > 0) json_error('DUPLICATE_USERNAME', 'このユーザー名は既に使用されています', 409);
        $fields[] = 'username = ?';
        $params[] = $newUsername;
    }

    if (isset($data['display_name'])) {
        $fields[] = 'display_name = ?';
        $params[] = trim($data['display_name']);
    }
    if (isset($data['is_active'])) {
        $fields[] = 'is_active = ?';
        $params[] = $data['is_active'] ? 1 : 0;
    }
    if (!empty($data['password'])) {
        validate_password_strength($data['password']);
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
    }

    if (!empty($fields)) {
        $params[] = $id;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }

    // S3 #13: マネージャーが他店舗の user_stores を更新できないよう、
    // 指定された store_id が操作者の所属店舗に含まれることを必ず検証する。
    // owner は全店舗アクセス可能なのでスキップ。
    $assertOperatorStore = function($targetStoreId) use ($user, $myStoreIds) {
        if ($user['role'] === 'owner') return;
        if (!in_array($targetStoreId, $myStoreIds, true)) {
            json_error('FORBIDDEN', '指定された店舗への操作権限がありません', 403);
        }
    };

    // L-3b2: visible_tools 更新（店舗スコープ）
    if (array_key_exists('visible_tools', $data)) {
        $data['visible_tools'] = _validate_visible_tools($data['visible_tools']);
        $vtStoreId = isset($data['store_id']) ? $data['store_id'] : (isset($_GET['store_id']) ? $_GET['store_id'] : null);
        if (!$vtStoreId) {
            // パラメータなければ、対象の所属店舗のうちmanagerがアクセス可能な最初の店舗
            $vtStoreId = $myStoreIds[0];
        }
        // S3 #13: 操作者の所属店舗境界チェック
        $assertOperatorStore($vtStoreId);
        $pdo->prepare('UPDATE user_stores SET visible_tools = ? WHERE user_id = ? AND store_id = ?')
            ->execute([$data['visible_tools'], $id, $vtStoreId]);
    }

    // L-3 Phase 2: hourly_rate 更新（店舗スコープ）
    if (array_key_exists('hourly_rate', $data)) {
        $hrStoreId = isset($data['store_id']) ? $data['store_id'] : (isset($_GET['store_id']) ? $_GET['store_id'] : null);
        if (!$hrStoreId) {
            $hrStoreId = $myStoreIds[0];
        }
        // S3 #13: 操作者の所属店舗境界チェック
        $assertOperatorStore($hrStoreId);
        $hrVal = $data['hourly_rate'] !== null && $data['hourly_rate'] !== '' ? (int)$data['hourly_rate'] : null;
        if ($hrVal !== null && ($hrVal < 1 || $hrVal > 10000)) $hrVal = null;
        $pdo->prepare('UPDATE user_stores SET hourly_rate = ? WHERE user_id = ? AND store_id = ?')
            ->execute([$hrVal, $id, $hrStoreId]);
    }

    $auditOld = ['username' => $target['username'], 'display_name' => $target['display_name'], 'is_active' => $target['is_active']];
    $auditNew = array_intersect_key($data, array_flip(['username', 'display_name', 'is_active']));
    if (!empty($data['password'])) $auditNew['password'] = '(変更あり)';
    write_audit_log($pdo, $user, null, $kind . '_update', 'user', $id, $auditOld, $auditNew, null);

    json_response(['ok' => true]);
}

// ----- DELETE -----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $storeId = $_GET['store_id'] ?? null;
    if (!$id) json_error('MISSING_ID', 'idが必要です', 400);
    if (!$storeId) json_error('MISSING_STORE', 'store_id は必須です', 400);
    require_store_access($storeId);

    if ($id === $user['user_id']) json_error('CANNOT_DELETE_SELF', '自分自身は削除できません', 400);

    // 対象ユーザーが指定 kind かつ同テナントか確認（監査ログ用に変更前の値も取得）
    $stmt = $pdo->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    $target = $stmt->fetch();
    if (!$target) json_error('NOT_FOUND', 'ユーザーが見つかりません', 404);
    if ($target['role'] !== $kind) {
        json_error('FORBIDDEN', ($kind === 'device' ? 'デバイス' : 'スタッフ') . 'のみ削除可能です', 403);
    }

    // 対象が指定店舗に紐づいているか確認
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_stores WHERE user_id = ? AND store_id = ?');
    $stmt->execute([$id, $storeId]);
    if ($stmt->fetchColumn() == 0) {
        json_error('FORBIDDEN', 'この店舗の' . ($kind === 'device' ? 'デバイス' : 'スタッフ') . 'ではありません', 403);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_stores WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('DELETE_FAILED', '削除に失敗しました', 500);
    }

    write_audit_log($pdo, $user, $storeId, $kind . '_delete', 'user', $id, ['username' => $target['username'], 'display_name' => $target['display_name']], null, null);

    json_response(['ok' => true]);
}
