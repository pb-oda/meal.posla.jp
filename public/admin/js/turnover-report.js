/**
 * 回転率・調理時間・客層分析レポート
 * M-2: 回転率・調理時間レポート強化
 * M-5: 客層・価格帯分析
 */
var TurnoverReport = (function () {
  'use strict';

  var _container = null;

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
      '<div class="report-presets" id="turnover-presets">'
      + '<button class="btn btn-outline active" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline" data-preset="month">今月</button></div>'
      + '<div class="report-date-range">'
      + '<input type="date" id="turnover-from" value="' + from + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="turnover-to" value="' + to + '">'
      + '<button class="btn btn-sm btn-primary" id="btn-turnover-load">表示</button></div>'
      + '<div id="turnover-data"></div>';

    document.getElementById('turnover-presets').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var r = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('turnover-from').value = r.from;
      document.getElementById('turnover-to').value = r.to;
      this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      fetchData(r.from, r.to);
    });

    document.getElementById('btn-turnover-load').addEventListener('click', function () {
      fetchData(
        document.getElementById('turnover-from').value,
        document.getElementById('turnover-to').value
      );
    });
  }

  function fetchData(from, to) {
    var dataEl = document.getElementById('turnover-data');
    if (!dataEl) return;
    dataEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getTurnoverReport(from, to).then(function (res) {
      renderReport(dataEl, res);
    }).catch(function (err) {
      dataEl.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  /* ========== レンダリング ========== */

  function renderReport(el, data) {
    var html = '';
    html += renderTopSummary(data);
    html += renderTurnoverTable(data.turnover);
    html += renderMealPeriods(data.mealPeriods);
    html += renderHourlyEfficiency(data.hourlyEfficiency);
    html += renderCookingAnalysis(data.cooking);
    html += renderGuestGroups(data.customer);
    html += renderPriceBands(data.customer);
    html += renderHourlyCustomers(data.customer);
    el.innerHTML = html;
  }

  // --- 1. トップサマリーカード ---
  function renderTopSummary(data) {
    var t = (data.turnover && data.turnover.summary) || {};
    var c = data.cooking || {};
    var cs = c.summary || {};
    var peakLabel = data.peakHour !== null ? data.peakHour + '時' : '-';
    var overRate = cs.overTargetRate !== undefined ? cs.overTargetRate : null;
    var overStyle = (overRate !== null && overRate >= 20) ? ' style="color:#e53935"' : '';

    return '<div class="report-section"><h3 class="report-section__title">サマリー</h3>'
      + '<div class="report-summary">'
      + '<div class="summary-card"><div class="summary-card__label">平均滞在</div><div class="summary-card__value">' + (data.overallAvgStay || 0) + '分</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">平均回転</div><div class="summary-card__value">' + (t.avgTurnover || 0) + '回</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">ピーク時間帯</div><div class="summary-card__value">' + peakLabel + '</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">調理超過率</div><div class="summary-card__value"' + overStyle + '>' + (overRate !== null ? overRate + '%' : '-') + '</div></div>'
      + '</div></div>';
  }

  // --- 2. テーブル別回転率（テーブル形式） ---
  function renderTurnoverTable(turnover) {
    if (!turnover) return '';
    var byTable = turnover.byTable || [];
    if (byTable.length === 0) return '';

    var html = '<div class="report-section"><h3 class="report-section__title">テーブル別回転率・滞在時間</h3>'
      + '<div class="card"><table class="report-table"><thead><tr>'
      + '<th>テーブル</th><th>回転数</th><th>平均滞在</th><th>合計客数</th>'
      + '</tr></thead><tbody>';

    byTable.forEach(function (t) {
      html += '<tr>'
        + '<td>' + Utils.escapeHtml(t.tableCode) + '</td>'
        + '<td>' + t.turnCount + '回</td>'
        + '<td>' + (t.avgStay || 0) + '分</td>'
        + '<td>' + (t.totalGuests || 0) + '人</td>'
        + '</tr>';
    });

    html += '</tbody></table></div></div>';
    return html;
  }

  // --- 3. ランチ・ディナー比較 ---
  function renderMealPeriods(mealPeriods) {
    if (!mealPeriods || mealPeriods.length === 0) return '';
    var html = '<div class="report-section"><h3 class="report-section__title">ランチ / ディナー比較</h3>'
      + '<div class="card"><table class="report-table"><thead><tr>'
      + '<th>時間帯</th><th>回転数</th><th>客数</th><th>平均滞在</th><th>売上</th>'
      + '</tr></thead><tbody>';

    mealPeriods.forEach(function (mp) {
      html += '<tr>'
        + '<td>' + Utils.escapeHtml(mp.label) + '</td>'
        + '<td>' + mp.sessions + '組</td>'
        + '<td>' + mp.guests + '人</td>'
        + '<td>' + mp.avgStay + '分</td>'
        + '<td>' + Utils.formatYen(mp.revenue) + '</td>'
        + '</tr>';
    });

    html += '</tbody></table></div></div>';
    return html;
  }

  // --- 4. 時間帯別効率（棒グラフ） ---
  function renderHourlyEfficiency(hourly) {
    if (!hourly || hourly.length === 0) return '';
    var maxSessions = Math.max.apply(null, hourly.map(function (h) { return h.sessions || 0; }));
    var html = '<div class="report-section"><h3 class="report-section__title">時間帯別効率</h3>'
      + '<div class="card"><ul class="bar-chart">';

    hourly.forEach(function (h) {
      var pct = maxSessions > 0 ? Math.round((h.sessions || 0) / maxSessions * 100) : 0;
      html += '<li class="bar-row">'
        + '<span class="bar-row__label">' + h.hour + '時</span>'
        + '<span class="bar-row__track"><span class="bar-row__fill" style="width:' + pct + '%"></span></span>'
        + '<span class="bar-row__value">' + (h.sessions || 0) + '組 / ' + (h.guests || 0) + '人 / ' + Utils.formatYen(h.revenue || 0) + '</span>'
        + '</li>';
    });

    html += '</ul></div></div>';
    return html;
  }

  // --- 5. 調理時間分析 ---
  function renderCookingAnalysis(cooking) {
    if (!cooking) return '';
    var html = '<div class="report-section"><h3 class="report-section__title">調理時間分析</h3>';

    var s = cooking.summary;
    if (s) {
      var overStyle = (s.overTargetRate >= 20) ? ' style="color:#e53935"' : '';
      html += '<div class="report-summary">'
        + '<div class="summary-card"><div class="summary-card__label">平均</div><div class="summary-card__value">' + formatSec(s.avg) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">中央値</div><div class="summary-card__value">' + formatSec(s.median) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">目標</div><div class="summary-card__value">' + formatSec(s.targetSec) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">超過率</div><div class="summary-card__value"' + overStyle + '>' + s.overTargetRate + '%</div></div>'
        + '</div>';
    }

    // 品目別
    var byItem = cooking.byItem || [];
    if (byItem.length > 0) {
      var targetSec = (s && s.targetSec) ? s.targetSec : 900;
      var maxAvg = Math.max.apply(null, byItem.map(function (i) { return i.avg; }));
      html += '<div class="card"><ul class="bar-chart">';
      byItem.forEach(function (item) {
        var pct = maxAvg > 0 ? Math.round(item.avg / maxAvg * 100) : 0;
        var warn = item.avg > targetSec ? ' bar-row__fill--warning' : '';
        html += '<li class="bar-row">'
          + '<span class="bar-row__label">' + Utils.escapeHtml(item.name) + '</span>'
          + '<span class="bar-row__track"><span class="bar-row__fill' + warn + '" style="width:' + pct + '%"></span></span>'
          + '<span class="bar-row__value">平均' + formatSec(item.avg) + ' / 最大' + formatSec(item.max) + ' (' + item.count + '品)</span>'
          + '</li>';
      });
      html += '</ul></div>';
    }

    html += '</div>';
    return html;
  }

  // --- 6. 組人数別分析（テーブル形式） ---
  function renderGuestGroups(customer) {
    if (!customer) return '';
    var groups = customer.guestGroups || [];

    var html = '<div class="report-section"><h3 class="report-section__title">組人数別分析</h3>'
      + '<div class="report-summary">'
      + '<div class="summary-card"><div class="summary-card__label">総組数</div><div class="summary-card__value">' + (customer.totalGroups || 0) + '組</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">総客数</div><div class="summary-card__value">' + (customer.totalGuests || 0) + '人</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">客単価(注文)</div><div class="summary-card__value">' + Utils.formatYen(customer.avgPerOrder) + '</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">客単価(人)</div><div class="summary-card__value">' + Utils.formatYen(customer.avgPerGuest) + '</div></div>'
      + '</div>';

    if (groups.length > 0) {
      html += '<div class="card"><table class="report-table"><thead><tr>'
        + '<th>人数帯</th><th>組数</th><th>客数</th><th>組平均単価</th><th>客単価</th>'
        + '</tr></thead><tbody>';

      groups.forEach(function (g) {
        html += '<tr>'
          + '<td>' + Utils.escapeHtml(g.guestLabel) + '</td>'
          + '<td>' + g.sessionCount + '組</td>'
          + '<td>' + g.totalGuests + '人</td>'
          + '<td>' + Utils.formatYen(g.avgSpendGroup) + '</td>'
          + '<td>' + Utils.formatYen(g.avgSpendPerson) + '</td>'
          + '</tr>';
      });

      html += '</tbody></table></div>';
    }

    html += '</div>';
    return html;
  }

  // --- 7. 価格帯別分布（棒グラフ 3本） ---
  function renderPriceBands(customer) {
    if (!customer || !customer.priceBands) return '';
    var bands = customer.priceBands;
    var maxCount = Math.max.apply(null, bands.map(function (b) { return b.orderCount; }));
    if (maxCount === 0) return '';

    var html = '<div class="report-section"><h3 class="report-section__title">価格帯別注文分布</h3>'
      + '<div class="card"><ul class="bar-chart">';

    bands.forEach(function (b) {
      var pct = maxCount > 0 ? Math.round(b.orderCount / maxCount * 100) : 0;
      html += '<li class="bar-row">'
        + '<span class="bar-row__label">' + Utils.escapeHtml(b.label) + '</span>'
        + '<span class="bar-row__track"><span class="bar-row__fill" style="width:' + pct + '%"></span></span>'
        + '<span class="bar-row__value">' + b.orderCount + '件 / ' + Utils.formatYen(b.totalRevenue) + '</span>'
        + '</li>';
    });

    html += '</ul></div></div>';
    return html;
  }

  // --- 8. 時間帯別客層（棒グラフ） ---
  function renderHourlyCustomers(customer) {
    if (!customer || !customer.hourlyCustomers || customer.hourlyCustomers.length === 0) return '';
    var hourly = customer.hourlyCustomers;
    var maxGuests = Math.max.apply(null, hourly.map(function (h) { return h.totalGuests; }));
    if (maxGuests === 0) return '';

    var html = '<div class="report-section"><h3 class="report-section__title">時間帯別客層</h3>'
      + '<div class="card"><ul class="bar-chart">';

    hourly.forEach(function (h) {
      var pct = maxGuests > 0 ? Math.round(h.totalGuests / maxGuests * 100) : 0;
      html += '<li class="bar-row">'
        + '<span class="bar-row__label">' + h.hour + '時</span>'
        + '<span class="bar-row__track"><span class="bar-row__fill" style="width:' + pct + '%"></span></span>'
        + '<span class="bar-row__value">' + h.sessionCount + '組 / ' + h.totalGuests + '人 / 平均' + h.avgGuestCount + '名 / ' + Utils.formatYen(h.avgSpend) + '</span>'
        + '</li>';
    });

    html += '</ul></div></div>';
    return html;
  }

  /* ========== ヘルパー ========== */

  function formatSec(sec) {
    if (!sec || sec <= 0) return '0秒';
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    if (m > 0) return m + '分' + (s > 0 ? s + '秒' : '');
    return s + '秒';
  }

  return { init: init, load: load };
})();
