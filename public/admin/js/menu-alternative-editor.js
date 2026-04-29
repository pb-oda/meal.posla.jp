/**
 * 品切れ時の代替提案設定
 *
 * 「この商品が売り切れたら、この候補を出す」を店舗単位で設定する。
 * ES5 IIFE。
 */
var MenuAlternativeEditor = (function () {
  'use strict';

  var _container = null;
  var _items = [];
  var _alternatives = [];

  function esc(value) {
    return (window.Utils && Utils.escapeHtml) ? Utils.escapeHtml(value) : String(value || '');
  }

  function init(container) {
    _container = container;
    if (!_container) return;
    _container.addEventListener('change', function (e) {
      if (e.target && e.target.id === 'menu-alt-source') {
        fillSourceAlternatives(e.target.value);
      }
    });
    _container.addEventListener('click', function (e) {
      var saveBtn = e.target.closest('[data-alt-save]');
      if (saveBtn) {
        save();
        return;
      }
      var editBtn = e.target.closest('[data-alt-edit]');
      if (editBtn) {
        selectSource(editBtn.getAttribute('data-alt-edit'));
        return;
      }
      var deleteBtn = e.target.closest('[data-alt-delete]');
      if (deleteBtn) {
        deleteSource(deleteBtn.getAttribute('data-alt-delete'));
      }
    });
  }

  function itemKey(item) {
    return String(item.source || 'template') + ':' + String(item.itemId || '');
  }

  function parseKey(key) {
    var parts = String(key || '').split(':');
    return { source: parts[0] || 'template', itemId: parts.slice(1).join(':') };
  }

  function itemLabel(item) {
    var label = (item.categoryName ? item.categoryName + ' / ' : '') + (item.name || item.nameEn || item.itemId);
    if (item.soldOut) label += '（売切中）';
    return label;
  }

  function findItemByKey(key) {
    for (var i = 0; i < _items.length; i++) {
      if (itemKey(_items[i]) === key) return _items[i];
    }
    return null;
  }

  function buildSelect(id, selectedKey, emptyLabel) {
    var html = '<select id="' + id + '" class="menu-alt-select">';
    html += '<option value="">' + esc(emptyLabel || '未選択') + '</option>';
    for (var i = 0; i < _items.length; i++) {
      var key = itemKey(_items[i]);
      html += '<option value="' + esc(key) + '"' + (key === selectedKey ? ' selected' : '') + '>'
        + esc(itemLabel(_items[i])) + '</option>';
    }
    html += '</select>';
    return html;
  }

  function groupedAlternatives() {
    var grouped = {};
    for (var i = 0; i < _alternatives.length; i++) {
      var row = _alternatives[i];
      var key = String(row.source_type || 'template') + ':' + String(row.source_item_id || '');
      if (!grouped[key]) grouped[key] = [];
      grouped[key].push(row);
    }
    return grouped;
  }

  function render() {
    if (!_container) return;
    if (!_items.length) {
      _container.innerHTML = '<p style="color:#777;margin:0">設定できるメニューがありません。</p>';
      return;
    }
    var html = ''
      + '<style>'
      + '.menu-alt-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr auto;gap:8px;align-items:end;margin-bottom:12px}'
      + '.menu-alt-field label{display:block;font-size:.78rem;color:#666;margin-bottom:4px}'
      + '.menu-alt-select{width:100%;padding:8px;border:1px solid #d0d7de;border-radius:6px;background:#fff}'
      + '.menu-alt-list{width:100%;border-collapse:collapse;font-size:.86rem}'
      + '.menu-alt-list th,.menu-alt-list td{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top}'
      + '.menu-alt-actions{display:flex;gap:6px;flex-wrap:wrap}'
      + '@media(max-width:900px){.menu-alt-grid{grid-template-columns:1fr}.menu-alt-actions{justify-content:flex-start}}'
      + '</style>'
      + '<div class="menu-alt-grid">'
      + '<div class="menu-alt-field"><label>売り切れ元</label>' + buildSelect('menu-alt-source', '', 'メニューを選択') + '</div>'
      + '<div class="menu-alt-field"><label>代替1</label>' + buildSelect('menu-alt-1', '', '未設定') + '</div>'
      + '<div class="menu-alt-field"><label>代替2</label>' + buildSelect('menu-alt-2', '', '未設定') + '</div>'
      + '<div class="menu-alt-field"><label>代替3</label>' + buildSelect('menu-alt-3', '', '未設定') + '</div>'
      + '<button type="button" class="btn btn-primary" data-alt-save="1">保存</button>'
      + '</div>'
      + '<div id="menu-alt-list">' + buildListHtml() + '</div>';
    _container.innerHTML = html;
  }

  function buildListHtml() {
    var grouped = groupedAlternatives();
    var keys = [];
    var key;
    for (key in grouped) {
      if (grouped.hasOwnProperty(key)) keys.push(key);
    }
    if (!keys.length) {
      return '<p style="color:#777;margin:8px 0 0">まだ代替提案は設定されていません。</p>';
    }
    var html = '<table class="menu-alt-list"><thead><tr><th>売り切れ元</th><th>代替候補</th><th>操作</th></tr></thead><tbody>';
    for (var i = 0; i < keys.length; i++) {
      var sourceItem = findItemByKey(keys[i]);
      var altLabels = [];
      for (var ai = 0; ai < grouped[keys[i]].length; ai++) {
        var row = grouped[keys[i]][ai];
        var altKey = String(row.alternative_source || 'template') + ':' + String(row.alternative_item_id || '');
        var altItem = findItemByKey(altKey);
        altLabels.push(altItem ? itemLabel(altItem) : row.alternative_item_id);
      }
      html += '<tr>'
        + '<td>' + esc(sourceItem ? itemLabel(sourceItem) : keys[i]) + '</td>'
        + '<td>' + esc(altLabels.join('、')) + '</td>'
        + '<td><div class="menu-alt-actions">'
        + '<button type="button" class="btn btn-sm btn-outline" data-alt-edit="' + esc(keys[i]) + '">編集</button>'
        + '<button type="button" class="btn btn-sm btn-outline" data-alt-delete="' + esc(keys[i]) + '">削除</button>'
        + '</div></td>'
        + '</tr>';
    }
    html += '</tbody></table>';
    return html;
  }

  function fillSourceAlternatives(sourceKey) {
    var grouped = groupedAlternatives();
    var rows = grouped[sourceKey] || [];
    for (var i = 1; i <= 3; i++) {
      var select = document.getElementById('menu-alt-' + i);
      if (!select) continue;
      var row = rows[i - 1];
      select.value = row ? String(row.alternative_source || 'template') + ':' + String(row.alternative_item_id || '') : '';
    }
  }

  function selectSource(sourceKey) {
    var select = document.getElementById('menu-alt-source');
    if (!select) return;
    select.value = sourceKey;
    fillSourceAlternatives(sourceKey);
    select.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function collectAlternativePayload() {
    var sourceEl = document.getElementById('menu-alt-source');
    if (!sourceEl || !sourceEl.value) return null;
    var source = parseKey(sourceEl.value);
    var alternatives = [];
    var seen = {};
    for (var i = 1; i <= 3; i++) {
      var altEl = document.getElementById('menu-alt-' + i);
      if (!altEl || !altEl.value || seen[altEl.value] || altEl.value === sourceEl.value) continue;
      seen[altEl.value] = true;
      var alt = parseKey(altEl.value);
      alternatives.push({ item_id: alt.itemId, source: alt.source, sort_order: alternatives.length });
    }
    return {
      source_item_id: source.itemId,
      source_type: source.source,
      alternatives: alternatives
    };
  }

  function save() {
    var payload = collectAlternativePayload();
    if (!payload) {
      alert('売り切れ元メニューを選択してください');
      return;
    }
    AdminApi.saveMenuAlternatives(payload).then(function () {
      load();
    }).catch(function (err) {
      alert(err.message || '保存に失敗しました');
    });
  }

  function deleteSource(sourceKey) {
    if (!sourceKey) return;
    if (!confirm('この代替提案を削除しますか？')) return;
    var source = parseKey(sourceKey);
    AdminApi.saveMenuAlternatives({
      source_item_id: source.itemId,
      source_type: source.source,
      alternatives: []
    }).then(function () {
      load();
    }).catch(function (err) {
      alert(err.message || '削除に失敗しました');
    });
  }

  function load() {
    if (!_container) return;
    _container.innerHTML = '<p style="color:#777;margin:0">読み込み中...</p>';
    AdminApi.getMenuAlternatives().then(function (data) {
      _items = data.items || [];
      _alternatives = data.alternatives || [];
      render();
    }).catch(function (err) {
      _container.innerHTML = '<p style="color:#c62828;margin:0">' + esc(err.message || '読み込みに失敗しました') + '</p>';
    });
  }

  return {
    init: init,
    load: load
  };
})();
