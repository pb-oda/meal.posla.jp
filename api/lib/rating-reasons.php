<?php
/**
 * 低評価理由 (reason_code) の許可リストと共通ヘルパ
 *
 * - フロントから送られた reason_code を許可リストで検証
 * - レポート用の表示ラベル（日本語）を提供
 *
 * 顧客 UI は menu.html に文言を持つが、API 側は許可コードのみで判定する。
 */

if (!function_exists('rating_reason_labels')) {
    /**
     * @return array<string,string>  reason_code => 日本語表示ラベル
     */
    function rating_reason_labels(): array
    {
        return [
            'slow_service' => '提供が遅かった',
            'taste'        => '味が好みではなかった',
            'temperature'  => '温度が気になった',
            'portion'      => '量が気になった',
            'wrong_item'   => '注文内容と違った',
            'staff'        => '接客が気になった',
            'cleanliness'  => '清潔感が気になった',
            'price'        => '価格が気になった',
            'other'        => 'その他',
        ];
    }

    /**
     * @return string[]  許可された reason_code 一覧
     */
    function rating_reason_allowed_codes(): array
    {
        return array_keys(rating_reason_labels());
    }

    /**
     * 許可リスト検証
     */
    function rating_reason_is_valid_code(?string $code): bool
    {
        if ($code === null || $code === '') return true; // 空は許容（理由なし）
        return in_array($code, rating_reason_allowed_codes(), true);
    }

    /**
     * reason_text を保存形式に正規化
     * - trim
     * - 最大 255 文字
     * - 空文字は null として扱う
     */
    function rating_reason_normalize_text(?string $text): ?string
    {
        if ($text === null) return null;
        $t = trim($text);
        if ($t === '') return null;
        // mb_substr で文字数ベースに丸める (MySQL VARCHAR(255) は文字数指定)
        return mb_substr($t, 0, 255);
    }
}
