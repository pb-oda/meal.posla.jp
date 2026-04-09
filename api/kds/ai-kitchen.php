<?php
/**
 * AIキッチンダッシュボード API
 *
 * POST /api/kds/ai-kitchen.php
 *
 * 料理品目単位のリストを受け取り、Gemini APIで調理優先順位を分析して返す。
 * キッチンは「テーブル単位」ではなく「料理単位」で動く設計思想。
 * 認証必要（staff以上）。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/posla-settings.php';

require_method(['POST']);
// P1a: device ロール（KDS端末）からも呼び出せるように staff 制限を撤廃
$user = require_auth();

// S-1: IPレートリミット（1時間あたり60リクエスト、スタッフ認証済みのため緩め）
require_once __DIR__ . '/../lib/rate-limiter.php';
check_rate_limit('ai-kitchen', 60, 3600);

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$items   = $data['items']    ?? [];

if (!$storeId) {
    json_error('MISSING_STORE', 'store_id は必須です', 400);
}

require_store_access($storeId);

$pdo = get_db();

// ── 1. APIキー取得（POSLA共通設定 / P1-6 で統一） ──
$apiKey = require_gemini_api_key($pdo);

// ── 2. 品目なし → 即返却 ──
if (empty($items)) {
    json_response([
        'urgent'      => [],
        'next'        => [],
        'waiting'     => [],
        'summary'     => '現在調理中の品目はありません。準備万端！',
        'pace_status' => 'good',
    ]);
}

// ── 3. 品目テキスト構築 ──
$itemsText = '';
foreach ($items as $it) {
    $table   = $it['table_code']      ?? '?';
    $name    = $it['item_name']       ?? '?';
    $qty     = (int) ($it['qty']      ?? 1);
    $status  = $it['status']          ?? '?';
    $elapsed = (int) ($it['elapsed_seconds'] ?? 0);
    $min     = round($elapsed / 60, 1);
    $itemsText .= $table . ' | ' . $name . ' x' . $qty . ' | ' . $status . ' | 経過' . $min . '分' . "\n";
}

// ── 4. システムプロンプト構築 ──
$systemPrompt = 'あなたはプロの料理長AIアシスタントです。' . "\n"
    . 'キッチンは料理ごとに担当・工程が異なります。' . "\n"
    . '現在の料理品目リストを分析して、品目単位で調理優先順位を指示してください。' . "\n"
    . '【現在の料理品目リスト】' . "\n"
    . $itemsText . "\n"
    . '【優先度の考え方】' . "\n"
    . '- 待ち時間が長い品目を優先' . "\n"
    . '- 調理工程が長い品目（揚げ物・煮込みなど）は早めに着手' . "\n"
    . '- 同じテーブルの品目はなるべく同時仕上げを狙う' . "\n"
    . '- 短時間で仕上がる品目（サラダ・ドリンクなど）は後から合わせる' . "\n"
    . '【出力形式】以下のJSON形式のみ出力。他のテキスト禁止。' . "\n"
    . '{"urgent":[{"table_code":"T01","item_name":"カツ丼","qty":1,"reason":"理由1行"}],'
    . '"next":[{"table_code":"T01","item_name":"品名","qty":1,"reason":"理由1行"}],'
    . '"waiting":[{"table_code":"T02","item_name":"品名","qty":1,"reason":"理由1行"}],'
    . '"summary":"全体の方針を1行で","pace_status":"good|normal|busy"}' . "\n"
    . '前置き・解説・補足は禁止。JSON以外出力禁止。';

// ── 5. Gemini API 呼び出し ──
$contents = [];
$contents[] = ['role' => 'user',  'parts' => [['text' => $systemPrompt]]];
$contents[] = ['role' => 'model', 'parts' => [['text' => '了解。JSON形式で出力します。']]];
$contents[] = ['role' => 'user',  'parts' => [['text' => '分析してください。']]];

$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

$payload = json_encode([
    'contents'         => $contents,
    'generationConfig' => [
        'temperature'     => 0.3,
        'maxOutputTokens' => 1024,
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($geminiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$geminiRaw = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($geminiRaw === false) {
    json_error('GEMINI_NETWORK', 'AI通信エラー: ' . $curlError, 502);
}

$geminiJson = json_decode($geminiRaw, true);
if (!$geminiJson) {
    json_error('GEMINI_PARSE', 'AIレスポンスの解析に失敗しました', 502);
}

if (isset($geminiJson['error'])) {
    json_error('GEMINI_ERROR', $geminiJson['error']['message'] ?? 'AI APIエラー', 502);
}

// ── 6. レスポンステキスト取得 ──
$replyText = '';
if (isset($geminiJson['candidates'][0]['content']['parts'][0]['text'])) {
    $replyText = $geminiJson['candidates'][0]['content']['parts'][0]['text'];
}

$replyText = _cleanGeminiResponse($replyText);

// ── 7. JSON パース ──
$result = json_decode($replyText, true);

if (!$result) {
    // Geminiがマークダウン等で囲んだ場合のフォールバック
    if (preg_match('/\{[\s\S]*\}/', $replyText, $m)) {
        $result = json_decode($m[0], true);
    }
}

if (!$result) {
    json_response([
        'urgent'      => [],
        'next'        => [],
        'waiting'     => [],
        'summary'     => $replyText ?: 'AI分析結果を取得できませんでした',
        'pace_status' => 'normal',
    ]);
}

// ── 8. 正規化して返却 ──
json_response([
    'urgent'      => $result['urgent']      ?? [],
    'next'        => $result['next']        ?? [],
    'waiting'     => $result['waiting']     ?? [],
    'summary'     => $result['summary']     ?? '',
    'pace_status' => $result['pace_status'] ?? 'normal',
]);

// ── ヘルパー ──
function _cleanGeminiResponse(string $text): string
{
    $text = trim($text);
    // マークダウンコードフェンス除去
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/\s*```$/m', '', $text);
    // 前置き行除去
    $lines = explode("\n", trim($text));
    while (count($lines) > 0) {
        $line = trim($lines[0]);
        if ($line === '') {
            array_shift($lines);
            continue;
        }
        if (preg_match('/^(はい|承知|以下|では|了解|かしこまり|わかりました)/', $line)) {
            array_shift($lines);
            continue;
        }
        break;
    }
    return trim(implode("\n", $lines));
}
