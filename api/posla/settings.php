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
require_once __DIR__ . '/admin-audit-helper.php';
require_once __DIR__ . '/../lib/mail.php';

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
 *     例: google_chat_webhook_url / slack_webhook_url はどのサフィックスにも該当せず実値が漏れていた
 *
 * 各キー分類根拠:
 *   - stripe_price_*           : Stripe Price ID。UI で価格選択のため可視化必要
 *   - connect_application_fee_percent : 手数料率。UI で表示・編集する数値
 *   - smaregi_client_id        : OAuth Client ID（Secret ではない）。UI で表示
 *   - monitor_last_heartbeat   : 監視 cron の最終実行時刻（運用ステータス）
 *   - ops_notify_email         : 通知先メール（機密ではない運用設定）
 *   - gemini_temperature_*     : LLM パラメータ（数値、機密ではない）
 *
 * これ以外（google_chat_webhook_url, slack_webhook_url, *_secret, *_key, *_token, monitor_cron_secret 等）
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
        'codex_ops_case_endpoint',
        'codex_ops_alert_endpoint',
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

function _setting_label_map() {
    return [
        'gemini_api_key' => 'Gemini APIキー',
        'google_places_api_key' => 'Google Places APIキー',
        'stripe_secret_key' => 'Stripe Secret Key',
        'stripe_publishable_key' => 'Stripe Publishable Key',
        'stripe_webhook_secret' => 'Stripe Subscription Webhook Secret',
        'stripe_webhook_secret_signup' => 'Stripe Signup Webhook Secret',
        'stripe_price_base' => 'Price ID: 基本料金',
        'stripe_price_additional_store' => 'Price ID: 追加店舗',
        'stripe_price_hq_broadcast' => 'Price ID: 本部一括配信',
        'connect_application_fee_percent' => 'Application Fee (%)',
        'smaregi_client_id' => 'スマレジ Client ID',
        'smaregi_client_secret' => 'スマレジ Client Secret',
        'google_chat_webhook_url' => 'Google Chat Webhook URL',
        'ops_notify_email' => '運用通知メール',
        'codex_ops_case_endpoint' => 'OP Incident Report Endpoint',
        'codex_ops_case_token' => 'OP Incident Report Token',
        'codex_ops_alert_endpoint' => 'OP Alert Endpoint',
        'codex_ops_alert_token' => 'OP Alert Token',
    ];
}

function _setting_label($key) {
    $labels = _setting_label_map();
    return $labels[$key] ?? $key;
}

function _build_audit_setting_value($key, $value) {
    $isSet = !($value === null || $value === '');
    $display = '未設定';

    if ($isSet) {
        if (_is_public_key($key)) {
            $display = (string)$value;
        } else {
            $display = mask_api_key((string)$value) ?: '設定済み';
        }
    }

    return [
        'key' => $key,
        'label' => _setting_label($key),
        'is_secret' => _is_secret_key($key),
        'is_set' => $isSet,
        'display' => $display,
    ];
}

function _fetch_current_settings_map(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM posla_settings');
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $map = [];
    $i = 0;

    for ($i = 0; $i < count($rows); $i++) {
        $map[$rows[$i]['setting_key']] = $rows[$i]['setting_value'];
    }

    return $map;
}

function _setting_is_present(array $settingsMap, string $key): bool {
    return array_key_exists($key, $settingsMap) && $settingsMap[$key] !== null && $settingsMap[$key] !== '';
}

function _setting_value(array $settingsMap, string $key): string {
    if (!_setting_is_present($settingsMap, $key)) {
        return '';
    }

    return (string)$settingsMap[$key];
}

function _build_config_check(string $id, string $label, string $tone, string $summary, array $details): array {
    return [
        'id' => $id,
        'label' => $label,
        'tone' => $tone,
        'summary' => $summary,
        'details' => $details,
    ];
}

function _detect_stripe_mode(string $secretKey, string $publishableKey): string {
    $secretMode = '';
    $publishableMode = '';

    if (strpos($secretKey, 'sk_live_') === 0) {
        $secretMode = 'live';
    } elseif (strpos($secretKey, 'sk_test_') === 0) {
        $secretMode = 'test';
    }

    if (strpos($publishableKey, 'pk_live_') === 0) {
        $publishableMode = 'live';
    } elseif (strpos($publishableKey, 'pk_test_') === 0) {
        $publishableMode = 'test';
    }

    if ($secretMode !== '' && $publishableMode !== '' && $secretMode !== $publishableMode) {
        return 'mismatch';
    }

    if ($secretMode !== '') {
        return $secretMode;
    }
    if ($publishableMode !== '') {
        return $publishableMode;
    }

    return '';
}

