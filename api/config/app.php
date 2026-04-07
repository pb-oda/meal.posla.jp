<?php
/**
 * アプリケーション全体の基底設定
 *
 * 環境ごとに APP_BASE_URL を切り替える。
 * 本番ドメイン切替時はこの1ファイルだけ書き換えれば全 OAuth コールバック等が追随する。
 */

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', 'https://eat.posla.jp');
}
