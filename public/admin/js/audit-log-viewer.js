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
    menu_update:     'メニュー変更',
    menu_soldout:    '品切れ変更',
    menu_create:     '限定メニュー作成',
    menu_delete:     '限定メニュー削除',
    staff_create:    'スタッフ作成',
    staff_update:    'スタッフ変更',
    staff_delete:    'スタッフ削除',
    order_cancel:    '注文キャンセル',
    order_status:    '注文ステータス',
    item_status:     '品目ステータス',
    settings_update: '設定変更',
    login:           'ログイン',
    logout:          'ログアウト'
  };

  var ENTITY_LABELS = {
    menu_item:      'メニュー',
    user:           'ユーザー',
    order:          '注文',
    order_item:     '注文品目',
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
    var html = '<div class="audit-filters" style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;align-items:flex-end;">'
      + '<label style="font-size:0.85rem;">期間: <input type="date" id="audit-from" style="padding:0.3rem;" value="' + today + '"></label>'
      + '<label style="font-size:0.85rem;">〜 <input type="date" id="audit-to" style="padding:0.3rem;" value="' + today + '"></label>'
      + '<label style="font-size:0.85rem;">操作: <select id="audit-action" style="padding:0.3rem;"><option value="">すべて</option></select></label>'
      + '<label style="font-size:0.85rem;">スタッフ: <select id="audit-user" style="padding:0.3rem;"><option value="">すべて</option></select></label>'
      + '<button class="btn btn-sm btn-primary" id="audit-search" style="padding:0.3rem 0.75rem;">検索</button>'
      + '</div>'
      + '<div id="audit-table-wrap"></div>'
      + '<div id="audit-pager" style="display:flex;gap:0.5rem;justify-content:center;margin-top:0.75rem;"></div>';
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
    wrap.innerHTML = '<p style="text-align:center;color:#888;padding:1rem;">読み込み中...</p>';

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
        wrap.innerHTML = '<p style="color:red;padding:1rem;">エラー: ' + Utils.escapeHtml(err.message) + '</p>';
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
      wrap.innerHTML = '<p style="text-align:center;color:#888;padding:2rem;">該当するログはありません</p>';
      return;
    }

    var html = '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;">'
      + '<thead><tr style="background:#f5f5f5;border-bottom:2px solid #ddd;">'
      + '<th style="padding:0.5rem;text-align:left;">日時</th>'
      + '<th style="padding:0.5rem;text-align:left;">スタッフ</th>'
      + '<th style="padding:0.5rem;text-align:left;">操作</th>'
      + '<th style="padding:0.5rem;text-align:left;">対象</th>'
      + '<th style="padding:0.5rem;text-align:left;">詳細</th>'
      + '</tr></thead><tbody>';

    for (var i = 0; i < logs.length; i++) {
      var log = logs[i];
      var time = (log.created_at || '').replace('T', ' ').slice(0, 19);
      var actionLabel = ACTION_LABELS[log.action] || log.action;
      var entityLabel = ENTITY_LABELS[log.entity_type] || log.entity_type;
      var detail = formatDetail(log);

      html += '<tr style="border-bottom:1px solid #eee;">'
        + '<td style="padding:0.4rem 0.5rem;white-space:nowrap;">' + Utils.escapeHtml(time) + '</td>'
        + '<td style="padding:0.4rem 0.5rem;">' + Utils.escapeHtml(log.username || '-') + '</td>'
        + '<td style="padding:0.4rem 0.5rem;">' + Utils.escapeHtml(actionLabel) + '</td>'
        + '<td style="padding:0.4rem 0.5rem;">' + Utils.escapeHtml(entityLabel)
        + (log.entity_id ? ' <span style="color:#888;font-size:0.75rem;">' + Utils.escapeHtml(log.entity_id.slice(0, 8)) + '</span>' : '')
        + '</td>'
        + '<td style="padding:0.4rem 0.5rem;font-size:0.8rem;">' + detail + '</td>'
        + '</tr>';
    }

    html += '</tbody></table>';
    wrap.innerHTML = html;
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
      // 変更差分を表示
      var keys = Object.keys(newVal);
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        var ov = oldVal[k] !== undefined ? String(oldVal[k]) : '-';
        var nv = String(newVal[k]);
        if (ov !== nv) {
          parts.push(Utils.escapeHtml(k) + ': ' + Utils.escapeHtml(ov) + ' → ' + Utils.escapeHtml(nv));
        }
      }
    } else if (newVal) {
      var nKeys = Object.keys(newVal);
      for (var j = 0; j < nKeys.length; j++) {
        parts.push(Utils.escapeHtml(nKeys[j]) + ': ' + Utils.escapeHtml(String(newVal[nKeys[j]])));
      }
    } else if (oldVal) {
      var oKeys = Object.keys(oldVal);
      for (var m = 0; m < oKeys.length; m++) {
        parts.push(Utils.escapeHtml(oKeys[m]) + ': ' + Utils.escapeHtml(String(oldVal[oKeys[m]])));
      }
    }

    var result = parts.join(', ');
    if (log.reason) result += ' (' + Utils.escapeHtml(log.reason) + ')';
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
    html += '<span style="padding:0.3rem 0.5rem;font-size:0.85rem;">'
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
