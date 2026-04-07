// @deprecated 2026-04-02 — cashier-app.js に統一済み。将来削除予定。
/**
 * KDS会計レンダラー
 * テーブル別合計・会計処理（食べ放題プラン対応）
 *
 * 注文があるテーブル＋アクティブセッションのみのテーブルの両方を表示。
 */
var AccountingRenderer = (function () {
  'use strict';

  var _container = null;
  var _orders = {};
  var _sessions = {}; // tableId → session（プラン情報含む）

  function init(container) {
    _container = container;
  }

  function setSessionData(sessions) {
    _sessions = {};
    (sessions || []).forEach(function (s) {
      _sessions[s.tableId] = s;
    });
  }

  function onData(data) {
    _orders = {};
    (data.orders || []).forEach(function (o) {
      _orders[o.id] = o;
    });
    render();
  }

  function render() {
    // テーブルごとにグループ化（注文ベース）
    var tables = {};
    Object.keys(_orders).forEach(function (id) {
      var o = _orders[id];
      if (o.status === 'paid' || o.status === 'cancelled') return;
      // table_idがnullの場合（テイクアウト等）はorder_idをキーにする
      var groupKey = o.table_id || ('_order_' + o.id);
      if (!tables[groupKey]) {
        tables[groupKey] = {
          tableCode: o.table_code || (o.customer_name || 'テイクアウト'),
          tableId: o.table_id,
          orders: [],
          total: 0,
          orderIds: []
        };
      }
      tables[groupKey].orders.push(o);
      tables[groupKey].orderIds.push(o.id);
      tables[groupKey].total += parseInt(o.total_amount, 10) || 0;
    });

    // アクティブセッション（注文なし）も追加
    Object.keys(_sessions).forEach(function (tableId) {
      if (tables[tableId]) return; // 注文ありなら既に含まれている
      var s = _sessions[tableId];
      tables[tableId] = {
        tableCode: s.tableCode,
        tableId: tableId,
        orders: [],
        total: 0
      };
    });

    var tableList = Object.values(tables).sort(function (a, b) {
      return (a.tableCode || '').localeCompare(b.tableCode || '');
    });

    if (tableList.length === 0) {
      _container.innerHTML = '<div class="kds-empty" style="padding:3rem;text-align:center;color:#999">会計待ちのテーブルはありません</div>';
      return;
    }

    var html = '';
    tableList.forEach(function (t) {
      // プランセッション確認
      var session = _sessions[t.tableId];
      var hasPlan = session && session.planName && session.planPrice;
      var billingTotal = t.total;
      var planHtml = '';

      if (hasPlan) {
        var guests = session.guestCount || 1;
        billingTotal = session.planPrice * guests;
        planHtml = '<div class="acc-plan-info">'
          + '<span class="acc-plan-badge">食べ放題</span> '
          + Utils.escapeHtml(session.planName)
          + ' ¥' + Number(session.planPrice).toLocaleString()
          + ' × ' + guests + '名'
          + '</div>';
      } else if (session) {
        // プランなしセッション（着席のみ）
        planHtml = '<div class="acc-plan-info" style="color:#90caf9">'
          + session.guestCount + '名 着席中'
          + '</div>';
      }

      var itemsHtml = '';
      t.orders.forEach(function (o) {
        (o.items || []).forEach(function (item) {
          itemsHtml += '<tr><td>' + Utils.escapeHtml(item.name) + '</td>'
            + '<td class="text-right">' + item.qty + '</td>'
            + '<td class="text-right">' + Utils.formatYen(item.price * item.qty) + '</td></tr>';
        });
      });

      html += '<div class="acc-table-card">'
        + '<div class="acc-table-card__header">'
        + '<span class="acc-table-card__name">' + Utils.escapeHtml(t.tableCode) + '</span>'
        + '<span class="acc-table-card__total">' + Utils.formatYen(billingTotal) + '</span></div>'
        + planHtml;

      if (itemsHtml) {
        html += '<table class="acc-items-table"><thead><tr><th>品名</th><th class="text-right">数量</th><th class="text-right">小計</th></tr></thead>'
          + '<tbody>' + itemsHtml + '</tbody></table>';
      } else {
        html += '<div style="padding:1rem 1.25rem;color:#999;font-size:0.85rem">注文なし</div>';
      }

      // プランありなら注文合計も参考表示
      if (hasPlan && t.total > 0) {
        html += '<div class="acc-order-ref">注文合計（参考）: ' + Utils.formatYen(t.total) + '</div>';
      }

      html += '<div class="acc-table-card__footer">'
        + '<select class="acc-payment-select" data-table="' + (t.tableId || '') + '">'
        + '<option value="cash">現金</option><option value="card">カード</option><option value="qr">QR決済</option></select>'
        + '<button class="btn-close-table" data-table="' + (t.tableId || '') + '" data-total="' + billingTotal
        + '" data-order-ids="' + (t.orderIds || []).join(',') + '">会計済み</button>'
        + '</div></div>';
    });

    _container.innerHTML = html;
  }

  function handleCloseTable(tableId, storeId, paymentMethod, total, orderIds) {
    var body = {
      store_id: storeId,
      payment_method: paymentMethod,
      received_amount: total
    };
    if (tableId) body.table_id = tableId;
    if (orderIds && orderIds.length > 0) body.order_ids = orderIds;

    return fetch('../../api/kds/close-table.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('サーバーが空のレスポンスを返しました');
        try { return JSON.parse(body); }
        catch (e) { throw new Error('応答エラー: ' + body.substring(0, 200)); }
      });
    });
  }

  return {
    init: init,
    setSessionData: setSessionData,
    onData: onData,
    handleCloseTable: handleCloseTable
  };
})();
