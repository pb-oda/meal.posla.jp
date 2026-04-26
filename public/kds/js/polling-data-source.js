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
  // Phase 3: 初回描画判定 + オフライン snapshot 連携用
  var _hasRenderedOnce = false;
  var SNAPSHOT_SCOPE = 'kds';
  var SNAPSHOT_CHANNEL = 'orders';

  // Phase 1 軽量化用: revision が変わらなくても、最大 MAX_GAP_MS ごとには必ず full fetch する。
  // 理由 (これらは revision が変わらないまま動く / 表示更新が必要な機能):
  //   - api/kds/orders.php がコース自動発火 (check_course_auto_fire) を実行する
  //   - 注文カードの経過時間 / 10分・20分警告 (kds-renderer.js の elapsed 計算)
  //   - 低評価アラート (satisfaction_ratings, revisions API では追跡していない)
  //   - KDS ステーション設定変更 (kds_stations / kds_routing_rules も追跡対象外)
  // 30 秒間隔ならコース auto_fire_min (通常 分単位) もほぼ即時、警告表示も ±30 秒精度で十分。
  var MAX_GAP_MS = 30000;
  var _lastFullFetchAt = 0;

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
    // 強制 full fetch: 最後の full fetch から MAX_GAP_MS 以上経過していたら gate を bypass
    //   コース自動発火 / 経過時間警告 / 低評価アラート / ステーション設定 を停止させないため
    var now = Date.now();
    if (now - _lastFullFetchAt >= MAX_GAP_MS) {
      _doPoll();
      return;
    }

    // Phase 1 軽量化: 重い orders.php を叩く前に revision で変更有無を確認
    // 失敗時 (RevisionGate 未ロード / API エラー) は必ず full fetch にフォールバック
    if (typeof RevisionGate !== 'undefined' && _storeId) {
      RevisionGate.shouldFetch('kds_orders', _storeId).then(function (should) {
        if (should) _doPoll();
      });
    } else {
      _doPoll();
    }
  }

  function _doPoll() {
    _lastFullFetchAt = Date.now();
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
          _onError((window.Utils && Utils.formatError) ? Utils.formatError(json) : ((json.error && json.error.message) || 'エラー'));
          return;
        }
        if (_onData) _onData(json.data);
        _hasRenderedOnce = true;
        // Phase 3: 正常取得を snapshot に退避 + stale 解除
        if (typeof OfflineSnapshot !== 'undefined' && _storeId) {
          OfflineSnapshot.save(SNAPSHOT_SCOPE, _storeId, SNAPSHOT_CHANNEL, json.data);
        }
        if (typeof OfflineStateBanner !== 'undefined') {
          OfflineStateBanner.setLastSuccessAt(Date.now());
          OfflineStateBanner.markFresh();
        }
      })
      .catch(function (err) {
        _onError(err.message || 'ポーリングエラー');
        // U-3: オフライン時はポーリング間隔を延長
        if (typeof OfflineDetector !== 'undefined' && !OfflineDetector.isOnline()) {
          _switchInterval(_offlinePollMs);
        }
        // Phase 3: 初回失敗時のみ snapshot から復元。既存表示がある場合は残す
        if (!_hasRenderedOnce && typeof OfflineSnapshot !== 'undefined' && _storeId) {
          var snap = OfflineSnapshot.load(SNAPSHOT_SCOPE, _storeId, SNAPSHOT_CHANNEL);
          if (snap && snap.data) {
            if (_onData) _onData(snap.data);
            _hasRenderedOnce = true;
            if (typeof OfflineStateBanner !== 'undefined') {
              OfflineStateBanner.setLastSuccessAt(snap.savedAt);
              OfflineStateBanner.markStale();
            }
            return;
          }
        }
        // 既存表示がある場合は markStale で stale バナーを出す
        if (_hasRenderedOnce && typeof OfflineStateBanner !== 'undefined') {
          OfflineStateBanner.markStale();
        } else if (typeof OfflineStateBanner !== 'undefined') {
          OfflineStateBanner.showFetchError();
        }
      });
  }

  function restart(storeId, stationId) {
    stop();
    start(storeId, _onData, _onError, stationId, _view);
  }

  function forceRefresh() {
    // gate を bypass して必ず即時取得（操作直後の反映保証）
    if (typeof RevisionGate !== 'undefined' && _storeId) {
      RevisionGate.invalidate('kds_orders', _storeId);
    }
    _doPoll();
  }

  // Phase 3: 通信復帰直後に呼ぶ。必ず本体 API を叩いて stale を解除する。
  function resetStaleState() {
    if (typeof RevisionGate !== 'undefined' && _storeId) {
      RevisionGate.invalidate('kds_orders', _storeId);
    }
    _doPoll();
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
    forceRefresh: forceRefresh,
    resetStaleState: resetStaleState
  };
})();
