/**
 * 監査ログビューア（S-2 Phase 2）
 * ES5 IIFE パターン
 */
var AuditLogViewer = (function () {
  'use strict';

  var _container = null;
  var _currentPage = 0;
  var _pageSize = 50;
  var _total = 0;
  var _users = [];

  var ACTION_LABELS = {
    menu_update:          'メニュー変更',
    menu_soldout:         '品切れ変更',
    menu_create:          '限定メニュー作成',
    menu_delete:          '限定メニュー削除',
    staff_create:         'スタッフ作成',
    staff_update:         'スタッフ変更',
    staff_delete:         'スタッフ削除',
    device_create:        'デバイス作成',
    device_update:        'デバイス変更',
    device_delete:        'デバイス削除',
    order_cancel:         '注文キャンセル',
    order_status:         '注文ステータス',
    item_status:          '品目ステータス',
    payment_complete:     '会計完了',
    payment_refund:       '返金',
    receipt_reprint:      '領収書再印刷',
    cashier_pin_used:     'レジ担当 PIN 使用',
    cashier_pin_failed:   'レジ担当 PIN 失敗',
    cashier_pin_set:      'レジ担当 PIN 設定',
    register_open:        'レジ開け',
    register_close:       'レジ締め',
    cash_in:              '現金入金',
    cash_out:             '現金出金',
    attendance_clock_in:  '出勤打刻',
    attendance_clock_out: '退勤打刻',
    settings_update:      '設定変更',
    login:                'ログイン',
    logout:               'ログアウト'
  };

  var ENTITY_LABELS = {
    menu_item:      'メニュー',
    user:           'ユーザー',
    order:          '注文',
    order_item:     '注文品目',
    payment:        '会計',
    receipt:        '領収書',
    cash_log:       'レジ金庫',
    attendance:     '勤怠',
    store_settings: '店舗設定',
    ingredient:     '原材料',
    store:          '店舗'
  };

  function init(container) {
    _container = container;
  }

  function load() {
    if (!_container) return;
    _currentPage = 0;
    renderShell();
    fetchLogs();
  }

  function renderShell() {
    var today = new Date().toISOString().slice(0, 10);
    var html = '<div class="audit-filters">'
      + '<label class="audit-filters__label">期間: <input type="date" id="audit-from" class="audit-filters__input" value="' + today + '"></label>'
      + '<label class="audit-filters__label">〜 <input type="date" id="audit-to" class="audit-filters__input" value="' + today + '"></label>'
      + '<label class="audit-filters__label">操作: <select id="audit-action" class="audit-filters__select"><option value="">すべて</option></select></label>'
      + '<label class="audit-filters__label">スタッフ: <select id="audit-user" class="audit-filters__select"><option value="">すべて</option></select></label>'
      + '<button class="btn btn-sm btn-primary audit-filters__button" id="audit-search">検索</button>'
      + '</div>'
      + '<div id="audit-table-wrap"></div>'
      + '<div id="audit-pager" class="audit-pager"></div>';
    _container.innerHTML = html;

    // 操作種別ドロップダウン
    var actionSel = _container.querySelector('#audit-action');
    var keys = Object.keys(ACTION_LABELS);
    for (var i = 0; i < keys.length; i++) {
      actionSel.innerHTML += '<option value="' + keys[i] + '">' + ACTION_LABELS[keys[i]] + '</option>';
    }

    // 検索ボタン
    _container.querySelector('#audit-search').addEventListener('click', function () {
      _currentPage = 0;
      fetchLogs();
    });
  }

  function fetchLogs() {
    var storeId = StoreSelector.getSelectedStoreId();
    if (!storeId) return;

    var params = 'store_id=' + encodeURIComponent(storeId);
    params += '&limit=' + _pageSize;
    params += '&offset=' + (_currentPage * _pageSize);

    var fromEl = _container.querySelector('#audit-from');
    var toEl   = _container.querySelector('#audit-to');
    var actEl  = _container.querySelector('#audit-action');
    var usrEl  = _container.querySelector('#audit-user');

    if (fromEl && fromEl.value) params += '&from=' + encodeURIComponent(fromEl.value);
    if (toEl && toEl.value)     params += '&to=' + encodeURIComponent(toEl.value);
    if (actEl && actEl.value)   params += '&action=' + encodeURIComponent(actEl.value);
    if (usrEl && usrEl.value)   params += '&user_id=' + encodeURIComponent(usrEl.value);

    var wrap = _container.querySelector('#audit-table-wrap');
    wrap.innerHTML = '<p class="audit-state-loading">読み込み中...</p>';

    fetch('../../api/store/audit-log.php?' + params, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (body) {
        try { var json = JSON.parse(body); } catch (e) { throw new Error('JSON parse error'); }
        if (!json.ok) throw new Error((json.error && json.error.message) || 'API error');
        _total = json.data.total || 0;
        _users = json.data.users || [];
        updateUserDropdown();
        renderTable(json.data.logs || []);
        renderPager();
      })
      .catch(function (err) {
        wrap.innerHTML = '<p class="audit-state-error">エラー: ' + Utils.escapeHtml(err.message) + '</p>';
      });
  }

  function updateUserDropdown() {
    var sel = _container.querySelector('#audit-user');
    if (!sel) return;
    var current = sel.value;
    var html = '<option value="">すべて</option>';
    for (var i = 0; i < _users.length; i++) {
      var u = _users[i];
      var selected = (u.user_id === current) ? ' selected' : '';
      html += '<option value="' + Utils.escapeHtml(u.user_id) + '"' + selected + '>'
            + Utils.escapeHtml(u.username || u.user_id) + '</option>';
    }
    sel.innerHTML = html;
  }

  function renderTable(logs) {
    var wrap = _container.querySelector('#audit-table-wrap');
    if (logs.length === 0) {
      wrap.innerHTML = '<p class="audit-state-empty audit-state-empty--large">該当するログはありません</p>';
      return;
    }

    var html = '<table class="audit-table">'
      + '<thead><tr>'
      + '<th>日時</th>'
      + '<th>スタッフ</th>'
      + '<th>操作</th>'
      + '<th>対象</th>'
      + '<th>詳細</th>'
      + '</tr></thead><tbody>';

    for (var i = 0; i < logs.length; i++) {
      var log = logs[i];
      var time = (log.created_at || '').replace('T', ' ').slice(0, 19);
      var actionLabel = ACTION_LABELS[log.action] || log.action;
      var entityLabel = ENTITY_LABELS[log.entity_type] || log.entity_type;
      var detail = formatDetail(log);

      // 2026-04-21 hotfix: PIN 検証で特定された担当スタッフ (cashier_user_name) があれば
      //   ログイン端末名と並記して「kds-test (担当: test1)」のように表示する。
      //   レジ系操作では大半が device アカウントログインなので、誰が PIN 入れたかを見える化する。
      var staffCell = Utils.escapeHtml(log.username || '-');
      var pinName = null;
      if (log.new_value) {
        try {
          var nv = (typeof log.new_value === 'string') ? JSON.parse(log.new_value) : log.new_value;
          if (nv && nv.cashier_user_name) pinName = String(nv.cashier_user_name);
        } catch (e) { /* JSON 不正は無視 */ }
      }
      if (pinName && pinName !== (log.username || '')) {
        staffCell += ' <span class="audit-table__pin-name">(担当: ' + Utils.escapeHtml(pinName) + ')</span>';
      }

      html += '<tr>'
        + '<td class="audit-table__time">' + Utils.escapeHtml(time) + '</td>'
        + '<td>' + staffCell + '</td>'
        + '<td>' + Utils.escapeHtml(actionLabel) + '</td>'
        + '<td>' + Utils.escapeHtml(entityLabel)
        + (log.entity_id ? ' <span class="audit-table__entity-id">' + Utils.escapeHtml(log.entity_id.slice(0, 8)) + '</span>' : '')
        + '</td>'
        + '<td class="audit-table__detail">' + detail + '</td>'
        + '</tr>';
    }

    html += '</tbody></table>';
    wrap.innerHTML = html;
  }

  // ── 詳細フィールド整形 (CB1) ──

  // よく出るキーの日本語ラベル
  var FIELD_LABELS = {
    total: '合計',
    amount: '金額',
    payment_method: '支払方法',
    is_partial: '個別会計',
    order_count: '注文数',
    pin_verified: 'PIN認証',
    cashier_user_name: '担当',
    cashier_user_id: '担当ID',
    refund_id: '返金ID',
    gateway: 'ゲートウェイ',
    reason: '理由',
    type: '種別',
    note: 'メモ',
    role: '権限',
    role_hint: 'ホール/キッチン',
    is_active: '有効',
    is_sold_out: '品切れ',
    is_hidden: '非表示',
    name: '名称',
    name_en: '英語名',
    price: '価格',
    base_price: '基本価格',
    description: '説明',
    display_name: '表示名',
    username: 'ユーザー名',
    email: 'メール',
    visible_tools: '使用ツール',
    pin_length: 'PIN桁数',
    context: '文脈',
    clock_in: '出勤',
    clock_out: '退勤',
    shift_assignment_id: 'シフトID',
    status: '状態',
    new_status: '新しい状態',
    old_status: '元の状態',
    item_name: '品目',
    table_id: 'テーブル',
    table_name: 'テーブル名',
    is_own: '自分のPIN',
    target_username: '対象ユーザー',
    target_display_name: '対象表示名',
    quantity: '数量',
    qty: '数量',
    discount: '割引',
    tax: '税額',
    subtotal: '小計'
  };

  // 列挙値の日本語表記
  var ENUM_LABELS = {
    payment_method: { cash: '現金', card: 'クレカ', qr: 'QR' },
    type: { open: 'レジ開け', close: 'レジ締め', cash_in: '入金', cash_out: '出金', cash_sale: '現金売上' },
    role: { owner: 'オーナー', manager: 'マネージャー', staff: 'スタッフ', device: '端末' },
    // 注文・品目ステータス
    status: {
      pending:    '受付',
      preparing:  '調理中',
      ready:      '提供待ち',
      served:     '提供済',
      paid:       '会計済',
      cancelled:  'キャンセル',
      open:       '開店',
      closed:     '閉店',
      working:    '勤務中',
      completed:  '完了',
      absent:     '欠勤',
      late:       '遅刻'
    },
    new_status: {
      pending: '受付', preparing: '調理中', ready: '提供待ち', served: '提供済',
      paid: '会計済', cancelled: 'キャンセル'
    },
    old_status: {
      pending: '受付', preparing: '調理中', ready: '提供待ち', served: '提供済',
      paid: '会計済', cancelled: 'キャンセル'
    }
  };

  // 金額系キー (¥xxx 表示)
  var YEN_KEYS = { total: 1, amount: 1, price: 1, base_price: 1 };
  // boolean っぽいキー (0/1, true/false → ✓/×)
  var BOOL_KEYS = { is_partial: 1, is_active: 1, is_sold_out: 1, is_hidden: 1, pin_verified: 1 };
  // ID 系キー (短縮表示)
  var ID_KEYS = { cashier_user_id: 1, refund_id: 1, shift_assignment_id: 1, table_id: 1 };
  // 表示しないキー (ノイズ)
  var SKIP_KEYS = { tenant_id: 1 };

  function _labelFor(key) { return FIELD_LABELS[key] || key; }

  function _formatValue(key, value) {
    if (value === null || value === undefined || value === '') return '-';
    if (SKIP_KEYS[key]) return null;
    if (BOOL_KEYS[key]) {
      var b = (value === 1 || value === true || value === '1' || value === 'true');
      return b
        ? '<span class="audit-kv-chip__bool--true">✓</span>'
        : '<span class="audit-kv-chip__bool--false">×</span>';
    }
    if (YEN_KEYS[key]) {
      var n = parseInt(value, 10);
      if (!isNaN(n)) return '¥' + n.toLocaleString();
    }
    if (ENUM_LABELS[key] && ENUM_LABELS[key][value] !== undefined) {
      return Utils.escapeHtml(ENUM_LABELS[key][value]);
    }
    if (ID_KEYS[key]) {
      var s = String(value);
      return '<span class="audit-kv-chip__id" title="' + Utils.escapeHtml(s) + '">' + Utils.escapeHtml(s.slice(0, 8)) + '…</span>';
    }
    return Utils.escapeHtml(String(value));
  }

  function _renderKV(key, value) {
    var formatted = _formatValue(key, value);
    if (formatted === null) return '';
    return '<span class="audit-kv-chip">'
      + '<span class="audit-kv-chip__label">' + Utils.escapeHtml(_labelFor(key)) + ':</span> '
      + '<strong>' + formatted + '</strong>'
      + '</span>';
  }

  function _renderDiff(key, oldVal, newVal) {
    var of = _formatValue(key, oldVal);
    var nf = _formatValue(key, newVal);
    if (of === null || nf === null) return '';
    return '<span class="audit-kv-chip audit-kv-chip--diff">'
      + '<span class="audit-kv-chip__label">' + Utils.escapeHtml(_labelFor(key)) + ':</span> '
      + '<span class="audit-kv-chip__old">' + of + '</span> → '
      + '<strong>' + nf + '</strong>'
      + '</span>';
  }

  function formatDetail(log) {
    var oldVal = null;
    var newVal = null;
    try { if (log.old_value) oldVal = JSON.parse(log.old_value); } catch (e) {}
    try { if (log.new_value) newVal = JSON.parse(log.new_value); } catch (e) {}

    if (!oldVal && !newVal) {
      return log.reason ? Utils.escapeHtml(log.reason) : '-';
    }

    var parts = [];

    if (oldVal && newVal) {
      // 差分のみ表示
      var keys = Object.keys(newVal);
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        var ov = (oldVal[k] !== undefined) ? oldVal[k] : null;
        var nv = newVal[k];
        if (String(ov) !== String(nv)) {
          parts.push(_renderDiff(k, ov, nv));
        }
      }
      if (parts.length === 0) parts.push('<span class="audit-kv-chip__no-change">変更なし</span>');
    } else {
      var src = newVal || oldVal;
      var sKeys = Object.keys(src);
      for (var j = 0; j < sKeys.length; j++) {
        parts.push(_renderKV(sKeys[j], src[sKeys[j]]));
      }
    }

    var result = parts.join('');
    if (log.reason && (!newVal || !newVal.reason)) {
      result += '<span class="audit-reason">理由: ' + Utils.escapeHtml(log.reason) + '</span>';
    }
    return result || '-';
  }

  function renderPager() {
    var pager = _container.querySelector('#audit-pager');
    if (!pager) return;
    var totalPages = Math.ceil(_total / _pageSize);
    if (totalPages <= 1) { pager.innerHTML = ''; return; }

    var html = '';
    if (_currentPage > 0) {
      html += '<button class="btn btn-sm btn-outline" data-audit-page="' + (_currentPage - 1) + '">← 前</button>';
    }
    html += '<span class="audit-pager__status">'
      + (_currentPage + 1) + ' / ' + totalPages + ' (' + _total + '件)</span>';
    if (_currentPage < totalPages - 1) {
      html += '<button class="btn btn-sm btn-outline" data-audit-page="' + (_currentPage + 1) + '">次 →</button>';
    }
    pager.innerHTML = html;

    pager.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-audit-page]');
      if (!btn) return;
      _currentPage = parseInt(btn.dataset.auditPage, 10);
      fetchLogs();
    });
  }

  return { init: init, load: load };
})();
