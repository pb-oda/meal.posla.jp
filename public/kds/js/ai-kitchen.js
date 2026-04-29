/**
 * AIキッチンダッシュボード（ai-kitchen.js）
 *
 * KDS画面に「AIシェフ」ダッシュボードパネルを追加する。
 * AIが料理品目単位で調理優先順位を指示する。
 * キッチンは「テーブル単位」ではなく「料理単位」で動く設計思想。
 *
 * ES5 IIFE / DOM自己挿入パターン
 */
var AiKitchen = (function () {
  'use strict';

  // ── 定数 ──
  var AI_API_URL    = '../../api/kds/ai-kitchen.php';
  var ORDERS_API_URL = '../../api/kds/orders.php';
  var REFRESH_INTERVAL = 60000; // 60秒
  var PANEL_ID = 'ai-kitchen-panel';
  var BTN_ID   = 'btn-ai-kitchen';

  // ── 状態 ──
  var _isOpen      = false;
  var _isLoading   = false;
  var _timer       = null;
  var _available   = null; // null=未確認, true/false

  // ── スタイル注入 ──
  function _injectStyles() {
    var style = document.createElement('style');
    style.textContent = ''
      // パネル本体
      + '#' + PANEL_ID + ' {'
      + '  position: fixed; bottom: 0; left: 0; right: 0;'
      + '  height: 0; overflow: hidden;'
      + '  background: #1a2535;'
      + '  border-top: 2px solid rgba(255,255,255,0.15);'
      + '  z-index: 900;'
      + '  transition: height 0.3s ease;'
      + '  display: flex; flex-direction: column;'
      + '  font-family: "Hiragino Kaku Gothic ProN","Noto Sans JP",sans-serif;'
      + '  color: #eee;'
      + '}'
      + '#' + PANEL_ID + '.aik-open { height: 40vh; }'

      // ヘッダー
      + '.aik-header {'
      + '  display: flex; align-items: center; justify-content: space-between;'
      + '  padding: 8px 16px; background: #0f1923;'
      + '  border-bottom: 1px solid rgba(255,255,255,0.1);'
      + '  flex-shrink: 0;'
      + '}'
      + '.aik-header__title { font-size: 1rem; font-weight: bold; }'
      + '.aik-header__actions { display: flex; gap: 8px; }'
      + '.aik-header__btn {'
      + '  background: rgba(255,255,255,0.1); color: #eee; border: 1px solid rgba(255,255,255,0.2);'
      + '  padding: 4px 12px; border-radius: 4px; font-size: 0.8rem; cursor: pointer;'
      + '}'
      + '.aik-header__btn:hover { background: rgba(255,255,255,0.2); }'

      // コンテンツ
      + '.aik-body {'
      + '  flex: 1; overflow-y: auto; padding: 12px 16px;'
      + '  display: flex; flex-direction: column; gap: 10px;'
      + '}'

      // セクション（urgent / next / wait）
      + '.aik-section { border-radius: 6px; padding: 10px 14px; }'
      + '.aik-section--urgent { background: #2a1a1a; border-left: 4px solid #d32f2f; }'
      + '.aik-section--next   { background: #2a2a1a; border-left: 4px solid #ffa000; }'
      + '.aik-section--wait   { background: rgba(255,255,255,0.04); border-left: 4px solid rgba(255,255,255,0.25); }'
      + '.aik-section__label {'
      + '  font-size: 0.75rem; font-weight: bold; margin-bottom: 6px;'
      + '  text-transform: uppercase; letter-spacing: 1px;'
      + '}'
      + '.aik-section--urgent .aik-section__label { color: #ef9a9a; }'
      + '.aik-section--next   .aik-section__label { color: #ffe082; }'
      + '.aik-section--wait   .aik-section__label { color: rgba(255,255,255,0.5); }'

      // アイテム行
      + '.aik-item {'
      + '  display: flex; align-items: baseline; gap: 8px;'
      + '  padding: 4px 0; font-size: 0.85rem;'
      + '}'
      + '.aik-item__table { font-weight: bold; min-width: 36px; color: #90caf9; }'
      + '.aik-item__name  { font-weight: bold; }'
      + '.aik-item__qty   { color: rgba(255,255,255,0.6); font-size: 0.8rem; }'
      + '.aik-item__reason { color: rgba(255,255,255,0.5); font-size: 0.75rem; margin-left: auto; }'

      // サマリー
      + '.aik-summary {'
      + '  background: rgba(255,255,255,0.05); border-radius: 6px;'
      + '  padding: 10px 14px; font-size: 0.85rem; line-height: 1.6;'
      + '}'
      + '.aik-summary__pace {'
      + '  display: inline-block; padding: 2px 8px; border-radius: 3px;'
      + '  font-size: 0.75rem; font-weight: bold; margin-left: 8px;'
      + '}'
      + '.aik-pace--good   { background: #2e7d32; color: #fff; }'
      + '.aik-pace--normal { background: #f9a825; color: #000; }'
      + '.aik-pace--busy   { background: #d32f2f; color: #fff; }'

      // ローディング・空状態
      + '.aik-loading { text-align: center; padding: 40px 0; color: rgba(255,255,255,0.5); font-size: 0.9rem; }'
      + '.aik-empty   { text-align: center; padding: 40px 0; font-size: 1.1rem; color: rgba(255,255,255,0.6); }'

      // KDSヘッダーのAIシェフボタン
      + '#' + BTN_ID + ' {'
      + '  background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.8);'
      + '  border: 1px solid rgba(255,255,255,0.15); padding: 4px 10px;'
      + '  border-radius: 4px; font-size: 0.8rem; cursor: pointer;'
      + '  white-space: nowrap;'
      + '}'
      + '#' + BTN_ID + ':hover { background: rgba(255,255,255,0.15); }'
      + '#' + BTN_ID + '.aik-active {'
      + '  background: rgba(66,165,245,0.3); border-color: rgba(66,165,245,0.6); color: #fff;'
      + '}'
      + '#' + BTN_ID + '.aik-unavailable {'
      + '  background: rgba(249,168,37,0.18); border-color: rgba(249,168,37,0.55); color: #ffe082;'
      + '}'

      // レスポンシブ
      + '@media (max-width: 1024px) {'
      + '  #' + PANEL_ID + '.aik-open { height: 45vh; }'
      + '  .aik-body { padding: 10px 12px; }'
      + '}'
      + '@media (max-width: 600px) {'
      + '  #' + PANEL_ID + '.aik-open { height: 55vh; }'
      + '  .aik-item { flex-wrap: wrap; }'
      + '  .aik-item__reason { width: 100%; margin-left: 44px; }'
      + '}';
    document.head.appendChild(style);
  }

  // ── パネル DOM 作成 ──
  function _createPanel() {
    var panel = document.createElement('div');
    panel.id = PANEL_ID;
    panel.innerHTML = ''
      + '<div class="aik-header">'
      + '  <div class="aik-header__title">'
      + '\uD83E\uDDD1\u200D\uD83C\uDF73 AI\u30AD\u30C3\u30C1\u30F3\u30C0\u30C3\u30B7\u30E5\u30DC\u30FC\u30C9'
      + '  </div>'
      + '  <div class="aik-header__actions">'
      + '    <button class="aik-header__btn" id="aik-btn-refresh">'
      + '\uD83D\uDD04 \u66F4\u65B0</button>'
      + '    <button class="aik-header__btn" id="aik-btn-close">'
      + '\u2715 \u9589\u3058\u308B</button>'
      + '  </div>'
      + '</div>'
      + '<div class="aik-body" id="aik-body">'
      + '  <div class="aik-empty">'
      + '\u300C\uD83E\uDDD1\u200D\uD83C\uDF73 AI\u30B7\u30A7\u30D5\u300D'
      + '\u30DC\u30BF\u30F3\u3092\u62BC\u3057\u3066\u958B\u59CB'
      + '  </div>'
      + '</div>';
    document.body.appendChild(panel);

    document.getElementById('aik-btn-close').addEventListener('click', _closePanel);
    document.getElementById('aik-btn-refresh').addEventListener('click', function () {
      _fetchAnalysis();
    });
  }

  // ── パネル開閉 ──
  function _openPanel() {
    var panel = document.getElementById(PANEL_ID);
    var btn   = document.getElementById(BTN_ID);
    if (!panel) return;
    panel.classList.add('aik-open');
    if (btn) btn.classList.add('aik-active');
    _isOpen = true;
    if (_available === false) {
      _renderError('AI機能が設定されていません。POSLA管理画面のAPI設定を確認してください。');
      return;
    }
    _fetchAnalysis();
    _startAutoRefresh();
  }

  function _closePanel() {
    var panel = document.getElementById(PANEL_ID);
    var btn   = document.getElementById(BTN_ID);
    if (!panel) return;
    panel.classList.remove('aik-open');
    if (btn) btn.classList.remove('aik-active');
    _isOpen = false;
    _stopAutoRefresh();
  }

  function _togglePanel() {
    if (_isOpen) { _closePanel(); }
    else { _openPanel(); }
  }

  // ── 自動更新 ──
  function _startAutoRefresh() {
    _stopAutoRefresh();
    _timer = setInterval(function () {
      if (_isOpen && !_isLoading) _fetchAnalysis();
    }, REFRESH_INTERVAL);
  }

  function _stopAutoRefresh() {
    if (_timer) { clearInterval(_timer); _timer = null; }
  }

  // ── store_id 取得 ──
  function _getStoreId() {
    if (typeof KdsAuth !== 'undefined' && KdsAuth.getStoreId) {
      return KdsAuth.getStoreId() || '';
    }
    return '';
  }

  // ── KDS注文APIから品目リストを取得 ──
  function _fetchOrdersAndAnalyze() {
    var storeId = _getStoreId();
    if (!storeId) {
      _renderError('店舗が選択されていません');
      _isLoading = false;
      return;
    }

    var url = ORDERS_API_URL + '?store_id=' + encodeURIComponent(storeId) + '&view=kitchen';
    fetch(url, { credentials: 'same-origin' })
      .then(function (res) { return res.text(); })
      .then(function (text) {
        var json;
        try { json = JSON.parse(text); } catch (e) {
          _renderError('注文データの取得に失敗しました');
          _isLoading = false;
          return;
        }
        if (!json.ok) {
          var errMsg = (json.error && json.error.message) || '注文データの取得に失敗しました';
          _renderError(errMsg);
          _isLoading = false;
          return;
        }

        var orders = (json.data && json.data.orders) || [];
        var items = _expandItems(orders);
        _sendToAi(storeId, items);
      })
      .catch(function (err) {
        _renderError('通信エラー: ' + err.message);
        _isLoading = false;
      });
  }

  // ── 注文を料理品目単位に展開 ──
  function _expandItems(orders) {
    var items = [];
    var now = Date.now();

    for (var i = 0; i < orders.length; i++) {
      var o = orders[i];
      var status = o.status || '';

      // pending・preparing のみ対象
      if (status !== 'pending' && status !== 'preparing') continue;

      var elapsed = 0;
      if (o.created_at) {
        var ct = new Date(o.created_at).getTime();
        if (ct > 0) elapsed = Math.round((now - ct) / 1000);
      }

      var tableCode = o.table_code || o.tableCode || '?';
      var orderId   = o.id || '';

      // items 配列を展開
      var orderItems = o.items;
      if (typeof orderItems === 'string') {
        try { orderItems = JSON.parse(orderItems); } catch (e) { orderItems = []; }
      }
      if (!orderItems || !orderItems.length) continue;

      for (var j = 0; j < orderItems.length; j++) {
        var it = orderItems[j];
        items.push({
          order_id:        orderId,
          table_code:      tableCode,
          item_name:       it.name || it.menuName || '不明',
          item_id:         it.id || it.menuItemId || '',
          qty:             it.qty || it.quantity || 1,
          status:          status,
          elapsed_seconds: elapsed
        });
      }
    }
    return items;
  }

  // ── AI分析APIに送信 ──
  function _sendToAi(storeId, items) {
    var payload = {
      store_id: storeId,
      items:    items
    };

    fetch(AI_API_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.text();
    }).then(function (text) {
      var json;
      try { json = JSON.parse(text); } catch (e) {
        _renderError('AIレスポンスの解析に失敗しました');
        return;
      }
      if (!json.ok) {
        var errMsg = (json.error && json.error.message) || 'エラーが発生しました';
        if (json.error && json.error.code === 'AI_NOT_CONFIGURED') {
          _markUnavailable();
        }
        _renderError(errMsg);
        return;
      }
      _renderResult(json.data);
    }).catch(function (err) {
      _renderError('通信エラー: ' + err.message);
    }).then(function () {
      _isLoading = false;
    });
  }

  // ── メインの分析実行 ──
  function _fetchAnalysis() {
    if (_isLoading) return;
    _isLoading = true;

    var body = document.getElementById('aik-body');
    if (body) {
      body.innerHTML = '<div class="aik-loading">'
        + '\u2699\uFE0F AI\u304C\u5206\u6790\u4E2D\u2026</div>';
    }

    _fetchOrdersAndAnalyze();
  }

  // ── 結果描画 ──
  function _renderResult(data) {
    var body = document.getElementById('aik-body');
    if (!body) return;

    var html = '';

    // 品目なし
    if ((!data.urgent || !data.urgent.length) &&
        (!data.next || !data.next.length) &&
        (!data.waiting || !data.waiting.length)) {
      html += '<div class="aik-empty">'
        + '\u73FE\u5728\u8ABF\u7406\u4E2D\u306E\u54C1\u76EE\u306F\u3042\u308A\u307E\u305B\u3093 '
        + '\uD83D\uDC68\u200D\uD83C\uDF73 \u6E96\u5099\u4E07\u7AEF\uFF01</div>';
      body.innerHTML = html;
      return;
    }

    // ⚡ 今すぐ着手
    if (data.urgent && data.urgent.length) {
      html += '<div class="aik-section aik-section--urgent">';
      html += '<div class="aik-section__label">'
        + '\u26A1 \u4ECA\u3059\u3050\u7740\u624B</div>';
      for (var i = 0; i < data.urgent.length; i++) {
        html += _renderItem(data.urgent[i]);
      }
      html += '</div>';
    }

    // 🔜 次に着手
    if (data.next && data.next.length) {
      html += '<div class="aik-section aik-section--next">';
      html += '<div class="aik-section__label">'
        + '\uD83D\uDD1C \u6B21\u306B\u7740\u624B</div>';
      for (var j = 0; j < data.next.length; j++) {
        html += _renderItem(data.next[j]);
      }
      html += '</div>';
    }

    // ⏸ 待機中
    if (data.waiting && data.waiting.length) {
      html += '<div class="aik-section aik-section--wait">';
      html += '<div class="aik-section__label">'
        + '\u23F8 \u5F85\u6A5F\u4E2D</div>';
      for (var w = 0; w < data.waiting.length; w++) {
        html += _renderItem(data.waiting[w]);
      }
      html += '</div>';
    }

    // サマリー
    html += '<div class="aik-summary">';
    if (data.summary) {
      html += '\uD83D\uDCAC ' + _escapeHtml(data.summary);
    }
    var paceClass = 'aik-pace--' + (data.pace_status || 'normal');
    var paceLabel = data.pace_status === 'good'
      ? '\u2705 \u826F\u597D'
      : data.pace_status === 'busy'
        ? '\uD83D\uDD34 \u6DF7\u96D1'
        : '\uD83D\uDFE1 \u901A\u5E38';
    html += '<br>\uD83D\uDCCA \u30DA\u30FC\u30B9\uFF1A'
      + '<span class="aik-summary__pace ' + paceClass + '">'
      + paceLabel + '</span>';
    html += '</div>';

    body.innerHTML = html;
  }

  function _renderItem(item) {
    var table  = _escapeHtml(item.table_code || item.table || '?');
    var name   = _escapeHtml(item.item_name  || item.item  || '?');
    var qty    = parseInt(item.qty, 10) || 1;
    var reason = _escapeHtml(item.reason || '');

    return '<div class="aik-item">'
      + '<span class="aik-item__table">' + table + '</span>'
      + '<span class="aik-item__name">' + name + '</span>'
      + '<span class="aik-item__qty">'
      + '\u00D7' + qty + '</span>'
      + (reason
        ? '<span class="aik-item__reason">'
          + '\u300C' + reason + '\u300D</span>'
        : '')
      + '</div>';
  }

  function _renderError(msg) {
    var body = document.getElementById('aik-body');
    if (body) {
      body.innerHTML = '<div class="aik-empty" style="color:#ef9a9a;">'
        + '\u26A0 ' + _escapeHtml(msg) + '</div>';
    }
  }

  // ── AI可用性表示 ──
  function _markUnavailable() {
    var btn = document.getElementById(BTN_ID);
    if (btn) {
      btn.classList.add('aik-unavailable');
      btn.title = 'AI機能が未設定です。押すと状態を確認できます。';
    }
    _available = false;
    _stopAutoRefresh();
  }

  function _markAvailable() {
    var btn = document.getElementById(BTN_ID);
    if (btn) {
      btn.classList.remove('aik-unavailable');
      btn.title = 'AIキッチンダッシュボードを開きます';
    }
    _available = true;
  }

  // ── APIキー可用性チェック（遅延実行） ──
  function _checkAvailability() {
    var storeId = _getStoreId();
    if (!storeId) {
      // KdsAuth未初期化 → リトライ（最大10回）
      if (!_checkAvailability._retries) _checkAvailability._retries = 0;
      _checkAvailability._retries++;
      if (_checkAvailability._retries < 10) {
        setTimeout(_checkAvailability, 1500);
      }
      return;
    }

    // 空のitemsで送信してAPIキー存在チェック
    fetch(AI_API_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ store_id: storeId, items: [] })
    }).then(function (res) {
      return res.text();
    }).then(function (text) {
      var json;
      try { json = JSON.parse(text); } catch (e) { return; }
      if (!json.ok && json.error && json.error.code === 'AI_NOT_CONFIGURED') {
        _markUnavailable();
      } else {
        _markAvailable();
      }
    }).catch(function () {
      // ネットワークエラー等は無視（ボタンは表示したまま）
    });
  }

  // ── ポーリングデータ受け取り（互換用） ──
  function onPollingData() {
    // 品目リストは独自にAPI取得するため、ここでは何もしない
  }

  // ── エスケープ ──
  function _escapeHtml(str) {
    if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(str);
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
  }

  // ── 初期化 ──
  function init() {
    _injectStyles();
    _createPanel();
    // KdsAuth初期化後にAPIキー可用性チェック
    setTimeout(_checkAvailability, 2000);
  }

  // DOM Ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // ── 公開 API ──
  return {
    onPollingData: onPollingData,
    toggle:        _togglePanel,
    open:          _openPanel,
    close:         _closePanel,
    refresh:       _fetchAnalysis
  };
})();
