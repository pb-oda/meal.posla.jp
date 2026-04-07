/**
 * 全店舗横断レポート（owner用）
 */
var CrossStoreReport = (function () {
  'use strict';

  var _container = null;

  function init(container) { _container = container; }

  function load() {
    var range = Utils.getPresetRange('today');

    // owner は店舗フィルタ選択可
    var storeFilterHtml = '';
    if (document.body.classList.contains('role-owner')) {
      storeFilterHtml = '<label class="abc-store-filter"><span style="font-size:0.8125rem;margin-right:0.25rem">店舗:</span>'
        + '<select class="form-input" id="cs-store-filter" style="width:auto;display:inline-block;font-size:0.8125rem">'
        + '<option value="">全店舗合算</option></select></label>';
    }

    _container.innerHTML =
      '<div class="report-presets" id="cs-presets">'
      + '<button class="btn btn-outline active" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline" data-preset="month">今月</button></div>'
      + '<div class="report-date-range">'
      + storeFilterHtml
      + '<input type="date" id="cs-from" value="' + range.from + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="cs-to" value="' + range.to + '">'
      + '<button class="btn btn-sm btn-primary" id="btn-cs-load">表示</button></div>'
      + '<div id="cs-data"></div>';

    // 店舗セレクタに選択肢追加
    var storeSel = document.getElementById('cs-store-filter');
    if (storeSel) {
      var stores = StoreSelector.getStores();
      stores.forEach(function (s) {
        var o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.name;
        storeSel.appendChild(o);
      });
      storeSel.addEventListener('change', function () {
        fetchData(document.getElementById('cs-from').value, document.getElementById('cs-to').value);
      });
    }

    document.getElementById('cs-presets').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var r = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('cs-from').value = r.from;
      document.getElementById('cs-to').value = r.to;
      this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      fetchData(r.from, r.to);
    });

    document.getElementById('btn-cs-load').addEventListener('click', function () {
      fetchData(document.getElementById('cs-from').value, document.getElementById('cs-to').value);
    });

    fetchData(range.from, range.to);
  }

  function getSelectedStore() {
    var sel = document.getElementById('cs-store-filter');
    return sel ? sel.value : '';
  }

  function fetchData(from, to) {
    var el = document.getElementById('cs-data');
    if (!el) return;
    el.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    var storeId = getSelectedStore();
    AdminApi.getCrossStoreReport(from, to, storeId).then(function (res) {
      renderReport(el, res);
    }).catch(function (err) {
      el.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function renderReport(el, data) {
    var s = data.summary || {};
    var cancelRate = (s.totalOrders + (s.cancelCount || 0)) > 0
      ? Math.round((s.cancelCount || 0) / (s.totalOrders + (s.cancelCount || 0)) * 100) : 0;
    var html = '<div class="report-summary">'
      + '<div class="summary-card"><div class="summary-card__label">売上合計</div><div class="summary-card__value">' + Utils.formatYen(s.totalRevenue) + '</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">注文合計</div><div class="summary-card__value">' + s.totalOrders + '</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">店舗数</div><div class="summary-card__value">' + s.storeCount + '</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">平均注文額</div><div class="summary-card__value">' + Utils.formatYen(s.avgOrderValue) + '</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">キャンセル</div><div class="summary-card__value">' + (s.cancelCount || 0) + '件 <span style="font-size:0.75rem;color:#e53935">(' + cancelRate + '%)</span></div></div>'
      + '<div class="summary-card"><div class="summary-card__label">キャンセル額</div><div class="summary-card__value" style="color:#e53935">' + Utils.formatYen(s.cancelAmount || 0) + '</div></div>'
      + '</div>';

    // 店舗別
    var stores = data.stores || [];
    if (stores.length > 0) {
      var maxRev = Math.max.apply(null, stores.map(function (st) { return st.revenue; }));
      html += '<div class="report-section"><h3 class="report-section__title">店舗別売上</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table">'
        + '<thead><tr><th>店舗</th><th class="text-right">注文数</th><th class="text-right">売上</th><th class="text-right">平均注文額</th><th class="text-right">キャンセル</th><th>比較</th></tr></thead><tbody>';

      stores.forEach(function (st) {
        var pct = maxRev > 0 ? Math.round(st.revenue / maxRev * 100) : 0;
        var stCancelRate = (st.orderCount + (st.cancelCount || 0)) > 0
          ? Math.round((st.cancelCount || 0) / (st.orderCount + (st.cancelCount || 0)) * 100) : 0;
        html += '<tr><td><strong>' + Utils.escapeHtml(st.storeName) + '</strong></td>'
          + '<td class="text-right">' + st.orderCount + '</td>'
          + '<td class="text-right">' + Utils.formatYen(st.revenue) + '</td>'
          + '<td class="text-right">' + Utils.formatYen(st.avgOrderValue) + '</td>'
          + '<td class="text-right">' + (st.cancelCount || 0) + '件'
          + (stCancelRate > 0 ? ' <span style="color:#e53935;font-size:0.8em">(' + stCancelRate + '%)</span>' : '')
          + '</td>'
          + '<td><div style="background:#e8eaf6;border-radius:4px;height:20px;overflow:hidden">'
          + '<div style="background:#3f51b5;height:100%;width:' + pct + '%"></div></div></td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

    // 提供時間分析
    var ts = data.timingSummary || {};
    if (ts.sampleCount > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">提供時間分析</h3>';

      // 全体サマリーカード
      html += '<div class="report-summary" style="margin-bottom:1rem">'
        + '<div class="summary-card"><div class="summary-card__label">平均提供時間</div><div class="summary-card__value">' + formatSec(ts.avgTotal) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">受付〜調理開始</div><div class="summary-card__value">' + formatSec(ts.avgWait) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">調理時間</div><div class="summary-card__value">' + formatSec(ts.avgCook) + '</div></div>'
        + '<div class="summary-card"><div class="summary-card__label">完成〜提供</div><div class="summary-card__value">' + formatSec(ts.avgDeliver) + '</div></div>'
        + '</div>';

      // 店舗別提供時間テーブル
      var timingStores = stores.filter(function (st) { return st.timing && st.timing.sampleCount > 0; });
      if (timingStores.length > 0) {
        html += '<div class="card"><div class="data-table-wrap"><table class="data-table">'
          + '<thead><tr>'
          + '<th>店舗</th>'
          + '<th class="text-right">計測数</th>'
          + '<th class="text-right">平均提供</th>'
          + '<th class="text-right">受付〜調理</th>'
          + '<th class="text-right">調理時間</th>'
          + '<th class="text-right">完成〜提供</th>'
          + '<th class="text-right">最短</th>'
          + '<th class="text-right">最長</th>'
          + '<th>分布</th>'
          + '</tr></thead><tbody>';

        var maxAvg = Math.max.apply(null, timingStores.map(function (st) { return st.timing.avgTotal; }));
        timingStores.forEach(function (st) {
          var t = st.timing;
          var barTotal = maxAvg > 0 ? Math.round(t.avgTotal / maxAvg * 100) : 0;
          var waitPct = t.avgTotal > 0 ? Math.round(t.avgWait / t.avgTotal * 100) : 0;
          var cookPct = t.avgTotal > 0 ? Math.round(t.avgCook / t.avgTotal * 100) : 0;
          var deliverPct = 100 - waitPct - cookPct;

          html += '<tr>'
            + '<td><strong>' + Utils.escapeHtml(st.storeName) + '</strong></td>'
            + '<td class="text-right">' + t.sampleCount + '</td>'
            + '<td class="text-right"><strong>' + formatSec(t.avgTotal) + '</strong></td>'
            + '<td class="text-right">' + formatSec(t.avgWait) + '</td>'
            + '<td class="text-right">' + formatSec(t.avgCook) + '</td>'
            + '<td class="text-right">' + formatSec(t.avgDeliver) + '</td>'
            + '<td class="text-right">' + formatSec(t.minTotal) + '</td>'
            + '<td class="text-right">' + formatSec(t.maxTotal) + '</td>'
            + '<td><div style="display:flex;height:20px;border-radius:4px;overflow:hidden;width:100%;min-width:80px">'
            + '<div style="background:#ff9800;width:' + waitPct + '%" title="受付〜調理"></div>'
            + '<div style="background:#f44336;width:' + cookPct + '%" title="調理"></div>'
            + '<div style="background:#4caf50;width:' + deliverPct + '%" title="提供"></div>'
            + '</div></td>'
            + '</tr>';
        });
        html += '</tbody></table></div></div>';

        // 凡例
        html += '<div style="display:flex;gap:1rem;margin-top:0.5rem;font-size:0.75rem;color:#666">'
          + '<span><span style="display:inline-block;width:12px;height:12px;background:#ff9800;border-radius:2px;vertical-align:middle;margin-right:2px"></span>受付〜調理開始</span>'
          + '<span><span style="display:inline-block;width:12px;height:12px;background:#f44336;border-radius:2px;vertical-align:middle;margin-right:2px"></span>調理時間</span>'
          + '<span><span style="display:inline-block;width:12px;height:12px;background:#4caf50;border-radius:2px;vertical-align:middle;margin-right:2px"></span>完成〜提供</span>'
          + '</div>';
      }

      html += '</div>';
    }

    // 全店舗品目ランキング
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

    // キャンセル品目ランキング
    var cancelRanking = data.cancelItemRanking || [];
    if (cancelRanking.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">キャンセルされた品目</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table">'
        + '<thead><tr><th>#</th><th>品目名</th><th class="text-right">キャンセル数</th><th class="text-right">機会損失額</th></tr></thead><tbody>';

      cancelRanking.forEach(function (r, i) {
        html += '<tr>'
          + '<td>' + (i + 1) + '</td>'
          + '<td>' + Utils.escapeHtml(r.name) + '</td>'
          + '<td class="text-right">' + r.qty + '個</td>'
          + '<td class="text-right" style="color:#e53935">' + Utils.formatYen(r.lostRevenue) + '</td>'
          + '</tr>';
      });

      html += '</tbody></table></div></div></div>';
    }

    // 迷った品目ランキング
    var hesitation = data.hesitationRanking || [];
    if (hesitation.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">迷った品目（カート追加→削除）</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table">'
        + '<thead><tr><th>#</th><th>品目名</th><th class="text-right">カート追加</th><th class="text-right">カート削除</th><th class="text-right">迷い率</th></tr></thead><tbody>';

      hesitation.forEach(function (r, i) {
        var rateColor = r.hesitationRate >= 50 ? '#e53935' : (r.hesitationRate >= 30 ? '#ff9800' : '#333');
        html += '<tr>'
          + '<td>' + (i + 1) + '</td>'
          + '<td>' + Utils.escapeHtml(r.name) + '</td>'
          + '<td class="text-right">' + r.addCount + '回</td>'
          + '<td class="text-right">' + r.removeCount + '回</td>'
          + '<td class="text-right" style="color:' + rateColor + ';font-weight:700">' + r.hesitationRate + '%</td>'
          + '</tr>';
      });

      html += '</tbody></table></div></div></div>';
    }

    el.innerHTML = html;
  }

  function formatSec(sec) {
    if (!sec || sec <= 0) return '-';
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    if (m >= 60) {
      var h = Math.floor(m / 60);
      m = m % 60;
      return h + '時間' + m + '分';
    }
    return m + '分' + (s > 0 ? s + '秒' : '');
  }

  return { init: init, load: load };
})();
