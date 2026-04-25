<?php
/**
 * POSLA管理者向け AIコード案内 / 仕様問い合わせ API
 *
 * POST /api/posla/ai-helpdesk.php
 * Body: { prompt: "...", mode?: "helpdesk_internal" }
 * Response: { ok: true, data: { text: "..." }, ... }
 *
 * 注意:
 * - POSLA管理者セッション専用
 * - read-only の knowledge base 回答のみ
 * - internal docs / helpdesk KB を根拠に回答する
 */

require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../lib/posla-settings.php';
require_once __DIR__ . '/../lib/helpdesk-rag.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

if (!function_exists('posla_helpdesk_load_cache')) {
    function posla_helpdesk_load_cache($path, $ttl, $allowStale)
    {
        $cachedJson = '';
        $cachedData = null;
        if (!is_readable($path)) {
            return null;
        }
        if (!$allowStale && (time() - filemtime($path) > $ttl)) {
            return null;
        }
        $cachedJson = file_get_contents($path);
        if ($cachedJson === false || $cachedJson === '') {
            return null;
        }
        $cachedData = json_decode($cachedJson, true);
        if (!is_array($cachedData) || !isset($cachedData['text'])) {
            return null;
        }
        return $cachedData;
    }
}

if (!function_exists('posla_helpdesk_is_quota_error')) {
    function posla_helpdesk_is_quota_error($error)
    {
        $code = isset($error['code']) ? (string)$error['code'] : '';
        $status = isset($error['status']) ? strtolower((string)$error['status']) : '';
        $message = isset($error['message']) ? strtolower((string)$error['message']) : '';

        if ($code === '429') {
            return true;
        }
        if ($status === 'resource_exhausted' || $status === 'too_many_requests') {
            return true;
        }
        if (strpos($message, 'resource exhausted') !== false || strpos($message, '429') !== false) {
            return true;
        }
        return false;
    }
}

if (!function_exists('posla_helpdesk_excerpt')) {
    function posla_helpdesk_excerpt($text, $limit)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '...';
    }
}

if (!function_exists('posla_helpdesk_build_degraded_text')) {
    function posla_helpdesk_build_degraded_text($prompt, $chunks)
    {
        $lines = [];
        $i = 0;
        $lines[] = '現在 AI の利用枠が一時的に上限に達しているため、関連ドキュメントの抜粋から案内します。';
        $lines[] = '必要なら少し時間を置いて同じ質問を再実行してください。';
        $lines[] = '';
        $lines[] = '質問: ' . $prompt;
        $lines[] = '';

        if (empty($chunks)) {
            $lines[] = '関連ドキュメントを十分に特定できませんでした。質問を短く区切るか、画面名・API名を含めて再実行してください。';
            return implode("\n", $lines);
        }

        $lines[] = '関連箇所:';
        foreach ($chunks as $chunk) {
            $file = isset($chunk['file']) ? (string)$chunk['file'] : 'unknown';
            $section = isset($chunk['section']) ? (string)$chunk['section'] : '';
            $text = isset($chunk['text']) ? (string)$chunk['text'] : '';
            $label = '- ' . $file;
            if ($section !== '') {
                $label .= ' / ' . $section;
            }
            $lines[] = $label;
            $lines[] = '  要点: ' . posla_helpdesk_excerpt($text, 180);
            $i++;
            if ($i >= 3) {
                break;
            }
        }
        return implode("\n", $lines);
    }
}

$admin = require_posla_admin();
$method = require_method(['POST']);

// SEC-HOTFIX-20260423-B: POSLA管理者 AI問い合わせ濫用防止
// admin_id を endpoint key に含めることで admin 単位 (× IP) で制限。
// 20 回 / 10 分 — POSLA 管理者は少数かつ通常業務での利用想定。
check_rate_limit('posla-ai-helpdesk:' . $admin['admin_id'], 20, 600);

$pdo = get_db();

$body = get_json_body();
$prompt = isset($body['prompt']) ? trim((string)$body['prompt']) : '';
if ($prompt === '') {
    json_error('MISSING_PROMPT', '質問が空です', 400);
}
$history = isset($body['history']) && is_array($body['history']) ? $body['history'] : [];

$mode = isset($body['mode']) ? (string)$body['mode'] : 'helpdesk_internal';
if ($mode !== 'helpdesk_internal' && $mode !== 'default') {
    json_error('INVALID_MODE', 'POSLA管理画面では internal モードのみ利用できます', 400);
}

$kbFile = __DIR__ . '/../../scripts/output/helpdesk-prompt-internal.txt';
if (!is_readable($kbFile)) {
    json_error('KNOWLEDGE_BASE_MISSING', 'internal knowledge base が未生成です', 503);
}
$ragFile = __DIR__ . '/../../scripts/output/helpdesk-rag-internal.json';
$ragIndex = helpdesk_rag_load_index($ragFile);
$kbVersion = is_file($kbFile) ? (string)filemtime($kbFile) : '0';
$ragVersion = is_file($ragFile) ? (string)filemtime($ragFile) : '0';

$chunkBundle = null;
$retrievedChunks = [];
$sources = [];
$historyText = helpdesk_rag_format_history($history, 3);
$systemPrompt = '';

if ($ragIndex !== null) {
    $retrievedChunks = helpdesk_rag_select_chunks($ragIndex, $prompt, 4);
    $chunkBundle = helpdesk_rag_format_chunks($retrievedChunks);
    $sources = $chunkBundle['sources'];
}

