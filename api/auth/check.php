<?php
/**
 * セッション確認 API（D-1: セッションタイムアウト対応）
 *
 * GET /api/auth/check.php
 *
 * require_auth() を呼ぶことで:
 * - セッション有効性チェック
 * - アイドルタイムアウト判定
 * - last_active_at 更新
 * が実行される。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_method(['GET']);

$user = require_auth();

json_response(['ok' => true]);
