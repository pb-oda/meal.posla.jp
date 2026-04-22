/**
 * リビジョンゲート（軽量ポーリング判定モジュール）
 *
 * 既存の重いポーリング API を毎回叩く前に、
 * api/store/revisions.php で「直近の変更時刻」を取得し、
 * 前回と同じなら full fetch をスキップする。
 *
 * 使い方:
 *   // 既存の poll() の冒頭に挟む
 *   RevisionGate.shouldFetch('kds_orders', storeId).then(function (should) {
 *     if (should) _doActualPoll();
 *   });
 *
 *   // PATCH/POST 後など即時反映したいときは invalidate
 *   RevisionGate.invalidate('call_alerts', storeId);
 *
 * 設計:
 *   - 失敗時は **必ず true を返す**（既存動作を絶対に壊さない / フェイルオープン）
 *   - revision API のエンドポイントは ../../api/store/revisions.php （相対パス）
 *   - storeId 別にキャッシュ（複数店舗切替対応）
 *   - 初回呼び出し時は必ず true（最新を取りに行く）
 *   - 同じ storeId に複数チャネル並行呼び出しがあれば、内部で 1 リクエストにまとめる（同フレーム内）
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal を使わない
 *
 * 顧客画面では使用しない（顧客側ポーリングは Phase 2 で別途検討）。
 */
var RevisionGate = (function () {
  'use strict';

  // 'storeId|channel' -> 直近の revision 文字列（または null）
  var _cache = {};

  // 同フレーム内のバッチ統合用: 'storeId' -> { channels: {...}, promise: ..., resolved: bool }
  var _pending = {};

  var API_PATH = '../../api/store/revisions.php';

  /**
   * @param {string} channel  - 'kds_orders' | 'call_alerts' 等
   * @param {string} storeId
   * @returns {Promise<boolean>} true = full fetch すべき / false = skip 可能
   */
  function shouldFetch(channel, storeId) {
    if (!channel || !storeId) return Promise.resolve(true);

    return _fetchRevision(channel, storeId).then(function (rev) {
      // null = サーバ失敗 / テーブル未作成 → 既存動作にフォールバック
      if (rev === null) return true;

      var key = storeId + '|' + channel;
      var prev = _cache[key];
      _cache[key] = rev;

      // 初回: prev は undefined → 必ず fetch
      if (typeof prev === 'undefined') return true;

      // 変化があれば fetch
      return prev !== rev;
    }).catch(function () {
      // 例外時もフェイルオープン
      return true;
    });
  }

  /**
   * 次回の shouldFetch で必ず true を返すように該当キャッシュを破棄。
   * 注文/会計/PATCH 等の直後に呼ぶと自端末の即時反映が確実になる。
   */
  function invalidate(channel, storeId) {
    if (!channel || !storeId) return;
    delete _cache[storeId + '|' + channel];
  }

  /**
   * 全キャッシュを破棄。店舗切替時に呼ぶ用途を想定。
   */
  function invalidateAll() {
    _cache = {};
    _pending = {};
  }

  // ── 内部: 同 storeId の並行呼び出しをバッチ統合 ──
  function _fetchRevision(channel, storeId) {
    var batch = _pending[storeId];
    if (batch && !batch.resolved) {
      // 既存の Promise に channel を追加
      batch.channels[channel] = true;
      return batch.promise.then(function (revisions) {
        return revisions ? (revisions[channel] || '') : null;
      });
    }

    // 新規バッチ開始
    var newBatch = { channels: {}, promise: null, resolved: false };
    newBatch.channels[channel] = true;
    _pending[storeId] = newBatch;

    // microtask 1 つ分待ってから fetch（並行呼び出しを統合）
    newBatch.promise = new Promise(function (resolve) {
      // setTimeout(0) で同フレームの並行呼び出しを集約
      setTimeout(function () {
        var channelList = Object.keys(newBatch.channels).join(',');
        var url = API_PATH
          + '?store_id=' + encodeURIComponent(storeId)
          + '&channels=' + encodeURIComponent(channelList);

        fetch(url, { credentials: 'same-origin' })
          .then(function (r) {
            if (!r.ok) { resolve(null); return; }
            return r.text().then(function (t) {
              var json;
              try { json = JSON.parse(t); } catch (e) { resolve(null); return; }
              if (!json || !json.ok || !json.data || !json.data.revisions) {
                resolve(null);
                return;
              }
              resolve(json.data.revisions);
            });
          })
          .catch(function () { resolve(null); });
      }, 0);
    });

    // 解決後は次回 shouldFetch でまた新しいバッチを開始できるようにマーク
    newBatch.promise.then(function () {
      newBatch.resolved = true;
      // ただし pending は次回呼び出しまで保持（同 tick 中は再利用）
      // 次の tick で削除
      setTimeout(function () {
        if (_pending[storeId] === newBatch) delete _pending[storeId];
      }, 0);
    });

    return newBatch.promise.then(function (revisions) {
      // フェイルオープン: revisions 全体取得失敗 OR 該当 channel が null/undefined
      // → null を返す（shouldFetch は null を「判定不能」として true 返却）
      // 旧バグ: revisions[channel] || '' で null を空文字に潰してキャッシュ → 2回目以降 false
      if (!revisions) return null;
      var v = revisions[channel];
      if (v === null || typeof v === 'undefined') return null;
      return v; // 期待: ISO 風 datetime 文字列
    });
  }

  return {
    shouldFetch: shouldFetch,
    invalidate: invalidate,
    invalidateAll: invalidateAll
  };
})();
