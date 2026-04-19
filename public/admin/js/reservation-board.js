/* L-9 予約管理 — 店舗側ガント台帳 IIFE (ES5)
   公開: window.ReservationBoard = { init(containerId, storeId) }
   - 1日のガント (時間×テーブル)
   - 予約クリック → 詳細モーダル
   - 着席 / no-show / キャンセル / メール再送 ボタン */

(function () {
  'use strict';

  var API = '/api/store';
  var _container = null;
  var _storeId = null;
  var _state = {
    date: null,
    data: null,         // { reservations, tables, settings, summary, range }
    activeTab: 'gantt', // 'gantt' | 'customers' | 'courses' | 'settings' | 'stats'
  };
  var _PIXELS_PER_MIN = 2; // 1分=2px (調整可)

  function ymd(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }
  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  // S3-#17: ES5 互換 (padStart は古い WebView 非対応)
  function pad2(n) { return ('0' + n).slice(-2); }
  function fmtTime(iso) {
    var d = new Date(iso);
    return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
  }

  function apiGet(path, cb) {
    var x = new XMLHttpRequest(); x.open('GET', API + path, true);
    x.onload = function () {
      try { var j = JSON.parse(x.responseText); cb(j.ok ? null : (j.error || { message: 'error' }), j.data); }
      catch (e) { cb({ message: 'invalid_response' }); }
    };
    x.onerror = function () { cb({ message: 'network_error' }); };
    x.send();
  }
  function apiSend(method, path, body, cb) {
    var x = new XMLHttpRequest(); x.open(method, API + path, true);
    x.setRequestHeader('Content-Type', 'application/json');
    x.onload = function () {
      try { var j = JSON.parse(x.responseText); cb(j.ok ? null : (j.error || { message: 'error' }), j.data); }
      catch (e) { cb({ message: 'invalid_response' }); }
    };
    x.onerror = function () { cb({ message: 'network_error' }); };
    x.send(body ? JSON.stringify(body) : null);
  }

  function init(containerId, storeId) {
    _container = document.getElementById(containerId);
    _storeId = storeId;
    if (!_container) return;
    var today = new Date();
    _state.date = ymd(today);
    renderShell();
    loadGantt();
  }

  function renderShell() {
    var html = '';
    html += '<div class="rb-tab-nav">';
    html += '<button data-tab="gantt" class="rb-tab--active">予約台帳</button>';
    html += '<button data-tab="customers">顧客台帳</button>';
    html += '<button data-tab="courses">コース管理</button>';
    html += '<button data-tab="settings">予約設定</button>';
    html += '<button data-tab="stats">予約レポート</button>';
    html += '</div>';
    html += '<div id="rb-tab-body"></div>';
    html += '<div id="rb-modal-host"></div>';
    _container.innerHTML = html;
    var btns = _container.querySelectorAll('.rb-tab-nav button');
    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener('click', function (e) { switchTab(e.currentTarget.getAttribute('data-tab')); });
    }
  }

  function switchTab(tab) {
    _state.activeTab = tab;
    var btns = _container.querySelectorAll('.rb-tab-nav button');
    for (var i = 0; i < btns.length; i++) {
      btns[i].className = btns[i].getAttribute('data-tab') === tab ? 'rb-tab--active' : '';
    }
    if (tab === 'gantt') loadGantt();
    else if (tab === 'customers') loadCustomers();
    else if (tab === 'courses') loadCourses();
    else if (tab === 'settings') loadSettings();
    else if (tab === 'stats') loadStats();
  }

  // ---------- Gantt ----------
  function loadGantt() {
    var body = document.getElementById('rb-tab-body');
    body.innerHTML = renderGanttToolbar() + '<div id="rb-gantt-area"><div class="rb-empty">読み込み中…</div></div>';
    bindGanttToolbar();
    apiGet('/reservations.php?store_id=' + encodeURIComponent(_storeId) + '&date=' + _state.date, function (err, data) {
      if (err) {
        document.getElementById('rb-gantt-area').innerHTML = '<div class="rb-empty">' + escapeHtml(err.message || '取得失敗') + '</div>';
        return;
      }
      _state.data = data;
      drawGantt();
    });
  }

  function renderGanttToolbar() {
    var html = '<div class="rb-toolbar">';
    html += '<div class="rb-toolbar__date">';
    html += '<button id="rb-prev-day">‹</button>';
    html += '<input type="date" id="rb-date" value="' + _state.date + '">';
    html += '<button id="rb-next-day">›</button>';
    html += '<button id="rb-today">今日</button>';
    html += '</div>';
    html += '<button class="rb-btn--primary" id="rb-add-walkin">+ Walk-in</button>';
    html += '<button class="rb-btn--primary" id="rb-add-rsv">+ 新規予約</button>';
    html += '<div class="rb-summary" id="rb-summary"></div>';
    html += '</div>';
    return html;
  }

  function bindGanttToolbar() {
    document.getElementById('rb-prev-day').addEventListener('click', function () { var d = new Date(_state.date); d.setDate(d.getDate() - 1); _state.date = ymd(d); loadGantt(); });
    document.getElementById('rb-next-day').addEventListener('click', function () { var d = new Date(_state.date); d.setDate(d.getDate() + 1); _state.date = ymd(d); loadGantt(); });
    document.getElementById('rb-today').addEventListener('click', function () { _state.date = ymd(new Date()); loadGantt(); });
    document.getElementById('rb-date').addEventListener('change', function (e) { _state.date = e.target.value; loadGantt(); });
    document.getElementById('rb-add-walkin').addEventListener('click', function () { openCreateModal(true); });
    document.getElementById('rb-add-rsv').addEventListener('click', function () { openCreateModal(false); });
  }

  function drawGantt() {
    var d = _state.data;
    if (!d) return;
    // Summary
    var sum = d.summary;
    var summaryHtml = '';
    summaryHtml += '<div class="rb-summary__item"><strong>' + sum.total + '</strong>件</div>';
    summaryHtml += '<div class="rb-summary__item"><strong>' + sum.guests + '</strong>名</div>';
    summaryHtml += '<div class="rb-summary__item">確定 <strong>' + sum.confirmed + '</strong></div>';
    summaryHtml += '<div class="rb-summary__item">着席 <strong>' + sum.seated + '</strong></div>';
    summaryHtml += '<div class="rb-summary__item">no-show <strong>' + sum.no_show + '</strong></div>';
    summaryHtml += '<div class="rb-summary__item">walk-in <strong>' + sum.walk_in + '</strong></div>';
    document.getElementById('rb-summary').innerHTML = summaryHtml;

    // Gantt
    var openH = parseInt(d.settings.open_time.split(':')[0], 10);
    var openM = parseInt(d.settings.open_time.split(':')[1], 10);
    var closeH = parseInt(d.settings.close_time.split(':')[0], 10);
    var closeM = parseInt(d.settings.close_time.split(':')[1], 10);
    if (closeH === 0) closeH = 24;
    var startMin = openH * 60 + openM;
    var endMin = closeH * 60 + closeM + 60; // 余裕
    var totalMin = endMin - startMin;
    var totalWidth = totalMin * _PIXELS_PER_MIN;

    if (!d.tables || !d.tables.length) {
      document.getElementById('rb-gantt-area').innerHTML = '<div class="rb-empty">テーブルが登録されていません。設定 > テーブル管理 で追加してください</div>';
      return;
    }

    var html = '<div class="rb-gantt"><div class="rb-gantt__inner" style="min-width:' + (140 + totalWidth) + 'px;">';
    // Header
    html += '<div class="rb-gantt__header"><div class="rb-gantt__corner">テーブル</div><div class="rb-gantt__hours" style="width:' + totalWidth + 'px;">';
    for (var m = startMin; m <= endMin; m += 60) {
      var h = Math.floor(m / 60);
      html += '<div class="rb-gantt__hour" style="min-width:' + (60 * _PIXELS_PER_MIN) + 'px;max-width:' + (60 * _PIXELS_PER_MIN) + 'px;">' + pad2(h % 24) + ':00</div>';
    }
    html += '</div></div>';

    // Rows
    for (var t = 0; t < d.tables.length; t++) {
      var tbl = d.tables[t];
      html += '<div class="rb-gantt__row" data-table-id="' + escapeHtml(tbl.id) + '"><div class="rb-gantt__row-label">' + escapeHtml(tbl.label) + '<small>' + tbl.capacity + '名 / ' + escapeHtml(tbl.area || '') + '</small></div>';
      html += '<div class="rb-gantt__row-area" style="width:' + totalWidth + 'px;">';
      // Hour separators
      for (var hm = startMin; hm < endMin; hm += 60) {
        var leftX = (hm - startMin) * _PIXELS_PER_MIN;
        html += '<div class="rb-gantt__cell rb-gantt__cell--hour" style="left:' + leftX + 'px;width:' + (60 * _PIXELS_PER_MIN) + 'px;"></div>';
      }
      // Reservations on this table
      for (var r = 0; r < d.reservations.length; r++) {
        var rsv = d.reservations[r];
        // キャンセル/no-show はガントから除外 (枠は解放されているため)
        if (rsv.status === 'cancelled' || rsv.status === 'no_show') continue;
        var tids = rsv.assigned_table_ids || [];
        if (tids.indexOf(tbl.id) === -1) continue;
        var rsvDate = new Date(rsv.reserved_at);
        var rsvMin = rsvDate.getHours() * 60 + rsvDate.getMinutes();
        var left = (rsvMin - startMin) * _PIXELS_PER_MIN;
        var width = rsv.duration_min * _PIXELS_PER_MIN;
        if (width < 40) width = 40;
        var cls = 'rb-rsv rb-rsv--' + rsv.status;
        if (rsv.tags && rsv.tags.indexOf('VIP') !== -1) cls += ' rb-rsv--vip';
        var label = '<div class="rb-rsv__name">' + escapeHtml(rsv.customer_name) + ' (' + rsv.party_size + ')</div>';
        var meta = fmtTime(rsv.reserved_at) + ' • ' + (rsv.source === 'walk_in' ? 'walk-in' : (rsv.source === 'web' ? 'web' : (rsv.source === 'ai_chat' ? 'AI' : '')));
        if (tids.length > 1) {
          // 連結割当: 他テーブル名を表示
          var otherLabels = [];
          for (var oi = 0; oi < tids.length; oi++) {
            if (tids[oi] === tbl.id) continue;
            for (var ot = 0; ot < d.tables.length; ot++) {
              if (d.tables[ot].id === tids[oi]) { otherLabels.push(d.tables[ot].label); break; }
            }
          }
          meta += ' • 🔗 ' + otherLabels.join('+') + ' と連結';
        }
        label += '<div class="rb-rsv__meta">' + escapeHtml(meta) + '</div>';
        if (rsv.memo) label += '<div class="rb-rsv__tags">📝</div>';
        html += '<div class="' + cls + '" style="left:' + left + 'px;width:' + width + 'px;" data-rsv-id="' + escapeHtml(rsv.id) + '">' + label + '</div>';
      }
      // Walk-in (no table assigned) を別行で表示しない: テーブル別なので空
      html += '</div></div>';
    }
    // Walk-in / 未割当の予約を一覧
    var unassigned = [];
    for (var k = 0; k < d.reservations.length; k++) {
      var rr = d.reservations[k];
      if (rr.status === 'cancelled' || rr.status === 'no_show') continue;
      var ti = rr.assigned_table_ids || [];
      if (!ti.length) unassigned.push(rr);
    }
    if (unassigned.length) {
      html += '<div class="rb-gantt__row" style="background:#fff3e0;"><div class="rb-gantt__row-label" style="background:#fff3e0;">未割当</div>';
      html += '<div class="rb-gantt__row-area" style="width:' + totalWidth + 'px;">';
      for (var u = 0; u < unassigned.length; u++) {
        var ur = unassigned[u];
        var udt = new Date(ur.reserved_at);
        var um = udt.getHours() * 60 + udt.getMinutes();
        var ul = (um - startMin) * _PIXELS_PER_MIN;
        var uw = ur.duration_min * _PIXELS_PER_MIN;
        var ucls = 'rb-rsv rb-rsv--' + ur.status;
        html += '<div class="' + ucls + '" style="left:' + ul + 'px;width:' + uw + 'px;" data-rsv-id="' + escapeHtml(ur.id) + '"><div class="rb-rsv__name">' + escapeHtml(ur.customer_name) + ' (' + ur.party_size + ')</div></div>';
      }
      html += '</div></div>';
    }
    html += '</div></div>';
    document.getElementById('rb-gantt-area').innerHTML = html;

    var rsvEls = document.querySelectorAll('.rb-rsv[data-rsv-id]');
    for (var i = 0; i < rsvEls.length; i++) {
      rsvEls[i].addEventListener('click', function (e) {
        openDetailModal(e.currentTarget.getAttribute('data-rsv-id'));
      });
    }
  }

  // ---------- Detail Modal ----------
  function openDetailModal(rsvId) {
    apiGet('/reservations.php?store_id=' + encodeURIComponent(_storeId) + '&id=' + encodeURIComponent(rsvId), function (err, data) {
      if (err) { alert(err.message); return; }
      var r = data.reservation;
      var statusJa = { confirmed: '確定', pending: '決済待ち', seated: '着席中', no_show: 'no-show', cancelled: 'キャンセル', completed: '完了' }[r.status] || r.status;
      var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
      html += '<button class="rb-modal__close" data-close>×</button>';
      html += '<h3>' + escapeHtml(r.customer_name) + ' / ' + r.party_size + '名</h3>';
      html += '<div class="rb-modal__row"><span>ステータス</span><span>' + escapeHtml(statusJa) + '</span></div>';
      html += '<div class="rb-modal__row"><span>日時</span><span>' + escapeHtml(r.reserved_at) + ' (' + r.duration_min + '分)</span></div>';
      html += '<div class="rb-modal__row"><span>ソース</span><span>' + escapeHtml(r.source) + '</span></div>';
      if (r.customer_phone) html += '<div class="rb-modal__row"><span>電話</span><span><a href="tel:' + escapeHtml(r.customer_phone) + '">' + escapeHtml(r.customer_phone) + '</a></span></div>';
      if (r.customer_email) html += '<div class="rb-modal__row"><span>メール</span><span>' + escapeHtml(r.customer_email) + '</span></div>';
      if (r.course_name) html += '<div class="rb-modal__row"><span>コース</span><span>' + escapeHtml(r.course_name) + '</span></div>';
      if (r.memo) html += '<div class="rb-modal__row"><span>メモ</span><span>' + escapeHtml(r.memo) + '</span></div>';
      if (r.tags) html += '<div class="rb-modal__row"><span>タグ</span><span>' + escapeHtml(r.tags) + '</span></div>';
      if (r.deposit_required) html += '<div class="rb-modal__row"><span>予約金</span><span>¥' + r.deposit_amount.toLocaleString() + ' / ' + escapeHtml(r.deposit_status) + '</span></div>';

      html += '<div class="rb-modal__actions">';
      if (r.status === 'confirmed' || r.status === 'pending') {
        html += '<button class="rb-btn-seat" data-action="seat">🪑 着席</button>';
        html += '<button class="rb-btn-noshow" data-action="noshow">❌ no-show</button>';
        html += '<button class="rb-btn-edit" data-action="edit">✏️ 変更</button>';
        html += '<button class="rb-btn-cancel" data-action="cancel">キャンセル</button>';
        if (r.customer_email) html += '<button class="rb-btn-resend" data-action="resend">📧 再送</button>';
      }
      if (r.status === 'seated' && r.table_session_id) {
        var sessionUrl = 'https://eat.posla.jp/public/customer/menu.html?store_id=' + encodeURIComponent(_storeId) + '&table_id=' + encodeURIComponent((r.assigned_table_ids||[])[0]||'');
        html += '<a class="rb-btn-edit" style="text-decoration:none;display:inline-block;" target="_blank" href="' + escapeHtml(sessionUrl) + '">📲 セッション</a>';
      }
      html += '</div>';
      html += '</div></div>';
      document.getElementById('rb-modal-host').innerHTML = html;

      var modal = document.getElementById('rb-modal');
      modal.querySelector('[data-close]').addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      var actBtns = modal.querySelectorAll('[data-action]');
      for (var i = 0; i < actBtns.length; i++) {
        actBtns[i].addEventListener('click', function (e) { handleAction(e.currentTarget.getAttribute('data-action'), r); });
      }
    });
  }

  function closeModal() { document.getElementById('rb-modal-host').innerHTML = ''; }

  function showSeatQrModal(r, data) {
    var url = data.qr_url;
    var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content" style="max-width:420px;text-align:center;">';
    html += '<button class="rb-modal__close" data-close>×</button>';
    html += '<h3>🪑 着席完了</h3>';
    html += '<p style="color:#2e7d32;font-weight:600;margin:8px 0;">' + escapeHtml(r.customer_name) + ' 様 / ' + r.party_size + '名</p>';
    html += '<p style="color:#666;font-size:0.85rem;margin-bottom:12px;">このQRコードをお客様のスマホで読み取るか、テーブルのQRコードから直接注文できます</p>';
    html += '<div id="rb-seat-qr" style="display:inline-block;padding:12px;background:#fff;border:1px solid #eee;border-radius:8px;margin:8px 0;"></div>';
    html += '<div style="word-break:break-all;font-size:0.75rem;color:#888;padding:8px;background:#f5f5f5;border-radius:4px;">' + escapeHtml(url) + '</div>';
    html += '<div class="rb-modal__actions" style="justify-content:center;">';
    html += '<button class="rb-btn-edit" id="rb-seat-copy">📋 URLコピー</button>';
    html += '<button class="rb-btn-edit" id="rb-seat-print" style="background:#ff6f00;">🖨 QR印刷</button>';
    html += '<button class="rb-btn-cancel" data-close>閉じる</button>';
    html += '</div></div></div>';
    document.getElementById('rb-modal-host').innerHTML = html;

    // QR 生成 (qrcodejs は dashboard.html で既に読込済)
    var qrEl = document.getElementById('rb-seat-qr');
    if (window.QRCode && qrEl) {
      new QRCode(qrEl, { text: url, width: 220, height: 220, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
    } else if (qrEl) {
      qrEl.textContent = 'QR生成ライブラリが読み込まれていません';
    }

    var modal = document.getElementById('rb-modal');
    var closes = modal.querySelectorAll('[data-close]');
    for (var i = 0; i < closes.length; i++) closes[i].addEventListener('click', closeModal);

    document.getElementById('rb-seat-copy').addEventListener('click', function () {
      if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function () { alert('URLをコピーしました'); });
      } else {
        var ta = document.createElement('textarea');
        ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        alert('URLをコピーしました');
      }
    });
    document.getElementById('rb-seat-print').addEventListener('click', function () {
      var canvas = qrEl.querySelector('canvas');
      if (!canvas) { alert('QR生成されていません'); return; }
      var dataUrl = canvas.toDataURL('image/png');
      var w = window.open('', '_blank');
      w.document.write(
        '<html><head><title>着席QR</title>' +
        '<style>body{font-family:sans-serif;text-align:center;padding:32px;}img{margin:24px 0;}p{font-size:0.85rem;color:#555;word-break:break-all;}</style>' +
        '</head><body>' +
        '<h2>' + escapeHtml(r.customer_name) + ' 様</h2>' +
        '<p>' + r.party_size + '名 / ' + escapeHtml(r.reserved_at) + '</p>' +
        '<img src="' + dataUrl + '" width="320" height="320">' +
        '<p>' + escapeHtml(url) + '</p>' +
        '<p>QRコードをスマホで読み取るとセルフオーダーができます</p>' +
        '</body></html>'
      );
      w.document.close();
      setTimeout(function () { w.print(); }, 200);
    });
  }

  function handleAction(action, r) {
    if (action === 'seat') {
      if (!r.assigned_table_ids || !r.assigned_table_ids.length) {
        alert('テーブル未割当です。先に変更画面でテーブルを指定してください');
        return;
      }
      apiSend('POST', '/reservation-seat.php', { reservation_id: r.id, store_id: _storeId, table_ids: r.assigned_table_ids }, function (err, data) {
        if (err) { alert(err.message); return; }
        showSeatQrModal(r, data);
        loadGantt();
      });
    } else if (action === 'noshow') {
      if (!confirm('no-show として記録しますか?')) return;
      apiSend('POST', '/reservation-no-show.php', { reservation_id: r.id, store_id: _storeId }, function (err, data) {
        if (err) { alert(err.message); return; }
        var msg = 'no-show 完了';
        if (data.captured_amount) msg += ' (予約金 ¥' + data.captured_amount.toLocaleString() + ' を回収)';
        alert(msg);
        closeModal(); loadGantt();
      });
    } else if (action === 'cancel') {
      if (!confirm('予約をキャンセルします。よろしいですか?')) return;
      apiSend('DELETE', '/reservations.php?id=' + encodeURIComponent(r.id) + '&store_id=' + encodeURIComponent(_storeId), null, function (err) {
        if (err) { alert(err.message); return; }
        closeModal(); loadGantt();
      });
    } else if (action === 'edit') {
      openEditModal(r);
    } else if (action === 'resend') {
      apiSend('POST', '/reservation-notify.php', { reservation_id: r.id, store_id: _storeId, type: 'confirm' }, function (err) {
        if (err) { alert(err.message); return; }
        alert('確認メールを再送しました');
      });
    }
  }

  function openEditModal(r) {
    var dateVal = r.reserved_at.substring(0, 10);
    var timeVal = r.reserved_at.substring(11, 16);
    var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
    html += '<button class="rb-modal__close" data-close>×</button>';
    html += '<h3>予約変更</h3>';
    html += '<div class="rb-modal__form-group"><label>名前</label><input type="text" id="rb-e-name" value="' + escapeHtml(r.customer_name) + '"></div>';
    html += '<div class="rb-modal__form-group"><label>電話</label><input type="tel" id="rb-e-phone" value="' + escapeHtml(r.customer_phone || '') + '"></div>';
    html += '<div class="rb-modal__form-group"><label>メール</label><input type="email" id="rb-e-email" value="' + escapeHtml(r.customer_email || '') + '"></div>';
    html += '<div class="rb-modal__form-group"><label>日付</label><input type="date" id="rb-e-date" value="' + dateVal + '"></div>';
    html += '<div class="rb-modal__form-group"><label>時刻</label><input type="time" id="rb-e-time" value="' + timeVal + '" step="900"></div>';
    html += '<div class="rb-modal__form-group"><label>人数</label><input type="number" id="rb-e-party" value="' + r.party_size + '" min="1" max="50"></div>';
    html += '<div class="rb-modal__form-group"><label>滞在分</label><input type="number" id="rb-e-dur" value="' + r.duration_min + '" min="15" step="15"></div>';
    html += '<div class="rb-modal__form-group"><label>テーブル割当</label><div class="rb-table-list" id="rb-e-tables"></div></div>';
    html += '<div class="rb-modal__form-group"><label>メモ</label><textarea id="rb-e-memo" rows="2">' + escapeHtml(r.memo || '') + '</textarea></div>';
    html += '<div class="rb-modal__form-group"><label>タグ (カンマ区切り: VIP, 誕生日 等)</label><input type="text" id="rb-e-tags" value="' + escapeHtml(r.tags || '') + '"></div>';
    html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close>戻る</button><button class="rb-btn-edit" id="rb-e-save">保存</button></div>';
    html += '</div></div>';
    document.getElementById('rb-modal-host').innerHTML = html;

    // テーブルリスト
    if (_state.data && _state.data.tables) {
      var tlist = document.getElementById('rb-e-tables');
      var th = '';
      for (var i = 0; i < _state.data.tables.length; i++) {
        var t = _state.data.tables[i];
        var checked = (r.assigned_table_ids || []).indexOf(t.id) !== -1 ? 'checked' : '';
        th += '<label><input type="checkbox" value="' + escapeHtml(t.id) + '" ' + checked + '> ' + escapeHtml(t.label) + ' (' + t.capacity + ')</label>';
      }
      tlist.innerHTML = th;
    }

    var modal = document.getElementById('rb-modal');
    var closeBtns = modal.querySelectorAll('[data-close]');
    for (var c = 0; c < closeBtns.length; c++) closeBtns[c].addEventListener('click', closeModal);

    document.getElementById('rb-e-save').addEventListener('click', function () {
      var date = document.getElementById('rb-e-date').value;
      var time = document.getElementById('rb-e-time').value;
      var party = parseInt(document.getElementById('rb-e-party').value, 10);
      var dur = parseInt(document.getElementById('rb-e-dur').value, 10);
      var memo = document.getElementById('rb-e-memo').value;
      var tags = document.getElementById('rb-e-tags').value;
      var name = document.getElementById('rb-e-name').value;
      var phone = document.getElementById('rb-e-phone').value;
      var email = document.getElementById('rb-e-email').value;
      var checked = document.querySelectorAll('#rb-e-tables input[type="checkbox"]:checked');
      var tids = [];
      for (var ci = 0; ci < checked.length; ci++) tids.push(checked[ci].value);
      var body = {
        id: r.id, store_id: _storeId,
        customer_name: name, customer_phone: phone, customer_email: email,
        reserved_at: date + 'T' + time + ':00', party_size: party, duration_min: dur,
        memo: memo, tags: tags, assigned_table_ids: tids,
      };
      this.disabled = true; this.textContent = '…';
      apiSend('PATCH', '/reservations.php', body, function (err) {
        if (err) { alert(err.message); document.getElementById('rb-e-save').disabled = false; document.getElementById('rb-e-save').textContent = '保存'; return; }
        closeModal(); loadGantt();
      });
    });
  }

  function openCreateModal(walkin) {
    var nowD = new Date();
    var dateVal = ymd(nowD);
    var timeVal = pad2(nowD.getHours()) + ':' + pad2(nowD.getMinutes());
    var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
    html += '<button class="rb-modal__close" data-close>×</button>';
    html += '<h3>' + (walkin ? '+ Walk-in 追加' : '+ 新規予約') + '</h3>';
    html += '<div class="rb-modal__form-group"><label>名前 *</label><input type="text" id="rb-c-name"></div>';
    html += '<div class="rb-modal__form-group"><label>電話</label><input type="tel" id="rb-c-phone"></div>';
    html += '<div class="rb-modal__form-group"><label>メール</label><input type="email" id="rb-c-email"></div>';
    html += '<div class="rb-modal__form-group"><label>日付 *</label><input type="date" id="rb-c-date" value="' + dateVal + '"></div>';
    html += '<div class="rb-modal__form-group"><label>時刻 *</label><input type="time" id="rb-c-time" value="' + timeVal + '" step="900"></div>';
    html += '<div class="rb-modal__form-group"><label>人数 *</label><input type="number" id="rb-c-party" value="2" min="1" max="50"></div>';
    html += '<div class="rb-modal__form-group"><label>滞在分</label><input type="number" id="rb-c-dur" value="90" min="15" step="15"></div>';
    html += '<div class="rb-modal__form-group"><label>テーブル割当</label><div class="rb-table-list" id="rb-c-tables"></div></div>';
    html += '<div class="rb-modal__form-group"><label>メモ</label><textarea id="rb-c-memo" rows="2"></textarea></div>';
    html += '<div class="rb-modal__form-group"><label>タグ</label><input type="text" id="rb-c-tags" placeholder="VIP, 誕生日 等"></div>';
    html += '<div class="rb-modal__form-group"><label><input type="checkbox" id="rb-c-skip-deposit" checked> 予約金徴収をスキップ (店内手動入力)</label></div>';
    html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close>戻る</button><button class="rb-btn-edit" id="rb-c-save">作成</button></div>';
    html += '</div></div>';
    document.getElementById('rb-modal-host').innerHTML = html;

    if (_state.data && _state.data.tables) {
      var tlist = document.getElementById('rb-c-tables');
      var th = '';
      for (var i = 0; i < _state.data.tables.length; i++) {
        var t = _state.data.tables[i];
        th += '<label><input type="checkbox" value="' + escapeHtml(t.id) + '"> ' + escapeHtml(t.label) + ' (' + t.capacity + ')</label>';
      }
      tlist.innerHTML = th;
    }

    var modal = document.getElementById('rb-modal');
    var closeBtns = modal.querySelectorAll('[data-close]');
    for (var c = 0; c < closeBtns.length; c++) closeBtns[c].addEventListener('click', closeModal);

    document.getElementById('rb-c-save').addEventListener('click', function () {
      var name = document.getElementById('rb-c-name').value.trim();
      if (!name) { alert('名前を入力してください'); return; }
      var date = document.getElementById('rb-c-date').value;
      var time = document.getElementById('rb-c-time').value;
      var party = parseInt(document.getElementById('rb-c-party').value, 10);
      var dur = parseInt(document.getElementById('rb-c-dur').value, 10);
      var memo = document.getElementById('rb-c-memo').value;
      var tags = document.getElementById('rb-c-tags').value;
      var skipDep = document.getElementById('rb-c-skip-deposit').checked;
      var checked = document.querySelectorAll('#rb-c-tables input[type="checkbox"]:checked');
      var tids = [];
      for (var ci = 0; ci < checked.length; ci++) tids.push(checked[ci].value);
      var body = {
        store_id: _storeId,
        customer_name: name,
        customer_phone: document.getElementById('rb-c-phone').value,
        customer_email: document.getElementById('rb-c-email').value,
        reserved_at: date + 'T' + time + ':00',
        party_size: party,
        duration_min: dur,
        memo: memo, tags: tags,
        assigned_table_ids: tids,
        source: walkin ? 'walk_in' : 'phone',
        status: walkin ? 'seated' : 'confirmed',
        skip_deposit: skipDep,
      };
      this.disabled = true; this.textContent = '…';
      apiSend('POST', '/reservations.php', body, function (err, data) {
        if (err) { alert(err.message); document.getElementById('rb-c-save').disabled = false; document.getElementById('rb-c-save').textContent = '作成'; return; }
        // walk-in & テーブルがあれば自動着席
        if (walkin && tids.length) {
          apiSend('POST', '/reservation-seat.php', { reservation_id: data.reservation.id, store_id: _storeId, table_ids: tids }, function () {
            closeModal(); loadGantt();
          });
        } else {
          closeModal(); loadGantt();
        }
      });
    });
  }

  // ---------- Customers ----------
  function loadCustomers() {
    var body = document.getElementById('rb-tab-body');
    body.innerHTML = '<div class="rb-customers"><div class="rb-customers__filters">';
    body.children[0].innerHTML += '<input type="text" id="rb-cust-q" placeholder="名前/電話/メールで検索">';
    body.children[0].innerHTML += '<label><input type="checkbox" id="rb-cust-vip"> VIPのみ</label>';
    body.children[0].innerHTML += '<label><input type="checkbox" id="rb-cust-bl"> ブラックリストのみ</label>';
    body.children[0].innerHTML += '<button id="rb-cust-search" class="rb-btn--primary" style="background:#1976d2;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;">検索</button>';
    body.children[0].innerHTML += '</div><div class="rb-customers__list" id="rb-cust-list">読み込み中…</div></div>';
    document.getElementById('rb-cust-search').addEventListener('click', fetchCustomers);
    fetchCustomers();
  }
  function fetchCustomers() {
    var q = document.getElementById('rb-cust-q').value;
    var vip = document.getElementById('rb-cust-vip').checked ? '1' : '';
    var bl = document.getElementById('rb-cust-bl').checked ? '1' : '';
    var url = '/reservation-customers.php?store_id=' + encodeURIComponent(_storeId) + '&q=' + encodeURIComponent(q);
    if (vip) url += '&vip_only=1';
    if (bl) url += '&blacklist_only=1';
    apiGet(url, function (err, data) {
      var list = document.getElementById('rb-cust-list');
      if (err) { list.innerHTML = '<div class="rb-empty">' + escapeHtml(err.message) + '</div>'; return; }
      if (!data.customers.length) { list.innerHTML = '<div class="rb-empty">該当顧客はいません</div>'; return; }
      var html = '';
      for (var i = 0; i < data.customers.length; i++) {
        var c = data.customers[i];
        html += '<div class="rb-customer-card" data-cust-id="' + escapeHtml(c.id) + '">';
        html += '<div class="rb-customer-card__name">' + escapeHtml(c.customer_name) + '</div>';
        html += '<div class="rb-customer-card__meta">来店 ' + c.visit_count + '回 / no-show ' + c.no_show_count + ' / cancel ' + c.cancel_count + '</div>';
        if (c.customer_phone) html += '<div class="rb-customer-card__meta">📞 ' + escapeHtml(c.customer_phone) + '</div>';
        if (c.customer_email) html += '<div class="rb-customer-card__meta">📧 ' + escapeHtml(c.customer_email) + '</div>';
        html += '<div class="rb-customer-card__tags">';
        if (parseInt(c.is_vip, 10)) html += '<span class="rb-tag rb-tag--vip">VIP</span>';
        if (parseInt(c.is_blacklisted, 10)) html += '<span class="rb-tag rb-tag--blacklist">BL</span>';
        if (c.tags) html += '<span class="rb-tag">' + escapeHtml(c.tags) + '</span>';
        html += '</div></div>';
      }
      list.innerHTML = html;
      var cards = list.querySelectorAll('[data-cust-id]');
      for (var j = 0; j < cards.length; j++) {
        cards[j].addEventListener('click', function (e) { openCustomerModal(e.currentTarget.getAttribute('data-cust-id')); });
      }
    });
  }
  function openCustomerModal(cid) {
    apiGet('/reservation-customers.php?store_id=' + encodeURIComponent(_storeId) + '&id=' + encodeURIComponent(cid), function (err, data) {
      if (err) { alert(err.message); return; }
      var c = data.customer;
      var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
      html += '<button class="rb-modal__close" data-close>×</button>';
      html += '<h3>' + escapeHtml(c.customer_name) + '</h3>';
      html += '<div class="rb-modal__form-group"><label>来店履歴</label>';
      if (data.history.length) {
        html += '<ul style="padding-left:20px;font-size:0.85rem;">';
        for (var i = 0; i < data.history.length; i++) {
          var h = data.history[i];
          html += '<li>' + escapeHtml(h.reserved_at) + ' / ' + h.party_size + '名 / ' + escapeHtml(h.status) + ' (' + escapeHtml(h.source) + ')</li>';
        }
        html += '</ul>';
      } else { html += '<div>なし</div>'; }
      html += '</div>';
      html += '<div class="rb-modal__form-group"><label>好み</label><textarea id="rb-cu-pref" rows="2">' + escapeHtml(c.preferences || '') + '</textarea></div>';
      html += '<div class="rb-modal__form-group"><label>アレルギー</label><textarea id="rb-cu-allergy" rows="2">' + escapeHtml(c.allergies || '') + '</textarea></div>';
      html += '<div class="rb-modal__form-group"><label>タグ</label><input type="text" id="rb-cu-tags" value="' + escapeHtml(c.tags || '') + '"></div>';
      html += '<div class="rb-modal__form-group"><label>内部メモ</label><textarea id="rb-cu-memo" rows="2">' + escapeHtml(c.internal_memo || '') + '</textarea></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" id="rb-cu-vip" ' + (parseInt(c.is_vip,10)?'checked':'') + '> VIP</label></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" id="rb-cu-bl" ' + (parseInt(c.is_blacklisted,10)?'checked':'') + '> ブラックリスト (manager以上)</label><input type="text" id="rb-cu-bl-reason" placeholder="理由" value="' + escapeHtml(c.blacklist_reason || '') + '" style="margin-top:4px;"></div>';
      html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close>戻る</button><button class="rb-btn-edit" id="rb-cu-save">保存</button></div>';
      html += '</div></div>';
      document.getElementById('rb-modal-host').innerHTML = html;

      var modal = document.getElementById('rb-modal');
      var closeBtns = modal.querySelectorAll('[data-close]');
      for (var z = 0; z < closeBtns.length; z++) closeBtns[z].addEventListener('click', closeModal);
      document.getElementById('rb-cu-save').addEventListener('click', function () {
        var body = {
          id: c.id, store_id: _storeId,
          preferences: document.getElementById('rb-cu-pref').value,
          allergies: document.getElementById('rb-cu-allergy').value,
          tags: document.getElementById('rb-cu-tags').value,
          internal_memo: document.getElementById('rb-cu-memo').value,
          is_vip: document.getElementById('rb-cu-vip').checked ? 1 : 0,
          is_blacklisted: document.getElementById('rb-cu-bl').checked ? 1 : 0,
          blacklist_reason: document.getElementById('rb-cu-bl-reason').value,
        };
        apiSend('PATCH', '/reservation-customers.php', body, function (err) {
          if (err) { alert(err.message); return; }
          closeModal(); fetchCustomers();
        });
      });
    });
  }

  // ---------- Courses ----------
  function loadCourses() {
    var body = document.getElementById('rb-tab-body');
    body.innerHTML = '<div class="rb-customers"><button id="rb-add-course" class="rb-btn--primary" style="background:#1976d2;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;">+ コース追加</button><div id="rb-courses-list" style="margin-top:12px;">読み込み中…</div></div>';
    document.getElementById('rb-add-course').addEventListener('click', function () { openCourseModal(null); });
    fetchCourses();
  }
  function fetchCourses() {
    apiGet('/reservation-courses.php?store_id=' + encodeURIComponent(_storeId), function (err, data) {
      var list = document.getElementById('rb-courses-list');
      if (err) { list.innerHTML = '<div class="rb-empty">' + escapeHtml(err.message) + '</div>'; return; }
      if (!data.courses.length) { list.innerHTML = '<div class="rb-empty">コース未登録</div>'; return; }
      var html = '<div class="rb-customers__list">';
      for (var i = 0; i < data.courses.length; i++) {
        var c = data.courses[i];
        html += '<div class="rb-customer-card" data-cid="' + escapeHtml(c.id) + '">';
        html += '<div class="rb-customer-card__name">' + escapeHtml(c.name) + ' (' + c.duration_min + '分)</div>';
        html += '<div class="rb-customer-card__meta">¥' + parseInt(c.price,10).toLocaleString() + ' / ' + c.min_party_size + '〜' + c.max_party_size + '名</div>';
        if (c.description) html += '<div class="rb-customer-card__meta">' + escapeHtml(c.description) + '</div>';
        html += '<div class="rb-customer-card__meta">' + (parseInt(c.is_active,10) ? '✅ 有効' : '⛔ 無効') + '</div>';
        html += '</div>';
      }
      html += '</div>';
      list.innerHTML = html;
      var cards = list.querySelectorAll('[data-cid]');
      for (var j = 0; j < cards.length; j++) {
        cards[j].addEventListener('click', function (e) {
          var id = e.currentTarget.getAttribute('data-cid');
          for (var k = 0; k < data.courses.length; k++) if (data.courses[k].id === id) openCourseModal(data.courses[k]);
        });
      }
    });
  }
  function openCourseModal(c) {
    var isNew = !c;
    var cd = c || { name: '', name_en: '', description: '', price: 0, duration_min: 90, min_party_size: 1, max_party_size: 10, is_active: 1, sort_order: 0 };
    var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
    html += '<button class="rb-modal__close" data-close>×</button>';
    html += '<h3>' + (isNew ? '+ コース追加' : 'コース編集') + '</h3>';
    html += '<div class="rb-modal__form-group"><label>名称 *</label><input type="text" id="rb-co-name" value="' + escapeHtml(cd.name) + '"></div>';
    html += '<div class="rb-modal__form-group"><label>名称(英)</label><input type="text" id="rb-co-name-en" value="' + escapeHtml(cd.name_en || '') + '"></div>';
    html += '<div class="rb-modal__form-group"><label>説明</label><textarea id="rb-co-desc" rows="2">' + escapeHtml(cd.description || '') + '</textarea></div>';
    html += '<div class="rb-modal__form-group"><label>価格 (円)</label><input type="number" id="rb-co-price" value="' + cd.price + '" min="0"></div>';
    html += '<div class="rb-modal__form-group"><label>所要時間 (分)</label><input type="number" id="rb-co-dur" value="' + cd.duration_min + '" min="15" step="15"></div>';
    html += '<div class="rb-modal__form-group"><label>人数 最小</label><input type="number" id="rb-co-min" value="' + cd.min_party_size + '" min="1"></div>';
    html += '<div class="rb-modal__form-group"><label>人数 最大</label><input type="number" id="rb-co-max" value="' + cd.max_party_size + '" min="1"></div>';
    html += '<div class="rb-modal__form-group"><label>並び順</label><input type="number" id="rb-co-sort" value="' + cd.sort_order + '"></div>';
    html += '<div class="rb-modal__form-group"><label><input type="checkbox" id="rb-co-active" ' + (parseInt(cd.is_active,10)?'checked':'') + '> 有効</label></div>';
    html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close>戻る</button>';
    if (!isNew) html += '<button class="rb-btn-noshow" id="rb-co-del">削除</button>';
    html += '<button class="rb-btn-edit" id="rb-co-save">保存</button></div>';
    html += '</div></div>';
    document.getElementById('rb-modal-host').innerHTML = html;
    var modal = document.getElementById('rb-modal');
    var closeBtns = modal.querySelectorAll('[data-close]');
    for (var z = 0; z < closeBtns.length; z++) closeBtns[z].addEventListener('click', closeModal);
    document.getElementById('rb-co-save').addEventListener('click', function () {
      var body = {
        store_id: _storeId,
        name: document.getElementById('rb-co-name').value.trim(),
        name_en: document.getElementById('rb-co-name-en').value.trim(),
        description: document.getElementById('rb-co-desc').value,
        price: parseInt(document.getElementById('rb-co-price').value, 10),
        duration_min: parseInt(document.getElementById('rb-co-dur').value, 10),
        min_party_size: parseInt(document.getElementById('rb-co-min').value, 10),
        max_party_size: parseInt(document.getElementById('rb-co-max').value, 10),
        sort_order: parseInt(document.getElementById('rb-co-sort').value, 10),
        is_active: document.getElementById('rb-co-active').checked ? 1 : 0,
      };
      if (!body.name) { alert('名称必須'); return; }
      var method = isNew ? 'POST' : 'PATCH';
      if (!isNew) body.id = c.id;
      apiSend(method, '/reservation-courses.php', body, function (err) {
        if (err) { alert(err.message); return; }
        closeModal(); fetchCourses();
      });
    });
    if (!isNew) {
      document.getElementById('rb-co-del').addEventListener('click', function () {
        if (!confirm('削除しますか?')) return;
        apiSend('DELETE', '/reservation-courses.php?id=' + encodeURIComponent(c.id) + '&store_id=' + encodeURIComponent(_storeId), null, function (err) {
          if (err) { alert(err.message); return; }
          closeModal(); fetchCourses();
        });
      });
    }
  }

  // ---------- Settings ----------
  function loadSettings() {
    var body = document.getElementById('rb-tab-body');
    body.innerHTML = '<div class="rb-customers" id="rb-settings-area">読み込み中…</div>';
    apiGet('/reservation-settings.php?store_id=' + encodeURIComponent(_storeId), function (err, data) {
      if (err) { document.getElementById('rb-settings-area').innerHTML = '<div class="rb-empty">' + escapeHtml(err.message) + '</div>'; return; }
      var s = data.settings;
      var html = '<form id="rb-settings-form" style="max-width:680px;">';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="online_enabled" ' + (parseInt(s.online_enabled,10)?'checked':'') + '> オンライン予約を有効化</label></div>';
      html += group('lead_time_hours', '受付締切 (時間前)', s.lead_time_hours, 'number');
      html += group('max_advance_days', '受付期間 (日先)', s.max_advance_days, 'number');
      html += group('default_duration_min', 'デフォルト滞在 (分)', s.default_duration_min, 'number');
      html += group('slot_interval_min', 'スロット間隔 (分)', s.slot_interval_min, 'number');
      html += group('min_party_size', '最小人数', s.min_party_size, 'number');
      html += group('max_party_size', '最大人数', s.max_party_size, 'number');
      html += group('open_time', '営業開始', s.open_time.substring(0,5), 'time');
      html += group('close_time', '営業終了', s.close_time.substring(0,5), 'time');
      html += group('last_order_offset_min', 'ラストオーダー (分前)', s.last_order_offset_min, 'number');
      html += group('weekly_closed_days', '定休曜日 (0=日 6=土 カンマ区切り)', s.weekly_closed_days || '', 'text');
      html += group('buffer_before_min', 'テーブル準備時間 (分)', s.buffer_before_min, 'number');
      html += group('buffer_after_min', '清掃時間 (分)', s.buffer_after_min, 'number');
      html += group('cancel_deadline_hours', 'キャンセル無料 (時間前)', s.cancel_deadline_hours, 'number');
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="require_phone" ' + (parseInt(s.require_phone,10)?'checked':'') + '> 電話必須</label></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="require_email" ' + (parseInt(s.require_email,10)?'checked':'') + '> メール必須</label></div>';
      html += '<hr><h4>予約金 (Stripe必須)</h4>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="deposit_enabled" ' + (parseInt(s.deposit_enabled,10)?'checked':'') + '> 予約金を徴収する (現在: ' + (data.deposit_available ? '✅利用可' : '❌Stripe未設定') + ')</label></div>';
      html += group('deposit_per_person', '一人あたり (円)', s.deposit_per_person, 'number');
      html += group('deposit_min_party_size', '徴収開始人数', s.deposit_min_party_size, 'number');
      html += '<hr><h4>通知</h4>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="reminder_24h_enabled" ' + (parseInt(s.reminder_24h_enabled,10)?'checked':'') + '> 24時間前リマインダー</label></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="reminder_2h_enabled" ' + (parseInt(s.reminder_2h_enabled,10)?'checked':'') + '> 2時間前リマインダー</label></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="ai_chat_enabled" ' + (parseInt(s.ai_chat_enabled,10)?'checked':'') + '> AIチャット予約 (Gemini)</label></div>';
      html += group('notification_email', '店舗側通知メール', s.notification_email || '', 'email');
      html += group('notes_to_customer', '客向け注意事項', s.notes_to_customer || '', 'textarea');
      html += '<button class="rb-btn-edit" id="rb-set-save" type="button" style="padding:12px 20px;font-size:1rem;">設定を保存</button>';
      html += '</form>';
      document.getElementById('rb-settings-area').innerHTML = html;
      document.getElementById('rb-set-save').addEventListener('click', saveSettings);
    });
    function group(name, label, value, type) {
      if (type === 'textarea') return '<div class="rb-modal__form-group"><label>' + label + '</label><textarea name="' + name + '" rows="2">' + escapeHtml(value) + '</textarea></div>';
      return '<div class="rb-modal__form-group"><label>' + label + '</label><input type="' + type + '" name="' + name + '" value="' + escapeHtml(value) + '"></div>';
    }
  }
  function saveSettings() {
    var form = document.getElementById('rb-settings-form');
    var body = { store_id: _storeId };
    var inputs = form.querySelectorAll('input,textarea');
    for (var i = 0; i < inputs.length; i++) {
      var el = inputs[i];
      var name = el.getAttribute('name');
      if (!name) continue;
      if (el.type === 'checkbox') body[name] = el.checked ? 1 : 0;
      else if (el.type === 'number') body[name] = parseInt(el.value, 10);
      else body[name] = el.value;
    }
    apiSend('PATCH', '/reservation-settings.php', body, function (err) {
      if (err) { alert(err.message); return; }
      alert('設定を保存しました');
    });
  }

  // ---------- Stats ----------
  function loadStats() {
    var body = document.getElementById('rb-tab-body');
    var to = ymd(new Date());
    var fromD = new Date(); fromD.setDate(fromD.getDate() - 30);
    var from = ymd(fromD);
    body.innerHTML = '<div class="rb-customers"><div class="rb-customers__filters"><label>From <input type="date" id="rb-st-from" value="' + from + '"></label><label>To <input type="date" id="rb-st-to" value="' + to + '"></label><button id="rb-st-apply" class="rb-btn--primary" style="background:#1976d2;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;">表示</button></div><div id="rb-st-area">読み込み中…</div></div>';
    document.getElementById('rb-st-apply').addEventListener('click', fetchStats);
    fetchStats();
  }
  function fetchStats() {
    var from = document.getElementById('rb-st-from').value;
    var to = document.getElementById('rb-st-to').value;
    apiGet('/reservation-stats.php?store_id=' + encodeURIComponent(_storeId) + '&from=' + from + '&to=' + to, function (err, data) {
      var area = document.getElementById('rb-st-area');
      if (err) { area.innerHTML = '<div class="rb-empty">' + escapeHtml(err.message) + '</div>'; return; }
      var t = data.totals; var s = data.by_source;
      var html = '<div class="rb-summary" style="margin-bottom:16px;">';
      html += '<div class="rb-summary__item">予約総数 <strong>' + t.total + '</strong></div>';
      html += '<div class="rb-summary__item">来店人数 <strong>' + t.total_party_size + '</strong></div>';
      html += '<div class="rb-summary__item">no-show率 <strong>' + t.no_show_rate + '%</strong></div>';
      html += '<div class="rb-summary__item">キャンセル率 <strong>' + t.cancel_rate + '%</strong></div>';
      html += '</div>';
      html += '<h4>ソース別</h4><ul>';
      html += '<li>Web: ' + s.web + ' / 電話: ' + s.phone + ' / Walk-in: ' + s.walk_in + ' / AI: ' + s.ai_chat + '</li></ul>';
      html += '<h4>日別</h4><table style="width:100%;border-collapse:collapse;">';
      html += '<tr><th style="text-align:left;border-bottom:1px solid #ccc;">日付</th><th>件数</th><th>人数</th></tr>';
      for (var i = 0; i < data.by_day.length; i++) {
        html += '<tr><td>' + data.by_day[i].d + '</td><td style="text-align:center;">' + data.by_day[i].cnt + '</td><td style="text-align:center;">' + data.by_day[i].guests + '</td></tr>';
      }
      html += '</table>';
      html += '<h4>時間帯別</h4><table style="width:100%;border-collapse:collapse;">';
      for (var j = 0; j < data.by_hour.length; j++) {
        var bw = Math.min(100, data.by_hour[j].cnt * 10);
        html += '<tr><td style="width:60px;">' + data.by_hour[j].h + ':00</td><td><div style="background:#1976d2;color:#fff;padding:2px 8px;width:' + bw + '%;border-radius:4px;">' + data.by_hour[j].cnt + '</div></td></tr>';
      }
      html += '</table>';
      area.innerHTML = html;
    });
  }

  // expose
  window.ReservationBoard = { init: init };
})();
