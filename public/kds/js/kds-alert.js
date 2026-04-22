/**
 * KDS 呼び出しアラート（kds-alert.js）
 *
 * スタッフ呼び出し通知をKDS画面上部に赤バナーで表示。
 * 5秒ポーリング + ビープ音 + 対応済みボタン。
 * DOM自己挿入パターン。
 *
 * 依存: KdsAuth, Utils
 * ES5 IIFE
 */
var KdsAlert = (function () {
  'use strict';

  var API_URL = '../../api/kds/call-alerts.php';
  var POLL_INTERVAL = 5000; // 5秒
  var _timer = null;
  var _banner = null;
  var _lastAlertIds = []; // 前回表示中のアラートID（音の重複防止）
  var _audioCtx = null;
  // Phase 3: オフライン時の表示耐性
  var _hasRendered = false;
  var _staleHeader = null;
  var SNAPSHOT_SCOPE = 'kds';
  var SNAPSHOT_CHANNEL = 'call_alerts';

  // ── 初期化 ──
  function init() {
    _injectStyles();
    _createBanner();
    _startPolling();
  }

  // ── スタイル注入 ──
  function _injectStyles() {
    var style = document.createElement('style');
    style.textContent = ''
      + '.kds-call-banner {'
      + '  display: none; background: #d32f2f; color: #fff;'
      + '  padding: 0; overflow: hidden;'
      + '  transition: max-height 0.3s ease;'
      + '  max-height: 0;'
      + '}'
      + '.kds-call-banner.kds-call-show {'
      + '  display: block; max-height: 300px;'
      + '  padding: 6px 12px;'
      + '}'
      + '.kds-call-item {'
      + '  display: flex; align-items: center; gap: 10px;'
      + '  padding: 6px 0; font-size: 0.875rem;'
      + '  border-bottom: 1px solid rgba(255,255,255,0.2);'
      + '}'
      + '.kds-call-item:last-child { border-bottom: none; }'
      + '.kds-call-icon { font-size: 1.1rem; flex-shrink: 0; }'
      + '.kds-call-text { flex: 1; }'
      + '.kds-call-table { font-weight: 700; }'
      + '.kds-call-elapsed { font-size: 0.75rem; opacity: 0.8; margin-left: 6px; }'
      + '.kds-call-ack {'
      + '  background: rgba(255,255,255,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.4);'
      + '  padding: 4px 12px; border-radius: 4px; font-size: 0.8rem;'
      + '  cursor: pointer; white-space: nowrap; flex-shrink: 0;'
      + '}'
      + '.kds-call-ack:hover { background: rgba(255,255,255,0.3); }';
    document.head.appendChild(style);
  }

  // ── バナーDOM作成 ──
  function _createBanner() {
    _banner = document.createElement('div');
    _banner.className = 'kds-call-banner';
    _banner.id = 'kds-call-banner';

    // ヘッダーの直後に挿入
    var header = document.querySelector('.kds-header');
    if (header && header.nextSibling) {
      header.parentNode.insertBefore(_banner, header.nextSibling);
    } else {
      document.body.insertBefore(_banner, document.body.firstChild);
    }

    // 対応済みボタンのイベント委譲
    _banner.addEventListener('click', function (e) {
      var btn = e.target.closest('.kds-call-ack');
      if (!btn) return;
      var alertId = btn.dataset.alertId;
      if (!alertId) return;
      btn.disabled = true;
      btn.textContent = '処理中...';
      _acknowledgeAlert(alertId);
    });
  }

  // ── ポーリング ──
  function _startPolling() {
    _poll(); // 初回即時実行
    _timer = setInterval(_poll, POLL_INTERVAL);
  }

  function _poll() {
    var storeId = _getStoreId();
    if (!storeId) return;

    // 表示中アラートがある間はゲート bypass:
    //   - 各アラートの elapsed_seconds (例「5秒前」「1分前」) はサーバー算出のため、
    //     skip すると表示が固まる
    //   - アラートが空の steady state では引き続き revision で skip 可能
    if (_lastAlertIds.length > 0) {
      _doPoll(storeId);
      return;
    }

    // Phase 1 軽量化: revision で変化が無ければ skip
    if (typeof RevisionGate !== 'undefined') {
      RevisionGate.shouldFetch('call_alerts', storeId).then(function (should) {
        if (should) _doPoll(storeId);
      });
    } else {
      _doPoll(storeId);
    }
  }

  function _doPoll(storeId) {
    var url = API_URL + '?store_id=' + encodeURIComponent(storeId);
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (!json.ok) return;
        var alerts = (json.data && json.data.alerts) || [];
        _renderAlerts(alerts, false);
        _hasRendered = true;
        // Phase 3: snapshot 保存 (call_alerts は小さいので常時保存 OK)
        if (typeof OfflineSnapshot !== 'undefined') {
          OfflineSnapshot.save(SNAPSHOT_SCOPE, storeId, SNAPSHOT_CHANNEL, alerts);
        }
      })
      .catch(function () {
        // Phase 3: 初回失敗時のみ snapshot から復元 + stale ヘッダを付けて描画
        if (_hasRendered) return;
        if (typeof OfflineSnapshot === 'undefined') return;
        var snap = OfflineSnapshot.load(SNAPSHOT_SCOPE, storeId, SNAPSHOT_CHANNEL);
        if (snap && snap.data) {
          _renderAlerts(snap.data, true);
          _hasRendered = true;
        }
      });
  }

  // ── 描画 ──
  function _renderAlerts(alerts, isStale) {
    if (!_banner) return;

    // O-5: KDS画面では product_ready を除外（調理側に「できました」通知は不要）
    // kitchen_call も除外（KDS自身が送信したアラートなので表示不要）
    var filtered = [];
    for (var fi = 0; fi < alerts.length; fi++) {
      var aType = alerts[fi].type || 'staff_call';
      if (aType !== 'product_ready' && aType !== 'kitchen_call') {
        filtered.push(alerts[fi]);
      }
    }
    alerts = filtered;

    if (alerts.length === 0) {
      _banner.classList.remove('kds-call-show');
      _banner.innerHTML = '';
      _lastAlertIds = [];
      return;
    }

    // 新着アラートがあればビープ音
    var newAlerts = false;
    var currentIds = [];
    for (var i = 0; i < alerts.length; i++) {
      currentIds.push(alerts[i].id);
      if (_lastAlertIds.indexOf(alerts[i].id) === -1) {
        newAlerts = true;
      }
    }
    if (newAlerts) _playBeep();
    _lastAlertIds = currentIds;

    var html = '';
    // Phase 3: stale 表示時は先頭にヘッダを追加し、対応済みボタンは出さない (read-only)
    if (isStale) {
      html += '<div class="kds-call-item" style="opacity:0.85;font-style:italic;">'
        + '\u26A0\uFE0F \u53E4\u3044\u547C\u3073\u51FA\u3057\u60C5\u5831\u3067\u3059\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u6700\u65B0\u8868\u793A\u306B\u66F4\u65B0\u3055\u308C\u307E\u3059\u3002'
        + '</div>';
    }
    for (var j = 0; j < alerts.length; j++) {
      var a = alerts[j];
      var elapsed = a.elapsed_seconds || 0;
      var elapsedText = elapsed < 60
        ? elapsed + '秒前'
        : Math.floor(elapsed / 60) + '分前';

      var ackBtn = isStale
        ? ''  // stale 時は対応済みボタン非表示 (オフライン中はキューしない)
        : '<button class="kds-call-ack" data-alert-id="' + _escapeHtml(a.id) + '">対応済み</button>';
      html += '<div class="kds-call-item">'
        + '<span class="kds-call-icon">&#x1F514;</span>'
        + '<span class="kds-call-text">'
        + '<span class="kds-call-table">' + _escapeHtml(a.table_code) + '</span>'
        + ' からの呼び出し：「' + _escapeHtml(a.reason) + '」'
        + '<span class="kds-call-elapsed">' + elapsedText + '</span>'
        + '</span>'
        + ackBtn
        + '</div>';
    }
    _banner.innerHTML = html;
    _banner.classList.add('kds-call-show');
  }

  // ── 対応済み ──
  function _acknowledgeAlert(alertId) {
    // Phase 3: offline または stale 中の PATCH は絶対に送らない (キューしない)
    var isOfflineOrStale =
      (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale && OfflineStateBanner.isOfflineOrStale())
      || (typeof OfflineDetector !== 'undefined' && OfflineDetector.isOnline && !OfflineDetector.isOnline());
    if (isOfflineOrStale) {
      var btn = _banner && _banner.querySelector('.kds-call-ack[data-alert-id="' + alertId + '"]');
      if (btn) {
        btn.disabled = false;
        btn.textContent = '\u5BFE\u5FDC\u6E08\u307F';
      }
      try { window.alert('\u30AA\u30D5\u30E9\u30A4\u30F3\u4E2D\u307E\u305F\u306F\u53E4\u3044\u30C7\u30FC\u30BF\u8868\u793A\u4E2D\u3067\u3059\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u304A\u9858\u3044\u3057\u307E\u3059\u3002'); } catch (e) {}
      return;
    }
    fetch(API_URL, {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ alert_id: alertId, status: 'acknowledged' })
    })
    .then(function (r) {
      return r.text().then(function (body) {
        try { return JSON.parse(body); } catch (e) { return {}; }
      });
    })
    .then(function () {
      // PATCH 直後は revision キャッシュを無効化して即時反映を保証
      if (typeof RevisionGate !== 'undefined') {
        RevisionGate.invalidate('call_alerts', _getStoreId());
      }
      _poll();
    })
    .catch(function () {
      if (typeof RevisionGate !== 'undefined') {
        RevisionGate.invalidate('call_alerts', _getStoreId());
      }
      _poll();
    });
  }

  // ── ビープ音 ──
  function _playBeep() {
    try {
      var AudioContext = window.AudioContext || window.webkitAudioContext;
      if (!AudioContext) return;
      if (!_audioCtx) _audioCtx = new AudioContext();
      if (_audioCtx.state === 'suspended') {
        _audioCtx.resume();
      }

      var osc = _audioCtx.createOscillator();
      var gain = _audioCtx.createGain();
      osc.connect(gain);
      gain.connect(_audioCtx.destination);

      osc.type = 'sine';
      osc.frequency.value = 880; // A5
      gain.gain.value = 0.3;

      var now = _audioCtx.currentTime;
      osc.start(now);
      osc.stop(now + 0.15);

      // 2回目のビープ（短い間隔で）
      var osc2 = _audioCtx.createOscillator();
      var gain2 = _audioCtx.createGain();
      osc2.connect(gain2);
      gain2.connect(_audioCtx.destination);
      osc2.type = 'sine';
      osc2.frequency.value = 1100; // C#6
      gain2.gain.value = 0.3;
      osc2.start(now + 0.2);
      osc2.stop(now + 0.35);
    } catch (e) { /* Audio APIが使えない環境は無視 */ }
  }

  // ── store_id取得 ──
  function _getStoreId() {
    if (typeof KdsAuth !== 'undefined' && KdsAuth.getStoreId) {
      return KdsAuth.getStoreId() || '';
    }
    return '';
  }

  // ── エスケープ ──
  function _escapeHtml(str) {
    if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(str);
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
  }

  // ── DOM Ready ──
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return {};
})();
