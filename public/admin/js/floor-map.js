/**
 * フロアマップ — テーブル状態一覧
 *
 * シンプルなカードグリッドで各テーブルの使用状況を表示。
 * 5秒ポーリングで自動更新。
 *
 * O-1: no_order ステータス / 品目進捗バー / サマリーバー強化
 * O-4: メモ表示・編集
 */
var FloorMap = (function () {
  'use strict';

  var POLL_INTERVAL = 5000;

  var STATUS = {
    empty:          { color: '#43a047', bg: '#e8f5e9', label: '空き' },
    seated:         { color: '#1e88e5', bg: '#e3f2fd', label: '着席' },
    no_order:       { color: '#ff9800', bg: '#fff8e1', label: '未注文' },
    eating:         { color: '#1565c0', bg: '#e3f2fd', label: '食事中' },
    bill_requested: { color: '#e53935', bg: '#ffebee', label: '会計待ち' },
    overtime:       { color: '#e65100', bg: '#fff3e0', label: '時間超過' }
  };

  var MEMO_PRESETS = ['アレルギー注意', 'VIP', 'お子様連れ', '誕生日', '接待', '車椅子'];

  var _container = null;
  var _pollTimer = null;

  function init(container) {
    _container = container;

    // イベント委譲
    _container.addEventListener('click', function (e) {
      // 着席キャンセルボタン
      var cancelBtn = e.target.closest('.fmap-card__cancel-btn');
      if (cancelBtn) {
        var sessionId = cancelBtn.dataset.sessionId;
        var tableCode = cancelBtn.dataset.tableCode;
        if (!confirm(tableCode + ' の着席をキャンセルしますか？')) return;
        AdminApi.closeTableSession(sessionId).then(function () {
          _fetch();
        }).catch(function (err) {
          alert(err.message || 'エラーが発生しました');
        });
        return;
      }

      // O-3: ラストオーダーボタン
      var loBtn = e.target.closest('#fmap-lo-btn');
      if (loBtn) {
        _toggleLastOrder(loBtn);
        return;
      }

      // メモ編集ボタン
      var memoBtn = e.target.closest('.fmap-card__memo-btn');
      if (memoBtn) {
        _openMemoModal(memoBtn.dataset.sessionId, memoBtn.dataset.tableCode, memoBtn.dataset.memo || '');
        return;
      }

      // メモプリセットタグ
      var presetTag = e.target.closest('.fmap-memo-preset');
      if (presetTag) {
        var textarea = document.getElementById('fmap-memo-textarea');
        if (textarea) {
          var cur = textarea.value.trim();
          var tag = presetTag.dataset.tag;
          textarea.value = cur ? (cur + '\n' + tag) : tag;
        }
        return;
      }

      // メモ保存ボタン
      var saveBtn = e.target.closest('#fmap-memo-save');
      if (saveBtn) {
        _saveMemo();
        return;
      }

      // メモモーダル閉じる
      var closeBtn = e.target.closest('#fmap-memo-cancel');
      if (closeBtn || e.target.classList.contains('fmap-memo-overlay')) {
        _closeMemoModal();
        return;
      }
    });
  }

  function load() {
    _container.innerHTML =
      '<div class="fmap-summary" id="fmap-summary"></div>' +
      '<div class="fmap-grid" id="fmap-grid"></div>';

    _stopPolling();
    _fetch();
    _pollTimer = setInterval(_fetch, POLL_INTERVAL);
  }

  function stop() {
    _stopPolling();
  }

  function _stopPolling() {
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
  }

  function _fetch() {
    var storeId = AdminApi.getCurrentStore();
    if (!storeId) return;

    AdminApi.getTablesStatus().then(function (data) {
      _renderGrid(data.tables || [], data.lastOrder || {});
    }).catch(function (err) {
      var grid = document.getElementById('fmap-grid');
      if (grid) grid.innerHTML = '<div class="empty-state"><div class="empty-state__text">データ取得エラー: ' + _esc(err.message) + '</div></div>';
    });
  }

  function _renderGrid(tables, lastOrder) {
    var grid = document.getElementById('fmap-grid');
    if (!grid) return;

    // サマリー集計
    var total = tables.length;
    var emptyCount = 0;
    var seatedCount = 0;
    var noOrderCount = 0;
    var totalGuests = 0;
    var totalSales = 0;

    tables.forEach(function (t) {
      var st = _getStatus(t);
      if (st === 'empty') emptyCount++;
      else if (st === 'no_order') noOrderCount++;
      else seatedCount++;
      if (t.session && t.session.guestCount) totalGuests += t.session.guestCount;
      if (t.orders && t.orders.totalAmount) totalSales += t.orders.totalAmount;
    });

    // O-3: ラストオーダー状態
    var loActive = lastOrder && lastOrder.active;
    var loTime = lastOrder && lastOrder.time ? lastOrder.time.substring(0, 5) : null;
    var loBtnLabel = loActive ? 'LO解除' : 'ラストオーダー';
    var loBtnClass = loActive ? 'fmap-lo-btn fmap-lo-btn--active' : 'fmap-lo-btn';
    var loStatusHtml = loActive ? '<span class="fmap-summary__item fmap-summary__item--red">ラストオーダー中</span>' : '';
    if (loTime && !loActive) {
      loStatusHtml = '<span class="fmap-summary__item fmap-summary__item--muted">LO ' + loTime + '</span>';
    }

    var summary = document.getElementById('fmap-summary');
    if (summary) {
      summary.innerHTML =
        '<span class="fmap-summary__item">全 ' + total + '卓</span>' +
        '<span class="fmap-summary__item fmap-summary__item--green">空き ' + emptyCount + '</span>' +
        '<span class="fmap-summary__item fmap-summary__item--blue">使用中 ' + seatedCount + '</span>' +
        (noOrderCount > 0 ? '<span class="fmap-summary__item fmap-summary__item--orange">未注文 ' + noOrderCount + '</span>' : '') +
        '<span class="fmap-summary__item">合計 ' + totalGuests + '名</span>' +
        '<span class="fmap-summary__item fmap-summary__item--bold">&yen;' + totalSales.toLocaleString() + '</span>' +
        loStatusHtml +
        '<button class="' + loBtnClass + '" id="fmap-lo-btn">' + loBtnLabel + '</button>';
    }

    if (total === 0) {
      grid.innerHTML = '<div class="empty-state"><div class="empty-state__text">テーブルが登録されていません</div></div>';
      return;
    }

    var html = '';
    tables.forEach(function (t) {
      var st = _getStatus(t);
      var s = STATUS[st];

      html += '<div class="fmap-card" style="border-left-color:' + s.color + ';background:' + s.bg + '">';
      html += '<div class="fmap-card__header">';
      html += '<span class="fmap-card__code">' + _esc(t.tableCode) + '</span>';
      html += '<span class="fmap-card__badge" style="background:' + s.color + '">' + s.label + '</span>';
      html += '</div>';

      html += '<div class="fmap-card__body">';
      if (t.session) {
        if (t.session.guestCount) html += '<div class="fmap-card__row">' + t.session.guestCount + '名</div>';
        html += '<div class="fmap-card__row">' + t.session.elapsedMin + '分経過</div>';
        if (t.session.timeLimitMin) {
          var remain = t.session.timeLimitMin - t.session.elapsedMin;
          if (remain > 0) {
            html += '<div class="fmap-card__row fmap-card__row--muted">残り' + remain + '分</div>';
          } else {
            html += '<div class="fmap-card__row fmap-card__row--warn">' + Math.abs(remain) + '分超過</div>';
          }
        }
      }
      if (t.orders && t.orders.orderCount > 0) {
        html += '<div class="fmap-card__row">' + t.orders.orderCount + '件の注文</div>';
        html += '<div class="fmap-card__amount">&yen;' + t.orders.totalAmount.toLocaleString() + '</div>';
      }

      // O-1: 品目進捗バー
      if (t.itemStatus) {
        var is = t.itemStatus;
        var totalQty = is.pendingQty + is.preparingQty + is.readyQty + is.servedQty;
        if (totalQty > 0) {
          var pPct = Math.round(is.pendingQty / totalQty * 100);
          var prPct = Math.round(is.preparingQty / totalQty * 100);
          var rPct = Math.round(is.readyQty / totalQty * 100);
          var sPct = Math.round(is.servedQty / totalQty * 100);
          html += '<div class="fmap-progress">';
          if (sPct > 0) html += '<div class="fmap-progress__seg fmap-progress__seg--served" style="width:' + sPct + '%" title="提供済 ' + is.servedQty + '品"></div>';
          if (rPct > 0) html += '<div class="fmap-progress__seg fmap-progress__seg--ready" style="width:' + rPct + '%" title="完成 ' + is.readyQty + '品"></div>';
          if (prPct > 0) html += '<div class="fmap-progress__seg fmap-progress__seg--preparing" style="width:' + prPct + '%" title="調理中 ' + is.preparingQty + '品"></div>';
          if (pPct > 0) html += '<div class="fmap-progress__seg fmap-progress__seg--pending" style="width:' + pPct + '%" title="受付 ' + is.pendingQty + '品"></div>';
          html += '</div>';
        }
      }

      // O-4: メモ表示
      if (t.session && t.session.memo) {
        html += '<div class="fmap-card__memo">' + _esc(t.session.memo) + '</div>';
      }

      if (!t.session && (!t.orders || t.orders.orderCount === 0)) {
        html += '<div class="fmap-card__row fmap-card__row--muted">' + t.capacity + '席</div>';
      }
      if (t.session) {
        html += '<div class="fmap-card__actions">';
        html += '<button class="fmap-card__memo-btn" data-session-id="' + t.session.id + '" data-table-code="' + _esc(t.tableCode) + '" data-memo="' + _esc(t.session.memo || '') + '">メモ</button>';
        html += '<button class="fmap-card__cancel-btn" data-session-id="' + t.session.id + '" data-table-code="' + _esc(t.tableCode) + '">取消</button>';
        html += '</div>';
      }
      html += '</div>';

      html += '</div>';
    });

    grid.innerHTML = html;
  }

  function _getStatus(t) {
    if (t.session) {
      var s = t.session.status;
      if (s === 'bill_requested') return 'bill_requested';
      if (t.session.timeLimitMin && t.session.elapsedMin > t.session.timeLimitMin) return 'overtime';
      // O-1: 着席済みだが注文ゼロ → no_order
      if (s === 'seated' && (!t.orders || t.orders.orderCount === 0)) return 'no_order';
      if (s === 'eating') return 'eating';
      if (s === 'seated') return 'seated';
      return 'eating';
    }
    if (t.orders && t.orders.orderCount > 0) return 'eating';
    return 'empty';
  }

  // O-4: メモ編集モーダル
  function _openMemoModal(sessionId, tableCode, currentMemo) {
    // 既存モーダルがあれば削除
    _closeMemoModal();

    var presetHtml = '';
    for (var i = 0; i < MEMO_PRESETS.length; i++) {
      presetHtml += '<span class="fmap-memo-preset" data-tag="' + _esc(MEMO_PRESETS[i]) + '">' + _esc(MEMO_PRESETS[i]) + '</span>';
    }

    var overlay = document.createElement('div');
    overlay.className = 'fmap-memo-overlay';
    overlay.innerHTML =
      '<div class="fmap-memo-modal">' +
      '<div class="fmap-memo-modal__title">' + _esc(tableCode) + ' メモ</div>' +
      '<div class="fmap-memo-presets">' + presetHtml + '</div>' +
      '<textarea id="fmap-memo-textarea" class="fmap-memo-modal__textarea" rows="4" placeholder="メモを入力...">' + _esc(currentMemo) + '</textarea>' +
      '<div class="fmap-memo-modal__footer">' +
      '<button id="fmap-memo-cancel" class="fmap-memo-modal__btn fmap-memo-modal__btn--cancel">キャンセル</button>' +
      '<button id="fmap-memo-save" class="fmap-memo-modal__btn fmap-memo-modal__btn--save" data-session-id="' + sessionId + '">保存</button>' +
      '</div>' +
      '</div>';

    _container.appendChild(overlay);
  }

  function _closeMemoModal() {
    var existing = _container.querySelector('.fmap-memo-overlay');
    if (existing) existing.remove();
  }

  function _saveMemo() {
    var saveBtn = document.getElementById('fmap-memo-save');
    var textarea = document.getElementById('fmap-memo-textarea');
    if (!saveBtn || !textarea) return;

    var sessionId = saveBtn.dataset.sessionId;
    var memo = textarea.value.trim();
    var storeId = AdminApi.getCurrentStore();
    if (!storeId) return;

    saveBtn.disabled = true;
    saveBtn.textContent = '保存中...';

    fetch('../../api/store/table-sessions.php?id=' + encodeURIComponent(sessionId), {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ store_id: storeId, memo: memo || null }),
      credentials: 'same-origin'
    })
    .then(function (r) { return r.text().then(function (t) { return JSON.parse(t); }); })
    .then(function (json) {
      if (!json.ok) {
        alert((json.error && json.error.message) || '保存に失敗しました');
        saveBtn.disabled = false;
        saveBtn.textContent = '保存';
        return;
      }
      _closeMemoModal();
      _fetch();
    })
    .catch(function () {
      alert('通信エラー');
      saveBtn.disabled = false;
      saveBtn.textContent = '保存';
    });
  }

  // O-3: ラストオーダー切替
  function _toggleLastOrder(btn) {
    var isActive = btn.classList.contains('fmap-lo-btn--active');
    var action = isActive ? 0 : 1;
    var msg = isActive ? 'ラストオーダーを解除しますか？' : 'ラストオーダーを発動しますか？\nセルフオーダー画面で注文がブロックされます。';
    if (!confirm(msg)) return;

    var storeId = AdminApi.getCurrentStore();
    if (!storeId) return;
    btn.disabled = true;

    fetch('../../api/store/last-order.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ store_id: storeId, active: action }),
      credentials: 'same-origin'
    })
    .then(function (r) { return r.text().then(function (t) { return JSON.parse(t); }); })
    .then(function (json) {
      if (!json.ok) {
        alert((json.error && json.error.message) || '操作に失敗しました');
        return;
      }
      _fetch();
    })
    .catch(function () { alert('通信エラー'); })
    .finally(function () { btn.disabled = false; });
  }

  function _esc(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  return { init: init, load: load, stop: stop };
})();
