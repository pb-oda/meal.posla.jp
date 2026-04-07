<?php
/**
 * L-4: メニュー一括翻訳 API
 *
 * POST /api/store/translate-menu.php
 *
 * リクエスト:
 *   { "store_id": "xxx", "langs": ["zh-Hans", "zh-Hant", "ko"] }
 *
 * Gemini APIでメニュー項目を翻訳し、menu_translations テーブルにキャッシュ保存する。
 * 既に翻訳済みのアイテムはスキップ（force: true で再翻訳）。
 *
 * 認証: owner / manager 必須
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/menu-resolver.php';

require_method(['POST']);
$user = require_auth();
$role = $user['role'];
if (!in_array($role, ['owner', 'manager'])) {
    json_error('FORBIDDEN', '権限がありません', 403);
}

$data = get_json_body();
$storeId = $data['store_id'] ?? null;
$langs   = $data['langs'] ?? ['zh-Hans', 'zh-Hant', 'ko'];
$force   = !empty($data['force']);

if (!$storeId) {
    json_error('MISSING_STORE', 'store_id は必須です', 400);
}

// 許可された言語のみ
$allowedLangs = ['en', 'zh-Hans', 'zh-Hant', 'ko'];
$langs = array_values(array_intersect($langs, $allowedLangs));
if (empty($langs)) {
    json_error('INVALID_LANGS', '有効な言語が指定されていません', 400);
}

$pdo = get_db();
$tenantId = $user['tenant_id'];

// 店舗がテナントに属するか確認
$stmt = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND tenant_id = ? AND is_active = 1');
$stmt->execute([$storeId, $tenantId]);
if (!$stmt->fetch()) {
    json_error('STORE_NOT_FOUND', '店舗が見つかりません', 404);
}

// ── 1. Gemini APIキー取得（POSLA共通設定） ──
$apiKey = '';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM posla_settings WHERE setting_key = 'gemini_api_key'");
    $stmt->execute();
    $row = $stmt->fetch();
    $apiKey = $row ? ($row['setting_value'] ?? '') : '';
} catch (PDOException $e) {
    // posla_settings テーブルなし → テナントのai_api_keyにフォールバック
}

if ($apiKey === '') {
    // フォールバック: テナント個別キー
    $stmt = $pdo->prepare('SELECT ai_api_key FROM tenants WHERE id = ?');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    $apiKey = $row ? ($row['ai_api_key'] ?? '') : '';
}

if ($apiKey === '') {
    json_error('AI_NOT_CONFIGURED', 'Gemini APIキーが設定されていません', 503);
}

// ── 2. 翻訳対象のメニュー項目を収集 ──
$categories = resolve_store_menu($pdo, $storeId);

$items = [];     // 翻訳対象アイテム
$optGroups = []; // オプショングループ
$optChoices = []; // オプション選択肢

// カテゴリ自体も翻訳対象
$catList = [];
foreach ($categories as $cat) {
    $catList[] = [
        'entity_type' => 'category',
        'entity_id'   => $cat['categoryId'],
        'name'        => $cat['categoryName'],
    ];

    foreach ($cat['items'] as $item) {
        $entityType = $item['source'] === 'local' ? 'local_item' : 'menu_item';
        $items[] = [
            'entity_type' => $entityType,
            'entity_id'   => $item['menuItemId'],
            'name'        => $item['name'],
            'description' => $item['description'] ?? '',
        ];

        // オプション
        if (!empty($item['optionGroups'])) {
            foreach ($item['optionGroups'] as $og) {
                $optGroups[$og['groupId']] = [
                    'entity_type' => 'option_group',
                    'entity_id'   => $og['groupId'],
                    'name'        => $og['groupName'],
                ];
                foreach ($og['choices'] as $ch) {
                    $optChoices[$ch['choiceId']] = [
                        'entity_type' => 'option_choice',
                        'entity_id'   => $ch['choiceId'],
                        'name'        => $ch['name'],
                    ];
                }
            }
        }
    }
}

$allEntities = array_merge($catList, $items, array_values($optGroups), array_values($optChoices));

if (empty($allEntities)) {
    json_response(['ok' => true, 'translated' => 0, 'message' => '翻訳対象がありません']);
    exit;
}

// ── 3. 既存翻訳を確認（スキップ判定用） ──
$existingMap = []; // "entity_type:entity_id:lang" => true
if (!$force) {
    try {
        $stmt = $pdo->prepare(
            'SELECT entity_type, entity_id, lang FROM menu_translations WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll() as $row) {
            $existingMap[$row['entity_type'] . ':' . $row['entity_id'] . ':' . $row['lang']] = true;
        }
    } catch (PDOException $e) {
        // テーブル未作成の場合は全て翻訳
    }
}

// ── 4. 翻訳が必要なアイテムをフィルタ ──
$toTranslate = [];
foreach ($allEntities as $entity) {
    foreach ($langs as $lang) {
        $key = $entity['entity_type'] . ':' . $entity['entity_id'] . ':' . $lang;
        if (!$force && isset($existingMap[$key])) continue;
        $toTranslate[] = array_merge($entity, ['lang' => $lang]);
    }
}

if (empty($toTranslate)) {
    json_response(['ok' => true, 'translated' => 0, 'message' => '全て翻訳済みです']);
    exit;
}

// ── 5. バッチ翻訳（Gemini API） ──
// 1回のAPI呼び出しで複数アイテムを翻訳（コスト最適化）
// 言語ごとにバッチ送信

$langNames = [
    'en'      => 'English',
    'zh-Hans' => 'Simplified Chinese',
    'zh-Hant' => 'Traditional Chinese',
    'ko'      => 'Korean',
];

$totalTranslated = 0;
$errors = [];

foreach ($langs as $targetLang) {
    // この言語で翻訳が必要なアイテムを抽出
    $langItems = array_filter($toTranslate, function ($t) use ($targetLang) {
        return $t['lang'] === $targetLang;
    });
    if (empty($langItems)) continue;
    $langItems = array_values($langItems);

    // バッチサイズ制限（Gemini の入力制限を考慮）
    $batches = array_chunk($langItems, 30);

    foreach ($batches as $batch) {
        $result = translate_batch($apiKey, $batch, $targetLang, $langNames[$targetLang] ?? $targetLang);

        if ($result === null) {
            $errors[] = $targetLang . ': Gemini API呼び出し失敗';
            continue;
        }

        // DB保存
        foreach ($result as $idx => $translation) {
            if (!isset($batch[$idx])) continue;
            $entity = $batch[$idx];

            $id = generate_uuid();
            $stmt = $pdo->prepare(
                'INSERT INTO menu_translations (id, tenant_id, entity_type, entity_id, lang, name, description, translated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), translated_at = NOW()'
            );
            $stmt->execute([
                $id,
                $tenantId,
                $entity['entity_type'],
                $entity['entity_id'],
                $targetLang,
                $translation['name'] ?? null,
                $translation['description'] ?? null,
            ]);
            $totalTranslated++;
        }
    }
}

$response = [
    'ok'         => true,
    'translated' => $totalTranslated,
    'langs'      => $langs,
    'message'    => $totalTranslated . '件の翻訳を完了しました',
];
if (!empty($errors)) {
    $response['warnings'] = $errors;
}

json_response($response);

// ============================================================
// ヘルパー関数
// ============================================================

/**
 * Gemini API でバッチ翻訳を実行
 *
 * @param string $apiKey      Gemini APIキー
 * @param array  $items       翻訳対象アイテム配列
 * @param string $targetLang  ターゲット言語コード
 * @param string $langName    ターゲット言語名（英語表記）
 * @return array|null         翻訳結果配列（失敗時null）
 */
