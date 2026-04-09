<?php
/**
 * Google Places / Geocoding プロキシ API
 *
 * ブラウザからのCORS制約を回避するため、サーバー側でGoogle APIを呼び出す。
 * APIキーはテナントDBから取得（クライアントに露出しない）。
 *
 * GET /api/store/places-proxy.php?action=geocode&store_id=xxx&address=...
 * GET /api/store/places-proxy.php?action=nearby&store_id=xxx&lat=...&lng=...&radius=...
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/posla-settings.php';

require_method(['GET']);
$user = require_auth();
$pdo = get_db();

$action  = $_GET['action'] ?? '';
$storeId = $_GET['store_id'] ?? '';
if (!$storeId) json_error('MISSING_STORE', 'store_idは必須です', 400);
require_store_access($storeId);

// POSLA共通設定からGoogle Places APIキーを取得（L-10b 統一 / P1-22）
$apiKey = get_posla_setting($pdo, 'google_places_api_key');

if (!$apiKey) {
    json_error('NO_API_KEY', 'Google Places APIキーが設定されていません（POSLA管理画面で設定してください）', 400);
}

// ── Geocoding ──
if ($action === 'geocode') {
    $address = $_GET['address'] ?? '';
    if (!$address) json_error('MISSING_ADDRESS', '住所は必須です', 400);

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?'
         . http_build_query(['address' => $address, 'key' => $apiKey, 'language' => 'ja']);

    $result = @file_get_contents($url);
    if ($result === false) json_error('FETCH_FAILED', 'Geocoding APIの呼び出しに失敗しました', 502);

    $json = json_decode($result, true);
    if ($json === null) json_error('PARSE_FAILED', 'Geocoding APIレスポンスの解析に失敗しました', 502);

    json_response(['geocode' => $json]);
}

// ── Nearby Search ──
if ($action === 'nearby') {
    $lat    = $_GET['lat'] ?? '';
    $lng    = $_GET['lng'] ?? '';
    $radius = $_GET['radius'] ?? 1000;
    if ($lat === '' || $lng === '') json_error('MISSING_PARAMS', 'lat, lngは必須です', 400);

    $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?'
         . http_build_query([
               'location' => $lat . ',' . $lng,
               'radius'   => (int)$radius,
               'type'     => 'restaurant',
               'language' => 'ja',
               'key'      => $apiKey,
           ]);

    $result = @file_get_contents($url);
    if ($result === false) json_error('FETCH_FAILED', 'Places APIの呼び出しに失敗しました', 502);

    $json = json_decode($result, true);
    if ($json === null) json_error('PARSE_FAILED', 'Places APIレスポンスの解析に失敗しました', 502);

    json_response(['places' => $json]);
}

json_error('INVALID_ACTION', 'actionパラメータが不正です（geocode / nearby）', 400);
