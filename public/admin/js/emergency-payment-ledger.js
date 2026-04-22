/**
 * 緊急会計台帳 (PWA Phase 4b-2 / 2026-04-20)
 *
 * 管理画面 dashboard.html 「レポート」グループのサブタブ「緊急会計台帳」用 JS。
 * 読み取り専用 (operation button なし)。
 *
 * 公開 API:
 *   EmergencyPaymentLedger.init()  — タブが初めて表示されたときの初期化 (現状 no-op、load が主)
 *   EmergencyPaymentLedger.load()  — dashboard.html の activateTab から呼ばれる
 *
 * 依存:
 *   AdminApi.getEmergencyPaymentLedger(from, to, status) — 読み取り API
 *   Utils.escapeHtml / Utils.formatYen (shared/js/utils.js)
 *
 * ES5 互換:
 *   var + function + コールバック。const/let/arrow/async-await/template literal/.includes/.repeat/.padStart 不使用。
 */
var EmergencyPaymentLedger = (function () {
  'use strict';

  var CONTAINER_ID = 'panel-emergency-ledger';
  var _loading = false;
  var _lastRecords = [];
  var _resolving = false;        // Phase 4c-1: 二重クリック防止フラグ
  var _transferring = false;     // Phase 4c-2: 二重クリック防止フラグ
  var _hasResolutionColumn = true;  // migration 未適用時は false で API から返る → 操作ボタン非表示
  var _hasTransferColumns = true;   // Phase 4c-2 migration 未適用時は false で転記ボタン非表示

  function init() {
    // 初期化時の重い処理はない。load 時に必要な要素を生成する。
  }

  function load() {
    var panel = document.getElementById(CONTAINER_ID);
    if (!panel) return;
    _renderShell(panel);
    _fetchAndRender();
  }

  // ── panel シェル ────────────────────────
  function _renderShell(panel) {
    if (panel.getAttribute('data-initialized') === '1') return;
    panel.setAttribute('data-initialized', '1');

    var todayStr = _dateStr(new Date());
    var weekAgoStr = _dateStr(new Date(Date.now() - 7 * 24 * 60 * 60 * 1000));

    panel.innerHTML = ''
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">'
      +   '<h2 style="margin:0;font-size:1.25rem;">\u7DCA\u6025\u4F1A\u8A08\u53F0\u5E33</h2>'
      +   '<span style="font-size:0.8rem;color:#666;">PWA Phase 4c-1 / \u7BA1\u7406\u8005\u5224\u65AD\u8A18\u9332</span>'
      + '</div>'
      + '<div style="background:#fff3cd;border:1px solid #ffd757;border-radius:6px;padding:10px 14px;margin-bottom:12px;color:#795500;font-size:0.85rem;line-height:1.6;">'
      +   '\u58F2\u4E0A\u53CD\u6620\u30FB\u4FEE\u6B63\u30FB\u53D6\u6D88\u306F\u307E\u3060\u3067\u304D\u307E\u305B\u3093\u3002\u7BA1\u7406\u8005\u5224\u65AD\u306E\u8A18\u9332\u306E\u307F\u53EF\u80FD\u3067\u3059\u3002'
      +   '\u7DCA\u6025\u4F1A\u8A08\u306F\u7AEF\u672B\u5185 IndexedDB \u304B\u3089\u540C\u671F\u3055\u308C\u305F\u4F1A\u8A08\u8A18\u9332\u3067\u3059\u3002conflict / pending_review \u306F\u5916\u90E8\u7AEF\u672B\u306E\u63A7\u3048\u3092\u78BA\u8A8D\u3057\u3066\u300C\u6709\u52B9\u78BA\u8A8D / \u91CD\u8907\u6271\u3044 / \u7121\u52B9\u6271\u3044 / \u4FDD\u7559\u300D\u3092\u884C\u3068\u3054\u3068\u306B\u8A18\u9332\u3057\u3066\u304F\u3060\u3055\u3044\u3002'
      + '</div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
      +   '<label style="font-size:0.85rem;">\u671F\u9593 '
      +     '<input type="date" id="epl-from" value="' + weekAgoStr + '" style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;">'
      +   '</label>'
      +   '<span>\u301C</span>'
      +   '<label style="font-size:0.85rem;">'
      +     '<input type="date" id="epl-to" value="' + todayStr + '" style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;">'
      +   '</label>'
      +   '<label style="font-size:0.85rem;margin-left:12px;">status '
      +     '<select id="epl-status" style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;">'
      +       '<option value="all">\u3059\u3079\u3066</option>'
      +       '<option value="synced">synced</option>'
      +       '<option value="conflict">conflict</option>'
      +       '<option value="pending_review">pending_review</option>'
      +       '<option value="failed">failed</option>'
      +     '</select>'
      +   '</label>'
      +   '<button type="button" id="epl-refresh" style="padding:6px 14px;background:#1565c0;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.85rem;">\u8868\u793A</button>'
      + '</div>'
      + '<div id="epl-summary" style="display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px;margin-bottom:12px;"></div>'
      + '<div id="epl-list"><div style="padding:40px;text-align:center;color:#888;">\u8AAD\u307F\u8FBC\u307F\u4E2D...</div></div>'
      + '<div id="epl-detail-overlay" style="display:none;"></div>';

    panel.querySelector('#epl-refresh').addEventListener('click', function () { _fetchAndRender(); });
  }

  // ── fetch + render ────────────────────
  function _fetchAndRender() {
    var panel = document.getElementById(CONTAINER_ID);
    if (!panel) return;
    if (typeof AdminApi === 'undefined' || !AdminApi.getEmergencyPaymentLedger) {
      _renderError(panel, 'AdminApi \u304C\u8AAD\u307F\u8FBC\u307E\u308C\u3066\u3044\u307E\u305B\u3093');
      return;
    }
    if (_loading) return;
    _loading = true;

    var from = panel.querySelector('#epl-from').value;
    var to   = panel.querySelector('#epl-to').value;
    var st   = panel.querySelector('#epl-status').value;
    panel.querySelector('#epl-list').innerHTML = '<div style="padding:40px;text-align:center;color:#888;">\u8AAD\u307F\u8FBC\u307F\u4E2D...</div>';

    AdminApi.getEmergencyPaymentLedger(from, to, st === 'all' ? '' : st).then(function (data) {
      _loading = false;
      if (!data) { _renderError(panel, '\u30C7\u30FC\u30BF\u304C\u53D6\u5F97\u3067\u304D\u307E\u305B\u3093\u3067\u3057\u305F'); return; }
      // Phase 4b レビュー指摘 #1 対応: summary は全体集計、records は LIMIT 300 で制限されうる
      var displayed = (typeof data.displayedCount === 'number') ? data.displayedCount : (data.records ? data.records.length : 0);
      var limit = data.limit || 300;
      // Phase 4c-1: API が hasResolutionColumn=false を返したら解決操作 UI を出さない
      _hasResolutionColumn = (data.hasResolutionColumn !== false);
      // Phase 4c-2: hasTransferColumns=false なら転記 UI を出さない
      _hasTransferColumns = (data.hasTransferColumns !== false);
      _renderSummary(panel, data.summary || {}, displayed, limit);
      _lastRecords = data.records || [];
      _renderList(panel, _lastRecords);
    }).catch(function (err) {
      _loading = false;
      _renderError(panel, (err && err.message) || '\u8AAD\u307F\u8FBC\u307F\u306B\u5931\u6557\u3057\u307E\u3057\u305F');
    });
  }

  function _renderError(panel, msg) {
    var listEl = panel.querySelector('#epl-list');
    if (listEl) {
      listEl.innerHTML = '<div style="padding:24px;text-align:center;color:#c62828;">' + _escape(msg) + '</div>';
    }
  }

  function _renderSummary(panel, s, displayed, limit) {
    var el = panel.querySelector('#epl-summary');
    if (!el) return;
    var count = parseInt(s.count || 0, 10);
    var total = parseInt(s.totalAmount || 0, 10);
    var need  = parseInt(s.needsReviewCount || 0, 10);
    var pinUn = parseInt(s.pinUnverifiedCount || 0, 10);
    // Phase 4c-1: resolution 別 4 KPI (migration 未適用時は 0)
    var unresolved = parseInt(s.unresolvedCount || 0, 10);
    var confirmed  = parseInt(s.confirmedCount  || 0, 10);
    var duplicate  = parseInt(s.duplicateCount  || 0, 10);
    var rejected   = parseInt(s.rejectedCount   || 0, 10);
    // Phase 4c-2: 転記可能 / 転記済み
    var transferable = parseInt(s.transferableCount || 0, 10);
    var transferred  = parseInt(s.transferredCount  || 0, 10);
    // Phase 4d-4a: 手入力計上可能 (transferable とは排他)
    var manualTransferable = parseInt(s.manualTransferableCount || 0, 10);

    // Phase 4b レビュー指摘 #1: LIMIT 300 で一覧側が切られる場合は件数ラベルに注記
    var countLabel = '\u4EF6\u6570';
    var countValue = String(count);
    if (displayed && limit && count > displayed) {
      countLabel = '\u4EF6\u6570 (\u5168\u4F53)';
      countValue = String(count) + ' / \u8868\u793A ' + displayed;
    }

    // 既存 4 + 新 4 の計 8 タイル (大きめ画面では 4 列 × 2 段になる grid-auto-flow)
    el.style.gridTemplateColumns = 'repeat(auto-fit,minmax(140px,1fr))';
    el.innerHTML = ''
      + _kpiTile(countLabel, countValue, '#455a64')
      + _kpiTile('\u5408\u8A08\u91D1\u984D', '\u00A5' + _formatYen(total), '#1565c0')
      + _kpiTile('\u8981\u78BA\u8A8D (conflict + pending_review)', String(need), need > 0 ? '#d32f2f' : '#455a64')
      + _kpiTile('PIN \u672A\u78BA\u8A8D', String(pinUn), pinUn > 0 ? '#f57c00' : '#455a64')
      + _kpiTile('\u672A\u89E3\u6C7A', String(unresolved), unresolved > 0 ? '#f57c00' : '#455a64')
      + _kpiTile('\u6709\u52B9\u78BA\u8A8D\u6E08', String(confirmed), '#2e7d32')
      + _kpiTile('\u91CD\u8907\u6271\u3044', String(duplicate), '#7b1fa2')
      + _kpiTile('\u7121\u52B9\u6271\u3044', String(rejected), '#c62828')
      // Phase 4c-2: 転記 KPI
      + _kpiTile('\u8EE2\u8A18\u53EF\u80FD', String(transferable), transferable > 0 ? '#1565c0' : '#455a64')
      + _kpiTile('\u58F2\u4E0A\u8EE2\u8A18\u6E08', String(transferred), '#00695c')
      // Phase 4d-4a: 手入力計上可能 (transferable とは別軸、分離表示)
      + _kpiTile('\u624B\u5165\u529B\u8A08\u4E0A\u53EF\u80FD', String(manualTransferable), manualTransferable > 0 ? '#6a1b9a' : '#455a64');
  }

  function _kpiTile(label, value, color) {
    return '<div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:10px 12px;">'
      + '<div style="font-size:0.75rem;color:#666;margin-bottom:4px;">' + _escape(label) + '</div>'
      + '<div style="font-size:1.3rem;font-weight:700;color:' + color + ';">' + _escape(value) + '</div>'
      + '</div>';
  }

  function _renderList(panel, records) {
    var listEl = panel.querySelector('#epl-list');
    if (!listEl) return;
    if (!records || records.length === 0) {
      listEl.innerHTML = '<div style="padding:40px;text-align:center;color:#999;background:#fafafa;border-radius:6px;">\u6761\u4EF6\u306B\u5408\u3046\u8A18\u9332\u306F\u3042\u308A\u307E\u305B\u3093</div>';
      return;
    }

    var html = ''
      + '<div style="overflow-x:auto;">'
      + '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;background:#fff;">'
      + '<thead><tr style="background:#eceff1;">'
      +   '<th style="padding:8px;text-align:left;white-space:nowrap;">\u65E5\u6642</th>'
      +   '<th style="padding:8px;text-align:left;">\u540C\u671F\u72B6\u614B</th>'
      +   '<th style="padding:8px;text-align:left;">\u7BA1\u7406\u8005\u5224\u65AD</th>'
      +   '<th style="padding:8px;text-align:left;">\u30C6\u30FC\u30D6\u30EB</th>'
      +   '<th style="padding:8px;text-align:right;">\u5408\u8A08</th>'
      +   '<th style="padding:8px;text-align:left;">\u652F\u6255</th>'
      +   '<th style="padding:8px;text-align:left;">\u63A7\u3048/\u627F\u8A8D</th>'
      +   '<th style="padding:8px;text-align:left;">\u62C5\u5F53</th>'
      +   '<th style="padding:8px;text-align:left;">PIN</th>'
      +   '<th style="padding:8px;text-align:left;">\u89E3\u6C7A\u8005 / \u6642\u523B</th>'
      +   '<th style="padding:8px;text-align:left;">\u8981\u78BA\u8A8D\u306E\u7406\u7531</th>'
      +   '<th style="padding:8px;text-align:left;">\u8A18\u9332ID</th>'
      + '</tr></thead><tbody>';

    for (var i = 0; i < records.length; i++) {
      var r = records[i];
      var tsDisp = _shortTs(r.serverReceivedAt || r.clientCreatedAt);
      var method = _methodLabel(r.paymentMethod, r.externalMethodType);
      var slipApp = [];
      if (r.externalSlipNo)     slipApp.push(r.externalSlipNo);
      if (r.externalApprovalNo) slipApp.push(r.externalApprovalNo);
      var slipStr = slipApp.length > 0 ? slipApp.join(' / ') : '-';
      // Phase 4d-1: PIN 4 状態判定
      //   staffPinVerified=true                              → 確認済 (緑)
      //   pinEnteredClient=true  && !staffPinVerified        → 入力不一致 (オレンジ)
      //   pinEnteredClient=false && !staffPinVerified        → 入力なし (グレー)
      //   pinEnteredClient=null/undefined && !staffPinVerified → 未確認 (旧データ不明、グレー)
      var pinCell = _pinBadge(r.staffPinVerified, r.pinEnteredClient);
      var localShort = (r.localEmergencyPaymentId || '').substring(0, 20) + '\u2026';

      // Phase 4d-1: 担当セル。PIN 未検証時は「自己申告: 〇〇」と prefix を付ける (staff_user_id 紐付けなし)
      var staffCell = _staffCell(r.staffName, r.staffPinVerified);

      // Phase 4d-1: 手入力記録の目立たせ
      //   tableId/orderIds が空、または conflictReason に manual_entry_without_context を含む場合に
      //   薄黄色バッジを付けて「要手動照合」を示す。エラーの赤には寄せない。
      var isManualEntry = _isManualEntryRecord(r);
      var manualBadgeHtml = isManualEntry
        ? ' <span style="display:inline-block;white-space:nowrap;margin-left:4px;padding:1px 6px;border-radius:10px;background:#fff3cd;color:#795500;font-size:0.7rem;border:1px solid #d0a200;" title="\u624B\u5165\u529B\u8A18\u9332 (\u7BA1\u7406\u8005\u78BA\u8A8D\u5FC5\u8981)">\u624B\u5165\u529B</span>'
        : '';
      // Phase 4d-5a: payments 取消済みなら「取消済み」バッジを日時列に追加
      var voidBadgeHtml = (r.paymentVoidStatus === 'voided')
        ? ' <span style="display:inline-block;white-space:nowrap;margin-left:4px;padding:1px 6px;border-radius:10px;background:#ffebee;color:#c62828;font-size:0.7rem;border:1px solid #ef9a9a;" title="\u624B\u5165\u529B\u8A08\u4E0A\u306F\u53D6\u6D88\u6E08\u307F">\u53D6\u6D88\u6E08</span>'
        : '';
      var rowBgStyle = isManualEntry ? 'background:#fffde7;' : '';
      // Phase 4c-1: 解決列 + 解決者・日時列
      var resolutionCell = _resolutionBadge(r.resolutionStatus);
      var resolvedBy = r.resolvedByName ? _escape(r.resolvedByName) : '-';
      var resolvedTs = r.resolvedAt ? _escape(_shortTs(r.resolvedAt)) : '';
      var resolvedCell = resolvedBy + (resolvedTs ? ('<br><span style="font-size:0.7rem;color:#888;">' + resolvedTs + '</span>') : '');

      html += '<tr data-idx="' + i + '" class="epl-row" style="border-bottom:1px solid #eee;cursor:pointer;' + rowBgStyle + '">'
        + '<td style="padding:8px;white-space:nowrap;">' + _escape(tsDisp) + manualBadgeHtml + voidBadgeHtml + '</td>'
        + '<td style="padding:8px;">' + _statusBadge(r.status) + '</td>'
        + '<td style="padding:8px;">' + resolutionCell + '</td>'
        + '<td style="padding:8px;">' + _escape(r.tableCode || '-') + '</td>'
        + '<td style="padding:8px;text-align:right;">\u00A5' + _formatYen(r.totalAmount || 0) + '</td>'
        + '<td style="padding:8px;">' + _escape(method) + '</td>'
        + '<td style="padding:8px;font-size:0.8rem;">' + _escape(slipStr) + '</td>'
        + '<td style="padding:8px;">' + staffCell + '</td>'
        + '<td style="padding:8px;text-align:center;">' + pinCell + '</td>'
        + '<td style="padding:8px;font-size:0.8rem;">' + resolvedCell + '</td>'
        + '<td style="padding:8px;font-size:0.8rem;color:#d32f2f;">' + _escape(_humanizeConflictReason(r.conflictReason) || '') + '</td>'
        + '<td style="padding:8px;font-size:0.75rem;color:#666;font-family:monospace;">' + _escape(localShort) + '</td>'
        + '</tr>';
    }

    html += '</tbody></table></div>';
    listEl.innerHTML = html;

    // 行クリックで詳細
    var rows = listEl.querySelectorAll('.epl-row');
    for (var j = 0; j < rows.length; j++) {
      rows[j].addEventListener('click', function (e) {
        var idx = parseInt(this.getAttribute('data-idx'), 10);
        if (isNaN(idx)) return;
        _openDetail(_lastRecords[idx]);
      });
    }
  }

  // ── 詳細モーダル ─────────────────────
  function _openDetail(r) {
    if (!r) return;
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:12000;display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;';
    var panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;border-radius:8px;max-width:720px;width:100%;max-height:85vh;overflow-y:auto;padding:20px;';

    var itemsHtml = '<div style="color:#999;font-size:0.8rem;">\u660E\u7D30\u306A\u3057</div>';
    if (r.itemSnapshot && r.itemSnapshot.length > 0) {
      itemsHtml = '<table style="width:100%;border-collapse:collapse;font-size:0.8rem;">'
        + '<thead><tr style="background:#f5f5f5;">'
        +   '<th style="padding:4px 8px;text-align:left;">\u54C1\u540D</th>'
        +   '<th style="padding:4px 8px;text-align:right;">\u6570\u91CF</th>'
        +   '<th style="padding:4px 8px;text-align:right;">\u5358\u4FA1</th>'
        +   '<th style="padding:4px 8px;text-align:right;">\u7A0E\u7387</th>'
        + '</tr></thead><tbody>';
      for (var i = 0; i < r.itemSnapshot.length; i++) {
        var it = r.itemSnapshot[i];
        itemsHtml += '<tr style="border-bottom:1px solid #eee;">'
          + '<td style="padding:4px 8px;">' + _escape(it.name || '') + '</td>'
          + '<td style="padding:4px 8px;text-align:right;">' + _escape(String(it.qty || 0)) + '</td>'
          + '<td style="padding:4px 8px;text-align:right;">\u00A5' + _formatYen(it.price || 0) + '</td>'
          + '<td style="padding:4px 8px;text-align:right;">' + (it.taxRate != null ? _escape(String(it.taxRate) + '%') : '-') + '</td>'
          + '</tr>';
      }
      itemsHtml += '</tbody></table>';
    }

    var orderIdsHtml = (r.orderIds && r.orderIds.length > 0)
      ? r.orderIds.map(function (o) { return '<div style="font-family:monospace;font-size:0.75rem;">' + _escape(o) + '</div>'; }).join('')
      : '<span style="color:#999;">(\u624B\u5165\u529B\u30E2\u30FC\u30C9 / \u6CE8\u6587\u7D10\u4ED8\u306A\u3057)</span>';

    // Phase 4c-1: 解決情報 + 操作 UI
    var resolutionBlock = _renderResolutionBlock(r);
    // Phase 4c-2: 売上転記情報 + 操作 UI
    var transferBlock = _renderTransferBlock(r);

    panel.innerHTML = ''
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
      +   '<h3 style="margin:0;font-size:1.1rem;">\u7DCA\u6025\u4F1A\u8A08 \u8A73\u7D30</h3>'
      +   '<button type="button" id="epl-detail-close" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>'
      + '</div>'
      + '<div style="font-size:0.85rem;line-height:1.8;">'
      +   '<div><strong>\u72B6\u614B:</strong> ' + _statusBadge(r.status) + '</div>'
      +   '<div><strong>\u30C6\u30FC\u30D6\u30EB:</strong> ' + _escape(r.tableCode || '-') + '</div>'
      +   '<div><strong>\u5408\u8A08:</strong> \u00A5' + _formatYen(r.totalAmount || 0) + ' (\u7A0E\u629C ' + _formatYen(r.subtotal || 0) + ' / \u7A0E ' + _formatYen(r.tax || 0) + ')</div>'
      +   '<div><strong>\u652F\u6255\u65B9\u6CD5:</strong> ' + _escape(_methodLabel(r.paymentMethod, r.externalMethodType)) + '</div>'
      +   (r.paymentMethod === 'other_external'
            ? ('<div><strong>\u5916\u90E8\u5206\u985E:</strong> '
                + (_externalMethodLabel(r.externalMethodType)
                     ? _escape(_externalMethodLabel(r.externalMethodType))
                     : '<span style="color:#888;">\u672A\u5206\u985E</span>')
                + '</div>')
            : '')
      +   ((r.receivedAmount != null) ? ('<div><strong>\u53D7\u9818:</strong> \u00A5' + _formatYen(r.receivedAmount) + ' / <strong>\u91E3\u92AD:</strong> \u00A5' + _formatYen(r.changeAmount || 0) + '</div>') : '')
      +   (r.externalTerminalName ? ('<div><strong>\u5916\u90E8\u7AEF\u672B:</strong> ' + _escape(r.externalTerminalName) + '</div>') : '')
      +   (r.externalSlipNo       ? ('<div><strong>\u63A7\u3048\u756A\u53F7:</strong> ' + _escape(r.externalSlipNo) + '</div>') : '')
      +   (r.externalApprovalNo   ? ('<div><strong>\u627F\u8A8D\u756A\u53F7:</strong> ' + _escape(r.externalApprovalNo) + '</div>') : '')
      +   '<div><strong>\u62C5\u5F53:</strong> ' + _staffCell(r.staffName, r.staffPinVerified) + ' (PIN: ' + _pinLabelText(r.staffPinVerified, r.pinEnteredClient) + ')</div>'
      +   (r.conflictReason ? ('<div style="color:#c62828;"><strong>\u8981\u78BA\u8A8D\u306E\u7406\u7531:</strong> ' + _escape(_humanizeConflictReason(r.conflictReason)) + '</div>') : '')
      +   (r.note ? ('<div><strong>\u30E1\u30E2:</strong> ' + _escape(r.note) + '</div>') : '')
      +   '<div><strong>\u7AEF\u672B\u8A18\u9332\u6642\u523B:</strong> ' + _escape(_shortTs(r.clientCreatedAt) || '-') + '</div>'
      +   '<div><strong>\u30B5\u30FC\u30D0\u30FC\u53D7\u4FE1\u6642\u523B:</strong> ' + _escape(_shortTs(r.serverReceivedAt) || '-') + '</div>'
      +   '<div style="margin-top:6px;font-size:0.7rem;color:#888;"><strong>\u30ED\u30FC\u30AB\u30EB\u8A18\u9332ID:</strong> <code style="font-size:0.7rem;">' + _escape(r.localEmergencyPaymentId || '-') + '</code> <span>(\u7AEF\u672B\u5185\u90E8ID)</span></div>'
      + '</div>'
      + '<hr style="margin:12px 0;">'
      + resolutionBlock
      + '<hr style="margin:12px 0;">'
      + transferBlock
      + '<hr style="margin:12px 0;">'
      + '<h4 style="margin:8px 0;font-size:0.95rem;">\u6CE8\u6587ID\u4E00\u89A7</h4>'
      + '<div style="background:#f9f9f9;padding:8px;border-radius:4px;max-height:120px;overflow-y:auto;">' + orderIdsHtml + '</div>'
      + '<h4 style="margin:12px 0 8px;font-size:0.95rem;">\u54C1\u76EE\u5185\u8A33</h4>'
      + itemsHtml
      + '<div style="margin-top:14px;padding:10px;background:#fff3cd;border-radius:4px;color:#795500;font-size:0.8rem;">'
      +   '\u26A0 \u58F2\u4E0A\u3078\u53CD\u6620\u3059\u308B\u306B\u306F\u3001\u3053\u306E\u753B\u9762\u306E\u300C\u58F2\u4E0A\u3078\u8EE2\u8A18\u3059\u308B\u300D\u64CD\u4F5C\u304C\u5FC5\u8981\u3067\u3059\u3002\u672A\u8EE2\u8A18\u306E\u8A18\u9332\u306F\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u306B\u306F\u53CD\u6620\u3055\u308C\u307E\u305B\u3093\u3002'
      + '</div>';

    overlay.appendChild(panel);
    overlay.id = 'epl-detail-overlay-active';
    overlay.addEventListener('click', function (e) { if (e.target === overlay && !_resolving) overlay.remove(); });
    document.body.appendChild(overlay);
    panel.querySelector('#epl-detail-close').addEventListener('click', function () { if (!_resolving) overlay.remove(); });

    // Phase 4c-1: 解決ボタンのバインド
    _bindResolutionButtons(panel, r, overlay);
    // Phase 4c-2: 売上転記ボタンのバインド
    _bindTransferButton(panel, r, overlay);
  }

  // ── Phase 4c-1: 解決ブロック HTML ────
  function _renderResolutionBlock(r) {
    var status = r.resolutionStatus || 'unresolved';
    var html = '<h4 style="margin:8px 0;font-size:0.95rem;">\u7BA1\u7406\u8005\u89E3\u6C7A (Phase 4c-1)</h4>';
    html += '<div style="font-size:0.85rem;line-height:1.8;">';
    html += '<div><strong>\u89E3\u6C7A\u72B6\u614B:</strong> ' + _resolutionBadge(status) + '</div>';
    if (r.resolvedByName) html += '<div><strong>\u89E3\u6C7A\u8005:</strong> ' + _escape(r.resolvedByName) + '</div>';
    if (r.resolvedAt)     html += '<div><strong>\u89E3\u6C7A\u65E5\u6642:</strong> ' + _escape(r.resolvedAt) + '</div>';
    if (r.resolutionNote) html += '<div><strong>\u30E1\u30E2:</strong> ' + _escape(r.resolutionNote) + '</div>';
    html += '</div>';

    // migration 未適用時は操作 UI を出さない
    if (!_hasResolutionColumn) {
      html += '<div style="margin-top:8px;padding:8px;background:#ffebee;color:#c62828;font-size:0.8rem;border-radius:4px;">'
        + '\u89E3\u6C7A\u7528\u30AB\u30E9\u30E0\u672A\u9069\u7528 (migration-pwa4c-emergency-payment-resolution.sql \u672A\u9069\u7528)\u3002\u7BA1\u7406\u8005\u306B\u9023\u7D61\u3057\u3066\u304F\u3060\u3055\u3044\u3002'
        + '</div>';
      return html;
    }

    // 終結状態 (confirmed/duplicate/rejected) は操作不可
    var terminal = { confirmed: true, duplicate: true, rejected: true };
    if (terminal[status]) {
      html += '<div style="margin-top:8px;padding:8px;background:#e3f2fd;color:#1565c0;font-size:0.8rem;border-radius:4px;">'
        + '\u65E2\u306B\u89E3\u6C7A\u6E08\u307F\u3067\u3059\u3002\u5909\u66F4\u306F Phase 4c-2 \u4EE5\u964D\u306E\u300C\u89E3\u6C7A\u53D6\u6D88\u300D\u30D5\u30ED\u30FC\u3092\u304A\u5F85\u3061\u304F\u3060\u3055\u3044\u3002'
        + '</div>';
      return html;
    }

    // unresolved / pending → 操作可能
    html += '<div style="margin-top:10px;padding:10px;background:#f5f5f5;border-radius:4px;">'
      + '<div style="font-size:0.8rem;margin-bottom:6px;color:#555;">\u7BA1\u7406\u8005\u30E1\u30E2 (\u300C\u91CD\u8907\u6271\u3044\u300D\u300C\u7121\u52B9\u6271\u3044\u300D\u306F\u5FC5\u9808)</div>'
      + '<textarea id="epl-resolve-note" maxlength="255" rows="2" style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" placeholder="\u5916\u90E8\u7AEF\u672B\u63A7\u3048\u3068\u306E\u7A81\u5408\u7D50\u679C\u306A\u3069"></textarea>'
      + '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">'
      +   '<button type="button" class="epl-resolve-btn" data-action="confirm"   style="flex:1;min-width:120px;padding:8px;background:#2e7d32;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.85rem;">\u6709\u52B9\u78BA\u8A8D</button>'
      +   '<button type="button" class="epl-resolve-btn" data-action="duplicate" style="flex:1;min-width:120px;padding:8px;background:#7b1fa2;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.85rem;">\u91CD\u8907\u6271\u3044</button>'
      +   '<button type="button" class="epl-resolve-btn" data-action="reject"    style="flex:1;min-width:120px;padding:8px;background:#c62828;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.85rem;">\u7121\u52B9\u6271\u3044</button>'
      +   '<button type="button" class="epl-resolve-btn" data-action="pending"   style="flex:1;min-width:120px;padding:8px;background:#546e7a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.85rem;">\u4FDD\u7559\u306B\u3059\u308B</button>'
      + '</div>'
      + '<div id="epl-resolve-msg" style="margin-top:8px;font-size:0.8rem;min-height:1.2em;"></div>'
      + '</div>';
    return html;
  }

  function _bindResolutionButtons(panel, record, overlay) {
    var btns = panel.querySelectorAll('.epl-resolve-btn');
    if (!btns || btns.length === 0) return;
    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener('click', function () {
        _submitResolution(this.getAttribute('data-action'), record, panel, overlay);
      });
    }
  }

  function _submitResolution(action, record, panel, overlay) {
    if (_resolving) return;
    if (!record || !record.id) return;
    if (typeof AdminApi === 'undefined' || !AdminApi.resolveEmergencyPayment) {
      _setResolveMsg(panel, 'AdminApi \u304C\u8AAD\u307F\u8FBC\u307E\u308C\u3066\u3044\u307E\u305B\u3093', '#c62828');
      return;
    }
    var actionLabelMap = { confirm: '\u6709\u52B9\u78BA\u8A8D', duplicate: '\u91CD\u8907\u6271\u3044', reject: '\u7121\u52B9\u6271\u3044', pending: '\u4FDD\u7559' };
    var actionLabel = actionLabelMap[action] || action;

    var noteInput = panel.querySelector('#epl-resolve-note');
    var note = noteInput ? noteInput.value.replace(/^\s+|\s+$/g, '').substring(0, 255) : '';
    var requireNote = (action === 'duplicate' || action === 'reject');
    if (requireNote && !note) {
      _setResolveMsg(panel, '\u300C' + actionLabel + '\u300D\u306B\u306F note \u306E\u5165\u529B\u304C\u5FC5\u9808\u3067\u3059', '#c62828');
      if (noteInput) noteInput.focus();
      return;
    }

    var confirmMsg = '\u300C' + actionLabel + '\u300D\u3068\u3057\u3066\u8A18\u9332\u3057\u307E\u3059\u3002\u3088\u308D\u3057\u3044\u3067\u3059\u304B\uFF1F\n\n'
      + '\u203B \u3053\u306E\u64CD\u4F5C\u306F\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u306B\u306F\u53CD\u6620\u3055\u308C\u307E\u305B\u3093 (Phase 4c-2 \u4EE5\u964D\u3067\u9023\u643A\u4E88\u5B9A)';
    if (!window.confirm(confirmMsg)) return;

    _resolving = true;
    _toggleResolveButtons(panel, true);
    _setResolveMsg(panel, '\u51E6\u7406\u4E2D...', '#1565c0');

    AdminApi.resolveEmergencyPayment(record.id, action, note).then(function (data) {
      _resolving = false;
      if (overlay) overlay.remove();
      _fetchAndRender();
    }).catch(function (err) {
      _resolving = false;
      _toggleResolveButtons(panel, false);
      var msg = (err && err.message) || '\u5931\u6557\u3057\u307E\u3057\u305F';
      // 409 Conflict (既に解決済み) の判定: サーバーメッセージに「既に解決済み」が含まれる
      if (msg.indexOf('\u65E2\u306B\u89E3\u6C7A\u6E08\u307F') !== -1) {
        _setResolveMsg(panel, '\u5225\u306E\u7BA1\u7406\u8005\u304C\u65E2\u306B\u89E3\u6C7A\u3092\u8A18\u9332\u3057\u305F\u53EF\u80FD\u6027\u304C\u3042\u308A\u307E\u3059\u3002' + msg, '#c62828');
      } else {
        _setResolveMsg(panel, msg, '#c62828');
      }
    });
  }

  function _toggleResolveButtons(panel, disabled) {
    var btns = panel.querySelectorAll('.epl-resolve-btn');
    for (var i = 0; i < btns.length; i++) {
      btns[i].disabled = !!disabled;
      btns[i].style.opacity = disabled ? '0.5' : '1';
      btns[i].style.cursor = disabled ? 'not-allowed' : 'pointer';
    }
  }

  function _setResolveMsg(panel, msg, color) {
    var el = panel.querySelector('#epl-resolve-msg');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = color || '#666';
  }

  // ── Phase 4c-2: 売上転記ブロック HTML + バインド ────
  function _renderTransferBlock(r) {
    var html = '<h4 style="margin:8px 0;font-size:0.95rem;">\u58F2\u4E0A\u8EE2\u8A18 (Phase 4c-2)</h4>';

    // 既に転記済み
    if (r && r.syncedPaymentId) {
      // Phase 4d-5a: void 済みなら「取消済み」バッジ、未 void なら取消ボタン (手入力計上分のみ)
      var isVoided = (r.paymentVoidStatus === 'voided');
      var isManualOnly = (!r.orderIds || r.orderIds.length === 0);  // 手入力計上 (4d-4a 由来) だけが void 対象
      html += '<div style="font-size:0.85rem;line-height:1.8;">';
      html += '<div><strong>\u8EE2\u8A18\u6E08\u307F:</strong> <code style="font-size:0.75rem;">' + _escape(r.syncedPaymentId) + '</code></div>';
      if (r.transferredByName) html += '<div><strong>\u8EE2\u8A18\u8005:</strong> ' + _escape(r.transferredByName) + '</div>';
      if (r.transferredAt)     html += '<div><strong>\u8EE2\u8A18\u65E5\u6642:</strong> ' + _escape(r.transferredAt) + '</div>';
      if (r.transferNote)      html += '<div><strong>\u30E1\u30E2:</strong> ' + _escape(r.transferNote) + '</div>';
      html += '</div>';
      if (isVoided) {
        // 取消済み表示
        html += '<div style="margin-top:8px;padding:8px;background:#ffebee;color:#c62828;font-size:0.85rem;border-radius:4px;border:1px solid #ef9a9a;">'
          + '\u2717 <strong>\u53D6\u6D88\u6E08\u307F</strong>'
          + (r.paymentVoidedAt ? (' (' + _escape(_shortTs(r.paymentVoidedAt)) + ')') : '')
          + (r.paymentVoidReason ? ('<br><span style="font-size:0.8rem;">\u7406\u7531: ' + _escape(r.paymentVoidReason) + '</span>') : '')
          + '</div>';
      } else {
        html += '<div style="margin-top:8px;padding:8px;background:#e8f5e9;color:#2e7d32;font-size:0.8rem;border-radius:4px;">'
          + '\u2713 \u901A\u5E38\u58F2\u4E0A payments \u3078\u8EE2\u8A18\u6E08\u307F\u3002\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u30FB\u30EC\u30B8\u7DE0\u3081\u306B\u306F\u7BA1\u7406\u753B\u9762\u5074\u306E\u96C6\u8A08\u304C\u53CD\u6620\u3055\u308C\u307E\u3059\u3002'
          + '</div>';
        // Phase 4d-5a: 手入力計上分 → 赤ボタン (#c62828)
        // Phase 4d-5b: 注文紐付き transfer 分 → オレンジボタン (#ef6c00) で区別
        if (isManualOnly) {
          html += '<div style="margin-top:10px;padding:10px;background:#f5f5f5;border-radius:4px;">'
            + '<div style="font-size:0.8rem;color:#c62828;margin-bottom:6px;">\u25BC \u624B\u5165\u529B\u8A08\u4E0A\u3092\u53D6\u308A\u6D88\u3059 (Phase 4d-5a)</div>'
            + '<div style="font-size:0.75rem;color:#666;line-height:1.5;margin-bottom:8px;">'
            + 'payments \u306F\u8AD6\u7406\u53D6\u6D88 (void_status=voided) \u306B\u306A\u308A\u307E\u3059\u3002\u73FE\u91D1\u306E\u5834\u5408\u306F cash_log \u306B cash_out \u76F8\u6BBA\u884C\u304C\u81EA\u52D5\u3067\u5165\u308A\u307E\u3059\u3002'
            + '\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u306B\u306F\u5143\u3005\u51FA\u3066\u3044\u306A\u3044\u305F\u3081\u5F71\u97FF\u306A\u3057\u3002synced_payment_id \u306F\u5C65\u6B74\u3068\u3057\u3066\u6B8B\u308A\u307E\u3059\u3002'
            + '</div>'
            + '<div style="font-size:0.8rem;margin-bottom:6px;color:#555;">\u53D6\u6D88\u7406\u7531 (\u5FC5\u9808 / 255 \u6587\u5B57\u4EE5\u5185)</div>'
            + '<textarea id="epl-void-reason" maxlength="255" rows="2" style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" placeholder="\u4F8B: \u5916\u90E8\u7AEF\u672B\u63A7\u3048\u3068\u306E\u4E0D\u4E00\u81F4\u304C\u767A\u898B\u3055\u308C\u305F\u305F\u3081"></textarea>'
            + '<div style="margin-top:10px;">'
            + '<button type="button" id="epl-void-btn" style="padding:10px 20px;background:#c62828;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;font-weight:600;">\u624B\u5165\u529B\u8A08\u4E0A\u3092\u53D6\u308A\u6D88\u3059</button>'
            + '</div>'
            + '<div id="epl-void-msg" style="margin-top:8px;font-size:0.8rem;min-height:1.2em;"></div>'
            + '</div>';
        } else {
          // Phase 4d-5b: 注文紐付き transfer 分の void
          //   orders.status は paid のまま、sales-report / turnover-report には残る (4d-5c で統合予定)
          //   table_sessions / session_token は戻さない (退店済み卓の再オープン事故を避ける)
          html += '<div style="margin-top:10px;padding:10px;background:#f5f5f5;border-radius:4px;">'
            + '<div style="font-size:0.8rem;color:#ef6c00;margin-bottom:6px;">\u25BC \u7DCA\u6025\u4F1A\u8A08\u8EE2\u8A18\u3092\u53D6\u308A\u6D88\u3059 (Phase 4d-5b)</div>'
            + '<div style="font-size:0.75rem;color:#666;line-height:1.5;margin-bottom:8px;">'
            + 'payments \u306F\u8AD6\u7406\u53D6\u6D88 (void_status=voided) \u306B\u306A\u308A\u307E\u3059\u3002\u73FE\u91D1\u306E\u5834\u5408\u306F cash_log \u306B cash_out \u76F8\u6BBA\u884C\u304C\u81EA\u52D5\u3067\u5165\u308A\u307E\u3059\u3002<br>'
            + '<strong style="color:#c62828;">\u26A0 orders.status \u306F paid \u306E\u307E\u307E\u7DAD\u6301\u3055\u308C\u307E\u3059\u3002\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u30FB\u56DE\u8EE2\u7387\u30EC\u30DD\u30FC\u30C8\u306B\u306F\u5F15\u304D\u7D9A\u304D\u6B8B\u308A\u307E\u3059 (Phase 4d-5c \u4EE5\u964D\u3067\u7D71\u5408\u4E88\u5B9A)</strong><br>'
            + '\u30C6\u30FC\u30D6\u30EB\u30BB\u30C3\u30B7\u30E7\u30F3\u30FBQR (session_token) \u306F\u623B\u3057\u307E\u305B\u3093\u3002'
            + '</div>'
            + '<div style="font-size:0.8rem;margin-bottom:6px;color:#555;">\u53D6\u6D88\u7406\u7531 (\u5FC5\u9808 / 255 \u6587\u5B57\u4EE5\u5185)</div>'
            + '<textarea id="epl-transfer-void-reason" maxlength="255" rows="2" style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" placeholder="\u4F8B: \u5916\u90E8\u7AEF\u672B\u63A7\u3048\u3068\u306E\u4E0D\u4E00\u81F4\u304C\u767A\u898B\u3055\u308C\u305F\u305F\u3081"></textarea>'
            + '<div style="margin-top:10px;">'
            + '<button type="button" id="epl-transfer-void-btn" style="padding:10px 20px;background:#ef6c00;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;font-weight:600;">\u7DCA\u6025\u4F1A\u8A08\u8EE2\u8A18\u3092\u53D6\u308A\u6D88\u3059</button>'
            + '</div>'
            + '<div id="epl-transfer-void-msg" style="margin-top:8px;font-size:0.8rem;min-height:1.2em;"></div>'
            + '</div>';
        }
      }
      return html;
    }

    // migration 4c-2 未適用
    if (!_hasTransferColumns) {
      html += '<div style="margin-top:8px;padding:8px;background:#ffebee;color:#c62828;font-size:0.8rem;border-radius:4px;">'
        + '\u8EE2\u8A18\u7528\u30AB\u30E9\u30E0\u672A\u9069\u7528 (migration-pwa4c2-emergency-payment-transfer.sql \u672A\u9069\u7528)\u3002\u7BA1\u7406\u8005\u306B\u9023\u7D61\u3057\u3066\u304F\u3060\u3055\u3044\u3002'
        + '</div>';
      return html;
    }

    // 転記不可の各理由
    if ((r.resolutionStatus || 'unresolved') !== 'confirmed') {
      // 生 slug ではなく日本語ラベルを表示する (不明な slug は素直に slug を出す fallback)
      var rsLabelMap = {
        'unresolved': '\u672A\u89E3\u6C7A',
        'duplicate':  '\u91CD\u8907\u6271\u3044',
        'rejected':   '\u7121\u52B9\u6271\u3044',
        'pending':    '\u4FDD\u7559\u4E2D'
      };
      var curRs = r.resolutionStatus || 'unresolved';
      var curRsLabel = Object.prototype.hasOwnProperty.call(rsLabelMap, curRs) ? rsLabelMap[curRs] : curRs;
      html += '<div style="margin-top:8px;padding:8px;background:#f5f5f5;color:#555;font-size:0.8rem;border-radius:4px;">'
        + '\u5148\u306B\u300C\u6709\u52B9\u78BA\u8A8D\u300D\u3092\u8A18\u9332\u3057\u3066\u304F\u3060\u3055\u3044 (\u73FE\u5728: ' + _escape(curRsLabel) + ')\u3002'
        + '</div>';
      return html;
    }
    if (r.status === 'failed') {
      html += '<div style="margin-top:8px;padding:8px;background:#ffebee;color:#c62828;font-size:0.8rem;border-radius:4px;">'
        + '\u540C\u671F\u5931\u6557 (status=failed) \u306E\u8A18\u9332\u306F\u8EE2\u8A18\u3067\u304D\u307E\u305B\u3093\u3002'
        + '</div>';
      return html;
    }
    if (!r.orderIds || r.orderIds.length === 0) {
      // Phase 4d-4a: 手入力モードは専用 API で payments に計上できる。
      //   resolution_status=confirmed かつ未転記のときのみ「売上として計上する (手入力)」ボタンを出す。
      //   other_external は分類済みのみ (未分類は上の分類フォームで先に分類する)
      var extType = r.externalMethodType || null;
      var allowedExt = ['voucher', 'bank_transfer', 'accounts_receivable', 'point', 'other'];
      var otherExtOk = (r.paymentMethod !== 'other_external') ||
                       (r.paymentMethod === 'other_external' && extType && allowedExt.indexOf(extType) !== -1);
      if (!otherExtOk) {
        // Phase 4d-4a-hotfix1: 手入力 other_external 未分類でも分類フォームを出す。
        //   旧データ (4d-2 以前の other_external で externalMethodType=null) を
        //   管理画面から後追い分類 → 手入力計上へ進められるようにする。
        //   既存 #epl-ext-method-sel / #epl-ext-method-save / _submitExternalMethod を再利用。
        html += ''
          + '<div style="margin-top:8px;padding:10px;background:#fff3cd;border:1px solid #ffd757;border-radius:4px;color:#795500;font-size:0.8rem;line-height:1.6;">'
          + '\u5916\u90E8\u5206\u985E\u304C\u672A\u8A2D\u5B9A\u3067\u3059\u3002\u307E\u305A\u5206\u985E\u3092\u8A18\u9332\u3057\u3066\u304B\u3089\u624B\u5165\u529B\u8A08\u4E0A\u3078\u9032\u3093\u3067\u304F\u3060\u3055\u3044\u3002'
          + '</div>'
          + '<div style="margin-top:10px;padding:10px;background:#f5f5f5;border-radius:4px;">'
          + '<label style="font-size:0.8rem;color:#555;display:block;margin-bottom:6px;">\u5916\u90E8\u5206\u985E</label>'
          + '<select id="epl-ext-method-sel" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-size:0.9rem;margin-bottom:10px;">'
          + '<option value="">\u9078\u629E\u3057\u3066\u304F\u3060\u3055\u3044</option>'
          + '<option value="voucher">\u5546\u54C1\u5238</option>'
          + '<option value="bank_transfer">\u4E8B\u524D\u632F\u8FBC</option>'
          + '<option value="accounts_receivable">\u58F2\u639B</option>'
          + '<option value="point">\u30DD\u30A4\u30F3\u30C8\u5145\u5F53</option>'
          + '<option value="other">\u305D\u306E\u4ED6</option>'
          + '</select>'
          + '<button type="button" id="epl-ext-method-save" style="padding:8px 16px;background:#2e7d32;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;">\u5206\u985E\u3092\u8A18\u9332</button>'
          + '<div id="epl-ext-method-msg" style="margin-top:8px;font-size:0.8rem;min-height:1.2em;"></div>'
          + '</div>';
        return html;
      }
      html += ''
        + '<div style="margin-top:8px;padding:10px;background:#fff3cd;border:1px solid #ffd757;border-radius:4px;color:#795500;font-size:0.8rem;line-height:1.6;">'
        + '\u26A0 \u6CE8\u6587\u7D10\u4ED8\u3051\u306A\u3057\u306E\u624B\u5165\u529B\u8A18\u9332\u3067\u3059\u3002\u3053\u306E\u64CD\u4F5C\u3067 payments \u306B\u8A08\u4E0A\u3057\u3001\u5F8C\u65E5\u306E\u53D6\u6D88\u306F 4d-5 \u4EE5\u964D\u3067\u8A2D\u8A08\u4E88\u5B9A\u3067\u3059\u3002<br>'
        + '<strong>\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8 (orders \u30D9\u30FC\u30B9) \u306B\u306F\u51FA\u307E\u305B\u3093</strong>\u3002\u53D6\u5F15\u30B8\u30E3\u30FC\u30CA\u30EB\u30FB\u30EC\u30B8\u5206\u6790\u30FB\u30EC\u30B8\u6B8B\u9AD8\u306B\u306E\u307F\u53CD\u6620\u3055\u308C\u307E\u3059\u3002'
        + '</div>'
        + '<div style="margin-top:10px;padding:10px;background:#f5f5f5;border-radius:4px;">'
        + '<div style="font-size:0.8rem;margin-bottom:6px;color:#555;">\u30E1\u30E2 (\u4EFB\u610F / 200\u6587\u5B57\u4EE5\u5185)</div>'
        + '<textarea id="epl-manual-note" maxlength="200" rows="2" style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" placeholder="\u4F8B: \u5F53\u65E5\u5206\u306E\u5916\u90E8\u7AEF\u672B\u7DE0\u3081\u3067\u7167\u5408\u6E08\u307F"></textarea>'
        + '<div style="margin-top:10px;">'
        + '<button type="button" id="epl-manual-transfer-btn" style="padding:10px 20px;background:#6a1b9a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;font-weight:600;">\u58F2\u4E0A\u3068\u3057\u3066\u8A08\u4E0A\u3059\u308B\uFF08\u624B\u5165\u529B\uFF09</button>'
        + '</div>'
        + '<div id="epl-manual-transfer-msg" style="margin-top:8px;font-size:0.8rem;min-height:1.2em;"></div>'
        + '</div>';
      return html;
    }
    // Phase 4d-3: other_external の分類済みは転記可能、未分類は分類フォーム表示
    if (r.paymentMethod === 'other_external') {
      var extType = r.externalMethodType || null;
      var allowedExt = ['voucher', 'bank_transfer', 'accounts_receivable', 'point', 'other'];
      var classified = extType && allowedExt.indexOf(extType) !== -1;
      if (!classified) {
        html += ''
          + '<div style="margin-top:8px;padding:10px;background:#fff3cd;border:1px solid #ffd757;border-radius:4px;color:#795500;font-size:0.8rem;line-height:1.6;">'
          + '\u5916\u90E8\u5206\u985E\u304C\u672A\u8A2D\u5B9A\u3067\u3059\u3002\u5206\u985E\u3092\u8A18\u9332\u3057\u3066\u304B\u3089\u300C\u58F2\u4E0A\u3078\u8EE2\u8A18\u3059\u308B\u300D\u304C\u53EF\u80FD\u306B\u306A\u308A\u307E\u3059\u3002'
          + '</div>'
          + '<div style="margin-top:10px;padding:10px;background:#f5f5f5;border-radius:4px;">'
          + '<label style="font-size:0.8rem;color:#555;display:block;margin-bottom:6px;">\u5916\u90E8\u5206\u985E</label>'
          + '<select id="epl-ext-method-sel" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-size:0.9rem;margin-bottom:10px;">'
          + '<option value="">\u9078\u629E\u3057\u3066\u304F\u3060\u3055\u3044</option>'
          + '<option value="voucher">\u5546\u54C1\u5238</option>'
          + '<option value="bank_transfer">\u4E8B\u524D\u632F\u8FBC</option>'
          + '<option value="accounts_receivable">\u58F2\u639B</option>'
          + '<option value="point">\u30DD\u30A4\u30F3\u30C8\u5145\u5F53</option>'
          + '<option value="other">\u305D\u306E\u4ED6</option>'
          + '</select>'
          + '<button type="button" id="epl-ext-method-save" style="padding:8px 16px;background:#2e7d32;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;">\u5206\u985E\u3092\u8A18\u9332</button>'
          + '<div id="epl-ext-method-msg" style="margin-top:8px;font-size:0.8rem;min-height:1.2em;"></div>'
          + '</div>';
        return html;
      }
      // 分類済み → 通常の転記ブロックへ合流 (下に続く)
    }

    // 転記可能 → ボタン表示
    html += '<div style="margin-top:8px;padding:10px;background:#fff3cd;border:1px solid #ffd757;border-radius:4px;color:#795500;font-size:0.8rem;line-height:1.6;">'
      + '\u26A0 \u3053\u306E\u64CD\u4F5C\u3067\u901A\u5E38\u58F2\u4E0A payments \u306B\u8EE2\u8A18\u3057\u307E\u3059\u3002\u8EE2\u8A18\u5F8C\u306F\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u30FB\u30EC\u30B8\u7DE0\u3081\u5BFE\u8C61\u306B\u306A\u308A\u307E\u3059\u3002\u5916\u90E8\u7AEF\u672B\u63A7\u3048\u3068\u91D1\u984D\u3092\u5FC5\u305A\u78BA\u8A8D\u3057\u3066\u304F\u3060\u3055\u3044\u3002'
      + '</div>'
      + '<div style="margin-top:10px;padding:10px;background:#f5f5f5;border-radius:4px;">'
      + '<div style="font-size:0.8rem;margin-bottom:6px;color:#555;">\u30E1\u30E2 (\u4EFB\u610F / 200\u6587\u5B57\u4EE5\u5185)</div>'
      + '<textarea id="epl-transfer-note" maxlength="200" rows="2" style="width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" placeholder="\u4F8B: \u5916\u90E8\u30AB\u30FC\u30C9\u7AEF\u672B\u65E5\u6B21\u7DE0\u3081\u3067\u7167\u5408\u6E08\u307F"></textarea>'
      + '<div style="margin-top:10px;">'
      + '<button type="button" id="epl-transfer-btn" style="padding:10px 20px;background:#1565c0;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;font-weight:600;">\u58F2\u4E0A\u3078\u8EE2\u8A18\u3059\u308B</button>'
      + '</div>'
      + '<div id="epl-transfer-msg" style="margin-top:8px;font-size:0.8rem;min-height:1.2em;"></div>'
      + '</div>';
    return html;
  }

  function _bindTransferButton(panel, record, overlay) {
    var btn = panel.querySelector('#epl-transfer-btn');
    if (btn) {
      btn.addEventListener('click', function () {
        _submitTransfer(record, panel, overlay);
      });
    }
    // Phase 4d-3: 分類編集フォームのバインド (other_external の未分類行のみ)
    var extBtn = panel.querySelector('#epl-ext-method-save');
    if (extBtn) {
      extBtn.addEventListener('click', function () {
        _submitExternalMethod(record, panel, overlay);
      });
    }
    // Phase 4d-4a: 手入力計上ボタンのバインド
    var manualBtn = panel.querySelector('#epl-manual-transfer-btn');
    if (manualBtn) {
      manualBtn.addEventListener('click', function () {
        _submitManualTransfer(record, panel, overlay);
      });
    }
    // Phase 4d-5a: 手入力計上 void ボタンのバインド
    var voidBtn = panel.querySelector('#epl-void-btn');
    if (voidBtn) {
      voidBtn.addEventListener('click', function () {
        _submitManualVoid(record, panel, overlay);
      });
    }
    // Phase 4d-5b: 注文紐付き緊急会計転記 void ボタンのバインド
    var transferVoidBtn = panel.querySelector('#epl-transfer-void-btn');
    if (transferVoidBtn) {
      transferVoidBtn.addEventListener('click', function () {
        _submitTransferVoid(record, panel, overlay);
      });
    }
  }

  // Phase 4d-5a: 手入力計上の void (AdminApi.voidManualEmergencyPayment)
  function _submitManualVoid(record, panel, overlay) {
    if (_transferring) return;
    if (!record || !record.id) return;
    if (typeof AdminApi === 'undefined' || !AdminApi.voidManualEmergencyPayment) {
      _setVoidMsg(panel, 'AdminApi \u304C\u8AAD\u307F\u8FBC\u307E\u308C\u3066\u3044\u307E\u305B\u3093', '#c62828');
      return;
    }
    var reasonInput = panel.querySelector('#epl-void-reason');
    var reason = reasonInput ? reasonInput.value.replace(/^\s+|\s+$/g, '').substring(0, 255) : '';
    if (!reason) {
      _setVoidMsg(panel, '\u53D6\u6D88\u7406\u7531\u3092\u5165\u529B\u3057\u3066\u304F\u3060\u3055\u3044', '#c62828');
      return;
    }

    var confirmMsg = '\u624B\u5165\u529B\u8A08\u4E0A\u3092\u53D6\u308A\u6D88\u3057\u307E\u3059\u3002\u3088\u308D\u3057\u3044\u3067\u3059\u304B\uFF1F\n\n'
      + '\u5408\u8A08: \u00A5' + _formatYen(record.totalAmount || 0) + '\n'
      + '\u652F\u6255: ' + _methodLabel(record.paymentMethod, record.externalMethodType) + '\n'
      + 'payment_id: ' + (record.syncedPaymentId || '-') + '\n\n'
      + 'payments \u306F\u8AD6\u7406\u53D6\u6D88\u3055\u308C\u3001\u73FE\u91D1\u306F cash_log \u306B\u76F8\u6BBA\u884C\u304C\u5165\u308A\u307E\u3059\u3002\n'
      + '\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u306B\u306F\u5143\u3005\u51FA\u3066\u3044\u306A\u3044\u305F\u3081\u5F71\u97FF\u306A\u3057\u3002\n'
      + '\u3053\u306E\u64CD\u4F5C\u306F\u5F8C\u304B\u3089\u3055\u3089\u306B\u623B\u3059\u3053\u3068\u304C\u3067\u304D\u307E\u305B\u3093\u3002';
    if (!window.confirm(confirmMsg)) return;

    _transferring = true;
    var btn = panel.querySelector('#epl-void-btn');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed'; btn.textContent = '\u51E6\u7406\u4E2D...'; }
    _setVoidMsg(panel, '\u51E6\u7406\u4E2D...', '#1565c0');

    AdminApi.voidManualEmergencyPayment(record.id, reason).then(function () {
      _transferring = false;
      if (overlay) overlay.remove();
      _fetchAndRender();
    }).catch(function (err) {
      _transferring = false;
      if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; btn.textContent = '\u624B\u5165\u529B\u8A08\u4E0A\u3092\u53D6\u308A\u6D88\u3059'; }
      var msg = (err && err.message) || '\u53D6\u6D88\u306B\u5931\u6557\u3057\u307E\u3057\u305F';
      if (err && err.status === 409) {
        msg = msg + ' (\u4ED6\u306E\u7BA1\u7406\u8005\u304C\u51E6\u7406\u6E08\u307F\u304B\u3001\u53D6\u6D88\u5BFE\u8C61\u3067\u306F\u306A\u3044\u53EF\u80FD\u6027\u304C\u3042\u308A\u307E\u3059\u3002\u518D\u8AAD\u8FBC\u3057\u3066\u304F\u3060\u3055\u3044)';
      }
      _setVoidMsg(panel, msg, '#c62828');
    });
  }

  function _setVoidMsg(panel, msg, color) {
    var el = panel.querySelector('#epl-void-msg');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = color || '#666';
  }

  // Phase 4d-5b: 注文紐付き緊急会計転記の void (AdminApi.voidTransferEmergencyPayment)
  //   orders.status='paid' は維持、table_sessions / session_token は戻さない
  //   sales-report / turnover-report には残る (4d-5c 以降で統合予定)
  function _submitTransferVoid(record, panel, overlay) {
    if (_transferring) return;
    if (!record || !record.id) return;
    if (typeof AdminApi === 'undefined' || !AdminApi.voidTransferEmergencyPayment) {
      _setTransferVoidMsg(panel, 'AdminApi \u304C\u8AAD\u307F\u8FBC\u307E\u308C\u3066\u3044\u307E\u305B\u3093', '#c62828');
      return;
    }
    var reasonInput = panel.querySelector('#epl-transfer-void-reason');
    var reason = reasonInput ? reasonInput.value.replace(/^\s+|\s+$/g, '').substring(0, 255) : '';
    if (!reason) {
      _setTransferVoidMsg(panel, '\u53D6\u6D88\u7406\u7531\u3092\u5165\u529B\u3057\u3066\u304F\u3060\u3055\u3044', '#c62828');
      return;
    }

    var confirmMsg = '\u7DCA\u6025\u4F1A\u8A08\u8EE2\u8A18\u3092\u53D6\u308A\u6D88\u3057\u307E\u3059\u3002\u3088\u308D\u3057\u3044\u3067\u3059\u304B\uFF1F\n\n'
      + '\u5408\u8A08: \u00A5' + _formatYen(record.totalAmount || 0) + '\n'
      + '\u652F\u6255: ' + _methodLabel(record.paymentMethod, record.externalMethodType) + '\n'
      + 'payment_id: ' + (record.syncedPaymentId || '-') + '\n'
      + '\u30C6\u30FC\u30D6\u30EB: ' + (record.tableCode || '-') + '\n\n'
      + '\u25A0 payments \u306F\u8AD6\u7406\u53D6\u6D88\u3055\u308C\u307E\u3059\n'
      + '\u25A0 \u73FE\u91D1\u306E\u5834\u5408\u306F cash_log \u306B\u76F8\u6BBA\u884C\u304C\u5165\u308A\u307E\u3059\n'
      + '\u25A0 orders.status \u306F paid \u306E\u307E\u307E\u7DAD\u6301\u3055\u308C\u307E\u3059\n'
      + '\u25A0 \u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u30FB\u56DE\u8EE2\u7387\u30EC\u30DD\u30FC\u30C8\u306B\u306F\u5F15\u304D\u7D9A\u304D\u6B8B\u308A\u307E\u3059\n'
      + '\u25A0 \u30C6\u30FC\u30D6\u30EB\u30BB\u30C3\u30B7\u30E7\u30F3/QR \u306F\u623B\u308A\u307E\u305B\u3093\n'
      + '\u25A0 synced_payment_id \u306F\u5C65\u6B74\u3068\u3057\u3066\u6B8B\u308A\u307E\u3059\n'
      + '\u25A0 \u3053\u306E\u64CD\u4F5C\u306F\u5F8C\u304B\u3089\u623B\u305B\u307E\u305B\u3093';
    if (!window.confirm(confirmMsg)) return;

    _transferring = true;
    var btn = panel.querySelector('#epl-transfer-void-btn');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed'; btn.textContent = '\u51E6\u7406\u4E2D...'; }
    _setTransferVoidMsg(panel, '\u51E6\u7406\u4E2D...', '#1565c0');

    AdminApi.voidTransferEmergencyPayment(record.id, reason).then(function () {
      _transferring = false;
      if (overlay) overlay.remove();
      _fetchAndRender();
    }).catch(function (err) {
      _transferring = false;
      if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; btn.textContent = '\u7DCA\u6025\u4F1A\u8A08\u8EE2\u8A18\u3092\u53D6\u308A\u6D88\u3059'; }
      var msg = (err && err.message) || '\u53D6\u6D88\u306B\u5931\u6557\u3057\u307E\u3057\u305F';
      if (err && err.status === 409) {
        msg = msg + ' (\u4ED6\u306E\u7BA1\u7406\u8005\u304C\u51E6\u7406\u6E08\u307F\u304B\u3001\u53D6\u6D88\u5BFE\u8C61\u3067\u306F\u306A\u3044\u53EF\u80FD\u6027\u304C\u3042\u308A\u307E\u3059\u3002\u518D\u8AAD\u8FBC\u3057\u3066\u304F\u3060\u3055\u3044)';
      }
      _setTransferVoidMsg(panel, msg, '#c62828');
    });
  }

  function _setTransferVoidMsg(panel, msg, color) {
    var el = panel.querySelector('#epl-transfer-void-msg');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = color || '#666';
  }

  // Phase 4d-4a: 手入力売上計上 (AdminApi.manualTransferEmergencyPayment)
  function _submitManualTransfer(record, panel, overlay) {
    if (_transferring) return;
    if (!record || !record.id) return;
    if (typeof AdminApi === 'undefined' || !AdminApi.manualTransferEmergencyPayment) {
      _setManualTransferMsg(panel, 'AdminApi \u304C\u8AAD\u307F\u8FBC\u307E\u308C\u3066\u3044\u307E\u305B\u3093', '#c62828');
      return;
    }
    var noteInput = panel.querySelector('#epl-manual-note');
    var note = noteInput ? noteInput.value.replace(/^\s+|\s+$/g, '').substring(0, 200) : '';

    var confirmMsg = '\u624B\u5165\u529B\u58F2\u4E0A\u3068\u3057\u3066 payments \u306B\u8A08\u4E0A\u3057\u307E\u3059\u3002\u3088\u308D\u3057\u3044\u3067\u3059\u304B\uFF1F\n\n'
      + '\u5408\u8A08: \u00A5' + _formatYen(record.totalAmount || 0) + '\n'
      + '\u652F\u6255: ' + _methodLabel(record.paymentMethod, record.externalMethodType) + '\n'
      + '\u6CE8\u6587\u7D10\u4ED8\u3051: \u306A\u3057 (\u624B\u5165\u529B)\n\n'
      + '\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u306B\u306F\u51FA\u307E\u305B\u3093\u3002\u53D6\u5F15\u30B8\u30E3\u30FC\u30CA\u30EB\u30FB\u30EC\u30B8\u5206\u6790\u306B\u306E\u307F\u53CD\u6620\u3055\u308C\u307E\u3059\u3002\n'
      + '\u5F8C\u65E5\u306E\u53D6\u6D88\u306F 4d-5 \u4EE5\u964D\u3067\u8A2D\u8A08\u4E88\u5B9A\u3067\u3059\u3002';
    if (!window.confirm(confirmMsg)) return;

    _transferring = true;
    var btn = panel.querySelector('#epl-manual-transfer-btn');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed'; btn.textContent = '\u51E6\u7406\u4E2D...'; }
    _setManualTransferMsg(panel, '\u51E6\u7406\u4E2D...', '#1565c0');

    AdminApi.manualTransferEmergencyPayment(record.id, note).then(function () {
      _transferring = false;
      if (overlay) overlay.remove();
      _fetchAndRender();
    }).catch(function (err) {
      _transferring = false;
      if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; btn.textContent = '\u58F2\u4E0A\u3068\u3057\u3066\u8A08\u4E0A\u3059\u308B\uFF08\u624B\u5165\u529B\uFF09'; }
      var msg = (err && err.message) || '\u624B\u5165\u529B\u8A08\u4E0A\u306B\u5931\u6557\u3057\u307E\u3057\u305F';
      if (err && err.status === 409) {
        msg = msg + ' (\u4ED6\u306E\u7BA1\u7406\u8005\u304C\u7D1A\u3044\u3067\u51E6\u7406\u3057\u305F\u53EF\u80FD\u6027\u304C\u3042\u308A\u307E\u3059\u3002\u518D\u8AAD\u8FBC\u3057\u3066\u304F\u3060\u3055\u3044\u3002)';
      }
      _setManualTransferMsg(panel, msg, '#c62828');
    });
  }

  function _setManualTransferMsg(panel, msg, color) {
    var el = panel.querySelector('#epl-manual-transfer-msg');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = color || '#666';
  }

  // Phase 4d-3: 外部分類を保存 (AdminApi.updateEmergencyExternalMethod)
  function _submitExternalMethod(record, panel, overlay) {
    if (!record || !record.id) return;
    if (typeof AdminApi === 'undefined' || !AdminApi.updateEmergencyExternalMethod) {
      _setExtMethodMsg(panel, 'AdminApi \u304C\u8AAD\u307F\u8FBC\u307E\u308C\u3066\u3044\u307E\u305B\u3093', '#c62828');
      return;
    }
    var sel = panel.querySelector('#epl-ext-method-sel');
    var btn = panel.querySelector('#epl-ext-method-save');
    if (!sel) return;
    var v = String(sel.value || '');
    if (!v) {
      _setExtMethodMsg(panel, '\u5916\u90E8\u5206\u985E\u3092\u9078\u629E\u3057\u3066\u304F\u3060\u3055\u3044', '#c62828');
      return;
    }
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }
    _setExtMethodMsg(panel, '\u51E6\u7406\u4E2D...', '#1565c0');
    AdminApi.updateEmergencyExternalMethod(record.id, v).then(function () {
      if (overlay) overlay.remove();
      _fetchAndRender();
    }).catch(function (err) {
      if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
      _setExtMethodMsg(panel, (err && err.message) || '\u5206\u985E\u306E\u4FDD\u5B58\u306B\u5931\u6557\u3057\u307E\u3057\u305F', '#c62828');
    });
  }

  function _setExtMethodMsg(panel, msg, color) {
    var el = panel.querySelector('#epl-ext-method-msg');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = color || '#666';
  }

  function _submitTransfer(record, panel, overlay) {
    if (_transferring) return;
    if (!record || !record.id) return;
    if (typeof AdminApi === 'undefined' || !AdminApi.transferEmergencyPayment) {
      _setTransferMsg(panel, 'AdminApi \u304C\u8AAD\u307F\u8FBC\u307E\u308C\u3066\u3044\u307E\u305B\u3093', '#c62828');
      return;
    }

    var noteInput = panel.querySelector('#epl-transfer-note');
    var note = noteInput ? noteInput.value.replace(/^\s+|\s+$/g, '').substring(0, 200) : '';

    var confirmMsg = '\u901A\u5E38\u58F2\u4E0A payments \u306B\u8EE2\u8A18\u3057\u307E\u3059\u3002\u3088\u308D\u3057\u3044\u3067\u3059\u304B\uFF1F\n\n'
      + '\u5408\u8A08: \u00A5' + _formatYen(record.totalAmount || 0) + '\n'
      + '\u652F\u6255: ' + _methodLabel(record.paymentMethod, record.externalMethodType) + '\n'
      + '\u30C6\u30FC\u30D6\u30EB: ' + (record.tableCode || '-') + '\n\n'
      + '\u8EE2\u8A18\u5F8C\u306F\u58F2\u4E0A\u30EC\u30DD\u30FC\u30C8\u30FB\u30EC\u30B8\u7DE0\u3081\u5BFE\u8C61\u306B\u306A\u308A\u307E\u3059\u3002\n'
      + '\u5916\u90E8\u7AEF\u672B\u63A7\u3048\u3068\u91D1\u984D\u304C\u4E00\u81F4\u3059\u308B\u3053\u3068\u3092\u78BA\u8A8D\u3057\u307E\u3057\u305F\u304B\uFF1F';
    if (!window.confirm(confirmMsg)) return;

    _transferring = true;
    var btn = panel.querySelector('#epl-transfer-btn');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed'; btn.textContent = '\u51E6\u7406\u4E2D...'; }
    _setTransferMsg(panel, '\u51E6\u7406\u4E2D...', '#1565c0');

    AdminApi.transferEmergencyPayment(record.id, note).then(function (data) {
      _transferring = false;
      if (overlay) overlay.remove();
      _fetchAndRender();
    }).catch(function (err) {
      _transferring = false;
      if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; btn.textContent = '\u58F2\u4E0A\u3078\u8EE2\u8A18\u3059\u308B'; }
      var msg = (err && err.message) || '\u5931\u6557\u3057\u307E\u3057\u305F';
      if (msg.indexOf('order_already_finalized') !== -1 || msg.indexOf('order_already_in_payments') !== -1 || msg.indexOf('order_update_rowcount_mismatch') !== -1) {
        _setTransferMsg(panel,
          '\u65E2\u306B\u901A\u5E38\u4F1A\u8A08\u6E08\u307F\u307E\u305F\u306F\u5225\u7BA1\u7406\u8005\u304C\u8EE2\u8A18\u6E08\u307F\u306E\u53EF\u80FD\u6027\u304C\u3042\u308A\u307E\u3059\u3002\u53F0\u5E33\u3092\u518D\u8AAD\u307F\u8FBC\u307F\u3057\u3066\u304F\u3060\u3055\u3044\u3002' + ' (' + msg + ')',
          '#c62828');
      } else {
        _setTransferMsg(panel, msg, '#c62828');
      }
    });
  }

  function _setTransferMsg(panel, msg, color) {
    var el = panel.querySelector('#epl-transfer-msg');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = color || '#666';
  }

  function _resolutionBadge(s) {
    var bg = '#757575';
    var label = s || 'unresolved';
    if (s === 'confirmed')        { bg = '#2e7d32'; label = '\u6709\u52B9\u78BA\u8A8D\u6E08'; }
    else if (s === 'duplicate')   { bg = '#7b1fa2'; label = '\u91CD\u8907\u6271\u3044'; }
    else if (s === 'rejected')    { bg = '#c62828'; label = '\u7121\u52B9\u6271\u3044'; }
    else if (s === 'pending')     { bg = '#546e7a'; label = '\u4FDD\u7559\u4E2D'; }
    else if (!s || s === 'unresolved' || s === '') { bg = '#ff9800'; label = '\u672A\u89E3\u6C7A'; }
    return '<span style="display:inline-block;white-space:nowrap;padding:2px 8px;border-radius:10px;background:' + bg + ';color:#fff;font-size:0.75rem;" title="' + _escape(s || 'unresolved') + '">' + _escape(label) + '</span>';
  }

  // ── util ───────────────────────────────
  // status のサーバー値を日本語ラベルに変換 (title には生の slug を残す)
  function _statusBadge(s) {
    var bg = '#757575';
    var label = s || '-';
    if (s === 'synced')              { bg = '#4caf50'; label = '\u540C\u671F\u6E08\u307F'; }
    else if (s === 'conflict')       { bg = '#f44336'; label = '\u8981\u78BA\u8A8D (\u91CD\u8907)'; }
    else if (s === 'pending_review') { bg = '#ab47bc'; label = '\u8981\u78BA\u8A8D'; }
    else if (s === 'failed')         { bg = '#d32f2f'; label = '\u540C\u671F\u5931\u6557'; }
    else if (s === 'syncing')        { bg = '#03a9f4'; label = '\u540C\u671F\u4E2D'; }
    else if (s === 'pending_sync')   { bg = '#ff9800'; label = '\u540C\u671F\u5F85\u3061'; }
    return '<span style="display:inline-block;white-space:nowrap;padding:2px 8px;border-radius:10px;background:' + bg + ';color:#fff;font-size:0.75rem;" title="' + _escape(s || '-') + '">' + _escape(label) + '</span>';
  }

  // Phase 4d-2: 外部分類 slug → 日本語ラベル
  function _externalMethodLabel(t) {
    if (t === 'voucher')             return '\u5546\u54C1\u5238';
    if (t === 'bank_transfer')       return '\u4E8B\u524D\u632F\u8FBC';
    if (t === 'accounts_receivable') return '\u58F2\u639B';
    if (t === 'point')               return '\u30DD\u30A4\u30F3\u30C8\u5145\u5F53';
    if (t === 'other')               return '\u305D\u306E\u4ED6';
    return null;  // 未知の slug / null 両方
  }

  // Phase 4d-2: method ラベル拡張。other_external の場合のみ分類を括弧内で表示。
  //   external_method_type=null は「その他外部（未分類）」で管理者に対処を促す。
  function _methodLabel(m, extType) {
    if (m === 'cash')           return '\u73FE\u91D1';
    if (m === 'external_card')  return '\u5916\u90E8\u30AB\u30FC\u30C9';
    if (m === 'external_qr')    return '\u5916\u90E8 QR';
    if (m === 'other_external') {
      var label = _externalMethodLabel(extType);
      if (label) return '\u305D\u306E\u4ED6\u5916\u90E8\uFF08' + label + '\uFF09';
      return '\u305D\u306E\u4ED6\u5916\u90E8\uFF08\u672A\u5206\u985E\uFF09';
    }
    return m || '';
  }

  function _dateStr(d) {
    var y = d.getFullYear();
    var m = d.getMonth() + 1;
    var dd = d.getDate();
    return y + '-' + (m < 10 ? '0' + m : String(m)) + '-' + (dd < 10 ? '0' + dd : String(dd));
  }

  function _shortTs(isoLike) {
    if (!isoLike) return '-';
    var s = String(isoLike).replace('T', ' ');
    if (s.length > 16) s = s.substring(0, 16);
    return s;
  }

  // Phase 4d-1: PIN 4 状態を「色つきバッジ HTML」に変換
  //   staffPinVerified=true                           → 緑「確認済」
  //   pinEnteredClient=true  && !staffPinVerified     → オレンジ「入力不一致」
  //   pinEnteredClient=false && !staffPinVerified     → グレー「入力なし」
  //   pinEnteredClient=null  && !staffPinVerified     → グレー「未確認」(旧データ/不明)
  function _pinBadge(verified, entered) {
    if (verified === true || verified === 1) {
      return '<span style="color:#2e7d32;white-space:nowrap;" title="\u78BA\u8A8D\u6E08 (PIN \u691C\u8A3C\u6210\u529F)">\u2713 \u78BA\u8A8D\u6E08</span>';
    }
    if (entered === true || entered === 1) {
      return '<span style="color:#ef6c00;white-space:nowrap;" title="PIN \u3092\u5165\u529B\u3057\u307E\u3057\u305F\u304C\u767B\u9332\u6E08\u307F\u30B9\u30BF\u30C3\u30D5\u3068\u4E00\u81F4\u3057\u307E\u305B\u3093\u3067\u3057\u305F">\u5165\u529B\u4E0D\u4E00\u81F4</span>';
    }
    if (entered === false || entered === 0) {
      return '<span style="color:#888;white-space:nowrap;" title="PIN \u5165\u529B\u306A\u3057 (\u7A7A\u6B04\u3067\u8A18\u9332)">\u5165\u529B\u306A\u3057</span>';
    }
    // entered === null or undefined (旧データ / 不明)
    return '<span style="color:#888;white-space:nowrap;" title="\u65E7\u30C7\u30FC\u30BF / \u5165\u529B\u6709\u7121\u306E\u60C5\u5831\u306A\u3057">\u672A\u78BA\u8A8D</span>';
  }

  // Phase 4d-1: PIN ラベルをテキストで返す (詳細モーダルの括弧内用)
  function _pinLabelText(verified, entered) {
    if (verified === true || verified === 1) return '\u78BA\u8A8D\u6E08';
    if (entered === true || entered === 1)   return '\u5165\u529B\u4E0D\u4E00\u81F4';
    if (entered === false || entered === 0)  return '\u5165\u529B\u306A\u3057';
    return '\u672A\u78BA\u8A8D';
  }

  // Phase 4d-1: 担当セル。PIN 検証済なら通常表示、未検証は「自己申告: ○○」で紐付けが無いことを示す。
  function _staffCell(staffName, verified) {
    var name = staffName ? String(staffName) : '';
    if (!name) return '-';
    if (verified === true || verified === 1) {
      return _escape(name);
    }
    // PIN 未検証 = staff_user_id 紐付けなし = クライアント自己申告
    return '<span style="color:#666;" title="PIN \u672A\u691C\u8A3C\u306E\u305F\u3081 users \u30C6\u30FC\u30D6\u30EB\u3068\u306F\u7D10\u4ED8\u3044\u3066\u3044\u307E\u305B\u3093">(\u81EA\u5DF1\u7533\u544A)</span> ' + _escape(name);
  }

  // Phase 4d-1: 手入力記録判定
  //   UI ヒント用。tableId/orderIds いずれも無い、または conflictReason が manual_entry_without_context を含む。
  function _isManualEntryRecord(r) {
    if (!r) return false;
    var hasTable = !!r.tableId;
    var hasOrders = r.orderIds && r.orderIds.length > 0;
    if (!hasTable && !hasOrders) return true;
    if (r.conflictReason && String(r.conflictReason).indexOf('manual_entry_without_context') !== -1) return true;
    return false;
  }

  // conflict_reason (サーバー側 slug) を店舗スタッフが読める日本語に変換する辞書
  //   - "slug (日本語補足)" 形式 → 日本語補足を残して slug を消す
  //   - "slug: 詳細"        形式 → "日本語ラベル: 詳細"
  //   - "slug"              形式 → "日本語ラベル"
  // 複数 slug が並んでいても全て順に置換する。
  function _humanizeConflictReason(raw) {
    if (!raw) return '';
    var s = String(raw);
    var dict = {
      'manual_entry_without_context': '\u30C6\u30FC\u30D6\u30EB\u30FB\u6CE8\u6587\u672A\u6307\u5B9A\u306E\u624B\u5165\u529B\u8A18\u9332',
      'pin_entered_but_not_verified': 'PIN \u3092\u5165\u529B\u3057\u307E\u3057\u305F\u304C\u3001\u767B\u9332\u6E08\u307F\u30B9\u30BF\u30C3\u30D5\u3068\u4E00\u81F4\u3057\u307E\u305B\u3093\u3067\u3057\u305F',
      'amount_mismatch': '\u91D1\u984D\u306E\u5185\u8A33\u304C\u5408\u308F\u306A\u3044 (\u7A0E\u629C+\u7A0E \u2260 \u5408\u8A08)',
      'order_not_found_or_wrong_store': '\u5BFE\u5FDC\u3059\u308B\u6CE8\u6587\u304C\u898B\u3064\u304B\u3089\u306A\u3044 / \u5225\u5E97\u8217\u306E\u6CE8\u6587',
      'table_not_found_or_wrong_store': '\u30C6\u30FC\u30D6\u30EB\u304C\u898B\u3064\u304B\u3089\u306A\u3044 / \u5225\u5E97\u8217\u306E\u30C6\u30FC\u30D6\u30EB',
      'order_already_paid': '\u6CE8\u6587\u306F\u65E2\u306B\u901A\u5E38\u4F1A\u8A08\u3067\u51E6\u7406\u6E08\u307F',
      'emergency_duplicate_order': '\u540C\u3058\u6CE8\u6587\u3092\u5225\u306E\u7DCA\u6025\u4F1A\u8A08\u8A18\u9332\u304C\u6301\u3063\u3066\u3044\u308B'
    };
    // 安全のため正規表現用エスケープ (slug は英数_のみだが念のため)
    function escRe(str) { return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    for (var key in dict) {
      if (!Object.prototype.hasOwnProperty.call(dict, key)) continue;
      var k = escRe(key);
      // 1) "slug (任意の文字列)" → 日本語だけ
      s = s.replace(new RegExp(k + '\\s*\\([^)]*\\)', 'g'), dict[key]);
      // 2) "slug:" → "日本語:"
      s = s.replace(new RegExp(k + '(?=:)', 'g'), dict[key]);
      // 3) 単独 "slug"
      s = s.replace(new RegExp(k, 'g'), dict[key]);
    }
    return s;
  }

  function _escape(s) {
    if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(s);
    s = String(s == null ? '' : s);
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function _formatYen(n) {
    if (typeof Utils !== 'undefined' && Utils.formatYen) {
      var v = Utils.formatYen(n);
      if (typeof v === 'string') {
        // 先頭の ¥ を取る (本モジュールでは自前で ¥ を付けているため二重防止)
        var c0 = v.charAt(0);
        if (c0 === '\u00A5' || c0 === '\uFFE5') return v.substring(1);
        return v;
      }
      return v;
    }
    n = parseInt(n, 10);
    if (isNaN(n)) n = 0;
    var abs = Math.abs(n);
    var str = String(abs);
    var out = '';
    for (var i = 0; i < str.length; i++) {
      if (i > 0 && (str.length - i) % 3 === 0) out += ',';
      out += str.charAt(i);
    }
    return (n < 0 ? '-' : '') + out;
  }

  return {
    init: init,
    load: load
  };
})();
