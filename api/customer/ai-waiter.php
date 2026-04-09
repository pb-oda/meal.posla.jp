<?php
/**
 * AIウェイター API（認証不要）
 *
 * POST /api/customer/ai-waiter.php
 *
 * 顧客メニュー画面からのチャット質問を受け取り、
 * Gemini API をサーバーサイドで呼び出して返答する。
 * APIキーはフロントに露出しない。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/menu-resolver.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/posla-settings.php';

require_method(['POST']);

// S-1: IPレートリミット（1時間あたり20リクエスト）
check_rate_limit('ai-waiter', 20, 3600);

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$message = $data['message'] ?? '';
$history = $data['history'] ?? [];

if (!$storeId) {
    json_error('MISSING_STORE', 'store_id は必須です', 400);
}
if (trim($message) === '') {
    json_error('MISSING_MESSAGE', 'メッセージを入力してください', 400);
}

$pdo = get_db();

// ── 1. 店舗情報取得 ──
$stmt = $pdo->prepare(
    'SELECT s.id, s.name, s.tenant_id
     FROM stores s
     WHERE s.id = ? AND s.is_active = 1'
);
$stmt->execute([$storeId]);
$store = $stmt->fetch();

if (!$store) {
    json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
}

// Gemini APIキーは POSLA共通設定から取得（P1-6 で統一）
$apiKey = require_gemini_api_key($pdo);

// ── 2. メニュー情報を取得 ──
$categories = resolve_store_menu($pdo, $storeId);
$menuLines = [];
foreach ($categories as $cat) {
    $catName = $cat['categoryName'] ?? '';
    foreach ($cat['items'] as $item) {
        $name    = $item['name'] ?? '';
        $price   = (int) ($item['price'] ?? 0);
        $soldOut = !empty($item['soldOut']) ? '【品切れ】' : '';
        $menuLines[] = $catName . ' | ' . $name . ' | ¥' . number_format($price) . $soldOut;
    }
}
$menuText = implode("\n", $menuLines);

// ── 3. システムプロンプト構築 ──
$storeName = $store['name'];
$systemPrompt = 'あなたは「' . $storeName . '」のAIウェイターです。'
    . 'お客様に親しみやすく・簡潔に日本語で答えてください。' . "\n"
    . '【メニュー情報】' . "\n"
    . $menuText . "\n"
    . '【ルール】' . "\n"
    . '- メニューに関係ない質問には「メニューのことでしたら何でもお聞きください😊」と答える' . "\n"
    . '- 品切れ品は勧めない' . "\n"
    . '- おすすめを聞かれたら1〜3品を具体的に提案する' . "\n"
    . '- カートへの追加を促す（「メニューからお選びいただけます」等）' . "\n"
    . '- 返答は3文以内に収める' . "\n"
    . '- 絵文字を適度に使う' . "\n"
    . '- 前置き・解説禁止。返答のみ出力';

// ── 4. Gemini API用 contents 構築 ──
$contents = [];
// システムプロンプトを最初のuserメッセージとして送信
$contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
$contents[] = ['role' => 'model', 'parts' => [['text' => 'かしこまりました。ご質問をどうぞ！😊']]];

// 会話履歴（最大10往復 = 20メッセージ）
$maxHistory = 20;
$historySlice = array_slice($history, -$maxHistory);
foreach ($historySlice as $h) {
    $role = ($h['role'] === 'model') ? 'model' : 'user';
    $text = $h['content'] ?? '';
    if ($text !== '') {
        $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
    }
}

// 今回のメッセージ
$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

// ── 5. Gemini API 呼び出し ──
$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

$payload = json_encode([
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.8,
        'maxOutputTokens' => 512,
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

// 前置き除去
$replyText = _cleanGeminiResponse($replyText);

// ── 7. suggested_items 抽出 ──
// AIの返答テキスト中に含まれるメニュー名を検出して提案品目として返す
$suggestedItems = [];
foreach ($categories as $cat) {
    foreach ($cat['items'] as $item) {
        $name = $item['name'] ?? '';
        if ($name === '' || !empty($item['soldOut'])) continue;
        if (mb_strpos($replyText, $name) !== false) {
            $suggestedItems[] = [
                'id'    => $item['menuItemId'] ?? '',
                'name'  => $name,
                'price' => (int) ($item['price'] ?? 0),
            ];
        }
    }
}
// 最大3品
$suggestedItems = array_slice($suggestedItems, 0, 3);

json_response([
    'message'        => $replyText,
    'suggested_items' => $suggestedItems,
]);

// ── ヘルパー ──
function _cleanGeminiResponse(string $text): string
{
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