function _build_config_health(array $settingsMap): array {
    $checks = [];
    $overall = [
        'ok_count' => 0,
        'warn_count' => 0,
        'danger_count' => 0,
        'total_count' => 0,
    ];

    $geminiSet = _setting_is_present($settingsMap, 'gemini_api_key');
    $placesSet = _setting_is_present($settingsMap, 'google_places_api_key');
    $aiReadyCount = ($geminiSet ? 1 : 0) + ($placesSet ? 1 : 0);
    $checks[] = _build_config_check(
        'ai_maps',
        'Gemini / Places',
        ($aiReadyCount === 2 ? 'ok' : 'warn'),
        $aiReadyCount . '/2 設定済み',
        [
            'Gemini APIキー: ' . ($geminiSet ? '設定済み' : '未設定'),
            'Google Places APIキー: ' . ($placesSet ? '設定済み' : '未設定'),
        ]
    );

    $stripeSecretSet = _setting_is_present($settingsMap, 'stripe_secret_key');
    $stripePublishableSet = _setting_is_present($settingsMap, 'stripe_publishable_key');
    $stripeWebhookSet = _setting_is_present($settingsMap, 'stripe_webhook_secret');
    $stripeSignupWebhookSet = _setting_is_present($settingsMap, 'stripe_webhook_secret_signup');
    $stripePriceBaseSet = _setting_is_present($settingsMap, 'stripe_price_base');
    $stripePriceAddSet = _setting_is_present($settingsMap, 'stripe_price_additional_store');
    $stripePriceHqSet = _setting_is_present($settingsMap, 'stripe_price_hq_broadcast');
    $connectFeeSet = _setting_is_present($settingsMap, 'connect_application_fee_percent');
    $stripeReadyCount = ($stripeSecretSet ? 1 : 0) + ($stripePublishableSet ? 1 : 0) + ($stripeWebhookSet ? 1 : 0) + ($stripeSignupWebhookSet ? 1 : 0)
        + ($stripePriceBaseSet ? 1 : 0) + ($stripePriceAddSet ? 1 : 0) + ($stripePriceHqSet ? 1 : 0) + ($connectFeeSet ? 1 : 0);
    $stripeMode = _detect_stripe_mode(
        _setting_value($settingsMap, 'stripe_secret_key'),
        _setting_value($settingsMap, 'stripe_publishable_key')
    );
    $stripeTone = 'ok';
    $stripeSummary = $stripeReadyCount . '/8 設定済み';

    if ($stripeMode === 'mismatch') {
        $stripeTone = 'danger';
        $stripeSummary = 'test/live 不一致';
    } elseif ($stripeReadyCount < 8) {
        $stripeTone = 'warn';
    }

    $checks[] = _build_config_check(
        'stripe_billing',
        'Stripe Billing',
        $stripeTone,
        $stripeSummary,
        [
            'Secret Key: ' . ($stripeSecretSet ? '設定済み' : '未設定'),
            'Publishable Key: ' . ($stripePublishableSet ? '設定済み' : '未設定'),
            'Subscription Webhook Secret: ' . ($stripeWebhookSet ? '設定済み' : '未設定'),
            'Signup Webhook Secret: ' . ($stripeSignupWebhookSet ? '設定済み' : '未設定'),
            'Price ID: ' . (($stripePriceBaseSet && $stripePriceAddSet && $stripePriceHqSet) ? '3/3 設定済み' : (($stripePriceBaseSet ? 1 : 0) + ($stripePriceAddSet ? 1 : 0) + ($stripePriceHqSet ? 1 : 0)) . '/3 設定済み'),
            'Application Fee: ' . ($connectFeeSet ? '設定済み' : '未設定'),
            'モード判定: ' . ($stripeMode === 'mismatch' ? '不一致' : ($stripeMode !== '' ? $stripeMode : '未判定')),
        ]
    );

    $smaregiIdSet = _setting_is_present($settingsMap, 'smaregi_client_id');
    $smaregiSecretSet = _setting_is_present($settingsMap, 'smaregi_client_secret');
    $smaregiReadyCount = ($smaregiIdSet ? 1 : 0) + ($smaregiSecretSet ? 1 : 0);
    $checks[] = _build_config_check(
        'smaregi',
        'スマレジ OAuth',
        ($smaregiReadyCount === 2 ? 'ok' : 'warn'),
        $smaregiReadyCount . '/2 設定済み',
        [
            'Client ID: ' . ($smaregiIdSet ? '設定済み' : '未設定'),
            'Client Secret: ' . ($smaregiSecretSet ? '設定済み' : '未設定'),
        ]
    );

    $googleChatSet = _setting_is_present($settingsMap, 'google_chat_webhook_url');
    $opsMailSet = _setting_is_present($settingsMap, 'ops_notify_email');
    $monitorSecretSet = _setting_is_present($settingsMap, 'monitor_cron_secret');
    $heartbeat = _setting_value($settingsMap, 'monitor_last_heartbeat');
    $heartbeatState = '未到達';
    $monitorTone = 'ok';

    if ($heartbeat !== '') {
        $lag = time() - strtotime($heartbeat);
        if ($lag > 900) {
            $heartbeatState = '遅延 (' . (int)floor($lag / 60) . '分)';
            $monitorTone = 'warn';
        } else {
            $heartbeatState = '正常';
        }
    } else {
        $monitorTone = 'warn';
    }

    if (!$googleChatSet || !$opsMailSet || !$monitorSecretSet) {
        $monitorTone = 'warn';
    }

    $checks[] = _build_config_check(
        'monitoring',
        '運用監視 / Google Chat',
        $monitorTone,
        (($googleChatSet ? 1 : 0) + ($opsMailSet ? 1 : 0) + ($monitorSecretSet ? 1 : 0)) . '/3 設定済み',
        [
            'Google Chat Webhook: ' . ($googleChatSet ? '設定済み' : '未設定'),
            '運用通知メール: ' . ($opsMailSet ? '設定済み' : '未設定'),
            'DB共有秘密: ' . ($monitorSecretSet ? '設定済み' : '未設定'),
            'heartbeat: ' . $heartbeatState,
        ]
    );

    $opsCaseEndpointSet = _setting_is_present($settingsMap, 'codex_ops_case_endpoint');
    $opsCaseTokenSet = _setting_is_present($settingsMap, 'codex_ops_case_token');
    $opsAlertEndpointSet = _setting_is_present($settingsMap, 'codex_ops_alert_endpoint');
    $opsAlertTokenSet = _setting_is_present($settingsMap, 'codex_ops_alert_token');
    $checks[] = _build_config_check(
        'ops_case_bridge',
        'OP障害報告 / Alert連携',
        ($opsCaseEndpointSet && $opsCaseTokenSet && $opsAlertEndpointSet && $opsAlertTokenSet) ? 'ok' : 'warn',
        (($opsCaseEndpointSet ? 1 : 0) + ($opsCaseTokenSet ? 1 : 0) + ($opsAlertEndpointSet ? 1 : 0) + ($opsAlertTokenSet ? 1 : 0)) . '/4 設定済み',
        [
            '障害報告 Endpoint: ' . ($opsCaseEndpointSet ? '設定済み' : '未設定'),
            '障害報告 Token: ' . ($opsCaseTokenSet ? '設定済み' : '未設定'),
            '監視アラート Endpoint: ' . ($opsAlertEndpointSet ? '設定済み' : '未設定'),
            '監視アラート Token: ' . ($opsAlertTokenSet ? '設定済み' : '未設定'),
        ]
    );

    $vapidPublicSet = _setting_is_present($settingsMap, 'web_push_vapid_public');
    $vapidPrivateSet = _setting_is_present($settingsMap, 'web_push_vapid_private_pem');
    $pwaReadyCount = ($vapidPublicSet ? 1 : 0) + ($vapidPrivateSet ? 1 : 0);
    $checks[] = _build_config_check(
        'pwa_push',
        'PWA / Web Push',
        ($pwaReadyCount === 2 ? 'ok' : 'warn'),
        $pwaReadyCount . '/2 設定済み',
        [
            'VAPID 公開鍵: ' . ($vapidPublicSet ? '設定済み' : '未設定'),
            'VAPID 秘密鍵: ' . ($vapidPrivateSet ? '設定済み' : '未設定'),
        ]
    );

    $i = 0;
    for ($i = 0; $i < count($checks); $i++) {
        $overall['total_count']++;
        if ($checks[$i]['tone'] === 'ok') {
            $overall['ok_count']++;
        } elseif ($checks[$i]['tone'] === 'danger') {
            $overall['danger_count']++;
        } else {
            $overall['warn_count']++;
        }
    }

    return [
        'overall' => $overall,
        'checks' => $checks,
    ];
}

