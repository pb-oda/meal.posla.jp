/**
 * メニュー整備チェック
 *
 * セルフメニュー品質に直結する不足項目を一覧化する。
 * ES5 IIFE。
 */
var MenuQualityCheck = (function () {
  'use strict';

  var _container = null;

  function esc(value) {
    return (window.Utils && Utils.escapeHtml) ? Utils.escapeHtml(value) : String(value || '');
  }

  function init(container) {
    _container = container;
    if (!_container) return;
    _container.addEventListener('click', function (e) {
      if (e.target.closest('[data-quality-reload]')) load();
    });
  }

  function renderSummary(summary, labels) {
    var keys = ['missing_photo', 'missing_desc', 'missing_allergens', 'missing_tags', 'missing_price'];
    var html = '<div class="menu-quality-summary">'
      + '<div class="menu-quality-card"><strong>' + esc(summary.needsAttention || 0) + '</strong><span>要確認 / ' + esc(summary.total || 0) + '品</span></div>';
    for (var i = 0; i < keys.length; i++) {
      html += '<div class="menu-quality-card"><strong>' + esc(summary[keys[i]] || 0) + '</strong><span>' + esc(labels[keys[i]] || keys[i]) + '</span></div>';
    }
    html += '</div>';
    return html;
  }

  function renderIssues(item, labels) {
    var issues = item.issues || [];
    if (!issues.length) return '<span class="quality-badge quality-badge--ok">OK</span>';
    var html = '';
    for (var i = 0; i < issues.length; i++) {
      html += '<span class="quality-badge">' + esc(labels[issues[i]] || issues[i]) + '</span>';
    }
    return html;
  }

  function render(data) {
    var summary = data.summary || {};
    var labels = data.issueLabels || {};
    var items = data.items || [];
    var html = ''
      + '<style>'
      + '.menu-quality-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}'
      + '.menu-quality-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:12px}'
      + '.menu-quality-card{border:1px solid #e0e0e0;border-radius:8px;padding:10px;background:#fff}'
      + '.menu-quality-card strong{display:block;font-size:1.25rem;color:#17324d}'
      + '.menu-quality-card span{display:block;font-size:.75rem;color:#666;margin-top:2px}'
      + '.menu-quality-table{width:100%;border-collapse:collapse;font-size:.86rem}'
      + '.menu-quality-table th,.menu-quality-table td{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top}'
      + '.quality-badge{display:inline-block;margin:0 4px 4px 0;padding:2px 8px;border-radius:999px;background:#fff2e8;color:#b45309;font-size:.72rem;font-weight:700}'
      + '.quality-badge--ok{background:#e8f5e9;color:#2d7a4f}'
      + '@media(max-width:900px){.menu-quality-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}'
      + '</style>'
      + '<div class="menu-quality-head">'
      + '<p style="margin:0;color:#666;font-size:.86rem">写真・説明・アレルゲン・タグ・価格の不足を確認します。</p>'
      + '<button type="button" class="btn btn-sm btn-outline" data-quality-reload="1">再読み込み</button>'
      + '</div>'
      + renderSummary(summary, labels);

    if (!items.length) {
      html += '<p style="color:#777;margin:0">メニューがありません。</p>';
      _container.innerHTML = html;
      return;
    }

    html += '<table class="menu-quality-table"><thead><tr><th>メニュー</th><th>カテゴリ</th><th>価格</th><th>確認項目</th></tr></thead><tbody>';
    for (var i = 0; i < items.length; i++) {
      html += '<tr>'
        + '<td>' + esc(items[i].name || items[i].nameEn || items[i].itemId) + (items[i].soldOut ? ' <span class="quality-badge">売切中</span>' : '') + '</td>'
        + '<td>' + esc(items[i].categoryName || '-') + '</td>'
        + '<td>' + esc((window.Utils && Utils.formatYen) ? Utils.formatYen(items[i].price || 0) : String(items[i].price || 0)) + '</td>'
        + '<td>' + renderIssues(items[i], labels) + '</td>'
        + '</tr>';
    }
    html += '</tbody></table>';
    _container.innerHTML = html;
  }

  function load() {
    if (!_container) return;
    _container.innerHTML = '<p style="color:#777;margin:0">読み込み中...</p>';
    AdminApi.getMenuQuality().then(function (data) {
      render(data || {});
    }).catch(function (err) {
      _container.innerHTML = '<p style="color:#c62828;margin:0">' + esc(err.message || '読み込みに失敗しました') + '</p>';
    });
  }

  return {
    init: init,
    load: load
  };
})();
