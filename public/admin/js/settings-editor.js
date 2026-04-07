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
      + '<div class="form-group"><label class="form-label">利用可能な支払方法</label>'
      + '<div class="settings-toggle-group">'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input pay-cb" value="cash"' + (payMethods.indexOf('cash') >= 0 ? ' checked' : '') + '> 現金</label>'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input pay-cb" value="card"' + (payMethods.indexOf('card') >= 0 ? ' checked' : '') + '> カード</label>'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input pay-cb" value="qr"' + (payMethods.indexOf('qr') >= 0 ? ' checked' : '') + '> QR決済</label></div></div>'

      + '<h3 style="margin:2rem 0 1rem;font-size:1rem">レシート設定</h3>'
      + '<div class="form-group"><label class="form-label">店舗名</label><input class="form-input" id="set-receipt-name" value="' + Utils.escapeHtml(s.receipt_store_name || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">住所</label><input class="form-input" id="set-receipt-addr" value="' + Utils.escapeHtml(s.receipt_address || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">電話番号</label><input class="form-input" id="set-receipt-phone" value="' + Utils.escapeHtml(s.receipt_phone || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">フッター</label><textarea class="form-input" id="set-receipt-footer">' + Utils.escapeHtml(s.receipt_footer || '') + '</textarea></div>'

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
      + '<div class="form-group"><label class="form-label">受付開始時刻</label><input class="form-input" id="set-takeout-from" type="time" value="' + (s.takeout_available_from ? s.takeout_available_from.substring(0, 5) : '10:00') + '"></div>'
      + '<div class="form-group"><label class="form-label">受付終了時刻</label><input class="form-input" id="set-takeout-to" type="time" value="' + (s.takeout_available_to ? s.takeout_available_to.substring(0, 5) : '20:00') + '"></div></div>'
      + '<div class="form-group"><label class="form-label">オンライン決済</label>'
      + '<div class="settings-toggle-group">'
      + '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input" id="set-takeout-online"' + (parseInt(s.takeout_online_payment, 10) ? ' checked' : '') + '> 有効</label>'
      + '</div>'
      + '<div style="font-size:0.75rem;color:#888;margin-top:0.25rem">オーナー管理画面で決済ゲートウェイ（Square/Stripe）を設定してから有効にしてください。</div></div>'
      + '<div id="takeout-qr-section" style="display:none;margin-top:1rem;padding:1rem;background:#f5f5f5;border-radius:8px;text-align:center;">'
      + '<p style="font-size:0.875rem;color:#333;margin-bottom:0.5rem;">テイクアウト注文ページ</p>'
      + '<div id="takeout-qr-code" style="display:inline-block;margin-bottom:0.5rem;"></div>'
      + '<p id="takeout-url-text" style="font-size:0.75rem;color:#666;word-break:break-all;margin-bottom:0.75rem;"></p>'
      + '<div style="display:flex;gap:0.5rem;justify-content:center;flex-wrap:wrap;">'
      + '<button type="button" id="btn-copy-takeout-url" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #2196F3;color:#2196F3;background:#fff;border-radius:4px;cursor:pointer;">URLをコピー</button>'
      + '<button type="button" id="btn-copy-takeout-qr" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #4CAF50;color:#4CAF50;background:#fff;border-radius:4px;cursor:pointer;">QR画像コピー</button>'
      + '<button type="button" id="btn-print-takeout-qr" style="padding:0.375rem 1rem;font-size:0.8125rem;border:1px solid #ff6f00;color:#ff6f00;background:#fff;border-radius:4px;cursor:pointer;">印刷</button>'
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
      var payload = {
        day_cutoff_time: (document.getElementById('set-cutoff').value || '05:00').replace(/^(\d{2}:\d{2})$/, '$1:00'),
        tax_rate: parseFloat(document.getElementById('set-tax').value) || 10,
        max_items_per_order: parseInt(document.getElementById('set-max-items').value, 10) || 10,
        max_amount_per_order: parseInt(document.getElementById('set-max-amount').value, 10) || 30000,
        last_order_time: loTimeVal ? loTimeVal.replace(/^(\d{2}:\d{2})$/, '$1:00') : null,
        default_open_amount: parseInt(document.getElementById('set-open').value, 10) || 30000,
        overshort_threshold: parseInt(document.getElementById('set-overshort').value, 10) || 500,
        payment_methods_enabled: payChecked.join(','),
        receipt_store_name: document.getElementById('set-receipt-name').value.trim(),
        receipt_address: document.getElementById('set-receipt-addr').value.trim(),
        receipt_phone: document.getElementById('set-receipt-phone').value.trim(),
        receipt_footer: document.getElementById('set-receipt-footer').value.trim(),
        google_place_id: document.getElementById('set-google-place-id').value.trim() || null,
        takeout_enabled: document.getElementById('set-takeout-enabled').checked ? 1 : 0,
        takeout_min_prep_minutes: parseInt(document.getElementById('set-takeout-prep').value, 10) || 30,
        takeout_available_from: (document.getElementById('set-takeout-from').value || '10:00').replace(/^(\d{2}:\d{2})$/, '$1:00'),
        takeout_available_to: (document.getElementById('set-takeout-to').value || '20:00').replace(/^(\d{2}:\d{2})$/, '$1:00'),
        takeout_slot_capacity: parseInt(document.getElementById('set-takeout-capacity').value, 10) || 5,
        takeout_online_payment: document.getElementById('set-takeout-online').checked ? 1 : 0,
        brand_color: document.getElementById('set-brand-color').value.trim() || null,
        brand_logo_url: document.getElementById('set-brand-logo-url').value.trim() || null,
        brand_display_name: document.getElementById('set-brand-display-name').value.trim() || null
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
