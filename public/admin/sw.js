/**
 * POSLA Admin (店舗管理) Service Worker — v1
 *
 * scope: /public/admin/
 *
 * 設計方針:
 *  - /api/ は完全 passthrough (キャッシュ禁止)。/api/customer/ は二重防御で明示拒否
 *  - /public/customer/ も二重防御で明示 passthrough (顧客側 PWA 化禁止)
 *  - POST / PATCH / DELETE / PUT は passthrough
 *  - URL に token / session / checkout / reservation / store_id / table_id を含む場合 passthrough
 *  - HTML: network-first + 失敗時に既存キャッシュ
 *  - 静的アセット (css/js/img/font): stale-while-revalidate
 *  - skipWaiting + clients.claim を使う理由:
 *    新版デプロイ時、開いている管理画面が古い JS を持ち続けるとデータ齟齬が起きる。
 *    install 直後に skipWaiting() で waiting → activating に進め、
 *    activate 時に clients.claim() で既存タブを即座に新 SW の制御下に置く。
 *    つまり**既存タブも次のリクエストから新 SW が応答**する (再読込待ち不要)。
 *    API は常に passthrough なので、既存タブ上で実行中の業務処理が壊れる
 *    リスクはほぼゼロ。静的アセットだけ徐々に新版に差し替わる挙動になる。
 *  - ES5 互換 (CLAUDE.md): const/let/arrow/async 不使用。var + .then() のみ
 */
(function () {
  'use strict';

  var SCOPE_NAME    = 'posla-admin';
  var VERSION       = 'v1';
  var STATIC_CACHE  = SCOPE_NAME + '-static-' + VERSION;
  var SCOPE_PREFIX  = SCOPE_NAME + '-';

  // ---- install ----
  self.addEventListener('install', function (event) {
    // 事前キャッシュは行わない (徐々に stale-while-revalidate で温まる)
    self.skipWaiting();
  });

  // ---- activate: 旧 cache 削除 ----
  self.addEventListener('activate', function (event) {
    event.waitUntil(
      caches.keys().then(function (keys) {
        return Promise.all(keys.map(function (key) {
          if (key.indexOf(SCOPE_PREFIX) === 0 && key !== STATIC_CACHE) {
            return caches.delete(key);
          }
          return null;
        }));
      }).then(function () {
        return self.clients.claim();
      })
    );
  });

  // ---- fetch: ルーティング ----
  self.addEventListener('fetch', function (event) {
    var req = event.request;
    var url;
    try { url = new URL(req.url); } catch (e) { return; }

    // 1. cross-origin はスルー
    if (url.origin !== self.location.origin) return;

    // 2. GET 以外は passthrough (Cache API は GET 前提。HEAD/POST/PATCH/DELETE はキャッシュしない)
    if (req.method !== 'GET') return;

    // 3. /api/ は絶対キャッシュしない (二重防御で /api/customer/ 明示)
    if (url.pathname.indexOf('/api/') !== -1) return;

    // 4. 顧客側パス (/public/customer/) は二重防御で passthrough
    if (url.pathname.indexOf('/public/customer/') !== -1
        || url.pathname.indexOf('/customer/') !== -1) return;

    // 5. センシティブクエリ含む URL は passthrough
    var qp = url.search || '';
    if (qp) {
      var keys = ['token=', 'session=', 'session_token=', 'sub_session_id=',
                  'checkout=', 'checkout_session=', 'reservation=',
                  'store_id=', 'table_id=', 'pin=', 'staff_pin='];
      for (var i = 0; i < keys.length; i++) {
        if (qp.indexOf(keys[i]) !== -1) return;
      }
    }

    // 6. HTML (navigate or text/html accept): network-first
    var accept = req.headers.get('accept') || '';
    if (req.mode === 'navigate' || accept.indexOf('text/html') !== -1) {
      event.respondWith(_networkFirst(req));
      return;
    }

    // 7. 静的アセット: stale-while-revalidate
    var pathname = url.pathname;
    if (/\.(css|js|png|jpg|jpeg|gif|svg|webp|woff2?|ttf|eot|ico|webmanifest)$/i.test(pathname)) {
      event.respondWith(_staleWhileRevalidate(req));
      return;
    }

    // 8. それ以外は passthrough (キャッシュしない)
  });

  function _networkFirst(req) {
    return fetch(req).then(function (response) {
      if (response && response.ok && response.status === 200 && response.type !== 'opaque') {
        var clone = response.clone();
        caches.open(STATIC_CACHE).then(function (cache) {
          cache.put(req, clone);
        }).catch(function () {});
      }
      return response;
    }).catch(function () {
      return caches.match(req).then(function (hit) {
        if (hit) return hit;
        return new Response(
          '<!doctype html><meta charset="utf-8"><title>オフライン</title>'
          + '<body style="font-family:sans-serif;padding:2rem;text-align:center;">'
          + '<h1>オフラインです</h1><p>ネットワーク接続を確認してから再読込してください。</p></body>',
          { status: 503, headers: { 'Content-Type': 'text/html;charset=utf-8' } }
        );
      });
    });
  }

  function _staleWhileRevalidate(req) {
    return caches.open(STATIC_CACHE).then(function (cache) {
      return cache.match(req).then(function (cached) {
        var fetchPromise = fetch(req).then(function (response) {
          if (response && response.ok && response.status === 200 && response.type !== 'opaque') {
            cache.put(req, response.clone()).catch(function () {});
          }
          return response;
        }).catch(function () {
          return cached || Response.error();
        });
        return cached || fetchPromise;
      });
    });
  }
})();
