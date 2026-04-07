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
        _renderAlerts(alerts);
      })
      .catch(function () { /* サイレント失敗 */ });
  }

  // ── 描画 ──
  function _renderAlerts(alerts) {
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
    for (var j = 0; j < alerts.length; j++) {
      var a = alerts[j];
      var elapsed = a.elapsed_seconds || 0;
      var elapsedText = elapsed < 60
        ? elapsed + '秒前'
        : Math.floor(elapsed / 60) + '分前';

      html += '<div class="kds-call-item">'
        + '<span class="kds-call-icon">&#x1F514;</span>'
        + '<span class="kds-call-text">'
        + '<span class="kds-call-table">' + _escapeHtml(a.table_code) + '</span>'
        + ' からの呼び出し：「' + _escapeHtml(a.reason) + '」'
        + '<span class="kds-call-elapsed">' + elapsedText + '</span>'
        + '</span>'
        + '<button class="kds-call-ack" data-alert-id="' + _escapeHtml(a.id) + '">対応済み</button>'
        + '</div>';
    }
    _banner.innerHTML = html;
    _banner.classList.add('kds-call-show');
  }

  // ── 対応済み ──
  function _acknowledgeAlert(alertId) {
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
      // 即座に再ポーリングして表示を更新
      _poll();
    })
    .catch(function () {
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
