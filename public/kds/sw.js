/**
 * POSLA KDS Service Worker
 *
 * バージョンは下方の VERSION 変数を真実とする (このコメントには書かない)
 *
 * scope: /public/kds/
 *
 * 設計方針:
 *  - /api/ は完全 passthrough (キャッシュ禁止)。/api/customer/ は二重防御で明示拒否
 *  - /public/customer/ も二重防御で明示 passthrough (顧客側 PWA 化禁止)
 *  - POST / PATCH / DELETE / PUT は passthrough
 *  - URL に token / session / checkout / reservation / store_id / table_id を含む場合 passthrough
 *  - HTML: network-first + 失敗時に既存キャッシュ
 *  - 静的アセット (css/js/img/font): stale-while-revalidate
 *  - 更新方式 (PWA Phase 1 / 2026-04-20 修正版):
 *    install 内では skipWaiting しない。新版 SW は waiting 状態に留まる。
 *    PWAUpdateManager (public/shared/js/pwa-update-manager.js) が waiting を検知し、
 *    画面下部に更新バナーを表示。ユーザーが「更新する」を押したときに
 *    postMessage({type: 'SKIP_WAITING'}) → 下記 message ハンドラで skipWaiting 発火。
 *    activate ハンドラ内の clients.claim() は維持しているため、skipWaiting 後は
 *    既存タブも新 SW 制御下に入り、次の reload で新版が適用される。
 *    旧運用 (install 直後に skipWaiting) は業務操作中の予期せぬ JS 切替リスクがあったため撤廃。
 *    初回 install (controller なし) は仕様により自動的に activate されるため新規ユーザーは影響なし。
 *  - ES5 互換 (CLAUDE.md): const/let/arrow/async 不使用。var + .then() のみ
 */
(function () {
  'use strict';

  var SCOPE_NAME    = 'posla-kds';
  var VERSION       = 'v5';
  var STATIC_CACHE  = SCOPE_NAME + '-static-' + VERSION;
  var SCOPE_PREFIX  = SCOPE_NAME + '-';

  // ---- install ----
  self.addEventListener('install', function (event) {
    // 事前キャッシュは行わない (徐々に stale-while-revalidate で温まる)
    //
    // PWA Phase 1 (修正版): 自動 skipWaiting を撤廃。
    //   skipWaiting は postMessage('SKIP_WAITING') を待つ
    //   → PWAUpdateManager の「更新する」ボタンで明示発火
    //   初回 install (controller なし) は仕様により自動的に activate されるため新規ユーザーは影響なし
  });

  // ---- message: PWAUpdateManager からの SKIP_WAITING / GET_VERSION 受信 ----
  self.addEventListener('message', function (event) {
    if (!event.data) return;
    if (event.data.type === 'SKIP_WAITING') {
      self.skipWaiting();
    } else if (event.data.type === 'GET_VERSION') {
      // ports[0] が存在すれば返信
      if (event.ports && event.ports[0]) {
        event.ports[0].postMessage({ version: VERSION, scope: SCOPE_NAME });
      }
    }
  });

  // ---- push (PWA Phase 2a): サーバーから通知を受信 ----
  self.addEventListener('push', function (event) {
    var payload = {};
    try {
      if (event.data) payload = event.data.json();
    } catch (e) {
      try { payload = { body: event.data ? event.data.text() : '' }; }
      catch (e2) { payload = {}; }
    }
    var title = (payload && payload.title) ? String(payload.title) : 'POSLA 通知';
    var options = {
      body: (payload && payload.body) ? String(payload.body) : '',
      icon: (payload && payload.icon) ? String(payload.icon) : '/public/kds/icons/icon-192.png',
      badge: (payload && payload.badge) ? String(payload.badge) : '/public/kds/icons/icon-192.png',
      tag: (payload && payload.tag) ? String(payload.tag) : 'posla-kds',
      data: { url: (payload && payload.url) ? String(payload.url) : '/public/kds/index.html' },
      requireInteraction: false
    };
    event.waitUntil(self.registration.showNotification(title, options));
  });

  // ---- notificationclick: 同一origin かつ /public/kds/ 配下のみ許可 ----
  self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var rawUrl = (event.notification.data && event.notification.data.url) || '/public/kds/index.html';
    var targetUrl = '/public/kds/index.html';
    try {
      var u = new URL(rawUrl, self.location.origin);
      if (u.origin === self.location.origin
          && u.pathname.indexOf('/public/kds/') === 0
          && u.pathname.indexOf('/public/customer/') === -1) {
        targetUrl = u.pathname + u.search + u.hash;
      }
    } catch (e) {}
    event.waitUntil(
      self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
        for (var i = 0; i < clientList.length; i++) {
          var c = clientList[i];
          try {
            var cu = new URL(c.url);
            if (cu.pathname.indexOf('/public/kds/') === 0) {
              return c.focus().then(function (focused) {
                if (focused && focused.navigate) focused.navigate(targetUrl);
                return focused;
              });
            }
          } catch (e) {}
        }
        if (self.clients.openWindow) return self.clients.openWindow(targetUrl);
      })
    );
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
