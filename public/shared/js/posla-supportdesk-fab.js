/**
 * POSLA サポートガイド FAB (非AI、tenant 向け)
 *
 * tenant 側の dashboard / owner-dashboard からのみ参照。
 * AI API は一切叩かず、静的 JSON (tenant-supportdesk.json) を読み込んで
 * FAQ / キーワード検索 / 症状フローチャート / エラーコード直引き を提供する。
 *
 * 使い方 (HTML 末尾に 1 行追加):
 *   <script src="../shared/js/posla-supportdesk-fab.js?v=20260423"></script>
 *
 * ES5 IIFE パターン。const/let/arrow 不使用。
 * POSLA 管理者向けは /posla-admin/ の非AI サポートセンターを利用する。
 */
(function () {
  'use strict';

  if (window.__POSLA_SUPPORTDESK_FAB__) return;
  window.__POSLA_SUPPORTDESK_FAB__ = true;

  var BRAND_ORANGE = '#ff6f00';
  var DATA_URL = _resolveDataUrl();

  var _open = false;
  var _loaded = false;
  var _data = null;
  var _root = null;
  var _bodyEl = null;
  var _currentTab = 'home';

  function _resolveDataUrl() {
    var path = location.pathname || '';
    if (path.indexOf('/public/') === 0) return '/public/shared/data/tenant-supportdesk.json';
    return '../shared/data/tenant-supportdesk.json';
  }

  function _normalizeUrl(url) {
    url = String(url || '');
    if (!url) return '#';
    if (url.indexOf('/public/') === 0) return url.substring(7);
    return url;
  }

  // ── スタイル一括注入 ──
  function _injectStyle() {
    if (document.getElementById('posla-supportdesk-style')) return;
    var css =
      '#posla-supportdesk-fab{position:fixed;right:20px;bottom:20px;width:60px;height:60px;border-radius:50%;'
      + 'background:' + BRAND_ORANGE + ';color:#fff;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.25);'
      + 'z-index:9998;display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:bold;'
      + 'transition:transform 0.15s ease,background 0.15s;}'
      + '#posla-supportdesk-fab:hover{transform:scale(1.06);background:#ff8a1f;}'
      + '#posla-supportdesk-fab:active{transform:scale(0.95);}'
      + '#posla-supportdesk-panel{position:fixed;right:20px;bottom:90px;width:420px;max-width:calc(100vw - 24px);'
      + 'height:640px;max-height:calc(100vh - 110px);background:#fff;border-radius:12px;'
      + 'box-shadow:0 8px 32px rgba(0,0,0,0.25);display:none;flex-direction:column;z-index:9999;'
      + 'font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans",sans-serif;overflow:hidden;}'
      + '#posla-supportdesk-panel.open{display:flex;}'
      + '.posla-sd__header{background:' + BRAND_ORANGE + ';color:#fff;padding:0.75rem 1rem;'
      + 'display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}'
      + '.posla-sd__title{font-weight:bold;font-size:0.95rem;}'
      + '.posla-sd__close{background:transparent;border:none;color:#fff;font-size:1.5rem;'
      + 'cursor:pointer;padding:0;line-height:1;}'
      + '.posla-sd__tabs{display:flex;border-bottom:1px solid #eee;flex-shrink:0;background:#fff;}'
      + '.posla-sd__tab{flex:1;padding:0.6rem 0.5rem;font-size:0.8rem;background:#fff;border:none;border-bottom:2px solid transparent;cursor:pointer;color:#555;font-family:inherit;}'
      + '.posla-sd__tab.active{color:' + BRAND_ORANGE + ';border-bottom-color:' + BRAND_ORANGE + ';font-weight:bold;}'
      + '.posla-sd__tab:hover{background:#fafafa;}'
      + '.posla-sd__body{flex:1;overflow-y:auto;padding:0.75rem 1rem;font-size:0.85rem;line-height:1.55;background:#fafafa;}'
      + '.posla-sd__body h4{margin:0.6rem 0 0.4rem;font-size:0.9rem;color:#333;}'
      + '.posla-sd__body p{margin:0.3rem 0;color:#444;}'
      + '.posla-sd__search{width:100%;padding:0.55rem 0.7rem;border:1px solid #ddd;border-radius:6px;font-size:0.9rem;font-family:inherit;box-sizing:border-box;}'
      + '.posla-sd__search:focus{outline:none;border-color:' + BRAND_ORANGE + ';}'
      + '.posla-sd__shortcut-row{display:flex;flex-wrap:wrap;gap:0.35rem;margin:0.5rem 0 0.8rem;}'
      + '.posla-sd__chip{background:#fff;border:1px solid #ddd;color:#555;padding:0.25rem 0.65rem;border-radius:14px;font-size:0.75rem;cursor:pointer;font-family:inherit;}'
      + '.posla-sd__chip:hover{background:' + BRAND_ORANGE + ';color:#fff;border-color:' + BRAND_ORANGE + ';}'
      + '.posla-sd__chip.active{background:' + BRAND_ORANGE + ';color:#fff;border-color:' + BRAND_ORANGE + ';}'
      + '.posla-sd__card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:0.7rem 0.85rem;margin:0.5rem 0;cursor:pointer;transition:border-color 0.12s;}'
      + '.posla-sd__card:hover{border-color:' + BRAND_ORANGE + ';}'
      + '.posla-sd__card-title{font-weight:bold;color:#222;margin-bottom:0.2rem;font-size:0.88rem;}'
      + '.posla-sd__card-summary{color:#666;font-size:0.8rem;}'
      + '.posla-sd__card-body{margin-top:0.5rem;color:#333;font-size:0.85rem;white-space:pre-wrap;display:none;}'
      + '.posla-sd__card.open .posla-sd__card-body{display:block;}'
      + '.posla-sd__card-link{display:inline-block;margin-top:0.4rem;color:' + BRAND_ORANGE + ';font-size:0.78rem;text-decoration:none;}'
      + '.posla-sd__card-link:hover{text-decoration:underline;}'
      + '.posla-sd__step-list{margin:0.5rem 0;padding-left:1.2rem;color:#333;}'
      + '.posla-sd__step-list li{margin:0.25rem 0;}'
      + '.posla-sd__err-card{background:#fff;border-left:3px solid ' + BRAND_ORANGE + ';border-radius:4px;padding:0.55rem 0.75rem;margin:0.4rem 0;}'
      + '.posla-sd__err-code{font-weight:bold;color:' + BRAND_ORANGE + ';font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}'
      + '.posla-sd__err-title{font-weight:bold;margin-left:0.4rem;color:#333;}'
      + '.posla-sd__err-msg{color:#666;font-size:0.8rem;margin:0.2rem 0;}'
      + '.posla-sd__err-action{color:#333;font-size:0.82rem;}'
      + '.posla-sd__empty{color:#999;text-align:center;padding:1.5rem 0;font-size:0.85rem;}'
      + '.posla-sd__footer{padding:0.55rem 1rem;background:#fff8e1;color:#555;font-size:0.78rem;border-top:1px solid #ffe082;flex-shrink:0;line-height:1.4;}'
      + '.posla-sd__footer a{color:' + BRAND_ORANGE + ';text-decoration:none;}'
      + '.posla-sd__footer a:hover{text-decoration:underline;}'
      + '@media (max-width:480px){#posla-supportdesk-panel{width:calc(100vw - 24px);height:calc(100vh - 110px);right:12px;bottom:80px;}}';
    var s = document.createElement('style');
    s.id = 'posla-supportdesk-style';
    s.textContent = css;
    document.head.appendChild(s);
  }

  // ── DOM 構築 ──
  function _buildDom() {
    var fab = document.createElement('button');
    fab.id = 'posla-supportdesk-fab';
    fab.type = 'button';
    fab.title = 'サポートガイドを開く';
    fab.setAttribute('aria-label', 'サポートガイドを開く');
    fab.innerHTML = '<span style="font-size:1.4rem;">?</span>';
    fab.addEventListener('click', _toggle);

    var panel = document.createElement('div');
    panel.id = 'posla-supportdesk-panel';
    panel.innerHTML =
      '<div class="posla-sd__header">'
      +   '<div class="posla-sd__title">📚 サポートガイド</div>'
      +   '<button class="posla-sd__close" type="button" aria-label="閉じる">×</button>'
      + '</div>'
      + '<div class="posla-sd__tabs">'
      +   '<button class="posla-sd__tab active" type="button" data-tab="home">ホーム</button>'
      +   '<button class="posla-sd__tab" type="button" data-tab="faq">よくある質問</button>'
      +   '<button class="posla-sd__tab" type="button" data-tab="search">キーワード</button>'
      +   '<button class="posla-sd__tab" type="button" data-tab="flow">症状から</button>'
      +   '<button class="posla-sd__tab" type="button" data-tab="error">エラー番号</button>'
      + '</div>'
      + '<div class="posla-sd__body" id="posla-sd-body"></div>'
      + '<div class="posla-sd__footer">解決しない場合: 画面名・操作内容・エラー番号・発生時刻を控えて <strong>POSLAサポート</strong> に連絡してください。</div>';

    document.body.appendChild(fab);
    document.body.appendChild(panel);

    _root = panel;
    _bodyEl = panel.querySelector('#posla-sd-body');

    panel.querySelector('.posla-sd__close').addEventListener('click', _close);

    var tabs = panel.querySelectorAll('.posla-sd__tab');
    var i;
    for (i = 0; i < tabs.length; i++) {
      tabs[i].addEventListener('click', _onTabClick);
    }
  }

  function _onTabClick(e) {
    var tabId = e.currentTarget.getAttribute('data-tab');
    _switchTab(tabId);
  }

  function _switchTab(tabId) {
    _currentTab = tabId;
    var tabs = _root.querySelectorAll('.posla-sd__tab');
    var i;
    for (i = 0; i < tabs.length; i++) {
      if (tabs[i].getAttribute('data-tab') === tabId) {
        tabs[i].className = 'posla-sd__tab active';
      } else {
        tabs[i].className = 'posla-sd__tab';
      }
    }
    _render();
  }

  function _toggle() {
    if (_open) _close(); else _show();
  }
  function _show() {
    _open = true;
    _root.classList.add('open');
    if (!_loaded) {
      _loadData();
    }
  }
  function _close() {
    _open = false;
    _root.classList.remove('open');
  }

  // ── データ読み込み ──
  function _loadData() {
    _bodyEl.innerHTML = '<div class="posla-sd__empty">読み込み中...</div>';
    fetch(DATA_URL, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        try { _data = JSON.parse(text); } catch (e) {
          _bodyEl.innerHTML = '<div class="posla-sd__empty">サポートデータの読み込みに失敗しました。</div>';
          return;
        }
        _loaded = true;
        _render();
      })
      .catch(function () {
        _bodyEl.innerHTML = '<div class="posla-sd__empty">サポートデータに接続できません。ネットワークを確認してください。</div>';
      });
  }

  // ── 画面レンダリング ──
  function _render() {
    if (!_data) return;
    if (_currentTab === 'home') _renderHome();
    else if (_currentTab === 'faq') _renderFaq();
    else if (_currentTab === 'search') _renderSearch();
    else if (_currentTab === 'flow') _renderFlow();
    else if (_currentTab === 'error') _renderError();
  }

  function _escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function _renderHome() {
    var html = ''
      + '<h4>お困りの内容を選んでください</h4>'
      + '<div class="posla-sd__shortcut-row">';
    var cats = _data.categories;
    var i;
    for (i = 0; i < cats.length; i++) {
      html += '<button class="posla-sd__chip" type="button" data-home-cat="' + _escapeHtml(cats[i].id) + '">' + _escapeHtml(cats[i].label) + '</button>';
    }
    html += '</div>'
      + '<h4>人気の質問</h4>';
    var popularIds = ['faq-login-01', 'faq-order-01', 'faq-payment-01', 'faq-print-01', 'faq-reservation-01'];
    var popularFaqs = _filterFaqByIds(popularIds);
    html += _renderFaqCards(popularFaqs);
    html += ''
      + '<h4>エラー番号を入力</h4>'
      + '<input type="text" class="posla-sd__search" id="posla-sd-home-err" placeholder="E3024 / E6001 などの番号を入力">';
    _bodyEl.innerHTML = html;

    var chips = _bodyEl.querySelectorAll('[data-home-cat]');
    for (i = 0; i < chips.length; i++) {
      chips[i].addEventListener('click', _onHomeCatClick);
    }
    var errInput = _bodyEl.querySelector('#posla-sd-home-err');
    if (errInput) {
      errInput.addEventListener('input', _onHomeErrInput);
    }
    _bindCardToggles();
  }

  function _onHomeCatClick(e) {
    var cat = e.currentTarget.getAttribute('data-home-cat');
    _currentTab = 'faq';
    _pendingCategoryFilter = cat;
    var tabs = _root.querySelectorAll('.posla-sd__tab');
    var i;
    for (i = 0; i < tabs.length; i++) {
      tabs[i].className = tabs[i].getAttribute('data-tab') === 'faq' ? 'posla-sd__tab active' : 'posla-sd__tab';
    }
    _render();
  }

  function _onHomeErrInput(e) {
    var v = (e.currentTarget.value || '').trim();
    if (v.length >= 2) {
      _currentTab = 'error';
      _pendingErrorQuery = v;
      var tabs = _root.querySelectorAll('.posla-sd__tab');
      var i;
      for (i = 0; i < tabs.length; i++) {
        tabs[i].className = tabs[i].getAttribute('data-tab') === 'error' ? 'posla-sd__tab active' : 'posla-sd__tab';
      }
      _render();
    }
  }

  var _pendingCategoryFilter = null;
  var _pendingErrorQuery = null;

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

  function _renderFaq() {
    var activeCat = _pendingCategoryFilter;
    _pendingCategoryFilter = null;
    var cats = _data.categories;
    var html = '<div class="posla-sd__shortcut-row">'
      + '<button class="posla-sd__chip ' + (activeCat ? '' : 'active') + '" type="button" data-faq-cat="">すべて</button>';
    var i;
    for (i = 0; i < cats.length; i++) {
      html += '<button class="posla-sd__chip ' + (activeCat === cats[i].id ? 'active' : '') + '" type="button" data-faq-cat="' + _escapeHtml(cats[i].id) + '">' + _escapeHtml(cats[i].label) + '</button>';
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
      return '<div class="posla-sd__empty">該当する質問がありません。</div>';
    }
    var html = '';
    var i;
    for (i = 0; i < list.length; i++) {
      var item = list[i];
      html += '<div class="posla-sd__card" data-card-id="' + _escapeHtml(item.id) + '">'
        + '<div class="posla-sd__card-title">' + _escapeHtml(item.title) + '</div>'
        + '<div class="posla-sd__card-body">' + _escapeHtml(item.answer);
      if (item.doc_link) {
        html += '<br><a class="posla-sd__card-link" href="' + _escapeHtml(_normalizeUrl(item.doc_link)) + '" target="_blank" rel="noopener">詳細マニュアルを開く →</a>';
      }
      html += '</div></div>';
    }
    return html;
  }

  function _bindCardToggles() {
    var cards = _bodyEl.querySelectorAll('.posla-sd__card');
    var i;
    for (i = 0; i < cards.length; i++) {
      cards[i].addEventListener('click', _onCardClick);
    }
  }

  function _onCardClick(e) {
    if (e.target && e.target.tagName === 'A') return;
    var card = e.currentTarget;
    if (card.className.indexOf('open') >= 0) {
      card.className = 'posla-sd__card';
    } else {
      card.className = 'posla-sd__card open';
    }
  }

  function _renderSearch() {
    var html = ''
      + '<input type="text" class="posla-sd__search" id="posla-sd-search-input" placeholder="キーワード例: 会計 / パスワード / 予約 / プリンタ" autocomplete="off">'
      + '<div id="posla-sd-search-result"></div>';
    _bodyEl.innerHTML = html;
    var input = _bodyEl.querySelector('#posla-sd-search-input');
    input.addEventListener('input', _onSearchInput);
    _renderSearchResult('');
    setTimeout(function () { input.focus(); }, 50);
  }

  function _onSearchInput(e) {
    _renderSearchResult(e.currentTarget.value || '');
  }

  function _renderSearchResult(query) {
    var resultEl = _bodyEl.querySelector('#posla-sd-search-result');
    if (!resultEl) return;
    var q = (query || '').trim().toLowerCase();
    if (q.length === 0) {
      resultEl.innerHTML = '<div class="posla-sd__empty">キーワードを入力すると質問・記事・エラーコードから部分一致で検索します。</div>';
      return;
    }

    var faqHits = _searchList(_data.faq, q, ['title', 'answer', 'keywords']);
    var artHits = _searchList(_data.articles, q, ['title', 'summary', 'keywords']);
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
        html += '<div class="posla-sd__card open">'
          + '<div class="posla-sd__card-title">' + _escapeHtml(a.title) + '</div>'
          + '<div class="posla-sd__card-body">' + _escapeHtml(a.summary);
        if (a.doc_link) html += '<br><a class="posla-sd__card-link" href="' + _escapeHtml(_normalizeUrl(a.doc_link)) + '" target="_blank" rel="noopener">詳細マニュアルを開く →</a>';
        html += '</div></div>';
      }
    }
    if (errHits.length > 0) {
      html += '<h4>エラーコード (' + errHits.length + ')</h4>';
      html += _renderErrorCards(errHits);
    }
    if (html === '') {
      html = '<div class="posla-sd__empty">「' + _escapeHtml(q) + '」に該当する項目がありません。別のキーワードでお試しください。</div>';
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
      html += '<div class="posla-sd__card" data-card-id="' + _escapeHtml(f.id) + '">'
        + '<div class="posla-sd__card-title">' + _escapeHtml(f.title) + '</div>'
        + '<div class="posla-sd__card-body">'
        + '<ol class="posla-sd__step-list">';
      for (j = 0; j < f.steps.length; j++) {
        html += '<li>' + _escapeHtml(f.steps[j]) + '</li>';
      }
      html += '</ol>';
      if (f.doc_link) {
        html += '<a class="posla-sd__card-link" href="' + _escapeHtml(_normalizeUrl(f.doc_link)) + '" target="_blank" rel="noopener">詳細マニュアルを開く →</a>';
      }
      html += '</div></div>';
    }
    _bodyEl.innerHTML = html;
    _bindCardToggles();
  }

  function _renderError() {
    var q = _pendingErrorQuery || '';
    _pendingErrorQuery = null;
    var html = ''
      + '<input type="text" class="posla-sd__search" id="posla-sd-err-input" placeholder="E3024 / E6001 など、番号を入力" value="' + _escapeHtml(q) + '" autocomplete="off">'
      + '<div id="posla-sd-err-result"></div>';
    _bodyEl.innerHTML = html;
    var input = _bodyEl.querySelector('#posla-sd-err-input');
    input.addEventListener('input', _onErrInput);
    _renderErrResult(q);
    setTimeout(function () { input.focus(); }, 50);
  }

  function _onErrInput(e) {
    _renderErrResult(e.currentTarget.value || '');
  }

  function _renderErrResult(query) {
    var resultEl = _bodyEl.querySelector('#posla-sd-err-result');
    if (!resultEl) return;
    var q = (query || '').trim().toUpperCase();
    var list = _data.error_codes || [];
    var hits = [];
    var i;
    if (q.length === 0) {
      resultEl.innerHTML = '<div class="posla-sd__empty">エラー番号 (例: E3024) を入力すると意味と対処が表示されます。収録は代表的な 15 件です。全件は <a href="/docs-tenant/tenant/99-error-catalog.html" target="_blank" rel="noopener">エラーカタログ</a> を参照してください。</div>';
      return;
    }
    for (i = 0; i < list.length; i++) {
      if (list[i].code.toUpperCase().indexOf(q) >= 0 || String(list[i].title).toUpperCase().indexOf(q) >= 0) {
        hits.push(list[i]);
      }
    }
    if (hits.length === 0) {
      resultEl.innerHTML = '<div class="posla-sd__empty">「' + _escapeHtml(q) + '」に該当するエラーコードがありません。<a href="/docs-tenant/tenant/99-error-catalog.html" target="_blank" rel="noopener">全エラーカタログを開く →</a></div>';
      return;
    }
    resultEl.innerHTML = _renderErrorCards(hits);
  }

  function _renderErrorCards(hits) {
    var html = '';
    var i;
    for (i = 0; i < hits.length; i++) {
      var e = hits[i];
      html += '<div class="posla-sd__err-card">'
        + '<span class="posla-sd__err-code">' + _escapeHtml(e.code) + '</span>'
        + '<span class="posla-sd__err-title">' + _escapeHtml(e.title) + '</span>'
        + '<div class="posla-sd__err-msg">' + _escapeHtml(e.message) + '</div>'
        + '<div class="posla-sd__err-action">→ ' + _escapeHtml(e.action) + '</div>'
        + '</div>';
    }
    return html;
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
