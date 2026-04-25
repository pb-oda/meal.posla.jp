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
    navigator.serviceWorker.register('/kds/sw.js', {
      scope: '/kds/',
      updateViaCache: 'none'
    }).then(function (reg) {
      // PWA Phase 1 (修正版): waiting への自動 SKIP_WAITING 送信を撤廃。
      //   PWAUpdateManager の「更新する」ボタンで明示発火する運用に統一。
      reg && reg.addEventListener && reg.addEventListener('updatefound', function () {
        var sw = reg.installing;
        if (sw) {
          sw.addEventListener('statechange', function () {
            if (sw.state === 'installed' && navigator.serviceWorker.controller) {
              // 新版インストール完了 → 業務中の不整合を避けるため次回ロード時に反映。
              // skipWaiting は PWAUpdateManager (public/shared/js/pwa-update-manager.js) が
              // 同じ waiting worker を検知して画面下部に更新バナーを表示し、
              // ユーザーが「更新する」を押したときに waiting worker へ postMessage で送る。
              // ここ (pwa-register.js) では何もしない。
            }
          });
        }
      });
    }).catch(function () {
      // SW 登録失敗時は普通の Web として動作継続
    });
  });
})();
