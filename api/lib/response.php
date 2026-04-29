<?php
/**
 * API 共通レスポンス関数
 *
 * 統一フォーマット:
 *   成功: { "ok": true,  "data": {...}, "serverTime": "..." }
 *   エラー: { "ok": false, "error": { "code": "...", "message": "...", "errorNo": "Exxxx" } }
 *
 * Phase B: errorNo は api/lib/error-codes.php の get_error_no() で文字列 code から導出する
 */

require_once __DIR__ . '/error-codes.php';
require_once __DIR__ . '/../config/app.php';

function send_api_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    // S3-#19 (Q3=B): 自ドメインのみ Origin allow
    // 通常は同一オリジン前提だが、将来のサブドメイン分離やローカル開発に備える
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = app_allowed_origins();
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}

/**
 * S3-#19 (Q3=B): preflight 応答 — 自ドメイン Origin のみ 204 を返す
 */
function handle_preflight(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        send_api_headers();
        http_response_code(204);
        exit;
    }
}

function json_response(array $data, int $status_code = 200): void
{
    send_api_headers();
    http_response_code($status_code);

    $body = json_encode([
        'ok'         => true,
        'data'       => $data,
        'serverTime' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // P1-14: json_encode 失敗時のフォールバック
    // 想定: data に不正な UTF-8 / リソース型 / 循環参照などが含まれる場合
    if ($body === false) {
        error_log('json_response: json_encode failed - ' . json_last_error_msg());
        http_response_code(500);
        $fallback = json_encode([
            'ok'    => false,
            'error' => [
                'code'    => 'ENCODE_FAILED',
                'message' => 'レスポンスの生成に失敗しました',
                'status'  => 500,
            ],
            'serverTime' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // 二重フェイルセーフ: フォールバック自体の encode 失敗にも備える
        echo ($fallback === false)
            ? '{"ok":false,"error":{"code":"ENCODE_FAILED","message":"response encode failed","status":500}}'
            : $fallback;
        exit;
    }

    echo $body;
    exit;
}

function json_error(string $code, string $message, int $status_code = 400): void
{
    send_api_headers();
    http_response_code($status_code);

    // Phase B: 文字列 code から E 番号を導出 (未登録なら null)
    $errorNo = function_exists('get_error_no') ? get_error_no($code) : null;

    // Phase B (CB1): error_log テーブルに記録 (失敗時もレスポンスを阻害しない)
    _record_error_log($code, $message, $status_code, $errorNo);

    $body = json_encode([
        'ok'    => false,
        'error' => [
            'code'    => $code,
            'message' => $message,
            'status'  => $status_code,
            'errorNo' => $errorNo,
        ],
        'serverTime' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // P1-14: json_encode 失敗時のフォールバック
    // 想定: message に不正な UTF-8 が含まれる場合等
    if ($body === false) {
        error_log('json_error: json_encode failed - ' . json_last_error_msg() . ' (code=' . $code . ')');
        // 二重フェイルセーフ: ハードコード文字列のみで構成
        echo '{"ok":false,"error":{"code":"ENCODE_FAILED","message":"error response encode failed","status":500}}';
        exit;
    }

    echo $body;
    exit;
}

/**
 * Phase B (CB1): error_log テーブルに記録
 *
 * 失敗時もレスポンスを阻害しない (silent fail)。
 * 認証情報が利用可能なら user/tenant/store を併記。
 * テーブル未作成や DB 接続失敗時もスローしない。
 */
function _record_error_log(string $code, string $message, int $status_code, ?string $errorNo): void
{
    try {
        // db.php がまだ require されていない可能性がある (低レイヤから呼ばれるケース)
        if (!function_exists('get_db')) {
            return;
        }
        $pdo = @get_db();
        if (!$pdo) return;

        // 認証情報の取得を試みる (セッションから直接読む。未認証時は null のまま)
        $tenantId = $userId = $username = $role = null;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            $tenantId = $_SESSION['tenant_id'] ?? null;
            $userId   = $_SESSION['user_id'] ?? null;
            $username = $_SESSION['username'] ?? null;
            $role     = $_SESSION['role'] ?? null;
        }
        // store_id はクエリパラメータ or POST body から拾う
        $storeId = null;
        if (isset($_GET['store_id']) && is_string($_GET['store_id'])) {
            $storeId = substr($_GET['store_id'], 0, 36);
        } else {
            $raw = @file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $body = json_decode($raw, true);
                if (is_array($body) && isset($body['store_id']) && is_string($body['store_id'])) {
                    $storeId = substr($body['store_id'], 0, 36);
                }
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO error_log
                (error_no, code, message, http_status,
                 tenant_id, store_id, user_id, username, role,
                 request_method, request_path, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $errorNo,
            substr($code, 0, 64),
            mb_substr($message, 0, 1000),
            $status_code,
            $tenantId,
            $storeId,
            $userId,
            $username,
            $role,
            $_SERVER['REQUEST_METHOD'] ?? null,
            substr($_SERVER['REQUEST_URI'] ?? '', 0, 255),
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    } catch (Throwable $e) {
        // テーブル未作成やDB障害でレスポンス送出を阻害しない
        @error_log('[CB1] error_log insert failed: ' . $e->getMessage());
    }
}

function get_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === '' || $raw === false) {
        json_error('EMPTY_BODY', 'リクエストボディが空です', 400);
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        json_error('INVALID_JSON', 'リクエストのJSONが不正です', 400);
    }

    return $data;
}

function require_method(array $allowed): string
{
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'OPTIONS') {
        handle_preflight();
    }

    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error(
            'METHOD_NOT_ALLOWED',
            "このエンドポイントは " . implode(', ', $allowed) . " のみ対応しています",
            405
        );
    }

    return $method;
}
