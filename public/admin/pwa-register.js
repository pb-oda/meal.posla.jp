/**
 * POSLA Admin PWA 登録スクリプト
 *
 * 各 admin HTML の <body> 末尾に以下 1 行を追加:
 *   <script src="/public/admin/pwa-register.js"></script>
 *
 * scope: /public/admin/ に固定。/customer/ や /api/ には絶対干渉しない。
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
  if (location.pathname.indexOf('/public/admin/') === -1
      && location.pathname.indexOf('/admin/') === -1) return;

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/public/admin/sw.js', {
      scope: '/public/admin/',
      updateViaCache: 'none'
    }).then(function (reg) {
      // PWA Phase 1 (修正版): waiting への自動 SKIP_WAITING 送信を撤廃。
      //   旧実装は新版 SW を即時適用していたが、Phase 1 の「更新する」ボタン方式と矛盾するため停止。
      //   PWAUpdateManager (public/shared/js/pwa-update-manager.js) が waiting を検知し、
      //   ユーザーが「更新する」を押したときのみ SKIP_WAITING を送る運用に統一。
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
