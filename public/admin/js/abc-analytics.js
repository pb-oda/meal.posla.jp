/**
 * ABC分析・売上ダッシュボード（owner専用）
 *
 * サマリーカード + ABC分析テーブル + カテゴリ集計
 * CSS-onlyバーチャート（外部ライブラリ不使用）
 */
var AbcAnalytics = (function () {
  'use strict';

  var _container = null;
  var _data = null;

  function init(container) {
    _container = container;
  }

  function load() {
    renderControls();
    var range = Utils.getPresetRange('month');
    document.getElementById('abc-from').value = range.from;
    document.getElementById('abc-to').value = range.to;
    fetchData(range.from, range.to);
  }

  function renderControls() {
    // owner は「全店舗合算」選択可、manager は自店舗のみ
    var isOwner = document.body.classList.contains('role-owner');
    var storeFilterHtml = '';
    if (isOwner) {
      storeFilterHtml = '<label class="abc-store-filter"><span style="font-size:0.8125rem;margin-right:0.25rem">店舗:</span>'
        + '<select class="form-input" id="abc-store-filter" style="width:auto;display:inline-block;font-size:0.8125rem">'
        + '<option value="">全店舗合算</option></select></label>';
    }

    _container.innerHTML =
      '<div class="report-presets" id="abc-presets">'
      + '<button class="btn btn-outline" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline active" data-preset="month">今月</button></div>'
      + '<div class="report-date-range">'
      + storeFilterHtml
      + '<input type="date" id="abc-from">'
      + '<span class="report-date-range__sep">\u301C</span>'
      + '<input type="date" id="abc-to">'
      + '<button class="btn btn-sm btn-primary" id="btn-abc-load">表示</button></div>'
      + '<div id="abc-data"></div>';

    // owner: 店舗セレクタに選択肢追加（StoreSelector から取得）
    var storeSel = document.getElementById('abc-store-filter');
    if (storeSel) {
      var stores = StoreSelector.getStores();
      stores.forEach(function (s) {
        var o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.name;
        storeSel.appendChild(o);
      });
    }

    document.getElementById('abc-presets').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var range = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('abc-from').value = range.from;
      document.getElementById('abc-to').value = range.to;
      this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      fetchData(range.from, range.to);
    });

    document.getElementById('btn-abc-load').addEventListener('click', function () {
      fetchData(document.getElementById('abc-from').value, document.getElementById('abc-to').value);
    });

    if (storeSel) {
      storeSel.addEventListener('change', function () {
        fetchData(document.getElementById('abc-from').value, document.getElementById('abc-to').value);
      });
    }
  }

  function getSelectedStore() {
    // owner: ABC専用セレクタから取得
    var abcSel = document.getElementById('abc-store-filter');
    if (abcSel) return abcSel.value;
    // manager: 管理画面のメイン店舗セレクタから取得
    return AdminApi.getCurrentStore() || '';
  }

  function fetchData(from, to) {
    var dataEl = document.getElementById('abc-data');
    if (!dataEl) return;
    dataEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    var storeId = getSelectedStore();
    AdminApi.getAbcAnalytics(from, to, storeId).then(function (res) {
      _data = res;
      renderDashboard(dataEl, res);
    }).catch(function (err) {
      dataEl.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function renderDashboard(el, data) {
    var s = data.summary || {};
    var html = '';

    // ── サマリーカード ──
    html += '<div class="abc-summary">'
      + summaryCard('総売上', Utils.formatYen(s.totalRevenue), 'abc-card--revenue')
      + summaryCard('総粗利', Utils.formatYen(Math.round(s.grossProfit)) + ' <span class="abc-card__sub">(' + s.grossProfitRate + '%)</span>', 'abc-card--profit')
      + summaryCard('注文単価', Utils.formatYen(s.avgOrderValue), 'abc-card--avg')
      + summaryCard('注文件数', (s.orderCount || 0) + '件', 'abc-card--count')
      + summaryCard('品目数', (s.uniqueItems || 0) + '品', 'abc-card--items')
      + summaryCard('総原価', Utils.formatYen(Math.round(s.totalCost)), 'abc-card--cost')
      + '</div>';

    // ── ランク別サマリー ──
    var rs = data.rankSummary || {};
    html += '<div class="abc-rank-summary">';
    ['A', 'B', 'C'].forEach(function (rank) {
      var d = rs[rank] || { count: 0, revenue: 0, profit: 0 };
      var cls = 'abc-rank-card abc-rank-card--' + rank.toLowerCase();
      html += '<div class="' + cls + '">'
        + '<div class="abc-rank-card__rank">' + rank + 'ランク</div>'
        + '<div class="abc-rank-card__detail">'
        + '<span>' + d.count + '品</span>'
        + '<span>売上 ' + Utils.formatYen(d.revenue) + '</span>'
        + '<span>粗利 ' + Utils.formatYen(Math.round(d.profit)) + '</span>'
        + '</div></div>';
    });
    html += '</div>';

    // ── ABC分析テーブル ──
    var items = data.items || [];
    if (items.length > 0) {
      var maxRevenue = items[0].revenue || 1;

      html += '<div class="report-section">'
        + '<h3 class="report-section__title">ABC分析（売上順）</h3>'
        + '<div class="abc-table-wrap"><table class="abc-table">'
        + '<thead><tr>'
        + '<th class="abc-th--rank">ランク</th>'
        + '<th class="abc-th--no">#</th>'
        + '<th class="abc-th--name">品目名</th>'
        + '<th class="abc-th--cat">カテゴリ</th>'
        + '<th class="abc-th--num">数量</th>'
        + '<th class="abc-th--num">売上</th>'
        + '<th class="abc-th--bar">構成比</th>'
        + '<th class="abc-th--num">累積</th>'
        + '<th class="abc-th--num">原価</th>'
        + '<th class="abc-th--num">粗利</th>'
        + '<th class="abc-th--num">利益率</th>'
        + '</tr></thead><tbody>';

      items.forEach(function (it, i) {
        var barPct = maxRevenue > 0 ? Math.round(it.revenue / maxRevenue * 100) : 0;
        var rowCls = 'abc-row abc-row--' + it.rank.toLowerCase();
        var badgeCls = 'abc-badge abc-badge--' + it.rank.toLowerCase();
        var profitCls = it.profit < 0 ? 'abc-negative' : '';

        html += '<tr class="' + rowCls + '">'
          + '<td><span class="' + badgeCls + '">' + it.rank + '</span></td>'
          + '<td class="abc-td--no">' + (i + 1) + '</td>'
          + '<td class="abc-td--name">' + Utils.escapeHtml(it.name) + '</td>'
          + '<td class="abc-td--cat">' + Utils.escapeHtml(it.category) + '</td>'
          + '<td class="abc-td--num">' + it.qty + '</td>'
          + '<td class="abc-td--num abc-td--revenue">' + Utils.formatYen(it.revenue) + '</td>'
          + '<td class="abc-td--bar">'
          + '<div class="abc-bar-track">'
          + '<div class="abc-bar-fill abc-bar-fill--' + it.rank.toLowerCase() + '" style="width:' + barPct + '%"></div>'
          + '</div>'
          + '<span class="abc-bar-label">' + it.revenuePct + '%</span>'
          + '</td>'
          + '<td class="abc-td--num">' + it.cumulativePct + '%</td>'
          + '<td class="abc-td--num">' + Utils.formatYen(Math.round(it.cost)) + '</td>'
          + '<td class="abc-td--num ' + profitCls + '">' + Utils.formatYen(Math.round(it.profit)) + '</td>'
          + '<td class="abc-td--num">' + it.profitRate + '%</td>'
          + '</tr>';
      });

      html += '</tbody></table></div></div>';
    } else {
      html += '<div class="empty-state" style="margin-top:1rem"><p class="empty-state__text">該当期間のデータがありません</p></div>';
    }

    // ── カテゴリ別集計 ──
    var cats = data.categories || [];
    if (cats.length > 0) {
      var maxCatRev = cats[0].revenue || 1;
      html += '<div class="report-section">'
        + '<h3 class="report-section__title">カテゴリ別集計</h3>'
        + '<div class="abc-cat-grid">';

      cats.forEach(function (cat) {
        var barPct = maxCatRev > 0 ? Math.round(cat.revenue / maxCatRev * 100) : 0;
        var profitRate = cat.revenue > 0 ? Math.round(cat.profit / cat.revenue * 100) : 0;
        html += '<div class="abc-cat-card">'
          + '<div class="abc-cat-card__header">'
          + '<span class="abc-cat-card__name">' + Utils.escapeHtml(cat.name) + '</span>'
          + '<span class="abc-cat-card__count">' + cat.itemCount + '品 / ' + cat.qty + '個</span>'
          + '</div>'
          + '<div class="abc-cat-card__bar-track">'
          + '<div class="abc-cat-card__bar-fill" style="width:' + barPct + '%"></div>'
          + '</div>'
          + '<div class="abc-cat-card__nums">'
          + '<span>売上 ' + Utils.formatYen(cat.revenue) + '</span>'
          + '<span>粗利 ' + Utils.formatYen(Math.round(cat.profit)) + ' (' + profitRate + '%)</span>'
          + '</div></div>';
      });

      html += '</div></div>';
    }

    el.innerHTML = html;
  }

  function summaryCard(label, value, cls) {
    return '<div class="abc-card ' + (cls || '') + '">'
      + '<div class="abc-card__label">' + label + '</div>'
      + '<div class="abc-card__value">' + value + '</div>'
      + '</div>';
  }

  return { init: init, load: load };
})();
