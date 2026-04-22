/**
 * POSLA Offline Snapshot (PWA Phase 3 / 2026-04-20)
 *
 * 業務端末 (KDS / レジ / ハンディ) の主要 GET API レスポンスを、通信不安定時の
 * read-only な参考表示のために localStorage に退避する共通ヘルパ。
 *
 * 要件 (PWA Phase 3 §A):
 *   - 最後に正常取得した GET レスポンスを scope / storeId / channel 単位で保存
 *   - 保存先は localStorage。quota 例外は握りつぶす (業務を止めない)
 *   - TTL 12 時間。期限超過の snapshot は load 時に自動削除
 *   - JSON.stringify 後のサイズが 1.5MB 超なら save を諦める
 *   - storeId が取れない場合は保存しない (tenant 混在防止)
 *   - 顧客セルフメニュー public/customer/ では early return (PWA 化対象外)
 *
 * 保存内容: { scope, storeId, channel, savedAt(ms), data }
 * Key 形式: posla_snapshot::<scope>::<storeId>::<channel>
 *
 * 禁止 (設計上):
 *   - POST/PATCH/DELETE のリクエストキュー ... オフライン注文/会計の再送は一切しない
 *   - title / body / 顧客コメント等 通知 payload の保存 ... 本ヘルパは業務データ専用
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal/.repeat/.padStart/.includes 不使用
 */
var OfflineSnapshot = (function () {
  'use strict';

  var KEY_PREFIX = 'posla_snapshot::';
  var TTL_MS = 12 * 60 * 60 * 1000; // 12h
  var MAX_SIZE = 1500000;           // 約 1.5MB (JSON.stringify 後)

  function _isCustomerScope() {
    var p = (location && location.pathname) || '';
    return p.indexOf('/public/customer/') !== -1 || p.indexOf('/customer/') !== -1;
  }

  function _storage() {
    try { return window.localStorage; } catch (e) { return null; }
  }

  function _key(scope, storeId, channel) {
    return KEY_PREFIX + scope + '::' + storeId + '::' + channel;
  }

  /**
   * @return boolean 保存できたら true / quota や size 超過などで諦めたら false
   */
  function save(scope, storeId, channel, data) {
    if (_isCustomerScope()) return false;
    if (!scope || !storeId || !channel) return false;
    var ls = _storage();
    if (!ls) return false;

    var payload = {
      scope: String(scope),
      storeId: String(storeId),
      channel: String(channel),
      savedAt: Date.now(),
      data: (typeof data === 'undefined' ? null : data)
    };

    var json;
    try { json = JSON.stringify(payload); }
    catch (e) { return false; }

    if (!json || json.length > MAX_SIZE) return false;

    try {
      ls.setItem(_key(scope, storeId, channel), json);
      return true;
    } catch (e) {
      // quota など。保存を諦めて業務処理は続行
      return false;
    }
  }

  /**
   * @return {null|{scope,storeId,channel,savedAt,data}}
   *   未保存 / TTL 切れ / parse 失敗のときは null。TTL 切れや破損は自動削除する。
   */
  function load(scope, storeId, channel) {
    if (_isCustomerScope()) return null;
    if (!scope || !storeId || !channel) return null;
    var ls = _storage();
    if (!ls) return null;

    var key = _key(scope, storeId, channel);
    var raw = null;
    try { raw = ls.getItem(key); } catch (e) { return null; }
    if (!raw) return null;

    var obj = null;
    try { obj = JSON.parse(raw); }
    catch (e) {
      try { ls.removeItem(key); } catch (e2) {}
      return null;
    }
    if (!obj || typeof obj.savedAt !== 'number') {
      try { ls.removeItem(key); } catch (e2) {}
      return null;
    }
    if (Date.now() - obj.savedAt > TTL_MS) {
      try { ls.removeItem(key); } catch (e2) {}
      return null;
    }
    return obj;
  }

  function clear(scope, storeId, channel) {
    var ls = _storage();
    if (!ls) return;
    try { ls.removeItem(_key(scope, storeId, channel)); } catch (e) {}
  }

  /**
   * scope 配下の全 snapshot を削除 (店舗切替・ログアウト・デバッグ用)。
   */
  function clearScope(scope) {
    var ls = _storage();
    if (!ls || !scope) return;
    var prefix = KEY_PREFIX + scope + '::';
    try {
      var keys = [];
      for (var i = 0; i < ls.length; i++) {
        var k = ls.key(i);
        if (k && k.indexOf(prefix) === 0) keys.push(k);
      }
      for (var j = 0; j < keys.length; j++) {
        try { ls.removeItem(keys[j]); } catch (e2) {}
      }
    } catch (e) {}
  }

  function formatSavedAt(ts) {
    if (!ts) return '';
    var d = new Date(ts);
    var hh = d.getHours();
    var mm = d.getMinutes();
    var hhStr = hh < 10 ? ('0' + hh) : String(hh);
    var mmStr = mm < 10 ? ('0' + mm) : String(mm);
    return hhStr + ':' + mmStr;
  }

  return {
    save: save,
    load: load,
    clear: clear,
    clearScope: clearScope,
    formatSavedAt: formatSavedAt
  };
})();
