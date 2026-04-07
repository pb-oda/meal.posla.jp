/**
 * スタッフ呼び出しボタン（call-staff.js）
 *
 * セルフオーダー画面のヘッダーに「🔔 呼び出し」ボタンを追加。
 * 理由選択モーダル → POST /api/customer/call-staff.php
 * 3分間の連打防止。DOM自己挿入パターン。
 *
 * ES5 IIFE
 */
var CallStaff = (function () {
  'use strict';

  // ── 多言語ヘルパー ──
  function _lang() { return window.__posla_lang ? window.__posla_lang() : 'ja'; }
  function _t(key, fallback) { return window.__posla_t ? window.__posla_t(key, fallback) : fallback; }

  var API_URL = '../../api/customer/call-staff.php';
  var COOLDOWN_MS = 3 * 60 * 1000; // 3分

  var _storeId = null;
  var _tableId = null;
  var ACK_POLL_INTERVAL = 5000; // 5秒
  var _btn = null;
  var _cooldownUntil = 0;
  var _cooldownTimer = null;
  var _ackTimer = null;
  var _lastAlertId = null;
  var _audioCtx = null;

  var _reasons = [
    { key: 'cs_reason_order', ja: '注文したい' },
    { key: 'cs_reason_check', ja: 'お会計をお願いします' },
    { key: 'cs_reason_clear', ja: '食器を下げてください' },
    { key: 'cs_reason_other', ja: 'その他' }
  ];

  // ── URLパラメータ取得 ──
  function _getParams() {
    var search = window.location.search;
    var params = {};
    search.replace(/[?&]+([^=&]+)=([^&]*)/gi, function (m, key, val) {
      params[key] = decodeURIComponent(val);
    });
    return params;
  }

  // ── AudioContext アンロック ──
  function _unlockAudio() {
    if (_audioCtx) return;
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      _audioCtx = new Ctx();
      // サイレント音を再生してアンロック
      var buf = _audioCtx.createBuffer(1, 1, 22050);
      var src = _audioCtx.createBufferSource();
      src.buffer = buf;
      src.connect(_audioCtx.destination);
      src.start(0);
    } catch (e) { /* AudioContext非対応 */ }
  }

  // ── 初期化 ──
  function init() {
    var params = _getParams();
    _storeId = params.store_id || params.store || null;
    _tableId = params.table_id || params.table || null;

    // テーブルIDがない場合（プレビュー）はボタンを表示しない
    if (!_storeId || !_tableId) return;

    _injectStyles();
    _createButton();
    _createModal();

    // 初回タッチ/クリックでAudioContextをアンロック
    document.addEventListener('touchstart', _unlockAudio, { once: true });
    document.addEventListener('click', _unlockAudio, { once: true });
  }

  // ── スタイル注入 ──
  function _injectStyles() {
    var style = document.createElement('style');
    style.textContent = ''
      + '.cs-btn {'
      + '  background: #ff6f00; color: #fff; border: none;'
      + '  padding: 6px 12px; border-radius: 20px;'
      + '  font-size: 0.8rem; font-weight: 700; cursor: pointer;'
      + '  white-space: nowrap; transition: opacity 0.2s;'
      + '}'
      + '.cs-btn:hover { opacity: 0.85; }'
      + '.cs-btn:disabled { opacity: 0.4; cursor: not-allowed; }'

      + '.cs-overlay {'
      + '  position: fixed; top: 0; left: 0; right: 0; bottom: 0;'
      + '  background: rgba(0,0,0,0.5); z-index: 10000;'
      + '  display: none; align-items: center; justify-content: center;'
      + '}'
      + '.cs-overlay.cs-open { display: flex; }'

      + '.cs-modal {'
      + '  background: #fff; border-radius: 12px; width: 90%; max-width: 340px;'
      + '  overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,0.3);'
      + '}'
      + '.cs-modal__header {'
      + '  padding: 16px 20px; font-size: 1rem; font-weight: 700;'
      + '  border-bottom: 1px solid #eee; text-align: center;'
      + '}'
      + '.cs-modal__body { padding: 8px 0; }'
      + '.cs-modal__reason {'
      + '  display: block; width: 100%; padding: 14px 20px;'
      + '  border: none; background: #fff; font-size: 0.95rem;'
      + '  text-align: left; cursor: pointer; transition: background 0.15s;'
      + '  border-bottom: 1px solid #f5f5f5;'
      + '}'
      + '.cs-modal__reason:hover { background: #fff3e0; }'
      + '.cs-modal__reason:active { background: #ffe0b2; }'
      + '.cs-modal__cancel {'
      + '  display: block; width: 100%; padding: 14px 20px;'
      + '  border: none; background: #f5f5f5; font-size: 0.9rem;'
      + '  color: #999; text-align: center; cursor: pointer;'
      + '}'
      + '.cs-modal__cancel:hover { background: #eee; }'

      + '.cs-toast {'
      + '  position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%);'
      + '  background: #333; color: #fff; padding: 10px 24px;'
      + '  border-radius: 24px; font-size: 0.9rem; z-index: 10001;'
      + '  opacity: 0; transition: opacity 0.3s; pointer-events: none;'
      + '}'
      + '.cs-toast.cs-show { opacity: 1; }';
    document.head.appendChild(style);
  }

  // ── ボタン作成 ──
  function _createButton() {
    _btn = document.createElement('button');
    _btn.className = 'cs-btn';
    _btn.innerHTML = '&#x1F514; ' + _t('call_staff', '呼び出し');

    var header = document.querySelector('.customer-header');
    if (!header) return;

    // ENボタンの前に挿入
    var langBtn = document.getElementById('btn-lang');
    if (langBtn) {
      langBtn.parentNode.insertBefore(_btn, langBtn);
    } else {
      header.appendChild(_btn);
    }

    _btn.addEventListener('click', function () {
      if (_isCooldown()) return;
      _openModal();
    });
  }

  // ── モーダル作成 ──
  function _createModal() {
    var overlay = document.createElement('div');
    overlay.className = 'cs-overlay';
    overlay.id = 'cs-overlay';

    var html = '<div class="cs-modal">'
      + '<div class="cs-modal__header">' + _t('cs_confirm', 'スタッフを呼びますか？') + '</div>'
      + '<div class="cs-modal__body">';

    for (var i = 0; i < _reasons.length; i++) {
      var reasonText = _t(_reasons[i].key, _reasons[i].ja);
      html += '<button class="cs-modal__reason" data-reason="'
        + _escapeAttr(_reasons[i].ja) + '">'
        + _escapeHtml(reasonText) + '</button>';
    }

    html += '</div>'
      + '<button class="cs-modal__cancel" id="cs-cancel">' + _t('cs_cancel', 'キャンセル') + '</button>'
      + '</div>';

    overlay.innerHTML = html;
    document.body.appendChild(overlay);

    // イベント
    overlay.addEventListener('click', function (e) {
      var reasonBtn = e.target.closest('.cs-modal__reason');
      if (reasonBtn) {
        _sendCall(reasonBtn.dataset.reason);
        _closeModal();
        return;
      }
      if (e.target.id === 'cs-cancel' || e.target === overlay) {
        _closeModal();
      }
    });
  }

  // ── モーダル開閉 ──
  function _openModal() {
    var overlay = document.getElementById('cs-overlay');
    if (overlay) overlay.classList.add('cs-open');
  }

  function _closeModal() {
    var overlay = document.getElementById('cs-overlay');
    if (overlay) overlay.classList.remove('cs-open');
  }

  // ── API呼び出し ──
  function _sendCall(reason) {
    var tableCode = '';
    var tableEl = document.getElementById('table-name');
    if (tableEl) tableCode = tableEl.textContent || '';

    var payload = {
      store_id: _storeId,
      table_id: _tableId,
      table_code: tableCode,
      reason: reason
    };

    fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('空のレスポンス');
        try { return JSON.parse(body); } catch (e) { throw new Error('応答解析エラー'); }
      });
    })
    .then(function (json) {
      if (!json.ok) {
        throw new Error((json.error && json.error.message) || 'エラー');
      }
      _lastAlertId = (json.data && json.data.alert_id) || null;
      _playFeedbackChime();
      _showToast(_t('cs_called', 'スタッフを呼びました 🔔'));
      _startCooldown();
      _startAckPolling();
    })
    .catch(function (err) {
      _showToast(_t('cs_send_failed', '送信に失敗しました') + ': ' + err.message);
    });
  }

  // ── クールダウン ──
  function _isCooldown() {
    return Date.now() < _cooldownUntil;
  }

  function _startCooldown() {
    _cooldownUntil = Date.now() + COOLDOWN_MS;
    if (_btn) _btn.disabled = true;
    _updateCooldownLabel();

    if (_cooldownTimer) clearInterval(_cooldownTimer);
    _cooldownTimer = setInterval(function () {
      if (!_isCooldown()) {
        clearInterval(_cooldownTimer);
        _cooldownTimer = null;
        if (_btn) {
          _btn.disabled = false;
          _btn.innerHTML = '&#x1F514; ' + _t('call_staff', '呼び出し');
        }
        return;
      }
      _updateCooldownLabel();
    }, 1000);
  }

  function _updateCooldownLabel() {
    if (!_btn) return;
    var remain = Math.ceil((_cooldownUntil - Date.now()) / 1000);
    if (remain <= 0) return;
    var min = Math.floor(remain / 60);
    var sec = remain % 60;
    _btn.innerHTML = '&#x1F514; ' + min + ':' + (sec < 10 ? '0' : '') + sec;
  }

  // ── 対応済みポーリング ──
  function _startAckPolling() {
    _stopAckPolling();
    if (!_lastAlertId) return;
    _ackTimer = setInterval(function () {
      _checkAck();
    }, ACK_POLL_INTERVAL);
  }

  function _stopAckPolling() {
    if (_ackTimer) {
      clearInterval(_ackTimer);
      _ackTimer = null;
    }
  }

  function _checkAck() {
    if (!_lastAlertId) { _stopAckPolling(); return; }

    var url = API_URL + '?alert_id=' + encodeURIComponent(_lastAlertId);
    fetch(url)
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (!json.ok) return;
        var status = json.data && json.data.status;
        if (status === 'acknowledged' || status === 'not_found') {
          _stopAckPolling();
          _cancelCooldown();
          if (status === 'acknowledged') {
            _playFeedbackChime();
            _showToast(_t('cs_acknowledged', 'スタッフが対応しました ✓'));
          }
        }
      })
      .catch(function () { /* サイレント失敗 */ });
  }

  function _cancelCooldown() {
    _cooldownUntil = 0;
    _lastAlertId = null;
    if (_cooldownTimer) {
      clearInterval(_cooldownTimer);
      _cooldownTimer = null;
    }
    if (_btn) {
      _btn.disabled = false;
      _btn.innerHTML = '&#x1F514; ' + _t('call_staff', '呼び出し');
    }
  }

  // ── フィードバックチャイム ──
  function _playFeedbackChime() {
    if (!_audioCtx) return;
    try {
      if (_audioCtx.state === 'suspended') _audioCtx.resume();
      var now = _audioCtx.currentTime;
      var gain = _audioCtx.createGain();
      gain.connect(_audioCtx.destination);
      gain.gain.setValueAtTime(0.3, now);
      gain.gain.exponentialRampToValueAtTime(0.01, now + 0.4);

      // 440Hz → 523Hz の2音チャイム
      var osc1 = _audioCtx.createOscillator();
      osc1.type = 'sine';
      osc1.frequency.setValueAtTime(440, now);
      osc1.connect(gain);
      osc1.start(now);
      osc1.stop(now + 0.2);

      var gain2 = _audioCtx.createGain();
      gain2.connect(_audioCtx.destination);
      gain2.gain.setValueAtTime(0.3, now + 0.2);
      gain2.gain.exponentialRampToValueAtTime(0.01, now + 0.6);

      var osc2 = _audioCtx.createOscillator();
      osc2.type = 'sine';
      osc2.frequency.setValueAtTime(523, now + 0.2);
      osc2.connect(gain2);
      osc2.start(now + 0.2);
      osc2.stop(now + 0.6);
    } catch (e) { /* 音声再生失敗は無視 */ }
  }

  // ── トースト ──
  function _showToast(msg) {
    var existing = document.getElementById('cs-toast');
    if (existing) existing.parentNode.removeChild(existing);

    var el = document.createElement('div');
    el.id = 'cs-toast';
    el.className = 'cs-toast';
    el.textContent = msg;
    document.body.appendChild(el);

    setTimeout(function () { el.classList.add('cs-show'); }, 50);
    setTimeout(function () {
      el.classList.remove('cs-show');
      setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 300);
    }, 3000);
  }

  // ── エスケープ ──
  function _escapeHtml(str) {
    if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(str);
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
  }

  function _escapeAttr(str) {
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // ── DOM Ready ──
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return {};
})();
