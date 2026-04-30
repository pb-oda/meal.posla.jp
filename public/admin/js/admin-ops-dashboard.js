/**
 * 管理画面オペレーションダッシュボード
 * 今日の運営コックピット / 横断検索 / 売上ドリル / 初期設定 / 端末状態 / カート離脱分析
 */
var AdminOpsDashboard = (function () {
  'use strict';

  var _containers = {};
  var _state = {
    drill: { from: '', to: '', date: '', item: '', orderId: '' },
    cart: { from: '', to: '' }
  };

  function init(containers) {
    _containers = containers || {};
  }

  function esc(s) {
    if (window.Utils && Utils.escapeHtml) return Utils.escapeHtml(s);
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function yen(v) {
    if (window.Utils && Utils.formatYen) return Utils.formatYen(v || 0);
    return '¥' + String(v || 0).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function todayRange() {
    return window.Utils && Utils.getPresetRange ? Utils.getPresetRange('today') : {
      from: new Date().toISOString().slice(0, 10),
      to: new Date().toISOString().slice(0, 10)
    };
  }

  function monthRange() {
    return window.Utils && Utils.getPresetRange ? Utils.getPresetRange('month') : todayRange();
  }

  function empty(el, text) {
    if (el) el.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + esc(text || 'データがありません') + '</p></div>';
  }

  function loading(el) {
    if (el) el.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
  }

  function requireStore(el) {
    if (!AdminApi.getCurrentStore()) {
      empty(el, '店舗を選択してください');
      return false;
    }
    return true;
  }

  function relativeTime(value) {
    if (!value) return '記録なし';
    var ts = new Date(value.replace(' ', 'T')).getTime();
    if (!ts) return value;
    var diff = Math.max(0, Math.floor((new Date().getTime() - ts) / 1000));
    if (diff < 60) return diff + '秒前';
    if (diff < 3600) return Math.floor(diff / 60) + '分前';
    if (diff < 86400) return Math.floor(diff / 3600) + '時間前';
    return Math.floor(diff / 86400) + '日前';
  }

  function loadCockpit() {
    var el = _containers.cockpit;
    if (!requireStore(el)) return;
    loading(el);
    AdminApi.getAdminOps('cockpit', { date: todayRange().from }).then(function (data) {
      var html = '<div class="ops-head"><div><h3>今日の運営コックピット</h3><p>未会計、未締め、予約、シフト、品切れ、低評価、障害を同じ画面で確認します。</p></div>'
        + '<button class="btn btn-sm btn-outline" data-ops-refresh="cockpit">更新</button></div>';
      html += '<div class="ops-kpi-grid">';
      var cards = data.cards || [];
      for (var i = 0; i < cards.length; i++) {
        var c = cards[i];
        html += '<button type="button" class="ops-kpi ops-kpi--' + esc(c.level || 'ok') + '" data-ops-jump="' + esc(c.key) + '">'
          + '<span class="ops-kpi__label">' + esc(c.label) + '</span>'
          + '<strong class="ops-kpi__value">' + esc(c.value) + '</strong>'
          + '<span class="ops-kpi__sub">' + esc(c.sub || '') + '</span>'
          + '</button>';
      }
      html += '</div>';
      html += '<div class="ops-two-col">'
        + '<div class="ops-card"><h4>レジ状態</h4><p>' + (data.register && data.register.isOpen ? 'レジは開局中です。締め忘れに注意してください。' : '未締め警告はありません。') + '</p>'
        + '<dl><dt>開局</dt><dd>' + esc(data.register && data.register.latestOpenAt ? data.register.latestOpenAt : '-') + '</dd><dt>締め</dt><dd>' + esc(data.register && data.register.latestCloseAt ? data.register.latestCloseAt : '-') + '</dd></dl></div>'
        + '<div class="ops-card"><h4>着席・シフト</h4><p>着席中 ' + esc(data.tables ? data.tables.activeCount : 0) + '卓 / 公開済みシフト ' + esc(data.shift ? data.shift.assignmentCount : 0) + '件</p>'
        + '<p>未打刻 ' + esc(data.shift ? data.shift.noClockInCount : 0) + ' / 未対応申請 ' + esc(data.shift ? data.shift.pendingRequestCount : 0) + '</p></div>'
        + '</div>';
      el.innerHTML = html;
    }).catch(function (err) {
      empty(el, err.message);
    });
  }

  function loadSearch() {
    var el = _containers.search;
    if (!requireStore(el)) return;
    el.innerHTML = '<div class="ops-head"><div><h3>全体検索</h3><p>注文、予約、顧客、商品、スタッフ、エラーを横断検索します。</p></div></div>'
      + '<div class="ops-searchbar"><input id="ops-search-q" class="form-input" placeholder="名前・電話・商品名・注文ID・エラー番号を入力" autocomplete="off">'
      + '<button class="btn btn-primary" id="ops-search-btn">検索</button></div>'
      + '<div id="ops-search-results" class="ops-results"></div>';
    var btn = document.getElementById('ops-search-btn');
    var input = document.getElementById('ops-search-q');
    function run() {
      var q = input.value.trim();
      var box = document.getElementById('ops-search-results');
      if (q.length < 2) { empty(box, '2文字以上で検索してください'); return; }
      loading(box);
      AdminApi.getAdminOps('search', { q: q }).then(function (data) { renderSearchResults(box, data.groups || []); })
        .catch(function (err) { empty(box, err.message); });
    }
    btn.addEventListener('click', run);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') run(); });
  }

  function renderSearchResults(box, groups) {
    var html = '';
    for (var i = 0; i < groups.length; i++) {
      var g = groups[i];
      html += '<section class="ops-card"><h4>' + esc(g.label) + ' <span>' + (g.items ? g.items.length : 0) + '件</span></h4>';
      if (!g.items || g.items.length === 0) {
        html += '<p class="ops-muted">該当なし</p></section>';
        continue;
      }
      html += '<div class="ops-list">';
      for (var j = 0; j < g.items.length; j++) {
        html += renderSearchItem(g.type, g.items[j]);
      }
      html += '</div></section>';
    }
    box.innerHTML = html || '<div class="empty-state"><p class="empty-state__text">該当なし</p></div>';
  }

  function renderSearchItem(type, item) {
    var title = item.name || item.customer_name || item.display_name || item.username || item.code || item.id || '-';
    var sub = '';
    if (type === 'orders') sub = item.status + ' / ' + yen(item.total_amount) + ' / ' + item.created_at;
    else if (type === 'reservations') sub = item.reserved_at + ' / ' + item.party_size + '名 / ' + item.status;
    else if (type === 'customers') sub = (item.customer_phone || '-') + ' / 来店 ' + (item.visit_count || 0) + ' / no-show ' + (item.no_show_count || 0);
    else if (type === 'menu') sub = (item.category_name || '-') + ' / ' + yen(item.price) + ' / ' + item.source;
    else if (type === 'staff') sub = item.role + ' / ' + (item.username || '');
    else if (type === 'errors') sub = (item.error_no || '') + ' ' + (item.http_status || '') + ' / ' + item.created_at;
    return '<div class="ops-list-row"><strong>' + esc(title) + '</strong><span>' + esc(sub) + '</span></div>';
  }

  function loadSalesDrilldown() {
    var el = _containers.salesDrilldown;
    if (!requireStore(el)) return;
    var r = monthRange();
    _state.drill.from = _state.drill.from || r.from;
    _state.drill.to = _state.drill.to || r.to;
    el.innerHTML = '<div class="ops-head"><div><h3>売上ドリルダウン</h3><p>日別 → 商品 → 注文 → 会計履歴まで追えます。</p></div></div>'
      + '<div class="report-date-range">'
      + '<input type="date" id="ops-drill-from" value="' + esc(_state.drill.from) + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="ops-drill-to" value="' + esc(_state.drill.to) + '">'
      + '<button class="btn btn-sm btn-primary" id="ops-drill-load">表示</button></div>'
      + '<div id="ops-drill-breadcrumb" class="ops-breadcrumb"></div>'
      + '<div id="ops-drill-body"></div>';
    document.getElementById('ops-drill-load').addEventListener('click', function () {
      _state.drill.from = document.getElementById('ops-drill-from').value;
      _state.drill.to = document.getElementById('ops-drill-to').value;
      _state.drill.date = '';
      _state.drill.item = '';
      _state.drill.orderId = '';
      fetchDrill();
    });
    fetchDrill();
  }

  function fetchDrill() {
    var body = document.getElementById('ops-drill-body');
    if (!body) return;
    loading(body);
    var params = { from: _state.drill.from, to: _state.drill.to };
    if (_state.drill.date) params.date = _state.drill.date;
    if (_state.drill.item) params.item = _state.drill.item;
    if (_state.drill.orderId) params.order_id = _state.drill.orderId;
    AdminApi.getAdminOps('sales_drilldown', params).then(function (data) {
      renderDrill(data);
    }).catch(function (err) {
      empty(body, err.message);
    });
  }

  function renderDrill(data) {
    var bc = document.getElementById('ops-drill-breadcrumb');
    var body = document.getElementById('ops-drill-body');
    if (!body) return;
    var crumbs = '<button class="btn btn-sm btn-outline" data-drill-back="days">日別</button>';
    if (_state.drill.date) crumbs += '<button class="btn btn-sm btn-outline" data-drill-back="items">' + esc(_state.drill.date) + '</button>';
    if (_state.drill.item) crumbs += '<button class="btn btn-sm btn-outline" data-drill-back="orders">' + esc(_state.drill.item) + '</button>';
    if (_state.drill.orderId) crumbs += '<span>' + esc(_state.drill.orderId) + '</span>';
    bc.innerHTML = crumbs;
    bc.onclick = function (e) {
      var b = e.target.closest('[data-drill-back]');
      if (!b) return;
      var target = b.getAttribute('data-drill-back');
      if (target === 'days') { _state.drill.date = ''; _state.drill.item = ''; _state.drill.orderId = ''; }
      else if (target === 'items') { _state.drill.item = ''; _state.drill.orderId = ''; }
      else if (target === 'orders') { _state.drill.orderId = ''; }
      fetchDrill();
    };
    if (data.mode === 'days') renderDrillDays(body, data.days || []);
    else if (data.mode === 'items') renderDrillItems(body, data.items || []);
    else if (data.mode === 'orders') renderDrillOrders(body, data.orders || []);
    else if (data.mode === 'order') renderDrillOrder(body, data.order || {}, data.payments || []);
  }

  function renderDrillDays(body, rows) {
    var html = '<div class="ops-card"><h4>日別売上</h4><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>日付</th><th>注文数</th><th>売上</th></tr></thead><tbody>';
    for (var i = 0; i < rows.length; i++) {
      html += '<tr data-drill-date="' + esc(rows[i].sales_date) + '"><td><button class="ops-link-btn">' + esc(rows[i].sales_date) + '</button></td><td>' + esc(rows[i].order_count) + '</td><td>' + yen(rows[i].revenue) + '</td></tr>';
    }
    html += '</tbody></table></div></div>';
    body.innerHTML = html;
    body.onclick = function (e) {
      var tr = e.target.closest('[data-drill-date]');
      if (!tr) return;
      _state.drill.date = tr.getAttribute('data-drill-date');
      _state.drill.item = '';
      _state.drill.orderId = '';
      fetchDrill();
    };
  }

  function renderDrillItems(body, rows) {
    var html = '<div class="ops-card"><h4>商品別売上</h4><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>商品</th><th>数量</th><th>注文</th><th>売上</th></tr></thead><tbody>';
    for (var i = 0; i < rows.length; i++) {
      html += '<tr data-drill-item="' + esc(rows[i].name) + '"><td><button class="ops-link-btn">' + esc(rows[i].name) + '</button></td><td>' + esc(rows[i].qty) + '</td><td>' + esc(rows[i].orderCount) + '</td><td>' + yen(rows[i].revenue) + '</td></tr>';
    }
    html += '</tbody></table></div></div>';
    body.innerHTML = html;
    body.onclick = function (e) {
      var tr = e.target.closest('[data-drill-item]');
      if (!tr) return;
      _state.drill.item = tr.getAttribute('data-drill-item');
      _state.drill.orderId = '';
      fetchDrill();
    };
  }

  function renderDrillOrders(body, rows) {
    var html = '<div class="ops-card"><h4>注文一覧</h4><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>注文ID</th><th>状態</th><th>金額</th><th>時刻</th></tr></thead><tbody>';
    for (var i = 0; i < rows.length; i++) {
      html += '<tr data-drill-order="' + esc(rows[i].id) + '"><td><button class="ops-link-btn">' + esc(rows[i].id) + '</button></td><td>' + esc(rows[i].status) + '</td><td>' + yen(rows[i].total_amount) + '</td><td>' + esc(rows[i].created_at) + '</td></tr>';
    }
    html += '</tbody></table></div></div>';
    body.innerHTML = html;
    body.onclick = function (e) {
      var tr = e.target.closest('[data-drill-order]');
      if (!tr) return;
      _state.drill.orderId = tr.getAttribute('data-drill-order');
      fetchDrill();
    };
  }

  function renderDrillOrder(body, order, payments) {
    var html = '<div class="ops-two-col"><div class="ops-card"><h4>注文詳細</h4>'
      + '<p><strong>' + esc(order.id || '-') + '</strong></p>'
      + '<p>状態 ' + esc(order.status || '-') + ' / 金額 ' + yen(order.total_amount) + '</p>'
      + '<div class="ops-list">';
    var items = order.items || [];
    for (var i = 0; i < items.length; i++) {
      html += '<div class="ops-list-row"><strong>' + esc(items[i].name || '-') + '</strong><span>' + esc(items[i].qty || 1) + '個 / ' + yen(items[i].price || 0) + '</span></div>';
    }
    html += '</div></div><div class="ops-card"><h4>会計履歴</h4><div class="ops-list">';
    for (var j = 0; j < payments.length; j++) {
      html += '<div class="ops-list-row"><strong>' + yen(payments[j].total_amount) + '</strong><span>' + esc(payments[j].payment_method || '-') + ' / ' + esc(payments[j].paid_at || '-') + ' / 返金 ' + esc(payments[j].refund_status || 'none') + '</span></div>';
    }
    if (payments.length === 0) html += '<p class="ops-muted">会計履歴なし</p>';
    html += '</div></div></div>';
    body.innerHTML = html;
  }

  function loadSetup() {
    var el = _containers.setup;
    if (!requireStore(el)) return;
    loading(el);
    AdminApi.getAdminOps('setup', {}).then(function (data) {
      var html = '<div class="ops-head"><div><h3>初期設定チェックリスト</h3><p>本番運用に必要な店舗、メニュー、卓、スタッフ、KDS、QR、予約、テイクアウトを確認します。</p></div>'
        + '<button class="btn btn-sm btn-outline" data-ops-refresh="setup">更新</button></div><div class="ops-check-grid">';
      var items = data.items || [];
      for (var i = 0; i < items.length; i++) {
        html += '<div class="ops-check ' + (items[i].done ? 'ops-check--done' : 'ops-check--todo') + '">'
          + '<span class="ops-check__mark">' + (items[i].done ? '✓' : '!') + '</span>'
          + '<strong>' + esc(items[i].label) + '</strong><small>' + esc(items[i].detail || '') + '</small></div>';
      }
      html += '</div>';
      el.innerHTML = html;
    }).catch(function (err) { empty(el, err.message); });
  }

  function loadTerminals() {
    var el = _containers.terminals;
    if (!requireStore(el)) return;
    loading(el);
    AdminApi.getAdminOps('terminals', {}).then(function (data) {
      var html = '<div class="ops-head"><div><h3>端末稼働状況</h3><p>KDS、ハンディ、セルフメニュー、レジの最終通信時刻を確認します。</p></div>'
        + '<button class="btn btn-sm btn-outline" data-ops-refresh="terminals">更新</button></div>';
      html += '<div class="ops-kpi-grid ops-kpi-grid--compact">';
      var summary = data.summary || [];
      for (var i = 0; i < summary.length; i++) {
        html += '<div class="ops-kpi"><span class="ops-kpi__label">' + esc(summary[i].label) + '</span>'
          + '<strong class="ops-kpi__value">' + esc(summary[i].count || 0) + '</strong>'
          + '<span class="ops-kpi__sub">' + relativeTime(summary[i].lastSeenAt) + '</span></div>';
      }
      html += '</div><div class="ops-card"><h4>端末一覧</h4><div class="ops-list">';
      var terms = data.terminals || [];
      for (var j = 0; j < terms.length; j++) {
        html += '<div class="ops-list-row"><strong>' + esc(terms[j].label || '-') + '</strong><span>' + esc(terms[j].type) + ' / ' + relativeTime(terms[j].lastSeenAt) + ' / ' + esc(terms[j].status) + '</span></div>';
      }
      if (terms.length === 0) html += '<p class="ops-muted">端末通信記録はまだありません。</p>';
      html += '</div></div>';
      el.innerHTML = html;
    }).catch(function (err) { empty(el, err.message); });
  }

  function loadCart() {
    var el = _containers.cart;
    if (!requireStore(el)) return;
    var r = monthRange();
    _state.cart.from = _state.cart.from || r.from;
    _state.cart.to = _state.cart.to || r.to;
    el.innerHTML = '<div class="ops-head"><div><h3>カート離脱・キャンセル分析</h3><p>一度カートに入れて外された商品と、注文内キャンセルを確認します。</p></div></div>'
      + '<div class="report-date-range"><input type="date" id="ops-cart-from" value="' + esc(_state.cart.from) + '">'
      + '<span class="report-date-range__sep">〜</span><input type="date" id="ops-cart-to" value="' + esc(_state.cart.to) + '">'
      + '<button class="btn btn-sm btn-primary" id="ops-cart-load">表示</button></div><div id="ops-cart-body"></div>';
    document.getElementById('ops-cart-load').addEventListener('click', function () {
      _state.cart.from = document.getElementById('ops-cart-from').value;
      _state.cart.to = document.getElementById('ops-cart-to').value;
      fetchCart();
    });
    fetchCart();
  }

  function fetchCart() {
    var body = document.getElementById('ops-cart-body');
    loading(body);
    AdminApi.getAdminOps('cart', { from: _state.cart.from, to: _state.cart.to }).then(function (data) {
      var s = data.summary || {};
      var html = '<div class="report-summary">'
        + '<div class="summary-card"><div class="summary-card__label">カート投入</div><div class="summary-card__value">' + esc(s.addCount || 0) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">カート削除</div><div class="summary-card__value">' + esc(s.removeCount || 0) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">対象商品</div><div class="summary-card__value">' + esc(s.items || 0) + '</div></div>'
        + '</div><div class="ops-card"><h4>カートから外された商品</h4><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>商品</th><th>投入</th><th>削除</th><th>削除率</th><th>最終</th></tr></thead><tbody>';
      var items = data.items || [];
      for (var i = 0; i < items.length; i++) {
        html += '<tr><td>' + esc(items[i].item_name) + '</td><td>' + esc(items[i].add_count) + '</td><td>' + esc(items[i].remove_count) + '</td><td>' + esc(items[i].remove_rate) + '%</td><td>' + esc(items[i].last_seen_at || '-') + '</td></tr>';
      }
      html += '</tbody></table></div></div>';
      var removed = data.removedFromOrders || [];
      html += '<div class="ops-card"><h4>注文履歴に残るキャンセル商品</h4><div class="ops-list">';
      for (var j = 0; j < removed.length; j++) {
        html += '<div class="ops-list-row"><strong>' + esc(removed[j].name) + '</strong><span>' + esc(removed[j].qty) + '個 / 注文 ' + esc(removed[j].orderId) + ' / ' + esc(removed[j].createdAt) + '</span></div>';
      }
      if (removed.length === 0) html += '<p class="ops-muted">注文内キャンセルの記録はありません。</p>';
      html += '</div></div>';
      body.innerHTML = html;
    }).catch(function (err) { empty(body, err.message); });
  }

  function bindRefresh(container) {
    if (!container || container.__opsRefreshBound) return;
    container.__opsRefreshBound = true;
    container.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-ops-refresh]');
      if (!btn) return;
      var key = btn.getAttribute('data-ops-refresh');
      if (key === 'cockpit') loadCockpit();
      else if (key === 'setup') loadSetup();
      else if (key === 'terminals') loadTerminals();
    });
  }

  function load(kind) {
    if (kind === 'cockpit') { bindRefresh(_containers.cockpit); loadCockpit(); }
    else if (kind === 'search') loadSearch();
    else if (kind === 'sales') loadSalesDrilldown();
    else if (kind === 'setup') { bindRefresh(_containers.setup); loadSetup(); }
    else if (kind === 'terminals') { bindRefresh(_containers.terminals); loadTerminals(); }
    else if (kind === 'cart') loadCart();
  }

  return {
    init: init,
    load: load,
    loadCockpit: loadCockpit,
    loadSearch: loadSearch,
    loadSalesDrilldown: loadSalesDrilldown,
    loadSetup: loadSetup,
    loadTerminals: loadTerminals,
    loadCart: loadCart
  };
})();
