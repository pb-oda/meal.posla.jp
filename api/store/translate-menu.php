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
 *
 * P1-20: コアロジックを translate_menu_core() 関数に抽出。
 *        api/smaregi/import-menu.php からも呼出可能。
 *        既存の POST レスポンス形式は完全互換。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/menu-resolver.php';
require_once __DIR__ . '/../lib/posla-settings.php';

/**
 * P1-20: コアロジック専用例外。
 * code   = エラーコード文字列（STORE_NOT_FOUND 等）
 * status = HTTP ステータスコード
 */
if (!class_exists('TranslateMenuException')) {
    class TranslateMenuException extends \Exception
    {
        private $errorCode;
        private $httpStatus;

        public function __construct($errorCode, $message, $httpStatus = 400)
        {
            parent::__construct($message);
            $this->errorCode  = $errorCode;
            $this->httpStatus = $httpStatus;
        }

        public function getErrorCode()
        {
            return $this->errorCode;
        }

        public function getHttpStatus()
        {
            return $this->httpStatus;
        }
    }
}

// ============================================================
// POST ハンドラ（既存動作完全互換）
// ============================================================
// translate-menu.php が直接呼ばれた場合（手動「一括翻訳」ボタン）のみ実行。
// import-menu.php から関数呼出された場合は POST 処理パスに入らない。
if (PHP_SAPI !== 'cli' && !defined('TRANSLATE_MENU_CORE_ONLY')) {
    require_method(['POST']);
    $user = require_auth();
    $role = $user['role'];
    if (!in_array($role, ['owner', 'manager'])) {
        json_error('FORBIDDEN', '権限がありません', 403);
    }

    $data = get_json_body();
    $storeId = isset($data['store_id']) ? $data['store_id'] : null;
    // P1-31: デフォルト言語に en を追加、zh-Hant を削除（運用判断で繁體中文無効化）
    $langs   = isset($data['langs']) ? $data['langs'] : ['en', 'zh-Hans', 'ko'];
    $force   = !empty($data['force']);

    if (!$storeId) {
        json_error('MISSING_STORE', 'store_id は必須です', 400);
    }

    // P1b-6: manager の店舗境界チェック
    // (既存ではテナント境界しかチェックしておらず、manager が他店舗 store_id を指定可能だった)
    require_store_access($storeId);

    $pdo = get_db();
    $tenantId = $user['tenant_id'];

    try {
        $result = translate_menu_core($pdo, $tenantId, $storeId, $langs, $force);
        json_response($result);
    } catch (TranslateMenuException $e) {
        json_error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
    }
}

// ============================================================
// コアロジック（P1-20: 関数抽出）
// ============================================================

/**
 * メニュー一括翻訳のコアロジック。
 *
 * @param PDO    $pdo       DB 接続
 * @param string $tenantId  テナント ID
 * @param string $storeId   店舗 ID
 * @param array  $langs     翻訳言語配列（['en', 'zh-Hans', 'ko'] のサブセット）
 * @param bool   $force     強制再翻訳フラグ
 * @return array            ['ok'=>bool, 'translated'=>int, 'langs'=>array, 'message'=>string, 'warnings'=>?array]
 * @throws TranslateMenuException
 */
