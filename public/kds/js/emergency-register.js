/**
 * POSLA Emergency Register UI (PWA Phase 4 / 2026-04-20)
 *
 * レジ画面 (public/kds/cashier.html) 専用の緊急会計 UI。
 *
 * 役割:
 *   - ヘッダに「緊急レジ」ボタン + 未同期件数バッジを配置
 *   - 緊急会計モーダル: 支払方法 (cash / external_card / external_qr / other_external) を選択し、
 *     金額・担当スタッフ PIN・外部端末控え番号等を入力して IndexedDB に保存
 *   - 記録直後に「POSLA 未同期の緊急会計控え」を画面表示 / 印刷できる
 *   - 一覧モーダル: IndexedDB 内の全記録を state 別に表示
 *   - 同期: POST /api/store/emergency-payments.php。UNIQUE (store_id, local_emergency_payment_id) で idempotent
 *
 * カード番号・有効期限・CVV の入力欄は一切置かない (設計上存在しない)。
 *
 * ES5 互換: const/let/arrow/async-await/template literal/.repeat/.padStart/.includes 不使用
 */
var EmergencyRegister = (function () {
  'use strict';

  var API_URL = '../../api/store/emergency-payments.php';
  var APP_VERSION = '20260421-pwa4d-2-hotfix1';

  var _btnEl = null;
  var _badgeEl = null;
  var _ctxGetter = null;
  var _badgeTimer = null;

  // ── 初期化 ─────────────────────────────────────────
  function init(options) {
    options = options || {};
    _ctxGetter = (typeof options.getContext === 'function') ? options.getContext : null;

    _createHeaderButton();
    _refreshBadge();
    if (_badgeTimer) clearInterval(_badgeTimer);
    _badgeTimer = setInterval(_refreshBadge, 8000);

    // 通信復帰で自動同期を試みる
    if (typeof OfflineDetector !== 'undefined' && OfflineDetector.onStatusChange) {
      OfflineDetector.onStatusChange(function (online) {
        if (online) syncPending();
      });
    }
  }

  function _createHeaderButton() {
    var rightSlot = document.querySelector('.ca-header__right');
    if (!rightSlot) return;
    if (document.getElementById('ca-emergency-register-btn')) return;

    var btn = document.createElement('button');
    btn.id = 'ca-emergency-register-btn';
    btn.type = 'button';
    btn.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:6px 12px;'
      + 'background:#d32f2f;color:#fff;border:none;border-radius:4px;font-size:13px;'
      + 'font-weight:600;cursor:pointer;margin-right:8px;';
    btn.innerHTML = '\uD83D\uDD34 \u7DCA\u6025\u30EC\u30B8 '
      + '<span id="ca-emergency-badge" style="display:none;background:#fff;color:#d32f2f;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;"></span>';
    rightSlot.insertBefore(btn, rightSlot.firstChild);
    _btnEl = btn;
    _badgeEl = document.getElementById('ca-emergency-badge');

    if (!EmergencyRegisterStore.isSupported()) {
      btn.innerHTML = '\uD83D\uDD34 \u7DCA\u6025\u30EC\u30B8 (\u5229\u7528\u4E0D\u53EF)';
      btn.style.opacity = '0.5';
      btn.style.cursor = 'not-allowed';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        try {
          window.alert('\u3053\u306E\u7AEF\u672B\u3067\u306F\u7DCA\u6025\u30EC\u30B8\u3092\u5229\u7528\u3067\u304D\u307E\u305B\u3093 (IndexedDB \u975E\u5BFE\u5FDC)');
        } catch (e2) {}
      });
      return;
    }

    btn.addEventListener('click', _openMainModal);
  }

  function _refreshBadge() {
    if (!_badgeEl) return;
    if (!EmergencyRegisterStore.isSupported()) return;
    // Phase 4 レビュー指摘 #6: 未同期だけでなく「要確認 (conflict / pending_review)」も含めて表示。
    EmergencyRegisterStore.countNeedsAttention(function (err, info) {
      if (err || !_badgeEl || !info) return;
      if (info.total > 0) {
        var label = String(info.total);
        // 要確認が含まれる場合は ! を付けて視覚的に区別
        if (info.needsReview > 0) label = '!' + label;
        _badgeEl.textContent = label;
        _badgeEl.style.display = '';
        // 要確認ありなら背景を少し濃く
        _badgeEl.title = (info.unsynced > 0 ? ('未同期: ' + info.unsynced + '\u4EF6') : '')
          + (info.needsReview > 0 ? ((info.unsynced > 0 ? ' / ' : '') + '\u8981\u78BA\u8A8D: ' + info.needsReview + '\u4EF6') : '');
      } else {
        _badgeEl.style.display = 'none';
        _badgeEl.title = '';
      }
    });
  }

  // ── メインメニュー ─────────────────────────────
  function _openMainModal() {
    var overlay = _createOverlay();
    var panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;padding:24px;border-radius:10px;max-width:720px;width:92vw;max-height:90vh;overflow-y:auto;';
    panel.innerHTML = _renderMainHtml();
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    panel.querySelector('#cer-close').addEventListener('click', function () { overlay.remove(); });
    panel.querySelector('#cer-new-btn').addEventListener('click', function () {
      overlay.remove();
      _openNewPaymentModal();
    });
    panel.querySelector('#cer-list-btn').addEventListener('click', function () {
      overlay.remove();
      _openListModal();
    });
    panel.querySelector('#cer-sync-btn').addEventListener('click', function () {
      overlay.remove();
      syncPending();
    });
  }

  function _renderMainHtml() {
    return ''
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">'
      + '<h2 style="margin:0;font-size:20px;color:#d32f2f;">\uD83D\uDD34 \u7DCA\u6025\u30EC\u30B8\u30E2\u30FC\u30C9</h2>'
      + '<button type="button" id="cer-close" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>'
      + '</div>'
      + '<div style="background:#fff3cd;padding:10px 14px;border:1px solid #ffd757;border-radius:6px;margin-bottom:14px;font-size:13px;line-height:1.7;color:#795500;">'
      + '\u901A\u4FE1\u969C\u5BB3\u30FBAPI \u969C\u5BB3\u6642\u306E <strong>POSLA \u672A\u540C\u671F\u4F1A\u8A08\u8A18\u9332</strong> \u7528\u3067\u3059\u3002<br>'
      + '\u30AB\u30FC\u30C9\u6C7A\u6E08\u3092 POSLA \u304C\u4EE3\u66FF\u3059\u308B\u3082\u306E\u3067\u306F\u3042\u308A\u307E\u305B\u3093\u3002<br>'
      + '\u30AB\u30FC\u30C9 / QR \u6C7A\u6E08\u306F\u5FC5\u305A\u5916\u90E8\u7AEF\u672B\u3067\u51E6\u7406\u3057\u3066\u304B\u3089\u3001\u672C\u753B\u9762\u306B\u300C\u5916\u90E8\u7AEF\u672B\u6C7A\u6E08\u300D\u3068\u3057\u3066\u8A18\u9332\u3057\u3066\u304F\u3060\u3055\u3044\u3002'
      + '</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
      + '<button type="button" id="cer-new-btn" style="padding:16px;background:#d32f2f;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">+ \u65B0\u3057\u3044\u7DCA\u6025\u4F1A\u8A08\u3092\u8A18\u9332</button>'
      + '<button type="button" id="cer-list-btn" style="padding:16px;background:#455a64;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">\u672A\u540C\u671F\u4F1A\u8A08\u4E00\u89A7</button>'
      + '<button type="button" id="cer-sync-btn" style="padding:16px;background:#1565c0;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;grid-column:span 2;">\u21BB \u540C\u671F\u518D\u8A66\u884C</button>'
      + '</div>'
      + '<hr style="margin:16px 0;">'
      + '<div style="font-size:12px;color:#666;line-height:1.6;">'
      + '<strong>\u91CD\u8981:</strong><br>'
      + '\u30FB\u30AB\u30FC\u30C9\u756A\u53F7\u30FB\u6709\u52B9\u671F\u9650\u30FB\u30BB\u30AD\u30E5\u30EA\u30C6\u30A3\u30B3\u30FC\u30C9\u306F <u>\u7D76\u5BFE\u306B\u5165\u529B\u3057\u306A\u3044\u3067\u304F\u3060\u3055\u3044</u>\u3002<br>'
      + '\u30FB\u5916\u90E8\u6C7A\u6E08\u7AEF\u672B\u306E\u63A7\u3048/\u627F\u8A8D\u756A\u53F7\u306E\u307F\u8A18\u9332\u3057\u3066\u304F\u3060\u3055\u3044\u3002<br>'
      + '\u30FB\u672A\u540C\u671F\u4F1A\u8A08\u304C\u3042\u308B\u9593\u306F\u30D6\u30E9\u30A6\u30B6\u306E\u30AD\u30E3\u30C3\u30B7\u30E5\u524A\u9664\u30FB\u30ED\u30B0\u30A2\u30A6\u30C8\u30FB\u7AEF\u672B\u30EA\u30BB\u30C3\u30C8\u3092\u884C\u308F\u306A\u3044\u3067\u304F\u3060\u3055\u3044\u3002<br>'
      + '\u30FBPOSLA \u7BA1\u7406\u753B\u9762\u3078\u306E\u53CD\u6620\u306F\u3001\u540C\u671F\u6210\u529F\u5F8C\u3001\u7BA1\u7406\u8005\u306E\u78BA\u8A8D\u3092\u7D4C\u3066\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u306B\u8A08\u4E0A\u3055\u308C\u307E\u3059\u3002'
      + '</div>';
  }

  // ── 新規記録モーダル ─────────────────────
  function _openNewPaymentModal() {
    var ctx = null;
    try { ctx = _ctxGetter ? _ctxGetter() : null; } catch (e) { ctx = null; }

    var overlay = _createOverlay();
    overlay.style.alignItems = 'flex-start';
    overlay.style.padding = '20px';
    overlay.style.overflowY = 'auto';
    var panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;padding:20px;border-radius:10px;max-width:600px;width:100%;';
    panel.innerHTML = _renderNewFormHtml(ctx);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    panel.querySelector('#cer-new-close').addEventListener('click', function () { overlay.remove(); });

    var methodSel = panel.querySelector('#cer-method');
    var cashRow = panel.querySelector('#cer-cash-row');
    var externalRow = panel.querySelector('#cer-external-row');
    // Phase 4d-2: 分類行は other_external 選択時のみ表示
    var extMethodRow = panel.querySelector('#cer-ext-method-row');
    var extMethodSel = panel.querySelector('#cer-ext-method');
    function toggleMethod() {
      var v = methodSel.value;
      if (v === 'cash') {
        cashRow.style.display = '';
        externalRow.style.display = 'none';
      } else {
        cashRow.style.display = 'none';
        externalRow.style.display = '';
      }
      if (extMethodRow) {
        if (v === 'other_external') {
          extMethodRow.style.display = '';
        } else {
          extMethodRow.style.display = 'none';
          // other_external 以外に切り替えた場合は select を空に戻す (送信しない)
          if (extMethodSel) extMethodSel.value = '';
        }
      }
    }
    methodSel.addEventListener('change', toggleMethod);
    toggleMethod();

    panel.querySelector('#cer-new-submit').addEventListener('click', function () {
      _submitNewPayment(ctx, panel, overlay);
    });
  }

  function _renderNewFormHtml(ctx) {
    var tableInfoHtml;
    if (ctx && ctx.tableId) {
      tableInfoHtml = '<div style="padding:10px;background:#e3f2fd;border-radius:6px;margin-bottom:12px;font-size:14px;line-height:1.7;">'
        + '<strong>\u5BFE\u8C61\u30C6\u30FC\u30D6\u30EB:</strong> ' + _escapeHtml(ctx.tableCode || '(unknown)') + '<br>'
        + '<strong>\u5BFE\u8C61\u6CE8\u6587:</strong> ' + ((ctx.orderIds && ctx.orderIds.length) || 0) + ' \u4EF6<br>'
        + '<strong>\u63A8\u5968\u5408\u8A08:</strong> &yen;' + _formatYen(ctx.totalAmount || 0)
        + '</div>';
    } else {
      tableInfoHtml = '<div style="padding:10px;background:#fff3cd;border-radius:6px;margin-bottom:12px;font-size:13px;color:#795500;">'
        + '\u26A0 \u5BFE\u8C61\u30C6\u30FC\u30D6\u30EB\u304C\u9078\u629E\u3055\u308C\u3066\u3044\u307E\u305B\u3093\u3002\u624B\u5165\u529B\u30E2\u30FC\u30C9\u3067\u8A18\u9332\u3057\u307E\u3059\u3002<br>'
        + '\u540C\u671F\u6642\u306B\u300C\u8981\u78BA\u8A8D (pending_review)\u300D\u306B\u306A\u308A\u3001\u7BA1\u7406\u8005\u304C\u624B\u52D5\u3067\u7167\u5408\u3059\u308B\u5FC5\u8981\u304C\u3042\u308A\u307E\u3059\u3002'
        + '</div>';
    }
    var totalVal = (ctx && ctx.totalAmount) ? String(ctx.totalAmount) : '';

    return ''
      + '<h3 style="margin:0 0 12px;color:#d32f2f;">\uD83D\uDD34 \u7DCA\u6025\u4F1A\u8A08\u8A18\u9332</h3>'
      + tableInfoHtml
      + '<div style="display:grid;gap:12px;">'
      + '<label style="font-size:13px;">\u652F\u6255\u65B9\u6CD5'
      + '<select id="cer-method" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;">'
      + '<option value="cash">\u73FE\u91D1</option>'
      + '<option value="external_card">\u5916\u90E8\u30AB\u30FC\u30C9\u7AEF\u672B</option>'
      + '<option value="external_qr">\u5916\u90E8 QR / \u96FB\u5B50\u6C7A\u6E08\u7AEF\u672B</option>'
      + '<option value="other_external">\u305D\u306E\u4ED6\u5916\u90E8\u6C7A\u6E08</option>'
      + '</select>'
      + '</label>'
      + '<label style="font-size:13px;">\u5408\u8A08\u91D1\u984D (\u7A0E\u8FBC)'
      + '<input type="number" id="cer-total" min="0" step="1" value="' + totalVal + '" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;" />'
      + '</label>'
      + '<div id="cer-cash-row">'
      + '<label style="font-size:13px;">\u73FE\u91D1\u53D7\u9818\u984D'
      + '<input type="number" id="cer-received" min="0" step="1" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;" />'
      + '</label>'
      + '</div>'
      + '<div id="cer-external-row" style="display:none;">'
      + '<div style="padding:10px;background:#fff3cd;border:1px solid #ffd757;border-radius:6px;margin-bottom:10px;font-size:12px;color:#795500;line-height:1.6;">'
      + '\u26A0 \u30AB\u30FC\u30C9\u756A\u53F7\u30FB\u6709\u52B9\u671F\u9650\u30FB\u30BB\u30AD\u30E5\u30EA\u30C6\u30A3\u30B3\u30FC\u30C9\u306F\u5165\u529B\u7981\u6B62\u3067\u3059\u3002<br>'
      + '\u6C7A\u6E08\u7AEF\u672B\u5074\u306E\u63A7\u3048\u3092\u5FC5\u305A\u4FDD\u7BA1\u3057\u3066\u304F\u3060\u3055\u3044\u3002'
      + '</div>'
      // Phase 4d-2: other_external 選択時のみ表示。初期値は "" の「選択してください」で、未選択だと alert で弾く。
      + '<div id="cer-ext-method-row" style="display:none;margin-bottom:10px;">'
      + '<label style="font-size:13px;">\u5206\u985E (\u5FC5\u9808)'
      + '<select id="cer-ext-method" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;">'
      + '<option value="">\u9078\u629E\u3057\u3066\u304F\u3060\u3055\u3044</option>'
      + '<option value="voucher">\u5546\u54C1\u5238</option>'
      + '<option value="bank_transfer">\u4E8B\u524D\u632F\u8FBC</option>'
      + '<option value="accounts_receivable">\u58F2\u639B</option>'
      + '<option value="point">\u30DD\u30A4\u30F3\u30C8\u5145\u5F53</option>'
      + '<option value="other">\u305D\u306E\u4ED6</option>'
      + '</select>'
      + '</label>'
      + '</div>'
      + '<label style="font-size:13px;">\u7AEF\u672B\u540D (\u4EFB\u610F)'
      + '<input type="text" id="cer-terminal" maxlength="100" autocomplete="off" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;" />'
      + '</label>'
      + '<label style="font-size:13px;display:block;margin-top:8px;">\u63A7\u3048\u756A\u53F7 (\u4EFB\u610F)'
      + '<input type="text" id="cer-slip" maxlength="100" autocomplete="off" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;" />'
      + '</label>'
      + '<label style="font-size:13px;display:block;margin-top:8px;">\u627F\u8A8D\u756A\u53F7 (\u4EFB\u610F)'
      + '<input type="text" id="cer-approval" maxlength="100" autocomplete="off" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;" />'
      + '</label>'
      + '</div>'
      + '<label style="font-size:13px;">\u62C5\u5F53\u30B9\u30BF\u30C3\u30D5 PIN (\u4EFB\u610F\u30FB\u8A2D\u5B9A\u6E08\u307F\u30B9\u30BF\u30C3\u30D5\u306E\u307F)'
      + '<input type="password" id="cer-pin" inputmode="numeric" maxlength="8" autocomplete="off" placeholder="\u7A7A\u6B04\u53EF\u3002\u5165\u529B\u3059\u308B\u5834\u5408\u306F 4-8 \u6841\u306E\u6570\u5B57" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;letter-spacing:0.3em;" />'
      + '</label>'
      + '<label style="font-size:13px;">\u30E1\u30E2 (\u4EFB\u610F)'
      + '<textarea id="cer-note" maxlength="255" rows="2" style="width:100%;padding:8px;margin-top:4px;border:1px solid #ccc;border-radius:4px;font-size:14px;"></textarea>'
      + '</label>'
      + '</div>'
      + '<div style="display:flex;gap:10px;margin-top:18px;">'
      + '<button type="button" id="cer-new-close" style="flex:1;padding:12px;background:#f5f5f5;border:1px solid #ccc;border-radius:6px;cursor:pointer;font-size:14px;">\u30AD\u30E3\u30F3\u30BB\u30EB</button>'
      + '<button type="button" id="cer-new-submit" style="flex:2;padding:12px;background:#d32f2f;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;">\u7DCA\u6025\u4F1A\u8A08\u3092\u8A18\u9332</button>'
      + '</div>';
  }

  function _submitNewPayment(ctx, panel, overlay) {
    var method = panel.querySelector('#cer-method').value;
    var totalStr = panel.querySelector('#cer-total').value;
    var total = parseInt(totalStr, 10);
    if (isNaN(total) || total < 0) {
      window.alert('\u5408\u8A08\u91D1\u984D\u3092\u6B63\u3057\u304F\u5165\u529B\u3057\u3066\u304F\u3060\u3055\u3044');
      return;
    }

    var received = null, change = null;
    var terminalName = null, slipNo = null, approvalNo = null;
    if (method === 'cash') {
      var recStr = panel.querySelector('#cer-received').value;
      var rec = parseInt(recStr, 10);
      if (isNaN(rec)) rec = total;
      if (rec < total) {
        if (!window.confirm('\u73FE\u91D1\u53D7\u9818\u984D\u304C\u5408\u8A08\u3092\u4E0B\u56DE\u3063\u3066\u3044\u307E\u3059\u3002\u3053\u306E\u307E\u307E\u8A18\u9332\u3057\u307E\u3059\u304B\uFF1F')) return;
      }
      received = rec;
      change = Math.max(0, rec - total);
    } else {
      terminalName = panel.querySelector('#cer-terminal').value.replace(/^\s+|\s+$/g, '').substring(0, 100) || null;
      slipNo       = panel.querySelector('#cer-slip').value.replace(/^\s+|\s+$/g, '').substring(0, 100) || null;
      approvalNo   = panel.querySelector('#cer-approval').value.replace(/^\s+|\s+$/g, '').substring(0, 100) || null;
    }

    // Phase 4d-2: other_external の場合のみ外部分類 (externalMethodType) を収集。
    //   UI 既定値は空文字 "" で、未選択なら必ず alert → return。
    //   other_external 以外では送信しない (IDB 保存・API 送信どちらも null のまま)。
    var externalMethodType = null;
    if (method === 'other_external') {
      var extSel = panel.querySelector('#cer-ext-method');
      var extVal = extSel ? String(extSel.value || '') : '';
      if (!extVal) {
        window.alert('\u305D\u306E\u4ED6\u5916\u90E8\u6C7A\u6E08\u306E\u5206\u985E\u3092\u9078\u629E\u3057\u3066\u304F\u3060\u3055\u3044');
        return;
      }
      externalMethodType = extVal;
    }

    // B' (2026-04-20): PIN は任意化。空欄なら「入力なし」として扱い、pinEnteredClient=false で記録する。
    //   - 空欄: サーバーに staffPin を送らず pin_entered_but_not_verified の pending_review にしない
    //   - 入力あり: 4-8 桁の数字のみ許可。非数字混入も明確にアラートで弾く (silent に削らない)
    // Fix 8 (2026-04-20): 以前は replace(/\D/g, '') で非数字を黙って消していたが、"12ab34" が 1234 として通るのは意図に反する。
    //                     trim のみに変更し、非数字混入はそのまま正規表現テストで弾く。
    var pinRaw = panel.querySelector('#cer-pin').value || '';
    var pinTrimmed = pinRaw.replace(/^\s+|\s+$/g, '');
    var pinEntered = (pinTrimmed.length > 0);
    if (pinEntered && !/^\d{4,8}$/.test(pinTrimmed)) {
      window.alert('PIN \u306F 4-8 \u6841\u306E\u6570\u5B57\u3067\u5165\u529B\u3057\u3066\u304F\u3060\u3055\u3044\u3002PIN \u3092\u5165\u308C\u306A\u3044\u5834\u5408\u306F\u7A7A\u6B04\u306E\u307E\u307E\u8A18\u9332\u3067\u304D\u307E\u3059\u3002');
      return;
    }
    var pin = pinTrimmed;

    var note = panel.querySelector('#cer-note').value.replace(/^\s+|\s+$/g, '').substring(0, 255) || null;

    // Fix 5 (2026-04-20): 個別会計状態では転記時に注文全体が paid 化される事故になるため、
    // 記録前に拒否する。API 側でも 409 partial_emergency_transfer_not_supported で止まるが、
    // UI でも先に止めてスタッフに選択し直しを促す。
    if (ctx && ctx.hasPartialSelection) {
      window.alert('個別会計の状態では緊急レジに記録できません。\n全品目を選択してから再度「緊急レジ」ボタンを押してください。');
      return;
    }

    if (!window.confirm('\u7DCA\u6025\u4F1A\u8A08\u3068\u3057\u3066\u8A18\u9332\u3057\u307E\u3059\u3002\u3088\u308D\u3057\u3044\u3067\u3059\u304B\uFF1F\n(\u3053\u306E\u8A18\u9332\u306F POSLA \u672A\u540C\u671F\u6271\u3044\u3067\u3059)')) return;

    var storeId = (ctx && ctx.storeId) || null;
    if (!storeId) {
      window.alert('\u5E97\u8217\u60C5\u5831\u304C\u53D6\u5F97\u3067\u304D\u307E\u305B\u3093\u3002\u30EC\u30B8\u753B\u9762\u3092\u30EA\u30ED\u30FC\u30C9\u3057\u3066\u304F\u3060\u3055\u3044\u3002');
      return;
    }
    var deviceId = EmergencyRegisterStore.getOrCreateDeviceId();
    var localId = EmergencyRegisterStore.generateLocalId(storeId, deviceId);

    var subtotal = (ctx && ctx.subtotal) ? ctx.subtotal : 0;
    var tax      = (ctx && ctx.tax)      ? ctx.tax      : 0;
    if (subtotal + tax === 0) { subtotal = total; tax = 0; }

    // IDB に保存する record。**staffPin は IDB に絶対保存しない**。
    // 代わりに pinEnteredClient フラグ (非機密) を立てて、「PIN を入力した事実」は保持する。
    //   - PIN 入力あり → pinEnteredClient=true。初回同期失敗時に再試行すると staffPin 未送信となり、
    //                    サーバー側で pin_entered_but_not_verified → pending_review に落とす。
    //   - PIN 空欄    → pinEnteredClient=false (B', 2026-04-20)。PIN 未検証だけを理由に
    //                    pending_review にしない。PIN 運用未整備の店が無意味な pending_review に落ちるのを防ぐ。
    var idbRecord = {
      localEmergencyPaymentId: localId,
      storeId:   storeId,
      storeName: (ctx && ctx.storeName) || '',
      deviceId:  deviceId,
      staffName: (ctx && ctx.userName) || '',
      pinEnteredClient: pinEntered,           // PIN 入力した事実 (非機密フラグ)
      tableId:   (ctx && ctx.tableId)   || null,
      tableCode: (ctx && ctx.tableCode) || null,
      hasTableContext: !!(ctx && ctx.hasTableContext),
      orderIds:  (ctx && ctx.orderIds)  || [],
      itemSnapshot: (ctx && ctx.items)  || [],
      subtotal:  subtotal,
      tax:       tax,
      totalAmount: total,
      paymentMethod: method,
      // Phase 4d-2: other_external 時のみ分類 (voucher/bank_transfer/accounts_receivable/point/other)。
      //   他の paymentMethod では null。
      externalMethodType: externalMethodType,
      receivedAmount: received,
      changeAmount:   change,
      externalTerminalName: terminalName,
      externalSlipNo:   slipNo,
      externalApprovalNo: approvalNo,
      note: note,
      status: 'pending_sync',
      createdAt: Date.now(),
      syncedAt: null,
      syncAttempts: 0,
      lastError: null,
      appVersion: APP_VERSION
    };

    EmergencyRegisterStore.add(idbRecord, function (err) {
      if (err) {
        window.alert('IndexedDB \u3078\u306E\u4FDD\u5B58\u306B\u5931\u6557\u3057\u307E\u3057\u305F: ' + ((err && err.message) || 'unknown'));
        return;
      }
      overlay.remove();
      _showReceipt(idbRecord);
      _refreshBadge();
      // サーバー同期を即座に 1 回試行 (PIN は初回送信時のみ同封、IDB には保存しない)
      _syncOne(idbRecord, pin, function () { _refreshBadge(); });
    });
  }

  // ── レシート ─────────────────────────────────
  function _showReceipt(record) {
    var overlay = _createOverlay();
    overlay.style.zIndex = '11100';
    var panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;padding:24px;max-width:360px;width:90vw;border-radius:8px;font-family:monospace;';

    var html = ''
      + '<div style="text-align:center;margin-bottom:12px;padding:8px;background:#fff3cd;color:#795500;border:2px dashed #d0a200;">'
      + '<strong>POSLA \u672A\u540C\u671F\u306E\u7DCA\u6025\u4F1A\u8A08\u63A7\u3048</strong><br>'
      + '<span style="font-size:11px;">\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B POSLA \u3078\u540C\u671F\u3057\u3066\u304F\u3060\u3055\u3044</span>'
      + '</div>'
      + '<div style="font-size:13px;line-height:1.7;">'
      + '<div><strong>\u7DCA\u6025\u4F1A\u8A08\u756A\u53F7:</strong><br><span style="font-size:10px;word-break:break-all;">' + _escapeHtml(record.localEmergencyPaymentId) + '</span></div>'
      + '<div><strong>\u5E97\u8217:</strong> ' + _escapeHtml(record.storeName || record.storeId) + '</div>'
      + '<div><strong>\u30C6\u30FC\u30D6\u30EB:</strong> ' + _escapeHtml(record.tableCode || '(\u6307\u5B9A\u306A\u3057)') + '</div>'
      + '<div><strong>\u5408\u8A08:</strong> &yen;' + _formatYen(record.totalAmount) + '</div>'
      + '<div><strong>\u652F\u6255\u65B9\u6CD5:</strong> ' + _escapeHtml(_methodLabel(record.paymentMethod)) + '</div>';
    if (record.paymentMethod === 'cash') {
      html += '<div><strong>\u53D7\u9818:</strong> &yen;' + _formatYen(record.receivedAmount || 0) + '</div>'
        + '<div><strong>\u91E3\u92AD:</strong> &yen;' + _formatYen(record.changeAmount || 0) + '</div>';
    }
    if (record.externalTerminalName) html += '<div><strong>\u7AEF\u672B:</strong> ' + _escapeHtml(record.externalTerminalName) + '</div>';
    if (record.externalSlipNo)      html += '<div><strong>\u63A7\u3048\u756A\u53F7:</strong> ' + _escapeHtml(record.externalSlipNo) + '</div>';
    if (record.externalApprovalNo)  html += '<div><strong>\u627F\u8A8D\u756A\u53F7:</strong> ' + _escapeHtml(record.externalApprovalNo) + '</div>';
    if (record.staffName)           html += '<div><strong>\u62C5\u5F53:</strong> ' + _escapeHtml(record.staffName) + '</div>';
    html += '<div><strong>\u6642\u523B:</strong> ' + _escapeHtml(new Date(record.createdAt).toLocaleString('ja-JP')) + '</div>'
      + '</div>'
      + '<hr style="margin:12px 0;">'
      + '<div style="font-size:11px;color:#666;line-height:1.6;">'
      + '<strong>\u3054\u6CE8\u610F:</strong><br>'
      + '\u30FB\u3053\u306E\u63A7\u3048\u306F POSLA \u3078\u672A\u540C\u671F\u3067\u3059\u3002<br>'
      + (record.paymentMethod !== 'cash' ? '\u30FB\u5916\u90E8\u6C7A\u6E08\u7AEF\u672B\u5074\u306E\u63A7\u3048\u3092\u5FC5\u305A\u4FDD\u7BA1\u3057\u3066\u304F\u3060\u3055\u3044\u3002<br>' : '')
      + '\u30FB\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u81EA\u52D5\u540C\u671F\u3055\u308C\u307E\u3059\u3002'
      + '</div>'
      + '<div style="display:flex;gap:8px;margin-top:14px;">'
      + '<button id="cer-receipt-print" type="button" style="flex:1;padding:10px;background:#1565c0;color:#fff;border:none;border-radius:4px;cursor:pointer;">\u5370\u5237</button>'
      + '<button id="cer-receipt-close" type="button" style="flex:1;padding:10px;background:#455a64;color:#fff;border:none;border-radius:4px;cursor:pointer;">\u9589\u3058\u308B</button>'
      + '</div>';
    panel.innerHTML = html;
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    panel.querySelector('#cer-receipt-close').addEventListener('click', function () { overlay.remove(); });
    panel.querySelector('#cer-receipt-print').addEventListener('click', function () { window.print(); });
  }

  // ── 同期 ──────────────────────────────────────
  function _syncOne(record, pin, callback) {
    var body = {
      payment: {
        localEmergencyPaymentId: record.localEmergencyPaymentId,
        storeId:   record.storeId,
        deviceId:  record.deviceId,
        staffName: record.staffName || null,
        tableId:   record.tableId || null,
        tableCode: record.tableCode || null,
        orderIds:  record.orderIds || [],
        itemSnapshot: record.itemSnapshot || [],
        subtotal:  record.subtotal || 0,
        tax:       record.tax || 0,
        totalAmount: record.totalAmount,
        paymentMethod: record.paymentMethod,
        receivedAmount: record.receivedAmount,
        changeAmount:   record.changeAmount,
        externalTerminalName: record.externalTerminalName,
        externalSlipNo:       record.externalSlipNo,
        externalApprovalNo:   record.externalApprovalNo,
        note: record.note,
        // Phase 4 レビュー指摘 #4: 初回記録時に PIN を入力した事実 (非機密フラグ)。
        // 再試行で staffPin が送られなくても、サーバー側で「PIN 入力はされた」と判定できる。
        pinEnteredClient: !!record.pinEnteredClient,
        // Phase 4 レビュー指摘 (2回目) #1: テーブル未選択の手入力モードは必ず pending_review に落とす。
        // IDB に入っている hasTableContext を body に載せてサーバーが判断できるようにする。
        hasTableContext: !!record.hasTableContext,
        appVersion:       record.appVersion || '',
        clientCreatedAt:  new Date(record.createdAt).toISOString()
      }
    };
    if (pin) body.payment.staffPin = pin;
    // Phase 4d-2: externalMethodType は paymentMethod==='other_external' のときだけ送る。
    //   サーバー側も paymentMethod!=='other_external' なら silent drop するが、body を綺麗に保つ。
    if (record.paymentMethod === 'other_external' && record.externalMethodType) {
      body.payment.externalMethodType = String(record.externalMethodType);
    }

    record.status = 'syncing';
    record.syncAttempts = (record.syncAttempts || 0) + 1;
    EmergencyRegisterStore.put(record, function () {
      fetch(API_URL, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      })
        .then(function (r) {
          return r.text().then(function (t) { return { ok: r.ok, body: t }; });
        })
        .then(function (res) {
          var json = null;
          try { json = JSON.parse(res.body); } catch (e) { json = null; }
          if (!res.ok || !json || !json.ok) {
            record.status = 'failed';
            record.lastError = (json && json.error && json.error.message) || ('http_' + (res.ok ? '200' : 'err'));
            EmergencyRegisterStore.put(record, function () { if (callback) callback(false); });
            return;
          }
          var d = (json.data) || {};
          record.status       = d.status || 'synced';
          record.syncedAt     = Date.now();
          record.lastError    = null;
          record.conflictReason = d.conflict_reason || null;
          EmergencyRegisterStore.put(record, function () { if (callback) callback(true); });
        })
        .catch(function (err) {
          record.status = 'failed';
          record.lastError = (err && err.message) || 'network_error';
          EmergencyRegisterStore.put(record, function () { if (callback) callback(false); });
        });
    });
  }

  function syncPending() {
    if (!EmergencyRegisterStore.isSupported()) return;
    EmergencyRegisterStore.all(function (err, list) {
      if (err || !list) return;
      var targets = [];
      for (var i = 0; i < list.length; i++) {
        var s = list[i].status;
        if (s === 'pending_sync' || s === 'failed' || s === 'syncing') targets.push(list[i]);
      }
      if (targets.length === 0) { _refreshBadge(); return; }
      _syncNext(targets, 0);
    });
  }

  function _syncNext(list, idx) {
    if (idx >= list.length) { _refreshBadge(); return; }
    // PIN は初回送信のみ。再試行時は PIN なし (staff_pin_verified=0 で記録される)
    _syncOne(list[idx], null, function () {
      _syncNext(list, idx + 1);
    });
  }

  // ── 一覧モーダル ──────────────────────────
  function _openListModal() {
    var overlay = _createOverlay();
    overlay.style.alignItems = 'flex-start';
    overlay.style.padding = '20px';
    overlay.style.overflowY = 'auto';
    var panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;padding:20px;border-radius:10px;max-width:900px;width:100%;max-height:85vh;overflow-y:auto;';
    panel.innerHTML = '<h3>\u8AAD\u307F\u8FBC\u307F\u4E2D...</h3>';
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    EmergencyRegisterStore.all(function (err, list) {
      if (err) {
        panel.innerHTML = '<div>\u8AAD\u307F\u8FBC\u307F\u306B\u5931\u6557\u3057\u307E\u3057\u305F</div>';
        return;
      }
      var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">'
        + '<h3 style="margin:0;">\u7DCA\u6025\u4F1A\u8A08\u4E00\u89A7 (\u7AEF\u672B\u5185 IndexedDB)</h3>'
        + '<button type="button" id="cer-list-close" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>'
        + '</div>';

      if (!list || list.length === 0) {
        html += '<div style="padding:20px;text-align:center;color:#999;">\u8A18\u9332\u306A\u3057</div>';
      } else {
        html += '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
          + '<thead><tr style="background:#eceff1;">'
          + '<th style="padding:8px;text-align:left;">\u72B6\u614B</th>'
          + '<th style="padding:8px;text-align:left;">\u30C6\u30FC\u30D6\u30EB</th>'
          + '<th style="padding:8px;text-align:right;">\u5408\u8A08</th>'
          + '<th style="padding:8px;text-align:left;">\u652F\u6255</th>'
          + '<th style="padding:8px;text-align:left;">\u6642\u523B</th>'
          + '<th style="padding:8px;text-align:left;">\u30E1\u30E2/\u30A8\u30E9\u30FC</th>'
          + '</tr></thead><tbody>';
        for (var i = 0; i < list.length; i++) {
          var r = list[i];
          var bg = _statusColor(r.status);
          var det = r.lastError ? ('Error: ' + r.lastError) : (r.conflictReason ? ('Conflict: ' + r.conflictReason) : (r.note || '-'));
          html += '<tr style="border-bottom:1px solid #eee;">'
            + '<td style="padding:8px;"><span style="padding:2px 8px;border-radius:12px;background:' + bg + ';color:#fff;font-size:11px;">' + _escapeHtml(r.status) + '</span></td>'
            + '<td style="padding:8px;">' + _escapeHtml(r.tableCode || '-') + '</td>'
            + '<td style="padding:8px;text-align:right;">&yen;' + _formatYen(r.totalAmount || 0) + '</td>'
            + '<td style="padding:8px;">' + _escapeHtml(_methodLabel(r.paymentMethod)) + '</td>'
            + '<td style="padding:8px;">' + _escapeHtml(new Date(r.createdAt || 0).toLocaleString('ja-JP')) + '</td>'
            + '<td style="padding:8px;font-size:11px;color:#666;">' + _escapeHtml(det) + '</td>'
            + '</tr>';
        }
        html += '</tbody></table>';
      }

      html += '<div style="margin-top:14px;padding:10px;background:#fff3cd;border-radius:6px;font-size:12px;color:#795500;">'
        + '\u26A0 \u672A\u540C\u671F (pending_sync / syncing / failed) \u304C\u3042\u308B\u9593\u306F\u30ED\u30B0\u30A2\u30A6\u30C8\u30FB\u30AD\u30E3\u30C3\u30B7\u30E5\u524A\u9664\u30FB\u30D6\u30E9\u30A6\u30B6\u30C7\u30FC\u30BF\u524A\u9664\u3092\u3057\u306A\u3044\u3067\u304F\u3060\u3055\u3044\u3002\u8A18\u9332\u304C\u6D88\u5931\u3057\u307E\u3059\u3002'
        + '</div>';
      panel.innerHTML = html;
      panel.querySelector('#cer-list-close').addEventListener('click', function () { overlay.remove(); });
    });
  }

  // ── utilities ────────────────────────────
  function _createOverlay() {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:11000;display:flex;align-items:center;justify-content:center;';
    return overlay;
  }

  function _methodLabel(m) {
    if (m === 'cash')           return '\u73FE\u91D1';
    if (m === 'external_card')  return '\u5916\u90E8\u30AB\u30FC\u30C9\u7AEF\u672B';
    if (m === 'external_qr')    return '\u5916\u90E8 QR';
    if (m === 'other_external') return '\u305D\u306E\u4ED6\u5916\u90E8';
    return m || '';
  }

  function _statusColor(s) {
    if (s === 'synced')         return '#4caf50';
    if (s === 'pending_sync')   return '#ff9800';
    if (s === 'syncing')        return '#03a9f4';
    if (s === 'conflict')       return '#f44336';
    if (s === 'pending_review') return '#ab47bc';
    if (s === 'failed')         return '#d32f2f';
    return '#757575';
  }

  function _escapeHtml(s) {
    if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(s);
    s = String(s == null ? '' : s);
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function _formatYen(n) {
    if (typeof Utils !== 'undefined' && Utils.formatYen) {
      var s = Utils.formatYen(n);
      // Utils.formatYen が '¥' を付ける場合、ここでは数値だけ欲しいので外す
      if (typeof s === 'string' && s.charAt(0) === '\u00A5') return s.substring(1);
      if (typeof s === 'string' && s.charAt(0) === '\uFFE5') return s.substring(1);
      return s;
    }
    n = n || 0;
    var abs = Math.abs(Math.floor(n));
    var out = '';
    var str = String(abs);
    for (var i = 0; i < str.length; i++) {
      if (i > 0 && (str.length - i) % 3 === 0) out += ',';
      out += str.charAt(i);
    }
    return (n < 0 ? '-' : '') + out;
  }

  return {
    init: init,
    syncPending: syncPending,
    refreshBadge: _refreshBadge
  };
})();
