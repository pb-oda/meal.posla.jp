/**
 * カートマネージャー（オフラインファースト）
 * localStorage でカートを永続化。セッショントークンでリセット制御。
 *
 * Sprint A-2: オプション/トッピング対応
 *   - options 配列付きアイテムをサポート
 *   - 同一メニューでもオプション構成が異なれば別カートエントリ
 *   - cartKey でエントリを一意識別
 */
var CartManager = (function () {
  'use strict';

  var STORAGE_KEY = 'mt_cart';
  var REMOVED_KEY = 'mt_cart_removed';
  var SESSION_KEY = 'mt_session_token';
  var _storeId = null;
  var _tableId = null;
  var _sessionToken = null;
  var _baseApi = null;
  var _planMode = false;

  function init(storeId, tableId, sessionToken, baseApi) {
    _storeId = storeId;
    _tableId = tableId;
    _sessionToken = sessionToken;
    _baseApi = baseApi || null;

    // セッショントークンが変わっていたらカートリセット
    var savedToken = localStorage.getItem(SESSION_KEY);
    if (savedToken !== sessionToken) {
      localStorage.removeItem(STORAGE_KEY);
      localStorage.removeItem(REMOVED_KEY);
      localStorage.setItem(SESSION_KEY, sessionToken);
    }
  }

  function getCart() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
    } catch (e) {
      return [];
    }
  }

  function saveCart(cart) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
  }

  /**
   * cartKey を生成（同一メニューでもオプション違いは別エントリ）
   *
   * @param {string} menuItemId
   * @param {Array}  options  [{choiceId, ...}, ...]
   * @return {string}
   */
  function generateCartKey(menuItemId, options) {
    if (!options || options.length === 0) return menuItemId;
    var ids = options.map(function (o) { return o.choiceId; }).sort().join(',');
    return menuItemId + '__' + ids;
  }

  /**
   * カートにアイテム追加
   *
   * @param {Object} item
   *   オプションなし: { id, name, price }
   *   オプションあり: { id, name, price, basePrice, options: [{groupId, groupName, choiceId, choiceName, price}] }
   */
  function getRemovedItems() {
    try {
      return JSON.parse(localStorage.getItem(REMOVED_KEY)) || [];
    } catch (e) {
      return [];
    }
  }

  function saveRemovedItems(removed) {
    localStorage.setItem(REMOVED_KEY, JSON.stringify(removed));
  }

  function trackRemoval(item) {
    var removed = getRemovedItems();
    // 同じメニューIDがあれば上書き
    var idx = removed.findIndex(function (r) { return r.id === item.id; });
    var record = { id: item.id, name: item.name, price: item.price, qty: item.qty };
    if (idx >= 0) {
      removed[idx] = record;
    } else {
      removed.push(record);
    }
    saveRemovedItems(removed);
  }

  function sendEvent(action, item) {
    if (!_baseApi) return;
    try {
      var payload = JSON.stringify({
        store_id: _storeId,
        table_id: _tableId,
        session_token: _sessionToken,
        item_id: item.id,
        item_name: item.name,
        item_price: item.price || 0,
        action: action
      });
      if (navigator.sendBeacon) {
        navigator.sendBeacon(_baseApi + '/customer/cart-event.php', new Blob([payload], { type: 'application/json' }));
      }
    } catch (e) { /* ignore */ }
  }

  function addItem(item) {
    var cart = getCart();
    var options = item.options || [];
    var cartKey = generateCartKey(item.id, options);

    var existing = cart.find(function (c) { return (c.cartKey || c.id) === cartKey; });
    if (existing) {
      existing.qty++;
    } else {
      var entry = {
        id: item.id,
        cartKey: cartKey,
        name: item.name,
        price: item.price,
        qty: 1
      };
      if (options.length > 0) {
        entry.options = options;
        entry.basePrice = item.basePrice;
      }
      cart.push(entry);
    }
    // 再追加 → 削除記録から除去（最終的に注文したので「迷い」ではない）
    var removed = getRemovedItems().filter(function (r) { return r.id !== item.id; });
    saveRemovedItems(removed);
    saveCart(cart);
    sendEvent('add', item);
  }

  /**
   * cartKey でインクリメント
   */
  function incrementItem(cartKey) {
    var cart = getCart();
    var item = cart.find(function (c) { return (c.cartKey || c.id) === cartKey; });
    if (item) { item.qty++; saveCart(cart); }
  }

  /**
   * cartKey でデクリメント（0以下で削除）
   */
  function decrementItem(cartKey) {
    var cart = getCart();
    var idx = cart.findIndex(function (c) { return (c.cartKey || c.id) === cartKey; });
    if (idx >= 0) {
      cart[idx].qty--;
      if (cart[idx].qty <= 0) {
        trackRemoval(cart[idx]);
        sendEvent('remove', cart[idx]);
        cart.splice(idx, 1);
      }
      saveCart(cart);
    }
  }

  /**
   * cartKey で削除
   */
  function removeItem(cartKey) {
    var cart = getCart();
    var item = cart.find(function (c) { return (c.cartKey || c.id) === cartKey; });
    if (item) {
      trackRemoval(item);
      sendEvent('remove', item);
    }
    cart = cart.filter(function (c) { return (c.cartKey || c.id) !== cartKey; });
    saveCart(cart);
  }

  /**
   * メニューアイテムIDの合計数量（オプション違い全て合算）
   * メニュー一覧のバッジ表示用
   */
  function getItemQty(menuItemId) {
    return getCart()
      .filter(function (c) { return c.id === menuItemId; })
      .reduce(function (sum, c) { return sum + c.qty; }, 0);
  }

  function clearCart() {
    localStorage.removeItem(STORAGE_KEY);
    localStorage.removeItem(REMOVED_KEY);
  }

  function getSessionToken() {
    return _sessionToken;
  }

  function setPlanMode(flag) {
    _planMode = !!flag;
  }

  function isPlanMode() {
    return _planMode;
  }

  return {
    init: init,
    getCart: getCart,
    addItem: addItem,
    incrementItem: incrementItem,
    decrementItem: decrementItem,
    removeItem: removeItem,
    getItemQty: getItemQty,
    clearCart: clearCart,
    getRemovedItems: getRemovedItems,
    getSessionToken: getSessionToken,
    generateCartKey: generateCartKey,
    setPlanMode: setPlanMode,
    isPlanMode: isPlanMode
  };
})();
