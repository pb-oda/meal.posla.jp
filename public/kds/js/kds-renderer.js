/**
 * KDSキッチンボード レンダラー
 * 3列カンバン: 受付 → 調理中 → 提供待ち
 *
 * Sprint A-3: オプション表示対応
 */
var KdsRenderer = (function () {
  'use strict';

  var _orders = {};
  var _columns = {
    pending: null,
    preparing: null,
    ready: null
  };
  // KDS-P1-1: expeditor (まとめ表示) モード用
  var _expeditorEl = null;
  var _mode = 'normal'; // 'normal' | 'expeditor'

  // 時間超過アラート閾値（秒）
  var WARNING_THRESHOLD_SEC = 600;  // 10分
  var DANGER_THRESHOLD_SEC  = 1200; // 20分

  function init(pendingEl, preparingEl, readyEl) {
    _columns.pending = pendingEl;
    _columns.preparing = preparingEl;
    _columns.ready = readyEl;
  }

  // KDS-P1-1: expeditor コンテナ登録 (呼び出し側でなくても動作 - null でも問題なし)
  function initExpeditor(el) {
    _expeditorEl = el;
  }

  // KDS-P1-1: 表示モード切替。render() はこの値を見て分岐
  function setMode(mode) {
    if (mode !== 'normal' && mode !== 'expeditor') return;
    _mode = mode;
    render();
  }

  function getMode() {
    return _mode;
  }

  function onData(data) {
    var orders = data.orders || [];

    // フルリフレッシュ
    _orders = {};
    orders.forEach(function (o) {
      _orders[o.id] = o;
    });
    render();

    // N-4: 低評価アラート表示
    renderLowRatingAlerts(data.low_rating_alerts || []);
  }

  function render() {
    // KDS-P1-1: expeditor モードでは専用レンダラへ委譲 (通常 3 列カンバンは維持)
    if (_mode === 'expeditor' && _expeditorEl) {
      renderExpeditor();
      return;
    }

    var groups = { pending: [], preparing: [], ready: [] };

    Object.keys(_orders).forEach(function (id) {
      var o = _orders[id];
      // 品目単位でカラム振り分け（品目ごとに独立したカードを作成）
      var hasItems = o.items && o.items.length > 0;
      if (!hasItems) {
        // items未設定の注文はorder.statusで表示（レガシー互換）
        if (groups[o.status]) groups[o.status].push(o);
        return;
      }
      var itemsByStatus = {};
      o.items.forEach(function (item) {
        var s = item.status || 'pending';
        if (s === 'served' || s === 'cancelled') return;
        if (!itemsByStatus[s]) itemsByStatus[s] = [];
        itemsByStatus[s].push(item);
      });
      if (Object.keys(itemsByStatus).length === 0) return; // 全品完了
      ['pending', 'preparing', 'ready'].forEach(function (status) {
        if (!itemsByStatus[status] || itemsByStatus[status].length === 0) return;
        var vo = {};
        var keys = Object.keys(o);
        for (var k = 0; k < keys.length; k++) { vo[keys[k]] = o[keys[k]]; }
        vo.items = itemsByStatus[status];
        vo._allItems = o.items;
        groups[status].push(vo);
      });
    });

    // 変更前の注文数を記録
    var prevCounts = {};
    Object.keys(_columns).forEach(function (status) {
      if (_columns[status]) {
        prevCounts[status] = _columns[status].querySelectorAll('.kds-card').length;
      }
    });

    // スクロール位置を保存
    var scrollPositions = {};
    Object.keys(_columns).forEach(function (status) {
      if (_columns[status]) {
        scrollPositions[status] = _columns[status].scrollTop;
      }
    });

    Object.keys(groups).forEach(function (status) {
      if (!_columns[status]) return;
      var html = '';
      groups[status].forEach(function (o) {
        html += renderCard(o, status);
      });
      _columns[status].innerHTML = html || '<div class="kds-empty">なし</div>';
    });

    // スクロール処理: 注文が増えたカラムは最下部へ、それ以外は位置復元
    Object.keys(_columns).forEach(function (status) {
      if (!_columns[status]) return;
      var newCount = _columns[status].querySelectorAll('.kds-card').length;
      if (newCount > (prevCounts[status] || 0)) {
        _columns[status].scrollTo({ top: _columns[status].scrollHeight, behavior: 'smooth' });
      } else if (scrollPositions[status] !== undefined) {
        _columns[status].scrollTop = scrollPositions[status];
      }
    });

    // カウント更新
    ['pending', 'preparing', 'ready'].forEach(function (s) {
      var countEl = document.getElementById('count-' + s);
      if (countEl) countEl.textContent = groups[s].length;
    });

    // Phase 3: stale 状態なら action ボタンを無効化 (read-only 表示)
    _applyStaleReadonlyIfNeeded();
  }

  // Phase 3: OfflineStateBanner.isStale() が true の間、
  // 調理開始 / 完成 / 提供済 / 取消 などの状態変更ボタンを disabled にする。
  // 通信が復帰して次の render() が走れば disabled は消える (stale=false のため)。
  function _applyStaleReadonlyIfNeeded() {
    if (typeof OfflineStateBanner === 'undefined' || !OfflineStateBanner.isStale()) return;
    var selectors = '.kds-card__action, .kds-card__cancel, .kds-item-action';
    var staleTitle = '\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u64CD\u4F5C\u3057\u3066\u304F\u3060\u3055\u3044';
    Object.keys(_columns).forEach(function (status) {
      if (!_columns[status]) return;
      var btns = _columns[status].querySelectorAll(selectors);
      for (var i = 0; i < btns.length; i++) {
        btns[i].disabled = true;
        btns[i].title = staleTitle;
        btns[i].setAttribute('aria-disabled', 'true');
        btns[i].style.opacity = '0.5';
        btns[i].style.cursor = 'not-allowed';
      }
    });
  }

  function renderCard(order, status) {
    var elapsed = Math.floor((Date.now() - new Date(order.created_at).getTime()) / 1000);
    var timeStr = Utils.formatDuration(elapsed);

    var itemsHtml = (order.items || []).map(function (item) {
      var optHtml = '';
      if (item.options && item.options.length > 0) {
        var optLabels = item.options.map(function (o) {
          return Utils.escapeHtml(o.choiceName || o.name || '');
        }).join(', ');
        optHtml = '<span class="kds-item-options">' + optLabels + '</span>';
      }

      // 品目ステータス表示
      var itemStatus = item.status || 'pending';
      var liClass = '';
      var itemActionHtml = '';

      if (itemStatus === 'served') {
        liClass = ' class="kds-item--served"';
        itemActionHtml = '<span class="kds-item-badge kds-item-badge--served">&#10003;提供済</span>';
      } else if (itemStatus === 'cancelled') {
        liClass = ' class="kds-item--cancelled"';
        itemActionHtml = '<span class="kds-item-badge kds-item-badge--cancelled">取消</span>';
      } else if (item.item_id) {
        // item_id がある場合のみ個別ボタンを表示
        if (itemStatus === 'pending') {
          itemActionHtml = '<button class="kds-item-action kds-item-action--start" data-item-id="' + item.item_id + '" data-item-status="preparing">調理開始</button>';
        } else if (itemStatus === 'preparing') {
          itemActionHtml = '<button class="kds-item-action kds-item-action--ready" data-item-id="' + item.item_id + '" data-item-status="ready">完成</button>';
        } else if (itemStatus === 'ready') {
          itemActionHtml = '<button class="kds-item-action kds-item-action--served" data-item-id="' + item.item_id + '" data-item-status="served">提供済</button>';
        }
      }

      return '<li' + liClass + '>'
        + Utils.escapeHtml(item.name) + ' &times;' + item.qty
        + optHtml + itemActionHtml + '</li>';
    }).join('');

    var cancelBtn = '<button class="kds-card__cancel" data-order="' + order.id + '" data-status="cancelled">取消</button>';
    var nextAction = '';
    if (status === 'pending') {
      nextAction = cancelBtn + '<button class="kds-card__action" data-order="' + order.id + '" data-status="preparing">全品調理開始</button>';
    } else if (status === 'preparing') {
      // preparing: 品目個別操作が主。全品readyチェック用ボタンのみ
      nextAction = cancelBtn + '<button class="kds-card__action" data-order="' + order.id + '" data-status="ready">全品完成</button>';
    } else if (status === 'ready') {
      nextAction = cancelBtn + '<button class="kds-card__action" data-order="' + order.id + '" data-status="served">全品提供済み</button>';
    }

    // テーブル名 or テイクアウト表示
    var tableLabel = order.table_code || '';
    var typeBadge = '';
    if (order.order_type === 'takeout') {
      tableLabel = order.customer_name || 'テイクアウト';
      typeBadge = '<span class="kds-badge-takeout">テイクアウト</span> ';
      if (order.pickup_at) {
        var pickupDate = new Date(order.pickup_at);
        var pickupTimeStr = ('0' + pickupDate.getHours()).slice(-2) + ':' + ('0' + pickupDate.getMinutes()).slice(-2);
        typeBadge += '<span class="kds-card__pickup-time">' + pickupTimeStr + '</span> ';
      }
    } else if (order.order_type === 'handy') {
      typeBadge = '<span class="kds-badge-handy">H</span> ';
    }

    // コースフェーズバッジ
    var courseBadge = '';
    if (order.course_id && order.current_phase) {
      var cLabel = (order.course_name || 'コース') + ' P' + order.current_phase;
      if (order.phase_name) cLabel += ':' + order.phase_name;
      courseBadge = '<span class="kds-badge-course">' + Utils.escapeHtml(cLabel) + '</span> ';
    }

    var cardClass = 'kds-card';
    if (order.order_type === 'takeout') cardClass += ' kds-card--takeout';
    if (order.course_id) cardClass += ' kds-card--course';
    if (elapsed >= DANGER_THRESHOLD_SEC) {
      cardClass += ' kds-card--danger';
    } else if (elapsed >= WARNING_THRESHOLD_SEC) {
      cardClass += ' kds-card--warning';
    }

    // N-3: メモ表示
    var memoHtml = '';
    if (order.memo) {
      memoHtml = '<div class="kds-order-memo">' + Utils.escapeHtml(order.memo) + '</div>';
    }

    // N-3: アレルギー表示（items から集約）
    var allergenSet = {};
    (order._allItems || order.items || []).forEach(function (item) {
      if (item.allergen_selections) {
        var sels = typeof item.allergen_selections === 'string' ? JSON.parse(item.allergen_selections) : item.allergen_selections;
        if (sels && sels.forEach) {
          sels.forEach(function (a) { allergenSet[a] = true; });
        }
      }
    });
    var allergenKeys = Object.keys(allergenSet);
    var allergenHtml = '';
    if (allergenKeys.length > 0) {
      var allergenLabels = { egg: '\u5375', milk: '\u4E73', wheat: '\u5C0F\u9EA6', shrimp: '\u3048\u3073', crab: '\u304B\u306B', buckwheat: '\u305D\u3070', peanut: '\u843D\u82B1\u751F' };
      var labels = allergenKeys.map(function (k) { return allergenLabels[k] || k; });
      allergenHtml = '<div class="kds-order-allergen">\u26A0\uFE0F \u30A2\u30EC\u30EB\u30AE\u30FC: ' + labels.join(', ') + '</div>';
    }

    return '<div class="' + cardClass + '" data-order-id="' + order.id + '"'
      + (order.course_id ? ' data-course-id="' + order.course_id + '" data-table-id="' + (order.table_id || '') + '"' : '')
      + '>'
      + '<div class="kds-card__header">'
      + '<span class="kds-card__table">' + typeBadge + courseBadge + Utils.escapeHtml(tableLabel) + '</span>'
      + '<span class="kds-card__time">' + timeStr + '</span>'
      + '</div>'
      + memoHtml + allergenHtml
      + '<ul class="kds-card__items">' + itemsHtml + '</ul>'
      + '<div class="kds-card__footer">'
      + '<span class="kds-card__total">' + (order.course_id ? '' : Utils.formatYen(order.total_amount)) + '</span>'
      + nextAction
      + '</div></div>';
  }

  // ============================================================
  // KDS-P1-1: expeditor (まとめ表示) モード
  //   - 注文単位のカードを 1 カラムに並べる
  //   - 優先: (1) ready item を持つ注文を先頭 (2) 経過時間が長い順
  //   - 各カードに ready/preparing/pending 品目のグループ表示
  //   - 既存 event delegation (.kds-item-action / .kds-card__action / .kds-card__cancel) をそのまま使う
  // ============================================================
  function renderExpeditor() {
    if (!_expeditorEl) return;

    // 変更前のスクロール位置・カード数を保存
    var prevCount = _expeditorEl.querySelectorAll('.kds-card').length;
    var prevScroll = _expeditorEl.scrollTop;

    // active な item を持つ注文を集める
    var activeOrders = [];
    Object.keys(_orders).forEach(function (id) {
      var o = _orders[id];
      var hasItems = o.items && o.items.length > 0;
      if (!hasItems) {
        // レガシー互換: items なし注文は order.status で判定
        if (o.status === 'pending' || o.status === 'preparing' || o.status === 'ready') {
          activeOrders.push({ order: o, hasReady: o.status === 'ready', elapsed: _elapsedSec(o) });
        }
        return;
      }
      var hasReady = false;
      var anyActive = false;
      for (var i = 0; i < o.items.length; i++) {
        var s = o.items[i].status || 'pending';
        if (s === 'served' || s === 'cancelled') continue;
        anyActive = true;
        if (s === 'ready') hasReady = true;
      }
      if (!anyActive) return; // 全品完了/取消
      activeOrders.push({ order: o, hasReady: hasReady, elapsed: _elapsedSec(o) });
    });

    // ソート: ready 持ちが先頭、続いて経過時間が長い順
    activeOrders.sort(function (a, b) {
      if (a.hasReady !== b.hasReady) return a.hasReady ? -1 : 1;
      return b.elapsed - a.elapsed;
    });

    // counts 更新 (通常モードと同じ count-* 要素を共有)
    var counts = { pending: 0, preparing: 0, ready: 0 };
    for (var n = 0; n < activeOrders.length; n++) {
      var items = activeOrders[n].order.items || [];
      for (var m = 0; m < items.length; m++) {
        var s2 = items[m].status || 'pending';
        if (counts[s2] !== undefined) counts[s2]++;
      }
    }
    ['pending', 'preparing', 'ready'].forEach(function (s) {
      var countEl = document.getElementById('count-' + s);
      if (countEl) countEl.textContent = counts[s];
    });

    // カードを描画
    var html = '';
    for (var k = 0; k < activeOrders.length; k++) {
      html += _renderExpeditorCard(activeOrders[k].order, activeOrders[k].hasReady, activeOrders[k].elapsed);
    }
    _expeditorEl.innerHTML = html || '<div class="kds-empty">\u6D3B\u6027\u306A\u6CE8\u6587\u306F\u3042\u308A\u307E\u305B\u3093</div>';

    // スクロール復元 (カードが増えたら最下部へ)
    var newCount = _expeditorEl.querySelectorAll('.kds-card').length;
    if (newCount > prevCount) {
      _expeditorEl.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
      _expeditorEl.scrollTop = prevScroll;
    }

    _applyStaleReadonlyIfNeeded();
  }

  function _elapsedSec(order) {
    var t = order.created_at ? new Date(order.created_at).getTime() : 0;
    if (!t) return 0;
    return Math.floor((Date.now() - t) / 1000);
  }

  function _renderExpeditorCard(order, hasReady, elapsed) {
    var timeStr = Utils.formatDuration(elapsed);

    // テーブル/テイクアウト ラベル (既存 renderCard と同じ)
    var tableLabel = order.table_code || '';
    var typeBadge = '';
    if (order.order_type === 'takeout') {
      tableLabel = order.customer_name || 'テイクアウト';
      typeBadge = '<span class="kds-badge-takeout">テイクアウト</span> ';
      if (order.pickup_at) {
        var pickupDate = new Date(order.pickup_at);
        var pickupTimeStr = ('0' + pickupDate.getHours()).slice(-2) + ':' + ('0' + pickupDate.getMinutes()).slice(-2);
        typeBadge += '<span class="kds-card__pickup-time">' + pickupTimeStr + '</span> ';
      }
    } else if (order.order_type === 'handy') {
      typeBadge = '<span class="kds-badge-handy">H</span> ';
    }
    var courseBadge = '';
    if (order.course_id && order.current_phase) {
      var cLabel = (order.course_name || 'コース') + ' P' + order.current_phase;
      if (order.phase_name) cLabel += ':' + order.phase_name;
      courseBadge = '<span class="kds-badge-course">' + Utils.escapeHtml(cLabel) + '</span> ';
    }

    // urgency クラス
    var cardClass = 'kds-card kds-card--expeditor';
    if (hasReady) cardClass += ' kds-card--ready-priority';
    if (order.order_type === 'takeout') cardClass += ' kds-card--takeout';
    if (order.course_id) cardClass += ' kds-card--course';
    if (elapsed >= DANGER_THRESHOLD_SEC) {
      cardClass += ' kds-card--danger';
    } else if (elapsed >= WARNING_THRESHOLD_SEC) {
      cardClass += ' kds-card--warning';
    }

    // 品目を status でグルーピング
    var byStatus = { ready: [], preparing: [], pending: [] };
    (order.items || []).forEach(function (item) {
      var s = item.status || 'pending';
      if (s === 'served' || s === 'cancelled') return;
      if (byStatus[s]) byStatus[s].push(item);
    });

    function _itemLine(item) {
      var optHtml = '';
      if (item.options && item.options.length > 0) {
        var optLabels = item.options.map(function (o) {
          return Utils.escapeHtml(o.choiceName || o.name || '');
        }).join(', ');
        optHtml = ' <span class="kds-item-options">' + optLabels + '</span>';
      }
      var itemStatus = item.status || 'pending';
      var actionHtml = '';
      if (item.item_id) {
        if (itemStatus === 'pending') {
          actionHtml = '<button class="kds-item-action kds-item-action--start" data-item-id="' + item.item_id + '" data-item-status="preparing">\u8abf\u7406\u958b\u59cb</button>';
        } else if (itemStatus === 'preparing') {
          actionHtml = '<button class="kds-item-action kds-item-action--ready" data-item-id="' + item.item_id + '" data-item-status="ready">\u5b8c\u6210</button>';
        } else if (itemStatus === 'ready') {
          actionHtml = '<button class="kds-item-action kds-item-action--served" data-item-id="' + item.item_id + '" data-item-status="served">\u63d0\u4f9b\u6e08</button>';
        }
      }
      return '<li>' + Utils.escapeHtml(item.name) + ' &times;' + item.qty + optHtml + actionHtml + '</li>';
    }

    function _section(title, items, cls) {
      if (!items.length) return '';
      var out = '<div class="kds-expeditor-section kds-expeditor-section--' + cls + '">';
      out += '<div class="kds-expeditor-section__title">' + Utils.escapeHtml(title) + ' (' + items.length + ')</div>';
      out += '<ul class="kds-card__items">';
      for (var i = 0; i < items.length; i++) out += _itemLine(items[i]);
      out += '</ul></div>';
      return out;
    }

    // N-3: メモ / アレルギー (既存 renderCard と同じ)
    var memoHtml = order.memo ? '<div class="kds-order-memo">' + Utils.escapeHtml(order.memo) + '</div>' : '';
    var allergenSet = {};
    (order._allItems || order.items || []).forEach(function (item) {
      if (item.allergen_selections) {
        var sels = typeof item.allergen_selections === 'string' ? JSON.parse(item.allergen_selections) : item.allergen_selections;
        if (sels && sels.forEach) {
          sels.forEach(function (a) { allergenSet[a] = true; });
        }
      }
    });
    var allergenKeys = Object.keys(allergenSet);
    var allergenHtml = '';
    if (allergenKeys.length > 0) {
      var allergenLabels = { egg: '\u5375', milk: '\u4E73', wheat: '\u5C0F\u9EA6', shrimp: '\u3048\u3073', crab: '\u304B\u306B', buckwheat: '\u305D\u3070', peanut: '\u843D\u82B1\u751F' };
      var labels = allergenKeys.map(function (k) { return allergenLabels[k] || k; });
      allergenHtml = '<div class="kds-order-allergen">\u26A0\uFE0F \u30A2\u30EC\u30EB\u30AE\u30FC: ' + labels.join(', ') + '</div>';
    }

    // KDS-P1-1 hotfix: 一括ボタンは「単一ステータス状態」のときだけ出す。
    // 混在 (例: ready + pending) で全品○○を押すと handleAction() が全items を巻き戻すため、
    // 誤操作を防ぐため 一括ボタンを抑止 + 品目ごと操作を案内する
    var nextAction = '';
    var cancelBtn = '<button class="kds-card__cancel" data-order="' + order.id + '" data-status="cancelled">\u53d6\u6d88</button>';
    var readyCount = byStatus.ready.length;
    var preparingCount = byStatus.preparing.length;
    var pendingCount = byStatus.pending.length;
    var activeBuckets = (readyCount > 0 ? 1 : 0) + (preparingCount > 0 ? 1 : 0) + (pendingCount > 0 ? 1 : 0);
    if (activeBuckets === 1) {
      // 単一状態 → 巻き戻しなしで一括遷移可
      if (readyCount > 0) {
        nextAction = cancelBtn + '<button class="kds-card__action" data-order="' + order.id + '" data-status="served">\u5168\u54c1\u63d0\u4f9b\u6e08\u307f</button>';
      } else if (preparingCount > 0) {
        nextAction = cancelBtn + '<button class="kds-card__action" data-order="' + order.id + '" data-status="ready">\u5168\u54c1\u5b8c\u6210</button>';
      } else if (pendingCount > 0) {
        nextAction = cancelBtn + '<button class="kds-card__action" data-order="' + order.id + '" data-status="preparing">\u5168\u54c1\u8abf\u7406\u958b\u59cb</button>';
      } else {
        nextAction = cancelBtn;
      }
    } else if (activeBuckets > 1) {
      // 混在状態 → 巻き戻し事故防止のため一括ボタンは出さない。品目個別ボタンで操作してもらう
      nextAction = cancelBtn
        + '<span class="kds-expeditor-hint">\u54c1\u76ee\u3054\u3068\u306b\u64cd\u4f5c\u3057\u3066\u304f\u3060\u3055\u3044</span>';
    } else {
      nextAction = cancelBtn;
    }

    return '<div class="' + cardClass + '" data-order-id="' + order.id + '"'
      + (order.course_id ? ' data-course-id="' + order.course_id + '" data-table-id="' + (order.table_id || '') + '"' : '')
      + '>'
      + '<div class="kds-card__header">'
      + '<span class="kds-card__table">' + typeBadge + courseBadge + Utils.escapeHtml(tableLabel) + '</span>'
      + '<span class="kds-card__time">' + timeStr + '</span>'
      + '</div>'
      + memoHtml + allergenHtml
      + _section('\u63d0\u4f9b\u53ef\u80fd', byStatus.ready, 'ready')
      + _section('\u8abf\u7406\u4e2d', byStatus.preparing, 'preparing')
      + _section('\u53d7\u4ed8', byStatus.pending, 'pending')
      + '<div class="kds-card__footer">'
      + '<span class="kds-card__total">' + (order.course_id ? '' : Utils.formatYen(order.total_amount)) + '</span>'
      + nextAction
      + '</div></div>';
  }

  // Phase 3 レビュー指摘 #1: 状態変更関数の冒頭で offline/stale を判定し、
  // 楽観更新 + API 呼び出しを両方とも止める。DOM disabled だけでは voice-commander の
  // 直接呼び出し経路で防げないため、関数側で必ずガードする。
  function _guardOfflineOrStale(label) {
    if (typeof OfflineStateBanner === 'undefined' || !OfflineStateBanner.isOfflineOrStale) {
      return false;
    }
    if (!OfflineStateBanner.isOfflineOrStale()) return false;
    try {
      window.alert('\u30AA\u30D5\u30E9\u30A4\u30F3\u4E2D\u307E\u305F\u306F\u53E4\u3044\u30C7\u30FC\u30BF\u8868\u793A\u4E2D\u3067\u3059\u3002\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u518D\u5EA6\u64CD\u4F5C\u3057\u3066\u304F\u3060\u3055\u3044\u3002');
    } catch (e) {}
    return true;
  }

  function handleAction(orderId, newStatus, storeId) {
    // Phase 3: offline/stale ならローカル状態変更も API 呼び出しもせず、呼び出し側に
    // reject で通知する (voice-commander 等の .then() が成功音を鳴らさないように)
    if (_guardOfflineOrStale('handleAction')) {
      return Promise.reject(new Error('offline_or_stale'));
    }
    // 楽観的更新
    if (_orders[orderId]) {
      _orders[orderId].status = newStatus;
      // 全品ステータスも連動（update-status.php と同様の挙動）
      var oiStatus = newStatus === 'paid' ? 'served' : newStatus;
      (_orders[orderId].items || []).forEach(function (item) {
        if ((item.status || 'pending') !== 'cancelled') {
          item.status = oiStatus;
        }
      });
      render();
    }

    return fetch('../../api/kds/update-status.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, status: newStatus, store_id: storeId }),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('empty response');
        try { var json = JSON.parse(body); }
        catch (e) { throw new Error('invalid JSON'); }
        if (!r.ok || !json.ok) throw new Error((json.error && json.error.message) || 'update failed');
        return json;
      });
    })
    .catch(function () {
      // ロールバック — 次のポーリングで修正
      PollingDataSource.forceRefresh();
    });
  }

  function handleItemAction(itemId, newStatus, storeId) {
    // Phase 3: voice-commander からの直接呼び出しもここで止める。
    // reject で通知して呼び出し側の .then() が成功と誤認しないようにする。
    if (_guardOfflineOrStale('handleItemAction')) {
      return Promise.reject(new Error('offline_or_stale'));
    }
    // 楽観的更新: _orders 内の該当品目の status を変更
    var parentOrderId = null;
    Object.keys(_orders).forEach(function (orderId) {
      var items = _orders[orderId].items || [];
      for (var i = 0; i < items.length; i++) {
        if (items[i].item_id === itemId) {
          items[i].status = newStatus;
          parentOrderId = orderId;
          break;
        }
      }
    });

    // 品目ステータスから親注文ステータスを連動更新
    if (parentOrderId && _orders[parentOrderId]) {
      var allItems = _orders[parentOrderId].items || [];
      var activeCount = 0;
      var anyPreparing = false;
      var allReadyOrBeyond = true;
      var allDone = true;
      for (var j = 0; j < allItems.length; j++) {
        var s = allItems[j].status || 'pending';
        if (s === 'cancelled') continue;
        activeCount++;
        if (s === 'preparing') anyPreparing = true;
        if (s === 'pending' || s === 'preparing') allReadyOrBeyond = false;
        if (s !== 'served') allDone = false;
      }
      if (activeCount > 0) {
        if (allDone) {
          _orders[parentOrderId].status = 'served';
        } else if (allReadyOrBeyond) {
          _orders[parentOrderId].status = 'ready';
        } else if (anyPreparing) {
          _orders[parentOrderId].status = 'preparing';
        }
      }
    }

    render();

    return fetch('../../api/kds/update-item-status.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ item_id: itemId, status: newStatus, store_id: storeId }),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('empty response');
        try { var json = JSON.parse(body); }
        catch (e) { throw new Error('invalid JSON'); }
        if (!r.ok || !json.ok) throw new Error((json.error && json.error.message) || 'update failed');
        return json;
      });
    })
    .catch(function () {
      PollingDataSource.forceRefresh();
    });
  }

  // コーステーブルの一覧を返す（手動発火ボタン用）
  function getCourseTableIds() {
    var tables = {};
    Object.keys(_orders).forEach(function (id) {
      var o = _orders[id];
      if (o.course_id && o.table_id) {
        tables[o.table_id] = {
          tableId: o.table_id,
          tableCode: o.table_code || '',
          courseId: o.course_id,
          courseName: o.course_name || '',
          currentPhase: o.current_phase || 0
        };
      }
    });
    return Object.values(tables);
  }

  // 手動フェーズ発火
  function advancePhase(storeId, tableId) {
    // Phase 3: offline/stale ならフェーズ進行させず reject。
    if (_guardOfflineOrStale('advancePhase')) {
      return Promise.reject(new Error('offline_or_stale'));
    }
    return fetch('../../api/kds/advance-phase.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ store_id: storeId, table_id: tableId }),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('empty response');
        try { return JSON.parse(body); }
        catch (e) { throw new Error('invalid JSON'); }
      });
    })
    .then(function (json) {
      if (json.ok) PollingDataSource.forceRefresh();
      return json;
    });
  }

  // N-4: 低評価アラートバナー
  function renderLowRatingAlerts(alerts) {
    var container = document.getElementById('kds-low-rating-alerts');
    if (!container) {
      container = document.createElement('div');
      container.id = 'kds-low-rating-alerts';
      container.className = 'kds-low-rating-alerts';
      var header = document.querySelector('.kds-header') || document.querySelector('header');
      if (header && header.nextSibling) {
        header.parentNode.insertBefore(container, header.nextSibling);
      } else {
        document.body.insertBefore(container, document.body.firstChild);
      }
    }
    if (alerts.length === 0) { container.innerHTML = ''; return; }
    var html = '';
    for (var i = 0; i < alerts.length; i++) {
      var a = alerts[i];
      var emoji = a.rating === 1 ? '\uD83D\uDE1E' : '\uD83D\uDE10';
      html += '<div class="kds-rating-alert">'
        + emoji + ' <strong>' + Utils.escapeHtml(a.item_name || '') + '</strong>'
        + ' - \u4F4E\u8A55\u4FA1(' + a.rating + '/5)'
        + ' <span class="kds-rating-alert__time">' + (a.created_at || '').substring(11, 16) + '</span>'
        + '</div>';
    }
    container.innerHTML = html;
  }

  return {
    init: init,
    // KDS-P1-1: expeditor モード公開 API
    initExpeditor: initExpeditor,
    setMode: setMode,
    getMode: getMode,
    onData: onData,
    handleAction: handleAction,
    handleItemAction: handleItemAction,
    getCourseTableIds: getCourseTableIds,
    advancePhase: advancePhase
  };
})();
