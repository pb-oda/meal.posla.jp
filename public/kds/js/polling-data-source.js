/**
 * ポーリングデータソース
 * DataSource抽象化でPhase 2 WebSocket移行準備
 *
 * Sprint A-3: station_id 対応
 */
var PollingDataSource = (function () {
  'use strict';

  var _interval = null;
  var _onData = null;
  var _onError = null;
  var _storeId = null;
  var _stationId = null;
  var _view = 'kitchen';
  var _pollMs = 3000;
  var _defaultPollMs = 3000;
  var _offlinePollMs = 30000;

  function start(storeId, onData, onError, stationId, view) {
    _storeId = storeId;
    _stationId = stationId || '';
    _view = view || 'kitchen';
    _onData = onData;
    _onError = onError || function () {};

    poll(); // 即座に1回
    _interval = setInterval(poll, _pollMs);
  }

  function stop() {
    if (_interval) {
      clearInterval(_interval);
      _interval = null;
    }
  }

  function poll() {
    var url = '../../api/kds/orders.php?store_id=' + encodeURIComponent(_storeId) + '&view=' + _view;
    if (_stationId) url += '&station_id=' + encodeURIComponent(_stationId);

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) {
          return r.text().then(function (body) {
            throw new Error('HTTP ' + r.status + ': ' + (body.substring(0, 200) || 'empty response'));
          });
        }
        return r.text().then(function (body) {
          if (!body) throw new Error('サーバーが空のレスポンスを返しました（PHPエラーの可能性）');
          try { return JSON.parse(body); }
          catch (e) { throw new Error('JSON解析エラー: ' + body.substring(0, 200)); }
        });
      })
      .then(function (json) {
        if (!json.ok) {
          _onError((json.error && json.error.message) || 'エラー');
          return;
        }
        if (_onData) _onData(json.data);
      })
      .catch(function (err) {
        _onError(err.message || 'ポーリングエラー');
        // U-3: オフライン時はポーリング間隔を延長
        if (typeof OfflineDetector !== 'undefined' && !OfflineDetector.isOnline()) {
          _switchInterval(_offlinePollMs);
        }
      });
  }

  function restart(storeId, stationId) {
    stop();
    start(storeId, _onData, _onError, stationId, _view);
  }

  function forceRefresh() {
    poll();
  }

  // U-3: ポーリング間隔切替
  function _switchInterval(ms) {
    if (_pollMs === ms || !_interval) return;
    _pollMs = ms;
    clearInterval(_interval);
    _interval = setInterval(poll, _pollMs);
  }

  // オンライン復帰でポーリング間隔を元に戻す + 即時リフレッシュ
  if (typeof OfflineDetector !== 'undefined') {
    OfflineDetector.onStatusChange(function (online) {
      if (online && _interval) {
        _switchInterval(_defaultPollMs);
        poll();
      }
    });
  }

  return {
    start: start,
    stop: stop,
    restart: restart,
    forceRefresh: forceRefresh
  };
})();
