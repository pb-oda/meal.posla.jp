/**
 * ハンディPOS アプリケーション
 *
 * スタッフ向け注文入力画面。店内（ハンディ）・テイクアウト注文の作成・修正。
 * オプション/トッピング選択対応。
 */
var HandyApp = (function () {
  'use strict';

  // ==== State ====
  var _user = null;
  var _stores = [];
  var _storeId = null;
  var _tables = [];
  var _categories = [];
  var _menuItemMap = {};   // menuItemId → full item data (with optionGroups)
  var _cart = [];          // [{id, cartKey, name, basePrice, price, qty, options:[]}]
  var _mode = 'dinein';    // 'dinein' | 'takeout'
  var _currentTab = 'dinein';
  var _activeCatId = null;
  var _cartOpen = false;
  var _editingOrder = null;
  var _quickOrders = [];
  var _quickFrequentItems = [];
  var _orderContextTimer = null;
  var _pendingOrders = [];
  var _pendingRetryLocks = {};
  var _opsTimer = null;
  var _opsAlerts = [];
  var _opsLogs = [];
  var _terminalMode = 'handy';
  var _deviceUid = '';
  var _terminalSynced = false;
  var _opsSoundEnabled = false;
  var _opsRealertEnabled = true;
  var _opsRealertIntervalSec = 180;
  var _opsAudioCtx = null;
  var _opsNoticeState = {};

  var API_BASE = '../../api';
  var PENDING_KEY_PREFIX = 'posla_handy_pending_orders_';
  var TERMINAL_MODE_KEY = 'posla_handy_terminal_mode';
  var TERMINAL_DEVICE_KEY = 'posla_handy_device_uid';
  var TERMINAL_SOUND_KEY = 'posla_handy_sound_enabled';
  var TERMINAL_REALERT_KEY = 'posla_handy_realert_enabled';
  var TERMINAL_REALERT_INTERVAL_KEY = 'posla_handy_realert_interval_sec';

  // ==== DOM cache ====
  var els = {};

  // ==== API helpers ====
  function apiFetch(path, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    return fetch(API_BASE + path, opts).then(function (r) {
      return r.text().then(function (text) {
        try {
          return JSON.parse(text);
        } catch (e) {
          console.error('API non-JSON response:', text.substring(0, 300));
          return Promise.reject(new Error(text.substring(0, 120)));
        }
      });
    });
  }

  function apiErrorMsg(json) {
    if (!json || !json.error) return 'エラー';
    if (window.Utils && Utils.formatError) return Utils.formatError(json);
    if (typeof json.error === 'string') return json.error;
    return json.error.message || json.error.code || 'エラー';
  }

  function apiPost(path, body) {
    return apiFetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
  }

  function apiPatch(path, body) {
    return apiFetch(path, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
  }

  // ==== Init ====
  function init() {
    cacheDom();

    apiFetch('/auth/me.php')
      .then(function (json) {
        if (!json.ok) {
          window.location.href = '../admin/index.html';
          return;
        }
        _user = json.data.user;
        _stores = json.data.stores || [];

        var saved = localStorage.getItem('mt_handy_store');
        if (saved && _stores.some(function (s) { return s.id === saved; })) {
          _storeId = saved;
        } else if (_stores.length === 1) {
          _storeId = _stores[0].id;
          localStorage.setItem('mt_handy_store', _storeId);
        } else if (_stores.length > 1) {
          // 複数店舗 + localStorage未保存 → オーバーレイで選択を強制
          els.userName.textContent = _user.displayName || _user.email;
          bindEvents();
          _showStoreOverlay(_stores, function (selectedId) {
            _storeId = selectedId;
            localStorage.setItem('mt_handy_store', _storeId);
            _checkGpsAccess(function () {
              setupUI();
              loadStoreData().then(function () {
                renderCategories();
                renderMenuItems();
                renderTables();
                renderCart();
                loadQuickOrderHints();
                loadCompactTableContext();
                _menuVersionTimer = setInterval(checkMenuVersion, _VERSION_CHECK_INTERVAL);
              });
            });
          });
          return 'overlay'; // 店舗選択中 → 後続.thenでスキップ
        }

        bindEvents();
        _checkGpsAccess(function () {
          setupUI();
          loadStoreData().then(function () {
            renderCategories();
            renderMenuItems();
            renderTables();
            renderCart();
            loadQuickOrderHints();
            loadCompactTableContext();
            _menuVersionTimer = setInterval(checkMenuVersion, _VERSION_CHECK_INTERVAL);
          });
        });
        return 'gps_check';
      })
      .then(function (flag) {
        if (flag === 'overlay' || flag === 'gps_check') return;
      })
      .catch(function (err) {
        console.error('Init error:', err);
      });
  }

  function cacheDom() {
    els.storeName    = document.getElementById('handy-store-name');
    els.changeStore  = document.getElementById('handy-change-store');
    els.terminalMode = document.getElementById('handy-terminal-mode');
    els.soundBtn     = document.getElementById('handy-sound-btn');
    els.pendingBtn   = document.getElementById('handy-pending-btn');
    els.pendingCount = document.getElementById('handy-pending-count');
    els.userName     = document.getElementById('handy-user-name');
    els.ctxDinein    = document.getElementById('ctx-dinein');
    els.ctxTakeout   = document.getElementById('ctx-takeout');
    els.tableSel     = document.getElementById('table-select');
    els.selectedTablePanel = document.getElementById('selected-table-panel');
    els.toName       = document.getElementById('to-name');
    els.toPhone      = document.getElementById('to-phone');
    els.toPickup     = document.getElementById('to-pickup');
    els.quickOrderPanel = document.getElementById('quick-order-panel');
    els.catTabs      = document.getElementById('category-tabs');
    els.menuGrid     = document.getElementById('menu-grid');
    els.panelOrder   = document.getElementById('panel-order');
    els.panelOps     = document.getElementById('panel-ops');
    els.opsSummary   = document.getElementById('ops-summary');
    els.opsAlerts    = document.getElementById('ops-alerts');
    els.opsTasks     = document.getElementById('ops-tasks');
    els.opsLog       = document.getElementById('ops-log');
    els.opsTableGrid = document.getElementById('ops-table-grid');
    els.panelHistory = document.getElementById('panel-history');
    els.cartBar      = document.getElementById('cart-bar');
    els.cartSummary  = document.getElementById('cart-summary');
    els.cartDetail   = document.getElementById('cart-detail');
    els.cartBadge    = document.getElementById('cart-badge');
    els.cartTotal    = document.getElementById('cart-total');
    els.cartItems    = document.getElementById('cart-items');
    els.btnSubmit    = document.getElementById('btn-submit');
    els.orderList    = document.getElementById('order-list');
    els.panelTables  = document.getElementById('panel-tables');
    els.tblInfo      = document.getElementById('table-status-info');
    els.tblGrid      = document.getElementById('table-status-grid');
    els.rsvNewName   = document.getElementById('rsv-new-name');
    els.rsvNewPhone  = document.getElementById('rsv-new-phone');
    els.rsvNewTime   = document.getElementById('rsv-new-time');
    els.rsvNewParty  = document.getElementById('rsv-new-party');
    els.rsvNewTable  = document.getElementById('rsv-new-table');
    els.rsvNewMemo   = document.getElementById('rsv-new-memo');
    els.rsvCreateBtn = document.getElementById('rsv-create-btn');
    els.rsvCheckTime = document.getElementById('rsv-check-time');
    els.rsvCheckParty = document.getElementById('rsv-check-party');
    els.rsvAvailability = document.getElementById('rsv-availability');
    els.rsvToggleCreate = document.getElementById('rsv-toggle-create');
    els.rsvCreatePanel = document.getElementById('rsv-create-panel');
    els.editBanner   = document.getElementById('edit-banner');
    els.toast        = document.getElementById('toast');
    els.optOverlay   = document.getElementById('option-overlay');
    els.optTitle     = document.getElementById('option-modal-title');
    els.optBody      = document.getElementById('option-modal-body');
    els.optPrice     = document.getElementById('option-modal-price');
    els.optAdd       = document.getElementById('option-modal-add');
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

    setDefaultPickupTime();
    loadTerminalMode();
    initTerminalProfile();
    renderTerminalModeControls();
    renderOpsNotifyControls();
    syncTerminalProfile(false);
    loadPendingOrders();
    renderPendingBadge();
  }

  function loadTerminalMode() {
    var saved = '';
    try { saved = localStorage.getItem(TERMINAL_MODE_KEY) || ''; } catch (e) {}
    if (saved === 'wall' || saved === 'register') {
      _terminalMode = saved;
    } else {
      _terminalMode = 'handy';
    }
    applyTerminalModeClass();
  }

  function setTerminalMode(mode) {
    if (mode !== 'handy' && mode !== 'wall' && mode !== 'register') return;
    _terminalMode = mode;
    try { localStorage.setItem(TERMINAL_MODE_KEY, mode); } catch (e) {}
    applyTerminalModeClass();
    renderTerminalModeControls();
    syncTerminalProfile(true);
    if (_currentTab === 'ops') renderOperationsBoard();
    if (_currentTab === 'tables') renderTableStatus(_lastTableData);
  }

  function applyTerminalModeClass() {
    document.body.classList.remove('handy-mode-handy');
    document.body.classList.remove('handy-mode-wall');
    document.body.classList.remove('handy-mode-register');
    document.body.classList.add('handy-mode-' + _terminalMode);
  }

  function renderTerminalModeControls() {
    if (!els.terminalMode) return;
    var modes = [
      { id: 'handy', label: 'ハンディ' },
      { id: 'wall', label: '壁掛け' },
      { id: 'register', label: 'レジ横' }
    ];
    var html = '';
    for (var i = 0; i < modes.length; i++) {
      html += '<button type="button" class="' + (_terminalMode === modes[i].id ? 'active' : '') + '" data-terminal-mode="' + modes[i].id + '">' + modes[i].label + '</button>';
    }
    els.terminalMode.innerHTML = html;
  }

  function initTerminalProfile() {
    _deviceUid = getOrCreateTerminalDeviceUid();
    _opsSoundEnabled = loadLocalBool(TERMINAL_SOUND_KEY, false);
    _opsRealertEnabled = loadLocalBool(TERMINAL_REALERT_KEY, true);
    _opsRealertIntervalSec = loadLocalInt(TERMINAL_REALERT_INTERVAL_KEY, 180, 60, 900);
  }

  function getOrCreateTerminalDeviceUid() {
    var saved = '';
    try { saved = localStorage.getItem(TERMINAL_DEVICE_KEY) || ''; } catch (e) {}
    if (/^[a-zA-Z0-9_-]{12,80}$/.test(saved)) return saved;
    saved = 'hdy_' + String(Date.now()) + '_' + String(Math.floor(Math.random() * 1000000000));
    try { localStorage.setItem(TERMINAL_DEVICE_KEY, saved); } catch (e2) {}
    return saved;
  }

  function loadLocalBool(key, defaultValue) {
    var raw = null;
    try { raw = localStorage.getItem(key); } catch (e) {}
    if (raw === '1') return true;
    if (raw === '0') return false;
    return !!defaultValue;
  }

  function loadLocalInt(key, defaultValue, min, max) {
    var raw = null;
    var val;
    try { raw = localStorage.getItem(key); } catch (e) {}
    val = parseInt(raw, 10);
    if (isNaN(val)) val = defaultValue;
    return Math.max(min, Math.min(max, val));
  }

  function persistTerminalLocal() {
    try {
      localStorage.setItem(TERMINAL_MODE_KEY, _terminalMode);
      localStorage.setItem(TERMINAL_SOUND_KEY, _opsSoundEnabled ? '1' : '0');
      localStorage.setItem(TERMINAL_REALERT_KEY, _opsRealertEnabled ? '1' : '0');
      localStorage.setItem(TERMINAL_REALERT_INTERVAL_KEY, String(_opsRealertIntervalSec));
    } catch (e) {}
  }

  function getTerminalLabel() {
    if (_terminalMode === 'wall') return '壁掛け端末';
    if (_terminalMode === 'register') return 'レジ横端末';
    return 'ハンディ端末';
  }

  function syncTerminalProfile(showToast) {
    if (!_storeId || !_deviceUid) return;
    persistTerminalLocal();
    apiPatch('/store/handy-terminal.php', {
      store_id: _storeId,
      device_uid: _deviceUid,
      device_label: getTerminalLabel(),
      terminal_mode: _terminalMode,
      sound_enabled: _opsSoundEnabled ? 1 : 0,
      realert_enabled: _opsRealertEnabled ? 1 : 0,
      realert_interval_sec: _opsRealertIntervalSec
    }).then(function (json) {
      if (!json.ok) {
        _terminalSynced = false;
        renderOpsNotifyControls();
        if (showToast) toast(apiErrorMsg(json), 'error');
        return;
      }
      _terminalSynced = true;
      if (json.data && json.data.terminal) {
        applyTerminalProfile(json.data.terminal);
      }
      renderOpsNotifyControls();
    }).catch(function () {
      _terminalSynced = false;
      renderOpsNotifyControls();
      if (showToast) toast('端末設定を同期できませんでした', 'error');
    });
  }

  function applyTerminalProfile(profile) {
    if (!profile) return;
    if (profile.terminalMode === 'handy' || profile.terminalMode === 'wall' || profile.terminalMode === 'register') {
      _terminalMode = profile.terminalMode;
      applyTerminalModeClass();
      renderTerminalModeControls();
    }
    _opsSoundEnabled = !!profile.soundEnabled;
    _opsRealertEnabled = profile.realertEnabled !== false;
    _opsRealertIntervalSec = loadProfileInterval(profile.realertIntervalSec);
    persistTerminalLocal();
  }

  function loadProfileInterval(value) {
    var val = parseInt(value, 10);
    if (isNaN(val)) val = 180;
    return Math.max(60, Math.min(900, val));
  }

  function renderOpsNotifyControls() {
    if (!els.soundBtn) return;
    els.soundBtn.textContent = _opsSoundEnabled ? '音ON' : '音OFF';
    els.soundBtn.className = 'handy-header__sound' + (_opsSoundEnabled ? ' handy-header__sound--on' : '') + (_terminalSynced ? '' : ' handy-header__sound--unsynced');
    els.soundBtn.title = '通知音: ' + (_opsSoundEnabled ? 'ON' : 'OFF') + ' / 再通知' + Math.floor(_opsRealertIntervalSec / 60) + '分' + (_terminalSynced ? '' : ' / 端末設定未同期');
  }

  function toggleOpsSound() {
    _opsSoundEnabled = !_opsSoundEnabled;
    persistTerminalLocal();
    renderOpsNotifyControls();
    syncTerminalProfile(false);
    if (_opsSoundEnabled) {
      unlockOpsAudio();
      playOpsTone(false);
      toast('通知音をONにしました', 'success');
    } else {
      toast('通知音をOFFにしました', 'success');
    }
  }

  function bindEvents() {
    // 店舗切替リンク
    els.changeStore.addEventListener('click', function (e) {
      e.preventDefault();
      _showStoreOverlay(_stores, function (selectedId) {
        _storeId = selectedId;
        localStorage.setItem('mt_handy_store', _storeId);
        var s = _stores.find(function (x) { return x.id === selectedId; });
        els.storeName.textContent = s ? s.name : '-';
        clearCart();
        syncTerminalProfile(false);
        loadStoreData().then(function () {
          renderCategories();
          renderMenuItems();
          renderTables();
          renderCart();
          loadPendingOrders();
          renderPendingBadge();
          loadQuickOrderHints();
          loadCompactTableContext();
        });
      });
    });

    // タブ切替
    document.querySelector('.handy-tabs').addEventListener('click', function (e) {
      var btn = e.target.closest('.handy-tabs__btn');
      if (!btn) return;
      switchTab(btn.dataset.tab);
    });

    // カテゴリタブ
    els.catTabs.addEventListener('click', function (e) {
      var tab = e.target.closest('.cat-tab');
      if (!tab) return;
      _activeCatId = tab.dataset.cat;
      updateCatActive();
      renderMenuItems();
    });

    if (els.tableSel) {
      els.tableSel.addEventListener('change', function () {
        renderSelectedTablePanel();
        renderQuickOrderPanel();
        loadCompactTableContext();
      });
    }

    if (els.quickOrderPanel) {
      els.quickOrderPanel.addEventListener('click', function (e) {
        var itemBtn = e.target.closest('[data-quick-item-id]');
        if (itemBtn) {
          addMenuItemById(itemBtn.getAttribute('data-quick-item-id'));
          return;
        }
        var orderBtn = e.target.closest('[data-reorder-id]');
        if (orderBtn) {
          addOrderToCart(orderBtn.getAttribute('data-reorder-id'));
          return;
        }
      });
    }

    if (els.selectedTablePanel) {
      els.selectedTablePanel.addEventListener('click', function (e) {
        var action = e.target.closest('[data-table-action]');
        if (!action) return;
        handleTableAction(action);
      });
    }

    if (els.pendingBtn) {
      els.pendingBtn.addEventListener('click', function () {
        openPendingOrdersModal();
      });
    }

    if (els.terminalMode) {
      els.terminalMode.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-terminal-mode]');
        if (!btn) return;
        setTerminalMode(btn.getAttribute('data-terminal-mode'));
      });
    }

    if (els.soundBtn) {
      els.soundBtn.addEventListener('click', function () {
        toggleOpsSound();
      });
    }

    document.addEventListener('pointerdown', function () {
      if (_opsSoundEnabled) unlockOpsAudio();
    }, false);

    if (els.panelOps) {
      els.panelOps.addEventListener('click', function (e) {
        var refreshBtn = e.target.closest('#ops-refresh');
        if (refreshBtn) {
          loadOperationsBoard();
          return;
        }
        var pendingBtn = e.target.closest('[data-ops-pending]');
        if (pendingBtn) {
          openPendingOrdersModal();
          return;
        }
        var ackBtn = e.target.closest('[data-ops-ack-alert]');
        if (ackBtn) {
          acknowledgeOperationAlert(ackBtn.getAttribute('data-ops-ack-alert'));
          return;
        }
        var undoServedBtn = e.target.closest('[data-ops-undo-served]');
        if (undoServedBtn) {
          undoServedItem(undoServedBtn.getAttribute('data-ops-undo-served'));
          return;
        }
        var tableAction = e.target.closest('[data-table-action]');
        if (tableAction) {
          handleTableAction(tableAction);
          return;
        }
        var openTable = e.target.closest('[data-ops-open-table]');
        if (openTable) {
          switchTab('dinein');
          if (els.tableSel) {
            els.tableSel.value = openTable.getAttribute('data-ops-open-table');
            loadCompactTableContext();
          }
        }
      });
    }

    // メニュー品目タップ
    els.menuGrid.addEventListener('click', function (e) {
      var el = e.target.closest('.menu-item');
      if (!el || el.classList.contains('menu-item--sold-out')) return;
      var itemId = el.dataset.id;
      var menuItem = _menuItemMap[itemId];
      if (!menuItem) return;

      if (menuItem.optionGroups && menuItem.optionGroups.length > 0) {
        openOptionModal(menuItem);
      } else {
        addToCart({
          id: menuItem.menuItemId,
          cartKey: menuItem.menuItemId,
          name: menuItem.name,
          basePrice: menuItem.price,
          price: menuItem.price,
          qty: 1,
          options: []
        });
      }
    });

    // カートバーサマリータップ
    els.cartSummary.addEventListener('click', function (e) {
      if (e.target.closest('.cart-bar__submit')) return;
      toggleCartDetail();
    });

    // カートアイテム操作
    els.cartDetail.addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      if (!btn) return;
      var idx = parseInt(btn.dataset.idx, 10);
      if (btn.classList.contains('qty-minus')) {
        updateQty(idx, -1);
      } else if (btn.classList.contains('qty-plus')) {
        updateQty(idx, 1);
      }
    });

    // カートクリア
    document.getElementById('btn-clear-cart').addEventListener('click', function () {
      if (_cart.length === 0) return;
      if (!confirm('カートを空にしますか？')) return;
      clearCart();
      renderCart();
      renderMenuItems();
    });

    // 送信ボタン
    els.btnSubmit.addEventListener('click', function () { submitOrder(); });
    document.getElementById('btn-submit2').addEventListener('click', function () { submitOrder(); });

    // 編集キャンセル
    document.getElementById('btn-cancel-edit').addEventListener('click', function () {
      cancelEdit();
    });

    // 注文履歴
    els.panelHistory.addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      if (!btn) return;
      if (btn.id === 'btn-refresh-history') {
        loadHistory();
      } else if (btn.classList.contains('btn-edit')) {
        startEdit(btn.dataset.orderId);
      } else if (btn.classList.contains('btn-order-cancel')) {
        cancelWholeOrder(btn.dataset.orderId);
      } else if (btn.getAttribute('data-item-cancel')) {
        cancelOrderItem(btn.getAttribute('data-order-id'), btn.getAttribute('data-item-cancel'));
      } else if (btn.getAttribute('data-item-undo-served')) {
        undoServedItem(btn.getAttribute('data-item-undo-served'));
      } else if (btn.getAttribute('data-item-qty-delta')) {
        adjustOrderItemQty(btn.getAttribute('data-order-id'), btn.getAttribute('data-item-id'), parseInt(btn.getAttribute('data-current-qty'), 10) || 0, parseInt(btn.getAttribute('data-item-qty-delta'), 10) || 0);
      }
    });

    // ログアウト
    document.getElementById('btn-logout').addEventListener('click', function () {
      fetch(API_BASE + '/auth/logout.php', { method: 'DELETE', credentials: 'same-origin' })
        .finally(function () { window.location.href = '../admin/index.html'; });
    });

    // テーブル状況: 着席ボタン / 着席キャンセルボタン / メモボタン
    els.tblGrid.addEventListener('click', function (e) {
      var tableAction = e.target.closest('[data-table-action]');
      if (tableAction) {
        handleTableAction(tableAction);
        return;
      }
      var seatBtn = e.target.closest('.ts-card__seat-btn');
      if (seatBtn) {
        openSeatModal(seatBtn.dataset.tableId, seatBtn.dataset.tableCode);
        return;
      }
      // L-9: テーブル開放 (ホワイトリスト方式) — スタッフが認証 → 客がQR読むと自動着席
      var openBtn = e.target.closest('.ts-card__open-btn');
      if (openBtn) {
        openTableForCustomer(openBtn.dataset.tableId, openBtn.dataset.tableCode);
        return;
      }
      var memoBtn = e.target.closest('.ts-card__memo-btn');
      if (memoBtn) {
        var sid = memoBtn.dataset.sessionId;
        var tc = memoBtn.dataset.tableCode;
        var currentMemo = '';
        for (var i = 0; i < _lastTableData.length; i++) {
          if (_lastTableData[i].session && _lastTableData[i].session.id === sid) {
            currentMemo = _lastTableData[i].session.memo || '';
            break;
          }
        }
        openMemoModal(sid, tc, currentMemo);
        return;
      }
      // F-QR1: 個別QRボタン
      var subQrBtn = e.target.closest('.ts-card__sub-qr-btn');
      if (subQrBtn) {
        _openSubQrModal(subQrBtn.dataset.sessionId, subQrBtn.dataset.tableId, subQrBtn.dataset.tableCode);
        return;
      }
      var cancelBtn = e.target.closest('.ts-card__cancel-btn');
      if (cancelBtn) {
        cancelSession(cancelBtn.dataset.sessionId, cancelBtn.dataset.tableCode);
        return;
      }
      // Batch-X: 既存セッションに plan を後付け (QR 先行ケース対応)
      var startPlanBtn = e.target.closest('.ts-card__start-plan-btn');
      if (startPlanBtn) {
        openStartPlanModal(startPlanBtn.dataset.sessionId, startPlanBtn.dataset.tableCode);
      }
    });

    // オプションモーダル: 閉じる
    els.optOverlay.addEventListener('click', function (e) {
      if (e.target === els.optOverlay) closeOptionModal();
    });
    document.getElementById('option-modal-cancel').addEventListener('click', closeOptionModal);

    // オプションモーダル: 選択変更 → 価格更新
    els.optBody.addEventListener('change', updateOptionModalPrice);

    // オプションモーダル: 追加
    els.optAdd.addEventListener('click', addFromOptionModal);
  }

  // ==== Data loading ====
  function loadStoreData() {
    return Promise.all([loadMenu(), loadTables()]);
  }

  function loadMenu() {
    return apiFetch('/customer/menu.php?store_id=' + _storeId)
      .then(function (json) {
        if (json.ok) {
          _applyMenuData(json.data);
          // Phase 3: 正常取得を snapshot に退避
          if (typeof OfflineSnapshot !== 'undefined' && _storeId) {
            OfflineSnapshot.save('handy', _storeId, 'menu', json.data);
          }
          if (typeof OfflineStateBanner !== 'undefined') {
            OfflineStateBanner.setLastSuccessAt(Date.now());
            OfflineStateBanner.markFresh();
          }
        }
      })
      .catch(function (err) {
        // Phase 3: メニュー未ロードの初回失敗時のみ snapshot 復元
        if (_categories && _categories.length > 0) {
          // 既存表示あり: バナーのみ、表示は壊さない
          if (typeof OfflineStateBanner !== 'undefined') OfflineStateBanner.markStale();
          throw err;
        }
        if (typeof OfflineSnapshot !== 'undefined' && _storeId) {
          var snap = OfflineSnapshot.load('handy', _storeId, 'menu');
          if (snap && snap.data) {
            _applyMenuData(snap.data);
            if (typeof OfflineStateBanner !== 'undefined') {
              OfflineStateBanner.setLastSuccessAt(snap.savedAt);
              OfflineStateBanner.markStale();
            }
            return; // fulfil: 画面は古いデータで初期化された
          }
        }
        throw err;
      });
  }

  // Phase 3: loadMenu / snapshot 復元両方から呼ぶメニューデータ適用関数
  function _applyMenuData(data) {
    _categories = (data && data.categories) || [];
    _menuItemMap = {};
    _categories.forEach(function (cat) {
      (cat.items || []).forEach(function (item) {
        _menuItemMap[item.menuItemId] = item;
      });
    });
  }

  function checkMenuVersion() {
    if (!_storeId) return;
    fetch(API_BASE + '/store/menu-version.php?store_id=' + _storeId + '&_t=' + Date.now(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.ok) return;
        var v = json.data.version;
        if (_lastMenuVersion && v !== _lastMenuVersion) {
          refreshMenuSilent();
        }
        _lastMenuVersion = v;
      })
      .catch(function () { /* サイレント失敗 */ });
  }

  function refreshMenuSilent() {
    if (!_storeId) return;
    fetch(API_BASE + '/customer/menu.php?store_id=' + _storeId + '&_t=' + Date.now(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.ok) return;
        _categories = json.data.categories || [];
        _menuItemMap = {};
        _categories.forEach(function (cat) {
          (cat.items || []).forEach(function (item) {
            _menuItemMap[item.menuItemId] = item;
          });
        });
        renderCategories();
        renderMenuItems();
      })
      .catch(function () { /* サイレント失敗 */ });
  }

  function loadQuickOrderHints() {
    if (!_storeId) return;
    apiFetch('/kds/orders.php?store_id=' + encodeURIComponent(_storeId) + '&view=accounting')
      .then(function (json) {
        if (!json.ok) return;
        _quickOrders = json.data.orders || [];
        buildQuickFrequentItems();
        renderQuickOrderPanel();
      })
      .catch(function () {
        // ハンディ注文自体は止めない
      });
  }

  function buildQuickFrequentItems() {
    var map = {};
    var list = [];
    var orders = (_quickOrders || []).slice().sort(function (a, b) {
      return new Date(b.created_at) - new Date(a.created_at);
    });
    for (var i = 0; i < orders.length; i++) {
      if (orders[i].status === 'cancelled') continue;
      var items = orders[i].items || [];
      for (var j = 0; j < items.length; j++) {
        var it = items[j];
        if (!it.id || !_menuItemMap[it.id]) continue;
        if (!map[it.id]) {
          map[it.id] = {
            id: it.id,
            name: it.name || _menuItemMap[it.id].name,
            count: 0,
            latest: orders[i].created_at
          };
        }
        map[it.id].count += parseInt(it.qty, 10) || 1;
      }
    }
    Object.keys(map).forEach(function (id) { list.push(map[id]); });
    list.sort(function (a, b) {
      if (b.count !== a.count) return b.count - a.count;
      return String(b.latest).localeCompare(String(a.latest));
    });
    _quickFrequentItems = list.slice(0, 6);
  }

  function renderQuickOrderPanel() {
    if (!els.quickOrderPanel) return;
    if (_mode !== 'dinein' && _mode !== 'takeout') {
      els.quickOrderPanel.style.display = 'none';
      return;
    }

    var selectedTableId = (_mode === 'dinein' && els.tableSel) ? els.tableSel.value : '';
    var recent = getRecentOrdersForQuick(selectedTableId);
    if (_quickFrequentItems.length === 0 && recent.length === 0) {
      els.quickOrderPanel.style.display = 'none';
      return;
    }

    var html = '';
    if (_quickFrequentItems.length > 0) {
      html += '<div class="quick-order-row">';
      html += '<div class="quick-order-title">よく出る</div>';
      html += '<div class="quick-order-chips">';
      for (var i = 0; i < _quickFrequentItems.length; i++) {
        var f = _quickFrequentItems[i];
        html += '<button type="button" class="quick-order-chip" data-quick-item-id="' + Utils.escapeHtml(f.id) + '">'
          + Utils.escapeHtml(f.name) + '<span>' + f.count + '</span></button>';
      }
      html += '</div></div>';
    }

    if (recent.length > 0) {
      html += '<div class="quick-order-row">';
      html += '<div class="quick-order-title">' + (selectedTableId ? 'この卓の直近' : '直近再注文') + '</div>';
      html += '<div class="quick-order-chips">';
      for (var j = 0; j < recent.length; j++) {
        var o = recent[j];
        html += '<button type="button" class="quick-order-chip quick-order-chip--recent" data-reorder-id="' + Utils.escapeHtml(o.id) + '">'
          + Utils.escapeHtml(formatOrderShortLabel(o)) + '</button>';
      }
      html += '</div></div>';
    }

    els.quickOrderPanel.innerHTML = html;
    els.quickOrderPanel.style.display = '';
  }

  function getRecentOrdersForQuick(tableId) {
    var orders = (_quickOrders || []).filter(function (o) {
      if (o.status === 'cancelled') return false;
      if (tableId && String(o.table_id || '') !== String(tableId)) return false;
      return (o.items || []).length > 0;
    }).sort(function (a, b) {
      return new Date(b.created_at) - new Date(a.created_at);
    });
    return orders.slice(0, 4);
  }

  function formatOrderShortLabel(order) {
    var items = order.items || [];
    if (items.length === 0) return '再注文';
    var first = items[0].name || '品目';
    var count = 0;
    for (var i = 0; i < items.length; i++) {
      count += parseInt(items[i].qty, 10) || 1;
    }
    return first + (count > 1 ? ' ほか' + (count - 1) + '品' : '');
  }

  function addMenuItemById(itemId) {
    var menuItem = _menuItemMap[itemId];
    if (!menuItem) { toast('現在のメニューにありません', 'error'); return; }
    if (menuItem.soldOut) { toast('品切れです', 'error'); return; }
    if (menuItem.optionGroups && menuItem.optionGroups.length > 0) {
      openOptionModal(menuItem);
      return;
    }
    addToCart({
      id: menuItem.menuItemId,
      cartKey: menuItem.menuItemId,
      name: menuItem.name,
      basePrice: menuItem.price,
      price: menuItem.price,
      qty: 1,
      options: []
    });
  }

  function addOrderToCart(orderId) {
    var order = null;
    for (var i = 0; i < _quickOrders.length; i++) {
      if (_quickOrders[i].id === orderId) { order = _quickOrders[i]; break; }
    }
    if (!order) { toast('注文が見つかりません', 'error'); return; }
    var items = order.items || [];
    for (var j = 0; j < items.length; j++) {
      var it = items[j];
      var opts = it.options || [];
      var choiceIds = opts.map(function (o) { return o.choiceId || ''; }).sort().join(',');
      addToCart({
        id: it.id,
        cartKey: it.id + (choiceIds ? '__' + choiceIds : ''),
        name: it.name,
        basePrice: it.price,
        price: it.price,
        qty: parseInt(it.qty, 10) || 1,
        options: opts
      });
    }
    toast('直近注文をカートに追加しました', 'success');
    if (!_cartOpen) toggleCartDetail();
  }

  function loadTables() {
    return apiFetch('/store/tables.php?store_id=' + _storeId)
      .then(function (json) {
        if (json.ok) {
          _applyTablesData(json.data);
          if (typeof OfflineSnapshot !== 'undefined' && _storeId) {
            OfflineSnapshot.save('handy', _storeId, 'tables', json.data);
          }
        }
      })
      .catch(function (err) {
        // Phase 3: 初回失敗のみ snapshot 復元
        if (_tables && _tables.length > 0) {
          if (typeof OfflineStateBanner !== 'undefined') OfflineStateBanner.markStale();
          throw err;
        }
        if (typeof OfflineSnapshot !== 'undefined' && _storeId) {
          var snap = OfflineSnapshot.load('handy', _storeId, 'tables');
          if (snap && snap.data) {
            _applyTablesData(snap.data);
            if (typeof OfflineStateBanner !== 'undefined') {
              OfflineStateBanner.setLastSuccessAt(snap.savedAt);
              OfflineStateBanner.markStale();
            }
            return;
          }
        }
        throw err;
      });
  }

  function _applyTablesData(data) {
    _tables = ((data && data.tables) || []).filter(function (t) {
      return parseInt(t.is_active, 10) === 1;
    });
  }

  // ==== Tab management ====
  var _tablesPollTimer = null;
  var _menuVersionTimer = null;
  var _lastMenuVersion = null;
  var _VERSION_CHECK_INTERVAL = 2000; // 2秒

  function switchTab(tab) {
    _currentTab = tab;

    // ポーリング停止
    if (_tablesPollTimer) { clearInterval(_tablesPollTimer); _tablesPollTimer = null; }
    if (_menuVersionTimer) { clearInterval(_menuVersionTimer); _menuVersionTimer = null; }
    if (_orderContextTimer) { clearInterval(_orderContextTimer); _orderContextTimer = null; }
    if (_opsTimer) { clearInterval(_opsTimer); _opsTimer = null; }

    var btns = document.querySelectorAll('.handy-tabs__btn');
    btns.forEach(function (b) {
      b.classList.toggle('active', b.dataset.tab === tab);
    });

    els.panelOrder.style.display = 'none';
    els.panelHistory.style.display = 'none';
    els.panelTables.style.display = 'none';
    if (els.panelOps) els.panelOps.style.display = 'none';
    var pRsv = document.getElementById('panel-reservations');
    if (pRsv) pRsv.style.display = 'none';
    els.cartBar.style.display = 'none';

    if (tab === 'history') {
      els.panelHistory.style.display = 'block';
      loadHistory();
    } else if (tab === 'tables') {
      els.panelTables.style.display = 'block';
      loadTableStatus();
      _tablesPollTimer = setInterval(loadTableStatus, 5000);
    } else if (tab === 'ops') {
      if (els.panelOps) els.panelOps.style.display = 'block';
      loadOperationsBoard();
      _opsTimer = setInterval(loadOperationsBoard, 5000);
    } else if (tab === 'reservations') {
      if (pRsv) pRsv.style.display = 'block';
      initReservationTab();
    } else {
      els.panelOrder.style.display = 'block';
      els.cartBar.style.display = 'block';
      _mode = tab;
      els.ctxDinein.style.display = (tab === 'dinein') ? 'block' : 'none';
      els.ctxTakeout.style.display = (tab === 'takeout') ? 'block' : 'none';
      if (tab === 'takeout') setDefaultPickupTime();
      renderSelectedTablePanel();
      renderQuickOrderPanel();
      if (tab === 'dinein') {
        loadCompactTableContext();
        _orderContextTimer = setInterval(loadCompactTableContext, 10000);
      }
      // メニュー変更検知ポーリング開始
      _menuVersionTimer = setInterval(checkMenuVersion, _VERSION_CHECK_INTERVAL);
    }
  }

  // ==== Menu rendering ====
  function renderCategories() {
    if (_categories.length === 0) {
      els.catTabs.innerHTML = '';
      return;
    }
    if (!_activeCatId || !_categories.some(function (c) { return c.categoryId === _activeCatId; })) {
      _activeCatId = _categories[0].categoryId;
    }
    els.catTabs.innerHTML = _categories.map(function (cat) {
      return '<button class="cat-tab' + (cat.categoryId === _activeCatId ? ' active' : '') + '" data-cat="' + cat.categoryId + '">'
        + Utils.escapeHtml(cat.categoryName) + '</button>';
    }).join('');
  }

  function updateCatActive() {
    var tabs = els.catTabs.querySelectorAll('.cat-tab');
    tabs.forEach(function (t) {
      t.classList.toggle('active', t.dataset.cat === _activeCatId);
    });
  }

  function renderMenuItems() {
    var cat = _categories.find(function (c) { return c.categoryId === _activeCatId; });
    if (!cat || !cat.items) {
      els.menuGrid.innerHTML = '<div class="empty-state">メニューがありません</div>';
      return;
    }

    els.menuGrid.innerHTML = cat.items.map(function (item) {
      // カート内の同一アイテム合計数
      var totalInCart = _cart.reduce(function (s, c) { return s + (c.id === item.menuItemId ? c.qty : 0); }, 0);
      var soldClass = item.soldOut ? ' menu-item--sold-out' : '';
      var hasOpts = item.optionGroups && item.optionGroups.length > 0;
      // 2026-04-23: ハンディは一覧速度優先。写真は出さない。
      var imgHtml = '';
      var badgeHtml = totalInCart > 0 ? '<span class="menu-item__added">' + totalInCart + '</span>' : '';
      var soldBadge = item.soldOut ? '<span class="menu-item__sold">品切れ</span>' : '';
      var limitedBadge = item.source === 'local' ? '<span class="menu-item__badge-limited">限定</span>' : '';
      var optIcon = hasOpts ? '<span class="menu-item__opt-icon">...</span>' : '';

      return '<div class="menu-item' + soldClass + '" data-id="' + item.menuItemId + '">'
        + imgHtml + soldBadge + limitedBadge + badgeHtml
        + '<div class="menu-item__body">'
        + '<div class="menu-item__name">' + Utils.escapeHtml(item.name) + optIcon + '</div>'
        + '<div class="menu-item__price">' + Utils.formatYen(item.price) + '</div>'
        + '</div></div>';
    }).join('');
  }

  // ==== Option Modal ====
  var _optionItem = null; // 現在モーダルで選択中のメニューアイテム

  function openOptionModal(item) {
    _optionItem = item;
    els.optTitle.textContent = item.name;

    var html = '';
    item.optionGroups.forEach(function (group, gi) {
      var reqLabel = group.isRequired ? '<span class="opt-required">必須</span>' : '<span class="opt-optional">任意</span>';
      html += '<div class="opt-group">';
      html += '<div class="opt-group__title">' + Utils.escapeHtml(group.groupName) + ' ' + reqLabel + '</div>';

      group.choices.forEach(function (choice) {
        var inputType = group.selectionType === 'single' ? 'radio' : 'checkbox';
        var inputName = 'opt-group-' + gi;
        var diffLabel = choice.priceDiff > 0 ? ' (+' + Utils.formatYen(choice.priceDiff) + ')' :
                        choice.priceDiff < 0 ? ' (' + Utils.formatYen(choice.priceDiff) + ')' : '';
        var checked = choice.isDefault ? ' checked' : '';

        html += '<label class="opt-choice">'
          + '<input type="' + inputType + '" name="' + inputName + '"'
          + ' value="' + choice.choiceId + '"'
          + ' data-group-index="' + gi + '"'
          + ' data-price-diff="' + choice.priceDiff + '"'
          + ' data-choice-name="' + Utils.escapeHtml(choice.name) + '"'
          + checked + '>'
          + '<span class="opt-choice__label">' + Utils.escapeHtml(choice.name) + diffLabel + '</span>'
          + '</label>';
      });

      html += '</div>';
    });

    els.optBody.innerHTML = html;
    els.optAdd.textContent = 'カートに追加';
    els.optAdd.style.display = '';
    els.optBody.onclick = null;
    updateOptionModalPrice();
    els.optOverlay.classList.add('show');
  }

  function closeOptionModal() {
    els.optOverlay.classList.remove('show');
    _optionItem = null;
    els.optAdd.style.display = '';
    els.optBody.onclick = null;

    // 着席モーダルで cloneNode されたボタンを元のメニュー用に復元
    var newAdd = els.optAdd.cloneNode(true);
    els.optAdd.parentNode.replaceChild(newAdd, els.optAdd);
    els.optAdd = newAdd;
    els.optAdd.addEventListener('click', addFromOptionModal);
  }

  function updateOptionModalPrice() {
    if (!_optionItem) return;
    var total = _optionItem.price;
    var checked = els.optBody.querySelectorAll('input:checked');
    checked.forEach(function (inp) {
      total += parseInt(inp.dataset.priceDiff, 10) || 0;
    });
    els.optPrice.textContent = Utils.formatYen(total);

    // 必須バリデーション
    var valid = true;
    _optionItem.optionGroups.forEach(function (group, gi) {
      if (group.isRequired) {
        var any = els.optBody.querySelector('input[name="opt-group-' + gi + '"]:checked');
        if (!any) valid = false;
      }
    });
    els.optAdd.disabled = !valid;
  }

  function addFromOptionModal() {
    if (!_optionItem) return;

    var options = [];
    var priceDiff = 0;
    var checked = els.optBody.querySelectorAll('input:checked');
    checked.forEach(function (inp) {
      var gi = parseInt(inp.dataset.groupIndex, 10);
      var group = _optionItem.optionGroups[gi];
      var diff = parseInt(inp.dataset.priceDiff, 10) || 0;
      priceDiff += diff;
      options.push({
        groupId: group.groupId,
        groupName: group.groupName,
        choiceId: inp.value,
        choiceName: inp.dataset.choiceName,
        price: diff
      });
    });

    var finalPrice = _optionItem.price + priceDiff;

    // cartKey: 同じアイテム+同じオプション組み合わせ → 数量加算
    var choiceIds = options.map(function (o) { return o.choiceId; }).sort().join(',');
    var cartKey = _optionItem.menuItemId + (choiceIds ? '__' + choiceIds : '');

    addToCart({
      id: _optionItem.menuItemId,
      cartKey: cartKey,
      name: _optionItem.name,
      basePrice: _optionItem.price,
      price: finalPrice,
      qty: 1,
      options: options
    });

    closeOptionModal();
  }

  // ==== Table rendering ====
  function renderTables() {
    els.tableSel.innerHTML = '<option value="">テーブルを選択</option>';
    if (els.rsvNewTable) {
      els.rsvNewTable.innerHTML = '<option value="">テーブル未割当</option>';
    }
    _tables.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = t.table_code + (t.capacity ? ' (' + t.capacity + '名)' : '');
      els.tableSel.appendChild(opt);
      if (els.rsvNewTable) {
        var rsvOpt = document.createElement('option');
        rsvOpt.value = t.id;
        rsvOpt.textContent = t.table_code + (t.capacity ? ' (' + t.capacity + '名)' : '');
        els.rsvNewTable.appendChild(rsvOpt);
      }
    });
  }

  // ==== Cart management ====
  function addToCart(item) {
    var key = item.cartKey || item.id;
    var existing = _cart.find(function (c) { return c.cartKey === key; });
    if (existing) {
      existing.qty++;
    } else {
      _cart.push({
        id: item.id,
        cartKey: key,
        name: item.name,
        basePrice: item.basePrice || item.price,
        price: item.price,
        qty: item.qty || 1,
        options: item.options || []
      });
    }
    renderCart();
    renderMenuItems();
    toast(item.name + ' を追加', 'success');
  }

  function updateQty(idx, delta) {
    if (idx < 0 || idx >= _cart.length) return;
    _cart[idx].qty += delta;
    if (_cart[idx].qty <= 0) {
      _cart.splice(idx, 1);
    }
    renderCart();
    renderMenuItems();
  }

  function clearCart() {
    _cart = [];
    _editingOrder = null;
    updateEditBanner();
  }

  function getTotal() {
    return _cart.reduce(function (sum, c) { return sum + c.price * c.qty; }, 0);
  }

  function renderCart() {
    var count = _cart.reduce(function (s, c) { return s + c.qty; }, 0);
    var total = getTotal();
    var isEdit = !!_editingOrder;

    els.cartBadge.textContent = 'カート (' + count + '品)';
    els.cartTotal.textContent = Utils.formatYen(total);

    var label = isEdit ? '注文を更新' : '注文送信';
    els.btnSubmit.textContent = label;
    els.btnSubmit.disabled = count === 0;
    els.btnSubmit.className = 'cart-bar__submit' + (isEdit ? ' cart-bar__submit--edit' : '');

    var btnSubmit2 = document.getElementById('btn-submit2');
    btnSubmit2.textContent = label;
    btnSubmit2.disabled = count === 0;
    btnSubmit2.className = 'cart-bar__submit' + (isEdit ? ' cart-bar__submit--edit' : '');

    if (_cart.length === 0) {
      els.cartItems.innerHTML = '<div class="empty-state" style="padding:1rem">カートは空です</div>';
    } else {
      els.cartItems.innerHTML = _cart.map(function (c, i) {
        var optText = '';
        if (c.options && c.options.length > 0) {
          optText = '<div class="cart-item__options">'
            + c.options.map(function (o) { return Utils.escapeHtml(o.choiceName); }).join(', ')
            + '</div>';
        }
        return '<div class="cart-item">'
          + '<div class="cart-item__info">'
          + '<div class="cart-item__name">' + Utils.escapeHtml(c.name) + '</div>'
          + optText
          + '<div class="cart-item__price">' + Utils.formatYen(c.price) + '</div>'
          + '</div>'
          + '<div class="cart-item__qty">'
          + '<button class="qty-minus" data-idx="' + i + '">−</button>'
          + '<span>' + c.qty + '</span>'
          + '<button class="qty-plus" data-idx="' + i + '">+</button>'
          + '</div></div>';
      }).join('');
    }

    var footer = els.cartDetail.querySelector('.cart-bar__footer');
    if (footer) {
      footer.querySelector('.cart-bar__footer-total').textContent = '合計: ' + Utils.formatYen(total);
    }
  }

  function toggleCartDetail() {
    _cartOpen = !_cartOpen;
    els.cartDetail.style.display = _cartOpen ? 'block' : 'none';
  }

  // Phase 3: 状態変更系 API (POST/PATCH/DELETE) の共通ガード。
  // offline または stale (古いデータ表示中) で true を返す。
  // 呼び出し側は `if (_guardOfflineOrStale()) return;` で中断する。
  function _isOfflineOrStale() {
    if (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale) {
      return OfflineStateBanner.isOfflineOrStale();
    }
    if (typeof OfflineDetector !== 'undefined' && OfflineDetector.isOnline) {
      return !OfflineDetector.isOnline();
    }
    return false;
  }
  function _guardOfflineOrStale() {
    if (!_isOfflineOrStale()) return false;
    toast('\u30AA\u30D5\u30E9\u30A4\u30F3\u4E2D\u307E\u305F\u306F\u53E4\u3044\u30C7\u30FC\u30BF\u8868\u793A\u4E2D\u3067\u3059\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u64CD\u4F5C\u3057\u3066\u304F\u3060\u3055\u3044\u3002', 'error');
    return true;
  }

  // ==== Order submission ====
  function submitOrder() {
    if (_cart.length === 0) { toast('カートが空です', 'error'); return; }

    var items = _cart.map(function (c) {
      var entry = { id: c.id, name: c.name, price: c.price, qty: c.qty };
      if (c.options && c.options.length > 0) {
        entry.options = c.options;
      }
      return entry;
    });

    if (_editingOrder) {
      if (_guardOfflineOrStale()) return;
      var patchBody = {
        order_id: _editingOrder.id,
        store_id: _storeId,
        items: items
      };
      if (_editingOrder.order_type === 'takeout') {
        patchBody.customer_name = els.toName.value.trim();
        patchBody.customer_phone = els.toPhone.value.trim();
        patchBody.pickup_at = els.toPickup.value ? toMySQLDatetime(els.toPickup.value) : '';
      }

      apiPatch('/store/handy-order.php', patchBody)
        .then(function (json) {
          if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
          toast('注文を更新しました', 'success');
          clearCart();
          renderCart();
          renderMenuItems();
        })
        .catch(function (err) { toast(err.message || '通信エラー', 'error'); console.error(err); });
      return;
    }

    var handyMemo = document.getElementById('handy-order-memo');
    var memoVal = handyMemo ? handyMemo.value.trim() : '';

    var body = {
      store_id: _storeId,
      items: items,
      order_type: _mode === 'takeout' ? 'takeout' : 'handy',
      idempotency_key: createHandyIdempotencyKey()
    };
    if (memoVal) {
      body.memo = memoVal;
    }

    if (_mode === 'dinein') {
      body.table_id = els.tableSel.value;
      if (!body.table_id) { toast('テーブルを選択してください', 'error'); return; }
    } else {
      body.customer_name = els.toName.value.trim();
      body.customer_phone = els.toPhone.value.trim();
      body.pickup_at = els.toPickup.value ? toMySQLDatetime(els.toPickup.value) : '';
      if (!body.pickup_at) { toast('受取時間を入力してください', 'error'); return; }
    }

    if (_isOfflineOrStale()) {
      savePendingOrder(body, 'オフラインまたは古いデータ表示中');
      toast('送信できないため未送信に保存しました', 'error');
      clearCart();
      if (handyMemo) handyMemo.value = '';
      renderCart();
      renderMenuItems();
      return;
    }

    apiPost('/store/handy-order.php', body)
      .then(function (json) {
        if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
        toast('注文を送信しました (' + Utils.formatYen(json.data.total) + ')', 'success');
        clearCart();
        if (handyMemo) handyMemo.value = '';
        renderCart();
        renderMenuItems();
        if (_mode === 'takeout') {
          els.toName.value = '';
          els.toPhone.value = '';
          setDefaultPickupTime();
        }
        loadQuickOrderHints();
        loadCompactTableContext();
      })
      .catch(function (err) {
        savePendingOrder(body, err.message || '通信エラー');
        toast('送信失敗。未送信に保存しました', 'error');
        clearCart();
        if (handyMemo) handyMemo.value = '';
        renderCart();
        renderMenuItems();
        console.error(err);
      });
  }

  // ==== Pending orders (送信失敗・未送信) ====
  function pendingStorageKey() {
    return PENDING_KEY_PREFIX + (_storeId || 'unknown');
  }

  function createHandyIdempotencyKey() {
    var storePart = String(_storeId || 'store').replace(/[^a-zA-Z0-9]/g, '').substring(0, 18);
    return 'handy-' + storePart + '-' + Date.now() + '-' + Math.floor(Math.random() * 1000000);
  }

  function clonePlain(obj) {
    return JSON.parse(JSON.stringify(obj));
  }

  function loadPendingOrders() {
    _pendingOrders = [];
    if (!_storeId || !window.localStorage) return;
    try {
      var raw = localStorage.getItem(pendingStorageKey());
      var list = raw ? JSON.parse(raw) : [];
      _pendingOrders = Array.isArray(list) ? list.filter(function (p) {
        return p && p.id && p.body && p.body.store_id === _storeId;
      }) : [];
    } catch (e) {
      _pendingOrders = [];
    }
  }

  function savePendingOrders() {
    if (!_storeId || !window.localStorage) return;
    try {
      localStorage.setItem(pendingStorageKey(), JSON.stringify(_pendingOrders.slice(0, 30)));
    } catch (e) {
      toast('未送信の保存に失敗しました', 'error');
    }
    renderPendingBadge();
  }

  function renderPendingBadge() {
    if (!els.pendingBtn || !els.pendingCount) return;
    var count = _pendingOrders.length;
    els.pendingCount.textContent = count;
    els.pendingBtn.style.display = count > 0 ? '' : 'none';
  }

  function savePendingOrder(body, reason) {
    loadPendingOrders();
    _pendingOrders.unshift({
      id: 'pending-' + Date.now() + '-' + Math.floor(Math.random() * 100000),
      body: clonePlain(body),
      reason: reason || '送信失敗',
      retryCount: 0,
      createdAt: new Date().toISOString()
    });
    savePendingOrders();
  }

  function getTableCodeById(tableId) {
    for (var i = 0; i < _lastTableData.length; i++) {
      if (String(_lastTableData[i].id) === String(tableId)) return _lastTableData[i].tableCode || _lastTableData[i].table_code || tableId;
    }
    for (var j = 0; j < _tables.length; j++) {
      if (String(_tables[j].id) === String(tableId)) return _tables[j].table_code || tableId;
    }
    return tableId || '-';
  }

  function formatPendingTitle(body) {
    if (body.order_type === 'takeout') {
      return 'TO ' + (body.customer_name || 'テイクアウト');
    }
    return getTableCodeById(body.table_id);
  }

  function formatPendingTime(iso) {
    if (!iso) return '-';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '-';
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }

  function renderPendingOrdersList() {
    if (_pendingOrders.length === 0) {
      els.optBody.innerHTML = '<div class="empty-state" style="padding:1rem">未送信注文はありません</div>';
      return;
    }
    var html = '<div class="pending-order-list">';
    for (var i = 0; i < _pendingOrders.length; i++) {
      var p = _pendingOrders[i];
      var body = p.body || {};
      var items = body.items || [];
      var total = 0;
      var summary = [];
      for (var j = 0; j < items.length; j++) {
        total += (parseInt(items[j].price, 10) || 0) * (parseInt(items[j].qty, 10) || 1);
        summary.push((items[j].name || '品目') + ' ×' + (items[j].qty || 1));
      }
      var createdLabel = formatPendingTime(p.createdAt);
      var locked = _pendingRetryLocks[p.id] === true;
      html += '<div class="pending-order-card">';
      html += '<div class="pending-order-card__top"><strong>' + Utils.escapeHtml(formatPendingTitle(body)) + '</strong><span>' + createdLabel + '</span></div>';
      html += '<div class="pending-order-card__items">' + Utils.escapeHtml(summary.join('、')) + '</div>';
      html += '<div class="pending-order-card__meta">' + Utils.formatYen(total) + ' / ' + Utils.escapeHtml(p.reason || '送信失敗') + ' / 再送' + (p.retryCount || 0) + '回</div>';
      html += '<div class="pending-order-card__actions">';
      html += '<button type="button" data-pending-retry="' + Utils.escapeHtml(p.id) + '"' + (locked ? ' disabled' : '') + '>' + (locked ? '送信中' : '再送信') + '</button>';
      html += '<button type="button" data-pending-delete="' + Utils.escapeHtml(p.id) + '"' + (locked ? ' disabled' : '') + '>破棄</button>';
      html += '</div></div>';
    }
    html += '</div>';
    els.optBody.innerHTML = html;
  }

  function openPendingOrdersModal() {
    loadPendingOrders();
    els.optTitle.textContent = '未送信注文';
    els.optPrice.textContent = '';
    els.optAdd.style.display = 'none';
    renderPendingOrdersList();
    els.optBody.onclick = function (e) {
      var retryBtn = e.target.closest('[data-pending-retry]');
      if (retryBtn) {
        retryPendingOrder(retryBtn.getAttribute('data-pending-retry'));
        return;
      }
      var deleteBtn = e.target.closest('[data-pending-delete]');
      if (deleteBtn) {
        deletePendingOrder(deleteBtn.getAttribute('data-pending-delete'));
      }
    };
    els.optOverlay.classList.add('show');
  }

  function retryPendingOrder(pendingId) {
    if (_guardOfflineOrStale()) return;
    var idx = -1;
    for (var i = 0; i < _pendingOrders.length; i++) {
      if (_pendingOrders[i].id === pendingId) { idx = i; break; }
    }
    if (idx === -1) return;
    var pending = _pendingOrders[idx];
    if (_pendingRetryLocks[pendingId]) return;
    _pendingRetryLocks[pendingId] = true;
    renderPendingOrdersList();
    apiPost('/store/handy-order.php', pending.body)
      .then(function (json) {
        _pendingRetryLocks[pendingId] = false;
        if (!json.ok) {
          pending.reason = apiErrorMsg(json);
          pending.retryCount = (pending.retryCount || 0) + 1;
          savePendingOrders();
          renderPendingOrdersList();
          toast('再送信できませんでした', 'error');
          return;
        }
        _pendingOrders.splice(idx, 1);
        savePendingOrders();
        renderPendingOrdersList();
        toast('未送信注文を送信しました', 'success');
        loadQuickOrderHints();
        loadCompactTableContext();
      })
      .catch(function (err) {
        _pendingRetryLocks[pendingId] = false;
        pending.reason = err.message || '通信エラー';
        pending.retryCount = (pending.retryCount || 0) + 1;
        savePendingOrders();
        renderPendingOrdersList();
        toast('再送信できませんでした', 'error');
      });
  }

  function deletePendingOrder(pendingId) {
    if (!confirm('この未送信注文を破棄しますか？')) return;
    _pendingOrders = _pendingOrders.filter(function (p) { return p.id !== pendingId; });
    savePendingOrders();
    renderPendingOrdersList();
  }

  // ==== Order history ====
  var _historyOrders = [];

  function loadHistory() {
    els.orderList.innerHTML = '<div class="loading">読み込み中...</div>';

    apiFetch('/kds/orders.php?store_id=' + _storeId + '&view=accounting')
      .then(function (json) {
        if (!json.ok) {
          els.orderList.innerHTML = '<div class="empty-state">読み込みに失敗しました</div>';
          return;
        }
        _historyOrders = json.data.orders || [];
        renderHistory();
      })
      .catch(function () {
        els.orderList.innerHTML = '<div class="empty-state">通信エラー</div>';
      });
  }

  function renderHistory() {
    var orders = _historyOrders;
    if (orders.length === 0) {
      els.orderList.innerHTML = '<div class="history-header">'
        + '<span class="history-header__title">本日の注文</span>'
        + '<button class="btn-refresh" id="btn-refresh-history">更新</button>'
        + '</div><div class="empty-state">注文がありません</div>';
      return;
    }

    var sorted = orders.slice().sort(function (a, b) {
      return new Date(b.created_at) - new Date(a.created_at);
    });

    var statusLabels = {
      pending: '受付', preparing: '調理中', ready: '完成',
      served: '提供済', cancelled: '取消'
    };

    var html = '<div class="history-header">'
      + '<span class="history-header__title">本日の注文 (' + orders.length + '件)</span>'
      + '<button class="btn-refresh" id="btn-refresh-history">更新</button>'
      + '</div>';

    sorted.forEach(function (o) {
      var isTakeout = o.order_type === 'takeout';
      var label = '';
      if (isTakeout) {
        label = '<span class="badge-to">TO</span>' + Utils.escapeHtml(o.customer_name || 'テイクアウト');
      } else if (o.order_type === 'handy') {
        label = '<span class="badge-handy">H</span>' + Utils.escapeHtml(o.table_code || '-');
      } else {
        label = Utils.escapeHtml(o.table_code || '-');
      }

      var items = o.items || [];
      var itemsSummary = items.map(function (it) {
        var optStr = '';
        if (it.options && it.options.length > 0) {
          optStr = '(' + it.options.map(function (op) { return op.choiceName || op.name || ''; }).join(',') + ')';
        }
        return Utils.escapeHtml(it.name) + optStr + ' ×' + it.qty;
      }).join('、');
      if (itemsSummary.length > 80) itemsSummary = itemsSummary.substring(0, 80) + '…';

      var canEdit = (['paid', 'cancelled'].indexOf(o.status) === -1);
      var statusClass = 'status--' + o.status;
      var itemRowsHtml = '<div class="order-card__item-list">';
      for (var ii = 0; ii < items.length; ii++) {
        var item = items[ii];
        var itemStatus = item.status || 'pending';
        var itemId = Utils.escapeHtml(item.item_id || '');
        var qty = parseInt(item.qty, 10) || 1;
        var itemCanAdjust = canEdit && item.item_id && itemStatus !== 'cancelled';
        var optLine = '';
        if (item.options && item.options.length > 0) {
          optLine = '<span class="order-card__item-options">' + Utils.escapeHtml(item.options.map(function (op) { return op.choiceName || op.name || ''; }).join(', ')) + '</span>';
        }
        itemRowsHtml += '<div class="order-card__item order-card__item--' + itemStatus + '">';
        itemRowsHtml += '<div class="order-card__item-main"><strong>' + Utils.escapeHtml(item.name || '品目') + '</strong>' + optLine + '<span>' + qty + '点 / ' + Utils.escapeHtml(statusLabels[itemStatus] || itemStatus) + '</span></div>';
        if (itemCanAdjust) {
          itemRowsHtml += '<div class="order-card__item-actions">';
          itemRowsHtml += '<button type="button" data-order-id="' + Utils.escapeHtml(o.id) + '" data-item-id="' + itemId + '" data-current-qty="' + qty + '" data-item-qty-delta="-1"' + (qty <= 1 ? ' disabled' : '') + '>数量-</button>';
          itemRowsHtml += '<button type="button" data-order-id="' + Utils.escapeHtml(o.id) + '" data-item-id="' + itemId + '" data-current-qty="' + qty + '" data-item-qty-delta="1">数量+</button>';
          itemRowsHtml += '<button type="button" data-order-id="' + Utils.escapeHtml(o.id) + '" data-item-cancel="' + itemId + '">品目取消</button>';
          if (itemStatus === 'served') {
            itemRowsHtml += '<button type="button" data-item-undo-served="' + itemId + '">配膳取消</button>';
          }
          itemRowsHtml += '</div>';
        }
        itemRowsHtml += '</div>';
      }
      itemRowsHtml += '</div>';

      var memoLine = '';
      if (o.memo) {
        memoLine = '<div class="order-card__memo" style="font-size:0.8rem;color:#ffd700;padding:2px 0">' + Utils.escapeHtml(o.memo) + '</div>';
      }

      html += '<div class="order-card' + (isTakeout ? ' order-card--takeout' : '') + '" data-order-id="' + o.id + '">'
        + '<div class="order-card__header">'
        + '<span class="order-card__label">' + label + '</span>'
        + '<div class="order-card__meta">'
        + '<span class="order-card__time">' + Utils.formatDateTime(o.created_at) + '</span>'
        + '<span class="order-card__status ' + statusClass + '">' + (statusLabels[o.status] || o.status) + '</span>'
        + '</div></div>'
        + memoLine
        + '<div class="order-card__items">' + itemsSummary + '</div>'
        + itemRowsHtml
        + '<div class="order-card__footer">'
        + '<span class="order-card__total">' + Utils.formatYen(o.total_amount) + '</span>'
        + (canEdit ? '<button class="btn-edit" data-order-id="' + o.id + '">修正</button>' : '')
        + (canEdit ? '<button class="btn-order-cancel" data-order-id="' + o.id + '">注文取消</button>' : '')
        + '</div></div>';
    });

    els.orderList.innerHTML = html;
  }

  function findHistoryOrder(orderId) {
    for (var i = 0; i < _historyOrders.length; i++) {
      if (_historyOrders[i].id === orderId) return _historyOrders[i];
    }
    return null;
  }

  function cancelWholeOrder(orderId) {
    if (!orderId) return;
    if (_guardOfflineOrStale()) return;
    var reason = prompt('注文取消の理由を入力してください', '');
    if (!reason || !reason.trim()) return;
    apiPatch('/kds/update-status.php', {
      order_id: orderId,
      store_id: _storeId,
      status: 'cancelled',
      reason: reason.trim()
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast('注文を取消しました', 'success');
      loadHistory();
      loadQuickOrderHints();
      loadCompactTableContext();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function cancelOrderItem(orderId, itemId) {
    if (!orderId || !itemId) return;
    if (_guardOfflineOrStale()) return;
    var reason = prompt('品目取消の理由を入力してください', '');
    if (!reason || !reason.trim()) return;
    apiPatch('/store/order-item-adjust.php', {
      store_id: _storeId,
      order_id: orderId,
      item_id: itemId,
      qty: 0,
      reason: reason.trim()
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast('品目を取消しました', 'success');
      loadHistory();
      loadQuickOrderHints();
      loadCompactTableContext();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function adjustOrderItemQty(orderId, itemId, currentQty, delta) {
    if (!orderId || !itemId || !delta) return;
    if (_guardOfflineOrStale()) return;
    var nextQty = currentQty + delta;
    if (nextQty < 1) return;
    var order = findHistoryOrder(orderId);
    var reason = '';
    if (order && order.status !== 'pending') {
      reason = prompt('数量変更の理由を入力してください', '');
      if (reason === null) return;
    }
    apiPatch('/store/order-item-adjust.php', {
      store_id: _storeId,
      order_id: orderId,
      item_id: itemId,
      qty: nextQty,
      reason: reason ? reason.trim() : null
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast('数量を変更しました', 'success');
      loadHistory();
      loadQuickOrderHints();
      loadCompactTableContext();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function undoServedItem(itemId) {
    if (!itemId) return;
    if (_guardOfflineOrStale()) return;
    if (!confirm('配膳完了を取り消して、提供待ちに戻しますか？')) return;
    apiPatch('/kds/update-item-status.php', {
      store_id: _storeId,
      item_id: itemId,
      status: 'ready',
      reason: '配膳完了の取り消し'
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast('提供待ちに戻しました', 'success');
      if (_currentTab === 'history') loadHistory();
      if (_currentTab === 'ops') loadOperationsBoard();
      loadCompactTableContext();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  // ==== Edit mode ====
  function startEdit(orderId) {
    var order = _historyOrders.find(function (o) { return o.id === orderId; });
    if (!order) { toast('注文が見つかりません', 'error'); return; }

    if (['paid', 'cancelled'].indexOf(order.status) !== -1) {
      toast('この注文は修正できません', 'error');
      return;
    }

    _editingOrder = order;

    _cart = (order.items || []).map(function (item) {
      var opts = item.options || [];
      var choiceIds = opts.map(function (o) { return o.choiceId || ''; }).sort().join(',');
      return {
        id: item.id,
        cartKey: item.id + (choiceIds ? '__' + choiceIds : ''),
        name: item.name,
        basePrice: item.price,
        price: item.price,
        qty: item.qty,
        options: opts
      };
    });

    var tab = (order.order_type === 'takeout') ? 'takeout' : 'dinein';
    switchTab(tab);

    if (tab === 'dinein' && order.table_id) {
      els.tableSel.value = order.table_id;
    } else if (tab === 'takeout') {
      els.toName.value = order.customer_name || '';
      els.toPhone.value = order.customer_phone || '';
      if (order.pickup_at) {
        els.toPickup.value = toDatetimeLocal(order.pickup_at);
      }
    }

    updateEditBanner();
    renderCart();
    renderMenuItems();

    if (!_cartOpen) toggleCartDetail();
  }

  function cancelEdit() {
    clearCart();
    updateEditBanner();
    renderCart();
    renderMenuItems();
  }

  function updateEditBanner() {
    if (_editingOrder) {
      els.editBanner.classList.add('show');
      var label = _editingOrder.table_code || _editingOrder.customer_name || '';
      els.editBanner.querySelector('span').textContent = '修正中: ' + label;
    } else {
      els.editBanner.classList.remove('show');
    }
  }

  // ==== Helpers ====
  function setDefaultPickupTime() {
    var d = new Date();
    d.setMinutes(d.getMinutes() + 30);
    els.toPickup.value = toDatetimeLocalValue(d);
  }

  function toDatetimeLocalValue(d) {
    var y = d.getFullYear();
    var m = ('0' + (d.getMonth() + 1)).slice(-2);
    var day = ('0' + d.getDate()).slice(-2);
    var h = ('0' + d.getHours()).slice(-2);
    var min = ('0' + d.getMinutes()).slice(-2);
    return y + '-' + m + '-' + day + 'T' + h + ':' + min;
  }

  function toMySQLDatetime(dtLocalVal) {
    return dtLocalVal.replace('T', ' ') + ':00';
  }

  function toDatetimeLocal(mysqlDt) {
    if (!mysqlDt) return '';
    return mysqlDt.substring(0, 16).replace(' ', 'T');
  }

  // ==== Table status ====
  var _timeLimitPlans = [];
  var _courseTemplates = [];

  var TABLE_STATUS = {
    empty:          { color: '#43a047', label: '空き' },
    qr_open:        { color: '#00acc1', label: 'QR開放中' },
    seated:         { color: '#1e88e5', label: '着席' },
    no_order:       { color: '#ff9800', label: '未注文' },
    eating:         { color: '#1565c0', label: '食事中' },
    bill_requested: { color: '#e53935', label: '会計待ち' },
    overtime:       { color: '#e65100', label: '時間超過' },
    cleaning:       { color: '#6d4c41', label: '清掃待ち' }
  };

  function loadTableStatus() {
    if (!_storeId) return;
    var today = ymdLocal(new Date());
    // L-9: テーブル状態 + プラン + コース + 当日予約 を並行取得
    Promise.all([
      apiFetch('/store/tables-status.php?store_id=' + _storeId),
      apiFetch('/store/time-limit-plans.php?store_id=' + _storeId).catch(function () { return { ok: true, data: { plans: [] } }; }),
      apiFetch('/store/course-templates.php?store_id=' + _storeId).catch(function () { return { ok: true, data: { courses: [] } }; }),
      apiFetch('/store/reservations.php?store_id=' + _storeId + '&date=' + today).catch(function () { return { ok: true, data: { reservations: [] } }; })
    ]).then(function (results) {
      // L-9: 当日予約をテーブルバッジ用にキャッシュ
      _todayReservationsByTable = {};
      var rsvData = results[3] && results[3].data && results[3].data.reservations ? results[3].data.reservations : [];
      var nowTs = Date.now();
      rsvData.forEach(function (r) {
        if (r.status === 'cancelled' || r.status === 'no_show' || r.status === 'completed') return;
        var rTs = new Date(r.reserved_at.replace(' ', 'T')).getTime();
        if (rTs < nowTs - 3 * 3600 * 1000) return; // 3h 以上前は表示しない
        (r.assigned_table_ids || []).forEach(function (tid) {
          if (!_todayReservationsByTable[tid]) _todayReservationsByTable[tid] = [];
          _todayReservationsByTable[tid].push(r);
        });
      });
      Object.keys(_todayReservationsByTable).forEach(function (tid) {
        _todayReservationsByTable[tid].sort(function (a, b) { return a.reserved_at.localeCompare(b.reserved_at); });
      });
      var tablesJson = results[0];
      var plansJson = results[1];
      var coursesJson = results[2];
      if (!tablesJson.ok) { renderTableStatusError(apiErrorMsg(tablesJson)); return; }
      _timeLimitPlans = (plansJson.ok && plansJson.data.plans) ? plansJson.data.plans.filter(function (p) { return parseInt(p.is_active); }) : [];
      _courseTemplates = (coursesJson.ok && coursesJson.data.courses) ? coursesJson.data.courses.filter(function (c) { return c.isActive; }) : [];
      renderTableStatus(tablesJson.data.tables || []);
    }).catch(function (err) {
      renderTableStatusError(err.message || '通信エラー');
    });
  }

  function renderTableStatusError(msg) {
    els.tblInfo.textContent = '';
    els.tblGrid.innerHTML = '<div class="empty-state">' + Utils.escapeHtml(msg) + '</div>';
  }

  var _lastTableData = [];

  function renderTableStatus(tables) {
    _lastTableData = tables;
    var total = tables.length;
    var empty = tables.filter(function (t) { return !t.session; }).length;
    els.tblInfo.textContent = '全 ' + total + '卓 / 空き ' + empty + '卓';

    if (total === 0) {
      els.tblGrid.innerHTML = '<div class="empty-state">テーブルが登録されていません</div>';
      return;
    }

    var html = '';
    tables.forEach(function (t) {
      var st = getTableDisplayStatus(t);
      var s = TABLE_STATUS[st];

      html += '<div class="ts-card" style="border-left-color:' + s.color + '">';
      html += '<div class="ts-card__top">';
      html += '<span class="ts-card__code">' + Utils.escapeHtml(t.tableCode) + '</span>';
      html += '<span class="ts-card__badge" style="background:' + s.color + '">' + s.label + '</span>';
      html += '</div>';

      if (t.session) {
        if (t.session.guestCount) html += '<div class="ts-card__detail">' + t.session.guestCount + '名</div>';
        html += '<div class="ts-card__detail">' + t.session.elapsedMin + '分経過</div>';
        if (t.session.timeLimitMin) {
          var remain = t.session.timeLimitMin - t.session.elapsedMin;
          if (remain > 0) {
            html += '<div class="ts-card__detail ts-card__detail--muted">残り' + remain + '分</div>';
          } else {
            html += '<div class="ts-card__detail ts-card__detail--warn">' + Math.abs(remain) + '分超過</div>';
          }
        }
      } else if (t.qrOpen) {
        html += '<div class="ts-card__detail">QR読込待ち</div>';
        html += '<div class="ts-card__detail ts-card__detail--muted">残り' + Math.ceil((t.qrOpen.remainingSec || 0) / 60) + '分</div>';
        if (t.qrOpen.openedByName) {
          html += '<div class="ts-card__detail ts-card__detail--muted">開放: ' + Utils.escapeHtml(t.qrOpen.openedByName) + '</div>';
        }
      }
      if (t.session && t.session.status === 'bill_requested') {
        html += '<div class="ts-card__detail ts-card__detail--warn">セルフ追加注文停止中</div>';
      }
      // コースありの場合
      if (t.session && t.session.courseName && t.session.coursePrice) {
        var courseTotal = t.session.coursePrice * (t.session.guestCount || 1);
        html += '<div class="ts-card__plan" style="color:#2e7d32">' + Utils.escapeHtml(t.session.courseName) + '</div>';
        html += '<div class="ts-card__amount">¥' + courseTotal.toLocaleString() + '</div>';
        if (t.session.currentPhaseNumber) {
          html += '<div class="ts-card__detail ts-card__detail--muted">フェーズ ' + t.session.currentPhaseNumber + '</div>';
        }
      }
      // プランありの場合はプラン料金を表示、注文は件数のみ
      else if (t.session && t.session.planName && t.session.planPrice) {
        var planTotal = t.session.planPrice * (t.session.guestCount || 1);
        html += '<div class="ts-card__plan">' + Utils.escapeHtml(t.session.planName) + '</div>';
        html += '<div class="ts-card__amount">¥' + planTotal.toLocaleString() + '</div>';
        if (t.orders && t.orders.orderCount > 0) {
          html += '<div class="ts-card__detail ts-card__detail--muted">' + t.orders.orderCount + '件注文済</div>';
        }
      } else if (t.orders && t.orders.orderCount > 0) {
        // 通常テーブル: 注文合計を表示
        html += '<div class="ts-card__detail">' + t.orders.orderCount + '件の注文</div>';
        html += '<div class="ts-card__detail ts-card__detail--muted">' + getOrderSourceLabel(t.orders) + '</div>';
        html += '<div class="ts-card__amount">¥' + t.orders.totalAmount.toLocaleString() + '</div>';
      }

      // O-1: 品目進捗バー
      if (t.itemStatus) {
        var is = t.itemStatus;
        var totalQty = is.pendingQty + is.preparingQty + is.readyQty + is.servedQty;
        if (totalQty > 0) {
          var sPct = Math.round(is.servedQty / totalQty * 100);
          var rPct = Math.round(is.readyQty / totalQty * 100);
          var prPct = Math.round(is.preparingQty / totalQty * 100);
          var pPct = Math.round(is.pendingQty / totalQty * 100);
          html += '<div class="ts-progress">';
          if (sPct > 0) html += '<div class="ts-progress__seg ts-progress__seg--served" style="width:' + sPct + '%"></div>';
          if (rPct > 0) html += '<div class="ts-progress__seg ts-progress__seg--ready" style="width:' + rPct + '%"></div>';
          if (prPct > 0) html += '<div class="ts-progress__seg ts-progress__seg--preparing" style="width:' + prPct + '%"></div>';
          if (pPct > 0) html += '<div class="ts-progress__seg ts-progress__seg--pending" style="width:' + pPct + '%"></div>';
          html += '</div>';
        }
      }

      // O-4: メモ表示
      if (t.session && t.session.memo) {
        html += '<div class="ts-card__memo">' + Utils.escapeHtml(t.session.memo) + '</div>';
      }

      // L-9: 当日の予約バッジ表示 (テーブルに紐付く未着席の予約)
      var rsvForTable = _todayReservationsByTable[t.id] || [];
      var nextRsv = null;
      if (rsvForTable.length) {
        nextRsv = rsvForTable.find(function (r) { return r.status === 'confirmed' || r.status === 'pending'; });
      }
      // 着席中はバッジ表示せず (現在のセッションが優先)
      if (!t.session && nextRsv) {
        var when = nextRsv.reserved_at.substring(11, 16);
        html += '<div class="ts-card__reservation">';
        html += '<strong>' + when + '</strong> ' + Utils.escapeHtml(nextRsv.customer_name) + ' (' + nextRsv.party_size + '名)';
        if (nextRsv.tags && nextRsv.tags.indexOf('VIP') !== -1) html += ' VIP';
        if (nextRsv.memo) html += '<div>' + Utils.escapeHtml(nextRsv.memo) + '</div>';
        html += '</div>';
      }

      var attention = buildAttentionChips(t, nextRsv);
      if (attention.length > 0) {
        html += '<div class="table-attention-strip table-attention-strip--card">';
        for (var ac = 0; ac < attention.length; ac++) {
          html += '<span class="table-attention-chip table-attention-chip--' + attention[ac].level + '">' + Utils.escapeHtml(attention[ac].text) + '</span>';
        }
        html += '</div>';
      }

      // S6: PIN表示
      if (t.session && t.session.sessionPin) {
        html += '<div style="margin-top:0.25rem;font-size:0.8rem;color:#ff6f00;font-weight:700">PIN: ' + Utils.escapeHtml(t.session.sessionPin) + '</div>';
      }

      if (!t.session && (!t.orders || t.orders.orderCount === 0)) {
        html += '<div class="ts-card__detail ts-card__detail--muted">' + t.capacity + '席</div>';
      }

      html += renderTableActionButtons(t, nextRsv, false);

      if (t.session && t.session.status !== 'cleaning') {
        html += '<div class="table-secondary-actions">';
        html += '<button class="ts-card__cancel-btn" data-session-id="' + t.session.id + '" data-table-code="' + Utils.escapeHtml(t.tableCode) + '">着席キャンセル</button>';
        html += '</div>';
        // Batch-X: plan / course なし + プラン登録あり → 「プラン開始」ボタン
        if (!t.session.planName && !t.session.courseName && _timeLimitPlans && _timeLimitPlans.length > 0) {
          html += '<button class="ts-card__start-plan-btn" data-session-id="' + t.session.id + '" data-table-code="' + Utils.escapeHtml(t.tableCode) + '" style="margin-top:0.4rem;width:100%;font-size:0.85rem;padding:0.5rem;border:1px solid #ff6f00;border-radius:6px;background:#fff;color:#ff6f00;cursor:pointer;font-weight:600">🍽 プラン開始</button>';
        }
      }

      html += '</div>';
    });

    els.tblGrid.innerHTML = html;
  }

  function getTableDisplayStatus(t) {
    // セッションがあればその状態を優先
    if (t.session) {
      var s = t.session.status;
      if (s === 'cleaning') return 'cleaning';
      if (s === 'bill_requested') return 'bill_requested';
      if (t.session.timeLimitMin && t.session.elapsedMin > t.session.timeLimitMin) return 'overtime';
      // O-1: 着席済みだが注文ゼロ → 未注文
      if (s === 'seated' && (!t.orders || t.orders.orderCount === 0)) return 'no_order';
      if (s === 'eating') return 'eating';
      if (s === 'seated') return 'seated';
      return 'eating';
    }
    if (t.qrOpen) return 'qr_open';
    // セッションなし → 未会計注文があれば食事中
    if (t.orders && t.orders.orderCount > 0) return 'eating';
    return 'empty';
  }

  function getOrderSourceLabel(orders) {
    if (!orders) return '注文なし';
    var selfCount = parseInt(orders.selfOrderCount, 10) || 0;
    var handyCount = parseInt(orders.handyOrderCount, 10) || 0;
    if (selfCount > 0 && handyCount > 0) return 'セルフ+ハンディ';
    if (selfCount > 0) return 'セルフ注文';
    if (handyCount > 0) return 'ハンディ注文';
    return '注文あり';
  }

  function loadCompactTableContext() {
    if (!_storeId) return;
    var today = ymdLocal(new Date());
    Promise.all([
      apiFetch('/store/tables-status.php?store_id=' + encodeURIComponent(_storeId)),
      apiFetch('/store/reservations.php?store_id=' + encodeURIComponent(_storeId) + '&date=' + today).catch(function () { return { ok: true, data: { reservations: [] } }; })
    ]).then(function (results) {
      if (results[0] && results[0].ok) {
        _lastTableData = results[0].data.tables || [];
      }
      if (results[1] && results[1].ok) {
        cacheReservationsByTable(results[1].data.reservations || []);
      }
      renderSelectedTablePanel();
      renderQuickOrderPanel();
    }).catch(function () {
      // 注文入力は止めない
    });
  }

  function cacheReservationsByTable(reservations) {
    _todayReservationsByTable = {};
    var nowTs = Date.now();
    for (var i = 0; i < reservations.length; i++) {
      var r = reservations[i];
      if (r.status === 'cancelled' || r.status === 'no_show' || r.status === 'completed') continue;
      var rTs = new Date(String(r.reserved_at || '').replace(' ', 'T')).getTime();
      if (rTs && rTs < nowTs - 3 * 3600 * 1000) continue;
      var tids = r.assigned_table_ids || [];
      for (var j = 0; j < tids.length; j++) {
        if (!_todayReservationsByTable[tids[j]]) _todayReservationsByTable[tids[j]] = [];
        _todayReservationsByTable[tids[j]].push(r);
      }
    }
    Object.keys(_todayReservationsByTable).forEach(function (tid) {
      _todayReservationsByTable[tid].sort(function (a, b) { return String(a.reserved_at).localeCompare(String(b.reserved_at)); });
    });
  }

  function findTableStatus(tableId) {
    for (var i = 0; i < _lastTableData.length; i++) {
      if (String(_lastTableData[i].id) === String(tableId)) return _lastTableData[i];
    }
    for (var j = 0; j < _tables.length; j++) {
      if (String(_tables[j].id) === String(tableId)) {
        return {
          id: _tables[j].id,
          tableCode: _tables[j].table_code,
          capacity: parseInt(_tables[j].capacity, 10) || 0,
          session: null,
          orders: null,
          itemStatus: null
        };
      }
    }
    return null;
  }

  function getNextReservationForTable(tableId) {
    var list = _todayReservationsByTable[tableId] || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i].status === 'confirmed' || list[i].status === 'pending' || list[i].status === 'seated') return list[i];
    }
    return null;
  }

  function renderSelectedTablePanel() {
    if (!els.selectedTablePanel) return;
    var tableId = els.tableSel ? els.tableSel.value : '';
    if (!tableId || _mode !== 'dinein') {
      els.selectedTablePanel.style.display = 'none';
      els.selectedTablePanel.innerHTML = '';
      return;
    }

    var t = findTableStatus(tableId);
    if (!t) {
      els.selectedTablePanel.style.display = 'none';
      return;
    }

    var st = getTableDisplayStatus(t);
    var status = TABLE_STATUS[st] || TABLE_STATUS.empty;
    var session = t.session || null;
    var rsv = getNextReservationForTable(tableId);
    var html = '';

    html += '<div class="table-action-panel__head">';
    html += '<div><strong>' + Utils.escapeHtml(t.tableCode || '-') + '</strong>';
    html += '<span class="table-action-panel__status" style="background:' + status.color + '">' + status.label + '</span></div>';
    html += '<div class="table-action-panel__sub">';
    if (session && session.guestCount) html += session.guestCount + '名 / ';
    if (session && session.elapsedMin !== undefined) html += session.elapsedMin + '分経過 / ';
    if (t.qrOpen && !session) html += 'QR読込待ち 残り' + Math.ceil((t.qrOpen.remainingSec || 0) / 60) + '分 / ';
    if (t.orders && t.orders.orderCount) html += t.orders.orderCount + '件 ' + Utils.formatYen(t.orders.totalAmount || 0) + ' / ' + getOrderSourceLabel(t.orders);
    else html += '注文なし';
    html += '</div></div>';

    if (session && session.status === 'bill_requested') {
      html += '<div class="table-action-panel__notice">セルフ追加注文は停止中です。追加注文は会計解除後に受けてください。</div>';
    } else if (t.qrOpen && !session && t.qrOpen.openedByName) {
      html += '<div class="table-action-panel__notice">QR開放: ' + Utils.escapeHtml(t.qrOpen.openedByName) + '</div>';
    }

    var attention = buildAttentionChips(t, rsv);
    if (attention.length > 0) {
      html += '<div class="table-attention-strip">';
      for (var i = 0; i < attention.length; i++) {
        html += '<span class="table-attention-chip table-attention-chip--' + attention[i].level + '">' + Utils.escapeHtml(attention[i].text) + '</span>';
      }
      html += '</div>';
    }

    html += renderTableActionButtons(t, rsv, true);
    els.selectedTablePanel.innerHTML = html;
    els.selectedTablePanel.style.display = '';
  }

  function buildAttentionChips(t, rsv) {
    var chips = [];
    function add(text, level) {
      for (var i = 0; i < chips.length; i++) {
        if (chips[i].text === text) return;
      }
      chips.push({ text: text, level: level || 'info' });
    }
    var memo = '';
    if (t && t.session && t.session.memo) memo += ' ' + t.session.memo;
    if (rsv) {
      if (rsv.memo) memo += ' ' + rsv.memo;
      if (rsv.customer_internal_memo) memo += ' ' + rsv.customer_internal_memo;
      if (rsv.customer_preferences) memo += ' ' + rsv.customer_preferences;
      if (rsv.customer_tags) memo += ' ' + rsv.customer_tags;
      if (rsv.tags) memo += ' ' + rsv.tags;
      if (rsv.customer_allergies) add('アレルギー: ' + rsv.customer_allergies, 'danger');
      if (parseInt(rsv.customer_is_vip, 10) === 1) add('VIP', 'vip');
      if (parseInt(rsv.customer_is_blacklisted, 10) === 1) add('要注意客', 'danger');
      if (rsv.customer_visit_count && parseInt(rsv.customer_visit_count, 10) >= 5) add('再来店 ' + rsv.customer_visit_count + '回', 'info');
    }
    if (/アレル|卵|乳|小麦|そば|落花生|えび|かに|甲殻|ナッツ/.test(memo)) add('アレルギー注意', 'danger');
    if (/VIP|常連|上客/.test(memo)) add('VIP', 'vip');
    if (/子連れ|お子|ベビ|乳児|幼児/.test(memo)) add('子連れ', 'warn');
    if (/誕生日|記念日|お祝い|バースデ/.test(memo)) add('誕生日/記念日', 'info');
    if (memo.trim()) add('メモあり', 'memo');
    return chips.slice(0, 6);
  }

  function renderTableActionButtons(t, rsv, compact) {
    var primary = getTablePrimaryAction(t, rsv);
    var secondary = getTableSecondaryActions(t, rsv, primary, compact);
    var html = '<div class="' + (compact ? 'table-action-buttons table-action-buttons--compact table-action-buttons--one-tap' : 'table-action-buttons table-action-buttons--one-tap') + '">';

    if (primary) {
      html += renderTableActionButton(t, primary, 'table-action-button--primary');
    }
    if (secondary.length > 0) {
      html += '<div class="table-action-buttons__secondary">';
      for (var i = 0; i < secondary.length; i++) {
        html += renderTableActionButton(t, secondary[i], 'table-action-button--secondary');
      }
      html += '</div>';
    }
    html += '</div>';
    return html;
  }

  function getTablePrimaryAction(t, rsv) {
    var session = t.session || null;
    var st = getTableDisplayStatus(t);
    if (session) {
      if (session.status === 'cleaning') return { action: 'clean-done', label: '清掃完了' };
      if (session.status === 'bill_requested') return { action: 'open-register', label: 'レジへ' };
      if (st === 'overtime') return { action: 'bill', label: '会計待ち' };
      return { action: 'order', label: st === 'no_order' ? '注文確認' : '注文' };
    }
    if (t.qrOpen) return { action: 'qr-reissue', label: 'QR再発行' };
    if (rsv && (rsv.status === 'confirmed' || rsv.status === 'pending')) return { action: 'qr-open', label: 'QR開放' };
    return { action: 'qr-open', label: 'QR開放' };
  }

  function getTableSecondaryActions(t, rsv, primary, compact) {
    var session = t.session || null;
    var actions = [];
    function add(action, label, extra) {
      if (primary && primary.action === action) return;
      extra = extra || {};
      extra.action = action;
      extra.label = label;
      actions.push(extra);
    }
    if (session) {
      if (session.status === 'bill_requested') {
        add('bill-undo', '食事中へ戻す');
      } else if (session.status !== 'cleaning') {
        add('bill', '会計待ち');
      }
      if (!compact && session.status !== 'cleaning') {
        add('qr', 'QR表示');
        add('memo', 'メモ');
        add('guests', '人数');
        add('move', '席移動');
      }
    } else if (t.qrOpen) {
      add('qr-cancel', 'QR取消');
    } else {
      if (rsv && (rsv.status === 'confirmed' || rsv.status === 'pending')) {
        add('reservation-seat', '予約着席', { rsvId: rsv.id });
      } else {
        add('seat', '着席');
      }
    }
    return actions;
  }

  function renderTableActionButton(t, action, className) {
    var session = t.session || null;
    var attrs = ' data-table-action="' + Utils.escapeHtml(action.action) + '"'
      + ' data-table-id="' + Utils.escapeHtml(t.id || '') + '"'
      + ' data-table-code="' + Utils.escapeHtml(t.tableCode || '') + '"';
    if (session && session.id) attrs += ' data-session-id="' + Utils.escapeHtml(session.id) + '"';
    if (action.rsvId) attrs += ' data-rsv-id="' + Utils.escapeHtml(action.rsvId) + '"';
    if (action.action === 'guests') attrs += ' data-guest-count="' + (session && session.guestCount ? session.guestCount : 2) + '"';
    return '<button type="button" class="' + className + '" ' + attrs + '>' + Utils.escapeHtml(action.label) + '</button>';
  }

  function handleTableAction(btn) {
    var action = btn.getAttribute('data-table-action');
    var tableId = btn.getAttribute('data-table-id') || '';
    var tableCode = btn.getAttribute('data-table-code') || '';
    var sessionId = btn.getAttribute('data-session-id') || '';
    if (action === 'order') {
      if (tableId) {
        switchTab('dinein');
        els.tableSel.value = tableId;
        renderSelectedTablePanel();
        renderQuickOrderPanel();
      }
      try { els.menuGrid.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
      return;
    }
    if (action === 'seat') {
      openSeatModal(tableId, tableCode);
      return;
    }
    if (action === 'reservation-seat') {
      seatReservationFromHandy(btn.getAttribute('data-rsv-id'));
      return;
    }
    if (action === 'qr-open') {
      openTableForCustomer(tableId, tableCode);
      return;
    }
    if (action === 'qr-cancel') {
      cancelTableQr(tableId, tableCode);
      return;
    }
    if (action === 'qr-reissue') {
      reissueTableQr(tableId, tableCode);
      return;
    }
    if (action === 'qr') {
      if (!sessionId) { openTableForCustomer(tableId, tableCode); return; }
      _openSubQrModal(sessionId, tableId, tableCode);
      return;
    }
    if (action === 'memo') {
      var currentMemo = '';
      var t = findTableStatus(tableId);
      if (t && t.session) currentMemo = t.session.memo || '';
      openMemoModal(sessionId, tableCode, currentMemo);
      return;
    }
    if (action === 'guests') {
      openGuestCountModal(sessionId, tableCode, parseInt(btn.getAttribute('data-guest-count'), 10) || 1);
      return;
    }
    if (action === 'bill') {
      requestBillForSession(sessionId, tableCode);
      return;
    }
    if (action === 'bill-undo') {
      undoBillForSession(sessionId, tableCode);
      return;
    }
    if (action === 'open-register') {
      openRegisterForTable(tableId);
      return;
    }
    if (action === 'move') {
      openTableMoveModal(sessionId, tableId, tableCode);
      return;
    }
    if (action === 'clean-done') {
      completeCleaningSession(sessionId, tableCode);
    }
  }

  function refreshTableSurfaces() {
    loadCompactTableContext();
    if (_currentTab === 'tables') loadTableStatus();
    if (_currentTab === 'ops') loadOperationsBoard();
  }

  function cancelTableQr(tableId, tableCode) {
    if (!tableId) return;
    if (_guardOfflineOrStale()) return;
    if (!confirm(tableCode + ' のQR開放を取り消しますか？')) return;
    apiFetch('/store/table-open.php?store_id=' + encodeURIComponent(_storeId) + '&table_id=' + encodeURIComponent(tableId), {
      method: 'DELETE'
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast('QR開放を取り消しました', 'success');
      refreshTableSurfaces();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function reissueTableQr(tableId, tableCode) {
    if (!tableId) return;
    if (_guardOfflineOrStale()) return;
    apiFetch('/store/table-open.php?store_id=' + encodeURIComponent(_storeId) + '&table_id=' + encodeURIComponent(tableId), {
      method: 'DELETE'
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      return apiPost('/store/table-open.php', { store_id: _storeId, table_id: tableId, expires_minutes: 5 });
    }).then(function (json2) {
      if (!json2) return;
      if (!json2.ok) { toast(apiErrorMsg(json2), 'error'); return; }
      toast(tableCode + ' のQRを再発行しました', 'success');
      refreshTableSurfaces();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function completeCleaningSession(sessionId, tableCode) {
    if (!sessionId) { toast('対象セッションがありません', 'error'); return; }
    if (_guardOfflineOrStale()) return;
    apiPatch('/store/table-sessions.php?id=' + encodeURIComponent(sessionId), {
      store_id: _storeId,
      status: 'closed'
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast(tableCode + ' を空席に戻しました', 'success');
      refreshTableSurfaces();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function requestBillForSession(sessionId, tableCode) {
    if (!sessionId) { toast('着席中の卓ではありません', 'error'); return; }
    if (_guardOfflineOrStale()) return;
    apiPatch('/store/table-sessions.php?id=' + encodeURIComponent(sessionId), {
      store_id: _storeId,
      status: 'bill_requested'
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast(tableCode + ' を会計待ちにしました', 'success');
      refreshTableSurfaces();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function openRegisterForTable(tableId) {
    if (tableId) {
      try { localStorage.setItem('mt_handy_register_focus_table', tableId); } catch (e) {}
    }
    window.location.href = 'pos-register.html';
  }

  function undoBillForSession(sessionId, tableCode) {
    if (!sessionId) { toast('対象セッションがありません', 'error'); return; }
    if (_guardOfflineOrStale()) return;
    if (!confirm(tableCode + ' を食事中に戻しますか？')) return;
    apiPatch('/store/table-sessions.php?id=' + encodeURIComponent(sessionId), {
      store_id: _storeId,
      status: 'eating'
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast(tableCode + ' を食事中に戻しました', 'success');
      refreshTableSurfaces();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function openTableMoveModal(sessionId, fromTableId, fromTableCode) {
    if (!sessionId) { toast('対象セッションがありません', 'error'); return; }
    var options = '';
    for (var i = 0; i < _lastTableData.length; i++) {
      var t = _lastTableData[i];
      if (String(t.id) === String(fromTableId)) continue;
      if (t.session || t.qrOpen) continue;
      options += '<option value="' + Utils.escapeHtml(t.id) + '">' + Utils.escapeHtml(t.tableCode || '-') + ' (' + (t.capacity || '-') + '席)</option>';
    }
    if (!options) {
      toast('移動先にできる空席がありません', 'error');
      return;
    }
    els.optTitle.textContent = fromTableCode + ' 席移動';
    els.optBody.innerHTML =
      '<div class="opt-group">'
      + '<div class="opt-group__title">移動先</div>'
      + '<select id="table-move-target" class="ctx-select" style="width:100%">' + options + '</select>'
      + '</div>';
    els.optPrice.textContent = '';
    els.optAdd.textContent = '移動する';
    els.optAdd.disabled = false;
    els.optAdd.style.display = '';
    els.optOverlay.classList.add('show');

    var newAdd = els.optAdd.cloneNode(true);
    els.optAdd.parentNode.replaceChild(newAdd, els.optAdd);
    els.optAdd = newAdd;
    els.optAdd.addEventListener('click', function () {
      if (_guardOfflineOrStale()) return;
      var sel = document.getElementById('table-move-target');
      var toTableId = sel ? sel.value : '';
      if (!toTableId) return;
      els.optAdd.disabled = true;
      els.optAdd.textContent = '移動中...';
      apiPost('/store/table-session-move.php', {
        store_id: _storeId,
        session_id: sessionId,
        to_table_id: toTableId
      }).then(function (json) {
        if (!json.ok) {
          toast(apiErrorMsg(json), 'error');
          els.optAdd.disabled = false;
          els.optAdd.textContent = '移動する';
          return;
        }
        closeOptionModal();
        toast((json.data.from_table_code || fromTableCode) + ' から ' + (json.data.to_table_code || '') + ' へ移動しました', 'success');
        refreshTableSurfaces();
        loadHistory();
      }).catch(function (err) {
        toast(err.message || '通信エラー', 'error');
        els.optAdd.disabled = false;
        els.optAdd.textContent = '移動する';
      });
    });
  }

  function openGuestCountModal(sessionId, tableCode, currentCount) {
    if (!sessionId) { toast('着席中の卓ではありません', 'error'); return; }
    els.optTitle.textContent = tableCode + ' 人数変更';
    els.optBody.innerHTML =
      '<div class="opt-group">'
      + '<div class="opt-group__title">人数</div>'
      + '<div class="guest-stepper">'
      + '<button type="button" id="guest-minus">−</button>'
      + '<input type="number" id="guest-count-edit" class="ctx-input" min="1" max="99" value="' + currentCount + '">'
      + '<button type="button" id="guest-plus">+</button>'
      + '</div>'
      + '</div>';
    els.optPrice.textContent = '';
    els.optAdd.textContent = '人数を保存';
    els.optAdd.disabled = false;
    els.optAdd.style.display = '';
    els.optOverlay.classList.add('show');

    setTimeout(function () {
      var input = document.getElementById('guest-count-edit');
      var minus = document.getElementById('guest-minus');
      var plus = document.getElementById('guest-plus');
      if (minus) minus.addEventListener('click', function () {
        var v = Math.max(1, (parseInt(input.value, 10) || 1) - 1);
        input.value = v;
      });
      if (plus) plus.addEventListener('click', function () {
        var v2 = Math.min(99, (parseInt(input.value, 10) || 1) + 1);
        input.value = v2;
      });
    }, 0);

    var newAdd = els.optAdd.cloneNode(true);
    els.optAdd.parentNode.replaceChild(newAdd, els.optAdd);
    els.optAdd = newAdd;
    els.optAdd.addEventListener('click', function () {
      if (_guardOfflineOrStale()) return;
      var input = document.getElementById('guest-count-edit');
      var guestCount = Math.max(1, parseInt(input.value, 10) || 1);
      els.optAdd.disabled = true;
      els.optAdd.textContent = '保存中...';
      apiPatch('/store/table-sessions.php?id=' + encodeURIComponent(sessionId), {
        store_id: _storeId,
        guest_count: guestCount
      }).then(function (json) {
        if (!json.ok) {
          toast(apiErrorMsg(json), 'error');
          els.optAdd.disabled = false;
          els.optAdd.textContent = '人数を保存';
          return;
        }
        closeOptionModal();
        toast(tableCode + ' を' + guestCount + '名に変更しました', 'success');
        loadCompactTableContext();
        if (_currentTab === 'tables') loadTableStatus();
      }).catch(function (err) {
        toast(err.message || '通信エラー', 'error');
        els.optAdd.disabled = false;
        els.optAdd.textContent = '人数を保存';
      });
    });
  }

  // ==== Operations board (壁掛け・運用確認) ====
  function loadOperationsBoard() {
    if (!_storeId || !els.panelOps) return;
    loadPendingOrders();
    Promise.all([
      apiFetch('/store/tables-status.php?store_id=' + encodeURIComponent(_storeId)).catch(function () { return { ok: false, data: { tables: [] } }; }),
      apiFetch('/kds/call-alerts.php?store_id=' + encodeURIComponent(_storeId)).catch(function () { return { ok: true, data: { alerts: [] } }; }),
      apiFetch('/store/handy-ops-log.php?store_id=' + encodeURIComponent(_storeId)).catch(function () { return { ok: true, data: { logs: [] } }; })
    ]).then(function (results) {
      if (results[0] && results[0].ok) {
        _lastTableData = results[0].data.tables || [];
      }
      _opsAlerts = (results[1] && results[1].ok && results[1].data.alerts) ? results[1].data.alerts : [];
      _opsLogs = (results[2] && results[2].ok && results[2].data.logs) ? results[2].data.logs : [];
      renderOperationsBoard();
    }).catch(function () {
      renderOperationsBoardError();
    });
  }

  function renderOperationsBoardError() {
    if (els.opsTasks) {
      els.opsTasks.innerHTML = '<div class="ops-empty ops-empty--warn">運用情報を更新できませんでした</div>';
    }
  }

  function getOpsBuckets() {
    var buckets = {
      qrOpen: [],
      bill: [],
      overtime: [],
      noOrder: [],
      cleaning: [],
      occupied: 0,
      empty: 0
    };
    for (var i = 0; i < _lastTableData.length; i++) {
      var t = _lastTableData[i];
      var st = getTableDisplayStatus(t);
      if (!t.session) buckets.empty++;
      else buckets.occupied++;
      if (st === 'qr_open') buckets.qrOpen.push(t);
      if (st === 'bill_requested') buckets.bill.push(t);
      if (st === 'overtime') buckets.overtime.push(t);
      if (st === 'no_order') buckets.noOrder.push(t);
      if (st === 'cleaning') buckets.cleaning.push(t);
    }
    return buckets;
  }

  function countAlertsByType(type) {
    var count = 0;
    for (var i = 0; i < _opsAlerts.length; i++) {
      if ((_opsAlerts[i].type || 'staff_call') === type) count++;
    }
    return count;
  }

  function renderOperationsBoard() {
    if (!els.opsSummary || !els.opsTasks || !els.opsAlerts || !els.opsTableGrid) return;
    var buckets = getOpsBuckets();
    var readyCount = countAlertsByType('product_ready');
    var kitchenCount = countAlertsByType('kitchen_call');
    var callCount = _opsAlerts.length - readyCount - kitchenCount;

    els.opsSummary.innerHTML =
      renderOpsSummaryCard('未送信', _pendingOrders.length, 'pending', 'data-ops-pending="1"') +
      renderOpsSummaryCard('QR開放中', buckets.qrOpen.length, 'qr-open', '') +
      renderOpsSummaryCard('料理完成', readyCount, 'ready', '') +
      renderOpsSummaryCard('呼び出し', callCount + kitchenCount, 'call', '') +
      renderOpsSummaryCard('会計待ち', buckets.bill.length, 'bill', '') +
      renderOpsSummaryCard('清掃待ち', buckets.cleaning.length, 'cleaning', '') +
      renderOpsSummaryCard('時間超過', buckets.overtime.length, 'overtime', '') +
      renderOpsSummaryCard('未注文', buckets.noOrder.length, 'no-order', '');

    renderOperationAlerts();
    renderOperationTasks(buckets);
    renderOperationLog();
    renderOperationTableGrid();
    processOpsNotifications(buckets);
  }

  function renderOpsSummaryCard(label, count, level, attrs) {
    var dangerClass = count > 0 ? ' ops-summary-card--' + level : '';
    return '<button type="button" class="ops-summary-card' + dangerClass + '" ' + attrs + '>'
      + '<span>' + Utils.escapeHtml(label) + '</span>'
      + '<strong>' + count + '</strong>'
      + '</button>';
  }

  function formatElapsed(seconds) {
    seconds = parseInt(seconds, 10) || 0;
    if (seconds < 60) return seconds + '秒前';
    if (seconds < 3600) return Math.floor(seconds / 60) + '分前';
    return Math.floor(seconds / 3600) + '時間前';
  }

  function isOpsAlertEscalated(alert) {
    var type = alert.type || 'staff_call';
    var elapsed = parseInt(alert.elapsed_seconds, 10) || 0;
    if (type === 'product_ready') return elapsed >= 300;
    return elapsed >= 180;
  }

  function renderOperationAlerts() {
    if (_opsAlerts.length === 0) {
      els.opsAlerts.innerHTML = '<div class="ops-empty">KDS通知はありません</div>';
      return;
    }
    var html = '<div class="ops-board__section-title">KDS通知</div>';
    for (var i = 0; i < _opsAlerts.length; i++) {
      var a = _opsAlerts[i];
      var type = a.type || 'staff_call';
      var label = type === 'product_ready' ? '料理完成' : (type === 'kitchen_call' ? '厨房呼出' : '客席呼出');
      var cardClass = type === 'product_ready' ? ' ops-alert-card--ready' : (type === 'kitchen_call' ? ' ops-alert-card--kitchen' : '');
      var text = type === 'product_ready'
        ? (a.item_name || a.reason || '商品完成')
        : (a.reason || '呼び出し');
      var ackLabel = type === 'product_ready' ? '配膳完了' : '対応済み';
      var isProgress = a.status === 'in_progress';
      var escalated = isOpsAlertEscalated(a);
      html += '<div class="ops-alert-card' + cardClass + (isProgress ? ' ops-alert-card--progress' : '') + (escalated ? ' ops-alert-card--escalated' : '') + '">';
      html += '<div><span class="ops-alert-card__type">' + label + '</span>';
      html += '<strong>' + Utils.escapeHtml(a.table_code || '-') + '</strong>';
      html += '<p>' + Utils.escapeHtml(text) + '</p>';
      html += '<small>' + formatElapsed(a.elapsed_seconds);
      if (escalated) html += ' / 要確認';
      if (isProgress) html += ' / 対応中';
      html += '</small></div>';
      html += '<div class="ops-alert-card__actions">';
      html += '<button type="button" data-ops-ack-alert="' + Utils.escapeHtml(a.id) + '">' + ackLabel + '</button>';
      html += '</div>';
      html += '</div>';
    }
    els.opsAlerts.innerHTML = html;
  }

  function renderOperationTasks(buckets) {
    var html = '<div class="ops-board__section-title">次にやること</div>';
    var count = 0;

    if (_pendingOrders.length > 0) {
      html += renderOperationTask('未送信注文を再送してください', _pendingOrders.length + '件', 'pending', 'data-ops-pending="1"');
      count++;
    }
    html += renderTableTaskList(buckets.qrOpen, 'QR開放後の読込待ち', 'qr-open');
    html += renderTableTaskList(buckets.bill, '会計待ちの卓をレジへ案内', 'bill');
    html += renderTableTaskList(buckets.cleaning, '会計済みの卓を清掃', 'cleaning');
    html += renderTableTaskList(buckets.overtime, '時間超過の卓を確認', 'overtime');
    html += renderTableTaskList(buckets.noOrder, '着席後まだ注文がない卓', 'no-order');
    count += buckets.qrOpen.length + buckets.bill.length + buckets.cleaning.length + buckets.overtime.length + buckets.noOrder.length;

    if (count === 0 && _opsAlerts.length === 0) {
      html += '<div class="ops-empty ops-empty--ok">今すぐ対応が必要な項目はありません</div>';
    }
    els.opsTasks.innerHTML = html;
  }

  function renderTableTaskList(list, title, level) {
    var html = '';
    for (var i = 0; i < list.length; i++) {
      var t = list[i];
      var detail = '';
      if (t.qrOpen) detail += '残り' + Math.ceil((t.qrOpen.remainingSec || 0) / 60) + '分';
      if (t.session && t.session.elapsedMin !== undefined) detail += t.session.elapsedMin + '分経過';
      var escalated = isTableTaskEscalated(t, level);
      if (escalated) detail += (detail ? ' / ' : '') + '要確認';
      if (t.orders && t.orders.orderCount) {
        detail += (detail ? ' / ' : '') + t.orders.orderCount + '件 ' + Utils.formatYen(t.orders.totalAmount || 0);
      }
      html += renderOperationWorkItem(title, (t.tableCode || '-') + (detail ? ' / ' + detail : ''), level, t, escalated);
    }
    return html;
  }

  function isTableTaskEscalated(t, level) {
    if (level === 'bill') {
      return t.session && t.session.statusChangedAt && (parseInt(t.session.statusElapsedSec, 10) || 0) >= 300;
    }
    if (level === 'cleaning') {
      return t.session && t.session.statusChangedAt && (parseInt(t.session.statusElapsedSec, 10) || 0) >= 600;
    }
    return false;
  }

  function renderOperationTask(title, detail, level, attrs, escalated) {
    return '<button type="button" class="ops-task ops-task--' + level + (escalated ? ' ops-task--escalated' : '') + '" ' + attrs + '>'
      + '<span>' + Utils.escapeHtml(title) + '</span>'
      + '<strong>' + Utils.escapeHtml(detail) + '</strong>'
      + '</button>';
  }

  function renderOperationWorkItem(title, detail, level, t, escalated) {
    var primary = getTablePrimaryAction(t, null);
    var secondary = getTableSecondaryActions(t, null, primary, true);
    var html = '<div class="ops-task ops-task--' + level + (escalated ? ' ops-task--escalated' : '') + '">';
    html += '<div class="ops-task__body" data-ops-open-table="' + Utils.escapeHtml(t.id || '') + '">';
    html += '<span>' + Utils.escapeHtml(title) + '</span>';
    html += '<strong>' + Utils.escapeHtml(detail) + '</strong>';
    html += '</div>';
    html += '<div class="ops-task__actions">';
    if (primary) html += renderTableActionButton(t, primary, 'ops-task__primary');
    for (var i = 0; i < secondary.length && i < 1; i++) {
      html += renderTableActionButton(t, secondary[i], 'ops-task__secondary');
    }
    html += '</div></div>';
    return html;
  }

  function renderOperationLog() {
    if (!els.opsLog) return;
    if (!_opsLogs || _opsLogs.length === 0) {
      els.opsLog.innerHTML = '';
      return;
    }
    var html = '<div class="ops-board__section-title">端末ログ</div>';
    html += '<div class="ops-log-list">';
    var max = _terminalMode === 'wall' ? 6 : 10;
    for (var i = 0; i < _opsLogs.length && i < max; i++) {
      var log = _opsLogs[i];
      html += '<div class="ops-log-row">';
      html += '<div><strong>' + Utils.escapeHtml(log.title || log.action) + '</strong>';
      html += '<span>' + Utils.escapeHtml(log.detail || '') + '</span></div>';
      html += '<small>端末: ' + Utils.escapeHtml(log.username || '-') + ' / ' + formatElapsed(log.elapsed_seconds) + '</small>';
      if (log.can_undo_served && log.order_item_id) {
        html += '<button type="button" data-ops-undo-served="' + Utils.escapeHtml(log.order_item_id) + '">配膳取消</button>';
      }
      html += '</div>';
    }
    html += '</div>';
    els.opsLog.innerHTML = html;
  }

  function renderOperationTableGrid() {
    if (_lastTableData.length === 0) {
      els.opsTableGrid.innerHTML = '<div class="ops-empty">テーブルが登録されていません</div>';
      return;
    }
    var html = '';
    for (var i = 0; i < _lastTableData.length; i++) {
      var t = _lastTableData[i];
      var st = getTableDisplayStatus(t);
      var s = TABLE_STATUS[st] || TABLE_STATUS.empty;
      var escalated = isTableTaskEscalated(t, st === 'bill_requested' ? 'bill' : st);
      html += '<div class="ops-table-card ops-table-card--' + st + (escalated ? ' ops-table-card--escalated' : '') + '" style="border-left-color:' + s.color + '">';
      html += '<div class="ops-table-card__top"><strong>' + Utils.escapeHtml(t.tableCode || '-') + '</strong>';
      html += '<span style="background:' + s.color + '">' + Utils.escapeHtml(s.label) + '</span></div>';
      if (t.session) {
        html += '<div class="ops-table-card__meta">';
        if (t.session.guestCount) html += t.session.guestCount + '名 / ';
        if (t.session.elapsedMin !== undefined) html += t.session.elapsedMin + '分';
        if (t.session.status === 'bill_requested' && t.session.statusElapsedSec) html += ' / 会計待ち' + formatElapsed(t.session.statusElapsedSec);
        if (t.orders && t.orders.orderCount) html += ' / ' + t.orders.orderCount + '件';
        html += '</div>';
      } else if (t.qrOpen) {
        html += '<div class="ops-table-card__meta">QR読込待ち / 残り' + Math.ceil((t.qrOpen.remainingSec || 0) / 60) + '分</div>';
        if (t.qrOpen.openedByName) html += '<div class="ops-table-card__meta">開放: ' + Utils.escapeHtml(t.qrOpen.openedByName) + '</div>';
      } else {
        html += '<div class="ops-table-card__meta">空き / ' + (t.capacity || '-') + '席</div>';
      }
      html += renderTableActionButtons(t, null, true);
      html += '</div>';
    }
    els.opsTableGrid.innerHTML = html;
  }

  function processOpsNotifications(buckets) {
    var targets;
    var now;
    var intervalMs;
    var active = {};
    var shouldPlay = false;
    var urgent = false;
    var i;
    var t;
    var st;
    if (!_opsSoundEnabled) return;
    targets = buildOpsNotificationTargets(buckets);
    now = Date.now();
    intervalMs = Math.max(60, _opsRealertIntervalSec) * 1000;
    for (i = 0; i < targets.length; i++) {
      t = targets[i];
      active[t.key] = true;
      st = _opsNoticeState[t.key] || { lastAt: 0, urgentNotified: false };
      if (!st.lastAt || (t.urgent && !st.urgentNotified) || (_opsRealertEnabled && now - st.lastAt >= intervalMs)) {
        shouldPlay = true;
        if (t.urgent) urgent = true;
        st.lastAt = now;
        st.urgentNotified = !!t.urgent;
      }
      _opsNoticeState[t.key] = st;
    }
    Object.keys(_opsNoticeState).forEach(function (key) {
      if (!active[key]) delete _opsNoticeState[key];
    });
    if (shouldPlay) playOpsTone(urgent);
  }

  function buildOpsNotificationTargets(buckets) {
    var targets = [];
    var i;
    var a;
    var type;
    var elapsed;
    var escalated;
    var list;
    var t;
    for (i = 0; i < _opsAlerts.length; i++) {
      a = _opsAlerts[i];
      type = a.type || 'staff_call';
      elapsed = parseInt(a.elapsed_seconds, 10) || 0;
      escalated = isOpsAlertEscalated(a);
      targets.push({
        key: 'alert:' + a.id,
        urgent: escalated,
        type: type,
        elapsed: elapsed
      });
    }

    list = buckets.bill || [];
    for (i = 0; i < list.length; i++) {
      t = list[i];
      if (t.session && (parseInt(t.session.statusElapsedSec, 10) || 0) >= 300) {
        targets.push({ key: 'bill:' + t.session.id, urgent: true, type: 'bill' });
      }
    }

    list = buckets.cleaning || [];
    for (i = 0; i < list.length; i++) {
      t = list[i];
      if (t.session && (parseInt(t.session.statusElapsedSec, 10) || 0) >= 600) {
        targets.push({ key: 'cleaning:' + t.session.id, urgent: true, type: 'cleaning' });
      }
    }

    list = buckets.overtime || [];
    for (i = 0; i < list.length; i++) {
      t = list[i];
      if (t.session && t.session.id) {
        targets.push({ key: 'overtime:' + t.session.id, urgent: true, type: 'overtime' });
      }
    }
    return targets;
  }

  function unlockOpsAudio() {
    var ctx = getOpsAudioContext();
    if (ctx && ctx.state === 'suspended' && ctx.resume) {
      try { ctx.resume(); } catch (e) {}
    }
  }

  function getOpsAudioContext() {
    if (_opsAudioCtx) return _opsAudioCtx;
    if (!window.AudioContext && !window.webkitAudioContext) return null;
    try {
      _opsAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
    } catch (e) {
      _opsAudioCtx = null;
    }
    return _opsAudioCtx;
  }

  function playOpsTone(urgent) {
    var ctx = getOpsAudioContext();
    var now;
    if (!ctx) return;
    if (ctx.state === 'suspended' && ctx.resume) {
      try { ctx.resume(); } catch (e) {}
    }
    if (ctx.state === 'suspended') return;
    now = ctx.currentTime || 0;
    playToneSegment(ctx, urgent ? 880 : 660, now, 0.12, 0.07);
    playToneSegment(ctx, urgent ? 1175 : 880, now + 0.16, urgent ? 0.16 : 0.11, urgent ? 0.09 : 0.06);
    if (urgent) {
      playToneSegment(ctx, 988, now + 0.38, 0.18, 0.08);
    }
  }

  function playToneSegment(ctx, frequency, startAt, duration, volume) {
    var osc;
    var gain;
    try {
      osc = ctx.createOscillator();
      gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = frequency;
      gain.gain.setValueAtTime(0.001, startAt);
      gain.gain.exponentialRampToValueAtTime(volume, startAt + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, startAt + duration);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(startAt);
      osc.stop(startAt + duration + 0.02);
    } catch (e) {}
  }

  function startOperationAlert(alertId) {
    if (!alertId) return;
    if (_guardOfflineOrStale()) return;
    apiPatch('/kds/call-alerts.php', {
      alert_id: alertId,
      status: 'in_progress'
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast('対応中にしました', 'success');
      if (typeof RevisionGate !== 'undefined') {
        RevisionGate.invalidate('call_alerts', _storeId);
      }
      loadOperationsBoard();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  function acknowledgeOperationAlert(alertId) {
    if (!alertId) return;
    if (_guardOfflineOrStale()) return;
    apiPatch('/kds/call-alerts.php', {
      alert_id: alertId,
      status: 'acknowledged'
    }).then(function (json) {
      if (!json.ok) { toast(apiErrorMsg(json), 'error'); return; }
      toast('対応済みにしました', 'success');
      if (typeof RevisionGate !== 'undefined') {
        RevisionGate.invalidate('call_alerts', _storeId);
      }
      loadOperationsBoard();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  // ==== Seat modal (着席) ====
  // ===== L-9: 予約タブ =====
  var _rsvDate = null;
  var _rsvData = null;
  var _rsvBoundOnce = false;
  var _todayReservationsByTable = {}; // テーブルバッジ用キャッシュ
  var _rsvAvailabilityCache = {};
  var _rsvAvailabilityTimer = null;
  var _rsvAvailabilitySelected = null;

  function ymdLocal(d) {
    // S3-#17: ES5 互換 (padStart は古い WebView 非対応)
    function _p2(n) { return ('0' + n).slice(-2); }
    return d.getFullYear() + '-' + _p2(d.getMonth() + 1) + '-' + _p2(d.getDate());
  }

  function initReservationTab() {
    if (!_rsvDate) _rsvDate = ymdLocal(new Date());
    var dateInput = document.getElementById('rsv-date');
    if (dateInput) dateInput.value = _rsvDate;
    renderReservationFormDefaults();
    setReservationCreateOpen(false);
    if (!_rsvBoundOnce) {
      document.getElementById('rsv-prev-day').addEventListener('click', function () {
        var d = new Date(_rsvDate); d.setDate(d.getDate() - 1); _rsvDate = ymdLocal(d); document.getElementById('rsv-date').value = _rsvDate; renderReservationFormDefaults(); loadReservations();
      });
      document.getElementById('rsv-next-day').addEventListener('click', function () {
        var d = new Date(_rsvDate); d.setDate(d.getDate() + 1); _rsvDate = ymdLocal(d); document.getElementById('rsv-date').value = _rsvDate; renderReservationFormDefaults(); loadReservations();
      });
      document.getElementById('rsv-today').addEventListener('click', function () {
        _rsvDate = ymdLocal(new Date()); document.getElementById('rsv-date').value = _rsvDate; renderReservationFormDefaults(); loadReservations();
      });
      document.getElementById('rsv-date').addEventListener('change', function (e) {
        _rsvDate = e.target.value; renderReservationFormDefaults(); loadReservations();
      });
      if (els.rsvCheckTime) {
        els.rsvCheckTime.addEventListener('change', function () {
          syncReservationCheckToForm();
          scheduleReservationAvailability();
        });
      }
      if (els.rsvCheckParty) {
        els.rsvCheckParty.addEventListener('input', function () {
          syncReservationCheckToForm();
          scheduleReservationAvailability();
        });
        els.rsvCheckParty.addEventListener('change', function () {
          syncReservationCheckToForm();
          scheduleReservationAvailability();
        });
      }
      if (els.rsvToggleCreate) {
        els.rsvToggleCreate.addEventListener('click', function () {
          setReservationCreateOpen(!els.rsvCreatePanel || els.rsvCreatePanel.style.display === 'none');
        });
      }
      if (els.rsvNewTime) {
        els.rsvNewTime.addEventListener('change', function () {
          syncReservationFormToCheck();
          scheduleReservationAvailability();
        });
      }
      if (els.rsvNewParty) {
        els.rsvNewParty.addEventListener('input', function () {
          syncReservationFormToCheck();
          scheduleReservationAvailability();
        });
        els.rsvNewParty.addEventListener('change', function () {
          syncReservationFormToCheck();
          scheduleReservationAvailability();
        });
      }
      if (els.rsvCreateBtn) {
        els.rsvCreateBtn.addEventListener('click', createReservationFromHandy);
      }
      // 予約カードクリック (delegation)
      document.getElementById('rsv-list').addEventListener('click', function (e) {
        var openBtn = e.target.closest('[data-rsv-open-table]');
        if (openBtn) { openTableForCustomer(openBtn.getAttribute('data-rsv-open-table'), openBtn.getAttribute('data-rsv-table-code')); return; }
        var seatBtn = e.target.closest('[data-rsv-seat]');
        if (seatBtn) { seatReservationFromHandy(seatBtn.getAttribute('data-rsv-seat')); return; }
        var noShowBtn = e.target.closest('[data-rsv-noshow]');
        if (noShowBtn) { markReservationNoShow(noShowBtn.getAttribute('data-rsv-noshow')); return; }
      });
      _rsvBoundOnce = true;
    }
    loadReservations();
    scheduleReservationAvailability();
  }

  function _suggestReservationTime() {
    var now = new Date();
    var min = now.getMinutes();
    var rounded = min <= 30 ? 30 : 60;
    if (rounded === 60) {
      now.setHours(now.getHours() + 1);
      now.setMinutes(0);
    } else {
      now.setMinutes(30);
    }
    return ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
  }

  function renderReservationFormDefaults() {
    if (els.rsvCheckParty && !els.rsvCheckParty.value) els.rsvCheckParty.value = '2';
    if (els.rsvCheckTime && !els.rsvCheckTime.value) els.rsvCheckTime.value = _suggestReservationTime();
    if (els.rsvNewParty && !els.rsvNewParty.value) els.rsvNewParty.value = els.rsvCheckParty ? els.rsvCheckParty.value : '2';
    if (els.rsvNewTable) els.rsvNewTable.value = '';
    if (els.rsvNewTime && !els.rsvNewTime.value) {
      els.rsvNewTime.value = els.rsvCheckTime ? els.rsvCheckTime.value : _suggestReservationTime();
    }
    syncReservationCheckToForm();
    updateReservationCreateAvailability();
  }

  function setReservationCreateOpen(isOpen) {
    if (els.rsvCreatePanel) {
      els.rsvCreatePanel.style.display = isOpen ? 'block' : 'none';
    }
    if (els.rsvToggleCreate) {
      els.rsvToggleCreate.textContent = isOpen ? '追加フォームを閉じる' : '電話予約を追加';
    }
    if (isOpen) {
      syncReservationCheckToForm();
      updateReservationCreateAvailability();
    }
  }

  function syncReservationCheckToForm() {
    if (els.rsvCheckTime && els.rsvNewTime) els.rsvNewTime.value = els.rsvCheckTime.value || els.rsvNewTime.value;
    if (els.rsvCheckParty && els.rsvNewParty) els.rsvNewParty.value = els.rsvCheckParty.value || els.rsvNewParty.value;
  }

  function syncReservationFormToCheck() {
    if (els.rsvNewTime && els.rsvCheckTime) els.rsvCheckTime.value = els.rsvNewTime.value || els.rsvCheckTime.value;
    if (els.rsvNewParty && els.rsvCheckParty) els.rsvCheckParty.value = els.rsvNewParty.value || els.rsvCheckParty.value;
  }

  function _minutesFromTime(hhmm) {
    var parts = String(hhmm || '').split(':');
    if (parts.length !== 2) return -1;
    return (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
  }

  function _findNearbyAvailableTimes(slots, selectedTime) {
    var targetMin = _minutesFromTime(selectedTime);
    var available = [];
    var out = [];
    var i;
    for (i = 0; i < (slots || []).length; i++) {
      if (slots[i].available) available.push(slots[i]);
    }
    available.sort(function (a, b) {
      return Math.abs(_minutesFromTime(a.time) - targetMin) - Math.abs(_minutesFromTime(b.time) - targetMin);
    });
    for (i = 0; i < available.length && i < 4; i++) out.push(available[i]);
    return out;
  }

  function _formatSuggestedTables(slot) {
    var labels = [];
    var i;
    if (!slot || !slot.suggested_tables) return '';
    for (i = 0; i < slot.suggested_tables.length; i++) {
      labels.push(slot.suggested_tables[i].label || slot.suggested_tables[i].table_code || '?');
    }
    return labels.join(' + ');
  }

  function scheduleReservationAvailability() {
    if (_rsvAvailabilityTimer) clearTimeout(_rsvAvailabilityTimer);
    _rsvAvailabilityTimer = setTimeout(function () {
      loadReservationAvailability();
    }, 120);
  }

  function loadReservationAvailability() {
    var partySize = els.rsvCheckParty ? parseInt(els.rsvCheckParty.value, 10) : 0;
    var cacheKey;
    if (!els.rsvAvailability) return;
    if (!_storeId || !_rsvDate || !partySize || partySize < 1) {
      _rsvAvailabilitySelected = null;
      els.rsvAvailability.className = 'handy-rsv-check__status';
      els.rsvAvailability.textContent = '日付・時間・人数を指定すると空席状況を表示します';
      updateReservationCreateAvailability();
      return;
    }
    cacheKey = _rsvDate + '_' + partySize;
    if (_rsvAvailabilityCache[cacheKey]) {
      renderReservationAvailability(_rsvAvailabilityCache[cacheKey]);
      return;
    }
    els.rsvAvailability.className = 'handy-rsv-check__status';
    els.rsvAvailability.textContent = '空席状況を確認中...';
    apiFetch('/store/reservations.php?action=availability&store_id=' + encodeURIComponent(_storeId) + '&date=' + encodeURIComponent(_rsvDate) + '&party_size=' + partySize)
      .then(function (j) {
        if (!j.ok) {
          _rsvAvailabilitySelected = null;
          els.rsvAvailability.className = 'handy-rsv-check__status handy-rsv-check__status--error';
          els.rsvAvailability.textContent = apiErrorMsg(j);
          updateReservationCreateAvailability();
          return;
        }
        _rsvAvailabilityCache[cacheKey] = j.data;
        renderReservationAvailability(j.data);
      })
      .catch(function (e) {
        _rsvAvailabilitySelected = null;
        els.rsvAvailability.className = 'handy-rsv-check__status handy-rsv-check__status--error';
        els.rsvAvailability.textContent = '空席確認に失敗しました: ' + e.message;
        updateReservationCreateAvailability();
      });
  }

  function renderReservationAvailability(data) {
    var selectedTime = els.rsvCheckTime ? String(els.rsvCheckTime.value || '').trim() : '';
    var slots = (data && data.slots) ? data.slots : [];
    var selected = null;
    var nearby;
    var html = '';
    var i;

    _rsvAvailabilitySelected = null;
    for (i = 0; i < slots.length; i++) {
      if (slots[i].time === selectedTime) {
        selected = slots[i];
        break;
      }
    }

    if (!selectedTime) {
      els.rsvAvailability.className = 'handy-rsv-check__status';
      els.rsvAvailability.textContent = '時間を選ぶと空席状況を表示します';
      updateReservationCreateAvailability();
      return;
    }

    if (selected && selected.available) {
      _rsvAvailabilitySelected = selected;
      els.rsvAvailability.className = 'handy-rsv-check__status handy-rsv-check__status--ok';
      html = '<strong>' + Utils.escapeHtml(selected.time) + ' は予約可能です。</strong>';
      html += ' 残席目安 ' + Utils.escapeHtml(String(selected.remaining_capacity || 0)) + '席';
      if (_formatSuggestedTables(selected)) {
        html += '<div class="handy-rsv-check__times"><span class="handy-rsv-check__chip">候補テーブル: ' + Utils.escapeHtml(_formatSuggestedTables(selected)) + '</span></div>';
      }
      els.rsvAvailability.innerHTML = html;
      updateReservationCreateAvailability();
      return;
    }

    nearby = _findNearbyAvailableTimes(slots, selectedTime);
    if (selected) {
      html = '<strong>' + Utils.escapeHtml(selected.time) + ' は現在満席です。</strong>';
    } else {
      html = '<strong>この時間は予約枠外です。</strong> 営業時間内の30分刻みで確認してください。';
    }
    if (nearby.length) {
      html += '<div class="handy-rsv-check__times">';
      for (i = 0; i < nearby.length; i++) {
        html += '<span class="handy-rsv-check__chip">' + Utils.escapeHtml(nearby[i].time) + '</span>';
      }
      html += '</div>';
    }
    els.rsvAvailability.className = 'handy-rsv-check__status handy-rsv-check__status--warn';
    els.rsvAvailability.innerHTML = html;
    updateReservationCreateAvailability();
  }

  function updateReservationCreateAvailability() {
    if (!els.rsvCreateBtn) return;
    if (els.rsvCreateBtn.getAttribute('data-busy') === '1') return;
    if (_rsvAvailabilitySelected && _rsvAvailabilitySelected.available) {
      els.rsvCreateBtn.disabled = false;
      els.rsvCreateBtn.textContent = 'この日付で予約登録';
      return;
    }
    els.rsvCreateBtn.disabled = true;
    els.rsvCreateBtn.textContent = '空席条件を確認してください';
  }

  function resetReservationForm() {
    if (els.rsvNewName) els.rsvNewName.value = '';
    if (els.rsvNewPhone) els.rsvNewPhone.value = '';
    if (els.rsvNewMemo) els.rsvNewMemo.value = '';
    if (els.rsvCheckParty) els.rsvCheckParty.value = '2';
    if (els.rsvNewParty) els.rsvNewParty.value = '2';
    if (els.rsvNewTable) els.rsvNewTable.value = '';
    if (els.rsvCheckTime) els.rsvCheckTime.value = _suggestReservationTime();
    if (els.rsvNewTime) els.rsvNewTime.value = els.rsvCheckTime ? els.rsvCheckTime.value : _suggestReservationTime();
    scheduleReservationAvailability();
  }

  function createReservationFromHandy() {
    var name = els.rsvNewName ? String(els.rsvNewName.value || '').trim() : '';
    var phone = els.rsvNewPhone ? String(els.rsvNewPhone.value || '').trim() : '';
    var time = els.rsvNewTime ? String(els.rsvNewTime.value || '').trim() : '';
    var memo = els.rsvNewMemo ? String(els.rsvNewMemo.value || '').trim() : '';
    var tableId = els.rsvNewTable ? String(els.rsvNewTable.value || '').trim() : '';
    var partySize = els.rsvNewParty ? parseInt(els.rsvNewParty.value, 10) : 0;
    var reservedAt;
    var body;

    if (_guardOfflineOrStale()) return;
    syncReservationFormToCheck();
    if (!_rsvAvailabilitySelected || !_rsvAvailabilitySelected.available) { toast('先に空席を確認してください', 'error'); return; }
    if (!name) { toast('お客様名を入力してください', 'error'); return; }
    if (!phone) { toast('電話番号を入力してください', 'error'); return; }
    if (!time) { toast('予約時間を入力してください', 'error'); return; }
    if (!partySize || partySize < 1) { toast('人数を入力してください', 'error'); return; }

    reservedAt = _rsvDate + ' ' + time + ':00';
    body = {
      store_id: _storeId,
      customer_name: name,
      customer_phone: phone,
      party_size: partySize,
      reserved_at: reservedAt,
      source: 'phone',
      status: 'confirmed',
      memo: memo || null
    };
    if (tableId) body.assigned_table_ids = [tableId];

    if (els.rsvCreateBtn) {
      els.rsvCreateBtn.setAttribute('data-busy', '1');
      els.rsvCreateBtn.disabled = true;
      els.rsvCreateBtn.textContent = '登録中...';
    }

    apiPost('/store/reservations.php', body)
      .then(function (j) {
        if (!j.ok) {
          toast(apiErrorMsg(j), 'error');
          return;
        }
        toast('電話予約を登録しました', 'success');
        _rsvAvailabilityCache = {};
        resetReservationForm();
        setReservationCreateOpen(false);
        loadReservations();
        loadTableStatus();
      })
      .catch(function (e) {
        toast('通信エラー: ' + e.message, 'error');
      })
      .then(function () {
        if (els.rsvCreateBtn) {
          els.rsvCreateBtn.setAttribute('data-busy', '0');
        }
        updateReservationCreateAvailability();
      });
  }

  function loadReservations() {
    var listEl = document.getElementById('rsv-list');
    listEl.innerHTML = '<div style="text-align:center;padding:24px;color:#888;">読み込み中…</div>';
    apiFetch('/store/reservations.php?store_id=' + encodeURIComponent(_storeId) + '&date=' + encodeURIComponent(_rsvDate))
      .then(function (j) {
        if (!j.ok) { listEl.innerHTML = '<div style="text-align:center;padding:24px;color:#c62828;">' + Utils.escapeHtml(apiErrorMsg(j)) + '</div>'; return; }
        _rsvAvailabilityCache = {};
        _rsvData = j.data;
        renderReservations();
        scheduleReservationAvailability();
        // 当日ぶんはテーブルバッジ用にも保存
        if (_rsvDate === ymdLocal(new Date())) {
          _todayReservationsByTable = {};
          (j.data.reservations || []).forEach(function (r) {
            if (r.status === 'cancelled' || r.status === 'no_show') return;
            (r.assigned_table_ids || []).forEach(function (tid) {
              if (!_todayReservationsByTable[tid]) _todayReservationsByTable[tid] = [];
              _todayReservationsByTable[tid].push(r);
            });
          });
        }
      })
      .catch(function (e) { listEl.innerHTML = '<div style="text-align:center;padding:24px;color:#c62828;">通信エラー: ' + Utils.escapeHtml(e.message) + '</div>'; });
  }

  function renderReservations() {
    var d = _rsvData;
    var sumEl = document.getElementById('rsv-summary');
    if (sumEl && d.summary) {
      sumEl.textContent = d.summary.total + '件 / ' + d.summary.guests + '名 / 着席' + d.summary.seated + ' / no-show' + d.summary.no_show;
    }
    var listEl = document.getElementById('rsv-list');
    if (!d.reservations || !d.reservations.length) {
      listEl.innerHTML = '<div style="text-align:center;padding:32px;color:#888;">この日の予約はありません</div>';
      return;
    }
    var html = '';
    d.reservations.forEach(function (r) {
      var when = r.reserved_at ? r.reserved_at.substring(11, 16) : '--:--';
      var tids = r.assigned_table_ids || [];
      var tableLabels = tids.map(function (tid) {
        var found = (d.tables || []).find(function (t) { return t.id === tid; });
        return found ? found.label : '?';
      }).join('+') || '未割当';
      var statusBadge = '';
      var statusColor = '#1976d2';
      if (r.status === 'confirmed') { statusBadge = '確定'; statusColor = '#1976d2'; }
      else if (r.status === 'pending') { statusBadge = '決済待ち'; statusColor = '#f57c00'; }
      else if (r.status === 'seated') { statusBadge = '着席中'; statusColor = '#2e7d32'; }
      else if (r.status === 'no_show') { statusBadge = 'no-show'; statusColor = '#c62828'; }
      else if (r.status === 'cancelled') { statusBadge = 'キャンセル'; statusColor = '#888'; }
      else if (r.status === 'completed') { statusBadge = '完了'; statusColor = '#5e35b1'; }
      var tagsHtml = '';
      if (r.tags && r.tags.indexOf('VIP') !== -1) tagsHtml += ' <span style="background:#ffd600;color:#000;padding:2px 6px;border-radius:4px;font-size:0.7rem;">★VIP</span>';
      if (r.source === 'walk_in') tagsHtml += ' <span style="background:#fff3e0;color:#e65100;padding:2px 6px;border-radius:4px;font-size:0.7rem;">walk-in</span>';
      if (r.source === 'web') tagsHtml += ' <span style="background:#e3f2fd;color:#1565c0;padding:2px 6px;border-radius:4px;font-size:0.7rem;">web</span>';
      if (r.source === 'ai_chat') tagsHtml += ' <span style="background:#f3e5f5;color:#6a1b9a;padding:2px 6px;border-radius:4px;font-size:0.7rem;">AI</span>';

      html += '<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;margin-bottom:10px;padding:12px;color:#212121;">';
      html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
      html += '<span style="font-size:1.4rem;font-weight:700;color:#212121;">' + when + '</span>';
      html += '<span style="background:' + statusColor + ';color:#fff;padding:2px 8px;border-radius:4px;font-size:0.75rem;">' + statusBadge + '</span>';
      html += tagsHtml;
      html += '</div>';
      html += '<div style="font-size:1.05rem;font-weight:700;margin-bottom:4px;color:#212121;">' + Utils.escapeHtml(r.customer_name) + ' / ' + r.party_size + '名 (' + r.duration_min + '分)</div>';
      html += '<div style="font-size:0.9rem;color:#424242;margin-bottom:4px;">テーブル: <strong style="color:#212121;">' + Utils.escapeHtml(tableLabels) + '</strong></div>';
      if (r.customer_phone) html += '<div style="font-size:0.9rem;margin-bottom:4px;color:#424242;">📞 <a href="tel:' + Utils.escapeHtml(r.customer_phone) + '" style="color:#1565c0;text-decoration:none;font-weight:600;">' + Utils.escapeHtml(r.customer_phone) + '</a></div>';
      if (r.course_name) html += '<div style="font-size:0.9rem;color:#424242;margin-bottom:4px;">🍽 ' + Utils.escapeHtml(r.course_name) + '</div>';
      if (r.memo) html += '<div style="font-size:0.9rem;background:#fff8e1;padding:6px 8px;border-radius:4px;margin:4px 0;color:#424242;">📝 ' + Utils.escapeHtml(r.memo) + '</div>';

      // アクションボタン (ステータス別)
      if (r.status === 'confirmed' || r.status === 'pending') {
        html += '<div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">';
        if (tids.length) {
          html += '<button data-rsv-open-table="' + tids[0] + '" data-rsv-table-code="' + Utils.escapeHtml(tableLabels) + '" style="flex:1;background:#43a047;color:#fff;border:none;padding:8px;border-radius:6px;font-size:0.85rem;">📲 QR開放</button>';
          html += '<button data-rsv-seat="' + r.id + '" style="flex:1;background:#1976d2;color:#fff;border:none;padding:8px;border-radius:6px;font-size:0.85rem;">🪑 即着席</button>';
        }
        html += '<button data-rsv-noshow="' + r.id + '" style="background:#c62828;color:#fff;border:none;padding:8px 12px;border-radius:6px;font-size:0.85rem;">no-show</button>';
        html += '</div>';
      }
      html += '</div>';
    });
    listEl.innerHTML = html;
  }

  function seatReservationFromHandy(reservationId) {
    if (_guardOfflineOrStale()) return;
    if (!confirm('この予約客を即着席させますか?')) return;
    apiPost('/store/reservation-seat.php', { reservation_id: reservationId, store_id: _storeId })
      .then(function (j) {
        if (j.ok) { showToast('着席完了', 2500); loadReservations(); }
        else showToast(apiErrorMsg(j), 3500);
      })
      .catch(function (e) { showToast('通信エラー: ' + e.message, 3000); });
  }

  function markReservationNoShow(reservationId) {
    if (_guardOfflineOrStale()) return;
    if (!confirm('この予約を no-show として記録しますか?')) return;
    apiPost('/store/reservation-no-show.php', { reservation_id: reservationId, store_id: _storeId })
      .then(function (j) {
        if (j.ok) {
          var msg = 'no-show 完了';
          if (j.data && j.data.captured_amount) msg += ' (予約金 ¥' + j.data.captured_amount.toLocaleString() + ' 回収)';
          showToast(msg, 3000);
          loadReservations();
        } else showToast(apiErrorMsg(j), 3500);
      })
      .catch(function (e) { showToast('通信エラー: ' + e.message, 3000); });
  }

  // L-9: テーブル開放 (ホワイトリスト方式)
  // スタッフが空席を「開放」 → next_session_token を発行 (5分有効、ワンタイム)
  // 客が QR を読むと自動的にセッション作成、トークンは即消費される
  function openTableForCustomer(tableId, tableCode) {
    if (_guardOfflineOrStale()) return;
    apiPost('/store/table-open.php', { store_id: _storeId, table_id: tableId, expires_minutes: 5 })
      .then(function (j) {
        if (j.ok) {
          showToast('テーブル ' + tableCode + ' を開放しました (5分有効)', 4000);
          loadTables();
          refreshTableSurfaces();
        } else {
          var msg = apiErrorMsg(j);
          if (j.error && j.error.code === 'TABLE_IN_USE') msg = 'テーブルは既に使用中です';
          showToast('開放失敗: ' + msg, 3500);
        }
      })
      .catch(function (e) { showToast('通信エラー: ' + e.message, 3000); });
  }

  // Batch-X: 既存セッションへのプラン後付け modal
  //   PATCH /api/store/table-sessions.php?id=... { plan_id } を呼ぶ
  //   POST の着席フローと異なり、既に seated 状態のセッションに対して実行する
  function openStartPlanModal(sessionId, tableCode) {
    if (_guardOfflineOrStale()) return;
    if (!_timeLimitPlans || _timeLimitPlans.length === 0) {
      showToast('食べ放題プランが登録されていません', 3000);
      return;
    }

    var planOptions = '';
    _timeLimitPlans.forEach(function (p) {
      planOptions += '<option value="' + p.id + '">' + Utils.escapeHtml(p.name) + ' (' + p.duration_min + '分 ¥' + Number(p.price).toLocaleString() + ')</option>';
    });

    els.optTitle.textContent = tableCode + ' にプラン開始';
    els.optBody.innerHTML =
      '<div class="opt-group">'
      + '<div class="opt-group__title">開始するプラン</div>'
      + '<select id="start-plan-select" class="ctx-select" style="width:100%">' + planOptions + '</select>'
      + '</div>'
      + '<div class="opt-group">'
      + '<div class="opt-group__title" style="color:#666;font-size:0.8rem">注意</div>'
      + '<div style="font-size:0.8rem;color:#666;line-height:1.5">プランは「今から」開始し、制限時間は現在時刻から計算されます。既存の注文は保持されます。</div>'
      + '</div>';

    els.optPrice.textContent = '';
    els.optAdd.textContent = 'プラン開始';
    els.optAdd.disabled = false;
    els.optOverlay.classList.add('show');

    var newAdd = els.optAdd.cloneNode(true);
    els.optAdd.parentNode.replaceChild(newAdd, els.optAdd);
    els.optAdd = newAdd;

    els.optAdd.addEventListener('click', function () {
      if (_guardOfflineOrStale()) return;
      var planId = document.getElementById('start-plan-select').value;
      if (!planId) return;

      els.optAdd.disabled = true;
      els.optAdd.textContent = '処理中...';

      apiFetch('/store/table-sessions.php?id=' + encodeURIComponent(sessionId), {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ store_id: _storeId, plan_id: planId })
      }).then(function (json) {
        if (!json.ok) {
          showToast(apiErrorMsg(json) || 'プラン開始に失敗しました', 3500);
          els.optAdd.disabled = false;
          els.optAdd.textContent = 'プラン開始';
          return;
        }
        closeOptionModal();
        showToast(tableCode + ' にプランを開始しました', 3000);
        loadTableStatus();
      }).catch(function (err) {
        showToast((err && err.message) || '通信エラー', 3000);
        els.optAdd.disabled = false;
        els.optAdd.textContent = 'プラン開始';
      });
    });
  }

  function openSeatModal(tableId, tableCode) {
    var planOptions = '<option value="">プランなし（通常）</option>';
    _timeLimitPlans.forEach(function (p) {
      planOptions += '<option value="' + p.id + '">' + Utils.escapeHtml(p.name) + ' (' + p.duration_min + '分 ¥' + Number(p.price).toLocaleString() + ')</option>';
    });

    var courseOptions = '<option value="">コースなし（通常）</option>';
    _courseTemplates.forEach(function (c) {
      courseOptions += '<option value="' + c.id + '">' + Utils.escapeHtml(c.name) + ' (¥' + Number(c.price).toLocaleString() + ')</option>';
    });

    els.optTitle.textContent = tableCode + ' に着席';
    els.optBody.innerHTML =
      '<div class="opt-group">'
      + '<div class="opt-group__title">人数</div>'
      + '<input type="number" id="seat-guest-count" class="ctx-input" min="1" value="2" style="width:100%;margin-bottom:0.75rem">'
      + '</div>'
      + '<div class="opt-group">'
      + '<div class="opt-group__title">食べ放題プラン</div>'
      + '<select id="seat-plan" class="ctx-select" style="width:100%">' + planOptions + '</select>'
      + '</div>'
      + '<div class="opt-group">'
      + '<div class="opt-group__title">コース</div>'
      + '<select id="seat-course" class="ctx-select" style="width:100%">' + courseOptions + '</select>'
      + '</div>'
      + '<div class="opt-group">'
      + '<div class="opt-group__title">メモ</div>'
      + '<textarea id="seat-memo" class="ctx-input" rows="2" placeholder="アレルギー・VIP等" style="width:100%;resize:vertical"></textarea>'
      + '</div>';

    // プランとコースの排他制御
    setTimeout(function () {
      var planSel = document.getElementById('seat-plan');
      var courseSel = document.getElementById('seat-course');
      if (planSel && courseSel) {
        planSel.addEventListener('change', function () {
          if (this.value) courseSel.value = '';
        });
        courseSel.addEventListener('change', function () {
          if (this.value) planSel.value = '';
        });
      }
    }, 0);

    els.optPrice.textContent = '';
    els.optAdd.textContent = '着席する';
    els.optAdd.disabled = false;
    els.optOverlay.classList.add('show');

    // 既存リスナーを一旦外して付け直し
    var newAdd = els.optAdd.cloneNode(true);
    els.optAdd.parentNode.replaceChild(newAdd, els.optAdd);
    els.optAdd = newAdd;

    els.optAdd.addEventListener('click', function () {
      if (_guardOfflineOrStale()) return;
      var guestCount = parseInt(document.getElementById('seat-guest-count').value, 10) || 1;
      var planId = document.getElementById('seat-plan').value || null;
      var courseId = document.getElementById('seat-course').value || null;
      var memoEl = document.getElementById('seat-memo');
      var seatMemo = memoEl ? memoEl.value.trim() : '';

      els.optAdd.disabled = true;
      els.optAdd.textContent = '処理中...';

      apiPost('/store/table-sessions.php', {
        store_id: _storeId,
        table_id: tableId,
        guest_count: guestCount,
        plan_id: planId,
        course_id: courseId,
        memo: seatMemo || null
      }).then(function (json) {
        if (!json.ok) {
          toast(apiErrorMsg(json), 'error');
          els.optAdd.disabled = false;
          els.optAdd.textContent = '着席する';
          return;
        }
        closeOptionModal();
        // S6: PIN表示
        var pin = json.data.sessionPin;
        if (pin) {
          toast(tableCode + ' に' + guestCount + '名着席  PIN: ' + pin, 'success');
        } else {
          toast(tableCode + ' に' + guestCount + '名着席しました', 'success');
        }
        loadTableStatus();
      }).catch(function (err) {
        toast(err.message || '通信エラー', 'error');
        els.optAdd.disabled = false;
        els.optAdd.textContent = '着席する';
      });
    });
  }

  // ==== Memo modal (メモ編集) ====
  var _memoPresets = ['アレルギー注意', 'VIP', 'お子様連れ', '誕生日', '接待', '車椅子'];

  function openMemoModal(sessionId, tableCode, currentMemo) {
    var presetsHtml = '<div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-bottom:0.75rem">';
    _memoPresets.forEach(function (tag) {
      presetsHtml += '<button type="button" class="memo-preset-tag" style="font-size:0.8rem;padding:0.3rem 0.6rem;border:1px solid #90a4ae;border-radius:12px;background:#eceff1;color:#546e7a;cursor:pointer">' + Utils.escapeHtml(tag) + '</button>';
    });
    presetsHtml += '</div>';

    els.optTitle.textContent = tableCode + ' メモ';
    els.optBody.innerHTML =
      '<div class="opt-group">'
      + '<div class="opt-group__title">プリセット</div>'
      + presetsHtml
      + '<textarea id="memo-text" class="ctx-input" rows="4" placeholder="メモを入力" style="width:100%;resize:vertical">' + Utils.escapeHtml(currentMemo) + '</textarea>'
      + '</div>';

    els.optPrice.textContent = '';
    els.optAdd.textContent = '保存';
    els.optAdd.disabled = false;
    els.optOverlay.classList.add('show');

    // プリセットタグ → テキストエリアに追記
    setTimeout(function () {
      var tags = els.optBody.querySelectorAll('.memo-preset-tag');
      var ta = document.getElementById('memo-text');
      for (var i = 0; i < tags.length; i++) {
        tags[i].addEventListener('click', function () {
          if (!ta) return;
          var val = ta.value.trim();
          ta.value = val ? val + '\n' + this.textContent : this.textContent;
          ta.focus();
        });
      }
    }, 0);

    // cloneNode パターンで保存ボタンのリスナーを付け替え
    var newAdd = els.optAdd.cloneNode(true);
    els.optAdd.parentNode.replaceChild(newAdd, els.optAdd);
    els.optAdd = newAdd;

    els.optAdd.addEventListener('click', function () {
      if (_guardOfflineOrStale()) return;
      var ta = document.getElementById('memo-text');
      var memo = ta ? ta.value.trim() : '';

      els.optAdd.disabled = true;
      els.optAdd.textContent = '保存中...';

      apiPatch('/store/table-sessions.php?id=' + encodeURIComponent(sessionId), {
        store_id: _storeId,
        memo: memo || null
      }).then(function (json) {
        if (!json.ok) {
          toast(apiErrorMsg(json), 'error');
          els.optAdd.disabled = false;
          els.optAdd.textContent = '保存';
          return;
        }
        closeOptionModal();
        toast(tableCode + ' のメモを保存しました', 'success');
        loadTableStatus();
      }).catch(function (err) {
        toast(err.message || '通信エラー', 'error');
        els.optAdd.disabled = false;
        els.optAdd.textContent = '保存';
      });
    });
  }

  // ==== Cancel session (着席キャンセル) ====
  function cancelSession(sessionId, tableCode) {
    if (_guardOfflineOrStale()) return;
    if (!confirm(tableCode + ' の着席をキャンセルしますか？')) return;
    apiFetch('/store/table-sessions.php?id=' + encodeURIComponent(sessionId) + '&store_id=' + encodeURIComponent(_storeId), {
      method: 'DELETE'
    }).then(function (json) {
      if (!json.ok) {
        toast(apiErrorMsg(json), 'error');
        return;
      }
      toast(tableCode + ' の着席をキャンセルしました', 'success');
      loadTableStatus();
    }).catch(function (err) {
      toast(err.message || '通信エラー', 'error');
    });
  }

  // ==== Toast ====
  var _toastTimer = null;

  function toast(msg, type) {
    if (_toastTimer) clearTimeout(_toastTimer);
    els.toast.textContent = msg;
    els.toast.className = 'handy-toast show' + (type ? ' handy-toast--' + type : '');
    _toastTimer = setTimeout(function () {
      els.toast.classList.remove('show');
    }, 2000);
  }

  // ==== Store overlay ====
  function _showStoreOverlay(stores, onSelect) {
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

  // ==== F-QR1: 個別QR (サブセッション) ====
  function _openSubQrModal(sessionId, tableId, tableCode) {
    els.optTitle.textContent = tableCode + ' 個別QR発行';
    els.optBody.innerHTML = '<div style="text-align:center;padding:1rem"><p style="color:#aaa">読み込み中...</p></div>';
    els.optAdd.style.display = 'none';
    els.optOverlay.classList.add('show');

    // 既存サブセッション一覧を取得
    apiFetch('/store/sub-sessions.php?store_id=' + encodeURIComponent(_storeId) + '&table_session_id=' + encodeURIComponent(sessionId))
      .then(function (json) {
        if (!json.ok) {
          els.optBody.innerHTML = '<p style="color:#e74c3c">' + apiErrorMsg(json) + '</p>';
          return;
        }
        _renderSubQrPanel(json.data.subSessions || [], sessionId, tableId, tableCode);
      })
      .catch(function (err) {
        els.optBody.innerHTML = '<p style="color:#e74c3c">' + Utils.escapeHtml(err.message) + '</p>';
      });
  }

  function _renderSubQrPanel(subs, sessionId, tableId, tableCode) {
    var html = '';
    if (subs.length > 0) {
      html += '<div style="margin-bottom:1rem">';
      for (var i = 0; i < subs.length; i++) {
        var s = subs[i];
        var qrUrl = window.location.origin + window.location.pathname.replace(/\/handy\/.*$/, '')
          + '/customer/menu.html?store_id=' + encodeURIComponent(_storeId)
          + '&table_id=' + encodeURIComponent(tableId)
          + '&sub_token=' + encodeURIComponent(s.subToken);
        html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem;border-bottom:1px solid #37474f">';
        html += '<div>';
        html += '<strong style="color:#ff6f00">' + Utils.escapeHtml(s.label || '—') + '</strong>';
        html += '<div style="font-size:0.75rem;color:#90a4ae">' + s.orderCount + '件 / ' + Utils.formatYen(s.totalAmount) + '</div>';
        html += '</div>';
        html += '<a href="' + Utils.escapeHtml(qrUrl) + '" target="_blank" style="padding:0.4rem 0.8rem;background:#1e88e5;color:#fff;border-radius:6px;font-size:0.8rem;text-decoration:none">QR表示</a>';
        html += '</div>';
      }
      html += '</div>';
    } else {
      html += '<p style="color:#90a4ae;text-align:center;margin-bottom:1rem">個別QRはまだ発行されていません</p>';
    }
    html += '<button id="sub-qr-add-btn" style="width:100%;padding:0.75rem;background:#ff6f00;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer">+ 個別QRを発行</button>';
    els.optBody.innerHTML = html;

    document.getElementById('sub-qr-add-btn').addEventListener('click', function () {
      if (_guardOfflineOrStale()) return;
      this.disabled = true;
      this.textContent = '発行中...';
      apiPost('/store/sub-sessions.php', {
        store_id: _storeId,
        table_session_id: sessionId,
        table_id: tableId
      }).then(function (json) {
        if (!json.ok) {
          toast(apiErrorMsg(json), 'error');
          return;
        }
        var sub = json.data.subSession;
        toast(sub.label + ' の個別QRを発行しました', 'success');
        // リロード
        _openSubQrModal(sessionId, tableId, tableCode);
      }).catch(function (err) {
        toast(err.message, 'error');
      });
    });
  }

  // ==== C-G1: GPS 圏内チェック ====
  function _haversine(lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLng = (lng2 - lng1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function _getStoreGps(storeId) {
    for (var i = 0; i < _stores.length; i++) {
      if (_stores[i].id === storeId) {
        return {
          gpsRequired: _stores[i].gpsRequired || 0,
          storeLat: _stores[i].storeLat,
          storeLng: _stores[i].storeLng,
          gpsRadiusMeters: _stores[i].gpsRadiusMeters || 200
        };
      }
    }
    return null;
  }

  function _checkGpsAccess(onSuccess) {
    var gps = _getStoreGps(_storeId);
    if (!gps) { onSuccess(); return; }

    var isDevice = _user && _user.role === 'device';
    var shouldCheck = isDevice || gps.gpsRequired === 1;
    if (!shouldCheck || gps.storeLat === null || gps.storeLng === null) {
      onSuccess();
      return;
    }
    _showGpsOverlay('位置情報を確認中...', '#666');
    if (!navigator.geolocation) {
      _showGpsOverlay('このブラウザは位置情報に対応していません', '#e74c3c', true, onSuccess);
      return;
    }
    navigator.geolocation.getCurrentPosition(function (pos) {
      var dist = _haversine(gps.storeLat, gps.storeLng, pos.coords.latitude, pos.coords.longitude);
      if (dist <= gps.gpsRadiusMeters) {
        _hideGpsOverlay();
        onSuccess();
      } else {
        _showGpsOverlay(
          '店舗から' + Math.round(dist) + 'm離れています（許容: ' + gps.gpsRadiusMeters + 'm）。店舗圏内でご利用ください。',
          '#e74c3c', true, onSuccess
        );
      }
    }, function (err) {
      var msg = '位置情報を取得できませんでした';
      if (err.code === 1) msg = '位置情報の取得が拒否されました。ブラウザの設定を確認してください';
      _showGpsOverlay(msg, '#e74c3c', true, onSuccess);
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
  }

  function _showGpsOverlay(msg, color, showRetry, onSuccess) {
    var ov = document.getElementById('handy-gps-overlay');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'handy-gps-overlay';
      ov.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;';
      document.body.appendChild(ov);
    }
    var html = '<div style="background:#fff;padding:2rem;border-radius:12px;max-width:360px;text-align:center;">'
      + '<div style="font-size:2rem;margin-bottom:1rem;">📍</div>'
      + '<p style="color:' + (color || '#666') + ';font-size:0.95rem;margin-bottom:1rem;">' + Utils.escapeHtml(msg) + '</p>';
    if (showRetry) {
      html += '<button id="handy-gps-retry" style="padding:0.6rem 1.5rem;background:#ff6f00;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer;">再チェック</button>';
    }
    html += '</div>';
    ov.innerHTML = html;
    ov.style.display = 'flex';
    if (showRetry) {
      document.getElementById('handy-gps-retry').addEventListener('click', function () {
        _checkGpsAccess(onSuccess);
      });
    }
  }

  function _hideGpsOverlay() {
    var ov = document.getElementById('handy-gps-overlay');
    if (ov) ov.style.display = 'none';
  }

  return { init: init };
})();
