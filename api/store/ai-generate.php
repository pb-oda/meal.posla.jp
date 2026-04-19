<?php
/**
 * Gemini API プロキシ
 *
 * ブラウザから直接Gemini APIを呼び出さず、サーバー経由で呼び出す。
 * APIキーはPOSLA共通設定（posla_settings.gemini_api_key）から取得し、クライアントに露出しない。
 *
 * POST /api/store/ai-generate.php
 * Body: { prompt: "...", temperature?: 0.8, max_tokens?: 1024 }
 * Response: { ok: true, data: { text: "..." }, ... }
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/posla-settings.php';

require_method(['POST']);
$user = require_auth();
$pdo = get_db();

$body = get_json_body();
$prompt = isset($body['prompt']) ? trim((string)$body['prompt']) : '';
if ($prompt === '') {
    json_error('MISSING_PROMPT', 'プロンプトが空です', 400);
}

// パラメータの正規化
$temperature = isset($body['temperature']) ? (float)$body['temperature'] : 0.8;
if ($temperature < 0) $temperature = 0;
if ($temperature > 2) $temperature = 2;

$maxTokens = isset($body['max_tokens']) ? (int)$body['max_tokens'] : 1024;
if ($maxTokens < 1) $maxTokens = 1024;
if ($maxTokens > 8192) $maxTokens = 8192;

// Batch-FINAL: AI ヘルプデスク モード
// mode='helpdesk_tenant' or 'helpdesk_internal' の場合、マニュアル knowledge base を先頭に付与
$mode = isset($body['mode']) ? (string)$body['mode'] : 'default';
if ($mode === 'helpdesk_tenant' || $mode === 'helpdesk_internal') {
    $kbFile = $mode === 'helpdesk_internal'
        ? __DIR__ . '/../../scripts/output/helpdesk-prompt-internal.txt'
        : __DIR__ . '/../../scripts/output/helpdesk-prompt-tenant.txt';
    if (!is_readable($kbFile)) {
        json_error('KNOWLEDGE_BASE_MISSING', 'ヘルプデスク knowledge base が未生成です。POSLA運営に連絡してください', 503);
    }
    // POSLA 管理者のみ internal モード可
    if ($mode === 'helpdesk_internal') {
        $isPoslaAdmin = !empty($user['is_posla_admin']) || ($user['tenant_id'] ?? '') === 'posla';
        if (!$isPoslaAdmin) {
            json_error('FORBIDDEN', 'POSLA運営モードはPOSLA管理者のみ利用可能です', 403);
        }
    }
    $kb = file_get_contents($kbFile);
    // プロンプト構築: knowledge base + user question
    $prompt = $kb . "\n\n==============================================\n# ユーザーからの質問\n==============================================\n\n" . $prompt
            . "\n\n(上記マニュアルに記載された内容を根拠に回答してください。マニュアルに記載なしの場合は「マニュアルに記載がないため、POSLA運営にお問い合わせください」と答えてください。)";
    // ヘルプデスク用に temperature を下げる (正確性優先)
    $temperature = 0.2;
    // ヘルプデスク用に max_tokens を増やす
    $maxTokens = 2048;
}

// POSLA共通設定からGemini APIキーを取得
$apiKey = require_gemini_api_key($pdo);

// Gemini API 呼び出し
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

$payload = json_encode([
    'contents' => [
        ['parts' => [['text' => $prompt]]],
    ],
    'generationConfig' => [
        'temperature' => $temperature,
        'maxOutputTokens' => $maxTokens,
    ],
]);

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 60,
        'ignore_errors' => true,
    ],
]);

$result = @file_get_contents($url, false, $ctx);
if ($result === false) {
    json_error('FETCH_FAILED', 'AI APIの呼び出しに失敗しました', 502);
}

$json = json_decode($result, true);
if ($json === null) {
    json_error('PARSE_FAILED', 'AI APIレスポンスの解析に失敗しました', 502);
}

if (isset($json['error'])) {
    $msg = isset($json['error']['message']) ? $json['error']['message'] : 'AI APIエラー';
    json_error('AI_ERROR', $msg, 502);
}

$text = '';
if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
    $text = $json['candidates'][0]['content']['parts'][0]['text'];
}

json_response(['text' => $text]);
