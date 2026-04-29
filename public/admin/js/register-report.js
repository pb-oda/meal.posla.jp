/**
 * レジ分析
 */
var RegisterReport = (function () {
  'use strict';

  var _container = null;
  var _lastData = null;
  var _lastRange = null;
  var _eventsBound = false;

  function init(container) {
    _container = container;
    bindActionEvents();
  }

  function bindActionEvents() {
    if (_eventsBound || !_container) return;
    _eventsBound = true;
    _container.addEventListener('click', function (e) {
      var resolveBtn = e.target.closest('[data-preclose-resolve]');
      if (!resolveBtn) return;
      var id = resolveBtn.getAttribute('data-preclose-resolve');
      if (!id) return;
      var note = window.prompt('解決メモを入力してください\n例: 端末日計控え確認済み / 入出金記録漏れを修正済み');
      if (note === null) return;
      note = note.replace(/^\s+|\s+$/g, '');
      if (!note) {
        notify('解決メモを入力してください', true);
        return;
      }
      resolveBtn.disabled = true;
      AdminApi.resolveRegisterPreCloseLog(id, note).then(function () {
        notify('仮締めを解決済みにしました', false);
        if (_lastRange) fetchData(_lastRange.from, _lastRange.to);
      }).catch(function (err) {
        resolveBtn.disabled = false;
        notify(err.message || '解決処理に失敗しました', true);
      });
    });
  }

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
      + '<div class="report-date-range" id="reg-tx-filters" style="gap:8px;flex-wrap:wrap;">'
      + '<input type="text" id="reg-filter-text" class="form-input" style="max-width:220px;" placeholder="卓番・担当・控えメモ">'
      + '<select id="reg-filter-method" class="form-input" style="max-width:140px;"><option value="">全支払</option><option value="cash">現金</option><option value="card">カード</option><option value="qr">QR/電子</option></select>'
      + '<select id="reg-filter-status" class="form-input" style="max-width:160px;"><option value="">全状態</option><option value="active">有効</option><option value="void">取消済</option><option value="refund">返金あり</option><option value="missing_note">外部控え未入力</option></select>'
      + '<input type="number" id="reg-filter-min" class="form-input" style="max-width:110px;" placeholder="下限">'
      + '<input type="number" id="reg-filter-max" class="form-input" style="max-width:110px;" placeholder="上限">'
      + '<button class="btn btn-sm btn-outline" id="btn-reg-filter-clear">検索クリア</button>'
      + '</div>'
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
    bindFilterEvents();

    fetchData(range.from, range.to);
  }

  function bindFilterEvents() {
    var filters = document.getElementById('reg-tx-filters');
    if (!filters) return;
    var refresh = function () {
      if (!_lastData) return;
      var el = document.getElementById('reg-data');
      if (el) renderReport(el, _lastData);
    };
    filters.addEventListener('input', refresh);
    filters.addEventListener('change', refresh);
    document.getElementById('btn-reg-filter-clear').addEventListener('click', function () {
      ['reg-filter-text', 'reg-filter-method', 'reg-filter-status', 'reg-filter-min', 'reg-filter-max'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
      });
      refresh();
    });
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
      + renderCloseAssist(data.closeAssist || {})
      + renderUnresolvedPreClose(data.unresolvedPreCloseLogs || [])
      + renderPostCloseActivity(data.postCloseTransactions || [], data.postCloseAdjustments || [])
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

    html += renderPaymentTransactions(data.paymentTransactions || []);

    var closeRecs = data.closeReconciliations || [];
    if (closeRecs.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">レジ締め照合</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr>'
        + '<th>締め時刻</th><th>担当者</th><th class="text-right">予想現金</th><th class="text-right">実際現金</th><th class="text-right">差額</th>'
        + '<th class="text-right">現金売上</th><th class="text-right">カード売上</th><th class="text-right">QR/電子</th><th>金種</th><th>端末日計</th><th>メモ</th><th>引き継ぎ</th>'
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
          + '<td>' + Utils.escapeHtml(closeDenominationText(r.cashDenomination)) + '</td>'
          + '<td>' + Utils.escapeHtml(closeExternalText(r.externalReconciliation)) + '</td>'
          + '<td>' + Utils.escapeHtml(r.reconciliationNote || r.note || '') + '</td>'
          + '<td>' + Utils.escapeHtml(r.handoverNote || '') + '</td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

    var preCloseLogs = data.preCloseLogs || [];
    if (preCloseLogs.length > 0) {
      html += '<div class="report-section"><h3 class="report-section__title">仮締め履歴</h3>'
        + '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr>'
        + '<th>保存時刻</th><th>状態</th><th>担当者</th><th class="text-right">予想現金</th><th class="text-right">実際現金</th><th class="text-right">差額</th>'
        + '<th>端末日計</th><th>メモ</th><th>引き継ぎ</th><th>解決メモ</th><th>操作</th>'
        + '</tr></thead><tbody>';
      preCloseLogs.forEach(function (r) {
        html += '<tr><td>' + Utils.formatDateTime(r.createdAt) + '</td>'
          + '<td>' + preCloseStatusLabel(r) + '</td>'
          + '<td>' + Utils.escapeHtml(r.userName || '-') + '</td>'
          + '<td class="text-right">' + formatNullableYen(r.expectedCashAmount) + '</td>'
          + '<td class="text-right">' + formatNullableYen(r.actualCashAmount) + '</td>'
          + '<td class="text-right">' + formatNullableDiff(r.differenceAmount) + '</td>'
          + '<td>' + Utils.escapeHtml(closeExternalText(r.externalReconciliation)) + '</td>'
          + '<td>' + Utils.escapeHtml(r.reconciliationNote || '') + '</td>'
          + '<td>' + Utils.escapeHtml(r.handoverNote || '') + '</td>'
          + '<td>' + Utils.escapeHtml(resolutionText(r)) + '</td>'
          + '<td>' + preCloseResolveButton(r) + '</td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

    html += renderReceiptReprints(data.receiptReprints || []);

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
        var cashLogMemo = (e.note || '') + (e.handover_note ? ' / 引継: ' + e.handover_note : '');
        var cashLogDenomText = closeDenominationText(parseJsonColumn(e.cash_denomination_json));
        if (cashLogDenomText) cashLogMemo += (cashLogMemo ? ' / ' : '') + '金種: ' + cashLogDenomText;
        html += '<tr><td>' + Utils.formatDateTime(e.created_at) + '</td>'
          + '<td>' + (typeLabels[e.type] || e.type) + '</td>'
          + '<td>' + Utils.escapeHtml(e.user_name || '-') + '</td>'
          + '<td class="text-right">' + Utils.formatYen(e.amount) + '</td>'
          + '<td>' + Utils.escapeHtml(cashLogMemo) + '</td></tr>';
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

    if (status.closeReminder && status.closeReminder.isOverdue) {
      bg = '#ffebee';
      color = '#b71c1c';
      border = '#ffcdd2';
    } else if (!status.hasOpen) {
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
    if (status.closeReminder && status.closeReminder.configured) {
      meta.push('締め予定: ' + Utils.formatDateTime(status.closeReminder.dueAt));
      meta.push('警告開始: ' + Utils.formatDateTime(status.closeReminder.alertAt));
    }

    return '<div class="report-section"><div class="card" style="border:1px solid ' + border + ';background:' + bg + ';color:' + color + ';padding:12px 14px;">'
      + '<div style="font-weight:700;margin-bottom:4px;">' + title + '</div>'
      + '<div style="font-size:0.9rem;line-height:1.6;">' + Utils.escapeHtml(message) + '</div>'
      + (meta.length ? '<div style="font-size:0.78rem;line-height:1.5;margin-top:6px;">' + Utils.escapeHtml(meta.join(' / ')) + '</div>' : '')
      + '</div></div>';
  }

  function renderCloseAssist(closeAssist) {
    var warnings = (closeAssist && closeAssist.warnings) || [];
    var html = '<div class="report-section"><h3 class="report-section__title">締め前チェック</h3>';
    if (warnings.length === 0) {
      html += '<div class="card" style="padding:12px 14px;border:1px solid #a5d6a7;background:#eef7ee;color:#1b5e20;font-weight:700;">警告はありません</div></div>';
      return html;
    }
    html += '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr><th>確認事項</th><th class="text-right">件数</th><th class="text-right">金額</th></tr></thead><tbody>';
    warnings.forEach(function (w) {
      var color = w.level === 'alert' ? '#c62828' : '#795500';
      var count = typeof w.count === 'undefined' ? '-' : w.count;
      var amount = '-';
      if (typeof w.amount !== 'undefined') {
        amount = w.code === 'cash_difference' ? formatOvershort(parseInt(w.amount, 10) || 0) : Utils.formatYen(w.amount || 0);
      }
      html += '<tr><td style="color:' + color + ';font-weight:700;">' + Utils.escapeHtml(w.message || '') + '</td>'
        + '<td class="text-right">' + count + '</td>'
        + '<td class="text-right">' + amount + '</td></tr>';
    });
    html += '</tbody></table></div></div></div>';
    return html;
  }

  function renderUnresolvedPreClose(list) {
    if (!list || list.length === 0) return '';
    var html = '<div class="report-section"><h3 class="report-section__title">未解決の仮締め差額</h3>'
      + '<div class="card" style="border:1px solid #ffcdd2;background:#ffebee;">'
      + '<div style="padding:8px 12px;color:#b71c1c;font-size:0.84rem;font-weight:700;">過去の差額が未解決です。調査したら解決済みにしてください。</div>'
      + '<div class="data-table-wrap"><table class="data-table"><thead><tr>'
      + '<th>営業日</th><th>保存時刻</th><th>担当者</th><th class="text-right">差額</th><th>メモ</th><th>引き継ぎ</th><th>操作</th>'
      + '</tr></thead><tbody>';
    list.forEach(function (r) {
      html += '<tr><td>' + Utils.escapeHtml(r.businessDay || '') + '</td>'
        + '<td>' + Utils.formatDateTime(r.createdAt) + '</td>'
        + '<td>' + Utils.escapeHtml(r.userName || '-') + '</td>'
        + '<td class="text-right">' + formatNullableDiff(r.differenceAmount) + '</td>'
        + '<td>' + Utils.escapeHtml(r.reconciliationNote || '') + '</td>'
        + '<td>' + Utils.escapeHtml(r.handoverNote || '') + '</td>'
        + '<td>' + preCloseResolveButton(r) + '</td></tr>';
    });
    html += '</tbody></table></div></div></div>';
    return html;
  }

  function renderPostCloseActivity(payments, adjustments) {
    payments = payments || [];
    adjustments = adjustments || [];
    if (payments.length === 0 && adjustments.length === 0) return '';
    var html = '<div class="report-section"><h3 class="report-section__title">締め後取引アラート</h3>'
      + '<div class="card" style="border:1px solid #ffcdd2;background:#ffebee;">'
      + '<div style="padding:8px 12px;color:#b71c1c;font-size:0.84rem;font-weight:700;">レジ締め後に売上または取消・返金が動いています。締め直し、端末日計、現金実査を確認してください。</div>'
      + '<div class="data-table-wrap"><table class="data-table"><thead><tr>'
      + '<th>発生時刻</th><th>種別</th><th>卓</th><th>支払方法</th><th class="text-right">金額</th><th>状態/理由</th>'
      + '</tr></thead><tbody>';
    payments.forEach(function (p) {
      var statusText = transactionStatusLabel(p);
      if (p.payment_note) statusText += ' / ' + Utils.escapeHtml(p.payment_note);
      html += '<tr><td>' + Utils.formatDateTime(p.paid_at) + '</td>'
        + '<td><span style="color:#c62828;font-weight:700;">締め後会計</span></td>'
        + '<td>' + Utils.escapeHtml(p.table_code || '-') + '</td>'
        + '<td>' + Utils.escapeHtml(paymentMethodLabel(p.payment_method, p.payment_method_detail, p.gateway_name)) + '</td>'
        + '<td class="text-right">' + Utils.formatYen(p.total_amount || 0) + '</td>'
        + '<td>' + statusText + '</td></tr>';
    });
    adjustments.forEach(function (a) {
      var typeLabel = a.type === 'refund' ? '締め後返金' : '締め後取消';
      html += '<tr><td>' + Utils.formatDateTime(a.adjustedAt) + '</td>'
        + '<td><span style="color:#c62828;font-weight:700;">' + typeLabel + '</span></td>'
        + '<td>' + Utils.escapeHtml(a.tableCode || '-') + '</td>'
        + '<td>' + Utils.escapeHtml(paymentMethodLabel(a.paymentMethod, a.paymentMethodDetail, a.gatewayName)) + '</td>'
        + '<td class="text-right">-' + Utils.formatYen(a.amount || 0) + '</td>'
        + '<td>' + Utils.escapeHtml(a.reason || '') + '</td></tr>';
    });
    html += '</tbody></table></div></div></div>';
    return html;
  }

  function closeDenominationText(cashDenomination) {
    if (!cashDenomination || !cashDenomination.items || cashDenomination.items.length === 0) return '';
    var parts = [];
    cashDenomination.items.forEach(function (item) {
      parts.push((item.label || item.value + '円') + 'x' + (item.quantity || 0));
    });
    return parts.join(' / ');
  }

  function parseJsonColumn(value) {
    if (!value) return null;
    if (typeof value === 'object') return value;
    try {
      return JSON.parse(value);
    } catch (e) {
      return null;
    }
  }

  function closeExternalText(externalReconciliation) {
    if (!externalReconciliation) return '';
    var parts = [];
    ['card', 'qr'].forEach(function (key) {
      var row = externalReconciliation[key];
      if (!row || row.actual === null || typeof row.actual === 'undefined') return;
      var label = key === 'card' ? 'カード' : 'QR';
      var diff = parseInt(row.difference, 10) || 0;
      parts.push(label + ' ' + Utils.formatYen(row.actual) + ' / 差額 ' + (diff >= 0 ? '+' : '') + Utils.formatYen(diff));
    });
    return parts.join(' / ');
  }

  function renderPaymentTransactions(list) {
    var filtered = filterPaymentTransactions(list || []);
    var html = '<div class="report-section"><h3 class="report-section__title">取引検索</h3>';
    html += '<div class="card"><div style="padding:8px 12px;color:#666;font-size:0.82rem;">表示 ' + filtered.length + '件 / 全 ' + (list || []).length + '件</div>';
    if (filtered.length === 0) {
      html += '<div class="empty-state"><p class="empty-state__text">条件に一致する取引はありません</p></div></div></div>';
      return html;
    }
    html += '<div class="data-table-wrap"><table class="data-table"><thead><tr>'
      + '<th>会計日時</th><th>卓</th><th>担当者</th><th>支払方法</th><th>外部控えメモ</th><th class="text-right">金額</th><th>状態</th>'
      + '</tr></thead><tbody>';
    filtered.forEach(function (p) {
      var status = transactionStatusLabel(p);
      html += '<tr><td>' + Utils.formatDateTime(p.paid_at) + '</td>'
        + '<td>' + Utils.escapeHtml(p.table_code || '-') + '</td>'
        + '<td>' + Utils.escapeHtml(p.staff_name || '-') + '</td>'
        + '<td>' + Utils.escapeHtml(paymentMethodLabel(p.payment_method, p.payment_method_detail, p.gateway_name)) + '</td>'
        + '<td>' + Utils.escapeHtml(p.external_payment_note || '') + '</td>'
        + '<td class="text-right">' + Utils.formatYen(p.total_amount) + '</td>'
        + '<td>' + status + '</td></tr>';
    });
    html += '</tbody></table></div></div></div>';
    return html;
  }

  function renderReceiptReprints(list) {
    if (!list || list.length === 0) return '';
    var typeLabels = { receipt: '領収書', invoice: '適格簡易請求書' };
    var html = '<div class="report-section"><h3 class="report-section__title">領収書再発行履歴</h3>'
      + '<div class="card"><div class="data-table-wrap"><table class="data-table"><thead><tr>'
      + '<th>再発行日時</th><th>番号</th><th>種類</th><th class="text-right">金額</th><th>担当者</th>'
      + '</tr></thead><tbody>';
    list.forEach(function (r) {
      html += '<tr><td>' + Utils.formatDateTime(r.created_at) + '</td>'
        + '<td>' + Utils.escapeHtml(r.receipt_number || '-') + '</td>'
        + '<td>' + Utils.escapeHtml(typeLabels[r.receipt_type] || r.receipt_type || '-') + '</td>'
        + '<td class="text-right">' + Utils.formatYen(r.total_amount || 0) + '</td>'
        + '<td>' + Utils.escapeHtml(r.user_name || r.username || '-') + '</td></tr>';
    });
    html += '</tbody></table></div></div></div>';
    return html;
  }

  function filterPaymentTransactions(list) {
    var text = getFilterValue('reg-filter-text').toLowerCase();
    var method = getFilterValue('reg-filter-method');
    var status = getFilterValue('reg-filter-status');
    var min = parseInt(getFilterValue('reg-filter-min'), 10);
    var max = parseInt(getFilterValue('reg-filter-max'), 10);
    if (isNaN(min)) min = null;
    if (isNaN(max)) max = null;

    return list.filter(function (p) {
      var amount = parseInt(p.total_amount, 10) || 0;
      if (method && p.payment_method !== method) return false;
      if (min !== null && amount < min) return false;
      if (max !== null && amount > max) return false;
      if (status && !matchesTransactionStatus(p, status)) return false;
      if (text) {
        var hay = [
          p.id || '',
          p.table_code || '',
          p.staff_name || '',
          paymentMethodLabel(p.payment_method, p.payment_method_detail, p.gateway_name),
          p.external_payment_note || ''
        ].join(' ').toLowerCase();
        if (hay.indexOf(text) === -1) return false;
      }
      return true;
    });
  }

  function getFilterValue(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '') : '';
  }

  function matchesTransactionStatus(p, status) {
    var isVoided = p.void_status === 'voided';
    var refundStatus = p.refund_status || 'none';
    var isRefunded = refundStatus !== 'none' && refundStatus !== '';
    if (status === 'void') return isVoided;
    if (status === 'refund') return isRefunded;
    if (status === 'active') return !isVoided && !isRefunded;
    if (status === 'missing_note') {
      return !isVoided && (p.payment_method === 'card' || p.payment_method === 'qr') && !p.external_payment_note;
    }
    return true;
  }

  function transactionStatusLabel(p) {
    if (p.void_status === 'voided') return '<span style="color:#c62828;font-weight:700;">取消済</span>';
    var refundStatus = p.refund_status || 'none';
    if (refundStatus !== 'none' && refundStatus !== '') return '<span style="color:#1565c0;font-weight:700;">返金あり</span>';
    if ((p.payment_method === 'card' || p.payment_method === 'qr') && !p.external_payment_note) {
      return '<span style="color:#795500;font-weight:700;">控え未入力</span>';
    }
    return '有効';
  }

  function transactionStatusText(p) {
    if (p.void_status === 'voided') return '取消済';
    var refundStatus = p.refund_status || 'none';
    if (refundStatus !== 'none' && refundStatus !== '') return '返金あり';
    if ((p.payment_method === 'card' || p.payment_method === 'qr') && !p.external_payment_note) return '控え未入力';
    return '有効';
  }

  function formatOvershort(val) {
    if (val === 0) return Utils.formatYen(0);
    return (val > 0 ? '+' : '') + Utils.formatYen(val);
  }

  function getRegisterStatusMessage(status) {
    status = status || {};
    if (status.closeReminder && status.closeReminder.isOverdue) return 'レジ締め予定時刻を過ぎています。営業終了後は本締めを完了してください。';
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

  function preCloseStatusLabel(row) {
    var text = preCloseStatusText(row);
    if (text === '未解決') return '<span style="color:#c62828;font-weight:700;">未解決</span>';
    return '<span style="color:#2e7d32;font-weight:700;">解決済み</span>';
  }

  function preCloseStatusText(row) {
    var diff = row && row.differenceAmount !== null && typeof row.differenceAmount !== 'undefined'
      ? parseInt(row.differenceAmount, 10) || 0
      : 0;
    if (row && row.status === 'open' && diff !== 0) return '未解決';
    return '解決済み';
  }

  function preCloseResolveButton(row) {
    if (!row || preCloseStatusText(row) !== '未解決') return '';
    return '<button class="btn btn-sm btn-primary" data-preclose-resolve="' + Utils.escapeHtml(row.id || '') + '">解決済みにする</button>';
  }

  function resolutionText(row) {
    if (!row) return '';
    var parts = [];
    if (row.resolutionNote) parts.push(row.resolutionNote);
    if (row.resolvedByName) parts.push('対応者: ' + row.resolvedByName);
    if (row.resolvedAt) parts.push('対応日時: ' + Utils.formatDateTime(row.resolvedAt));
    return parts.join(' / ');
  }

  function notify(message, isError) {
    if (window.showToast) {
      window.showToast(message, isError ? 'error' : 'success');
      return;
    }
    if (isError) alert(message);
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

    appendCloseAssist(rows, data.closeAssist || {});
    appendUnresolvedPreClose(rows, data.unresolvedPreCloseLogs || []);
    appendPostCloseActivity(rows, data.postCloseTransactions || [], data.postCloseAdjustments || []);
    appendPaymentAdjustments(rows, data.paymentAdjustments || []);
    appendPaymentTransactions(rows, data.paymentTransactions || []);
    appendCloseReconciliations(rows, data.closeReconciliations || []);
    appendPreCloseLogs(rows, data.preCloseLogs || []);
    appendReceiptReprints(rows, data.receiptReprints || []);
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

  function appendCloseAssist(rows, closeAssist) {
    rows.push([]);
    rows.push(['締め前チェック']);
    rows.push(['確認事項', '件数', '金額']);
    (closeAssist.warnings || []).forEach(function (w) {
      rows.push([w.message || '', rawAmount(w.count), nullableAmount(w.amount)]);
    });
  }

  function appendPaymentTransactions(rows, list) {
    rows.push([]);
    rows.push(['取引検索']);
    rows.push(['会計日時', '卓', '担当者', '支払方法', '外部控えメモ', '金額', '状態']);
    filterPaymentTransactions(list || []).forEach(function (p) {
      rows.push([
        p.paid_at || '',
        p.table_code || '-',
        p.staff_name || '-',
        paymentMethodLabel(p.payment_method, p.payment_method_detail, p.gateway_name),
        p.external_payment_note || '',
        rawAmount(p.total_amount),
        transactionStatusText(p)
      ]);
    });
  }

  function appendPostCloseActivity(rows, payments, adjustments) {
    payments = payments || [];
    adjustments = adjustments || [];
    if (payments.length === 0 && adjustments.length === 0) return;
    rows.push([]);
    rows.push(['締め後取引アラート']);
    rows.push(['発生時刻', '種別', '卓', '支払方法', '金額', '状態/理由']);
    payments.forEach(function (p) {
      rows.push([
        p.paid_at || '',
        '締め後会計',
        p.table_code || '-',
        paymentMethodLabel(p.payment_method, p.payment_method_detail, p.gateway_name),
        rawAmount(p.total_amount),
        transactionStatusText(p)
      ]);
    });
    adjustments.forEach(function (a) {
      rows.push([
        a.adjustedAt || '',
        a.type === 'refund' ? '締め後返金' : '締め後取消',
        a.tableCode || '-',
        paymentMethodLabel(a.paymentMethod, a.paymentMethodDetail, a.gatewayName),
        rawAmount(a.amount) * -1,
        a.reason || ''
      ]);
    });
  }

  function appendCloseReconciliations(rows, list) {
    rows.push([]);
    rows.push(['レジ締め照合']);
    rows.push(['締め時刻', '担当者', '予想現金', '実際現金', '差額', '現金売上', 'カード売上', 'QR/電子', '金種', '端末日計', 'メモ', '引き継ぎ']);
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
        closeDenominationText(r.cashDenomination),
        closeExternalText(r.externalReconciliation),
        r.reconciliationNote || r.note || '',
        r.handoverNote || ''
      ]);
    });
  }

  function appendPreCloseLogs(rows, list) {
    if (!list || list.length === 0) return;
    rows.push([]);
    rows.push(['仮締め履歴']);
    rows.push(['保存時刻', '状態', '担当者', '予想現金', '実際現金', '差額', '端末日計', 'メモ', '引き継ぎ', '解決メモ']);
    list.forEach(function (r) {
      rows.push([
        r.createdAt || '',
        preCloseStatusText(r),
        r.userName || '-',
        nullableAmount(r.expectedCashAmount),
        nullableAmount(r.actualCashAmount),
        nullableAmount(r.differenceAmount),
        closeExternalText(r.externalReconciliation),
        r.reconciliationNote || '',
        r.handoverNote || '',
        resolutionText(r)
      ]);
    });
  }

  function appendUnresolvedPreClose(rows, list) {
    if (!list || list.length === 0) return;
    rows.push([]);
    rows.push(['未解決の仮締め差額']);
    rows.push(['営業日', '保存時刻', '担当者', '予想現金', '実際現金', '差額', 'メモ', '引き継ぎ']);
    list.forEach(function (r) {
      rows.push([
        r.businessDay || '',
        r.createdAt || '',
        r.userName || '-',
        nullableAmount(r.expectedCashAmount),
        nullableAmount(r.actualCashAmount),
        nullableAmount(r.differenceAmount),
        r.reconciliationNote || '',
        r.handoverNote || ''
      ]);
    });
  }

  function appendReceiptReprints(rows, list) {
    rows.push([]);
    rows.push(['領収書再発行履歴']);
    rows.push(['再発行日時', '番号', '種類', '金額', '担当者']);
    list.forEach(function (r) {
      rows.push([
        r.created_at || '',
        r.receipt_number || '-',
        r.receipt_type || '-',
        rawAmount(r.total_amount),
        r.user_name || r.username || '-'
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
      var memo = (e.note || '') + (e.handover_note ? ' / 引継: ' + e.handover_note : '');
      var denomText = closeDenominationText(parseJsonColumn(e.cash_denomination_json));
      if (denomText) memo += (memo ? ' / ' : '') + '金種: ' + denomText;
      rows.push([
        e.created_at || '',
        typeLabels[e.type] || e.type || '',
        e.user_name || '-',
        rawAmount(e.amount),
        memo
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