function _count_rows_or_default(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $default;
    }
}

function _build_notification_center(PDO $pdo, array $settingsMap): array
{
    $googleChatSet = _setting_is_present($settingsMap, 'google_chat_webhook_url');
    $slackSet = _setting_is_present($settingsMap, 'slack_webhook_url');
    $opsMail = _setting_value($settingsMap, 'ops_notify_email');
    $mailTransport = posla_mail_transport_label();
    $vapidReady = _setting_is_present($settingsMap, 'web_push_vapid_public') && _setting_is_present($settingsMap, 'web_push_vapid_private_pem');
    $pushEnabledCount = (int)_count_rows_or_default(
        $pdo,
        'SELECT COUNT(*) FROM push_subscriptions WHERE enabled = 1',
        [],
        0
    );
    $pushTest24h = (int)_count_rows_or_default(
        $pdo,
        "SELECT COUNT(*) FROM push_send_log WHERE type = 'push_test' AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        [],
        0
    );
    $pushLastTestAt = _count_rows_or_default(
        $pdo,
        "SELECT MAX(sent_at) FROM push_send_log WHERE type = 'push_test'",
        [],
        null
    );

    return [
        'google_chat' => [
            'route' => $googleChatSet ? 'google_chat' : ($slackSet ? 'slack_legacy' : 'none'),
            'available' => $googleChatSet || $slackSet,
            'detail' => $googleChatSet ? 'Google Chat webhook を正系で使用' : ($slackSet ? 'Slack fallback を使用' : '通知先未設定'),
        ],
        'ops_mail' => [
            'recipient' => $opsMail,
            'transport' => $mailTransport,
            'available' => ($opsMail !== '' && $mailTransport !== 'none'),
        ],
        'web_push' => [
            'available' => $vapidReady,
            'enabled_subscriptions' => $pushEnabledCount,
            'push_test_24h' => $pushTest24h,
            'last_push_test_at' => $pushLastTestAt,
        ],
        'monitor' => [
            'heartbeat' => _setting_value($settingsMap, 'monitor_last_heartbeat'),
        ],
    ];
}

