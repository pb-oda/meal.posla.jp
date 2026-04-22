/**
 * POSLA PWA 更新マネージャ (Phase 1)
 *
 * 目的:
 *   - Service Worker の新版を検知し、画面下部に小さなバナーで通知
 *   - ユーザーが「更新する」を押したときだけ skipWaiting + reload
 *   - 「キャッシュリセット」ボタンで current scope の Cache Storage だけ削除
 *
 * 削除しないもの:
 *   - localStorage / sessionStorage
 *   - 認証 Cookie / セッション情報
 *   - 店舗選択情報 (mt_handy_store 等)
 *   - 他 scope の Cache Storage (admin にいるとき kds の cache は触らない)
 *
 * 自動リロードは行わない (業務操作中の事故防止)。ユーザー押下時のみ。
 *
 * 顧客画面 (public/customer/) では絶対に動作しない (二重防御で skip)。
 *
 * 既存の pwa-register.js は削除しない・干渉しない。pwa-register が SW 登録を完了した後、
 * PWAUpdateManager が同じ registration を取得して updatefound を監視する。
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal を使わない。
 * innerHTML は固定文言のみ (ユーザー入力を絶対に挿入しない)。
 *
 * 使い方:
 *   <script src="../shared/js/pwa-update-manager.js?v=20260420-pwa1"></script>
 *   <script>PWAUpdateManager.init({ scope: 'kds', cachePrefix: 'posla-kds-' });</script>
 *
 *   options:
 *     - scope:       'admin' / 'kds' / 'handy' / 'cashier' / 'pos-register' (画面別ID)
 *     - cachePrefix: キャッシュ削除対象の prefix (例: 'posla-kds-')
 *     - swPath:      Service Worker のパス (省略時はカレントスコープから推定)
 */
