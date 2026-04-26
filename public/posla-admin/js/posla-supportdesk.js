/**
 * POSLA管理画面 サポートセンター (非AI、panel-mount 版)
 *
 * HELPDESK-P2-NONAI-ALL-20260423 で AIコード案内タブを置換。
 * 静的 JSON (internal-supportdesk.json) を読み込んで
 * FAQ / キーワード検索 / 症状フロー / エラーコード直引き を提供する。
 *
 * 使い方: dashboard.html で
 *   <script src="js/posla-supportdesk.js?v=20260423"></script>
 * を読み込み、posla-app.js の activateTab('support') で
 *   PoslaSupportdesk.mount(document.getElementById('posla-support-root'));
 * を呼ぶ。
 *
 * ES5 IIFE / const・let・arrow・async・await 不使用。
 */
var PoslaSupportdesk = (function () {
  'use strict';

  var DATA_URL = '/shared/data/internal-supportdesk.json';
  var BRAND_ORANGE = 'var(--primary)';

  var _mounted = false;
  var _loaded = false;
  var _data = null;
  var _rootEl = null;
  var _bodyEl = null;
  var _currentTab = 'home';
  var _pendingCategoryFilter = null;
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
    if (document.getElementById('posla-supportdesk-admin-style')) return;
    var css =
      '.posla-sdp{display:flex;flex-direction:column;background:#fff;border:1px solid #dde3f5;border-radius:8px;overflow:hidden;}'
      + '.posla-sdp__tabs{display:flex;border-bottom:1px solid #dde3f5;background:#f8faff;}'
      + '.posla-sdp__tab{flex:1;padding:0.7rem 0.4rem;font-size:0.85rem;background:transparent;border:none;border-bottom:2px solid transparent;cursor:pointer;color:var(--text-secondary);font-family:inherit;}'
      + '.posla-sdp__tab.active{color:' + BRAND_ORANGE + ';border-bottom-color:' + BRAND_ORANGE + ';font-weight:bold;background:#fff;}'
      + '.posla-sdp__tab:hover{background:#eef2ff;}'
      + '.posla-sdp__body{padding:1rem 1.2rem;min-height:320px;max-height:640px;overflow-y:auto;font-size:0.9rem;line-height:1.55;}'
      + '.posla-sdp__body h4{margin:0.8rem 0 0.4rem;font-size:0.92rem;color:#222;}'
      + '.posla-sdp__body h4:first-child{margin-top:0;}'
      + '.posla-sdp__search{width:100%;padding:0.6rem 0.75rem;border:1px solid #dfe4f5;border-radius:6px;font-size:0.9rem;font-family:inherit;box-sizing:border-box;margin-bottom:0.75rem;}'
      + '.posla-sdp__search:focus{outline:none;border-color:' + BRAND_ORANGE + ';}'
      + '.posla-sdp__chip-row{display:flex;flex-wrap:wrap;gap:0.35rem;margin:0.5rem 0 0.8rem;}'
      + '.posla-sdp__chip{background:#fff;border:1px solid #dfe4f5;color:var(--text-secondary);padding:0.3rem 0.7rem;border-radius:14px;font-size:0.8rem;cursor:pointer;font-family:inherit;}'
      + '.posla-sdp__chip:hover{background:' + BRAND_ORANGE + ';color:#fff;border-color:' + BRAND_ORANGE + ';}'
      + '.posla-sdp__chip.active{background:' + BRAND_ORANGE + ';color:#fff;border-color:' + BRAND_ORANGE + ';}'
      + '.posla-sdp__card{background:#fff;border:1px solid #e3e8f7;border-radius:6px;padding:0.7rem 0.9rem;margin:0.5rem 0;cursor:pointer;}'
      + '.posla-sdp__card:hover{border-color:' + BRAND_ORANGE + ';}'
      + '.posla-sdp__card-title{font-weight:bold;color:#222;margin-bottom:0.2rem;font-size:0.9rem;}'
      + '.posla-sdp__card-body{margin-top:0.5rem;color:#333;font-size:0.85rem;white-space:pre-wrap;display:none;}'
      + '.posla-sdp__card.open .posla-sdp__card-body{display:block;}'
      + '.posla-sdp__card-link{display:inline-block;margin-top:0.4rem;color:' + BRAND_ORANGE + ';font-size:0.8rem;text-decoration:none;}'
      + '.posla-sdp__card-link:hover{text-decoration:underline;}'
      + '.posla-sdp__step-list{margin:0.5rem 0;padding-left:1.3rem;color:#333;}'
      + '.posla-sdp__step-list li{margin:0.3rem 0;}'
      + '.posla-sdp__err-card{background:#fff;border-left:3px solid ' + BRAND_ORANGE + ';border-radius:4px;padding:0.6rem 0.85rem;margin:0.45rem 0;}'
      + '.posla-sdp__err-code{font-weight:bold;color:' + BRAND_ORANGE + ';font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}'
      + '.posla-sdp__err-title{font-weight:bold;margin-left:0.5rem;color:#333;}'
      + '.posla-sdp__err-msg{color:#666;font-size:0.82rem;margin:0.25rem 0;white-space:pre-wrap;}'
      + '.posla-sdp__err-action{color:#333;font-size:0.85rem;white-space:pre-wrap;}'
      + '.posla-sdp__empty{color:#999;text-align:center;padding:1.5rem 0;font-size:0.9rem;}'
      + '.posla-sdp__footer{padding:0.65rem 1rem;background:#f8faff;color:var(--text-secondary);font-size:0.82rem;border-top:1px solid #dde3f5;line-height:1.45;}'
      + '.posla-sdp__footer a{color:' + BRAND_ORANGE + ';text-decoration:none;}'
      + '.posla-sdp__footer a:hover{text-decoration:underline;}';
    var s = document.createElement('style');
    s.id = 'posla-supportdesk-admin-style';
    s.textContent = css;
    document.head.appendChild(s);
  }

  function _buildShell() {
    _rootEl.innerHTML = ''
      + '<div class="posla-sdp">'
      +   '<div class="posla-sdp__tabs">'
      +     '<button class="posla-sdp__tab active" type="button" data-sdp-tab="home">ホーム</button>'
      +     '<button class="posla-sdp__tab" type="button" data-sdp-tab="faq">よくある質問</button>'
      +     '<button class="posla-sdp__tab" type="button" data-sdp-tab="search">キーワード</button>'
      +     '<button class="posla-sdp__tab" type="button" data-sdp-tab="flow">症状から</button>'
      +     '<button class="posla-sdp__tab" type="button" data-sdp-tab="error">エラーコード</button>'
      +   '</div>'
      +   '<div class="posla-sdp__body" id="posla-sdp-body"></div>'
      +   '<div class="posla-sdp__footer">収録外の質問は <a href="/docs-internal/" target="_blank" rel="noopener">internal docs トップ</a> / <a href="/docs-tenant/" target="_blank" rel="noopener">tenant docs</a> / <a href="/docs-internal/internal/production-api-checklist.html" target="_blank" rel="noopener">production-api-checklist</a> も参照してください。AIは現場業務 (AIウェイター / 予約AI解析 / AIキッチン / SNS生成) 専用となり、本ヘルプからは撤去されています。</div>'
      + '</div>';

    _bodyEl = _rootEl.querySelector('#posla-sdp-body');

    var tabs = _rootEl.querySelectorAll('[data-sdp-tab]');
    var i;
    for (i = 0; i < tabs.length; i++) {
      tabs[i].addEventListener('click', _onTabClick);
    }
  }

  function _onTabClick(e) {
    var tabId = e.currentTarget.getAttribute('data-sdp-tab');
    _switchTab(tabId);
  }

  function _switchTab(tabId) {
    _currentTab = tabId;
    var tabs = _rootEl.querySelectorAll('[data-sdp-tab]');
    var i;
    for (i = 0; i < tabs.length; i++) {
      if (tabs[i].getAttribute('data-sdp-tab') === tabId) {
        tabs[i].className = 'posla-sdp__tab active';
      } else {
        tabs[i].className = 'posla-sdp__tab';
      }
    }
    _render();
  }

  function _loadData() {
    _bodyEl.innerHTML = '<div class="posla-sdp__empty">読み込み中...</div>';
    fetch(DATA_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        try { _data = JSON.parse(text); } catch (e) {
          _bodyEl.innerHTML = '<div class="posla-sdp__empty">サポートデータの読み込みに失敗しました。</div>';
          return;
        }
        _loaded = true;
        _render();
      })
      .catch(function () {
        _bodyEl.innerHTML = '<div class="posla-sdp__empty">サポートデータに接続できません。ネットワーク / 配信確認してください。</div>';
      });
  }

  function _render() {
    if (!_data) return;
    if (_currentTab === 'home') _renderHome();
    else if (_currentTab === 'faq') _renderFaq();
    else if (_currentTab === 'search') _renderSearch();
    else if (_currentTab === 'flow') _renderFlow();
    else if (_currentTab === 'error') _renderError();
  }

  function _filterFaqByIds(ids) {
    var out = [];
    var i, j;
    for (i = 0; i < _data.faq.length; i++) {
      for (j = 0; j < ids.length; j++) {
        if (_data.faq[i].id === ids[j]) { out.push(_data.faq[i]); break; }
      }
    }
    return out;
  }

  function _renderHome() {
    var html = '<h4>カテゴリから探す</h4>'
      + '<div class="posla-sdp__chip-row">';
    var cats = _data.categories;
    var i;
    for (i = 0; i < cats.length; i++) {
      html += '<button class="posla-sdp__chip" type="button" data-home-cat="' + _escapeHtml(cats[i].id) + '">' + _escapeHtml(cats[i].label) + '</button>';
    }
    html += '</div>'
      + '<h4>よく使う質問</h4>';
    var pop = _filterFaqByIds([
      'ifq-login-session-revoked',
      'ifq-login-rate-limited',
      'ifq-ops-cron-silent',
      'ifq-migration-first-page',
      'ifq-support-tenant-contact'
    ]);
    html += _renderFaqCards(pop)
      + '<h4>エラーコードを入力</h4>'
      + '<input type="text" class="posla-sdp__search" id="posla-sdp-home-err" placeholder="SESSION_REVOKED / RATE_LIMITED / AI_NOT_CONFIGURED など" autocomplete="off">';
    _bodyEl.innerHTML = html;

    var chips = _bodyEl.querySelectorAll('[data-home-cat]');
    for (i = 0; i < chips.length; i++) {
      chips[i].addEventListener('click', _onHomeCatClick);
    }
    var errInput = _bodyEl.querySelector('#posla-sdp-home-err');
    if (errInput) errInput.addEventListener('input', _onHomeErrInput);
    _bindCardToggles();
  }

  function _onHomeCatClick(e) {
    _pendingCategoryFilter = e.currentTarget.getAttribute('data-home-cat');
    _switchTab('faq');
  }

  function _onHomeErrInput(e) {
    var v = (e.currentTarget.value || '').trim();
    if (v.length >= 2) {
      _pendingErrorQuery = v;
      _switchTab('error');
    }
  }

  function _renderFaq() {
    var activeCat = _pendingCategoryFilter;
    _pendingCategoryFilter = null;
    var cats = _data.categories;
    var html = '<div class="posla-sdp__chip-row">'
      + '<button class="posla-sdp__chip ' + (activeCat ? '' : 'active') + '" type="button" data-faq-cat="">すべて</button>';
    var i;
    for (i = 0; i < cats.length; i++) {
      html += '<button class="posla-sdp__chip ' + (activeCat === cats[i].id ? 'active' : '') + '" type="button" data-faq-cat="' + _escapeHtml(cats[i].id) + '">' + _escapeHtml(cats[i].label) + '</button>';
    }
    html += '</div>';

    var filtered = _data.faq;
    if (activeCat) {
      filtered = [];
      for (i = 0; i < _data.faq.length; i++) {
        if (_data.faq[i].category === activeCat) filtered.push(_data.faq[i]);
      }
    }
    html += _renderFaqCards(filtered);
    _bodyEl.innerHTML = html;

    var chips = _bodyEl.querySelectorAll('[data-faq-cat]');
    for (i = 0; i < chips.length; i++) {
      chips[i].addEventListener('click', _onFaqCatClick);
    }
    _bindCardToggles();
  }

  function _onFaqCatClick(e) {
    _pendingCategoryFilter = e.currentTarget.getAttribute('data-faq-cat') || null;
    _render();
  }

  function _renderFaqCards(list) {
    if (!list || list.length === 0) {
      return '<div class="posla-sdp__empty">該当する質問がありません。</div>';
    }
    var html = '';
    var i;
    for (i = 0; i < list.length; i++) {
      var item = list[i];
      html += '<div class="posla-sdp__card" data-card-id="' + _escapeHtml(item.id) + '">'
        + '<div class="posla-sdp__card-title">' + _escapeHtml(item.title) + '</div>'
        + '<div class="posla-sdp__card-body">' + _escapeHtml(item.answer);
      if (item.doc_link) {
        html += '<br><a class="posla-sdp__card-link" href="' + _escapeHtml(_normalizeUrl(item.doc_link)) + '" target="_blank" rel="noopener">詳細 docs を開く →</a>';
      }
      html += '</div></div>';
    }
    return html;
  }

  function _bindCardToggles() {
    var cards = _bodyEl.querySelectorAll('.posla-sdp__card');
    var i;
    for (i = 0; i < cards.length; i++) {
      cards[i].addEventListener('click', _onCardClick);
    }
  }

  function _onCardClick(e) {
    if (e.target && e.target.tagName === 'A') return;
    var card = e.currentTarget;
    if (card.className.indexOf('open') >= 0) {
      card.className = 'posla-sdp__card';
    } else {
      card.className = 'posla-sdp__card open';
    }
  }

  function _renderSearch() {
    var html = '<input type="text" class="posla-sdp__search" id="posla-sdp-search-input" placeholder="キーワード例: cron / env / SESSION_REVOKED / Stripe / 予約 / LINE" autocomplete="off">'
      + '<div id="posla-sdp-search-result"></div>';
    _bodyEl.innerHTML = html;
    var input = _bodyEl.querySelector('#posla-sdp-search-input');
    input.addEventListener('input', _onSearchInput);
    _renderSearchResult('');
    setTimeout(function () { input.focus(); }, 50);
  }

  function _onSearchInput(e) {
    _renderSearchResult(e.currentTarget.value || '');
  }

  function _renderSearchResult(query) {
    var resultEl = _bodyEl.querySelector('#posla-sdp-search-result');
    if (!resultEl) return;
    var q = (query || '').trim().toLowerCase();
    if (q.length === 0) {
      resultEl.innerHTML = '<div class="posla-sdp__empty">キーワードを入力すると質問・記事・エラーコードから部分一致で検索します。</div>';
      return;
    }

    var faqHits = _searchList(_data.faq, q, ['title', 'answer', 'keywords']);
    var artHits = _searchList(_data.articles || [], q, ['title', 'summary', 'keywords']);
    var errHits = _searchList(_data.error_codes, q, ['code', 'title', 'message', 'action']);

    var html = '';
    if (faqHits.length > 0) {
      html += '<h4>よくある質問 (' + faqHits.length + ')</h4>';
      html += _renderFaqCards(faqHits);
    }
    if (artHits.length > 0) {
      html += '<h4>関連記事 (' + artHits.length + ')</h4>';
      var i;
      for (i = 0; i < artHits.length; i++) {
        var a = artHits[i];
        html += '<div class="posla-sdp__card open">'
          + '<div class="posla-sdp__card-title">' + _escapeHtml(a.title) + '</div>'
          + '<div class="posla-sdp__card-body">' + _escapeHtml(a.summary);
        if (a.doc_link) html += '<br><a class="posla-sdp__card-link" href="' + _escapeHtml(_normalizeUrl(a.doc_link)) + '" target="_blank" rel="noopener">詳細 docs を開く →</a>';
        html += '</div></div>';
      }
    }
    if (errHits.length > 0) {
      html += '<h4>エラーコード (' + errHits.length + ')</h4>';
      html += _renderErrorCards(errHits);
    }
    if (html === '') {
      html = '<div class="posla-sdp__empty">「' + _escapeHtml(q) + '」に該当する項目がありません。別のキーワードでお試しください。</div>';
    }
    resultEl.innerHTML = html;
    _bindCardToggles();
  }

  function _searchList(list, q, fields) {
    var out = [];
    var i, j;
    for (i = 0; i < list.length; i++) {
      var item = list[i];
      var hit = false;
      for (j = 0; j < fields.length; j++) {
        var f = fields[j];
        var v = item[f];
        if (v == null) continue;
        if (Array.isArray(v)) {
          var k;
          for (k = 0; k < v.length; k++) {
            if (String(v[k]).toLowerCase().indexOf(q) >= 0) { hit = true; break; }
          }
        } else {
          if (String(v).toLowerCase().indexOf(q) >= 0) { hit = true; }
        }
        if (hit) break;
      }
      if (hit) out.push(item);
    }
    return out;
  }

  function _renderFlow() {
    var html = '<h4>症状から対処手順を選ぶ</h4>';
    var flows = _data.flows || [];
    var i, j;
    for (i = 0; i < flows.length; i++) {
      var f = flows[i];
      html += '<div class="posla-sdp__card" data-card-id="' + _escapeHtml(f.id) + '">'
        + '<div class="posla-sdp__card-title">' + _escapeHtml(f.title) + '</div>'
        + '<div class="posla-sdp__card-body">'
        + '<ol class="posla-sdp__step-list">';
      for (j = 0; j < f.steps.length; j++) {
        html += '<li>' + _escapeHtml(f.steps[j]) + '</li>';
      }
      html += '</ol>';
      if (f.doc_link) {
        html += '<a class="posla-sdp__card-link" href="' + _escapeHtml(_normalizeUrl(f.doc_link)) + '" target="_blank" rel="noopener">詳細 docs を開く →</a>';
      }
      html += '</div></div>';
    }
    _bodyEl.innerHTML = html;
    _bindCardToggles();
  }

  function _renderError() {
    var q = _pendingErrorQuery || '';
    _pendingErrorQuery = null;
    var html = '<input type="text" class="posla-sdp__search" id="posla-sdp-err-input" placeholder="SESSION_REVOKED / E3005 / RATE_LIMITED 等" value="' + _escapeHtml(q) + '" autocomplete="off">'
      + '<div id="posla-sdp-err-result"></div>';
    _bodyEl.innerHTML = html;
    var input = _bodyEl.querySelector('#posla-sdp-err-input');
    input.addEventListener('input', _onErrInput);
    _renderErrResult(q);
    setTimeout(function () { input.focus(); }, 50);
  }

  function _onErrInput(e) {
    _renderErrResult(e.currentTarget.value || '');
  }

  function _renderErrResult(query) {
    var resultEl = _bodyEl.querySelector('#posla-sdp-err-result');
    if (!resultEl) return;
    var q = (query || '').trim().toUpperCase();
    var list = _data.error_codes || [];
    var hits = [];
    var i;
    if (q.length === 0) {
      resultEl.innerHTML = '<div class="posla-sdp__empty">エラーコード (例: SESSION_REVOKED / RATE_LIMITED / AI_NOT_CONFIGURED) を入力すると意味と対処が表示されます。収録は代表的な ' + list.length + ' 件です。全件は <a href="/docs-internal/internal/99-error-catalog.html" target="_blank" rel="noopener">internal 側 エラーカタログ</a> / <a href="/docs-tenant/tenant/99-error-catalog.html" target="_blank" rel="noopener">tenant 側</a> を参照。</div>';
      return;
    }
    for (i = 0; i < list.length; i++) {
      if (list[i].code.toUpperCase().indexOf(q) >= 0 || String(list[i].title).toUpperCase().indexOf(q) >= 0) {
        hits.push(list[i]);
      }
    }
    if (hits.length === 0) {
      resultEl.innerHTML = '<div class="posla-sdp__empty">「' + _escapeHtml(q) + '」に該当するコードがありません。<a href="/docs-tenant/tenant/99-error-catalog.html" target="_blank" rel="noopener">tenant 側 E コード全カタログ</a> もご確認ください。</div>';
      return;
    }
    resultEl.innerHTML = _renderErrorCards(hits);
  }

  function _renderErrorCards(hits) {
    var html = '';
    var i;
    for (i = 0; i < hits.length; i++) {
      var e = hits[i];
      html += '<div class="posla-sdp__err-card">'
        + '<span class="posla-sdp__err-code">' + _escapeHtml(e.code) + '</span>'
        + '<span class="posla-sdp__err-title">' + _escapeHtml(e.title) + '</span>'
        + '<div class="posla-sdp__err-msg">' + _escapeHtml(e.message) + '</div>'
        + '<div class="posla-sdp__err-action">→ ' + _escapeHtml(e.action) + '</div>'
        + '</div>';
    }
    return html;
  }

  // public API
  return {
    mount: function (rootEl) {
      if (!rootEl) return;
      _injectStyle();
      if (_rootEl !== rootEl || !_bodyEl || !_rootEl || !_rootEl.querySelector('#posla-sdp-body')) {
        _rootEl = rootEl;
        _buildShell();
      }
      _mounted = true;
      if (!_loaded) _loadData();
      else {
        _switchTab(_currentTab || 'home');
      }
    },
    reset: function () {
      _currentTab = 'home';
      _pendingCategoryFilter = null;
      _pendingErrorQuery = null;
      if (_loaded) {
        var tabs = _rootEl ? _rootEl.querySelectorAll('[data-sdp-tab]') : [];
        var i;
        for (i = 0; i < tabs.length; i++) {
          if (tabs[i].getAttribute('data-sdp-tab') === 'home') tabs[i].className = 'posla-sdp__tab active';
          else tabs[i].className = 'posla-sdp__tab';
        }
        _render();
      }
    }
  };
})();
