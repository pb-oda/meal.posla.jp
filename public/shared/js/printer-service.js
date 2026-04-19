/* U-2 プリンタ共通サービス (ES5 IIFE)
   対応機種:
     - Star mC-Print3 (WebPRNT HTTP API)
     - Epson TM-m30 系 (ePOS-Print HTTP API)
     - ブラウザ標準 (window.print)
   公開: window.PrinterService = { print(config, jobType, lines) }
*/

(function () {
  'use strict';

  // ---------- 公開関数 ----------
  function print(config, jobType, lines) {
    // config = { printer_type, printer_ip, printer_port, printer_paper_width }
    // jobType = 'receipt' | 'kitchen' | 'refund'
    // lines   = [ { type:'text'|'line'|'qr'|'barcode', ...}, ... ]
    var type = config && config.printer_type ? config.printer_type : 'browser';
    if (type === 'none') return Promise.resolve({ success: true, note: 'printer_disabled' });
    if (type === 'browser') {
      return _printBrowser(lines, jobType);
    }
    if (type === 'star') {
      return _printStar(config, lines);
    }
    if (type === 'epson') {
      return _printEpson(config, lines);
    }
    return Promise.reject(new Error('Unknown printer_type: ' + type));
  }

  // ---------- ブラウザ印刷 ----------
  function _printBrowser(lines, jobType) {
    return new Promise(function (resolve) {
      var w = window.open('', '_blank', 'width=400,height=600');
      if (!w) { resolve({ success: false, error: 'popup_blocked' }); return; }
      var html = '<html><head><title>' + (jobType || 'print') + '</title>';
      html += '<style>body{font-family:monospace;font-size:12px;margin:8px;}'
           + '.center{text-align:center;}.large{font-size:1.4em;font-weight:bold;}'
           + '.line{border-top:1px dashed #888;margin:6px 0;}</style>';
      html += '</head><body>';
      for (var i = 0; i < lines.length; i++) {
        html += _lineToHtml(lines[i]);
      }
      html += '</body></html>';
      w.document.write(html);
      w.document.close();
      setTimeout(function () {
        w.print();
        resolve({ success: true });
      }, 200);
    });
  }

  function _lineToHtml(l) {
    var esc = function (s) {
      if (s === null || s === undefined) return '';
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    };
    if (l.type === 'line') return '<div class="line"></div>';
    if (l.type === 'text') {
      var cls = [];
      if (l.align === 'center') cls.push('center');
      if (l.size === 'large') cls.push('large');
      return '<div' + (cls.length ? ' class="' + cls.join(' ') + '"' : '') + '>' + esc(l.text || '') + '</div>';
    }
    if (l.type === 'qr' || l.type === 'barcode') {
      return '<div class="center">[' + l.type.toUpperCase() + ': ' + esc(l.data || '') + ']</div>';
    }
    return '';
  }

  // ---------- Star WebPRNT ----------
  // 仕様: https://www.star-m.jp/prjump/sm-webprnt-spec.html
  // XML を HTTP POST
  function _printStar(config, lines) {
    var ip = config.printer_ip;
    if (!ip) return Promise.reject(new Error('printer_ip が未設定'));
    var url = 'http://' + ip + ':' + (config.printer_port || 80) + '/StarWebPRNT/SendMessage';

    var xml = '<?xml version="1.0" encoding="UTF-8"?>';
    xml += '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">';
    for (var i = 0; i < lines.length; i++) {
      xml += _lineToStarXml(lines[i]);
    }
    xml += '<cut type="feed"/>';
    xml += '</epos-print>';

    return _httpPost(url, xml, 'text/xml; charset=utf-8');
  }

  function _lineToStarXml(l) {
    if (l.type === 'line') return '<text>--------------------------------&#10;</text>';
    if (l.type === 'text') {
      var x = '';
      if (l.align === 'center') x += '<text align="center"/>';
      if (l.align === 'right') x += '<text align="right"/>';
      if (l.size === 'large') x += '<text width="2" height="2"/>';
      x += '<text>' + _xmlEsc(l.text || '') + '&#10;</text>';
      if (l.size === 'large') x += '<text width="1" height="1"/>';
      if (l.align !== 'left') x += '<text align="left"/>';
      return x;
    }
    if (l.type === 'qr') {
      return '<symbol type="qrcode_model_2" level="level_m" width="5">' + _xmlEsc(l.data || '') + '</symbol>';
    }
    if (l.type === 'barcode') {
      return '<barcode type="code39" hri="below" width="2" height="48">' + _xmlEsc(l.data || '') + '</barcode>';
    }
    return '';
  }

  // ---------- Epson ePOS-Print ----------
  // 仕様: https://files.support.epson.com/pdf/pos/bulk/eposprint_api_j.pdf
  function _printEpson(config, lines) {
    var ip = config.printer_ip;
    if (!ip) return Promise.reject(new Error('printer_ip が未設定'));
    var url = 'http://' + ip + ':' + (config.printer_port || 80) + '/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000';

    var xml = '<?xml version="1.0" encoding="utf-8"?>';
    xml += '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">';
    xml += '<s:Body>';
    xml += '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">';
    for (var i = 0; i < lines.length; i++) {
      xml += _lineToStarXml(lines[i]); // ePOS も仕様は近い
    }
    xml += '<cut type="feed"/>';
    xml += '</epos-print>';
    xml += '</s:Body></s:Envelope>';

    return _httpPost(url, xml, 'text/xml; charset=utf-8', { 'SOAPAction': '""' });
  }

  // ---------- 共通 HTTP POST ----------
  function _httpPost(url, body, contentType, extraHeaders) {
    return new Promise(function (resolve) {
      try {
        var x = new XMLHttpRequest();
        x.open('POST', url, true);
        x.setRequestHeader('Content-Type', contentType || 'text/xml');
        if (extraHeaders) {
          for (var k in extraHeaders) {
            if (extraHeaders.hasOwnProperty(k)) x.setRequestHeader(k, extraHeaders[k]);
          }
        }
        x.timeout = 10000;
        x.onload = function () {
          if (x.status >= 200 && x.status < 300) resolve({ success: true, response: x.responseText });
          else resolve({ success: false, error: 'http_' + x.status, response: x.responseText });
        };
        x.onerror = function () { resolve({ success: false, error: 'network_error' }); };
        x.ontimeout = function () { resolve({ success: false, error: 'timeout' }); };
        x.send(body);
      } catch (e) {
        resolve({ success: false, error: String(e) });
      }
    });
  }

  function _xmlEsc(s) {
    return String(s).replace(/[&<>'"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&apos;', '"': '&quot;' }[c];
    });
  }

  // ---------- 共通 expose ----------
  window.PrinterService = {
    print: print,
    // テンプレート: レシート形式
    buildReceiptLines: function (store, order) {
      var lines = [];
      lines.push({ type: 'text', align: 'center', size: 'large', text: store.name || '' });
      if (store.phone) lines.push({ type: 'text', align: 'center', text: 'TEL: ' + store.phone });
      if (store.address) lines.push({ type: 'text', align: 'center', text: store.address });
      lines.push({ type: 'line' });
      lines.push({ type: 'text', text: '日時: ' + (order.paid_at || order.created_at || '') });
      lines.push({ type: 'text', text: 'テーブル: ' + (order.table_code || '-') });
      if (order.order_id) lines.push({ type: 'text', text: '注文番号: ' + order.order_id });
      lines.push({ type: 'line' });
      (order.items || []).forEach(function (it) {
        lines.push({ type: 'text', text: (it.name || '') + ' x' + (it.qty || 1) + '  ¥' + (it.subtotal || it.price * it.qty || 0).toLocaleString() });
      });
      lines.push({ type: 'line' });
      lines.push({ type: 'text', align: 'right', size: 'large', text: '合計 ¥' + (order.total || 0).toLocaleString() });
      if (order.payment_method) lines.push({ type: 'text', align: 'right', text: '支払: ' + order.payment_method });
      lines.push({ type: 'line' });
      lines.push({ type: 'text', align: 'center', text: 'ありがとうございました' });
      return lines;
    },
    // テンプレート: キッチン伝票
    buildKitchenLines: function (store, order) {
      var lines = [];
      lines.push({ type: 'text', align: 'center', size: 'large', text: '★ キッチン伝票 ★' });
      lines.push({ type: 'text', text: 'テーブル: ' + (order.table_code || '-') });
      lines.push({ type: 'text', text: '時刻: ' + (order.created_at || '') });
      lines.push({ type: 'line' });
      (order.items || []).forEach(function (it) {
        lines.push({ type: 'text', size: 'large', text: (it.name || '') + '  x' + (it.qty || 1) });
        if (it.memo) lines.push({ type: 'text', text: '  ※ ' + it.memo });
      });
      lines.push({ type: 'line' });
      if (order.memo) lines.push({ type: 'text', text: '全体メモ: ' + order.memo });
      return lines;
    }
  };
})();
