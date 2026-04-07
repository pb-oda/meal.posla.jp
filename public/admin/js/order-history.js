/**
 * 注文履歴（ページネーション付き）
 */
var OrderHistory = (function () {
  'use strict';

  var _container = null;
  var _currentPage = 1;

  function init(container) { _container = container; }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }

    var range = Utils.getPresetRange('today');
    _container.innerHTML =
      '<div class="report-presets" id="oh-presets">'
      + '<button class="btn btn-outline active" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline" data-preset="month">今月</button></div>'
      + '<div class="report-date-range">'
      + '<input type="date" id="oh-from" value="' + range.from + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="oh-to" value="' + range.to + '">'
      + '<select class="filter-select" id="oh-status"><option value="">全ステータス</option>'
      + '<option value="pending">受付</option><option value="preparing">調理中</option>'
      + '<option value="ready">提供待ち</option><option value="served">提供済み</option>'
      + '<option value="paid">会計済み</option>'
      + '<option value="cancelled">キャンセル</option></select>'
      + '<input type="text" class="filter-input" id="oh-search" placeholder="メニュー名で検索">'
      + '<button class="btn btn-sm btn-primary" id="btn-oh-load">表示</button></div>'
      + '<div id="oh-data"></div>';

    document.getElementById('oh-presets').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var r = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('oh-from').value = r.from;
      document.getElementById('oh-to').value = r.to;
      this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      _currentPage = 1;
      fetchData();
    });

    document.getElementById('btn-oh-load').addEventListener('click', function () {
      _currentPage = 1;
      fetchData();
    });

    _currentPage = 1;
    fetchData();
  }

  function fetchData() {
    var el = document.getElementById('oh-data');
    if (!el) return;
    el.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getOrderHistory({
      from: document.getElementById('oh-from').value,
      to: document.getElementById('oh-to').value,
      status: document.getElementById('oh-status').value,
      q: document.getElementById('oh-search').value.trim(),
      page: _currentPage,
      limit: 20
    }).then(function (res) {
      renderData(el, res);
    }).catch(function (err) {
      el.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function renderData(el, data) {
    var orders = data.orders || [];
    var pg = data.pagination || {};

    if (orders.length === 0) {
      el.innerHTML = '<div class="empty-state"><p class="empty-state__text">注文がありません</p></div>';
      return;
    }

    var statusLabels = {
      pending: '受付', preparing: '調理中', ready: '提供待ち', served: '提供済み', paid: '会計済み', cancelled: 'キャンセル'
    };

    var html = '<div class="card"><div class="data-table-wrap"><table class="data-table">'
      + '<thead><tr><th>時刻</th><th>テーブル</th><th>品目</th><th class="text-right">金額</th><th>提供時間</th><th>ステータス</th></tr></thead><tbody>';

    orders.forEach(function (o) {
      var itemNames = (o.items || []).map(function (i) { return i.name + '×' + i.qty; }).join(', ');
      var removedNames = (o.removed_items || []).map(function (i) { return i.name; }).join(', ');
      var serviceTime = o.service_time !== null ? Utils.formatDuration(o.service_time) : '-';

      html += '<tr>'
        + '<td>' + Utils.formatDateTime(o.created_at) + '</td>'
        + '<td>' + Utils.escapeHtml(o.table_code || '-') + '</td>'
        + '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + Utils.escapeHtml(itemNames)
        + (removedNames ? '<br><span style="color:#c62828;font-size:0.75rem;text-decoration:line-through">' + Utils.escapeHtml(removedNames) + '</span>' : '') + '</td>'
        + '<td class="text-right">' + Utils.formatYen(o.total_amount) + '</td>'
        + '<td>' + serviceTime + '</td>'
        + '<td><span class="badge badge--' + (o.status === 'paid' ? 'available' : (o.status === 'cancelled' ? 'cancelled' : 'sold-out')) + '">' + (statusLabels[o.status] || o.status) + '</span></td>'
        + '</tr>';
    });

    html += '</tbody></table></div></div>';

    // ページネーション
    if (pg.totalPages > 1) {
      html += '<div class="pagination">'
        + '<button class="pagination__btn" id="oh-prev"' + (pg.page <= 1 ? ' disabled' : '') + '>&lt; 前へ</button>'
        + '<span class="pagination__info">' + pg.page + ' / ' + pg.totalPages + ' (' + pg.total + '件)</span>'
        + '<button class="pagination__btn" id="oh-next"' + (pg.page >= pg.totalPages ? ' disabled' : '') + '>次へ &gt;</button>'
        + '</div>';
    }

    el.innerHTML = html;

    // ページネーションイベント
    var prevBtn = document.getElementById('oh-prev');
    var nextBtn = document.getElementById('oh-next');
    if (prevBtn) prevBtn.addEventListener('click', function () { _currentPage--; fetchData(); });
    if (nextBtn) nextBtn.addEventListener('click', function () { _currentPage++; fetchData(); });
  }

  return { init: init, load: load };
})();
