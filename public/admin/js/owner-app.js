/**
 * owner-app.js — オーナー本部管理画面アプリ
 *
 * 責務:
 *   - 認証チェック（ownerロール限定）
 *   - StoreSelector / AdminApi 初期化
 *   - 各モジュール init / load
 *   - タブ切り替え（店舗管理・ユーザー管理は独立タブ）
 *   - イベント委譲（追加ボタン、リスト操作）
 *   - ユーザー管理
 *
 * ES5 IIFE パターン
 */
(function () {
  'use strict';

  // L-3 Phase 3: プラン機能フラグ保存用
  var _planFeatures = {};

  // --- 認証チェック ---
  AdminApi.me().then(function (res) {
    var user = res.user;
    if (!user || user.role !== 'owner') {
      window.location.href = 'index.html';
      return;
    }
    if (res.features) _planFeatures = res.features;
    initApp(user, res.stores);
    // L-16: プラン機能制限
    if (res.features) applyPlanRestrictions(res.features);
  }).catch(function () {
    window.location.href = 'index.html';
  });

  // --- アプリ初期化 ---
  function initApp(user, stores) {
    // ロール用bodyクラス（CrossStoreReport等が参照する）
    document.body.classList.add('role-owner');

    // ヘッダー表示
    var titleEl = document.getElementById('owner-header-title');
    if (titleEl) {
      titleEl.textContent = (user.tenantName || '松の屋') + ' 本部管理画面';
    }
    document.title = (user.tenantName || '松の屋') + ' 本部管理画面';

    var userEl = document.getElementById('owner-header-user');
    if (userEl) {
      userEl.textContent = user.displayName || user.email;
    }

    // StoreSelector初期化（UserEditor.load() が getStores() を使うため必要）
    // ownerの場合、店舗セレクターは非表示だがデータは保持する
    var selectEl = document.getElementById('owner-store-select');
    StoreSelector.init(selectEl, stores, 'owner');
    // owner は店舗未選択状態にする（クロス店舗分析用）
    selectEl.value = '';
    AdminApi.setCurrentStore(null);

    // 各モジュール初期化
    CrossStoreReport.init(document.getElementById('cross-store-container'));
    AbcAnalytics.init(document.getElementById('abc-analytics-container'));
    UserEditor.init(document.getElementById('user-list'));
    StoreEditor.init(document.getElementById('store-list'));
    AiAssistant.init(document.getElementById('ai-assistant-container'));

    // P1-28: 本部メニュー（enterprise限定: hq_menu_broadcast）
    // CategoryEditor は init(listEl, countEl) で両方必須（render() が countEl.textContent を書き込むため）
    if (typeof CategoryEditor !== 'undefined') {
      CategoryEditor.init(
        document.getElementById('hq-category-list-hidden'),
        document.getElementById('hq-category-count-hidden')
      );
    }
    if (typeof MenuTemplateEditor !== 'undefined') {
      MenuTemplateEditor.init(document.getElementById('hq-menu-template-list'));
    }

    // UI-P1-2d: localStorage で最後に開いていたタブを復元 (manager dashboard と同じ挙動)
    var OWNER_LAST_TAB_KEY = 'posla_owner_last_tab';
    var VALID_TABS = ['cross-store','abc-analytics','shift-overview','hq-menu','stores','users','ai-assistant','subscription','payment','external-pos'];
    var savedTab = 'cross-store';
    try {
      var v = localStorage.getItem(OWNER_LAST_TAB_KEY);
      if (v && VALID_TABS.indexOf(v) !== -1) savedTab = v;
    } catch (e) { /* localStorage 無効環境はデフォルト */ }

    activateTab(savedTab);
    var firstBtn = document.querySelector('.tab-nav__btn[data-tab="' + savedTab + '"]');
    if (firstBtn) firstBtn.classList.add('active');

    // イベント委譲・ボタン設定
    setupEventHandlers();

    // L-11: URLパラメータ処理（Stripe Checkout戻り）
    var urlParams = new URLSearchParams(window.location.search);
    var subParam = urlParams.get('subscription');
    if (subParam === 'success') {
      showToast('契約が完了しました', 'success');
      history.replaceState(null, '', window.location.pathname);
      activateTab('subscription');
      try { localStorage.setItem('posla_owner_last_tab', 'subscription'); } catch (e3) {}
      var subBtn = document.querySelector('.tab-nav__btn[data-tab="subscription"]');
      if (subBtn) {
        tabNav.querySelectorAll('.tab-nav__btn').forEach(function (b) { b.classList.remove('active'); });
        subBtn.classList.add('active');
      }
    } else if (subParam === 'cancel') {
      showToast('契約がキャンセルされました', 'error');
      history.replaceState(null, '', window.location.pathname);
    }

    // L-13: Connect URLパラメータ処理（Stripe Connect戻り）
    var connectParam = urlParams.get('connect');
    if (connectParam === 'success') {
      showToast('Stripe Connectの登録が完了しました', 'success');
      history.replaceState(null, '', window.location.pathname);
      activateTab('payment');
      try { localStorage.setItem('posla_owner_last_tab', 'payment'); } catch (e4) {}
      var payBtn = document.querySelector('.tab-nav__btn[data-tab="payment"]');
      if (payBtn) {
        tabNav.querySelectorAll('.tab-nav__btn').forEach(function (b) { b.classList.remove('active'); });
        payBtn.classList.add('active');
      }
    } else if (connectParam === 'pending') {
      showToast('オンボーディングが未完了です。再度お試しください', 'error');
      history.replaceState(null, '', window.location.pathname);
    } else if (connectParam === 'refresh') {
      showToast('セッションが期限切れです。再度開始してください', 'error');
      history.replaceState(null, '', window.location.pathname);
    }

    // L-15: スマレジ URLパラメータ処理（OAuthコールバック戻り）
    var smaregiParam = urlParams.get('smaregi');
    if (smaregiParam === 'success') {
      showToast('スマレジ連携が完了しました', 'success');
      history.replaceState(null, '', window.location.pathname);
      activateTab('external-pos');
      try { localStorage.setItem('posla_owner_last_tab', 'external-pos'); } catch (e5) {}
      var epBtn = document.querySelector('.tab-nav__btn[data-tab="external-pos"]');
      if (epBtn) {
        tabNav.querySelectorAll('.tab-nav__btn').forEach(function (b) { b.classList.remove('active'); });
        epBtn.classList.add('active');
      }
    } else if (smaregiParam === 'error') {
      var smaregiMsg = urlParams.get('message') || 'スマレジ連携に失敗しました';
      showToast(smaregiMsg, 'error');
      history.replaceState(null, '', window.location.pathname);
      activateTab('external-pos');
      try { localStorage.setItem('posla_owner_last_tab', 'external-pos'); } catch (e6) {}
      var epBtn2 = document.querySelector('.tab-nav__btn[data-tab="external-pos"]');
      if (epBtn2) {
        tabNav.querySelectorAll('.tab-nav__btn').forEach(function (b) { b.classList.remove('active'); });
        epBtn2.classList.add('active');
      }
    }
  }

  // --- L-16: プラン機能制限 ---
  function applyPlanRestrictions(features) {
    // owner-dashboard タブの data-tab 値 → feature_key 対応
    var tabFeatureMap = {
      'cross-store': 'advanced_reports',
      'abc-analytics': 'basket_analysis',
      'hq-menu': 'hq_menu_broadcast',
      'ai-assistant': 'ai_waiter'
    };
    var keys = Object.keys(tabFeatureMap);
    for (var i = 0; i < keys.length; i++) {
      var tabName = keys[i];
      var featureKey = tabFeatureMap[tabName];
      if (features[featureKey] === false) {
        var btn = document.querySelector('.tab-nav__btn[data-tab="' + tabName + '"]');
        if (btn) btn.style.display = 'none';
        var panel = document.getElementById('owner-panel-' + tabName);
        if (panel) panel.style.display = 'none';
      }
    }
  }

  // --- タブ切替 ---
  var tabNav = document.getElementById('owner-tab-nav');
  if (tabNav) {
    tabNav.addEventListener('click', function (e) {
      var btn = e.target.closest('.tab-nav__btn');
      if (!btn || !btn.dataset.tab) return;

      tabNav.querySelectorAll('.tab-nav__btn').forEach(function (b) {
        b.classList.remove('active');
      });
      btn.classList.add('active');
      activateTab(btn.dataset.tab);
      // UI-P1-2d: リロード時に同じタブを復元するため localStorage に保存
      try { localStorage.setItem('posla_owner_last_tab', btn.dataset.tab); } catch (e2) { /* noop */ }
    });
  }

  function activateTab(tabId) {
    document.querySelectorAll('.owner-tab-panel').forEach(function (p) {
      p.classList.toggle('active', p.id === 'owner-panel-' + tabId);
    });

    // データ読み込み
    switch (tabId) {
      case 'cross-store': CrossStoreReport.load(); break;
      case 'abc-analytics': AbcAnalytics.load(); break;
      case 'hq-menu':
        // P1-28: 本部メニュー（enterprise限定）
        // 1. CategoryEditor.load() でカテゴリ取得 + _categories キャッシュ更新（buildCategoryOptions が依存）
        // 2. AdminApi.getCategories() でカテゴリフィルタ <select> を再構築
        // 3. MenuTemplateEditor.load() でテンプレート一覧表示
        if (typeof CategoryEditor !== 'undefined' && typeof MenuTemplateEditor !== 'undefined') {
          CategoryEditor.load();
          AdminApi.getCategories().then(function (res) {
            var cats = res.categories || [];
            var filterEl = document.getElementById('hq-menu-category-filter');
            if (filterEl) {
              filterEl.innerHTML = '<option value="">全カテゴリ</option>';
              for (var i = 0; i < cats.length; i++) {
                var opt = document.createElement('option');
                opt.value = cats[i].id;
                opt.textContent = cats[i].name;
                filterEl.appendChild(opt);
              }
            }
            MenuTemplateEditor.load();
          }).catch(function (err) {
            showToast('カテゴリ読み込み失敗: ' + err.message, 'error');
          });
        }
        break;
      case 'stores': StoreEditor.load(); break;
      case 'users': UserEditor.load(); break;
      case 'ai-assistant':
        AiAssistant.load();
        break;
      case 'subscription':
        loadSubscriptionStatus();
        break;
      case 'payment':
        loadApiKeyManager();
        break;
      case 'external-pos':
        loadLineSettings();
        loadLineCustomerLinks();
        loadLineLinkTokens();
        loadSmaregiStatus();
        break;
      case 'shift-overview':
        initShiftOverview();
        break;
    }
  }

  // ═══════════════════════════════════
  // L-3: シフト概況（オーナー向け）
  // ═══════════════════════════════════
  var _shiftOverviewInitialized = false;

  function initShiftOverview() {
    if (_shiftOverviewInitialized) return;
    _shiftOverviewInitialized = true;

    // 店舗ドロップダウンを生成
    var storeSelect = document.getElementById('shift-store-select');
    var dateInput = document.getElementById('shift-date-input');
    var loadBtn = document.getElementById('shift-load-summary');
    if (!storeSelect || !loadBtn) return;

    var stores = StoreSelector.getStores ? StoreSelector.getStores() : [];
    // StoreSelector.getStores がない場合の fallback
    if (stores.length === 0) {
      var opts = document.getElementById('owner-store-select').options;
      for (var i = 0; i < opts.length; i++) {
        if (opts[i].value) stores.push({ id: opts[i].value, name: opts[i].textContent });
      }
    }

    storeSelect.innerHTML = '<option value="">-- 選択 --</option>';
    for (var s = 0; s < stores.length; s++) {
      var opt = document.createElement('option');
      opt.value = stores[s].id;
      opt.textContent = stores[s].name || stores[s].id;
      storeSelect.appendChild(opt);
    }

    // デフォルト日付: 今週月曜
    var today = new Date();
    var day = today.getDay();
    var diff = today.getDate() - day + (day === 0 ? -6 : 1);
    var monday = new Date(today);
    monday.setDate(diff);
    dateInput.value = monday.toISOString().substring(0, 10);

    loadBtn.addEventListener('click', loadShiftSummary);

    // L-3 Phase 3: enterprise プランなら全店舗ボタン追加
    if (_planFeatures.shift_help_request === true) {
      var actionsDiv = document.querySelector('#owner-panel-shift-overview .panel-header__actions');
      if (actionsDiv) {
        var allBtn = document.createElement('button');
        allBtn.className = 'btn btn-sm';
        allBtn.style.cssText = 'margin-left:8px;background:#7c4dff;color:#fff;';
        allBtn.textContent = '全店舗概況';
        allBtn.id = 'shift-unified-btn';
        actionsDiv.appendChild(allBtn);

        allBtn.addEventListener('click', function() {
          var period = document.getElementById('shift-period-select').value;
          var date = document.getElementById('shift-date-input').value;
          if (!date) { showToast('日付を入力してください', 'error'); return; }
          loadUnifiedView(period, date);
        });

        // デフォルトで全店舗表示
        loadUnifiedView('weekly', dateInput.value);
      }
    }
  }

  function loadShiftSummary() {
    var storeId = document.getElementById('shift-store-select').value;
    var period = document.getElementById('shift-period-select').value;
    var date = document.getElementById('shift-date-input').value;
    var container = document.getElementById('shift-summary-container');

    if (!storeId) { showToast('店舗を選択してください', 'error'); return; }
    if (!date) { showToast('日付を入力してください', 'error'); return; }

    container.innerHTML = '<p style="text-align:center;padding:1rem;color:#888;">読み込み中...</p>';

    var apiDate = (period === 'monthly' && date.length === 10) ? date.substring(0, 7) : date;
    var url = '/api/store/shift/summary.php?store_id=' + encodeURIComponent(storeId) +
              '&period=' + encodeURIComponent(period) +
              '&date=' + encodeURIComponent(apiDate);

    fetch(url).then(function(res) { return res.text(); }).then(function(text) {
      var json = JSON.parse(text);
      if (!json.ok) {
        container.innerHTML = '<p class="error" style="padding:1rem;">取得に失敗しました: ' +
          _escHtml(json.error ? json.error.message : '') + '</p>';
        return;
      }
      renderShiftSummary(container, json.data);
    }).catch(function(err) {
      container.innerHTML = '<p class="error" style="padding:1rem;">エラー: ' + _escHtml(err.message) + '</p>';
    });
  }

  function renderShiftSummary(container, data) {
    var html = '<div style="padding:0.5rem 0;">' +
      '<h3 style="margin:0 0 0.5rem;">期間: ' + (data.period || '') +
      ' &nbsp; 合計: ' + (data.total_hours || 0) + '時間</h3>';

    // スタッフ別
    if (data.staff_summary && data.staff_summary.length > 0) {
      html += '<h4 style="margin:1rem 0 0.5rem;">スタッフ別労働時間</h4>' +
        '<table class="shift-table" style="width:100%"><thead><tr>' +
        '<th>スタッフ</th><th>勤務日数</th><th>合計時間</th><th>残業</th><th>遅刻</th>' +
        '</tr></thead><tbody>';
      for (var i = 0; i < data.staff_summary.length; i++) {
        var s = data.staff_summary[i];
        html += '<tr>' +
          '<td>' + _escHtml(s.display_name || s.username || '') + '</td>' +
          '<td>' + (s.days_worked || 0) + '日</td>' +
          '<td>' + (s.total_hours || 0) + 'h</td>' +
          '<td>' + (s.overtime_hours || 0) + 'h</td>' +
          '<td>' + (s.late_count || 0) + '回</td>' +
          '</tr>';
      }
      html += '</tbody></table>';
    } else {
      html += '<p style="color:#888;">この期間の勤怠データはありません。</p>';
    }

    // 日別
    if (data.daily_summary && data.daily_summary.length > 0) {
      html += '<h4 style="margin:1.5rem 0 0.5rem;">日別配置人数</h4>' +
        '<div class="shift-daily-bars">';
      for (var d = 0; d < data.daily_summary.length; d++) {
        var day = data.daily_summary[d];
        var barHeight = Math.min(day.staff_count * 20, 100);
        html += '<div class="shift-daily-bar">' +
          '<div class="shift-bar-fill" style="height:' + barHeight + 'px;"></div>' +
          '<div class="shift-bar-count">' + day.staff_count + '人</div>' +
          '<div class="shift-bar-date">' + (day.date || '').substring(5) + '</div>' +
          '</div>';
      }
      html += '</div>';
    }

    html += '</div>';
    container.innerHTML = html;
  }

  // ═══════════════════════════════════
  // L-3 Phase 3: 統合シフトビュー
  // ═══════════════════════════════════
  function _escHtml(str) {
    if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(str);
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function loadUnifiedView(period, date) {
    var container = document.getElementById('shift-summary-container');
    if (!container) return;
    container.innerHTML = '<p style="text-align:center;padding:1rem;color:#888;">全店舗データを読み込み中...</p>';

    var apiDate = (period === 'monthly' && date.length === 10) ? date.substring(0, 7) : date;
    var url = '/api/owner/shift/unified-view.php?period=' + encodeURIComponent(period) +
              '&date=' + encodeURIComponent(apiDate);

    fetch(url).then(function(res) { return res.text(); }).then(function(text) {
      var json = JSON.parse(text);
      if (!json.ok) {
        container.innerHTML = '<p class="error" style="padding:1rem;">取得に失敗しました: ' +
          _escHtml(json.error ? json.error.message : '') + '</p>';
        return;
      }
      renderUnifiedView(container, json.data, period, date);
    }).catch(function(err) {
      container.innerHTML = '<p class="error" style="padding:1rem;">エラー: ' + _escHtml(err.message) + '</p>';
    });
  }

  function renderUnifiedView(container, data, period, date) {
    var stores = data.stores || [];
    var helpSummary = data.help_requests_summary || {};
    var helpRequests = data.help_requests || [];

    // 日付ナビゲーション
    var dt = new Date(date);
    var prevDate, nextDate;
    if (period === 'weekly') {
      var prev = new Date(dt); prev.setDate(prev.getDate() - 7);
      var next = new Date(dt); next.setDate(next.getDate() + 7);
      prevDate = prev.toISOString().substring(0, 10);
      nextDate = next.toISOString().substring(0, 10);
    } else {
      var prev2 = new Date(dt); prev2.setMonth(prev2.getMonth() - 1);
      var next2 = new Date(dt); next2.setMonth(next2.getMonth() + 1);
      prevDate = prev2.toISOString().substring(0, 7);
      nextDate = next2.toISOString().substring(0, 7);
    }

    var html = '<div style="padding:0.5rem 0;">';

    // 期間ナビ
    html += '<div style="display:flex;align-items:center;gap:12px;margin-bottom:1rem;">' +
      '<button class="btn btn-sm" id="unified-prev">◀ 前</button>' +
      '<h3 style="margin:0;">' + _escHtml(data.period || '') + '</h3>' +
      '<button class="btn btn-sm" id="unified-next">次 ▶</button>' +
      '<span style="font-size:0.85rem;color:#888;margin-left:auto;">' +
      'ヘルプ要請: 待機 ' + (helpSummary.total_pending || 0) +
      ' / 承認 ' + (helpSummary.total_approved || 0) + '</span>' +
      '</div>';

    // 店舗カード (UI-P1-2d: flex → grid に変更。全カード均等幅で並ぶ)
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:12px;margin-bottom:1.5rem;">';
    var maxCost = 0;
    for (var m = 0; m < stores.length; m++) {
      if (stores[m].labor_cost_total > maxCost) maxCost = stores[m].labor_cost_total;
    }

    for (var i = 0; i < stores.length; i++) {
      var s = stores[i];
      var helpCount = s.help_sent_pending + s.help_received_pending;
      html += '<div class="card unified-store-card" data-store-id="' + _escHtml(s.store_id) + '" ' +
        'style="padding:16px;cursor:pointer;border:1px solid #e0e3eb;border-radius:8px;background:#fff;">' +
        '<h4 style="margin:0 0 8px;font-size:1rem;">' + _escHtml(s.store_name) + '</h4>' +
        '<div style="font-size:0.85rem;color:#555;">' +
        '<div>出勤: <strong>' + s.total_staff_count + '人</strong> / ' + s.total_hours + 'h</div>' +
        '<div>人件費: <strong>¥' + (s.labor_cost_total || 0).toLocaleString() + '</strong></div>' +
        '<div>充足率: <strong>' + s.fulfillment_rate + '%</strong></div>';
      if (helpCount > 0) {
        html += '<div style="color:#e65100;">ヘルプ要請: ' + helpCount + '件</div>';
      }
      html += '</div>' +
        '<div style="margin-top:8px;"><span style="color:#1565c0;font-size:0.8rem;">詳細を見る →</span></div>' +
        '</div>';
    }
    if (stores.length === 0) {
      html += '<p style="color:#888;">店舗がありません</p>';
    }
    html += '</div>';

    // ヘルプ要請一覧
    if (helpRequests.length > 0) {
      html += '<h4 style="margin:0 0 0.5rem;">ヘルプ要請</h4>' +
        '<table class="shift-table" style="width:100%;margin-bottom:1.5rem;"><thead><tr>' +
        '<th>経路</th><th>日付</th><th>時間帯</th><th>人数</th><th>役割</th><th>状態</th>' +
        '</tr></thead><tbody>';
      for (var h = 0; h < helpRequests.length; h++) {
        var hr = helpRequests[h];
        var statusColor = hr.status === 'pending' ? '#ff9800' : '#4caf50';
        var statusLabel = hr.status === 'pending' ? '待機中' : '承認済';
        html += '<tr>' +
          '<td>' + _escHtml(hr.from_store_name) + ' → ' + _escHtml(hr.to_store_name) + '</td>' +
          '<td>' + _escHtml(hr.requested_date) + '</td>' +
          '<td>' + _escHtml((hr.start_time || '').substring(0, 5)) + '-' + _escHtml((hr.end_time || '').substring(0, 5)) + '</td>' +
          '<td>' + hr.requested_staff_count + '名</td>' +
          '<td>' + _escHtml(hr.role_hint || '-') + '</td>' +
          '<td><span style="color:#fff;background:' + statusColor + ';padding:2px 8px;border-radius:12px;font-size:0.75rem;">' + statusLabel + '</span></td>' +
          '</tr>';
      }
      html += '</tbody></table>';
    }

    // 人件費比較バー (UI-P1-2d 視覚強化: card 化 + 余白整理)
    if (stores.length > 0 && maxCost > 0) {
      html += '<div class="card" style="padding:16px 20px;margin-bottom:1.5rem;border:1px solid #e0e3eb;border-radius:8px;background:#fff;max-width:900px;">';
      html += '<h4 style="margin:0 0 0.75rem;font-size:0.95rem;color:#37474f;">人件費比較</h4>';
      for (var b = 0; b < stores.length; b++) {
        var sb = stores[b];
        var barWidth = maxCost > 0 ? Math.round((sb.labor_cost_total / maxCost) * 100) : 0;
        html += '<div style="margin-bottom:8px;display:flex;align-items:center;gap:12px;">' +
          '<span style="width:130px;font-size:0.82rem;color:#455a64;flex-shrink:0;">' + _escHtml(sb.store_name) + '</span>' +
          '<div style="flex:1;background:#eef2f7;border-radius:4px;height:14px;overflow:hidden;min-width:0;">' +
          '<div style="width:' + barWidth + '%;background:linear-gradient(90deg,#3f51b5,#1976d2);height:100%;border-radius:4px;"></div>' +
          '</div>' +
          '<span style="font-size:0.85rem;min-width:90px;text-align:right;font-weight:600;color:#263238;">¥' + (sb.labor_cost_total || 0).toLocaleString() + '</span>' +
          '</div>';
      }
      html += '</div>';
    }

    html += '</div>';
    container.innerHTML = html;

    // イベント: 期間ナビ
    var prevBtn = document.getElementById('unified-prev');
    var nextBtn = document.getElementById('unified-next');
    if (prevBtn) {
      prevBtn.addEventListener('click', function() {
        document.getElementById('shift-date-input').value = prevDate;
        loadUnifiedView(period, prevDate);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function() {
        document.getElementById('shift-date-input').value = nextDate;
        loadUnifiedView(period, nextDate);
      });
    }

    // イベント: 店舗カードクリック → 1店舗サマリー
    var cards = container.querySelectorAll('.unified-store-card');
    for (var ci = 0; ci < cards.length; ci++) {
      cards[ci].addEventListener('click', function() {
        var storeId = this.getAttribute('data-store-id');
        // 店舗セレクトを変更して1店舗表示
        var storeSelect = document.getElementById('shift-store-select');
        storeSelect.value = storeId;

        // 「戻る」リンク付きで表示
        var summaryContainer = document.getElementById('shift-summary-container');
        summaryContainer.innerHTML = '<p style="text-align:center;padding:1rem;color:#888;">読み込み中...</p>';

        var url = '/api/store/shift/summary.php?store_id=' + encodeURIComponent(storeId) +
                  '&period=' + encodeURIComponent(period) +
                  '&date=' + encodeURIComponent(date);

        fetch(url).then(function(res) { return res.text(); }).then(function(text) {
          var json = JSON.parse(text);
          if (!json.ok) {
            summaryContainer.innerHTML = '<p class="error" style="padding:1rem;">取得に失敗しました</p>';
            return;
          }
          // 戻るリンク追加してから既存レンダー
          var backLink = '<div style="margin-bottom:0.5rem;">' +
            '<a href="#" id="back-to-unified" style="color:#1565c0;font-size:0.9rem;">← 全店舗に戻る</a></div>';
          renderShiftSummary(summaryContainer, json.data);
          summaryContainer.insertAdjacentHTML('afterbegin', backLink);

          document.getElementById('back-to-unified').addEventListener('click', function(e) {
            e.preventDefault();
            storeSelect.value = '';
            loadUnifiedView(period, date);
          });
        }).catch(function(err) {
          summaryContainer.innerHTML = '<p class="error" style="padding:1rem;">エラー: ' + _escHtml(err.message) + '</p>';
        });
      });
    }
  }

  // ═══════════════════════════════════
  // L-11: サブスクリプション管理
  // ═══════════════════════════════════
  var SUBSCRIPTION_API = '../../api/subscription';

  function _subRequest(method, path, body) {
    var opts = { method: method, headers: {}, credentials: 'same-origin' };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(SUBSCRIPTION_API + path, opts).then(function (res) {
      return res.text().then(function (text) {
        if (!text) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(text); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || 'エラーが発生しました';
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    });
  }

  function loadSubscriptionStatus() {
    var container = document.getElementById('subscription-container');
    if (!container) return;

    container.innerHTML = '<p style="color:var(--text-muted);">読み込み中...</p>';

    _subRequest('GET', '/status.php').then(function (data) {
      renderSubscription(container, data);
    }).catch(function (err) {
      container.innerHTML = '<p style="color:#c62828;">' + Utils.escapeHtml(err.message) + '</p>';
    });
  }

  // P1-35: α-1 価格内訳テーブルを組み立てる
  // 戻り値: { html: 文字列, total: 数値 }
  function _renderPricingBreakdown(storeCount, hasHqBroadcast) {
    storeCount = parseInt(storeCount, 10) || 1;
    if (storeCount < 1) storeCount = 1;

    var lines = [];
    lines.push({ label: '基本料金', unit: 20000, qty: 1, subtotal: 20000 });
    if (storeCount > 1) {
      var addQty = storeCount - 1;
      lines.push({ label: '追加店舗', unit: 17000, qty: addQty, subtotal: 17000 * addQty });
    }
    if (hasHqBroadcast) {
      lines.push({ label: '本部一括配信', unit: 3000, qty: storeCount, subtotal: 3000 * storeCount });
    }

    var total = 0;
    for (var i = 0; i < lines.length; i++) total += lines[i].subtotal;

    var html = '';
    html += '<table class="pricing-table" style="width:100%;max-width:480px;border-collapse:collapse;font-size:0.9rem;margin-bottom:0.75rem;">';
    html += '<thead><tr style="border-bottom:2px solid #e0e0e0;">'
      +    '<th style="text-align:left;padding:0.5rem 0.5rem;color:var(--text-secondary);font-weight:600;">項目</th>'
      +    '<th style="text-align:right;padding:0.5rem 0.5rem;color:var(--text-secondary);font-weight:600;">単価</th>'
      +    '<th style="text-align:right;padding:0.5rem 0.5rem;color:var(--text-secondary);font-weight:600;">数量</th>'
      +    '<th style="text-align:right;padding:0.5rem 0.5rem;color:var(--text-secondary);font-weight:600;">小計</th>'
      + '</tr></thead><tbody>';
    for (var j = 0; j < lines.length; j++) {
      var ln = lines[j];
      html += '<tr style="border-bottom:1px solid #f0f0f0;">'
        +    '<td style="padding:0.5rem 0.5rem;">' + Utils.escapeHtml(ln.label) + '</td>'
        +    '<td style="padding:0.5rem 0.5rem;text-align:right;">¥' + ln.unit.toLocaleString() + '</td>'
        +    '<td style="padding:0.5rem 0.5rem;text-align:right;">× ' + ln.qty + '</td>'
        +    '<td style="padding:0.5rem 0.5rem;text-align:right;">¥' + ln.subtotal.toLocaleString() + '</td>'
        + '</tr>';
    }
    html += '<tr style="border-top:2px solid #333;background:#fafafa;">'
      +    '<td colspan="3" style="padding:0.6rem 0.5rem;font-weight:700;">月額合計</td>'
      +    '<td style="padding:0.6rem 0.5rem;text-align:right;font-weight:700;font-size:1.1rem;color:var(--primary);">¥' + total.toLocaleString() + '</td>'
      + '</tr>';
    html += '</tbody></table>';

    return { html: html, total: total };
  }

  // P1-35: 未契約表示 (subStatus === 'none')
  function _renderSubUnsubscribed(container, data) {
    var storeCount = parseInt(data.store_count, 10) || 1;

    function renderInner(checked) {
      var pb = _renderPricingBreakdown(storeCount, checked);

      var html = '';
      html += '<div class="card" style="max-width:560px;">'
        +    '<div class="card__body">'
        +      '<h3 style="margin:0 0 0.5rem;font-size:1.1rem;font-weight:700;">POSLA サブスクリプション</h3>'
        +      '<p style="margin:0 0 1rem;font-size:0.85rem;color:var(--text-secondary);">'
        +        '店舗数: <strong>' + storeCount + '</strong> 店舗 (現在の登録店舗から自動)'
        +      '</p>'
        +      '<div id="sub-pricing-area">' + pb.html + '</div>'
        +      '<label style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f5f5f5;border-radius:6px;cursor:pointer;margin-bottom:1rem;">'
        +        '<input type="checkbox" id="sub-hq-broadcast"' + (checked ? ' checked' : '') + '>'
        +        '<span style="font-size:0.9rem;">'
        +          '<strong>本部一括配信アドオンを追加する</strong>'
        +          '<br><span style="font-size:0.78rem;color:var(--text-secondary);">チェーン本部運用向け（複数店舗で同一メニューを一括配信）</span>'
        +        '</span>'
        +      '</label>'
        +      '<p style="margin:0 0 1rem;font-size:0.85rem;color:var(--text-secondary);text-align:center;">※ 30日間無料トライアル / 31日目から自動課金</p>'
        +      '<div style="text-align:center;">'
        +        '<button class="btn btn-primary" id="btn-start-trial" style="font-size:1rem;padding:0.75rem 2rem;">30日間無料で始める</button>'
        +      '</div>'
        +    '</div>'
        + '</div>';

      container.innerHTML = html;

      // チェックボックス変更で再描画 (合計金額更新)
      var chk = document.getElementById('sub-hq-broadcast');
      if (chk) {
        chk.addEventListener('change', function () {
          renderInner(chk.checked);
        });
      }

      var startBtn = document.getElementById('btn-start-trial');
      if (startBtn) {
        startBtn.addEventListener('click', function () {
          var hqChecked = chk ? chk.checked : false;
          startBtn.disabled = true;
          startBtn.textContent = '処理中...';
          _subRequest('POST', '/checkout.php', { hq_broadcast: hqChecked }).then(function (resp) {
            if (resp.checkout_url) {
              window.location.href = resp.checkout_url;
            }
          }).catch(function (err) {
            showToast(err.message || 'エラーが発生しました', 'error');
            startBtn.disabled = false;
            startBtn.textContent = '30日間無料で始める';
          });
        });
      }
    }

    // 初期表示は data.has_hq_broadcast を尊重 (none 状態では通常 false)
    renderInner(!!data.has_hq_broadcast);
  }

  // P1-35: 契約中表示 (trialing / active / past_due / canceled)
  function _renderSubActive(container, data) {
    var subStatus = data.subscription_status || 'none';
    var statusLabels = { active: '有効', past_due: '支払い遅延', canceled: '解約済み', trialing: 'トライアル中' };
    var statusColors = { active: '#4caf50', past_due: '#c62828', canceled: '#999', trialing: '#ff9800' };
    var statusLabel = statusLabels[subStatus] || subStatus;
    var statusColor = statusColors[subStatus] || '#999';

    // 次回請求日 / トライアル残日数
    var periodEndStr = '-';
    var trialDaysLeftStr = '';
    if (data.current_period_end) {
      var d = new Date(data.current_period_end);
      if (!isNaN(d.getTime())) {
        periodEndStr = d.getFullYear() + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + ('0' + d.getDate()).slice(-2);
        if (subStatus === 'trialing') {
          var diffMs = d.getTime() - (new Date()).getTime();
          var diffDays = Math.ceil(diffMs / 86400000);
          if (diffDays > 0) {
            trialDaysLeftStr = '残り ' + diffDays + ' 日';
          }
        }
      }
    }

    var pb = _renderPricingBreakdown(data.store_count || 1, !!data.has_hq_broadcast);

    var html = '';
    html += '<div class="card" style="max-width:560px;">'
      +    '<div class="card__body">'
      +      '<h3 style="margin:0 0 0.75rem;font-size:1.1rem;font-weight:700;">サブスクリプション</h3>'
      +      '<div style="margin-bottom:1rem;">'
      +        '<span style="font-size:0.85rem;color:var(--text-secondary);">状態: </span>'
      +        '<span style="display:inline-block;padding:0.25rem 0.75rem;border-radius:10px;font-size:0.8rem;font-weight:600;color:#fff;background:' + statusColor + ';">'
      +          Utils.escapeHtml(statusLabel)
      +        '</span>'
      +        (trialDaysLeftStr ? ' <span style="font-size:0.85rem;color:var(--text-secondary);margin-left:0.5rem;">' + Utils.escapeHtml(trialDaysLeftStr) + '</span>' : '')
      +      '</div>'
      +      '<div style="margin-bottom:0.75rem;font-size:0.85rem;color:var(--text-secondary);">'
      +        '店舗数: <strong>' + (data.store_count || 1) + '</strong> 店舗'
      +        ' / 本部一括配信アドオン: <strong>' + (data.has_hq_broadcast ? 'あり' : 'なし') + '</strong>'
      +      '</div>'
      +      pb.html
      +      '<div style="margin-top:1rem;font-size:0.875rem;color:var(--text-secondary);">'
      +        (subStatus === 'trialing' ? '課金開始日' : '次回請求日') + ': ' + Utils.escapeHtml(periodEndStr)
      +      '</div>'
      +      '<div style="margin-top:1.5rem;text-align:center;">'
      +        '<button class="btn btn-primary" id="btn-open-portal">サブスクリプション管理 (Stripe)</button>'
      +      '</div>'
      +    '</div>'
      + '</div>';

    container.innerHTML = html;

    var portalBtn = document.getElementById('btn-open-portal');
    if (portalBtn) {
      portalBtn.addEventListener('click', function () {
        portalBtn.disabled = true;
        portalBtn.textContent = '処理中...';
        _subRequest('POST', '/portal.php').then(function (resp) {
          if (resp.portal_url) {
            window.location.href = resp.portal_url;
          }
        }).catch(function (err) {
          showToast(err.message || 'エラーが発生しました', 'error');
          portalBtn.disabled = false;
          portalBtn.textContent = 'サブスクリプション管理 (Stripe)';
        });
      });
    }
  }

  function renderSubscription(container, data) {
    // P1-35: α-1 化 — 旧 standard/pro/enterprise 3カードを廃止
    // 価格内訳テーブル + HQ アドオンチェックボックス + 契約中/未契約で UI 切替
    var subStatus = data.subscription_status || 'none';
    var hasSubscription = data.has_subscription;

    if (hasSubscription && subStatus !== 'none') {
      _renderSubActive(container, data);
    } else {
      _renderSubUnsubscribed(container, data);
    }
  }

  // ═══════════════════════════════════
  // APIキー管理
  // ═══════════════════════════════════
  var _tenantData = null;

  function loadApiKeyManager() {
    var container = document.getElementById('api-key-manager-content');
    if (!container) return;

    AdminApi.getTenant().then(function (res) {
      _tenantData = res.tenant;
      renderApiKeyManager(container);
    }).catch(function (err) {
      container.innerHTML = '<p style="color:#c62828;">' + Utils.escapeHtml(err.message) + '</p>';
    });
  }

  function renderApiKeyManager(container) {
    var t = _tenantData;
    if (!t) return;
    var gwVal = t.payment_gateway || 'none';

    container.innerHTML = ''
      // 決済ゲートウェイ設定（P1-2 で Square 削除済み）
      + '<h4 style="margin:0 0 1rem;font-size:1rem;color:#333;">決済ゲートウェイ設定</h4>'
      + '<div style="margin-bottom:1rem;">'
      +   '<label style="display:block;font-weight:600;margin-bottom:0.5rem;font-size:0.9rem;color:#333;">使用する決済サービス</label>'
      +   '<div style="display:flex;gap:1rem;flex-wrap:wrap;">'
      +     '<label style="cursor:pointer;"><input type="radio" name="payment-gateway" value="none"' + (gwVal === 'none' ? ' checked' : '') + '> なし</label>'
      +     '<label style="cursor:pointer;"><input type="radio" name="payment-gateway" value="stripe"' + (gwVal === 'stripe' ? ' checked' : '') + '> Stripe</label>'
      +   '</div>'
      + '</div>'
      // Stripe Secret Key
      + '<div>'
      +   '<label style="display:block;font-weight:600;margin-bottom:0.5rem;font-size:0.9rem;color:#333;">Stripe Secret Key</label>'
      +   '<div id="ak-stripe-current" style="margin-bottom:0.5rem;">'
      +     (t.stripe_secret_key_set
              ? '<span style="font-family:monospace;font-size:0.9rem;color:#555;">' + Utils.escapeHtml(t.stripe_secret_key_masked || '') + '</span>'
                + ' <button class="btn btn-secondary btn-sm" id="ak-stripe-toggle" style="margin-left:0.5rem;">表示</button>'
                + ' <button class="btn btn-sm" id="ak-stripe-delete" style="margin-left:0.25rem;color:#c62828;border-color:#c62828;">削除</button>'
              : '<span style="color:#999;font-size:0.85rem;">未設定</span>')
      +   '</div>'
      +   '<div style="display:flex;gap:0.5rem;align-items:center;">'
      +     '<input class="form-input" id="ak-stripe-input" type="password" placeholder="新しいキーを入力..." style="flex:1;">'
      +     '<button class="btn btn-primary btn-sm" id="ak-stripe-save">保存</button>'
      +   '</div>'
      + '</div>'
      // L-13: Stripe Connect セクション
      + '<hr style="margin:2rem 0;">'
      + '<h3>Stripe Connect（POSLA経由決済）</h3>'
      + '<p style="color:#666;font-size:0.9em;">自前の決済アカウントをお持ちでない場合、POSLAが決済を代行します。手数料は決済金額の一定割合がかかります。</p>'
      + '<div id="connect-status-area"><p style="color:#888;">読み込み中...</p></div>';

    // イベント登録
    setupApiKeyEvents(container);

    // L-13: Connect状態読み込み
    loadConnectStatus(t);
  }

  function setupApiKeyEvents(container) {
    // 決済ゲートウェイ選択
    var gwRadios = container.querySelectorAll('input[name="payment-gateway"]');
    gwRadios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        saveApiKey('payment_gateway', this.value);
      });
    });

    // Stripe 保存
    var stripeSave = document.getElementById('ak-stripe-save');
    if (stripeSave) {
      stripeSave.addEventListener('click', function () {
        var val = document.getElementById('ak-stripe-input').value.trim();
        if (!val) { showToast('キーを入力してください', 'error'); return; }
        saveApiKey('stripe_secret_key', val);
      });
    }
    // Stripe 削除
    var stripeDel = document.getElementById('ak-stripe-delete');
    if (stripeDel) {
      stripeDel.addEventListener('click', function () {
        if (!confirm('Stripe Secret Keyを削除しますか？')) return;
        deleteApiKey('stripe_secret_key');
      });
    }
    // Stripe 表示トグル
    var stripeToggle = document.getElementById('ak-stripe-toggle');
    if (stripeToggle) {
      stripeToggle.addEventListener('click', function () {
        toggleKeyVisibility('ak-stripe', this);
      });
    }
  }

  function saveApiKey(field, value) {
    var payload = {};
    payload[field] = value;
    AdminApi.updateTenant(payload).then(function () {
      showToast('APIキーを保存しました', 'success');
      loadApiKeyManager();
    }).catch(function (err) {
      showToast(err.message || '保存に失敗しました', 'error');
    });
  }

  function deleteApiKey(field) {
    var payload = {};
    payload[field] = null;
    AdminApi.updateTenant(payload).then(function () {
      showToast('APIキーを削除しました', 'success');
      loadApiKeyManager();
    }).catch(function (err) {
      showToast(err.message || '削除に失敗しました', 'error');
    });
  }

  function toggleKeyVisibility(prefix, btn) {
    var input = document.getElementById(prefix + '-input');
    if (!input) return;
    if (input.type === 'password') {
      input.type = 'text';
      btn.textContent = '隠す';
    } else {
      input.type = 'password';
      btn.textContent = '表示';
    }
  }

  // ═══════════════════════════════════
  // L-13: Stripe Connect
  // ═══════════════════════════════════
  var CONNECT_API = '../../api/connect';

  function _connectRequest(method, path) {
    var opts = { method: method, headers: {}, credentials: 'same-origin' };
    return fetch(CONNECT_API + path, opts).then(function (res) {
      return res.text().then(function (text) {
        if (!text) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(text); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || 'エラーが発生しました';
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    });
  }

  function loadConnectStatus(tenantData) {
    var area = document.getElementById('connect-status-area');
    if (!area) return;

    _connectRequest('GET', '/status.php').then(function (data) {
      renderConnectStatus(area, data, tenantData);
    }).catch(function (err) {
      area.innerHTML = '<p style="color:#888;font-size:0.9em;">Connect情報を取得できませんでした</p>';
    });
  }

  function renderConnectStatus(area, data, tenantData) {
    var html = '';

    // 排他制御: 自前決済が設定済みの場合のinfo表示
    var hasOwnGateway = (tenantData && tenantData.stripe_secret_key_set);

    if (data.connected && data.charges_enabled) {
      // パターンC: 有効
      html += '<div style="margin:1rem 0;padding:0.75rem 1rem;background:#e8f5e9;border-radius:6px;border-left:4px solid #4caf50;">'
        + '<span style="color:#2e7d32;font-weight:600;">&#10003; Stripe Connect: 有効</span><br>'
        + '<span style="font-size:0.85rem;color:#555;">アカウントID: ' + Utils.escapeHtml(data.account_id || '') + '</span><br>'
        + '<span style="font-size:0.85rem;color:#666;">決済・入金状況はStripeダッシュボードで確認できます</span>'
        + '</div>'
        + '<button class="btn btn-secondary" id="connect-disconnect-btn" style="margin-top:0.5rem;">Stripe Connectを解除</button>'
        + '<div style="font-size:0.75rem;color:#888;margin-top:0.25rem;">解除後は自前のStripe Secret Keyで決済できます。Stripeダッシュボード上のアカウント自体は残るため、再接続も可能です。完全に削除する場合はStripeダッシュボードから手動で行ってください。</div>';
      // 自前決済エリアへのinfo（既存エリアにはDOM的にアクセスしにくいのでConnect側に表示）
    } else if (data.connected && !data.charges_enabled) {
      // パターンB: オンボーディング途中
      html += '<div style="margin:1rem 0;padding:0.75rem 1rem;background:#fff3e0;border-radius:6px;border-left:4px solid #ff9800;">'
        + '<span style="color:#e65100;font-weight:600;">&#9888; オンボーディング未完了です</span><br>'
        + '<span style="font-size:0.85rem;color:#555;">Stripeへの情報登録を完了してください。</span>'
        + '</div>'
        + '<button class="btn btn-primary" id="btn-connect-resume" style="margin-top:0.5rem;">オンボーディングを再開</button>'
        + '<button class="btn btn-secondary" id="connect-disconnect-btn" style="margin-top:0.5rem;margin-left:0.5rem;">Stripe Connectを解除</button>'
        + '<div style="font-size:0.75rem;color:#888;margin-top:0.25rem;">登録途中でも Stripe Connect の連携情報を破棄できます。Stripeダッシュボード上のアカウント自体は残るため、再接続も可能です。</div>';
    } else {
      // パターンA: 未登録
      if (hasOwnGateway) {
        html += '<div style="margin:0.5rem 0 1rem;padding:0.5rem 0.75rem;background:#e3f2fd;border-radius:4px;font-size:0.85rem;color:#1565c0;">'
          + '&#9432; 自前の決済アカウントが設定されています。Stripe Connectは自前決済アカウントをお持ちでない店舗向けです。'
          + '</div>';
      }
      html += '<button class="btn btn-primary" id="btn-connect-start" style="margin-top:0.5rem;">Stripe Connectに登録して決済を開始する</button>';
    }

    // 有効時: 自前決済設定エリアへのinfo
    if (data.connected && data.charges_enabled) {
      var infoTarget = document.querySelector('#api-key-manager-content h4');
      if (infoTarget) {
        var infoDiv = document.createElement('div');
        infoDiv.style.cssText = 'margin:0.5rem 0 1rem;padding:0.5rem 0.75rem;background:#e3f2fd;border-radius:4px;font-size:0.85rem;color:#1565c0;';
        infoDiv.innerHTML = '&#9432; 現在POSLA経由のStripe Connectで決済しています。自前決済に切り替える場合は、Stripe Connectを解除してから設定してください。';
        infoTarget.parentNode.insertBefore(infoDiv, infoTarget.nextSibling);
      }
    }

    area.innerHTML = html;

    // ボタンイベント
    var startBtn = document.getElementById('btn-connect-start');
    var resumeBtn = document.getElementById('btn-connect-resume');
    var disconnectBtn = document.getElementById('connect-disconnect-btn');

    if (startBtn) {
      startBtn.addEventListener('click', function () {
        startBtn.disabled = true;
        startBtn.textContent = '処理中...';
        startConnectOnboarding();
      });
    }
    if (resumeBtn) {
      resumeBtn.addEventListener('click', function () {
        resumeBtn.disabled = true;
        resumeBtn.textContent = '処理中...';
        startConnectOnboarding();
      });
    }
    if (disconnectBtn) {
      disconnectBtn.addEventListener('click', function () {
        if (!confirm('Stripe Connectを解除します。解除後は自前のStripeキーで決済できます。Stripeダッシュボードのアカウント自体は残るので、再接続も可能です。解除しますか？')) return;
        disconnectBtn.disabled = true;
        disconnectBtn.textContent = '解除中...';
        fetch(CONNECT_API + '/disconnect.php', {
          method: 'POST',
          credentials: 'same-origin'
        }).then(function (res) {
          return res.text().then(function (text) {
            var json;
            try { json = JSON.parse(text); } catch (e) { throw new Error('応答の解析に失敗しました'); }
            if (!res.ok || !json.ok) {
              var msg = (json.error && json.error.message) || 'エラーが発生しました';
              throw new Error(msg);
            }
            return json.data;
          });
        }).then(function () {
          showToast('Stripe Connectを解除しました', 'success');
          loadApiKeyManager();
        }).catch(function (err) {
          showToast(err.message || 'Connect解除に失敗しました', 'error');
          disconnectBtn.disabled = false;
          disconnectBtn.textContent = 'Stripe Connectを解除';
        });
      });
    }
  }

  function startConnectOnboarding() {
    var opts = { method: 'POST', headers: {}, credentials: 'same-origin' };
    fetch(CONNECT_API + '/onboard.php', opts).then(function (res) {
      return res.text().then(function (text) {
        var json;
        try { json = JSON.parse(text); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || 'エラーが発生しました';
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    }).then(function (data) {
      if (data.url) {
        window.location.href = data.url;
      } else {
        showToast('リダイレクトURLが取得できませんでした', 'error');
      }
    }).catch(function (err) {
      showToast(err.message || 'Connectの登録に失敗しました', 'error');
      var btn = document.getElementById('btn-connect-start') || document.getElementById('btn-connect-resume');
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Stripe Connectに登録して決済を開始する';
      }
    });
  }

  // --- イベントハンドラ ---
  function setupEventHandlers() {
    // ユーザー追加ボタン
    document.getElementById('btn-add-user').addEventListener('click', function () {
      UserEditor.openAddModal();
    });
    // 店舗追加ボタン
    document.getElementById('btn-add-store').addEventListener('click', function () {
      StoreEditor.openAddModal();
    });

    // ユーザーリストのイベント委譲
    document.getElementById('user-list').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-action]');
      if (!btn) return;
      if (btn.dataset.action === 'edit-user') {
        UserEditor.openEditModal(btn.dataset.id);
      }
      else if (btn.dataset.action === 'delete-user') UserEditor.confirmDelete(btn.dataset.id);
    });

    // 店舗リストのイベント委譲
    document.getElementById('store-list').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-action]');
      if (!btn) return;
      if (btn.dataset.action === 'edit-store') StoreEditor.openEditModal(btn.dataset.id);
      else if (btn.dataset.action === 'delete-store') StoreEditor.confirmDelete(btn.dataset.id);
    });

    // P1-28: 本部メニュー追加ボタン
    var btnAddHqMenu = document.getElementById('btn-add-hq-menu');
    if (btnAddHqMenu) {
      btnAddHqMenu.addEventListener('click', function () {
        if (typeof MenuTemplateEditor !== 'undefined') {
          MenuTemplateEditor.openAddModal();
        }
      });
    }

    // P1-28: 本部メニュー カテゴリフィルタ
    var hqFilterEl = document.getElementById('hq-menu-category-filter');
    if (hqFilterEl) {
      hqFilterEl.addEventListener('change', function () {
        if (typeof MenuTemplateEditor !== 'undefined') {
          MenuTemplateEditor.filterByCategory(hqFilterEl.value);
        }
      });
    }

    // P1-28: 本部メニュー編集/削除/売切トグル のイベント委譲
    var hqMenuListEl = document.getElementById('hq-menu-template-list');
    if (hqMenuListEl) {
      hqMenuListEl.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn || typeof MenuTemplateEditor === 'undefined') return;
        if (btn.dataset.action === 'edit-template') MenuTemplateEditor.openEditModal(btn.dataset.id);
        else if (btn.dataset.action === 'delete-template') MenuTemplateEditor.confirmDelete(btn.dataset.id);
        else if (btn.dataset.action === 'toggle-sold-out') MenuTemplateEditor.toggleSoldOut(btn.dataset.id);
      });
    }

    // P1-28b: 本部メニュー CSV エクスポート
    var btnHqCsvExport = document.getElementById('btn-hq-menu-csv-export');
    if (btnHqCsvExport) {
      btnHqCsvExport.addEventListener('click', function () {
        AdminApi.exportMenuCsv();
      });
    }

    // P1-28b: 本部メニュー CSV インポート (ボタン → 隠し file input トリガ)
    var btnHqCsvImport = document.getElementById('btn-hq-menu-csv-import');
    var hqCsvFile = document.getElementById('hq-menu-csv-file');
    if (btnHqCsvImport && hqCsvFile) {
      btnHqCsvImport.addEventListener('click', function () {
        hqCsvFile.click();
      });
      hqCsvFile.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        if (!confirm('CSVファイル「' + file.name + '」をインポートします。\n既存メニューと id が一致する行は更新されます。続行しますか？')) {
          this.value = '';
          return;
        }
        AdminApi.importMenuCsv(file).then(function (res) {
          var msg = '作成: ' + (res.created || 0) + '件、更新: ' + (res.updated || 0) + '件';
          var hasErrors = res.errors && res.errors.length > 0;
          if (hasErrors) msg += '\nエラー:\n' + res.errors.join('\n');
          showToast(msg, hasErrors ? 'warning' : 'success');
          if (typeof MenuTemplateEditor !== 'undefined') MenuTemplateEditor.load();
        }).catch(function (err) {
          showToast('インポート失敗: ' + (err.message || err), 'error');
        }).finally(function () {
          hqCsvFile.value = '';
        });
      });
    }

    // モーダルキャンセル
    document.addEventListener('click', function (e) {
      if (e.target.matches('[data-action="modal-cancel"]')) {
        document.getElementById('admin-modal-overlay').classList.remove('open');
      }
    });
    var modalOverlay = document.getElementById('admin-modal-overlay');
    modalOverlay.addEventListener('click', function (e) {
      if (e.target === modalOverlay) modalOverlay.classList.remove('open');
    });
  }

  // ═══════════════════════════════════
  // L-17: LINE連携
  // ═══════════════════════════════════
  var _lineSettingsCache = null;

  function loadLineSettings() {
    var container = document.getElementById('line-settings-content');
    if (!container) return;
    container.innerHTML = '<span style="color:#888;">読み込み中...</span>';

    AdminApi.getLineSettings().then(function (res) {
      _lineSettingsCache = res.line || null;
      renderLineSettings(container, _lineSettingsCache);
    }).catch(function (err) {
      container.innerHTML = '<p style="color:#c62828;">' + Utils.escapeHtml(err.message) + '</p>';
    });
  }

  function renderLineSettings(container, data) {
    if (!data) return;

    if (data.migration_applied === false) {
      container.innerHTML = '<div style="padding:0.75rem 1rem;background:#fff3e0;border-left:4px solid #ff9800;border-radius:6px;color:#6d4c41;">'
        + '<strong>DB migration 未適用</strong><br>'
        + '<span style="font-size:0.85rem;color:#666;">sql/migration-l17-line-settings.sql を適用後に利用できます。</span>'
        + '</div>';
      return;
    }

    var enabled = (data.is_enabled === 1 || data.is_enabled === true);
    var statusText = enabled ? '有効' : '無効';
    var statusColor = enabled ? '#2e7d32' : '#666';
    var statusBg = enabled ? '#e8f5e9' : '#f5f5f5';
    var webhookMeta = data.last_webhook_at
      ? ('最終受信: ' + Utils.escapeHtml(data.last_webhook_at) + (data.last_webhook_event_type ? ' / ' + Utils.escapeHtml(data.last_webhook_event_type) : '') + ' / ' + (data.last_webhook_event_count || 0) + '件')
      : 'まだ webhook は受信していません';

    container.innerHTML = ''
      + '<p style="color:#666;font-size:0.9rem;margin:0 0 1rem;">店舗が保有する LINE公式アカウントを POSLA に接続します。未設定のままなら既存動作は一切変わりません。</p>'
      + '<div style="margin-bottom:1rem;padding:0.75rem 1rem;background:' + statusBg + ';border-radius:6px;border-left:4px solid ' + (enabled ? '#4caf50' : '#bdbdbd') + ';">'
      +   '<span style="font-weight:600;color:' + statusColor + ';">LINE連携: ' + statusText + '</span><br>'
      +   '<span style="display:inline-block;margin-top:0.35rem;font-size:0.85rem;color:#666;">' + Utils.escapeHtml(webhookMeta) + '</span>'
      + '</div>'
      + '<div style="margin-bottom:1rem;">'
      +   '<label style="display:block;font-weight:600;margin-bottom:0.4rem;">Webhook URL</label>'
      +   '<div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">'
      +     '<input class="form-input" id="line-webhook-url" type="text" readonly value="' + Utils.escapeHtml(data.webhook_url || '') + '" style="flex:1;min-width:280px;font-family:monospace;font-size:0.85rem;">'
      +     '<button class="btn btn-sm" id="line-copy-webhook-btn">コピー</button>'
      +   '</div>'
      +   '<div style="font-size:0.8rem;color:#888;margin-top:0.35rem;">LINE Developers Console の Webhook URL に設定してください。</div>'
      + '</div>'
      + '<div style="display:grid;gap:1rem;margin-bottom:1rem;">'
      +   '<div>'
      +     '<label style="display:block;font-weight:600;margin-bottom:0.4rem;">Channel Access Token</label>'
      +     '<div style="margin-bottom:0.35rem;">'
      +       (data.channel_access_token_set
                ? '<span style="font-family:monospace;font-size:0.9rem;color:#555;">' + Utils.escapeHtml(data.channel_access_token_masked || '') + '</span>'
                : '<span style="color:#999;font-size:0.85rem;">未設定</span>')
      +     '</div>'
      +     '<div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">'
      +       '<input class="form-input" id="line-access-token-input" type="password" placeholder="新しい token を入力..." style="flex:1;min-width:240px;">'
      +       '<button class="btn btn-primary btn-sm" id="line-access-token-save">保存</button>'
      +       '<button class="btn btn-sm" id="line-access-token-delete" style="color:#c62828;border-color:#c62828;">削除</button>'
      +     '</div>'
      +   '</div>'
      +   '<div>'
      +     '<label style="display:block;font-weight:600;margin-bottom:0.4rem;">Channel Secret</label>'
      +     '<div style="margin-bottom:0.35rem;">'
      +       (data.channel_secret_set
                ? '<span style="font-family:monospace;font-size:0.9rem;color:#555;">' + Utils.escapeHtml(data.channel_secret_masked || '') + '</span>'
                : '<span style="color:#999;font-size:0.85rem;">未設定</span>')
      +     '</div>'
      +     '<div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">'
      +       '<input class="form-input" id="line-channel-secret-input" type="password" placeholder="新しい secret を入力..." style="flex:1;min-width:240px;">'
      +       '<button class="btn btn-primary btn-sm" id="line-channel-secret-save">保存</button>'
      +       '<button class="btn btn-sm" id="line-channel-secret-delete" style="color:#c62828;border-color:#c62828;">削除</button>'
      +     '</div>'
      +   '</div>'
      +   '<div>'
      +     '<label style="display:block;font-weight:600;margin-bottom:0.4rem;">LIFF ID（任意）</label>'
      +     '<div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">'
      +       '<input class="form-input" id="line-liff-id-input" type="text" value="' + Utils.escapeHtml(data.liff_id || '') + '" placeholder="LIFF ID を入力..." style="flex:1;min-width:240px;">'
      +       '<button class="btn btn-primary btn-sm" id="line-liff-id-save">保存</button>'
      +     '</div>'
      +   '</div>'
      + '</div>'
      + '<div style="padding:0.85rem 1rem;background:#fafafa;border:1px solid #eee;border-radius:8px;">'
      +   '<label style="display:flex;align-items:center;gap:0.5rem;font-weight:600;margin-bottom:0.9rem;">'
      +     '<input type="checkbox" id="line-enabled-toggle"' + (enabled ? ' checked' : '') + '> LINE連携を有効化する'
      +   '</label>'
      +   '<div style="display:grid;gap:0.55rem;margin-bottom:0.9rem;">'
      +     '<label style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" id="line-notify-reservation-created"' + ((data.notify_reservation_created === 1 || data.notify_reservation_created === true) ? ' checked' : '') + '> 予約受付完了通知</label>'
      +     '<label style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" id="line-notify-reservation-reminder-day"' + ((data.notify_reservation_reminder_day === 1 || data.notify_reservation_reminder_day === true) ? ' checked' : '') + '> 前日リマインド通知</label>'
      +     '<label style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" id="line-notify-reservation-reminder-2h"' + ((data.notify_reservation_reminder_2h === 1 || data.notify_reservation_reminder_2h === true) ? ' checked' : '') + '> 2時間前リマインド通知</label>'
      +     '<label style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" id="line-notify-takeout-ready"' + ((data.notify_takeout_ready === 1 || data.notify_takeout_ready === true) ? ' checked' : '') + '> テイクアウト準備完了通知</label>'
      +   '</div>'
      +   '<div style="font-size:0.8rem;color:#888;margin-bottom:0.9rem;">ON にした通知のうち、予約受付完了 / 前日リマインド / 2時間前リマインドは LINE 連携済の顧客に LINE でも送られます。テイクアウト準備完了は次フェーズで接続予定です。</div>'
      +   '<button class="btn btn-primary" id="line-settings-save-btn">設定を保存</button>'
      + '</div>';

    bindLineSettingsEvents();
  }

  function bindLineSettingsEvents() {
    var copyBtn = document.getElementById('line-copy-webhook-btn');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        var input = document.getElementById('line-webhook-url');
        if (!input) return;
        input.select();
        input.setSelectionRange(0, input.value.length);
        try {
          document.execCommand('copy');
          showToast('Webhook URL をコピーしました', 'success');
        } catch (e) {
          showToast('Webhook URL のコピーに失敗しました', 'error');
        }
      });
    }

    var tokenSave = document.getElementById('line-access-token-save');
    if (tokenSave) {
      tokenSave.addEventListener('click', function () {
        var input = document.getElementById('line-access-token-input');
        var value = input ? input.value.trim() : '';
        if (!value) {
          showToast('Channel Access Token を入力してください', 'error');
          return;
        }
        saveLineSettings({ channel_access_token: value });
      });
    }

    var tokenDelete = document.getElementById('line-access-token-delete');
    if (tokenDelete) {
      tokenDelete.addEventListener('click', function () {
        if (!confirm('Channel Access Token を削除しますか？')) return;
        saveLineSettings({ channel_access_token: null });
      });
    }

    var secretSave = document.getElementById('line-channel-secret-save');
    if (secretSave) {
      secretSave.addEventListener('click', function () {
        var input = document.getElementById('line-channel-secret-input');
        var value = input ? input.value.trim() : '';
        if (!value) {
          showToast('Channel Secret を入力してください', 'error');
          return;
        }
        saveLineSettings({ channel_secret: value });
      });
    }

    var secretDelete = document.getElementById('line-channel-secret-delete');
    if (secretDelete) {
      secretDelete.addEventListener('click', function () {
        if (!confirm('Channel Secret を削除しますか？')) return;
        saveLineSettings({ channel_secret: null });
      });
    }

    var liffSave = document.getElementById('line-liff-id-save');
    if (liffSave) {
      liffSave.addEventListener('click', function () {
        var input = document.getElementById('line-liff-id-input');
        var value = input ? input.value.trim() : '';
        saveLineSettings({ liff_id: value || null });
      });
    }

    var settingsSave = document.getElementById('line-settings-save-btn');
    if (settingsSave) {
      settingsSave.addEventListener('click', function () {
        var enabled = document.getElementById('line-enabled-toggle');
        var notifyReservation = document.getElementById('line-notify-reservation-created');
        var notifyReminder = document.getElementById('line-notify-reservation-reminder-day');
        var notifyReminder2h = document.getElementById('line-notify-reservation-reminder-2h');
        var notifyTakeout = document.getElementById('line-notify-takeout-ready');
        saveLineSettings({
          is_enabled: enabled && enabled.checked ? 1 : 0,
          notify_reservation_created: notifyReservation && notifyReservation.checked ? 1 : 0,
          notify_reservation_reminder_day: notifyReminder && notifyReminder.checked ? 1 : 0,
          notify_reservation_reminder_2h: notifyReminder2h && notifyReminder2h.checked ? 1 : 0,
          notify_takeout_ready: notifyTakeout && notifyTakeout.checked ? 1 : 0
        });
      });
    }
  }

  function saveLineSettings(payload) {
    AdminApi.updateLineSettings(payload).then(function (res) {
      _lineSettingsCache = res.line || null;
      showToast('LINE連携設定を保存しました', 'success');
      loadLineSettings();
    }).catch(function (err) {
      showToast(err.message || 'LINE連携設定の保存に失敗しました', 'error');
    });
  }

  // ═══════════════════════════════════
  // L-17 Phase 2A-1: LINE 顧客連携 (read + unlink のみ)
  // ═══════════════════════════════════
  function loadLineCustomerLinks() {
    var container = document.getElementById('line-customer-links-content');
    if (!container) return;
    container.innerHTML = '<span style="color:#888;">読み込み中...</span>';

    AdminApi.getLineCustomerLinks().then(function (res) {
      var data = (res && res.line_links) ? res.line_links : null;
      renderLineCustomerLinks(container, data);
    }).catch(function (err) {
      container.innerHTML = '<p style="color:#c62828;">' + Utils.escapeHtml(err.message || '読み込みに失敗しました') + '</p>';
    });
  }

  function _formatLineLinkDate(s) {
    if (!s) return '—';
    // "2026-04-22 18:30:00" のような datetime を短縮表示
    return String(s).replace('T', ' ').substring(0, 16);
  }

  function renderLineCustomerLinks(container, data) {
    if (!data) {
      container.innerHTML = '<p style="color:#888;">読み込みに失敗しました。</p>';
      return;
    }
    if (data.migration_applied === false) {
      container.innerHTML =
        '<p style="color:#888;margin:0 0 0.5rem;">LINE 顧客連携テーブルがまだ適用されていません。</p>' +
        '<p style="font-size:0.85rem;color:#888;margin:0;">sql/migration-l17-2a-customer-line-links.sql を適用後に利用できます。</p>';
      return;
    }

    var linked = parseInt(data.linked_count, 10) || 0;
    var unlinked = parseInt(data.unlinked_count, 10) || 0;
    var recent = (data.recent && data.recent.length) ? data.recent : [];

    var html = '';
    html += '<p style="color:#666;font-size:0.9rem;margin:0 0 1rem;">LINE公式アカウントの友だちと POSLA の予約顧客をひも付けた状態を一覧できます。連携がない間は既存動作に影響しません。</p>';

    // summary
    html += '<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">' +
              '<div style="flex:1 1 160px;padding:0.75rem 1rem;background:#f5f5f5;border-radius:6px;">' +
                '<div style="font-size:0.75rem;color:#666;">連携中</div>' +
                '<div style="font-size:1.5rem;font-weight:700;color:' + (linked > 0 ? '#2e7d32' : '#333') + ';">' + linked + '</div>' +
              '</div>' +
              '<div style="flex:1 1 160px;padding:0.75rem 1rem;background:#f5f5f5;border-radius:6px;">' +
                '<div style="font-size:0.75rem;color:#666;">解除済</div>' +
                '<div style="font-size:1.5rem;font-weight:700;color:#999;">' + unlinked + '</div>' +
              '</div>' +
              '<div style="flex:1 1 200px;padding:0.75rem 1rem;background:#f5f5f5;border-radius:6px;">' +
                '<div style="font-size:0.75rem;color:#666;">最終 interaction</div>' +
                '<div style="font-size:0.95rem;font-weight:600;color:#333;">' + Utils.escapeHtml(_formatLineLinkDate(data.last_interaction_at)) + '</div>' +
              '</div>' +
            '</div>';

    if (recent.length === 0) {
      html += '<p style="color:#888;font-size:0.9rem;margin:0.5rem 0 0;">連携はまだありません。LINE 公式アカウントの友だちがサービス内の顧客とひも付くと、ここに一覧されます。(リンク作成 UI は Phase 2A-2 で追加予定)</p>';
      container.innerHTML = html;
      return;
    }

    html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">' +
            '<thead><tr style="background:#f0f0f0;text-align:left;">' +
              '<th style="padding:0.4rem 0.5rem;width:140px;">顧客</th>' +
              '<th style="padding:0.4rem 0.5rem;width:140px;">LINE 表示名</th>' +
              '<th style="padding:0.4rem 0.5rem;width:180px;">LINE user ID</th>' +
              '<th style="padding:0.4rem 0.5rem;width:140px;">店舗</th>' +
              '<th style="padding:0.4rem 0.5rem;width:120px;">状態</th>' +
              '<th style="padding:0.4rem 0.5rem;width:130px;">連携日時</th>' +
              '<th style="padding:0.4rem 0.5rem;width:80px;">操作</th>' +
            '</tr></thead><tbody>';

    for (var i = 0; i < recent.length; i++) {
      var r = recent[i];
      var isLinked = r.link_status === 'linked';
      var statusColor = isLinked ? '#2e7d32' : '#999';
      var statusText = isLinked ? '連携中' : '解除済';
      var action = '';
      if (isLinked) {
        action = '<button class="btn btn-sm line-link-unlink-btn" data-link-id="' + Utils.escapeHtml(r.id) + '" style="color:#c62828;border-color:#c62828;">解除</button>';
      } else {
        action = '<span style="color:#bbb;font-size:0.8rem;">—</span>';
      }

      html += '<tr style="border-bottom:1px solid #eee;">' +
                '<td style="padding:0.4rem 0.5rem;">' + Utils.escapeHtml(r.customer_name || '(名称なし)') + '</td>' +
                '<td style="padding:0.4rem 0.5rem;color:#555;">' + Utils.escapeHtml(r.display_name || '—') + '</td>' +
                '<td style="padding:0.4rem 0.5rem;font-family:monospace;font-size:0.8rem;color:#888;">' + Utils.escapeHtml(r.line_user_id_masked || '—') + '</td>' +
                '<td style="padding:0.4rem 0.5rem;color:#555;">' + Utils.escapeHtml(r.store_name || '—') + '</td>' +
                '<td style="padding:0.4rem 0.5rem;color:' + statusColor + ';font-weight:600;">' + statusText + '</td>' +
                '<td style="padding:0.4rem 0.5rem;font-size:0.8rem;color:#888;">' + Utils.escapeHtml(_formatLineLinkDate(r.linked_at)) + '</td>' +
                '<td style="padding:0.4rem 0.5rem;">' + action + '</td>' +
              '</tr>';
    }
    html += '</tbody></table></div>';

    container.innerHTML = html;
    bindLineCustomerLinksEvents(container);
  }

  function bindLineCustomerLinksEvents(container) {
    var btns = container.querySelectorAll('.line-link-unlink-btn');
    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener('click', function (e) {
        var linkId = e.currentTarget.getAttribute('data-link-id');
        if (!linkId) return;
        if (!confirm('この顧客の LINE 連携を解除しますか？\n(LINE 側の友だち関係は変わりません)')) return;
        unlinkLineCustomerLink(linkId);
      });
    }
  }

  function unlinkLineCustomerLink(linkId) {
    AdminApi.unlinkLineCustomer(linkId).then(function () {
      showToast('LINE 連携を解除しました', 'success');
      loadLineCustomerLinks();
    }).catch(function (err) {
      showToast(err.message || '解除に失敗しました', 'error');
    });
  }

  // ═══════════════════════════════════
  // L-17 Phase 2A-2: リンク用 one-time token
  // ═══════════════════════════════════
  var _lineLinkTokensCache = null;
  var _lineLinkTokenLastIssued = null;

  function loadLineLinkTokens() {
    var container = document.getElementById('line-link-tokens-content');
    if (!container) return;
    container.innerHTML = '<span style="color:#888;">読み込み中...</span>';

    AdminApi.getLineLinkTokens().then(function (res) {
      var data = (res && res.tokens) ? res.tokens : null;
      _lineLinkTokensCache = data;
      renderLineLinkTokens(container, data);
    }).catch(function (err) {
      container.innerHTML = '<p style="color:#c62828;">' + Utils.escapeHtml(err.message || '読み込みに失敗しました') + '</p>';
    });
  }

  function _formatLineTokenDate(s) {
    if (!s) return '—';
    return String(s).replace('T', ' ').substring(0, 16);
  }

  function renderLineLinkTokens(container, data) {
    if (!data) {
      container.innerHTML = '<p style="color:#888;">読み込みに失敗しました。</p>';
      return;
    }
    if (data.migration_applied === false) {
      container.innerHTML =
        '<p style="color:#888;margin:0 0 0.5rem;">リンク用トークン用マイグレーションがまだ適用されていません。</p>' +
        '<p style="font-size:0.85rem;color:#888;margin:0;">sql/migration-l17-2a2-line-link-tokens.sql を適用後に利用できます。</p>';
      return;
    }

    var html = '';
    html += '<p style="color:#666;font-size:0.9rem;margin:0 0 1rem;">' +
            '顧客に LINE 公式アカウントで「<code>LINK:XXXXXX</code>」と送信してもらうと、予約顧客と LINE が連携されます。' +
            'トークンの有効期限は 30 分、1 回のみ使用できます。</p>';

    // 発行フォーム
    html += '<div style="background:#fafafa;border:1px solid #e0e0e0;border-radius:6px;padding:1rem;margin-bottom:1rem;">' +
              '<div style="font-weight:600;margin-bottom:0.5rem;font-size:0.9rem;">新しいトークンを発行</div>' +
              '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;margin-bottom:0.5rem;">' +
                '<input type="text" id="line-token-customer-id-input" placeholder="reservation_customer_id (UUID)" style="flex:1;min-width:260px;padding:0.4rem 0.6rem;border:1px solid #ccc;border-radius:4px;font-family:monospace;font-size:0.85rem;">' +
                '<button class="btn btn-primary btn-sm" id="line-token-issue-by-id-btn">この顧客ID で発行</button>' +
              '</div>' +
              '<div style="font-size:0.8rem;color:#888;margin:0.35rem 0 0.75rem;">または</div>' +
              '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">' +
                '<input type="text" id="line-token-store-id-input" placeholder="store_id (UUID)" style="flex:1;min-width:220px;padding:0.4rem 0.6rem;border:1px solid #ccc;border-radius:4px;font-family:monospace;font-size:0.85rem;">' +
                '<input type="tel" id="line-token-phone-input" placeholder="customer_phone (例: 090-1234-5678)" style="flex:1;min-width:200px;padding:0.4rem 0.6rem;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;">' +
                '<button class="btn btn-primary btn-sm" id="line-token-issue-by-phone-btn">店舗×電話番号で発行</button>' +
              '</div>' +
              '<div style="font-size:0.78rem;color:#aaa;margin-top:0.5rem;">' +
                '※ reservation_customer_id は予約顧客の内部 UUID です (予約管理画面から取得)。' +
              '</div>' +
            '</div>';

    // 直近発行結果
    if (_lineLinkTokenLastIssued) {
      var t = _lineLinkTokenLastIssued;
      var cname = (t.customer && t.customer.customer_name) ? t.customer.customer_name : '(名称なし)';
      html += '<div style="background:#e8f5e9;border:1px solid #66bb6a;border-radius:6px;padding:1rem;margin-bottom:1rem;">' +
                '<div style="font-weight:600;margin-bottom:0.35rem;color:#2e7d32;font-size:0.9rem;">発行済トークン (コピーして顧客にお伝えください)</div>' +
                '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;margin:0.5rem 0;">' +
                  '<code id="line-token-last-code" style="font-size:1.5rem;font-weight:700;color:#2e7d32;background:#fff;padding:0.35rem 0.75rem;border-radius:4px;letter-spacing:0.15em;">LINK:' + Utils.escapeHtml(t.token) + '</code>' +
                  '<button class="btn btn-sm" id="line-token-copy-btn">コピー</button>' +
                '</div>' +
                '<div style="font-size:0.85rem;color:#555;">顧客: <strong>' + Utils.escapeHtml(cname) + '</strong> / 有効期限: ' + Utils.escapeHtml(_formatLineTokenDate(t.expires_at)) + '</div>' +
              '</div>';
    }

    // active tokens list
    var active = (data.active && data.active.length) ? data.active : [];
    html += '<div style="font-weight:600;margin-bottom:0.5rem;font-size:0.9rem;">有効なトークン</div>';
    if (active.length === 0) {
      html += '<p style="color:#888;font-size:0.9rem;margin:0;">現在有効なトークンはありません。</p>';
    } else {
      html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">' +
              '<thead><tr style="background:#f0f0f0;text-align:left;">' +
                '<th style="padding:0.4rem 0.5rem;width:120px;">コード</th>' +
                '<th style="padding:0.4rem 0.5rem;">顧客</th>' +
                '<th style="padding:0.4rem 0.5rem;width:140px;">電話番号</th>' +
                '<th style="padding:0.4rem 0.5rem;width:140px;">店舗</th>' +
                '<th style="padding:0.4rem 0.5rem;width:140px;">有効期限</th>' +
                '<th style="padding:0.4rem 0.5rem;width:90px;">操作</th>' +
              '</tr></thead><tbody>';
      for (var i = 0; i < active.length; i++) {
        var a = active[i];
        html += '<tr style="border-bottom:1px solid #eee;">' +
                  '<td style="padding:0.4rem 0.5rem;font-family:monospace;font-weight:700;color:#2e7d32;letter-spacing:0.1em;">' + Utils.escapeHtml(a.token) + '</td>' +
                  '<td style="padding:0.4rem 0.5rem;">' + Utils.escapeHtml(a.customer_name || '(名称なし)') + '</td>' +
                  '<td style="padding:0.4rem 0.5rem;color:#555;">' + Utils.escapeHtml(a.customer_phone || '—') + '</td>' +
                  '<td style="padding:0.4rem 0.5rem;color:#555;">' + Utils.escapeHtml(a.store_name || '—') + '</td>' +
                  '<td style="padding:0.4rem 0.5rem;font-size:0.8rem;color:#888;">' + Utils.escapeHtml(_formatLineTokenDate(a.expires_at)) + '</td>' +
                  '<td style="padding:0.4rem 0.5rem;">' +
                    '<button class="btn btn-sm line-token-revoke-btn" data-token-id="' + Utils.escapeHtml(a.id) + '" style="color:#c62828;border-color:#c62828;">失効</button>' +
                  '</td>' +
                '</tr>';
      }
      html += '</tbody></table></div>';
    }

    container.innerHTML = html;
    bindLineLinkTokensEvents(container);
  }

  function bindLineLinkTokensEvents(container) {
    var issueIdBtn = container.querySelector('#line-token-issue-by-id-btn');
    if (issueIdBtn) {
      issueIdBtn.addEventListener('click', function () {
        var cid = (document.getElementById('line-token-customer-id-input') || {}).value;
        cid = (cid || '').trim();
        if (!cid) { showToast('reservation_customer_id を入力してください', 'error'); return; }
        issueLineLinkTokenCall({ reservation_customer_id: cid });
      });
    }
    var issuePhoneBtn = container.querySelector('#line-token-issue-by-phone-btn');
    if (issuePhoneBtn) {
      issuePhoneBtn.addEventListener('click', function () {
        var sid = (document.getElementById('line-token-store-id-input') || {}).value;
        var phone = (document.getElementById('line-token-phone-input') || {}).value;
        sid = (sid || '').trim();
        phone = (phone || '').trim();
        if (!sid || !phone) { showToast('store_id と customer_phone を両方入力してください', 'error'); return; }
        issueLineLinkTokenCall({ store_id: sid, customer_phone: phone });
      });
    }
    var copyBtn = container.querySelector('#line-token-copy-btn');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        var codeEl = document.getElementById('line-token-last-code');
        if (!codeEl) return;
        var txt = codeEl.textContent || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(txt).then(function () {
            showToast('コピーしました', 'success');
          });
        } else {
          // fallback: select + execCommand
          var range = document.createRange();
          range.selectNodeContents(codeEl);
          var sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
          try { document.execCommand('copy'); showToast('コピーしました', 'success'); } catch (e) { showToast('コピーに失敗しました', 'error'); }
        }
      });
    }
    var revokeBtns = container.querySelectorAll('.line-token-revoke-btn');
    for (var i = 0; i < revokeBtns.length; i++) {
      revokeBtns[i].addEventListener('click', function (e) {
        var tokenId = e.currentTarget.getAttribute('data-token-id');
        if (!tokenId) return;
        if (!confirm('このトークンを失効させますか？')) return;
        revokeLineLinkTokenCall(tokenId);
      });
    }
  }

  function issueLineLinkTokenCall(body) {
    AdminApi.issueLineLinkToken(body).then(function (res) {
      _lineLinkTokenLastIssued = res.token || null;
      showToast('トークンを発行しました', 'success');
      loadLineLinkTokens();
    }).catch(function (err) {
      showToast(err.message || 'トークン発行に失敗しました', 'error');
    });
  }

  function revokeLineLinkTokenCall(tokenId) {
    AdminApi.revokeLineLinkToken(tokenId).then(function () {
      showToast('トークンを失効しました', 'success');
      loadLineLinkTokens();
    }).catch(function (err) {
      showToast(err.message || '失効に失敗しました', 'error');
    });
  }

  // ═══════════════════════════════════
  // L-15: スマレジ連携
  // ═══════════════════════════════════
  var SMAREGI_API = '../../api/smaregi';

  function _smaregiRequest(method, path, body) {
    var opts = { method: method, headers: {}, credentials: 'same-origin' };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(SMAREGI_API + path, opts).then(function (res) {
      return res.text().then(function (text) {
        if (!text) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(text); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || 'エラーが発生しました';
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    });
  }

  // --- 接続状態表示 ---
  function loadSmaregiStatus() {
    var display = document.getElementById('smaregi-status-display');
    if (!display) return;
    display.innerHTML = '<span style="color:#888;">読み込み中...</span>';

    _smaregiRequest('GET', '/status.php').then(function (data) {
      renderSmaregiStatus(display, data);
    }).catch(function (err) {
      display.innerHTML = '<p style="color:#c62828;">' + Utils.escapeHtml(err.message) + '</p>';
    });
  }

  function renderSmaregiStatus(display, data) {
    var html = '';
    var mappingSection = document.getElementById('smaregi-store-mapping-section');
    var importSection = document.getElementById('smaregi-menu-import-section');

    if (data.connected) {
      // 接続済み
      var connDate = data.connected_at ? data.connected_at.split(' ')[0] : '-';
      var tokenLabel = '有効';
      var tokenColor = '#4caf50';
      if (data.token_status === 'expired') { tokenLabel = '期限切れ'; tokenColor = '#c62828'; }
      else if (data.token_status === 'expiring_soon') { tokenLabel = 'まもなく期限切れ'; tokenColor = '#ff9800'; }

      html += '<div style="margin-bottom:1rem;">'
        + '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#4caf50;margin-right:6px;vertical-align:middle;"></span>'
        + '<strong>接続済み</strong>'
        + '</div>'
        + '<table style="font-size:0.9rem;border-collapse:collapse;">'
        + '<tr><td style="padding:4px 12px 4px 0;color:#666;">契約ID</td><td>' + Utils.escapeHtml(data.contract_id || '-') + '</td></tr>'
        + '<tr><td style="padding:4px 12px 4px 0;color:#666;">接続日</td><td>' + Utils.escapeHtml(connDate) + '</td></tr>'
        + '<tr><td style="padding:4px 12px 4px 0;color:#666;">トークン</td><td><span style="color:' + tokenColor + ';">' + Utils.escapeHtml(tokenLabel) + '</span></td></tr>'
        + '<tr><td style="padding:4px 12px 4px 0;color:#666;">マッピング店舗</td><td>' + (data.mapped_stores || 0) + ' 店舗</td></tr>'
        + '</table>'
        + '<button class="btn" id="smaregi-disconnect-btn" style="margin-top:1rem;color:#c62828;border-color:#c62828;">連携を解除する</button>';

      // マッピング・インポートセクション表示
      if (mappingSection) mappingSection.style.display = 'block';
      if (importSection) importSection.style.display = 'block';

      // 店舗データ読み込み
      loadSmaregiStores();
    } else {
      // 未接続
      html += '<div style="margin-bottom:1rem;">'
        + '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ccc;margin-right:6px;vertical-align:middle;"></span>'
        + '<strong style="color:#666;">未接続</strong>'
        + '</div>'
        + '<p style="color:#666;font-size:0.85rem;margin-bottom:1rem;">スマレジPOSと連携すると、注文データを自動でスマレジに送信できます。</p>'
        + '<button class="btn btn-primary" id="smaregi-connect-btn">スマレジと連携する</button>';

      if (mappingSection) mappingSection.style.display = 'none';
      if (importSection) importSection.style.display = 'none';
    }

    display.innerHTML = html;

    // イベント: 連携開始
    var connectBtn = document.getElementById('smaregi-connect-btn');
    if (connectBtn) {
      connectBtn.addEventListener('click', function () {
        window.location.href = SMAREGI_API + '/auth.php';
      });
    }

    // イベント: 連携解除
    var disconnectBtn = document.getElementById('smaregi-disconnect-btn');
    if (disconnectBtn) {
      disconnectBtn.addEventListener('click', function () {
        disconnectSmaregi();
      });
    }
  }

  // --- 連携解除 ---
  function disconnectSmaregi() {
    if (!confirm('スマレジ連携を解除しますか？\nPOSLAのメニューや注文履歴はそのまま残ります。')) return;

    _smaregiRequest('POST', '/disconnect.php').then(function () {
      showToast('スマレジ連携を解除しました', 'success');
      loadSmaregiStatus();
    }).catch(function (err) {
      showToast(err.message || '解除に失敗しました', 'error');
    });
  }

  // --- 店舗マッピング ---
  var _smaregiStoreData = null;

  function loadSmaregiStores() {
    var listEl = document.getElementById('smaregi-store-mapping-list');
    if (!listEl) return;
    listEl.innerHTML = '<span style="color:#888;">店舗情報を読み込み中...</span>';

    _smaregiRequest('GET', '/stores.php').then(function (data) {
      _smaregiStoreData = data;
      renderStoreMapping(listEl, data);
      updateImportStoreSelect(data);
    }).catch(function (err) {
      listEl.innerHTML = '<p style="color:#c62828;">' + Utils.escapeHtml(err.message) + '</p>';
    });
  }

  function renderStoreMapping(listEl, data) {
    var poslaStores = data.posla_stores || [];
    var smaregiStores = data.smaregi_stores || [];
    var mappings = data.mappings || [];

    // 既存マッピングのマップ（store_id → smaregi_store_id）
    var currentMap = {};
    for (var m = 0; m < mappings.length; m++) {
      currentMap[mappings[m].store_id] = mappings[m].smaregi_store_id;
    }

    if (poslaStores.length === 0) {
      listEl.innerHTML = '<p style="color:#888;font-size:0.85rem;">店舗がありません</p>';
      return;
    }

    var html = '<table class="data-table" style="width:100%;"><thead><tr>'
      + '<th>POSLA店舗</th><th>スマレジ店舗</th>'
      + '</tr></thead><tbody>';

    for (var i = 0; i < poslaStores.length; i++) {
      var ps = poslaStores[i];
      var selectedSmId = currentMap[ps.id] || '';

      html += '<tr>'
        + '<td>' + Utils.escapeHtml(ps.name) + '</td>'
        + '<td><select class="form-input smaregi-store-select" data-posla-store-id="' + Utils.escapeHtml(ps.id) + '" style="min-width:200px;">'
        + '<option value="">（マッピングしない）</option>';

      for (var j = 0; j < smaregiStores.length; j++) {
        var ss = smaregiStores[j];
        var sel = (ss.storeId === selectedSmId) ? ' selected' : '';
        html += '<option value="' + Utils.escapeHtml(ss.storeId || '') + '"' + sel + '>'
          + Utils.escapeHtml(ss.storeName || 'Store ' + ss.storeId) + '</option>';
      }

      html += '</select></td></tr>';
    }

    html += '</tbody></table>';
    listEl.innerHTML = html;
  }

  function updateImportStoreSelect(data) {
    var selectEl = document.getElementById('smaregi-import-store');
    if (!selectEl) return;

    var mappings = data.mappings || [];
    var poslaStores = data.posla_stores || [];

    // マッピング済みstore_idのセット
    var mappedStoreIds = {};
    for (var m = 0; m < mappings.length; m++) {
      mappedStoreIds[mappings[m].store_id] = true;
    }

    var html = '<option value="">-- 店舗を選択 --</option>';
    for (var i = 0; i < poslaStores.length; i++) {
      var ps = poslaStores[i];
      if (mappedStoreIds[ps.id]) {
        html += '<option value="' + Utils.escapeHtml(ps.id) + '">' + Utils.escapeHtml(ps.name) + '</option>';
      }
    }

    selectEl.innerHTML = html;
  }

  function saveStoreMapping() {
    var selects = document.querySelectorAll('.smaregi-store-select');
    var mappings = [];

    for (var i = 0; i < selects.length; i++) {
      var sel = selects[i];
      var poslaStoreId = sel.getAttribute('data-posla-store-id');
      var smaregiStoreId = sel.value;
      if (poslaStoreId && smaregiStoreId) {
        mappings.push({
          store_id: poslaStoreId,
          smaregi_store_id: smaregiStoreId,
          sync_enabled: 1
        });
      }
    }

    _smaregiRequest('POST', '/store-mapping.php', { mappings: mappings }).then(function (data) {
      showToast('店舗マッピングを保存しました', 'success');
      loadSmaregiStores();
    }).catch(function (err) {
      showToast(err.message || '保存に失敗しました', 'error');
    });
  }

  // --- メニューインポート ---
  function importSmaregiMenu() {
    var selectEl = document.getElementById('smaregi-import-store');
    var storeId = selectEl ? selectEl.value : '';
    if (!storeId) {
      showToast('インポート対象の店舗を選択してください', 'error');
      return;
    }

    var btn = document.getElementById('smaregi-import-btn');
    if (btn) {
      btn.disabled = true;
      // P1-20: 同期翻訳のため、ボタン文言で事前告知
      btn.textContent = 'インポート＆翻訳中...';
    }

    var resultEl = document.getElementById('smaregi-import-result');
    if (resultEl) resultEl.innerHTML = '';

    _smaregiRequest('POST', '/import-menu.php', { store_id: storeId }).then(function (data) {
      var msg = 'インポート完了: '
        + (data.imported || 0) + '件追加, '
        + (data.skipped || 0) + '件スキップ';
      if (data.errors > 0) {
        msg += ', ' + data.errors + '件エラー';
      }

      // P1-20: 自動翻訳結果の表示分岐
      var translateMsg = '';
      var translateWarn = false;
      var auto = data.auto_translate;
      if (auto && auto.ok === true) {
        translateMsg = '（翻訳 ' + (auto.translated || 0) + '件）';
      } else if (auto && auto.ok === false) {
        translateMsg = '⚠ 翻訳は後ほど手動で実行してください';
        translateWarn = true;
      }
      // auto === null の場合（新規0件）は何も追記しない

      if (resultEl) {
        var bgColor = translateWarn ? '#fff8e1' : '#e8f5e9';
        var fgColor = translateWarn ? '#e65100' : '#2e7d32';
        var html = '<div style="padding:0.75rem;background:' + bgColor + ';border-radius:4px;color:' + fgColor + ';font-size:0.9rem;">'
          + Utils.escapeHtml(msg);
        if (translateMsg) {
          html += '<br>' + Utils.escapeHtml(translateMsg);
        }
        html += '</div>';
        resultEl.innerHTML = html;
      }
      showToast(msg + (translateMsg ? ' ' + translateMsg : ''), translateWarn ? 'warning' : 'success');
    }).catch(function (err) {
      if (resultEl) {
        resultEl.innerHTML = '<div style="padding:0.75rem;background:#ffebee;border-radius:4px;color:#c62828;font-size:0.9rem;">' + Utils.escapeHtml(err.message) + '</div>';
      }
      showToast(err.message || 'インポートに失敗しました', 'error');
    }).then(function () {
      // finally 相当
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'インポート実行';
      }
    });
  }

  // --- スマレジ イベントバインド ---
  var smMappingBtn = document.getElementById('smaregi-save-mapping-btn');
  if (smMappingBtn) {
    smMappingBtn.addEventListener('click', function () {
      saveStoreMapping();
    });
  }

  var smImportBtn = document.getElementById('smaregi-import-btn');
  if (smImportBtn) {
    smImportBtn.addEventListener('click', function () {
      importSmaregiMenu();
    });
  }

  // --- ログアウト ---
  var logoutBtn = document.getElementById('owner-btn-logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', function () {
      AdminApi.logout().finally(function () {
        window.location.href = 'index.html';
      });
    });
  }

})();
