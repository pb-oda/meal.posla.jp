/**
 * POSレジ アプリケーション
 *
 * タブレット最適化されたレジ（会計）画面。
 * テーブル選択 → 注文確認 → テンキーで受取金額入力 → 会計処理。
 */
var PosRegister = (function () {
  'use strict';

  var API_BASE = '../../api';

  // ==== State ====
  var _user = null;
  var _stores = [];
  var _storeId = null;
  var _tables = [];           // tables-status.php レスポンス
  var _selectedTable = null;  // 選択中テーブルオブジェクト (from _tables)
  var _takeoutOrders = [];    // テイクアウト未会計注文
  var _isTakeoutMode = false;
  var _orders = [];           // 選択テーブルの未会計注文
  var _paymentMethod = 'cash';
  var _receivedInput = '';    // テンキー入力バッファ
  var _totalAmount = 0;
  var _subtotal10 = 0;
  var _tax10 = 0;
  var _subtotal8 = 0;
  var _tax8 = 0;
  var _sessionInfo = null;    // プラン/コース情報
  var _pollTimer = null;

  // ==== DOM cache ====
  var els = {};

  // ==== API ====
  function apiFetch(path, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    return fetch(API_BASE + path, opts).then(function (r) {
      return r.text().then(function (text) {
        try { return JSON.parse(text); }
        catch (e) {
          console.error('API non-JSON:', text.substring(0, 300));
          return Promise.reject(new Error(text.substring(0, 120)));
        }
      });
    });
  }

  function apiPost(path, body) {
    return apiFetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
  }

  // ==== Init ====
  function init() {
    cacheDom();

    apiFetch('/auth/me.php').then(function (json) {
      if (!json.ok) { window.location.href = '../admin/index.html'; return; }
      _user = json.data.user;
      _stores = json.data.stores || [];

      var saved = localStorage.getItem('mt_handy_store');
      if (saved && _stores.some(function (s) { return s.id === saved; })) {
        _storeId = saved;
      } else if (_stores.length === 1) {
        _storeId = _stores[0].id;
      } else if (_stores.length > 1) {
        // 複数店舗 + localStorage未保存 → オーバーレイで選択を強制
        els.userName.textContent = _user.displayName || _user.email;
        bindEvents();
        showStoreOverlay(_stores, function (selectedId) {
          _storeId = selectedId;
          localStorage.setItem('mt_handy_store', _storeId);
          setupUI();
          loadTables();
          startPolling();
        });
        return;
      }

      setupUI();
      bindEvents();
      loadTables();
      startPolling();
    }).catch(function (err) {
      console.error('Init error:', err);
    });
  }

  function cacheDom() {
    els.storeName     = document.getElementById('pos-store-name');
    els.changeStore   = document.getElementById('pos-change-store');
    els.userName      = document.getElementById('pos-user-name');
    els.tableGrid     = document.getElementById('pos-table-grid');
    els.takeoutBtn    = document.getElementById('pos-takeout-btn');
    els.orderHeader   = document.getElementById('pos-order-header-title');
    els.sessionInfo   = document.getElementById('pos-session-info');
    els.orderList     = document.getElementById('pos-order-list');
    els.taxArea       = document.getElementById('pos-tax-area');
    els.tenkey        = document.getElementById('pos-tenkey');
    els.quickAmounts  = document.getElementById('pos-quick-amounts');
    els.payBtns       = document.querySelectorAll('.pos-pay-btn');
    els.receivedVal   = document.getElementById('pos-received-value');
    els.changeVal     = document.getElementById('pos-change-value');
    els.cashInfo      = document.getElementById('pos-cash-info');
    els.submitBtn     = document.getElementById('pos-submit-btn');
    els.receiptOverlay = document.getElementById('pos-receipt-overlay');
    els.receiptBody   = document.getElementById('pos-receipt-body');
    els.toast         = document.getElementById('pos-toast');
  }

  function setupUI() {
    els.userName.textContent = _user.displayName || _user.email;

    // 店舗名テキスト表示
    var currentStore = _stores.find(function (s) { return s.id === _storeId; });
    els.storeName.textContent = currentStore ? currentStore.name : '-';

    // 複数店舗ユーザー: 切替リンク表示
    if (_stores.length > 1) {
      els.changeStore.style.display = '';
    }
  }

  function bindEvents() {
    // 店舗切替リンク
    els.changeStore.addEventListener('click', function (e) {
      e.preventDefault();
      showStoreOverlay(_stores, function (selectedId) {
        _storeId = selectedId;
        localStorage.setItem('mt_handy_store', _storeId);
        var s = _stores.find(function (x) { return x.id === selectedId; });
        els.storeName.textContent = s ? s.name : '-';
        resetSelection();
        loadTables();
      });
    });

    // テーブル選択
    els.tableGrid.addEventListener('click', function (e) {
      var tbl = e.target.closest('.pos-tbl');
      if (!tbl) return;
      selectTable(tbl.dataset.id);
    });

    // テイクアウト
    els.takeoutBtn.addEventListener('click', function () {
      toggleTakeoutMode();
    });

    // 支払方法
    document.querySelector('.pos-pay-btns').addEventListener('click', function (e) {
      var btn = e.target.closest('.pos-pay-btn');
      if (!btn) return;
      selectPaymentMethod(btn.dataset.method);
    });

    // テンキー
    els.tenkey.addEventListener('click', function (e) {
      var btn = e.target.closest('.pos-tenkey__btn');
      if (!btn) return;
      onTenkey(btn.dataset.val);
    });

    // クイック金額
    els.quickAmounts.addEventListener('click', function (e) {
      var btn = e.target.closest('.pos-quick-btn');
      if (!btn) return;
      var val = btn.dataset.val;
      if (val === 'exact') {
        onExact();
      } else {
        onQuickAmount(parseInt(val, 10));
      }
    });

    // 会計ボタン
    els.submitBtn.addEventListener('click', function () {
      processPayment();
    });

    // レシートモーダル
    els.receiptOverlay.addEventListener('click', function (e) {
      if (e.target === els.receiptOverlay) closeReceipt();
    });

    // ログアウト
    document.getElementById('pos-logout').addEventListener('click', function () {
      fetch(API_BASE + '/auth/logout.php', { method: 'DELETE', credentials: 'same-origin' })
        .finally(function () { window.location.href = '../admin/index.html'; });
    });
  }

  // ==== Polling ====
  function startPolling() {
    if (_pollTimer) clearInterval(_pollTimer);
    _pollTimer = setInterval(function () {
      loadTables(true);
    }, 30000);
  }

  // ==== Data loading ====
  function loadTables(silent) {
    if (!_storeId) return;
    apiFetch('/store/tables-status.php?store_id=' + _storeId).then(function (json) {
      if (!json.ok) return;
      _tables = json.data.tables || [];
      renderTables();

      // 選択中テーブルを更新
      if (_selectedTable && !_isTakeoutMode) {
        var updated = _tables.find(function (t) { return t.id === _selectedTable.id; });
        if (updated) {
          _selectedTable = updated;
          if (!silent) loadOrders();
        }
      }
    }).catch(function () {});

    // テイクアウト注文もロード
    loadTakeoutOrders();
  }

  function loadTakeoutOrders() {
    apiFetch('/kds/orders.php?store_id=' + _storeId + '&view=accounting').then(function (json) {
      if (!json.ok) return;
      _takeoutOrders = (json.data.orders || []).filter(function (o) {
        return !o.table_id && o.status !== 'paid' && o.status !== 'cancelled';
      });
      if (_isTakeoutMode) renderOrders();
    }).catch(function () {});
  }

  function loadOrders() {
    if (_isTakeoutMode) {
      renderOrders();
      return;
    }
    if (!_selectedTable) return;

    apiFetch('/kds/orders.php?store_id=' + _storeId + '&view=accounting').then(function (json) {
      if (!json.ok) return;
      _orders = (json.data.orders || []).filter(function (o) {
        return o.table_id === _selectedTable.id && o.status !== 'paid' && o.status !== 'cancelled';
      });
      calcTax();
      renderOrders();
      renderTaxArea();
      updateSubmitState();
    }).catch(function () {});
  }

  // ==== Selection ====
  function selectTable(tableId) {
    _isTakeoutMode = false;
    els.takeoutBtn.classList.remove('pos-takeout-btn--selected');

    _selectedTable = _tables.find(function (t) { return t.id === tableId; }) || null;
    _receivedInput = '';
    _orders = [];

    // セッション情報取得
    _sessionInfo = _selectedTable && _selectedTable.session ? _selectedTable.session : null;

    renderTables();
    loadOrders();
  }

  function toggleTakeoutMode() {
    _isTakeoutMode = !_isTakeoutMode;
    _selectedTable = null;
    _sessionInfo = null;
    _receivedInput = '';

    if (_isTakeoutMode) {
      els.takeoutBtn.classList.add('pos-takeout-btn--selected');
    } else {
      els.takeoutBtn.classList.remove('pos-takeout-btn--selected');
    }

    renderTables();
    renderOrders();
    renderTaxArea();
    updateSubmitState();
  }

  function resetSelection() {
    _selectedTable = null;
    _isTakeoutMode = false;
    _sessionInfo = null;
    _orders = [];
    _takeoutOrders = [];
    _receivedInput = '';
    _totalAmount = 0;
    els.takeoutBtn.classList.remove('pos-takeout-btn--selected');
    renderOrders();
    renderTaxArea();
    updateCashDisplay();
    updateSubmitState();
  }

  // ==== Payment method ====
  function selectPaymentMethod(method) {
    _paymentMethod = method;
    _receivedInput = '';

    els.payBtns.forEach(function (btn) {
      btn.classList.toggle('pos-pay-btn--active', btn.dataset.method === method);
    });

    var isCash = method === 'cash';
    els.tenkey.classList.toggle('pos-tenkey--hidden', !isCash);
    els.quickAmounts.classList.toggle('pos-quick-amounts--hidden', !isCash);
    els.cashInfo.style.display = isCash ? 'block' : 'none';

    updateCashDisplay();
    updateSubmitState();
  }

  // ==== Tenkey ====
  function onTenkey(val) {
    if (val === 'C') {
      _receivedInput = '';
    } else if (val === 'BS') {
      _receivedInput = _receivedInput.slice(0, -1);
    } else {
      if (_receivedInput.length >= 8) return; // 最大8桁
      _receivedInput += val;
    }
    updateCashDisplay();
    updateSubmitState();
  }

  function onQuickAmount(amount) {
    _receivedInput = String(amount);
    updateCashDisplay();
    updateSubmitState();
  }

  function onExact() {
    _receivedInput = String(_totalAmount);
    updateCashDisplay();
    updateSubmitState();
  }

  function updateCashDisplay() {
    var received = parseInt(_receivedInput, 10) || 0;
    els.receivedVal.textContent = received > 0 ? Utils.formatYen(received) : '-';

    if (received > 0 && _totalAmount > 0) {
      var change = received - _totalAmount;
      if (change >= 0) {
        els.changeVal.textContent = Utils.formatYen(change);
        els.changeVal.className = 'pos-cash-row__value pos-cash-row__value--change';
      } else {
        els.changeVal.textContent = Utils.formatYen(change);
        els.changeVal.className = 'pos-cash-row__value pos-cash-row__value--short';
      }
    } else {
      els.changeVal.textContent = '-';
      els.changeVal.className = 'pos-cash-row__value';
    }
  }

  // ==== Tax calculation ====
  function calcTax() {
    _subtotal10 = 0;
    _tax10 = 0;
    _subtotal8 = 0;
    _tax8 = 0;
    _totalAmount = 0;

    // プランがあればプラン料金 × 人数
    if (_sessionInfo && _sessionInfo.planPrice) {
      var guestCount = Math.max(1, _sessionInfo.guestCount || 1);
      _totalAmount = _sessionInfo.planPrice * guestCount;
      _subtotal10 = Math.floor(_totalAmount / 1.10);
      _tax10 = _totalAmount - _subtotal10;
      return;
    }

    // コースがあればコース料金 × 人数
    if (_sessionInfo && _sessionInfo.coursePrice) {
      var gc = Math.max(1, _sessionInfo.guestCount || 1);
      _totalAmount = _sessionInfo.coursePrice * gc;
      _subtotal10 = Math.floor(_totalAmount / 1.10);
      _tax10 = _totalAmount - _subtotal10;
      return;
    }

    // 通常: 注文品目から計算
    var sourceOrders = _isTakeoutMode ? _takeoutOrders : _orders;
    sourceOrders.forEach(function (order) {
      var items = order.items || [];
      items.forEach(function (item) {
        var qty = parseInt(item.qty, 10) || 1;
        var price = parseInt(item.price, 10) || 0;
        var lineTotal = price * qty;
        var taxRate = item.taxRate ? parseInt(item.taxRate, 10) : 10;

        if (taxRate === 8) {
          var sub8 = Math.floor(lineTotal / 1.08);
          _subtotal8 += sub8;
          _tax8 += lineTotal - sub8;
        } else {
          var sub10 = Math.floor(lineTotal / 1.10);
          _subtotal10 += sub10;
          _tax10 += lineTotal - sub10;
        }
      });
      _totalAmount += parseInt(order.total_amount, 10) || 0;
    });
  }

  // ==== Rendering ====
  function renderTables() {
    var html = '';
    _tables.forEach(function (t) {
      var hasSession = !!t.session;
      var hasOrders = t.orders && t.orders.orderCount > 0;
      var isBill = hasSession && t.session.status === 'bill_requested';
      var isSelected = _selectedTable && _selectedTable.id === t.id && !_isTakeoutMode;

      var cls = 'pos-tbl';
      if (isSelected) cls += ' pos-tbl--selected';
      else if (isBill) cls += ' pos-tbl--bill';
      else if (hasSession || hasOrders) cls += ' pos-tbl--occupied';
      else cls += ' pos-tbl--empty';

      html += '<div class="' + cls + '" data-id="' + t.id + '">';
      html += '<div class="pos-tbl__code">' + Utils.escapeHtml(t.tableCode) + '</div>';

      if (hasSession) {
        html += '<div class="pos-tbl__info">' + (t.session.guestCount || 0) + '名 ' + (t.session.elapsedMin || 0) + '分</div>';
      }

      if (hasSession && t.session.planName) {
        var planAmt = (t.session.planPrice || 0) * Math.max(1, t.session.guestCount || 1);
        html += '<div class="pos-tbl__amount">' + Utils.formatYen(planAmt) + '</div>';
      } else if (hasSession && t.session.courseName) {
        var courseAmt = (t.session.coursePrice || 0) * Math.max(1, t.session.guestCount || 1);
        html += '<div class="pos-tbl__amount">' + Utils.formatYen(courseAmt) + '</div>';
      } else if (hasOrders) {
        html += '<div class="pos-tbl__amount">' + Utils.formatYen(t.orders.totalAmount) + '</div>';
      } else {
        html += '<div class="pos-tbl__info">' + t.capacity + '席</div>';
      }

      html += '</div>';
    });

    els.tableGrid.innerHTML = html;
  }

  function renderOrders() {
    var sourceOrders = _isTakeoutMode ? _takeoutOrders : _orders;

    // ヘッダー更新
    if (_isTakeoutMode) {
      els.orderHeader.innerHTML = '<span class="pos-order-header__table">テイクアウト</span> ' + sourceOrders.length + '件';
    } else if (_selectedTable) {
      els.orderHeader.innerHTML = '<span class="pos-order-header__table">' + Utils.escapeHtml(_selectedTable.tableCode) + '</span>';
    } else {
      els.orderHeader.textContent = 'テーブルを選択してください';
    }

    // セッション情報
    if (_sessionInfo) {
      var info = '';
      if (_sessionInfo.planName) {
        info = _sessionInfo.planName + ' (' + (_sessionInfo.guestCount || 1) + '名)';
      } else if (_sessionInfo.courseName) {
        info = _sessionInfo.courseName + ' (' + (_sessionInfo.guestCount || 1) + '名)';
        if (_sessionInfo.currentPhaseNumber) info += ' フェーズ' + _sessionInfo.currentPhaseNumber;
      } else if (_sessionInfo.guestCount) {
        info = _sessionInfo.guestCount + '名';
      }
      if (info) {
        els.sessionInfo.textContent = info;
        els.sessionInfo.style.display = 'block';
      } else {
        els.sessionInfo.style.display = 'none';
      }
    } else {
      els.sessionInfo.style.display = 'none';
    }

    // 注文一覧
    if (!_selectedTable && !_isTakeoutMode) {
      els.orderList.innerHTML = '<div class="pos-empty">テーブルを選択してください</div>';
      return;
    }

    if (sourceOrders.length === 0) {
      els.orderList.innerHTML = '<div class="pos-empty">未会計の注文がありません</div>';
      return;
    }

    var html = '';
    sourceOrders.forEach(function (order) {
      var items = order.items || [];
      items.forEach(function (item) {
        var qty = parseInt(item.qty, 10) || 1;
        var price = parseInt(item.price, 10) || 0;
        html += '<div class="pos-order-item">';
        html += '<span class="pos-order-item__name">' + Utils.escapeHtml(item.name) + '</span>';
        html += '<span class="pos-order-item__qty">&times;' + qty + '</span>';
        html += '<span class="pos-order-item__price">' + Utils.formatYen(price * qty) + '</span>';
        html += '</div>';

        // オプション表示
        if (item.options && item.options.length > 0) {
          var optNames = item.options.map(function (o) { return o.choiceName || o.name || ''; }).join(', ');
          html += '<div class="pos-order-item pos-order-item--options">' + Utils.escapeHtml(optNames) + '</div>';
        }
      });
    });

    els.orderList.innerHTML = html;
  }

  function renderTaxArea() {
    if (_totalAmount === 0) {
      els.taxArea.innerHTML = '';
      return;
    }

    var html = '';
    if (_subtotal10 > 0 || _tax10 > 0) {
      html += '<div class="pos-tax-row"><span>10%対象</span><span class="pos-tax-row__value">' + Utils.formatYen(_subtotal10 + _tax10) + '</span></div>';
      html += '<div class="pos-tax-row"><span>&nbsp;&nbsp;(税 ' + Utils.formatYen(_tax10) + ')</span><span></span></div>';
    }
    if (_subtotal8 > 0 || _tax8 > 0) {
      html += '<div class="pos-tax-row"><span>8%対象</span><span class="pos-tax-row__value">' + Utils.formatYen(_subtotal8 + _tax8) + '</span></div>';
      html += '<div class="pos-tax-row"><span>&nbsp;&nbsp;(税 ' + Utils.formatYen(_tax8) + ')</span><span></span></div>';
    }
    html += '<div class="pos-total-row">';
    html += '<span class="pos-total-row__label">合計</span>';
    html += '<span class="pos-total-row__value">' + Utils.formatYen(_totalAmount) + '</span>';
    html += '</div>';

    els.taxArea.innerHTML = html;
    updateCashDisplay();
  }

  // ==== Submit state ====
  function updateSubmitState() {
    var hasTarget = _selectedTable || _isTakeoutMode;
    var hasAmount = _totalAmount > 0;

    if (_paymentMethod === 'cash') {
      var received = parseInt(_receivedInput, 10) || 0;
      els.submitBtn.disabled = !hasTarget || !hasAmount || received < _totalAmount;
    } else {
      // カード/QR: 受取金額不要
      els.submitBtn.disabled = !hasTarget || !hasAmount;
    }
  }

  // ==== Payment processing ====
  function processPayment() {
    if (els.submitBtn.disabled) return;

    var sourceOrders = _isTakeoutMode ? _takeoutOrders : _orders;
    var orderIds = sourceOrders.map(function (o) { return o.id; });
    var received = parseInt(_receivedInput, 10) || 0;

    var body = {
      store_id: _storeId,
      order_ids: orderIds,
      payment_method: _paymentMethod
    };

    if (_selectedTable) {
      body.table_id = _selectedTable.id;
    }

    if (_paymentMethod === 'cash') {
      body.received_amount = received;
    }

    els.submitBtn.disabled = true;
    els.submitBtn.textContent = '処理中...';

    apiPost('/store/process-payment.php', body).then(function (json) {
      if (!json.ok) {
        var errMsg = '会計に失敗しました';
        if (json.error) {
          errMsg = typeof json.error === 'string' ? json.error : (json.error.message || errMsg);
        }
        toast(errMsg, 'error');
        els.submitBtn.disabled = false;
        els.submitBtn.textContent = '会計する';
        return;
      }

      showReceipt(json.data);
      toast('会計完了', 'success');

      // リセット
      _selectedTable = null;
      _isTakeoutMode = false;
      _sessionInfo = null;
      _orders = [];
      _receivedInput = '';
      _totalAmount = 0;
      els.takeoutBtn.classList.remove('pos-takeout-btn--selected');
      els.submitBtn.textContent = '会計する';

      loadTables();
      renderOrders();
      renderTaxArea();
      updateCashDisplay();
      updateSubmitState();

    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
      els.submitBtn.disabled = false;
      els.submitBtn.textContent = '会計する';
    });
  }

  // ==== Receipt ====
  function showReceipt(data) {
    var html = '<div class="pos-receipt__title">会計完了</div>';

    html += '<div class="pos-receipt__row"><span>支払方法</span><span>' + getMethodLabel(data.paymentMethod) + '</span></div>';
    html += '<div class="pos-receipt__row"><span>注文数</span><span>' + data.orderCount + '件</span></div>';

    html += '<hr class="pos-receipt__divider">';

    if (data.subtotal10 > 0 || data.tax10 > 0) {
      html += '<div class="pos-receipt__row"><span>10%対象</span><span>' + Utils.formatYen(data.subtotal10 + data.tax10) + '</span></div>';
      html += '<div class="pos-receipt__row"><span>&nbsp;&nbsp;消費税(10%)</span><span>' + Utils.formatYen(data.tax10) + '</span></div>';
    }
    if (data.subtotal8 > 0 || data.tax8 > 0) {
      html += '<div class="pos-receipt__row"><span>8%対象</span><span>' + Utils.formatYen(data.subtotal8 + data.tax8) + '</span></div>';
      html += '<div class="pos-receipt__row"><span>&nbsp;&nbsp;消費税(8%)</span><span>' + Utils.formatYen(data.tax8) + '</span></div>';
    }

    html += '<div class="pos-receipt__row pos-receipt__row--total"><span>合計</span><span>' + Utils.formatYen(data.totalAmount) + '</span></div>';

    if (data.paymentMethod === 'cash' && data.changeAmount !== null) {
      html += '<hr class="pos-receipt__divider">';
      html += '<div class="pos-receipt__row"><span>お預かり</span><span>' + Utils.formatYen(data.totalAmount + data.changeAmount) + '</span></div>';
      html += '<div class="pos-receipt__row pos-receipt__row--change"><span>お釣り</span><span>' + Utils.formatYen(data.changeAmount) + '</span></div>';
    }

    html += '<div class="pos-receipt__actions">';
    html += '<button class="pos-receipt__btn pos-receipt__btn--close" onclick="PosRegister.closeReceipt()">閉じる</button>';
    html += '<button class="pos-receipt__btn pos-receipt__btn--print" onclick="PosRegister.printReceipt()">レシート印刷</button>';
    html += '</div>';

    els.receiptBody.innerHTML = html;
    els.receiptOverlay.classList.add('show');
  }

  function closeReceipt() {
    els.receiptOverlay.classList.remove('show');
  }

  function printReceipt() {
    console.log('Receipt print requested');
    window.print();
  }

  function getMethodLabel(method) {
    switch (method) {
      case 'cash': return '現金';
      case 'card': return 'カード';
      case 'qr':   return 'QR決済';
      default:     return method;
    }
  }

  // ==== Toast ====
  var _toastTimer = null;

  function toast(msg, type) {
    if (_toastTimer) clearTimeout(_toastTimer);
    els.toast.textContent = msg;
    els.toast.className = 'handy-toast show' + (type ? ' handy-toast--' + type : '');
    _toastTimer = setTimeout(function () {
      els.toast.classList.remove('show');
    }, 2500);
  }

  // ==== Store overlay ====
  function showStoreOverlay(stores, onSelect) {
    var overlay = document.getElementById('handy-store-overlay');
    var list = document.getElementById('handy-store-list');
    var html = '';
    stores.forEach(function (s) {
      html += '<button class="handy-store-overlay__btn" data-store-id="' + s.id + '">' + Utils.escapeHtml(s.name) + '</button>';
    });
    list.innerHTML = html;
    overlay.style.display = 'flex';

    list.addEventListener('click', function handler(e) {
      var btn = e.target.closest('.handy-store-overlay__btn');
      if (!btn) return;
      list.removeEventListener('click', handler);
      overlay.style.display = 'none';
      onSelect(btn.dataset.storeId);
    });
  }

  return {
    init: init,
    closeReceipt: closeReceipt,
    printReceipt: printReceipt
  };
})();
