<?php
/**
 * POSLA共通設定（posla_settings テーブル）アクセスヘルパー
 *
 * APIキー等の POSLA運営共通設定は、以前は tenants テーブルに分散していたが、
 * P1-6（Gemini APIキー POSLA共通化）で posla_settings に一元化された。
 *
 * 旧 tenants.ai_api_key へのフォールバックは廃止済み（P1-6c で DROP）。
 * 全ての Gemini / Google Places キー取得は本ヘルパー経由で行うこと。
 */

if (!function_exists('get_posla_setting')) {
    /**
     * POSLA共通設定値を取得する
     *
     * @param PDO $pdo
     * @param string $key 設定キー（例: 'gemini_api_key', 'google_places_api_key'）
     * @return string|null 設定値（未設定または空文字列の場合は null）
     */
    function get_posla_setting($pdo, $key)
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM posla_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $value = $row['setting_value'] ?? '';
        return $value === '' ? null : $value;
    }
}

if (!function_exists('require_gemini_api_key')) {
    /**
     * Gemini APIキーを取得する。未設定なら 503 で即終了。
     *
     * @param PDO $pdo
     * @return string APIキー
     */
    function require_gemini_api_key($pdo)
    {
        $key = get_posla_setting($pdo, 'gemini_api_key');
        if ($key === null) {
            json_error('AI_NOT_CONFIGURED', 'AI機能が設定されていません', 503);
        }
        return $key;
    }
}
