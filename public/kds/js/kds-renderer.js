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
  var _servedUndoEl = null;
  var _actionUndoEl = null;
  var _actionUndo = null;
  var _actionUndoTimer = null;
  // KDS-P1-1: expeditor (まとめ表示) モード用
  var _expeditorEl = null;
  var _mode = 'normal'; // 'normal' | 'expeditor'

  // 時間超過アラート閾値（秒）
  var WARNING_THRESHOLD_SEC = 600;  // 10分
  var DANGER_THRESHOLD_SEC  = 1200; // 20分
  var PREP_THRESHOLD_SEC = 1200;    // 予約/テイクアウトの事前着手目安
  var UNDO_WINDOW_SEC = 10;

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
    renderActionUndo();
    renderServedUndo();

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

    Object.keys(groups).forEach(function (status) {
      groups[status].sort(compareOrderPriority);
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

  function ensureServedUndoEl() {
    if (_servedUndoEl) return _servedUndoEl;
    var board = document.getElementById('kds-board');
    if (!board) return null;
    _servedUndoEl = document.createElement('div');
    _servedUndoEl.id = 'kds-served-undo';
    _servedUndoEl.className = 'kds-served-undo';
    var actionUndo = document.getElementById('kds-action-undo');
    if (board.parentNode && actionUndo && actionUndo.nextSibling) {
      board.parentNode.insertBefore(_servedUndoEl, actionUndo.nextSibling);
    } else if (board.parentNode) {
      board.parentNode.insertBefore(_servedUndoEl, board);
    } else {
      board.insertBefore(_servedUndoEl, board.firstChild);
    }
    return _servedUndoEl;
  }

  function ensureActionUndoEl() {
    if (_actionUndoEl) return _actionUndoEl;
    var board = document.getElementById('kds-board');
    if (!board) return null;
    _actionUndoEl = document.createElement('div');
    _actionUndoEl.id = 'kds-action-undo';
    _actionUndoEl.className = 'kds-action-undo';
    if (board.parentNode) {
      board.parentNode.insertBefore(_actionUndoEl, board);
    } else {
      board.insertBefore(_actionUndoEl, board.firstChild);
    }
    return _actionUndoEl;
  }

  function _clearActionUndo() {
    if (_actionUndoTimer) {
      clearInterval(_actionUndoTimer);
      _actionUndoTimer = null;
    }
    _actionUndo = null;
    renderActionUndo();
  }

  function _startActionUndo(snapshot) {
    if (!snapshot) return;
    if (_actionUndoTimer) {
      clearInterval(_actionUndoTimer);
      _actionUndoTimer = null;
    }
    snapshot.expiresAt = Date.now() + (UNDO_WINDOW_SEC * 1000);
    _actionUndo = snapshot;
    renderActionUndo();
    _actionUndoTimer = setInterval(function () {
      if (!_actionUndo || Date.now() >= _actionUndo.expiresAt) {
        _clearActionUndo();
        return;
      }
      renderActionUndo();
    }, 250);
  }

  function renderActionUndo() {
    var el = ensureActionUndoEl();
    if (!el) return;
    if (!_actionUndo || Date.now() >= _actionUndo.expiresAt) {
      el.innerHTML = '';
      el.style.display = 'none';
      return;
    }
    var remain = Math.max(0, Math.ceil((_actionUndo.expiresAt - Date.now()) / 1000));
    el.innerHTML = '<div class="kds-action-undo__text">'
      + '<strong>10秒取り消し</strong> '
      + Utils.escapeHtml(_actionUndo.label || '直前の操作') + ' / 残り' + remain + '秒'
      + '</div>'
      + '<button type="button" class="kds-action-undo__btn" data-kds-undo-action="last">戻す</button>';
    el.style.display = '';
  }

  function renderServedUndo() {
    var el = ensureServedUndoEl();
    if (!el) return;
    var rows = [];
    Object.keys(_orders).forEach(function (id) {
      var o = _orders[id];
      var items = o.items || [];
      for (var i = 0; i < items.length; i++) {
        if ((items[i].status || '') !== 'served' || !items[i].item_id) continue;
        rows.push({
          itemId: items[i].item_id,
          name: items[i].name || '',
          table: o.table_code || o.customer_name || '-',
          servedAt: items[i].served_at || o.served_at || o.updated_at || ''
        });
      }
    });
    rows.sort(function (a, b) { return String(b.servedAt).localeCompare(String(a.servedAt)); });
    rows = rows.slice(0, 8);
    if (rows.length === 0) {
      el.innerHTML = '';
      el.style.display = 'none';
      return;
    }
    var html = '<div class="kds-served-undo__title">直近の配膳完了</div><div class="kds-served-undo__list">';
    for (var j = 0; j < rows.length; j++) {
      html += '<button type="button" class="kds-item-action kds-item-action--ready kds-served-undo__btn" data-item-id="' + Utils.escapeHtml(rows[j].itemId) + '" data-item-status="ready">'
        + Utils.escapeHtml(rows[j].table) + ' / ' + Utils.escapeHtml(rows[j].name) + ' を戻す</button>';
    }
    html += '</div>';
    el.innerHTML = html;
    el.style.display = '';
  }

  // Phase 3: OfflineStateBanner.isStale() が true の間、
  // 調理開始 / 完成 / 提供済 / 取消 などの状態変更ボタンを disabled にする。
  // 通信が復帰して次の render() が走れば disabled は消える (stale=false のため)。
  function _applyStaleReadonlyIfNeeded() {
    if (typeof OfflineStateBanner === 'undefined' || !OfflineStateBanner.isStale()) return;
    var selectors = '.kds-card__action, .kds-card__cancel, .kds-item-action, [data-kds-undo-action]';
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
    var extraBtns = document.querySelectorAll('#kds-action-undo [data-kds-undo-action], #kds-served-undo .kds-item-action');
    for (var j = 0; j < extraBtns.length; j++) {
      extraBtns[j].disabled = true;
      extraBtns[j].title = staleTitle;
      extraBtns[j].setAttribute('aria-disabled', 'true');
      extraBtns[j].style.opacity = '0.5';
      extraBtns[j].style.cursor = 'not-allowed';
    }
  }

  function _statusLabel(status) {
    var labels = {
      pending: '受付',
      preparing: '調理開始',
      ready: '完成',
      served: '提供済み',
      cancelled: '取消'
    };
    return labels[status] || status;
  }

  function _formatClock(date) {
    if (!date || isNaN(date.getTime())) return '';
    return ('0' + date.getHours()).slice(-2) + ':' + ('0' + date.getMinutes()).slice(-2);
  }

  function _orderLabel(order) {
    if (!order) return '-';
    if (order.order_type === 'takeout') return order.customer_name || 'テイクアウト';
    return order.table_code || order.customer_name || '-';
  }

  function _priorityRank(level) {
    if (level === 'danger') return 3;
    if (level === 'warning') return 2;
    if (level === 'prep') return 1;
    return 0;
  }

  function _applyUrgency(urgency, level, reason) {
    if (_priorityRank(level) > _priorityRank(urgency.level)) {
      urgency.level = level;
      urgency.reason = reason || '';
    } else if (_priorityRank(level) === _priorityRank(urgency.level) && !urgency.reason && reason) {
      urgency.reason = reason;
    }
  }

  function _getUrgency(order) {
    var now = Date.now();
    var elapsed = _elapsedSec(order);
    var urgency = {
      level: 'normal',
      elapsed: elapsed,
      reason: ''
    };

    if (elapsed >= DANGER_THRESHOLD_SEC) {
      _applyUrgency(urgency, 'danger', '経過' + Utils.formatDuration(elapsed));
    } else if (elapsed >= WARNING_THRESHOLD_SEC) {
      _applyUrgency(urgency, 'warning', '経過' + Utils.formatDuration(elapsed));
    }

    if (order && order.order_type === 'takeout' && order.pickup_at) {
      var pickup = new Date(order.pickup_at);
      if (!isNaN(pickup.getTime())) {
        var pickupDiffSec = Math.floor((pickup.getTime() - now) / 1000);
        if (pickupDiffSec <= 0) {
          _applyUrgency(urgency, 'danger', '受取' + _formatClock(pickup) + '超過');
        } else if (pickupDiffSec <= WARNING_THRESHOLD_SEC) {
          _applyUrgency(urgency, 'warning', '受取' + _formatClock(pickup) + 'まで' + Utils.formatDuration(pickupDiffSec));
        } else if (pickupDiffSec <= PREP_THRESHOLD_SEC) {
          _applyUrgency(urgency, 'prep', '受取' + _formatClock(pickup) + 'まで' + Utils.formatDuration(pickupDiffSec));
        }
      }
    }

    if (order && order.reservation_reserved_at) {
      var reserved = new Date(order.reservation_reserved_at);
      if (!isNaN(reserved.getTime())) {
        var reservedOverSec = Math.floor((now - reserved.getTime()) / 1000);
        var reservedDiffSec = Math.floor((reserved.getTime() - now) / 1000);
        if (reservedOverSec >= WARNING_THRESHOLD_SEC) {
          _applyUrgency(urgency, 'danger', '予約' + _formatClock(reserved) + '超過');
        } else if (reservedOverSec >= 0) {
          _applyUrgency(urgency, 'warning', '予約' + _formatClock(reserved));
        } else if (reservedDiffSec <= PREP_THRESHOLD_SEC) {
          _applyUrgency(urgency, 'prep', '予約' + _formatClock(reserved) + 'まで' + Utils.formatDuration(reservedDiffSec));
        }
      }
    }

    return urgency;
  }

  function compareOrderPriority(a, b) {
    var ua = _getUrgency(a);
    var ub = _getUrgency(b);
    var pr = _priorityRank(ub.level) - _priorityRank(ua.level);
    if (pr !== 0) return pr;
    return ub.elapsed - ua.elapsed;
  }

  function _uniquePush(list, value) {
    if (!value) return;
    for (var i = 0; i < list.length; i++) {
      if (list[i] === value) return;
    }
    list.push(value);
  }

  function _itemOptionText(item) {
    var labels = [];
    if (item && item.options && item.options.length > 0) {
      for (var i = 0; i < item.options.length; i++) {
        labels.push(item.options[i].choiceName || item.options[i].name || '');
      }
    }
    return labels.join(' ');
  }

  function _collectNotice(order) {
    var allergenSet = {};
    var cautions = [];
    var allergenLabels = { egg: '\u5375', milk: '\u4E73', wheat: '\u5C0F\u9EA6', shrimp: '\u3048\u3073', crab: '\u304B\u306B', buckwheat: '\u305D\u3070', peanut: '\u843D\u82B1\u751F' };
    var cautionRules = [
      { label: '辛さ指定', words: ['辛', '激辛', 'ピリ辛', '唐辛子', 'チリ', 'spicy'] },
      { label: '抜き/なし指定', words: ['抜き', 'なし', '無し', '除く', '不使用', '入れない'] },
      { label: '温度/温め指定', words: ['温め', 'あたため', '熱め', 'ぬるめ', '冷たい', '冷やし'] },
      { label: '焼き加減/仕上げ指定', words: ['よく焼き', 'ウェルダン', 'レア', '固め', '柔らか', '別添え', '少なめ', '多め'] }
    ];
    var texts = [];
    if (order && order.memo) texts.push(order.memo);
    (order && (order._allItems || order.items) || []).forEach(function (item) {
      if (item.allergen_selections) {
        try {
          var sels = typeof item.allergen_selections === 'string' ? JSON.parse(item.allergen_selections) : item.allergen_selections;
          if (sels && sels.forEach) {
            sels.forEach(function (a) { allergenSet[a] = true; });
          }
        } catch (e) {}
      }
      var optText = _itemOptionText(item);
      if (optText) texts.push(optText);
    });

    var source = texts.join(' ');
    for (var i = 0; i < cautionRules.length; i++) {
      for (var j = 0; j < cautionRules[i].words.length; j++) {
        if (source.indexOf(cautionRules[i].words[j]) !== -1) {
          _uniquePush(cautions, cautionRules[i].label);
          break;
        }
      }
    }

    var allergenKeys = Object.keys(allergenSet);
    var allergenText = '';
    if (allergenKeys.length > 0) {
      var labels = allergenKeys.map(function (k) { return allergenLabels[k] || k; });
      allergenText = labels.join(', ');
    }
    return {
      allergenText: allergenText,
      cautionText: cautions.join(' / ')
    };
  }

  function _noticeHtml(order) {
    var memoHtml = order && order.memo ? '<div class="kds-order-memo">' + Utils.escapeHtml(order.memo) + '</div>' : '';
    var notice = _collectNotice(order);
    var allergenHtml = notice.allergenText
      ? '<div class="kds-order-allergen">\u26A0\uFE0F \u30A2\u30EC\u30EB\u30AE\u30FC: ' + Utils.escapeHtml(notice.allergenText) + '</div>'
      : '';
    var cautionHtml = notice.cautionText
      ? '<div class="kds-order-caution">\u26A0\uFE0F \u6307\u5B9A: ' + Utils.escapeHtml(notice.cautionText) + '</div>'
      : '';
    return memoHtml + allergenHtml + cautionHtml;
  }

  function _urgencyHtml(urgency) {
    if (!urgency || !urgency.reason) return '';
    return '<span class="kds-urgency-reason kds-urgency-reason--' + urgency.level + '">' + Utils.escapeHtml(urgency.reason) + '</span>';
  }

  function renderCard(order, status) {
    var urgency = _getUrgency(order);
    var elapsed = urgency.elapsed;
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
    if (urgency.level === 'danger') {
      cardClass += ' kds-card--danger';
    } else if (urgency.level === 'warning') {
      cardClass += ' kds-card--warning';
    } else if (urgency.level === 'prep') {
      cardClass += ' kds-card--prep';
    }
    var noticeHtml = _noticeHtml(order);

    return '<div class="' + cardClass + '" data-order-id="' + order.id + '"'
      + (order.course_id ? ' data-course-id="' + order.course_id + '" data-table-id="' + (order.table_id || '') + '"' : '')
      + '>'
      + '<div class="kds-card__header">'
      + '<span class="kds-card__table">' + typeBadge + courseBadge + Utils.escapeHtml(tableLabel) + '</span>'
      + '<span class="kds-card__time">' + timeStr + _urgencyHtml(urgency) + '</span>'
      + '</div>'
      + noticeHtml
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
          activeOrders.push({ order: o, hasReady: o.status === 'ready', urgency: _getUrgency(o) });
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
      activeOrders.push({ order: o, hasReady: hasReady, urgency: _getUrgency(o) });
    });

    // ソート: ready 持ちが先頭、続いて赤/黄、最後に経過時間が長い順
    activeOrders.sort(function (a, b) {
      if (a.hasReady !== b.hasReady) return a.hasReady ? -1 : 1;
      var pr = _priorityRank(b.urgency.level) - _priorityRank(a.urgency.level);
      if (pr !== 0) return pr;
      return b.urgency.elapsed - a.urgency.elapsed;
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
    var html = _renderExpeditorSummary(activeOrders);
    for (var k = 0; k < activeOrders.length; k++) {
      html += _renderExpeditorCard(activeOrders[k].order, activeOrders[k].hasReady, activeOrders[k].urgency);
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

  function _renderExpeditorSummary(activeOrders) {
    if (!activeOrders || activeOrders.length === 0) return '';
    var tableMap = {};
    var totalReady = 0;
    var totalUnfinished = 0;
    for (var i = 0; i < activeOrders.length; i++) {
      var order = activeOrders[i].order;
      var key = _orderLabel(order);
      if (!tableMap[key]) {
        tableMap[key] = { label: key, ready: 0, preparing: 0, pending: 0, total: 0, priority: 'normal' };
      }
      var summary = tableMap[key];
      if (_priorityRank(activeOrders[i].urgency.level) > _priorityRank(summary.priority)) {
        summary.priority = activeOrders[i].urgency.level;
      }
      var items = order.items || [];
      for (var j = 0; j < items.length; j++) {
        var s = items[j].status || 'pending';
        if (s === 'served' || s === 'cancelled') continue;
        summary.total++;
        if (s === 'ready') { summary.ready++; totalReady++; }
        else if (s === 'preparing') { summary.preparing++; totalUnfinished++; }
        else if (s === 'pending') { summary.pending++; totalUnfinished++; }
      }
    }

    var tables = Object.keys(tableMap).map(function (k) { return tableMap[k]; });
    tables.sort(function (a, b) {
      var pr = _priorityRank(b.priority) - _priorityRank(a.priority);
      if (pr !== 0) return pr;
      if (b.ready !== a.ready) return b.ready - a.ready;
      return b.total - a.total;
    });

    var html = '<div class="kds-expeditor-summary">';
    html += '<div class="kds-expeditor-summary__metric"><span>提供待ち</span><strong>' + totalReady + '</strong></div>';
    html += '<div class="kds-expeditor-summary__metric"><span>未完成品</span><strong>' + totalUnfinished + '</strong></div>';
    html += '<div class="kds-expeditor-summary__tables">';
    for (var t = 0; t < tables.length && t < 12; t++) {
      html += '<div class="kds-expeditor-summary__table kds-expeditor-summary__table--' + tables[t].priority + '">'
        + '<strong>' + Utils.escapeHtml(tables[t].label) + '</strong>'
        + '<span>残り' + tables[t].total + ' / 提供' + tables[t].ready + ' / 調理' + tables[t].preparing + ' / 受付' + tables[t].pending + '</span>'
        + '</div>';
    }
    html += '</div></div>';
    return html;
  }

  function _renderExpeditorCard(order, hasReady, urgency) {
    var elapsed = urgency.elapsed;
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
    if (urgency.level === 'danger') {
      cardClass += ' kds-card--danger';
    } else if (urgency.level === 'warning') {
      cardClass += ' kds-card--warning';
    } else if (urgency.level === 'prep') {
      cardClass += ' kds-card--prep';
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

    var noticeHtml = _noticeHtml(order);

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
      + '<span class="kds-card__time">' + timeStr + _urgencyHtml(urgency) + '</span>'
      + '</div>'
      + noticeHtml
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

  function getOperationalSummary() {
    var summary = {
      orders: 0,
      active: 0,
      pending: 0,
      preparing: 0,
      ready: 0,
      prep: 0,
      warning: 0,
      danger: 0,
      topLevel: 'normal',
      topReason: ''
    };

    Object.keys(_orders).forEach(function (id) {
      var order = _orders[id];
      var items = order.items || [];
      var activeInOrder = 0;
      if (items.length > 0) {
        for (var i = 0; i < items.length; i++) {
          var s = items[i].status || 'pending';
          if (s === 'served' || s === 'cancelled') continue;
          if (summary[s] !== undefined) summary[s]++;
          activeInOrder++;
        }
      } else {
        var os = order.status || 'pending';
        if (summary[os] !== undefined) {
          summary[os]++;
          activeInOrder++;
        }
      }
      if (activeInOrder === 0) return;
      summary.orders++;
      summary.active += activeInOrder;

      var urgency = _getUrgency(order);
      if (urgency.level === 'prep') summary.prep++;
      else if (urgency.level === 'warning') summary.warning++;
      else if (urgency.level === 'danger') summary.danger++;

      if (_priorityRank(urgency.level) > _priorityRank(summary.topLevel)) {
        summary.topLevel = urgency.level;
        summary.topReason = urgency.reason || '';
      }
    });

    return summary;
  }

  function _snapshotOrders(orderIds, newStatus, label) {
    var rows = [];
    for (var i = 0; i < orderIds.length; i++) {
      var orderId = orderIds[i];
      var o = _orders[orderId];
      if (!o) continue;
      var itemRows = [];
      var items = o.items || [];
      for (var j = 0; j < items.length; j++) {
        if (!items[j].item_id) continue;
        if ((items[j].status || 'pending') === 'cancelled') continue;
        itemRows.push({ itemId: items[j].item_id, status: items[j].status || 'pending' });
      }
      rows.push({
        orderId: orderId,
        status: o.status || 'pending',
        items: itemRows
      });
    }
    if (rows.length === 0) return null;
    return {
      label: label || (_orderLabel(_orders[orderIds[0]]) + ' → ' + _statusLabel(newStatus)),
      orders: rows
    };
  }

  function _snapshotItem(itemId, newStatus) {
    var row = null;
    Object.keys(_orders).forEach(function (orderId) {
      if (row) return;
      var o = _orders[orderId];
      var items = o.items || [];
      for (var i = 0; i < items.length; i++) {
        if (items[i].item_id === itemId) {
          row = {
            label: _orderLabel(o) + ' / ' + (items[i].name || '') + ' → ' + _statusLabel(newStatus),
            orders: [{
              orderId: orderId,
              status: o.status || 'pending',
              items: [{ itemId: itemId, status: items[i].status || 'pending' }]
            }]
          };
          break;
        }
      }
    });
    return row;
  }

  function _applyOrderStatusOptimistic(orderId, newStatus) {
    if (!_orders[orderId]) return;
    _orders[orderId].status = newStatus;
    var oiStatus = newStatus === 'paid' ? 'served' : newStatus;
    (_orders[orderId].items || []).forEach(function (item) {
      if ((item.status || 'pending') !== 'cancelled') {
        item.status = oiStatus;
      }
    });
  }

  function _patchOrderStatus(orderId, newStatus, storeId, reason) {
    return fetch('../../api/kds/update-status.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, status: newStatus, store_id: storeId, reason: reason || null }),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('empty response');
        try { var json = JSON.parse(body); }
        catch (e) { throw new Error('invalid JSON'); }
        if (!r.ok || !json.ok) throw ((window.Utils && Utils.createApiError) ? Utils.createApiError(json, 'update failed') : new Error((json.error && json.error.message) || 'update failed'));
        return json;
      });
    });
  }

  function _patchItemStatus(itemId, newStatus, storeId, reason) {
    return fetch('../../api/kds/update-item-status.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ item_id: itemId, status: newStatus, store_id: storeId, reason: reason || null }),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('empty response');
        try { var json = JSON.parse(body); }
        catch (e) { throw new Error('invalid JSON'); }
        if (!r.ok || !json.ok) throw ((window.Utils && Utils.createApiError) ? Utils.createApiError(json, 'update failed') : new Error((json.error && json.error.message) || 'update failed'));
        return json;
      });
    });
  }

  function handleAction(orderId, newStatus, storeId, reason) {
    // Phase 3: offline/stale ならローカル状態変更も API 呼び出しもせず、呼び出し側に
    // reject で通知する (voice-commander 等の .then() が成功音を鳴らさないように)
    if (_guardOfflineOrStale('handleAction')) {
      return Promise.reject(new Error('offline_or_stale'));
    }
    var undoSnapshot = _snapshotOrders([orderId], newStatus, null);
    _applyOrderStatusOptimistic(orderId, newStatus);
    render();

    return _patchOrderStatus(orderId, newStatus, storeId, reason)
    .then(function (json) {
      _startActionUndo(undoSnapshot);
      return json;
    })
    .catch(function (err) {
      // ロールバック — 次のポーリングで修正
      PollingDataSource.forceRefresh();
      throw err;
    });
  }

  function handleItemAction(itemId, newStatus, storeId) {
    // Phase 3: voice-commander からの直接呼び出しもここで止める。
    // reject で通知して呼び出し側の .then() が成功と誤認しないようにする。
    if (_guardOfflineOrStale('handleItemAction')) {
      return Promise.reject(new Error('offline_or_stale'));
    }
    var undoSnapshot = _snapshotItem(itemId, newStatus);

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

    return _patchItemStatus(itemId, newStatus, storeId)
    .then(function (json) {
      _startActionUndo(undoSnapshot);
      return json;
    })
    .catch(function (err) {
      PollingDataSource.forceRefresh();
      throw err;
    });
  }

  function handleOrderBatch(orderIds, newStatus, storeId, label, reason) {
    if (_guardOfflineOrStale('handleOrderBatch')) {
      return Promise.reject(new Error('offline_or_stale'));
    }
    var ids = orderIds || [];
    if (ids.length === 0) return Promise.reject(new Error('no_orders'));
    var undoSnapshot = _snapshotOrders(ids, newStatus, label);
    for (var i = 0; i < ids.length; i++) {
      _applyOrderStatusOptimistic(ids[i], newStatus);
    }
    render();
    var calls = [];
    for (var j = 0; j < ids.length; j++) {
      calls.push(_patchOrderStatus(ids[j], newStatus, storeId, reason || null));
    }
    return Promise.all(calls)
      .then(function () {
        _startActionUndo(undoSnapshot);
        return { ok: true };
      })
      .catch(function (err) {
        PollingDataSource.forceRefresh();
        throw err;
      });
  }

  function undoLastAction(storeId) {
    if (_guardOfflineOrStale('undoLastAction')) {
      return Promise.reject(new Error('offline_or_stale'));
    }
    if (!_actionUndo || Date.now() >= _actionUndo.expiresAt) {
      _clearActionUndo();
      return Promise.reject(new Error('undo_expired'));
    }
    var snapshot = _actionUndo;
    _clearActionUndo();
    var calls = [];
    for (var i = 0; i < snapshot.orders.length; i++) {
      var row = snapshot.orders[i];
      if (row.items && row.items.length > 0) {
        for (var j = 0; j < row.items.length; j++) {
          if (row.items[j].status === 'cancelled') continue;
          calls.push(_patchItemStatus(row.items[j].itemId, row.items[j].status, storeId, null));
        }
      } else {
        calls.push(_patchOrderStatus(row.orderId, row.status, storeId, null));
      }
    }
    if (calls.length === 0) return Promise.reject(new Error('nothing_to_undo'));
    return Promise.all(calls)
      .then(function () {
        PollingDataSource.forceRefresh();
        return { ok: true };
      })
      .catch(function (err) {
        PollingDataSource.forceRefresh();
        throw err;
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
    var rows = [];
    Object.keys(tables).forEach(function (key) {
      rows.push(tables[key]);
    });
    return rows;
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
    handleOrderBatch: handleOrderBatch,
    undoLastAction: undoLastAction,
    getOperationalSummary: getOperationalSummary,
    getCourseTableIds: getCourseTableIds,
    advancePhase: advancePhase
  };
})();
