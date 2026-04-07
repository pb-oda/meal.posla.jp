/**
 * 店舗セレクター
 *
 * ヘッダーの店舗切替ドロップダウンを管理。
 * 選択変更時に各タブモジュールへ通知。
 */
var StoreSelector = (function () {
  'use strict';

  var _selectEl = null;
  var _stores = [];
  var _role = '';
  var _onChangeCallbacks = [];

  function init(selectEl, stores, role) {
    _selectEl = selectEl;
    _stores = stores || [];
    _role = role;

    render();

    _selectEl.addEventListener('change', function () {
      var storeId = _selectEl.value;
      AdminApi.setCurrentStore(storeId || null);
      localStorage.setItem('mt_selected_store', storeId);
      _onChangeCallbacks.forEach(function (cb) { cb(storeId); });
    });

    // 保存された選択を復元
    var saved = localStorage.getItem('mt_selected_store');
    if (saved && _stores.some(function (s) { return s.id === saved; })) {
      _selectEl.value = saved;
    } else if (_stores.length > 0) {
      _selectEl.value = _stores[0].id;
    }
    AdminApi.setCurrentStore(_selectEl.value || null);
  }

  function render() {
    var html = '';

    // ownerは「全店舗」オプション付き
    if (_role === 'owner') {
      html += '<option value="">全店舗</option>';
    }

    _stores.forEach(function (s) {
      html += '<option value="' + Utils.escapeHtml(s.id) + '">'
            + Utils.escapeHtml(s.name)
            + '</option>';
    });

    _selectEl.innerHTML = html;
  }

  function onChange(cb) {
    _onChangeCallbacks.push(cb);
  }

  function getSelectedStoreId() {
    return _selectEl ? _selectEl.value : null;
  }

  function getStores() {
    return _stores;
  }

  return {
    init: init,
    onChange: onChange,
    getSelectedStoreId: getSelectedStoreId,
    getStores: getStores
  };
})();
