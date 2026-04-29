/**
 * KDS 音声コマンダー（コマンドパターン検出方式）
 * Web Speech API continuous モード + コマンドパターンフィルタ
 * タップでON/OFF切替 → 常時リスニング → 主要操作はファストパスで処理
 * 未対応の言い回しだけ、端末ごとの「AI補助ON」時にGeminiへ送信
 *
 * 依存: KdsAuth, KdsRenderer, PollingDataSource, Utils
 * ES5 IIFE パターン
 */
var VoiceCommander = (function () {
  'use strict';

  var _aiConfigured = false;
  var _aiConfigLoaded = false;
  var _recognition = null;
  var _btn = null;
  var _aiFallbackBtn = null;
  var _statusEl = null;
  var _lastOrders = [];
  var _aiFallbackEnabled = false;

  // ── 状態 ──
  // 'off' | 'standby' | 'processing'
  var _state = 'off';
  var _shouldRestart = false;
  var _blinkTimer = null;
  var _interimCmd = null;       // interim で検出したコマンドテキスト
  var _interimTimer = null;     // interim コマンド確定タイマー
  var _menuItems = [];          // 全メニュー [{menuItemId, source, name, soldOut}]
  var _menuLoaded = false;
  var _standbyHint = '🎤 音声待機中（例:「T01完成」「T01残り」「まとめ表示」「戻して」）';
  var _consecutiveErrors = 0;  // 連続エラー回数（バックオフ用）
  var _lastStartTime = 0;
  var _lastResultTime = 0;
  var _lastFinalTime = 0;
  var _lastInterimTime = 0;
  var _lastHardResetTime = 0;
  var _interimOnlySince = 0;
  var _interimStreak = 0;
  var _voiceErrorWindowStartedAt = 0;
  var _noSpeechErrors = 0;
  var _networkErrors = 0;
  var _audioCaptureErrors = 0;
  var _hardResetPending = false;

  // ── コマンドパターン ──
  // テーブル番号パターン（T01, t1, テーブル1, 1番テーブル, 1番 等）
  var _tablePattern = /[TＴｔt]\s*0*\d{1,3}|テーブル\s*\d{1,3}|\d{1,3}\s*番/i;
  // 動作語パターン
  var _actionWords = [
    '調理', '開始', '完成', '提供', '済み', 'すみ',
    'キャンセル', '取消', '取り消', 'できた', 'できました',
    'あがり', '上がり', 'アップ', 'サーブ', 'サーヴ',
    '準備', 'じゅんび', 'ready', 'レディ',
    // 品切れ管理
    '品切れ', '品切', '売り切れ', '売切',
    '販売再開', '再開', '解除', '復活',
    '品切れ確認', '品切れ一覧',
    '品切れ管理',
    // AIキッチンダッシュボード
    'シェフ', 'ダッシュボード', '最新にして',
    // 音声AI補助
    'AI補助', 'AI解析', 'AIフォールバック',
    // テーマ切替
    'ライトモード', 'ダークモード', '明るく', '暗く', 'テーマ',
    // KDS画面操作
    'まとめ', '提供順', 'エクスペディター', '通常', '3列',
    '戻して', '今のなし', '直前', '残り', '未完成', '注意', 'アレルギー',
    '次フェーズ', '次のフェーズ', '全品'
  ];

  var _VOICE_KEY = 'mt_kds_voice_active';
  var _AI_FALLBACK_KEY = 'mt_kds_voice_ai_fallback';

  // ── 初期化 ──
  function init() {
    _loadAiFallbackSetting();
    _createUI();
    _checkAiConfig();
    _loadMenu();
    _loadSensitivity();
    _unlockAudio();
    _setupGlobalAudioUnlock();
    // 前回音声ONだった場合は自動復帰（ページ遷移して戻った時用）
    if (localStorage.getItem(_VOICE_KEY) === '1') {
      _startContinuousListening();
    }
  }

  // ページ上のどこかを最初にタッチ/クリックした時点でオーディオをアンロック
  function _setupGlobalAudioUnlock() {
    function handler() {
      _unlockAudio();
      document.removeEventListener('click', handler, true);
      document.removeEventListener('touchstart', handler, true);
    }
    document.addEventListener('click', handler, true);
    document.addEventListener('touchstart', handler, true);
  }

  function _loadAiFallbackSetting() {
    try {
      _aiFallbackEnabled = localStorage.getItem(_AI_FALLBACK_KEY) === '1';
    } catch (e) {
      _aiFallbackEnabled = false;
    }
  }

  function _setAiFallbackEnabled(enabled) {
    _aiFallbackEnabled = !!enabled;
    try {
      if (_aiFallbackEnabled) localStorage.setItem(_AI_FALLBACK_KEY, '1');
      else localStorage.removeItem(_AI_FALLBACK_KEY);
    } catch (e) {}
    _updateAiFallbackButton();
  }

  function _updateAiFallbackButton() {
    if (!_aiFallbackBtn) return;
    var isLight = document.documentElement.classList.contains('light-theme');
    _aiFallbackBtn.textContent = _aiFallbackEnabled ? 'AI補助ON' : 'AI補助OFF';
    _aiFallbackBtn.title = _aiFallbackEnabled
      ? '未対応の音声コマンドをGeminiで補助解析します'
      : '未対応の音声コマンドをAI解析せず、誤操作を防ぎます';
    _aiFallbackBtn.style.borderColor = _aiFallbackEnabled ? '#42a5f5' : (isLight ? '#bbb' : 'rgba(255,255,255,0.3)');
    _aiFallbackBtn.style.background = _aiFallbackEnabled ? 'rgba(66,165,245,0.18)' : 'none';
    _aiFallbackBtn.style.color = isLight ? (_aiFallbackEnabled ? '#1565c0' : '#555') : '#fff';
  }

  // ── UI生成 ──
  function _createUI() {
    _btn = document.createElement('button');
    _btn.id = 'btn-voice-cmd';
    _btn.className = 'kds-header__link';
    _btn.style.cssText = 'cursor:pointer;background:none;border:2px solid rgba(255,255,255,0.3);border-radius:4px;color:#fff;padding:0.25rem 0.6rem;font-size:0.8rem;display:flex;align-items:center;gap:0.3rem;transition:border-color 0.3s,background 0.3s;';
    _btn.innerHTML = '<span style="font-size:1rem">&#x1F3A4;</span> <span id="voice-cmd-label">音声OFF</span>';
    _btn.title = 'タップで音声コマンドON/OFF';

    _statusEl = document.createElement('div');
    _statusEl.id = 'voice-status';
    _statusEl.style.cssText = 'display:none;position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);background:#263238;color:#fff;padding:0.6rem 1.2rem;border-radius:8px;font-size:0.85rem;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.4);max-width:90vw;text-align:center;';
    document.body.appendChild(_statusEl);

    var headerRight = document.querySelector('.kds-header__right');
    if (headerRight) {
      headerRight.insertBefore(_btn, headerRight.firstChild);
      _aiFallbackBtn = document.createElement('button');
      _aiFallbackBtn.type = 'button';
      _aiFallbackBtn.className = 'kds-header__link';
      _aiFallbackBtn.style.cssText = 'cursor:pointer;background:none;border:2px solid rgba(255,255,255,0.3);border-radius:4px;color:#fff;padding:0.25rem 0.6rem;font-size:0.78rem;font-weight:700;';
      _aiFallbackBtn.addEventListener('click', function (e) {
        e.preventDefault();
        _setAiFallbackEnabled(!_aiFallbackEnabled);
        _showStatus(_aiFallbackEnabled ? '音声AI補助をONにしました' : '音声AI補助をOFFにしました', 2000);
      });
      headerRight.insertBefore(_aiFallbackBtn, _btn.nextSibling);
      _updateAiFallbackButton();
    }

    // クリック（タップ）トグル
    _btn.addEventListener('click', function (e) {
      e.preventDefault();
      _unlockAudio();
      if (_state === 'off') {
        _startContinuousListening();
      } else {
        _stopContinuousListening();
      }
    });
  }

  // ── AI設定確認（POSLA共通設定の有無フラグのみ取得。キー本体はサーバー内に保持） ──
  function _checkAiConfig() {
    var storeId = KdsAuth.getStoreId();
    if (!storeId) return;

    fetch('../../api/store/settings.php?store_id=' + encodeURIComponent(storeId), { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        _aiConfigLoaded = true;
        if (json.ok && json.data && json.data.settings) {
          _aiConfigured = !!json.data.settings.ai_api_key_set;
        }
      })
      .catch(function () {
        _aiConfigLoaded = true;
      });
  }

  // ── メニューデータ読み込み（品切れ管理用） ──
  function _loadMenu() {
    var storeId = KdsAuth.getStoreId();
    if (!storeId) return;

    fetch('../../api/kds/sold-out.php?store_id=' + encodeURIComponent(storeId), { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        _menuLoaded = true;
        if (json.ok && json.data && json.data.categories) {
          _menuItems = [];
          json.data.categories.forEach(function (cat) {
            (cat.items || []).forEach(function (item) {
              _menuItems.push({
                menuItemId: item.menuItemId,
                source: item.source,
                name: item.name,
                soldOut: !!item.soldOut
              });
            });
          });
        }
      })
      .catch(function () {
        _menuLoaded = true;
      });
  }

  // ── 注文データ更新 ──
  function updateOrders(orders) {
    _lastOrders = orders || [];
  }

  // ── コマンドパターン検出 ──
  // 動作語を含む発話をコマンドと判定（テーブル特定はGeminiに委任）
  // Web Speech APIは「T01」等の英数字を日本語モードで正しく認識しないため、
  // テーブル番号の要件は外す
  // ── 品目単位ステータスファストパス（Gemini不要） ──
  var _itemStatusWords = {
    preparing: ['調理', '開始', '準備', 'じゅんび'],
    ready: ['完成', 'できた', 'できました', 'あがり', '上がり', 'アップ', 'レディ', 'ready'],
    served: ['提供', '済み', 'すみ', 'サーブ', 'サーヴ']
  };

  function _detectItemStatusFromText(text) {
    var statuses = ['preparing', 'ready', 'served'];
    for (var i = 0; i < statuses.length; i++) {
      var words = _itemStatusWords[statuses[i]];
      for (var j = 0; j < words.length; j++) {
        if (text.indexOf(words[j]) !== -1) return statuses[i];
      }
    }
    return null;
  }

  function _detectItemCommandFastPath(text) {
    var newStatus = _detectItemStatusFromText(text);
    if (!newStatus) return null;
    var matched = _findMatchingItem(text);
    if (!matched) return null;
    return { item: matched, newStatus: newStatus };
  }

  // 品切れ一覧ファストパス（Gemini不要）
  var _listWords = ['品切れ一覧', '品切れ確認', '今の品切れ', '品切れリスト'];
  var _soldOutNavWords = ['品切れ管理', '品切れ管理画面', '売り切れ管理', '売切管理'];

  function _isListCommand(text) {
    for (var i = 0; i < _listWords.length; i++) {
      if (text.indexOf(_listWords[i]) !== -1) return true;
    }
    return false;
  }

  function _detectSoldOutNavigationFastPath(text) {
    for (var i = 0; i < _soldOutNavWords.length; i++) {
      if (text.indexOf(_soldOutNavWords[i]) !== -1) return true;
    }
    return false;
  }

  function _detectSoldOutToggleFastPath(text) {
    if (_isListCommand(text) || _detectSoldOutNavigationFastPath(text)) return null;
    var isResume = text.indexOf('販売再開') !== -1 || text.indexOf('再開') !== -1 || text.indexOf('解除') !== -1 || text.indexOf('復活') !== -1;
    var isSoldOut = text.indexOf('品切れ') !== -1 || text.indexOf('品切') !== -1 || text.indexOf('売り切れ') !== -1 || text.indexOf('売切') !== -1;
    if (!isResume && !isSoldOut) return null;
    var item = _findMatchingMenuItem(text);
    if (!item) return null;
    return {
      menuItemId: item.menuItemId,
      source: item.source,
      menuName: item.name,
      isSoldOut: isResume ? false : true
    };
  }

  function _executeNavigateSoldOut() {
    _showStatus('品切れ管理画面へ移動します...', 1500);
    try { localStorage.setItem(_VOICE_KEY, '1'); } catch (e) {}
    _shouldRestart = false;
    if (_recognition) { try { _recognition.abort(); } catch (ex) {} }
    setTimeout(function () { window.location.href = 'sold-out.html'; }, 500);
  }

  function _isCommand(text) {
    // テーブル番号がある場合は優先的にコマンド判定
    if (_tablePattern.test(text)) return true;

    // 動作語のみでもコマンドと判定
    for (var i = 0; i < _actionWords.length; i++) {
      if (text.indexOf(_actionWords[i]) !== -1) return true;
    }

    // AIキッチンパネルが開いている場合、「閉じて」「更新」もコマンドとして検出
    if (_isAiKitchenOpen()) {
      if (text.indexOf('閉じて') !== -1 || text.indexOf('更新') !== -1) return true;
    }

    return false;
  }

  // ── AIキッチンダッシュボード音声コマンド ──
  var _aiKitchenWords = ['シェフ', 'ダッシュボード'];

  function _isAiKitchenOpen() {
    var panel = document.getElementById('ai-kitchen-panel');
    return panel && panel.classList.contains('aik-open');
  }

  // テーマ切替ファストパス
  var _darkWords = ['ダークモード', 'ダーク', '暗く', '暗い'];
  var _lightWords = ['ライトモード', 'ライト', '明るく', '明るい', 'テーマ変更'];

  function _detectThemeFastPath(text) {
    var i;
    for (i = 0; i < _darkWords.length; i++) {
      if (text.indexOf(_darkWords[i]) !== -1) return 'dark';
    }
    for (i = 0; i < _lightWords.length; i++) {
      if (text.indexOf(_lightWords[i]) !== -1) return 'light';
    }
    return null;
  }

  // ── ステーション切替ファストパス ──
  function _detectStationFastPath(text) {
    // 「全品目」→ 全品目ボタン
    if (text.indexOf('全品目') !== -1 || text.indexOf('ぜんひんもく') !== -1) return '';
    // ステーションバーのボタンテキストと照合
    var btns = document.querySelectorAll('.kds-station-btn');
    for (var i = 0; i < btns.length; i++) {
      var name = btns[i].textContent.trim();
      if (name && name !== '全品目' && text.indexOf(name) !== -1) {
        return btns[i].dataset.station;
      }
    }
    return null;
  }

  function _executeStationSwitch(stationId) {
    var btns = document.querySelectorAll('.kds-station-btn');
    var target = null;
    for (var i = 0; i < btns.length; i++) {
      if (btns[i].dataset.station === stationId) { target = btns[i]; break; }
    }
    if (target) {
      target.click();
      _beepSuccess();
      _showStatus('ステーション切替: ' + target.textContent.trim(), 2000);
    } else {
      _beepError();
      _showStatus('ステーションが見つかりません', 2000);
    }
    _returnToStandby();
  }

  function _executeThemeSwitch(theme) {
    if (typeof ThemeSwitcher !== 'undefined' && ThemeSwitcher.set) {
      _beepSuccess();
      setTimeout(function () {
        ThemeSwitcher.set(theme);
        _showStatus(theme === 'light' ? '\u2600 ライトモードに切替' : '\uD83C\uDF19 ダークモードに切替', 2000);
        // テーマ変更後にボタン色を再適用
        _setState(_state);
        _updateAiFallbackButton();
      }, 200);
    } else {
      _beepError();
      _showStatus('テーマ切替に失敗しました', 2000);
    }
  }

  function _detectAiFallbackSwitchFastPath(text) {
    var hasKeyword = text.indexOf('AI補助') !== -1 || text.indexOf('AI解析') !== -1 || text.indexOf('AIフォールバック') !== -1;
    if (!hasKeyword) return null;
    if (text.indexOf('オフ') !== -1 || text.indexOf('OFF') !== -1 || text.indexOf('切って') !== -1 || text.indexOf('無効') !== -1) return false;
    if (text.indexOf('オン') !== -1 || text.indexOf('ON') !== -1 || text.indexOf('入れて') !== -1 || text.indexOf('有効') !== -1) return true;
    return null;
  }

  function _executeAiFallbackSwitch(enabled) {
    _setAiFallbackEnabled(enabled);
    _beepSuccess();
    _showStatus(enabled ? '音声AI補助をONにしました' : '音声AI補助をOFFにしました', 2000);
    _speak(enabled ? '音声AI補助をオンにしました' : '音声AI補助をオフにしました');
    _returnToStandby();
  }

  // ── KDS表示モード切替ファストパス ──
  function _detectModeFastPath(text) {
    if (_tablePattern.test(text)) return null;
    if (text.indexOf('まとめ表示') !== -1 || text.indexOf('提供順') !== -1 || text.indexOf('エクスペ') !== -1) return 'expeditor';
    if (text.indexOf('通常表示') !== -1 || text.indexOf('通常モード') !== -1 || text.indexOf('3列') !== -1 || text.indexOf('三列') !== -1) return 'normal';
    return null;
  }

  function _executeModeSwitch(mode) {
    var btn = document.querySelector('[data-kds-mode="' + mode + '"]');
    if (!btn) {
      _beepError();
      _showStatus('表示モードを切り替えられません', 2000);
      _returnToStandby();
      return;
    }
    btn.click();
    _beepSuccess();
    _showStatus(mode === 'expeditor' ? 'まとめ表示に切替' : '通常3列に切替', 2000);
    _speak(mode === 'expeditor' ? 'まとめ表示にしました' : '通常表示にしました');
    _returnToStandby();
  }

  // ── 直前操作Undoファストパス ──
  function _detectUndoFastPath(text) {
    if (text.indexOf('表示') !== -1 || text.indexOf('モード') !== -1) return false;
    var words = ['戻して', '戻す', '今のなし', 'さっきのなし', '直前を戻す', '直前戻して'];
    for (var i = 0; i < words.length; i++) {
      if (text.indexOf(words[i]) !== -1) return true;
    }
    return false;
  }

  function _executeUndoLast() {
    if (!window.KdsRenderer || !KdsRenderer.undoLastAction) {
      _beepError();
      _showStatus('戻せる操作がありません', 2000);
      _returnToStandby();
      return;
    }
    _showStatus('直前操作を戻しています...', 0);
    KdsRenderer.undoLastAction(KdsAuth.getStoreId())
      .then(function () {
        _beepSuccess();
        _showStatus('直前操作を戻しました', 2000);
        _speak('戻しました');
        _returnToStandby();
      })
      .catch(function () {
        _beepError();
        _showStatus('戻せる操作がありません', 2500);
        _speak('戻せる操作がありません');
        _returnToStandby();
      });
  }

  // ── 卓指定ヘルパ ──
  function _extractTableNumber(text) {
    var m = text.match(/[TＴｔt]\s*0*(\d{1,3})/);
    if (m) return String(parseInt(m[1], 10));
    m = text.match(/テーブル\s*0*(\d{1,3})/);
    if (m) return String(parseInt(m[1], 10));
    m = text.match(/0*(\d{1,3})\s*番/);
    if (m) return String(parseInt(m[1], 10));
    return '';
  }

  function _tableCodeMatches(order, text) {
    var code = order.table_code || '';
    if (!code) return false;
    if (text.indexOf(code) !== -1) return true;
    var n = _extractTableNumber(text);
    if (!n) return false;
    var digits = code.replace(/[^\d]/g, '');
    if (!digits) return false;
    return String(parseInt(digits, 10)) === n;
  }

  function _activeOrdersForTableText(text) {
    var rows = [];
    for (var i = 0; i < _lastOrders.length; i++) {
      var order = _lastOrders[i];
      if (!_tableCodeMatches(order, text)) continue;
      if (order.status === 'served' || order.status === 'paid' || order.status === 'cancelled') continue;
      rows.push(order);
    }
    return rows;
  }

  function _activeItems(order) {
    var rows = [];
    var items = order.items || [];
    for (var i = 0; i < items.length; i++) {
      var s = items[i].status || 'pending';
      if (s === 'served' || s === 'cancelled') continue;
      rows.push(items[i]);
    }
    return rows;
  }

  function _detectTableReadFastPath(text) {
    if (!_tablePattern.test(text)) return null;
    if (text.indexOf('残り') !== -1 || text.indexOf('未完成') !== -1 || text.indexOf('何が') !== -1) return 'remaining';
    if (text.indexOf('注意') !== -1 || text.indexOf('アレルギー') !== -1 || text.indexOf('指定') !== -1) return 'notice';
    return null;
  }

  function _noticeTextForOrder(order) {
    var lines = [];
    if (order.memo) lines.push('メモ ' + order.memo);
    var items = order.items || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].allergen_selections) lines.push(items[i].name + ' アレルギーあり');
      if (items[i].options && items[i].options.length > 0) {
        var opts = [];
        for (var j = 0; j < items[i].options.length; j++) {
          opts.push(items[i].options[j].choiceName || items[i].options[j].name || '');
        }
        if (opts.length) lines.push(items[i].name + ' ' + opts.join('、'));
      }
    }
    return lines;
  }

  function _executeTableRead(text, kind) {
    var orders = _activeOrdersForTableText(text);
    if (orders.length === 0) {
      _beepError();
      _showStatus('該当する卓の未完了注文がありません', 2500);
      _speak('未完了注文がありません');
      _returnToStandby();
      return;
    }
    var tableLabel = orders[0].table_code || '卓';
    var lines = [];
    var ready = 0;
    var preparing = 0;
    var pending = 0;
    for (var i = 0; i < orders.length; i++) {
      if (kind === 'notice') {
        lines = lines.concat(_noticeTextForOrder(orders[i]));
        continue;
      }
      var items = _activeItems(orders[i]);
      for (var j = 0; j < items.length; j++) {
        var s = items[j].status || 'pending';
        if (s === 'ready') ready++;
        else if (s === 'preparing') preparing++;
        else pending++;
        lines.push(items[j].name + ' ' + (items[j].qty || 1) + '個 ' + (s === 'ready' ? '提供待ち' : (s === 'preparing' ? '調理中' : '受付')));
      }
    }
    if (lines.length === 0) lines.push(kind === 'notice' ? '注意指定はありません' : '残りはありません');
    var msg = kind === 'notice'
      ? tableLabel + ' 注意: ' + lines.join(' / ')
      : tableLabel + ' 残り' + (ready + preparing + pending) + '品。提供待ち' + ready + '、調理中' + preparing + '、受付' + pending + '。' + lines.join(' / ');
    _beepSuccess();
    _showStatus(msg, 7000);
    _speak(msg);
    _returnToStandby();
  }

  function _detectTableBulkFastPath(text) {
    if (!_tablePattern.test(text)) return null;
    if (text.indexOf('全品') === -1 && text.indexOf('全部') === -1 && text.indexOf('まとめて') === -1) return null;
    var status = _detectItemStatusFromText(text);
    if (!status) return null;
    return status;
  }

  function _executeTableBulk(text, newStatus) {
    var orders = _activeOrdersForTableText(text);
    if (orders.length === 0) {
      _beepError();
      _showStatus('該当する卓の未完了注文がありません', 2500);
      _returnToStandby();
      return;
    }
    var ids = [];
    for (var i = 0; i < orders.length; i++) ids.push(orders[i].id);
    var tableLabel = orders[0].table_code || '卓';
    var labels = { preparing: '調理開始', ready: '完成', served: '提供済み' };
    var statusLabel = labels[newStatus] || newStatus;
    _hideStatus();
    _showConfirmDialog(
      tableLabel + ' の未完了注文 ' + ids.length + '件を全品' + statusLabel + 'にしますか？',
      function () {
        _showStatus(tableLabel + ' 全品' + statusLabel + ' 実行中...', 0);
        KdsRenderer.handleOrderBatch(ids, newStatus, KdsAuth.getStoreId(), tableLabel + ' 全品' + statusLabel)
          .then(function () {
            _beepSuccess();
            _showStatus(tableLabel + ' 全品' + statusLabel + ' 完了', 2500);
            _speak('完了しました');
            _returnToStandby();
          })
          .catch(function () {
            _beepError();
            _showStatus('一括操作に失敗しました', 3000);
            _returnToStandby();
          });
      },
      function () { _showStatus('キャンセルしました', 2000); _returnToStandby(); }
    );
  }

  function _detectTableStatusFastPath(text) {
    if (!_tablePattern.test(text)) return null;
    if (_detectTableReadFastPath(text) || _detectCancelFastPath(text) || _detectCourseAdvanceFastPath(text) || _detectTableBulkFastPath(text)) return null;
    if (_findMatchingItem(text)) return null;
    var status = _detectItemStatusFromText(text);
    if (!status) return null;
    return status;
  }

  function _pickTableOrderForStatus(orders, newStatus) {
    if (!orders || orders.length === 0) return null;
    var preferred = [];
    var i;
    if (newStatus === 'preparing') {
      preferred = ['pending'];
    } else if (newStatus === 'ready') {
      preferred = ['preparing', 'pending'];
    } else if (newStatus === 'served') {
      preferred = ['ready', 'preparing', 'pending'];
    }
    for (var p = 0; p < preferred.length; p++) {
      for (i = 0; i < orders.length; i++) {
        if ((orders[i].status || 'pending') === preferred[p]) return orders[i];
      }
    }
    return orders[0];
  }

  function _executeTableStatus(text, newStatus) {
    var orders = _activeOrdersForTableText(text);
    var order = _pickTableOrderForStatus(orders, newStatus);
    if (!order) {
      _beepError();
      _showStatus('該当する卓の未完了注文がありません', 2500);
      _returnToStandby();
      return;
    }
    var tableLabel = order.table_code || '卓';
    var labels = { preparing: '調理開始', ready: '完成', served: '提供済み' };
    var statusLabel = labels[newStatus] || newStatus;
    _executeStatusUpdate(order.id, newStatus, tableLabel, statusLabel);
  }

  function _detectCancelFastPath(text) {
    if (!_tablePattern.test(text)) return false;
    if (text.indexOf('キャンセル') !== -1 || text.indexOf('取消') !== -1 || text.indexOf('取り消') !== -1) return true;
    return false;
  }

  function _executeCancelOrder(text) {
    var orders = _activeOrdersForTableText(text);
    if (orders.length === 0) {
      _beepError();
      _showStatus('該当する卓の未完了注文がありません', 2500);
      _returnToStandby();
      return;
    }
    var all = text.indexOf('全品') !== -1 || text.indexOf('全部') !== -1 || text.indexOf('まとめて') !== -1;
    var targetOrders = all ? orders : [orders[0]];
    var ids = [];
    for (var i = 0; i < targetOrders.length; i++) ids.push(targetOrders[i].id);
    var tableLabel = targetOrders[0].table_code || '卓';
    var reason = '音声取消: ' + text.substring(0, 80);
    _hideStatus();
    _showConfirmDialog(
      tableLabel + ' の注文 ' + ids.length + '件を取消しますか？',
      function () {
        _showStatus(tableLabel + ' 取消中...', 0);
        KdsRenderer.handleOrderBatch(ids, 'cancelled', KdsAuth.getStoreId(), tableLabel + ' 注文取消', reason)
          .then(function () {
            _beepSuccess();
            _showStatus(tableLabel + ' 注文を取消しました', 2500);
            _speak('取消しました');
            _returnToStandby();
          })
          .catch(function () {
            _beepError();
            _showStatus('取消に失敗しました', 3000);
            _returnToStandby();
          });
      },
      function () { _showStatus('キャンセルしました', 2000); _returnToStandby(); }
    );
  }

  function _detectCourseAdvanceFastPath(text) {
    if (text.indexOf('次フェーズ') !== -1 || text.indexOf('次のフェーズ') !== -1 || text.indexOf('コース進め') !== -1) {
      return true;
    }
    return false;
  }

  function _findCourseTableForText(text) {
    var seen = {};
    for (var i = 0; i < _lastOrders.length; i++) {
      var o = _lastOrders[i];
      if (!o.course_id || !o.table_id) continue;
      if (!_tableCodeMatches(o, text)) continue;
      if (seen[o.table_id]) continue;
      seen[o.table_id] = true;
      return o;
    }
    return null;
  }

  function _executeCourseAdvance(text) {
    var order = _findCourseTableForText(text);
    if (!order) {
      _beepError();
      _showStatus('該当するコース卓が見つかりません', 2500);
      _returnToStandby();
      return;
    }
    var tableLabel = order.table_code || '卓';
    _hideStatus();
    _showConfirmDialog(
      tableLabel + ' を次フェーズに進めますか？',
      function () {
        _showStatus(tableLabel + ' 次フェーズ処理中...', 0);
        KdsRenderer.advancePhase(KdsAuth.getStoreId(), order.table_id)
          .then(function (json) {
            if (json && json.ok) {
              _beepSuccess();
              _showStatus(tableLabel + ' 次フェーズに進めました', 2500);
              _speak('次フェーズに進めました');
            } else {
              _beepError();
              _showStatus('次フェーズ処理に失敗しました', 3000);
            }
            _returnToStandby();
          })
          .catch(function () {
            _beepError();
            _showStatus('次フェーズ処理に失敗しました', 3000);
            _returnToStandby();
          });
      },
      function () { _showStatus('キャンセルしました', 2000); _returnToStandby(); }
    );
  }

  // ── コマンド一覧ファストパス ──
  var _helpWords = ['コマンド一覧', 'コマンドリスト', 'ヘルプ', '使い方', '何ができる'];

  function _detectHelpFastPath(text) {
    for (var i = 0; i < _helpWords.length; i++) {
      if (text.indexOf(_helpWords[i]) !== -1) return true;
    }
    return false;
  }

  function _executeHelp() {
    var existing = document.getElementById('vc-help-overlay');
    if (existing) existing.parentNode.removeChild(existing);

    var overlay = document.createElement('div');
    overlay.id = 'vc-help-overlay';
    overlay.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#263238;color:#fff;padding:1.5rem 2rem;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.6);z-index:10000;max-width:90vw;max-height:80vh;overflow-y:auto;font-size:0.85rem;line-height:1.7;min-width:320px;';

    var html = '<div style="font-size:1.1rem;font-weight:700;margin-bottom:1rem;color:#42a5f5;">音声コマンド一覧</div>'
      + '<div style="margin-bottom:0.75rem;"><span style="color:#4CAF50;font-weight:700;">■ 注文操作</span><br>'
      + '「T01 完成」「T01 調理開始」「T01 全品提供済み」「T01 キャンセル」「戻して」</div>'
      + '<div style="margin-bottom:0.75rem;"><span style="color:#4CAF50;font-weight:700;">■ Expeditor</span><br>'
      + '「まとめ表示」「通常表示」「T01 残り」「T01 注意確認」「T01 次フェーズ」</div>'
      + '<div style="margin-bottom:0.75rem;"><span style="color:#4CAF50;font-weight:700;">■ ステーション切替</span><br>'
      + '「全品目」「ドリンク」（設定したステーション名）</div>'
      + '<div style="margin-bottom:0.75rem;"><span style="color:#4CAF50;font-weight:700;">■ 品切れ管理</span><br>'
      + '「○○ 品切れ」「○○ 販売再開」「品切れ一覧」「品切れ管理」</div>'
      + '<div style="margin-bottom:0.75rem;"><span style="color:#4CAF50;font-weight:700;">■ スタッフ呼び出し</span><br>'
      + '「スタッフ呼んで」「ホール呼んで」</div>'
      + '<div style="margin-bottom:0.75rem;"><span style="color:#4CAF50;font-weight:700;">■ 画面操作</span><br>'
      + '「ライトモード」「ダークモード」<br>'
      + '「下にスクロール」「上にスクロール」</div>'
      + '<div style="margin-bottom:0.75rem;"><span style="color:#4CAF50;font-weight:700;">■ AIシェフ</span><br>'
      + '「シェフ」「ダッシュボード」「最新にして」</div>'
      + '<div style="margin-bottom:0.75rem;"><span style="color:#4CAF50;font-weight:700;">■ 音声AI補助</span><br>'
      + '「AI補助オン」「AI補助オフ」<br>'
      + '<span style="font-size:0.75rem;color:#bbb;">通常はOFF推奨。OFF時は未対応の言い回しをAI解析しません。</span></div>'
      + '<div><span style="color:#4CAF50;font-weight:700;">■ システム</span><br>'
      + '「音声再起動」「コマンド一覧」</div>'
      + '<div style="margin-top:1rem;font-size:0.75rem;color:#999;">タップで閉じる</div>';

    overlay.innerHTML = html;
    document.body.appendChild(overlay);

    overlay.addEventListener('click', function () {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    });
    setTimeout(function () {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    }, 15000);

    _beepSuccess();
    _returnToStandby();
  }

  // ── スクロールファストパス ──
  var _scrollDownWords = ['下にスクロール', 'スクロールダウン', '下スクロール', '下に移動'];
  var _scrollUpWords = ['上にスクロール', 'スクロールアップ', '上スクロール', '上に移動'];

  function _detectScrollFastPath(text) {
    for (var i = 0; i < _scrollDownWords.length; i++) {
      if (text.indexOf(_scrollDownWords[i]) !== -1) return 'down';
    }
    for (var j = 0; j < _scrollUpWords.length; j++) {
      if (text.indexOf(_scrollUpWords[j]) !== -1) return 'up';
    }
    return null;
  }

  function _executeScroll(direction) {
    var amount = Math.round(window.innerHeight * 0.6);
    window.scrollBy({ top: direction === 'down' ? amount : -amount, behavior: 'smooth' });
    _beepSuccess();
    _showStatus(direction === 'down' ? '↓ 下にスクロール' : '↑ 上にスクロール', 1500);
    _returnToStandby();
  }

  // ── スタッフ呼び出しファストパス ──
  // スタッフ呼び出し: 人物 + 動作の組み合わせ検出（助詞「を」「に」等に依存しない）
  var _staffCallPersons = ['スタッフ', 'すたっふ', 'ホール', 'ほーる'];
  var _staffCallActions = ['呼んで', '読んで', 'よんで', '来て', 'きて', 'お願い', 'コール'];
  var _staffCallExact = ['呼び出し', 'よびだし'];

  function _detectStaffCallFastPath(text) {
    var i, p;
    // 単独キーワード
    for (i = 0; i < _staffCallExact.length; i++) {
      if (text.indexOf(_staffCallExact[i]) !== -1) return true;
    }
    // 人物 + 動作の組み合わせ（「スタッフを呼んで」「ホールに来て」等に対応）
    for (p = 0; p < _staffCallPersons.length; p++) {
      if (text.indexOf(_staffCallPersons[p]) !== -1) {
        for (i = 0; i < _staffCallActions.length; i++) {
          if (text.indexOf(_staffCallActions[i]) !== -1) return true;
        }
      }
    }
    return false;
  }

  function _executeStaffCall() {
    // Phase 3 レビュー指摘 #2: offline/stale では POST を叩かない
    if (_voiceIsOfflineOrStale()) {
      _beepError();
      _showStatus('通信が不安定です。通信復帰後に操作してください', 3000);
      _returnToStandby();
      return;
    }

    var storeId = KdsAuth.getStoreId();
    if (!storeId) {
      _showStatus('店舗情報が取得できません', 3000);
      _returnToStandby();
      return;
    }

    _showStatus('スタッフを呼び出し中...', 0);
    fetch('../../api/kds/call-alerts.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ store_id: storeId })
    })
    .then(function (r) {
      return r.text().then(function (b) { return JSON.parse(b); });
    })
    .then(function (json) {
      if (json.ok) {
        _beepSuccess();
        _showStaffCallPopup(true);
      } else {
        _beepError();
        _showStaffCallPopup(false);
      }
      _returnToStandby();
    })
    .catch(function () {
      _beepError();
      _showStaffCallPopup(false);
      _returnToStandby();
    });
  }

  function _showStaffCallPopup(success) {
    var existing = document.getElementById('vc-staffcall-popup');
    if (existing) existing.parentNode.removeChild(existing);

    var el = document.createElement('div');
    el.id = 'vc-staffcall-popup';

    var bg = success ? '#2e7d32' : '#b71c1c';
    var icon = success ? '&#x1F468;&#x200D;&#x1F373;' : '&#x2717;';
    var msg = success ? 'スタッフを呼び出しました' : '呼び出しに失敗しました';

    el.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);'
      + 'background:' + bg + ';color:#fff;padding:2rem 2.5rem;border-radius:16px;'
      + 'box-shadow:0 8px 32px rgba(0,0,0,0.6);z-index:10000;text-align:center;'
      + 'font-size:1.2rem;line-height:1.6;min-width:250px;max-width:90vw;'
      + 'animation:vc-pop-in 0.3s ease;';
    el.innerHTML = '<div style="font-size:2.5rem;margin-bottom:0.5rem;">' + icon + '</div>'
      + '<div>' + msg + '</div>';
    document.body.appendChild(el);

    // アニメーション用style注入（一度だけ）
    if (!document.getElementById('vc-pop-style')) {
      var style = document.createElement('style');
      style.id = 'vc-pop-style';
      style.textContent = '@keyframes vc-pop-in{0%{opacity:0;transform:translate(-50%,-50%) scale(0.7)}100%{opacity:1;transform:translate(-50%,-50%) scale(1)}}';
      document.head.appendChild(style);
    }

    el.addEventListener('click', function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    });
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 3000);

    _hideStatus();
  }

  // ── 音声再起動ファストパス ──
  var _restartWords = ['音声再起動', '音声リスタート', '音声リセット', '再起動して', 'リスタート'];

  function _detectRestartFastPath(text) {
    for (var i = 0; i < _restartWords.length; i++) {
      if (text.indexOf(_restartWords[i]) !== -1) return true;
    }
    return false;
  }

  function _executeVoiceRestart() {
    _showStatus('音声を再起動しています...', 2000);
    // 停止
    _shouldRestart = false;
    _hardResetPending = false;
    _resetVoiceHealthCounters();
    _clearInterimTimer();
    _stopWatchdog();
    if (_recognition) {
      _recognition.onresult = null;
      _recognition.onerror = null;
      _recognition.onend = null;
      try { _recognition.abort(); } catch (e) {}
      _recognition = null;
    }
    // AudioContext 破棄→再作成
    if (_audioCtx) {
      try { _audioCtx.close(); } catch (e) {}
      _audioCtx = null;
    }
    _unlockAudio();
    // 再開始
    setTimeout(function () {
      _beepSuccess();
      _startContinuousListening();
      _showStatus('音声を再起動しました', 2000);
    }, 300);
  }

  function _detectAiKitchenFastPath(text) {
    var hasKeyword = false;
    for (var i = 0; i < _aiKitchenWords.length; i++) {
      if (text.indexOf(_aiKitchenWords[i]) !== -1) { hasKeyword = true; break; }
    }

    if (hasKeyword) {
      if (text.indexOf('閉じ') !== -1) return 'close';
      if (text.indexOf('更新') !== -1 || text.indexOf('最新') !== -1) return 'refresh';
      return 'open';
    }

    if (text.indexOf('最新にして') !== -1) return 'refresh';

    // パネルが開いている場合、「閉じて」「更新」単独でもAIキッチン操作と判定
    if (_isAiKitchenOpen()) {
      if (text.indexOf('閉じて') !== -1) return 'close';
      if (text.indexOf('更新') !== -1) return 'refresh';
    }

    return null;
  }

  function _executeAiKitchenAction(action) {
    if (!window.AiKitchen) {
      _showStatus('AIキッチンダッシュボードが利用できません', 2000);
      _returnToStandby();
      return;
    }

    var msg = '';
    if (action === 'open') {
      AiKitchen.open();
      msg = 'AIシェフを開きます';
    } else if (action === 'close') {
      AiKitchen.close();
      msg = 'AIシェフを閉じます';
    } else if (action === 'refresh') {
      if (!_isAiKitchenOpen()) { AiKitchen.open(); }
      else { AiKitchen.refresh(); }
      msg = 'AIシェフを更新します';
    }
    _showStatus(msg, 2000);
    _returnToStandby();
  }

  // ── 常時リスニング開始 ──
  function _startContinuousListening() {
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      _showStatus('このブラウザは音声認識に対応していません', 3000);
      return;
    }

    // 既存インスタンスを完全に破棄（not-allowed等で壊れた可能性があるため）
    if (_recognition) {
      _recognition.onresult = null;
      _recognition.onerror = null;
      _recognition.onend = null;
      try { _recognition.abort(); } catch (e) {}
      _recognition = null;
    }

    _shouldRestart = true;
    _lastHardResetTime = Date.now();
    _resetVoiceHealthCounters();
    _setState('standby');
    localStorage.setItem(_VOICE_KEY, '1');
    _showStatus(_standbyHint, 0);
    _ensureRecognition(SpeechRecognition);
    _startWatchdog();
  }

  // ── SpeechRecognition 確保（1回だけ作成、以降は再利用） ──
  function _ensureRecognition(SpeechRecognition) {
    if (!_recognition) {
      _recognition = new SpeechRecognition();
      _recognition.lang = 'ja-JP';
      _recognition.continuous = true;
      _recognition.interimResults = true;
      _recognition.maxAlternatives = 1;
      _recognition.onresult = _onResult;
      _recognition.onerror = _onError;
      _recognition.onend = _onEnd;
    }
    _startRecognition();
  }

  // ── 認識開始（既存インスタンスを再利用） ──
  function _startRecognition() {
    if (!_recognition) return;
    try {
      _recognition.start();
      var now = Date.now();
      _lastStartTime = now;
      if (!_lastHardResetTime) _lastHardResetTime = now;
      _consecutiveErrors = 0;
    } catch (e) {
      if (e.name === 'InvalidStateError') {
        // 既にstartされている場合 → 正常
        if (!_lastStartTime) _lastStartTime = Date.now();
      } else {
        // その他のエラー → ハードリセット
        _consecutiveErrors++;
        setTimeout(function () {
          if (_shouldRestart) _hardResetRecognition();
        }, 500);
      }
    }
  }

  // ── 認識を強制再作成（ハードリセット） ──
  function _hardResetRecognition() {
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) return;
    if (_hardResetPending) return;
    _hardResetPending = true;
    _clearInterimTimer();
    _resetVoiceHealthCounters();
    _lastHardResetTime = Date.now();
    if (_recognition) {
      _recognition.onresult = null;
      _recognition.onerror = null;
      _recognition.onend = null;
      try { _recognition.abort(); } catch (e) {}
      _recognition = null;
    }
    setTimeout(function () {
      _hardResetPending = false;
      if (!_shouldRestart) return;
      _ensureRecognition(SpeechRecognition);
      if (_state !== 'processing') _setState('standby');
    }, 350);
  }

  // ── interim コマンド確定タイマーをクリア ──
  function _clearInterimTimer() {
    if (_interimTimer) {
      clearTimeout(_interimTimer);
      _interimTimer = null;
    }
    _interimCmd = null;
  }

  // ── interim コマンドを確定してGeminiへ送信 ──
  function _commitInterimCommand() {
    var cmd = _interimCmd;
    _clearInterimTimer();
    if (!cmd || _state !== 'standby') return;

    // コマンド一覧: ファストパス（Gemini不要）
    if (_detectHelpFastPath(cmd)) {
      _executeHelp();
      return;
    }

    var aiFallbackSwitch = _detectAiFallbackSwitchFastPath(cmd);
    if (aiFallbackSwitch !== null) {
      _executeAiFallbackSwitch(aiFallbackSwitch);
      return;
    }

    if (_detectUndoFastPath(cmd)) {
      _executeUndoLast();
      return;
    }

    var modeCmd = _detectModeFastPath(cmd);
    if (modeCmd) {
      _executeModeSwitch(modeCmd);
      return;
    }

    var tableRead = _detectTableReadFastPath(cmd);
    if (tableRead) {
      _executeTableRead(cmd, tableRead);
      return;
    }

    if (_detectCancelFastPath(cmd)) {
      _executeCancelOrder(cmd);
      return;
    }

    if (_detectCourseAdvanceFastPath(cmd)) {
      _executeCourseAdvance(cmd);
      return;
    }

    var tableBulk = _detectTableBulkFastPath(cmd);
    if (tableBulk) {
      _executeTableBulk(cmd, tableBulk);
      return;
    }

    var tableStatus = _detectTableStatusFastPath(cmd);
    if (tableStatus) {
      _executeTableStatus(cmd, tableStatus);
      return;
    }

    // スクロール: ファストパス（Gemini不要）
    var scrollCmd = _detectScrollFastPath(cmd);
    if (scrollCmd) {
      _executeScroll(scrollCmd);
      return;
    }

    // スタッフ呼び出し: ファストパス（Gemini不要）
    if (_detectStaffCallFastPath(cmd)) {
      _executeStaffCall();
      return;
    }

    // 音声再起動: ファストパス（Gemini不要）
    if (_detectRestartFastPath(cmd)) {
      _executeVoiceRestart();
      return;
    }

    // テーマ切替: ファストパス（Gemini不要）
    var themeAction = _detectThemeFastPath(cmd);
    if (themeAction) {
      _showStatus('認識:「' + Utils.escapeHtml(cmd) + '」', 1000);
      _executeThemeSwitch(themeAction);
      return;
    }

    // ステーション切替: ファストパス（Gemini不要）
    var stationAction = _detectStationFastPath(cmd);
    if (stationAction !== null) {
      _showStatus('認識:「' + Utils.escapeHtml(cmd) + '」', 1000);
      _executeStationSwitch(stationAction);
      return;
    }

    // AIキッチンダッシュボード: ファストパス（Gemini不要）
    var aiAction = _detectAiKitchenFastPath(cmd);
    if (aiAction) {
      _showStatus('認識:「' + Utils.escapeHtml(cmd) + '」', 2000);
      _executeAiKitchenAction(aiAction);
      return;
    }

    // 品切れ管理画面: ファストパス（Gemini不要）
    if (_detectSoldOutNavigationFastPath(cmd)) {
      _showStatus('認識:「' + Utils.escapeHtml(cmd) + '」', 1500);
      _executeNavigateSoldOut();
      return;
    }

    var soldOutCmd = _detectSoldOutToggleFastPath(cmd);
    if (soldOutCmd) {
      _showStatus('認識:「' + Utils.escapeHtml(cmd) + '」', 1500);
      _executeSoldOutToggle(soldOutCmd.menuItemId, soldOutCmd.source, soldOutCmd.isSoldOut, soldOutCmd.menuName);
      return;
    }

    // 品切れ一覧: ファストパス（Gemini不要）
    if (_isListCommand(cmd)) {
      _showStatus('認識:「' + Utils.escapeHtml(cmd) + '」', 2000);
      _executeListSoldOut();
      return;
    }

    // 品目単位ステータス: ファストパス（Gemini不要）
    var itemCmd = _detectItemCommandFastPath(cmd);
    if (itemCmd) {
      _setState('processing');
      var fastLabels = { preparing: '調理開始', ready: '完成', served: '提供済み' };
      _showStatus('認識:「' + Utils.escapeHtml(cmd) + '」', 1000);
      _executeItemStatusUpdate(itemCmd.item.item_id, itemCmd.newStatus, itemCmd.item.name, fastLabels[itemCmd.newStatus]);
      return;
    }

    _setState('processing');
    _showStatus('認識:「' + Utils.escapeHtml(cmd) + '」— 解析中...', 0);
    _analyzeWithGemini(cmd);
  }

  // ── フィードバック音（U-4: Web Audio API） ──
  var _BEEP_VOLUME = 0.3;
  var _audioCtx = null;

  function _unlockAudio() {
    if (!_audioCtx) {
      try { _audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
      catch (e) { return; }
    }
    if (_audioCtx.state === 'suspended') {
      try { _audioCtx.resume(); } catch (e) {}
    }
  }

  function _beep(freq, durationMs) {
    if (!_audioCtx) return;
    if (_audioCtx.state === 'suspended') {
      try { _audioCtx.resume(); } catch (e) {}
    }
    try {
      var osc = _audioCtx.createOscillator();
      var gain = _audioCtx.createGain();
      osc.connect(gain);
      gain.connect(_audioCtx.destination);
      osc.frequency.value = freq;
      gain.gain.value = _BEEP_VOLUME;
      osc.start(_audioCtx.currentTime);
      osc.stop(_audioCtx.currentTime + durationMs / 1000);
    } catch (e) { /* ignore */ }
  }

  function _beepRecognized() {
    _beep(880, 100);
  }

  function _beepSuccess() {
    _beep(880, 80);
    setTimeout(function () { _beep(1100, 80); }, 130);
  }

  function _beepError() {
    _beep(330, 200);
  }

  function _speak(text) {
    if (!text || !window.speechSynthesis || !window.SpeechSynthesisUtterance) return;
    try {
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'ja-JP';
      u.rate = 1.05;
      u.volume = 1;
      window.speechSynthesis.cancel();
      window.speechSynthesis.speak(u);
    } catch (e) {}
  }

  // ── 確認モーダル（U-4: confirm() 置き換え） ──
  var _confirmOverlay = null;
  var _confirmTimeout = null;
  var _pendingVoiceConfirm = null;

  function _showConfirmDialog(message, onOk, onCancel) {
    _hideConfirmDialog();
    _pendingVoiceConfirm = { ok: onOk, cancel: onCancel };

    _confirmOverlay = document.createElement('div');
    _confirmOverlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;'
      + 'background:rgba(0,0,0,0.6);z-index:10001;display:flex;'
      + 'align-items:center;justify-content:center;';

    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:16px;padding:32px 24px;'
      + 'max-width:420px;width:90%;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,0.3);';

    var title = document.createElement('h3');
    title.style.cssText = 'margin:0 0 16px;font-size:18px;color:#666;';
    title.textContent = '音声コマンド確認';

    var msg = document.createElement('p');
    msg.style.cssText = 'margin:0 0 24px;font-size:24px;color:#333;line-height:1.5;font-weight:bold;';
    msg.textContent = message;

    var hint = document.createElement('p');
    hint.style.cssText = 'margin:0 0 24px;font-size:13px;color:#999;';
    hint.textContent = '声で「実行」または「やめる」。5秒後に自動キャンセルされます';

    var btnWrap = document.createElement('div');
    btnWrap.style.cssText = 'display:flex;gap:16px;justify-content:center;';

    var btnOk = document.createElement('button');
    btnOk.type = 'button';
    btnOk.textContent = '実行する';
    btnOk.style.cssText = 'background:#2e7d32;color:#fff;border:none;border-radius:12px;'
      + 'padding:0 32px;font-size:18px;font-weight:bold;cursor:pointer;min-height:60px;min-width:120px;';

    var btnCancel = document.createElement('button');
    btnCancel.type = 'button';
    btnCancel.textContent = 'やめる';
    btnCancel.style.cssText = 'background:#c62828;color:#fff;border:none;border-radius:12px;'
      + 'padding:0 32px;font-size:18px;font-weight:bold;cursor:pointer;min-height:60px;min-width:120px;';

    btnOk.addEventListener('click', function () {
      _hideConfirmDialog();
      if (onOk) onOk();
    });

    btnCancel.addEventListener('click', function () {
      _hideConfirmDialog();
      if (onCancel) onCancel();
    });

    btnWrap.appendChild(btnOk);
    btnWrap.appendChild(btnCancel);
    card.appendChild(title);
    card.appendChild(msg);
    card.appendChild(hint);
    card.appendChild(btnWrap);
    _confirmOverlay.appendChild(card);
    document.body.appendChild(_confirmOverlay);
    _setState('standby');
    _speak(message + '。実行、または、やめる、と言ってください。');

    // 5秒タイムアウトで自動キャンセル
    _confirmTimeout = setTimeout(function () {
      _hideConfirmDialog();
      if (onCancel) onCancel();
    }, 5000);
  }

  function _hideConfirmDialog() {
    if (_confirmTimeout) { clearTimeout(_confirmTimeout); _confirmTimeout = null; }
    if (_confirmOverlay && _confirmOverlay.parentNode) {
      _confirmOverlay.parentNode.removeChild(_confirmOverlay);
    }
    _confirmOverlay = null;
    _pendingVoiceConfirm = null;
  }

  function _detectConfirmDecision(text) {
    var okWords = ['実行', 'はい', 'お願い', '確定', '進めて', 'やって'];
    var cancelWords = ['やめる', 'キャンセル', '中止', '違う', 'いいえ', 'なし'];
    var i;
    for (i = 0; i < cancelWords.length; i++) {
      if (text.indexOf(cancelWords[i]) !== -1) return 'cancel';
    }
    for (i = 0; i < okWords.length; i++) {
      if (text.indexOf(okWords[i]) !== -1) return 'ok';
    }
    return null;
  }

  function _handlePendingVoiceConfirm(text) {
    if (!_pendingVoiceConfirm) return false;
    var decision = _detectConfirmDecision(text);
    if (!decision) {
      _showStatus('確認中:「実行」または「やめる」と言ってください', 0);
      return true;
    }
    var pending = _pendingVoiceConfirm;
    _hideConfirmDialog();
    if (decision === 'ok') {
      if (pending.ok) pending.ok();
    } else {
      if (pending.cancel) pending.cancel();
    }
    return true;
  }

  // ── 認識感度の動的調整（U-4） ──
  var _SENSITIVITY_KEY = 'mt_kds_voice_sensitivity';

  function _loadSensitivity() {
    var val = parseFloat(localStorage.getItem(_SENSITIVITY_KEY));
    if (!isNaN(val) && val >= 0.3 && val <= 0.7) {
      _MIN_CONFIDENCE = val;
    }
  }

  function setSensitivity(value) {
    var v = parseFloat(value);
    if (isNaN(v) || v < 0.3 || v > 0.7) return;
    _MIN_CONFIDENCE = v;
    try { localStorage.setItem(_SENSITIVITY_KEY, String(v)); } catch (e) { /* ignore */ }
  }

  // ── ノイズフィルタリング ──
  var _MIN_CMD_LENGTH = 2;   // 2文字以下はノイズとみなす
  var _MAX_CMD_LENGTH = 60;  // 60文字超は会話の拾い読みとみなす
  var _MIN_CONFIDENCE = 0.4; // 信頼度40%未満は除外

  function _isNoise(result) {
    var text = result[0].transcript;
    if (text.length <= _MIN_CMD_LENGTH) return true;
    if (text.length > _MAX_CMD_LENGTH) return true;
    // final結果の信頼度チェック（interimはconfidenceが不安定なのでスキップ）
    if (result.isFinal && typeof result[0].confidence === 'number' && result[0].confidence < _MIN_CONFIDENCE) return true;
    return false;
  }

  function _resetVoiceHealthCounters() {
    _lastResultTime = 0;
    _lastFinalTime = 0;
    _lastInterimTime = 0;
    _interimOnlySince = 0;
    _interimStreak = 0;
    _resetVoiceErrorWindow();
  }

  function _resetVoiceErrorWindow() {
    _voiceErrorWindowStartedAt = 0;
    _noSpeechErrors = 0;
    _networkErrors = 0;
    _audioCaptureErrors = 0;
  }

  function _rememberVoiceError(type) {
    var now = Date.now();
    if (!_voiceErrorWindowStartedAt || now - _voiceErrorWindowStartedAt > _VOICE_ERROR_WINDOW_MS) {
      _voiceErrorWindowStartedAt = now;
      _noSpeechErrors = 0;
      _networkErrors = 0;
      _audioCaptureErrors = 0;
    }
    if (type === 'no-speech') _noSpeechErrors++;
    if (type === 'network') _networkErrors++;
    if (type === 'audio-capture') _audioCaptureErrors++;
  }

  // ── 音声認識結果ハンドラ ──
  function _onResult(event) {
    var i, result, transcript;
    var now = Date.now();
    // 認識結果が来た → 正常動作中
    _consecutiveErrors = 0;
    _lastResultTime = now;

    for (i = event.resultIndex; i < event.results.length; i++) {
      result = event.results[i];
      transcript = result[0].transcript;
      if (result.isFinal) {
        _lastFinalTime = now;
        _interimOnlySince = 0;
        _interimStreak = 0;
        _resetVoiceErrorWindow();
      } else {
        _lastInterimTime = now;
        _interimStreak++;
        if (!_interimOnlySince) _interimOnlySince = now;
      }

      if (_pendingVoiceConfirm) {
        if (result.isFinal) {
          _handlePendingVoiceConfirm(transcript);
        } else {
          _showStatus('確認中: ' + Utils.escapeHtml(transcript) + ' ...', 0);
        }
        continue;
      }

      if (_state === 'standby') {
        // ノイズ除外
        if (_isNoise(result)) {
          if (result.isFinal) _showStatus(_standbyHint, 0);
          continue;
        }

        if (result.isFinal) {
          // final結果 → 即座に処理（interim待ちをキャンセル）
          _clearInterimTimer();
          // コマンド一覧: ファストパス
          if (_detectHelpFastPath(transcript)) {
            _executeHelp();
            continue;
          }
          var aiFallbackSwitch2 = _detectAiFallbackSwitchFastPath(transcript);
          if (aiFallbackSwitch2 !== null) {
            _executeAiFallbackSwitch(aiFallbackSwitch2);
            continue;
          }
          if (_detectUndoFastPath(transcript)) {
            _executeUndoLast();
            continue;
          }
          var modeCmd2 = _detectModeFastPath(transcript);
          if (modeCmd2) {
            _executeModeSwitch(modeCmd2);
            continue;
          }
          var tableRead2 = _detectTableReadFastPath(transcript);
          if (tableRead2) {
            _executeTableRead(transcript, tableRead2);
            continue;
          }
          if (_detectCancelFastPath(transcript)) {
            _executeCancelOrder(transcript);
            continue;
          }
          if (_detectCourseAdvanceFastPath(transcript)) {
            _executeCourseAdvance(transcript);
            continue;
          }
          var tableBulk2 = _detectTableBulkFastPath(transcript);
          if (tableBulk2) {
            _executeTableBulk(transcript, tableBulk2);
            continue;
          }
          var tableStatus2 = _detectTableStatusFastPath(transcript);
          if (tableStatus2) {
            _executeTableStatus(transcript, tableStatus2);
            continue;
          }
          // スクロール: ファストパス
          var scrollDir = _detectScrollFastPath(transcript);
          if (scrollDir) {
            _executeScroll(scrollDir);
            continue;
          }
          // スタッフ呼び出し: ファストパス（_isCommandの前に判定）
          if (_detectStaffCallFastPath(transcript)) {
            _executeStaffCall();
            continue;
          }
          // 音声再起動: ファストパス（_isCommandの前に判定）
          if (_detectRestartFastPath(transcript)) {
            _executeVoiceRestart();
            continue;
          }
          // テーマ切替: ファストパス（_isCommandの前に判定）
          var themeAct = _detectThemeFastPath(transcript);
          if (themeAct) {
            _beepRecognized();
            _showStatus('認識:「' + Utils.escapeHtml(transcript) + '」', 1000);
            _executeThemeSwitch(themeAct);
            continue;
          }
          // ステーション切替: ファストパス（_isCommandの前に判定）
          var stationAct = _detectStationFastPath(transcript);
          if (stationAct !== null) {
            _beepRecognized();
            _showStatus('認識:「' + Utils.escapeHtml(transcript) + '」', 1000);
            _executeStationSwitch(stationAct);
            continue;
          }
          if (_detectSoldOutNavigationFastPath(transcript)) {
            _beepRecognized();
            _showStatus('認識:「' + Utils.escapeHtml(transcript) + '」', 1500);
            _executeNavigateSoldOut();
            continue;
          }
          var soldOutCmd2 = _detectSoldOutToggleFastPath(transcript);
          if (soldOutCmd2) {
            _beepRecognized();
            _showStatus('認識:「' + Utils.escapeHtml(transcript) + '」', 1500);
            _executeSoldOutToggle(soldOutCmd2.menuItemId, soldOutCmd2.source, soldOutCmd2.isSoldOut, soldOutCmd2.menuName);
            continue;
          }
          if (_isCommand(transcript)) {
            _beepRecognized();
            // AIキッチンダッシュボード: ファストパス（Gemini不要）
            var aiAct = _detectAiKitchenFastPath(transcript);
            if (aiAct) {
              _showStatus('認識:「' + Utils.escapeHtml(transcript) + '」', 2000);
              _executeAiKitchenAction(aiAct);
              continue;
            }
            // 品切れ一覧: ファストパス（Gemini不要）
            if (_isListCommand(transcript)) {
              _showStatus('認識:「' + Utils.escapeHtml(transcript) + '」', 2000);
              _executeListSoldOut();
              continue;
            }
            // 品目単位ステータス: ファストパス（Gemini不要）
            var itemCmd2 = _detectItemCommandFastPath(transcript);
            if (itemCmd2) {
              _setState('processing');
              var fastLabels2 = { preparing: '調理開始', ready: '完成', served: '提供済み' };
              _showStatus('認識:「' + Utils.escapeHtml(transcript) + '」', 1000);
              _executeItemStatusUpdate(itemCmd2.item.item_id, itemCmd2.newStatus, itemCmd2.item.name, fastLabels2[itemCmd2.newStatus]);
              continue;
            }
            _setState('processing');
            _showStatus('認識:「' + Utils.escapeHtml(transcript) + '」— 解析中...', 0);
            _analyzeWithGemini(transcript);
          } else {
            _showStatus(_standbyHint, 0);
          }
        } else {
          // interim結果
          if (_isCommand(transcript) || _detectThemeFastPath(transcript) || _detectStationFastPath(transcript) !== null || _detectRestartFastPath(transcript) || _detectHelpFastPath(transcript) || _detectScrollFastPath(transcript) || _detectStaffCallFastPath(transcript) || _detectUndoFastPath(transcript) || _detectModeFastPath(transcript) || _detectTableReadFastPath(transcript) || _detectCancelFastPath(transcript) || _detectCourseAdvanceFastPath(transcript) || _detectTableBulkFastPath(transcript) || _detectTableStatusFastPath(transcript) || _detectSoldOutNavigationFastPath(transcript) || _detectSoldOutToggleFastPath(transcript) || _detectAiFallbackSwitchFastPath(transcript) !== null) {
            // コマンドパターン検出 → 1.5秒後に確定（finalが来なかった場合の保険）
            _interimCmd = transcript;
            if (_interimTimer) clearTimeout(_interimTimer);
            _interimTimer = setTimeout(_commitInterimCommand, 1500);
            _showStatus('🎤 ' + Utils.escapeHtml(transcript) + ' ...', 0);
          } else {
            _showStatus('🎤 聞取中: ' + Utils.escapeHtml(transcript), 0);
          }
        }
      }
      // _state === 'processing' の場合は無視（Gemini処理中）
    }
  }

  // ── エラーハンドラ ──
  function _onError(event) {
    if (event.error === 'no-speech') {
      // 無音による自動停止 → onend で即再起動するので何もしない
      _rememberVoiceError('no-speech');
      return;
    }
    if (event.error === 'aborted') {
      return;
    }
    if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
      _showStatus('マイクが検出できません。macOS設定→プライバシーとセキュリティ→マイク→Chromeを確認してください', 6000);
      _stopContinuousListening();
      return;
    }
    if (event.error === 'network') {
      // ネットワークエラー → onend で再起動
      _rememberVoiceError('network');
      _consecutiveErrors++;
      return;
    }
    if (event.error === 'audio-capture') {
      _showStatus('マイクにアクセスできません。他のアプリが使用中の可能性があります', 3000);
      _rememberVoiceError('audio-capture');
      _consecutiveErrors++;
      return;
    }
    _consecutiveErrors++;
    _showStatus('音声認識エラー: ' + event.error, 3000);
  }

  // ── 終了ハンドラ（穏やかな再起動） ──
  var _MAX_CONSECUTIVE_ERRORS = 5; // 連続エラー上限

  function _onEnd() {
    if (!_shouldRestart) return;

    // 連続エラーが上限を超えたら自動停止
    if (_consecutiveErrors >= _MAX_CONSECUTIVE_ERRORS) {
      _showStatus('音声認識を自動停止しました。音声ボタンをタップして再開してください', 5000);
      _stopContinuousListening();
      return;
    }

    // Chrome は onend 内で同期的に start() するとエラーになるため遅延を入れる
    // 通常時: 500ms、エラー時: バックオフ（最大5秒）
    var delay = _consecutiveErrors > 0 ? Math.min(_consecutiveErrors * 1000, 5000) : 500;

    setTimeout(function () {
      if (!_shouldRestart) return;
      _startRecognition();
      if (_consecutiveErrors === 0 && _state !== 'processing') {
        _setState('standby');
      }
    }, delay);
  }

  // ── ウォッチドッグ（認識が沈黙死した場合の保険） ──
  var _watchdogTimer = null;
  var _WATCHDOG_INTERVAL = 20000; // 20秒ごとに監視
  var _WATCHDOG_STALE_MS = 90000; // 90秒応答なしならハードリセット
  var _PERIODIC_HARD_RESET_MS = 12 * 60 * 1000; // 12分ごとに予防リフレッシュ
  var _NO_FINAL_RESET_MS = 3 * 60 * 1000; // 3分final未確定なら品質低下として扱う
  var _INTERIM_STUCK_RESET_MS = 45 * 1000; // interimだけ45秒続く場合は滞留
  var _VOICE_ERROR_WINDOW_MS = 3 * 60 * 1000;
  var _MAX_NO_SPEECH_ERRORS = 4;
  var _MAX_NETWORK_ERRORS = 2;
  var _MAX_AUDIO_CAPTURE_ERRORS = 2;

  function _startWatchdog() {
    _stopWatchdog();
    _watchdogTimer = setInterval(function () {
      _checkRecognitionHealth();
    }, _WATCHDOG_INTERVAL);
  }

  function _stopWatchdog() {
    if (_watchdogTimer) { clearInterval(_watchdogTimer); _watchdogTimer = null; }
  }

  function _isVoiceMaintenanceSafe() {
    if (!_shouldRestart) return false;
    if (_hardResetPending) return false;
    if (_state === 'processing') return false;
    if (_pendingVoiceConfirm || _confirmOverlay) return false;
    return true;
  }

  function _checkRecognitionHealth() {
    var now = Date.now();
    var finalBase;
    if (!_isVoiceMaintenanceSafe()) return;
    if (!_lastHardResetTime) _lastHardResetTime = now;

    if (now - _lastHardResetTime >= _PERIODIC_HARD_RESET_MS) {
      _autoHardResetRecognition('定期');
      return;
    }

    if (_networkErrors >= _MAX_NETWORK_ERRORS) {
      _autoHardResetRecognition('network');
      return;
    }

    if (_audioCaptureErrors >= _MAX_AUDIO_CAPTURE_ERRORS) {
      _autoHardResetRecognition('マイク');
      return;
    }

    if (_noSpeechErrors >= _MAX_NO_SPEECH_ERRORS) {
      _autoHardResetRecognition('無音');
      return;
    }

    if (_interimOnlySince && now - _interimOnlySince >= _INTERIM_STUCK_RESET_MS) {
      _autoHardResetRecognition('聞取滞留');
      return;
    }

    finalBase = _lastFinalTime || _lastStartTime;
    if (finalBase && now - finalBase >= _NO_FINAL_RESET_MS && (_interimStreak >= 3 || _noSpeechErrors > 0 || _networkErrors > 0)) {
      _autoHardResetRecognition('final未確定');
      return;
    }

    if (_lastStartTime && now - _lastStartTime >= _WATCHDOG_STALE_MS && (!_lastResultTime || _lastResultTime < _lastStartTime)) {
      _autoHardResetRecognition('応答なし');
    }
  }

  function _autoHardResetRecognition(reason) {
    if (!_isVoiceMaintenanceSafe()) return;
    _showStatus('音声認識を自動リフレッシュ中（' + reason + '）', 1400);
    _hardResetRecognition();
  }

  // ── 常時リスニング停止 ──
  function _stopContinuousListening() {
    _shouldRestart = false;
    _hardResetPending = false;
    _clearInterimTimer();
    _stopWatchdog();
    _consecutiveErrors = 0;
    _setState('off');
    localStorage.removeItem(_VOICE_KEY);
    _hideStatus();

    if (_recognition) {
      _recognition.onresult = null;
      _recognition.onerror = null;
      _recognition.onend = null;
      try { _recognition.abort(); } catch (e) { /* ignore */ }
      _recognition = null;
    }
  }

  // ── 状態遷移 + UI更新 ──
  function _setState(newState) {
    _state = newState;
    _stopBlink();

    var label = document.getElementById('voice-cmd-label');
    if (!_btn || !label) return;

    var isLight = document.documentElement.classList.contains('light-theme');
    switch (newState) {
      case 'off':
        _btn.style.borderColor = isLight ? '#bbb' : 'rgba(255,255,255,0.3)';
        _btn.style.background = 'none';
        _btn.style.color = isLight ? '#555' : '';
        label.textContent = '音声OFF';
        break;
      case 'standby':
        _btn.style.borderColor = '#4CAF50';
        _btn.style.background = isLight ? 'rgba(76,175,80,0.1)' : 'rgba(76,175,80,0.15)';
        _btn.style.color = isLight ? '#2e7d32' : '';
        label.textContent = '音声ON';
        break;
      case 'processing':
        _startBlink();
        label.textContent = '解析中';
        break;
    }
  }

  // ── 赤点滅 ──
  function _startBlink() {
    var on = true;
    _blinkTimer = setInterval(function () {
      if (!_btn) return;
      if (on) {
        _btn.style.borderColor = '#f44336';
        _btn.style.background = 'rgba(244,67,54,0.25)';
      } else {
        _btn.style.borderColor = 'rgba(255,255,255,0.3)';
        _btn.style.background = 'none';
      }
      on = !on;
    }, 400);
  }

  function _stopBlink() {
    if (_blinkTimer) {
      clearInterval(_blinkTimer);
      _blinkTimer = null;
    }
  }

  // ── Gemini API 呼び出し（ai-generate.php プロキシ経由） ──
  function _analyzeWithGemini(transcript) {
    if (!_aiFallbackEnabled) {
      _beepError();
      _showStatus('AI補助OFF: 未対応の音声コマンドです。「コマンド一覧」で確認してください', 3500);
      _returnToStandby();
      return;
    }
    if (!_aiConfigured) {
      _showStatus('APIキー未設定: POSLA管理画面で設定してください', 3000);
      _returnToStandby();
      return;
    }
    var ordersSummary = _lastOrders.map(function (o) {
      return {
        order_id: o.id,
        table_code: o.table_code || '',
        status: o.status,
        items: (o.items || []).map(function (it) {
          return { item_id: it.item_id, name: it.name, qty: it.qty, status: it.status || 'pending' };
        })
      };
    });

    var menuSummary = _menuItems.map(function (m) {
      return { id: m.menuItemId, src: m.source, name: m.name, out: m.soldOut };
    });

    var prompt = 'あなたはキッチンスタッフの音声コマンドを解析するアシスタントです。\n'
      + '音声テキストから操作内容をJSONのみで返してください。前置き・解説は禁止。\n\n'
      + '■ 最重要ルール:\n'
      + '品目名（メニュー名）が含まれる場合は必ず update_item_status を使用すること。\n'
      + 'update_status はテーブル番号のみで品目名を含まない場合に限る。\n\n'
      + '■ コマンド種別と返却JSON:\n\n'
      + '1. 注文全体のステータス変更（テーブル番号のみ: 「T01完成」「テーブル1調理開始」等）\n'
      + '{"action":"update_status","order_id":"注文ID","new_status":"preparing|ready|served","confidence":"high|low"}\n'
      + '※テーブル番号のみで品目名がない場合に使用。最も古い未完了注文を対象にする。\n'
      + '※音声認識で「t01」「ティーゼロワン」「テーブル1」「1番」等の表記ゆれがある。\n\n'
      + '2. 品目単位のステータス変更（品目名を含む: 「チキン南蛮開始」「ハンバーグ完成」「T01のトンカツ提供済み」等）\n'
      + '{"action":"update_item_status","item_id":"品目のitem_id","item_name":"品目名","new_status":"preparing|ready|served","confidence":"high|low"}\n'
      + '※品目名が含まれていれば必ずこちらを使用（テーブル番号の有無に関わらず）。\n'
      + '※item_idはアクティブ注文のitemsから該当品目のitem_idを正確にコピーすること。\n'
      + '※同じ品目名が複数注文にある場合は最も古い未完了（status!=served）のものを選択。\n\n'
      + '3. 品切れ設定（「トンカツ品切れ」「サバ味噌売り切れ」等）\n'
      + '{"action":"toggle_sold_out","menu_item_id":"メニューID","source":"template|local","menu_name":"正式メニュー名","is_sold_out":true,"confidence":"high|low"}\n\n'
      + '4. 品切れ解除（「トンカツ販売再開」「サバ味噌解除」等）\n'
      + '{"action":"toggle_sold_out","menu_item_id":"メニューID","source":"template|local","menu_name":"正式メニュー名","is_sold_out":false,"confidence":"high|low"}\n\n'
      + '5. 品切れ確認（「今の品切れ」「品切れ一覧」等）\n'
      + '{"action":"list_sold_out","confidence":"high"}\n\n'
      + '6. 品切れ管理画面へ移動（「品切れ管理」「品切れ管理画面」等）\n'
      + '{"action":"navigate_sold_out","confidence":"high"}\n\n'
      + '7. AIキッチンダッシュボードを開く（「AIシェフ」「シェフ」「ダッシュボード」等）\n'
      + '{"action":"ai_kitchen_open","confidence":"high"}\n\n'
      + '8. AIキッチンダッシュボードを閉じる（「AIシェフ閉じて」「ダッシュボード閉じて」「閉じて」等）\n'
      + '{"action":"ai_kitchen_close","confidence":"high"}\n\n'
      + '9. AIキッチンダッシュボードを更新（「AI更新」「最新にして」「更新」等）\n'
      + '{"action":"ai_kitchen_refresh","confidence":"high"}\n\n'
      + '10. 該当なし → {"action":"unknown"}\n\n'
      + (menuSummary.length > 0
        ? '■ 現在のメニュー:\n' + JSON.stringify(menuSummary) + '\n\n'
        : '')
      + '■ 現在のアクティブ注文:\n' + JSON.stringify(ordersSummary) + '\n\n'
      + '■ 音声:「' + transcript + '」';

    fetch('../../api/store/ai-generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        prompt: prompt,
        temperature: 0,
        max_tokens: 256
      })
    })
    .then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('AIプロキシが空のレスポンスを返しました');
        try { return JSON.parse(body); } catch (e) { throw new Error('AIプロキシ JSON解析エラー'); }
      });
    })
    .then(function (json) {
      if (!json.ok) {
        var msg = (json.error && json.error.message) || 'AIプロキシエラー';
        if (json.error && json.error.code === 'AI_NOT_CONFIGURED') {
          _aiConfigured = false;
          msg = 'POSLA管理画面でGemini APIキーを設定してください';
        }
        throw new Error(msg);
      }
      var text = (json.data && json.data.text) || '';
      _handleGeminiResponse(text, transcript);
    })
    .catch(function (err) {
      _showStatus('Geminiエラー: ' + err.message, 4000);
      _returnToStandby();
    });
  }

  // ── Gemini レスポンス処理 ──
  function _handleGeminiResponse(text, transcript) {
    var cleaned = text.replace(/```json\s*/gi, '').replace(/```\s*/g, '').trim();

    var parsed;
    try { parsed = JSON.parse(cleaned); }
    catch (e) {
      _showStatus('AI応答の解析に失敗しました: ' + Utils.escapeHtml(text.substring(0, 100)), 4000);
      _returnToStandby();
      return;
    }

    if (parsed.action === 'unknown' || !parsed.action) {
      _showStatus('該当する注文が見つかりませんでした。「' + Utils.escapeHtml(transcript) + '」', 3000);
      _returnToStandby();
      return;
    }

    if (parsed.action === 'update_status') {
      // フォールバック: 発話に品目名が含まれていたら品目単位に変換
      var itemMatch = _findMatchingItem(transcript);
      if (itemMatch) {
        var iLabels = { preparing: '調理開始', ready: '完成', served: '提供済み' };
        var iLabel = iLabels[parsed.new_status] || parsed.new_status;
        if (parsed.confidence === 'low') {
          _hideStatus();
          _showConfirmDialog(
            '「' + itemMatch.name + '」を「' + iLabel + '」にしますか？',
            function () { _executeItemStatusUpdate(itemMatch.item_id, parsed.new_status, itemMatch.name, iLabel); },
            function () { _showStatus('キャンセルしました', 2000); _returnToStandby(); }
          );
          return;
        }
        _executeItemStatusUpdate(itemMatch.item_id, parsed.new_status, itemMatch.name, iLabel);
        return;
      }

      var order = _findOrder(parsed.order_id);
      var orderLabel = order ? (order.table_code || order.id.substring(0, 8)) : (parsed.order_id || '???').substring(0, 8);

      var statusLabels = {
        preparing: '調理開始',
        ready: '完成',
        served: '提供済み'
      };
      var statusLabel = statusLabels[parsed.new_status] || parsed.new_status;

      if (parsed.confidence === 'low') {
        _hideStatus();
        _showConfirmDialog(
          '「' + orderLabel + '」を「' + statusLabel + '」にしますか？',
          function () { _executeStatusUpdate(parsed.order_id, parsed.new_status, orderLabel, statusLabel); },
          function () { _showStatus('キャンセルしました', 2000); _returnToStandby(); }
        );
        return;
      }

      _executeStatusUpdate(parsed.order_id, parsed.new_status, orderLabel, statusLabel);

    } else if (parsed.action === 'update_item_status') {
      var resolvedItemId = parsed.item_id;
      var itemName = parsed.item_name || '不明';
      // item_idがGeminiから正しく返されなかった場合、名前で検索
      if (!_findItemById(resolvedItemId)) {
        var fallbackItem = _findMatchingItem(transcript);
        if (fallbackItem) {
          resolvedItemId = fallbackItem.item_id;
          itemName = fallbackItem.name;
        }
      }
      var itemStatusLabels = { preparing: '調理開始', ready: '完成', served: '提供済み' };
      var itemStatusLabel = itemStatusLabels[parsed.new_status] || parsed.new_status;

      if (parsed.confidence === 'low') {
        _hideStatus();
        _showConfirmDialog(
          '「' + itemName + '」を「' + itemStatusLabel + '」にしますか？',
          function () { _executeItemStatusUpdate(resolvedItemId, parsed.new_status, itemName, itemStatusLabel); },
          function () { _showStatus('キャンセルしました', 2000); _returnToStandby(); }
        );
        return;
      }
      _executeItemStatusUpdate(resolvedItemId, parsed.new_status, itemName, itemStatusLabel);

    } else if (parsed.action === 'toggle_sold_out') {
      var menuName = parsed.menu_name || '不明';
      var soldOutLabel = parsed.is_sold_out ? '品切れ' : '販売再開';

      if (parsed.confidence === 'low') {
        _hideStatus();
        _showConfirmDialog(
          '「' + menuName + '」を「' + soldOutLabel + '」にしますか？',
          function () { _executeSoldOutToggle(parsed.menu_item_id, parsed.source, parsed.is_sold_out, menuName); },
          function () { _showStatus('キャンセルしました', 2000); _returnToStandby(); }
        );
        return;
      }

      _executeSoldOutToggle(parsed.menu_item_id, parsed.source, parsed.is_sold_out, menuName);

    } else if (parsed.action === 'list_sold_out') {
      _executeListSoldOut();

    } else if (parsed.action === 'navigate_sold_out') {
      _showStatus('品切れ管理画面へ移動します...', 1500);
      localStorage.setItem(_VOICE_KEY, '1');
      _shouldRestart = false;
      if (_recognition) { try { _recognition.abort(); } catch (e) {} }
      setTimeout(function () { window.location.href = 'sold-out.html'; }, 500);
      return;

    } else if (parsed.action === 'ai_kitchen_open') {
      _executeAiKitchenAction('open');

    } else if (parsed.action === 'ai_kitchen_close') {
      _executeAiKitchenAction('close');

    } else if (parsed.action === 'ai_kitchen_refresh') {
      _executeAiKitchenAction('refresh');

    } else {
      _showStatus('未対応のアクション: ' + Utils.escapeHtml(parsed.action), 3000);
      _returnToStandby();
    }
  }

  // Phase 3: voice-commander の状態変更経路も offline/stale で止めるための共通ヘルパ
  function _voiceIsOfflineOrStale() {
    if (typeof OfflineStateBanner !== 'undefined' && OfflineStateBanner.isOfflineOrStale) {
      return OfflineStateBanner.isOfflineOrStale();
    }
    if (typeof OfflineDetector !== 'undefined' && OfflineDetector.isOnline) {
      return !OfflineDetector.isOnline();
    }
    return false;
  }

  // Phase 3: handleAction / handleItemAction / 既存 fetch catch の undefined 解決でも
  // 音声操作が「成功」と誤認しないよう、json.ok === true だけを成功扱いに絞る。
  function _isResultSuccess(json) {
    return !!(json && json.ok === true);
  }

  // ── ステータス更新実行 ──
  function _executeStatusUpdate(orderId, newStatus, orderLabel, statusLabel) {
    // Phase 3 レビュー指摘 #1: offline/stale で実際に更新されない経路を「成功」と誤発話しない
    if (_voiceIsOfflineOrStale()) {
      _beepError();
      _showStatus('通信が不安定です。通信復帰後に操作してください', 3000);
      _returnToStandby();
      return;
    }

    var storeId = KdsAuth.getStoreId();
    _showStatus(orderLabel + ' → ' + statusLabel + ' 実行中...', 0);

    KdsRenderer.handleAction(orderId, newStatus, storeId)
      .then(function (json) {
        // reject された場合はここに来ない。resolve された場合も json.ok 必須。
        // 既存の fetch catch が undefined resolve するケースもここで失敗判定する。
        if (!_isResultSuccess(json)) {
          _beepError();
          _showStatus('更新に失敗しました', 3000);
          _returnToStandby();
          return;
        }
        _beepSuccess();
        _showStatus('✓ ' + orderLabel + ' → ' + statusLabel, 2000);
        _returnToStandby();
      })
      .catch(function () {
        _beepError();
        _showStatus('更新に失敗しました', 3000);
        _returnToStandby();
      });
  }

  // ── 品目単位ステータス更新実行 ──
  function _executeItemStatusUpdate(itemId, newStatus, itemName, statusLabel) {
    // Phase 3: 同上
    if (_voiceIsOfflineOrStale()) {
      _beepError();
      _showStatus('通信が不安定です。通信復帰後に操作してください', 3000);
      _returnToStandby();
      return;
    }

    var storeId = KdsAuth.getStoreId();
    _showStatus(itemName + ' → ' + statusLabel + ' 実行中...', 0);

    KdsRenderer.handleItemAction(itemId, newStatus, storeId)
      .then(function (json) {
        if (!_isResultSuccess(json)) {
          _beepError();
          _showStatus('更新に失敗しました', 3000);
          _returnToStandby();
          return;
        }
        _beepSuccess();
        _showStatus('✓ ' + itemName + ' → ' + statusLabel, 2000);
        _returnToStandby();
      })
      .catch(function () {
        _beepError();
        _showStatus('更新に失敗しました', 3000);
        _returnToStandby();
      });
  }

  // ── 品切れトグル実行 ──
  function _executeSoldOutToggle(menuItemId, source, isSoldOut, menuName) {
    // Phase 3 レビュー指摘 #2: offline/stale では PATCH を叩かない
    if (_voiceIsOfflineOrStale()) {
      _beepError();
      _showStatus('通信が不安定です。通信復帰後に操作してください', 3000);
      _returnToStandby();
      return;
    }

    var storeId = KdsAuth.getStoreId();
    var label = isSoldOut ? '品切れ' : '販売再開';
    _showStatus(menuName + ' → ' + label + ' 実行中...', 0);

    fetch('../../api/kds/sold-out.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        store_id: storeId,
        menu_item_id: menuItemId,
        source: source,
        is_sold_out: isSoldOut
      })
    })
    .then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('空のレスポンス');
        var json;
        try { json = JSON.parse(body); } catch (e) { throw new Error('応答の解析に失敗'); }
        if (!json.ok) throw new Error((json.error && json.error.message) || 'エラー');
        return json;
      });
    })
    .then(function () {
      _beepSuccess();
      _showSoldOutConfirm(menuName, isSoldOut);
      // ローカルのメニュー状態を更新
      for (var i = 0; i < _menuItems.length; i++) {
        if (_menuItems[i].menuItemId === menuItemId) {
          _menuItems[i].soldOut = isSoldOut;
          break;
        }
      }
      _returnToStandby();
    })
    .catch(function (err) {
      _beepError();
      _showSoldOutConfirm(menuName, isSoldOut, err.message);
      _returnToStandby();
    });
  }

  // ── 品切れ一覧表示（中央オーバーレイ） ──
  function _executeListSoldOut() {
    var soldOutNames = [];
    for (var i = 0; i < _menuItems.length; i++) {
      if (_menuItems[i].soldOut) {
        soldOutNames.push(_menuItems[i].name);
      }
    }
    _showSoldOutOverlay(soldOutNames);
    _returnToStandby();
  }

  function _showSoldOutOverlay(names) {
    var existing = document.getElementById('vc-soldout-overlay');
    if (existing) existing.parentNode.removeChild(existing);

    var overlay = document.createElement('div');
    overlay.id = 'vc-soldout-overlay';
    overlay.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#263238;color:#fff;padding:1.5rem 2rem;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.6);z-index:10000;max-width:90vw;max-height:70vh;overflow-y:auto;font-size:1rem;line-height:1.6;';

    var html = '';
    if (names.length === 0) {
      html = '<div style="color:#4CAF50;font-weight:700;font-size:1.1rem;">現在品切れの商品はありません</div>';
    } else {
      html = '<div style="font-size:1.1rem;font-weight:700;color:#e53935;margin-bottom:0.75rem;">品切れ中（' + names.length + '品）</div>';
      for (var oi = 0; oi < names.length; oi++) {
        html += '<div style="padding:0.3rem 0;border-bottom:1px solid #37474f;">' + Utils.escapeHtml(names[oi]) + '</div>';
      }
    }
    overlay.innerHTML = html;
    document.body.appendChild(overlay);

    overlay.addEventListener('click', function () {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    });
    setTimeout(function () {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    }, 6000);
  }

  // ── 品切れトグル結果ポップアップ ──
  function _showSoldOutConfirm(menuName, isSoldOut, errorMsg) {
    var existing = document.getElementById('vc-soldout-confirm');
    if (existing) existing.parentNode.removeChild(existing);

    var el = document.createElement('div');
    el.id = 'vc-soldout-confirm';

    var isError = !!errorMsg;
    var bg = isError ? '#b71c1c' : (isSoldOut ? '#e53935' : '#43a047');
    var icon = isError ? '✗' : '✓';
    var msg = isError
      ? '品切れ更新に失敗\n' + errorMsg
      : Utils.escapeHtml(menuName) + '\n' + (isSoldOut ? '品切れにしました' : '販売再開しました');

    el.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);'
      + 'background:' + bg + ';color:#fff;padding:2rem 2.5rem;border-radius:16px;'
      + 'box-shadow:0 8px 32px rgba(0,0,0,0.6);z-index:10000;text-align:center;'
      + 'font-size:1.2rem;line-height:1.6;min-width:250px;max-width:90vw;'
      + 'animation:vc-pop-in 0.3s ease;';
    el.innerHTML = '<div style="font-size:2.5rem;margin-bottom:0.5rem;">' + icon + '</div>'
      + '<div style="white-space:pre-line;">' + msg + '</div>';
    document.body.appendChild(el);

    // アニメーション用style注入（一度だけ）
    if (!document.getElementById('vc-pop-style')) {
      var style = document.createElement('style');
      style.id = 'vc-pop-style';
      style.textContent = '@keyframes vc-pop-in{0%{opacity:0;transform:translate(-50%,-50%) scale(0.7)}100%{opacity:1;transform:translate(-50%,-50%) scale(1)}}';
      document.head.appendChild(style);
    }

    el.addEventListener('click', function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    });
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, isError ? 5000 : 3000);
  }

  // ── standby に戻る ──
  function _returnToStandby() {
    if (!_shouldRestart) return;

    setTimeout(function () {
      if (!_shouldRestart) return;
      _setState('standby');
      _showStatus(_standbyHint, 0);
    }, 500);
  }

  // ── 注文検索 ──
  function _findOrder(orderId) {
    for (var i = 0; i < _lastOrders.length; i++) {
      if (_lastOrders[i].id === orderId) return _lastOrders[i];
    }
    return null;
  }

  // ── 品目名マッチング（「ハンバーグ定食」→「ハンバーグ」でも照合） ──
  var _foodSuffixes = ['定食', 'セット', '弁当', '丼ぶり', '丼', '御膳', '膳', 'ランチ', 'プレート'];

  function _itemNameMatch(itemName, transcript) {
    if (transcript.indexOf(itemName) !== -1) return true;
    var core = itemName;
    for (var k = 0; k < _foodSuffixes.length; k++) {
      var sfx = _foodSuffixes[k];
      if (core.length > sfx.length && core.lastIndexOf(sfx) === core.length - sfx.length) {
        core = core.substring(0, core.length - sfx.length);
        break;
      }
    }
    return core.length >= 2 && transcript.indexOf(core) !== -1;
  }

  // ── 品目名でアクティブ注文内の品目を検索（最古の未完了品目を返す） ──
  function _findMatchingItem(transcript) {
    for (var i = 0; i < _lastOrders.length; i++) {
      var items = _lastOrders[i].items || [];
      for (var j = 0; j < items.length; j++) {
        var item = items[j];
        if (item.name && item.item_id && _itemNameMatch(item.name, transcript)) {
          if (item.status !== 'served' && item.status !== 'cancelled') {
            return item;
          }
        }
      }
    }
    return null;
  }

  function _findMatchingMenuItem(transcript) {
    var best = null;
    var bestLen = 0;
    for (var i = 0; i < _menuItems.length; i++) {
      var item = _menuItems[i];
      if (!item.name || !item.menuItemId) continue;
      if (_itemNameMatch(item.name, transcript) && item.name.length > bestLen) {
        best = item;
        bestLen = item.name.length;
      }
    }
    return best;
  }

  // ── item_idで品目を検索 ──
  function _findItemById(itemId) {
    for (var i = 0; i < _lastOrders.length; i++) {
      var items = _lastOrders[i].items || [];
      for (var j = 0; j < items.length; j++) {
        if (items[j].item_id === itemId) return items[j];
      }
    }
    return null;
  }

  // ── ステータス表示 ──
  function _showStatus(msg, autoHideMs) {
    if (!_statusEl) return;
    _statusEl.textContent = msg;
    _statusEl.style.display = '';

    if (_statusEl._timer) clearTimeout(_statusEl._timer);
    if (autoHideMs > 0) {
      _statusEl._timer = setTimeout(function () {
        _statusEl.style.display = 'none';
      }, autoHideMs);
    }
  }

  function _hideStatus() {
    if (_statusEl) {
      _statusEl.style.display = 'none';
      if (_statusEl._timer) clearTimeout(_statusEl._timer);
    }
  }

  // ── 店舗変更時にAI設定を再取得 ──
  function onStoreChange() {
    _stopContinuousListening();
    _aiConfigured = false;
    _aiConfigLoaded = false;
    _menuItems = [];
    _menuLoaded = false;
    _checkAiConfig();
    _loadMenu();
  }

  return {
    init: init,
    updateOrders: updateOrders,
    onStoreChange: onStoreChange,
    setSensitivity: setSensitivity,
    unlockAudio: _unlockAudio
  };
})();
