/**
 * 注文送信（冪等キー + リトライ）
 */
var OrderSender = (function () {
  'use strict';

  var _subSessionId = null; // F-QR1: 個別QR サブセッションID

  function generateIdempotencyKey() {
    var arr = new Uint8Array(16);
    crypto.getRandomValues(arr);
    // S3-#17: ES5 互換 (padStart は古い WebView 非対応)
    return Array.from(arr, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  function send(baseApi, storeId, tableId, cartItems, sessionToken, removedItems, retries, idempotencyKey, memo, allergens) {
    retries = retries || 0;
    var key = idempotencyKey || generateIdempotencyKey();
    var inPlanMode = CartManager && CartManager.isPlanMode && CartManager.isPlanMode();

    var body = {
      store_id: storeId,
      table_id: tableId,
      items: cartItems.map(function (i) {
        var price = inPlanMode ? 0 : i.price;
        var item = { id: i.id, name: i.name, price: price, qty: i.qty };
        if (i.options && i.options.length > 0) {
          item.options = i.options;
          item.basePrice = inPlanMode ? 0 : i.basePrice;
        }
        return item;
      }),
      idempotency_key: key,
      session_token: sessionToken
    };
    if (removedItems && removedItems.length > 0) {
      body.removed_items = removedItems;
    }
    if (memo) {
      body.memo = memo;
    }
    if (allergens && allergens.length > 0) {
      body.items.forEach(function (item) {
        item.allergen_selections = allergens;
      });
    }
    // F-QR1: サブセッションIDを注文に含める
    if (_subSessionId) {
      body.sub_session_id = _subSessionId;
    }

    return fetch(baseApi + '/customer/orders.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
    .then(function (res) {
      return res.text().then(function (text) {
        if (!text) throw { network: true, message: 'サーバーが空のレスポンスを返しました' };
        var json;
        try { json = JSON.parse(text); }
        catch (e) { throw { network: true, message: 'JSON解析エラー: ' + text.substring(0, 200) }; }
        if (!res.ok || json.error) {
          var formatted = (window.Utils && Utils.formatError) ? Utils.formatError(json) : ((json.error && json.error.message) || '注文の送信に失敗しました');
          var err = new Error(formatted);
          err.retriable = res.status >= 500 || res.status === 0;
          // O-3: ラストオーダーエラーを識別可能にする
          if (json.error && json.error.code === 'LAST_ORDER_PASSED') {
            err.lastOrder = true;
          }
          throw err;
        }
        return json;
      });
    })
    .catch(function (err) {
      // Only retry network errors and 5xx; never retry 4xx (bad request, auth, conflict)
      var canRetry = err.network || err.retriable || (err instanceof TypeError);
      if (canRetry && retries < 2) {
        return new Promise(function (resolve) {
          setTimeout(function () {
            resolve(send(baseApi, storeId, tableId, cartItems, sessionToken, removedItems, retries + 1, key, memo, allergens));
          }, 2000);
        });
      }
      // U-3: リトライ全失敗 → localStorage に一時保存
      if (canRetry) {
        _savePendingOrder(baseApi, storeId, tableId, cartItems, sessionToken, removedItems, key, memo, allergens);
      }
      if (!(err instanceof Error)) {
        throw new Error(err.message || '注文の送信に失敗しました');
      }
      throw err;
    });
  }

  // ── U-3: 未送信注文の一時保存・復帰送信 ──

  var PENDING_KEY = 'mt_pending_orders';

  function _savePendingOrder(baseApi, storeId, tableId, cartItems, sessionToken, removedItems, idempotencyKey, memo, allergens) {
    try {
      var pending = JSON.parse(localStorage.getItem(PENDING_KEY) || '[]');
      pending.push({
        baseApi: baseApi,
        storeId: storeId,
        tableId: tableId,
        items: cartItems,
        sessionToken: sessionToken,
        removedItems: removedItems || [],
        idempotencyKey: idempotencyKey,
        memo: memo || null,
        allergens: allergens || [],
        timestamp: Date.now()
      });
      localStorage.setItem(PENDING_KEY, JSON.stringify(pending));
    } catch (e) {
      // localStorage 容量不足等
    }
  }

  function _flushPendingOrders() {
    var pending;
    try {
      pending = JSON.parse(localStorage.getItem(PENDING_KEY) || '[]');
    } catch (e) {
      return;
    }
    if (pending.length === 0) return;

    // 24時間以上前の注文は破棄
    var cutoff = Date.now() - 24 * 60 * 60 * 1000;
    pending = pending.filter(function (p) { return p.timestamp > cutoff; });

    if (pending.length === 0) {
      localStorage.removeItem(PENDING_KEY);
      return;
    }

    var remaining = pending.slice();

    function sendNext(index) {
      if (index >= remaining.length) {
        localStorage.removeItem(PENDING_KEY);
        _showToast('\u4FDD\u5B58\u3057\u3066\u3044\u305F\u6CE8\u6587\u3092\u9001\u4FE1\u3057\u307E\u3057\u305F');
        return;
      }
      var p = remaining[index];
      send(p.baseApi, p.storeId, p.tableId, p.items, p.sessionToken, p.removedItems, 0, p.idempotencyKey, p.memo, p.allergens)
        .then(function () {
          sendNext(index + 1);
        })
        .catch(function () {
          // 送信失敗 → 残りを保存し直して中断
          try {
            localStorage.setItem(PENDING_KEY, JSON.stringify(remaining.slice(index)));
          } catch (e) { /* ignore */ }
        });
    }

    sendNext(0);
  }

  function _showToast(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'success');
      return;
    }
    // 簡易トースト
    var el = document.createElement('div');
    el.textContent = message;
    el.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);'
      + 'background:#333;color:#fff;padding:12px 24px;border-radius:8px;z-index:9999;'
      + 'font-size:14px;opacity:0;transition:opacity 0.3s;';
    document.body.appendChild(el);
    setTimeout(function () { el.style.opacity = '1'; }, 10);
    setTimeout(function () {
      el.style.opacity = '0';
      setTimeout(function () { el.parentNode && el.parentNode.removeChild(el); }, 300);
    }, 3000);
  }

  // オンライン復帰時に未送信注文を自動送信
  if (typeof OfflineDetector !== 'undefined') {
    OfflineDetector.onStatusChange(function (online) {
      if (online) _flushPendingOrders();
    });
  }

  function setSubSessionId(id) { _subSessionId = id; }

  return { send: send, setSubSessionId: setSubSessionId };
})();