function translate_batch(string $apiKey, array $items, string $targetLang, string $langName): ?array
{
    // 翻訳対象JSONを構築
    $sourceItems = [];
    foreach ($items as $idx => $item) {
        $entry = ['index' => $idx, 'name' => $item['name']];
        if (!empty($item['description'])) {
            $entry['description'] = $item['description'];
        }
        $sourceItems[] = $entry;
    }

    $sourceJson = json_encode($sourceItems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $prompt = 'You are a professional Japanese restaurant menu translator. '
        . 'Translate the following Japanese menu items to ' . $langName . '. ' . "\n\n"
        . 'RULES:' . "\n"
        . '- Keep the original meaning and cultural context' . "\n"
        . '- For Japanese dishes (sushi, ramen, yakitori, etc.), keep the romanized Japanese name and add a brief translation in parentheses. Example: "Yakitori (Grilled Chicken Skewers)"' . "\n"
        . '- For common dishes, translate naturally' . "\n"
        . '- Keep it concise — menu names should be short' . "\n"
        . '- Output ONLY a valid JSON array, no markdown, no explanation' . "\n\n"
        . 'INPUT (JSON array):' . "\n"
        . $sourceJson . "\n\n"
        . 'OUTPUT FORMAT: Return a JSON array with the same indices. Each object must have "index", "name"'
        . ' and optionally "description" (only if input had description). Example:' . "\n"
        . '[{"index":0,"name":"translated name","description":"translated description"}]' . "\n\n"
        . 'Output ONLY the JSON array. No preamble. No explanation.';

    $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

    $payload = json_encode([
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature'     => 0.3,
            'maxOutputTokens' => 4096,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($geminiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode !== 200) {
        error_log('[L-4 translate] Gemini API error: HTTP ' . $httpCode . ' / ' . substr($raw, 0, 500));
        return null;
    }

    $geminiJson = json_decode($raw, true);
    if (!$geminiJson) {
        error_log('[L-4 translate] Gemini response parse error');
        return null;
    }

    // レスポンスからテキスト抽出
    $text = $geminiJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') return null;

    // _cleanResponse: マークダウンコードブロック除去
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/```\s*$/m', '', $text);
    $text = trim($text);

    $translations = json_decode($text, true);
    if (!is_array($translations)) {
        error_log('[L-4 translate] Failed to parse translations JSON: ' . substr($text, 0, 500));
        return null;
    }

    // index でソートして返す
    usort($translations, function ($a, $b) {
        return ($a['index'] ?? 0) - ($b['index'] ?? 0);
    });

    return $translations;
}

// generate_uuid() は api/lib/db.php で定義済み（require_once 経由で利用可能）
