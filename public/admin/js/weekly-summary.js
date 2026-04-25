/**
 * RPT-P1-1: 週次サマリー (store dashboard)
 *
 * 過去 7 日間の KPI を panel-sales の先頭に帯表示する軽量サマリー。
 * 既存 SalesReport / RegisterReport 等を壊さない追加レイヤ。
 *
 * 使い方:
 *   WeeklySummary.init(document.getElementById('weekly-summary-container'));
 *   WeeklySummary.load();   // sales タブ活性化時に呼ばれる
 */
var WeeklySummary = (function () {
  'use strict';

  var _container = null;

  function init(container) {
    _container = container;
  }

  function load() {
    if (!_container) return;
    var storeId = AdminApi && AdminApi.getCurrentStore ? AdminApi.getCurrentStore() : null;
    if (!storeId) {
      _container.innerHTML = '';
      return;
    }
    _container.innerHTML = '<div class="weekly-summary__loading" style="padding:8px 12px;color:#999;font-size:0.82rem;">\u9031\u6B21\u30B5\u30DE\u30EA\u30FC\u8AAD\u307F\u8FBC\u307F\u4E2D...</div>';

    fetch('/api/store/weekly-summary.php?store_id=' + encodeURIComponent(storeId), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        var json;
        try { json = JSON.parse(text); }
        catch (e) { throw new Error('\u5FDC\u7B54\u306E\u89E3\u6790\u306B\u5931\u6557'); }
        if (!json || !json.ok) {
          var msg = (json && json.error && json.error.message) || '\u30B5\u30DE\u30EA\u30FC\u53D6\u5F97\u306B\u5931\u6557';
          throw new Error(msg);
        }
        render(json.data);
      })
      .catch(function (err) {
        _container.innerHTML = '<div class="weekly-summary__error" style="padding:8px 12px;color:#c62828;font-size:0.82rem;">'
          + Utils.escapeHtml(err.message || '\u30A8\u30E9\u30FC')
          + '</div>';
      });
  }

  function render(data) {
    var thisWeek = data.thisWeek || {};
    var deltas = data.deltas || {};
    var period = (data.period && data.period.this) || {};
    var topItems = thisWeek.topItems || [];

    var html = '<div class="weekly-summary">';
    html += '<div class="weekly-summary__head">';
    html += '<span class="weekly-summary__title">\u9031\u6B21\u30B5\u30DE\u30EA\u30FC</span>';
    html += '<span class="weekly-summary__period">'
      + Utils.escapeHtml(_fmtDate(period.from)) + ' \u2013 ' + Utils.escapeHtml(_fmtDate(period.to))
      + ' (\u904E\u53BB 7 \u65E5\u9593)</span>';
    html += '</div>';

    html += '<div class="weekly-summary__cards">';
    html += _card('\u58F2\u4E0A\u5408\u8A08', '\u00A5' + _num(thisWeek.totalRevenue), deltas.revenuePct, '\u524D\u9031\u6BD4');
    html += _card('\u6CE8\u6587\u4EF6\u6570', _num(thisWeek.orderCount) + ' \u4EF6', deltas.orderPct, '\u524D\u9031\u6BD4');
    html += _card('\u5BA2\u6570', _num(thisWeek.guestCount) + ' \u540D', deltas.guestPct, '\u524D\u9031\u6BD4');
    html += _card('\u5BA2\u5358\u4FA1', '\u00A5' + _num(thisWeek.avgOrderValue), deltas.avgOrderValuePct, '\u524D\u9031\u6BD4');
    html += '</div>';

    if (topItems.length > 0) {
      html += '<div class="weekly-summary__top">';
      html += '<span class="weekly-summary__top-title">\u4EBA\u6C17\u5546\u54C1 Top 3</span>';
      html += '<ol class="weekly-summary__top-list">';
      for (var i = 0; i < topItems.length; i++) {
        var it = topItems[i];
        html += '<li>'
          + '<span class="weekly-summary__top-name">' + Utils.escapeHtml(it.name || '') + '</span>'
          + '<span class="weekly-summary__top-qty">\u00D7' + _num(it.qty) + '</span>'
          + '<span class="weekly-summary__top-revenue">\u00A5' + _num(it.revenue) + '</span>'
          + '</li>';
      }
      html += '</ol></div>';
    }

    html += '</div>';
    _container.innerHTML = html;
  }

  function _card(label, value, deltaPct, deltaLabel) {
    var deltaHtml = '';
    if (deltaPct === null || deltaPct === undefined) {
      deltaHtml = '<span class="weekly-summary__delta weekly-summary__delta--na">\u524D\u9031\u30C7\u30FC\u30BF\u306A\u3057</span>';
    } else {
      var sign = deltaPct > 0 ? '+' : '';
      var cls = deltaPct > 0 ? 'weekly-summary__delta--up'
              : (deltaPct < 0 ? 'weekly-summary__delta--down' : 'weekly-summary__delta--flat');
      deltaHtml = '<span class="weekly-summary__delta ' + cls + '">'
        + sign + deltaPct + '% <small>' + Utils.escapeHtml(deltaLabel) + '</small></span>';
    }
    return '<div class="weekly-summary__card">'
      + '<div class="weekly-summary__label">' + Utils.escapeHtml(label) + '</div>'
      + '<div class="weekly-summary__value">' + value + '</div>'
      + deltaHtml
      + '</div>';
  }

  function _num(n) {
    var v = parseInt(n, 10);
    if (isNaN(v)) v = 0;
    return v.toLocaleString();
  }

  function _fmtDate(s) {
    if (!s) return '';
    return String(s).replace(/-/g, '/');
  }

  return { init: init, load: load };
})();