function translate_menu_core(PDO $pdo, $tenantId, $storeId, array $langs, $force)
{
    // 許可された言語のみ（P1-31: zh-Hant を削除。DB 既存データは残置）
    $allowedLangs = ['en', 'zh-Hans', 'ko'];
    $langs = array_values(array_intersect($langs, $allowedLangs));
    if (empty($langs)) {
        throw new TranslateMenuException('INVALID_LANGS', '有効な言語が指定されていません', 400);
    }

    // 店舗がテナントに属するか確認
    $stmt = $pdo->prepare('SELECT id FROM stores WHERE id = ? AND tenant_id = ? AND is_active = 1');
    $stmt->execute([$storeId, $tenantId]);
    if (!$stmt->fetch()) {
        throw new TranslateMenuException('STORE_NOT_FOUND', '店舗が見つかりません', 404);
    }

    // ── 1. Gemini APIキー取得（POSLA共通設定 / P1-6 で統一） ──
    $apiKey = require_gemini_api_key($pdo);

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
                'description' => isset($item['description']) ? $item['description'] : '',
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
        return ['ok' => true, 'translated' => 0, 'message' => '翻訳対象がありません'];
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
            error_log('[P1-12][api/store/translate-menu.php] load_existing_translations: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
        }
    }

    // P1-31: 英語の場合は menu_templates.name_en を直接見て手動入力済みをスキップする
    // （menu_translations ではなくテーブル本体を見る。tenant_id で境界維持）
    $existingNameEnMap = []; // template_id => true（name_en が非空のもの）
    if (!$force && in_array('en', $langs, true)) {
        $stmt = $pdo->prepare(
            'SELECT id, name_en FROM menu_templates WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll() as $r) {
            if ($r['name_en'] !== null && $r['name_en'] !== '') {
                $existingNameEnMap[$r['id']] = true;
            }
        }
    }

    // ── 4. 翻訳が必要なアイテムをフィルタ ──
    $toTranslate = [];
    foreach ($allEntities as $entity) {
        foreach ($langs as $lang) {
            $key = $entity['entity_type'] . ':' . $entity['entity_id'] . ':' . $lang;
            if (!$force && isset($existingMap[$key])) continue;
            // P1-31: 英語 + menu_item は本体テーブルの name_en で個別判定（手動入力保護）
            if (!$force && $lang === 'en' && $entity['entity_type'] === 'menu_item'
                && isset($existingNameEnMap[$entity['entity_id']])) {
                continue;
            }
            $toTranslate[] = array_merge($entity, ['lang' => $lang]);
        }
    }

    if (empty($toTranslate)) {
        return ['ok' => true, 'translated' => 0, 'message' => '全て翻訳済みです'];
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
            $result = translate_batch($apiKey, $batch, $targetLang, isset($langNames[$targetLang]) ? $langNames[$targetLang] : $targetLang);

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
                    isset($translation['name']) ? $translation['name'] : null,
                    isset($translation['description']) ? $translation['description'] : null,
                ]);

                // P1-31: 英語 + menu_item は menu_templates.name_en / description_en も更新
                // （customer/menu.html L423 が name_en を直接読むため。tenant_id で境界維持）
                if ($targetLang === 'en' && $entity['entity_type'] === 'menu_item') {
                    $stmt2 = $pdo->prepare(
                        'UPDATE menu_templates
                         SET name_en = ?, description_en = ?
                         WHERE id = ? AND tenant_id = ?'
                    );
                    $stmt2->execute([
                        isset($translation['name']) ? $translation['name'] : null,
                        isset($translation['description']) ? $translation['description'] : null,
                        $entity['entity_id'],
                        $tenantId,
                    ]);
                }

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

    return $response;
}

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
        . 'Translate the following Japanese menu items and category names to ' . $langName . '. ' . "\n\n"
        . 'RULES:' . "\n"
        . '- Keep the original meaning and cultural context' . "\n"
        . '- For Japanese dishes (sushi, ramen, yakitori, etc.), keep the romanized Japanese name and add a brief translation in parentheses. Example: "Yakitori (Grilled Chicken Skewers)"' . "\n"
        . '- For common dishes, translate naturally' . "\n"
        . '- Keep it concise — menu names should be short' . "\n"
        . '- CRITICAL: For katakana words, loanwords, brand names, or proper nouns (e.g. スマレジ, インポート, テスト), ALWAYS convert them into the target language script. For Chinese (zh-Hans/zh-Hant) use Chinese characters (例: スマレジインポート → 智能收银导入 / 智能收銀匯入). For Korean (ko) use Hangul (例: 스마레지 임포트). For English (en) use Latin alphabet. NEVER output the original Japanese characters (hiragana/katakana/kanji) unchanged in the translation result. If you are unsure of an exact equivalent, transliterate phonetically into the target script.' . "\n"
        . '- This rule applies equally to short single-word category names, not only to dish names.' . "\n"
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
