<?php
/**
 * L-15: スマレジAPI共通クライアント
 *
 * スマレジPOS連携に必要なOAuth2トークン管理とAPI呼び出しを提供する。
 * - get_smaregi_config()         POSLA共通のClient ID/Secret取得
 * - get_tenant_smaregi()         テナントのスマレジ認証情報取得
 * - refresh_token_if_needed()    トークンの自動リフレッシュ
 * - smaregi_api_request()        スマレジAPIへのリクエスト送信
 */

require_once __DIR__ . '/db.php';

/**
 * posla_settings から スマレジ Client ID / Secret を取得
 *
 * @param PDO $pdo
 * @return array ['client_id' => string|null, 'client_secret' => string|null]
 */
function get_smaregi_config($pdo) {
    $stmt = $pdo->prepare(
        "SELECT setting_key, setting_value FROM posla_settings
         WHERE setting_key IN ('smaregi_client_id', 'smaregi_client_secret')"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $config = ['client_id' => null, 'client_secret' => null];
    foreach ($rows as $row) {
        if ($row['setting_key'] === 'smaregi_client_id') {
            $config['client_id'] = $row['setting_value'];
        } elseif ($row['setting_key'] === 'smaregi_client_secret') {
            $config['client_secret'] = $row['setting_value'];
        }
    }
    return $config;
}

/**
 * テナントのスマレジ認証情報を取得
 *
 * @param PDO $pdo
 * @param string $tenantId
 * @return array|null  null=未連携
 *   ['contract_id', 'access_token', 'refresh_token', 'token_expires_at', 'connected_at']
 */
function get_tenant_smaregi($pdo, $tenantId) {
    $stmt = $pdo->prepare(
        'SELECT smaregi_contract_id, smaregi_access_token,
                smaregi_refresh_token, smaregi_token_expires_at,
                smaregi_connected_at
         FROM tenants WHERE id = ?'
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();

    if (!$row || !$row['smaregi_contract_id']) {
        return null;
    }

    return [
        'contract_id'      => $row['smaregi_contract_id'],
        'access_token'     => $row['smaregi_access_token'],
        'refresh_token'    => $row['smaregi_refresh_token'],
        'token_expires_at' => $row['smaregi_token_expires_at'],
        'connected_at'     => $row['smaregi_connected_at'],
    ];
}

/**
 * アクセストークンの有効期限を確認し、必要ならリフレッシュする
 *
 * - 有効期限が現在時刻の5分以内、または過去ならリフレッシュ実行
 * - スマレジ token endpoint: POST https://id.smaregi.dev/authorize/token
 *
 * @param PDO $pdo
 * @param string $tenantId
 * @param array $smaregi  get_tenant_smaregi() の戻り値
 * @return array 更新後の $smaregi 配列（失敗時は元のまま + 'refresh_error' キー付き）
 */
function refresh_token_if_needed($pdo, $tenantId, $smaregi) {
    if (!$smaregi) {
        $smaregi['refresh_error'] = 'スマレジ認証情報がありません';
        return $smaregi;
    }

    // 有効期限チェック（5分のマージン）— トークンがまだ有効ならリフレッシュ不要
    $expiresAt = $smaregi['token_expires_at'];
    if ($expiresAt) {
        $expiresTs = strtotime($expiresAt);
        $margin = 5 * 60; // 5分
        if ($expiresTs - time() > $margin) {
            // まだ有効
            return $smaregi;
        }
    }

    // リフレッシュが必要だがリフレッシュトークンがない
    if (!$smaregi['refresh_token']) {
        $smaregi['refresh_error'] = 'トークン期限切れ。リフレッシュトークンがないため再接続が必要です';
        return $smaregi;
    }

    // POSLA共通設定からClient ID/Secret取得
    $config = get_smaregi_config($pdo);
    if (!$config['client_id'] || !$config['client_secret']) {
        $smaregi['refresh_error'] = 'スマレジ Client ID/Secret が未設定です';
        return $smaregi;
    }

    // トークンリフレッシュリクエスト
    $url = 'https://id.smaregi.dev/authorize/token';
    $postFields = http_build_query([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $smaregi['refresh_token'],
    ]);

    $authHeader = 'Authorization: Basic ' . base64_encode($config['client_id'] . ':' . $config['client_secret']);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => [
            $authHeader,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    if ($curlErr) {
        $smaregi['refresh_error'] = 'cURLエラー: ' . $curlErr;
        return $smaregi;
    }

    $json = json_decode($body, true);
    if ($httpCode >= 400 || !$json || !isset($json['access_token'])) {
        $errMsg = isset($json['error_description']) ? $json['error_description'] : (isset($json['error']) ? $json['error'] : '不明なエラー');
        $smaregi['refresh_error'] = 'トークンリフレッシュ失敗: ' . $errMsg;
        return $smaregi;
    }

    // 新しいトークン情報
    $newAccessToken  = $json['access_token'];
    $newRefreshToken = isset($json['refresh_token']) ? $json['refresh_token'] : $smaregi['refresh_token'];
    $expiresIn       = isset($json['expires_in']) ? (int)$json['expires_in'] : 3600;
    $newExpiresAt    = date('Y-m-d H:i:s', time() + $expiresIn);

    // DB更新
    $stmt = $pdo->prepare(
        'UPDATE tenants SET
            smaregi_access_token = ?,
            smaregi_refresh_token = ?,
            smaregi_token_expires_at = ?
         WHERE id = ?'
    );
    $stmt->execute([$newAccessToken, $newRefreshToken, $newExpiresAt, $tenantId]);

    // 更新後の配列を返す
    $smaregi['access_token']     = $newAccessToken;
    $smaregi['refresh_token']    = $newRefreshToken;
    $smaregi['token_expires_at'] = $newExpiresAt;
    unset($smaregi['refresh_error']);

    return $smaregi;
}

/**
 * スマレジAPIにリクエストを送信する
 *
 * Base URL: https://api.smaregi.dev/{contract_id}
 * トークン自動リフレッシュ付き
 *
 * @param PDO $pdo
 * @param string $tenantId
 * @param string $method  'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
 * @param string $path    APIパス（例: '/pos/transactions'）
 * @param array|null $body  POSTボディ（連想配列、JSONエンコードされる）
 * @return array ['ok' => bool, 'data' => mixed, 'status' => int]
 */
function smaregi_api_request($pdo, $tenantId, $method, $path, $body = null) {
    // テナントのスマレジ認証情報取得
    $smaregi = get_tenant_smaregi($pdo, $tenantId);
    if (!$smaregi) {
        return ['ok' => false, 'data' => ['error' => 'スマレジ未連携'], 'status' => 0];
    }

    // トークンリフレッシュ
    $smaregi = refresh_token_if_needed($pdo, $tenantId, $smaregi);
    if (isset($smaregi['refresh_error'])) {
        return ['ok' => false, 'data' => ['error' => $smaregi['refresh_error']], 'status' => 0];
    }

    if (!$smaregi['access_token']) {
        return ['ok' => false, 'data' => ['error' => 'アクセストークンがありません'], 'status' => 0];
    }

    // URL組み立て（sandbox: api.smaregi.dev, 本番: api.smaregi.jp）
    $baseUrl = 'https://api.smaregi.dev/' . urlencode($smaregi['contract_id']);
    $url = $baseUrl . $path;

    error_log('[POSLA-Smaregi] API request: ' . $method . ' ' . $url);

    // ヘッダー
    $headers = [
        'Authorization: Bearer ' . $smaregi['access_token'],
        'Content-Type: application/json',
    ];

    // cURL
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    $methodUpper = strtoupper($method);
    if ($methodUpper === 'GET') {
        $opts[CURLOPT_HTTPGET] = true;
    } elseif ($methodUpper === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
    } else {
        // PUT, PATCH, DELETE
        $opts[CURLOPT_CUSTOMREQUEST] = $methodUpper;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
    }

    curl_setopt_array($ch, $opts);

    $respBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'data' => ['error' => 'cURLエラー: ' . $curlErr], 'status' => 0];
    }

    $respData = json_decode($respBody, true);
    if ($respData === null && $respBody !== '' && $respBody !== 'null') {
        $respData = ['raw' => substr($respBody, 0, 500)];
    }

    $ok = ($httpCode >= 200 && $httpCode < 300);

    if (!$ok) {
        error_log('[POSLA-Smaregi] API error: HTTP=' . $httpCode . ' body=' . substr($respBody, 0, 500));
    }

    return ['ok' => $ok, 'data' => $respData, 'status' => $httpCode];
}
