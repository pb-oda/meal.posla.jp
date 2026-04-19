/**
 * POSLA AI ヘルプデスク FAB (Floating Action Button)
 *
 * Phase B / CB1c: どの業務画面からでも AI に質問できるオーバーレイ。
 * 既存の /api/store/ai-generate.php?mode=helpdesk_tenant をそのまま叩く。
 *
 * 使い方 (HTML 末尾に 1 行追加):
 *   <script src="../shared/js/posla-helpdesk-fab.js?v=cb1c"></script>
 *
 * mode を指定したい場合 (POSLA 管理者用):
 *   <script>window.POSLA_HELPDESK_MODE = 'helpdesk_internal';</script>
 *   <script src="../shared/js/posla-helpdesk-fab.js?v=cb1c"></script>
 *
 * ES5 IIFE パターン。const/let/arrow 不使用。
 */
(function () {
  'use strict';

  // 既に設置済みなら何もしない (HTML が複数 include しても安全)
  if (window.__POSLA_HELPDESK_FAB__) return;
  window.__POSLA_HELPDESK_FAB__ = true;

  var BRAND_ORANGE = '#ff6f00';
  var MODE = window.POSLA_HELPDESK_MODE || 'helpdesk_tenant';
  var API_URL = (window.POSLA_AI_API_URL) || _resolveApiUrl();

  var _open = false;
  var _sending = false;
  var _history = [];   // [{ role: 'user'|'ai', text: '...' }]
  var _root = null;
  var _logEl = null;
  var _inputEl = null;
  var _sendBtn = null;

  function _resolveApiUrl() {
    // location.pathname ベースで API への相対パスを推定
    // /public/admin/dashboard.html → /api/store/ai-generate.php
    var path = location.pathname || '';
    if (path.indexOf('/public/') === 0) return '/api/store/ai-generate.php';
    return '../../api/store/ai-generate.php';
  }

  // ── スタイル一括注入 ──
  function _injectStyle() {
    if (document.getElementById('posla-helpdesk-style')) return;
    var css =
      '#posla-helpdesk-fab{position:fixed;right:20px;bottom:20px;width:60px;height:60px;border-radius:50%;'
      + 'background:' + BRAND_ORANGE + ';color:#fff;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.25);'
      + 'z-index:9998;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:bold;'
      + 'transition:transform 0.15s ease,background 0.15s;}'
      + '#posla-helpdesk-fab:hover{transform:scale(1.06);background:#ff8a1f;}'
      + '#posla-helpdesk-fab:active{transform:scale(0.95);}'
      + '#posla-helpdesk-panel{position:fixed;right:20px;bottom:90px;width:380px;max-width:calc(100vw - 24px);'
      + 'height:600px;max-height:calc(100vh - 110px);background:#fff;border-radius:12px;'
      + 'box-shadow:0 8px 32px rgba(0,0,0,0.25);display:none;flex-direction:column;z-index:9999;'
      + 'font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans",sans-serif;overflow:hidden;}'
      + '#posla-helpdesk-panel.open{display:flex;}'
      + '.posla-hd__header{background:' + BRAND_ORANGE + ';color:#fff;padding:0.75rem 1rem;'
      + 'display:flex;align-items:center;justify-content:space-between;}'
      + '.posla-hd__title{font-weight:bold;font-size:0.95rem;}'
      + '.posla-hd__close{background:transparent;border:none;color:#fff;font-size:1.5rem;'
      + 'cursor:pointer;padding:0;line-height:1;}'
      + '.posla-hd__log{flex:1;padding:0.75rem;overflow-y:auto;background:#fafafa;'
      + 'display:flex;flex-direction:column;gap:0.5rem;font-size:0.85rem;line-height:1.5;}'
      + '.posla-hd__msg{padding:0.5rem 0.75rem;border-radius:8px;max-width:85%;word-wrap:break-word;white-space:pre-wrap;}'
      + '.posla-hd__msg--ai{background:#fff;border:1px solid #e0e0e0;align-self:flex-start;}'
      + '.posla-hd__msg--user{background:' + BRAND_ORANGE + ';color:#fff;align-self:flex-end;}'
      + '.posla-hd__msg--system{background:#fff3e0;color:#666;font-size:0.78rem;align-self:stretch;'
      + 'border-radius:6px;border:1px solid #ffe0b2;}'
      + '.posla-hd__loader{align-self:flex-start;color:#999;font-style:italic;font-size:0.8rem;padding:0.25rem 0.75rem;}'
      + '.posla-hd__input-row{display:flex;gap:0.5rem;padding:0.5rem;border-top:1px solid #eee;background:#fff;}'
      + '.posla-hd__input{flex:1;padding:0.6rem;border:1px solid #ddd;border-radius:6px;font-size:0.9rem;font-family:inherit;resize:none;height:42px;}'
      + '.posla-hd__input:focus{outline:none;border-color:' + BRAND_ORANGE + ';}'
      + '.posla-hd__send{background:' + BRAND_ORANGE + ';color:#fff;border:none;padding:0 1rem;border-radius:6px;cursor:pointer;font-weight:bold;}'
      + '.posla-hd__send:disabled{background:#ccc;cursor:not-allowed;}'
      + '.posla-hd__suggest{display:flex;flex-wrap:wrap;gap:0.3rem;padding:0 0.75rem 0.5rem;}'
      + '.posla-hd__chip{background:#fff;border:1px solid ' + BRAND_ORANGE + ';color:' + BRAND_ORANGE + ';'
      + 'padding:0.25rem 0.6rem;border-radius:14px;font-size:0.75rem;cursor:pointer;}'
      + '.posla-hd__chip:hover{background:' + BRAND_ORANGE + ';color:#fff;}'
      + '@media (max-width:480px){#posla-helpdesk-panel{width:calc(100vw - 24px);height:calc(100vh - 110px);right:12px;bottom:80px;}}';
    var s = document.createElement('style');
    s.id = 'posla-helpdesk-style';
    s.textContent = css;
    document.head.appendChild(s);
  }

  // ── DOM 構築 ──
  function _buildDom() {
    var fab = document.createElement('button');
    fab.id = 'posla-helpdesk-fab';
    fab.type = 'button';
    fab.title = 'AI ヘルプデスクに質問';
    fab.setAttribute('aria-label', 'AI ヘルプデスクを開く');
    fab.innerHTML = '<span style="font-size:1.4rem;">?</span>';
    fab.addEventListener('click', _toggle);

    var panel = document.createElement('div');
    panel.id = 'posla-helpdesk-panel';
    panel.innerHTML =
      '<div class="posla-hd__header">'
      +   '<div class="posla-hd__title">🤖 POSLA AI ヘルプデスク</div>'
      +   '<button class="posla-hd__close" type="button" aria-label="閉じる">×</button>'
      + '</div>'
      + '<div class="posla-hd__log" id="posla-hd-log"></div>'
      + '<div class="posla-hd__suggest" id="posla-hd-suggest">'
      +   '<button class="posla-hd__chip" data-q="エラー番号 E3024 とは？">E3024 とは？</button>'
      +   '<button class="posla-hd__chip" data-q="レジで会計するときの操作手順は？">会計手順</button>'
      +   '<button class="posla-hd__chip" data-q="返金の手順を教えて">返金手順</button>'
      +   '<button class="posla-hd__chip" data-q="出勤打刻のやり方">出勤打刻</button>'
      + '</div>'
      + '<div class="posla-hd__input-row">'
      +   '<textarea class="posla-hd__input" id="posla-hd-input" placeholder="エラー番号や知りたい操作を入力 (Enter で送信、Shift+Enter で改行)"></textarea>'
      +   '<button class="posla-hd__send" id="posla-hd-send" type="button">送信</button>'
      + '</div>';

    document.body.appendChild(fab);
    document.body.appendChild(panel);

    _root    = panel;
    _logEl   = panel.querySelector('#posla-hd-log');
    _inputEl = panel.querySelector('#posla-hd-input');
    _sendBtn = panel.querySelector('#posla-hd-send');

    panel.querySelector('.posla-hd__close').addEventListener('click', _close);
    _sendBtn.addEventListener('click', _send);
    _inputEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        _send();
      }
    });

    // サジェストチップ
    panel.querySelectorAll('.posla-hd__chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        _inputEl.value = chip.getAttribute('data-q');
        _send();
      });
    });

    // 初期メッセージ
    _appendSystem('画面に「エラー E2017」のような番号が表示されたら、そのまま入力すると意味と対処方法をご案内します。');
  }

  function _toggle() {
    if (_open) _close(); else _show();
  }
  function _show() {
    _open = true;
    _root.classList.add('open');
    setTimeout(function () { if (_inputEl) _inputEl.focus(); }, 50);
  }
  function _close() {
    _open = false;
    _root.classList.remove('open');
  }

  // ── メッセージ表示 ──
  function _appendUser(text) {
    var el = document.createElement('div');
    el.className = 'posla-hd__msg posla-hd__msg--user';
    el.textContent = text;
    _logEl.appendChild(el);
    _scrollToBottom();
  }
  function _appendAi(text) {
    var el = document.createElement('div');
    el.className = 'posla-hd__msg posla-hd__msg--ai';
    el.textContent = text;
    _logEl.appendChild(el);
    _scrollToBottom();
  }
  function _appendSystem(text) {
    var el = document.createElement('div');
    el.className = 'posla-hd__msg posla-hd__msg--system';
    el.textContent = text;
    _logEl.appendChild(el);
    _scrollToBottom();
  }
  function _appendLoader() {
    var el = document.createElement('div');
    el.className = 'posla-hd__loader';
    el.id = 'posla-hd-loader';
    el.textContent = 'AI が回答を準備中…';
    _logEl.appendChild(el);
    _scrollToBottom();
    return el;
  }
  function _scrollToBottom() {
    setTimeout(function () { _logEl.scrollTop = _logEl.scrollHeight; }, 0);
  }

  // ── 送信処理 ──
  function _send() {
    if (_sending) return;
    var text = (_inputEl.value || '').trim();
    if (!text) return;

    _appendUser(text);
    _history.push({ role: 'user', text: text });
    _inputEl.value = '';

    // サジェストを 1 回目以降は薄く
    var suggest = _root.querySelector('#posla-hd-suggest');
    if (suggest) suggest.style.display = 'none';

    _sending = true;
    _sendBtn.disabled = true;
    var loader = _appendLoader();

    // 過去 4 ターンを文脈として含める
    var context = '';
    var recent = _history.slice(-8);
    for (var i = 0; i < recent.length - 1; i++) {
      context += (recent[i].role === 'user' ? '【ユーザー】' : '【AI】') + recent[i].text + '\n';
    }
    var prompt = context ? (context + '【ユーザー】' + text) : text;

    var done = function () {
      _sending = false;
      _sendBtn.disabled = false;
      var l = _root.querySelector('#posla-hd-loader');
      if (l && l.parentNode) l.parentNode.removeChild(l);
    };

    fetch(API_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mode: MODE, prompt: prompt })
    })
    .then(function (r) { return r.text().then(function (t) {
      try { return JSON.parse(t); } catch (e) { return { ok: false, error: { message: t.substring(0, 200) } }; }
    }); })
    .then(function (json) {
      done();
      if (!json.ok) {
        var msg = (json.error && (json.error.message || json.error.code)) || '不明なエラー';
        _appendSystem('AI 呼び出しに失敗しました: ' + msg);
      } else {
        var reply = (json.data && (json.data.text || json.data.message)) || '';
        _appendAi(reply || '(空の回答)');
        _history.push({ role: 'ai', text: reply });
      }
    })
    .catch(function (err) {
      done();
      _appendSystem('通信エラーが発生しました: ' + (err.message || ''));
    });
  }

  // ── 初期化 ──
  function _init() {
    _injectStyle();
    _buildDom();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _init);
  } else {
    _init();
  }
})();
