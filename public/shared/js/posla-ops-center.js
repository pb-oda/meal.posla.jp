/**
 * POSLA 運用補助センター (OPS-CENTER-P1-READONLY-20260423、非AI、read-only)
 *
 * POSLA管理画面の `運用補助` タブ内に mount。
 * 平常時 / 軽障害時の一次切り分け用。本格的な原因調査・修正は外部運用支援に回す方針。
 *
 * 使い方: dashboard.html で
 *   <script src="../shared/js/posla-ops-center.js?v=20260423"></script>
 * を読み込み、posla-app.js の activateTab('ops') で
 *   PoslaOpsCenter.mount(document.getElementById('posla-ops-root'));
 * を呼ぶ。
 *
 * ES5 IIFE / const・let・arrow・async・await 不使用。
 * 書き込み操作ゼロ、AI/Gemini 呼び出しゼロ。
 */
var PoslaOpsCenter = (function () {
  'use strict';

  var DATA_URL = '/shared/data/internal-ops-center.json';
  var PING_URL = '/api/monitor/ping.php';
  var BRAND = 'var(--primary)';

  var _mounted = false;
  var _loaded = false;
  var _data = null;
  var _rootEl = null;
  var _bodyEl = null;
  var _currentTab = 'status';
  var _pendingErrorQuery = null;

  function _escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function _normalizeUrl(url) {
    url = String(url || '');
    if (!url) return '#';
    if (url.indexOf('/public/') === 0) return url.substring(7);
    return url;
  }

  function _injectStyle() {
    if (document.getElementById('posla-ops-center-style')) return;
    var css =
      '.posla-opc{display:flex;flex-direction:column;background:#fff;border:1px solid #dde3f5;border-radius:8px;overflow:hidden;}'
      + '.posla-opc__tabs{display:flex;border-bottom:1px solid #dde3f5;background:#f8faff;}'
      + '.posla-opc__tab{flex:1;padding:0.7rem 0.4rem;font-size:0.85rem;background:transparent;border:none;border-bottom:2px solid transparent;cursor:pointer;color:var(--text-secondary);font-family:inherit;}'
      + '.posla-opc__tab.active{color:' + BRAND + ';border-bottom-color:' + BRAND + ';font-weight:bold;background:#fff;}'
      + '.posla-opc__tab:hover{background:#eef2ff;}'
      + '.posla-opc__body{padding:1rem 1.2rem;min-height:320px;max-height:720px;overflow-y:auto;font-size:0.9rem;line-height:1.55;}'
      + '.posla-opc__body h4{margin:0.9rem 0 0.4rem;font-size:0.92rem;color:#222;}'
      + '.posla-opc__body h4:first-child{margin-top:0;}'
      + '.posla-opc__notice{background:#f8faff;border-left:3px solid var(--primary);padding:0.6rem 0.85rem;margin-bottom:0.85rem;color:var(--text-secondary);font-size:0.82rem;line-height:1.5;}'
      + '.posla-opc__search{width:100%;padding:0.6rem 0.75rem;border:1px solid #dfe4f5;border-radius:6px;font-size:0.9rem;font-family:inherit;box-sizing:border-box;margin-bottom:0.75rem;}'
      + '.posla-opc__search:focus{outline:none;border-color:' + BRAND + ';}'
      + '.posla-opc__health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.7rem;margin-bottom:0.9rem;}'
      + '.posla-opc__health{background:#fff;border:1px solid #e3e8f7;border-left:4px solid #9e9e9e;border-radius:6px;padding:0.65rem 0.85rem;}'
      + '.posla-opc__health.ok{border-left-color:#2e7d32;}'
      + '.posla-opc__health.warn{border-left-color:#ef6c00;}'
      + '.posla-opc__health.ng{border-left-color:#c62828;}'
      + '.posla-opc__health-label{font-size:0.78rem;color:#666;}'
      + '.posla-opc__health-value{font-size:1.2rem;font-weight:bold;color:#222;margin-top:0.2rem;}'
      + '.posla-opc__health-note{color:#666;font-size:0.78rem;margin-top:0.3rem;line-height:1.4;}'
      + '.posla-opc__reload{margin-bottom:0.9rem;}'
      + '.posla-opc__reload button{background:' + BRAND + ';color:#fff;border:none;padding:0.45rem 0.95rem;border-radius:6px;cursor:pointer;font-size:0.85rem;font-family:inherit;}'
      + '.posla-opc__reload button:hover{filter:brightness(1.05);}'
      + '.posla-opc__reload button:disabled{background:#ccc;cursor:not-allowed;}'
      + '.posla-opc__card{background:#fff;border:1px solid #e3e8f7;border-radius:6px;padding:0.7rem 0.9rem;margin:0.5rem 0;cursor:pointer;}'
      + '.posla-opc__card:hover{border-color:' + BRAND + ';}'
      + '.posla-opc__card-title{font-weight:bold;color:#222;font-size:0.9rem;}'
      + '.posla-opc__card-body{margin-top:0.5rem;color:#333;font-size:0.85rem;display:none;}'
      + '.posla-opc__card.open .posla-opc__card-body{display:block;}'
      + '.posla-opc__step-list{margin:0.5rem 0;padding-left:1.3rem;color:#333;}'
      + '.posla-opc__step-list li{margin:0.3rem 0;}'
      + '.posla-opc__card-link{display:inline-block;margin-top:0.4rem;color:' + BRAND + ';font-size:0.8rem;text-decoration:none;}'
      + '.posla-opc__card-link:hover{text-decoration:underline;}'
      + '.posla-opc__err-card{background:#fff;border-left:3px solid ' + BRAND + ';border-radius:4px;padding:0.6rem 0.85rem;margin:0.45rem 0;}'
      + '.posla-opc__err-code{font-weight:bold;color:' + BRAND + ';font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}'
      + '.posla-opc__err-title{font-weight:bold;margin-left:0.5rem;color:#333;}'
      + '.posla-opc__err-msg{color:#666;font-size:0.82rem;margin:0.25rem 0;white-space:pre-wrap;}'
      + '.posla-opc__err-action{color:#333;font-size:0.85rem;white-space:pre-wrap;}'
      + '.posla-opc__doc-card{display:flex;gap:0.75rem;background:#fff;border:1px solid #e3e8f7;border-radius:6px;padding:0.75rem 0.9rem;margin:0.45rem 0;text-decoration:none;color:inherit;}'
      + '.posla-opc__doc-card:hover{border-color:' + BRAND + ';}'
      + '.posla-opc__doc-main{flex:1;min-width:0;}'
      + '.posla-opc__doc-title{font-weight:bold;color:#222;font-size:0.9rem;}'
      + '.posla-opc__doc-summary{color:#666;font-size:0.82rem;margin-top:0.3rem;line-height:1.45;}'
      + '.posla-opc__doc-arrow{color:' + BRAND + ';font-weight:bold;align-self:center;}'
      + '.posla-opc__checklist{background:#fff;border:1px solid #e3e8f7;border-radius:6px;padding:0.9rem 1.1rem;margin:0.7rem 0;}'
      + '.posla-opc__checklist-title{font-weight:bold;color:#222;font-size:0.95rem;margin-bottom:0.55rem;}'
      + '.posla-opc__checklist-list{list-style:none;padding-left:0;margin:0;}'
      + '.posla-opc__checklist-list li{padding:0.3rem 0;font-size:0.88rem;color:#333;display:flex;align-items:flex-start;gap:0.55rem;}'
      + '.posla-opc__checklist-list input[type=checkbox]{margin-top:0.15rem;flex-shrink:0;}'
      + '.posla-opc__empty{color:#999;text-align:center;padding:1.5rem 0;font-size:0.9rem;}';
    var s = document.createElement('style');
    s.id = 'posla-ops-center-style';
    s.textContent = css;
    document.head.appendChild(s);
  }

  function _buildShell() {
    _rootEl.innerHTML = ''
      + '<div class="posla-opc">'
      +   '<div class="posla-opc__tabs">'
      +     '<button class="posla-opc__tab active" type="button" data-opc-tab="status">運用状況</button>'
      +     '<button class="posla-opc__tab" type="button" data-opc-tab="error">エラー検索</button>'
      +     '<button class="posla-opc__tab" type="button" data-opc-tab="flow">症状からたどる</button>'
      +     '<button class="posla-opc__tab" type="button" data-opc-tab="docs">運用ドキュメント</button>'
      +     '<button class="posla-opc__tab" type="button" data-opc-tab="checklist">対応前チェック</button>'
      +   '</div>'
      +   '<div class="posla-opc__body" id="posla-opc-body"></div>'
      + '</div>';

    _bodyEl = _rootEl.querySelector('#posla-opc-body');

    var tabs = _rootEl.querySelectorAll('[data-opc-tab]');
    var i;
    for (i = 0; i < tabs.length; i++) {
      tabs[i].addEventListener('click', _onTabClick);
    }
  }

  function _onTabClick(e) {
    _switchTab(e.currentTarget.getAttribute('data-opc-tab'));
  }

  function _switchTab(tabId) {
    _currentTab = tabId;
    var tabs = _rootEl.querySelectorAll('[data-opc-tab]');
    var i;
    for (i = 0; i < tabs.length; i++) {
      if (tabs[i].getAttribute('data-opc-tab') === tabId) {
        tabs[i].className = 'posla-opc__tab active';
      } else {
        tabs[i].className = 'posla-opc__tab';
      }
    }
    _render();
  }

  function _loadData() {
    _bodyEl.innerHTML = '<div class="posla-opc__empty">読み込み中...</div>';
    fetch(DATA_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        try { _data = JSON.parse(text); } catch (e) {
          _bodyEl.innerHTML = '<div class="posla-opc__empty">運用補助データの読み込みに失敗しました。</div>';
          return;
        }
        _loaded = true;
        _render();
      })
      .catch(function () {
        _bodyEl.innerHTML = '<div class="posla-opc__empty">運用補助データに接続できません。</div>';
      });
  }

  function _render() {
    if (!_data) return;
    if (_currentTab === 'status') _renderStatus();
    else if (_currentTab === 'error') _renderError();
    else if (_currentTab === 'flow') _renderFlow();
    else if (_currentTab === 'docs') _renderDocs();
    else if (_currentTab === 'checklist') _renderChecklist();
  }

  // ── 運用状況 (ping + health cards) ──
  function _renderStatus() {
    var html = ''
      + '<div class="posla-opc__notice">この画面は <strong>read-only</strong> です。書き込み操作・本番修正は含みません。障害時の本命調査ツールは別系統 (外部運用支援)、ここは平常時 / 軽障害時の一次確認向けです。</div>'
      + '<h4>稼働状態 (/api/monitor/ping.php)</h4>'
      + '<div class="posla-opc__reload"><button id="posla-opc-reload" type="button">最新情報を取得</button></div>'
      + '<div id="posla-opc-health-grid" class="posla-opc__health-grid"><div class="posla-opc__empty">クリックして読み込み</div></div>'
      + '<h4>health 閾値の目安</h4>';
    var i;
    for (i = 0; i < _data.health_cards.length; i++) {
      var c = _data.health_cards[i];
      html += '<div class="posla-opc__card open">'
        + '<div class="posla-opc__card-title">' + _escapeHtml(c.title) + '</div>'
        + '<div class="posla-opc__card-body">' + _escapeHtml(c.description);
      if (c.source) html += '<br><small style="color:#999;">source: <code>' + _escapeHtml(c.source) + '</code></small>';
      html += '</div></div>';
    }
    _bodyEl.innerHTML = html;

    _rootEl.querySelector('#posla-opc-reload').addEventListener('click', _fetchPing);
    _fetchPing();
  }

  function _fetchPing() {
    var gridEl = _rootEl.querySelector('#posla-opc-health-grid');
    var btn = _rootEl.querySelector('#posla-opc-reload');
    if (!gridEl) return;
    if (btn) btn.disabled = true;
    gridEl.innerHTML = '<div class="posla-opc__empty">取得中...</div>';
    fetch(PING_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var json;
        try { json = JSON.parse(t); } catch (e) {
          gridEl.innerHTML = '<div class="posla-opc__empty">ping 応答の解析に失敗しました</div>';
          return;
        }
        gridEl.innerHTML = _renderPingCards(json);
      })
      .catch(function (err) {
        gridEl.innerHTML = '<div class="posla-opc__empty">ping に接続できません (ネットワーク / 外部 firewall 確認): ' + _escapeHtml((err && err.message) || '') + '</div>';
      })
      .then(function () {
        if (btn) btn.disabled = false;
      });
  }

  function _renderPingCards(p) {
    var okClass = p.ok ? 'ok' : 'ng';
    var dbClass = p.db === 'ok' ? 'ok' : 'ng';
    var cronClass = 'ok';
    var cronNote = '';
    if (p.cron_lag_sec == null) {
      cronClass = 'warn'; cronNote = 'heartbeat 未記録';
    } else if (p.cron_lag_sec > 900) {
      cronClass = 'ng'; cronNote = '15 分以上遅延 → 要調査';
    } else if (p.cron_lag_sec > 600) {
      cronClass = 'warn'; cronNote = 'やや遅延 (600 秒超)';
    } else {
      cronNote = '正常範囲 (< 10 分)';
    }
    var timeNote = p.time ? ('サーバ時刻: ' + _escapeHtml(p.time)) : '';
    var heartbeat = p.last_heartbeat ? _escapeHtml(p.last_heartbeat) : '(未記録)';

    var html = ''
      + '<div class="posla-opc__health ' + okClass + '">'
      +   '<div class="posla-opc__health-label">overall ok</div>'
      +   '<div class="posla-opc__health-value">' + (p.ok ? '✓ TRUE' : '✗ FALSE') + '</div>'
      +   '<div class="posla-opc__health-note">' + timeNote + '</div>'
      + '</div>'
      + '<div class="posla-opc__health ' + dbClass + '">'
      +   '<div class="posla-opc__health-label">DB 接続</div>'
      +   '<div class="posla-opc__health-value">' + _escapeHtml(String(p.db || 'unknown')) + '</div>'
      +   '<div class="posla-opc__health-note">ng の場合 credentials rotation / env 未供給を疑う</div>'
      + '</div>'
      + '<div class="posla-opc__health ' + cronClass + '">'
      +   '<div class="posla-opc__health-label">cron lag (秒)</div>'
      +   '<div class="posla-opc__health-value">' + (p.cron_lag_sec == null ? '—' : _escapeHtml(String(p.cron_lag_sec))) + '</div>'
      +   '<div class="posla-opc__health-note">' + cronNote + '<br>last heartbeat: ' + heartbeat + '</div>'
      + '</div>';
    if (p.error) {
      html += '<div class="posla-opc__health ng">'
        +   '<div class="posla-opc__health-label">エラー詳細</div>'
        +   '<div class="posla-opc__health-value" style="font-size:0.85rem;">' + _escapeHtml(p.error) + '</div>'
        + '</div>';
    }
    return html;
  }

  // ── エラー検索 ──
  function _renderError() {
    var q = _pendingErrorQuery || '';
    _pendingErrorQuery = null;
    var html = ''
      + '<div class="posla-opc__notice">🔍 コード文字列またはタイトルの部分一致で検索します (例: <code>SESSION_REVOKED</code> / <code>RATE</code>)。収録は代表 ' + _data.error_codes.length + ' 件。全件は <a href="/docs-tenant/tenant/99-error-catalog.html" target="_blank" rel="noopener">tenant/99-error-catalog</a> を参照。</div>'
      + '<input type="text" class="posla-opc__search" id="posla-opc-err-input" placeholder="SESSION_REVOKED / RATE_LIMITED / AI_NOT_CONFIGURED 等" value="' + _escapeHtml(q) + '" autocomplete="off">'
      + '<div id="posla-opc-err-result"></div>';
    _bodyEl.innerHTML = html;
    var input = _rootEl.querySelector('#posla-opc-err-input');
    input.addEventListener('input', _onErrInput);
    _renderErrResult(q);
    setTimeout(function () { input.focus(); }, 50);
  }

  function _onErrInput(e) {
    _renderErrResult(e.currentTarget.value || '');
  }

  function _renderErrResult(query) {
    var resultEl = _rootEl.querySelector('#posla-opc-err-result');
    if (!resultEl) return;
    var q = (query || '').trim().toUpperCase();
    var list = _data.error_codes || [];
    if (q.length === 0) {
      resultEl.innerHTML = _renderErrorCards(list);
      return;
    }
    var hits = [];
    var i;
    for (i = 0; i < list.length; i++) {
      if (list[i].code.toUpperCase().indexOf(q) >= 0
          || String(list[i].title).toUpperCase().indexOf(q) >= 0
          || String(list[i].action || '').toUpperCase().indexOf(q) >= 0) {
        hits.push(list[i]);
      }
    }
    if (hits.length === 0) {
      resultEl.innerHTML = '<div class="posla-opc__empty">「' + _escapeHtml(q) + '」に該当するコードがありません。</div>';
      return;
    }
    resultEl.innerHTML = _renderErrorCards(hits);
  }

  function _renderErrorCards(hits) {
    var html = '';
    var i;
    for (i = 0; i < hits.length; i++) {
      var e = hits[i];
      html += '<div class="posla-opc__err-card">'
        + '<span class="posla-opc__err-code">' + _escapeHtml(e.code) + '</span>'
        + '<span class="posla-opc__err-title">' + _escapeHtml(e.title) + '</span>'
        + '<div class="posla-opc__err-msg">' + _escapeHtml(e.message) + '</div>'
        + '<div class="posla-opc__err-action">→ ' + _escapeHtml(e.action) + '</div>';
      if (e.doc_link) {
        html += '<a class="posla-opc__card-link" href="' + _escapeHtml(_normalizeUrl(e.doc_link)) + '" target="_blank" rel="noopener">関連 docs →</a>';
      }
      html += '</div>';
    }
    return html;
  }

  // ── 症状からたどる ──
  function _renderFlow() {
    var html = '<div class="posla-opc__notice">📋 症状ごとに対処手順をまとめた checklist。クリックで展開。深掘り調査は link 先の docs / 外部運用支援へ。</div>';
    var flows = _data.flows || [];
    var i, j;
    for (i = 0; i < flows.length; i++) {
      var f = flows[i];
      html += '<div class="posla-opc__card" data-card-id="' + _escapeHtml(f.id) + '">'
        + '<div class="posla-opc__card-title">' + _escapeHtml(f.title) + '</div>'
        + '<div class="posla-opc__card-body">'
        + '<ol class="posla-opc__step-list">';
      for (j = 0; j < f.steps.length; j++) {
        html += '<li>' + _escapeHtml(f.steps[j]) + '</li>';
      }
      html += '</ol>';
      if (f.doc_link) {
        html += '<a class="posla-opc__card-link" href="' + _escapeHtml(_normalizeUrl(f.doc_link)) + '" target="_blank" rel="noopener">詳細 docs を開く →</a>';
      }
      html += '</div></div>';
    }
    _bodyEl.innerHTML = html;
    _bindCardToggles();
  }

  function _bindCardToggles() {
    var cards = _bodyEl.querySelectorAll('.posla-opc__card');
    var i;
    for (i = 0; i < cards.length; i++) {
      cards[i].addEventListener('click', _onCardClick);
    }
  }

  function _onCardClick(e) {
    if (e.target && e.target.tagName === 'A') return;
    var card = e.currentTarget;
    if (card.className.indexOf('open') >= 0) {
      card.className = 'posla-opc__card';
    } else {
      card.className = 'posla-opc__card open';
    }
  }

  // ── 運用ドキュメント ──
  function _renderDocs() {
    var html = '<div class="posla-opc__notice">クリックで VitePress / 原本ドキュメントを新しいタブで開きます。</div>';
    var docs = _data.doc_links || [];
    var i;
    for (i = 0; i < docs.length; i++) {
      var d = docs[i];
      html += '<a class="posla-opc__doc-card" href="' + _escapeHtml(_normalizeUrl(d.url)) + '" target="_blank" rel="noopener">'
        + '<div class="posla-opc__doc-main">'
        +   '<div class="posla-opc__doc-title">' + _escapeHtml(d.title) + '</div>'
        +   '<div class="posla-opc__doc-summary">' + _escapeHtml(d.summary) + '</div>'
        + '</div>'
        + '<div class="posla-opc__doc-arrow">→</div>'
        + '</a>';
    }
    _bodyEl.innerHTML = html;
  }

  // ── 対応前チェックリスト ──
  function _renderChecklist() {
    var html = '<div class="posla-opc__notice">🧾 調査・対応を始める前に必要な情報を揃えるためのメモ。checkbox はブラウザ内のみで消えます (送信しません)。</div>';
    var cls = _data.checklist || [];
    var i, j;
    for (i = 0; i < cls.length; i++) {
      var c = cls[i];
      html += '<div class="posla-opc__checklist">'
        + '<div class="posla-opc__checklist-title">' + _escapeHtml(c.title) + '</div>'
        + '<ul class="posla-opc__checklist-list">';
      for (j = 0; j < c.items.length; j++) {
        var cbId = 'posla-opc-cb-' + _escapeHtml(c.id) + '-' + j;
        html += '<li><input type="checkbox" id="' + cbId + '"><label for="' + cbId + '">' + _escapeHtml(c.items[j]) + '</label></li>';
      }
      html += '</ul></div>';
    }
    _bodyEl.innerHTML = html;
  }

  return {
    mount: function (rootEl) {
      if (!rootEl) return;
      if (_mounted) return;
      _mounted = true;
      _rootEl = rootEl;
      _injectStyle();
      _buildShell();
      if (!_loaded) _loadData();
      else _render();
    }
  };
})();
