<?php
/**
 * L-4: UI翻訳取得 API（認証不要）
 *
 * GET /api/customer/ui-translations.php?lang=zh-Hans
 *
 * セルフオーダー画面のUI文言を指定言語で返す。
 * テーブル未作成時は空オブジェクトを返す（グレースフルデグレード）。
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';

require_method(['GET']);

$lang = $_GET['lang'] ?? 'ja';

// 許可された言語のみ
$allowedLangs = ['ja', 'en', 'zh-Hans', 'zh-Hant', 'ko'];
if (!in_array($lang, $allowedLangs)) {
    $lang = 'ja';
}

$pdo = get_db();
$translations = [];

try {
    $stmt = $pdo->prepare('SELECT msg_key, msg_value FROM ui_translations WHERE lang = ?');
    $stmt->execute([$lang]);
    foreach ($stmt->fetchAll() as $row) {
        $translations[$row['msg_key']] = $row['msg_value'];
    }
} catch (PDOException $e) {
    // テーブル未作成時は空オブジェクト
    error_log('[P1-12][api/customer/ui-translations.php:33] load_translations: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log');
}

json_response([
    'lang'         => $lang,
    'translations' => $translations,
]);
