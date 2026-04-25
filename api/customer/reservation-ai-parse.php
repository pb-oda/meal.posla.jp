<?php
/**
 * L-9 予約管理 (客側) — AI 自由文 → 予約情報抽出
 *
 * POST { store_id, text }
 *  - 「明日の夜6時、2人で」「6/20 19:30 4人 田中 アレルギー：エビ」等の自由文を Gemini で構造化
 *  - 返り値: { reserved_at_iso, party_size, customer_name?, memo? }
 *
 * - レートリミット: 1 IP / 分 = 5回 (悪用防止)
 * - 認証: 不要 (Geminiコスト負担はPOSLA運営)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/rate-limiter.php';

require_method(['POST']);
check_rate_limit('reserve-ai:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 5, 60);

$pdo = get_db();
$body = get_json_body();
$storeId = isset($body['store_id']) ? trim($body['store_id']) : '';
$text = isset($body['text']) ? trim((string)$body['text']) : '';
if (!$storeId || $text === '') json_error('MISSING_PARAM', 'store_id と text が必要です', 400);
if (mb_strlen($text) > 1000) json_error('TEXT_TOO_LONG', '入力が長すぎます', 400);

// 設定確認
$sStmt = $pdo->prepare('SELECT online_enabled, ai_chat_enabled, max_party_size, min_party_size FROM reservation_settings WHERE store_id = ?');
$sStmt->execute([$storeId]);
$s = $sStmt->fetch();
if (!$s || (int)$s['online_enabled'] !== 1) json_error('RESERVATION_DISABLED', 'オンライン予約は受け付けていません', 403);
if ((int)$s['ai_chat_enabled'] !== 1) json_error('AI_CHAT_DISABLED', 'AI予約は無効です', 403);

// Gemini API key (posla 共通)
$key = null;
try {
    $kStmt = $pdo->prepare("SELECT setting_value FROM posla_settings WHERE setting_key = 'gemini_api_key'");
    $kStmt->execute();
    $row = $kStmt->fetch();
    if ($row) $key = $row['setting_value'];
} catch (PDOException $e) {
    error_log('[L-9][ai] key_load_failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
}
if (!$key) json_error('AI_NOT_CONFIGURED', 'AI機能が設定されていません', 503);

$nowIso = date('c');
$prompt = "あなたは飲食店予約の自然言語パーサーです。以下の入力から予約情報を抽出して、純粋なJSONのみを出力してください。前置き・解説・コードブロック・補足は禁止です。\n\n基準時刻: {$nowIso} (JST)\n入力: \"\"\"{$text}\"\"\"\n\n出力フォーマット:\n{\n  \"reserved_at\": \"YYYY-MM-DDTHH:MM:00+09:00\" (時刻が読み取れない場合は null),\n  \"party_size\": 整数 (人数が読み取れない場合は null),\n  \"customer_name\": 文字列 or null,\n  \"memo\": 文字列 or null,\n  \"confidence\": 0.0〜1.0の数値,\n  \"missing_fields\": 不足している必須項目の配列 (例: [\"reserved_at\",\"party_size\"])\n}\n\n例1: 入力「明日の夜6時、2人で」→ {\"reserved_at\":\"" . date('Y-m-d', strtotime('+1 day')) . "T18:00:00+09:00\",\"party_size\":2,\"customer_name\":null,\"memo\":null,\"confidence\":0.95,\"missing_fields\":[\"customer_name\"]}\n例2: 入力「来週金曜の昼12時 4名 田中」→ reserved_at は次の金曜日の12:00、party_size=4、customer_name=\"田中\"\n例3: 「予約したい」→ {\"reserved_at\":null,\"party_size\":null,\"customer_name\":null,\"memo\":null,\"confidence\":0.1,\"missing_fields\":[\"reserved_at\",\"party_size\"]}\n\n注意: 解釈不能な場合は無理に埋めず null を入れる。confidence < 0.5 なら missing_fields に必ず該当項目を入れる。";

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($key);
$payload = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 512, 'responseMimeType' => 'application/json'],
]);
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err || $code >= 400) {
    error_log('[L-9][ai] gemini_error code=' . $code . ' err=' . $err . ' body=' . substr((string)$resp, 0, 300), 3, POSLA_PHP_ERROR_LOG);
    json_error('AI_FAILED', 'AIの応答に失敗しました', 503);
}
$data = json_decode($resp, true);
$generated = isset($data['candidates'][0]['content']['parts'][0]['text']) ? $data['candidates'][0]['content']['parts'][0]['text'] : '';
// JSON 周辺の余計な記号を除去 (Geminiは responseMimeType=json で純粋JSONを返す想定だが念のため)
$generated = preg_replace('/^```json\s*|\s*```$/i', '', trim($generated));
$parsed = json_decode($generated, true);
if (!is_array($parsed)) json_error('AI_PARSE_FAILED', 'AI応答を解釈できませんでした', 500);

// 軽くサニタイズ
$reservedAt = isset($parsed['reserved_at']) ? $parsed['reserved_at'] : null;
$partySize = isset($parsed['party_size']) ? (int)$parsed['party_size'] : null;
$customerName = isset($parsed['customer_name']) ? (string)$parsed['customer_name'] : null;
$memo = isset($parsed['memo']) ? (string)$parsed['memo'] : null;
$confidence = isset($parsed['confidence']) ? (float)$parsed['confidence'] : 0;
$missing = isset($parsed['missing_fields']) && is_array($parsed['missing_fields']) ? $parsed['missing_fields'] : [];

if ($partySize !== null) {
    $minP = (int)$s['min_party_size'];
    $maxP = (int)$s['max_party_size'];
    if ($partySize < $minP || $partySize > $maxP) {
        $missing[] = 'party_size_range';
    }
}

json_response([
    'reserved_at' => $reservedAt,
    'party_size' => $partySize,
    'customer_name' => $customerName,
    'memo' => $memo,
    'confidence' => $confidence,
    'missing_fields' => array_values(array_unique($missing)),
]);
