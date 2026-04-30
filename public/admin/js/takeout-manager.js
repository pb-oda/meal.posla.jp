/**
 * テイクアウト注文管理（L-1 / P1-65）
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
      + '<option value="cancelled"' + (_currentFilter === 'cancelled' ? ' selected' : '') + '>キャンセル</option>'
      + '</select>'
      + '<span style="font-size:0.75rem;color:#888;" id="to-mgmt-refresh-info">30秒ごとに自動更新</span>'
      + '</div>'
      + '<div id="to-mgmt-summary" style="margin-bottom:0.75rem;"></div>'
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
        _renderSummary(data.summary || {});
        _renderOrders(data.orders || []);
      })
      .catch(function (err) {
        listEl.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
      });
  }

  function _renderSummary(summary) {
    var el = document.getElementById('to-mgmt-summary');
    if (!el) return;
    var items = [
      ['本日', summary.total || 0, '#455A64'],
      ['遅れ注意', summary.sla_risk || 0, '#EF6C00'],
      ['遅延中', summary.sla_late || 0, '#C62828'],
      ['到着済み', summary.arrived_waiting || 0, '#00796B'],
      ['梱包未完了', summary.packing_incomplete || 0, '#6A1B9A'],
      ['通知失敗', summary.notify_failed || 0, '#C62828'],
      ['返金対応', summary.refund_attention || 0, '#1565C0']
    ];
    var html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:0.5rem;">';
    for (var i = 0; i < items.length; i++) {
      html += '<div style="border:1px solid #e0e0e0;border-radius:6px;padding:0.5rem 0.6rem;background:#fff;">'
        + '<div style="font-size:0.7rem;color:#666;">' + Utils.escapeHtml(items[i][0]) + '</div>'
        + '<div style="font-size:1.2rem;font-weight:700;color:' + items[i][2] + ';">' + items[i][1] + '</div>'
        + '</div>';
    }
    html += '</div>';
    el.innerHTML = html;
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
      var borderColor = _slaBorder(o.sla);

      html += '<div class="card" data-order-card="' + Utils.escapeHtml(o.order_id) + '" style="margin-bottom:0.75rem;padding:1rem;border-left:5px solid ' + borderColor + ';">'
        + '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.5rem;gap:0.75rem;">'
        + '<div>'
        + '<span style="font-size:1.3rem;font-weight:700;color:#2196F3;">' + Utils.escapeHtml(pickupTime) + '</span>'
        + ' ' + statusBadge + ' ' + _slaBadge(o.sla)
        + '</div>'
        + '<span style="font-size:0.7rem;color:#999;">#' + Utils.escapeHtml(o.order_id.substring(0, 8).toUpperCase()) + '</span>'
        + '</div>'
        + '<div style="margin-bottom:0.5rem;">'
        + '<strong>' + Utils.escapeHtml(o.customer_name || '') + '</strong>'
        + ' <span style="color:#666;font-size:0.85rem;">' + Utils.escapeHtml(o.customer_phone || '') + '</span>'
        + '</div>'
        + '<div style="font-size:0.85rem;color:#555;margin-bottom:0.5rem;">' + Utils.escapeHtml(o.item_summary || '') + '</div>'
        + '<div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">'
        + '<span style="font-weight:600;">&yen;' + (o.total_amount || 0).toLocaleString() + '</span>'
        + '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:flex-end;">' + actionBtns + '</div>'
        + '</div>'
        + _renderArrival(o)
        + _renderPacking(o)
        + _renderOps(o)
        + _renderNotification(o);

      if (o.memo) {
        html += '<div style="margin-top:0.5rem;font-size:0.8rem;color:#666;background:#f5f5f5;padding:0.35rem 0.5rem;border-radius:4px;">'
          + Utils.escapeHtml(o.memo) + '</div>';
      }

      html += '</div>';
    }

    listEl.innerHTML = html;
    listEl.onclick = function (e) {
      _handleListClick(e);
    };
    listEl.onchange = function (e) {
      _handleListChange(e);
    };
  }

  function _statusBadge(status) {
    var map = {
      'pending': ['受付済み', '#FF9800', '#fff'],
      'pending_payment': ['決済待ち', '#9E9E9E', '#fff'],
      'preparing': ['調理中', '#2196F3', '#fff'],
      'ready': ['準備完了', '#4CAF50', '#fff'],
      'served': ['受取完了', '#E0E0E0', '#666'],
      'paid': ['会計済', '#E0E0E0', '#666'],
      'cancelled': ['キャンセル', '#F44336', '#fff']
    };
    var m = map[status] || [status, '#999', '#fff'];
    var style = 'background:' + m[1] + ';color:' + m[2] + ';padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;';
    if (status === 'ready') style += 'animation:to-mgmt-pulse 1.5s infinite;';
    return '<span style="' + style + '">' + Utils.escapeHtml(m[0]) + '</span>';
  }

  function _slaBadge(sla) {
    if (!sla) return '';
    var map = {
      ok: ['SLA OK', '#ECEFF1', '#455A64'],
      ready: ['SLA OK', '#E8F5E9', '#2E7D32'],
      risk: [sla.label || '遅れ注意', '#FFF3E0', '#EF6C00'],
      late: [sla.label || '遅延中', '#FFEBEE', '#C62828'],
      late_resolved: [sla.label || '遅延済', '#FCE4EC', '#AD1457'],
      cancelled: ['キャンセル', '#F5F5F5', '#777'],
      unknown: ['時刻未設定', '#F5F5F5', '#777']
    };
    var m = map[sla.state] || [sla.label || sla.state, '#F5F5F5', '#777'];
    return '<span style="background:' + m[1] + ';color:' + m[2] + ';padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;">'
      + Utils.escapeHtml(m[0]) + '</span>';
  }

  function _slaBorder(sla) {
    if (!sla) return '#e0e0e0';
    if (sla.state === 'late') return '#C62828';
    if (sla.state === 'risk') return '#EF6C00';
    if (sla.state === 'late_resolved') return '#AD1457';
    if (sla.state === 'ready') return '#4CAF50';
    return '#e0e0e0';
  }

  function _actionButtons(orderId, status) {
    var btns = '';
    if (status === 'pending') {
      btns += '<button class="btn btn-primary btn-sm" data-to-order-id="' + Utils.escapeHtml(orderId) + '" data-to-action="preparing">調理開始</button>';
    } else if (status === 'preparing') {
      btns += '<button class="btn btn-sm" style="background:#4CAF50;color:#fff;border:none;" data-to-order-id="' + Utils.escapeHtml(orderId) + '" data-to-action="ready">準備完了・通知</button>';
    } else if (status === 'ready') {
      btns += '<button class="btn btn-sm" style="background:#1976D2;color:#fff;border:none;" data-to-order-id="' + Utils.escapeHtml(orderId) + '" data-to-action="notify_ready">再通知</button>';
      btns += '<button class="btn btn-sm" style="background:#FF9800;color:#fff;border:none;" data-to-order-id="' + Utils.escapeHtml(orderId) + '" data-to-action="served">受取完了</button>';
    }
    return btns;
  }

  function _renderArrival(o) {
    if (!o.arrived_at) return '';
    var typeLabel = o.arrival_type === 'curbside' ? '車で待機' : '店頭到着';
    var timeLabel = String(o.arrived_at || '').substring(11, 16);
    var note = o.arrival_note ? ' / ' + o.arrival_note : '';
    var bg = o.arrival_waiting ? '#E0F2F1' : '#F5F5F5';
    var color = o.arrival_waiting ? '#00695C' : '#666';
    return '<div style="margin-top:0.65rem;padding:0.5rem 0.65rem;background:' + bg + ';border:1px solid #B2DFDB;border-radius:6px;color:' + color + ';font-size:0.8rem;font-weight:600;">'
      + '到着連絡: ' + Utils.escapeHtml(typeLabel) + ' ' + Utils.escapeHtml(timeLabel) + Utils.escapeHtml(note)
      + '</div>';
  }

  function _renderPacking(o) {
    var c = o.pack_checklist || {};
    var keys = [
      ['items', '商品'],
      ['chopsticks', '箸'],
      ['bag', '袋'],
      ['sauce', 'ソース'],
      ['receipt', '領収書']
    ];
    var html = '<div style="margin-top:0.75rem;padding:0.6rem;background:#FAFAFA;border-radius:6px;">'
      + '<div style="font-size:0.75rem;font-weight:600;color:#555;margin-bottom:0.4rem;">梱包チェック</div>'
      + '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;">';
    for (var i = 0; i < keys.length; i++) {
      html += '<label style="font-size:0.78rem;color:#333;display:flex;gap:0.25rem;align-items:center;">'
        + '<input type="checkbox" data-pack-key="' + keys[i][0] + '"' + (c[keys[i][0]] ? ' checked' : '') + '> '
        + Utils.escapeHtml(keys[i][1]) + '</label>';
    }
    html += '</div>';
    if (o.pack_complete) {
      html += '<div style="font-size:0.72rem;color:#2E7D32;margin-top:0.35rem;">梱包完了 ' + Utils.escapeHtml((o.pack_checked_at || '').substring(11, 16)) + '</div>';
    }
    html += '</div>';
    return html;
  }

  function _renderOps(o) {
    var statuses = [
      ['normal', '通常'],
      ['late_risk', '遅れそう'],
      ['late', '遅延中'],
      ['customer_delayed', '受取遅れ'],
      ['cancel_requested', 'キャンセル連絡'],
      ['cancelled', 'キャンセル済'],
      ['refund_pending', '返金待ち'],
      ['refunded', '返金済み']
    ];
    var html = '<div style="margin-top:0.75rem;display:grid;grid-template-columns:minmax(120px,180px) 1fr auto;gap:0.5rem;align-items:center;">';
    html += '<select class="form-input" data-ops-status style="min-width:0;">';
    for (var i = 0; i < statuses.length; i++) {
      html += '<option value="' + statuses[i][0] + '"' + (o.ops_status === statuses[i][0] ? ' selected' : '') + '>' + statuses[i][1] + '</option>';
    }
    html += '</select>';
    html += '<input class="form-input" data-ops-note maxlength="255" placeholder="運用メモ" value="' + Utils.escapeHtml(o.ops_note || '') + '" style="min-width:0;">';
    html += '<button class="btn btn-sm" data-ops-save="' + Utils.escapeHtml(o.order_id) + '">保存</button>';
    html += '</div>';
    return html;
  }

  function _renderNotification(o) {
    var status = o.ready_notification_status || 'not_requested';
    var map = {
      not_requested: ['通知未送信', '#777'],
      sent: ['通知送信済', '#2E7D32'],
      failed: ['通知失敗', '#C62828'],
      skipped: ['通知スキップ', '#EF6C00']
    };
    var m = map[status] || [status, '#777'];
    var text = m[0];
    if (o.ready_notified_at && status === 'sent') {
      text += ' ' + o.ready_notified_at.substring(11, 16);
    }
    if (o.ready_notification_error && status !== 'sent') {
      text += ' / ' + o.ready_notification_error;
    }
    return '<div style="margin-top:0.45rem;font-size:0.72rem;color:' + m[1] + ';">' + Utils.escapeHtml(text) + '</div>';
  }

  function _handleListClick(e) {
    var btn = e.target.closest('[data-to-action]');
    if (btn) {
      var orderId = btn.dataset.toOrderId;
      var action = btn.dataset.toAction;
      if (!orderId || !action) return;
      btn.disabled = true;
      if (action === 'notify_ready') {
        _notifyReady(orderId);
      } else {
        _updateStatus(orderId, action);
      }
      return;
    }

    var save = e.target.closest('[data-ops-save]');
    if (save) {
      _saveOps(save.dataset.opsSave, save);
    }
  }

  function _handleListChange(e) {
    var cb = e.target.closest('[data-pack-key]');
    if (cb) {
      _savePacking(cb);
    }
  }

  function _savePacking(changed) {
    var card = changed.closest('[data-order-card]');
    if (!card) return;
    var orderId = card.getAttribute('data-order-card');
    var checklist = {};
    var inputs = card.querySelectorAll('[data-pack-key]');
    for (var i = 0; i < inputs.length; i++) {
      checklist[inputs[i].dataset.packKey] = inputs[i].checked ? 1 : 0;
    }
    AdminApi.patchTakeoutOrder(orderId, { action: 'pack', checklist: checklist })
      .then(function () { _fetchOrders(); })
      .catch(function (err) {
        showToast(err.message || '梱包チェックの保存に失敗しました', 'error');
        _fetchOrders();
      });
  }

  function _saveOps(orderId, btn) {
    var card = btn.closest('[data-order-card]');
    if (!card || !orderId) return;
    var statusEl = card.querySelector('[data-ops-status]');
    var noteEl = card.querySelector('[data-ops-note]');
    btn.disabled = true;
    AdminApi.patchTakeoutOrder(orderId, {
      action: 'ops',
      ops_status: statusEl ? statusEl.value : 'normal',
      ops_note: noteEl ? noteEl.value : ''
    }).then(function () {
      showToast('運用ステータスを保存しました', 'success');
      _fetchOrders();
    }).catch(function (err) {
      showToast(err.message || '保存に失敗しました', 'error');
      _fetchOrders();
    });
  }

  function _updateStatus(orderId, newStatus) {
    AdminApi.patchTakeoutOrder(orderId, newStatus)
      .then(function (data) {
        if (data.note) showToast(data.note, data.notification && data.notification.status === 'failed' ? 'warning' : 'success');
        else showToast('ステータスを更新しました', 'success');
        _fetchOrders();
      })
      .catch(function (err) {
        showToast(err.message || 'エラー', 'error');
        _fetchOrders();
      });
  }

  function _notifyReady(orderId) {
    AdminApi.patchTakeoutOrder(orderId, { action: 'notify_ready' })
      .then(function (data) {
        if (data.notification && data.notification.status === 'sent') {
          showToast('準備完了通知を送信しました', 'success');
        } else if (data.notification && data.notification.status === 'skipped') {
          showToast('LINE未連携のため通知はスキップされました', 'warning');
        } else {
          showToast('通知送信に失敗しました', 'warning');
        }
        _fetchOrders();
      })
      .catch(function (err) {
        showToast(err.message || '通知に失敗しました', 'error');
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
