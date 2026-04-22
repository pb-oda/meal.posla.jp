/**
 * 満足度・低評価分析レポート (R1)
 *
 * dashboard.html → レポート → 満足度分析 サブタブから呼ばれる ES5 IIFE モジュール。
 * AdminApi.getRatingReport(from, to) → /api/store/rating-report.php を叩いて描画する。
 *
 * 設計方針:
 *   - 低評価は店舗・品目・時間帯改善の参考データ。スタッフ責任を断定しない表現にする。
 *   - innerHTML はテンプレート + Utils.escapeHtml で組み立てる (XSS 防止)。
 *   - データ未収集 / カラム未追加環境でも空表示で壊れないこと。
 */
var RatingReport = (function () {
  'use strict';

  var _container = null;

  function init(container) {
    _container = container;
  }

  function load() {
    if (!_container) return;
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    var range = Utils.getPresetRange('today');
    renderControls(range.from, range.to);
    fetchData(range.from, range.to);
  }

  function renderControls(from, to) {
    _container.innerHTML =
      '<div class="report-presets" id="rating-presets">'
      + '<button class="btn btn-outline active" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline" data-preset="month">今月</button>'
      + '</div>'
      + '<div class="report-date-range">'
      + '<input type="date" id="rating-from" value="' + Utils.escapeHtml(from) + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="rating-to" value="' + Utils.escapeHtml(to) + '">'
      + '<button class="btn btn-sm btn-primary" id="btn-rating-load">表示</button>'
      + '</div>'
      + '<p style="color:#666;font-size:0.8rem;margin:4px 0 12px;">満足度評価の集計です。低評価は品目・時間帯・店舗運営の改善にご活用ください。スタッフ個人の評価ではありません。</p>'
      + '<div id="rating-data"></div>';

    var presets = document.getElementById('rating-presets');
    presets.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var r = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('rating-from').value = r.from;
      document.getElementById('rating-to').value = r.to;
      var btns = presets.querySelectorAll('.btn');
      for (var i = 0; i < btns.length; i++) btns[i].classList.remove('active');
      btn.classList.add('active');
      fetchData(r.from, r.to);
    });

    document.getElementById('btn-rating-load').addEventListener('click', function () {
      fetchData(
        document.getElementById('rating-from').value,
        document.getElementById('rating-to').value
      );
    });
  }

  function fetchData(from, to) {
    var dataEl = document.getElementById('rating-data');
    if (dataEl) dataEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';

    function showErr(msg) {
      if (dataEl) {
        dataEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">データ取得エラー: '
          + Utils.escapeHtml(msg || 'unknown') + '</p></div>';
      }
    }

    // AdminApi.getRatingReport が未定義 (古い admin-api.js キャッシュ等) でも
    // スピナーが永遠に止まらない問題を防ぐため、同期チェック + try/catch でガード
    if (!AdminApi || typeof AdminApi.getRatingReport !== 'function') {
      showErr('画面が古い可能性があります。ブラウザを再読込してください。');
      return;
    }

    var p;
    try {
      p = AdminApi.getRatingReport(from, to);
    } catch (e) {
      showErr((e && e.message) || '同期呼び出しエラー');
      return;
    }
    if (!p || typeof p.then !== 'function') {
      showErr('レスポンスが取得できませんでした');
      return;
    }

    p.then(function (data) {
      renderData(data);
    }).catch(function (err) {
      showErr((err && err.message) || 'unknown');
    });
  }

  function renderData(data) {
    var dataEl = document.getElementById('rating-data');
    if (!dataEl) return;

    var summary = data.summary || {};
    var html = '';

    // ── サマリー ──
    html += '<div class="kpi-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px;">';
    html += _kpiCard('評価件数', summary.total || 0, '件');
    html += _kpiCard('平均評価', (summary.total > 0 ? summary.avg : '-'), '/ 5');
    html += _kpiCard('低評価件数', summary.lowCount || 0, '件 (1〜2)', summary.lowCount > 0 ? '#d9534f' : '');
    html += _kpiCard('低評価率', summary.total > 0 ? (summary.lowRate + '%') : '-', '', summary.lowRate >= 10 ? '#d9534f' : '');
    html += _kpiCard('高評価件数', summary.highCount || 0, '件 (4〜5)', summary.highCount > 0 ? '#28a745' : '');
    html += '</div>';

    if ((summary.total || 0) === 0) {
      html += '<div class="empty-state"><p class="empty-state__text">この期間の満足度評価はまだありません。</p></div>';
      dataEl.innerHTML = html;
      return;
    }

    // ── 低評価理由ランキング ──
    var reasons = data.reasonRanking || [];
    html += '<h3 style="font-size:1rem;margin:18px 0 8px;">低評価の理由ランキング</h3>';
    if (reasons.length === 0) {
      html += '<p style="color:#999;font-size:0.85rem;margin:0 0 12px;">該当期間にまだ理由つきの低評価はありません。</p>';
    } else {
      html += '<table style="width:100%;border-collapse:collapse;font-size:0.875rem;margin-bottom:16px;">';
      html += '<thead><tr style="background:#f0f0f0;text-align:left;">'
        + '<th style="padding:6px 8px;">理由</th>'
        + '<th style="padding:6px 8px;text-align:right;width:80px;">件数</th>'
        + '<th style="padding:6px 8px;text-align:right;width:80px;">割合</th>'
        + '</tr></thead><tbody>';
      for (var i = 0; i < reasons.length; i++) {
        var r = reasons[i];
        html += '<tr style="border-bottom:1px solid #eee;">'
          + '<td style="padding:6px 8px;">' + Utils.escapeHtml(r.label) + '</td>'
          + '<td style="padding:6px 8px;text-align:right;font-weight:600;">' + r.count + '</td>'
          + '<td style="padding:6px 8px;text-align:right;color:#666;">' + r.ratio + '%</td>'
          + '</tr>';
      }
      html += '</tbody></table>';
    }

    // ── 低評価品目ランキング ──
    var items = data.itemRanking || [];
    html += '<h3 style="font-size:1rem;margin:18px 0 8px;">低評価が出た品目ランキング</h3>';
    if (items.length === 0) {
      html += '<p style="color:#999;font-size:0.85rem;margin:0 0 12px;">該当期間に低評価の品目はありません。</p>';
    } else {
      html += '<table style="width:100%;border-collapse:collapse;font-size:0.875rem;margin-bottom:16px;">';
      html += '<thead><tr style="background:#f0f0f0;text-align:left;">'
        + '<th style="padding:6px 8px;">品目</th>'
        + '<th style="padding:6px 8px;text-align:right;width:90px;">低評価数</th>'
        + '<th style="padding:6px 8px;text-align:right;width:80px;">平均評価</th>'
        + '<th style="padding:6px 8px;width:130px;">最終発生</th>'
        + '</tr></thead><tbody>';
      for (var k = 0; k < items.length; k++) {
        var it = items[k];
        html += '<tr style="border-bottom:1px solid #eee;">'
          + '<td style="padding:6px 8px;">' + Utils.escapeHtml(it.name || '不明') + '</td>'
          + '<td style="padding:6px 8px;text-align:right;font-weight:600;color:#d9534f;">' + it.lowCount + '</td>'
          + '<td style="padding:6px 8px;text-align:right;">' + it.avg + '</td>'
          + '<td style="padding:6px 8px;font-size:0.8rem;color:#888;">'
          + Utils.escapeHtml((it.lastAt || '').replace('T', ' ').substring(0, 16)) + '</td>'
          + '</tr>';
      }
      html += '</tbody></table>';
    }

    // ── 時間帯別 ──
    var hourly = data.hourlyLow || [];
    html += '<h3 style="font-size:1rem;margin:18px 0 8px;">時間帯別 低評価</h3>';
    if (hourly.length === 0) {
      html += '<p style="color:#999;font-size:0.85rem;margin:0 0 12px;">該当期間にデータがありません。</p>';
    } else {
      html += '<table style="width:100%;border-collapse:collapse;font-size:0.875rem;margin-bottom:16px;">';
      html += '<thead><tr style="background:#f0f0f0;text-align:left;">'
        + '<th style="padding:6px 8px;width:80px;">時間帯</th>'
        + '<th style="padding:6px 8px;text-align:right;width:80px;">評価件数</th>'
        + '<th style="padding:6px 8px;text-align:right;width:90px;">低評価数</th>'
        + '<th style="padding:6px 8px;text-align:right;width:80px;">低評価率</th>'
        + '</tr></thead><tbody>';
      for (var hi = 0; hi < hourly.length; hi++) {
        var h = hourly[hi];
        html += '<tr style="border-bottom:1px solid #eee;">'
          + '<td style="padding:6px 8px;">' + h.hour + ':00 〜</td>'
          + '<td style="padding:6px 8px;text-align:right;">' + h.totalCount + '</td>'
          + '<td style="padding:6px 8px;text-align:right;color:' + (h.lowCount > 0 ? '#d9534f' : '#333') + ';font-weight:600;">' + h.lowCount + '</td>'
          + '<td style="padding:6px 8px;text-align:right;color:#666;">' + h.lowRate + '%</td>'
          + '</tr>';
      }
      html += '</tbody></table>';
    }

    // ── 直近の低評価一覧 ──
    var recent = data.recentLow || [];
    html += '<h3 style="font-size:1rem;margin:18px 0 8px;">直近の低評価 (最大50件)</h3>';
    if (recent.length === 0) {
      html += '<p style="color:#999;font-size:0.85rem;">該当期間に低評価はありません。</p>';
    } else {
      html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
      html += '<thead><tr style="background:#f0f0f0;text-align:left;">'
        + '<th style="padding:6px 8px;width:130px;">日時</th>'
        + '<th style="padding:6px 8px;">品目</th>'
        + '<th style="padding:6px 8px;width:60px;text-align:center;">評価</th>'
        + '<th style="padding:6px 8px;width:140px;">理由</th>'
        + '<th style="padding:6px 8px;">コメント</th>'
        + '<th style="padding:6px 8px;width:90px;">注文</th>'
        + '</tr></thead><tbody>';
      for (var ri = 0; ri < recent.length; ri++) {
        var rec = recent[ri];
        var ratingBadge = '<span style="display:inline-block;background:#d9534f;color:#fff;padding:2px 6px;border-radius:3px;font-weight:600;">★' + rec.rating + '</span>';
        var reasonCell = rec.reasonLabel ? Utils.escapeHtml(rec.reasonLabel) : '<span style="color:#bbb;">―</span>';
        var commentCell = rec.reasonText ? Utils.escapeHtml(rec.reasonText) : '<span style="color:#bbb;">―</span>';
        html += '<tr style="border-bottom:1px solid #eee;">'
          + '<td style="padding:6px 8px;font-size:0.78rem;color:#666;">'
          + Utils.escapeHtml((rec.createdAt || '').replace('T', ' ').substring(0, 16)) + '</td>'
          + '<td style="padding:6px 8px;">' + Utils.escapeHtml(rec.itemName || '不明') + '</td>'
          + '<td style="padding:6px 8px;text-align:center;">' + ratingBadge + '</td>'
          + '<td style="padding:6px 8px;">' + reasonCell + '</td>'
          + '<td style="padding:6px 8px;color:#444;">' + commentCell + '</td>'
          + '<td style="padding:6px 8px;font-family:monospace;font-size:0.78rem;color:#888;">' + Utils.escapeHtml(rec.orderIdShort || '') + '</td>'
          + '</tr>';
      }
      html += '</tbody></table></div>';
    }

    dataEl.innerHTML = html;
  }

  function _kpiCard(title, value, suffix, color) {
    var col = color || '#222';
    return '<div style="background:#fff;border:1px solid #eee;border-radius:6px;padding:10px 12px;">'
      + '<div style="font-size:0.75rem;color:#666;">' + Utils.escapeHtml(title) + '</div>'
      + '<div style="font-size:1.4rem;font-weight:700;color:' + col + ';">'
      + Utils.escapeHtml(String(value)) + ' <span style="font-size:0.7rem;color:#888;font-weight:400;">' + Utils.escapeHtml(suffix || '') + '</span></div>'
      + '</div>';
  }

  return {
    init: init,
    load: load
  };
})();
