/**
 * L-5: 領収書/インボイス設定・発行履歴
 */
var ReceiptSettings = (function () {
  'use strict';

  var _container = null;

  function init(container) { _container = container; }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getReceiptSettings().then(function (data) {
      render(data);
    }).catch(function () {
      render({});
    });
  }

  function render(settings) {
    var today = new Date().toISOString().slice(0, 10);

    var html = '<h3 class="report-section__title">インボイス・領収書設定</h3>'
      + '<div class="card" style="padding:1.5rem;">'
      + formField('適格請求書発行事業者登録番号', 'rcpt-reg-number', settings.registration_number || '', 'T1234567890123', 'T + 13桁の数字')
      + formField('事業者名', 'rcpt-biz-name', settings.business_name || '', '株式会社○○')
      + formField('店舗名（領収書表示用）', 'rcpt-store-name', settings.receipt_store_name || '', '○○店')
      + formField('住所', 'rcpt-address', settings.receipt_address || '', '東京都...')
      + formField('電話番号', 'rcpt-phone', settings.receipt_phone || '', '03-xxxx-xxxx')
      + '<div class="form-group">'
      + '<label class="form-label">フッター（お礼メッセージ等）</label>'
      + '<textarea class="form-input" id="rcpt-footer" rows="2" placeholder="ご来店ありがとうございました">' + Utils.escapeHtml(settings.receipt_footer || '') + '</textarea>'
      + '</div>'
      + '<button class="btn btn-primary" id="btn-save-receipt-settings">設定を保存</button>'
      + '</div>';

    // 発行済み領収書セクション
    html += '<div style="margin-top:2rem;">'
      + '<h3 class="report-section__title">発行済み領収書</h3>'
      + '<div class="report-date-range" style="margin-bottom:1rem;">'
      + '<input type="date" id="rcpt-issued-date" value="' + today + '">'
      + '<button class="btn btn-sm btn-primary" id="btn-rcpt-issued-load">表示</button>'
      + '</div>'
      + '<div id="rcpt-issued-list"></div></div>';

    _container.innerHTML = html;

    // イベント
    document.getElementById('btn-save-receipt-settings').addEventListener('click', saveSettings);
    document.getElementById('btn-rcpt-issued-load').addEventListener('click', loadIssuedReceipts);

    document.getElementById('rcpt-issued-list').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-action="reprint-receipt"]');
      if (btn) reprintReceipt(btn.dataset.receiptId);
    });

    // 初期ロード
    loadIssuedReceipts();
  }

  function formField(label, id, value, placeholder, hint) {
    var h = '<div class="form-group">'
      + '<label class="form-label">' + Utils.escapeHtml(label) + '</label>'
      + '<input class="form-input" type="text" id="' + id + '" value="' + Utils.escapeHtml(value) + '" placeholder="' + Utils.escapeHtml(placeholder || '') + '">';
    if (hint) h += '<small style="color:#888;">' + Utils.escapeHtml(hint) + '</small>';
    h += '</div>';
    return h;
  }

  function saveSettings() {
    var data = {
      registration_number: document.getElementById('rcpt-reg-number').value.trim() || null,
      business_name: document.getElementById('rcpt-biz-name').value.trim() || null,
      receipt_store_name: document.getElementById('rcpt-store-name').value.trim() || null,
      receipt_address: document.getElementById('rcpt-address').value.trim() || null,
      receipt_phone: document.getElementById('rcpt-phone').value.trim() || null,
      receipt_footer: document.getElementById('rcpt-footer').value.trim() || null
    };

    AdminApi.updateReceiptSettings(data).then(function () {
      _showToast('設定を保存しました');
    }).catch(function (err) {
      _showToast(err.message, true);
    });
  }

  function loadIssuedReceipts() {
    var dateEl = document.getElementById('rcpt-issued-date');
    var el = document.getElementById('rcpt-issued-list');
    if (!el || !dateEl) return;
    el.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getReceiptsByDate(dateEl.value).then(function (data) {
      var receipts = data.receipts || [];
      if (receipts.length === 0) {
        el.innerHTML = '<div class="empty-state"><p class="empty-state__text">該当日の発行済み領収書はありません</p></div>';
        return;
      }
      var typeLabels = { receipt: '領収書', invoice: '適格簡易請求書' };
      var html = '<div class="card"><div class="data-table-wrap"><table class="data-table">'
        + '<thead><tr><th>番号</th><th>種類</th><th>宛名</th><th class="text-right">金額</th><th>発行日時</th><th>操作</th></tr></thead><tbody>';

      for (var i = 0; i < receipts.length; i++) {
        var r = receipts[i];
        html += '<tr>'
          + '<td>' + Utils.escapeHtml(r.receipt_number) + '</td>'
          + '<td>' + (typeLabels[r.receipt_type] || Utils.escapeHtml(r.receipt_type)) + '</td>'
          + '<td>' + Utils.escapeHtml(r.addressee || '-') + '</td>'
          + '<td class="text-right">' + Utils.formatYen(r.total_amount) + '</td>'
          + '<td>' + Utils.formatDateTime(r.issued_at) + '</td>'
          + '<td><button class="btn btn-sm btn-outline" data-action="reprint-receipt"'
          + ' data-receipt-id="' + Utils.escapeHtml(r.id) + '">再印刷</button></td>'
          + '</tr>';
      }

      html += '</tbody></table></div></div>';
      el.innerHTML = html;
    }).catch(function (err) {
      el.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function reprintReceipt(receiptId) {
    _showToast('読み込み中...');
    AdminApi.getReceiptDetail(receiptId).then(function (data) {
      if (AdminApi.logReceiptReprint) {
        AdminApi.logReceiptReprint(receiptId).catch(function () {});
      }
      _printReceiptHtml(data);
    }).catch(function (err) {
      _showToast(err.message || '読み込みエラー', true);
    });
  }

  function _printReceiptHtml(data) {
    var r = data.receipt;
    var s = data.store;
    var items = data.items || [];
    var pay = data.payment;

    var w = window.open('', '_blank', 'width=320,height=600');
    if (!w) {
      _showToast('ポップアップがブロックされました', true);
      return;
    }

    var h = '<!DOCTYPE html><html><head><meta charset="utf-8">';
    h += '<title>領収書</title>';
    h += '<style>';
    h += 'body{font-family:sans-serif;width:72mm;margin:0 auto;padding:4mm;font-size:12px;line-height:1.4;}';
    h += '.center{text-align:center;} .right{text-align:right;} .bold{font-weight:bold;}';
    h += '.divider{border-top:1px dashed #000;margin:4px 0;}';
    h += '.row{display:flex;justify-content:space-between;}';
    h += '.title{font-size:16px;font-weight:bold;text-align:center;margin:8px 0;letter-spacing:2px;}';
    h += '.small{font-size:10px;color:#555;}';
    h += '@media print{body{margin:0;padding:2mm;} .no-print{display:none;}}';
    h += '</style></head><body>';

    h += '<div class="title">' + (r.receipt_type === 'invoice' ? '適格簡易請求書' : '領収書') + '</div>';

    h += '<div class="center bold">' + _escPrint(r.addressee) + ' 様</div>';
    h += '<div class="divider"></div>';

    h += '<div class="center">' + _escPrint(s.receipt_store_name || '') + '</div>';
    if (s.receipt_address) h += '<div class="center small">' + _escPrint(s.receipt_address) + '</div>';
    if (s.receipt_phone) h += '<div class="center small">TEL: ' + _escPrint(s.receipt_phone) + '</div>';
    if (r.receipt_type === 'invoice' && s.registration_number) {
      h += '<div class="center small">登録番号: ' + _escPrint(s.registration_number) + '</div>';
      if (s.business_name) h += '<div class="center small">' + _escPrint(s.business_name) + '</div>';
    }
    h += '<div class="divider"></div>';

    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      var taxMark = (it.taxRate === 8 || it.tax_rate === 8) ? ' ※' : '';
      var lineTotal = it.price * it.qty;
      h += '<div class="row"><span>' + _escPrint(it.name) + taxMark + ' x' + it.qty + '</span><span>&yen;' + lineTotal.toLocaleString() + '</span></div>';
    }
    h += '<div class="divider"></div>';

    if (r.subtotal_10 > 0) {
      h += '<div class="row"><span>10%対象</span><span>&yen;' + (r.subtotal_10 + r.tax_10).toLocaleString() + '</span></div>';
      h += '<div class="row small"><span>　(税 &yen;' + r.tax_10.toLocaleString() + ')</span><span></span></div>';
    }
    if (r.subtotal_8 > 0) {
      h += '<div class="row"><span>8%対象 ※</span><span>&yen;' + (r.subtotal_8 + r.tax_8).toLocaleString() + '</span></div>';
      h += '<div class="row small"><span>　(税 &yen;' + r.tax_8.toLocaleString() + ')</span><span></span></div>';
    }

    h += '<div class="divider"></div>';
    h += '<div class="row bold" style="font-size:14px;"><span>合計</span><span>&yen;' + r.total_amount.toLocaleString() + '</span></div>';

    var methodLabel = {cash:'現金',card:'カード',qr:'QR決済',terminal:'カード'}[pay.payment_method] || pay.payment_method;
    h += '<div class="row"><span>支払方法</span><span>' + methodLabel + '</span></div>';

    h += '<div class="divider"></div>';
    h += '<div class="center small">No. ' + _escPrint(r.receipt_number) + '</div>';
    h += '<div class="center small">' + _escPrint(r.issued_at) + '</div>';

    if (s.receipt_footer) {
      h += '<div class="divider"></div>';
      h += '<div class="center small">' + _escPrint(s.receipt_footer) + '</div>';
    }

    h += '</body></html>';

    w.document.write(h);
    w.document.close();
    w.focus();
    w.print();
    w.onafterprint = function () { w.close(); };
  }

  function _escPrint(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function _showToast(msg, isError) {
    var el = document.getElementById('admin-toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'admin-toast show' + (isError ? ' error' : '');
    setTimeout(function () { el.className = 'admin-toast'; }, 3000);
  }

  return { init: init, load: load };
})();