var PWAUpdateManager = (function () {
  'use strict';

  var _scope = null;
  var _cachePrefix = null;
  var _registration = null;
  var _bannerId = 'posla-pwa-update-banner';
  var _resetBtnId = 'posla-pwa-reset-btn';

  function _isCustomerScope() {
    var p = location.pathname || '';
    return p.indexOf('/public/customer/') !== -1
        || p.indexOf('/customer/') !== -1;
  }

  function _hasSW() {
    return !!(navigator && navigator.serviceWorker);
  }

  function init(options) {
    if (_isCustomerScope()) return;
    if (!_hasSW()) return;
    if (!options || !options.scope || !options.cachePrefix) return;

    _scope = options.scope;
    _cachePrefix = options.cachePrefix;

    // pwa-register.js の register が完了した後の registration を取得する
    // ready は activate 済み SW を含む registration を解決
    navigator.serviceWorker.getRegistration().then(function (reg) {
      if (!reg) {
        // まだ登録されていない可能性 (pwa-register が遅れている)。ready で再試行
        navigator.serviceWorker.ready.then(function (r) { _attach(r); }).catch(function () {});
        return;
      }
      _attach(reg);
    }).catch(function () {
      // SW 取得失敗時はキャッシュリセットボタンだけ提供
      _injectResetButton();
    });

    // どの scope にいても、controllerchange (新 SW が clients.claim) でユーザーへ通知
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      // skipWaiting 後に発火。controllerchange 単体では reload しない (業務中の事故防止)。
      // ユーザーが「更新する」を押したときの reload は _activateAndReload 側で行う。
    });

    _injectResetButton();
  }

  function _attach(reg) {
    _registration = reg;

    // 既に waiting 中の SW があれば即バナー表示
    if (reg.waiting) {
      _showUpdateBanner();
    }

    // 新版検出: updatefound → installing が installed になったら waiting にいる
    reg.addEventListener('updatefound', function () {
      var newWorker = reg.installing;
      if (!newWorker) return;
      newWorker.addEventListener('statechange', function () {
        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
          // 既存タブに新 SW が waiting 状態で待機 → ユーザーに通知
          _showUpdateBanner();
        }
      });
    });
  }

  function _showUpdateBanner() {
    if (document.getElementById(_bannerId)) return;
    if (!document.body) {
      document.addEventListener('DOMContentLoaded', _showUpdateBanner);
      return;
    }

    var banner = document.createElement('div');
    banner.id = _bannerId;
    banner.setAttribute('role', 'status');
    banner.style.cssText = [
      'position:fixed',
      'bottom:60px',                  // キャッシュリセットボタンの上
      'left:50%',
      'transform:translateX(-50%)',
      'z-index:2147483000',
      'background:#1565c0',
      'color:#fff',
      'border-radius:8px',
      'padding:10px 14px',
      'font-size:13px',
      'line-height:1.4',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Yu Gothic UI",sans-serif',
      'display:flex',
      'align-items:center',
      'gap:10px',
      'box-shadow:0 4px 12px rgba(0,0,0,0.25)',
      'max-width:calc(100% - 24px)'
    ].join(';');

    var span = document.createElement('span');
    span.style.cssText = 'flex:1 1 auto;min-width:0';
    span.textContent = '新しいバージョンがあります。更新すると最新の画面になります。';

    var updateBtn = document.createElement('button');
    updateBtn.type = 'button';
    updateBtn.textContent = '更新する';
    updateBtn.style.cssText = [
      'flex:0 0 auto',
      'background:#fff',
      'border:1px solid #fff',
      'color:#1565c0',
      'border-radius:4px',
      'padding:5px 12px',
      'font-size:12px',
      'cursor:pointer',
      'line-height:1.2',
      'font-weight:700'
    ].join(';');
    updateBtn.addEventListener('click', _activateAndReload);

    var laterBtn = document.createElement('button');
    laterBtn.type = 'button';
    laterBtn.textContent = '後で';
    laterBtn.style.cssText = [
      'flex:0 0 auto',
      'background:transparent',
      'border:1px solid rgba(255,255,255,0.6)',
      'color:#fff',
      'border-radius:4px',
      'padding:5px 12px',
      'font-size:12px',
      'cursor:pointer',
      'line-height:1.2'
    ].join(';');
    laterBtn.addEventListener('click', function () {
      var n = document.getElementById(_bannerId);
      if (n && n.parentNode) n.parentNode.removeChild(n);
    });

    banner.appendChild(span);
    banner.appendChild(updateBtn);
    banner.appendChild(laterBtn);
    document.body.appendChild(banner);
  }

  function _activateAndReload() {
    if (!_registration || !_registration.waiting) {
      // waiting がなければそのままリロード (キャッシュバスター無し版で取得を試みる)
      location.reload();
      return;
    }

    // controllerchange を待ってからリロード (skipWaiting 完了を確認)
    var reloaded = false;
    function onChange() {
      if (reloaded) return;
      reloaded = true;
      navigator.serviceWorker.removeEventListener('controllerchange', onChange);
      location.reload();
    }
    navigator.serviceWorker.addEventListener('controllerchange', onChange);

    // フォールバック: 3 秒後にも reload (controllerchange が来ないケース対策)
    setTimeout(function () {
      if (!reloaded) {
        reloaded = true;
        location.reload();
      }
    }, 3000);

    try {
      _registration.waiting.postMessage({ type: 'SKIP_WAITING' });
    } catch (e) {
      location.reload();
    }
  }

  // ── キャッシュリセットボタン (画面右下に控えめ) ──
  function _injectResetButton() {
    if (document.getElementById(_resetBtnId)) return;
    if (!document.body) {
      document.addEventListener('DOMContentLoaded', _injectResetButton);
      return;
    }

    var btn = document.createElement('button');
    btn.id = _resetBtnId;
    btn.type = 'button';
    btn.title = '表示がおかしい場合はキャッシュをリセットしてください';
    btn.textContent = '⟳ キャッシュリセット';
    btn.style.cssText = [
      'position:fixed',
      'bottom:8px',
      'right:8px',
      'z-index:2147482999',           // 更新バナーより一段下
      'background:rgba(0,0,0,0.55)',
      'color:#fff',
      'border:1px solid rgba(255,255,255,0.25)',
      'border-radius:14px',
      'padding:4px 10px',
      'font-size:11px',
      'line-height:1.2',
      'cursor:pointer',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans",sans-serif',
      'opacity:0.7'
    ].join(';');
    btn.addEventListener('click', function () {
      if (!confirm('この画面のキャッシュをリセットして再読込します。\nログイン情報・店舗選択は保持されますが、入力途中の内容は失われる場合があります。\n実行しますか?')) return;
      _resetCachesAndReload();
    });
    document.body.appendChild(btn);
  }

  function _resetCachesAndReload() {
    if (!('caches' in window)) {
      location.reload();
      return;
    }
    caches.keys().then(function (keys) {
      var deletions = [];
      for (var i = 0; i < keys.length; i++) {
        var key = keys[i];
        if (key.indexOf(_cachePrefix) === 0) {
          deletions.push(caches.delete(key));
        }
      }
      return Promise.all(deletions);
    }).then(function () {
      location.reload();
    }).catch(function () {
      location.reload();
    });
  }

  return {
    init: init
  };
})();
