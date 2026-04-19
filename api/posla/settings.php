<?php
/**
 * POSLA共通設定 API
 *
 * GET   /api/posla/settings.php  — 現在の設定を取得（キーはマスク済み・実値は返さない）
 * PATCH /api/posla/settings.php  — 設定を更新（空文字/未指定は現状維持）
 *
 * セキュリティ方針:
 *   - GET レスポンスでは APIキー / シークレットの実値を一切返さない
 *   - 実値はサーバ内部 (api/lib/posla-settings.php) からのみ参照する
 *   - 編集 UI は「変更する場合のみ新値を入力、空送信で現状維持」
 */

require_once __DIR__ . '/auth-helper.php';

$admin = require_posla_admin();
$method = require_method(['GET', 'PATCH']);
$pdo = get_db();

/**
 * 公開してよい設定キー（allowlist 方式）
 *
 * 方針:
 *   - allowlist に明示列挙したキーのみ実値を返す（GET レスポンスに `*_value` を出力）
 *   - allowlist 漏れ = 機密扱い（{set, masked} のみ返却）= 安全側
 *   - 文字列推測（_secret/_key/_token サフィックス判定）は誤判定が起きるため廃止
 *     例: slack_webhook_url はどのサフィックスにも該当せず実値が漏れていた
 *
 * 各キー分類根拠:
 *   - stripe_price_*           : Stripe Price ID。UI で価格選択のため可視化必要
 *   - connect_application_fee_percent : 手数料率。UI で表示・編集する数値
 *   - smaregi_client_id        : OAuth Client ID（Secret ではない）。UI で表示
 *   - monitor_last_heartbeat   : 監視 cron の最終実行時刻（運用ステータス）
 *   - ops_notify_email         : 通知先メール（機密ではない運用設定）
 *   - gemini_temperature_*     : LLM パラメータ（数値、機密ではない）
 *
 * これ以外（slack_webhook_url, *_secret, *_key, *_token, monitor_cron_secret 等）
 * はすべて機密扱い。
 */
function _public_setting_keys() {
    return [
        // 価格・料率
        'stripe_price_base',
        'stripe_price_additional_store',
        'stripe_price_hq_broadcast',
        'stripe_price_standard',     // 旧プラン (rollback 用に DB 温存中)
        'stripe_price_pro',          // 旧プラン (rollback 用に DB 温存中)
        'stripe_price_enterprise',   // 旧プラン (rollback 用に DB 温存中)
        'connect_application_fee_percent',
        // 連携 ID
        'smaregi_client_id',
        // 運用設定
        'monitor_last_heartbeat',
        'ops_notify_email',
        // LLM パラメータ
        'gemini_temperature_sns',
        'gemini_temperature_analysis',
    ];
}

/**
 * GET レスポンスで実値（_value）を返してよいか判定
 *   - allowlist 方式：明示列挙したキーのみ true
 *   - それ以外はすべて機密扱い（{set, masked} のみ）
 */
function _is_public_key($key) {
    return in_array($key, _public_setting_keys(), true);
}

/**
 * 後方互換用: PATCH 側の「空送信＝現状維持」判定で利用
 *   公開キー以外（=機密キー）は空送信を「変更しない」として扱う
 */
function _is_secret_key($key) {
    return !_is_public_key($key);
}

/**
 * APIキー / シークレットをマスクする
 *   - 16文字以上: 先頭8 + "***...***" + 末尾4
 *   - それ未満: "********"
 */
function mask_api_key($key) {
    if ($key === null || $key === '') return null;
    $len = strlen($key);
    if ($len >= 16) {
        return substr($key, 0, 8) . '***...***' . substr($key, -4);
    }
    return '********';
}

// ============================================================
// GET — 設定取得
// ============================================================
if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM posla_settings');
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $settings = [];
    foreach ($rows as $row) {
        $key = $row['setting_key'];
        $val = $row['setting_value'];
        $isSet = ($val !== null && $val !== '');

        if (_is_public_key($key)) {
            // 公開キー（allowlist 該当）: 実値をそのまま返す
            $settings[$key] = $val;
            $settings[$key . '_set']    = $isSet;
            $settings[$key . '_masked'] = $val;
            $settings[$key . '_value']  = $val;
        } else {
            // 機密キー（allowlist 漏れはすべて機密扱い）: 実値は絶対に返さない
            $settings[$key] = [
                'set'    => $isSet,
                'masked' => $isSet ? mask_api_key($val) : null,
            ];
            // 後方互換: フロント既存コードが参照する場合のフォールバックも構造体に統一
            $settings[$key . '_set']    = $isSet;
            $settings[$key . '_masked'] = $isSet ? mask_api_key($val) : null;
            // 注意: *_value は意図的に出力しない
        }
    }

    json_response(['settings' => $settings]);
}

// ============================================================
// PATCH — 設定更新
// ============================================================
if ($method === 'PATCH') {
    $input = get_json_body();
    // P1-35: α-1 化で stripe_price_standard/pro/enterprise → base/additional_store/hq_broadcast
    // 旧キーは posla_settings の row として温存（rollback 用）。allowedKeys からは削除して UI 編集経路を閉じる
    $allowedKeys = ['gemini_api_key', 'google_places_api_key', 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret', 'stripe_price_base', 'stripe_price_additional_store', 'stripe_price_hq_broadcast', 'connect_application_fee_percent', 'smaregi_client_id', 'smaregi_client_secret'];
    $updated = 0;

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $input)) continue;

        $val = $input[$key];

        // 空文字 = 「変更しない」（フォーム未入力扱い）
        // 機密キーで実値が見えない以上、ユーザーが「何も入れない」=「現状維持」が直感的
        if ($val === '' && _is_secret_key($key)) {
            continue;
        }

        if ($val === null || $val === '') {
            // 明示的な null（削除）または非機密項目の空文字（クリア）
            $stmt = $pdo->prepare(
                'UPDATE posla_settings SET setting_value = NULL WHERE setting_key = ?'
            );
            $stmt->execute([$key]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE posla_settings SET setting_value = ? WHERE setting_key = ?'
            );
            $stmt->execute([$val, $key]);
        }
        $updated++;
    }

    if ($updated === 0) {
        json_error('NO_CHANGES', '更新項目がありません', 400);
    }

    json_response(['message' => '設定を更新しました']);
}
