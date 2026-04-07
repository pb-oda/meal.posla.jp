/**
 * スタッフ別評価 + 監査・不正検知レポート
 * M-1: スタッフ別評価レポート
 * M-3: 監査・不正検知
 */
var StaffReport = (function () {
  'use strict';

  var _container = null;

  function init(container) {
    _container = container;
  }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';

    var range = Utils.getPresetRange('today');
    renderControls(range.from, range.to);
    fetchData(range.from, range.to);
  }

  function renderControls(from, to) {
    _container.innerHTML =
      '<div class="report-presets" id="staff-presets">'
      + '<button class="btn btn-outline active" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline" data-preset="month">今月</button></div>'
      + '<div class="report-date-range">'
      + '<input type="date" id="staff-from" value="' + from + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="staff-to" value="' + to + '">'
      + '<button class="btn btn-sm btn-primary" id="btn-staff-load">表示</button></div>'
      + '<div id="staff-data"></div>';

    document.getElementById('staff-presets').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var r = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('staff-from').value = r.from;
      document.getElementById('staff-to').value = r.to;
      this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      fetchData(r.from, r.to);
    });

    document.getElementById('btn-staff-load').addEventListener('click', function () {
      fetchData(
        document.getElementById('staff-from').value,
        document.getElementById('staff-to').value
      );
    });
  }

  function fetchData(from, to) {
    var dataEl = document.getElementById('staff-data');
    if (!dataEl) return;
    dataEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getStaffReport(from, to).then(function (res) {
      renderReport(dataEl, res);
    }).catch(function (err) {
      dataEl.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  /* ========== レンダリング ========== */

  function renderReport(el, data) {
    var html = '';
    html += renderAlertBanner(data.alerts);
    html += renderStaffPerformance(data.staffPerformance);
    html += renderCancelAnalysis(data.cancelDetails);
    html += renderRegisterDiscrepancies(data.registerDiscrepancies);
    html += renderLateNightOps(data.lateNightOps);
    el.innerHTML = html;
  }

  // --- 1. アラートバナー ---
  function renderAlertBanner(alerts) {
    if (!alerts || alerts.length === 0) {
      return '<div style="background:#e8f5e9;border-left:4px solid #43a047;padding:12px;margin-bottom:16px;border-radius:4px;">'
        + '<strong>異常なし</strong> — この期間にアラートはありません</div>';
    }

    var html = '<div style="background:#fff3f0;border-left:4px solid #e53935;padding:12px;margin-bottom:16px;border-radius:4px;">'
      + '<strong style="color:#e53935;">アラート（' + alerts.length + '件）</strong><ul style="margin:8px 0 0;padding-left:20px;">';

    alerts.forEach(function (a) {
      var color = a.severity === 'danger' ? '#e53935' : '#f57c00';
      html += '<li style="color:' + color + ';margin-bottom:4px;">' + Utils.escapeHtml(a.message) + '</li>';
    });

    html += '</ul></div>';
    return html;
  }

  // --- 2. スタッフ別実績テーブル ---
  function renderStaffPerformance(performance) {
    if (!performance) return '';

    var hasHandy = false;
    performance.forEach(function (p) { if (p.totalOrders > 0) hasHandy = true; });

    var html = '<div class="report-section"><h3 class="report-section__title">スタッフ別実績</h3>';

    if (!hasHandy) {
      html += '<p style="color:#888;font-size:0.85rem;margin-bottom:8px;">※ ハンディ注文データがないため注文数は0です</p>';
    }

    html += '<div class="card"><table class="report-table"><thead><tr>'
      + '<th>スタッフ</th><th>役職</th><th>注文数</th><th>売上</th><th>客単価</th><th>キャンセル率</th><th>満足度</th>'
      + '</tr></thead><tbody>';

    performance.forEach(function (p) {
      var roleLabel = p.role === 'manager' ? 'マネージャー' : (p.role === 'owner' ? 'オーナー' : 'スタッフ');
      var cancelStyle = p.cancelRate >= 20 ? ' style="color:#e53935;font-weight:bold;"' : '';
      var ratingVal = '-';
      var ratingStyle = '';
      if (p.avgRating !== null) {
        ratingVal = p.avgRating + ' (' + p.ratingCount + '件)';
        if (p.avgRating < 3.0) ratingStyle = ' style="color:#e53935;"';
        else if (p.avgRating >= 4.0) ratingStyle = ' style="color:#43a047;"';
      }

      html += '<tr>'
        + '<td>' + Utils.escapeHtml(p.displayName) + '</td>'
        + '<td>' + roleLabel + '</td>'
        + '<td>' + p.totalOrders + '件</td>'
        + '<td>' + Utils.formatYen(p.totalRevenue) + '</td>'
        + '<td>' + Utils.formatYen(p.avgOrderValue) + '</td>'
        + '<td' + cancelStyle + '>' + p.cancelRate + '%</td>'
        + '<td' + ratingStyle + '>' + ratingVal + '</td>'
        + '</tr>';
    });

    html += '</tbody></table></div></div>';
    return html;
  }

  // --- 3. キャンセル分析 ---
  function renderCancelAnalysis(cancelDetails) {
    if (!cancelDetails || cancelDetails.length === 0) {
      return '<div class="report-section"><h3 class="report-section__title">キャンセル分析</h3>'
        + '<div class="empty-state"><p class="empty-state__text">キャンセルデータなし</p></div></div>';
    }

    // 棒グラフ
    var maxCancel = Math.max.apply(null, cancelDetails.map(function (c) { return c.cancelCount; }));
    var html = '<div class="report-section"><h3 class="report-section__title">キャンセル分析</h3>'
      + '<div class="card"><ul class="bar-chart">';

    cancelDetails.forEach(function (c) {
      var pct = maxCancel > 0 ? Math.round(c.cancelCount / maxCancel * 100) : 0;
      html += '<li class="bar-row">'
        + '<span class="bar-row__label">' + Utils.escapeHtml(c.displayName) + '</span>'
        + '<span class="bar-row__track"><span class="bar-row__fill' + (c.cancelCount >= 3 ? ' bar-row__fill--warning' : '') + '" style="width:' + pct + '%"></span></span>'
        + '<span class="bar-row__value">' + c.cancelCount + '件</span>'
        + '</li>';
    });

    html += '</ul></div>';

    // キャンセル理由テーブル
    var hasReasons = false;
    cancelDetails.forEach(function (c) {
      if (Object.keys(c.reasons).length > 0) hasReasons = true;
    });

    if (hasReasons) {
      html += '<div class="card" style="margin-top:8px;"><table class="report-table"><thead><tr>'
        + '<th>スタッフ</th><th>理由</th><th>件数</th>'
        + '</tr></thead><tbody>';

      cancelDetails.forEach(function (c) {
        var reasons = c.reasons;
        var keys = Object.keys(reasons);
        // 上位5件
        keys.sort(function (a, b) { return reasons[b] - reasons[a]; });
        keys.slice(0, 5).forEach(function (reason, idx) {
          html += '<tr>'
            + '<td>' + (idx === 0 ? Utils.escapeHtml(c.displayName) : '') + '</td>'
            + '<td>' + Utils.escapeHtml(reason) + '</td>'
            + '<td>' + reasons[reason] + '件</td>'
            + '</tr>';
        });
      });

      html += '</tbody></table></div>';
    }

    html += '</div>';
    return html;
  }

  // --- 4. レジ差異 ---
  function renderRegisterDiscrepancies(discrepancies) {
    if (!discrepancies || discrepancies.length === 0) {
      return '<div class="report-section"><h3 class="report-section__title">レジ差異</h3>'
        + '<div class="empty-state"><p class="empty-state__text">レジデータなし</p></div></div>';
    }

    var html = '<div class="report-section"><h3 class="report-section__title">レジ差異</h3>'
      + '<div class="card"><table class="report-table"><thead><tr>'
      + '<th>日付</th><th>開店者</th><th>閉店者</th><th>予想残高</th><th>実際残高</th><th>差異</th>'
      + '</tr></thead><tbody>';

    discrepancies.forEach(function (d) {
      var rowStyle = d.isAlert ? ' style="background:#fff3f0;"' : '';
      var overshortText = '-';
      var overshortStyle = '';
      if (d.overshort !== null) {
        var sign = d.overshort >= 0 ? '+' : '';
        overshortText = sign + Utils.formatYen(d.overshort);
        if (d.overshort < 0) overshortStyle = ' style="color:#e53935;font-weight:bold;"';
        else if (d.overshort > 0) overshortStyle = ' style="color:#1565c0;font-weight:bold;"';
      }

      html += '<tr' + rowStyle + '>'
        + '<td>' + Utils.escapeHtml(d.date) + '</td>'
        + '<td>' + Utils.escapeHtml(d.openedBy || '-') + '</td>'
        + '<td>' + Utils.escapeHtml(d.closedBy || '-') + '</td>'
        + '<td>' + Utils.formatYen(d.expectedBalance) + '</td>'
        + '<td>' + (d.closeAmount !== null ? Utils.formatYen(d.closeAmount) : '-') + '</td>'
        + '<td' + overshortStyle + '>' + overshortText + '</td>'
        + '</tr>';
    });

    html += '</tbody></table></div></div>';
    return html;
  }

  // --- 5. 深夜帯操作ログ ---
  function renderLateNightOps(lateNightOps) {
    var html = '<div class="report-section"><h3 class="report-section__title">深夜帯操作（23:00〜5:00）</h3>';

    if (!lateNightOps || lateNightOps.length === 0) {
      html += '<div class="empty-state"><p class="empty-state__text">深夜帯の操作はありません</p></div>';
      html += '</div>';
      return html;
    }

    html += '<div class="card"><table class="report-table"><thead><tr>'
      + '<th>日時</th><th>スタッフ</th><th>操作</th><th>理由</th>'
      + '</tr></thead><tbody>';

    var actionLabels = {
      'order_cancel': '注文キャンセル',
      'cash_out': '出金',
      'settings_update': '設定変更'
    };

    lateNightOps.forEach(function (op) {
      html += '<tr>'
        + '<td>' + Utils.escapeHtml(op.createdAt) + '</td>'
        + '<td>' + Utils.escapeHtml(op.username) + '</td>'
        + '<td>' + (actionLabels[op.action] || Utils.escapeHtml(op.action)) + '</td>'
        + '<td>' + (op.reason ? Utils.escapeHtml(op.reason) : '-') + '</td>'
        + '</tr>';
    });

    html += '</tbody></table></div></div>';
    return html;
  }

  return { init: init, load: load };
})();
