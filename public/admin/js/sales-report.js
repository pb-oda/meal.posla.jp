/**
 * 売上レポート
 */
var SalesReport = (function () {
  'use strict';

  var _container = null;
  var _loaded = false;

  function init(container) {
    _container = container;
  }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';

    var range = Utils.getPresetRange('today');
    renderControls(range.from, range.to);
    fetchData(range.from, range.to);
  }

  function renderControls(from, to) {
    _container.innerHTML =
      '<div class="report-presets" id="sales-presets">'
      + '<button class="btn btn-outline active" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline" data-preset="month">今月</button></div>'
      + '<div class="report-date-range">'
      + '<input type="date" id="sales-from" value="' + from + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="sales-to" value="' + to + '">'
      + '<button class="btn btn-sm btn-primary" id="btn-sales-load">表示</button></div>'
      + '<div id="sales-data"></div>';

    document.getElementById('sales-presets').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var range = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('sales-from').value = range.from;
      document.getElementById('sales-to').value = range.to;
      this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      fetchData(range.from, range.to);
    });

    document.getElementById('btn-sales-load').addEventListener('click', function () {
      fetchData(document.getElementById('sales-from').value, document.getElementById('sales-to').value);
    });
  }

  function fetchData(from, to) {
    var dataEl = document.getElementById('sales-data');
    if (!dataEl) return;
    dataEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getSalesReport(from, to).then(function (res) {
      renderReport(dataEl, res);
    }).catch(function (err) {
      dataEl.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function renderReport(el, data) {
    var s = data.summary || {};
    var html = '<div class="report-summary">'
      + '<div class="summary-card"><div class="summary-card__label">注文数</div><div class="summary-card__value">' + (s.orderCount || 0) + '</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">売上合計</div><div class="summary-card__value">' + Utils.formatYen(s.totalRevenue) + '</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">平均注文額</div><div class="summary-card__value">' + Utils.formatYen(s.avgOrderValue) + '</div></div>'
      + '</div>';

    // 提供時間分析
    if (data.serviceTimeAnalysis) {
      var st = data.serviceTimeAnalysis;
      html += '<div class="report-section"><h3 class="report-section__title">提供時間分析</h3>'
        + '<div class="report-summary">'
        + '<div class="summary-card"><div class="summary-card__label">平均</div><div class="summary-card__value">' + Utils.formatDuration(st.avg) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">中央値</div><div class="summary-card__value">' + Utils.formatDuration(st.median) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">最短</div><div class="summary-card__value">' + Utils.formatDuration(st.min) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">最長</div><div class="summary-card__value">' + Utils.formatDuration(st.max) + '</div></div>'
        + '</div></div>';
    }

    // 品目ランキング
    var ranking = data.itemRanking || [];
    if (ranking.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">品目ランキング</h3><div class="card"><ul class="ranking-list">';
      var medals = ['🥇', '🥈', '🥉'];
      ranking.forEach(function (r, i) {
        html += '<li class="ranking-item"><span class="ranking-item__rank">' + (medals[i] || (i + 1)) + '</span>'
          + '<span class="ranking-item__name">' + Utils.escapeHtml(r.name) + '</span>'
          + '<span class="ranking-item__qty">' + r.qty + '個</span>'
          + '<span class="ranking-item__revenue">' + Utils.formatYen(r.revenue) + '</span></li>';
      });
      html += '</ul></div></div>';
    }

    // 時間帯別
    var hourly = data.hourly || [];
    if (hourly.length > 0) {
      var maxRev = Math.max.apply(null, hourly.map(function (h) { return h.revenue; }));
      html += '<div class="report-section"><h3 class="report-section__title">時間帯別売上</h3><div class="card"><ul class="bar-chart">';
      hourly.forEach(function (h) {
        var pct = maxRev > 0 ? Math.round(h.revenue / maxRev * 100) : 0;
        html += '<li class="bar-row"><span class="bar-row__label">' + h.hour + '時</span>'
          + '<span class="bar-row__track"><span class="bar-row__fill" style="width:' + pct + '%"></span></span>'
          + '<span class="bar-row__value">' + h.count + '件 / ' + Utils.formatYen(h.revenue) + '</span></li>';
      });
      html += '</ul></div></div>';
    }

    el.innerHTML = html;
  }

  return { init: init, load: load };
})();
