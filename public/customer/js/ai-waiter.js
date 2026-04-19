/**
 * AIウェイター — セルフオーダー画面用チャットボットウィジェット
 *
 * DOM自己挿入型（menu.html に script タグを追加するだけで動作）。
 * ES5 IIFE パターン。外部依存なし。
 */
(function () {
  'use strict';

  // ── 多言語ヘルパー ──
  function _lang() { return window.__posla_lang ? window.__posla_lang() : 'ja'; }
  function _t(key, fallback) { return window.__posla_t ? window.__posla_t(key, fallback) : fallback; }

  // ── 設定 ──
  var API_URL = '../../api/customer/ai-waiter.php';
  var CHECK_URL = '../../api/customer/menu.php';
  var MAX_HISTORY = 10; // 保持する最大往復数

  // ── 状態 ──
  var _storeId = null;
  var _history = [];    // [{role,content}, ...]
  var _isOpen = false;
  var _isSending = false;
  var _available = false;

  // ── 音声入力 ──
  var _micBtn = null;
  var _recognition = null;
  var _isListening = false;
  var _autoSendTimer = null;
  var _prevInputLen = 0;

  // ── DOM参照 ──
  var _fab = null;
  var _panel = null;
  var _messagesEl = null;
  var _inputEl = null;
  var _sendBtn = null;

  // ── 初期化 ──
  function init() {
    // URLからstore_id取得
    var params = new URLSearchParams(window.location.search);
    _storeId = params.get('store_id') || params.get('store');
    if (!_storeId) return;

    // AIキーが設定されているか確認
    checkAvailability();
  }

  function checkAvailability() {
    // ai-waiter.php に ping 的にPOSTして AI_NOT_CONFIGURED が返れば非表示
    fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ store_id: _storeId, message: 'ping', history: [] })
    })
    .then(function (r) { return r.text(); })
    .then(function (text) {
      try {
        var json = JSON.parse(text);
        if (json.ok) {
          _available = true;
          injectUI();
        }
        // ok=false → AI未設定 → ボタン非表示（何もしない）
      } catch (e) { /* parse失敗 → 非表示 */ }
    })
    .catch(function () { /* ネットワークエラー → 非表示 */ });
  }

  // ── UI注入 ──
  function injectUI() {
    injectStyles();
    createFab();
    createPanel();
  }

  function injectStyles() {
    var css = ''
      + '.aiw-fab{position:fixed;bottom:72px;right:12px;z-index:9000;'
      + 'width:56px;height:56px;border-radius:50%;border:none;'
      + 'background:linear-gradient(135deg,#ff6600,#ff8800);color:#fff;'
      + 'font-size:1.5rem;cursor:pointer;box-shadow:0 4px 16px rgba(255,102,0,.4);'
      + 'display:flex;align-items:center;justify-content:center;'
      + 'transition:transform .2s,box-shadow .2s;-webkit-tap-highlight-color:transparent;}'
      + '.aiw-fab:active{transform:scale(.92);}'
      + '.aiw-fab-label{position:fixed;bottom:56px;right:12px;z-index:9000;'
      + 'text-align:center;width:56px;font-size:.65rem;color:#ff6600;font-weight:700;pointer-events:none;}'
      + '.aiw-panel{position:fixed;bottom:64px;right:12px;width:320px;height:420px;z-index:9100;'
      + 'display:none;flex-direction:column;background:#fff;'
      + 'border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,0.2);overflow:hidden;}'
      + '.aiw-panel.open{display:flex;}'
      + '@media(max-width:400px){.aiw-panel{width:calc(100% - 24px);height:50vh;}}'
      + '.aiw-header{display:flex;align-items:center;justify-content:space-between;'
      + 'padding:12px 16px;background:linear-gradient(135deg,#ff6600,#ff8800);color:#fff;flex-shrink:0;}'
      + '.aiw-header-title{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:6px;}'
      + '.aiw-close{background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;padding:4px 8px;}'
      + '.aiw-messages{flex:1;overflow-y:auto;padding:16px;-webkit-overflow-scrolling:touch;}'
      + '.aiw-msg{margin-bottom:12px;max-width:85%;clear:both;}'
      + '.aiw-msg.ai{float:left;}'
      + '.aiw-msg.user{float:right;}'
      + '.aiw-bubble{padding:10px 14px;border-radius:16px;font-size:.9rem;line-height:1.5;word-break:break-word;}'
      + '.aiw-msg.ai .aiw-bubble{background:#f0f0f0;color:#333;border-bottom-left-radius:4px;}'
      + '.aiw-msg.user .aiw-bubble{background:#ff6600;color:#fff;border-bottom-right-radius:4px;}'
      + '.aiw-suggestions{clear:both;padding-top:4px;}'
      + '.aiw-sug-card{display:flex;align-items:center;justify-content:space-between;'
      + 'padding:10px 12px;margin:6px 0;border-radius:10px;border:1px solid #ffe0cc;background:#fff8f3;}'
      + '.aiw-sug-name{font-size:.88rem;font-weight:600;color:#333;}'
      + '.aiw-sug-price{font-size:.82rem;color:#ff6600;font-weight:700;margin:0 8px;white-space:nowrap;}'
      + '.aiw-sug-btn{padding:6px 14px;border:none;border-radius:20px;'
      + 'background:#ff6600;color:#fff;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap;}'
      + '.aiw-sug-btn:active{background:#e55b00;}'
      + '.aiw-input-wrap{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #eee;flex-shrink:0;background:#fff;}'
      + '.aiw-input{flex:1;padding:10px 14px;border:1px solid #ddd;border-radius:24px;font-size:.9rem;outline:none;}'
      + '.aiw-input:focus{border-color:#ff6600;}'
      + '.aiw-send{width:44px;height:44px;border:none;border-radius:50%;'
      + 'background:#ff6600;color:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;}'
      + '.aiw-send:disabled{background:#ccc;cursor:default;}'
      + '.aiw-typing{display:inline-block;}.aiw-typing span{display:inline-block;width:6px;height:6px;'
      + 'border-radius:50%;background:#999;margin:0 2px;animation:aiwBounce .6s infinite;}'
      + '.aiw-typing span:nth-child(2){animation-delay:.15s;}'
      + '.aiw-typing span:nth-child(3){animation-delay:.3s;}'
      + '@keyframes aiwBounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}'
      + '.aiw-welcome{text-align:center;color:#999;font-size:.85rem;padding:20px 10px;line-height:1.8;}'
      + '.aiw-toast{position:fixed;bottom:140px;left:50%;transform:translateX(-50%);'
      + 'background:#333;color:#fff;padding:8px 20px;border-radius:20px;font-size:.82rem;'
      + 'z-index:9200;opacity:0;transition:opacity .3s;pointer-events:none;}'
      + '.aiw-toast.show{opacity:1;}'
      + '.aiw-mic{width:44px;height:44px;border:none;border-radius:50%;'
      + 'background:#f0f0f0;color:#333;font-size:1.1rem;cursor:pointer;flex-shrink:0;'
      + 'transition:background .2s;}'
      + '.aiw-mic:active{background:#ddd;}'
      + '.aiw-mic.listening{background:#ff3b30;color:#fff;animation:aiwPulse 1s infinite;}'
      + '@keyframes aiwPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.1)}}';
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);
  }

  function createFab() {
    _fab = document.createElement('button');
    _fab.className = 'aiw-fab';
    _fab.innerHTML = '\uD83E\uDD16';
    _fab.setAttribute('aria-label', _t('ask_ai', 'AIに聞く'));
    _fab.addEventListener('click', function () { togglePanel(!_isOpen); });
    document.body.appendChild(_fab);

    var label = document.createElement('div');
    label.className = 'aiw-fab-label';
    label.textContent = _t('ask_ai', 'AIに聞く');
    document.body.appendChild(label);
  }

  function createPanel() {
    _panel = document.createElement('div');
    _panel.className = 'aiw-panel';
    _panel.innerHTML = ''
      + '<div class="aiw-header">'
      + '  <div class="aiw-header-title">\uD83E\uDD16 ' + _t('aiw_title', 'AIウェイター') + '</div>'
      + '  <button class="aiw-close" id="aiw-close">\u2715</button>'
      + '</div>'
      + '<div class="aiw-messages" id="aiw-messages">'
      + '  <div class="aiw-welcome">'
      + '    ' + _t('aiw_welcome', '何でもお気軽にどうぞ！😊') + '<br>'
      + '    ' + _t('aiw_example1', '「辛いものは苦手です」') + '<br>'
      + '    ' + _t('aiw_example2', '「ボリュームある定食は？」') + '<br>'
      + '    ' + _t('aiw_example3', '「おすすめを教えて」')
      + '  </div>'
      + '</div>'
      + '<div class="aiw-input-wrap">'
      + '  <input class="aiw-input" id="aiw-input" type="text" placeholder="' + _t('aiw_placeholder', 'メニューについて質問...') + '" enterkeyhint="send">'
      + '  <button class="aiw-mic" id="aiw-mic" style="display:none">&#x1F3A4;</button>'
      + '  <button class="aiw-send" id="aiw-send">\u25B6</button>'
      + '</div>';
    document.body.appendChild(_panel);

    _messagesEl = document.getElementById('aiw-messages');
    _inputEl = document.getElementById('aiw-input');
    _sendBtn = document.getElementById('aiw-send');
    _micBtn = document.getElementById('aiw-mic');
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    if (!isIOS && (window.SpeechRecognition || window.webkitSpeechRecognition)) {
      _micBtn.style.display = '';
      _micBtn.addEventListener('click', toggleVoice);
    }
    if (isIOS) {
      _inputEl.placeholder = _t('aiw_placeholder', 'メニューについて質問...');
      _inputEl.addEventListener('input', function () {
        var curLen = _inputEl.value.length;
        var delta = curLen - _prevInputLen;
        _prevInputLen = curLen;
        if (delta > 1 && curLen > 0) {
          clearTimeout(_autoSendTimer);
          _autoSendTimer = setTimeout(function () {
            var text = _inputEl.value.trim();
            if (text.length > 0 && !_isSending) {
              sendMessage();
            }
          }, 2000);
        } else {
          clearTimeout(_autoSendTimer);
        }
      });
    }

    document.getElementById('aiw-close').addEventListener('click', function () { togglePanel(false); });
    _sendBtn.addEventListener('click', sendMessage);
    _inputEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.keyCode === 13) {
        e.preventDefault();
        sendMessage();
      }
    });
  }

  // ── パネル開閉 ──
  function togglePanel(open) {
    _isOpen = open;
    if (open) {
      _panel.classList.add('open');
      setTimeout(function () { _inputEl.focus(); }, 100);
    } else {
      _panel.classList.remove('open');
    }
  }

  // ── 音声入力 ──
  function toggleVoice() {
    if (_isListening) {
      stopVoice();
      return;
    }
    startVoice();
  }

  function startVoice() {
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) return;

    // 即時UI変更（onstart を待たない）
    _isListening = true;
    if (_micBtn) _micBtn.classList.add('listening');
    if (_inputEl) _inputEl.placeholder = _t('aiw_placeholder_voice', '🎤 話してください...');

    _recognition = new SpeechRecognition();
    _recognition.lang = 'ja-JP';
    _recognition.interimResults = true;
    _recognition.continuous = false;
    _recognition.maxAlternatives = 1;

    _recognition.onresult = function (event) {
      var transcript = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        transcript += event.results[i][0].transcript;
      }
      if (_inputEl) _inputEl.value = transcript;

      if (event.results[event.results.length - 1].isFinal) {
        stopVoice();
        if (transcript.trim() !== '') {
          sendMessage();
        }
      }
    };

    _recognition.onerror = function (event) {
      stopVoice();
      var errMap = {
        'not-allowed': '\u30DE\u30A4\u30AF\u306E\u4F7F\u7528\u3092\u8A31\u53EF\u3057\u3066\u304F\u3060\u3055\u3044',
        'network': '\u30CD\u30C3\u30C8\u30EF\u30FC\u30AF\u63A5\u7D9A\u3092\u78BA\u8A8D\u3057\u3066\u304F\u3060\u3055\u3044',
        'audio-capture': '\u30DE\u30A4\u30AF\u304C\u898B\u3064\u304B\u308A\u307E\u305B\u3093',
        'service-not-allowed': '\u8A2D\u5B9A\u2192\u30AD\u30FC\u30DC\u30FC\u30C9\u2192\u97F3\u58F0\u5165\u529B\u3092ON\u306B\u3057\u3066\u304F\u3060\u3055\u3044'
      };
      if (event.error === 'service-not-allowed') {
        appendBubble('ai', '\uD83C\uDFA4 \u97F3\u58F0\u5165\u529B\u3092\u4F7F\u3046\u306B\u306F\u3001\u7AEF\u672B\u306E\u8A2D\u5B9A\u304C\u5FC5\u8981\u3067\u3059\u3002\n\n\u300C\u8A2D\u5B9A\u300D\u2192\u300C\u4E00\u822C\u300D\u2192\u300C\u30AD\u30FC\u30DC\u30FC\u30C9\u300D\u2192\u300C\u97F3\u58F0\u5165\u529B\u300D\u3092ON\u306B\u3057\u3066\u304F\u3060\u3055\u3044\u3002\n\n\u8A2D\u5B9A\u5F8C\u3001\u3082\u3046\u4E00\u5EA6\u30DE\u30A4\u30AF\u30DC\u30BF\u30F3\u3092\u62BC\u3057\u3066\u304F\u3060\u3055\u3044\u3002\u30C6\u30AD\u30B9\u30C8\u5165\u529B\u3067\u3082\u3054\u8CEA\u554F\u3044\u305F\u3060\u3051\u307E\u3059\uFF01');
        return;
      }
      var msg = errMap[event.error];
      if (msg) {
        showToast(msg);
      } else if (event.error !== 'no-speech' && event.error !== 'aborted') {
        showToast('\u97F3\u58F0\u8A8D\u8B58\u30A8\u30E9\u30FC: ' + event.error);
      }
    };

    _recognition.onend = function () {
      if (_isListening) {
        stopVoice();
        var text = _inputEl ? _inputEl.value.trim() : '';
        if (text !== '' && !_isSending) {
          sendMessage();
        }
      }
    };

    try {
      _recognition.start();
    } catch (e) {
      stopVoice();
      showToast('\u97F3\u58F0\u8A8D\u8B58\u3092\u958B\u59CB\u3067\u304D\u307E\u305B\u3093\u3067\u3057\u305F');
    }
  }

  function stopVoice() {
    _isListening = false;
    if (_micBtn) _micBtn.classList.remove('listening');
    if (_inputEl) _inputEl.placeholder = _t('aiw_placeholder', 'メニューについて質問...');
    if (_recognition) {
      try { _recognition.stop(); } catch (e) {}
      _recognition = null;
    }
  }

  // ── メッセージ送信 ──
  function sendMessage() {
    if (_isSending) return;
    if (_isListening) stopVoice();
    clearTimeout(_autoSendTimer);
    _prevInputLen = 0;
    var text = _inputEl.value.trim();
    if (text === '') return;

    _inputEl.value = '';
    appendBubble('user', text);
    _history.push({ role: 'user', content: text });

    // 履歴を最大往復数に制限
    while (_history.length > MAX_HISTORY * 2) {
      _history.shift();
    }

    _isSending = true;
    _sendBtn.disabled = true;
    var typingEl = appendTyping();

    fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        store_id: _storeId,
        message: text,
        history: _history.slice(0, -1) // 今回のメッセージは除く（API側で追加される）
      })
    })
    .then(function (r) { return r.text(); })
    .then(function (raw) {
      var json;
      try { json = JSON.parse(raw); } catch (e) { throw new Error('応答の解析に失敗しました'); }
      removeTyping(typingEl);

      if (!json.ok) {
        var errMsg = (window.Utils && Utils.formatError) ? Utils.formatError(json) : ((json.error && json.error.message) || 'エラーが発生しました');
        appendBubble('ai', errMsg);
        return;
      }

      var reply = json.data.message || '';
      var items = json.data.suggested_items || [];

      appendBubble('ai', reply);
      _history.push({ role: 'model', content: reply });

      if (items.length > 0) {
        appendSuggestions(items);
      }
    })
    .catch(function (err) {
      removeTyping(typingEl);
      appendBubble('ai', _t('aiw_error', '通信エラーが発生しました。もう一度お試しください。'));
    })
    .then(function () {
      _isSending = false;
      _sendBtn.disabled = false;
    });
  }

  // ── 吹き出し描画 ──
  function appendBubble(role, text) {
    // ウェルカムメッセージを削除
    var welcome = _messagesEl.querySelector('.aiw-welcome');
    if (welcome) welcome.parentNode.removeChild(welcome);

    var cls = role === 'user' ? 'user' : 'ai';
    var div = document.createElement('div');
    div.className = 'aiw-msg ' + cls;
    var bubble = document.createElement('div');
    bubble.className = 'aiw-bubble';
    bubble.textContent = text;
    div.appendChild(bubble);
    _messagesEl.appendChild(div);
    scrollToBottom();
  }

  function appendTyping() {
    var div = document.createElement('div');
    div.className = 'aiw-msg ai';
    div.innerHTML = '<div class="aiw-bubble"><span class="aiw-typing"><span></span><span></span><span></span></span></div>';
    _messagesEl.appendChild(div);
    scrollToBottom();
    return div;
  }

  function removeTyping(el) {
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  function appendSuggestions(items) {
    var wrap = document.createElement('div');
    wrap.className = 'aiw-suggestions';
    for (var i = 0; i < items.length; i++) {
      (function (item) {
        var card = document.createElement('div');
        card.className = 'aiw-sug-card';
        card.innerHTML = ''
          + '<span class="aiw-sug-name">' + escapeHtml(item.name) + '</span>'
          + '<span class="aiw-sug-price">\u00A5' + formatNumber(item.price) + '</span>'
          + '<button class="aiw-sug-btn">' + _t('add_to_cart', 'カートに追加') + '</button>';
        var btn = card.querySelector('.aiw-sug-btn');
        btn.addEventListener('click', function () {
          addToCart(item);
          btn.textContent = _t('aiw_added', '✓ 追加済み');
          btn.disabled = true;
          btn.style.background = '#4caf50';
        });
        wrap.appendChild(card);
      })(items[i]);
    }
    _messagesEl.appendChild(wrap);
    scrollToBottom();
  }

  // ── カート連動 ──
  function addToCart(item) {
    if (typeof CartManager !== 'undefined' && CartManager.addItem) {
      CartManager.addItem({
        id: item.id,
        name: item.name,
        price: item.price
      });
      // カートバーUI更新（menu.html のIIFE内 updateCartBar は直接呼べないためDOM直接更新）
      refreshCartBar();
      showToast(escapeHtml(item.name) + ' ' + _t('added_to_cart', 'をカートに追加しました'));
    }
  }

  function refreshCartBar() {
    try {
      var cart = CartManager.getCart();
      var count = cart.reduce(function (s, i) { return s + i.qty; }, 0);
      var total = cart.reduce(function (s, i) { return s + i.price * i.qty; }, 0);
      var countEl = document.getElementById('cart-count');
      var totalEl = document.getElementById('cart-total');
      var barEl = document.getElementById('cart-bar');
      if (countEl) countEl.textContent = count;
      if (totalEl && typeof Utils !== 'undefined' && Utils.formatYen) {
        totalEl.textContent = Utils.formatYen(total);
      }
      if (barEl) barEl.style.display = count > 0 ? '' : 'none';
    } catch (e) { /* ignore */ }
  }

  // ── トースト ──
  function showToast(msg) {
    var existing = document.querySelector('.aiw-toast');
    if (existing) existing.parentNode.removeChild(existing);

    var toast = document.createElement('div');
    toast.className = 'aiw-toast';
    toast.innerHTML = msg;
    document.body.appendChild(toast);
    setTimeout(function () { toast.classList.add('show'); }, 10);
    setTimeout(function () {
      toast.classList.remove('show');
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 300);
    }, 2000);
  }

  // ── ユーティリティ ──
  function scrollToBottom() {
    setTimeout(function () {
      _messagesEl.scrollTop = _messagesEl.scrollHeight;
    }, 50);
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatNumber(n) {
    return String(n).replace(/(\d)(?=(\d{3})+$)/g, '$1,');
  }

  // ── 起動 ──
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
