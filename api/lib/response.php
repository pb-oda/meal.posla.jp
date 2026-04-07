<?php
/**
 * API 共通レスポンス関数
 *
 * 統一フォーマット:
 *   成功: { "ok": true,  "data": {...}, "serverTime": "..." }
 *   エラー: { "ok": false, "error": { "code": "...", "message": "..." } }
 */

function send_api_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Cache-Control: no-store');
}

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

    echo json_encode([
        'ok'         => true,
        'data'       => $data,
        'serverTime' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

function json_error(string $code, string $message, int $status_code = 400): void
{
    send_api_headers();
    http_response_code($status_code);

    echo json_encode([
        'ok'    => false,
        'error' => [
            'code'    => $code,
            'message' => $message,
            'status'  => $status_code,
        ],
        'serverTime' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
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
