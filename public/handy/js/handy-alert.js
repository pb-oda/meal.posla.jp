/**
 * ハンディ 呼び出しアラート（handy-alert.js）
 *
 * スタッフ呼び出し通知をハンディ画面上部に赤バナーで表示。
 * 5秒ポーリング + ビープ音 + 対応済みボタン。
 * DOM自己挿入パターン。
 *
 * store_id は localStorage('mt_handy_store') から取得。
 *
 * ES5 IIFE
 */
var HandyAlert = (function () {
  'use strict';

  var API_URL = '../../api/kds/call-alerts.php';
  var POLL_INTERVAL = 5000; // 5秒
  var _timer = null;
  var _banner = null;
  var _lastAlertIds = [];
  var _audioCtx = null;
  // Phase 3: オフライン時の表示耐性
  var _hasRendered = false;
  var SNAPSHOT_SCOPE = 'handy';
  var SNAPSHOT_CHANNEL = 'call_alerts';
  var _initRetries = 0;

  // ── 初期化 ──
  function init() {
    _injectStyles();
    _createBanner();
    _setupAudioUnlock();
    // store_id がlocalStorageにセットされるのを待つ
    _waitAndStart();
  }

  // ── モバイル AudioContext アンロック ──
  var _audioUnlocked = false;

  function _setupAudioUnlock() {
    // 画面のどこかをタップでもアンロック（保険）
    function unlock() {
      _unlockAudioCtx();
      document.removeEventListener('touchstart', unlock, true);
      document.removeEventListener('click', unlock, true);
    }
    document.addEventListener('touchstart', unlock, true);
    document.addEventListener('click', unlock, true);

    // 起動時に通知音有効化バナーを表示
    _showAudioEnableBanner();
  }

  function _unlockAudioCtx() {
    if (_audioUnlocked) return;
    if (!_audioCtx) {
      try { _audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
    }
    if (_audioCtx && _audioCtx.state === 'suspended') {
      _audioCtx.resume();
    }
    _audioUnlocked = true;
  }

  function _showAudioEnableBanner() {
    var bar = document.createElement('div');
    bar.id = 'handy-audio-enable';
    bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#ff9800;color:#fff;'
      + 'text-align:center;padding:12px 16px;font-size:0.9rem;font-weight:700;z-index:9999;'
      + 'cursor:pointer;box-shadow:0 -2px 8px rgba(0,0,0,0.2);';
    bar.textContent = '\uD83D\uDD14 タップして通知音を有効にする';

    bar.addEventListener('click', function () {
      _unlockAudioCtx();
      // テスト音を鳴らして有効化を確認
      if (_audioCtx) {
        try {
          var o = _audioCtx.createOscillator();
          var g = _audioCtx.createGain();
          o.connect(g);
          g.connect(_audioCtx.destination);
          o.type = 'sine';
          o.frequency.value = 880;
          g.gain.value = 0.2;
          var now = _audioCtx.currentTime;
          o.start(now);
          o.stop(now + 0.1);
        } catch (e) {}
      }
      if (bar.parentNode) bar.parentNode.removeChild(bar);
    });

    document.body.appendChild(bar);
  }

  function _waitAndStart() {
    var storeId = _getStoreId();
    if (storeId) {
      _startPolling();
      return;
    }
    _initRetries++;
    if (_initRetries < 30) {
      setTimeout(_waitAndStart, 500);
    }
  }

  // ── スタイル注入 ──
  function _injectStyles() {
    var style = document.createElement('style');
    style.textContent = ''
      + '.handy-call-banner {'
      + '  display: none; background: #d32f2f; color: #fff;'
      + '  padding: 0; overflow: hidden;'
      + '  transition: max-height 0.3s ease;'
      + '  max-height: 0;'
      + '}'
      + '.handy-call-banner.handy-call-show {'
      + '  display: block; max-height: 300px;'
      + '  padding: 6px 12px;'
      + '}'
      + '.handy-call-item {'
      + '  display: flex; align-items: center; gap: 8px;'
      + '  padding: 6px 0; font-size: 0.85rem;'
      + '  border-bottom: 1px solid rgba(255,255,255,0.2);'
      + '}'
      + '.handy-call-item:last-child { border-bottom: none; }'
      + '.handy-call-icon { font-size: 1rem; flex-shrink: 0; }'
      + '.handy-call-text { flex: 1; }'
      + '.handy-call-table { font-weight: 700; }'
      + '.handy-call-elapsed { font-size: 0.7rem; opacity: 0.8; margin-left: 4px; }'
      + '.handy-call-ack {'
      + '  background: rgba(255,255,255,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.4);'
      + '  padding: 4px 10px; border-radius: 4px; font-size: 0.75rem;'
      + '  cursor: pointer; white-space: nowrap; flex-shrink: 0;'
      + '}'
      + '.handy-call-ack:hover { background: rgba(255,255,255,0.3); }'
      + '.handy-call-item--ready { background: rgba(255,255,255,0.1); border-left: 3px solid #66bb6a; padding-left: 9px; }'
      + '.handy-call-item--kitchen { background: rgba(255,255,255,0.1); border-left: 3px solid #ff9800; padding-left: 9px; }';
    document.head.appendChild(style);
  }

  // ── バナーDOM作成 ──
  function _createBanner() {
    _banner = document.createElement('div');
    _banner.className = 'handy-call-banner';
    _banner.id = 'handy-call-banner';

    // ヘッダーの直後に挿入
    var header = document.querySelector('.handy-header');
    if (header && header.nextSibling) {
      header.parentNode.insertBefore(_banner, header.nextSibling);
    } else {
      document.body.insertBefore(_banner, document.body.firstChild);
    }

    // 対応済みボタンのイベント委譲
    _banner.addEventListener('click', function (e) {
      var btn = e.target.closest('.handy-call-ack');
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
    _poll();
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
        // Phase 3: snapshot 保存
        if (typeof OfflineSnapshot !== 'undefined') {
          OfflineSnapshot.save(SNAPSHOT_SCOPE, storeId, SNAPSHOT_CHANNEL, alerts);
        }
      })
      .catch(function () {
        // Phase 3: 初回失敗時のみ snapshot 復元 + stale ヘッダ
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

    if (alerts.length === 0) {
      _banner.classList.remove('handy-call-show');
      _banner.innerHTML = '';
      _lastAlertIds = [];
      return;
    }

    // タイプ分類（ビープ音の判定に先行して必要）
    var hasStaffCall = false;
    var hasProductReady = false;
    var hasKitchenCall = false;
    for (var ti = 0; ti < alerts.length; ti++) {
      var t = alerts[ti].type || 'staff_call';
      if (t === 'product_ready') hasProductReady = true;
      else if (t === 'kitchen_call') hasKitchenCall = true;
      else hasStaffCall = true;
    }

    // 新着ビープ
    var newAlerts = false;
    var currentIds = [];
    for (var i = 0; i < alerts.length; i++) {
      currentIds.push(alerts[i].id);
      if (_lastAlertIds.indexOf(alerts[i].id) === -1) {
        newAlerts = true;
      }
    }
    if (newAlerts) {
      if (hasKitchenCall) {
        _playBeep('kitchen');
        _vibrate([200, 100, 200, 100, 200]); // 長めの振動パターン
      } else if (hasProductReady && !hasStaffCall) {
        _playBeep('ready');
        _vibrate([150, 80, 150]);
      } else {
        _playBeep('call');
        _vibrate([200, 100, 200]);
      }
    }
    _lastAlertIds = currentIds;

    var html = '';
    for (var j = 0; j < alerts.length; j++) {
      var a = alerts[j];
      var alertType = a.type || 'staff_call';
      var elapsed = a.elapsed_seconds || 0;
      var elapsedText = elapsed < 60
        ? elapsed + '秒前'
        : Math.floor(elapsed / 60) + '分前';

      // O-5: タイプ別表示
      var icon, text, itemClass;
      if (alertType === 'product_ready') {
        icon = '&#x1F373;'; // 🍳
        text = '<span class="handy-call-table">' + _escapeHtml(a.table_code) + '</span>'
          + ' ' + _escapeHtml(a.item_name || a.reason || '商品完成');
        itemClass = 'handy-call-item handy-call-item--ready';
        hasProductReady = true;
      } else if (alertType === 'kitchen_call') {
        icon = '&#x1F468;&#x200D;&#x1F373;'; // 👨‍🍳
        text = _escapeHtml(a.reason);
        itemClass = 'handy-call-item handy-call-item--kitchen';
      } else {
        icon = '&#x1F514;'; // 🔔
        text = '<span class="handy-call-table">' + _escapeHtml(a.table_code) + '</span>'
          + ' 呼び出し：「' + _escapeHtml(a.reason) + '」';
        itemClass = 'handy-call-item';
        hasStaffCall = true;
      }

      var ackLabel = alertType === 'product_ready' ? '配膳完了' : '対応済み'; // kitchen_call も「対応済み」
      // Phase 3: stale 時はボタンを出さず read-only
      var ackBtn = isStale
        ? ''
        : '<button class="handy-call-ack" data-alert-id="' + _escapeHtml(a.id) + '">' + ackLabel + '</button>';
      html += '<div class="' + itemClass + '">'
        + '<span class="handy-call-icon">' + icon + '</span>'
        + '<span class="handy-call-text">' + text
        + '<span class="handy-call-elapsed">' + elapsedText + '</span>'
        + '</span>'
        + ackBtn
        + '</div>';
    }
    // Phase 3: stale ヘッダ (古い呼び出し情報である旨)
    if (isStale) {
      var staleHeader = '<div class="handy-call-item" style="opacity:0.85;font-style:italic;">'
        + '\u26A0\uFE0F \u53E4\u3044\u547C\u3073\u51FA\u3057\u60C5\u5831\u3067\u3059\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u6700\u65B0\u8868\u793A\u306B\u66F4\u65B0\u3055\u308C\u307E\u3059\u3002'
        + '</div>';
      html = staleHeader + html;
    }
    _banner.innerHTML = html;
    _banner.classList.add('handy-call-show');
  }

  // ── 対応済み ──
  function _acknowledgeAlert(alertId) {
    // Phase 3: offline または stale 中の PATCH は絶対に送らない (キューしない)
    var isOfflineOrStale =
      (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale && OfflineStateBanner.isOfflineOrStale())
      || (typeof OfflineDetector !== 'undefined' && OfflineDetector.isOnline && !OfflineDetector.isOnline());
    if (isOfflineOrStale) {
      var btn = _banner && _banner.querySelector('.handy-call-ack[data-alert-id="' + alertId + '"]');
      if (btn) {
        btn.disabled = false;
        btn.textContent = btn.dataset.origLabel || '\u5BFE\u5FDC\u6E08\u307F';
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
    .then(function () {
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
  function _playBeep(type) {
    try {
      var AudioContext = window.AudioContext || window.webkitAudioContext;
      if (!AudioContext) return;
      if (!_audioCtx) _audioCtx = new AudioContext();
      if (_audioCtx.state === 'suspended') {
        _audioCtx.resume();
      }

      var now = _audioCtx.currentTime;

      // kitchen_call: 緊急3連ビープ × 2セット（気づきやすい）
      if (type === 'kitchen') {
        _playKitchenAlarm(now);
        return;
      }

      // O-5: product_ready は低音2トーン、staff_call は高音2トーン
      var freq1 = type === 'ready' ? 523 : 880;
      var freq2 = type === 'ready' ? 659 : 1100;

      var osc = _audioCtx.createOscillator();
      var gain = _audioCtx.createGain();
      osc.connect(gain);
      gain.connect(_audioCtx.destination);
      osc.type = 'sine';
      osc.frequency.value = freq1;
      gain.gain.value = 0.3;
      osc.start(now);
      osc.stop(now + 0.15);

      var osc2 = _audioCtx.createOscillator();
      var gain2 = _audioCtx.createGain();
      osc2.connect(gain2);
      gain2.connect(_audioCtx.destination);
      osc2.type = 'sine';
      osc2.frequency.value = freq2;
      gain2.gain.value = 0.3;
      osc2.start(now + 0.2);
      osc2.stop(now + 0.35);
    } catch (e) { /* ignore */ }
  }

  // kitchen_call 専用アラーム: ピピピッ × 2セット
  function _playKitchenAlarm(now) {
    try {
      var tones = [
        // 1セット目: 高→中→高（ピピピッ）
        { freq: 880, start: 0,    dur: 0.12 },
        { freq: 700, start: 0.15, dur: 0.12 },
        { freq: 880, start: 0.30, dur: 0.12 },
        // 2セット目（0.5秒後に繰り返し）
        { freq: 880, start: 0.55, dur: 0.12 },
        { freq: 700, start: 0.70, dur: 0.12 },
        { freq: 880, start: 0.85, dur: 0.12 }
      ];
      for (var i = 0; i < tones.length; i++) {
        var t = tones[i];
        var o = _audioCtx.createOscillator();
        var g = _audioCtx.createGain();
        o.connect(g);
        g.connect(_audioCtx.destination);
        o.type = 'sine';
        o.frequency.value = t.freq;
        g.gain.value = 0.4;
        o.start(now + t.start);
        o.stop(now + t.start + t.dur);
      }
    } catch (e) { /* ignore */ }
  }

  // ── バイブレーション（AudioContext不要・ユーザー操作不要） ──
  function _vibrate(pattern) {
    try {
      if (navigator.vibrate) navigator.vibrate(pattern);
    } catch (e) { /* ignore */ }
  }

  // ── store_id取得 ──
  function _getStoreId() {
    return localStorage.getItem('mt_handy_store') || '';
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
