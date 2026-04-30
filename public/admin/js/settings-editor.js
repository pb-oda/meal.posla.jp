/**
 * 店舗設定エディタ
 */
var SettingsEditor = (function () {
  'use strict';

  var _container = null;

  function init(container) { _container = container; }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getSettings().then(function (res) {
      renderForm(res.settings || {});
    }).catch(function (err) {
      _container.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function renderForm(s) {
    var payMethods = (s.payment_methods_enabled || 'cash,card,qr').split(',');

    _container.innerHTML =
      '<div class="card"><div class="card__body">'
      + '<h3 style="margin-bottom:1.5rem;font-size:1rem">営業設定</h3>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">営業日区切り時刻</label><input class="form-input" id="set-cutoff" type="time" value="' + (s.day_cutoff_time || '05:00') + '"></div>'
      + '<div class="form-group"><label class="form-label">税率 (%)</label><input class="form-input" id="set-tax" type="number" step="0.1" value="' + (s.tax_rate || 10) + '"></div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">1回の注文品数上限</label><input class="form-input" id="set-max-items" type="number" value="' + (s.max_items_per_order || 10) + '"></div>'
      + '<div class="form-group"><label class="form-label">1回の注文金額上限</label><input class="form-input" id="set-max-amount" type="number" value="' + (s.max_amount_per_order || 30000) + '"></div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">ラストオーダー時刻</label><input class="form-input" id="set-lo-time" type="time" value="' + (s.last_order_time ? s.last_order_time.substring(0, 5) : '') + '" placeholder="空欄=無効"></div>'
      + '<div class="form-group"><label class="form-label" style="visibility:hidden">-</label><span style="font-size:0.75rem;color:#888">空欄で無効。例: 21:30</span></div></div>'

      + '<h3 style="margin:2rem 0 1rem;font-size:1rem">レジ設定</h3>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">デフォルト開店金額</label><input class="form-input" id="set-open" type="number" value="' + (s.default_open_amount || 30000) + '"></div>'
      + '<div class="form-group"><label class="form-label">過不足閾値</label><input class="form-input" id="set-overshort" type="number" value="' + (s.overshort_threshold || 500) + '"></div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">レジ締め予定時刻</label><input class="form-input" id="set-register-close-time" type="time" value="' + (s.register_close_time ? s.register_close_time.substring(0, 5) : '') + '" placeholder="空欄=LO+60分"></div>'
      + '<div class="form-group"><label class="form-label">締め忘れ警告 猶予分</label><input class="form-input" id="set-register-close-grace" type="number" min="0" value="' + (s.register_close_grace_min || 30) + '"></div></div>'
      + '<div class="form-group"><label class="form-label">締め忘れ警告</label>'
      + '<div class="settings-toggle-group">'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input" id="set-register-close-alert"' + (parseInt(s.register_close_alert_enabled, 10) !== 0 ? ' checked' : '') + '> 有効</label>'
      + '</div>'
      + '<div style="font-size:0.75rem;color:#888;margin-top:0.25rem">予定時刻＋猶予分を過ぎてもレジが開いたままの場合、管理画面とレジ画面に警告します。予定時刻が空欄の場合はラストオーダー+60分を暫定基準にします。</div></div>'
      + '<div class="form-group"><label class="form-label">利用可能な支払方法</label>'
      + '<div class="settings-toggle-group">'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input pay-cb" value="cash"' + (payMethods.indexOf('cash') >= 0 ? ' checked' : '') + '> 現金</label>'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input pay-cb" value="card"' + (payMethods.indexOf('card') >= 0 ? ' checked' : '') + '> カード</label>'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input pay-cb" value="qr"' + (payMethods.indexOf('qr') >= 0 ? ' checked' : '') + '> QR決済</label></div></div>'

      + '<div class="form-group"><label class="form-label">セルフレジ</label>'
      + '<div class="settings-toggle-group">'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input" id="set-self-checkout"' + (parseInt(s.self_checkout_enabled, 10) ? ' checked' : '') + '> 有効</label>'
      + '</div>'
      + '<div style="font-size:0.75rem;color:#888;margin-top:0.25rem">顧客がスマホで自己決済できる機能。事前にオーナー管理画面で決済ゲートウェイ（Stripe）を設定してから有効にしてください。</div></div>'

      + '<h3 style="margin:2rem 0 1rem;font-size:1rem">レシート設定</h3>'
      + '<div class="form-group"><label class="form-label">店舗名</label><input class="form-input" id="set-receipt-name" value="' + Utils.escapeHtml(s.receipt_store_name || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">住所</label><input class="form-input" id="set-receipt-addr" value="' + Utils.escapeHtml(s.receipt_address || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">電話番号</label><input class="form-input" id="set-receipt-phone" value="' + Utils.escapeHtml(s.receipt_phone || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">フッター</label><textarea class="form-input" id="set-receipt-footer">' + Utils.escapeHtml(s.receipt_footer || '') + '</textarea></div>'

      + '<h3 style="margin:2rem 0 1rem;font-size:1rem">🖨 プリンタ設定 (U-2)</h3>'
      + '<div style="font-size:0.75rem;color:#888;margin-bottom:0.75rem">Star mC-Print3 / Epson TM-m30 (Wi-Fi/Ethernet 接続) 対応。ドライバインストール不要。詳細は <a href="../../docs/printer-guide.md" target="_blank">プリンタガイド</a> 参照。</div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">プリンタ種別</label>'
      + '<select class="form-input" id="set-printer-type">'
      + '<option value="browser"' + ((s.printer_type === 'browser' || !s.printer_type) ? ' selected' : '') + '>ブラウザ標準 (初期)</option>'
      + '<option value="star"' + (s.printer_type === 'star' ? ' selected' : '') + '>Star mC-Print3 (WebPRNT)</option>'
      + '<option value="epson"' + (s.printer_type === 'epson' ? ' selected' : '') + '>Epson TM-m30 (ePOS-Print)</option>'
      + '<option value="none"' + (s.printer_type === 'none' ? ' selected' : '') + '>無効</option>'
      + '</select></div>'
      + '<div class="form-group"><label class="form-label">プリンタ IP</label>'
      + '<input class="form-input" id="set-printer-ip" value="' + Utils.escapeHtml(s.printer_ip || '') + '" placeholder="例: 192.168.1.50"></div>'
      + '</div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">ポート</label>'
      + '<input class="form-input" id="set-printer-port" type="number" min="1" max="65535" value="' + (s.printer_port || 80) + '"></div>'
      + '<div class="form-group"><label class="form-label">用紙幅 (mm)</label>'
      + '<select class="form-input" id="set-printer-width">'
      + '<option value="58"' + ((s.printer_paper_width === 58 || s.printer_paper_width === '58') ? ' selected' : '') + '>58mm</option>'
      + '<option value="80"' + ((!s.printer_paper_width || s.printer_paper_width === 80 || s.printer_paper_width === '80') ? ' selected' : '') + '>80mm</option>'
      + '</select></div>'
      + '</div>'
      + '<div class="form-group"><label class="form-label">自動印刷</label>'
      + '<div class="settings-toggle-group">'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input" id="set-printer-auto-kitchen"' + (parseInt(s.printer_auto_kitchen, 10) ? ' checked' : '') + '> 注文確定時にキッチン伝票を自動印刷</label>'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input" id="set-printer-auto-receipt"' + ((parseInt(s.printer_auto_receipt, 10) || s.printer_auto_receipt === undefined) ? ' checked' : '') + '> 会計完了時にレシートを自動印刷</label>'
      + '</div></div>'
      + '<div style="margin-bottom:1.5rem"><button type="button" id="btn-test-print" style="padding:0.5rem 1rem;border:1px solid #43a047;color:#43a047;background:#fff;border-radius:4px;cursor:pointer;">🖨 テスト印刷</button></div>'

      + '<h3 style="margin:2rem 0 1rem;font-size:1rem">Googleレビュー設定</h3>'
      + '<div class="form-group"><label class="form-label">Google Place ID</label><input class="form-input" id="set-google-place-id" value="' + Utils.escapeHtml(s.google_place_id || '') + '" placeholder="例: ChIJxxxxxx"></div>'
      + '<div style="font-size:0.75rem;color:#888;margin-top:-0.5rem;margin-bottom:1rem">高評価（4-5点）のお客様にGoogleレビューへの誘導リンクを表示します。<br>Place IDは <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" style="color:#1976d2">Google Place ID Finder</a> で検索できます。</div>'

      + '<h3 style="margin:2rem 0 1rem;font-size:1rem">テイクアウト設定</h3>'
      + '<div class="form-group"><label class="form-label">テイクアウト受付</label>'
      + '<div class="settings-toggle-group">'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input" id="set-takeout-enabled"' + (parseInt(s.takeout_enabled, 10) ? ' checked' : '') + '> 有効</label>'
      + '</div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">最短準備時間（分）</label><input class="form-input" id="set-takeout-prep" type="number" value="' + (s.takeout_min_prep_minutes || 30) + '"></div>'
      + '<div class="form-group"><label class="form-label">時間枠あたり最大注文数</label><input class="form-input" id="set-takeout-capacity" type="number" value="' + (s.takeout_slot_capacity || 5) + '"></div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">時間枠あたり最大品数（0=無制限）</label><input class="form-input" id="set-takeout-item-capacity" type="number" min="0" value="' + (s.takeout_slot_item_capacity || 0) + '"></div>'
      + '<div class="form-group"><label class="form-label">混雑時追加待ち時間（分）</label><input class="form-input" id="set-takeout-delay" type="number" min="0" value="' + (s.takeout_acceptance_delay_minutes || 0) + '"></div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">受付開始時刻</label><input class="form-input" id="set-takeout-from" type="time" value="' + (s.takeout_available_from ? s.takeout_available_from.substring(0, 5) : '10:00') + '"></div>'
      + '<div class="form-group"><label class="form-label">受付終了時刻</label><input class="form-input" id="set-takeout-to" type="time" value="' + (s.takeout_available_to ? s.takeout_available_to.substring(0, 5) : '20:00') + '"></div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">ピーク開始時刻</label><input class="form-input" id="set-takeout-peak-from" type="time" value="' + (s.takeout_peak_start_time ? s.takeout_peak_start_time.substring(0, 5) : '') + '"></div>'
      + '<div class="form-group"><label class="form-label">ピーク終了時刻</label><input class="form-input" id="set-takeout-peak-to" type="time" value="' + (s.takeout_peak_end_time ? s.takeout_peak_end_time.substring(0, 5) : '') + '"></div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">ピーク最大注文数（0=通常設定）</label><input class="form-input" id="set-takeout-peak-capacity" type="number" min="0" value="' + (s.takeout_peak_slot_capacity || 0) + '"></div>'
      + '<div class="form-group"><label class="form-label">ピーク最大品数（0=通常設定）</label><input class="form-input" id="set-takeout-peak-item-capacity" type="number" min="0" value="' + (s.takeout_peak_slot_item_capacity || 0) + '"></div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">SLA警告（受取何分前）</label><input class="form-input" id="set-takeout-sla-warning" type="number" min="1" value="' + (s.takeout_sla_warning_minutes || 10) + '"></div>'
      + '</div>'
      + '<div class="form-group"><label class="form-label">オンライン決済</label>'
      + '<div class="settings-toggle-group">'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input" id="set-takeout-online"' + (parseInt(s.takeout_online_payment, 10) ? ' checked' : '') + '> 有効</label>'
      + '</div>'
      + '<div style="font-size:0.75rem;color:#888;margin-top:0.25rem">オーナー管理画面で決済ゲートウェイ（Stripe）を設定してから有効にしてください。</div></div>'
      + '<div id="takeout-qr-section" style="display:none;margin-top:1rem;padding:1rem;background:#f5f5f5;border-radius:8px;text-align:center;">'
      + '<p style="font-size:0.875rem;color:#333;margin-bottom:0.5rem;">テイクアウト注文ページ</p>'
      + '<div id="takeout-qr-code" style="display:inline-block;margin-bottom:0.5rem;"></div>'
      + '<p id="takeout-url-text" style="font-size:0.75rem;color:#666;word-break:break-all;margin-bottom:0.75rem;"></p>'
      + '<div style="display:flex;gap:0.5rem;justify-content:center;flex-wrap:wrap;">'
      + '<button type="button" id="btn-copy-takeout-url" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #2196F3;color:#2196F3;background:#fff;border-radius:4px;cursor:pointer;">URLをコピー</button>'
      + '<button type="button" id="btn-copy-takeout-qr" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #4CAF50;color:#4CAF50;background:#fff;border-radius:4px;cursor:pointer;">QR画像コピー</button>'
      + '<button type="button" id="btn-print-takeout-qr" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #ff6f00;color:#ff6f00;background:#fff;border-radius:4px;cursor:pointer;">印刷</button>'
      + '</div></div>'

      + '<h3 style="margin:2rem 0 1rem;font-size:1rem">📅 予約LP (L-9)</h3>'
      + '<div style="font-size:0.75rem;color:#888;margin-bottom:0.5rem">お客様が空き状況を見ながらオンライン予約できるページのURLとQRコードです。Googleマイビジネス・Instagram・店頭ポスターに掲載してください。<br>※「予約管理 > 予約設定」でオンライン予約を有効化していないと表示されません。</div>'
      + '<div id="reserve-qr-section" style="margin-top:0.5rem;padding:1rem;background:#fff7e6;border-radius:8px;text-align:center;">'
      + '<p style="font-size:0.875rem;color:#333;margin-bottom:0.5rem;">予約・空き状況確認ページ</p>'
      + '<div id="reserve-qr-code" style="display:inline-block;margin-bottom:0.5rem;"></div>'
      + '<p id="reserve-url-text" style="font-size:0.75rem;color:#666;word-break:break-all;margin-bottom:0.75rem;"></p>'
      + '<div style="display:flex;gap:0.5rem;justify-content:center;flex-wrap:wrap;">'
      + '<button type="button" id="btn-copy-reserve-url" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #ef6c00;color:#ef6c00;background:#fff;border-radius:4px;cursor:pointer;">URLをコピー</button>'
      + '<button type="button" id="btn-open-reserve" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #1976d2;color:#1976d2;background:#fff;border-radius:4px;cursor:pointer;">新タブで開く</button>'
      + '<button type="button" id="btn-copy-reserve-qr" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #4CAF50;color:#4CAF50;background:#fff;border-radius:4px;cursor:pointer;">QR画像コピー</button>'
      + '<button type="button" id="btn-print-reserve-qr" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #ff6f00;color:#ff6f00;background:#fff;border-radius:4px;cursor:pointer;">印刷</button>'
      + '</div></div>'

      + '<h3 style="margin:2rem 0 1rem;font-size:1rem">外観カスタマイズ</h3>'
      + '<div style="font-size:0.75rem;color:#888;margin-bottom:1rem">セルフオーダー画面（menu.html）とテイクアウト画面の外観をカスタマイズできます。未設定の場合はデフォルト配色が使われます。</div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">テーマカラー</label>'
      + '<div style="display:flex;gap:0.5rem;align-items:center;">'
      + '<input type="color" id="set-brand-color-picker" value="' + (s.brand_color || '#000000') + '" style="width:40px;height:32px;padding:0;border:1px solid #ccc;border-radius:4px;cursor:pointer;">'
      + '<input class="form-input" id="set-brand-color" type="text" value="' + Utils.escapeHtml(s.brand_color || '') + '" placeholder="未設定=デフォルト配色" maxlength="7" style="width:120px;">'
      + '<button type="button" id="btn-brand-color-clear" style="padding:0.25rem 0.5rem;font-size:0.75rem;border:1px solid #ccc;background:#fff;border-radius:4px;cursor:pointer;color:#888;">デフォルトに戻す</button>'
      + '</div>'
      + '<div style="display:flex;gap:1rem;align-items:center;margin-top:0.375rem;font-size:0.75rem;color:#888;">'
      + '<span>デフォルト:</span>'
      + '<span style="display:inline-flex;align-items:center;gap:0.25rem;"><span style="display:inline-block;width:14px;height:14px;background:#ff6f00;border-radius:3px;border:1px solid #ddd;vertical-align:middle;"></span> セルフオーダー #ff6f00</span>'
      + '<span style="display:inline-flex;align-items:center;gap:0.25rem;"><span style="display:inline-block;width:14px;height:14px;background:#2196F3;border-radius:3px;border:1px solid #ddd;vertical-align:middle;"></span> テイクアウト #2196F3</span>'
      + '</div></div>'
      + '<div class="form-group"><label class="form-label">表示名</label><input class="form-input" id="set-brand-display-name" value="' + Utils.escapeHtml(s.brand_display_name || '') + '" placeholder="ヘッダーに表示する店舗名"></div>'
      + '</div>'
      + '<div class="form-group"><label class="form-label">ロゴ画像URL</label><input class="form-input" id="set-brand-logo-url" value="' + Utils.escapeHtml(s.brand_logo_url || '') + '" placeholder="https://example.com/logo.png"></div>'
      + '<div id="brand-preview" style="margin-top:0.5rem;display:none;"><img id="brand-logo-preview" style="max-height:40px;border:1px solid #eee;border-radius:4px;" alt="ロゴプレビュー"></div>'

      + '<div style="margin-top:2rem"><button class="btn btn-primary" id="btn-save-settings">設定を保存</button></div>'
      + '</div></div>';

    // テイクアウトQRコード
    function _loadQRLib(cb) {
      if (window.QRCode) { cb(); return; }
      var s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
      s.onload = cb;
      document.head.appendChild(s);
    }

    function _getTakeoutUrl() {
      var origin = location.origin;
      var path = location.pathname.replace(/\/admin\/.*$/, '/customer/takeout.html');
      return origin + path + '?store_id=' + encodeURIComponent(AdminApi.getCurrentStore());
    }

    function _updateTakeoutQR(enabled) {
      var sec = document.getElementById('takeout-qr-section');
      if (!sec) return;
      if (!enabled) { sec.style.display = 'none'; return; }
      sec.style.display = '';
      var url = _getTakeoutUrl();
      var urlEl = document.getElementById('takeout-url-text');
      if (urlEl) urlEl.textContent = url;
      var qrEl = document.getElementById('takeout-qr-code');
      if (qrEl) {
        qrEl.innerHTML = '';
        _loadQRLib(function () {
          new QRCode(qrEl, { text: url, width: 200, height: 200, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
        });
      }
    }

    var takeoutToggle = document.getElementById('set-takeout-enabled');
    if (takeoutToggle) {
      takeoutToggle.addEventListener('change', function () { _updateTakeoutQR(this.checked); });
    }
    _updateTakeoutQR(parseInt(s.takeout_enabled, 10) === 1);

    // 予約LP QR (L-9)
    function _getReserveUrl() {
      var origin = location.origin;
      var path = location.pathname.replace(/\/admin\/.*$/, '/customer/reserve.html');
      return origin + path + '?store_id=' + encodeURIComponent(AdminApi.getCurrentStore());
    }
    function _renderReserveQR() {
      var url = _getReserveUrl();
      var urlEl = document.getElementById('reserve-url-text');
      if (urlEl) urlEl.textContent = url;
      var qrEl = document.getElementById('reserve-qr-code');
      if (qrEl) {
        qrEl.innerHTML = '';
        _loadQRLib(function () {
          new QRCode(qrEl, { text: url, width: 200, height: 200, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
        });
      }
    }
    _renderReserveQR();

    // U-2: テスト印刷
    var btnTestPrint = document.getElementById('btn-test-print');
    if (btnTestPrint) {
      btnTestPrint.addEventListener('click', function () {
        if (!window.PrinterService) { showToast('printer-service.js が未ロード', 'error'); return; }
        var config = {
          printer_type: document.getElementById('set-printer-type').value,
          printer_ip: document.getElementById('set-printer-ip').value.trim() || null,
          printer_port: parseInt(document.getElementById('set-printer-port').value, 10) || 80,
          printer_paper_width: parseInt(document.getElementById('set-printer-width').value, 10) || 80
        };
        var testLines = [
          { type: 'text', align: 'center', size: 'large', text: 'POSLA プリンタテスト' },
          { type: 'text', align: 'center', text: new Date().toLocaleString('ja-JP') },
          { type: 'line' },
          { type: 'text', text: 'プリンタ種別: ' + config.printer_type },
          { type: 'text', text: 'IP: ' + (config.printer_ip || '(未設定)') },
          { type: 'text', text: '用紙幅: ' + config.printer_paper_width + 'mm' },
          { type: 'line' },
          { type: 'text', align: 'center', text: '正常に印刷されました' }
        ];
        window.PrinterService.print(config, 'test', testLines).then(function (r) {
          if (r.success) showToast('テスト印刷を送信しました', 'success');
          else showToast('印刷失敗: ' + (r.error || 'unknown'), 'error');
        }).catch(function (e) { showToast('エラー: ' + e.message, 'error'); });
      });
    }

    var btnCopyReserveUrl = document.getElementById('btn-copy-reserve-url');
    if (btnCopyReserveUrl) {
      btnCopyReserveUrl.addEventListener('click', function () {
        var url = _getReserveUrl();
        if (navigator.clipboard) {
          navigator.clipboard.writeText(url).then(function () { showToast('URLをコピーしました', 'success'); });
        } else {
          var ta = document.createElement('textarea');
          ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
          showToast('URLをコピーしました', 'success');
        }
      });
    }
    var btnOpenReserve = document.getElementById('btn-open-reserve');
    if (btnOpenReserve) {
      btnOpenReserve.addEventListener('click', function () { window.open(_getReserveUrl(), '_blank'); });
    }
    var btnCopyReserveQr = document.getElementById('btn-copy-reserve-qr');
    if (btnCopyReserveQr) {
      btnCopyReserveQr.addEventListener('click', function () {
        var canvas = document.querySelector('#reserve-qr-code canvas');
        if (!canvas) { showToast('QRコードが生成されていません', 'error'); return; }
        canvas.toBlob(function (blob) {
          if (navigator.clipboard && navigator.clipboard.write) {
            navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () { showToast('QR画像をコピーしました', 'success'); });
          } else {
            showToast('お使いのブラウザは画像コピーに対応していません', 'error');
          }
        });
      });
    }
    var btnPrintReserveQr = document.getElementById('btn-print-reserve-qr');
    if (btnPrintReserveQr) {
      btnPrintReserveQr.addEventListener('click', function () {
        var canvas = document.querySelector('#reserve-qr-code canvas');
        if (!canvas) { showToast('QRコードが生成されていません', 'error'); return; }
        var dataUrl = canvas.toDataURL('image/png');
        var url = _getReserveUrl();
        var w = window.open('', '_blank');
        w.document.write(
          '<html><head><title>予約LP QRコード</title>' +
          '<style>body{font-family:sans-serif;text-align:center;padding:32px;}img{margin:24px 0;}p{font-size:0.85rem;color:#555;word-break:break-all;}</style>' +
          '</head><body>' +
          '<h2>📅 ご予約はこちら</h2>' +
          '<img src="' + dataUrl + '" width="320" height="320">' +
          '<p>' + url + '</p>' +
          '<p>QRコードを読み取ると予約ページが開きます</p>' +
          '</body></html>'
        );
        w.document.close();
        setTimeout(function () { w.print(); }, 200);
      });
    }

    // URLコピー
    var btnCopyUrl = document.getElementById('btn-copy-takeout-url');
    if (btnCopyUrl) {
      btnCopyUrl.addEventListener('click', function () {
        var url = _getTakeoutUrl();
        if (navigator.clipboard) {
          navigator.clipboard.writeText(url).then(function () { showToast('URLをコピーしました', 'success'); });
        } else {
          var ta = document.createElement('textarea');
          ta.value = url;
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
          showToast('URLをコピーしました', 'success');
        }
      });
    }

    // QR画像コピー
    var btnCopyQr = document.getElementById('btn-copy-takeout-qr');
    if (btnCopyQr) {
      btnCopyQr.addEventListener('click', function () {
        var canvas = document.querySelector('#takeout-qr-code canvas');
        if (!canvas) { showToast('QRコードが生成されていません', 'error'); return; }
        canvas.toBlob(function (blob) {
          if (navigator.clipboard && navigator.clipboard.write) {
            var item = new ClipboardItem({ 'image/png': blob });
            navigator.clipboard.write([item]).then(function () { showToast('QR画像をコピーしました', 'success'); });
          } else {
            showToast('このブラウザでは画像コピーに対応していません', 'error');
          }
        }, 'image/png');
      });
    }

    // QR印刷
    var btnPrintQr = document.getElementById('btn-print-takeout-qr');
    if (btnPrintQr) {
      btnPrintQr.addEventListener('click', function () {
        var canvas = document.querySelector('#takeout-qr-code canvas');
        if (!canvas) { showToast('QRコードが生成されていません', 'error'); return; }
        var dataUrl = canvas.toDataURL('image/png');
        var url = _getTakeoutUrl();
        var pw = window.open('', '_blank', 'width=400,height=600');
        pw.document.write(
          '<html><head><title>テイクアウトQRコード</title>'
          + '<style>body{text-align:center;font-family:sans-serif;padding:2rem;}'
          + 'img{width:200px;height:200px;margin:1rem 0;}'
          + 'h2{font-size:1.25rem;margin-bottom:0.5rem;}'
          + 'p{font-size:0.75rem;color:#666;word-break:break-all;}</style></head>'
          + '<body><h2>テイクアウト注文</h2>'
          + '<p>スマホで読み取って事前注文できます</p>'
          + '<img src="' + dataUrl + '">'
          + '<p>' + Utils.escapeHtml(url) + '</p>'
          + '</body></html>'
        );
        pw.document.close();
        pw.onload = function () { pw.print(); };
      });
    }

    // ブランドカラー: picker ↔ text 双方向同期
    var _brandPicker = document.getElementById('set-brand-color-picker');
    var _brandText = document.getElementById('set-brand-color');
    if (_brandPicker && _brandText) {
      _brandPicker.addEventListener('input', function () { _brandText.value = this.value; });
      _brandText.addEventListener('input', function () {
        if (/^#[0-9a-fA-F]{6}$/.test(this.value)) _brandPicker.value = this.value;
      });
    }
    var _brandClearBtn = document.getElementById('btn-brand-color-clear');
    if (_brandClearBtn) {
      _brandClearBtn.addEventListener('click', function () {
        if (_brandText) _brandText.value = '';
        if (_brandPicker) _brandPicker.value = '#000000';
      });
    }
    // ロゴプレビュー
    var _logoInput = document.getElementById('set-brand-logo-url');
    var _logoPrev = document.getElementById('brand-preview');
    var _logoImg = document.getElementById('brand-logo-preview');
    if (_logoInput && _logoPrev && _logoImg) {
      function _updateLogoPreview() {
        var url = _logoInput.value.trim();
        if (url) {
          _logoImg.src = url;
          _logoImg.onerror = function () { _logoPrev.style.display = 'none'; };
          _logoPrev.style.display = '';
        } else {
          _logoPrev.style.display = 'none';
        }
      }
      _logoInput.addEventListener('change', _updateLogoPreview);
      _updateLogoPreview();
    }

    document.getElementById('btn-save-settings').addEventListener('click', function () {
      var payChecked = [];
      document.querySelectorAll('.pay-cb:checked').forEach(function (cb) { payChecked.push(cb.value); });

      var loTimeVal = document.getElementById('set-lo-time') ? document.getElementById('set-lo-time').value : '';
      var regCloseTimeVal = document.getElementById('set-register-close-time') ? document.getElementById('set-register-close-time').value : '';
      var takeoutPeakFrom = document.getElementById('set-takeout-peak-from') ? document.getElementById('set-takeout-peak-from').value : '';
      var takeoutPeakTo = document.getElementById('set-takeout-peak-to') ? document.getElementById('set-takeout-peak-to').value : '';
      var payload = {
        day_cutoff_time: (document.getElementById('set-cutoff').value || '05:00').replace(/^(\d{2}:\d{2})$/, '$1:00'),
        tax_rate: parseFloat(document.getElementById('set-tax').value) || 10,
        max_items_per_order: parseInt(document.getElementById('set-max-items').value, 10) || 10,
        max_amount_per_order: parseInt(document.getElementById('set-max-amount').value, 10) || 30000,
        last_order_time: loTimeVal ? loTimeVal.replace(/^(\d{2}:\d{2})$/, '$1:00') : null,
        default_open_amount: parseInt(document.getElementById('set-open').value, 10) || 30000,
        overshort_threshold: parseInt(document.getElementById('set-overshort').value, 10) || 500,
        register_close_alert_enabled: document.getElementById('set-register-close-alert').checked ? 1 : 0,
        register_close_time: regCloseTimeVal ? regCloseTimeVal.replace(/^(\d{2}:\d{2})$/, '$1:00') : null,
        register_close_grace_min: parseInt(document.getElementById('set-register-close-grace').value, 10) || 0,
        payment_methods_enabled: payChecked.join(','),
        receipt_store_name: document.getElementById('set-receipt-name').value.trim(),
        receipt_address: document.getElementById('set-receipt-addr').value.trim(),
        receipt_phone: document.getElementById('set-receipt-phone').value.trim(),
        receipt_footer: document.getElementById('set-receipt-footer').value.trim(),
        printer_type: document.getElementById('set-printer-type').value,
        printer_ip: document.getElementById('set-printer-ip').value.trim() || null,
        printer_port: parseInt(document.getElementById('set-printer-port').value, 10) || 80,
        printer_paper_width: parseInt(document.getElementById('set-printer-width').value, 10) || 80,
        printer_auto_kitchen: document.getElementById('set-printer-auto-kitchen').checked ? 1 : 0,
        printer_auto_receipt: document.getElementById('set-printer-auto-receipt').checked ? 1 : 0,
        google_place_id: document.getElementById('set-google-place-id').value.trim() || null,
        takeout_enabled: document.getElementById('set-takeout-enabled').checked ? 1 : 0,
        takeout_min_prep_minutes: parseInt(document.getElementById('set-takeout-prep').value, 10) || 30,
        takeout_available_from: (document.getElementById('set-takeout-from').value || '10:00').replace(/^(\d{2}:\d{2})$/, '$1:00'),
        takeout_available_to: (document.getElementById('set-takeout-to').value || '20:00').replace(/^(\d{2}:\d{2})$/, '$1:00'),
        takeout_slot_capacity: parseInt(document.getElementById('set-takeout-capacity').value, 10) || 5,
        takeout_slot_item_capacity: parseInt(document.getElementById('set-takeout-item-capacity').value, 10) || 0,
        takeout_peak_start_time: takeoutPeakFrom ? takeoutPeakFrom.replace(/^(\d{2}:\d{2})$/, '$1:00') : null,
        takeout_peak_end_time: takeoutPeakTo ? takeoutPeakTo.replace(/^(\d{2}:\d{2})$/, '$1:00') : null,
        takeout_peak_slot_capacity: parseInt(document.getElementById('set-takeout-peak-capacity').value, 10) || 0,
        takeout_peak_slot_item_capacity: parseInt(document.getElementById('set-takeout-peak-item-capacity').value, 10) || 0,
        takeout_acceptance_delay_minutes: parseInt(document.getElementById('set-takeout-delay').value, 10) || 0,
        takeout_sla_warning_minutes: parseInt(document.getElementById('set-takeout-sla-warning').value, 10) || 10,
        takeout_online_payment: document.getElementById('set-takeout-online').checked ? 1 : 0,
        brand_color: document.getElementById('set-brand-color').value.trim() || null,
        brand_logo_url: document.getElementById('set-brand-logo-url').value.trim() || null,
        brand_display_name: document.getElementById('set-brand-display-name').value.trim() || null,
        self_checkout_enabled: document.getElementById('set-self-checkout').checked ? 1 : 0
      };

      this.disabled = true;
      AdminApi.updateSettings(payload).then(function () {
        showToast('設定を保存しました', 'success');
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-settings');
        if (btn) btn.disabled = false;
      });
    });
  }

  return { init: init, load: load };
})();
