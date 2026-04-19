/**
 * POSLA管理画面 メインアプリ
 */
(function() {
  'use strict';

  // ── トースト ──
  function showToast(msg) {
    var el = document.getElementById('posla-toast');
    if (!el) return;
    el.textContent = msg;
    el.style.opacity = '1';
    setTimeout(function() { el.style.opacity = '0'; }, 3000);
  }

  // ── 認証チェック ──
  PoslaApi.me().then(function(data) {
    initApp(data.admin);
  }).catch(function() {
    window.location.href = 'index.html';
  });

  // ── 初期化 ──
  function initApp(admin) {
    // ヘッダー
    var userEl = document.getElementById('posla-header-user');
    if (userEl) userEl.textContent = admin.displayName || admin.email;

    // ログアウト
    var logoutBtn = document.getElementById('posla-btn-logout');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function() {
        PoslaApi.logout().then(function() {
          window.location.href = 'index.html';
        }).catch(function() {
          window.location.href = 'index.html';
        });
      });
    }

    // タブクリック
    var tabNav = document.getElementById('posla-tab-nav');
    if (tabNav) {
      tabNav.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-tab]');
        if (!btn) return;
        activateTab(btn.getAttribute('data-tab'));
      });
    }

    // モーダル閉じ
    var overlay = document.getElementById('tenant-modal-overlay');
    var closeBtn = document.getElementById('modal-close-btn');
    var cancelBtn = document.getElementById('modal-cancel-btn');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (overlay) {
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal();
      });
    }

    // モーダル保存
    var saveBtn = document.getElementById('modal-save-btn');
    if (saveBtn) saveBtn.addEventListener('click', saveTenant);

    // テナント作成
    var createBtn = document.getElementById('btn-create-tenant');
    if (createBtn) createBtn.addEventListener('click', createTenant);

    // テナント一覧クリック委譲
    var tenantsList = document.getElementById('tenants-list');
    if (tenantsList) {
      tenantsList.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action="edit-tenant"]');
        if (btn) {
          var id = btn.getAttribute('data-id');
          openTenantModal(id);
        }
      });
    }

    // API設定
    var saveApiBtn = document.getElementById('btn-save-api');
    if (saveApiBtn) saveApiBtn.addEventListener('click', saveApiSettings);

    var clearApiBtn = document.getElementById('btn-clear-api');
    if (clearApiBtn) clearApiBtn.addEventListener('click', clearApiSettings);

    // 初期タブ
    activateTab('overview');
  }

  // ── タブ切替 ──
  var _currentTab = '';

  function activateTab(tabId) {
    _currentTab = tabId;

    // ボタン active
    var buttons = document.querySelectorAll('#posla-tab-nav [data-tab]');
    for (var i = 0; i < buttons.length; i++) {
      if (buttons[i].getAttribute('data-tab') === tabId) {
        buttons[i].classList.add('active');
      } else {
        buttons[i].classList.remove('active');
      }
    }

    // パネル表示
    var panels = document.querySelectorAll('.posla-tab-panel');
    for (var j = 0; j < panels.length; j++) {
      if (panels[j].id === 'posla-panel-' + tabId) {
        panels[j].classList.add('active');
      } else {
        panels[j].classList.remove('active');
      }
    }

    // データ読み込み
    if (tabId === 'overview') loadDashboard();
    if (tabId === 'tenants') loadTenants();
    if (tabId === 'api-settings') loadApiStatus();
  }

  // ── ダッシュボード ──
  function loadDashboard() {
    PoslaApi.getDashboard().then(function(data) {
      // 統計カード
      var statsEl = document.getElementById('overview-stats');
      if (statsEl) {
        statsEl.innerHTML =
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.totalTenants)) + '</div><div class="stat-card__label">テナント数</div></div>' +
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.totalStores)) + '</div><div class="stat-card__label">店舗数</div></div>' +
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.totalUsers)) + '</div><div class="stat-card__label">ユーザー数</div></div>';
      }

      // プラン分布
      var planEl = document.getElementById('plan-distribution');
      if (planEl) {
        var planLabels = { standard: 'Standard', pro: 'Pro', enterprise: 'Enterprise' };
        var html = '<table class="data-table"><thead><tr><th>プラン</th><th>テナント数</th></tr></thead><tbody>';
        if (data.planDistribution && data.planDistribution.length > 0) {
          for (var i = 0; i < data.planDistribution.length; i++) {
            var p = data.planDistribution[i];
            var label = planLabels[p.plan] || p.plan;
            html += '<tr><td><span class="badge badge--' + Utils.escapeHtml(p.plan) + '">' + Utils.escapeHtml(label) + '</span></td><td>' + Utils.escapeHtml(String(p.count)) + '</td></tr>';
          }
        } else {
          html += '<tr><td colspan="2" style="text-align:center;color:var(--text-muted);">データなし</td></tr>';
        }
        html += '</tbody></table>';
        planEl.innerHTML = html;
      }

      // 最近のテナント
      var recentEl = document.getElementById('recent-tenants');
      if (recentEl) {
        var rhtml = '<table class="data-table"><thead><tr><th>テナント名</th><th>Slug</th><th>プラン</th><th>状態</th><th>作成日</th></tr></thead><tbody>';
        if (data.recentTenants && data.recentTenants.length > 0) {
          for (var j = 0; j < data.recentTenants.length; j++) {
            var t = data.recentTenants[j];
            var activeClass = parseInt(t.is_active) ? 'active' : 'inactive';
            var activeLabel = parseInt(t.is_active) ? '有効' : '無効';
            var dateStr = t.created_at ? t.created_at.substring(0, 10) : '-';
            rhtml += '<tr>' +
              '<td>' + Utils.escapeHtml(t.name) + '</td>' +
              '<td><code>' + Utils.escapeHtml(t.slug) + '</code></td>' +
              '<td><span class="badge badge--' + Utils.escapeHtml(t.plan) + '">' + Utils.escapeHtml(t.plan) + '</span></td>' +
              '<td><span class="badge badge--' + activeClass + '">' + activeLabel + '</span></td>' +
              '<td>' + Utils.escapeHtml(dateStr) + '</td>' +
              '</tr>';
          }
        } else {
          rhtml += '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);">データなし</td></tr>';
        }
        rhtml += '</tbody></table>';
        recentEl.innerHTML = rhtml;
      }
    }).catch(function(err) {
      showToast('ダッシュボードの読み込みに失敗しました: ' + err.message);
    });
  }

  // ── テナント一覧 ──
  function loadTenants() {
    PoslaApi.getTenants().then(function(data) {
      var container = document.getElementById('tenants-list');
      if (!container) return;

      var tenants = data.tenants || [];
      var html = '<table class="data-table"><thead><tr>' +
        '<th>テナント名</th><th>Slug</th><th>プラン</th>' +
        '<th>店舗数</th><th>ユーザー数</th><th>サブスク</th><th>Connect</th><th>状態</th><th>操作</th>' +
        '</tr></thead><tbody>';

      if (tenants.length === 0) {
        html += '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);">テナントがありません</td></tr>';
      } else {
        for (var i = 0; i < tenants.length; i++) {
          var t = tenants[i];
          var activeClass = parseInt(t.is_active) ? 'active' : 'inactive';
          var activeLabel = parseInt(t.is_active) ? '有効' : '無効';

          var subStatus = t.subscription_status || 'none';
          var subHtml = '';
          if (subStatus === 'active') {
            subHtml = '<span class="badge badge--standard">active</span>';
          } else if (subStatus === 'past_due') {
            subHtml = '<span class="badge badge--inactive" style="background:#ffebee;color:#c62828;">past_due</span>';
          } else if (subStatus === 'canceled') {
            subHtml = '<span class="badge badge--inactive">canceled</span>';
          } else if (subStatus === 'trialing') {
            subHtml = '<span class="badge badge--pro">trialing</span>';
          }

          // L-13: Connect状態バッジ
          var connectHtml = '-';
          if (t.stripe_connect_account_id) {
            if (parseInt(t.connect_onboarding_complete)) {
              connectHtml = '<span class="badge badge--active">有効</span>';
            } else {
              connectHtml = '<span class="badge badge--pro">登録中</span>';
            }
          }

          html += '<tr>' +
            '<td>' + Utils.escapeHtml(t.name) + '</td>' +
            '<td><code>' + Utils.escapeHtml(t.slug) + '</code></td>' +
            '<td><span class="badge badge--' + Utils.escapeHtml(t.plan) + '">' + Utils.escapeHtml(t.plan) + '</span></td>' +
            '<td>' + Utils.escapeHtml(String(t.store_count)) + '</td>' +
            '<td>' + Utils.escapeHtml(String(t.user_count)) + '</td>' +
            '<td>' + subHtml + '</td>' +
            '<td>' + connectHtml + '</td>' +
            '<td><span class="badge badge--' + activeClass + '">' + activeLabel + '</span></td>' +
            '<td><button class="btn btn-sm btn-outline" data-action="edit-tenant" data-id="' + Utils.escapeHtml(t.id) + '">編集</button></td>' +
            '</tr>';
        }
      }

      html += '</tbody></table>';
      container.innerHTML = html;
    }).catch(function(err) {
      showToast('テナント一覧の読み込みに失敗しました: ' + err.message);
    });
  }

  // ── テナント編集モーダル ──
  function openTenantModal(id) {
    PoslaApi.getTenant(id).then(function(data) {
      var t = data.tenant;
      document.getElementById('modal-tenant-id').value = t.id;
      document.getElementById('modal-tenant-title').textContent = Utils.escapeHtml(t.name) + ' の編集';
      document.getElementById('modal-tenant-name').value = t.name || '';
      document.getElementById('modal-tenant-slug').value = t.slug || '';
      document.getElementById('modal-tenant-plan').value = t.plan || 'standard';
      document.getElementById('modal-tenant-active').value = parseInt(t.is_active) ? '1' : '0';

      document.getElementById('tenant-modal-overlay').classList.add('open');
    }).catch(function(err) {
      showToast('テナント情報の取得に失敗しました: ' + err.message);
    });
  }

  function closeModal() {
    document.getElementById('tenant-modal-overlay').classList.remove('open');
  }

  function saveTenant() {
    var id = document.getElementById('modal-tenant-id').value;
    var updateData = {
      name: document.getElementById('modal-tenant-name').value.trim(),
      is_active: parseInt(document.getElementById('modal-tenant-active').value)
    };

    var saveBtn = document.getElementById('modal-save-btn');
    saveBtn.disabled = true;

    PoslaApi.updateTenant(id, updateData).then(function() {
      showToast('テナントを更新しました');
      closeModal();
      loadTenants();
    }).catch(function(err) {
      showToast('更新に失敗しました: ' + err.message);
    }).then(function() {
      saveBtn.disabled = false;
    });
  }

  // ── テナント作成 ──
  function createTenant() {
    var slug = document.getElementById('new-slug').value.trim();
    var name = document.getElementById('new-name').value.trim();
    var plan = document.getElementById('new-plan').value;

    if (!slug) { showToast('Slugは必須です'); return; }
    if (!/^[a-z0-9\-]+$/.test(slug)) { showToast('Slugは半角英小文字・数字・ハイフンのみ'); return; }
    if (!name) { showToast('テナント名は必須です'); return; }

    var btn = document.getElementById('btn-create-tenant');
    btn.disabled = true;

    PoslaApi.createTenant({ slug: slug, name: name, plan: plan }).then(function() {
      showToast('テナントを作成しました');
      document.getElementById('new-slug').value = '';
      document.getElementById('new-name').value = '';
      document.getElementById('new-plan').value = 'standard';
      activateTab('tenants');
    }).catch(function(err) {
      showToast('作成に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

  // ── API設定（POSLA共通） ──
  var _apiSettings = {};

  // 機密キーは「設定済みかどうか + マスク表示」のみ。実値はサーバーから返らない
  function _buildKeyDisplay(label, isSet, masked) {
    if (!isSet) {
      return '<span class="ai-status ai-status--unset"></span>未設定';
    }
    return '<span class="ai-status ai-status--set"></span>' +
      '<span>' + Utils.escapeHtml(masked || '********') + '</span>';
  }

  // posla_settings GET レスポンスからキー情報 {set, masked} を取り出す
  // 新形式: settings[key] = {set, masked}  /  旧形式: settings[key+'_set'], settings[key+'_masked']
  function _getKeyInfo(s, key) {
    var entry = s[key];
    if (entry && typeof entry === 'object' && 'set' in entry) {
      return { set: !!entry.set, masked: entry.masked || null };
    }
    return { set: !!s[key + '_set'], masked: s[key + '_masked'] || null };
  }

  // 非機密項目（price ID 等）の生値取得
  function _getPlainValue(s, key) {
    var entry = s[key];
    if (entry && typeof entry === 'object') {
      // 機密キー構造体しかない場合は空扱い
      return '';
    }
    if (entry !== undefined && entry !== null) return String(entry);
    if (s[key + '_value'] !== undefined && s[key + '_value'] !== null) return String(s[key + '_value']);
    return '';
  }

  function loadApiStatus() {
    PoslaApi.getSettings().then(function(data) {
      _apiSettings = data.settings || {};
      var s = _apiSettings;
      var statusEl = document.getElementById('current-api-status');
      if (!statusEl) return;

      var gemini = _getKeyInfo(s, 'gemini_api_key');
      var places = _getKeyInfo(s, 'google_places_api_key');
      var stripeSecret = _getKeyInfo(s, 'stripe_secret_key');
      var stripePub = _getKeyInfo(s, 'stripe_publishable_key');
      var stripeWebhook = _getKeyInfo(s, 'stripe_webhook_secret');
      var smaregiSecret = _getKeyInfo(s, 'smaregi_client_secret');

      var geminiHtml = _buildKeyDisplay('gemini', gemini.set, gemini.masked);
      var placesHtml = _buildKeyDisplay('places', places.set, places.masked);
      var stripeSecretHtml = _buildKeyDisplay('stripe-secret', stripeSecret.set, stripeSecret.masked);
      var stripePubHtml = _buildKeyDisplay('stripe-pub', stripePub.set, stripePub.masked);
      var stripeWebhookHtml = _buildKeyDisplay('stripe-webhook', stripeWebhook.set, stripeWebhook.masked);
      var smaregiSecretHtml = _buildKeyDisplay('smaregi-secret', smaregiSecret.set, smaregiSecret.masked);

      var priceBaseVal = _getPlainValue(s, 'stripe_price_base');
      var priceAddVal = _getPlainValue(s, 'stripe_price_additional_store');
      var priceHqVal = _getPlainValue(s, 'stripe_price_hq_broadcast');
      var connectFeeVal = _getPlainValue(s, 'connect_application_fee_percent');
      var smaregiClientIdVal = _getPlainValue(s, 'smaregi_client_id');

      statusEl.innerHTML =
        '<table class="data-table"><tbody>' +
        '<tr><td style="font-weight:600;">Gemini API</td><td>' + geminiHtml + '</td></tr>' +
        '<tr><td style="font-weight:600;">Google Places API</td><td>' + placesHtml + '</td></tr>' +
        '<tr><td colspan="2" style="border-top:2px solid #e0e0e0;font-weight:600;padding-top:0.75rem;">Stripe Billing</td></tr>' +
        '<tr><td style="font-weight:600;">Secret Key</td><td>' + stripeSecretHtml + '</td></tr>' +
        '<tr><td style="font-weight:600;">Publishable Key</td><td>' + stripePubHtml + '</td></tr>' +
        '<tr><td style="font-weight:600;">Webhook Secret</td><td>' + stripeWebhookHtml + '</td></tr>' +
        '<tr><td style="font-weight:600;">Price: 基本料金 (¥20,000)</td><td>' + (priceBaseVal ? Utils.escapeHtml(priceBaseVal) : '<span style="color:#999;">未設定</span>') + '</td></tr>' +
        '<tr><td style="font-weight:600;">Price: 追加店舗 (¥17,000)</td><td>' + (priceAddVal ? Utils.escapeHtml(priceAddVal) : '<span style="color:#999;">未設定</span>') + '</td></tr>' +
        '<tr><td style="font-weight:600;">Price: 本部一括配信 (¥3,000)</td><td>' + (priceHqVal ? Utils.escapeHtml(priceHqVal) : '<span style="color:#999;">未設定</span>') + '</td></tr>' +
        '<tr><td colspan="2" style="border-top:2px solid #e0e0e0;font-weight:600;padding-top:0.75rem;">Stripe Connect</td></tr>' +
        '<tr><td style="font-weight:600;">Application Fee (%)</td><td>' + (connectFeeVal ? Utils.escapeHtml(connectFeeVal) : '未設定') + '</td></tr>' +
        '<tr><td colspan="2" style="border-top:2px solid #e0e0e0;font-weight:600;padding-top:0.75rem;">スマレジ連携</td></tr>' +
        '<tr><td style="font-weight:600;">Client ID</td><td>' + (smaregiClientIdVal ? Utils.escapeHtml(smaregiClientIdVal) : '<span style="color:#999;">未設定</span>') + '</td></tr>' +
        '<tr><td style="font-weight:600;">Client Secret</td><td>' + smaregiSecretHtml + '</td></tr>' +
        '</tbody></table>';

      // Connect手数料率フィールドに現在値をセット
      var feeInput = document.getElementById('posla-connect-fee');
      if (feeInput && connectFeeVal) {
        feeInput.value = connectFeeVal;
      }

      // スマレジ Client IDに現在値をセット
      var smClientIdInput = document.getElementById('posla-smaregi-client-id');
      if (smClientIdInput && smaregiClientIdVal) {
        smClientIdInput.value = smaregiClientIdVal;
      }
    }).catch(function(err) {
      showToast('API設定の読み込みに失敗しました: ' + err.message);
    });
  }

  function saveApiSettings() {
    var aiKey = document.getElementById('posla-ai-key').value.trim();
    var placesKey = document.getElementById('posla-places-key').value.trim();
    var stripeSecret = document.getElementById('posla-stripe-secret').value.trim();
    var stripePub = document.getElementById('posla-stripe-pub').value.trim();
    var stripeWebhook = document.getElementById('posla-stripe-webhook').value.trim();
    var priceBase = document.getElementById('posla-stripe-price-base').value.trim();
    var priceAdd = document.getElementById('posla-stripe-price-add').value.trim();
    var priceHq = document.getElementById('posla-stripe-price-hq').value.trim();
    var connectFeeEl = document.getElementById('posla-connect-fee');
    var connectFee = connectFeeEl ? connectFeeEl.value.trim() : '';

    var data = {};
    if (aiKey) data.gemini_api_key = aiKey;
    if (placesKey) data.google_places_api_key = placesKey;
    if (stripeSecret) data.stripe_secret_key = stripeSecret;
    if (stripePub) data.stripe_publishable_key = stripePub;
    if (stripeWebhook) data.stripe_webhook_secret = stripeWebhook;
    if (priceBase) data.stripe_price_base = priceBase;
    if (priceAdd) data.stripe_price_additional_store = priceAdd;
    if (priceHq) data.stripe_price_hq_broadcast = priceHq;
    if (connectFee !== '') data.connect_application_fee_percent = connectFee;

    var smaregiClientId = document.getElementById('posla-smaregi-client-id');
    var smaregiClientSecret = document.getElementById('posla-smaregi-client-secret');
    if (smaregiClientId && smaregiClientId.value.trim()) data.smaregi_client_id = smaregiClientId.value.trim();
    if (smaregiClientSecret && smaregiClientSecret.value.trim()) data.smaregi_client_secret = smaregiClientSecret.value.trim();

    if (Object.keys(data).length === 0) {
      showToast('少なくとも1つの値を入力してください');
      return;
    }

    var btn = document.getElementById('btn-save-api');
    btn.disabled = true;

    PoslaApi.updateSettings(data).then(function() {
      showToast('設定を保存しました');
      document.getElementById('posla-ai-key').value = '';
      document.getElementById('posla-places-key').value = '';
      document.getElementById('posla-stripe-secret').value = '';
      document.getElementById('posla-stripe-pub').value = '';
      document.getElementById('posla-stripe-webhook').value = '';
      document.getElementById('posla-stripe-price-base').value = '';
      document.getElementById('posla-stripe-price-add').value = '';
      document.getElementById('posla-stripe-price-hq').value = '';
      var feeEl = document.getElementById('posla-connect-fee');
      if (feeEl) feeEl.value = '';
      var smIdEl = document.getElementById('posla-smaregi-client-id');
      if (smIdEl) smIdEl.value = '';
      var smSecEl = document.getElementById('posla-smaregi-client-secret');
      if (smSecEl) smSecEl.value = '';
      loadApiStatus();
    }).catch(function(err) {
      showToast('保存に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

  function clearApiSettings() {
    if (!confirm('APIキーを削除します。よろしいですか？')) return;

    var btn = document.getElementById('btn-clear-api');
    btn.disabled = true;

    PoslaApi.updateSettings({
      gemini_api_key: null,
      google_places_api_key: null,
      stripe_secret_key: null,
      stripe_publishable_key: null,
      stripe_webhook_secret: null,
      stripe_price_base: null,
      stripe_price_additional_store: null,
      stripe_price_hq_broadcast: null
    }).then(function() {
      showToast('キーを削除しました');
      loadApiStatus();
    }).catch(function(err) {
      showToast('削除に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

})();