if ($chunkBundle !== null && $chunkBundle['prompt'] !== '') {
    $systemPrompt =
        "# POSLA AI HELPDESK KNOWLEDGE BASE (INTERNAL / RAG)\n\n" .
        "以下の抜粋だけを根拠に回答してください。\n" .
        "- 抜粋に書かれていないことは推測せず「internal docs / KB に明記がない」と答えること\n" .
        "- 回答は read-only の仕様案内に限定し、コード変更・deploy・DB操作を実行するような断定はしないこと\n" .
        "- 可能なら画面名・API名・設定名を明示して、引き継ぎに使える実務的な回答にすること\n\n" .
        "==============================================\n" .
        "# 関連抜粋\n" .
        "==============================================\n\n" .
        $chunkBundle['prompt'] . "\n\n";

    if ($historyText !== '') {
        $systemPrompt .=
            "==============================================\n" .
            "# 直近の会話\n" .
            "==============================================\n\n" .
            $historyText . "\n\n";
    }

    $systemPrompt .=
        "==============================================\n" .
        "# 管理者からの質問\n" .
        "==============================================\n\n" .
        $prompt;
} else {
    $kb = file_get_contents($kbFile);
    $systemPrompt = $kb
        . "\n\n==============================================\n"
        . "# POSLA管理者向け回答ルール\n"
        . "==============================================\n"
        . "- internal docs / knowledge base に書かれた内容を根拠に回答すること。\n"
        . "- 不明点や未記載事項は「internal docs / KB に明記がない」と明示すること。\n"
        . "- 回答は read-only の仕様案内に限定し、コード変更・deploy・DB操作を実行するような断定はしないこと。\n"
        . "- 可能なら画面名・API名・設定名を明示して、引き継ぎに使える実務的な回答にすること。\n";
    if ($historyText !== '') {
        $systemPrompt .= "\n==============================================\n# 直近の会話\n==============================================\n\n" . $historyText . "\n";
    }
    $systemPrompt .= "\n==============================================\n# 管理者からの質問\n==============================================\n\n" . $prompt;
}

$cacheDir = sys_get_temp_dir() . '/posla_helpdesk_cache';
$cacheKey = sha1(implode('|', [
    'internal-rag-v2',
    $mode,
    $prompt,
    $historyText,
    $kbVersion,
    $ragVersion,
]));
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
$cacheTtl = 900;

if (is_readable($cacheFile) && (time() - filemtime($cacheFile) <= $cacheTtl)) {
    $cachedData = posla_helpdesk_load_cache($cacheFile, $cacheTtl, false);
    if ($cachedData !== null) {
        $cachedData['cached'] = true;
        $cachedData['stale'] = false;
        json_response($cachedData);
    }
}

$apiKey = require_gemini_api_key($pdo);
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

$payload = json_encode([
    'contents' => [
        ['parts' => [['text' => $systemPrompt]]],
    ],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 1024,
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
    $staleCache = posla_helpdesk_load_cache($cacheFile, $cacheTtl, true);
    if ($staleCache !== null) {
        $staleCache['cached'] = true;
        $staleCache['stale'] = true;
        $staleCache['degraded'] = true;
        $staleCache['quotaExceeded'] = false;
        $staleCache['notice'] = 'AI API の応答取得に失敗したため、直近のキャッシュ回答を表示しています。';
        json_response($staleCache);
    }
    json_error('FETCH_FAILED', 'AI APIの呼び出しに失敗しました', 502);
}

$json = json_decode($result, true);
if ($json === null) {
    json_error('PARSE_FAILED', 'AI APIレスポンスの解析に失敗しました', 502);
}

if (isset($json['error'])) {
    $msg = isset($json['error']['message']) ? $json['error']['message'] : 'AI APIエラー';
    if (posla_helpdesk_is_quota_error($json['error'])) {
        $staleCache = posla_helpdesk_load_cache($cacheFile, $cacheTtl, true);
        if ($staleCache !== null) {
            $staleCache['cached'] = true;
            $staleCache['stale'] = true;
            $staleCache['degraded'] = true;
            $staleCache['quotaExceeded'] = true;
            $staleCache['notice'] = 'AI 利用枠が一時的に上限のため、直近のキャッシュ回答を表示しています。';
            json_response($staleCache);
        }

        $responseData = [
            'text' => posla_helpdesk_build_degraded_text($prompt, $retrievedChunks),
            'sources' => $sources,
            'retrievedCount' => count($retrievedChunks),
            'cached' => false,
            'stale' => false,
            'degraded' => true,
            'quotaExceeded' => true,
            'notice' => 'AI 利用枠が一時的に上限のため、関連ドキュメントの抜粋を表示しています。',
            'admin' => [
                'admin_id' => $admin['admin_id'],
                'display_name' => $admin['display_name'],
            ],
        ];
        json_response($responseData);
    }
    json_error('AI_ERROR', $msg, 502);
}

$text = '';
if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
    $text = $json['candidates'][0]['content']['parts'][0]['text'];
}

$responseData = [
    'text' => $text,
    'sources' => $sources,
    'retrievedCount' => count($retrievedChunks),
    'cached' => false,
    'stale' => false,
    'degraded' => false,
    'quotaExceeded' => false,
    'admin' => [
        'admin_id' => $admin['admin_id'],
        'display_name' => $admin['display_name'],
    ],
];

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
if (is_dir($cacheDir)) {
    @file_put_contents($cacheFile, json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

json_response($responseData);
