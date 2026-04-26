/**
 * KDS 会計（Cashier）ステーション レンダラー
 *
 * Sprint C-1: 高性能POSレジ
 * - 会計待ち一覧（テーブル別 + テイクアウト）
 * - 本格POSレジモーダル（テンキー、個別会計、税計算、支払方法選択）
 */
var CashierRenderer = (function () {
  'use strict';

  var _container = null;
  var _orders = {};
  var _sessions = {};
  var _storeId = '';

  // モーダル State
  var _modal = {
    open: false,
    tableId: null,
    tableCode: '',
    orderIds: [],
    items: [],        // [{name, qty, price, taxRate, orderId, itemIndex, checked}]
    planInfo: null,
    paymentMethod: 'cash',
    receivedInput: '',
    calc: { subtotal10: 0, tax10: 0, subtotal8: 0, tax8: 0, total: 0 }
  };

  function init(container) {
    _container = container;
    bindModalEvents();
  }

  function setStoreId(storeId) { _storeId = storeId; }

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
    renderList();
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // 会計待ち一覧
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  function renderList() {
    var tables = {};
    Object.keys(_orders).forEach(function (id) {
      var o = _orders[id];
      if (o.status === 'paid' || o.status === 'cancelled') return;
      var groupKey = o.table_id || ('_to_' + o.id);
      if (!tables[groupKey]) {
        tables[groupKey] = {
          tableCode: o.table_code || (o.customer_name || 'テイクアウト'),
          tableId: o.table_id,
          orders: [],
          orderIds: [],
          total: 0,
          isTakeout: !o.table_id
        };
      }
      tables[groupKey].orders.push(o);
      tables[groupKey].orderIds.push(o.id);
      tables[groupKey].total += parseInt(o.total_amount, 10) || 0;
    });

    // セッションのみ（注文なし）も追加
    Object.keys(_sessions).forEach(function (tableId) {
      if (tables[tableId]) return;
      var s = _sessions[tableId];
      tables[tableId] = {
        tableCode: s.tableCode,
        tableId: tableId,
        orders: [],
        orderIds: [],
        total: 0,
        isTakeout: false
      };
    });

    var list = Object.values(tables).sort(function (a, b) {
      if (a.isTakeout !== b.isTakeout) return a.isTakeout ? 1 : -1;
      return (a.tableCode || '').localeCompare(b.tableCode || '');
    });

    if (list.length === 0) {
      _container.innerHTML = '<div class="cashier-empty">会計待ちの注文はありません</div>';
      return;
    }

    var html = '<div class="cashier-grid">';
    list.forEach(function (t) {
      var session = _sessions[t.tableId];
      var hasPlan = session && session.planName && session.planPrice;
      var billingTotal = t.total;
      if (hasPlan) {
        billingTotal = session.planPrice * (session.guestCount || 1);
      }

      var itemCount = 0;
      t.orders.forEach(function (o) { itemCount += (o.items || []).length; });

      var badgeClass = t.isTakeout ? 'cashier-card__badge--to' : '';
      var badgeText = t.isTakeout ? 'TO' : '';
      var planBadge = hasPlan
        ? '<span class="cashier-card__plan">' + Utils.escapeHtml(session.planName) + ' ' + (session.guestCount || 1) + '名</span>'
        : '';

      html += '<div class="cashier-card' + (t.isTakeout ? ' cashier-card--to' : '') + '"'
        + ' data-table-id="' + (t.tableId || '') + '"'
        + ' data-order-ids="' + t.orderIds.join(',') + '">'
        + '<div class="cashier-card__top">'
        + (badgeText ? '<span class="cashier-card__badge ' + badgeClass + '">' + badgeText + '</span>' : '')
        + '<span class="cashier-card__code">' + Utils.escapeHtml(t.tableCode) + '</span>'
        + '</div>'
        + planBadge
        + '<div class="cashier-card__info">'
        + '<span>' + t.orders.length + '注文 / ' + itemCount + '品</span>'
        + '</div>'
        + '<div class="cashier-card__total">' + Utils.formatYen(billingTotal) + '</div>'
        + '<button class="cashier-card__btn">会計</button>'
        + '</div>';
    });
    html += '</div>';
    _container.innerHTML = html;
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // カードクリック → モーダルオープン
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  function handleCardClick(tableId, orderIdsStr) {
    var orderIds = orderIdsStr ? orderIdsStr.split(',') : [];
    var tableOrders = orderIds.map(function (id) { return _orders[id]; }).filter(Boolean);

    if (tableOrders.length === 0 && !_sessions[tableId]) return;

    var tableCode = '';
    if (tableOrders.length > 0) {
      tableCode = tableOrders[0].table_code || tableOrders[0].customer_name || 'テイクアウト';
    } else if (_sessions[tableId]) {
      tableCode = _sessions[tableId].tableCode;
    }

    // アイテム一覧を構築
    var items = [];
    tableOrders.forEach(function (o) {
      (o.items || []).forEach(function (item, idx) {
        // テイクアウト注文は8%、店内は10%
        var taxRate = 10;
        if (item.taxRate) taxRate = item.taxRate;
        else if (o.order_type === 'takeout') taxRate = 8;

        items.push({
          name: item.name,
          qty: item.qty || 1,
          price: item.price || 0,
          taxRate: taxRate,
          orderId: o.id,
          itemIndex: idx,
          options: item.options || [],
          checked: true   // デフォルト全選択
        });
      });
    });

    // プラン
    var session = _sessions[tableId];
    var planInfo = null;
    if (session && session.planName && session.planPrice) {
      planInfo = {
        name: session.planName,
        price: session.planPrice,
        guests: session.guestCount || 1,
        total: session.planPrice * (session.guestCount || 1)
      };
    }

    _modal.open = true;
    _modal.tableId = tableId || null;
    _modal.tableCode = tableCode;
    _modal.orderIds = orderIds;
    _modal.items = items;
    _modal.planInfo = planInfo;
    _modal.paymentMethod = 'cash';
    _modal.receivedInput = '';

    calcSelected();
    renderModal();
    document.getElementById('pos-modal-overlay').classList.add('show');
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // POS レジモーダル
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  function renderModal() {
    var m = _modal;
    var allChecked = m.items.every(function (it) { return it.checked; });
    var someChecked = m.items.some(function (it) { return it.checked; });
    var isPartial = someChecked && !allChecked;

    // ── 左側: 明細 ──
    var leftHtml = '<div class="pos-modal__left">';

    // プラン情報
    if (m.planInfo) {
      leftHtml += '<div class="pos-plan-badge">'
        + '<span class="pos-plan-badge__name">' + Utils.escapeHtml(m.planInfo.name) + '</span>'
        + '<span class="pos-plan-badge__detail">' + Utils.formatYen(m.planInfo.price) + ' x ' + m.planInfo.guests + '名 = ' + Utils.formatYen(m.planInfo.total) + '</span>'
        + '</div>';
    }

    // 全選択チェックボックス
    leftHtml += '<div class="pos-items__header">'
      + '<label class="pos-check-all"><input type="checkbox" id="pos-check-all"'
      + (allChecked ? ' checked' : '') + '> 全選択</label>'
      + (isPartial ? '<span class="pos-partial-badge">個別会計</span>' : '')
      + '</div>';

    // 品目リスト
    leftHtml += '<div class="pos-items__list">';
    m.items.forEach(function (item, i) {
      var lineTotal = item.price * item.qty;
      var optText = '';
      if (item.options && item.options.length > 0) {
        optText = '<span class="pos-item__options">'
          + item.options.map(function (o) { return Utils.escapeHtml(o.choiceName || o.name || ''); }).join(', ')
          + '</span>';
      }
      var taxLabel = item.taxRate === 8 ? '<span class="pos-tax-label pos-tax-label--8">軽減</span>' : '';

      leftHtml += '<label class="pos-item' + (item.checked ? '' : ' pos-item--unchecked') + '">'
        + '<input type="checkbox" class="pos-item__check" data-idx="' + i + '"' + (item.checked ? ' checked' : '') + '>'
        + '<div class="pos-item__detail">'
        + '<div class="pos-item__name">' + Utils.escapeHtml(item.name) + taxLabel + '</div>'
        + optText
        + '</div>'
        + '<span class="pos-item__qty">x' + item.qty + '</span>'
        + '<span class="pos-item__price">' + Utils.formatYen(lineTotal) + '</span>'
        + '</label>';
    });
    leftHtml += '</div>';

    // 税内訳
    leftHtml += '<div class="pos-tax-area">';
    if (m.calc.subtotal10 + m.calc.tax10 > 0) {
      leftHtml += '<div class="pos-tax-row"><span>10%対象</span><span>' + Utils.formatYen(m.calc.subtotal10 + m.calc.tax10) + '</span></div>';
      leftHtml += '<div class="pos-tax-row pos-tax-row--sub"><span>&nbsp;(税 ' + Utils.formatYen(m.calc.tax10) + ')</span><span></span></div>';
    }
    if (m.calc.subtotal8 + m.calc.tax8 > 0) {
      leftHtml += '<div class="pos-tax-row"><span>8%対象</span><span>' + Utils.formatYen(m.calc.subtotal8 + m.calc.tax8) + '</span></div>';
      leftHtml += '<div class="pos-tax-row pos-tax-row--sub"><span>&nbsp;(税 ' + Utils.formatYen(m.calc.tax8) + ')</span><span></span></div>';
    }
    leftHtml += '<div class="pos-total-line">'
      + '<span>合計</span><span>' + Utils.formatYen(m.calc.total) + '</span>'
      + '</div>';
    leftHtml += '</div>';

    leftHtml += '</div>'; // end left

    // ── 右側: 操作部 ──
    var rightHtml = '<div class="pos-modal__right">';

    // 支払方法
    rightHtml += '<div class="pos-pay-methods">';
    ['cash', 'card', 'qr'].forEach(function (method) {
      var labels = { cash: '現金', card: 'クレカ', qr: 'QR' };
      var cls = 'pos-pay-btn pos-pay-btn--' + method;
      if (m.paymentMethod === method) cls += ' pos-pay-btn--active';
      rightHtml += '<button class="' + cls + '" data-method="' + method + '">' + labels[method] + '</button>';
    });
    rightHtml += '</div>';

    // 預かり金・お釣り表示
    var received = parseInt(m.receivedInput, 10) || 0;
    var change = received > m.calc.total ? received - m.calc.total : 0;
    var canPay = m.calc.total > 0 && someChecked;
    if (m.paymentMethod === 'cash') {
      canPay = canPay && received >= m.calc.total;
    }

    rightHtml += '<div class="pos-amount-display">';
    rightHtml += '<div class="pos-amount-row"><span class="pos-amount-label">合計</span><span class="pos-amount-value">' + Utils.formatYen(m.calc.total) + '</span></div>';
    if (m.paymentMethod === 'cash') {
      rightHtml += '<div class="pos-amount-row"><span class="pos-amount-label">預かり</span><span class="pos-amount-value pos-amount-value--received">' + (m.receivedInput ? Utils.formatYen(received) : '-') + '</span></div>';
      rightHtml += '<div class="pos-amount-row pos-amount-row--change"><span class="pos-amount-label">お釣り</span><span class="pos-amount-value pos-amount-value--change">' + (received >= m.calc.total && m.calc.total > 0 ? Utils.formatYen(change) : '-') + '</span></div>';
    }
    rightHtml += '</div>';

    // テンキー（現金時のみ）
    if (m.paymentMethod === 'cash') {
      rightHtml += '<div class="pos-tenkey">';
      rightHtml += '<div class="pos-tenkey__grid">';
      ['7','8','9','4','5','6','1','2','3','00','0','C'].forEach(function (v) {
        var cls = 'pos-tenkey__btn' + (v === 'C' ? ' pos-tenkey__btn--clear' : '');
        rightHtml += '<button class="' + cls + '" data-val="' + v + '">' + v + '</button>';
      });
      rightHtml += '</div>';
      rightHtml += '<div class="pos-tenkey__quick">';
      rightHtml += '<button class="pos-quick-btn" data-amount="1000">&yen;1,000</button>';
      rightHtml += '<button class="pos-quick-btn" data-amount="5000">&yen;5,000</button>';
      rightHtml += '<button class="pos-quick-btn" data-amount="10000">&yen;10,000</button>';
      rightHtml += '<button class="pos-quick-btn pos-quick-btn--exact" data-amount="exact">ぴったり</button>';
      rightHtml += '</div>';
      rightHtml += '</div>';
    }

    // 会計ボタン
    var submitLabel = isPartial ? '個別会計する' : '会計する';
    rightHtml += '<button id="pos-submit-btn" class="pos-submit-btn' + (isPartial ? ' pos-submit-btn--partial' : '') + '"'
      + (canPay ? '' : ' disabled') + '>' + submitLabel + '</button>';

    rightHtml += '</div>'; // end right

    document.getElementById('pos-modal-body').innerHTML =
      '<div class="pos-modal__header">'
      + '<span class="pos-modal__title">' + Utils.escapeHtml(m.tableCode) + '</span>'
      + '<button class="pos-modal__close" id="pos-modal-close">&times;</button>'
      + '</div>'
      + '<div class="pos-modal__content">' + leftHtml + rightHtml + '</div>';
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // 税計算
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  function calcSelected() {
    var m = _modal;

    // プランの場合はプラン料金
    if (m.planInfo) {
      var allChecked = m.items.every(function (it) { return it.checked; });
      if (allChecked) {
        m.calc.total = m.planInfo.total;
        m.calc.subtotal10 = Math.floor(m.planInfo.total / 1.10);
        m.calc.tax10 = m.planInfo.total - m.calc.subtotal10;
        m.calc.subtotal8 = 0;
        m.calc.tax8 = 0;
        return;
      }
      // 個別会計時はプランではなく品目ベース
    }

    m.calc.subtotal10 = 0;
    m.calc.tax10 = 0;
    m.calc.subtotal8 = 0;
    m.calc.tax8 = 0;
    m.calc.total = 0;

    m.items.forEach(function (item) {
      if (!item.checked) return;
      var lineTotal = item.price * item.qty;
      m.calc.total += lineTotal;

      if (item.taxRate === 8) {
        var sub8 = Math.floor(lineTotal / 1.08);
        m.calc.subtotal8 += sub8;
        m.calc.tax8 += lineTotal - sub8;
      } else {
        var sub10 = Math.floor(lineTotal / 1.10);
        m.calc.subtotal10 += sub10;
        m.calc.tax10 += lineTotal - sub10;
      }
    });
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // モーダルイベント
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  function bindModalEvents() {
    var overlay = document.getElementById('pos-modal-overlay');
    var body = document.getElementById('pos-modal-body');

    // 背景クリックで閉じる
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeModal();
    });

    // モーダル内イベント委譲
    body.addEventListener('click', function (e) {
      // 閉じるボタン
      if (e.target.closest('#pos-modal-close')) { closeModal(); return; }

      // 支払方法
      var payBtn = e.target.closest('.pos-pay-btn');
      if (payBtn) {
        _modal.paymentMethod = payBtn.dataset.method;
        _modal.receivedInput = '';
        renderModal();
        return;
      }

      // テンキー
      var tenkey = e.target.closest('.pos-tenkey__btn');
      if (tenkey) {
        var val = tenkey.dataset.val;
        if (val === 'C') {
          _modal.receivedInput = '';
        } else if (_modal.receivedInput.length < 7) {
          _modal.receivedInput += val;
        }
        renderModal();
        return;
      }

      // クイック金額
      var quick = e.target.closest('.pos-quick-btn');
      if (quick) {
        var amount = quick.dataset.amount;
        if (amount === 'exact') {
          _modal.receivedInput = String(_modal.calc.total);
        } else {
          _modal.receivedInput = amount;
        }
        renderModal();
        return;
      }

      // 会計ボタン
      if (e.target.closest('#pos-submit-btn') && !e.target.closest('#pos-submit-btn').disabled) {
        submitPayment();
        return;
      }
    });

    // チェックボックス変更（change イベント）
    body.addEventListener('change', function (e) {
      // 全選択
      if (e.target.id === 'pos-check-all') {
        var checked = e.target.checked;
        _modal.items.forEach(function (it) { it.checked = checked; });
        calcSelected();
        _modal.receivedInput = '';
        renderModal();
        return;
      }

      // 個別チェック
      var itemCheck = e.target.closest('.pos-item__check');
      if (itemCheck) {
        var idx = parseInt(itemCheck.dataset.idx, 10);
        _modal.items[idx].checked = itemCheck.checked;
        calcSelected();
        _modal.receivedInput = '';
        renderModal();
        return;
      }
    });

    // カード一覧クリック
    _container.addEventListener('click', function (e) {
      var card = e.target.closest('.cashier-card');
      if (!card) return;
      if (e.target.closest('.cashier-card__btn') || card) {
        var tableId = card.dataset.tableId || null;
        var orderIdsStr = card.dataset.orderIds || '';
        handleCardClick(tableId, orderIdsStr);
      }
    });
  }

  function closeModal() {
    _modal.open = false;
    document.getElementById('pos-modal-overlay').classList.remove('show');
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // 決済処理
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  function submitPayment() {
    var m = _modal;
    var allChecked = m.items.every(function (it) { return it.checked; });
    var isPartial = !allChecked;

    var body = {
      store_id: _storeId,
      payment_method: m.paymentMethod
    };

    if (m.tableId) body.table_id = m.tableId;
    if (m.orderIds.length > 0) body.order_ids = m.orderIds;

    if (m.paymentMethod === 'cash') {
      body.received_amount = parseInt(m.receivedInput, 10) || 0;
    }

    if (isPartial) {
      body.selected_items = m.items.filter(function (it) { return it.checked; }).map(function (it) {
        return { name: it.name, qty: it.qty, price: it.price, taxRate: it.taxRate };
      });
      body.total_override = m.calc.total;
    }

    var btn = document.getElementById('pos-submit-btn');
    btn.disabled = true;
    btn.textContent = '処理中...';

    fetch('../../api/store/process-payment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (text) {
        try { return JSON.parse(text); }
        catch (e) { throw new Error(text.substring(0, 200)); }
      });
    }).then(function (json) {
      if (!json.ok) {
        showToast((window.Utils && Utils.formatError) ? Utils.formatError(json) : ((json.error && json.error.message) || 'エラー'), 'error');
        btn.disabled = false;
        btn.textContent = isPartial ? '個別会計する' : '会計する';
        return;
      }

      var d = json.data;

      // レシートモーダル表示
      showReceipt(d, m.tableCode);
      PollingDataSource.forceRefresh();
    }).catch(function (err) {
      showToast(err.message || '通信エラー', 'error');
      btn.disabled = false;
      btn.textContent = isPartial ? '個別会計する' : '会計する';
    });
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // レシート表示
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  function showReceipt(data, tableCode) {
    var methodLabels = { cash: '現金', card: 'クレカ', qr: 'QR' };
    var html = '<div class="pos-receipt">';
    html += '<div class="pos-receipt__header">会計完了</div>';
    html += '<div class="pos-receipt__table">' + Utils.escapeHtml(tableCode) + '</div>';
    if (data.isPartial) {
      html += '<div class="pos-receipt__partial">個別会計</div>';
    }

    html += '<div class="pos-receipt__rows">';
    if (data.subtotal10 + data.tax10 > 0) {
      html += '<div class="pos-receipt__row"><span>10%対象</span><span>' + Utils.formatYen(data.subtotal10 + data.tax10) + '</span></div>';
      html += '<div class="pos-receipt__row pos-receipt__row--sub"><span>&nbsp;(税 ' + Utils.formatYen(data.tax10) + ')</span><span></span></div>';
    }
    if (data.subtotal8 + data.tax8 > 0) {
      html += '<div class="pos-receipt__row"><span>8%対象</span><span>' + Utils.formatYen(data.subtotal8 + data.tax8) + '</span></div>';
      html += '<div class="pos-receipt__row pos-receipt__row--sub"><span>&nbsp;(税 ' + Utils.formatYen(data.tax8) + ')</span><span></span></div>';
    }
    html += '<div class="pos-receipt__total"><span>合計</span><span>' + Utils.formatYen(data.totalAmount) + '</span></div>';

    if (data.paymentMethod === 'cash' && data.changeAmount !== null) {
      html += '<div class="pos-receipt__divider"></div>';
      html += '<div class="pos-receipt__row"><span>預かり</span><span>' + Utils.formatYen(data.totalAmount + data.changeAmount) + '</span></div>';
      html += '<div class="pos-receipt__change"><span>お釣り</span><span>' + Utils.formatYen(data.changeAmount) + '</span></div>';
    }

    html += '<div class="pos-receipt__divider"></div>';
    html += '<div class="pos-receipt__row"><span>支払方法</span><span>' + (methodLabels[data.paymentMethod] || '') + '</span></div>';
    html += '</div>';

    html += '<div class="pos-receipt__actions">'
      + '<button class="pos-receipt__btn pos-receipt__btn--print" id="pos-receipt-print">レシート印刷</button>'
      + '<button class="pos-receipt__btn pos-receipt__btn--close" id="pos-receipt-close">閉じる</button>'
      + '</div>';
    html += '</div>';

    document.getElementById('pos-modal-body').innerHTML = html;

    document.getElementById('pos-receipt-close').addEventListener('click', function () {
      closeModal();
    });
    document.getElementById('pos-receipt-print').addEventListener('click', function () {
      window.print();
    });

    showToast('会計完了: ' + Utils.formatYen(data.totalAmount), 'success');
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // Toast
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  var _toastTimer = null;
  function showToast(msg, type) {
    var el = document.getElementById('kds-toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'kds-toast show';
    if (type === 'success') el.classList.add('kds-toast--success');
    if (type === 'error') el.classList.add('kds-toast--error');
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function () { el.classList.remove('show'); }, 3000);
  }

  return {
    init: init,
    setStoreId: setStoreId,
    setSessionData: setSessionData,
    onData: onData,
    showToast: showToast
  };
})();
