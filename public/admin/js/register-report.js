/**
 * レジ分析
 */
var RegisterReport = (function () {
  'use strict';

  var _container = null;

  function init(container) { _container = container; }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }

    var range = Utils.getPresetRange('today');
    _container.innerHTML =
      '<div class="report-presets" id="reg-presets">'
      + '<button class="btn btn-outline active" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline" data-preset="month">今月</button></div>'
      + '<div class="report-date-range">'
      + '<input type="date" id="reg-from" value="' + range.from + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="reg-to" value="' + range.to + '">'
      + '<button class="btn btn-sm btn-primary" id="btn-reg-load">表示</button></div>'
      + '<div id="reg-data"></div>';

    document.getElementById('reg-presets').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var r = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('reg-from').value = r.from;
      document.getElementById('reg-to').value = r.to;
      this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      fetchData(r.from, r.to);
    });

    document.getElementById('btn-reg-load').addEventListener('click', function () {
      fetchData(document.getElementById('reg-from').value, document.getElementById('reg-to').value);
    });

    fetchData(range.from, range.to);
  }

  function fetchData(from, to) {
    var el = document.getElementById('reg-data');
    if (!el) return;
    el.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getRegisterReport(from, to).then(function (res) {
      renderReport(el, res);
    }).catch(function (err) {
      el.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function renderReport(el, data) {
    var rs = data.registerSummary || {};

    var html = '<div class="report-summary">'
      + card('レジ開け', Utils.formatYen(rs.openAmount))
      + card('現金売上', Utils.formatYen(rs.cashSales))
      + card('入金', Utils.formatYen(rs.cashIn))
      + card('出金', Utils.formatYen(rs.cashOut))
      + card('予想残高', Utils.formatYen(rs.expectedBalance))
      + (rs.closeAmount !== null ? card('レジ締め', Utils.formatYen(rs.closeAmount)) : '')
      + (rs.overshort !== null ? card('過不足', formatOvershort(rs.overshort)) : '')
      + '</div>';

    // 支払方法別
    var breakdown = data.paymentBreakdown || [];
    if (breakdown.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">支払方法別</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr><th>方法</th><th class="text-right">件数</th><th class="text-right">金額</th></tr></thead><tbody>';
      var labels = { cash: '現金', card: 'カード', qr: 'QR決済' };
      breakdown.forEach(function (b) {
        html += '<tr><td>' + (labels[b.payment_method] || b.payment_method) + '</td>'
          + '<td class="text-right">' + b.count + '</td>'
          + '<td class="text-right">' + Utils.formatYen(b.total) + '</td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

    // Phase 4d-3: 外部分類別 (緊急会計転記分の内訳)
    //   paymentBreakdown は orders.payment_method('cash','card','qr') 集計なので、
    //   other_external 転記分 (qr に寄せて保存) を商品券/事前振込等に分けて追加表示する。
    var extBreakdown = data.externalMethodBreakdown || [];
    if (extBreakdown.length > 0) {
      var extLabels = {
        voucher: '\u5546\u54C1\u5238',
        bank_transfer: '\u4E8B\u524D\u632F\u8FBC',
        accounts_receivable: '\u58F2\u639B',
        point: '\u30DD\u30A4\u30F3\u30C8\u5145\u5F53',
        other: '\u305D\u306E\u4ED6'
      };
      html += '<div class="report-section"><h3 class="report-section__title">外部分類別 (緊急会計転記)</h3>'
        + '<div class="card" style="margin-bottom:6px;">'
        + '<div style="padding:8px 12px;background:#fff3cd;color:#795500;font-size:0.8rem;border-radius:4px;margin-bottom:8px;line-height:1.5;">'
        + '上の「支払方法別」では QR 決済に合算されます。こちらは緊急会計から転記された分の内訳です (payments.external_method_type 集計)。'
        + '</div>'
        + '<div class="data-table-wrap"><table class="data-table"><thead><tr><th>分類</th><th class="text-right">件数</th><th class="text-right">金額</th></tr></thead><tbody>';
      extBreakdown.forEach(function (b) {
        var t = b.external_method_type || '';
        html += '<tr><td>' + (extLabels[t] || t) + '</td>'
          + '<td class="text-right">' + b.count + '</td>'
          + '<td class="text-right">' + Utils.formatYen(b.total) + '</td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

    // cash_log
    var log = data.cashLog || [];
    if (log.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">レジログ</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr><th>時刻</th><th>種別</th><th>担当者</th><th class="text-right">金額</th><th>メモ</th></tr></thead><tbody>';
      var typeLabels = { open: 'レジ開け', close: 'レジ締め', cash_in: '入金', cash_out: '出金', cash_sale: '現金売上' };
      log.forEach(function (e) {
        html += '<tr><td>' + Utils.formatDateTime(e.created_at) + '</td>'
          + '<td>' + (typeLabels[e.type] || e.type) + '</td>'
          + '<td>' + Utils.escapeHtml(e.user_name || '-') + '</td>'
          + '<td class="text-right">' + Utils.formatYen(e.amount) + '</td>'
          + '<td>' + Utils.escapeHtml(e.note || '') + '</td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

    el.innerHTML = html;
  }

  function card(label, value) {
    return '<div class="summary-card"><div class="summary-card__label">' + label + '</div><div class="summary-card__value">' + value + '</div></div>';
  }

  function formatOvershort(val) {
    if (val === 0) return Utils.formatYen(0);
    return (val > 0 ? '+' : '') + Utils.formatYen(val);
  }

  return { init: init, load: load };
})();
