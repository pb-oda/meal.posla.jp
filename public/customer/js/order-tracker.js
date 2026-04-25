/**
 * 注文追跡モジュール (N-2 / N-4)
 *
 * セルフオーダー画面で注文状況をポーリング・表示する。
 * 品目が「提供済み」に変化した瞬間をコールバックで通知。
 */
var OrderTracker = (function () {
  'use strict';

  var _baseApi = '';
  var _storeId = '';
  var _tableId = '';
  var _sessionToken = '';
  var _interval = null;
  var _pollMs = 5000;
  var _previousStatuses = {};
  var _ratedItemIds = {};
  var _onServed = null;
  var _onSessionInvalid = null;

  function init(config) {
    _baseApi = config.baseApi;
    _storeId = config.storeId;
    _tableId = config.tableId;
    _sessionToken = config.sessionToken;
    _onServed = config.onServed || null;
    _onSessionInvalid = config.onSessionInvalid || null;
  }

  function start() {
    if (_interval) return;
    poll();
    _interval = setInterval(poll, _pollMs);
  }

  function stop() {
    if (_interval) { clearInterval(_interval); _interval = null; }
  }

  function poll() {
    if (!_sessionToken) return;
    var url = _baseApi + '/customer/order-status.php?store_id=' + encodeURIComponent(_storeId)
      + '&table_id=' + encodeURIComponent(_tableId)
      + '&session_token=' + encodeURIComponent(_sessionToken);

    fetch(url)
      .then(function (r) {
        var status = r.status;
        return r.text().then(function (text) { return { status: status, text: text }; });
      })
      .then(function (result) {
        var json;
        try { json = JSON.parse(result.text); } catch (e) { return; }

        // 会計後トークン無効を検知 → 即座にセッション終了
        if (result.status === 403 || (json && json.error && json.error.code === 'INVALID_SESSION')) {
          stop();
          if (_onSessionInvalid) _onSessionInvalid();
          return;
        }

        if (!json || !json.ok) return;
        var data = json.data;

        var ratedIds = data.rated_item_ids || [];
        for (var ri = 0; ri < ratedIds.length; ri++) {
          _ratedItemIds[ratedIds[ri]] = true;
        }

        renderOrderStatus(data.orders || []);

        var orders = data.orders || [];
        for (var oi = 0; oi < orders.length; oi++) {
          var items = orders[oi].items || [];
          for (var ii = 0; ii < items.length; ii++) {
            var item = items[ii];
            var prev = _previousStatuses[item.item_id];
            if (prev !== 'served' && item.status === 'served' && !_ratedItemIds[item.item_id]) {
              if (_onServed) _onServed(orders[oi], item);
            }
            _previousStatuses[item.item_id] = item.status;
          }
        }
      })
      .catch(function () { /* silent */ });
  }

  function renderOrderStatus(orders) {
    var container = document.getElementById('order-status-section');
    if (!container) return;

    var activeOrders = [];
    for (var i = 0; i < orders.length; i++) {
      if (orders[i].status !== 'paid') activeOrders.push(orders[i]);
    }

    if (activeOrders.length === 0) { container.style.display = 'none'; return; }

    container.style.display = '';
    var html = '';
    var lang = document.documentElement.lang === 'en' ? 'en' : 'ja';
    var statusLabels = {
      ja: { pending: '受付済み', preparing: '調理中', ready: 'まもなくお届け', served: 'お届け済み' },
      en: { pending: 'Received', preparing: 'Preparing', ready: 'Almost Ready', served: 'Served' }
    };
    var labels = statusLabels[lang] || statusLabels.ja;

    for (var oi = 0; oi < activeOrders.length; oi++) {
      var order = activeOrders[oi];
      var items = order.items || [];
      if (items.length === 0) continue;

      html += '<div class="order-status-card">';
      html += '<div class="order-status-card__header">';
      html += '<span class="order-status-card__time">' + Utils.escapeHtml((order.created_at || '').substring(11, 16)) + '</span>';
      // SELF-P1-4: 任意ゲスト名 badge (存在時のみ表示、表示専用)
      if (order.guest_alias) {
        html += '<span class="order-status-card__alias">'
             + Utils.escapeHtml(String(order.guest_alias))
             + '</span>';
      }
      var amt = parseInt(order.total_amount, 10) || 0;
      html += '<span class="order-status-card__total">&yen;' + amt.toLocaleString() + '</span>';
      html += '</div>';

      var totalItems = 0;
      var servedItems = 0;
      var readyItems = 0;
      var preparingItems = 0;

      for (var ii = 0; ii < items.length; ii++) {
        var item = items[ii];
        if (item.status === 'cancelled') continue;
        var label = labels[item.status] || item.status;
        var statusClass = 'status-' + item.status;
        html += '<div class="order-status-item">';
        html += '<span class="order-status-item__name">' + Utils.escapeHtml(item.name) + ' x' + item.qty + '</span>';
        html += '<span class="order-status-item__badge ' + statusClass + '">' + Utils.escapeHtml(label) + '</span>';
        html += '</div>';

        totalItems++;
        if (item.status === 'served') servedItems++;
        else if (item.status === 'ready') readyItems++;
        else if (item.status === 'preparing') preparingItems++;
      }

      var progress = totalItems > 0 ? Math.round(((servedItems * 3 + readyItems * 2 + preparingItems) / (totalItems * 3)) * 100) : 0;
      html += '<div class="order-progress-bar"><div class="order-progress-bar__fill" style="width:' + progress + '%"></div></div>';
      html += '</div>';
    }

    container.innerHTML = html;
  }

  function isRated(itemId) { return !!_ratedItemIds[itemId]; }
  function markRated(itemId) { _ratedItemIds[itemId] = true; }

  return { init: init, start: start, stop: stop, isRated: isRated, markRated: markRated };
})();
