/**
 * エラー監視ビューア (Phase B / CB1)
 * ES5 IIFE パターン
 *
 * 過去 N 時間の error_log を集計表示。
 * テーブル: トップ10 (頻度順)
 * サマリー: 件数 / カテゴリ別 / HTTP ステータス別
 */
var ErrorStatsViewer = (function () {
  'use strict';

  var _container = null;
  var _storeId   = null;
  var _hours     = 24;

  var CATEGORY_LABELS = {
    E1: 'システム',
    E2: '入力検証',
    E3: '認証・認可',
    E4: '未発見',
    E5: '注文・KDS',
    E6: '決済・返金',
    E7: 'メニュー・在庫',
    E8: 'シフト・勤怠',
    E9: '顧客・予約・AI'
  };

  // メッセージ内の DB / API フィールド名を運用者向け日本語に置換する辞書
  var FIELD_LABELS = {
    'menu_template_id': 'メニュー',
    'menu_item_id': 'メニュー',
    'plan_id':     'プラン',
    'course_id':   'コース',
    'option_id':   'オプション',
    'category_id': 'カテゴリ',
    'store_id':    '店舗',
    'tenant_id':   'アカウント',
    'table_id':    'テーブル',
    'user_id':     'ユーザー',
    'staff_id':    'スタッフ',
    'payment_id':  '会計',
    'order_id':    '注文',
    'order_item_id': '注文品目',
    'reservation_id': '予約',
    'session_token': 'セッション',
    'session_id':  'セッション',
    'sub_session_id': 'サブセッション',
    'shift_assignment_id': 'シフト',
    'ingredient_id': '原材料',
    'recipe_id':   'レシピ',
    'start_date':  '開始日',
    'end_date':    '終了日',
    'received_amount': '預かり金',
    'idempotency_key': '送信キー',
    'staff_pin':   'PIN',
    'cashier_pin': 'PIN',
    'idempotencyKey': '送信キー'
  };

  function _humanize(msg) {
    if (!msg) return '';
    var s = String(msg);
    // 長い key から先に置換 (menu_template_id を menu_item_id 置換より先に)
    var keys = Object.keys(FIELD_LABELS).sort(function (a, b) { return b.length - a.length; });
    for (var i = 0; i < keys.length; i++) {
      // 単語境界 (英数字_でない) で囲まれた完全一致のみ置換
      var k = keys[i];
      var re = new RegExp('(^|[^A-Za-z0-9_])' + k + '(?![A-Za-z0-9_])', 'g');
      s = s.replace(re, '$1' + FIELD_LABELS[k]);
    }
    return s;
  }

  function init(container, storeId) {
    _container = container;
    _storeId   = storeId;
  }

  function load(storeId) {
    if (storeId) _storeId = storeId;
    if (!_container) return;
    renderShell();
    fetchStats();
  }

  function renderShell() {
    var html = '<div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">'
      + '<label style="font-size:0.85rem;">期間: '
      + '<select id="error-stats-hours" style="padding:0.3rem;">'
      +   '<option value="1">直近 1 時間</option>'
      +   '<option value="6">直近 6 時間</option>'
      +   '<option value="24" selected>直近 24 時間</option>'
      +   '<option value="168">直近 7 日間</option>'
      + '</select></label>'
      + '<button id="error-stats-refresh" class="btn btn--small">再読込</button>'
      + '<span id="error-stats-meta" style="font-size:0.8rem;color:#666;margin-left:auto;"></span>'
      + '</div>'
      + '<div id="error-stats-summary" style="margin-bottom:1.5rem;"></div>'
      + '<h3 style="font-size:1rem;margin:0 0 0.5rem;">頻発エラー トップ10</h3>'
      + '<div id="error-stats-table"></div>'
      + '<p style="font-size:0.75rem;color:#999;margin-top:1rem;">'
      + '※ エラー番号 (Exxxx) をクリックすると、別タブで「エラーカタログ」が開きます。AI アシスタントに「<code>E2017 とは？</code>」と質問することもできます。'
      + '</p>';
    _container.innerHTML = html;

    var sel = _container.querySelector('#error-stats-hours');
    if (sel) sel.addEventListener('change', function () { _hours = parseInt(sel.value, 10) || 24; fetchStats(); });
    var btn = _container.querySelector('#error-stats-refresh');
    if (btn) btn.addEventListener('click', fetchStats);
  }

  function fetchStats() {
    if (!_storeId) {
      _container.querySelector('#error-stats-summary').innerHTML = '<p style="color:#999;">店舗が選択されていません。</p>';
      return;
    }
    var url = '../../api/store/error-stats.php?store_id=' + encodeURIComponent(_storeId) + '&hours=' + _hours;
    var meta = _container.querySelector('#error-stats-meta');
    if (meta) meta.textContent = '読み込み中…';

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { return { ok: false, error: { message: t.substring(0, 100) } }; }
      }); })
      .then(function (json) {
        if (!json.ok) {
          _container.querySelector('#error-stats-summary').innerHTML = '<p style="color:#d9534f;">読み込みエラー: ' + Utils.escapeHtml((json.error && json.error.message) || 'unknown') + '</p>';
          return;
        }
        renderResults(json.data);
      })
      .catch(function (err) {
        _container.querySelector('#error-stats-summary').innerHTML = '<p style="color:#d9534f;">通信エラー: ' + Utils.escapeHtml(err.message || '') + '</p>';
      });
  }

  function renderResults(data) {
    var meta = _container.querySelector('#error-stats-meta');
    if (meta) meta.textContent = '更新: ' + new Date().toLocaleTimeString('ja-JP');

    if (data.unmigrated) {
      _container.querySelector('#error-stats-summary').innerHTML = '<p style="color:#999;">エラーログテーブルが未作成です。POSLA運営にお問い合わせください。</p>';
      _container.querySelector('#error-stats-table').innerHTML = '';
      return;
    }

    var summaryHtml = '';
    summaryHtml += '<div style="display:flex;gap:1rem;flex-wrap:wrap;">';
    summaryHtml += '<div style="flex:0 0 auto;background:#f8f9fa;padding:0.75rem 1rem;border-radius:6px;">'
      + '<div style="font-size:0.75rem;color:#666;">総エラー件数</div>'
      + '<div style="font-size:1.5rem;font-weight:bold;color:' + (data.total > 0 ? '#d9534f' : '#28a745') + ';">' + data.total + '</div>'
      + '</div>';

    // カテゴリ別
    var catKeys = Object.keys(data.by_category || {}).sort();
    if (catKeys.length > 0) {
      summaryHtml += '<div style="flex:1 1 auto;background:#f8f9fa;padding:0.75rem 1rem;border-radius:6px;min-width:280px;">'
        + '<div style="font-size:0.75rem;color:#666;margin-bottom:0.4rem;">カテゴリ別</div>';
      for (var i = 0; i < catKeys.length; i++) {
        var k = catKeys[i];
        var label = CATEGORY_LABELS[k] || k;
        summaryHtml += '<span style="display:inline-block;margin:0.15rem 0.5rem 0.15rem 0;padding:0.1rem 0.4rem;border:1px solid #ddd;border-radius:3px;font-size:0.8rem;">'
          + '<code style="color:#666;">' + Utils.escapeHtml(k) + 'xxx</code> '
          + Utils.escapeHtml(label) + ': <strong>' + data.by_category[k] + '</strong>'
          + '</span>';
      }
      summaryHtml += '</div>';
    }

    // HTTP ステータス別
    var statusKeys = Object.keys(data.by_status || {}).sort();
    if (statusKeys.length > 0) {
      summaryHtml += '<div style="flex:1 1 auto;background:#f8f9fa;padding:0.75rem 1rem;border-radius:6px;min-width:280px;">'
        + '<div style="font-size:0.75rem;color:#666;margin-bottom:0.4rem;">HTTP ステータス別</div>';
      for (var j = 0; j < statusKeys.length; j++) {
        var s = statusKeys[j];
        var color = (parseInt(s, 10) >= 500) ? '#d9534f' : (parseInt(s, 10) >= 400 ? '#f0ad4e' : '#666');
        summaryHtml += '<span style="display:inline-block;margin:0.15rem 0.5rem 0.15rem 0;padding:0.1rem 0.4rem;border:1px solid #ddd;border-radius:3px;font-size:0.8rem;">'
          + '<strong style="color:' + color + ';">' + Utils.escapeHtml(s) + '</strong>: ' + data.by_status[s]
          + '</span>';
      }
      summaryHtml += '</div>';
    }
    summaryHtml += '</div>';

    _container.querySelector('#error-stats-summary').innerHTML = summaryHtml;

    // テーブル
    var top = data.top || [];
    if (top.length === 0) {
      _container.querySelector('#error-stats-table').innerHTML = '<p style="color:#999;padding:1rem;background:#f8f9fa;border-radius:6px;">この期間にエラーは発生していません。</p>';
      return;
    }

    var tableHtml = '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;">'
      + '<thead><tr style="background:#f0f0f0;text-align:left;">'
      + '<th style="padding:0.4rem 0.5rem;width:80px;">番号</th>'
      + '<th style="padding:0.4rem 0.5rem;width:120px;">コード</th>'
      + '<th style="padding:0.4rem 0.5rem;width:60px;text-align:center;">HTTP</th>'
      + '<th style="padding:0.4rem 0.5rem;">メッセージ</th>'
      + '<th style="padding:0.4rem 0.5rem;width:80px;text-align:right;">件数</th>'
      + '<th style="padding:0.4rem 0.5rem;width:130px;">最終発生</th>'
      + '</tr></thead><tbody>';

    var catalogBase = '../docs-tenant/tenant/99-error-catalog.html';
    for (var k = 0; k < top.length; k++) {
      var t = top[k];
      var statusColor = (t.http_status >= 500) ? '#d9534f' : (t.http_status >= 400 ? '#f0ad4e' : '#666');
      var noLink = t.errorNo
        ? '<a href="' + catalogBase + '#' + encodeURIComponent(String(t.errorNo).toLowerCase()) + '" target="_blank" style="font-family:monospace;font-weight:bold;">' + Utils.escapeHtml(t.errorNo) + '</a>'
        : '<span style="color:#999;">未採番</span>';
      tableHtml += '<tr style="border-bottom:1px solid #eee;">'
        + '<td style="padding:0.4rem 0.5rem;">' + noLink + '</td>'
        + '<td style="padding:0.4rem 0.5rem;font-family:monospace;font-size:0.8rem;">' + Utils.escapeHtml(t.code) + '</td>'
        + '<td style="padding:0.4rem 0.5rem;text-align:center;color:' + statusColor + ';font-weight:bold;">' + t.http_status + '</td>'
        + '<td style="padding:0.4rem 0.5rem;color:#555;">' + Utils.escapeHtml(_humanize(t.message || '')) + '</td>'
        + '<td style="padding:0.4rem 0.5rem;text-align:right;font-weight:bold;color:' + (t.count >= 10 ? '#d9534f' : '#333') + ';">' + t.count + '</td>'
        + '<td style="padding:0.4rem 0.5rem;font-size:0.75rem;color:#888;">' + Utils.escapeHtml((t.last_seen || '').replace('T', ' ').substring(0, 16)) + '</td>'
        + '</tr>';
    }
    tableHtml += '</tbody></table>';
    _container.querySelector('#error-stats-table').innerHTML = tableHtml;
  }

  return {
    init: init,
    load: load,
  };
})();
