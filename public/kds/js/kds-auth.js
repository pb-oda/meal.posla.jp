/**
 * KDS認証ヘルパー
 * KDS画面のログイン状態確認・店舗選択
 */
var KdsAuth = (function () {
  'use strict';

  var _user = null;
  var _stores = [];
  var _selectedStoreId = null;

  function init() {
    return fetch('../../api/auth/me.php', { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) throw new Error('空のレスポンス');
          try { return JSON.parse(body); }
          catch (e) { throw new Error('認証エラー'); }
        });
      })
      .then(function (json) {
        if (!json.ok) {
          window.location.href = '../admin/index.html';
          return Promise.reject(new Error('未認証'));
        }
        _user = json.data.user;
        _stores = json.data.stores || [];

        // 店舗復元
        var saved = localStorage.getItem('mt_kds_store');
        if (saved && _stores.some(function (s) { return s.id === saved; })) {
          _selectedStoreId = saved;
        } else if (_stores.length > 0) {
          _selectedStoreId = _stores[0].id;
        }

        return { user: _user, stores: _stores, storeId: _selectedStoreId };
      });
  }

  function setStore(storeId) {
    _selectedStoreId = storeId;
    localStorage.setItem('mt_kds_store', storeId);
  }

  function getStoreId() { return _selectedStoreId; }
  function getUser() { return _user; }
  function getStores() { return _stores; }

  return {
    init: init,
    setStore: setStore,
    getStoreId: getStoreId,
    getUser: getUser,
    getStores: getStores
  };
})();
