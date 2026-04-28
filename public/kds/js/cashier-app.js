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
  var _userRole = '';     // CB1: device は manager 専用 API を叩かない
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
  var _PAY_LABELS = { cash: '現金', card: 'カード', qr: 'QR' };
  var _paymentDetail = '';
  var _PAY_DETAIL_OPTIONS = {
    card: [
      { value: 'card_credit', label: 'クレジット' },
      { value: 'card_debit', label: 'デビット' },
      { value: 'card_other', label: 'その他カード' }
    ],
    qr: [
      { value: 'qr_paypay', label: 'PayPay' },
      { value: 'qr_rakuten_pay', label: '楽天ペイ' },
      { value: 'qr_dbarai', label: 'd払い' },
      { value: 'qr_au_pay', label: 'au PAY' },
      { value: 'qr_merpay', label: 'メルペイ' },
      { value: 'qr_line_pay', label: 'LINE Pay' },
      { value: 'qr_alipay', label: 'Alipay' },
      { value: 'qr_wechat_pay', label: 'WeChat Pay' },
      { value: 'emoney_transport_ic', label: '交通系IC' },
      { value: 'emoney_id', label: 'iD' },
      { value: 'emoney_quicpay', label: 'QUICPay' },
      { value: 'qr_other', label: 'その他QR' },
      { value: 'emoney_other', label: 'その他電子マネー' }
    ]
  };
  var _PAY_DETAIL_LABELS = {};
  Object.keys(_PAY_DETAIL_OPTIONS).forEach(function (method) {
    _PAY_DETAIL_OPTIONS[method].forEach(function (opt) {
      _PAY_DETAIL_LABELS[opt.value] = opt.label;
    });
  });

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
      _userRole = (ctx.user && ctx.user.role) || '';   // CB1: 売上系 API は manager+ のみ呼び出し
      ctx.stores.forEach(function (s) {
        if (s.id === ctx.storeId) _storeName = s.name;
      });

      // GPS チェック (device ロールは常に強制)
      KdsAuth.checkGps(_storeId, function () {
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

        // 通常レジは店舗既存の決済端末 / QR を使い、POSLA は記録のみ行う。
      });
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

  function _defaultPaymentDetail(method) {
    if (_PAY_DETAIL_OPTIONS[method] && _PAY_DETAIL_OPTIONS[method][0]) {
      return _PAY_DETAIL_OPTIONS[method][0].value;
    }
    return '';
  }

  function _paymentLabel(method, detail) {
    if (detail && _PAY_DETAIL_LABELS[detail]) return _PAY_DETAIL_LABELS[detail];
    return _PAY_LABELS[method] || method || '';
  }

  function _currentPaymentLabel() {
    return _paymentLabel(_paymentMethod, _paymentDetail);
  }

  function _setPaymentMethod(method) {
    _paymentMethod = method;
    _paymentDetail = _defaultPaymentDetail(method);
    _receivedInput = '';
    if (_paymentMethod !== 'cash') {
      var calc = _calcTotal();
      _receivedInput = String(calc.finalTotal);
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

  /**
   * @param opts { failClosed?: boolean }
   *   failClosed=true の場合、通信失敗時は snapshot 復元をせず catch で reject する。
   *   会計前リフレッシュなど「古いデータで続行してはいけない」経路で使う (Phase 3 レビュー指摘 #2)。
   */
  function _fetchOrders(opts) {
    var failClosed = !!(opts && opts.failClosed);
    var orderUrl = '../../api/kds/orders.php?store_id=' + encodeURIComponent(_storeId) + '&view=accounting';
    var sessionUrl = '../../api/store/table-sessions.php?store_id=' + encodeURIComponent(_storeId);

    // CP1: Promise を返すよう変更。会計前の強制リフレッシュ等で await するため。
    return fetch(orderUrl, { credentials: 'same-origin' })
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
          } else {
            // CP1b: 選択中テーブルの注文/品目が変わっていたら _items を再構築
            _refreshSelectedItems();
          }
        }
        // Phase 3: snapshot 保存 + stale 解除
        if (typeof OfflineSnapshot !== 'undefined' && _storeId) {
          OfflineSnapshot.save('cashier', _storeId, 'orders-sessions', {
            orders: Object.keys(_orders).map(function (k) { return _orders[k]; }),
            sessions: _sessions
          });
        }
        if (typeof OfflineStateBanner !== 'undefined') {
          OfflineStateBanner.setLastSuccessAt(Date.now());
          OfflineStateBanner.markFresh();
        }
      }).catch(function (err) {
        console.error('Cashier polling error:', err);
        if (typeof OfflineStateBanner !== 'undefined') OfflineStateBanner.markStale();
        // Phase 3: 既存表示があれば残す (stale バナーのみ)。
        // 初回失敗 (画面に何も出ていない状態) のみ snapshot 復元。
        // ただし failClosed=true の場合 (会計前リフレッシュ等) はエラーを再送出して呼び出し側で中断させる。
        if (Object.keys(_orders).length === 0 && typeof OfflineSnapshot !== 'undefined' && _storeId) {
          var snap = OfflineSnapshot.load('cashier', _storeId, 'orders-sessions');
          if (snap && snap.data) {
            _orders = {};
            (snap.data.orders || []).forEach(function (o) { _orders[o.id] = o; });
            _sessions = snap.data.sessions || {};
            _buildTableGroups();
            _renderTableList();
            if (typeof OfflineStateBanner !== 'undefined') {
              OfflineStateBanner.setLastSuccessAt(snap.savedAt);
            }
          }
        }
        if (failClosed) {
          // 会計前リフレッシュ等「古いデータで続行してはいけない」呼び出し経路
          throw (err instanceof Error) ? err : new Error('fetch_failed');
        }
      });
  }

  // CP1b: 選択中テーブルの _items を最新の _orders から再構築する。
  // _checkedItems の状態は orderId+itemId 単位で保持。新規品目はチェック済みで追加。
  function _refreshSelectedItems() {
    if (!_selectedTableId) return;
    var group = _findGroup(_selectedTableId);
    if (!group) return;

    // 旧チェック状態を orderId+itemId キーで退避
    var prevChecked = {};
    _items.forEach(function (it, i) {
      var key = it.orderId + ':' + (it.itemId || '');
      prevChecked[key] = _checkedItems[i];
    });

    var newItems = [];
    var newChecked = {};
    group.orders.forEach(function (o) {
      (o.items || []).forEach(function (item) {
        var taxRate = 10;
        if (item.taxRate) taxRate = item.taxRate;
        else if (o.order_type === 'takeout') taxRate = 8;

        var i = newItems.length;
        newItems.push({
          name: item.name,
          qty: item.qty || 1,
          price: item.price || 0,
          taxRate: taxRate,
          orderId: o.id,
          options: item.options || [],
          itemId: item.item_id || null,
          paymentId: item.payment_id || null,
          status: item.status || 'pending'
        });
        var key = o.id + ':' + (item.item_id || '');
        if (item.payment_id) {
          newChecked[i] = false;
        } else if (prevChecked.hasOwnProperty(key)) {
          newChecked[i] = prevChecked[key];
        } else {
          newChecked[i] = true;
        }
      });
    });

    // 内容に変化がある場合のみ再描画
    var changed = newItems.length !== _items.length;
    if (!changed) {
      for (var i = 0; i < newItems.length; i++) {
        var a = newItems[i], b = _items[i];
        if (a.orderId !== b.orderId || a.itemId !== b.itemId || a.status !== b.status || a.paymentId !== b.paymentId) {
          changed = true;
          break;
        }
      }
    }
    _items = newItems;
    _checkedItems = newChecked;
    if (changed) _renderRegister();
  }

  // ── 売上サマリー ──
  function _startSalesPolling() {
    // CB1: device ロールは manager+ 限定 API を叩けないのでスキップ (FORBIDDEN ノイズ防止)
    if (_userRole === 'device') return;
    _fetchSalesSummary();
    _salesPollTimer = setInterval(_fetchSalesSummary, 30000);
  }

  function _fetchSalesSummary() {
    // CB1: device ロールは売上サマリー API を呼ばない
    if (_userRole === 'device') return;
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
        if (_openPanel === 'register-ctrl') _renderRegisterCtrl();
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
    html += '<div class="ca-sales-summary__row"><span>カード</span><span>' + Utils.formatYen(s.card) + '</span></div>';
    html += '<div class="ca-sales-summary__row"><span>QR/電子</span><span>' + Utils.formatYen(s.qr) + '</span></div>';
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
    var alertEl = document.getElementById('ca-table-alert-count');
    countEl.textContent = _tableGroups.length;

    var alertCount = 0;
    _tableGroups.forEach(function (t) {
      var s = _sessions[t.tableId];
      var elapsed = _calcElapsedMin(s);
      if (elapsed !== null && elapsed >= 60 && (parseInt(t.total, 10) || 0) > 0) alertCount++;
    });
    if (alertEl) {
      alertEl.textContent = alertCount > 0 ? '注意 ' + alertCount : '';
      alertEl.style.display = alertCount > 0 ? '' : 'none';
    }

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
      var alertLabel = '';
      if (elapsed !== null) {
        var eCls = 'ca-table-card__elapsed';
        if (elapsed >= 60) eCls += ' ca-table-card__elapsed--danger';
        else if (elapsed >= 30) eCls += ' ca-table-card__elapsed--warn';
        elapsedHtml = '<span class="' + eCls + '">' + elapsed + '分</span>';
        if ((parseInt(t.total, 10) || 0) > 0 && elapsed >= 90) {
          cls += ' ca-table-card--unpaid-danger';
          alertLabel = '<span class="ca-table-card__alert-badge ca-table-card__alert-badge--danger">長時間</span>';
        } else if ((parseInt(t.total, 10) || 0) > 0 && elapsed >= 60) {
          cls += ' ca-table-card--unpaid-warn';
          alertLabel = '<span class="ca-table-card__alert-badge">会計注意</span>';
        }
      }

      html += '<div class="' + cls + '" data-group-key="' + Utils.escapeHtml(t.groupKey) + '">';
      html += '<div class="ca-table-card__top">';
      html += '<span class="ca-table-card__code">' + Utils.escapeHtml(t.tableCode) + '</span>';
      html += '<span class="ca-table-card__badges">';
      html += alertLabel;
      if (t.isTakeout) {
        html += '<span class="ca-table-card__badge">TO</span>';
      } else {
        html += '<span class="ca-table-card__type-badge">店内</span>';
      }
      html += '</span>';
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
          paymentId: item.payment_id || null,
          status: item.status || 'pending'
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
            tableCode: g.tableCode,
            status: item.status || 'pending'
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
    if (_paymentMethod !== 'cash') {
      var opts = _PAY_DETAIL_OPTIONS[_paymentMethod] || [];
      h += '<div class="ca-pay-detail">';
      h += '<div class="ca-pay-detail__label">支払い詳細</div>';
      h += '<div class="ca-pay-detail__grid">';
      opts.forEach(function (opt) {
        var cls = 'ca-pay-detail__btn';
        if (_paymentDetail === opt.value) cls += ' ca-pay-detail__btn--active';
        h += '<button class="' + cls + '" data-payment-detail="' + Utils.escapeHtml(opt.value) + '">' + Utils.escapeHtml(opt.label) + '</button>';
      });
      h += '</div>';
      h += '</div>';
    }
    h += _renderManualPaymentNoticeHtml();
    return h;
  }

  function _renderManualPaymentNoticeHtml() {
    if (_paymentMethod === 'cash') return '';
    if (_paymentMethod === 'card') {
      return '<div class="ca-pay-notice ca-pay-notice--card">'
        + '<div class="ca-pay-notice__title">外部カード端末で決済完了後に記録</div>'
        + '<div class="ca-pay-notice__text">POSLAからカード決済・返金は実行されません。端末側の完了控えを確認してから会計してください。</div>'
        + '</div>';
    }
    if (_paymentMethod === 'qr') {
      return '<div class="ca-pay-notice ca-pay-notice--qr">'
        + '<div class="ca-pay-notice__title">店舗契約QR/電子マネーで支払い完了後に記録</div>'
        + '<div class="ca-pay-notice__text">POSLAは決済結果の記録のみ行います。PayPay等の端末・アプリ側で完了を確認してください。</div>'
        + '</div>';
    }
    return '';
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
    html += '<div class="ca-receipt__payment-row"><span>支払方法</span><span>' + Utils.escapeHtml(_currentPaymentLabel()) + '</span></div>';
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
    // Phase 3: オフライン中の領収書発行は API 呼び出しなので絶対にしない
    if (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale && OfflineStateBanner.isOfflineOrStale()) {
      _showToast('\u30AA\u30D5\u30E9\u30A4\u30F3\u4E2D\u306F\u9818\u53CE\u66F8\u767A\u884C\u3067\u304D\u307E\u305B\u3093\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u64CD\u4F5C\u3057\u3066\u304F\u3060\u3055\u3044\u3002', 'error');
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
        _showToast(Utils.formatError(json), 'error');
        return;
      }
      _printReceiptHtml(json.data);
      _showToast('領収書を発行しました', 'success');
    }).catch(function (err) {
      _showToast(Utils.formatError(err), 'error');
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

    var methodLabel = _paymentLabel(pay.payment_method, pay.payment_method_detail);
    h += '<div class="row"><span>支払方法</span><span>' + _escPrint(methodLabel) + '</span></div>';

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

    // Phase 3: offline または stale 状態なら会計を絶対に実行しない (キューもしない)
    if (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale && OfflineStateBanner.isOfflineOrStale()) {
      _showToast('\u30AA\u30D5\u30E9\u30A4\u30F3\u4E2D\u307E\u305F\u306F\u53E4\u3044\u30C7\u30FC\u30BF\u8868\u793A\u4E2D\u3067\u3059\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u64CD\u4F5C\u3057\u3066\u304F\u3060\u3055\u3044\u3002', 'error');
      return;
    }

    // CP1b: 会計前に _orders を強制リフレッシュして、KDS の提供済み更新とのレースを防ぐ
    // Phase 3 レビュー指摘 #2: 失敗時は古いデータで続行せず、toast で中断する (fail-closed)
    _fetchOrders({ failClosed: true }).then(function () {
      _submitPaymentAfterRefresh();
    }).catch(function () {
      _showToast('\u6700\u65B0\u306E\u6CE8\u6587\u72B6\u614B\u3092\u53D6\u5F97\u3067\u304D\u307E\u305B\u3093\u3067\u3057\u305F\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u4F1A\u8A08\u3057\u3066\u304F\u3060\u3055\u3044\u3002', 'error');
    });
  }

  function _submitPaymentAfterRefresh() {
    var isMergedSubmit = _mergeMode && _mergedGroupKeys.length >= 2;
    var group = isMergedSubmit ? null : _findGroup(_selectedTableId);
    if (!isMergedSubmit && !group) return;

    // CP1b: _items の status を最新の _orders で上書き（indices は保持するので _checkedItems は有効）
    _items.forEach(function (item) {
      var order = _orders[item.orderId];
      if (!order || !order.items) return;
      for (var j = 0; j < order.items.length; j++) {
        var oi = order.items[j];
        if (oi.item_id && item.itemId && oi.item_id === item.itemId) {
          if (oi.status) item.status = oi.status;
          break;
        }
      }
    });

    var allChecked = _isAllChecked();
    var isPartial = _isSomeChecked() && (!allChecked || _hasPaidItems());
    var calc = _calcTotal();

    // 未提供品チェック（S5）
    var unservedNames = [];
    _items.forEach(function (item, i) {
      if (!_checkedItems[i]) return;
      if (item.paymentId) return;
      if (item.status !== 'served' && item.status !== 'cancelled') {
        unservedNames.push(item.name);
      }
    });
    if (unservedNames.length > 0) {
      var unservedMsg = '【注意】まだ提供されていない商品があります:\n';
      unservedNames.forEach(function (n) { unservedMsg += '  ・' + n + '\n'; });
      unservedMsg += '\nこのまま会計を続けますか？';
      if (!confirm(unservedMsg)) return;
    }

    // 確認ダイアログ
    var payLabel = _currentPaymentLabel();
    var confirmMsg = Utils.formatYen(calc.finalTotal) + ' を ' + payLabel + ' で会計しますか？';
    if (_paymentMethod === 'card') {
      confirmMsg = '外部カード端末（' + payLabel + '）で決済完了を確認済みとして、' + Utils.formatYen(calc.finalTotal) + ' をPOSLAに記録しますか？';
    } else if (_paymentMethod === 'qr') {
      confirmMsg = '店舗契約の' + payLabel + 'で支払い完了を確認済みとして、' + Utils.formatYen(calc.finalTotal) + ' をPOSLAに記録しますか？';
    }
    if (isPartial) confirmMsg = '【個別会計】' + confirmMsg;
    if (!confirm(confirmMsg)) return;

    // CP1: 担当スタッフ PIN 入力を要求
    _promptCashierPin(function (pin) {
      if (pin === null) return;   // キャンセル
      _submitPaymentWithPin(group, isPartial, calc, pin);
    });
  }

  /**
   * CP1: 担当スタッフ PIN 入力モーダルを表示
   * callback(pin or null) — null はキャンセル
   */
  function _promptCashierPin(callback) {
    // 既存モーダル除去
    var existing = document.getElementById('ca-pin-modal-overlay');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'ca-pin-modal-overlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:10000;';
    overlay.innerHTML =
      '<div style="background:#fff;padding:2rem;border-radius:12px;width:340px;text-align:center;">' +
      '<h3 style="margin:0 0 0.75rem;font-size:1.25rem;color:#333;">担当スタッフ PIN 入力</h3>' +
      '<p style="margin:0 0 1rem;font-size:0.85rem;color:#666;">レジを操作するスタッフの PIN を入力してください。</p>' +
      '<input id="ca-pin-input" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off" placeholder="••••" style="width:200px;font-size:2rem;text-align:center;letter-spacing:0.5em;padding:0.5rem;border:2px solid #ccc;border-radius:8px;margin-bottom:1rem;" />' +
      '<div id="ca-pin-error" style="color:#d9534f;font-size:0.85rem;min-height:1.2em;margin-bottom:0.75rem;"></div>' +
      '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;margin-bottom:1rem;">' +
        ['1','2','3','4','5','6','7','8','9','C','0','⌫'].map(function (k) {
          var bg = (k === 'C' || k === '⌫') ? '#f5f5f5' : '#fff';
          return '<button type="button" data-pinkey="' + k + '" style="font-size:1.5rem;padding:0.75rem;background:' + bg + ';border:1px solid #ddd;border-radius:6px;cursor:pointer;font-weight:bold;">' + k + '</button>';
        }).join('') +
      '</div>' +
      '<div style="display:flex;gap:0.5rem;">' +
      '<button id="ca-pin-cancel" style="flex:1;padding:0.75rem;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;cursor:pointer;">キャンセル</button>' +
      '<button id="ca-pin-ok" style="flex:1;padding:0.75rem;background:#43a047;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;">確定</button>' +
      '</div>' +
      '</div>';

    document.body.appendChild(overlay);

    var input = document.getElementById('ca-pin-input');
    var errEl = document.getElementById('ca-pin-error');

    setTimeout(function () { if (input) input.focus(); }, 100);

    function cleanup() {
      overlay.remove();
    }

    function submit() {
      var pin = input.value.replace(/\D/g, '');
      if (!/^\d{4,8}$/.test(pin)) {
        errEl.textContent = '4〜8 桁の数字を入力してください';
        return;
      }
      cleanup();
      callback(pin);
    }

    // テンキー
    overlay.querySelectorAll('[data-pinkey]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var k = btn.getAttribute('data-pinkey');
        if (k === 'C') input.value = '';
        else if (k === '⌫') input.value = input.value.slice(0, -1);
        else if (input.value.length < 8) input.value += k;
        errEl.textContent = '';
      });
    });

    // キーボード
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); submit(); }
      if (e.key === 'Escape') { cleanup(); callback(null); }
    });

    document.getElementById('ca-pin-ok').addEventListener('click', submit);
    document.getElementById('ca-pin-cancel').addEventListener('click', function () {
      cleanup();
      callback(null);
    });
  }

  /**
   * CP1: PIN 確認後の実際の会計送信処理
   */
  function _submitPaymentWithPin(group, isPartial, calc, pin) {
    var isMerged = _mergeMode && _mergedGroupKeys.length >= 2;
    var isManualNonCash = (_paymentMethod !== 'cash');

    var body = {
      store_id: _storeId,
      payment_method: _paymentMethod,
      payment_method_detail: _paymentDetail || '',
      staff_pin: pin   // CP1: 担当スタッフ PIN
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
    } else {
      body.payment_entry_mode = 'manual';
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

    // カード/QR決済時はローディング表示
    var gwOverlay = null;
    if (_paymentMethod !== 'cash') {
      gwOverlay = document.createElement('div');
      gwOverlay.id = 'ca-gw-overlay';
      gwOverlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:9999;';
      gwOverlay.innerHTML = '<div style="background:#fff;padding:2rem 3rem;border-radius:12px;text-align:center;">'
        + '<div style="font-size:2rem;margin-bottom:0.5rem;">&#128179;</div>'
        + '<div style="font-size:1.1rem;font-weight:600;color:#333;">' + (isManualNonCash ? '会計記録中...' : '決済処理中...') + '</div>'
        + '<div style="font-size:0.85rem;color:#888;margin-top:0.5rem;">' + (isManualNonCash ? '外部決済完了後の記録を保存しています' : 'しばらくお待ちください') + '</div>'
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
        var formatted = Utils.formatError(json);
        if (errCode === 'GATEWAY_ERROR') {
          _showToast('決済エラー: ' + formatted, 'error');
        } else {
          _showToast(formatted, 'error');
        }
        _startPolling();
        _renderRegister();
        return;
      }

      var d = json.data;

      _playSuccess();
      var toastMsg = '会計完了: ' + Utils.formatYen(d.totalAmount);
      if (_paymentMethod !== 'cash') toastMsg += ' (' + _currentPaymentLabel() + '記録)';
      _showToast(toastMsg, 'success');
      _lastPaymentId = d.payment_id;
      _showReceiptPreview();
      // リセットは _closeReceiptModal() 内で実行
    }).catch(function (err) {
      _isSubmitting = false;
      var ov = document.getElementById('ca-gw-overlay');
      if (ov) ov.parentNode.removeChild(ov);
      _playError();
      _showToast(Utils.formatError(err), 'error');
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

    // 返金集計
    var refundCount = 0;
    var refundTotal = 0;
    // Phase 4d-5c-ba: void 集計 (補正1)
    //   summary.total は payments-journal.php 側で voided も含めて合算しているため、
    //   UI サマリーでは voidedTotal をローカル集計して netTotal から控除する。
    //   refund と void は排他なので二重控除にはならない。
    var voidedCount = 0;
    var voidedTotal = 0;
    payments.forEach(function (p) {
      if (p.refund_status && p.refund_status !== 'none') {
        refundCount++;
        refundTotal += parseInt(p.refund_amount || p.total_amount, 10) || 0;
      }
      if (p.void_status === 'voided') {
        voidedCount++;
        voidedTotal += parseInt(p.total_amount, 10) || 0;
      }
    });

    var html = '';

    // サマリー
    html += '<div class="ca-journal__summary">';
    html += '<div class="ca-journal__stat"><div class="ca-journal__stat-label">取引件数</div><div class="ca-journal__stat-value">' + summary.count + '</div></div>';
    var netTotal = summary.total - refundTotal - voidedTotal;
    html += '<div class="ca-journal__stat"><div class="ca-journal__stat-label">合計金額</div><div class="ca-journal__stat-value">' + Utils.formatYen(netTotal) + '</div></div>';
    if (refundCount > 0) {
      html += '<div class="ca-journal__stat ca-journal__stat--refund"><div class="ca-journal__stat-label">返金</div><div class="ca-journal__stat-value">' + refundCount + '件 / -' + Utils.formatYen(refundTotal) + '</div></div>';
    }
    if (voidedCount > 0) {
      html += '<div class="ca-journal__stat ca-journal__stat--voided"><div class="ca-journal__stat-label">取消済</div><div class="ca-journal__stat-value">' + voidedCount + '件 / -' + Utils.formatYen(voidedTotal) + '</div></div>';
    }
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

      var refundStatus = p.refund_status || 'none';
      var isRefunded = refundStatus !== 'none';
      var gatewayName = p.gateway_name || '';
      // Phase 4d-5c-ba: voided 判定
      var isVoided = (p.void_status === 'voided');
      var canRefund = gatewayName && !isRefunded && !isVoided && method !== 'cash';
      var canVoidByRole = (_userRole === 'manager' || _userRole === 'owner');
      // Phase 4d-5c-bb-A / 4d-5c-bb-C: 会計取消ボタンは以下をすべて満たす行にのみ表示する
      //   - 返金されていない
      //   - まだ取消していない
      //   - gateway_name が空 / null        (gateway 通過していない = cash / 手動 card / 手動 qr)
      //   - is_partial != 1                (分割会計でない)
      // payment_method は cash / card / qr (ENUM 全値) を許可。gateway 経由は API 側で
      // NOT_VOIDABLE_GATEWAY 409 に弾く。
      // API 側でさらに order_ids>0 / order_type ∈ ('dine_in','handy','takeout') / status='paid'
      // をチェックして 409 を返すので、UI のボタンは以上に絞り込んで出す。
      // 4d-5c-bb-C: POS cashier 経由で会計された takeout (status='paid' cash) は取消可能。
      // online 決済 takeout (status='served' 等) は API 側の status ガードで弾かれる。
      var canVoid = canVoidByRole && !isRefunded && !isVoided && !gatewayName && !isPartial;

      var rowClass = 'ca-journal__item';
      if (isRefunded) rowClass += ' ca-journal__item--refunded';
      if (isVoided)   rowClass += ' ca-journal__item--voided';

      html += '<div class="' + rowClass + '">';
      html += '<span class="ca-journal__item-time">' + Utils.escapeHtml(timeStr) + '</span>';
      html += '<span class="ca-journal__item-table">' + Utils.escapeHtml(tableCode) + (isPartial ? ' *' : '') + '</span>';
      html += '<span class="ca-journal__item-method ca-journal__item-method--' + method + '">' + Utils.escapeHtml(_paymentLabel(method, p.payment_method_detail)) + '</span>';
      html += '<span class="ca-journal__item-staff">' + Utils.escapeHtml(staffName) + '</span>';
      html += '<span class="ca-journal__item-amount">' + Utils.formatYen(amount) + '</span>';
      if (isVoided) {
        html += '<span class="ca-journal__void-badge">✗ 取消済</span>';
      } else if (isRefunded) {
        html += '<span class="ca-journal__refund-badge">返金済</span>';
      } else {
        if (canRefund) {
          html += '<button class="ca-journal__refund-btn" data-payment-id="' + Utils.escapeHtml(p.id) + '" data-amount="' + amount + '">返金</button>';
        }
        if (canVoid) {
          html += '<button class="ca-journal__void-btn" data-payment-id="' + Utils.escapeHtml(p.id) + '" data-amount="' + amount + '">会計取消</button>';
        }
      }
      html += '</div>';
    });
    html += '</div>';

    body.innerHTML = html;

    // C-R1: 返金ボタンイベントバインド
    var refundBtns = body.querySelectorAll('.ca-journal__refund-btn');
    for (var ri = 0; ri < refundBtns.length; ri++) {
      refundBtns[ri].addEventListener('click', function () {
        var pid = this.getAttribute('data-payment-id');
        var amt = parseInt(this.getAttribute('data-amount'), 10) || 0;
        _confirmRefund(pid, amt);
      });
    }

    // Phase 4d-5c-ba: 会計取消ボタンイベントバインド
    var voidBtns = body.querySelectorAll('.ca-journal__void-btn');
    for (var vi = 0; vi < voidBtns.length; vi++) {
      voidBtns[vi].addEventListener('click', function () {
        var pid = this.getAttribute('data-payment-id');
        var amt = parseInt(this.getAttribute('data-amount'), 10) || 0;
        _confirmNormalVoid(pid, amt);
      });
    }
  }

  // Phase 4d-5c-ba: 通常会計取消 (論理取消) — 返金とは別経路
  //   会計記録のみ無効化。orders.status / 卓状態 / レシートは維持
  //   cash の場合は cash_log.cash_out で相殺
  //   詳細条件は API 側 (payment-void.php) で判定し、失敗時は alert で理由表示
  var _voidingPayment = false;
  function _confirmNormalVoid(paymentId, amount) {
    if (_voidingPayment) return;
    if (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale && OfflineStateBanner.isOfflineOrStale()) {
      _showToast('\u30AA\u30D5\u30E9\u30A4\u30F3\u4E2D\u306F\u4F1A\u8A08\u53D6\u6D88\u3057\u304D\u307E\u305B\u3093\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u64CD\u4F5C\u3057\u3066\u304F\u3060\u3055\u3044\u3002', 'error');
      return;
    }
    var msg = 'この取引 (' + Utils.formatYen(amount) + ') を会計取消しますか？\n\n' +
              '・売上集計から除外されます\n' +
              '・レジ現金は cash_out で相殺されます (現金の場合のみ)\n' +
              '・注文ステータス / 卓状態 / レシートは維持されます\n' +
              '・返金は別操作です (現金以外の返金は「返金」ボタンから)\n\n' +
              'この操作は取り消せません。';
    if (!confirm(msg)) return;
    var reason = prompt('取消理由を入力してください (必須、1-255 文字)');
    if (reason === null) return; // cancel
    reason = String(reason || '').replace(/^\s+|\s+$/g, '');
    if (reason === '') {
      alert('取消理由の入力は必須です。');
      return;
    }
    _executeNormalVoid(paymentId, amount, reason);
  }

  function _executeNormalVoid(paymentId, amount, reason) {
    if (!(_userRole === 'manager' || _userRole === 'owner')) {
      _showToast('会計取消は manager / owner のみ実行できます', 'error');
      return;
    }
    _voidingPayment = true;
    fetch('../../api/store/payment-void.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        store_id:   _storeId,
        payment_id: paymentId,
        reason:     reason
      })
    })
    .then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { return { ok: false, error: { message: t.substring(0, 120) } }; }
      });
    })
    .then(function (json) {
      if (json.ok) {
        _playSound('success');
        if (json.data && json.data.idempotent) {
          alert('この取引はすでに取消済みです。');
        } else {
          alert('会計取消が完了しました (' + Utils.formatYen(amount) + ')');
        }
        _openJournal();
      } else {
        alert('会計取消に失敗しました: ' + Utils.formatError(json));
      }
    })
    .catch(function (err) {
      alert('通信エラーが発生しました: ' + Utils.formatError(err));
    })
    .finally(function () {
      _voidingPayment = false;
    });
  }

  // C-R1: 返金確認 + 実行（CP1b: 担当スタッフ PIN 必須）
  var _refunding = false;
  function _confirmRefund(paymentId, amount) {
    if (_refunding) return;
    // Phase 3: オフライン中の返金は絶対に実行しない
    if (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale && OfflineStateBanner.isOfflineOrStale()) {
      _showToast('\u30AA\u30D5\u30E9\u30A4\u30F3\u4E2D\u306F\u8FD4\u91D1\u3067\u304D\u307E\u305B\u3093\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u64CD\u4F5C\u3057\u3066\u304F\u3060\u3055\u3044\u3002', 'error');
      return;
    }
    if (!confirm('この取引 (' + Utils.formatYen(amount) + ') を返金しますか？\nこの操作は取り消せません。')) return;

    // 担当スタッフ PIN を要求
    _promptCashierPin(function (pin) {
      if (pin === null) return;   // キャンセル
      _executeRefund(paymentId, amount, pin);
    });
  }

  function _executeRefund(paymentId, amount, pin) {
    _refunding = true;
    var url = '../../api/store/refund-payment.php';
    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        payment_id: paymentId,
        store_id: _storeId,
        reason: 'requested_by_customer',
        staff_pin: pin
      })
    })
    .then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { return { ok: false, error: { message: t.substring(0, 120) } }; }
      });
    })
    .then(function (json) {
      if (json.ok) {
        _playSound('success');
        alert('返金が完了しました (' + Utils.formatYen(amount) + ')');
        _openJournal(); // リロード
      } else {
        alert('返金に失敗しました: ' + Utils.formatError(json));
      }
    })
    .catch(function (err) {
      alert('通信エラーが発生しました: ' + Utils.formatError(err));
    })
    .finally(function () {
      _refunding = false;
    });
  }

  // ── レジ開閉 ──
  function _openRegisterCtrl() {
    _openSlidePanel('register-ctrl');
    _fetchSalesSummary();
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
      var closeSummary = _getCloseReconcileSummary();
      html += '<div class="ca-register-ctrl__reconcile">';
      html += '<div class="ca-register-ctrl__reconcile-row"><span>予想現金</span><strong>' + Utils.formatYen(closeSummary.expectedCash) + '</strong></div>';
      html += '<div class="ca-register-ctrl__reconcile-row"><span>現金売上</span><strong>' + Utils.formatYen(closeSummary.cashSales) + '</strong></div>';
      html += '<div class="ca-register-ctrl__reconcile-row"><span>カード売上</span><strong>' + Utils.formatYen(closeSummary.cardSales) + '</strong></div>';
      html += '<div class="ca-register-ctrl__reconcile-row"><span>QR/電子</span><strong>' + Utils.formatYen(closeSummary.qrSales) + '</strong></div>';
      html += '</div>';
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
      html += '<div class="ca-register-ctrl__input-group">';
      html += '<label class="ca-register-ctrl__input-label">差額理由・締めメモ</label>';
      html += '<input type="text" class="ca-register-ctrl__input ca-register-ctrl__input--text" id="ca-reg-close-note" maxlength="120" placeholder="例: 100円硬貨不足">';
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
        if (entry.type === 'close' && entry.difference_amount !== null && typeof entry.difference_amount !== 'undefined') {
          var closeDiff = parseInt(entry.difference_amount, 10) || 0;
          html += '<span class="ca-register-ctrl__log-note">' + (closeDiff >= 0 ? '+' : '') + Utils.formatYen(closeDiff) + '</span>';
        }
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

  function _getCloseReconcileSummary() {
    var cashFromLog = 0;
    _cashLogEntries.forEach(function (e) {
      if (e.type === 'cash_sale') cashFromLog += parseInt(e.amount, 10) || 0;
    });
    var s = _salesSummary || {};
    return {
      expectedCash: _calcExpectedBalance(),
      cashSales: parseInt(s.cash, 10) || cashFromLog,
      cardSales: parseInt(s.card, 10) || 0,
      qrSales: parseInt(s.qr, 10) || 0,
      totalRevenue: parseInt(s.totalRevenue, 10) || 0
    };
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

  function _postCashLog(type, amount, note, pin, callback, extra) {
    // 後方互換: 旧シグネチャ (type, amount, note, callback) を許容
    if (typeof pin === 'function') { callback = pin; pin = null; }
    // Phase 3: オフライン中の金銭ログ書き込みは絶対に実行しない
    if (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale && OfflineStateBanner.isOfflineOrStale()) {
      _showToast('\u30AA\u30D5\u30E9\u30A4\u30F3\u4E2D\u306F\u30EC\u30B8\u64CD\u4F5C\u3067\u304D\u307E\u305B\u3093\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u64CD\u4F5C\u3057\u3066\u304F\u3060\u3055\u3044\u3002', 'error');
      if (typeof callback === 'function') {
        try { callback({ ok: false, error: { message: 'offline' } }); } catch (e) {}
      }
      return;
    }
    var payload = {
      store_id: _storeId,
      type: type,
      amount: amount,
      note: note || '',
      staff_pin: pin || ''
    };
    if (extra) {
      Object.keys(extra).forEach(function (key) {
        payload[key] = extra[key];
      });
    }
    fetch('../../api/kds/cash-log.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
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
        _showToast('エラー: ' + Utils.formatError(json), 'error');
        if (callback) callback(false);
      }
    }).catch(function (err) {
      _playError();
      _showToast('通信エラー: ' + Utils.formatError(err), 'error');
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
        _setPaymentMethod('cash');
        _playClick();
        _renderRegister();
        return;
      }
      if (e.key === 'F2') {
        e.preventDefault();
        _setPaymentMethod('card');
        _playClick();
        _renderRegister();
        return;
      }
      if (e.key === 'F3') {
        e.preventDefault();
        _setPaymentMethod('qr');
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
        _setPaymentMethod(payBtn.dataset.method);
        _playClick();
        _renderRegister();
        return;
      }

      var payDetailBtn = e.target.closest('.ca-pay-detail__btn');
      if (payDetailBtn) {
        _paymentDetail = payDetailBtn.getAttribute('data-payment-detail') || _defaultPaymentDetail(_paymentMethod);
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
        // レジ開け (CB1d: PIN 必須)
        if (e.target.closest('#ca-reg-open-btn')) {
          var openInput = document.getElementById('ca-reg-open-amount');
          var openAmt = openInput ? (parseInt(openInput.value, 10) || 0) : 0;
          _promptCashierPin(function (pin) {
            if (pin === null) return;
            _postCashLog('open', openAmt, 'レジ開け', pin, function (ok) {
              if (ok) {
                _showToast('レジを開けました', 'success');
                _fetchSalesSummary();
              }
            });
          });
          return;
        }

        // 入金 (CB1d: PIN 必須)
        if (e.target.closest('#ca-reg-cashin-btn')) {
          var inInput = document.getElementById('ca-reg-io-amount');
          var noteInput = document.getElementById('ca-reg-io-note');
          var inAmt = inInput ? (parseInt(inInput.value, 10) || 0) : 0;
          var inNote = noteInput ? noteInput.value : '';
          if (inAmt <= 0) { _showToast('金額を入力してください', 'error'); return; }
          _promptCashierPin(function (pin) {
            if (pin === null) return;
            _postCashLog('cash_in', inAmt, inNote || '入金', pin, function (ok) {
              if (ok) {
                _showToast('入金しました', 'success');
                _fetchSalesSummary();
              }
            });
          });
          return;
        }

        // 出金 (CB1d: PIN 必須 — 不正抑止のため特に重要)
        if (e.target.closest('#ca-reg-cashout-btn')) {
          var outInput = document.getElementById('ca-reg-io-amount');
          var outNoteInput = document.getElementById('ca-reg-io-note');
          var outAmt = outInput ? (parseInt(outInput.value, 10) || 0) : 0;
          var outNote = outNoteInput ? outNoteInput.value : '';
          if (outAmt <= 0) { _showToast('金額を入力してください', 'error'); return; }
          _promptCashierPin(function (pin) {
            if (pin === null) return;
            _postCashLog('cash_out', outAmt, outNote || '出金', pin, function (ok) {
              if (ok) {
                _showToast('出金しました', 'success');
                _fetchSalesSummary();
              }
            });
          });
          return;
        }

        // レジ閉め (CB1d: PIN 必須)
        if (e.target.closest('#ca-reg-close-btn')) {
          var closeInput = document.getElementById('ca-reg-close-amount');
          var closeNoteInput = document.getElementById('ca-reg-close-note');
          var closeAmt = closeInput ? (parseInt(closeInput.value, 10) || 0) : 0;
          var closeNote = closeNoteInput ? closeNoteInput.value.replace(/^\s+|\s+$/g, '') : '';
          var closeSummary = _getCloseReconcileSummary();
          var expected = closeSummary.expectedCash;
          var diff = closeAmt - expected;
          var diffStr = diff >= 0 ? '+' + Utils.formatYen(diff) : Utils.formatYen(diff);
          if (diff !== 0 && !closeNote) {
            _showToast('差額がある場合は理由メモを入力してください', 'error');
            if (closeNoteInput) closeNoteInput.focus();
            return;
          }
          var closeNoteText = 'レジ閉め（差額: ' + diffStr + '）';
          if (closeNote) closeNoteText += ' ' + closeNote;
          closeNoteText = closeNoteText.slice(0, 180);
          var confirmMsg = 'レジを閉めますか？'
            + '\n実際現金: ' + Utils.formatYen(closeAmt)
            + '\n予想現金: ' + Utils.formatYen(expected)
            + '\n差額: ' + diffStr
            + '\n現金売上: ' + Utils.formatYen(closeSummary.cashSales)
            + '\nカード売上: ' + Utils.formatYen(closeSummary.cardSales)
            + '\nQR/電子: ' + Utils.formatYen(closeSummary.qrSales);
          if (!confirm(confirmMsg)) return;
          _promptCashierPin(function (pin) {
            if (pin === null) return;
            _postCashLog('close', closeAmt, closeNoteText, pin, function (ok) {
              if (ok) {
                _showToast('レジを閉めました', 'success');
                _fetchSalesSummary();
              }
            }, {
              expected_amount: expected,
              difference_amount: diff,
              cash_sales_amount: closeSummary.cashSales,
              card_sales_amount: closeSummary.cardSales,
              qr_sales_amount: closeSummary.qrSales,
              reconciliation_note: closeNote
            });
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

  /**
   * Phase 4: 緊急レジモード用の read-only な現在コンテキスト snapshot を返す。
   * 既存内部状態への直接参照は返さず、必要最小限の値をコピーして返す。
   *
   * 戻り値:
   *   { storeId, storeName, userName, userRole,
   *     tableId?, tableCode?, isMerged, mergedGroupKeys?,
   *     orderIds[], items[{orderId,itemId,name,qty,price,taxRate,status}],
   *     subtotal, tax, totalAmount, paymentMethod,
   *     hasTableContext: bool }
   *
   * Phase 4 レビュー指摘 #2 対応:
   *   テーブル選択・品目がない場合でも storeId/storeName/userName だけは必ず返す (store-level context)。
   *   これにより緊急レジ UI の「手入力モード」が storeId を取得できて会計記録を残せる。
   *   store-level のみの場合は hasTableContext=false で、UI 側が判定できる。
   *   storeId すら未確定の場合のみ null を返す (初期化未完了)。
   */
  CashierApp.getEmergencyContext = function () {
    try {
      if (!_storeId) return null;   // 店舗未確定のみ null

      var isMergedSubmit = _mergeMode && _mergedGroupKeys.length >= 2;
      var group = isMergedSubmit ? null : _findGroup(_selectedTableId);
      var hasTableContext = !!(isMergedSubmit || group || (_items && _items.length > 0));

      var itemsSnap = [];
      var hasPartialSelection = false; // Fix 5 (2026-04-20): 個別会計状態警告用
      for (var i = 0; i < _items.length; i++) {
        var it = _items[i];
        var isPayable = !!(it && !it.paymentId && it.status !== 'cancelled');
        if (!_checkedItems[i]) {
          // 未チェックかつ「会計対象になり得る品目」が残っていれば partial 状態
          if (isPayable) hasPartialSelection = true;
          continue;
        }
        if (!isPayable) continue; // 既会計・取消済みは除外
        itemsSnap.push({
          orderId: it.orderId || null,
          itemId:  it.itemId  || null,
          name:    it.name    || '',
          qty:     it.qty     || 0,
          price:   it.price   || 0,
          taxRate: (typeof it.taxRate === 'number') ? it.taxRate : null,
          status:  it.status  || null
        });
      }

      // orderIds を抽出 (重複除去)
      var orderIdMap = {};
      for (var j = 0; j < itemsSnap.length; j++) {
        if (itemsSnap[j].orderId) orderIdMap[itemsSnap[j].orderId] = true;
      }
      var orderIds = [];
      for (var k in orderIdMap) {
        if (Object.prototype.hasOwnProperty.call(orderIdMap, k)) orderIds.push(k);
      }

      var calc = {};
      try { calc = _calcTotal() || {}; } catch (ee) { calc = {}; }
      var subtotal = (calc.subtotal10 || 0) + (calc.subtotal8 || 0);
      var tax      = (calc.tax10 || 0)      + (calc.tax8 || 0);
      var total    = calc.finalTotal || 0;

      return {
        storeId:   _storeId,
        storeName: _storeName || '',
        userName:  _userName  || '',
        userRole:  _userRole  || '',
        tableId:   group ? group.tableId   : null,
        tableCode: group ? group.tableCode : '',
        isMerged:  isMergedSubmit,
        mergedGroupKeys: isMergedSubmit ? _mergedGroupKeys.slice() : null,
        orderIds:  orderIds,
        items:     itemsSnap,
        subtotal:  subtotal,
        tax:       tax,
        totalAmount: total,
        paymentMethod: _paymentMethod,
        hasTableContext: hasTableContext,
        hasPartialSelection: hasPartialSelection
      };
    } catch (e) {
      // 致命的エラー時も storeId は最低限返す (緊急レジの記録を諦めないため)
      if (_storeId) {
        return {
          storeId: _storeId, storeName: _storeName || '',
          userName: _userName || '', userRole: _userRole || '',
          tableId: null, tableCode: '', isMerged: false, mergedGroupKeys: null,
          orderIds: [], items: [],
          subtotal: 0, tax: 0, totalAmount: 0,
          paymentMethod: 'cash',
          hasTableContext: false
        };
      }
      return null;
    }
  };

  window.CashierApp = CashierApp;
})();
