/**
 * AIアシスタント — SNS投稿文生成 + 売上トレンド分析
 * Gemini API 連携
 *
 * 依存: AdminApi, StoreSelector, Utils
 * ES5 IIFE パターン
 */
var AiAssistant = (function () {
  'use strict';

  var _container = null;
  var _apiConfigured = false;
  var _settingsLoaded = false;

  // SNS用DOM参照
  var _inputStore = null;
  var _inputMenu = null;
  var _inputAppeal = null;
  var _btnInsta = null;
  var _btnX = null;
  var _resultArea = null;
  var _btnCopy = null;
  var _copyMsg = null;
  var _statusMsg = null;

  // 分析用DOM参照
  var _periodBtns = null;
  var _btnAnalyze = null;
  var _analysisArea = null;
  var _selectedPeriod = 'week';

  // 競合調査用
  var _btnCompetition = null;
  var _compArea = null;
  var _storeName = '';
  var _storeAddress = '';
  var _placesApiKey = '';
  var _selectedRadius = 1000;

  // 需要予測用
  var _btnDemand = null;
  var _demandArea = null;

  // owner判定用
  var _isOwner = false;
  var _stores = [];

  // 生テキスト保持（コピー用）
  var _rawResults = { sns: '', analysis: '', comp: '', demand: '' };

  function init(container) {
    _container = container;
  }

  function load() {
    if (!_container) return;
    _render();
    _detectRoleAndLoad();
  }

  // ── ロール判定＆初期化 ──
  function _detectRoleAndLoad() {
    fetch('../../api/auth/me.php', { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data && json.data.user && json.data.user.role === 'owner') {
          _isOwner = true;
          _stores = json.data.stores || [];
          _renderOwnerDropdowns();
        }
        _loadSettings();
      })
      .catch(function () {
        _loadSettings();
      });
  }

  // ── ownerドロップダウン描画 ──
  function _renderOwnerDropdowns() {
    var html = '';
    for (var i = 0; i < _stores.length; i++) {
      html += '<option value="' + Utils.escapeHtml(_stores[i].id) + '">'
            + Utils.escapeHtml(_stores[i].name) + '</option>';
    }

    var analyzeWrap = document.getElementById('ai-analyze-store-wrap');
    var analyzeSelect = document.getElementById('ai-analyze-store');
    if (analyzeWrap && analyzeSelect) {
      analyzeSelect.innerHTML = html;
      analyzeWrap.style.display = '';
    }

    var compWrap = document.getElementById('ai-comp-store-wrap');
    var compSelect = document.getElementById('ai-comp-store');
    if (compWrap && compSelect) {
      compSelect.innerHTML = html;
      compWrap.style.display = '';
      // 初回選択で住所取得
      if (_stores.length > 0) {
        _onCompStoreChange(_stores[0].id);
      }
    }

    var demandWrap = document.getElementById('ai-demand-store-wrap');
    var demandSelect = document.getElementById('ai-demand-store');
    if (demandWrap && demandSelect) {
      demandSelect.innerHTML = html;
      demandWrap.style.display = '';
    }

  }

  // ── 競合調査店舗変更時の住所取得 ──
  function _onCompStoreChange(storeId) {
    if (!storeId) return;
    _storeAddress = '';
    _storeName = '';

    fetch('../../api/store/settings.php?store_id=' + encodeURIComponent(storeId), { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (json.ok && json.data && json.data.settings) {
          _storeAddress = json.data.settings.receipt_address || '';
          _storeName = json.data.settings.receipt_store_name || '';
        }
        // P1-23: 住所登録状況に応じて案内バナー表示制御
        _updateCompAddressWarn();
      });
  }

  // P1-23: 住所未登録警告バナーの表示/非表示制御
  function _updateCompAddressWarn() {
    var warnEl = document.getElementById('ai-comp-address-warn');
    if (!warnEl) return;
    if (!_storeAddress) {
      warnEl.style.display = '';
    } else {
      warnEl.style.display = 'none';
    }
  }

  // ── 結果カード用クラス名（Batch-A2: 旧 cssText 文字列から class 名に移行）──
  var _snsCardClass      = 'ai-result-card ai-result-card--sns';
  var _analysisCardClass = 'ai-result-card ai-result-card--analysis';
  var _compCardClass     = 'ai-result-card ai-result-card--comp';
  var _demandCardClass   = 'ai-result-card ai-result-card--demand';
  var _loadingCardClass  = 'ai-result-card ai-result-card--loading';

  // ── UI描画 ──
  // Batch-A2 (2026-04-22): inline style を admin.css 側の class に移行。
  // ただし #ai-status-msg / #ai-result-wrap / #ai-*-wrap / #ai-copy-msg /
  // #ai-comp-address-warn は style.display の動的トグルに依存するため、
  // 初期 display:none のみ inline で残す (toggle ロジックを壊さない)。
  function _render() {
    _container.innerHTML = ''
      + '<div class="ai-shell">'

      // 旧 AI ヘルプデスク UI は 2026-04-25 に retire 済。

      // ── SNS投稿文生成 ──

      + '<div class="ai-tool-header">'
      +   '<div class="ai-tool-header__icon ai-tool-header__icon--sns">&#x270D;</div>'
      +   '<div>'
      +     '<h3 class="ai-tool-header__title">SNS投稿文生成</h3>'
      +     '<p class="ai-tool-header__subtitle">AIが魅力的な投稿文を自動生成します</p>'
      +   '</div>'
      + '</div>'

      + '<div id="ai-status-msg" class="ai-status-msg" style="display:none;"></div>'

      // フォーム
      + '<div class="ai-form-card">'
      +   '<div class="ai-field">'
      +     '<label class="ai-field__label">店舗名</label>'
      +     '<input type="text" id="ai-input-store" class="ai-field__input" placeholder="例：松の屋 渋谷店">'
      +   '</div>'
      +   '<div class="ai-field">'
      +     '<label class="ai-field__label">今日のおすすめメニュー</label>'
      +     '<input type="text" id="ai-input-menu" class="ai-field__input" placeholder="例：特製焼き魚定食、季節の天ぷら盛り合わせ">'
      +   '</div>'
      +   '<div class="ai-field">'
      +     '<label class="ai-field__label">アピールポイント <span class="ai-field__label-opt">（任意）</span></label>'
      +     '<input type="text" id="ai-input-appeal" class="ai-field__input" placeholder="例：本日限定！数量限定でご提供">'
      +   '</div>'
      + '</div>'

      + '<div class="ai-action-row">'
      +   '<button id="ai-btn-insta" class="btn ai-sns-btn ai-sns-btn--insta" disabled>Instagram</button>'
      +   '<button id="ai-btn-x" class="btn ai-sns-btn ai-sns-btn--x" disabled>X (Twitter)</button>'
      + '</div>'

      // SNS結果エリア
      + '<div id="ai-result-wrap" style="display:none;">'
      +   '<div class="ai-result-header">'
      +     '<span class="ai-result-header__label">生成結果</span>'
      +     '<span class="ai-result-header__tools">'
      +       '<span id="ai-copy-msg" class="ai-result-copy-msg" style="display:none;">Copied!</span>'
      +       '<button id="ai-btn-copy" class="btn ai-result-copy-btn">&#x1F4CB; コピー</button>'
      +     '</span>'
      +   '</div>'
      +   '<div id="ai-result-area" class="ai-result-card ai-result-card--sns"></div>'
      + '</div>'

      // ── 売上・注文トレンド分析 ──
      + '<hr class="ai-divider">'

      + '<div class="ai-tool-header">'
      +   '<div class="ai-tool-header__icon ai-tool-header__icon--analysis">&#x1F4CA;</div>'
      +   '<div>'
      +     '<h3 class="ai-tool-header__title">売上・注文トレンド分析</h3>'
      +     '<p class="ai-tool-header__subtitle">売上データをAIが分析してインサイトを提供</p>'
      +   '</div>'
      + '</div>'

      + '<div id="ai-analyze-store-wrap" class="ai-field" style="display:none;">'
      +   '<label class="ai-field__label">店舗</label>'
      +   '<select id="ai-analyze-store" class="form-input ai-field__input"></select>'
      + '</div>'

      + '<div class="ai-field">'
      +   '<label class="ai-field__label">期間</label>'
      +   '<div id="ai-period-btns" class="ai-segment-row">'
      +     '<button class="btn ai-period-btn ai-segment-btn" data-period="today">今日</button>'
      +     '<button class="btn btn-primary ai-period-btn ai-segment-btn" data-period="week">今週</button>'
      +     '<button class="btn ai-period-btn ai-segment-btn" data-period="month">今月</button>'
      +   '</div>'
      + '</div>'

      + '<div class="ai-action-row">'
      +   '<button id="ai-btn-analyze" class="btn btn-primary ai-primary-btn" disabled>AIに分析させる</button>'
      + '</div>'

      + '<div id="ai-analysis-wrap" style="display:none;">'
      +   '<div class="ai-result-label">分析結果</div>'
      +   '<div id="ai-analysis-area" class="ai-result-card ai-result-card--analysis"></div>'
      + '</div>'

      // ── 周辺競合調査 ──
      + '<hr class="ai-divider">'

      + '<div class="ai-tool-header">'
      +   '<div class="ai-tool-header__icon ai-tool-header__icon--comp">&#x1F50D;</div>'
      +   '<div>'
      +     '<h3 class="ai-tool-header__title">周辺競合調査</h3>'
      +     '<p class="ai-tool-header__subtitle">Google Places + AIで競合店を分析</p>'
      +   '</div>'
      + '</div>'

      + '<div id="ai-comp-store-wrap" class="ai-field" style="display:none;">'
      +   '<label class="ai-field__label">店舗</label>'
      +   '<select id="ai-comp-store" class="form-input ai-field__input"></select>'
      + '</div>'
      + '<p class="ai-help-text">店舗の住所は店舗設定から自動取得します。</p>'

      // P1-23: 住所未登録時の案内バナー（_updateCompAddressWarn() で表示制御）
      + '<div id="ai-comp-address-warn" class="ai-address-warn" style="display:none;">'
      +   '&#x26A0;&#xFE0F; 店舗住所が未登録です。競合調査には住所情報が必要です。'
      +   '<a href="#" id="ai-comp-go-settings" class="ai-address-warn__link">店舗設定へ</a>'
      + '</div>'

      + '<div class="ai-field">'
      +   '<label class="ai-field__label">調査半径</label>'
      +   '<div id="ai-radius-btns" class="ai-segment-row">'
      +     '<button class="btn ai-radius-btn ai-segment-btn" data-radius="500">500m</button>'
      +     '<button class="btn btn-primary ai-radius-btn ai-segment-btn" data-radius="1000">1km</button>'
      +     '<button class="btn ai-radius-btn ai-segment-btn" data-radius="2000">2km</button>'
      +   '</div>'
      + '</div>'

      + '<div class="ai-action-row">'
      +   '<button id="ai-btn-competition" class="btn btn-primary ai-primary-btn" disabled>周辺の競合店を調査する</button>'
      + '</div>'

      + '<div id="ai-comp-wrap" style="display:none;">'
      +   '<div class="ai-result-label">調査結果</div>'
      +   '<div id="ai-comp-area" class="ai-result-card ai-result-card--comp"></div>'
      + '</div>'

      // ── 需要予測・発注提案 ──
      + '<hr class="ai-divider">'

      + '<div class="ai-tool-header">'
      +   '<div class="ai-tool-header__icon ai-tool-header__icon--demand">&#x1F4E6;</div>'
      +   '<div>'
      +     '<h3 class="ai-tool-header__title">需要予測・発注提案</h3>'
      +     '<p class="ai-tool-header__subtitle">過去の売上データとレシピ情報からAIが需要を予測</p>'
      +   '</div>'
      + '</div>'

      + '<div id="ai-demand-store-wrap" class="ai-field" style="display:none;">'
      +   '<label class="ai-field__label">店舗</label>'
      +   '<select id="ai-demand-store" class="form-input ai-field__input"></select>'
      + '</div>'

      + '<div class="ai-action-row">'
      +   '<button id="ai-btn-demand" class="btn btn-primary ai-primary-btn" disabled>需要予測を実行</button>'
      + '</div>'

      + '<div id="ai-demand-wrap" style="display:none;">'
      +   '<div class="ai-result-label">予測結果</div>'
      +   '<div id="ai-demand-area" class="ai-result-card ai-result-card--demand"></div>'
      + '</div>'

      + '</div>';

    // SNS DOM
    _inputStore = document.getElementById('ai-input-store');
    _inputMenu = document.getElementById('ai-input-menu');
    _inputAppeal = document.getElementById('ai-input-appeal');
    _btnInsta = document.getElementById('ai-btn-insta');
    _btnX = document.getElementById('ai-btn-x');
    _resultArea = document.getElementById('ai-result-area');
    _btnCopy = document.getElementById('ai-btn-copy');
    _copyMsg = document.getElementById('ai-copy-msg');
    _statusMsg = document.getElementById('ai-status-msg');

    // 分析DOM
    _btnAnalyze = document.getElementById('ai-btn-analyze');
    _analysisArea = document.getElementById('ai-analysis-area');

    // 旧 AI ヘルプデスク導線は retire 済。

    // SNSイベント
    _btnInsta.addEventListener('click', function () { _generate('instagram'); });
    _btnX.addEventListener('click', function () { _generate('x'); });
    _btnCopy.addEventListener('click', _copyToClipboard);

    // 期間ボタンイベント
    var periodWrap = document.getElementById('ai-period-btns');
    periodWrap.addEventListener('click', function (e) {
      var btn = e.target.closest('.ai-period-btn');
      if (!btn) return;
      var btns = periodWrap.querySelectorAll('.ai-period-btn');
      for (var i = 0; i < btns.length; i++) {
        btns[i].className = 'btn ai-period-btn';
      }
      btn.className = 'btn btn-primary ai-period-btn';
      _selectedPeriod = btn.dataset.period;
    });

    // 分析ボタンイベント
    _btnAnalyze.addEventListener('click', _analyze);

    // 競合調査DOM
    _btnCompetition = document.getElementById('ai-btn-competition');
    _compArea = document.getElementById('ai-comp-area');

    // 半径ボタンイベント
    var radiusWrap = document.getElementById('ai-radius-btns');
    radiusWrap.addEventListener('click', function (e) {
      var btn = e.target.closest('.ai-radius-btn');
      if (!btn) return;
      var btns = radiusWrap.querySelectorAll('.ai-radius-btn');
      for (var i = 0; i < btns.length; i++) {
        btns[i].className = 'btn ai-radius-btn';
      }
      btn.className = 'btn btn-primary ai-radius-btn';
      _selectedRadius = parseInt(btn.dataset.radius, 10);
    });

    // 競合調査ボタンイベント
    _btnCompetition.addEventListener('click', _compResearch);

    // P1-23: 案内バナーの「店舗設定へ」リンク
    var goSettingsLink = document.getElementById('ai-comp-go-settings');
    if (goSettingsLink) {
      goSettingsLink.addEventListener('click', function (e) {
        e.preventDefault();
        if (_isOwner) {
          // owner-dashboard の「店舗管理」タブへ
          var ownerTab = document.querySelector('[data-tab="stores"]');
          if (ownerTab) ownerTab.click();
        } else {
          // dashboard では config グループを開くと最初の sub-tab (店舗設定) が自動選択される
          var configGroup = document.querySelector('.tab-nav__btn[data-group="config"]');
          if (configGroup) {
            configGroup.click();
          } else {
            // フォールバック: settings サブタブ直接
            var settingsTab = document.querySelector('[data-tab="settings"]');
            if (settingsTab) settingsTab.click();
          }
        }
      });
    }

    // 競合調査店舗ドロップダウン変更（owner用）
    var compStoreEl = document.getElementById('ai-comp-store');
    if (compStoreEl) {
      compStoreEl.addEventListener('change', function () {
        _onCompStoreChange(compStoreEl.value);
      });
    }

    // 需要予測DOM
    _btnDemand = document.getElementById('ai-btn-demand');
    _demandArea = document.getElementById('ai-demand-area');

    // 需要予測ボタンイベント
    _btnDemand.addEventListener('click', _runDemandForecast);

  }

  // ── 設定読み込み（AI利用可否フラグ + 店舗情報） ──
  // ※APIキー本体はサーバー側（api/store/ai-generate.php）に保持。ブラウザには渡さない
  function _loadSettings() {
    var storeId = AdminApi.getCurrentStore();
    // ownerの場合は最初の店舗を使用
    if (!storeId && _isOwner && _stores.length > 0) {
      storeId = _stores[0].id;
    }
    if (!storeId) {
      _showMsg('店舗を選択してください', 'warn');
      return;
    }

    _settingsLoaded = false;
    _apiConfigured = false;
    _setButtonsEnabled(false);

    fetch('../../api/store/settings.php?store_id=' + encodeURIComponent(storeId), { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        _settingsLoaded = true;
        if (json.ok && json.data && json.data.settings) {
          // POSLA共通APIキーが設定済みかフラグで判定
          _apiConfigured = json.data.settings.ai_configured === true
                        || json.data.settings.ai_api_key_set === true;
          if (_apiConfigured) {
            _setButtonsEnabled(true);
            _hideMsg();
          } else {
            _showMsg('AIサービスは現在利用できません。POSLA運営にお問い合わせください', 'warn');
          }
          // 店舗設定から住所・店舗名を取得
          _storeAddress = json.data.settings.receipt_address || '';
          _storeName = json.data.settings.receipt_store_name || '';
          // SNS店舗名をデフォルト入力（空欄の場合のみ）
          if (_inputStore && !_inputStore.value && _storeName) {
            _inputStore.value = _storeName;
          }
          // P1-23: 住所登録状況に応じて案内バナー表示制御
          _updateCompAddressWarn();
        } else {
          _showMsg('AIサービスは現在利用できません。POSLA運営にお問い合わせください', 'warn');
        }
      })
      .catch(function () {
        _settingsLoaded = true;
        _showMsg('設定の取得に失敗しました', 'error');
      });
  }

  // ══════════════════════════════════
  // テキスト→モダンHTML変換
  // ══════════════════════════════════

  // テーマ色取得
  function _getAccentColor(type) {
    var c = { analysis: '#43a047', comp: '#1e88e5', sns: '#e91e63' };
    return c[type] || c.analysis;
  }

  function _formatResponse(text, type) {
    if (!text) return '';
    var lines = text.split('\n');
    var html = '';
    var inList = false;
    var tableLines = [];

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var trimmed = line.trim();

      // ── テーブル行検出（|で始まり|で終わる） ──
      var isTableRow = /^\|.+\|$/.test(trimmed);
      if (isTableRow) {
        if (inList) { html += '</ul>'; inList = false; }
        tableLines.push(trimmed);
        continue;
      }

      // テーブルブロック終了 → レンダリング
      if (tableLines.length > 0) {
        html += _renderTable(tableLines, type);
        tableLines = [];
      }

      if (!trimmed) {
        if (inList) { html += '</ul>'; inList = false; }
        html += '<div class="ai-content-spacer"></div>';
        continue;
      }

      // 水平線（--- / ===）
      if (/^[-=]{3,}$/.test(trimmed)) {
        if (inList) { html += '</ul>'; inList = false; }
        html += '<hr class="ai-content-hr">';
        continue;
      }

      // 【セクション見出し】
      var sectionMatch = trimmed.match(/^【(.+?)】(.*)$/);
      if (sectionMatch) {
        if (inList) { html += '</ul>'; inList = false; }
        var sectionThemes = {
          analysis: { bg: '#e8f5e9', border: '#43a047', color: '#1b5e20', icon: '&#x1F4CA;' },
          comp:     { bg: '#e3f2fd', border: '#1e88e5', color: '#0d47a1', icon: '&#x1F50D;' },
          sns:      { bg: '#fce4ec', border: '#e91e63', color: '#880e4f', icon: '&#x270D;' }
        };
        var st = sectionThemes[type] || sectionThemes.analysis;
        html += '<div style="margin:1.25rem 0 0.625rem;padding:0.625rem 0.875rem;background:' + st.bg + ';border-left:4px solid ' + st.border + ';border-radius:0 8px 8px 0;display:flex;align-items:center;gap:0.5rem;">'
              + '<span style="font-size:1rem;">' + st.icon + '</span>'
              + '<span style="font-weight:700;font-size:0.925rem;color:' + st.color + ';">' + Utils.escapeHtml(sectionMatch[1]) + '</span>'
              + '</div>';
        if (sectionMatch[2].trim()) {
          html += '<p style="margin:0.25rem 0 0.5rem;font-size:0.875rem;color:#444;">' + _inlineFormat(sectionMatch[2].trim()) + '</p>';
        }
        continue;
      }

      // Markdownの ## 見出し
      var h2Match = trimmed.match(/^#{1,3}\s+(.+)$/);
      if (h2Match) {
        if (inList) { html += '</ul>'; inList = false; }
        var hColors = { comp: '#0d47a1', analysis: '#1b5e20', sns: '#880e4f' };
        var hc = hColors[type] || hColors.analysis;
        html += '<div style="margin:1.25rem 0 0.625rem;font-weight:700;font-size:0.95rem;color:' + hc + ';padding-bottom:0.3rem;border-bottom:2px solid rgba(0,0,0,0.08);">'
              + _inlineFormat(h2Match[1])
              + '</div>';
        continue;
      }

      // 箇条書き（- / ・ / * / ●）
      var bulletMatch = trimmed.match(/^[-・\*●]\s*(.+)$/);
      if (bulletMatch) {
        if (!inList) {
          html += '<ul style="margin:0.375rem 0;padding-left:0;list-style:none;">';
          inList = true;
        }
        html += '<li style="margin:0.3rem 0;font-size:0.875rem;color:#444;padding-left:1.25rem;position:relative;line-height:1.7;">'
              + '<span style="position:absolute;left:0.25rem;top:0.2rem;color:' + _getAccentColor(type) + ';font-size:0.6rem;">&#x25CF;</span>'
              + _inlineFormat(bulletMatch[1])
              + '</li>';
        continue;
      }

      // 番号付きリスト
      var numMatch = trimmed.match(/^(\d+)[.）]\s*(.+)$/);
      if (numMatch) {
        if (!inList) {
          html += '<ul style="margin:0.375rem 0;padding-left:0;list-style:none;">';
          inList = true;
        }
        html += '<li style="margin:0.4rem 0;font-size:0.875rem;color:#444;display:flex;gap:0.625rem;align-items:flex-start;">'
              + '<span style="flex-shrink:0;width:1.625rem;height:1.625rem;border-radius:50%;background:' + _getAccentColor(type) + ';display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;color:#fff;">' + numMatch[1] + '</span>'
              + '<span style="flex:1;padding-top:0.15rem;line-height:1.7;">' + _inlineFormat(numMatch[2]) + '</span>'
              + '</li>';
        continue;
      }

      // 通常テキスト
      if (inList) { html += '</ul>'; inList = false; }
      html += '<p style="margin:0.3rem 0;font-size:0.875rem;color:#444;line-height:1.8;">' + _inlineFormat(trimmed) + '</p>';
    }

    // 末尾テーブル処理
    if (tableLines.length > 0) {
      html += _renderTable(tableLines, type);
    }
    if (inList) html += '</ul>';
    return html;
  }

  // ── Markdownテーブル → HTMLテーブル変換 ──
  function _renderTable(lines, type) {
    // セパレータ行（| --- | --- |）を除外してデータ行のみ抽出
    var dataLines = [];
    for (var i = 0; i < lines.length; i++) {
      var stripped = lines[i].replace(/[\|\s\-:+]/g, '');
      if (stripped.length === 0) continue;
      dataLines.push(lines[i]);
    }
    if (dataLines.length === 0) return '';

    // 各行をセル配列にパース
    var rows = [];
    for (var i = 0; i < dataLines.length; i++) {
      var raw = dataLines[i];
      if (raw.charAt(0) === '|') raw = raw.substring(1);
      if (raw.charAt(raw.length - 1) === '|') raw = raw.substring(0, raw.length - 1);
      var cells = raw.split('|');
      var cleaned = [];
      for (var j = 0; j < cells.length; j++) {
        cleaned.push(cells[j].trim());
      }
      rows.push(cleaned);
    }
    if (rows.length === 0) return '';

    // テーマ色
    var tc = {
      analysis: { hBg: '#c8e6c9', hColor: '#1b5e20', border: '#a5d6a7', stripe: '#f1f8e9' },
      comp:     { hBg: '#bbdefb', hColor: '#0d47a1', border: '#90caf9', stripe: '#e3f2fd' },
      sns:      { hBg: '#f8bbd0', hColor: '#880e4f', border: '#f48fb1', stripe: '#fce4ec' }
    };
    var c = tc[type] || tc.analysis;

    var html = '<div style="overflow-x:auto;margin:0.875rem 0;border-radius:10px;border:1px solid ' + c.border + ';box-shadow:0 2px 8px rgba(0,0,0,0.06);">'
      + '<table style="width:100%;border-collapse:collapse;font-size:0.82rem;">';

    // ヘッダー行
    html += '<thead><tr style="background:' + c.hBg + ';">';
    for (var j = 0; j < rows[0].length; j++) {
      html += '<th style="padding:0.625rem 0.875rem;text-align:left;font-weight:700;font-size:0.8rem;color:' + c.hColor + ';white-space:nowrap;border-bottom:2px solid ' + c.border + ';">'
        + _inlineFormat(rows[0][j]) + '</th>';
    }
    html += '</tr></thead>';

    // データ行
    html += '<tbody>';
    for (var i = 1; i < rows.length; i++) {
      var bg = i % 2 === 0 ? c.stripe : 'transparent';
      html += '<tr style="background:' + bg + ';">';
      for (var j = 0; j < rows[i].length; j++) {
        var tdStyle = 'padding:0.5rem 0.875rem;color:#444;border-bottom:1px solid rgba(0,0,0,0.05);';
        if (j === 0) tdStyle += 'font-weight:600;color:#333;white-space:nowrap;';
        html += '<td style="' + tdStyle + '">' + _inlineFormat(rows[i][j]) + '</td>';
      }
      html += '</tr>';
    }
    html += '</tbody></table></div>';

    return html;
  }

  // ── インラインフォーマット（太字・金額・評価・脅威度バッジ）──
  function _inlineFormat(text) {
    var escaped = Utils.escapeHtml(text);
    // **太字**
    escaped = escaped.replace(/\*\*(.+?)\*\*/g, '<strong style="color:#222;">$1</strong>');
    // 金額ハイライト（¥1,234 or ￥1,234）
    escaped = escaped.replace(/([\u00a5\uffe5][0-9,]+)/g,
      '<span style="font-weight:700;color:#e65100;font-family:\'SF Mono\',Consolas,monospace;background:rgba(230,81,0,0.08);padding:0 3px;border-radius:3px;">$1</span>');
    // パーセンテージ
    escaped = escaped.replace(/(\d+\.?\d*%)/g,
      '<span style="font-weight:600;color:#1565c0;background:rgba(21,101,192,0.08);padding:0 3px;border-radius:3px;">$1</span>');
    // 評価バッジ（評価4.2）
    escaped = escaped.replace(/(評価)\s*([\d.]+)/g,
      '<span style="display:inline-flex;align-items:center;gap:2px;font-weight:600;color:#f57f17;background:rgba(245,127,23,0.1);padding:1px 6px;border-radius:4px;font-size:0.9em;">&#x2B50;$2</span>');
    // 星記号
    escaped = escaped.replace(/([\u2605\u2606]+)/g, '<span style="color:#f9a825;">$1</span>');
    // 脅威度バッジ
    escaped = escaped.replace(/(脅威度)\s*[:：]\s*(高|中|低)/g, function (m, label, level) {
      var bc = { '高': '#c62828', '中': '#e65100', '低': '#2e7d32' };
      return '<span style="font-weight:600;">' + label + ': </span>'
        + '<span style="display:inline-block;font-weight:700;color:#fff;background:' + (bc[level] || '#666') + ';padding:1px 8px;border-radius:4px;font-size:0.82em;">' + level + '</span>';
    });
    // 口コミ件数バッジ（口コミ123件）
    escaped = escaped.replace(/(口コミ)\s*(\d+)\s*(件)/g,
      '<span style="display:inline-flex;align-items:center;gap:2px;font-weight:600;color:#5c6bc0;background:rgba(92,107,192,0.1);padding:1px 6px;border-radius:4px;font-size:0.9em;">&#x1F4AC;$2$3</span>');
    return escaped;
  }

  // ローディング表示 (Batch-A2: class 駆動、keyframes は admin.css 側で定義)
  function _showLoading(el, msg) {
    el.className = _loadingCardClass;
    el.innerHTML = '<div class="ai-spinner-wrap">'
      + '<div class="ai-spinner"></div>'
      + '<div class="ai-spinner-label">' + Utils.escapeHtml(msg) + '</div>'
      + '</div>';
  }

  // 結果表示
  function _showResult(el, text, type, cardClass) {
    el.className = cardClass;
    el.innerHTML = _formatResponse(text, type);
  }

  // ══════════════════════════════════
  // SNS投稿文生成
  // ══════════════════════════════════

  function _generate(platform) {
    var storeName = _inputStore.value.trim();
    var menu = _inputMenu.value.trim();
    var appeal = _inputAppeal.value.trim();

    if (!storeName) { _inputStore.focus(); return; }
    if (!menu) { _inputMenu.focus(); return; }
    if (!_apiConfigured) {
      _showMsg('AIサービスは現在利用できません。POSLA運営にお問い合わせください', 'warn');
      return;
    }

    var info = '店舗名：' + storeName + '\n'
      + 'おすすめメニュー：' + menu + '\n'
      + 'アピールポイント：' + (appeal || 'なし');

    var prompt;
    if (platform === 'instagram') {
      prompt = 'あなたは飲食店のSNS担当者です。\n'
        + 'Instagram投稿文のみを出力してください。\n'
        + '前置き・解説・補足・箇条書きは一切不要です。投稿文だけを出力してください。\n\n'
        + info + '\n\n'
        + '条件：\n'
        + '- 絵文字を適度に使う\n'
        + '- ハッシュタグを5つ以上末尾に含める\n'
        + '- 投稿文のみ出力（説明・コメント・補足は禁止）';
    } else {
      prompt = 'あなたは飲食店のSNS担当者です。\n'
        + 'X（Twitter）投稿文のみを出力してください。\n'
        + '前置き・解説・補足は一切不要です。投稿文だけを出力してください。\n\n'
        + info + '\n\n'
        + '条件：\n'
        + '- 140字以内\n'
        + '- ハッシュタグを2〜3個含める\n'
        + '- 投稿文のみ出力（説明・コメント・補足は禁止）';
    }

    _setButtonsEnabled(false);
    document.getElementById('ai-result-wrap').style.display = '';
    _showLoading(_resultArea, 'AI生成中...');

    _callGemini(prompt, function (text) {
      var cleaned = _cleanResponse(text);
      _rawResults.sns = cleaned;
      // SNSはそのままテキスト表示（改行保持、フォーマットなし）
      _resultArea.className = _snsCardClass + ' ai-result-card--sns-raw';
      _resultArea.innerHTML = Utils.escapeHtml(cleaned);
      _setButtonsEnabled(true);
    }, function (err) {
      _rawResults.sns = '';
      _resultArea.className = _snsCardClass;
      _resultArea.innerHTML = '<div class="ai-state-error">エラー: ' + Utils.escapeHtml(err) + '</div>';
      _setButtonsEnabled(true);
    });
  }

  // ══════════════════════════════════
  // 売上・注文トレンド分析
  // ══════════════════════════════════

  function _analyze() {
    var storeId = AdminApi.getCurrentStore();
    // ownerの場合は分析用ドロップダウンから取得
    if (_isOwner) {
      var sel = document.getElementById('ai-analyze-store');
      storeId = sel ? sel.value : '';
    }
    if (!storeId) {
      _showMsg('店舗を選択してください', 'warn');
      return;
    }
    if (!_apiConfigured) {
      _showMsg('AIサービスは現在利用できません。POSLA運営にお問い合わせください', 'warn');
      return;
    }

    var range = _calcPeriod(_selectedPeriod);

    _setButtonsEnabled(false);
    document.getElementById('ai-analysis-wrap').style.display = '';
    _showLoading(_analysisArea, 'AI分析中...');

    // 売上データ取得
    var url = '../../api/store/sales-report.php?store_id=' + encodeURIComponent(storeId)
      + '&from=' + encodeURIComponent(range.from) + '&to=' + encodeURIComponent(range.to);

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (!json.ok || !json.data) {
          _rawResults.analysis = '';
          _analysisArea.className = _analysisCardClass;
          _analysisArea.innerHTML = '<div class="ai-state-error">売上データの取得に失敗しました</div>';
          _setButtonsEnabled(true);
          return;
        }

        var data = json.data;

        // データ空チェック
        if (!data.summary || data.summary.orderCount === 0) {
          _rawResults.analysis = '';
          _analysisArea.className = _analysisCardClass;
          _analysisArea.innerHTML = '<div class="ai-state-empty">対象期間のデータがありません</div>';
          _setButtonsEnabled(true);
          return;
        }

        _analyzeWithGemini(data, range);
      })
      .catch(function () {
        _rawResults.analysis = '';
        _analysisArea.className = _analysisCardClass;
        _analysisArea.innerHTML = '<div class="ai-state-error">売上データの取得に失敗しました</div>';
        _setButtonsEnabled(true);
      });
  }

  function _analyzeWithGemini(data, range) {
    var periodLabel = range.from + ' 〜 ' + range.to;

    // Geminiに送るデータを整形（トークン節約）
    var salesData = {
      summary: data.summary,
      itemRanking: (data.itemRanking || []).slice(0, 10),
      hourly: data.hourly || [],
      serviceTime: data.serviceTimeAnalysis || null
    };

    var prompt = 'あなたは飲食店の経営アドバイザーです。\n'
      + '以下の売上データを分析して、オーナーへの報告文を日本語で作成してください。\n'
      + '前置き・補足・箇条書きの解説は不要です。報告文のみ出力してください。\n\n'
      + '期間：' + periodLabel + '\n'
      + '売上データ：' + JSON.stringify(salesData) + '\n\n'
      + '以下の観点で分析してください：\n'
      + '- 売上の傾向（前期比較があれば言及）\n'
      + '- よく売れているメニューとその特徴\n'
      + '- 注意すべきポイントや改善提案\n'
      + '- 端的に3〜5文でまとめること';

    _callGemini(prompt, function (text) {
      var cleaned = _cleanResponse(text);
      _rawResults.analysis = cleaned;
      _showResult(_analysisArea, cleaned, 'analysis', _analysisCardClass);
      _setButtonsEnabled(true);
    }, function (err) {
      _rawResults.analysis = '';
      _analysisArea.className = _analysisCardClass;
      _analysisArea.innerHTML = '<div class="ai-state-error">エラー: ' + Utils.escapeHtml(err) + '</div>';
      _setButtonsEnabled(true);
    });
  }

  // ══════════════════════════════════
  // 周辺競合調査
  // ══════════════════════════════════

  function _compResearch() {
    // 店舗ID取得（owner=ドロップダウン、manager=StoreSelector）
    var storeId = AdminApi.getCurrentStore();
    if (_isOwner) {
      var sel = document.getElementById('ai-comp-store');
      storeId = sel ? sel.value : '';
    }
    if (!storeId) {
      _showMsg('店舗を選択してください', 'warn');
      return;
    }
    if (!_storeAddress) {
      _showMsg('店舗設定で住所を登録してください', 'warn');
      return;
    }
    if (!_apiConfigured) {
      _showMsg('AIサービスは現在利用できません。POSLA運営にお問い合わせください', 'warn');
      return;
    }

    _setButtonsEnabled(false);
    document.getElementById('ai-comp-wrap').style.display = '';
    _showLoading(_compArea, '調査中... 住所をジオコーディングしています');

    // Step 1: Geocoding via サーバープロキシ
    var geocodeUrl = '../../api/store/places-proxy.php?action=geocode'
      + '&store_id=' + encodeURIComponent(storeId)
      + '&address=' + encodeURIComponent(_storeAddress);

    fetch(geocodeUrl, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (b) {
          if (!b) throw new Error('空のレスポンス');
          try { return JSON.parse(b); } catch (e) { throw new Error('JSON解析エラー'); }
        });
      })
      .then(function (json) {
        if (!json.ok || !json.data || !json.data.geocode) {
          var msg = (json.error && json.error.message) || 'Geocoding失敗';
          throw new Error(msg);
        }
        var geocode = json.data.geocode;
        if (geocode.status !== 'OK' || !geocode.results || !geocode.results[0]) {
          throw new Error('住所のジオコーディングに失敗しました（status: ' + (geocode.status || 'unknown') + '）');
        }
        var loc = geocode.results[0].geometry.location;
        _showLoading(_compArea, '調査中... 周辺の飲食店を検索しています');
        return _nearbySearch(loc.lat, loc.lng, storeId);
      })
      .then(function (places) {
        _analyzeCompetitors(places);
      })
      .catch(function (err) {
        _rawResults.comp = '';
        _compArea.className = _compCardClass;
        _compArea.innerHTML = '<div class="ai-state-error">エラー: ' + Utils.escapeHtml(err.message) + '</div>';
        _setButtonsEnabled(true);
      });
  }

  // Step 2: Places API Nearby Search via サーバープロキシ
  function _nearbySearch(lat, lng, storeId) {
    var url = '../../api/store/places-proxy.php?action=nearby'
      + '&store_id=' + encodeURIComponent(storeId)
      + '&lat=' + lat + '&lng=' + lng
      + '&radius=' + _selectedRadius;

    return fetch(url, { credentials: 'same-origin' })
    .then(function (r) {
      return r.text().then(function (b) {
        if (!b) throw new Error('空のレスポンス');
        try { return JSON.parse(b); } catch (e) { throw new Error('JSON解析エラー'); }
      });
    })
    .then(function (json) {
      if (!json.ok || !json.data || !json.data.places) {
        var msg = (json.error && json.error.message) || 'Places API失敗';
        throw new Error(msg);
      }
      var places = json.data.places;
      if (places.status && places.status !== 'OK' && places.status !== 'ZERO_RESULTS') {
        throw new Error('Places APIエラー（status: ' + places.status + '）');
      }
      return places.results || [];
    });
  }

  // Step 3: Gemini分析
  function _analyzeCompetitors(places) {
    if (!places.length) {
      _rawResults.comp = '';
      _compArea.className = _compCardClass;
      _compArea.innerHTML = '<div class="ai-state-empty">周辺（半径' + _radiusLabel() + '）に飲食店が見つかりませんでした。半径を広げて再度お試しください。</div>';
      _setButtonsEnabled(true);
      return;
    }

    _showLoading(_compArea, '調査中... ' + places.length + '件の飲食店をAIが分析しています');

    // レガシーAPIのレスポンスを整形
    var summary = [];
    for (var i = 0; i < places.length; i++) {
      var p = places[i];
      // 飲食店のタイプを抽出（restaurant, cafe, bar等以外の汎用タグを除外）
      var types = (p.types || []).filter(function (t) {
        return ['point_of_interest', 'establishment', 'food'].indexOf(t) === -1;
      });
      summary.push({
        name: p.name || '不明',
        rating: p.rating || null,
        reviews: p.user_ratings_total || 0,
        price_level: p.price_level != null ? p.price_level : null,
        types: types.join(','),
        vicinity: p.vicinity || ''
      });
    }

    var storeLbl = (_storeName || '自店舗') + '（' + _storeAddress + '）';

    var prompt = 'あなたは飲食店の経営コンサルタントです。\n'
      + '以下の周辺競合店データを分析し、具体的なアクションプランを含む報告書を作成してください。\n'
      + '前置き・補足は不要です。報告書のみ出力してください。\n\n'
      + '自店舗：' + storeLbl + '\n'
      + '検索半径：' + _selectedRadius + 'm\n'
      + '周辺の飲食店リスト：' + JSON.stringify(summary) + '\n\n'
      + 'price_levelの目安: 0=無料, 1=〜¥1,000, 2=¥1,000〜3,000, 3=¥3,000〜5,000, 4=¥5,000〜\n'
      + 'price_levelがnullの店は、店名・ジャンル・立地からランチ/ディナーの客単価を推定してください。\n\n'
      + '以下の構成で報告してください：\n\n'
      + '【直接競合】同ジャンル・同価格帯の店を具体名で挙げ、評価・口コミ数・推定客単価から脅威度を判定\n'
      + '【価格比較表】主要競合5店の店名・推定ランチ単価・推定ディナー単価・評価を一覧で表示\n'
      + '【高評価店の強み】rating 4.0以上の店を具体名で挙げ、なぜ支持されているか推測\n'
      + '【集客チャンス】競合が弱い時間帯・曜日・客層の隙間を具体的に指摘\n'
      + '【即実行できるアクション3つ】メニュー・価格・販促の観点で今週から実行可能な施策\n\n'
      + '店名は必ず具体名で記載し、数値（評価・口コミ数・推定客単価）も併記すること。';

    _callGemini(prompt, function (text) {
      var cleaned = _cleanResponse(text);
      _rawResults.comp = cleaned;
      _showResult(_compArea, cleaned, 'comp', _compCardClass);
      _setButtonsEnabled(true);
    }, function (err) {
      _rawResults.comp = '';
      _compArea.className = _compCardClass;
      _compArea.innerHTML = '<div class="ai-state-error">エラー: ' + Utils.escapeHtml(err) + '</div>';
      _setButtonsEnabled(true);
    });
  }

  function _radiusLabel() {
    return _selectedRadius >= 1000
      ? (_selectedRadius / 1000) + 'km'
      : _selectedRadius + 'm';
  }


  // ── 期間計算 ──
  function _calcPeriod(period) {
    var today = new Date();
    var from, to;

    if (period === 'today') {
      from = _formatDate(today);
      to = _formatDate(today);
    } else if (period === 'week') {
      // 月曜日起算
      var day = today.getDay();
      var diffToMon = (day === 0) ? 6 : (day - 1);
      var monday = new Date(today);
      monday.setDate(today.getDate() - diffToMon);
      from = _formatDate(monday);
      to = _formatDate(today);
    } else {
      // 今月
      var monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
      from = _formatDate(monthStart);
      to = _formatDate(today);
    }

    return { from: from, to: to };
  }

  function _formatDate(d) {
    var y = d.getFullYear();
    var m = ('0' + (d.getMonth() + 1)).slice(-2);
    var day = ('0' + d.getDate()).slice(-2);
    return y + '-' + m + '-' + day;
  }

  // ══════════════════════════════════
  // 共通ユーティリティ
  // ══════════════════════════════════

  // ── Gemini API呼び出し（サーバープロキシ経由）──
  // ※APIキーはサーバー側で保持。ブラウザには露出しない
  function _callGemini(prompt, onSuccess, onError) {
    fetch('../../api/store/ai-generate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        prompt: prompt,
        temperature: 0.8,
        max_tokens: 1024
      })
    })
    .then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('空のレスポンス');
        try { return JSON.parse(body); } catch (e) { throw new Error('JSON解析エラー'); }
      });
    })
    .then(function (json) {
      if (!json.ok) {
        var msg = (json.error && json.error.message) || 'AI APIエラー';
        throw new Error(msg);
      }
      var text = (json.data && json.data.text) || '';
      onSuccess(text);
    })
    .catch(function (err) {
      onError(err.message);
    });
  }

  // ── レスポンス前置き除去 ──
  function _cleanResponse(text) {
    var lines = text.trim().split('\n');
    while (lines.length > 0) {
      var line = lines[0].trim();
      if (!line) {
        lines.shift();
        continue;
      }
      if (/^(はい|承知|以下|では|了解|かしこまり|わかりました)/.test(line)
          || /生成します/.test(line)
          || /作成します/.test(line)
          || /投稿文[をは]/.test(line)
          || /報告文[をは]/.test(line)
          || /分析します/.test(line)
          || /分析結果/.test(line)
          || /調査結果/.test(line)
          || /レポート[をは]/.test(line)
          || line === '---') {
        lines.shift();
        continue;
      }
      break;
    }
    return lines.join('\n').trim();
  }

  // ── クリップボードコピー ──
  function _copyToClipboard() {
    var text = _rawResults.sns;
    if (!text) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text);
    } else {
      // フォールバック: 一時textarea
      var tmp = document.createElement('textarea');
      tmp.value = text;
      tmp.style.position = 'fixed';
      tmp.style.left = '-9999px';
      document.body.appendChild(tmp);
      tmp.select();
      document.execCommand('copy');
      document.body.removeChild(tmp);
    }

    _copyMsg.style.display = '';
    setTimeout(function () { _copyMsg.style.display = 'none'; }, 2000);
  }

  // ── ボタン有効/無効 ──
  function _setButtonsEnabled(enabled) {
    if (_btnInsta) _btnInsta.disabled = !enabled;
    if (_btnX) _btnX.disabled = !enabled;
    if (_btnAnalyze) _btnAnalyze.disabled = !enabled;
    if (_btnCompetition) _btnCompetition.disabled = !enabled;
    if (_btnDemand) _btnDemand.disabled = !enabled;
  }

  // ── メッセージ表示 ──
  function _showMsg(text, type) {
    if (!_statusMsg) return;
    _statusMsg.textContent = text;
    _statusMsg.style.display = '';
    _statusMsg.classList.remove('ai-status-msg--error', 'ai-status-msg--warn');
    _statusMsg.classList.add(type === 'error' ? 'ai-status-msg--error' : 'ai-status-msg--warn');
  }

  function _hideMsg() {
    if (_statusMsg) _statusMsg.style.display = 'none';
  }

  // ══════════════════════════════════
  // 需要予測・発注提案
  // ══════════════════════════════════

  function _runDemandForecast() {
    var storeId = AdminApi.getCurrentStore();
    if (_isOwner) {
      var sel = document.getElementById('ai-demand-store');
      storeId = sel ? sel.value : '';
    }
    if (!storeId) {
      _showMsg('店舗を選択してください', 'warn');
      return;
    }
    if (!_apiConfigured) {
      _showMsg('AIサービスは現在利用できません。POSLA運営にお問い合わせください', 'warn');
      return;
    }

    _setButtonsEnabled(false);
    document.getElementById('ai-demand-wrap').style.display = '';
    _showLoading(_demandArea, 'データ収集中...');

    var url = '../../api/store/demand-forecast-data.php?store_id=' + encodeURIComponent(storeId);

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (body) {
          if (!body) return {};
          try { return JSON.parse(body); } catch (e) { return {}; }
        });
      })
      .then(function (json) {
        if (!json.ok || !json.data) {
          _rawResults.demand = '';
          _demandArea.className = _demandCardClass;
          _demandArea.innerHTML = '<div class="ai-state-error">データの取得に失敗しました</div>';
          _setButtonsEnabled(true);
          return;
        }

        var data = json.data;

        if (!data.dailySales || data.dailySales.length === 0) {
          _rawResults.demand = '';
          _demandArea.className = _demandCardClass;
          _demandArea.innerHTML = '<div class="ai-state-empty">売上データが不足しています。7日以上の運用データが溜まると精度が向上します。</div>';
          _setButtonsEnabled(true);
          return;
        }

        _forecastWithGemini(data);
      })
      .catch(function () {
        _rawResults.demand = '';
        _demandArea.className = _demandCardClass;
        _demandArea.innerHTML = '<div class="ai-state-error">データの取得に失敗しました</div>';
        _setButtonsEnabled(true);
      });
  }

  function _forecastWithGemini(data) {
    _showLoading(_demandArea, 'AIが需要を予測中...');

    // 日別売上データ整形
    var salesText = '';
    data.dailySales.forEach(function (d) {
      salesText += d.date + ': ';
      var items = [];
      d.items.forEach(function (it) {
        items.push(it.name + ' x' + it.qty);
      });
      salesText += items.join(', ') + '\n';
    });

    // 曜日パターン整形
    var weekdayText = '';
    data.weekdayPattern.forEach(function (w) {
      weekdayText += w.weekday + ': 注文数' + w.totalOrders + '件, 平均売上' + w.avgRevenue + '円\n';
    });

    // 在庫情報整形（低在庫優先で上位20件）
    var inventoryText = '';
    if (data.inventory && data.inventory.length > 0) {
      var sorted = data.inventory.slice().sort(function (a, b) {
        var ratioA = a.lowStockThreshold > 0 ? a.stockQuantity / a.lowStockThreshold : 999;
        var ratioB = b.lowStockThreshold > 0 ? b.stockQuantity / b.lowStockThreshold : 999;
        return ratioA - ratioB;
      });
      var invSlice = sorted.slice(0, 20);
      invSlice.forEach(function (inv) {
        var warn = (inv.lowStockThreshold > 0 && inv.stockQuantity <= inv.lowStockThreshold) ? ' [低在庫]' : '';
        inventoryText += inv.name + ': 在庫' + inv.stockQuantity + inv.unit
          + ' (しきい値' + inv.lowStockThreshold + inv.unit + ')' + warn + '\n';
      });
      if (data.inventory.length > 20) {
        inventoryText += '（他 ' + (data.inventory.length - 20) + ' 食材は在庫十分）\n';
      }
    } else {
      inventoryText = '在庫情報なし（食材管理が未設定です）\n';
    }

    // レシピ情報整形（上位30件に制限）
    var recipeText = '';
    if (data.recipes && data.recipes.length > 0) {
      var recipeSlice = data.recipes.slice(0, 30);
      recipeSlice.forEach(function (r) {
        recipeText += r.menuName + ' → ' + r.ingredientName + ' ' + r.quantityPerDish + r.unit + '\n';
      });
      if (data.recipes.length > 30) {
        recipeText += '（他 ' + (data.recipes.length - 30) + ' 件省略）\n';
      }
    } else {
      recipeText = 'レシピ情報なし（レシピが未設定です）\n';
    }

    var prompt = 'あなたは飲食店の発注担当AIです。以下のデータに基づき、本日と明日の需要予測と食材発注提案を行ってください。\n'
      + '前置き・挨拶は不要。分析結果のみ出力してください。\n\n'
      + '【店舗情報】\n'
      + '店舗名: ' + (data.storeName || '不明') + '\n'
      + '本日: ' + data.today.date + '（' + data.today.weekday + '）\n\n'
      + '【直近7日間の売上データ（品目別・日別）】\n'
      + salesText + '\n'
      + '【曜日別パターン】\n'
      + weekdayText + '\n'
      + '【現在の在庫状況】\n'
      + inventoryText + '\n'
      + '【レシピ情報（1品あたりの食材使用量）】\n'
      + recipeText + '\n'
      + '以下の形式で回答してください：\n\n'
      + '## 需要予測（本日・明日）\n'
      + '品目ごとの予測注文数（過去の曜日パターンから推定）\n\n'
      + '## 食材消費予測\n'
      + '予測注文数 × レシピ使用量 で算出した食材消費量をテーブルで表示。\n'
      + '在庫に余裕がある食材は省略し、消費量が大きい上位のみ表示。\n\n'
      + '## 発注が必要な食材\n'
      + '以下の条件に該当する食材のみをテーブルで表示：\n'
      + '- 現在在庫 − 2日分の予測消費量 がマイナスになる食材\n'
      + '- 2日分の消費後に低在庫しきい値を下回る食材\n'
      + '- 既に[低在庫]マークが付いている食材\n'
      + 'テーブル列: 食材名 / 現在在庫 / 2日分消費予測 / 残り予測 / 推奨発注量 / 緊急度(高/中)\n'
      + '該当なしの場合は「現在の在庫で2日間は十分です」と表示。\n\n'
      + '## アドバイス\n'
      + '曜日の傾向に基づくワンポイントアドバイスを2〜3行で。\n\n'
      + '【重要】上記4セクション（需要予測・食材消費予測・発注が必要な食材・アドバイス）を全て必ず出力すること。\n'
      + '各セクションは簡潔にまとめてください。';

    // 需要予測は温度低め + トークン多め（サーバープロキシ経由）
    fetch('../../api/store/ai-generate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        prompt: prompt,
        temperature: 0.5,
        max_tokens: 8192
      })
    })
    .then(function (r) {
      return r.text().then(function (body) {
        if (!body) throw new Error('空のレスポンス');
        try { return JSON.parse(body); } catch (e) { throw new Error('JSON解析エラー'); }
      });
    })
    .then(function (json) {
      if (!json.ok) {
        var msg = (json.error && json.error.message) || 'AI APIエラー';
        throw new Error(msg);
      }
      var text = (json.data && json.data.text) || '';
      var cleaned = _cleanResponse(text);
      _rawResults.demand = cleaned;
      _showResult(_demandArea, cleaned, 'analysis', _demandCardClass);
      _setButtonsEnabled(true);
    })
    .catch(function (err) {
      _rawResults.demand = '';
      _demandArea.className = _demandCardClass;
      _demandArea.innerHTML = '<div class="ai-state-error">エラー: ' + Utils.escapeHtml(err.message) + '</div>';
      _setButtonsEnabled(true);
    });
  }

  return {
    init: init,
    load: load
  };
})();
