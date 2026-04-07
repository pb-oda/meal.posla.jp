/**
 * テイクアウト注文管理（L-1）
 */
var TakeoutManager = (function () {
  'use strict';

  var _container = null;
  var _refreshTimer = null;
  var _currentDate = '';
  var _currentFilter = 'all';

  function init(container) {
    _container = container;
  }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _currentDate = _todayStr();
    _render();
    _fetchOrders();
    _startAutoRefresh();
  }

  function stop() {
    if (_refreshTimer) { clearInterval(_refreshTimer); _refreshTimer = null; }
  }

  function _todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }

  function _render() {
    _container.innerHTML = ''
      + '<div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">'
      + '<input type="date" class="form-input" id="to-mgmt-date" value="' + _currentDate + '" style="width:auto;">'
      + '<select class="form-input" id="to-mgmt-filter" style="width:auto;">'
      + '<option value="all"' + (_currentFilter === 'all' ? ' selected' : '') + '>全件</option>'
      + '<option value="pending"' + (_currentFilter === 'pending' ? ' selected' : '') + '>受付済み</option>'
      + '<option value="pending_payment"' + (_currentFilter === 'pending_payment' ? ' selected' : '') + '>決済待ち</option>'
      + '<option value="preparing"' + (_currentFilter === 'preparing' ? ' selected' : '') + '>調理中</option>'
      + '<option value="ready"' + (_currentFilter === 'ready' ? ' selected' : '') + '>準備完了</option>'
      + '<option value="served"' + (_currentFilter === 'served' ? ' selected' : '') + '>受取完了</option>'
      + '</select>'
      + '<span style="font-size:0.75rem;color:#888;" id="to-mgmt-refresh-info">30秒ごとに自動更新</span>'
      + '</div>'
      + '<div id="to-mgmt-list"><div class="loading-overlay"><span class="loading-spinner"></span></div></div>';

    document.getElementById('to-mgmt-date').addEventListener('change', function () {
      _currentDate = this.value;
      _fetchOrders();
    });
    document.getElementById('to-mgmt-filter').addEventListener('change', function () {
      _currentFilter = this.value;
      _fetchOrders();
    });
  }

  function _fetchOrders() {
    var listEl = document.getElementById('to-mgmt-list');
    if (!listEl) return;

    AdminApi.getTakeoutOrders(_currentDate, _currentFilter)
      .then(function (data) {
        _renderOrders(data.orders || []);
      })
      .catch(function (err) {
        listEl.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
      });
  }

  function _renderOrders(orders) {
    var listEl = document.getElementById('to-mgmt-list');
    if (!listEl) return;

    if (!orders.length) {
      listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">テイクアウト注文がありません</p></div>';
      return;
    }

    var html = '';
    for (var i = 0; i < orders.length; i++) {
      var o = orders[i];
      var pickupTime = o.pickup_at ? o.pickup_at.substring(11, 16) : '---';
      var statusBadge = _statusBadge(o.status);
      var actionBtns = _actionButtons(o.order_id, o.status);

      html += '<div class="card" style="margin-bottom:0.75rem;padding:1rem;">'
        + '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.5rem;">'
        + '<div>'
        + '<span style="font-size:1.3rem;font-weight:700;color:#2196F3;">' + Utils.escapeHtml(pickupTime) + '</span>'
        + ' ' + statusBadge
        + '</div>'
        + '<span style="font-size:0.7rem;color:#999;">#' + Utils.escapeHtml(o.order_id.substring(0, 8).toUpperCase()) + '</span>'
        + '</div>'
        + '<div style="margin-bottom:0.5rem;">'
        + '<strong>' + Utils.escapeHtml(o.customer_name || '') + '</strong>'
        + ' <span style="color:#666;font-size:0.85rem;">' + Utils.escapeHtml(o.customer_phone || '') + '</span>'
        + '</div>'
        + '<div style="font-size:0.85rem;color:#555;margin-bottom:0.5rem;">' + Utils.escapeHtml(o.item_summary || '') + '</div>'
        + '<div style="display:flex;justify-content:space-between;align-items:center;">'
        + '<span style="font-weight:600;">&yen;' + (o.total_amount || 0).toLocaleString() + '</span>'
        + '<div style="display:flex;gap:0.5rem;">' + actionBtns + '</div>'
        + '</div>';

      if (o.memo) {
        html += '<div style="margin-top:0.5rem;font-size:0.8rem;color:#888;background:#f5f5f5;padding:0.35rem 0.5rem;border-radius:4px;">'
          + Utils.escapeHtml(o.memo) + '</div>';
      }

      html += '</div>';
    }

    listEl.innerHTML = html;

    // イベント委譲
    listEl.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-to-action]');
      if (!btn) return;
      var orderId = btn.dataset.toOrderId;
      var newStatus = btn.dataset.toAction;
      if (!orderId || !newStatus) return;
      btn.disabled = true;
      _updateStatus(orderId, newStatus);
    });
  }

  function _statusBadge(status) {
    var map = {
      'pending': ['受付済み', '#FF9800', '#fff'],
      'pending_payment': ['決済待ち', '#9E9E9E', '#fff'],
      'preparing': ['調理中', '#2196F3', '#fff'],
      'ready': ['準備完了', '#4CAF50', '#fff'],
      'served': ['受取完了', '#E0E0E0', '#666'],
      'paid': ['会計済', '#E0E0E0', '#666'],
      'cancelled': ['キャンセル', '#F44336', '#fff'],
    };
    var m = map[status] || [status, '#999', '#fff'];
    var style = 'background:' + m[1] + ';color:' + m[2] + ';padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;';
    if (status === 'ready') style += 'animation:to-mgmt-pulse 1.5s infinite;';
    return '<span style="' + style + '">' + Utils.escapeHtml(m[0]) + '</span>';
  }

  function _actionButtons(orderId, status) {
    var btns = '';
    if (status === 'pending') {
      btns += '<button class="btn btn-primary btn-sm" data-to-order-id="' + orderId + '" data-to-action="preparing">調理開始</button>';
    } else if (status === 'preparing') {
      btns += '<button class="btn btn-sm" style="background:#4CAF50;color:#fff;border:none;" data-to-order-id="' + orderId + '" data-to-action="ready">準備完了</button>';
    } else if (status === 'ready') {
      btns += '<button class="btn btn-sm" style="background:#FF9800;color:#fff;border:none;" data-to-order-id="' + orderId + '" data-to-action="served">受取完了</button>';
    }
    return btns;
  }

  function _updateStatus(orderId, newStatus) {
    AdminApi.patchTakeoutOrder(orderId, newStatus)
      .then(function (data) {
        if (data.note) showToast(data.note, 'warning');
        else showToast('ステータスを更新しました', 'success');
        _fetchOrders();
      })
      .catch(function (err) {
        showToast(err.message || 'エラー', 'error');
        _fetchOrders();
      });
  }

  function _startAutoRefresh() {
    stop();
    _refreshTimer = setInterval(function () {
      _fetchOrders();
    }, 30000);
  }

  return { init: init, load: load, stop: stop };
})();
