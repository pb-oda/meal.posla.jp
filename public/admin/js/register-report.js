/**
 * レジ分析
 */
var RegisterReport = (function () {
  'use strict';

  var _container = null;
  var _lastData = null;
  var _lastRange = null;

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
      + '<button class="btn btn-sm btn-primary" id="btn-reg-load">表示</button>'
      + '<button class="btn btn-sm btn-outline" id="btn-reg-csv" disabled>CSV出力</button></div>'
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

    document.getElementById('btn-reg-csv').addEventListener('click', exportCsv);

    fetchData(range.from, range.to);
  }

  function fetchData(from, to) {
    var el = document.getElementById('reg-data');
    var csvBtn = document.getElementById('btn-reg-csv');
    if (!el) return;
    if (csvBtn) csvBtn.disabled = true;
    el.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getRegisterReport(from, to).then(function (res) {
      _lastData = res;
      _lastRange = { from: from, to: to };
      renderReport(el, res);
      if (csvBtn) csvBtn.disabled = false;
    }).catch(function (err) {
      _lastData = null;
      _lastRange = null;
      if (csvBtn) csvBtn.disabled = true;
      el.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function renderReport(el, data) {
    var rs = data.registerSummary || {};
    var status = data.registerStatus || {};

    var html = renderRegisterStatus(status)
      + '<div class="report-summary">'
      + card('レジ開け', Utils.formatYen(rs.openAmount))
      + card('現金売上', Utils.formatYen(rs.cashSales))
      + card('入金', Utils.formatYen(rs.cashIn))
      + card('出金', Utils.formatYen(rs.cashOut))
      + card('予想残高', Utils.formatYen(rs.expectedBalance))
      + (rs.closeAmount !== null ? card('レジ締め', Utils.formatYen(rs.closeAmount)) : '')
      + (rs.overshort !== null ? card('過不足', formatOvershort(rs.overshort)) : '')
      + '</div>';

    var adjustments = data.paymentAdjustments || [];
    if (adjustments.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">会計取消・返金履歴</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr>'
        + '<th>処理日時</th><th>種別</th><th>テーブル</th><th>支払方法</th><th class="text-right">金額</th><th>担当者</th><th>理由</th>'
        + '</tr></thead><tbody>';
      adjustments.forEach(function (a) {
        var typeLabel = a.type === 'refund' ? '返金' : '取消';
        var typeColor = a.type === 'refund' ? '#1565c0' : '#c62828';
        html += '<tr><td>' + Utils.formatDateTime(a.adjustedAt) + '</td>'
          + '<td><span style="font-weight:700;color:' + typeColor + ';">' + typeLabel + '</span></td>'
          + '<td>' + Utils.escapeHtml(a.tableCode || '-') + '</td>'
          + '<td>' + Utils.escapeHtml(paymentMethodLabel(a.paymentMethod, a.paymentMethodDetail, a.gatewayName)) + '</td>'
          + '<td class="text-right">-' + Utils.formatYen(a.amount) + '</td>'
          + '<td>' + Utils.escapeHtml(a.userName || '-') + '</td>'
          + '<td>' + Utils.escapeHtml(a.reason || '') + '</td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

    var closeRecs = data.closeReconciliations || [];
    if (closeRecs.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">レジ締め照合</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr>'
        + '<th>締め時刻</th><th>担当者</th><th class="text-right">予想現金</th><th class="text-right">実際現金</th><th class="text-right">差額</th>'
        + '<th class="text-right">現金売上</th><th class="text-right">カード売上</th><th class="text-right">QR/電子</th><th>メモ</th>'
        + '</tr></thead><tbody>';
      closeRecs.forEach(function (r) {
        html += '<tr><td>' + Utils.formatDateTime(r.createdAt) + '</td>'
          + '<td>' + Utils.escapeHtml(r.userName || '-') + '</td>'
          + '<td class="text-right">' + formatNullableYen(r.expectedAmount) + '</td>'
          + '<td class="text-right">' + formatNullableYen(r.actualAmount) + '</td>'
          + '<td class="text-right">' + formatNullableDiff(r.differenceAmount) + '</td>'
          + '<td class="text-right">' + formatNullableYen(r.cashSalesAmount) + '</td>'
          + '<td class="text-right">' + formatNullableYen(r.cardSalesAmount) + '</td>'
          + '<td class="text-right">' + formatNullableYen(r.qrSalesAmount) + '</td>'
          + '<td>' + Utils.escapeHtml(r.reconciliationNote || r.note || '') + '</td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

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

    var detailBreakdown = data.paymentDetailBreakdown || [];
    if (detailBreakdown.length > 0) {
      var detailLabels = {
        card_credit: 'クレジット',
        card_debit: 'デビット',
        card_other: 'カードその他',
        qr_paypay: 'PayPay',
        qr_rakuten_pay: '楽天ペイ',
        qr_dbarai: 'd払い',
        qr_au_pay: 'au PAY',
        qr_merpay: 'メルペイ',
        qr_line_pay: 'LINE Pay',
        qr_alipay: 'Alipay',
        qr_wechat_pay: 'WeChat Pay',
        qr_other: 'QRその他',
        emoney_transport_ic: '交通系IC',
        emoney_id: 'iD',
        emoney_quicpay: 'QUICPay',
        emoney_other: '電子マネーその他'
      };
      html += '<div class="report-section"><h3 class="report-section__title">支払い詳細別</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr><th>詳細</th><th class="text-right">件数</th><th class="text-right">金額</th></tr></thead><tbody>';
      detailBreakdown.forEach(function (b) {
        var t = b.payment_method_detail || '';
        html += '<tr><td>' + (detailLabels[t] || t) + '</td>'
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

  function renderRegisterStatus(status) {
    var title = 'レジ状態';
    var message = getRegisterStatusMessage(status);
    var bg = '#eef7ee';
    var color = '#1b5e20';
    var border = '#a5d6a7';
    var meta = [];

    if (!status.hasOpen) {
      bg = '#f5f5f5';
      color = '#616161';
      border = '#ddd';
    } else if (status.needsClose) {
      bg = '#fff3cd';
      color = '#795500';
      border = '#ffe08a';
    } else if (status.overshortLevel === 'alert') {
      bg = '#ffebee';
      color = '#b71c1c';
      border = '#ffcdd2';
    } else if (status.overshortLevel === 'notice') {
      bg = '#fff8e1';
      color = '#795500';
      border = '#ffe082';
    }

    if (status.latestOpenAt) meta.push('最終レジ開け: ' + Utils.formatDateTime(status.latestOpenAt));
    if (status.latestCloseAt) meta.push('最終レジ締め: ' + Utils.formatDateTime(status.latestCloseAt));
    if (status.latestDifference !== null && typeof status.latestDifference !== 'undefined') {
      meta.push('最新差額: ' + formatOvershort(parseInt(status.latestDifference, 10) || 0));
    }

    return '<div class="report-section"><div class="card" style="border:1px solid ' + border + ';background:' + bg + ';color:' + color + ';padding:12px 14px;">'
      + '<div style="font-weight:700;margin-bottom:4px;">' + title + '</div>'
      + '<div style="font-size:0.9rem;line-height:1.6;">' + Utils.escapeHtml(message) + '</div>'
      + (meta.length ? '<div style="font-size:0.78rem;line-height:1.5;margin-top:6px;">' + Utils.escapeHtml(meta.join(' / ')) + '</div>' : '')
      + '</div></div>';
  }

  function formatOvershort(val) {
    if (val === 0) return Utils.formatYen(0);
    return (val > 0 ? '+' : '') + Utils.formatYen(val);
  }

  function getRegisterStatusMessage(status) {
    status = status || {};
    if (!status.hasOpen) return 'この期間にはレジ開け記録がありません。営業日の対象店舗・期間を確認してください。';
    if (status.needsClose) return 'レジ締めが未完了です。営業終了後にPOSレジの「レジ開閉」から締め処理を行ってください。';
    if (status.overshortLevel === 'alert') return 'レジ締め済みですが、過不足が500円以上あります。現金、入出金、外部決済端末の日締め控えを確認してください。';
    if (status.overshortLevel === 'notice') return 'レジ締め済みですが、過不足があります。締めメモと現金実査を確認してください。';
    return 'レジ締めまで完了しています。';
  }

  function formatNullableYen(val) {
    if (val === null || typeof val === 'undefined') return '-';
    return Utils.formatYen(parseInt(val, 10) || 0);
  }

  function formatNullableDiff(val) {
    if (val === null || typeof val === 'undefined') return '-';
    return formatOvershort(parseInt(val, 10) || 0);
  }

  function paymentMethodLabel(method, detail, gatewayName) {
    var labels = { cash: '現金', card: 'カード', qr: 'QR/電子' };
    var detailLabels = {
      card_credit: 'クレジット',
      card_debit: 'デビット',
      card_other: 'カードその他',
      qr_paypay: 'PayPay',
      qr_rakuten_pay: '楽天ペイ',
      qr_dbarai: 'd払い',
      qr_au_pay: 'au PAY',
      qr_merpay: 'メルペイ',
      qr_line_pay: 'LINE Pay',
      qr_alipay: 'Alipay',
      qr_wechat_pay: 'WeChat Pay',
      qr_other: 'QRその他',
      emoney_transport_ic: '交通系IC',
      emoney_id: 'iD',
      emoney_quicpay: 'QUICPay',
      emoney_other: '電子マネーその他'
    };
    var text = labels[method] || method || '';
    if (detail) {
      text += (text ? ' / ' : '') + (detailLabels[detail] || detail);
    }
    if (gatewayName) text += ' / ' + gatewayName;
    return text || '-';
  }

  function exportCsv() {
    if (!_lastData) return;

    var data = _lastData;
    var range = _lastRange || {};
    var rs = data.registerSummary || {};
    var status = data.registerStatus || {};
    var rows = [];

    rows.push(['レジ分析CSV']);
    rows.push(['期間', range.from || '', range.to || '']);
    rows.push([]);
    rows.push(['サマリー', '項目', '値']);
    rows.push(['レジ状態', '状態', getRegisterStatusMessage(status)]);
    rows.push(['レジ状態', '最終レジ開け', status.latestOpenAt || '']);
    rows.push(['レジ状態', '最終レジ締め', status.latestCloseAt || '']);
    rows.push(['レジ状態', '最新差額', nullableAmount(status.latestDifference)]);
    rows.push(['サマリー', 'レジ開け', rawAmount(rs.openAmount)]);
    rows.push(['サマリー', '現金売上', rawAmount(rs.cashSales)]);
    rows.push(['サマリー', '入金', rawAmount(rs.cashIn)]);
    rows.push(['サマリー', '出金', rawAmount(rs.cashOut)]);
    rows.push(['サマリー', '予想残高', rawAmount(rs.expectedBalance)]);
    rows.push(['サマリー', 'レジ締め', nullableAmount(rs.closeAmount)]);
    rows.push(['サマリー', '過不足', nullableAmount(rs.overshort)]);

    appendPaymentAdjustments(rows, data.paymentAdjustments || []);
    appendCloseReconciliations(rows, data.closeReconciliations || []);
    appendPaymentBreakdown(rows, data.paymentBreakdown || []);
    appendPaymentDetailBreakdown(rows, data.paymentDetailBreakdown || []);
    appendExternalMethodBreakdown(rows, data.externalMethodBreakdown || []);
    appendCashLog(rows, data.cashLog || []);

    downloadCsv(rows, 'posla-register-report_' + safeFilePart(range.from) + '_' + safeFilePart(range.to) + '.csv');
  }

  function appendPaymentAdjustments(rows, list) {
    rows.push([]);
    rows.push(['会計取消・返金履歴']);
    rows.push(['処理日時', '種別', 'テーブル', '支払方法', '金額', '担当者', '理由']);
    list.forEach(function (a) {
      rows.push([
        a.adjustedAt || '',
        a.type === 'refund' ? '返金' : '取消',
        a.tableCode || '-',
        paymentMethodLabel(a.paymentMethod, a.paymentMethodDetail, a.gatewayName),
        rawAmount(a.amount) * -1,
        a.userName || '-',
        a.reason || ''
      ]);
    });
  }

  function appendCloseReconciliations(rows, list) {
    rows.push([]);
    rows.push(['レジ締め照合']);
    rows.push(['締め時刻', '担当者', '予想現金', '実際現金', '差額', '現金売上', 'カード売上', 'QR/電子', 'メモ']);
    list.forEach(function (r) {
      rows.push([
        r.createdAt || '',
        r.userName || '-',
        nullableAmount(r.expectedAmount),
        nullableAmount(r.actualAmount),
        nullableAmount(r.differenceAmount),
        nullableAmount(r.cashSalesAmount),
        nullableAmount(r.cardSalesAmount),
        nullableAmount(r.qrSalesAmount),
        r.reconciliationNote || r.note || ''
      ]);
    });
  }

  function appendPaymentBreakdown(rows, list) {
    var labels = { cash: '現金', card: 'カード', qr: 'QR決済' };
    rows.push([]);
    rows.push(['支払方法別']);
    rows.push(['方法', '件数', '金額']);
    list.forEach(function (b) {
      rows.push([labels[b.payment_method] || b.payment_method || '', rawAmount(b.count), rawAmount(b.total)]);
    });
  }

  function appendPaymentDetailBreakdown(rows, list) {
    rows.push([]);
    rows.push(['支払い詳細別']);
    rows.push(['詳細', '件数', '金額']);
    list.forEach(function (b) {
      rows.push([paymentMethodLabel('', b.payment_method_detail, ''), rawAmount(b.count), rawAmount(b.total)]);
    });
  }

  function appendExternalMethodBreakdown(rows, list) {
    var labels = {
      voucher: '商品券',
      bank_transfer: '事前振込',
      accounts_receivable: '売掛',
      point: 'ポイント充当',
      other: 'その他'
    };
    rows.push([]);
    rows.push(['外部分類別']);
    rows.push(['分類', '件数', '金額']);
    list.forEach(function (b) {
      rows.push([labels[b.external_method_type] || b.external_method_type || '', rawAmount(b.count), rawAmount(b.total)]);
    });
  }

  function appendCashLog(rows, list) {
    var typeLabels = { open: 'レジ開け', close: 'レジ締め', cash_in: '入金', cash_out: '出金', cash_sale: '現金売上' };
    rows.push([]);
    rows.push(['レジログ']);
    rows.push(['時刻', '種別', '担当者', '金額', 'メモ']);
    list.forEach(function (e) {
      rows.push([
        e.created_at || '',
        typeLabels[e.type] || e.type || '',
        e.user_name || '-',
        rawAmount(e.amount),
        e.note || ''
      ]);
    });
  }

  function nullableAmount(val) {
    if (val === null || typeof val === 'undefined') return '';
    return rawAmount(val);
  }

  function rawAmount(val) {
    var n = parseInt(val, 10);
    if (isNaN(n)) return 0;
    return n;
  }

  function safeFilePart(val) {
    if (!val) return 'unknown';
    return String(val).replace(/[^0-9A-Za-z_-]/g, '');
  }

  function downloadCsv(rows, filename) {
    var csv = rows.map(function (row) {
      return row.map(csvCell).join(',');
    }).join('\r\n');
    var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function csvCell(value) {
    if (value === null || typeof value === 'undefined') return '';
    var text = String(value);
    if (/[",\r\n]/.test(text)) {
      return '"' + text.replace(/"/g, '""') + '"';
    }
    return text;
  }

  return { init: init, load: load };
})();
