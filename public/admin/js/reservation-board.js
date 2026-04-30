/* L-9 予約管理 — 店舗側ガント台帳 IIFE (ES5)
   公開: window.ReservationBoard = { init(containerId, storeId) }
   Batch-UI-2 (2026-04-22) — 業務画面としての完成度向上:
     - 3 層 sticky header (topbar / KPI / attention)
     - 密度切替 (compact / normal / loose)
     - detail モーダルを tablet/desktop で右 drawer 化 (.rb-modal--drawer)
     - 予約ブロック内部を 3 段構造に (時刻+人数 / 名前 / メタ)
     - 現在時刻マーカー (now line)
     - 未割当 / pending 超過を attention パネルに集約
   ※ API / data-* / 全ハンドラセマンティクスは完全不変 */

(function () {
  'use strict';

  var API = '/api/store';
  var _container = null;
  var _storeId = null;
  var _state = {
    date: null,
    data: null,         // { reservations, tables, settings, summary, range }
    activeTab: 'gantt', // 'gantt' | 'customers' | 'courses' | 'settings' | 'stats'
    density: 'normal',  // 'compact' | 'normal' | 'loose'
  };

  // 密度別 pixel/min (1 時間の表示幅: compact=90 / normal=120 / loose=180)
  var _DENSITY_PX = { compact: 1.5, normal: 2, loose: 3 };

  function currentPxPerMin() { return _DENSITY_PX[_state.density] || 2; }

  function ymd(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }
  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad2(n) { return ('0' + n).slice(-2); }
  function fmtTime(iso) {
    var d = new Date(iso);
    return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
  }
  function sourceLabel(src) {
    if (src === 'walk_in') return 'walk-in';
    if (src === 'web') return 'web';
    if (src === 'ai_chat') return 'AI';
    if (src === 'phone') return '電話';
    return src || '';
  }
  function statusJa(st) {
    return ({ confirmed: '確定', pending: '決済待ち', seated: '着席中', no_show: 'no-show', cancelled: 'キャンセル', completed: '完了', waitlisted: '受付待ち' })[st] || st;
  }
  function arrivalFollowupLabel(st) {
    return ({ none: '未対応', contacted: '連絡済み', arriving: '到着予定', waiting_reply: '折り返し待ち', no_show_confirmed: 'no-show確定' })[st] || '未対応';
  }
  function waitlistCallLabel(st) {
    return ({ not_called: '未呼出', called: '呼出済み', recalled: '再呼出済み', absent: '不在', seated: '着席' })[st] || '未呼出';
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
    // 密度を localStorage から復元
    try {
      var savedDensity = localStorage.getItem('posla_rb_density');
      if (savedDensity && _DENSITY_PX[savedDensity]) _state.density = savedDensity;
    } catch (e) { /* noop */ }
    var today = new Date();
    _state.date = ymd(today);
    renderShell();
    loadGantt();
  }

  function renderShell() {
    var html = '';
    html += '<div class="rb-shell">';
    html += '<div class="rb-tab-nav">';
    html += '<button data-tab="gantt" class="rb-tab--active">予約台帳</button>';
    html += '<button data-tab="customers">顧客台帳</button>';
    html += '<button data-tab="courses">コース管理</button>';
    html += '<button data-tab="settings">予約設定</button>';
    html += '<button data-tab="stats">予約レポート</button>';
    html += '</div>';
    html += '<div id="rb-tab-body"></div>';
    html += '</div>';
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
    body.innerHTML = '<div class="rb-stickyhead">' + renderTopBar() + '<div id="rb-kpi-area"></div><div id="rb-attention-area"></div></div><div id="rb-dayops-area"></div><div class="rb-gantt-wrap"><div id="rb-gantt-area"><div class="rb-loading">読み込み中…</div></div></div>';
    bindTopBar();
    apiGet('/reservations.php?store_id=' + encodeURIComponent(_storeId) + '&date=' + _state.date, function (err, data) {
      if (err) {
        document.getElementById('rb-gantt-area').innerHTML = '<div class="rb-empty">' + escapeHtml(err.message || '取得失敗') + '</div>';
        return;
      }
      _state.data = data;
      drawGantt();
    });
  }

  function renderTopBar() {
    var dens = _state.density;
    var html = '<div class="rb-topbar">';
    html += '<div class="rb-topbar__date">';
    html += '<button id="rb-prev-day" type="button" aria-label="前日">‹</button>';
    html += '<input type="date" id="rb-date" value="' + _state.date + '">';
    html += '<button id="rb-next-day" type="button" aria-label="翌日">›</button>';
    html += '<button id="rb-today" type="button">今日</button>';
    html += '</div>';
    html += '<div class="rb-topbar__spacer"></div>';
    html += '<div class="rb-density" role="group" aria-label="表示密度">';
    html += '<button class="rb-density__btn' + (dens === 'compact' ? ' rb-density__btn--active' : '') + '" data-density="compact" type="button">コンパクト</button>';
    html += '<button class="rb-density__btn' + (dens === 'normal' ? ' rb-density__btn--active' : '') + '" data-density="normal" type="button">標準</button>';
    html += '<button class="rb-density__btn' + (dens === 'loose' ? ' rb-density__btn--active' : '') + '" data-density="loose" type="button">ゆったり</button>';
    html += '</div>';
    html += '<div class="rb-topbar__actions">';
    // RSV-P1-2: 受付待ち客の追加ボタン (ガントには乗せず waitlist パネルへ)
    html += '<button class="rb-btn" id="rb-add-waitlist" type="button">+ 受付待ち</button>';
    html += '<button class="rb-btn" id="rb-add-walkin" type="button">+ Walk-in</button>';
    html += '<button class="rb-btn rb-btn--primary" id="rb-add-rsv" type="button">+ 新規予約</button>';
    html += '</div>';
    html += '</div>';
    return html;
  }

  function bindTopBar() {
    document.getElementById('rb-prev-day').addEventListener('click', function () { var d = new Date(_state.date); d.setDate(d.getDate() - 1); _state.date = ymd(d); loadGantt(); });
    document.getElementById('rb-next-day').addEventListener('click', function () { var d = new Date(_state.date); d.setDate(d.getDate() + 1); _state.date = ymd(d); loadGantt(); });
    document.getElementById('rb-today').addEventListener('click', function () { _state.date = ymd(new Date()); loadGantt(); });
    document.getElementById('rb-date').addEventListener('change', function (e) { _state.date = e.target.value; loadGantt(); });
    document.getElementById('rb-add-walkin').addEventListener('click', function () { openCreateModal('walkin'); });
    document.getElementById('rb-add-rsv').addEventListener('click', function () { openCreateModal('reservation'); });
    // RSV-P1-2: 受付待ち追加
    document.getElementById('rb-add-waitlist').addEventListener('click', function () { openCreateModal('waitlist'); });

    var dBtns = document.querySelectorAll('.rb-density__btn');
    for (var i = 0; i < dBtns.length; i++) {
      dBtns[i].addEventListener('click', function (e) {
        var d = e.currentTarget.getAttribute('data-density');
        if (!d || d === _state.density) return;
        _state.density = d;
        try { localStorage.setItem('posla_rb_density', d); } catch (ex) { /* noop */ }
        // トグル表示更新
        var all = document.querySelectorAll('.rb-density__btn');
        for (var j = 0; j < all.length; j++) {
          all[j].className = 'rb-density__btn' + (all[j].getAttribute('data-density') === d ? ' rb-density__btn--active' : '');
        }
        if (_state.data) drawGantt();
      });
    }
  }

  /* KPI + attention の計算 (既存データから導出) */
  function computeAttention(reservations) {
    // RSV-P1-1: customer_attention に VIP / blacklist / allergy / memo 持ち予約を集約
    var att = { unassigned: [], pending_overdue: [], customer_attention: [], arrival_risk: [], reminder_due: [], risk_actions: [], notification_attention: [] };
    if (!reservations) return att;
    var now = Date.now();
    for (var i = 0; i < reservations.length; i++) {
      var r = reservations[i];
      // RSV-P1-2: waitlisted は waitlist パネル側で扱うため attention から除外
      if (r.status === 'cancelled' || r.status === 'no_show' || r.status === 'completed' || r.status === 'waitlisted') continue;
      var tids = r.assigned_table_ids || [];
      if (!tids.length && (r.status === 'confirmed' || r.status === 'pending' || r.status === 'seated')) {
        att.unassigned.push(r);
      }
      if (r.status === 'pending') {
        var rsvTs = new Date(r.reserved_at).getTime();
        // 予約時刻まで 1 時間以内 or 過去なのに pending のまま
        if (rsvTs - now < 3600 * 1000) att.pending_overdue.push(r);
      }
      if (r.ops_risk && (r.ops_risk.level === 'warning' || r.ops_risk.level === 'danger')) {
        att.arrival_risk.push(r);
      }
      if (r.reminder_status && r.reminder_status.level === 'due') {
        att.reminder_due.push(r);
      }
      if (r.notification_attention) {
        att.notification_attention.push(r);
      }
      if (r.risk_actions && r.risk_actions.length) {
        for (var ra = 0; ra < r.risk_actions.length; ra++) {
          if (r.risk_actions[ra].level === 'warning' || r.risk_actions[ra].level === 'danger') {
            att.risk_actions.push(r);
            break;
          }
        }
      }
      // RSV-P1-1: 今日の注目客 (VIP / blacklist / allergy / memo のいずれか)
      if (_hasCustomerAttention(r)) {
        att.customer_attention.push(r);
      }
    }
    return att;
  }

  // RSV-P1-1: 予約に注目客フラグがあるか (VIP / blacklist / allergy / memo)
  function _hasCustomerAttention(r) {
    if (!r) return false;
    if (parseInt(r.customer_is_vip, 10) === 1) return true;
    if (parseInt(r.customer_is_blacklisted, 10) === 1) return true;
    if (r.customer_allergies && String(r.customer_allergies).trim() !== '') return true;
    if (r.customer_internal_memo && String(r.customer_internal_memo).trim() !== '') return true;
    return false;
  }

  // RSV-P1-1: 小バッジ HTML (ガント card / waitlist 用)
  function _customerBadgesHtml(r) {
    if (!r) return '';
    var html = '';
    if (parseInt(r.customer_is_blacklisted, 10) === 1) {
      html += '<span title="ブラックリスト" style="display:inline-block;padding:0 3px;border-radius:3px;background:#c62828;color:#fff;font-size:0.65rem;font-weight:700;margin-left:3px">\u26A0</span>';
    }
    if (parseInt(r.customer_is_vip, 10) === 1) {
      html += '<span title="VIP" style="display:inline-block;padding:0 3px;border-radius:3px;background:#ffb300;color:#fff;font-size:0.65rem;font-weight:700;margin-left:3px">\u2605</span>';
    }
    if (r.customer_allergies && String(r.customer_allergies).trim() !== '') {
      html += '<span title="アレルギー" style="display:inline-block;padding:0 3px;border-radius:3px;background:#ff7043;color:#fff;font-size:0.65rem;font-weight:700;margin-left:3px">ALG</span>';
    }
    if (r.customer_internal_memo && String(r.customer_internal_memo).trim() !== '') {
      html += '<span title="スタッフメモあり" style="display:inline-block;padding:0 3px;border-radius:3px;background:#1976d2;color:#fff;font-size:0.65rem;font-weight:700;margin-left:3px">MEMO</span>';
    }
    return html;
  }

  function renderKpi(sum, attention) {
    var total = sum.total || 0;
    var confirmed = sum.confirmed || 0;
    var seated = sum.seated || 0;
    var walkIn = sum.walk_in || 0;
    var noShow = sum.no_show || 0;
    var guests = sum.guests || 0;
    var confirmedRate = total > 0 ? Math.round((confirmed / total) * 100) : 0;

    var attentionCount = attention.unassigned.length + attention.pending_overdue.length + attention.arrival_risk.length + attention.reminder_due.length;
    var criticalCls = attentionCount > 0 ? ' rb-kpi__card--attention' : '';
    if (attentionCount >= 3) criticalCls += ' rb-kpi__card--critical';

    var html = '<div class="rb-kpi">';

    html += '<div class="rb-kpi__card">';
    html += '<div class="rb-kpi__label">本日の予約</div>';
    html += '<div class="rb-kpi__value">' + total + '<span class="rb-kpi__value-unit">件</span></div>';
    html += '<div class="rb-kpi__sub">来店 ' + guests + ' 名</div>';
    html += '</div>';

    html += '<div class="rb-kpi__card">';
    html += '<div class="rb-kpi__label">確定率</div>';
    html += '<div class="rb-kpi__value">' + confirmedRate + '<span class="rb-kpi__value-unit">%</span></div>';
    html += '<div class="rb-kpi__sub">確定 ' + confirmed + ' / ' + total + '</div>';
    html += '</div>';

    html += '<div class="rb-kpi__card">';
    html += '<div class="rb-kpi__label">着席中</div>';
    html += '<div class="rb-kpi__value">' + seated + '<span class="rb-kpi__value-unit">件</span></div>';
    html += '<div class="rb-kpi__sub">walk-in ' + walkIn + '</div>';
    html += '</div>';

    html += '<div class="rb-kpi__card' + criticalCls + '">';
    html += '<div class="rb-kpi__label">';
    if (attentionCount > 0) html += '<span class="rb-kpi__dot"></span>';
    html += '要注意</div>';
    html += '<div class="rb-kpi__value">' + attentionCount + '<span class="rb-kpi__value-unit">件</span></div>';
    html += '<div class="rb-kpi__sub">未割当 ' + attention.unassigned.length + ' / 遅刻 ' + attention.arrival_risk.length + ' / 通知 ' + attention.reminder_due.length + '</div>';
    html += '</div>';

    html += '<div class="rb-kpi__card">';
    html += '<div class="rb-kpi__label">no-show</div>';
    html += '<div class="rb-kpi__value">' + noShow + '<span class="rb-kpi__value-unit">件</span></div>';
    html += '<div class="rb-kpi__sub">本日分</div>';
    html += '</div>';

    // RSV-P1-1: 今日の注目客 KPI (VIP + blacklist + allergy + memo)
    var attTotal = sum.attention_total || 0;
    var vipN = sum.vip || 0;
    var blN  = sum.blacklist || 0;
    var algN = sum.allergy || 0;
    var memN = sum.memo || 0;
    var custAttCls = (blN > 0) ? ' rb-kpi__card--critical' : (attTotal > 0 ? ' rb-kpi__card--attention' : '');
    html += '<div class="rb-kpi__card' + custAttCls + '">';
    html += '<div class="rb-kpi__label">';
    if (attTotal > 0) html += '<span class="rb-kpi__dot"></span>';
    html += '今日の注目客</div>';
    html += '<div class="rb-kpi__value">' + attTotal + '<span class="rb-kpi__value-unit">組</span></div>';
    html += '<div class="rb-kpi__sub">VIP ' + vipN + ' / BL ' + blN + ' / ALG ' + algN + ' / MEMO ' + memN + '</div>';
    html += '</div>';

    // RSV-P1-2: 受付待ち客 KPI
    var waitlisted = sum.waitlisted || 0;
    var waitCalled = sum.waitlist_called || 0;
    var waitAbsent = sum.waitlist_absent || 0;
    html += '<div class="rb-kpi__card' + (waitlisted > 0 ? ' rb-kpi__card--attention' : '') + '">';
    html += '<div class="rb-kpi__label">受付待ち</div>';
    html += '<div class="rb-kpi__value">' + waitlisted + '<span class="rb-kpi__value-unit">組</span></div>';
    html += '<div class="rb-kpi__sub">' + (waitlisted > 0 ? ('呼出済 ' + waitCalled + ' / 不在 ' + waitAbsent) : '0 組') + '</div>';
    html += '</div>';

    html += '</div>';
    return html;
  }

  // RSV-P1-2: 受付待ち客パネル (ガントとは別レーン)
  function renderWaitlistPanel(reservations) {
    var items = [];
    for (var i = 0; i < reservations.length; i++) {
      if (reservations[i].status === 'waitlisted') items.push(reservations[i]);
    }
    if (!items.length) return '';
    // created_at 昇順 (古い順 = 待ち時間が長い順)
    items.sort(function (a, b) {
      var ta = a.created_at ? new Date(a.created_at).getTime() : 0;
      var tb = b.created_at ? new Date(b.created_at).getTime() : 0;
      return ta - tb;
    });
    var now = Date.now();
    var html = '<div class="rb-waitlist" role="region" aria-label="受付待ち客">';
    html += '<div class="rb-waitlist__head">🕒 受付待ち客 (' + items.length + ' 組)</div>';
    html += '<ul class="rb-waitlist__list">';
    for (var j = 0; j < items.length; j++) {
      var w = items[j];
      var createdTs = w.created_at ? new Date(w.created_at).getTime() : now;
      var waitMin = Math.max(0, Math.round((now - createdTs) / 60000));
      var waitLabel = waitMin >= 60 ? (Math.floor(waitMin / 60) + '時間' + (waitMin % 60) + '分') : (waitMin + '分');
      // RSV-P1-1: waitlist 行にも顧客要約バッジを表示
      html += '<li class="rb-waitlist__row"><button type="button" class="rb-waitlist__item" data-rsv-id="' + escapeHtml(w.id) + '">'
        + '<span class="rb-waitlist__name">' + escapeHtml(w.customer_name) + _customerBadgesHtml(w) + '</span>'
        + '<span class="rb-waitlist__party">' + w.party_size + '名</span>'
        + '<span class="rb-waitlist__src">' + escapeHtml(sourceLabel(w.source)) + '</span>'
        + '<span class="rb-waitlist__wait">待ち ' + waitLabel + '</span>'
        + _waitlistCallInlineHtml(w)
        + '</button>'
        + _waitlistQuickActionsHtml(w)
        + '</li>';
    }
    html += '</ul></div>';
    return html;
  }

  function renderCancelWaitlistPanel(candidates) {
    if (!candidates || !candidates.length) return '';
    var html = '<div class="rb-cancelwait" role="region" aria-label="キャンセル待ち">';
    html += '<div class="rb-cancelwait__head">キャンセル待ち (' + candidates.length + ' 件)</div>';
    html += '<ul class="rb-cancelwait__list">';
    for (var i = 0; i < candidates.length; i++) {
      var c = candidates[i];
      var st = c.status || 'waiting';
      var meta = fmtTime(c.desired_at) + ' / ' + c.party_size + '名';
      if (c.notification_count) meta += ' / 通知' + c.notification_count + '回';
      if (c.hold_expires_at) meta += ' / 確保 ' + String(c.hold_expires_at).substring(11, 16) + 'まで';
      html += '<li class="rb-cancelwait__item rb-cancelwait__item--' + escapeHtml(st) + '">';
      html += '<span class="rb-cancelwait__name">' + escapeHtml(c.customer_name) + '</span>';
      html += '<span class="rb-cancelwait__meta">' + escapeHtml(meta) + '</span>';
      html += '<span class="rb-cancelwait__status">' + escapeHtml(cancelWaitStatusLabel(st)) + '</span>';
      if (c.last_notification_error) html += '<span class="rb-cancelwait__error">' + escapeHtml(c.last_notification_error) + '</span>';
      html += '</li>';
    }
    html += '</ul></div>';
    return html;
  }

  function cancelWaitStatusLabel(st) {
    return ({ waiting: '待機中', notified: '通知済み', booked: '予約化済み', cancelled: '取消', expired: '期限切れ' })[st] || st;
  }

  function _riskBadgeHtml(r) {
    if (!r || !r.ops_risk || !r.ops_risk.level || r.ops_risk.level === 'normal') return '';
    var level = r.ops_risk.level;
    var label = r.ops_risk.label || '注意';
    return '<span class="rb-risk rb-risk--' + escapeHtml(level) + '">' + escapeHtml(label) + '</span>';
  }

  function _reminderBadgeHtml(r) {
    if (!r || !r.reminder_status) return '';
    var st = r.reminder_status;
    return '<span class="rb-reminder rb-reminder--' + escapeHtml(st.level || 'normal') + '">' + escapeHtml(st.label || '') + '</span>';
  }

  function _arrivalBadgeHtml(r) {
    var st = r && r.arrival_followup_status ? r.arrival_followup_status : 'none';
    if (st === 'none') return '';
    return '<span class="rb-arrival rb-arrival--' + escapeHtml(st) + '">' + escapeHtml(arrivalFollowupLabel(st)) + '</span>';
  }

  function _waitlistCallBadgeHtml(r) {
    if (!r || r.status !== 'waitlisted') return '';
    var st = r.waitlist_call_status || 'not_called';
    return '<span class="rb-waitcall rb-waitcall--' + escapeHtml(st) + '">' + escapeHtml(waitlistCallLabel(st)) + '</span>';
  }

  function _waitlistCallInlineHtml(r) {
    if (!r || r.status !== 'waitlisted') return '';
    var st = r.waitlist_call_status || 'not_called';
    var bits = [waitlistCallLabel(st)];
    if (r.waitlist_call_count) bits.push(r.waitlist_call_count + '回');
    if (r.waitlist_called_at) bits.push(String(r.waitlist_called_at).substring(11, 16));
    return '<span class="rb-waitlist__call rb-waitlist__call--' + escapeHtml(st) + '">' + escapeHtml(bits.join(' / ')) + '</span>';
  }

  function _waitlistQuickActionsHtml(r) {
    if (!r || r.status !== 'waitlisted') return '';
    var st = r.waitlist_call_status || 'not_called';
    var html = '<span class="rb-waitlist__actions">';
    if (st === 'called' || st === 'recalled') {
      html += '<button type="button" data-wait-call="recalled" data-wait-rsv-id="' + escapeHtml(r.id) + '">再呼出</button>';
      html += '<button type="button" data-wait-call="absent" data-wait-rsv-id="' + escapeHtml(r.id) + '">不在</button>';
    } else if (st === 'absent') {
      html += '<button type="button" data-wait-call="recalled" data-wait-rsv-id="' + escapeHtml(r.id) + '">再呼出</button>';
    } else {
      html += '<button type="button" data-wait-call="called" data-wait-rsv-id="' + escapeHtml(r.id) + '">呼出</button>';
    }
    html += '</span>';
    return html;
  }

  function _lastVisitLabel(r) {
    if (!r || !r.customer_last_visit_at) return '';
    return String(r.customer_last_visit_at).substring(0, 10);
  }

  function _customerContextLineHtml(r) {
    var bits = [];
    if (r.customer_visit_count !== null && r.customer_visit_count !== undefined) bits.push('来店' + r.customer_visit_count + '回');
    if (_lastVisitLabel(r)) bits.push('前回' + _lastVisitLabel(r));
    if (r.customer_preferences) bits.push('好みあり');
    if (r.customer_allergies) bits.push('アレルギー');
    if (r.customer_internal_memo) bits.push('注意点');
    if (!bits.length) return '';
    return '<div class="rb-dayops-card__context">' + escapeHtml(bits.join(' / ')) + '</div>';
  }

  function _dayopsSort(a, b) {
    var ta = a.reserved_at ? new Date(String(a.reserved_at).replace(' ', 'T')).getTime() : 0;
    var tb = b.reserved_at ? new Date(String(b.reserved_at).replace(' ', 'T')).getTime() : 0;
    if (ta !== tb) return ta - tb;
    return String(a.created_at || '').localeCompare(String(b.created_at || ''));
  }

  function renderDayOpsBoard(reservations) {
    var lanes = [
      { key: 'booked', label: '予約', items: [] },
      { key: 'walkin', label: '飛び込み', items: [] },
      { key: 'wait', label: '待ち', items: [] },
      { key: 'seated', label: '着席中', items: [] }
    ];
    var byKey = { booked: lanes[0], walkin: lanes[1], wait: lanes[2], seated: lanes[3] };
    for (var i = 0; i < reservations.length; i++) {
      var r = reservations[i];
      if (r.status === 'cancelled' || r.status === 'no_show' || r.status === 'completed') continue;
      if (r.status === 'waitlisted') byKey.wait.items.push(r);
      if (r.status === 'seated') byKey.seated.items.push(r);
      if (r.source === 'walk_in' && r.status !== 'waitlisted') byKey.walkin.items.push(r);
      if (r.source !== 'walk_in' && (r.status === 'confirmed' || r.status === 'pending')) byKey.booked.items.push(r);
    }
    for (var s = 0; s < lanes.length; s++) lanes[s].items.sort(_dayopsSort);

    var html = '<div class="rb-dayops" role="region" aria-label="当日受付ボード">';
    html += '<div class="rb-dayops__head"><div><strong>当日受付ボード</strong><span>予約・飛び込み・待ち・着席中を同じ画面で確認</span></div>';
    html += '<div class="rb-dayops__meta">遅刻/no-show・リマインド・常連情報つき</div></div>';
    html += '<div class="rb-dayops__grid">';
    for (var j = 0; j < lanes.length; j++) {
      html += _renderDayOpsLane(lanes[j]);
    }
    html += '</div></div>';
    return html;
  }

  function _renderDayOpsLane(lane) {
    var html = '<section class="rb-dayops-lane rb-dayops-lane--' + escapeHtml(lane.key) + '">';
    html += '<div class="rb-dayops-lane__head"><span>' + escapeHtml(lane.label) + '</span><strong>' + lane.items.length + '</strong></div>';
    if (!lane.items.length) {
      html += '<div class="rb-dayops-lane__empty">なし</div>';
    } else {
      for (var i = 0; i < lane.items.length && i < 8; i++) {
        html += _renderDayOpsCard(lane.items[i]);
      }
      if (lane.items.length > 8) html += '<div class="rb-dayops-lane__more">+' + (lane.items.length - 8) + '件</div>';
    }
    html += '</section>';
    return html;
  }

  function _renderDayOpsCard(r) {
    var chips = _riskBadgeHtml(r) + _arrivalBadgeHtml(r) + _waitlistCallBadgeHtml(r) + _reminderBadgeHtml(r) + _customerBadgesHtml(r);
    var meta = fmtTime(r.reserved_at) + ' / ' + r.party_size + '名 / ' + statusJa(r.status);
    if (r.source === 'walk_in') meta += ' / walk-in';
    var memo = r.memo ? '<div class="rb-dayops-card__memo">メモ: ' + escapeHtml(r.memo) + '</div>' : '';
    return '<button type="button" class="rb-dayops-card" data-rsv-id="' + escapeHtml(r.id) + '">'
      + '<span class="rb-dayops-card__top"><strong>' + escapeHtml(r.customer_name) + '</strong><span>' + chips + '</span></span>'
      + '<span class="rb-dayops-card__meta">' + escapeHtml(meta) + '</span>'
      + _customerContextLineHtml(r)
      + memo
      + '</button>';
  }

  function renderAttentionPanel(attention) {
    // RSV-P1-1: 注目客 (VIP/BL/allergy/memo) も attention panel に並べる
    var custAtt = (attention && attention.customer_attention) || [];
    var total = attention.unassigned.length + attention.pending_overdue.length + attention.arrival_risk.length + attention.reminder_due.length + custAtt.length + (attention.risk_actions ? attention.risk_actions.length : 0) + (attention.notification_attention ? attention.notification_attention.length : 0);
    if (total === 0) return '';

    var html = '<div class="rb-attention" role="alert">';
    html += '<div class="rb-attention__icon" aria-hidden="true">!</div>';
    html += '<div class="rb-attention__body">';
    html += '<p class="rb-attention__title">要対応の予約があります (' + total + ' 件)</p>';
    html += '<ul class="rb-attention__list">';

    for (var i = 0; i < attention.unassigned.length; i++) {
      var u = attention.unassigned[i];
      html += '<li><button type="button" class="rb-attention__item" data-rsv-id="' + escapeHtml(u.id) + '">🪑 未割当: ' + escapeHtml(u.customer_name) + ' / ' + fmtTime(u.reserved_at) + ' ' + u.party_size + '名</button></li>';
    }
    for (var j = 0; j < attention.pending_overdue.length; j++) {
      var p = attention.pending_overdue[j];
      html += '<li><button type="button" class="rb-attention__item" data-rsv-id="' + escapeHtml(p.id) + '">⏰ 決済待ち: ' + escapeHtml(p.customer_name) + ' / ' + fmtTime(p.reserved_at) + '</button></li>';
    }
    for (var ar = 0; ar < attention.arrival_risk.length; ar++) {
      var rr = attention.arrival_risk[ar];
      html += '<li><button type="button" class="rb-attention__item rb-attention__item--risk" data-rsv-id="' + escapeHtml(rr.id) + '">'
        + '遅刻/no-show: ' + escapeHtml(rr.customer_name) + ' / ' + fmtTime(rr.reserved_at)
        + '</button></li>';
    }
    for (var rd = 0; rd < attention.reminder_due.length; rd++) {
      var rm = attention.reminder_due[rd];
      html += '<li><button type="button" class="rb-attention__item" data-rsv-id="' + escapeHtml(rm.id) + '">'
        + 'リマインド: ' + escapeHtml(rm.customer_name) + ' / ' + escapeHtml((rm.reminder_status && rm.reminder_status.label) || '')
        + '</button></li>';
    }
    var notifyAtt = attention.notification_attention || [];
    for (var nf = 0; nf < notifyAtt.length; nf++) {
      var nr = notifyAtt[nf];
      var na = nr.notification_attention || {};
      html += '<li><button type="button" class="rb-attention__item rb-attention__item--risk" data-rsv-id="' + escapeHtml(nr.id) + '">'
        + '通知失敗: ' + escapeHtml(nr.customer_name) + ' / 再送' + escapeHtml(String(na.retry_count || 0)) + '回'
        + '</button></li>';
    }
    var riskActions = attention.risk_actions || [];
    for (var rx = 0; rx < riskActions.length; rx++) {
      var xr = riskActions[rx];
      var actionLabel = '要確認';
      if (xr.risk_actions && xr.risk_actions.length) {
        for (var ax = 0; ax < xr.risk_actions.length; ax++) {
          if (xr.risk_actions[ax].level === 'warning' || xr.risk_actions[ax].level === 'danger') {
            actionLabel = xr.risk_actions[ax].label || actionLabel;
            break;
          }
        }
      }
      html += '<li><button type="button" class="rb-attention__item rb-attention__item--risk" data-rsv-id="' + escapeHtml(xr.id) + '">'
        + '高リスク: ' + escapeHtml(xr.customer_name) + ' / ' + escapeHtml(actionLabel)
        + '</button></li>';
    }
    // RSV-P1-1: 今日の注目客を attention panel に追加 (朝礼 / プリシフト用)
    for (var k = 0; k < custAtt.length; k++) {
      var c = custAtt[k];
      var icon = '👤';
      if (parseInt(c.customer_is_blacklisted, 10) === 1) icon = '⚠️';
      else if (parseInt(c.customer_is_vip, 10) === 1) icon = '⭐';
      else if (c.customer_allergies && String(c.customer_allergies).trim() !== '') icon = '🥜';
      else if (c.customer_internal_memo && String(c.customer_internal_memo).trim() !== '') icon = '📝';
      html += '<li><button type="button" class="rb-attention__item" data-rsv-id="' + escapeHtml(c.id) + '">'
        + icon + ' 注目客: ' + escapeHtml(c.customer_name)
        + ' / ' + fmtTime(c.reserved_at) + ' ' + c.party_size + '名'
        + _customerBadgesHtml(c)
        + '</button></li>';
    }

    html += '</ul></div></div>';
    return html;
  }

  function drawGantt() {
    var d = _state.data;
    if (!d) return;
    var pxPerMin = currentPxPerMin();

    // KPI + attention
    var attention = computeAttention(d.reservations || []);
    document.getElementById('rb-kpi-area').innerHTML = renderKpi(d.summary || {}, attention);
    document.getElementById('rb-attention-area').innerHTML = renderAttentionPanel(attention);
    var attBtns = document.querySelectorAll('.rb-attention__item');
    for (var ai = 0; ai < attBtns.length; ai++) {
      attBtns[ai].addEventListener('click', function (e) { openDetailModal(e.currentTarget.getAttribute('data-rsv-id')); });
    }

    var dayopsArea = document.getElementById('rb-dayops-area');
    if (dayopsArea) {
      dayopsArea.innerHTML = renderDayOpsBoard(d.reservations || []) + renderWaitlistPanel(d.reservations || []) + renderCancelWaitlistPanel(d.waitlist_candidates || []);
      var dayBtns = dayopsArea.querySelectorAll('[data-rsv-id]');
      for (var di = 0; di < dayBtns.length; di++) {
        dayBtns[di].addEventListener('click', function (e) { openDetailModal(e.currentTarget.getAttribute('data-rsv-id')); });
      }
      var waitCallBtns = dayopsArea.querySelectorAll('[data-wait-call]');
      for (var wi = 0; wi < waitCallBtns.length; wi++) {
        waitCallBtns[wi].addEventListener('click', function (e) {
          updateWaitlistCallById(e.currentTarget.getAttribute('data-wait-rsv-id'), e.currentTarget.getAttribute('data-wait-call'));
        });
      }
    }

    // Gantt
    var openH = parseInt(d.settings.open_time.split(':')[0], 10);
    var openM = parseInt(d.settings.open_time.split(':')[1], 10);
    var closeH = parseInt(d.settings.close_time.split(':')[0], 10);
    var closeM = parseInt(d.settings.close_time.split(':')[1], 10);
    if (closeH === 0) closeH = 24;
    var startMin = openH * 60 + openM;
    var endMin = closeH * 60 + closeM + 60; // 余裕
    var totalMin = endMin - startMin;
    var totalWidth = totalMin * pxPerMin;

    if (!d.tables || !d.tables.length) {
      document.getElementById('rb-gantt-area').innerHTML = '<div class="rb-empty">テーブルが登録されていません。<br>「テーブル・フロア」タブから追加してください。</div>';
      return;
    }

    var html = '<div class="rb-gantt"><div class="rb-gantt__inner" style="min-width:' + (140 + totalWidth) + 'px;">';
    // Header
    html += '<div class="rb-gantt__header"><div class="rb-gantt__corner">テーブル</div><div class="rb-gantt__hours" style="width:' + totalWidth + 'px;">';
    for (var m = startMin; m < endMin; m += 60) {
      var h = Math.floor(m / 60);
      html += '<div class="rb-gantt__hour" style="min-width:' + (60 * pxPerMin) + 'px;max-width:' + (60 * pxPerMin) + 'px;">' + pad2(h % 24) + ':00</div>';
    }
    html += '</div></div>';

    // Now line (同日の場合のみ)
    var todayYmd = ymd(new Date());
    var nowLineHtml = '';
    if (_state.date === todayYmd) {
      var nd = new Date();
      var nowMin = nd.getHours() * 60 + nd.getMinutes();
      if (nowMin >= startMin && nowMin <= endMin) {
        var nowLeft = 140 + (nowMin - startMin) * pxPerMin;
        nowLineHtml = '<div class="rb-gantt__now" style="left:' + nowLeft + 'px;" aria-label="現在時刻"></div>';
      }
    }

    // Rows
    for (var t = 0; t < d.tables.length; t++) {
      var tbl = d.tables[t];
      html += '<div class="rb-gantt__row" data-table-id="' + escapeHtml(tbl.id) + '"><div class="rb-gantt__row-label">' + escapeHtml(tbl.label) + '<small>' + tbl.capacity + '名' + (tbl.area ? ' / ' + escapeHtml(tbl.area) : '') + '</small></div>';
      html += '<div class="rb-gantt__row-area" style="width:' + totalWidth + 'px;">';
      // Hour separators
      for (var hm = startMin; hm < endMin; hm += 60) {
        var leftX = (hm - startMin) * pxPerMin;
        html += '<div class="rb-gantt__cell rb-gantt__cell--hour" style="left:' + leftX + 'px;width:' + (60 * pxPerMin) + 'px;"></div>';
      }
      // Reservations on this table
      for (var r = 0; r < d.reservations.length; r++) {
        var rsv = d.reservations[r];
        // RSV-P1-2: waitlisted はガント時間軸には載せず waitlist パネルで扱う
        if (rsv.status === 'cancelled' || rsv.status === 'no_show' || rsv.status === 'waitlisted') continue;
        var tids = rsv.assigned_table_ids || [];
        if (tids.indexOf(tbl.id) === -1) continue;
        var rsvDate = new Date(rsv.reserved_at);
        var rsvMin = rsvDate.getHours() * 60 + rsvDate.getMinutes();
        var left = (rsvMin - startMin) * pxPerMin;
        var width = rsv.duration_min * pxPerMin;
        if (width < 40) width = 40;
        var narrow = width < 120;
        var cls = 'rb-rsv rb-rsv--' + rsv.status + (narrow ? ' rb-rsv--narrow' : '');
        // RSV-P1-1: VIP は reservation.tags と reservation_customers.is_vip の OR で判定
        if ((rsv.tags && String(rsv.tags).indexOf('VIP') !== -1) || parseInt(rsv.customer_is_vip, 10) === 1) cls += ' rb-rsv--vip';

        var connLabel = '';
        if (tids.length > 1) {
          var otherLabels = [];
          for (var oi = 0; oi < tids.length; oi++) {
            if (tids[oi] === tbl.id) continue;
            for (var ot = 0; ot < d.tables.length; ot++) {
              if (d.tables[ot].id === tids[oi]) { otherLabels.push(d.tables[ot].label); break; }
            }
          }
          connLabel = ' · 🔗' + otherLabels.join('+');
        }

        var memoBadge = rsv.memo ? ' 📝' : '';

        // RSV-P1-1: 顧客要約バッジ (VIP / blacklist / allergy / memo)
        var custBadges = _customerBadgesHtml(rsv);
        var inner = '<div class="rb-rsv__head">'
          + '<span class="rb-rsv__time">' + fmtTime(rsv.reserved_at) + '</span>'
          + '<span class="rb-rsv__party">' + rsv.party_size + '名</span>'
          + '</div>'
          + '<div class="rb-rsv__name">' + escapeHtml(rsv.customer_name) + custBadges + '</div>';
        if (!narrow) {
          var srcLabel = sourceLabel(rsv.source);
          inner += '<div class="rb-rsv__meta">' + escapeHtml(srcLabel) + connLabel + memoBadge + '</div>';
        }

        var titleAttr = escapeHtml(rsv.customer_name) + ' / ' + fmtTime(rsv.reserved_at) + ' / ' + rsv.party_size + '名 / ' + statusJa(rsv.status);
        html += '<div class="' + cls + '" style="left:' + left + 'px;width:' + width + 'px;" data-rsv-id="' + escapeHtml(rsv.id) + '" tabindex="0" title="' + titleAttr + '">' + inner + '</div>';
      }
      html += '</div></div>';
    }
    html += '</div>' + nowLineHtml + '</div>';
    document.getElementById('rb-gantt-area').innerHTML = html;

    // Click / keyboard handler
    var rsvEls = document.querySelectorAll('.rb-rsv[data-rsv-id]');
    for (var i = 0; i < rsvEls.length; i++) {
      rsvEls[i].addEventListener('click', function (e) {
        openDetailModal(e.currentTarget.getAttribute('data-rsv-id'));
      });
      rsvEls[i].addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openDetailModal(e.currentTarget.getAttribute('data-rsv-id'));
        }
      });
    }
  }

  // ---------- Detail Drawer / Modal ----------
  function openDetailModal(rsvId) {
    apiGet('/reservations.php?store_id=' + encodeURIComponent(_storeId) + '&id=' + encodeURIComponent(rsvId), function (err, data) {
      if (err) { alert(err.message); return; }
      var r = data.reservation;
      var st = r.status;
      var html = '<div class="rb-modal rb-modal--drawer" id="rb-modal"><div class="rb-modal__content">';
      html += '<button class="rb-modal__close" data-close type="button" aria-label="閉じる">×</button>';
      html += '<div class="rb-detail__header">';
      html += '<div>';
      html += '<h3 class="rb-detail__title">' + escapeHtml(r.customer_name) + '</h3>';
      html += '<div class="rb-detail__sub">' + r.party_size + '名 / ' + escapeHtml(fmtTime(r.reserved_at)) + ' / ' + r.duration_min + '分</div>';
      html += '</div>';
      html += '<span class="rb-status-pill rb-status-pill--' + escapeHtml(st) + '">' + escapeHtml(statusJa(st)) + '</span>';
      html += '</div>';

      html += '<div class="rb-modal__row"><span>予約日時</span><span>' + escapeHtml(r.reserved_at) + '</span></div>';
      html += '<div class="rb-modal__row"><span>ソース</span><span>' + escapeHtml(sourceLabel(r.source)) + '</span></div>';
      if (r.customer_phone) html += '<div class="rb-modal__row"><span>電話</span><span><a href="tel:' + escapeHtml(r.customer_phone) + '">' + escapeHtml(r.customer_phone) + '</a></span></div>';
      if (r.customer_email) html += '<div class="rb-modal__row"><span>メール</span><span>' + escapeHtml(r.customer_email) + '</span></div>';
      if (r.course_name) html += '<div class="rb-modal__row"><span>コース</span><span>' + escapeHtml(r.course_name) + '</span></div>';
      if (r.memo) html += '<div class="rb-modal__row"><span>メモ</span><span>' + escapeHtml(r.memo) + '</span></div>';
      if (r.tags) html += '<div class="rb-modal__row"><span>タグ</span><span>' + escapeHtml(r.tags) + '</span></div>';
      if (r.deposit_required) html += '<div class="rb-modal__row"><span>予約金</span><span>¥' + r.deposit_amount.toLocaleString() + ' / ' + escapeHtml(r.deposit_status) + '</span></div>';
      html += _renderOpsStatus(r);
      html += _renderWaitlistCallStatus(r);
      html += _renderArrivalFollowupActions(r);
      html += _renderWaitlistCallActions(r);

      // RSV-P1-1: 顧客要約セクション (reservation_customers と紐付く場合のみ)
      html += _renderCustomerSummary(r);
      html += _renderRiskActions(r);
      html += _renderReminderDelivery(r);
      html += _renderChangeHistory(r);

      html += '<div class="rb-modal__actions">';
      if (r.status === 'confirmed' || r.status === 'pending') {
        html += '<button class="rb-btn-seat" data-action="seat" type="button">🪑 着席</button>';
        html += '<button class="rb-btn-edit" data-action="edit" type="button">✏️ 変更</button>';
        html += '<button class="rb-btn-noshow" data-action="noshow" type="button">no-show確定</button>';
        html += '<button class="rb-btn-cancel" data-action="cancel" type="button">キャンセル</button>';
        if (r.reminder_status && r.reminder_status.next_due && (r.customer_email || r.customer_phone || r.customer_id)) html += '<button class="rb-btn-resend" data-action="send_reminder" type="button">リマインド送信</button>';
        if (r.customer_email || r.customer_phone || r.customer_id) html += '<button class="rb-btn-resend" data-action="resend" type="button">通知再送</button>';
      }
      // RSV-P1-2: 受付待ちから着席 / 変更 / キャンセル
      if (r.status === 'waitlisted') {
        html += '<button class="rb-btn-seat" data-action="seat_waitlist" type="button">🪑 着席 (テーブル指定)</button>';
        html += '<button class="rb-btn-edit" data-action="edit" type="button">✏️ 変更</button>';
        html += '<button class="rb-btn-cancel" data-action="cancel" type="button">キャンセル</button>';
      }
      if (r.status === 'seated' && r.table_session_id) {
        var sessionUrl = '/customer/menu.html?store_id=' + encodeURIComponent(_storeId) + '&table_id=' + encodeURIComponent((r.assigned_table_ids || [])[0] || '');
        html += '<a class="rb-btn-edit" target="_blank" href="' + escapeHtml(sessionUrl) + '">📲 セッション</a>';
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
      // RSV-P1-1 + hotfix: 顧客台帳で編集 → openCustomerModal に遷移。
      // 保存後は customer タブの DOM ではなく board を再読込する
      // (customer タブの rb-cust-q / rb-cust-list が未 render の状態で fetchCustomers が走るのを防ぐ)
      var custEditBtn = modal.querySelector('[data-rb-open-customer]');
      if (custEditBtn) {
        custEditBtn.addEventListener('click', function (e) {
          var cid = e.currentTarget.getAttribute('data-rb-open-customer');
          if (cid) {
            closeModal();
            openCustomerModal(cid, { onSaved: function () { loadGantt(); } });
          }
        });
      }
      // ESC で閉じる
      _installEscClose();
    });
  }

  // RSV-P1-1: 顧客要約 HTML 生成 (予約詳細モーダル内のセクション)
  // blacklist は最上位で強警告、VIP / allergy / memo は色分けして表示、
  // preferences / customer_tags は末尾に補助情報として出す。
  // 編集導線は「顧客台帳で編集 →」ボタンで既存 openCustomerModal に遷移。
  function _renderCustomerSummary(r) {
    if (!r || !r.customer_id) return '';
    var isVip       = parseInt(r.customer_is_vip, 10) === 1;
    var isBlack     = parseInt(r.customer_is_blacklisted, 10) === 1;
    var allergies   = r.customer_allergies && String(r.customer_allergies).trim() !== '' ? String(r.customer_allergies).trim() : '';
    var memoText    = r.customer_internal_memo && String(r.customer_internal_memo).trim() !== '' ? String(r.customer_internal_memo).trim() : '';
    var prefs       = r.customer_preferences && String(r.customer_preferences).trim() !== '' ? String(r.customer_preferences).trim() : '';
    var custTagsStr = r.customer_tags && String(r.customer_tags).trim() !== '' ? String(r.customer_tags).trim() : '';
    var visitCount  = (r.customer_visit_count !== null && r.customer_visit_count !== undefined) ? parseInt(r.customer_visit_count, 10) : null;
    var noShowCount = (r.customer_no_show_count !== null && r.customer_no_show_count !== undefined) ? parseInt(r.customer_no_show_count, 10) : null;
    var cancelCount = (r.customer_cancel_count !== null && r.customer_cancel_count !== undefined) ? parseInt(r.customer_cancel_count, 10) : null;
    var lastVisit   = r.customer_last_visit_at ? String(r.customer_last_visit_at).substring(0, 10) : '';

    // 何も無ければセクション自体を出さない (customer_id あっても空プロフィールの場合)
    if (!isVip && !isBlack && !allergies && !memoText && !prefs && !custTagsStr && !visitCount && !noShowCount && !cancelCount && !lastVisit) return '';

    var html = '<div class="rb-customer-summary" style="margin:12px 0;padding:10px 12px;border:1px solid #dde6ef;border-radius:8px;background:#f7fafd;">';
    html += '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;">';
    html += '<span style="font-size:0.85rem;font-weight:700;color:#37474f;">顧客要約</span>';
    html += '<button type="button" class="rb-btn-edit" data-rb-open-customer="' + escapeHtml(r.customer_id) + '" style="font-size:0.75rem;padding:2px 8px">顧客台帳で編集 →</button>';
    html += '</div>';

    // blacklist は最上位で赤警告
    if (isBlack) {
      var reason = r.customer_blacklist_reason ? ' — ' + escapeHtml(String(r.customer_blacklist_reason)) : '';
      html += '<div style="padding:6px 8px;margin-bottom:6px;background:#ffebee;border-left:4px solid #c62828;border-radius:4px;color:#c62828;font-weight:700;font-size:0.85rem;">⚠️ ブラックリスト指定' + reason + '</div>';
    }

    // VIP + 来店回数
    var headBits = [];
    if (isVip) headBits.push('<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:#ffb300;color:#fff;font-size:0.72rem;font-weight:700">⭐ VIP</span>');
    if (visitCount !== null && !isNaN(visitCount)) headBits.push('<span style="font-size:0.8rem;color:#455a64">来店 ' + visitCount + ' 回</span>');
    if (lastVisit) headBits.push('<span style="font-size:0.8rem;color:#455a64">前回 ' + escapeHtml(lastVisit) + '</span>');
    if (noShowCount !== null && !isNaN(noShowCount) && noShowCount > 0) headBits.push('<span style="font-size:0.8rem;color:#c62828;font-weight:700">no-show ' + noShowCount + '</span>');
    if (cancelCount !== null && !isNaN(cancelCount) && cancelCount > 0) headBits.push('<span style="font-size:0.8rem;color:#6d4c00">キャンセル ' + cancelCount + '</span>');
    if (headBits.length > 0) {
      html += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap">' + headBits.join('') + '</div>';
    }

    // allergies は橙色 callout
    if (allergies) {
      html += '<div style="padding:6px 8px;margin-bottom:6px;background:#fff3e0;border-left:3px solid #ff7043;border-radius:4px;color:#bf360c;font-size:0.8rem;">🥜 アレルギー: ' + escapeHtml(allergies) + '</div>';
    }

    // internal_memo は黄色 box + ラベル明示
    if (memoText) {
      html += '<div style="padding:6px 8px;margin-bottom:6px;background:#fffde7;border-left:3px solid #fbc02d;border-radius:4px;color:#6d4c00;font-size:0.8rem;">📝 スタッフメモ: ' + escapeHtml(memoText) + '</div>';
    }

    // preferences (補助)
    if (prefs) {
      html += '<div style="font-size:0.8rem;color:#455a64;margin-bottom:4px;">好み: ' + escapeHtml(prefs) + '</div>';
    }

    // customer tags (補助、表示専用)
    if (custTagsStr) {
      html += '<div style="font-size:0.78rem;color:#546e7a;">タグ: ' + escapeHtml(custTagsStr) + '</div>';
    }

    html += '</div>';
    return html;
  }

  function _renderRiskActions(r) {
    if (!r || !r.risk_actions || !r.risk_actions.length) return '';
    var html = '<div class="rb-risk-actions">';
    html += '<div class="rb-risk-actions__title">高リスク対応</div>';
    for (var i = 0; i < r.risk_actions.length; i++) {
      var a = r.risk_actions[i];
      html += '<div class="rb-risk-action rb-risk-action--' + escapeHtml(a.level || 'notice') + '">';
      html += '<strong>' + escapeHtml(a.label || '要確認') + '</strong>';
      if (a.detail) html += '<span>' + escapeHtml(a.detail) + '</span>';
      html += '</div>';
    }
    html += '</div>';
    return html;
  }

  function _renderReminderDelivery(r) {
    if (!r || !r.reminder_delivery || !r.reminder_delivery.length) return '';
    var html = '<div class="rb-change-history">';
    html += '<div class="rb-change-history__title">リマインド送信履歴</div>';
    html += '<ul>';
    for (var i = 0; i < r.reminder_delivery.length; i++) {
      var d = r.reminder_delivery[i];
      html += '<li><span>' + escapeHtml(reminderTypeLabel(d.notification_type)) + ' / ' + escapeHtml(d.channel || '-') + '</span>';
      html += '<strong class="rb-delivery-status rb-delivery-status--' + escapeHtml(d.status || 'queued') + '">' + escapeHtml(deliveryStatusLabel(d.status)) + '</strong>';
      html += '<em>' + escapeHtml((d.sent_at || d.created_at || '').substring(0, 16)) + '</em>';
      if (d.retry_count) html += '<small>再送 ' + escapeHtml(String(d.retry_count)) + '回 / 次回 ' + escapeHtml((d.next_retry_at || '-').substring(0, 16)) + '</small>';
      if (parseInt(d.manager_attention || 0, 10) === 1 && !d.resolved_at) html += '<small>店長確認が必要です</small>';
      if (d.error_message) html += '<small>' + escapeHtml(d.error_message) + '</small>';
      html += '</li>';
    }
    html += '</ul></div>';
    return html;
  }

  function reminderTypeLabel(type) {
    return ({ reminder_24h: '24時間前', reminder_2h: '2時間前' })[type] || type || '-';
  }

  function deliveryStatusLabel(status) {
    return ({ queued: '送信待ち', sent: '送信済み', failed: '失敗' })[status] || status || '-';
  }

  function _renderChangeHistory(r) {
    if (!r || !r.change_history || !r.change_history.length) return '';
    var html = '<div class="rb-change-history">';
    html += '<div class="rb-change-history__title">変更履歴</div>';
    html += '<ul>';
    for (var i = 0; i < r.change_history.length; i++) {
      var h = r.change_history[i];
      html += '<li>';
      html += '<span>' + escapeHtml(fieldLabel(h.field_name)) + '</span>';
      html += '<strong>' + escapeHtml(historyValue(h.old_value)) + ' → ' + escapeHtml(historyValue(h.new_value)) + '</strong>';
      html += '<em>' + escapeHtml((h.changed_at || '').substring(0, 16)) + ' / ' + escapeHtml(h.actor_name || actorTypeLabel(h.actor_type)) + '</em>';
      html += '</li>';
    }
    html += '</ul></div>';
    return html;
  }

  function fieldLabel(field) {
    return ({
      customer_name: '名前',
      customer_phone: '電話',
      customer_email: 'メール',
      party_size: '人数',
      reserved_at: '予約日時',
      duration_min: '滞在分',
      assigned_table_ids: 'テーブル',
      memo: 'メモ',
      tags: 'タグ',
      status: 'ステータス',
      cancel_reason: 'キャンセル理由',
      arrival_followup_status: '遅刻対応',
      waitlist_call_status: '待ち客呼出',
      table_session_id: 'テーブルセッション'
    })[field] || field;
  }

  function historyValue(value) {
    if (value === null || value === undefined || value === '') return '-';
    var text = String(value);
    if (text.length > 80) text = text.substring(0, 80) + '...';
    return text;
  }

  function actorTypeLabel(type) {
    return ({ staff: 'スタッフ', customer: 'お客様', system: 'システム' })[type] || '-';
  }

  function _installEscClose() {
    if (_state._escBound) return;
    _state._escBound = true;
    var h = function (e) {
      if (e.key === 'Escape' && document.getElementById('rb-modal')) {
        closeModal();
      }
    };
    _state._escHandler = h;
    document.addEventListener('keydown', h);
  }

  function closeModal() {
    document.getElementById('rb-modal-host').innerHTML = '';
    if (_state._escBound && _state._escHandler) {
      document.removeEventListener('keydown', _state._escHandler);
      _state._escBound = false;
      _state._escHandler = null;
    }
  }

  function _renderOpsStatus(r) {
    if (!r) return '';
    var html = '';
    var followStatus = r.arrival_followup_status || 'none';
    if (r.ops_risk && r.ops_risk.level && r.ops_risk.level !== 'normal') {
      var reasons = (r.ops_risk.reasons || []).join(' / ');
      html += '<div class="rb-ops-status rb-ops-status--' + escapeHtml(r.ops_risk.level) + '">';
      html += '<strong>' + escapeHtml(r.ops_risk.label || '注意') + '</strong>';
      if (reasons) html += '<span>' + escapeHtml(reasons) + '</span>';
      html += '</div>';
    }
    if (followStatus !== 'none') {
      var followText = arrivalFollowupLabel(followStatus);
      if (r.arrival_followup_note) followText += ' / ' + r.arrival_followup_note;
      if (r.arrival_followup_at) followText += ' / ' + String(r.arrival_followup_at).substring(11, 16);
      html += '<div class="rb-ops-status rb-ops-status--arrival rb-ops-status--arrival-' + escapeHtml(followStatus) + '">';
      html += '<strong>遅刻対応</strong><span>' + escapeHtml(followText) + '</span>';
      html += '</div>';
    }
    if (r.reminder_status) {
      html += '<div class="rb-ops-status rb-ops-status--reminder">';
      html += '<strong>来店前リマインド</strong><span>' + escapeHtml(r.reminder_status.label || '-') + '</span>';
      html += '</div>';
    }
    return html;
  }

  function _renderWaitlistCallStatus(r) {
    if (!r || r.status !== 'waitlisted') return '';
    var st = r.waitlist_call_status || 'not_called';
    var text = waitlistCallLabel(st);
    if (r.waitlist_call_count) text += ' / ' + r.waitlist_call_count + '回';
    if (r.waitlist_called_at) text += ' / ' + String(r.waitlist_called_at).substring(11, 16);
    var html = '<div class="rb-ops-status rb-ops-status--waitcall rb-ops-status--waitcall-' + escapeHtml(st) + '">';
    html += '<strong>待ち客呼出</strong><span>' + escapeHtml(text) + '</span>';
    html += '</div>';
    return html;
  }

  function _waitlistActionButton(status, label, current) {
    var cls = 'rb-waitcall-action rb-waitcall-action--' + status;
    if (current === status) cls += ' rb-waitcall-action--active';
    return '<button class="' + cls + '" data-action="waitcall_' + escapeHtml(status) + '" type="button">' + escapeHtml(label) + '</button>';
  }

  function _renderWaitlistCallActions(r) {
    if (!r || r.status !== 'waitlisted') return '';
    var current = r.waitlist_call_status || 'not_called';
    var html = '<div class="rb-waitcall-actions">';
    html += '<div class="rb-waitcall-actions__label">待ち客呼び出し</div>';
    html += '<div class="rb-waitcall-actions__buttons">';
    html += _waitlistActionButton('called', '呼出', current);
    html += _waitlistActionButton('recalled', '再呼出', current);
    html += _waitlistActionButton('absent', '不在', current);
    if (current !== 'not_called') html += _waitlistActionButton('not_called', '未呼出に戻す', current);
    html += '</div></div>';
    return html;
  }

  function _arrivalActionButton(status, label, current) {
    var cls = 'rb-arrival-action rb-arrival-action--' + status;
    if (current === status) cls += ' rb-arrival-action--active';
    return '<button class="' + cls + '" data-action="followup_' + escapeHtml(status) + '" type="button">' + escapeHtml(label) + '</button>';
  }

  function _renderArrivalFollowupActions(r) {
    if (!r || (r.status !== 'confirmed' && r.status !== 'pending')) return '';
    var current = r.arrival_followup_status || 'none';
    var html = '<div class="rb-arrival-actions">';
    html += '<div class="rb-arrival-actions__label">遅刻対応</div>';
    html += '<div class="rb-arrival-actions__buttons">';
    html += _arrivalActionButton('contacted', '連絡済み', current);
    html += _arrivalActionButton('arriving', '到着予定', current);
    html += _arrivalActionButton('waiting_reply', '折り返し待ち', current);
    if (current !== 'none') html += _arrivalActionButton('none', '未対応に戻す', current);
    html += '</div></div>';
    return html;
  }

  function showSeatQrModal(r, data) {
    var url = data.qr_url;
    var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content rb-modal__content--qr">';
    html += '<button class="rb-modal__close" data-close type="button">×</button>';
    html += '<h3>🪑 着席完了</h3>';
    html += '<p class="rb-qr__name">' + escapeHtml(r.customer_name) + ' 様 / ' + r.party_size + '名</p>';
    html += '<p class="rb-qr__desc">このQRコードをお客様のスマホで読み取るか、テーブルのQRコードから直接注文できます</p>';
    html += '<div id="rb-seat-qr"></div>';
    html += '<div class="rb-qr__url-box">' + escapeHtml(url) + '</div>';
    html += '<div class="rb-modal__actions rb-modal__actions--center">';
    html += '<button class="rb-btn-edit" id="rb-seat-copy" type="button">📋 URLコピー</button>';
    html += '<button class="rb-btn-resend" id="rb-seat-print" type="button">🖨 QR印刷</button>';
    html += '<button class="rb-btn-cancel" data-close type="button">閉じる</button>';
    html += '</div></div></div>';
    document.getElementById('rb-modal-host').innerHTML = html;

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
    _installEscClose();
  }

  // RSV-P1-2: 受付待ち → 着席用のテーブル選択モーダル
  function openWaitlistSeatModal(r) {
    var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
    html += '<button class="rb-modal__close" data-close type="button">×</button>';
    html += '<h3>🪑 ' + escapeHtml(r.customer_name) + ' 様を着席</h3>';
    html += '<p class="rb-empty rb-empty--inline" style="margin:0 0 8px;font-size:0.85rem;color:#555;">' + r.party_size + '名 / 受付待ち中</p>';
    html += '<div class="rb-modal__form-group"><label>テーブル *</label><div class="rb-table-list" id="rb-ws-tables"></div></div>';
    html += '<div class="rb-modal__form-group"><label>滞在分</label><input type="number" id="rb-ws-dur" value="90" min="15" step="15"></div>';
    html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close type="button">戻る</button><button class="rb-btn-edit" id="rb-ws-save" type="button">着席する</button></div>';
    html += '</div></div>';
    document.getElementById('rb-modal-host').innerHTML = html;

    if (_state.data && _state.data.tables) {
      var tlist = document.getElementById('rb-ws-tables');
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

    document.getElementById('rb-ws-save').addEventListener('click', function () {
      var tblChecks = document.querySelectorAll('#rb-ws-tables input[type="checkbox"]:checked');
      var tids = [];
      for (var ci = 0; ci < tblChecks.length; ci++) tids.push(tblChecks[ci].value);
      if (!tids.length) { alert('テーブルを選択してください'); return; }
      var dur = parseInt(document.getElementById('rb-ws-dur').value, 10) || 90;
      var saveBtn = this;
      saveBtn.disabled = true; saveBtn.textContent = '…';
      // 現在時刻を reserved_at に寄せ直す (waitlisted 作成時の登録時刻と着席時刻がずれるため)
      var nowD = new Date();
      var nowIso = ymd(nowD) + 'T' + pad2(nowD.getHours()) + ':' + pad2(nowD.getMinutes()) + ':00';
      apiSend('PATCH', '/reservations.php', {
        id: r.id, store_id: _storeId,
        status: 'confirmed',
        assigned_table_ids: tids,
        duration_min: dur,
        reserved_at: nowIso
      }, function (err, data) {
        if (err) { alert(err.message); saveBtn.disabled = false; saveBtn.textContent = '着席する'; return; }
        apiSend('POST', '/reservation-seat.php', { reservation_id: r.id, store_id: _storeId, table_ids: tids }, function (seatErr, seatData) {
          if (seatErr) { alert(seatErr.message); saveBtn.disabled = false; saveBtn.textContent = '着席する'; return; }
          showSeatQrModal(r, seatData);
          loadGantt();
        });
      });
    });
    _installEscClose();
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
    } else if (action === 'seat_waitlist') {
      // RSV-P1-2: 受付待ち → 着席。テーブル選択後 PATCH で status='seated' + assigned_table_ids
      openWaitlistSeatModal(r);
    } else if (action === 'noshow') {
      if (!confirm('no-show として記録しますか?')) return;
      apiSend('POST', '/reservation-no-show.php', { reservation_id: r.id, store_id: _storeId }, function (err, data) {
        if (err) { alert(err.message); return; }
        var msg = 'no-show 完了';
        if (data.captured_amount) msg += ' (予約金 ¥' + data.captured_amount.toLocaleString() + ' を回収)';
        alert(msg);
        closeModal(); loadGantt();
      });
    } else if (action.indexOf('followup_') === 0) {
      updateArrivalFollowup(r, action.substring(9));
    } else if (action.indexOf('waitcall_') === 0) {
      updateWaitlistCall(r, action.substring(9));
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
    } else if (action === 'send_reminder') {
      var reminderType = (r.reminder_status && r.reminder_status.next_due) ? r.reminder_status.next_due : 'reminder_2h';
      apiSend('POST', '/reservation-notify.php', { reservation_id: r.id, store_id: _storeId, type: reminderType }, function (err) {
        if (err) { alert(err.message); return; }
        alert('リマインドを送信しました');
        closeModal(); loadGantt();
      });
    }
  }

  function updateArrivalFollowup(r, status) {
    apiSend('PATCH', '/reservations.php', { id: r.id, store_id: _storeId, arrival_followup_status: status }, function (err) {
      if (err) { alert(err.message); return; }
      loadGantt();
      openDetailModal(r.id);
    });
  }

  function updateWaitlistCall(r, status) {
    updateWaitlistCallById(r.id, status, function () { openDetailModal(r.id); });
  }

  function updateWaitlistCallById(reservationId, status, afterLoad) {
    apiSend('PATCH', '/reservations.php', { id: reservationId, store_id: _storeId, waitlist_call_status: status }, function (err) {
      if (err) { alert(err.message); return; }
      loadGantt();
      if (afterLoad) afterLoad();
    });
  }

  function openEditModal(r) {
    var dateVal = r.reserved_at.substring(0, 10);
    var timeVal = r.reserved_at.substring(11, 16);
    var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
    html += '<button class="rb-modal__close" data-close type="button">×</button>';
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
    html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close type="button">戻る</button><button class="rb-btn-edit" id="rb-e-save" type="button">保存</button></div>';
    html += '</div></div>';
    document.getElementById('rb-modal-host').innerHTML = html;

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
        if (err) { alert(err.message); var b = document.getElementById('rb-e-save'); if (b) { b.disabled = false; b.textContent = '保存'; } return; }
        closeModal(); loadGantt();
      });
    });
    _installEscClose();
  }

  // RSV-P1-2: mode は 'reservation' | 'walkin' | 'waitlist' の 3 モード。
  // 後方互換: 真偽値渡し (true=walkin, false=reservation) も許容
  function openCreateModal(mode) {
    var modeStr;
    if (mode === true) modeStr = 'walkin';
    else if (mode === false || mode === undefined || mode === null) modeStr = 'reservation';
    else modeStr = String(mode);
    if (modeStr !== 'walkin' && modeStr !== 'waitlist' && modeStr !== 'reservation') modeStr = 'reservation';

    var nowD = new Date();
    var dateVal = ymd(nowD);
    var timeVal = pad2(nowD.getHours()) + ':' + pad2(nowD.getMinutes());
    var titleText;
    if (modeStr === 'walkin') titleText = '+ Walk-in 追加';
    else if (modeStr === 'waitlist') titleText = '+ 受付待ち 追加';
    else titleText = '+ 新規予約';

    var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
    html += '<button class="rb-modal__close" data-close type="button">×</button>';
    html += '<h3>' + titleText + '</h3>';
    if (modeStr === 'waitlist') {
      html += '<p class="rb-empty rb-empty--inline" style="margin:0 0 8px;font-size:0.8rem;color:#666;">受付待ち客として登録します。席が空いたら「着席」で本席へ移してください。</p>';
    }
    html += '<div class="rb-modal__form-group"><label>名前 *</label><input type="text" id="rb-c-name"></div>';
    html += '<div class="rb-modal__form-group"><label>電話</label><input type="tel" id="rb-c-phone"></div>';
    html += '<div class="rb-modal__form-group"><label>メール</label><input type="email" id="rb-c-email"></div>';
    // 受付待ちは時間軸に載せないが、レコードは reserved_at を要求するため現在時刻を使う
    if (modeStr === 'waitlist') {
      html += '<input type="hidden" id="rb-c-date" value="' + dateVal + '">';
      html += '<input type="hidden" id="rb-c-time" value="' + timeVal + '">';
    } else {
      html += '<div class="rb-modal__form-group"><label>日付 *</label><input type="date" id="rb-c-date" value="' + dateVal + '"></div>';
      html += '<div class="rb-modal__form-group"><label>時刻 *</label><input type="time" id="rb-c-time" value="' + timeVal + '" step="900"></div>';
    }
    html += '<div class="rb-modal__form-group"><label>人数 *</label><input type="number" id="rb-c-party" value="2" min="1" max="50"></div>';
    if (modeStr !== 'waitlist') {
      html += '<div class="rb-modal__form-group"><label>滞在分</label><input type="number" id="rb-c-dur" value="90" min="15" step="15"></div>';
      html += '<div class="rb-modal__form-group"><label>テーブル割当</label><div class="rb-table-list" id="rb-c-tables"></div></div>';
    } else {
      // waitlist 時は滞在分/テーブルは後で着席時に入力
      html += '<input type="hidden" id="rb-c-dur" value="90">';
    }
    html += '<div class="rb-modal__form-group"><label>メモ</label><textarea id="rb-c-memo" rows="2"></textarea></div>';
    html += '<div class="rb-modal__form-group"><label>タグ</label><input type="text" id="rb-c-tags" placeholder="VIP, 誕生日 等"></div>';
    if (modeStr !== 'waitlist') {
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" id="rb-c-skip-deposit" checked> 予約金徴収をスキップ (店内手動入力)</label></div>';
    }
    html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close type="button">戻る</button><button class="rb-btn-edit" id="rb-c-save" type="button">作成</button></div>';
    html += '</div></div>';
    document.getElementById('rb-modal-host').innerHTML = html;

    if (modeStr !== 'waitlist' && _state.data && _state.data.tables) {
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
      var skipDepEl = document.getElementById('rb-c-skip-deposit');
      var skipDep = skipDepEl ? skipDepEl.checked : true; // 受付待ちは常に skip
      var tids = [];
      var tblChecks = document.querySelectorAll('#rb-c-tables input[type="checkbox"]:checked');
      for (var ci = 0; ci < tblChecks.length; ci++) tids.push(tblChecks[ci].value);

      var srcValue = modeStr === 'walkin' ? 'walk_in' : (modeStr === 'waitlist' ? 'walk_in' : 'phone');
      var statusValue;
      if (modeStr === 'walkin') statusValue = 'seated';
      else if (modeStr === 'waitlist') statusValue = 'waitlisted';
      else statusValue = 'confirmed';

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
        source: srcValue,
        status: statusValue,
        skip_deposit: skipDep
      };
      this.disabled = true; this.textContent = '…';
      apiSend('POST', '/reservations.php', body, function (err, data) {
        if (err) { alert(err.message); var b = document.getElementById('rb-c-save'); if (b) { b.disabled = false; b.textContent = '作成'; } return; }
        if (modeStr === 'walkin' && tids.length) {
          apiSend('POST', '/reservation-seat.php', { reservation_id: data.reservation.id, store_id: _storeId, table_ids: tids }, function () {
            closeModal(); loadGantt();
          });
        } else {
          closeModal(); loadGantt();
        }
      });
    });
    _installEscClose();
  }

  // ---------- Customers ----------
  function loadCustomers() {
    var body = document.getElementById('rb-tab-body');
    var html = '<div class="rb-customers">';
    html += '<div class="rb-customers__filters">';
    html += '<input type="text" id="rb-cust-q" placeholder="名前 / 電話 / メールで検索">';
    html += '<label><input type="checkbox" id="rb-cust-vip"> VIPのみ</label>';
    html += '<label><input type="checkbox" id="rb-cust-bl"> ブラックリストのみ</label>';
    html += '<button id="rb-cust-search" class="rb-btn rb-btn--primary" type="button">検索</button>';
    html += '</div>';
    html += '<div class="rb-customers__list" id="rb-cust-list"><div class="rb-loading">読み込み中…</div></div>';
    html += '</div>';
    body.innerHTML = html;
    document.getElementById('rb-cust-search').addEventListener('click', fetchCustomers);
    document.getElementById('rb-cust-q').addEventListener('keydown', function (e) { if (e.key === 'Enter') fetchCustomers(); });
    fetchCustomers();
  }
  function fetchCustomers() {
    // RSV-P1-1 hotfix: 顧客タブ以外 (rb-cust-q/rb-cust-list 未 render) から呼ばれても JS エラーで落ちないよう guard
    var qEl = document.getElementById('rb-cust-q');
    if (!qEl) return;
    var q = qEl.value;
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
        html += '<div class="rb-customer-card__meta">来店 ' + c.visit_count + ' 回 / no-show ' + c.no_show_count + ' / cancel ' + c.cancel_count + '</div>';
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
  // RSV-P1-1 hotfix: options.onSaved で呼び出し元ごとに保存後挙動を分岐
  // (顧客タブ: fetchCustomers / 予約台帳経由: loadGantt)。未指定時は fetchCustomers が guard で安全に no-op
  function openCustomerModal(cid, options) {
    apiGet('/reservation-customers.php?store_id=' + encodeURIComponent(_storeId) + '&id=' + encodeURIComponent(cid), function (err, data) {
      if (err) { alert(err.message); return; }
      var c = data.customer;
      var html = '<div class="rb-modal" id="rb-modal"><div class="rb-modal__content">';
      html += '<button class="rb-modal__close" data-close type="button">×</button>';
      html += '<h3>' + escapeHtml(c.customer_name) + '</h3>';
      html += '<div class="rb-modal__form-group"><label>来店履歴</label>';
      if (data.history.length) {
        html += '<ul class="rb-history-list">';
        for (var i = 0; i < data.history.length; i++) {
          var h = data.history[i];
          html += '<li>' + escapeHtml(h.reserved_at) + ' / ' + h.party_size + '名 / ' + escapeHtml(statusJa(h.status)) + ' (' + escapeHtml(sourceLabel(h.source)) + ')</li>';
        }
        html += '</ul>';
      } else { html += '<div class="rb-empty rb-empty--inline">なし</div>'; }
      html += '</div>';
      html += '<div class="rb-modal__form-group"><label>好み</label><textarea id="rb-cu-pref" rows="2">' + escapeHtml(c.preferences || '') + '</textarea></div>';
      html += '<div class="rb-modal__form-group"><label>アレルギー</label><textarea id="rb-cu-allergy" rows="2">' + escapeHtml(c.allergies || '') + '</textarea></div>';
      html += '<div class="rb-modal__form-group"><label>タグ</label><input type="text" id="rb-cu-tags" value="' + escapeHtml(c.tags || '') + '"></div>';
      html += '<div class="rb-modal__form-group"><label>内部メモ</label><textarea id="rb-cu-memo" rows="2">' + escapeHtml(c.internal_memo || '') + '</textarea></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" id="rb-cu-vip" ' + (parseInt(c.is_vip, 10) ? 'checked' : '') + '> VIP</label></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" id="rb-cu-bl" ' + (parseInt(c.is_blacklisted, 10) ? 'checked' : '') + '> ブラックリスト (manager以上)</label><input type="text" id="rb-cu-bl-reason" class="rb-bl-reason-input" placeholder="理由" value="' + escapeHtml(c.blacklist_reason || '') + '"></div>';
      html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close type="button">戻る</button><button class="rb-btn-edit" id="rb-cu-save" type="button">保存</button></div>';
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
          closeModal();
          // RSV-P1-1 hotfix: options.onSaved があればそれを呼ぶ (予約台帳経由は loadGantt)、
          // なければ顧客タブ前提で fetchCustomers (fetchCustomers 側にも DOM guard あり)
          if (options && typeof options.onSaved === 'function') {
            options.onSaved();
          } else {
            fetchCustomers();
          }
        });
      });
      _installEscClose();
    });
  }

  // ---------- Courses ----------
  function loadCourses() {
    var body = document.getElementById('rb-tab-body');
    var html = '<div class="rb-customers">';
    html += '<div class="rb-customers__filters">';
    html += '<button id="rb-add-course" class="rb-btn rb-btn--primary" type="button">+ コース追加</button>';
    html += '</div>';
    html += '<div id="rb-courses-list"><div class="rb-loading">読み込み中…</div></div>';
    html += '</div>';
    body.innerHTML = html;
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
        html += '<div class="rb-customer-card__name">' + escapeHtml(c.name) + ' <span class="rb-course-dur">(' + c.duration_min + '分)</span></div>';
        html += '<div class="rb-customer-card__meta">¥' + parseInt(c.price, 10).toLocaleString() + ' / ' + c.min_party_size + '〜' + c.max_party_size + '名</div>';
        if (c.description) html += '<div class="rb-customer-card__meta">' + escapeHtml(c.description) + '</div>';
        html += '<div class="rb-customer-card__tags"><span class="rb-tag">' + (parseInt(c.is_active, 10) ? '✅ 有効' : '⛔ 無効') + '</span></div>';
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
    html += '<button class="rb-modal__close" data-close type="button">×</button>';
    html += '<h3>' + (isNew ? '+ コース追加' : 'コース編集') + '</h3>';
    html += '<div class="rb-modal__form-group"><label>名称 *</label><input type="text" id="rb-co-name" value="' + escapeHtml(cd.name) + '"></div>';
    html += '<div class="rb-modal__form-group"><label>名称(英)</label><input type="text" id="rb-co-name-en" value="' + escapeHtml(cd.name_en || '') + '"></div>';
    html += '<div class="rb-modal__form-group"><label>説明</label><textarea id="rb-co-desc" rows="2">' + escapeHtml(cd.description || '') + '</textarea></div>';
    html += '<div class="rb-modal__form-group"><label>価格 (円)</label><input type="number" id="rb-co-price" value="' + cd.price + '" min="0"></div>';
    html += '<div class="rb-modal__form-group"><label>所要時間 (分)</label><input type="number" id="rb-co-dur" value="' + cd.duration_min + '" min="15" step="15"></div>';
    html += '<div class="rb-modal__form-group"><label>人数 最小</label><input type="number" id="rb-co-min" value="' + cd.min_party_size + '" min="1"></div>';
    html += '<div class="rb-modal__form-group"><label>人数 最大</label><input type="number" id="rb-co-max" value="' + cd.max_party_size + '" min="1"></div>';
    html += '<div class="rb-modal__form-group"><label>並び順</label><input type="number" id="rb-co-sort" value="' + cd.sort_order + '"></div>';
    html += '<div class="rb-modal__form-group"><label><input type="checkbox" id="rb-co-active" ' + (parseInt(cd.is_active, 10) ? 'checked' : '') + '> 有効</label></div>';
    html += '<div class="rb-modal__actions"><button class="rb-btn-cancel" data-close type="button">戻る</button>';
    if (!isNew) html += '<button class="rb-btn-noshow" id="rb-co-del" type="button">削除</button>';
    html += '<button class="rb-btn-edit" id="rb-co-save" type="button">保存</button></div>';
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
    _installEscClose();
  }

  // ---------- Settings ----------
  function loadSettings() {
    var body = document.getElementById('rb-tab-body');
    body.innerHTML = '<div class="rb-customers" id="rb-settings-area"><div class="rb-loading">読み込み中…</div></div>';
    apiGet('/reservation-settings.php?store_id=' + encodeURIComponent(_storeId), function (err, data) {
      if (err) { document.getElementById('rb-settings-area').innerHTML = '<div class="rb-empty">' + escapeHtml(err.message) + '</div>'; return; }
      var s = data.settings;
      var html = '<form id="rb-settings-form">';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="online_enabled" ' + (parseInt(s.online_enabled, 10) ? 'checked' : '') + '> オンライン予約を有効化</label></div>';
      html += group('lead_time_hours', '受付締切 (時間前)', s.lead_time_hours, 'number');
      html += group('max_advance_days', '受付期間 (日先)', s.max_advance_days, 'number');
      html += group('default_duration_min', 'デフォルト滞在 (分)', s.default_duration_min, 'number');
      html += group('slot_interval_min', 'スロット間隔 (分)', s.slot_interval_min, 'number');
      html += group('min_party_size', '最小人数', s.min_party_size, 'number');
      html += group('max_party_size', '最大人数', s.max_party_size, 'number');
      html += '<hr><h4>Web予約枠ルール</h4>';
      html += group('web_phone_only_min_party_size', '電話受付のみ開始人数 (0=無効)', s.web_phone_only_min_party_size || 0, 'number');
      html += group('web_peak_start_time', 'ピーク開始 (任意)', s.web_peak_start_time ? String(s.web_peak_start_time).substring(0, 5) : '', 'time');
      html += group('web_peak_end_time', 'ピーク終了 (任意)', s.web_peak_end_time ? String(s.web_peak_end_time).substring(0, 5) : '', 'time');
      html += group('web_peak_max_groups', 'ピークWeb最大組数 (0=無制限)', s.web_peak_max_groups || 0, 'number');
      html += group('web_peak_max_covers', 'ピークWeb最大人数 (0=無制限)', s.web_peak_max_covers || 0, 'number');
      html += group('web_table_area_filter', 'Web予約に使うフロア/エリア (空欄=全席)', s.web_table_area_filter || '', 'text');
      html += group('open_time', '営業開始', s.open_time.substring(0, 5), 'time');
      html += group('close_time', '営業終了', s.close_time.substring(0, 5), 'time');
      html += group('last_order_offset_min', 'ラストオーダー (分前)', s.last_order_offset_min, 'number');
      html += group('weekly_closed_days', '定休曜日 (0=日 6=土 カンマ区切り)', s.weekly_closed_days || '', 'text');
      html += group('buffer_before_min', 'テーブル準備時間 (分)', s.buffer_before_min, 'number');
      html += group('buffer_after_min', '清掃時間 (分)', s.buffer_after_min, 'number');
      html += group('cancel_deadline_hours', 'キャンセル無料 (時間前)', s.cancel_deadline_hours, 'number');
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="require_phone" ' + (parseInt(s.require_phone, 10) ? 'checked' : '') + '> 電話必須</label></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="require_email" ' + (parseInt(s.require_email, 10) ? 'checked' : '') + '> メール必須</label></div>';
      html += '<hr><h4>予約金 (Stripe必須)</h4>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="deposit_enabled" ' + (parseInt(s.deposit_enabled, 10) ? 'checked' : '') + '> 予約金を徴収する (現在: ' + (data.deposit_available ? '✅利用可' : '❌Stripe未設定') + ')</label></div>';
      html += group('deposit_per_person', '一人あたり (円)', s.deposit_per_person, 'number');
      html += group('deposit_min_party_size', '徴収開始人数', s.deposit_min_party_size, 'number');
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="high_risk_deposit_enabled" ' + (parseInt(s.high_risk_deposit_enabled, 10) ? 'checked' : '') + '> 高リスク予約は予約金必須</label></div>';
      html += group('high_risk_deposit_min_no_show_count', 'no-show履歴しきい値', s.high_risk_deposit_min_no_show_count || 2, 'number');
      html += group('high_risk_deposit_large_party_size', '大人数しきい値', s.high_risk_deposit_large_party_size || 8, 'number');
      html += '<hr><h4>通知</h4>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="reminder_24h_enabled" ' + (parseInt(s.reminder_24h_enabled, 10) ? 'checked' : '') + '> 24時間前リマインダー</label></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="reminder_2h_enabled" ' + (parseInt(s.reminder_2h_enabled, 10) ? 'checked' : '') + '> 2時間前リマインダー</label></div>';
      html += group('reminder_retry_minutes', '失敗時の再送間隔 (分)', s.reminder_retry_minutes || 15, 'number');
      html += group('reminder_retry_max', '再送上限回数', s.reminder_retry_max || 3, 'number');
      html += group('waitlist_lock_minutes', 'キャンセル待ち優先確保 (分)', s.waitlist_lock_minutes || 15, 'number');
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="sms_enabled" ' + (parseInt(s.sms_enabled, 10) ? 'checked' : '') + '> SMS Webhook通知を有効化</label></div>';
      html += group('sms_webhook_url', 'SMS Webhook URL (https)', s.sms_webhook_url || '', 'url');
      html += '<div class="rb-modal__form-group"><label>SMSテスト送信先</label><div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;"><input type="tel" id="rb-sms-test-phone" value="" placeholder="09012345678" style="flex:1;min-width:220px;"><button class="rb-btn-edit" id="rb-sms-test" type="button">SMS接続テスト</button></div></div>';
      html += '<div class="rb-modal__form-group"><label><input type="checkbox" name="ai_chat_enabled" ' + (parseInt(s.ai_chat_enabled, 10) ? 'checked' : '') + '> AIチャット予約 (Gemini)</label></div>';
      html += group('notification_email', '店舗側通知メール', s.notification_email || '', 'email');
      html += group('notes_to_customer', '客向け注意事項', s.notes_to_customer || '', 'textarea');
      html += '<div class="rb-save-row"><button class="rb-btn-edit" id="rb-set-save" type="button">設定を保存</button></div>';
      html += '</form>';
      document.getElementById('rb-settings-area').innerHTML = html;
      document.getElementById('rb-set-save').addEventListener('click', saveSettings);
      document.getElementById('rb-sms-test').addEventListener('click', testSmsConnection);
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
  function testSmsConnection() {
    var phone = document.getElementById('rb-sms-test-phone').value;
    apiSend('POST', '/reservation-notification-test.php', { store_id: _storeId, channel: 'sms', phone: phone }, function (err, data) {
      if (err) { alert(err.message); return; }
      if (data && data.sent) alert('SMS接続テストを実行しました');
      else alert('SMS接続テストに失敗しました: ' + ((data && data.error) ? data.error : 'unknown'));
    });
  }

  // ---------- Stats ----------
  function loadStats() {
    var body = document.getElementById('rb-tab-body');
    var to = ymd(new Date());
    var fromD = new Date(); fromD.setDate(fromD.getDate() - 30);
    var from = ymd(fromD);
    var html = '<div class="rb-customers">';
    html += '<div class="rb-customers__filters">';
    html += '<label>From <input type="date" id="rb-st-from" value="' + from + '"></label>';
    html += '<label>To <input type="date" id="rb-st-to" value="' + to + '"></label>';
    html += '<button id="rb-st-apply" class="rb-btn rb-btn--primary" type="button">表示</button>';
    html += '</div>';
    html += '<div id="rb-st-area"><div class="rb-loading">読み込み中…</div></div>';
    html += '</div>';
    body.innerHTML = html;
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
      var html = '<div class="rb-kpi rb-stats-kpi">';
      html += '<div class="rb-kpi__card"><div class="rb-kpi__label">予約総数</div><div class="rb-kpi__value">' + t.total + '</div></div>';
      html += '<div class="rb-kpi__card"><div class="rb-kpi__label">来店人数</div><div class="rb-kpi__value">' + t.total_party_size + '</div></div>';
      html += '<div class="rb-kpi__card"><div class="rb-kpi__label">no-show率</div><div class="rb-kpi__value">' + t.no_show_rate + '<span class="rb-kpi__value-unit">%</span></div></div>';
      html += '<div class="rb-kpi__card"><div class="rb-kpi__label">キャンセル率</div><div class="rb-kpi__value">' + t.cancel_rate + '<span class="rb-kpi__value-unit">%</span></div></div>';
      html += '</div>';
      html += '<h4 class="rb-stats-h4">ソース別</h4>';
      html += '<table class="rb-stats-table">';
      html += '<tr><th>Web</th><th>電話</th><th>Walk-in</th><th>AI</th></tr>';
      html += '<tr><td>' + s.web + '</td><td>' + s.phone + '</td><td>' + s.walk_in + '</td><td>' + s.ai_chat + '</td></tr>';
      html += '</table>';
      html += '<h4 class="rb-stats-h4">日別</h4>';
      html += '<table class="rb-stats-table">';
      html += '<tr><th>日付</th><th>件数</th><th>人数</th></tr>';
      for (var i = 0; i < data.by_day.length; i++) {
        html += '<tr><td>' + escapeHtml(data.by_day[i].d) + '</td><td>' + data.by_day[i].cnt + '</td><td>' + data.by_day[i].guests + '</td></tr>';
      }
      html += '</table>';
      html += '<h4 class="rb-stats-h4">時間帯別</h4>';
      html += '<table class="rb-stats-table">';
      for (var j = 0; j < data.by_hour.length; j++) {
        var bw = Math.min(100, data.by_hour[j].cnt * 10);
        html += '<tr><td class="rb-stats__hour-cell">' + data.by_hour[j].h + ':00</td><td><div class="rb-bar" style="width:' + bw + '%;">' + data.by_hour[j].cnt + '</div></td></tr>';
      }
      html += '</table>';
      area.innerHTML = html;
    });
  }

  // expose
  window.ReservationBoard = { init: init };
})();
