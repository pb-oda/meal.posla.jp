/**
 * POSLA Web Push 購読マネージャ (PWA Phase 2a)
 *
 * 業務端末 (KDS / レジ / ハンディ / 管理画面 / オーナー画面) で通知許可を取得し、
 * PushSubscription をサーバーへ保存する。
 *
 * 設計方針:
 *   - 顧客画面 (public/customer/) では絶対に動作しない (URL 判定で early return)
 *   - ページ読み込み直後に Notification.requestPermission() を呼ばない (ユーザー操作起点)
 *   - Android Chrome 標準環境を前提。iOS / 未対応ブラウザでは静かに無効化
 *   - 通知許可がすでに denied なら案内文だけ表示 (再取得を強制しない)
 *   - サーバー送信ロジックが Phase 2b のため、購読しても通知が届かない期間がある
 *
 * 提供 API:
 *   PushSubscriptionManager.init({ scope: 'kds', storeId: '...', deviceLabel: '...' })
 *   PushSubscriptionManager.attachUI(container, options)
 *     options:
 *       compact: true → 業務端末向けの小さな状態表示のみ
 *       compact: false → ボタンつきフル UI (admin / owner 向け)
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal を使わない
 * innerHTML は固定文言 (動的入力は textContent)
 */
var PushSubscriptionManager = (function () {
  'use strict';

  var STATE = {
    UNSUPPORTED: 'unsupported',     // ブラウザ未対応 / SW なし
    NO_VAPID:    'no_vapid',        // サーバーに VAPID 未設定
    DENIED:      'denied',          // ユーザーがブロック
    DEFAULT:     'default',         // 未許可 (まだ要求していない)
    GRANTED:     'granted',         // 許可済み
    SUBSCRIBED:  'subscribed',      // 購読保存済
    ERROR:       'error'
  };

  var _state = STATE.DEFAULT;
  var _scope = null;
  var _storeId = null;
  var _deviceLabel = null;
  var _vapidPublicKey = null;
  var _statusCallback = null;       // attachUI 経由で更新する

  function _isCustomerScope() {
    var p = location.pathname || '';
    return p.indexOf('/public/customer/') !== -1
        || p.indexOf('/customer/') !== -1;
  }

  function _supported() {
    return ('serviceWorker' in navigator)
        && ('PushManager' in window)
        && (typeof Notification !== 'undefined');
  }

  function _setState(s) {
    _state = s;
    if (_statusCallback) _statusCallback(s);
  }

  function _padRight(str, len, padChar) {
    // ES5 互換: String.prototype.repeat() を使わない手動パディング
    while (str.length < len) str += padChar;
    return str;
  }

  function _urlBase64ToUint8Array(base64String) {
    // Web Push の applicationServerKey は Uint8Array が必要 (base64url を変換)
    var padding = _padRight('', (4 - base64String.length % 4) % 4, '=');
    var base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  function _fetchConfig() {
    return fetch('/api/push/config.php', { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) return null;
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { return null; }
        });
      })
      .then(function (json) {
        if (!json || !json.ok || !json.data) return null;
        return json.data;   // { available, vapidPublicKey }
      })
      .catch(function () { return null; });
  }

  function init(options) {
    if (_isCustomerScope()) return;
    if (!options) options = {};

    _scope = options.scope || 'staff';
    _storeId = options.storeId || null;
    _deviceLabel = options.deviceLabel || null;

    if (!_supported()) {
      _setState(STATE.UNSUPPORTED);
      return;
    }

    // 既に denied なら状態だけセットして終了
    if (Notification.permission === 'denied') {
      _setState(STATE.DENIED);
      return;
    }

    // VAPID 公開鍵を取得 (未設定なら NO_VAPID)
    _fetchConfig().then(function (cfg) {
      if (!cfg || !cfg.available || !cfg.vapidPublicKey) {
        _setState(STATE.NO_VAPID);
        return;
      }
      _vapidPublicKey = cfg.vapidPublicKey;

      // permission==='granted' で既に subscription があれば SUBSCRIBED 状態に
      if (Notification.permission === 'granted') {
        _checkExistingSubscription();
      } else {
        _setState(STATE.DEFAULT);
      }
    });
  }

  function _checkExistingSubscription() {
    navigator.serviceWorker.getRegistration().then(function (reg) {
      if (!reg) { _setState(STATE.GRANTED); return; }
      reg.pushManager.getSubscription().then(function (sub) {
        if (sub) {
          _setState(STATE.SUBSCRIBED);
        } else {
          // 許可済みだが未購読 → サーバーに保存しに行く
          _doSubscribe(reg);
        }
      }).catch(function () { _setState(STATE.ERROR); });
    }).catch(function () { _setState(STATE.ERROR); });
  }

  /**
   * 通知許可ボタン押下時に呼ぶ。
   * ユーザー操作起点 (clickハンドラ内) で呼ばないと denied されやすい。
   */
  function requestPermission() {
    if (_state === STATE.UNSUPPORTED || _state === STATE.NO_VAPID) return;

    Notification.requestPermission().then(function (permission) {
      if (permission !== 'granted') {
        _setState(permission === 'denied' ? STATE.DENIED : STATE.DEFAULT);
        return;
      }
      navigator.serviceWorker.getRegistration().then(function (reg) {
        if (!reg) {
          // pwa-register がまだ登録していない場合は ready を待つ
          navigator.serviceWorker.ready.then(_doSubscribe).catch(function () { _setState(STATE.ERROR); });
        } else {
          _doSubscribe(reg);
        }
      }).catch(function () { _setState(STATE.ERROR); });
    }).catch(function () { _setState(STATE.ERROR); });
  }

  function _doSubscribe(reg) {
    if (!_vapidPublicKey) { _setState(STATE.NO_VAPID); return; }

    var subOptions = {
      userVisibleOnly: true,
      applicationServerKey: _urlBase64ToUint8Array(_vapidPublicKey)
    };

    reg.pushManager.subscribe(subOptions).then(function (sub) {
      // サーバーへ POST
      var body = {
        scope: _scope,
        store_id: _storeId,
        device_label: _deviceLabel,
        subscription: sub.toJSON()
      };
      return fetch('/api/push/subscribe.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      }).then(function (r) {
        if (!r.ok) {
          _setState(STATE.ERROR);
          return;
        }
        _setState(STATE.SUBSCRIBED);
      });
    }).catch(function () {
      // pushManager.subscribe 失敗 (VAPID 鍵不整合・OS制限など)
      _setState(STATE.ERROR);
    });
  }

  function unsubscribe() {
    navigator.serviceWorker.getRegistration().then(function (reg) {
      if (!reg) { _setState(STATE.DEFAULT); return; }
      reg.pushManager.getSubscription().then(function (sub) {
        if (!sub) { _setState(STATE.GRANTED); return; }
        var endpoint = sub.endpoint;
        sub.unsubscribe().then(function () {
          // サーバー側も無効化 (失敗しても UI 上は GRANTED に戻す)
          fetch('/api/push/unsubscribe.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ endpoint: endpoint })
          }).catch(function () {});
          _setState(STATE.GRANTED);
        });
      });
    });
  }

  function getState() { return _state; }

  /**
   * 後から storeId が確定したときに呼ぶ (KDS の KdsAuth / handy の HandyApp / admin の StoreSelector など)。
   * - 既に SUBSCRIBED の場合は subscribe.php を再 POST して store_id を UPSERT 更新する
   *   (push_save_subscription は endpoint_hash で UPSERT 実装になっているため安全)
   * - まだ DEFAULT の場合は内部変数だけ更新し、後の requestPermission 時に新 storeId で送信される
   * - 値が変わらなければ何もしない (無駄な POST を避ける)
   */
  function setStoreId(storeId) {
    storeId = storeId || null;
    if (_storeId === storeId) return;
    _storeId = storeId;
    if (_state === STATE.SUBSCRIBED) {
      _refreshSubscriptionMetadata();
    }
  }

  function _refreshSubscriptionMetadata() {
    if (!_supported()) return;
    navigator.serviceWorker.getRegistration().then(function (reg) {
      if (!reg) return;
      reg.pushManager.getSubscription().then(function (sub) {
        if (!sub) return;
        var body = {
          scope: _scope,
          store_id: _storeId,
          device_label: _deviceLabel,
          subscription: sub.toJSON()
        };
        fetch('/api/push/subscribe.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        }).catch(function () { /* 失敗しても UI 状態は維持 */ });
      }).catch(function () {});
    }).catch(function () {});
  }

  /**
   * UI を attach する。container は既存の DOM 要素 (例: 設定パネル内の div / KDS ヘッダ要素)。
   * options.compact = true → 業務端末向けの小さな状態表示のみ
   * options.compact = false → admin / owner 向けのボタンつきフル UI
   */
  function attachUI(container, options) {
    if (!container) return;
    if (!options) options = {};
    var compact = !!options.compact;

    var wrap = document.createElement('div');
    wrap.style.cssText = compact
      ? 'display:inline-flex;align-items:center;gap:6px;font-size:0.75rem;'
      : 'display:flex;flex-direction:column;gap:8px;font-size:0.85rem;padding:10px 12px;background:#f5f5f5;border-radius:6px;border:1px solid #e0e0e0;max-width:360px;';

    var label = document.createElement('span');
    label.style.cssText = compact ? 'opacity:0.75;' : 'color:#444;';

    var actionRow = null;
    var enableBtn = null;
    var disableBtn = null;

    if (!compact) {
      // フル UI: 「この端末で通知を受け取る」「通知を停止」ボタン
      var title = document.createElement('div');
      title.textContent = '通知 (この端末)';
      title.style.cssText = 'font-weight:600;font-size:0.9rem;';
      wrap.appendChild(title);

      actionRow = document.createElement('div');
      actionRow.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;';

      enableBtn = document.createElement('button');
      enableBtn.type = 'button';
      enableBtn.textContent = 'この端末で通知を受け取る';
      enableBtn.style.cssText = 'background:#1565c0;color:#fff;border:none;border-radius:4px;padding:6px 12px;font-size:0.8rem;cursor:pointer;';
      enableBtn.addEventListener('click', requestPermission);

      disableBtn = document.createElement('button');
      disableBtn.type = 'button';
      disableBtn.textContent = '通知を停止';
      disableBtn.style.cssText = 'background:transparent;color:#666;border:1px solid #ccc;border-radius:4px;padding:6px 12px;font-size:0.8rem;cursor:pointer;';
      disableBtn.addEventListener('click', unsubscribe);

      actionRow.appendChild(enableBtn);
      actionRow.appendChild(disableBtn);
    }

    wrap.appendChild(label);
    if (actionRow) wrap.appendChild(actionRow);
    container.appendChild(wrap);

    // 状態に応じた表示切替
    function render(state) {
      var labels = {};
      labels[STATE.UNSUPPORTED] = '通知: 未対応 (Android Chrome 推奨)';
      labels[STATE.NO_VAPID]    = '通知: サーバー未設定';
      labels[STATE.DENIED]      = '通知: ブロック中 (ブラウザ設定から許可してください)';
      labels[STATE.DEFAULT]     = '通知: 未設定';
      labels[STATE.GRANTED]     = '通知: 許可済み (購読待ち)';
      labels[STATE.SUBSCRIBED]  = '通知: 有効';
      labels[STATE.ERROR]       = '通知: エラー (時間を置いて再試行)';
      label.textContent = labels[state] || labels[STATE.DEFAULT];

      if (!compact) {
        var canEnable = (state === STATE.DEFAULT || state === STATE.ERROR);
        var canDisable = (state === STATE.SUBSCRIBED || state === STATE.GRANTED);
        if (enableBtn) enableBtn.style.display = canEnable ? '' : 'none';
        if (disableBtn) disableBtn.style.display = canDisable ? '' : 'none';
      }
    }
    render(_state);
    _statusCallback = render;
  }

  return {
    init: init,
    requestPermission: requestPermission,
    unsubscribe: unsubscribe,
    getState: getState,
    setStoreId: setStoreId,
    attachUI: attachUI
  };
})();
