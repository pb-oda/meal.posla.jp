/**
 * CashierApp — スタンドアロン会計ページモジュール
 *
 * 2カラムレイアウト: 左テーブル一覧 / 右レジ
 * 割引・レシートプレビュー・ダークプロテーマ対応
 * v2: 時計・スタッフ名・取引ジャーナル・レジ開閉・キーボードショートカット・操作音・売上バー・経過時間
 *
 * ES5 IIFE — const/let/arrow/async 禁止
 */
(function () {
  'use strict';

  var CashierApp = {};

  // ── 状態 ──
  var _storeId = null;
  var _storeName = '';
  var _userName = '';
  var _sessions = {};        // {tableId: session}
  var _orders = {};          // {orderId: order}
  var _selectedTableId = null;
  var _items = [];           // 選択テーブルの品目
  var _checkedItems = {};    // {index: true}
  var _planInfo = null;
  var _discountType = 'amount';  // 'amount' | 'percent'
  var _discountValue = 0;
  var _discountApplied = false;  // 適用済みフラグ
  var _paymentMethod = 'cash';
  var _receivedInput = '';
  var _receiptSettings = {};
  var _pollTimer = null;
  var _tableGroups = [];     // テーブルグループ一覧
  var _salesSummary = null;  // 本日売上サマリー
  var _salesPollTimer = null;
  var _isSubmitting = false; // 決済送信中フラグ
  var _PAY_LABELS = { cash: '現金', card: 'クレカ', qr: 'QR' };

  // 新機能状態
  var _clockTimer = null;
  var _soundEnabled = false;
  var _audioCtx = null;
  var _openPanel = null;     // 'journal' | 'register-ctrl' | null
  var _journalData = null;
  var _cashLogEntries = [];
  var _registerOpen = false; // レジ開き済みかどうか

  var _lastPaymentId = null;       // L-5: 領収書発行用

  // O-2: 合流・分割
  var _mergeMode = false;         // 合流モードON/OFF
  var _mergedGroupKeys = [];      // 合流選択されたグループキー

  // ── 初期化 ──
  CashierApp.init = function () {
    KdsAuth.init().catch(function () {}).then(function (ctx) {
      if (!ctx) return;
      _storeId = ctx.storeId;
      _userName = (ctx.user && ctx.user.display_name) || (ctx.user && ctx.user.username) || '';
      ctx.stores.forEach(function (s) {
        if (s.id === ctx.storeId) _storeName = s.name;
      });
      document.getElementById('ca-store-name').textContent = _storeName;

      // スタッフ名表示
      var staffEl = document.getElementById('ca-staff-name');
      if (staffEl && _userName) staffEl.textContent = _userName;

      // 時計開始
      _startClock();

      // 音声設定復元
      _soundEnabled = localStorage.getItem('ca_sound') === '1';
      _updateSoundBtn();

      _fetchReceiptSettings();
      _startPolling();
      _startSalesPolling();
      _bindEvents();
      _bindKeyboard();
      _renderRegister();

      // L-13: Stripe Terminal 初期化（Connect対応店舗のみ）
      _initStripeTerminal();
    });
  };

  // ── 時計 ──
  function _startClock() {
    _updateClock();
    _clockTimer = setInterval(_updateClock, 1000);
  }

  function _updateClock() {
    var el = document.getElementById('ca-clock');
    if (!el) return;
    var now = new Date();
    var h = ('0' + now.getHours()).slice(-2);
    var m = ('0' + now.getMinutes()).slice(-2);
    var s = ('0' + now.getSeconds()).slice(-2);
    el.textContent = h + ':' + m + ':' + s;
  }

  // ── 操作音フィードバック ──
  function _getAudioCtx() {
    if (!_audioCtx) {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) _audioCtx = new Ctx();
    }
    return _audioCtx;
  }

  function _playBeep(freq, duration, type) {
    if (!_soundEnabled) return;
    var ctx = _getAudioCtx();
    if (!ctx) return;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.type = type || 'sine';
    osc.frequency.value = freq;
    gain.gain.value = 0.08;
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + (duration / 1000));
    osc.start(ctx.currentTime);
    osc.stop(ctx.currentTime + (duration / 1000));
  }

  function _playClick() { _playBeep(800, 60); }
  function _playSuccess() {
    _playBeep(523, 100);
    setTimeout(function () { _playBeep(659, 100); }, 80);
    setTimeout(function () { _playBeep(784, 150); }, 160);
  }
  function _playError() { _playBeep(220, 300, 'square'); }

  function _updateSoundBtn() {
    var btn = document.getElementById('ca-btn-sound');
    if (!btn) return;
    if (_soundEnabled) {
      btn.classList.add('active');
    } else {
      btn.classList.remove('active');
    }
  }

  // ── レシート設定取得 ──
  function _fetchReceiptSettings() {
    fetch('../../api/store/settings.php?store_id=' + encodeURIComponent(_storeId), { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data && json.data.settings) {
          _receiptSettings = json.data.settings;
        }
      }).catch(function () {});
  }

  // ── ポーリング ──
  function _startPolling() {
    _fetchOrders();
    _pollTimer = setInterval(_fetchOrders, 5000);
  }

  function _fetchOrders() {
    var orderUrl = '../../api/kds/orders.php?store_id=' + encodeURIComponent(_storeId) + '&view=accounting';
    var sessionUrl = '../../api/store/table-sessions.php?store_id=' + encodeURIComponent(_storeId);

    fetch(orderUrl, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (t) {
          if (!t) return {};
          try { return JSON.parse(t); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data) {
          _orders = {};
          (json.data.orders || []).forEach(function (o) {
            _orders[o.id] = o;
          });
        }
        return fetch(sessionUrl, { credentials: 'same-origin' });
      })
      .then(function (r) {
        return r.text().then(function (t) {
          if (!t) return {};
          try { return JSON.parse(t); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data) {
          _sessions = {};
          (json.data.sessions || []).forEach(function (s) {
            _sessions[s.tableId] = s;
          });
        }
        _buildTableGroups();
        _renderTableList();
        // 選択テーブルが消えたらリセット（決済送信中は除外、合流モードは別処理）
        if (_selectedTableId && !_isSubmitting && !_mergeMode) {
          var found = _tableGroups.some(function (g) {
            return g.groupKey === _selectedTableId;
          });
          if (!found) {
            _selectedTableId = null;
            _resetRegisterState();
            _renderRegister();
          }
        }
      }).catch(function (err) {
        console.error('Cashier polling error:', err);
      });
  }

  // ── 売上サマリー ──
  function _startSalesPolling() {
    _fetchSalesSummary();
    _salesPollTimer = setInterval(_fetchSalesSummary, 30000);
  }

  function _fetchSalesSummary() {
    var today = _todayStr();
    var salesUrl = '../../api/store/sales-report.php?store_id=' + encodeURIComponent(_storeId) + '&from=' + today + '&to=' + today;
    var regUrl = '../../api/store/register-report.php?store_id=' + encodeURIComponent(_storeId) + '&from=' + today + '&to=' + today;
    var salesData = null;
    var regData = null;

    fetch(salesUrl, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data) salesData = json.data;
        return fetch(regUrl, { credentials: 'same-origin' });
      })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data) regData = json.data;
        _salesSummary = _buildSalesSummary(salesData, regData);
        _renderSalesSummary();
      })
      .catch(function (err) {
        console.error('Sales summary error:', err);
      });
  }

  function _todayStr() {
    var d = new Date();
    var mm = ('0' + (d.getMonth() + 1)).slice(-2);
    var dd = ('0' + d.getDate()).slice(-2);
    return d.getFullYear() + '-' + mm + '-' + dd;
  }

  function _buildSalesSummary(sales, reg) {
    var summary = {
      totalRevenue: 0,
      orderCount: 0,
      cash: 0,
      card: 0,
      qr: 0,
      expectedBalance: 0
    };
    if (sales && sales.summary) {
      summary.totalRevenue = sales.summary.totalRevenue || 0;
      summary.orderCount = sales.summary.orderCount || 0;
    }
    if (reg && reg.paymentBreakdown) {
      reg.paymentBreakdown.forEach(function (p) {
        if (p.payment_method === 'cash') summary.cash = parseInt(p.total, 10) || 0;
        else if (p.payment_method === 'card') summary.card = parseInt(p.total, 10) || 0;
        else if (p.payment_method === 'qr') summary.qr = parseInt(p.total, 10) || 0;
      });
    }
    if (reg && reg.registerSummary) {
      summary.expectedBalance = reg.registerSummary.expectedBalance || 0;
    }
    return summary;
  }

  function _renderSalesSummary() {
    var container = document.querySelector('.ca-table-list');
    if (!container) return;

    var el = document.getElementById('ca-sales-summary');
    if (!el) {
      el = document.createElement('div');
      el.id = 'ca-sales-summary';
      el.className = 'ca-sales-summary';
      var header = container.querySelector('.ca-table-list__header');
      if (header) {
        header.parentNode.insertBefore(el, header.nextSibling);
      } else {
        container.insertBefore(el, container.firstChild);
      }
    }

    var s = _salesSummary || { totalRevenue: 0, orderCount: 0, cash: 0, card: 0, qr: 0, expectedBalance: 0 };

    // 比率バー計算
    var barTotal = s.cash + s.card + s.qr;
    var cashPct = barTotal > 0 ? Math.round(s.cash / barTotal * 100) : 0;
    var cardPct = barTotal > 0 ? Math.round(s.card / barTotal * 100) : 0;
    var qrPct = barTotal > 0 ? (100 - cashPct - cardPct) : 0;

    var html = '';
    html += '<div class="ca-sales-summary__title">本日の売上</div>';
    html += '<div class="ca-sales-summary__main">';
    html += '<span class="ca-sales-summary__total">' + Utils.formatYen(s.totalRevenue) + '</span>';
    html += '<span class="ca-sales-summary__count">' + s.orderCount + '件</span>';
    html += '</div>';

    // 比率バー
    if (barTotal > 0) {
      html += '<div class="ca-sales-summary__bar">';
      if (cashPct > 0) html += '<div class="ca-sales-summary__bar-seg ca-sales-summary__bar-seg--cash" style="width:' + cashPct + '%"></div>';
      if (cardPct > 0) html += '<div class="ca-sales-summary__bar-seg ca-sales-summary__bar-seg--card" style="width:' + cardPct + '%"></div>';
      if (qrPct > 0) html += '<div class="ca-sales-summary__bar-seg ca-sales-summary__bar-seg--qr" style="width:' + qrPct + '%"></div>';
      html += '</div>';
    }

    html += '<div class="ca-sales-summary__divider"></div>';
    html += '<div class="ca-sales-summary__row"><span>現金</span><span>' + Utils.formatYen(s.cash) + '</span></div>';
    html += '<div class="ca-sales-summary__row"><span>クレカ</span><span>' + Utils.formatYen(s.card) + '</span></div>';
    html += '<div class="ca-sales-summary__row"><span>QR</span><span>' + Utils.formatYen(s.qr) + '</span></div>';
    html += '<div class="ca-sales-summary__divider"></div>';
    html += '<div class="ca-sales-summary__row ca-sales-summary__row--balance"><span>予想在高</span><span>' + Utils.formatYen(s.expectedBalance) + '</span></div>';

    el.innerHTML = html;
  }

  // ── テーブルグループ構築 ──
  function _buildTableGroups() {
    var tables = {};
    Object.keys(_orders).forEach(function (id) {
      var o = _orders[id];
      if (o.status === 'paid' || o.status === 'cancelled') return;
      var groupKey = o.table_id || ('_to_' + o.id);
      if (!tables[groupKey]) {
        tables[groupKey] = {
          groupKey: groupKey,
          tableCode: o.table_code || (o.customer_name || 'テイクアウト'),
          tableId: o.table_id,
          orders: [],
          orderIds: [],
          total: 0,
          isTakeout: !o.table_id,
          itemCount: 0
        };
      }
      tables[groupKey].orders.push(o);
      tables[groupKey].orderIds.push(o.id);
      tables[groupKey].total += parseInt(o.total_amount, 10) || 0;
      tables[groupKey].itemCount += (o.items || []).length;
    });

    // セッションのみ（注文なし）も追加
    Object.keys(_sessions || {}).forEach(function (tableId) {
      if (tables[tableId]) return;
      var s = _sessions[tableId];
      tables[tableId] = {
        groupKey: tableId,
        tableCode: s.tableCode,
        tableId: tableId,
        orders: [],
        orderIds: [],
        total: 0,
        isTakeout: false,
        itemCount: 0
      };
    });

    _tableGroups = Object.keys(tables).map(function (k) { return tables[k]; })
      .sort(function (a, b) {
        if (a.isTakeout !== b.isTakeout) return a.isTakeout ? 1 : -1;
        return (a.tableCode || '').localeCompare(b.tableCode || '');
      });
  }

  // ── 経過時間計算 ──
  function _calcElapsedMin(session) {
    if (!session || !session.openedAt) return null;
    var opened = new Date(session.openedAt).getTime();
    if (isNaN(opened)) return null;
    var now = Date.now();
    return Math.floor((now - opened) / 60000);
  }

  // ── テーブル一覧描画（左カラム） ──
  function _renderTableList() {
    var body = document.getElementById('ca-table-body');
    var countEl = document.getElementById('ca-table-count');
    countEl.textContent = _tableGroups.length;

    // 合流トグルボタン
    var mergeBtn = document.getElementById('ca-btn-merge-toggle');
    if (mergeBtn) {
      mergeBtn.textContent = _mergeMode ? '合流解除' : '合流会計';
      if (_mergeMode) {
        mergeBtn.classList.add('active');
      } else {
        mergeBtn.classList.remove('active');
      }
    }

    if (_tableGroups.length === 0) {
      body.innerHTML = '<div class="ca-table-list__empty">会計待ちの注文はありません</div>';
      return;
    }

    var html = '';
    _tableGroups.forEach(function (t) {
      var session = _sessions[t.tableId];
      var hasPlan = session && session.planName && session.planPrice;
      var displayTotal = t.total;
      if (hasPlan) {
        displayTotal = (parseInt(session.planPrice, 10) || 0) * (parseInt(session.guestCount, 10) || 1);
      }

      var isMergeSelected = _mergeMode && _mergedGroupKeys.indexOf(t.groupKey) !== -1;
      var isActive = !_mergeMode && _selectedTableId === t.groupKey;
      var cls = 'ca-table-card' + (isActive ? ' active' : '') + (t.isTakeout ? ' ca-table-card--takeout' : '') + (isMergeSelected ? ' ca-table-card--merge-selected' : '');

      // 経過時間
      var elapsed = _calcElapsedMin(session);
      var elapsedHtml = '';
      if (elapsed !== null) {
        var eCls = 'ca-table-card__elapsed';
        if (elapsed >= 60) eCls += ' ca-table-card__elapsed--danger';
        else if (elapsed >= 30) eCls += ' ca-table-card__elapsed--warn';
        elapsedHtml = '<span class="' + eCls + '">' + elapsed + '分</span>';
      }

      html += '<div class="' + cls + '" data-group-key="' + Utils.escapeHtml(t.groupKey) + '">';
      html += '<div class="ca-table-card__top">';
      html += '<span class="ca-table-card__code">' + Utils.escapeHtml(t.tableCode) + '</span>';
      if (t.isTakeout) {
        html += '<span class="ca-table-card__badge">TO</span>';
      } else {
        html += '<span class="ca-table-card__type-badge">店内</span>';
      }
      html += '</div>';

      if (hasPlan) {
        html += '<div class="ca-table-card__plan">' + Utils.escapeHtml(session.planName) + ' ' + (session.guestCount || 1) + '名</div>';
      }

      html += '<div class="ca-table-card__info">';
      html += '<span class="ca-table-card__meta">' + t.orders.length + '注文 / ' + t.itemCount + '品' + elapsedHtml + '</span>';
      html += '<span class="ca-table-card__total">' + Utils.formatYen(displayTotal) + '</span>';
      html += '</div>';
      html += '</div>';
    });
    body.innerHTML = html;
  }

  // ── テーブル選択 ──
  function _selectTable(groupKey) {
    if (_mergeMode) {
      _toggleMergeGroup(groupKey);
      return;
    }
    if (_selectedTableId === groupKey) return;
    _selectedTableId = groupKey;
    _resetRegisterState();

    var group = _findGroup(groupKey);
    if (!group) return;

    // 品目構築
    _items = [];
    _checkedItems = {};
    group.orders.forEach(function (o) {
      (o.items || []).forEach(function (item) {
        var taxRate = 10;
        if (item.taxRate) taxRate = item.taxRate;
        else if (o.order_type === 'takeout') taxRate = 8;

        var i = _items.length;
        _items.push({
          name: item.name,
          qty: item.qty || 1,
          price: item.price || 0,
          taxRate: taxRate,
          orderId: o.id,
          options: item.options || [],
          itemId: item.item_id || null,
          paymentId: item.payment_id || null
        });
        _checkedItems[i] = item.payment_id ? false : true;
      });
    });

    // プラン
    var session = _sessions[group.tableId];
    _planInfo = null;
    if (session && session.planName && session.planPrice) {
      var pPrice = parseInt(session.planPrice, 10) || 0;
      var pGuests = parseInt(session.guestCount, 10) || 1;
      _planInfo = {
        name: session.planName,
        price: pPrice,
        guests: pGuests,
        total: pPrice * pGuests
      };
    }

    _playClick();
    _renderTableList();
    _renderRegister();
  }

  function _findGroup(groupKey) {
    for (var i = 0; i < _tableGroups.length; i++) {
      if (_tableGroups[i].groupKey === groupKey) return _tableGroups[i];
    }
    return null;
  }

  function _resetRegisterState() {
    _items = [];
    _checkedItems = {};
    _planInfo = null;
    _discountType = 'amount';
    _discountValue = 0;
    _discountApplied = false;
    // _paymentMethod はリセットしない（連続会計時に支払方法を維持）
    _receivedInput = '';
  }

  // O-2: 合流モードのトグル
  function _toggleMergeMode() {
    _mergeMode = !_mergeMode;
    _mergedGroupKeys = [];
    if (_mergeMode) {
      _selectedTableId = null;
      _resetRegisterState();
    }
    _renderTableList();
    _renderRegister();
  }

  function _toggleMergeGroup(groupKey) {
    var idx = _mergedGroupKeys.indexOf(groupKey);
    if (idx !== -1) {
      _mergedGroupKeys.splice(idx, 1);
    } else {
      _mergedGroupKeys.push(groupKey);
    }
    // 2つ以上選択で品目統合
    if (_mergedGroupKeys.length >= 2) {
      _selectMergedTables();
    } else if (_mergedGroupKeys.length === 1) {
      _selectMergedTables();
    } else {
      _selectedTableId = null;
      _resetRegisterState();
    }
    _renderTableList();
    _renderRegister();
  }

  function _selectMergedTables() {
    _items = [];
    _checkedItems = {};
    _planInfo = null;
    _discountType = 'amount';
    _discountValue = 0;
    _discountApplied = false;
    _receivedInput = '';
    _selectedTableId = '_merged_';

    _mergedGroupKeys.forEach(function (gk) {
      var g = _findGroup(gk);
      if (!g) return;
      g.orders.forEach(function (o) {
        (o.items || []).forEach(function (item) {
          var taxRate = 10;
          if (item.taxRate) taxRate = item.taxRate;
          else if (o.order_type === 'takeout') taxRate = 8;

          var i = _items.length;
          _items.push({
            name: item.name,
            qty: item.qty || 1,
            price: item.price || 0,
            taxRate: taxRate,
            orderId: o.id,
            options: item.options || [],
            itemId: item.item_id || null,
            paymentId: item.payment_id || null,
            tableCode: g.tableCode
          });
          _checkedItems[i] = item.payment_id ? false : true;
        });
      });
    });
  }

  // ── 共通HTML生成ヘルパー ──
  function _renderPayMethodsHtml() {
    var h = '<div class="ca-pay-methods">';
    ['cash', 'card', 'qr'].forEach(function (method) {
      var cls = 'ca-pay-btn ca-pay-btn--' + method;
      if (_paymentMethod === method) cls += ' ca-pay-btn--active';
      h += '<button class="' + cls + '" data-method="' + method + '">' + _PAY_LABELS[method] + '</button>';
    });
    h += '</div>';
    return h;
  }

  function _renderAmountHtml(calc) {
    var received = parseInt(_receivedInput, 10) || 0;
    var total = calc ? calc.finalTotal : 0;
    var change = received > total ? received - total : 0;

    var h = '<div class="ca-amount-display">';
    h += '<div class="ca-amount-row"><span class="ca-amount-label">合計</span><span class="ca-amount-value">' + (calc ? Utils.formatYen(total) : '-') + '</span></div>';
    if (_paymentMethod === 'cash') {
      h += '<div class="ca-amount-row"><span class="ca-amount-label">預かり</span><span class="ca-amount-value ca-amount-value--received">' + (_receivedInput ? Utils.formatYen(received) : '-') + '</span></div>';
      var changeClass = 'ca-amount-value';
      if (received >= total && total > 0) {
        changeClass += ' ca-amount-value--change';
      } else if (_receivedInput && received < total) {
        changeClass += ' ca-amount-value--short';
      }
      h += '<div class="ca-amount-row ca-amount-row--change"><span class="ca-amount-label">お釣り</span><span class="' + changeClass + '">' + (received >= total && total > 0 ? Utils.formatYen(change) : '-') + '</span></div>';
    }
    h += '</div>';
    return h;
  }

  function _renderTenkeyHtml() {
    if (_paymentMethod !== 'cash') return '';
    var h = '<div class="ca-tenkey">';
    h += '<div class="ca-tenkey__quick">';
    h += '<button class="ca-quick-btn" data-amount="1000">&yen;1,000</button>';
    h += '<button class="ca-quick-btn" data-amount="5000">&yen;5,000</button>';
    h += '<button class="ca-quick-btn" data-amount="10000">&yen;10,000</button>';
    h += '<button class="ca-quick-btn ca-quick-btn--exact" data-amount="exact">ぴったり</button>';
    h += '</div>';
    h += '<div class="ca-tenkey__grid">';
    ['7','8','9','4','5','6','1','2','3','00','0','C'].forEach(function (v) {
      var cls = 'ca-tenkey__btn' + (v === 'C' ? ' ca-tenkey__btn--clear' : '');
      h += '<button class="' + cls + '" data-val="' + v + '">' + v + '</button>';
    });
    h += '</div>';
    h += '<button class="ca-tenkey__btn ca-tenkey__btn--bs ca-tenkey__btn--wide" data-val="BS">&larr; 1字削除</button>';
    h += '</div>';
    return h;
  }

  // ── レジ描画（右カラム） ──
  function _renderRegister() {
    var container = document.getElementById('ca-register');

    if (!_selectedTableId) {
      var html0 = '';
      html0 += '<div class="ca-register__content">';
      html0 += '<div class="ca-items-panel">';
      html0 += '<div class="ca-register__placeholder">';
      html0 += '<div class="ca-register__placeholder-icon">&#x1F4B0;</div>';
      html0 += '<div>&larr; テーブルを選択してください</div>';
      html0 += '</div>';
      html0 += '</div>';
      html0 += '<div class="ca-operation">';
      html0 += _renderPayMethodsHtml();
      html0 += _renderAmountHtml(null);
      html0 += _renderTenkeyHtml();
      html0 += '<div class="ca-action-btns">';
      html0 += '<button class="ca-btn-receipt" id="ca-btn-receipt" disabled>レシートプレビュー</button>';
      html0 += '<button class="ca-btn-submit" id="ca-btn-submit" disabled>会計する</button>';
      html0 += '</div>';
      html0 += '</div>';
      html0 += '</div>';
      container.innerHTML = html0;
      return;
    }

    var isMerged = _mergeMode && _mergedGroupKeys.length >= 1;
    var group = isMerged ? null : _findGroup(_selectedTableId);
    if (!isMerged && !group) {
      container.innerHTML = '<div class="ca-register__placeholder">テーブルデータなし</div>';
      return;
    }

    var allChecked = _isAllChecked();
    var someChecked = _isSomeChecked();
    var isPartial = someChecked && (!allChecked || _hasPaidItems());
    var session = isMerged ? null : _sessions[group.tableId];
    var guestCount = (session && session.guestCount) ? session.guestCount : '';
    var calc = _calcTotal();

    // 合流モードのテーブル名
    var headerTableName = '';
    if (isMerged) {
      var mergedNames = [];
      _mergedGroupKeys.forEach(function (gk) {
        var mg = _findGroup(gk);
        if (mg) mergedNames.push(mg.tableCode);
      });
      headerTableName = mergedNames.join(' + ');
    } else {
      headerTableName = group.tableCode;
    }

    var html = '';

    // ── ヘッダー ──
    html += '<div class="ca-register__header">';
    html += '<div class="ca-register__header-left">';
    html += '<span class="ca-register__table-name">' + Utils.escapeHtml(headerTableName) + '</span>';
    if (isMerged) html += '<span class="ca-merge-badge">合流</span>';
    if (guestCount) html += '<span class="ca-register__guest-count">' + Utils.escapeHtml(String(guestCount)) + '名</span>';
    if (isPartial) html += '<span class="ca-partial-badge">個別会計</span>';
    html += '</div>';
    html += '<div class="ca-register__select-btns">';
    html += '<button class="ca-select-btn" id="ca-btn-select-all">全選択</button>';
    html += '<button class="ca-select-btn" id="ca-btn-deselect-all">全解除</button>';
    html += '</div>';
    html += '</div>';

    // ── コンテンツ ──
    html += '<div class="ca-register__content">';

    // ── 品目パネル ──
    html += '<div class="ca-items-panel">';

    // プラン情報
    if (_planInfo) {
      html += '<div class="ca-plan-badge">';
      html += '<div class="ca-plan-badge__name">' + Utils.escapeHtml(_planInfo.name) + '</div>';
      html += '<div class="ca-plan-badge__detail">' + Utils.formatYen(_planInfo.price) + ' x ' + _planInfo.guests + '名 = ' + Utils.formatYen(_planInfo.total) + '</div>';
      html += '</div>';
    }

    // 品目リスト
    if (_items.length === 0) {
      html += '<div class="ca-items-empty">注文がありません</div>';
    } else {
      html += '<div class="ca-items-list">';
      _items.forEach(function (item, i) {
        var checked = !!_checkedItems[i];
        var isPaid = !!item.paymentId;
        var lineTotal = item.price * item.qty;
        var optText = '';
        if (item.options && item.options.length > 0) {
          optText = '<span class="ca-item__options">'
            + item.options.map(function (o) { return Utils.escapeHtml(o.choiceName || o.name || ''); }).join(', ')
            + '</span>';
        }
        var taxLabel = item.taxRate === 8 ? '<span class="ca-tax-label ca-tax-label--8">軽減</span>' : '';
        var tableTag = (isMerged && item.tableCode) ? '<span class="ca-item__table-tag">' + Utils.escapeHtml(item.tableCode) + '</span>' : '';
        var paidLabel = isPaid ? ' <span class="ca-paid-label">支払済</span>' : '';

        var itemCls = 'ca-item';
        if (isPaid) itemCls += ' ca-item--paid';
        else if (!checked) itemCls += ' ca-item--unchecked';

        html += '<label class="' + itemCls + '">';
        html += '<input type="checkbox" class="ca-item__check" data-idx="' + i + '"' + (checked ? ' checked' : '') + (isPaid ? ' disabled' : '') + '>';
        html += '<div class="ca-item__detail">';
        html += '<div class="ca-item__name">' + tableTag + Utils.escapeHtml(item.name) + taxLabel + paidLabel + '</div>';
        html += optText;
        html += '</div>';
        html += '<span class="ca-item__qty">x' + item.qty + '</span>';
        html += '<span class="ca-item__price">' + Utils.formatYen(lineTotal) + '</span>';
        html += '</label>';
      });
      html += '</div>';
    }

    // 合計エリア
    html += '<div class="ca-summary">';
    // 小計
    html += '<div class="ca-summary__row"><span>小計</span><span>' + Utils.formatYen(calc.total) + '</span></div>';
    // 税内訳
    if (calc.subtotal10 + calc.tax10 > 0) {
      html += '<div class="ca-summary__row ca-summary__row--sub"><span>&nbsp;10%対象 ' + Utils.formatYen(calc.subtotal10 + calc.tax10) + ' (税 ' + Utils.formatYen(calc.tax10) + ')</span><span></span></div>';
    }
    if (calc.subtotal8 + calc.tax8 > 0) {
      html += '<div class="ca-summary__row ca-summary__row--sub"><span>&nbsp;8%対象 ' + Utils.formatYen(calc.subtotal8 + calc.tax8) + ' (税 ' + Utils.formatYen(calc.tax8) + ')</span><span></span></div>';
    }
    // 割引
    if (calc.discountAmount > 0) {
      html += '<div class="ca-summary__row ca-summary__row--discount"><span>割引</span><span>-' + Utils.formatYen(calc.discountAmount) + '</span></div>';
    }
    // 合計
    html += '<div class="ca-summary__total"><span>合計</span><span>' + Utils.formatYen(calc.finalTotal) + '</span></div>';
    html += '</div>';

    html += '</div>'; // ca-items-panel

    // ── 操作パネル ──
    html += '<div class="ca-operation">';

    // 割引
    html += '<div class="ca-discount">';
    html += '<div class="ca-discount__header">';
    html += '<span class="ca-discount__label">割引</span>';
    html += '<div class="ca-discount__type-btns">';
    html += '<button class="ca-discount__type-btn' + (_discountType === 'amount' ? ' active' : '') + '" data-dtype="amount">金額</button>';
    html += '<button class="ca-discount__type-btn' + (_discountType === 'percent' ? ' active' : '') + '" data-dtype="percent">%</button>';
    html += '</div>';
    html += '</div>';
    html += '<div class="ca-discount__input-row">';
    html += '<input type="text" class="ca-discount__input" id="ca-discount-input" value="' + (_discountValue || '') + '" inputmode="numeric" placeholder="0">';
    html += '<span class="ca-discount__unit">' + (_discountType === 'percent' ? '%' : '円') + '</span>';
    html += '<button class="ca-discount__apply" id="ca-discount-apply">適用</button>';
    html += '<button class="ca-discount__clear" id="ca-discount-clear">取消</button>';
    html += '</div>';
    html += '</div>';

    // 支払方法（ヘルパー）
    html += _renderPayMethodsHtml();

    // 金額表示（ヘルパー）
    var received = parseInt(_receivedInput, 10) || 0;
    var canPay = calc.finalTotal > 0 && someChecked && !_isSubmitting;
    if (_paymentMethod === 'cash') {
      canPay = canPay && received >= calc.finalTotal;
    }
    html += _renderAmountHtml(calc);

    // テンキー（ヘルパー）
    html += _renderTenkeyHtml();

    // ボタンエリア
    var submitLabel = _isSubmitting ? '処理中...' : (isPartial ? '個別会計する' : '会計する');
    html += '<div class="ca-action-btns">';
    html += '<button class="ca-btn-receipt" id="ca-btn-receipt"' + (calc.finalTotal > 0 && someChecked ? '' : ' disabled') + '>レシートプレビュー</button>';
    html += '<button class="ca-btn-submit' + (isPartial ? ' ca-btn-submit--partial' : '') + '" id="ca-btn-submit"' + (canPay ? '' : ' disabled') + '>' + submitLabel + '</button>';
    html += '</div>';

    html += '</div>'; // ca-operation
    html += '</div>'; // ca-register__content

    container.innerHTML = html;
  }

  // ── 合計計算 ──
  function _calcTotal() {
    var result = { subtotal10: 0, tax10: 0, subtotal8: 0, tax8: 0, total: 0, discountAmount: 0, finalTotal: 0 };

    // プランで全選択の場合
    if (_planInfo && _isAllChecked()) {
      result.total = _planInfo.total;
      result.subtotal10 = Math.floor(_planInfo.total / 1.10);
      result.tax10 = _planInfo.total - result.subtotal10;
    } else {
      _items.forEach(function (item, i) {
        if (!_checkedItems[i]) return;
        var lineTotal = item.price * item.qty;
        result.total += lineTotal;

        if (item.taxRate === 8) {
          var sub8 = Math.floor(lineTotal / 1.08);
          result.subtotal8 += sub8;
          result.tax8 += lineTotal - sub8;
        } else {
          var sub10 = Math.floor(lineTotal / 1.10);
          result.subtotal10 += sub10;
          result.tax10 += lineTotal - sub10;
        }
      });
    }

    // 割引計算（適用済みの場合のみ）
    if (_discountApplied && _discountValue > 0) {
      if (_discountType === 'percent') {
        result.discountAmount = Math.floor(result.total * _discountValue / 100);
      } else {
        result.discountAmount = _discountValue;
      }
    }

    result.finalTotal = Math.max(0, result.total - result.discountAmount);
    return result;
  }

  function _isAllChecked() {
    if (_items.length === 0) return false;
    for (var i = 0; i < _items.length; i++) {
      if (_items[i].paymentId) continue; // 支払済みはスキップ
      if (!_checkedItems[i]) return false;
    }
    return true;
  }

  function _isSomeChecked() {
    for (var i = 0; i < _items.length; i++) {
      if (_checkedItems[i]) return true;
    }
    return false;
  }

  function _hasPaidItems() {
    for (var i = 0; i < _items.length; i++) {
      if (_items[i].paymentId) return true;
    }
    return false;
  }

  // ── レシートプレビュー ──
  function _showReceiptPreview() {
    var calc = _calcTotal();
    var isMergedPreview = _mergeMode && _mergedGroupKeys.length >= 1;
    var group = isMergedPreview ? null : _findGroup(_selectedTableId);
    if (!isMergedPreview && !group) return;

    var isPartial = _isSomeChecked() && (!_isAllChecked() || _hasPaidItems());
    var received = parseInt(_receivedInput, 10) || 0;
    var change = _paymentMethod === 'cash' ? Math.max(0, received - calc.finalTotal) : 0;
    var shopName = _receiptSettings.receipt_store_name || _storeName || '';
    var shopAddr = _receiptSettings.receipt_address || '';
    var shopPhone = _receiptSettings.receipt_phone || '';
    var shopFooter = _receiptSettings.receipt_footer || '';

    var html = '';
    // 店舗情報
    html += '<div class="ca-receipt__shop">';
    html += '<div class="ca-receipt__shop-name">' + Utils.escapeHtml(shopName) + '</div>';
    if (shopAddr) html += '<div class="ca-receipt__shop-info">' + Utils.escapeHtml(shopAddr) + '</div>';
    if (shopPhone) html += '<div class="ca-receipt__shop-info">TEL: ' + Utils.escapeHtml(shopPhone) + '</div>';
    html += '</div>';

    html += '<div class="ca-receipt__divider"></div>';

    // テーブル
    var receiptTableName = '';
    if (isMergedPreview) {
      var rNames = [];
      _mergedGroupKeys.forEach(function (gk) {
        var mg = _findGroup(gk);
        if (mg) rNames.push(mg.tableCode);
      });
      receiptTableName = rNames.join(' + ') + ' (合流)';
    } else {
      receiptTableName = group.tableCode;
    }
    html += '<div class="ca-receipt__table">' + Utils.escapeHtml(receiptTableName) + '</div>';
    if (isPartial) html += '<div class="ca-receipt__partial">個別会計</div>';

    html += '<div class="ca-receipt__divider"></div>';

    // 品目
    html += '<div class="ca-receipt__items">';
    _items.forEach(function (item, i) {
      if (!_checkedItems[i]) return;
      var lineTotal = item.price * item.qty;
      var taxMark = item.taxRate === 8 ? ' *' : '';
      html += '<div class="ca-receipt__item-row">';
      html += '<span>' + Utils.escapeHtml(item.name) + taxMark + ' x' + item.qty + '</span>';
      html += '<span>' + Utils.formatYen(lineTotal) + '</span>';
      html += '</div>';
    });
    if (calc.discountAmount > 0) {
      html += '<div class="ca-receipt__item-row ca-receipt__item-row--discount">';
      html += '<span>割引</span><span>-' + Utils.formatYen(calc.discountAmount) + '</span>';
      html += '</div>';
    }
    html += '</div>';

    html += '<div class="ca-receipt__divider"></div>';

    // 税内訳
    if (calc.subtotal10 + calc.tax10 > 0) {
      html += '<div class="ca-receipt__tax-row"><span>10%対象</span><span>' + Utils.formatYen(calc.subtotal10 + calc.tax10) + '</span></div>';
      html += '<div class="ca-receipt__tax-row"><span>&nbsp;(税 ' + Utils.formatYen(calc.tax10) + ')</span><span></span></div>';
    }
    if (calc.subtotal8 + calc.tax8 > 0) {
      html += '<div class="ca-receipt__tax-row"><span>8%対象 *</span><span>' + Utils.formatYen(calc.subtotal8 + calc.tax8) + '</span></div>';
      html += '<div class="ca-receipt__tax-row"><span>&nbsp;(税 ' + Utils.formatYen(calc.tax8) + ')</span><span></span></div>';
    }

    // 合計
    html += '<div class="ca-receipt__total-row"><span>合計</span><span>' + Utils.formatYen(calc.finalTotal) + '</span></div>';

    html += '<div class="ca-receipt__divider"></div>';

    // 支払
    html += '<div class="ca-receipt__payment-row"><span>支払方法</span><span>' + (_PAY_LABELS[_paymentMethod] || '') + '</span></div>';
    if (_paymentMethod === 'cash') {
      html += '<div class="ca-receipt__payment-row"><span>預かり</span><span>' + Utils.formatYen(received) + '</span></div>';
      html += '<div class="ca-receipt__change-row"><span>お釣り</span><span>' + Utils.formatYen(change) + '</span></div>';
    }

    // 日時
    var now = new Date();
    var dtStr = now.getFullYear() + '/' + ('0' + (now.getMonth() + 1)).slice(-2) + '/' + ('0' + now.getDate()).slice(-2)
      + ' ' + ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
    html += '<div class="ca-receipt__datetime">' + dtStr + '</div>';

    if (shopFooter) {
      html += '<div class="ca-receipt__footer">' + Utils.escapeHtml(shopFooter) + '</div>';
    }

    // ボタン
    html += '<div class="ca-receipt__actions">';
    html += '<button class="ca-receipt__btn ca-receipt__btn--print" id="ca-receipt-print">レシート印刷</button>';
    if (_lastPaymentId) {
      html += '<button class="ca-receipt__btn ca-receipt__btn--invoice" id="ca-receipt-invoice">領収書</button>';
    }
    html += '<button class="ca-receipt__btn ca-receipt__btn--close" id="ca-receipt-close">閉じる</button>';
    html += '</div>';

    document.getElementById('ca-receipt-body').innerHTML = html;
    document.getElementById('ca-receipt-modal').classList.add('show');

    var invoiceBtn = document.getElementById('ca-receipt-invoice');
    if (invoiceBtn) {
      invoiceBtn.addEventListener('click', _issueReceipt);
    }
  }

  function _closeReceiptModal() {
    document.getElementById('ca-receipt-modal').classList.remove('show');
    // L-5: 決済完了後のリセット（レシート表示→閉じるの流れ）
    if (_lastPaymentId) {
      _lastPaymentId = null;
      _selectedTableId = null;
      _mergeMode = false;
      _mergedGroupKeys = [];
      _resetRegisterState();
      _renderRegister();
      _startPolling();
      _fetchSalesSummary();
    }
  }

  // ── L-5: 領収書発行 ──
  function _issueReceipt() {
    if (!_lastPaymentId) {
      _showToast('支払い情報がありません', 'error');
      return;
    }
    var addressee = prompt('宛名を入力してください（空欄の場合「上様」）');
    if (addressee === null) return;
    if (!addressee) addressee = '上様';

    var receiptType = (_receiptSettings.registration_number) ? 'invoice' : 'receipt';

    fetch('../../api/store/receipt.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        payment_id: _lastPaymentId,
        receipt_type: receiptType,
        addressee: addressee
      }),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (text) {
        try { return JSON.parse(text); }
        catch (e) { throw new Error(text.substring(0, 200)); }
      });
    }).then(function (json) {
      if (!json.ok) {
        _showToast(json.error ? (json.error.message || 'エラー') : 'エラー', 'error');
        return;
      }
      _printReceiptHtml(json.data);
      _showToast('領収書を発行しました', 'success');
    }).catch(function (err) {
      _showToast(err.message || '通信エラー', 'error');
    });
  }

  function _printReceiptHtml(data) {
    var r = data.receipt;
    var s = data.store;
    var items = data.items || [];
    var pay = data.payment;

    var w = window.open('', '_blank', 'width=320,height=600');
    if (!w) {
      _showToast('ポップアップがブロックされました', 'error');
      return;
    }

    var h = '<!DOCTYPE html><html><head><meta charset="utf-8">';
    h += '<title>領収書</title>';
    h += '<style>';
    h += 'body{font-family:sans-serif;width:72mm;margin:0 auto;padding:4mm;font-size:12px;line-height:1.4;}';
    h += '.center{text-align:center;} .right{text-align:right;} .bold{font-weight:bold;}';
    h += '.divider{border-top:1px dashed #000;margin:4px 0;}';
    h += '.row{display:flex;justify-content:space-between;}';
    h += '.title{font-size:16px;font-weight:bold;text-align:center;margin:8px 0;letter-spacing:2px;}';
    h += '.small{font-size:10px;color:#555;}';
    h += '@media print{body{margin:0;padding:2mm;} .no-print{display:none;}}';
    h += '</style></head><body>';

    h += '<div class="title">' + (r.receipt_type === 'invoice' ? '適格簡易請求書' : '領収書') + '</div>';

    h += '<div class="center bold">' + _escPrint(r.addressee) + ' 様</div>';
    h += '<div class="divider"></div>';

    h += '<div class="center">' + _escPrint(s.receipt_store_name || '') + '</div>';
    if (s.receipt_address) h += '<div class="center small">' + _escPrint(s.receipt_address) + '</div>';
    if (s.receipt_phone) h += '<div class="center small">TEL: ' + _escPrint(s.receipt_phone) + '</div>';
    if (r.receipt_type === 'invoice' && s.registration_number) {
      h += '<div class="center small">登録番号: ' + _escPrint(s.registration_number) + '</div>';
      if (s.business_name) h += '<div class="center small">' + _escPrint(s.business_name) + '</div>';
    }
    h += '<div class="divider"></div>';

    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      var taxMark = (it.taxRate === 8 || it.tax_rate === 8) ? ' ※' : '';
      var lineTotal = it.price * it.qty;
      h += '<div class="row"><span>' + _escPrint(it.name) + taxMark + ' x' + it.qty + '</span><span>&yen;' + lineTotal.toLocaleString() + '</span></div>';
    }
    h += '<div class="divider"></div>';

    if (r.subtotal_10 > 0) {
      h += '<div class="row"><span>10%対象</span><span>&yen;' + (r.subtotal_10 + r.tax_10).toLocaleString() + '</span></div>';
      h += '<div class="row small"><span>　(税 &yen;' + r.tax_10.toLocaleString() + ')</span><span></span></div>';
    }
    if (r.subtotal_8 > 0) {
      h += '<div class="row"><span>8%対象 ※</span><span>&yen;' + (r.subtotal_8 + r.tax_8).toLocaleString() + '</span></div>';
      h += '<div class="row small"><span>　(税 &yen;' + r.tax_8.toLocaleString() + ')</span><span></span></div>';
    }

    h += '<div class="divider"></div>';
    h += '<div class="row bold" style="font-size:14px;"><span>合計</span><span>&yen;' + r.total_amount.toLocaleString() + '</span></div>';

    var methodLabel = {cash:'現金',card:'カード',qr:'QR決済',terminal:'カード'}[pay.payment_method] || pay.payment_method;
    h += '<div class="row"><span>支払方法</span><span>' + methodLabel + '</span></div>';

    h += '<div class="divider"></div>';
    h += '<div class="center small">No. ' + _escPrint(r.receipt_number) + '</div>';
    h += '<div class="center small">' + _escPrint(r.issued_at) + '</div>';

    if (s.receipt_footer) {
      h += '<div class="divider"></div>';
      h += '<div class="center small">' + _escPrint(s.receipt_footer) + '</div>';
    }

    h += '</body></html>';

    w.document.write(h);
    w.document.close();
    w.focus();
    w.print();
    w.onafterprint = function () { w.close(); };
  }

  function _escPrint(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ── 会計送信 ──
  function _submitPayment() {
    if (_isSubmitting) return;

    var isMergedSubmit = _mergeMode && _mergedGroupKeys.length >= 2;
    var group = isMergedSubmit ? null : _findGroup(_selectedTableId);
    if (!isMergedSubmit && !group) return;

    var allChecked = _isAllChecked();
    var isPartial = _isSomeChecked() && (!allChecked || _hasPaidItems());
    var calc = _calcTotal();

    // 確認ダイアログ
    var confirmMsg = Utils.formatYen(calc.finalTotal) + ' を ' + _PAY_LABELS[_paymentMethod] + ' で会計しますか？';
    if (isPartial) confirmMsg = '【個別会計】' + confirmMsg;
    if (!confirm(confirmMsg)) return;

    var isMerged = _mergeMode && _mergedGroupKeys.length >= 2;

    var body = {
      store_id: _storeId,
      payment_method: _paymentMethod
    };

    // 合流モードの場合
    if (isMerged) {
      var mergedTableIds = [];
      var mergedOrderIds = [];
      _mergedGroupKeys.forEach(function (gk) {
        var mg = _findGroup(gk);
        if (mg) {
          if (mg.tableId) mergedTableIds.push(mg.tableId);
          mergedOrderIds = mergedOrderIds.concat(mg.orderIds);
        }
      });
      body.merged_table_ids = mergedTableIds;
      body.order_ids = mergedOrderIds;
      body.table_id = mergedTableIds[0] || null;
    } else {
      if (group.tableId) body.table_id = group.tableId;
      if (group.orderIds.length > 0) body.order_ids = group.orderIds;
    }

    if (_paymentMethod === 'cash') {
      body.received_amount = parseInt(_receivedInput, 10) || 0;
    }

    // 個別会計 or 割引ありの場合は selected_items + total_override
    if (isPartial || calc.discountAmount > 0) {
      body.selected_items = _items.filter(function (item, i) {
        return !!_checkedItems[i];
      }).map(function (it) {
        return { name: it.name, qty: it.qty, price: it.price, taxRate: it.taxRate };
      });
      body.total_override = calc.finalTotal;
    }

    // 分割会計: チェック済み品目の itemId を送信
    if (isPartial) {
      var selIds = [];
      _items.forEach(function (item, i) {
        if (_checkedItems[i] && item.itemId) selIds.push(item.itemId);
      });
      if (selIds.length > 0) body.selected_item_ids = selIds;
    }

    // 二重送信防止 + ポーリング一時停止
    _isSubmitting = true;
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
    _renderRegister();

    // L-13: Stripe Terminal 経由（Connect + 物理カードリーダー）
    if (_terminal && _terminalConnected && _paymentMethod !== 'cash') {
      _processTerminalPayment(body, calc);
      return;
    }

    // カード/QR決済時はローディング表示
    var gwOverlay = null;
    if (_paymentMethod !== 'cash') {
      gwOverlay = document.createElement('div');
      gwOverlay.id = 'ca-gw-overlay';
      gwOverlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:9999;';
      gwOverlay.innerHTML = '<div style="background:#fff;padding:2rem 3rem;border-radius:12px;text-align:center;">'
        + '<div style="font-size:2rem;margin-bottom:0.5rem;">&#128179;</div>'
        + '<div style="font-size:1.1rem;font-weight:600;color:#333;">決済処理中...</div>'
        + '<div style="font-size:0.85rem;color:#888;margin-top:0.5rem;">しばらくお待ちください</div>'
        + '</div>';
      document.body.appendChild(gwOverlay);
    }

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
      _isSubmitting = false;
      // オーバーレイ除去
      var ov = document.getElementById('ca-gw-overlay');
      if (ov) ov.parentNode.removeChild(ov);

      if (!json.ok) {
        _playError();
        var errCode = (json.error && json.error.code) || '';
        var errMsg = (json.error && json.error.message) || 'エラー';
        if (errCode === 'GATEWAY_ERROR') {
          _showToast('決済エラー: ' + errMsg, 'error');
        } else {
          _showToast(errMsg, 'error');
        }
        _startPolling();
        _renderRegister();
        return;
      }

      var d = json.data;

      _playSuccess();
      var toastMsg = '会計完了: ' + Utils.formatYen(d.totalAmount);
      if (d.gatewayName === 'stripe') toastMsg += ' (Stripe決済完了)';
      _showToast(toastMsg, 'success');
      _lastPaymentId = d.payment_id;
      _showReceiptPreview();
      // リセットは _closeReceiptModal() 内で実行
    }).catch(function (err) {
      _isSubmitting = false;
      var ov = document.getElementById('ca-gw-overlay');
      if (ov) ov.parentNode.removeChild(ov);
      _playError();
      _showToast(err.message || '通信エラー', 'error');
      _startPolling();
      _renderRegister();
    });
  }

  // ── トースト ──
  var _toastTimer = null;
  function _showToast(msg, type) {
    var el = document.getElementById('ca-toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'ca-toast show';
    if (type === 'success') el.classList.add('ca-toast--success');
    if (type === 'error') el.classList.add('ca-toast--error');
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function () { el.classList.remove('show'); }, 3000);
  }

  // ── スライドパネル共通 ──
  function _openSlidePanel(panelId) {
    if (_openPanel) _closeSlidePanel();
    _openPanel = panelId;
    var panel = document.getElementById('ca-' + panelId + '-panel');
    var overlay = document.getElementById('ca-panel-overlay');
    if (panel) panel.classList.add('open');
    if (overlay) overlay.classList.add('show');
  }

  function _closeSlidePanel() {
    if (!_openPanel) return;
    var panel = document.getElementById('ca-' + _openPanel + '-panel');
    var overlay = document.getElementById('ca-panel-overlay');
    if (panel) panel.classList.remove('open');
    if (overlay) overlay.classList.remove('show');
    _openPanel = null;
  }

  // ── 取引ジャーナル ──
  function _openJournal() {
    _openSlidePanel('journal');
    _fetchJournal();
  }

  function _fetchJournal() {
    var body = document.getElementById('ca-journal-body');
    if (!body) return;
    body.innerHTML = '<div class="ca-journal__empty">読み込み中...</div>';

    var today = _todayStr();
    var url = '../../api/store/payments-journal.php?store_id=' + encodeURIComponent(_storeId) + '&from=' + today + '&to=' + today;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data) {
          _journalData = json.data;
          _renderJournal();
        } else {
          body.innerHTML = '<div class="ca-journal__empty">データを取得できませんでした</div>';
        }
      })
      .catch(function () {
        body.innerHTML = '<div class="ca-journal__empty">通信エラー</div>';
      });
  }

  function _renderJournal() {
    var body = document.getElementById('ca-journal-body');
    if (!body || !_journalData) return;

    var payments = _journalData.payments || [];
    var summary = _journalData.summary || { count: 0, total: 0 };

    var html = '';

    // サマリー
    html += '<div class="ca-journal__summary">';
    html += '<div class="ca-journal__stat"><div class="ca-journal__stat-label">取引件数</div><div class="ca-journal__stat-value">' + summary.count + '</div></div>';
    html += '<div class="ca-journal__stat"><div class="ca-journal__stat-label">合計金額</div><div class="ca-journal__stat-value">' + Utils.formatYen(summary.total) + '</div></div>';
    html += '</div>';

    if (payments.length === 0) {
      html += '<div class="ca-journal__empty">本日の取引はありません</div>';
      body.innerHTML = html;
      return;
    }

    html += '<div class="ca-journal__list">';
    payments.forEach(function (p) {
      var paidAt = p.paid_at || '';
      var timeStr = '';
      if (paidAt) {
        var d = new Date(paidAt);
        timeStr = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
      }
      var tableCode = p.table_code || '-';
      var amount = parseInt(p.total_amount, 10) || 0;
      var method = p.payment_method || 'cash';
      var staffName = p.staff_name || '-';
      var isPartial = parseInt(p.is_partial, 10) === 1;

      html += '<div class="ca-journal__item">';
      html += '<span class="ca-journal__item-time">' + Utils.escapeHtml(timeStr) + '</span>';
      html += '<span class="ca-journal__item-table">' + Utils.escapeHtml(tableCode) + (isPartial ? ' *' : '') + '</span>';
      html += '<span class="ca-journal__item-method ca-journal__item-method--' + method + '">' + (_PAY_LABELS[method] || method) + '</span>';
      html += '<span class="ca-journal__item-staff">' + Utils.escapeHtml(staffName) + '</span>';
      html += '<span class="ca-journal__item-amount">' + Utils.formatYen(amount) + '</span>';
      html += '</div>';
    });
    html += '</div>';

    body.innerHTML = html;
  }

  // ── レジ開閉 ──
  function _openRegisterCtrl() {
    _openSlidePanel('register-ctrl');
    _fetchCashLog();
  }

  function _fetchCashLog() {
    var url = '../../api/kds/cash-log.php?store_id=' + encodeURIComponent(_storeId);
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data) {
          _cashLogEntries = json.data.entries || [];
          // レジ開閉状態判定
          _registerOpen = false;
          for (var i = _cashLogEntries.length - 1; i >= 0; i--) {
            var entry = _cashLogEntries[i];
            if (entry.type === 'open') { _registerOpen = true; break; }
            if (entry.type === 'close') { _registerOpen = false; break; }
          }
          _renderRegisterCtrl();
        }
      })
      .catch(function () {
        var body = document.getElementById('ca-register-ctrl-body');
        if (body) body.innerHTML = '<div class="ca-journal__empty">通信エラー</div>';
      });
  }

  function _renderRegisterCtrl() {
    var body = document.getElementById('ca-register-ctrl-body');
    if (!body) return;

    var html = '';

    // ステータス
    html += '<div class="ca-register-ctrl__status">';
    if (_registerOpen) {
      html += '<span class="ca-register-ctrl__status-badge ca-register-ctrl__status-badge--open">レジ開放中</span>';
    } else {
      html += '<span class="ca-register-ctrl__status-badge ca-register-ctrl__status-badge--closed">レジ未開放</span>';
    }
    html += '</div>';

    // フォーム
    if (!_registerOpen) {
      // レジ開け
      html += '<div class="ca-register-ctrl__form">';
      html += '<div class="ca-register-ctrl__form-title">レジ開け</div>';
      html += '<div class="ca-register-ctrl__input-group">';
      html += '<label class="ca-register-ctrl__input-label">開始在高（円）</label>';
      html += '<input type="text" class="ca-register-ctrl__input" id="ca-reg-open-amount" inputmode="numeric" placeholder="0" value="">';
      html += '</div>';
      html += '<button class="ca-register-ctrl__btn ca-register-ctrl__btn--open" id="ca-reg-open-btn">レジを開ける</button>';
      html += '</div>';
    } else {
      // 入出金フォーム
      html += '<div class="ca-register-ctrl__form">';
      html += '<div class="ca-register-ctrl__form-title">入出金</div>';
      html += '<div class="ca-register-ctrl__input-group">';
      html += '<label class="ca-register-ctrl__input-label">金額（円）</label>';
      html += '<input type="text" class="ca-register-ctrl__input" id="ca-reg-io-amount" inputmode="numeric" placeholder="0">';
      html += '</div>';
      html += '<div class="ca-register-ctrl__input-group">';
      html += '<label class="ca-register-ctrl__input-label">備考</label>';
      html += '<input type="text" class="ca-register-ctrl__input ca-register-ctrl__input--text" id="ca-reg-io-note" placeholder="例: つり銭準備">';
      html += '</div>';
      html += '<div style="display:flex;gap:0.375rem;">';
      html += '<button class="ca-register-ctrl__btn ca-register-ctrl__btn--action" id="ca-reg-cashin-btn" style="flex:1;">入金</button>';
      html += '<button class="ca-register-ctrl__btn ca-register-ctrl__btn--action" id="ca-reg-cashout-btn" style="flex:1;background:var(--ca-warn);color:var(--ca-bg-primary);">出金</button>';
      html += '</div>';
      html += '</div>';

      // レジ閉め
      html += '<div class="ca-register-ctrl__form" style="margin-top:0.5rem;">';
      html += '<div class="ca-register-ctrl__form-title">レジ閉め</div>';
      html += '<div class="ca-register-ctrl__input-group">';
      html += '<label class="ca-register-ctrl__input-label">実際の現金額（円）</label>';
      html += '<input type="text" class="ca-register-ctrl__input" id="ca-reg-close-amount" inputmode="numeric" placeholder="0" oninput="CashierApp._onCloseAmountInput()">';
      html += '</div>';

      // 予想在高との差額
      var expected = _calcExpectedBalance();
      html += '<div class="ca-register-ctrl__input-group">';
      html += '<label class="ca-register-ctrl__input-label">予想在高: ' + Utils.formatYen(expected) + '</label>';
      html += '<div class="ca-register-ctrl__diff" id="ca-reg-close-diff">--</div>';
      html += '</div>';

      html += '<button class="ca-register-ctrl__btn ca-register-ctrl__btn--close" id="ca-reg-close-btn">レジを閉める</button>';
      html += '</div>';
    }

    // 入出金履歴
    if (_cashLogEntries.length > 0) {
      html += '<div class="ca-register-ctrl__log-title">本日の入出金履歴</div>';
      var typeLabels = { open: '開始', close: '閉め', cash_in: '入金', cash_out: '出金', cash_sale: '売上' };
      _cashLogEntries.forEach(function (entry) {
        var time = '';
        if (entry.created_at) {
          var d = new Date(entry.created_at);
          time = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
        }
        var typeCls = 'ca-register-ctrl__log-type';
        if (entry.type === 'open') typeCls += ' ca-register-ctrl__log-type--open';
        else if (entry.type === 'close') typeCls += ' ca-register-ctrl__log-type--close';
        else if (entry.type === 'cash_in') typeCls += ' ca-register-ctrl__log-type--in';
        else if (entry.type === 'cash_out') typeCls += ' ca-register-ctrl__log-type--out';
        else if (entry.type === 'cash_sale') typeCls += ' ca-register-ctrl__log-type--sale';

        html += '<div class="ca-register-ctrl__log-item">';
        html += '<span class="ca-register-ctrl__log-time">' + time + '</span>';
        html += '<span class="' + typeCls + '">' + (typeLabels[entry.type] || entry.type) + '</span>';
        if (entry.note) html += '<span class="ca-register-ctrl__log-note">' + Utils.escapeHtml(entry.note) + '</span>';
        html += '<span class="ca-register-ctrl__log-amount">' + Utils.formatYen(parseInt(entry.amount, 10) || 0) + '</span>';
        html += '</div>';
      });
    }

    body.innerHTML = html;
  }

  function _calcExpectedBalance() {
    var balance = 0;
    _cashLogEntries.forEach(function (e) {
      var amt = parseInt(e.amount, 10) || 0;
      if (e.type === 'open' || e.type === 'cash_in' || e.type === 'cash_sale') {
        balance += amt;
      } else if (e.type === 'cash_out') {
        balance -= amt;
      }
    });
    return balance;
  }

  // レジ閉め金額入力時の差額リアルタイム計算
  CashierApp._onCloseAmountInput = function () {
    var input = document.getElementById('ca-reg-close-amount');
    var diffEl = document.getElementById('ca-reg-close-diff');
    if (!input || !diffEl) return;
    var actual = parseInt(input.value, 10) || 0;
    var expected = _calcExpectedBalance();
    var diff = actual - expected;
    if (diff > 0) {
      diffEl.textContent = '+' + Utils.formatYen(diff) + ' 過剰';
      diffEl.className = 'ca-register-ctrl__diff ca-register-ctrl__diff--plus';
    } else if (diff < 0) {
      diffEl.textContent = Utils.formatYen(diff) + ' 不足';
      diffEl.className = 'ca-register-ctrl__diff ca-register-ctrl__diff--minus';
    } else {
      diffEl.textContent = Utils.formatYen(0) + ' ぴったり';
      diffEl.className = 'ca-register-ctrl__diff ca-register-ctrl__diff--zero';
    }
  };

  function _postCashLog(type, amount, note, callback) {
    fetch('../../api/kds/cash-log.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        store_id: _storeId,
        type: type,
        amount: amount,
        note: note || ''
      }),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { return {}; }
      });
    }).then(function (json) {
      if (json.ok || (json.data && json.data.ok)) {
        _playClick();
        _fetchCashLog();
        if (callback) callback(true);
      } else {
        _playError();
        _showToast('エラー: ' + ((json.error && json.error.message) || '不明'), 'error');
        if (callback) callback(false);
      }
    }).catch(function () {
      _playError();
      _showToast('通信エラー', 'error');
      if (callback) callback(false);
    });
  }

  // ── キーボードショートカット ──
  function _bindKeyboard() {
    document.addEventListener('keydown', function (e) {
      // input/textarea にフォーカス中は無視（ただし特定キーは除く）
      var tag = (e.target.tagName || '').toLowerCase();
      var inInput = (tag === 'input' || tag === 'textarea');

      // Escape: 常に動作
      if (e.key === 'Escape') {
        e.preventDefault();
        if (_openPanel) {
          _closeSlidePanel();
        } else if (document.getElementById('ca-receipt-modal').classList.contains('show')) {
          _closeReceiptModal();
        } else if (_receivedInput) {
          _receivedInput = '';
          _renderRegister();
        }
        return;
      }

      // input内ではテンキーショートカットを無効にする
      if (inInput) return;

      // 0-9: テンキー入力
      if (_paymentMethod === 'cash' && _selectedTableId && !_openPanel) {
        if (e.key >= '0' && e.key <= '9') {
          e.preventDefault();
          if (_receivedInput.length < 7) {
            _receivedInput += e.key;
            _playClick();
            _renderRegister();
          }
          return;
        }
      }

      // Backspace: 1桁削除
      if (e.key === 'Backspace' && _paymentMethod === 'cash' && _selectedTableId && !_openPanel) {
        e.preventDefault();
        _receivedInput = _receivedInput.slice(0, -1);
        _renderRegister();
        return;
      }

      // Enter: 会計実行
      if (e.key === 'Enter' && _selectedTableId && !_openPanel) {
        e.preventDefault();
        var submitBtn = document.getElementById('ca-btn-submit');
        if (submitBtn && !submitBtn.disabled) {
          _submitPayment();
        }
        return;
      }

      // F1/F2/F3: 決済方法切替
      if (e.key === 'F1') {
        e.preventDefault();
        _paymentMethod = 'cash';
        _receivedInput = '';
        _playClick();
        _renderRegister();
        return;
      }
      if (e.key === 'F2') {
        e.preventDefault();
        _paymentMethod = 'card';
        var calc2 = _calcTotal();
        _receivedInput = String(calc2.finalTotal);
        _playClick();
        _renderRegister();
        return;
      }
      if (e.key === 'F3') {
        e.preventDefault();
        _paymentMethod = 'qr';
        var calc3 = _calcTotal();
        _receivedInput = String(calc3.finalTotal);
        _playClick();
        _renderRegister();
        return;
      }

      // J: ジャーナル
      if (e.key === 'j' || e.key === 'J') {
        e.preventDefault();
        if (_openPanel === 'journal') {
          _closeSlidePanel();
        } else {
          _openJournal();
        }
        return;
      }

      // R: レシートプレビュー
      if ((e.key === 'r' || e.key === 'R') && _selectedTableId) {
        e.preventDefault();
        var receiptBtn = document.getElementById('ca-btn-receipt');
        if (receiptBtn && !receiptBtn.disabled) {
          _showReceiptPreview();
        }
        return;
      }
    });
  }

  // ── イベントバインド ──
  function _bindEvents() {
    var tableBody = document.getElementById('ca-table-body');
    var register = document.getElementById('ca-register');
    var receiptModal = document.getElementById('ca-receipt-modal');
    var overlay = document.getElementById('ca-panel-overlay');

    // 合流トグルボタン
    var mergeToggle = document.getElementById('ca-btn-merge-toggle');
    if (mergeToggle) {
      mergeToggle.addEventListener('click', function () {
        _toggleMergeMode();
      });
    }

    // テーブルカードクリック
    tableBody.addEventListener('click', function (e) {
      var card = e.target.closest('.ca-table-card');
      if (!card) return;
      _selectTable(card.dataset.groupKey);
    });

    // レジ内イベント委譲
    register.addEventListener('click', function (e) {
      // 全選択（支払済みはスキップ）
      if (e.target.closest('#ca-btn-select-all')) {
        _items.forEach(function (item, i) {
          if (!item.paymentId) _checkedItems[i] = true;
        });
        _receivedInput = '';
        _playClick();
        _renderRegister();
        return;
      }

      // 全解除（支払済みはスキップ）
      if (e.target.closest('#ca-btn-deselect-all')) {
        _items.forEach(function (item, i) {
          if (!item.paymentId) _checkedItems[i] = false;
        });
        _receivedInput = '';
        _playClick();
        _renderRegister();
        return;
      }

      // 割引タイプ切替
      var dtypeBtn = e.target.closest('.ca-discount__type-btn');
      if (dtypeBtn) {
        _discountType = dtypeBtn.dataset.dtype;
        _discountValue = 0;
        _discountApplied = false;
        _playClick();
        _renderRegister();
        return;
      }

      // 割引適用
      if (e.target.closest('#ca-discount-apply')) {
        var inp = document.getElementById('ca-discount-input');
        if (inp) {
          var v = parseInt(inp.value, 10);
          _discountValue = isNaN(v) ? 0 : Math.max(0, v);
        }
        _discountApplied = _discountValue > 0;
        _receivedInput = '';
        _playClick();
        _renderRegister();
        return;
      }

      // 割引取消
      if (e.target.closest('#ca-discount-clear')) {
        _discountValue = 0;
        _discountApplied = false;
        _receivedInput = '';
        _playClick();
        _renderRegister();
        return;
      }

      // 支払方法
      var payBtn = e.target.closest('.ca-pay-btn');
      if (payBtn) {
        _paymentMethod = payBtn.dataset.method;
        _receivedInput = '';
        if (_paymentMethod !== 'cash') {
          var calc = _calcTotal();
          _receivedInput = String(calc.finalTotal);
        }
        _playClick();
        _renderRegister();
        return;
      }

      // テンキー
      var tenkey = e.target.closest('.ca-tenkey__btn');
      if (tenkey) {
        var val = tenkey.dataset.val;
        if (val === 'C') {
          _receivedInput = '';
        } else if (val === 'BS') {
          _receivedInput = _receivedInput.slice(0, -1);
        } else if (_receivedInput.length < 7) {
          _receivedInput += val;
        }
        _playClick();
        _renderRegister();
        return;
      }

      // クイック金額
      var quick = e.target.closest('.ca-quick-btn');
      if (quick) {
        var amount = quick.dataset.amount;
        if (amount === 'exact') {
          var calc2 = _calcTotal();
          _receivedInput = String(calc2.finalTotal);
        } else {
          _receivedInput = amount;
        }
        _playClick();
        _renderRegister();
        return;
      }

      // レシートプレビューボタン
      if (e.target.closest('#ca-btn-receipt')) {
        if (!e.target.closest('#ca-btn-receipt').disabled) {
          _showReceiptPreview();
        }
        return;
      }

      // 会計ボタン
      if (e.target.closest('#ca-btn-submit')) {
        if (!e.target.closest('#ca-btn-submit').disabled) {
          _submitPayment();
        }
        return;
      }
    });

    // チェックボックス変更
    register.addEventListener('change', function (e) {
      var itemCheck = e.target.closest('.ca-item__check');
      if (itemCheck) {
        var idx = parseInt(itemCheck.dataset.idx, 10);
        // 支払済み品目はチェック変更不可
        if (_items[idx] && _items[idx].paymentId) {
          itemCheck.checked = false;
          return;
        }
        _checkedItems[idx] = itemCheck.checked;
        _receivedInput = '';
        _playClick();
        _renderRegister();
        return;
      }
    });

    // レシートモーダル
    receiptModal.addEventListener('click', function (e) {
      if (e.target === receiptModal) {
        _closeReceiptModal();
        return;
      }
      if (e.target.closest('#ca-receipt-print')) {
        window.print();
        return;
      }
      if (e.target.closest('#ca-receipt-close')) {
        _closeReceiptModal();
        return;
      }
    });

    // パネルオーバーレイクリック
    if (overlay) {
      overlay.addEventListener('click', function () {
        _closeSlidePanel();
      });
    }

    // パネル閉じるボタン
    document.querySelectorAll('.ca-slide-panel__close').forEach(function (btn) {
      btn.addEventListener('click', function () {
        _closeSlidePanel();
      });
    });

    // ヘッダーボタン
    var journalBtn = document.getElementById('ca-btn-journal');
    if (journalBtn) {
      journalBtn.addEventListener('click', function () {
        if (_openPanel === 'journal') {
          _closeSlidePanel();
        } else {
          _openJournal();
        }
      });
    }

    var regCtrlBtn = document.getElementById('ca-btn-register-ctrl');
    if (regCtrlBtn) {
      regCtrlBtn.addEventListener('click', function () {
        if (_openPanel === 'register-ctrl') {
          _closeSlidePanel();
        } else {
          _openRegisterCtrl();
        }
      });
    }

    var soundBtn = document.getElementById('ca-btn-sound');
    if (soundBtn) {
      soundBtn.addEventListener('click', function () {
        _soundEnabled = !_soundEnabled;
        localStorage.setItem('ca_sound', _soundEnabled ? '1' : '0');
        _updateSoundBtn();
        if (_soundEnabled) {
          _playClick();
        }
      });
    }

    // レジ開閉パネル内のイベント委譲
    var regCtrlBody = document.getElementById('ca-register-ctrl-body');
    if (regCtrlBody) {
      regCtrlBody.addEventListener('click', function (e) {
        // レジ開け
        if (e.target.closest('#ca-reg-open-btn')) {
          var openInput = document.getElementById('ca-reg-open-amount');
          var openAmt = openInput ? (parseInt(openInput.value, 10) || 0) : 0;
          _postCashLog('open', openAmt, 'レジ開け', function (ok) {
            if (ok) {
              _showToast('レジを開けました', 'success');
              _fetchSalesSummary();
            }
          });
          return;
        }

        // 入金
        if (e.target.closest('#ca-reg-cashin-btn')) {
          var inInput = document.getElementById('ca-reg-io-amount');
          var noteInput = document.getElementById('ca-reg-io-note');
          var inAmt = inInput ? (parseInt(inInput.value, 10) || 0) : 0;
          var inNote = noteInput ? noteInput.value : '';
          if (inAmt <= 0) { _showToast('金額を入力してください', 'error'); return; }
          _postCashLog('cash_in', inAmt, inNote || '入金', function (ok) {
            if (ok) {
              _showToast('入金しました', 'success');
              _fetchSalesSummary();
            }
          });
          return;
        }

        // 出金
        if (e.target.closest('#ca-reg-cashout-btn')) {
          var outInput = document.getElementById('ca-reg-io-amount');
          var outNoteInput = document.getElementById('ca-reg-io-note');
          var outAmt = outInput ? (parseInt(outInput.value, 10) || 0) : 0;
          var outNote = outNoteInput ? outNoteInput.value : '';
          if (outAmt <= 0) { _showToast('金額を入力してください', 'error'); return; }
          _postCashLog('cash_out', outAmt, outNote || '出金', function (ok) {
            if (ok) {
              _showToast('出金しました', 'success');
              _fetchSalesSummary();
            }
          });
          return;
        }

        // レジ閉め
        if (e.target.closest('#ca-reg-close-btn')) {
          var closeInput = document.getElementById('ca-reg-close-amount');
          var closeAmt = closeInput ? (parseInt(closeInput.value, 10) || 0) : 0;
          var expected = _calcExpectedBalance();
          var diff = closeAmt - expected;
          var diffStr = diff >= 0 ? '+' + Utils.formatYen(diff) : Utils.formatYen(diff);
          if (!confirm('レジを閉めますか？\n実際: ' + Utils.formatYen(closeAmt) + '\n予想: ' + Utils.formatYen(expected) + '\n差額: ' + diffStr)) return;
          _postCashLog('close', closeAmt, 'レジ閉め（差額: ' + diffStr + '）', function (ok) {
            if (ok) {
              _showToast('レジを閉めました', 'success');
              _fetchSalesSummary();
            }
          });
          return;
        }
      });
    }
  }

  // querySelectorAll forEach polyfill (ES5)
  if (!NodeList.prototype.forEach) {
    NodeList.prototype.forEach = Array.prototype.forEach;
  }

  // ═══════════════════════════════════
  // L-13 + P1-1: Stripe Terminal（Pattern A: 自前キー / Pattern B: Connect 両対応）
  // ═══════════════════════════════════
  var _terminal = null;
  var _terminalConnected = false;

  function _initStripeTerminal() {
    if (typeof StripeTerminal === 'undefined') return;

    fetch('../../api/connect/status.php', { credentials: 'same-origin' })
      .then(function (res) { return res.text(); })
      .then(function (text) {
        var json;
        try { json = JSON.parse(text); } catch (e) { return; }
        if (!json.ok || !json.data) return;
        // Pattern A or B のいずれかが有効な場合のみ初期化
        if (!json.data.terminal_pattern) return;

        _terminal = StripeTerminal.create({
          onFetchConnectionToken: _fetchConnectionToken,
          onUnexpectedReaderDisconnect: _onReaderDisconnect
        });

        _discoverReaders();
      })
      .catch(function (err) {
        console.error('Terminal init skipped:', err.message || err);
      });
  }

  function _fetchConnectionToken() {
    return fetch('../../api/connect/terminal-token.php', {
      method: 'POST',
      credentials: 'same-origin'
    })
    .then(function (res) { return res.text(); })
    .then(function (text) {
      var json;
      try { json = JSON.parse(text); } catch (e) { throw new Error('Token応答の解析に失敗'); }
      if (!json.ok) throw new Error((json.error && json.error.message) || 'Token取得失敗');
      return json.data.secret;
    });
  }

  function _onReaderDisconnect() {
    _terminalConnected = false;
    _showToast('カードリーダーが切断されました', 'error');
    _updateTerminalStatusUI();
  }

  function _discoverReaders() {
    if (!_terminal) return;

    _terminal.discoverReaders({ simulated: false }).then(function (result) {
      if (result.error) {
        console.error('リーダー検出エラー:', result.error.message);
        _updateTerminalStatusUI();
        return;
      }

      var readers = result.discoveredReaders;
      if (readers.length === 0) {
        console.warn('リーダーが見つかりません');
        _updateTerminalStatusUI();
        return;
      }

      _connectReader(readers[0]);
    });
  }

  function _connectReader(reader) {
    _terminal.connectReader(reader).then(function (result) {
      if (result.error) {
        _showToast('リーダー接続失敗: ' + result.error.message, 'error');
        _terminalConnected = false;
      } else {
        _terminalConnected = true;
        _showToast('カードリーダーに接続しました', 'success');
      }
      _updateTerminalStatusUI();
    });
  }

  function _updateTerminalStatusUI() {
    var statusEl = document.getElementById('terminal-status');
    if (!statusEl) return;

    if (!_terminal) {
      statusEl.style.display = 'none';
      return;
    }

    statusEl.style.display = '';

    if (_terminalConnected) {
      statusEl.innerHTML = '<span style="color:#22c55e;">&#9679; リーダー接続中</span>';
    } else {
      statusEl.innerHTML = '<span style="color:#ef4444;">&#9679; リーダー未接続</span>'
        + ' <button id="ca-btn-rediscover" style="margin-left:4px;font-size:0.85em;padding:1px 6px;cursor:pointer;background:transparent;border:1px solid #888;color:#ccc;border-radius:3px;">再検出</button>';
      var rediscBtn = document.getElementById('ca-btn-rediscover');
      if (rediscBtn) {
        rediscBtn.addEventListener('click', function () {
          _discoverReaders();
        });
      }
    }
  }

  /**
   * Terminal経由の決済実行:
   * 1. サーバーでPaymentIntent作成 → client_secret取得
   * 2. Terminal SDK で collectPaymentMethod(client_secret) → カード読取
   * 3. Terminal SDK で processPayment(pi) → 決済実行
   * 4. process-payment.php で記録（payment_method=terminal + PI ID）
   */
  function _processTerminalPayment(body, calc) {
    // オーバーレイ表示
    var gwOverlay = document.createElement('div');
    gwOverlay.id = 'ca-gw-overlay';
    gwOverlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:9999;';
    gwOverlay.innerHTML = '<div style="background:#fff;padding:2rem 3rem;border-radius:12px;text-align:center;">'
      + '<div style="font-size:2rem;margin-bottom:0.5rem;">&#128179;</div>'
      + '<div id="ca-terminal-msg" style="font-size:1.1rem;font-weight:600;color:#333;">カードリーダーで決済準備中...</div>'
      + '<div style="font-size:0.85rem;color:#888;margin-top:0.5rem;">カードをリーダーにかざしてください</div>'
      + '<div style="margin-top:1rem;"><button id="ca-terminal-cancel" style="padding:6px 16px;cursor:pointer;border:1px solid #ccc;border-radius:4px;background:#fff;">キャンセル</button></div>'
      + '</div>';
    document.body.appendChild(gwOverlay);

    var _cancelCollect = null;
    var cancelBtn = document.getElementById('ca-terminal-cancel');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', function () {
        if (_cancelCollect) {
          _cancelCollect();
          _cancelCollect = null;
        }
        _terminalPaymentCleanup('キャンセルしました');
      });
    }

    var totalAmount = calc.finalTotal;

    // Step 1: サーバーで PaymentIntent 作成
    fetch('../../api/store/terminal-intent.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount: totalAmount }),
      credentials: 'same-origin'
    })
    .then(function (res) { return res.text(); })
    .then(function (text) {
      var json;
      try { json = JSON.parse(text); } catch (e) { throw new Error('応答の解析に失敗'); }
      if (!json.ok) throw new Error((json.error && json.error.message) || 'PaymentIntent作成失敗');
      return json.data;
    })
    .then(function (piData) {
      var msgEl = document.getElementById('ca-terminal-msg');
      if (msgEl) msgEl.textContent = 'カードをリーダーにかざしてください';

      // Step 2: Terminal SDK でカード読取
      var collectResult = _terminal.collectPaymentMethod(piData.client_secret);
      _cancelCollect = function () { _terminal.cancelCollectPaymentMethod(); };
      return collectResult;
    })
    .then(function (result) {
      _cancelCollect = null;
      if (result.error) throw new Error('カード読取エラー: ' + result.error.message);

      var msgEl = document.getElementById('ca-terminal-msg');
      if (msgEl) msgEl.textContent = '決済処理中...';

      // Step 3: Terminal SDK で決済実行
      return _terminal.processPayment(result.paymentIntent);
    })
    .then(function (result) {
      if (result.error) throw new Error('決済処理エラー: ' + result.error.message);

      var piId = result.paymentIntent.id;

      // Step 4: process-payment.php で記録
      body.payment_method = 'terminal';
      body.terminal_payment_id = piId;

      return fetch('../../api/store/process-payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        credentials: 'same-origin'
      });
    })
    .then(function (r) {
      return r.text().then(function (text) {
        try { return JSON.parse(text); }
        catch (e) { throw new Error(text.substring(0, 200)); }
      });
    })
    .then(function (json) {
      _isSubmitting = false;
      var ov = document.getElementById('ca-gw-overlay');
      if (ov) ov.parentNode.removeChild(ov);

      if (!json.ok) {
        _playError();
        var errMsg = (json.error && json.error.message) || 'エラー';
        _showToast(errMsg, 'error');
        _startPolling();
        _renderRegister();
        return;
      }

      var d = json.data;
      _playSuccess();
      _showToast('会計完了: ' + Utils.formatYen(d.totalAmount) + ' (Terminal決済)', 'success');
      _lastPaymentId = d.payment_id;
      _showReceiptPreview();
      // リセットは _closeReceiptModal() 内で実行
    })
    .catch(function (err) {
      _terminalPaymentCleanup(err.message || '通信エラー');
    });
  }

  function _terminalPaymentCleanup(errMsg) {
    _isSubmitting = false;
    var ov = document.getElementById('ca-gw-overlay');
    if (ov) ov.parentNode.removeChild(ov);
    _playError();
    _showToast(errMsg, 'error');
    _startPolling();
    _renderRegister();
  }

  window.CashierApp = CashierApp;
})();
