/**
 * POSLA Emergency Register Store (PWA Phase 4 / 2026-04-20)
 *
 * 緊急レジモードで記録した会計を端末内 IndexedDB に保存する ES5 IIFE ラッパ。
 *
 * DB:    posla_emergency_v1
 * Store: emergency_payments (keyPath: localEmergencyPaymentId)
 * Index:
 *   - status    (status 別の高速検索 / 未同期カウント)
 *   - createdAt
 *   - storeId
 *
 * 重要 (設計方針):
 *   - カード番号・有効期限・CVV 相当のフィールドは IndexedDB に保存しない (本ラッパが受け取っても無視)
 *   - 会計本体は必ず IndexedDB。localStorage には件数バッジ程度しか置かない (quota 不安定なため)
 *   - IDB 非対応環境 (プライベートモード一部等) では isSupported() が false を返し、
 *     上位 (EmergencyRegister) が機能を無効化する
 *   - Promise 未対応環境は想定しない (Android Chrome / モダン Chromium 前提)。
 *     ただし callback ベース API なので、Promise 自体は内部で使わない。
 *
 * ES5 互換: const/let/arrow/async-await/template literal/.repeat/.padStart/.includes 不使用
 */
var EmergencyRegisterStore = (function () {
  'use strict';

  var DB_NAME = 'posla_emergency_v1';
  var DB_VERSION = 1;
  var STORE_NAME = 'emergency_payments';
  // IDB に保存してはいけない機密フィールド (万が一混入したら落とす)
  var FORBIDDEN_FIELDS = [
    'cardNumber', 'card_number', 'pan',
    'cardExpiry', 'card_expiry', 'expiry', 'expiration',
    'cvv', 'cvc', 'securityCode', 'security_code',
    'cardholder', 'card_holder'
  ];

  var _db = null;
  var _deviceIdCache = null;

  function isSupported() {
    try { return !!(window.indexedDB); } catch (e) { return false; }
  }

  function _sanitize(record) {
    if (!record || typeof record !== 'object') return record;
    var clean = {};
    var keys = Object.keys(record);
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      // 禁止フィールドは落とす (string 比較なので indexOf で判定)
      var forbidden = false;
      for (var j = 0; j < FORBIDDEN_FIELDS.length; j++) {
        if (k === FORBIDDEN_FIELDS[j]) { forbidden = true; break; }
      }
      if (forbidden) continue;
      clean[k] = record[k];
    }
    return clean;
  }

  function _open(callback) {
    if (!isSupported()) { callback(new Error('indexeddb_not_supported')); return; }
    if (_db) { callback(null, _db); return; }
    var req;
    try { req = indexedDB.open(DB_NAME, DB_VERSION); }
    catch (e) { callback(e); return; }
    req.onupgradeneeded = function (e) {
      var db = e.target.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        var os = db.createObjectStore(STORE_NAME, { keyPath: 'localEmergencyPaymentId' });
        os.createIndex('status', 'status', { unique: false });
        os.createIndex('createdAt', 'createdAt', { unique: false });
        os.createIndex('storeId', 'storeId', { unique: false });
      }
    };
    req.onsuccess = function (e) { _db = e.target.result; callback(null, _db); };
    req.onerror = function (e) {
      callback((e.target && e.target.error) || new Error('idb_open_failed'));
    };
    req.onblocked = function () { callback(new Error('idb_blocked')); };
  }

  function add(record, callback) {
    _open(function (err, db) {
      if (err) { callback(err); return; }
      var clean = _sanitize(record);
      try {
        var tx = db.transaction([STORE_NAME], 'readwrite');
        var os = tx.objectStore(STORE_NAME);
        var req = os.add(clean);
        req.onsuccess = function () { callback(null, clean); };
        req.onerror = function (e) {
          callback((e.target && e.target.error) || new Error('idb_add_failed'));
        };
      } catch (e) { callback(e); }
    });
  }

  function put(record, callback) {
    _open(function (err, db) {
      if (err) { callback(err); return; }
      var clean = _sanitize(record);
      try {
        var tx = db.transaction([STORE_NAME], 'readwrite');
        var os = tx.objectStore(STORE_NAME);
        var req = os.put(clean);
        req.onsuccess = function () { callback(null, clean); };
        req.onerror = function (e) {
          callback((e.target && e.target.error) || new Error('idb_put_failed'));
        };
      } catch (e) { callback(e); }
    });
  }

  function get(localId, callback) {
    _open(function (err, db) {
      if (err) { callback(err); return; }
      try {
        var tx = db.transaction([STORE_NAME], 'readonly');
        var os = tx.objectStore(STORE_NAME);
        var req = os.get(localId);
        req.onsuccess = function () { callback(null, req.result || null); };
        req.onerror = function (e) {
          callback((e.target && e.target.error) || new Error('idb_get_failed'));
        };
      } catch (e) { callback(e); }
    });
  }

  function all(callback) {
    _open(function (err, db) {
      if (err) { callback(err); return; }
      try {
        var tx = db.transaction([STORE_NAME], 'readonly');
        var os = tx.objectStore(STORE_NAME);
        var req = os.getAll();
        req.onsuccess = function () {
          var list = req.result || [];
          // createdAt 新しい順
          list.sort(function (a, b) {
            var ta = (a && a.createdAt) || 0;
            var tb = (b && b.createdAt) || 0;
            return tb - ta;
          });
          callback(null, list);
        };
        req.onerror = function (e) {
          callback((e.target && e.target.error) || new Error('idb_all_failed'));
        };
      } catch (e) { callback(e); }
    });
  }

  function byStatus(status, callback) {
    _open(function (err, db) {
      if (err) { callback(err); return; }
      try {
        var tx = db.transaction([STORE_NAME], 'readonly');
        var os = tx.objectStore(STORE_NAME);
        var idx = os.index('status');
        var req = idx.getAll(IDBKeyRange.only(status));
        req.onsuccess = function () { callback(null, req.result || []); };
        req.onerror = function (e) {
          callback((e.target && e.target.error) || new Error('idb_byStatus_failed'));
        };
      } catch (e) { callback(e); }
    });
  }

  function countByStatus(status, callback) {
    _open(function (err, db) {
      if (err) { callback(err); return; }
      try {
        var tx = db.transaction([STORE_NAME], 'readonly');
        var os = tx.objectStore(STORE_NAME);
        var idx = os.index('status');
        var req = idx.count(IDBKeyRange.only(status));
        req.onsuccess = function () { callback(null, req.result || 0); };
        req.onerror = function (e) {
          callback((e.target && e.target.error) || new Error('idb_count_failed'));
        };
      } catch (e) { callback(e); }
    });
  }

  /**
   * 未同期 + 要確認件数の合算 (Phase 4 レビュー指摘 #6 対応)
   *
   * 含める状態:
   *   - pending_sync  : 未送信
   *   - syncing       : 送信中 (再起動で残り得る)
   *   - failed        : 送信失敗
   *   - conflict      : サーバー到達済だが管理者確認必須
   *   - pending_review: サーバー到達済だが要精査
   *
   * バッジ = 「スタッフが把握すべき緊急会計の総件数」という意味合い。
   * 詳細: returns { total, unsynced, needsReview } で内訳を渡せば UI 側で区別可能。
   */
  function countNeedsAttention(callback) {
    var unsyncedStatuses = ['pending_sync', 'syncing', 'failed'];
    var reviewStatuses   = ['conflict', 'pending_review'];
    var all = unsyncedStatuses.concat(reviewStatuses);
    var counts = {};
    var remaining = all.length;
    var firstErr = null;

    function step(s) {
      countByStatus(s, function (err, cnt) {
        if (err && !firstErr) firstErr = err;
        else if (!err) counts[s] = cnt || 0;
        remaining--;
        if (remaining === 0) {
          var unsynced = 0, review = 0;
          for (var i = 0; i < unsyncedStatuses.length; i++) unsynced += (counts[unsyncedStatuses[i]] || 0);
          for (var j = 0; j < reviewStatuses.length; j++)   review   += (counts[reviewStatuses[j]]   || 0);
          callback(firstErr, {
            total: unsynced + review,
            unsynced: unsynced,
            needsReview: review
          });
        }
      });
    }
    for (var k = 0; k < all.length; k++) step(all[k]);
  }

  // 後方互換: 旧 API 名。既存呼び出しが total だけを期待する callback シグネチャで動くようラップ。
  function countUnsynced(callback) {
    countNeedsAttention(function (err, info) {
      if (err) { callback(err); return; }
      callback(null, info ? info.total : 0);
    });
  }

  function getOrCreateDeviceId() {
    if (_deviceIdCache) return _deviceIdCache;
    var key = 'posla_emergency_device_id';
    try {
      var existing = window.localStorage.getItem(key);
      if (existing) { _deviceIdCache = existing; return existing; }
      var newId = 'dev-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
      window.localStorage.setItem(key, newId);
      _deviceIdCache = newId;
      return newId;
    } catch (e) {
      // localStorage が使えない場合は端末内一意性を諦め、セッションごとにランダム
      _deviceIdCache = 'dev-unknown-' + Math.random().toString(36).slice(2, 10);
      return _deviceIdCache;
    }
  }

  /**
   * localEmergencyPaymentId を端末内一意に生成。
   * 形式: eme-<storeId頭8>-<deviceId頭12>-<tsBase36>-<rand8>
   */
  function generateLocalId(storeId, deviceId) {
    var ts = Date.now().toString(36);
    var rand = Math.floor(Math.random() * 0x100000000).toString(16);
    while (rand.length < 8) rand = '0' + rand;
    var s = (storeId || 'nostore').substring(0, 8);
    var d = (deviceId || 'nodev').substring(0, 12);
    return 'eme-' + s + '-' + d + '-' + ts + '-' + rand;
  }

  return {
    isSupported:          isSupported,
    add:                  add,
    put:                  put,
    get:                  get,
    all:                  all,
    byStatus:             byStatus,
    countByStatus:        countByStatus,
    countUnsynced:        countUnsynced,
    countNeedsAttention:  countNeedsAttention,
    getOrCreateDeviceId:  getOrCreateDeviceId,
    generateLocalId:      generateLocalId,
    STORE_NAME:           STORE_NAME,
    DB_NAME:              DB_NAME
  };
})();
