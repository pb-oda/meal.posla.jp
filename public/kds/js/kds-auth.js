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

  // ── GPS チェック (device ロールは常に強制) ──

  function _haversine(lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLng = (lng2 - lng1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function _getStoreGps(storeId) {
    for (var i = 0; i < _stores.length; i++) {
      if (_stores[i].id === storeId) {
        return {
          gpsRequired: _stores[i].gpsRequired || 0,
          storeLat: _stores[i].storeLat,
          storeLng: _stores[i].storeLng,
          gpsRadiusMeters: _stores[i].gpsRadiusMeters || 200
        };
      }
    }
    return null;
  }

  /**
   * GPS チェック
   * - device ロール: gps_required に関係なく常に GPS チェック強制
   * - その他ロール: gps_required=1 の場合のみチェック
   * @param {string} storeId
   * @param {Function} onSuccess - GPS OK 時のコールバック
   */
  function checkGps(storeId, onSuccess) {
    var gps = _getStoreGps(storeId);
    if (!gps) { onSuccess(); return; }

    var isDevice = _user && _user.role === 'device';
    // device は常に強制、その他は gps_required=1 の場合のみ
    var shouldCheck = isDevice || gps.gpsRequired === 1;
    if (!shouldCheck || gps.storeLat === null || gps.storeLng === null) {
      onSuccess();
      return;
    }

    _showGpsOverlay('位置情報を確認中...', '#90a4ae');
    if (!navigator.geolocation) {
      _showGpsOverlay('このブラウザは位置情報に対応していません', '#e74c3c', true, storeId, onSuccess);
      return;
    }
    navigator.geolocation.getCurrentPosition(function (pos) {
      var dist = _haversine(gps.storeLat, gps.storeLng, pos.coords.latitude, pos.coords.longitude);
      if (dist <= gps.gpsRadiusMeters) {
        _hideGpsOverlay();
        onSuccess();
      } else {
        _showGpsOverlay(
          '店舗から' + Math.round(dist) + 'm離れています（許容: ' + gps.gpsRadiusMeters + 'm）。店舗圏内でご利用ください。',
          '#e74c3c', true, storeId, onSuccess
        );
      }
    }, function (err) {
      var msg = '位置情報を取得できませんでした';
      if (err.code === 1) msg = '位置情報の取得が拒否されました。ブラウザの設定を確認してください';
      _showGpsOverlay(msg, '#e74c3c', true, storeId, onSuccess);
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
  }

  function _showGpsOverlay(msg, color, showRetry, storeId, onSuccess) {
    var ov = document.getElementById('kds-gps-overlay');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'kds-gps-overlay';
      ov.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;display:flex;align-items:center;justify-content:center;';
      document.body.appendChild(ov);
    }
    var html = '<div style="background:#1e293b;padding:2rem;border-radius:12px;max-width:360px;text-align:center;border:1px solid #334155;">'
      + '<div style="font-size:2rem;margin-bottom:1rem;">📍</div>'
      + '<p style="color:' + (color || '#90a4ae') + ';font-size:0.95rem;margin-bottom:1rem;">' + Utils.escapeHtml(msg || '') + '</p>';
    if (showRetry) {
      html += '<button id="kds-gps-retry" style="padding:0.6rem 1.5rem;background:#ff6f00;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer;">再チェック</button>';
    }
    html += '</div>';
    ov.innerHTML = html;
    ov.style.display = 'flex';
    if (showRetry) {
      document.getElementById('kds-gps-retry').addEventListener('click', function () {
        checkGps(storeId, onSuccess);
      });
    }
  }

  function _hideGpsOverlay() {
    var ov = document.getElementById('kds-gps-overlay');
    if (ov) ov.style.display = 'none';
  }

  return {
    init: init,
    setStore: setStore,
    getStoreId: getStoreId,
    getUser: getUser,
    getStores: getStores,
    checkGps: checkGps
  };
})();
