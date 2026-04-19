/**
 * POSLA KDS PWA 登録スクリプト
 *
 * 各 admin HTML の <body> 末尾に以下 1 行を追加:
 *   <script src="/public/kds/pwa-register.js"></script>
 *
 * scope: /public/kds/ に固定。/customer/ や /api/ には絶対干渉しない。
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow 不使用
 */
(function () {
  'use strict';

  if (!('serviceWorker' in navigator)) return;

  // 二重防御: 顧客側ページから誤って読み込まれた場合はスキップ
  if (location.pathname.indexOf('/public/customer/') !== -1
      || location.pathname.indexOf('/customer/') !== -1) return;

  // 自スコープ配下からのみ登録 (誤って LP や posla-admin から読み込まれても発火しない)
  if (location.pathname.indexOf('/public/kds/') === -1
      && location.pathname.indexOf('/kds/') === -1) return;

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/public/kds/sw.js', {
      scope: '/public/kds/',
      updateViaCache: 'none'
    }).then(function (reg) {
      // 更新ありで waiting に入ったら即時適用 (skipWaiting と組み合わせ)
      if (reg && reg.waiting) {
        reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      }
      reg && reg.addEventListener && reg.addEventListener('updatefound', function () {
        var sw = reg.installing;
        if (sw) {
          sw.addEventListener('statechange', function () {
            if (sw.state === 'installed' && navigator.serviceWorker.controller) {
              // 新版インストール完了 → 業務中の不整合を避けるため次回ロード時に反映
              // (skipWaiting は activate 時に発火するので、ここでは何もしない)
            }
          });
        }
      });
    }).catch(function () {
      // SW 登録失敗時は普通の Web として動作継続
    });
  });
})();