// ============================================================
// GET — 設定取得
// ============================================================
if ($method === 'GET') {
    $rows = [];
    $settingsMap = _fetch_current_settings_map($pdo);
    $keys = array_keys($settingsMap);
    $i = 0;

    $settings = [];
    for ($i = 0; $i < count($keys); $i++) {
        $key = $keys[$i];
        $val = $settingsMap[$key];
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

    $recentChanges = posla_admin_fetch_recent_audit_log($pdo, 20, 'posla_setting');
    $auditSummary = posla_admin_build_audit_summary($recentChanges);
    $configHealth = _build_config_health($settingsMap);
    $notificationCenter = _build_notification_center($pdo, $settingsMap);

    json_response([
        'settings' => $settings,
        'recent_changes' => $recentChanges,
        'audit_summary' => $auditSummary,
        'config_health' => $configHealth,
        'notification_center' => $notificationCenter,
    ]);
}

// ============================================================
// PATCH — 設定更新
// ============================================================
if ($method === 'PATCH') {
    $input = get_json_body();
    // P1-35: α-1 化で stripe_price_standard/pro/enterprise → base/additional_store/hq_broadcast
    // 旧キーは posla_settings の row として温存（rollback 用）。allowedKeys からは削除して UI 編集経路を閉じる
    $allowedKeys = ['gemini_api_key', 'google_places_api_key', 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret', 'stripe_webhook_secret_signup', 'stripe_price_base', 'stripe_price_additional_store', 'stripe_price_hq_broadcast', 'connect_application_fee_percent', 'smaregi_client_id', 'smaregi_client_secret', 'google_chat_webhook_url', 'ops_notify_email', 'codex_ops_case_endpoint', 'codex_ops_case_token', 'codex_ops_alert_endpoint', 'codex_ops_alert_token'];
    $updated = 0;
    $updatedKeys = [];
    $currentSettings = _fetch_current_settings_map($pdo);
    $batchId = generate_uuid();

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $input)) continue;

        $val = $input[$key];

        // 空文字 = 「変更しない」（フォーム未入力扱い）
        // 機密キーで実値が見えない以上、ユーザーが「何も入れない」=「現状維持」が直感的
        if ($val === '' && _is_secret_key($key)) {
            continue;
        }

        // zero-tenant restore 後の current DB では posla_settings の行自体が欠落している
        // ケースがあるため、UPDATE ではなく UPSERT で確実に保存する。
        $currentVal = array_key_exists($key, $currentSettings) ? $currentSettings[$key] : null;
        $storeVal = ($val === null || $val === '') ? null : $val;
        if ((string)($currentVal ?? '') === (string)($storeVal ?? '')) {
            continue;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO posla_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$key, $storeVal]);

        $action = 'settings_update';
        if (($currentVal === null || $currentVal === '') && $storeVal !== null) {
            $action = 'settings_create';
        } elseif ($storeVal === null) {
            $action = 'settings_clear';
        }

        posla_admin_write_audit_log(
            $pdo,
            $admin,
            $action,
            'posla_setting',
            $key,
            _build_audit_setting_value($key, $currentVal),
            _build_audit_setting_value($key, $storeVal),
            null,
            $batchId
        );

        $updated++;
        $updatedKeys[] = $key;
    }

    if ($updated === 0) {
        json_error('NO_CHANGES', '更新項目がありません', 400);
    }

    json_response([
        'message' => '設定を更新しました',
        'updated_keys' => $updatedKeys,
        'batch_id' => $batchId,
    ]);
}
