/* L-9 予約管理 — 客側 予約詳細・変更・キャンセル IIFE (ES5) */

(function () {
  'use strict';

  var API = '/api/customer';
  var _id = null, _token = null, _data = null;

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  // S3-#17: ES5 互換 (String.prototype.padStart は古い WebView 非対応)
  function _pad2(n) { return ('0' + n).slice(-2); }
  function showToast(msg, ms) {
    var el = document.getElementById('rs-toast');
    el.textContent = msg; el.hidden = false;
    setTimeout(function () { el.hidden = true; }, ms || 2400);
  }
  function makeApiError(d, fallback) {
    if (window.Utils && Utils.createApiError) {
      return Utils.createApiError(d, fallback || 'エラー');
    }
    return (d && d.error) || d || { message: fallback || 'エラー' };
  }
  function formatDisplayError(err, fallback) {
    if (window.Utils && Utils.formatError) return Utils.formatError(err || { message: fallback || 'エラー' });
    return (err && err.message) || fallback || 'エラー';
  }
  function apiGet(path, cb) {
    var x = new XMLHttpRequest(); x.open('GET', API + path);
    x.onload = function () {
      try { var d = JSON.parse(x.responseText); cb(d.ok ? null : makeApiError(d), d.data); }
      catch (e) { cb({ message: 'invalid_response' }); }
    };
    x.onerror = function () { cb({ message: 'network_error' }); };
    x.send();
  }
  function apiPost(path, body, cb) {
    var x = new XMLHttpRequest(); x.open('POST', API + path);
    x.setRequestHeader('Content-Type', 'application/json');
    x.onload = function () {
      try { var d = JSON.parse(x.responseText); cb(d.ok ? null : makeApiError(d), d.data); }
      catch (e) { cb({ message: 'invalid_response' }); }
    };
    x.onerror = function () { cb({ message: 'network_error' }); };
    x.send(JSON.stringify(body));
  }

  function init() {
    var qs = new URLSearchParams(location.search);
    _id = qs.get('id'); _token = qs.get('t');
    if (!_id || !_token) { showError('リンクが不正です'); return; }
    var depositResult = qs.get('deposit');
    if (depositResult === 'success') showToast('予約金のお支払いを確認しました', 3500);
    else if (depositResult === 'cancel') showToast('予約金のお支払いがキャンセルされました', 3500);
    load();
  }

  function load() {
    apiGet('/reservation-detail.php?id=' + encodeURIComponent(_id) + '&t=' + encodeURIComponent(_token), function (err, data) {
      if (err) { showError(formatDisplayError(err, '予約を取得できませんでした')); return; }
      _data = data;
      render();
    });
  }

  function render() {
    var r = _data.reservation;
    var s = _data.store || {};
    var dt = new Date(r.reserved_at);
    var when = dt.getFullYear() + '/' + _pad2(dt.getMonth()+1) + '/' + _pad2(dt.getDate()) + ' ' + _pad2(dt.getHours()) + ':' + _pad2(dt.getMinutes());

    var statusLabel = { confirmed: '✅ 確定', pending: '⏳ 決済待ち', seated: '🪑 着席中', no_show: '❌ no-show', cancelled: '❌ キャンセル済み', completed: '✅ 完了' }[r.status] || r.status;

    var html = '<section class="rs-section">';
    html += '<h2 class="rs-section__title">' + escapeHtml(s.name || '予約') + '</h2>';
    html += '<div class="rs-confirm-row"><span>ステータス</span><span>' + escapeHtml(statusLabel) + '</span></div>';
    html += '<div class="rs-confirm-row"><span>予約番号</span><span style="font-family:monospace;font-size:0.85rem;">' + escapeHtml(r.id) + '</span></div>';
    html += '<div class="rs-confirm-row"><span>日時</span><span id="rd-when-display">' + escapeHtml(when) + '</span></div>';
    html += '<div class="rs-confirm-row"><span>人数</span><span id="rd-party-display">' + r.party_size + '名</span></div>';
    if (r.course_name) html += '<div class="rs-confirm-row"><span>コース</span><span>' + escapeHtml(r.course_name) + '</span></div>';
    if (r.memo) html += '<div class="rs-confirm-row"><span>ご要望</span><span>' + escapeHtml(r.memo) + '</span></div>';
    html += '<div class="rs-confirm-row"><span>お名前</span><span>' + escapeHtml(r.customer_name) + '</span></div>';
    if (r.customer_phone) html += '<div class="rs-confirm-row"><span>電話</span><span>' + escapeHtml(r.customer_phone) + '</span></div>';
    if (r.customer_email) html += '<div class="rs-confirm-row"><span>メール</span><span>' + escapeHtml(r.customer_email) + '</span></div>';

    if (r.deposit_required) {
      html += '<div class="rs-confirm-row"><span>予約金</span><span>¥' + r.deposit_amount.toLocaleString() + ' / ' + escapeHtml(r.deposit_status) + '</span></div>';
      if (r.deposit_status === 'pending' || r.deposit_status === 'failed') {
        html += '<button class="rs-btn rs-btn--primary" id="rd-pay" style="margin-top:12px;">予約金を支払う</button>';
      }
    }

    if (s.address) html += '<div class="rs-confirm-row"><span>住所</span><span>' + escapeHtml(s.address) + '</span></div>';
    if (s.phone) html += '<div class="rs-confirm-row"><span>店舗電話</span><span><a href="tel:' + escapeHtml(s.phone) + '">' + escapeHtml(s.phone) + '</a></span></div>';

    html += '<p class="rs-section__sub" style="margin-top:12px;">キャンセル無料: 来店' + (_data.cancel_deadline_hours || 3) + '時間前まで</p>';

    html += '<div class="rs-completion__actions">';
    if (r.status === 'confirmed' || r.status === 'pending') {
      html += '<a class="rs-btn rs-btn--secondary" style="text-decoration:none;text-align:center;" href="webcal://' + window.location.host + '/customer/reservation-ical.php?id=' + encodeURIComponent(_id) + '&t=' + encodeURIComponent(_token) + '">📅 カレンダーに追加</a>';
      html += '<button class="rs-btn rs-btn--secondary" id="rd-edit">時間・人数・メモを変更</button>';
      html += '<button class="rs-btn rs-btn--secondary" id="rd-cancel" style="background:#ffebee;color:#c62828;">予約をキャンセル</button>';
    }
    html += '</div>';
    html += '</section>';
    document.getElementById('rd-app').innerHTML = html;

    var pay = document.getElementById('rd-pay');
    if (pay) pay.addEventListener('click', payDeposit);
    var edit = document.getElementById('rd-edit');
    if (edit) edit.addEventListener('click', openEditForm);
    var cancel = document.getElementById('rd-cancel');
    if (cancel) cancel.addEventListener('click', confirmCancel);
  }

  function payDeposit() {
    apiPost('/reservation-deposit-checkout.php', { id: _id, edit_token: _token }, function (err, data) {
      if (err) { showToast(formatDisplayError(err, 'エラー'), 3000); return; }
      window.location.href = data.checkout_url;
    });
  }

  function openEditForm() {
    var r = _data.reservation;
    var dt = new Date(r.reserved_at);
    var dateVal = dt.getFullYear() + '-' + _pad2(dt.getMonth()+1) + '-' + _pad2(dt.getDate());
    var timeVal = _pad2(dt.getHours()) + ':' + _pad2(dt.getMinutes());
    var html = '<section class="rs-section"><h2 class="rs-section__title">予約変更</h2>';
    html += '<div class="rs-form-group"><label>日付</label><input type="date" id="rd-date" value="' + dateVal + '"></div>';
    html += '<div class="rs-form-group"><label>時刻</label><input type="time" id="rd-time" value="' + timeVal + '" step="900"></div>';
    html += '<div class="rs-form-group"><label>人数</label><input type="number" id="rd-party" value="' + r.party_size + '" min="1" max="50"></div>';
    html += '<div class="rs-form-group"><label>ご要望</label><textarea id="rd-memo" rows="3">' + escapeHtml(r.memo || '') + '</textarea></div>';
    html += '<div class="rs-nav">';
    html += '<button class="rs-btn rs-btn--secondary" id="rd-edit-cancel">戻る</button>';
    html += '<button class="rs-btn rs-btn--primary" id="rd-edit-submit">変更を保存</button>';
    html += '</div></section>';
    document.getElementById('rd-app').innerHTML = html;
    document.getElementById('rd-edit-cancel').addEventListener('click', render);
    document.getElementById('rd-edit-submit').addEventListener('click', function () {
      var date = document.getElementById('rd-date').value;
      var time = document.getElementById('rd-time').value;
      var party = parseInt(document.getElementById('rd-party').value, 10);
      var memo = document.getElementById('rd-memo').value;
      if (!date || !time || !party) { showToast('入力に不備があります'); return; }
      this.disabled = true; this.textContent = '…';
      var btn = this;
      apiPost('/reservation-update.php', { id: _id, edit_token: _token, reserved_at: date + 'T' + time + ':00', party_size: party, memo: memo }, function (err, data) {
        btn.disabled = false; btn.textContent = '変更を保存';
        if (err) { showToast(formatDisplayError(err, 'エラー'), 3500); return; }
        showToast('変更を保存しました', 2500);
        load();
      });
    });
  }

  function confirmCancel() {
    if (!confirm('本当にキャンセルしますか? この操作は取り消せません')) return;
    apiPost('/reservation-cancel.php', { id: _id, edit_token: _token, reason: 'customer_cancel' }, function (err, data) {
      if (err) { showToast(formatDisplayError(err, 'エラー'), 3500); return; }
      var msg = 'キャンセルが完了しました';
      if (data.deposit_outcome === 'captured') msg += '(キャンセル料が発生しました)';
      else if (data.deposit_outcome === 'released') msg += '(予約金は返金されます)';
      showToast(msg, 4000);
      load();
    });
  }

  function showError(msg) {
    document.getElementById('rd-app').innerHTML = '<div class="rs-error" style="margin:24px;">' + escapeHtml(msg) + '</div>';
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
