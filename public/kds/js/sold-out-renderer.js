/**
 * 品切れ管理レンダラー（KDS用）
 */
var SoldOutRenderer = (function () {
  'use strict';

  var _container = null;
  var _storeId = null;
  var _pollTimer = null;

  function init(container, storeId) {
    _container = container;
    _storeId = storeId;
    load(storeId);
    startPolling();
  }

  function load(storeId) {
    if (storeId) _storeId = storeId;
    if (!_storeId) return;

    fetch('../../api/kds/sold-out.php?store_id=' + encodeURIComponent(_storeId), { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) throw new Error('空のレスポンス');
          try { return JSON.parse(body); }
          catch (e) { throw new Error('応答の解析に失敗しました'); }
        });
      })
      .then(function (json) {
        if (!json.ok) throw new Error((json.error && json.error.message) || 'エラー');
        render(json.data.categories || []);
      })
      .catch(function (err) {
        _container.innerHTML = '<div class="so-loading">' + Utils.escapeHtml(err.message) + '</div>';
      });
  }

  function render(categories) {
    if (categories.length === 0) {
      _container.innerHTML = '<div class="so-loading">メニューがありません</div>';
      return;
    }

    var html = '';
    categories.forEach(function (cat) {
      html += '<div class="so-category">';
      html += '<div class="so-category__title">' + Utils.escapeHtml(cat.categoryName) + '</div>';
      cat.items.forEach(function (item) {
        var checked = item.soldOut ? ' checked' : '';
        var labelClass = item.soldOut ? 'so-label so-label--out' : 'so-label so-label--ok';
        var labelText = item.soldOut ? '品切れ' : '販売中';
        html += '<div class="so-item">'
          + '<span class="so-item__name">' + Utils.escapeHtml(item.name) + '</span>'
          + '<span class="so-item__price">' + Utils.formatYen(item.price) + '</span>'
          + '<span class="so-item__source">' + (item.source === 'local' ? '限定' : '') + '</span>'
          + '<span class="' + labelClass + '">' + labelText + '</span>'
          + '<label class="so-toggle">'
          + '<input type="checkbox" data-id="' + item.menuItemId + '" data-source="' + item.source + '"' + checked + '>'
          + '<span class="so-toggle__slider"></span>'
          + '</label>'
          + '</div>';
      });
      html += '</div>';
    });
    _container.innerHTML = html;

    // トグルイベント
    _container.querySelectorAll('.so-toggle input').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var el = this;
        el.disabled = true;
        toggleSoldOut(el.dataset.id, el.dataset.source, el.checked)
          .finally(function () { el.disabled = false; });
      });
    });
  }

  function toggleSoldOut(menuItemId, source, isSoldOut) {
    return fetch('../../api/kds/sold-out.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        store_id: _storeId,
        menu_item_id: menuItemId,
        source: source,
        is_sold_out: isSoldOut
      })
    }).then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('空のレスポンス');
        try { return JSON.parse(body); }
        catch (e) { throw new Error('応答の解析に失敗しました'); }
      });
    }).then(function (json) {
      if (!json.ok) throw new Error((json.error && json.error.message) || 'エラー');
      load();
    }).catch(function () {
      load();
    });
  }

  function startPolling() {
    if (_pollTimer) clearInterval(_pollTimer);
    _pollTimer = setInterval(function () { load(); }, 30000);
  }

  return {
    init: init,
    load: load
  };
})();
