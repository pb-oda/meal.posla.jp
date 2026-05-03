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
  window.PoslaShowToast = showToast;

  // ── 認証チェック ──
  PoslaApi.me().then(function(data) {
    initApp(data.admin);
  }).catch(function() {
    window.location.href = 'index.html';
  });

  // ── 初期化 ──
  function initApp(admin) {
    updateOpLinks('');
    refreshOpLinksFromSettings();

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

    var adminContent = document.querySelector('.admin-content');
    if (adminContent) {
      adminContent.addEventListener('click', function(e) {
        var jumpBtn = e.target.closest('[data-tab-jump]');
        if (jumpBtn) {
          activateTab(jumpBtn.getAttribute('data-tab-jump'));
          return;
        }

        var releaseBtn = e.target.closest('[data-release-action]');
        if (releaseBtn) {
          handleReleaseReadinessAction(releaseBtn);
          return;
        }

        var openTenantBtn = e.target.closest('[data-open-tenant]');
        if (openTenantBtn) {
          openTenantModal(openTenantBtn.getAttribute('data-open-tenant'));
        }
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
        var resolveBtn = e.target.closest('[data-monitor-resolve-id]');
        if (resolveBtn) {
          e.preventDefault();
          handleResolveMonitorEvent(resolveBtn);
          return;
        }
        if (e.target === overlay) closeModal();
      });
    }

    // モーダル保存
    var saveBtn = document.getElementById('modal-save-btn');
    if (saveBtn) saveBtn.addEventListener('click', saveTenant);

    // テナント作成
    var createBtn = document.getElementById('btn-create-tenant');
    if (createBtn) createBtn.addEventListener('click', createTenant);

    var refreshCellProvisioningBtn = document.getElementById('btn-refresh-cell-provisioning');
    if (refreshCellProvisioningBtn) refreshCellProvisioningBtn.addEventListener('click', function() {
      loadCellProvisioning();
      loadReleasePlan();
    });

    var cellProvisioningList = document.getElementById('cell-provisioning-list');
    if (cellProvisioningList) {
      cellProvisioningList.addEventListener('click', handleCellProvisioningClick);
    }

    var refreshReleasePlanBtn = document.getElementById('btn-refresh-release-plan');
    if (refreshReleasePlanBtn) refreshReleasePlanBtn.addEventListener('click', loadReleasePlan);

    var releasePlanTargetScope = document.getElementById('release-plan-target-scope');
    if (releasePlanTargetScope) releasePlanTargetScope.addEventListener('change', updateReleasePlanTargetScopeUi);

    var saveReleasePlanBtn = document.getElementById('btn-save-release-plan');
    if (saveReleasePlanBtn) saveReleasePlanBtn.addEventListener('click', saveReleasePlan);

    var openFeatureFlagsFromReleaseBtn = document.getElementById('btn-open-feature-flags-from-release');
    if (openFeatureFlagsFromReleaseBtn) openFeatureFlagsFromReleaseBtn.addEventListener('click', function() {
      activateTab('feature-flags');
    });

    var refreshFeatureFlagsBtn = document.getElementById('btn-refresh-feature-flags');
    if (refreshFeatureFlagsBtn) refreshFeatureFlagsBtn.addEventListener('click', loadFeatureFlags);

    var featureFlagTenantSelect = document.getElementById('ff-tenant-select');
    if (featureFlagTenantSelect) featureFlagTenantSelect.addEventListener('change', loadFeatureFlags);

    var featureFlagScopeSelect = document.getElementById('ff-scope-type');
    if (featureFlagScopeSelect) featureFlagScopeSelect.addEventListener('change', updateFeatureFlagScopeHint);

    var saveFeatureFlagBtn = document.getElementById('btn-save-feature-flag');
    if (saveFeatureFlagBtn) saveFeatureFlagBtn.addEventListener('click', saveFeatureFlagOverride);

    var clearFeatureFlagBtn = document.getElementById('btn-clear-feature-flag');
    if (clearFeatureFlagBtn) clearFeatureFlagBtn.addEventListener('click', clearFeatureFlagOverride);

    // テナント一覧クリック委譲
    var tenantsList = document.getElementById('tenants-list');
    if (tenantsList) {
      tenantsList.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action="edit-tenant"]');
        if (btn) {
          var id = btn.getAttribute('data-id');
          openTenantModal(id);
          return;
        }

        btn = e.target.closest('[data-action="delete-tenant"]');
        if (btn) {
          deleteTenant(
            btn.getAttribute('data-id'),
            btn.getAttribute('data-slug'),
            btn.getAttribute('data-name')
          );
        }
      });
    }

    // API設定
    var saveApiBtn = document.getElementById('btn-save-api');
    if (saveApiBtn) saveApiBtn.addEventListener('click', saveApiSettings);

    var clearApiBtn = document.getElementById('btn-clear-api');
    if (clearApiBtn) clearApiBtn.addEventListener('click', clearApiSettings);

    var runMonitorBtn = document.getElementById('btn-run-monitor-health');
    if (runMonitorBtn) runMonitorBtn.addEventListener('click', runMonitorHealth);

    var sendMonitorTestBtn = document.getElementById('btn-send-monitor-test');
    if (sendMonitorTestBtn) sendMonitorTestBtn.addEventListener('click', sendMonitorTestAlert);

    var sendOpsMailTestBtn = document.getElementById('btn-send-ops-mail-test');
    if (sendOpsMailTestBtn) sendOpsMailTestBtn.addEventListener('click', sendOpsMailTest);

    var saveOpsConnectionBtn = document.getElementById('btn-save-ops-connection');
    if (saveOpsConnectionBtn) saveOpsConnectionBtn.addEventListener('click', saveOpsConnectionSettings);

    var refreshPushBtn = document.getElementById('btn-refresh-push');
    if (refreshPushBtn) refreshPushBtn.addEventListener('click', loadPushStatus);

    var pushGenerateBtn = document.getElementById('btn-push-generate-apply');
    if (pushGenerateBtn) pushGenerateBtn.addEventListener('click', generateAndApplyPushVapid);

    var pushPublicBtn = document.getElementById('btn-push-public-view');
    if (pushPublicBtn) pushPublicBtn.addEventListener('click', function() {
      revealPushSecret('get_public_key');
    });

    var pushPrivateBtn = document.getElementById('btn-push-private-view');
    if (pushPrivateBtn) pushPrivateBtn.addEventListener('click', function() {
      revealPushSecret('get_private_pem');
    });

    var pushBackupBtn = document.getElementById('btn-push-backup-view');
    if (pushBackupBtn) pushBackupBtn.addEventListener('click', function() {
      revealPushSecret('get_backup_bundle');
    });

    var copyPushBtn = document.getElementById('btn-push-secret-copy');
    if (copyPushBtn) copyPushBtn.addEventListener('click', copyPushSecretValue);

    var downloadPushBtn = document.getElementById('btn-push-secret-download');
    if (downloadPushBtn) downloadPushBtn.addEventListener('click', downloadPushSecretValue);

    // サポートセンター (HELPDESK-P2-NONAI-ALL-20260423: AIコード案内→非AI置換)
    // 実装は js/posla-supportdesk.js (PoslaSupportdesk.mount)

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
    if (tabId === 'cell-provisioning') {
      loadCellProvisioning();
      loadReleasePlan();
    }
    if (tabId === 'feature-flags') loadFeatureFlags();
    if (tabId === 'api-settings') loadApiStatus();
    if (tabId === 'ops-source') loadOpsSource();
    if (tabId === 'pwa-push') loadPushStatus();
    if (tabId === 'admin-users') loadAdminUsers();
    if (tabId === 'customer-support' || tabId === 'support' || tabId === 'ops') updateOpLinks('');
  }

  function deriveOpBaseUrl(endpoint) {
    if (!endpoint) return '';
    var anchor = document.createElement('a');
    anchor.href = endpoint;
    if (!anchor.protocol || !anchor.host) return '';
    if (anchor.hostname === 'host.docker.internal') {
      var browserHost = (window.location && window.location.hostname) ? window.location.hostname : '';
      anchor.hostname = (browserHost === 'localhost' || browserHost === '127.0.0.1') ? browserHost : '127.0.0.1';
    }
    return anchor.protocol + '//' + anchor.host + '/';
  }

  function opBaseUrlFromSettings(settings) {
    var publicUrl = _getPlainValue(settings, 'codex_ops_public_url');
    if (publicUrl) return deriveOpBaseUrl(publicUrl);
    return deriveOpBaseUrl(_getPlainValue(settings, 'codex_ops_case_endpoint'));
  }

  function buildOpUrl(baseUrl, path) {
    var base = baseUrl || 'http://127.0.0.1:8091/';
    var suffix = path || '';
    if (base.charAt(base.length - 1) !== '/') base += '/';
    if (suffix.charAt(0) === '/') suffix = suffix.substring(1);
    return base + suffix;
  }

  function updateOpLinks(baseUrl) {
    var links = document.querySelectorAll('[data-op-link]');
    for (var i = 0; i < links.length; i++) {
      links[i].setAttribute('href', '../../api/posla/op-launch.php');
      links[i].setAttribute('data-op-target-url', buildOpUrl(baseUrl, links[i].getAttribute('data-op-path') || ''));
    }
  }

  function refreshOpLinksFromSettings() {
    if (!PoslaApi || !PoslaApi.getSettings) return;
    PoslaApi.getSettings().then(function(data) {
      var s = (data && data.settings) ? data.settings : {};
      updateOpLinks(opBaseUrlFromSettings(s));
    }).catch(function() {
      updateOpLinks('');
    });
  }

  // ── Feature Flags ──
  var _featureFlagState = {
    tenants: [],
    tenantsLoaded: false,
    flags: [],
    cellId: ''
  };
  var FEATURE_FLAG_TEST_CELL_ID = 'test-01';

  function loadFeatureFlagTenants() {
    if (_featureFlagState.tenantsLoaded) {
      return Promise.resolve(_featureFlagState.tenants);
    }

    return PoslaApi.getTenants().then(function(data) {
      _featureFlagState.tenants = data.tenants || [];
      _featureFlagState.tenantsLoaded = true;
      renderFeatureFlagTenantOptions();
      return _featureFlagState.tenants;
    });
  }

  function renderFeatureFlagTenantOptions() {
    var select = document.getElementById('ff-tenant-select');
    if (!select) return;

    var currentValue = select.value || '';
    var html = '<option value="">顧客未選択（global / cell のみ確認）</option>';
    var i;
    for (i = 0; i < _featureFlagState.tenants.length; i++) {
      var tenant = _featureFlagState.tenants[i];
      html += '<option value="' + Utils.escapeHtml(tenant.id) + '">' +
        Utils.escapeHtml(tenant.name || tenant.slug || tenant.id) +
        ' / ' + Utils.escapeHtml(tenant.slug || '-') +
        '</option>';
    }
    select.innerHTML = html;
    select.value = currentValue;
  }

  function buildFeatureFlagCellOptions() {
    var map = {};
    var items = [];
    var i;

    function addCell(cellId, label, sourceRank) {
      if (!cellId || map[cellId]) return;
      map[cellId] = {
        id: cellId,
        label: label || cellId,
        rank: cellId === FEATURE_FLAG_TEST_CELL_ID ? 0 : sourceRank
      };
    }

    for (i = 0; i < _featureFlagState.tenants.length; i++) {
      var tenant = _featureFlagState.tenants[i];
      var cellId = tenant.cell_id || '';
      var tenantLabel = tenant.name || tenant.slug || tenant.id || cellId;
      if (cellId) {
        addCell(cellId, tenantLabel + ' / ' + cellId, 1);
      }
    }

    if (_featureFlagState.cellId) {
      addCell(_featureFlagState.cellId, '現在のcell / ' + _featureFlagState.cellId, 2);
    }

    Object.keys(map).forEach(function(cellId) {
      items.push(map[cellId]);
    });
    items.sort(function(a, b) {
      if (a.rank !== b.rank) return a.rank - b.rank;
      return a.label.localeCompare(b.label, 'ja');
    });
    return items;
  }

  function renderFeatureFlagCellOptions() {
    var select = document.getElementById('ff-cell-id');
    if (!select) return;

    var currentValue = select.value || '';
    var items = buildFeatureFlagCellOptions();
    var html = '';
    var preferredValue = '';
    var hasCurrent = false;
    var i;

    if (items.length === 0) {
      select.innerHTML = '<option value="">cell未検出</option>';
      return;
    }

    for (i = 0; i < items.length; i++) {
      if (items[i].id === currentValue) hasCurrent = true;
      if (!preferredValue && items[i].id === FEATURE_FLAG_TEST_CELL_ID) {
        preferredValue = items[i].id;
      }
      html += '<option value="' + Utils.escapeHtml(items[i].id) + '">' +
        Utils.escapeHtml(items[i].label) +
        '</option>';
    }
    select.innerHTML = html;

    if (hasCurrent) {
      select.value = currentValue;
    } else if (preferredValue) {
      select.value = preferredValue;
    } else if (_featureFlagState.cellId) {
      select.value = _featureFlagState.cellId;
    }
  }

  function loadFeatureFlags() {
    var selectedTenantId = getSelectedFeatureFlagTenantId();
    var listEl = document.getElementById('feature-flags-list');
    if (listEl) {
      listEl.innerHTML = '<div style="padding:1rem;color:var(--text-muted);">読み込み中...</div>';
    }

    loadFeatureFlagTenants().then(function() {
      return PoslaApi.getFeatureFlags(selectedTenantId);
    }).then(function(data) {
      _featureFlagState.flags = data.flags || [];
      _featureFlagState.cellId = data.cell_id || '';
      renderFeatureFlagCellOptions();
      renderFeatureFlags(data);
      updateFeatureFlagScopeHint();
    }).catch(function(err) {
      if (listEl) {
        listEl.innerHTML = '<div style="padding:1rem;color:#c62828;">Feature Flags の読み込みに失敗しました: ' + Utils.escapeHtml(err.message) + '</div>';
      }
      showToast('Feature Flags の読み込みに失敗しました: ' + err.message);
    });
  }

  function renderFeatureFlags(data) {
    var listEl = document.getElementById('feature-flags-list');
    var keySelect = document.getElementById('ff-feature-key');
    var flags = data.flags || [];
    var i;
    var html;

    if (keySelect) {
      var currentKey = keySelect.value || '';
      var optionHtml = '';
      for (i = 0; i < flags.length; i++) {
        optionHtml += '<option value="' + Utils.escapeHtml(flags[i].feature_key) + '">' +
          Utils.escapeHtml(flags[i].feature_key) +
          '</option>';
      }
      keySelect.innerHTML = optionHtml;
      if (currentKey) keySelect.value = currentKey;
    }

    if (!listEl) return;

    if (!data.available) {
      listEl.innerHTML = '<div style="padding:1rem;color:#c62828;">Feature Flag テーブルが未作成です。migration-p1-42 を適用してください。</div>';
      return;
    }

    if (flags.length === 0) {
      listEl.innerHTML = '<div style="padding:1rem;color:var(--text-muted);">Feature Flag がありません</div>';
      return;
    }

    html = buildFeatureFlagSummaryHtml(flags) + '<div class="feature-flag-list">';
    for (i = 0; i < flags.length; i++) {
      html += buildFeatureFlagCardHtml(flags[i]);
    }
    html += '</div>';
    listEl.innerHTML = html;
  }

  function buildFeatureFlagSummaryHtml(flags) {
    var total = flags.length;
    var onCount = 0;
    var overrideCount = 0;
    var i;
    var overrides;
    for (i = 0; i < flags.length; i++) {
      if (parseInt(flags[i].resolved_enabled, 10) === 1) onCount++;
      overrides = flags[i].overrides || {};
      if (overrides.global) overrideCount++;
      if (overrides.cell) overrideCount++;
      if (overrides.tenant) overrideCount++;
    }

    return '<div class="feature-flag-summary">' +
      '<div class="feature-flag-summary__item"><div class="feature-flag-summary__label">機能数</div><div class="feature-flag-summary__value">' + Utils.escapeHtml(String(total)) + '</div></div>' +
      '<div class="feature-flag-summary__item"><div class="feature-flag-summary__label">有効</div><div class="feature-flag-summary__value">' + Utils.escapeHtml(String(onCount)) + '</div></div>' +
      '<div class="feature-flag-summary__item"><div class="feature-flag-summary__label">無効</div><div class="feature-flag-summary__value">' + Utils.escapeHtml(String(total - onCount)) + '</div></div>' +
      '<div class="feature-flag-summary__item"><div class="feature-flag-summary__label">個別設定</div><div class="feature-flag-summary__value">' + Utils.escapeHtml(String(overrideCount)) + '</div></div>' +
      '</div>';
  }

  function featureFlagScopeLabel(scopeType) {
    if (scopeType === 'global') return '全顧客';
    if (scopeType === 'cell') return 'cell';
    if (scopeType === 'tenant') return '顧客';
    return '初期値';
  }

  function buildFeatureFlagCardHtml(flag) {
    var resolvedOn = parseInt(flag.resolved_enabled, 10) === 1;
    var resolvedTone = resolvedOn ? 'active' : 'inactive';
    var resolvedLabel = resolvedOn ? 'ON' : 'OFF';
    var resolvedSource = featureFlagScopeLabel(flag.resolved_source || 'default');
    if (flag.resolved_scope_id) {
      resolvedSource += ' / ' + flag.resolved_scope_id;
    }
    var desc = flag.description ? '<div class="feature-flag-card__desc">' + Utils.escapeHtml(flag.description) + '</div>' : '';

    return '<section class="feature-flag-card feature-flag-card--' + (resolvedOn ? 'on' : 'off') + '">' +
      '<div class="feature-flag-card__head">' +
        '<div>' +
          '<div class="feature-flag-card__title">' + Utils.escapeHtml(flag.label || flag.feature_key) + '</div>' +
          '<div class="feature-flag-card__key">' + Utils.escapeHtml(flag.feature_key || '-') + '</div>' +
        '</div>' +
        '<div class="tenant-contract">' +
          '<span class="badge badge--' + resolvedTone + '">' + resolvedLabel + '</span>' +
          '<span class="status-pill status-pill--info">' + Utils.escapeHtml(resolvedSource) + '</span>' +
        '</div>' +
      '</div>' +
      desc +
      '<div class="feature-flag-scope-chain">' +
        buildFeatureFlagScopeHtml(flag, 'default') +
        buildFeatureFlagScopeHtml(flag, 'global') +
        buildFeatureFlagScopeHtml(flag, 'cell') +
        buildFeatureFlagScopeHtml(flag, 'tenant') +
      '</div>' +
    '</section>';
  }

  function buildFeatureFlagScopeHtml(flag, scopeType) {
    var overrides = flag.overrides || {};
    var override = scopeType === 'default' ? null : overrides[scopeType];
    var isResolved = (flag.resolved_source || 'default') === scopeType;
    var value;
    var meta = '';

    if (scopeType === 'default') {
      value = buildFeatureFlagBadge(flag.default_enabled);
      meta = '初期値';
    } else if (override) {
      value = buildFeatureFlagBadge(override.enabled);
      if (override.reason) meta += Utils.escapeHtml(override.reason);
      if (override.updated_at) meta += (meta ? '<br>' : '') + Utils.escapeHtml(override.updated_at);
    } else {
      value = '<span style="color:var(--text-muted);">未設定</span>';
      meta = '前段の設定を継承';
    }

    return '<div class="feature-flag-scope' + (isResolved ? ' is-resolved' : '') + '">' +
      '<div class="feature-flag-scope__label">' + Utils.escapeHtml(featureFlagScopeLabel(scopeType)) + '</div>' +
      value +
      '<div class="feature-flag-scope__meta">' + meta + '</div>' +
      '</div>';
  }

  function buildFeatureFlagBadge(value) {
    var on = parseInt(value, 10) === 1;
    return '<span class="badge badge--' + (on ? 'active' : 'inactive') + '">' + (on ? 'ON' : 'OFF') + '</span>';
  }

  function getSelectedFeatureFlagTenantId() {
    var select = document.getElementById('ff-tenant-select');
    return select ? select.value : '';
  }

  function getSelectedFeatureFlagCellId() {
    var select = document.getElementById('ff-cell-id');
    return select ? select.value : '';
  }

  function isFeatureFlagReasonRequired(scopeType) {
    return scopeType === 'global';
  }

  function buildFeatureFlagPayload(clear) {
    var keyEl = document.getElementById('ff-feature-key');
    var scopeEl = document.getElementById('ff-scope-type');
    var enabledEl = document.getElementById('ff-enabled');
    var reasonEl = document.getElementById('ff-reason');
    var featureKey = keyEl ? keyEl.value : '';
    var scopeType = scopeEl ? scopeEl.value : '';
    var tenantId = getSelectedFeatureFlagTenantId();
    var cellId = getSelectedFeatureFlagCellId();
    var payload;

    if (!featureKey) {
      showToast('Feature を選択してください');
      return null;
    }
    if (scopeType === 'tenant' && !tenantId) {
      showToast('選択顧客のみへ適用する場合は、対象顧客を選択してください');
      return null;
    }
    if (scopeType === 'cell' && !cellId) {
      showToast('cellへ適用する場合は、対象cellを選択してください');
      return null;
    }
    if (isFeatureFlagReasonRequired(scopeType) && (!reasonEl || !reasonEl.value.trim())) {
      showToast('全顧客へ影響する変更では理由を入力してください');
      if (reasonEl) reasonEl.focus();
      return null;
    }

    payload = {
      feature_key: featureKey,
      scope_type: scopeType,
      scope_id: scopeType === 'tenant' ? tenantId : (scopeType === 'cell' ? cellId : ''),
      reason: reasonEl ? reasonEl.value.trim() : ''
    };

    if (clear) {
      payload.clear = true;
    } else {
      payload.enabled = enabledEl ? enabledEl.value : '0';
    }

    return payload;
  }

  function saveFeatureFlagOverride() {
    var btn = document.getElementById('btn-save-feature-flag');
    var payload = buildFeatureFlagPayload(false);
    if (!payload) return;

    if (btn) btn.disabled = true;
    PoslaApi.updateFeatureFlag(payload).then(function() {
      showToast('Feature Flag を更新しました');
      loadFeatureFlags();
    }).catch(function(err) {
      showToast('Feature Flag の更新に失敗しました: ' + err.message);
    }).then(function() {
      if (btn) btn.disabled = false;
    });
  }

  function clearFeatureFlagOverride() {
    var btn = document.getElementById('btn-clear-feature-flag');
    var payload = buildFeatureFlagPayload(true);
    if (!payload) return;

    if (btn) btn.disabled = true;
    PoslaApi.updateFeatureFlag(payload).then(function() {
      showToast('Feature Flag override を解除しました');
      loadFeatureFlags();
    }).catch(function(err) {
      showToast('Feature Flag override の解除に失敗しました: ' + err.message);
    }).then(function() {
      if (btn) btn.disabled = false;
    });
  }

  function updateFeatureFlagScopeHint() {
    var scopeEl = document.getElementById('ff-scope-type');
    var hintEl = document.getElementById('ff-scope-hint');
    var cellGroupEl = document.getElementById('ff-cell-scope-group');
    var scopeType = scopeEl ? scopeEl.value : '';
    if (!hintEl) return;

    if (cellGroupEl) {
      cellGroupEl.style.display = scopeType === 'cell' ? '' : 'none';
    }

    if (scopeType === 'global') {
      hintEl.textContent = '全顧客に影響します。test-01 または特定顧客で確認してから最後に使います。';
    } else if (scopeType === 'tenant') {
      hintEl.textContent = '選択した顧客だけに適用します。一部のお客様への先行提供に使います。';
    } else {
      hintEl.textContent = '選択したcellだけに適用します。通常の検証先は POSLA運用確認用 / test-01 です。';
    }
  }

  // ── POSLA 管理者ユーザー管理 ──
  function loadAdminUsers() {
    var rootEl = document.getElementById('posla-admin-users-root');
    if (!rootEl) return;
    if (typeof PoslaSupportConsole === 'undefined' || !PoslaSupportConsole || !PoslaSupportConsole.mountAdminUsers) {
      rootEl.innerHTML = '<div style="padding:1.5rem;color:#c62828;">posla-support-console.js の読み込みに失敗しています。ページを再読み込みしてください。</div>';
      return;
    }
    PoslaSupportConsole.mountAdminUsers(rootEl);
  }

  // ── 顧客サポートビュー (read-only) ──
  function loadCustomerSupport() {
    var rootEl = document.getElementById('posla-customer-support-root');
    if (!rootEl) return;
    if (typeof PoslaSupportConsole === 'undefined' || !PoslaSupportConsole || !PoslaSupportConsole.mountSupport) {
      rootEl.innerHTML = '<div style="padding:1.5rem;color:#c62828;">posla-support-console.js の読み込みに失敗しています。ページを再読み込みしてください。</div>';
      return;
    }
    PoslaSupportConsole.mountSupport(rootEl);
  }

  // ── サポートセンター (非AI、HELPDESK-P2-NONAI-ALL-20260423) ──
  function loadSupport() {
    var rootEl = document.getElementById('posla-support-root');
    if (!rootEl) return;
    if (typeof PoslaSupportdesk === 'undefined' || !PoslaSupportdesk || !PoslaSupportdesk.mount) {
      rootEl.innerHTML = '<div style="padding:1.5rem;color:#c62828;">posla-supportdesk.js の読み込みに失敗しています。ページを再読み込みしてください。</div>';
      return;
    }
    PoslaSupportdesk.mount(rootEl);
  }

  // ── 運用補助センター (非AI / read-only、OPS-CENTER-P1-READONLY-20260423) ──
  function loadOpsCenter() {
    var rootEl = document.getElementById('posla-ops-root');
    if (!rootEl) return;
    if (typeof PoslaOpsCenter === 'undefined' || !PoslaOpsCenter || !PoslaOpsCenter.mount) {
      rootEl.innerHTML = '<div style="padding:1.5rem;color:#c62828;">posla-ops-center.js の読み込みに失敗しています。ページを再読み込みしてください。</div>';
      return;
    }
    PoslaOpsCenter.mount(rootEl);
  }

  // ── OP連携 ──
  function loadOpsSource() {
    var summaryEl = document.getElementById('ops-source-summary');
    if (summaryEl) summaryEl.innerHTML = '<div style="padding:1rem;color:var(--text-muted);">読み込み中...</div>';

    PoslaApi.getSettings().then(function(data) {
      renderOpsSource(data || {});
    }).catch(function(err) {
      if (summaryEl) {
        summaryEl.innerHTML = '<div style="padding:1rem;color:#c62828;">OP連携の読み込みに失敗しました: ' + Utils.escapeHtml(err.message) + '</div>';
      }
      showToast('OP連携の読み込みに失敗しました: ' + err.message);
    });
  }

  function renderOpsConnectionSettings(settingsData) {
    var s = (settingsData && settingsData.settings) ? settingsData.settings : {};
    var publicUrl = _getPlainValue(s, 'codex_ops_public_url');
    var caseEndpoint = _getPlainValue(s, 'codex_ops_case_endpoint');
    var caseToken = _getKeyInfo(s, 'codex_ops_case_token');

    _setInputValue('ops-connection-public-url', publicUrl);
    _setInputValue('ops-connection-case-endpoint', caseEndpoint);
    updateOpLinks(opBaseUrlFromSettings(s));

    return _buildSummaryCard('OP ACCESS', 'OP画面', [
      _summaryLine('URL', publicUrl ? '<code>' + Utils.escapeHtml(publicUrl) + '</code>' : '<span style="color:#999;">未設定</span>')
    ]) +
      _buildSummaryCard('CASE', '障害報告送信', [
        _summaryLine('Endpoint', caseEndpoint ? '<code>' + Utils.escapeHtml(caseEndpoint) + '</code>' : '<span style="color:#999;">未設定</span>'),
        _summaryLine('Token', caseToken.set ? _buildStatusPill('設定済み', 'ok') : _buildStatusPill('未設定', 'warn'))
      ]);
  }

  function renderOpsSource(settingsData) {
    var summaryEl = document.getElementById('ops-source-summary');

    if (summaryEl) {
      summaryEl.innerHTML = renderOpsConnectionSettings(settingsData) +
        _buildSummaryCard('MONITORING', '監視設定の場所', [
          _summaryLine('監視方向', _buildStatusPill('OP -> POSLA', 'info')),
          _summaryLine('監視対象', 'OP側で管理'),
          _summaryLine('POSLA側', 'ping / snapshot の受け口のみ')
        ]);
    }
  }

  function saveOpsConnectionSettings() {
    var btn = document.getElementById('btn-save-ops-connection');
    var payload = {};
    var publicUrl = _readInputValue('ops-connection-public-url');
    var caseEndpoint = _readInputValue('ops-connection-case-endpoint');
    var caseToken = _readInputValue('ops-connection-case-token');

    if (publicUrl !== '') payload.codex_ops_public_url = publicUrl;
    if (caseEndpoint !== '') payload.codex_ops_case_endpoint = caseEndpoint;
    if (caseToken !== '') payload.codex_ops_case_token = caseToken;

    if (Object.keys(payload).length === 0) {
      showToast('保存するOP接続情報を入力してください');
      return;
    }

    if (btn) btn.disabled = true;
    PoslaApi.updateSettings(payload).then(function() {
      showToast('OP接続情報を保存しました');
      _setInputValue('ops-connection-case-token', '');
      refreshOpLinksFromSettings();
      loadOpsSource();
    }).catch(function(err) {
      showToast('OP接続情報の保存に失敗しました: ' + err.message);
    }).then(function() {
      if (btn) btn.disabled = false;
    });
  }

  function _readInputValue(id) {
    var el = document.getElementById(id);
    return el ? el.value.trim() : '';
  }

  // ── PWA/Push タブ ──
  var _pushSecretState = {
    value: '',
    filename: '',
    label: '',
    note: ''
  };
  var _lastPushStatus = null;

  function loadPushStatus(options) {
    options = options || {};
    var statusEl = document.getElementById('posla-push-status');
    var subsEl = document.getElementById('posla-push-subs');
    var logEl = document.getElementById('posla-push-sendlog');
    if (!statusEl || !subsEl || !logEl) return;

    statusEl.textContent = '読み込み中...';
    subsEl.textContent = '';
    logEl.textContent = '';
    if (!options.keepSecretOutput) {
      setPushSecretOutput('', '', '', '');
    }

    PoslaApi.getPushVapid()
      .then(function(data) {
        if (!data) {
          statusEl.textContent = '取得失敗: unknown';
          return;
        }
        _lastPushStatus = data;
        renderPushStatus(data, statusEl, subsEl, logEl);
      })
      .catch(function(err) {
        statusEl.textContent = '取得失敗: ' + ((err && err.message) || 'ネットワークエラー');
      });
  }

  function generateAndApplyPushVapid() {
    var btn = document.getElementById('btn-push-generate-apply');
    var current = _lastPushStatus || {};
    var vapid = current.vapid || {};
    var subscriptions = current.subscriptions || {};
    var enabledCount = Number(subscriptions.enabled || 0);
    var needsConfirm = !!(vapid.available || vapid.public_key || vapid.private_pem_set || enabledCount > 0);
    var confirmMessage = '';
    var note = '';

    if (needsConfirm) {
      confirmMessage = '既存の VAPID 鍵を再生成して適用します。';
      if (enabledCount > 0) {
        confirmMessage += '\n有効購読 ' + enabledCount + ' 件は無効化され、再登録が必要です。';
      } else {
        confirmMessage += '\n既存のブラウザ購読は新しい公開鍵で再登録が必要になる可能性があります。';
      }
      confirmMessage += '\n続行しますか？';
      if (!window.confirm(confirmMessage)) {
        return;
      }
    }

    if (btn) btn.disabled = true;

    PoslaApi.runPushVapidAction('generate_and_apply', {
      confirm_regenerate: needsConfirm
    })
      .then(function(data) {
        var summary = data.summary || {};
        note = '新しい VAPID 鍵を適用しました。退避用 bundle を安全な保管先に保存してください。';
        if (summary.disabled_subscriptions > 0) {
          note += ' 有効購読 ' + summary.disabled_subscriptions + ' 件を無効化しています。';
        }
        setPushSecretOutput(data.label || 'VAPID 退避用 bundle', data.value || '', data.filename || '', note);
        showToast(summary.rotated ? 'VAPID 鍵を再生成して適用しました' : 'VAPID 鍵を生成して適用しました');
        loadPushStatus({ keepSecretOutput: true });
      })
      .catch(function(err) {
        showToast('VAPID 鍵の適用に失敗しました: ' + err.message);
      })
      .then(function() {
        if (btn) btn.disabled = false;
      });
  }

  function revealPushSecret(action) {
    var buttonIdMap = {
      get_public_key: 'btn-push-public-view',
      get_private_pem: 'btn-push-private-view',
      get_backup_bundle: 'btn-push-backup-view'
    };
    var btn = document.getElementById(buttonIdMap[action] || '');
    if (btn) btn.disabled = true;

    PoslaApi.runPushVapidAction(action)
      .then(function(data) {
        var note = '';
        if (action === 'get_private_pem') {
          note = '秘密鍵です。画面共有・チャット貼り付け・第三者転送は避けてください。';
        } else if (action === 'get_backup_bundle') {
          note = '公開鍵と秘密鍵を 1 つの退避テキストにまとめています。安全な保管先に退避してください。';
        } else {
          note = '公開鍵です。PWA 登録や service worker 設定の確認に使えます。';
        }
        setPushSecretOutput(data.label || 'VAPID 鍵', data.value || '', data.filename || '', note);
        showToast((data.label || 'VAPID 鍵') + ' を取得しました');
      })
      .catch(function(err) {
        showToast('鍵の取得に失敗しました: ' + err.message);
      })
      .then(function() {
        if (btn) btn.disabled = false;
      });
  }

  function setPushSecretOutput(label, value, filename, note) {
    var wrapEl = document.getElementById('posla-push-secret-output');
    var labelEl = document.getElementById('posla-push-secret-label');
    var valueEl = document.getElementById('posla-push-secret-value');
    var noteEl = document.getElementById('posla-push-secret-note');
    var copyBtn = document.getElementById('btn-push-secret-copy');
    var downloadBtn = document.getElementById('btn-push-secret-download');
    if (!wrapEl || !labelEl || !valueEl || !noteEl) return;

    _pushSecretState = {
      value: value || '',
      filename: filename || '',
      label: label || '',
      note: note || ''
    };

    if (!_pushSecretState.value) {
      wrapEl.style.display = 'none';
      valueEl.value = '';
      noteEl.textContent = '';
      labelEl.textContent = 'VAPID 鍵';
      if (copyBtn) copyBtn.disabled = true;
      if (downloadBtn) downloadBtn.disabled = true;
      return;
    }

    wrapEl.style.display = 'block';
    labelEl.textContent = _pushSecretState.label;
    valueEl.value = _pushSecretState.value;
    noteEl.textContent = _pushSecretState.note;
    if (copyBtn) copyBtn.disabled = false;
    if (downloadBtn) downloadBtn.disabled = false;
  }

  function copyPushSecretValue() {
    if (!_pushSecretState.value) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(_pushSecretState.value)
        .then(function() {
          showToast((_pushSecretState.label || 'VAPID 鍵') + ' をコピーしました');
        })
        .catch(function() {
          fallbackCopyPushSecret();
        });
      return;
    }
    fallbackCopyPushSecret();
  }

  function fallbackCopyPushSecret() {
    var valueEl = document.getElementById('posla-push-secret-value');
    if (!valueEl) return;
    valueEl.focus();
    valueEl.select();
    try {
      document.execCommand('copy');
      showToast((_pushSecretState.label || 'VAPID 鍵') + ' をコピーしました');
    } catch (e) {
      showToast('コピーに失敗しました');
    }
  }

  function downloadPushSecretValue() {
    if (!_pushSecretState.value) return;
    var blob = new Blob([_pushSecretState.value], { type: 'text/plain;charset=utf-8' });
    var url = window.URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = _pushSecretState.filename || 'posla-vapid.txt';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
    showToast((_pushSecretState.label || 'VAPID 鍵') + ' をダウンロードしました');
  }

  function renderPushStatus(data, statusEl, subsEl, logEl) {
    var v = data.vapid || {};
    var generateBtn = document.getElementById('btn-push-generate-apply');
    var availableMark = v.available
      ? '<span style="color:#2e7d32;font-weight:600;">✓ 設定済み</span>'
      : '<span style="color:#c62828;font-weight:600;">✗ 未設定</span>';
    var pubSnippet = v.public_key
      ? (v.public_key.substring(0, 16) + '...' + v.public_key.substring(v.public_key.length - 8))
      : '(未設定)';
    if (generateBtn) {
      generateBtn.textContent = v.available ? 'VAPID 鍵を再生成して適用' : 'VAPID 鍵を生成して適用';
    }
    statusEl.innerHTML =
        '<div class="metric-list">' +
        buildMetricRow('VAPID 状態', availableMark, 'ブラウザ購読で使われる公開鍵セットの状態です。') +
        buildMetricRow('公開鍵', v.public_key ? '<code class="metric-code">' + escapeHtml(pubSnippet) + '</code>' : '<span style="color:#c62828;">未設定</span>', String(v.public_key_length || 0) + ' 文字') +
        buildMetricRow('秘密鍵', v.private_pem_set ? '設定済み' : '<span style="color:#c62828;">未設定</span>', v.private_pem_set ? (String(v.private_pem_length || 0) + ' 文字') : '未設定の場合は通知署名ができません') +
        '</div>';

    var s = data.subscriptions || { total: 0, enabled: 0, tenants_with_subs: 0, by_role: {} };
    var roles = ['owner', 'manager', 'staff', 'device'];
    var roleTags = [];
    for (var i = 0; i < roles.length; i++) {
      var cnt = s.by_role && s.by_role[roles[i]] ? s.by_role[roles[i]] : 0;
      roleTags.push('<span class="tag' + (cnt ? '' : ' tag--muted') + '">' + escapeHtml(roles[i]) + ': ' + cnt + '</span>');
    }
    subsEl.innerHTML =
        '<div class="metric-list">' +
        buildMetricRow('全購読数', escapeHtml(String(s.total)), '現在保存されているブラウザ購読の総数です。') +
        buildMetricRow('有効購読', escapeHtml(String(s.enabled)), '実際に通知送信対象として使われる件数です。') +
        buildMetricRow('有効 tenant 数', escapeHtml(String(s.tenants_with_subs)), '少なくとも 1 件の有効購読が存在する tenant 数です。') +
        '</div>' +
        '<div class="metric-list__meta" style="margin-top:0.9rem;">role 別 (有効)</div>' +
        '<div class="tag-row">' + roleTags.join('') + '</div>';

    var r = data.recent_24h || { total: 0, sent_ok: 0, gone_disabled: 0, transient: 0, other_error: 0, by_type: {} };
    var byTypeTags = [];
    if (r.by_type) {
      for (var k in r.by_type) {
        if (Object.prototype.hasOwnProperty.call(r.by_type, k)) {
          byTypeTags.push('<span class="tag">' + escapeHtml(k) + ': ' + r.by_type[k] + '</span>');
        }
      }
    }
    logEl.innerHTML =
        '<div class="metric-list">' +
        buildMetricRow('総送信', escapeHtml(String(r.total)), '直近 24 時間で送信を試みた件数です。') +
        buildMetricRow('成功 (2xx)', '<span style="color:#2e7d32;">' + escapeHtml(String(r.sent_ok)) + '</span>', '正常に配信完了した通知') +
        buildMetricRow('失効 (410/404)', escapeHtml(String(r.gone_disabled)), '端末削除などで自動無効化された購読') +
        buildMetricRow('一時失敗 (429/5xx)', escapeHtml(String(r.transient)), '再試行検討が必要な一時エラー') +
        buildMetricRow('その他エラー', escapeHtml(String(r.other_error)), '401 / 403 / 413 など設定起因の失敗') +
        '</div>' +
        '<div class="metric-list__meta" style="margin-top:0.9rem;">type 別</div>' +
        (byTypeTags.length ? ('<div class="tag-row">' + byTypeTags.join('') + '</div>') : '<div class="tag-row"><span class="tag tag--muted">送信なし</span></div>');
  }

  function buildMetricRow(label, valueHtml, metaText) {
    return '<div class="metric-list__row">' +
      '<div>' +
      '<div class="metric-list__label">' + escapeHtml(label) + '</div>' +
      (metaText ? ('<div class="metric-list__meta">' + escapeHtml(metaText) + '</div>') : '') +
      '</div>' +
      '<div class="metric-list__value">' + valueHtml + '</div>' +
      '</div>';
  }

  function buildTenantContractHtml(tenant) {
    var parts = [
      '<span class="badge badge--standard">POSLA標準</span>'
    ];

    if (parseInt(tenant && tenant.hq_menu_broadcast, 10)) {
      parts.push('<span class="badge badge--active">本部一括配信</span>');
    }

    return '<div class="tenant-contract">' + parts.join('') + '</div>';
  }

  function getHealthTone(status) {
    if (status === 'ok') return 'ok';
    if (status === 'warn') return 'warn';
    return 'danger';
  }

  function getProgressTone(progress) {
    progress = parseInt(progress, 10) || 0;
    if (progress >= 100) return 'ok';
    if (progress >= 60) return 'warn';
    return 'alert';
  }

  function buildProgressBar(percent, tone) {
    var safePercent = parseInt(percent, 10) || 0;
    if (safePercent < 0) safePercent = 0;
    if (safePercent > 100) safePercent = 100;
    return '<div class="progress-track"><div class="progress-track__bar progress-track__bar--' + tone + '" style="width:' + safePercent + '%;"></div></div>';
  }

  function buildTenantHealthHtml(tenant) {
    var tone = getHealthTone(tenant.health_status);
    var flags = tenant.health_flags || [];
    var cellMeta = buildTenantCellMetaText(tenant);
    return '<div class="tenant-health">' +
      '<div class="tenant-health__top">' +
        '<span class="status-pill status-pill--' + tone + '">' + escapeHtml(tenant.health_label || '要確認') + '</span>' +
        '<span class="tenant-health__score">' + escapeHtml(String(tenant.health_score || 0)) + ' / 100</span>' +
      '</div>' +
      buildProgressBar(tenant.health_score || 0, tone === 'danger' ? 'alert' : tone) +
      '<div class="tenant-health__flags">' + escapeHtml(flags.join(' / ')) + '</div>' +
      (cellMeta ? '<div class="tenant-health__flags">' + escapeHtml(cellMeta) + '</div>' : '') +
      '</div>';
  }

  function buildTenantCellMetaText(tenant) {
    var parts = [];
    if (!tenant || !tenant.cell_id) {
      return '';
    }

    parts.push('cell: ' + tenant.cell_id);
    if (tenant.cell_snapshot_status) {
      parts.push('snapshot: ' + tenant.cell_snapshot_status);
    }
    if (tenant.cell_tier0_status) {
      parts.push('tier0: ' + tenant.cell_tier0_status);
    }

    return parts.join(' / ');
  }

  function buildTenantProgressHtml(tenant) {
    var tone = getProgressTone(tenant.onboarding_progress);
    var completed = tenant.onboarding_completed_steps || 0;
    var total = tenant.onboarding_total_steps || 0;
    return '<div class="tenant-progress">' +
      '<div class="tenant-progress__top">' +
        '<span class="status-pill status-pill--' + (tone === 'alert' ? 'danger' : tone) + '">' + escapeHtml(String(tenant.onboarding_progress || 0)) + '%</span>' +
        '<span class="tenant-progress__score">' + escapeHtml(String(completed)) + ' / ' + escapeHtml(String(total)) + ' 完了</span>' +
      '</div>' +
      buildProgressBar(tenant.onboarding_progress || 0, tone) +
      '<div class="tenant-progress__meta">次アクション: ' + escapeHtml(tenant.next_action || '確認') + '</div>' +
      '</div>';
  }

  function buildTenantIncidentHtml(tenant) {
    var tone = 'muted';
    var meta = '直近異常なし';
    if (parseInt(tenant.critical_open_count, 10) > 0 || parseInt(tenant.open_incident_count, 10) > 0) {
      tone = 'danger';
      meta = (tenant.last_incident_at ? tenant.last_incident_at.replace('T', ' ').substring(0, 16) : tenant.last_incident_at || '未解決あり');
    } else if (parseInt(tenant.incident_count_24h, 10) > 0) {
      tone = 'warn';
      meta = '24h 内に記録あり';
    }
    return '<div class="tenant-incident">' +
      '<span class="status-pill status-pill--' + tone + '">' + escapeHtml(tenant.recent_incident_label || '異常なし') + '</span>' +
      '<div class="tenant-incident__meta">' + escapeHtml(meta) + '</div>' +
      '</div>';
  }

  function formatTenantTimelineDate(value) {
    if (!value) return '-';
    return String(value).replace('T', ' ').substring(0, 16);
  }

  function buildTenantModalSummaryHtml(tenant) {
    var healthTone = getHealthTone(tenant.health_status);
    var progressTone = getProgressTone(tenant.onboarding_progress);
    var incidentTone = 'muted';
    var incidentValue = tenant.recent_incident_label || '異常なし';
    var incidentMeta = tenant.last_incident_at ? ('最終記録 ' + formatTenantTimelineDate(tenant.last_incident_at)) : '直近 14 日に重大イベントなし';

    if (parseInt(tenant.critical_open_count, 10) > 0 || parseInt(tenant.open_incident_count, 10) > 0) {
      incidentTone = 'danger';
    } else if (parseInt(tenant.incident_count_24h, 10) > 0) {
      incidentTone = 'warn';
    }

    return '' +
      '<div class="tenant-modal-summary__card">' +
        '<div class="tenant-modal-summary__label">健全性</div>' +
        '<div class="tenant-modal-summary__value"><span class="status-pill status-pill--' + (healthTone === 'danger' ? 'danger' : healthTone) + '">' + escapeHtml(tenant.health_label || '要確認') + '</span> ' + escapeHtml(String(tenant.health_score || 0)) + ' / 100</div>' +
        '<div class="tenant-modal-summary__meta">' + escapeHtml((tenant.health_flags || []).join(' / ') || '安定稼働中') + '</div>' +
      '</div>' +
      '<div class="tenant-modal-summary__card">' +
        '<div class="tenant-modal-summary__label">導入状況</div>' +
        '<div class="tenant-modal-summary__value"><span class="status-pill status-pill--' + (progressTone === 'alert' ? 'danger' : progressTone) + '">' + escapeHtml(String(tenant.onboarding_progress || 0)) + '%</span></div>' +
        '<div class="tenant-modal-summary__meta">完了 ' + escapeHtml(String(tenant.onboarding_completed_steps || 0)) + ' / ' + escapeHtml(String(tenant.onboarding_total_steps || 0)) + ' ・ 次: ' + escapeHtml(tenant.next_action || '確認') + '</div>' +
      '</div>' +
      '<div class="tenant-modal-summary__card">' +
        '<div class="tenant-modal-summary__label">監視イベント</div>' +
        '<div class="tenant-modal-summary__value"><span class="status-pill status-pill--' + incidentTone + '">' + escapeHtml(incidentValue) + '</span></div>' +
        '<div class="tenant-modal-summary__meta">' + escapeHtml(incidentMeta) + '</div>' +
      '</div>' +
      (tenant.cell_id ? (
        '<div class="tenant-modal-summary__card">' +
          '<div class="tenant-modal-summary__label">Cell</div>' +
          '<div class="tenant-modal-summary__value"><code>' + escapeHtml(tenant.cell_id || '-') + '</code></div>' +
          '<div class="tenant-modal-summary__meta">' + escapeHtml(buildTenantCellMetaText(tenant) || 'cell registry 未連携') + '</div>' +
        '</div>'
      ) : '');
  }

  function buildTenantInvestigationSummaryHtml(view) {
    var summary = view || {};
    var cards = summary.cards || [];
    var html = '';
    var i;
    var card;
    var tone;

    if (summary.headline) {
      html += '<div class="tenant-investigation-banner">' + escapeHtml(summary.headline) + '</div>';
    }

    if (!cards.length) {
      return html + '<div class="tenant-modal-timeline__empty">調査材料がまだありません。監視イベントまたは設定変更が入るとここに要約が表示されます。</div>';
    }

    html += '<div class="tenant-modal-summary">';
    for (i = 0; i < cards.length; i++) {
      card = cards[i] || {};
      tone = card.tone || 'muted';
      html += '<div class="tenant-modal-summary__card">' +
        '<div class="tenant-modal-summary__label">' + escapeHtml(card.label || '要約') + '</div>' +
        '<div class="tenant-modal-summary__value">' + _buildStatusPill(card.pill_label || '-', tone === 'danger' ? 'danger' : tone) + ' ' + escapeHtml(card.value || '-') + '</div>' +
        '<div class="tenant-modal-summary__meta">' + escapeHtml(card.meta || '-') + '</div>' +
      '</div>';
    }
    html += '</div>';

    return html;
  }

  function buildTenantInvestigationChangesHtml(view) {
    var items = (view && view.related_changes) || [];
    var html = '';
    var i;
    var item;
    var tone;
    var meta = [];
    var actorText;

    if (!items.length) {
      return '<div class="tenant-modal-timeline__empty">関連しそうな POSLA 側変更はありません。店舗設定や外部連携先を優先して確認してください。</div>';
    }

    for (i = 0; i < items.length; i++) {
      item = items[i] || {};
      tone = item.tone || 'muted';
      meta = ['変更: ' + formatTenantTimelineDate(item.created_at)];
      if (item.status_label) {
        meta.push('区分: ' + item.status_label);
      }
      if (item.relation_label) {
        meta.push(item.relation_label);
      }
      actorText = item.actor_label ? ('変更者: ' + item.actor_label) : '';

      html += '<div class="tenant-timeline-item tenant-timeline-item--' + escapeHtml(tone) + '">' +
        '<div class="tenant-timeline-item__head">' +
          '<div class="tenant-timeline-item__title">' + escapeHtml(item.title || '設定変更') + '</div>' +
          '<div class="tenant-timeline-item__badges">' +
            '<span class="status-pill status-pill--' + (tone === 'danger' ? 'danger' : tone) + '">' + escapeHtml(item.status_label || '変更') + '</span>' +
            (item.relation_label ? ('<span class="status-pill status-pill--info">' + escapeHtml(item.relation_label) + '</span>') : '') +
          '</div>' +
        '</div>' +
        '<div class="tenant-timeline-item__meta">' + escapeHtml(meta.join(' / ')) + '</div>' +
        '<div class="tenant-timeline-item__detail">' + escapeHtml(item.detail || '詳細メッセージはありません。') + '</div>' +
        (actorText ? ('<div class="tenant-timeline-item__subnote">' + escapeHtml(actorText) + '</div>') : '') +
      '</div>';
    }

    return html;
  }

  function buildTenantTimelineHtml(items) {
    var html = '';
    var i;
    var item;
    var tone;
    var stateLabel;
    var sourceLabel;
    var meta = [];
    var detail;
    var actionsHtml;

    if (!items || !items.length) {
      return '<div class="tenant-modal-timeline__empty">直近 14 日の監視イベントはありません。未解決アラートも無く、いまは安定稼働です。</div>';
    }

    for (i = 0; i < items.length; i++) {
      item = items[i];
      tone = item.severity || 'info';
      if (parseInt(item.resolved, 10)) {
        tone = 'resolved';
      }
      stateLabel = parseInt(item.resolved, 10) ? '解消済み' : '未解決';
      sourceLabel = item.source || item.event_type || 'monitor';
      meta = [
        '発生: ' + formatTenantTimelineDate(item.created_at),
        'ソース: ' + sourceLabel
      ];
      if (item.store_name) {
        meta.push('店舗: ' + item.store_name);
      }
      if (parseInt(item.resolved, 10) && item.resolved_at) {
        meta.push('解消: ' + formatTenantTimelineDate(item.resolved_at));
      }
      detail = item.detail ? escapeHtml(item.detail) : '詳細メッセージは記録されていません。';
      actionsHtml = '';
      if (!parseInt(item.resolved, 10) && item.id) {
        actionsHtml = '<div class="tenant-timeline-item__actions">' +
          '<button class="btn btn-secondary btn-sm" type="button" data-monitor-resolve-id="' + escapeHtml(item.id) + '">解消済みにする</button>' +
        '</div>';
      }

      html += '<div class="tenant-timeline-item tenant-timeline-item--' + escapeHtml(tone) + '">' +
        '<div class="tenant-timeline-item__head">' +
          '<div class="tenant-timeline-item__title">' + escapeHtml(item.title || '監視イベント') + '</div>' +
          '<div class="tenant-timeline-item__badges">' +
            '<span class="status-pill status-pill--' + (item.severity === 'critical' || item.severity === 'error' ? 'danger' : (item.severity || 'info')) + '">' + escapeHtml(item.severity || 'info') + '</span>' +
            '<span class="status-pill status-pill--' + (parseInt(item.resolved, 10) ? 'muted' : 'warn') + '">' + escapeHtml(stateLabel) + '</span>' +
          '</div>' +
        '</div>' +
        '<div class="tenant-timeline-item__meta">' + escapeHtml(meta.join(' / ')) + '</div>' +
        '<div class="tenant-timeline-item__detail">' + detail + '</div>' +
        actionsHtml +
      '</div>';
    }

    return html;
  }

  function handleResolveMonitorEvent(btn) {
    var id = btn.getAttribute('data-monitor-resolve-id') || '';
    var tenantIdEl = document.getElementById('modal-tenant-id');
    var tenantId = tenantIdEl ? tenantIdEl.value : '';

    if (!id) return;
    if (!window.confirm('この監視イベントを解消済みにします。原因確認後の復旧操作として続けますか？')) {
      return;
    }

    btn.disabled = true;
    PoslaApi.resolveMonitorEvent(id).then(function() {
      showToast('監視イベントを解消済みにしました');
      if (tenantId) {
        openTenantModal(tenantId);
      }
      loadDashboard();
    }).catch(function(err) {
      showToast('監視イベントの更新に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

  function buildTenantOpsTimelineHtml(items) {
    var html = '';
    var i;
    var item;
    var tone;
    var meta = [];
    var badges;
    var actorText;

    if (!items || !items.length) {
      return '<div class="tenant-modal-timeline__empty">まだ運用タイムラインはありません。POSLA 側の変更や監視イベントが発生するとここにまとまります。</div>';
    }

    for (i = 0; i < items.length; i++) {
      item = items[i];
      tone = item.tone || 'info';
      meta = ['発生: ' + formatTenantTimelineDate(item.created_at)];
      if (item.meta_label) {
        meta.push('ソース: ' + item.meta_label);
      }
      if (item.store_name) {
        meta.push('店舗: ' + item.store_name);
      }
      if (parseInt(item.resolved, 10) && item.resolved_at) {
        meta.push('解消: ' + formatTenantTimelineDate(item.resolved_at));
      }
      badges = '<div class="tenant-timeline-item__badges">' +
        '<span class="status-pill status-pill--' + (tone === 'resolved' ? 'muted' : (tone === 'critical' || tone === 'error' ? 'danger' : tone)) + '">' + escapeHtml(item.status_label || '記録') + '</span>' +
        '<span class="status-pill status-pill--info">' + escapeHtml(item.timeline_type || 'ops') + '</span>' +
        '</div>';
      actorText = item.actor_label ? ('操作者: ' + item.actor_label) : '';

      html += '<div class="tenant-timeline-item tenant-timeline-item--' + escapeHtml(tone) + '">' +
        '<div class="tenant-timeline-item__head">' +
          '<div class="tenant-timeline-item__title">' + escapeHtml(item.title || '運用イベント') + '</div>' +
          badges +
        '</div>' +
        '<div class="tenant-timeline-item__meta">' + escapeHtml(meta.join(' / ')) + '</div>' +
        '<div class="tenant-timeline-item__detail">' + escapeHtml(item.detail || '詳細メッセージはありません。') + '</div>' +
        (actorText ? ('<div class="tenant-timeline-item__subnote">' + escapeHtml(actorText) + '</div>') : '') +
      '</div>';
    }

    return html;
  }

  function buildWatchListHtml(items, emptyMessage, mode) {
    var html = '<div class="watch-list">';
    var i;
    var item;
    var tone;
    var flags;
    if (!items || !items.length) {
      html += '<div class="watch-item"><div class="watch-item__title">' + escapeHtml(emptyMessage) + '</div></div>';
      html += '</div>';
      return html;
    }

    for (i = 0; i < items.length; i++) {
      item = items[i];
      tone = mode === 'risk' ? getHealthTone(item.health_status) : getProgressTone(item.onboarding_progress);
      flags = item.health_flags || [];
      html += '<div class="watch-item watch-item--' + (tone === 'danger' ? 'alert' : tone) + '">' +
        '<div class="watch-item__head">' +
          '<div>' +
            '<div class="watch-item__title">' + escapeHtml(item.name || '-') + '</div>' +
            '<div class="watch-item__slug">' + escapeHtml(item.slug || '-') + '</div>' +
          '</div>' +
          '<span class="status-pill status-pill--' + (tone === 'danger' ? 'danger' : tone) + '">' +
            (mode === 'risk'
              ? (escapeHtml(item.health_label || '要確認') + ' ' + escapeHtml(String(item.health_score || 0)))
              : (escapeHtml(String(item.onboarding_progress || 0)) + '%')) +
          '</span>' +
        '</div>' +
        '<div class="watch-item__meta">';
      if (mode === 'risk') {
        html += '<div>直近異常: ' + escapeHtml(item.recent_incident_label || '異常なし') + '</div>' +
          '<div>次アクション: ' + escapeHtml(item.next_action || '確認') + '</div>';
      } else {
        html += '<div>完了ステップ: ' + escapeHtml(String(item.onboarding_completed_steps || 0)) + ' / ' + escapeHtml(String(item.onboarding_total_steps || 0)) + '</div>' +
          '<div>次アクション: ' + escapeHtml(item.next_action || '確認') + '</div>';
      }
      html += '</div>';
      if (flags.length) {
        html += '<div class="watch-item__tags">';
        for (var j = 0; j < flags.length; j++) {
          html += '<span class="tag">' + escapeHtml(flags[j]) + '</span>';
        }
        html += '</div>';
      }
      if (item.id) {
        html += '<div class="tenant-timeline-item__actions">' +
          '<button class="btn btn-secondary btn-sm" type="button" data-open-tenant="' + escapeHtml(item.id) + '">詳細を開く</button>' +
        '</div>';
      }
      html += '</div>';
    }

    html += '</div>';
    return html;
  }

  function renderOpMonitoringDelegation(monitoring) {
    var root = document.getElementById('release-readiness');
    if (!root) return;

    monitoring = monitoring || {};
    root.innerHTML = '<section class="release-readiness">' +
      '<div class="release-readiness__head">' +
        '<div>' +
          '<div class="release-readiness__title">' + escapeHtml(monitoring.label || '監視はOPで確認') + '</div>' +
          '<div class="release-readiness__meta">' + escapeHtml(monitoring.detail || 'ping / snapshot / Tier0 / Alert はOPを正とします。') + '</div>' +
        '</div>' +
        _buildStatusPill('OP -> POSLA', 'ok') +
      '</div>' +
      '<div class="release-readiness__checks">' +
        '<div class="release-readiness-check release-readiness-check--ok">' +
          '<div class="release-readiness-check__label">監視主体</div>' +
          '<div class="release-readiness-check__value">OP</div>' +
          '<div class="release-readiness-check__detail">OPがPOSLAの ping / snapshot を取得し、監視状態と証跡を保持します。</div>' +
        '</div>' +
        '<div class="release-readiness-check release-readiness-check--ok">' +
          '<div class="release-readiness-check__label">POSLA</div>' +
          '<div class="release-readiness-check__value">受け口のみ</div>' +
          '<div class="release-readiness-check__detail">POSLA管理画面では監視結果を再判定しません。OP画面URLと障害報告連携だけを管理します。</div>' +
        '</div>' +
        '<div class="release-readiness-check release-readiness-check--ok">' +
          '<div class="release-readiness-check__label">確認場所</div>' +
          '<div class="release-readiness-check__value">OP監視状況</div>' +
          '<div class="release-readiness-check__detail">OPの監視画面 / Release readiness / Alert で現在状態を確認します。</div>' +
          '<div class="release-readiness-check__actions">' +
            '<a class="btn btn-primary btn-sm" data-op-link href="http://127.0.0.1:8091/" target="_blank" rel="noopener">OPを開く</a>' +
            '<button class="btn btn-secondary btn-sm" type="button" data-release-action="open-ops-source">OP連携</button>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</section>';
    updateOpLinks(monitoring.op_public_url || '');
  }

  function handleReleaseReadinessAction(btn) {
    var action = btn.getAttribute('data-release-action') || '';
    if (action === 'open-ops-source') {
      activateTab('ops-source');
    }
  }

  function renderTenantCreateResult(data) {
    var container = document.getElementById('tenant-create-result');
    var bootstrap = data && data.bootstrap ? data.bootstrap : {};
    var accounts = bootstrap.accounts || [];
    var rows = '';
    var i;

    if (!container) return;

    for (i = 0; i < accounts.length; i++) {
      rows += '<tr>' +
        '<td>' + escapeHtml(accounts[i].role) + '</td>' +
        '<td><code>' + escapeHtml(accounts[i].username) + '</code></td>' +
        '<td>' + escapeHtml(accounts[i].display_name || '') + '</td>' +
        '</tr>';
    }

    container.innerHTML =
      '<div class="tenant-create-result__head">' +
        '<div>' +
          '<div class="tenant-create-result__eyebrow">Bootstrap Complete</div>' +
          '<div class="tenant-create-result__title">初期ログイン情報を発行しました</div>' +
        '</div>' +
        '<span class="status-pill status-pill--ok">作成済み</span>' +
      '</div>' +
      '<div class="metric-list">' +
        buildMetricRow('テナント', escapeHtml((data.tenant && data.tenant.name) || '-'), (data.tenant && data.tenant.slug) ? ('slug: ' + data.tenant.slug) : '') +
        buildMetricRow('契約', buildTenantContractHtml(data.tenant || {}), '') +
        buildMetricRow('初期店舗', escapeHtml((bootstrap.store && bootstrap.store.name) || '-'), (bootstrap.store && bootstrap.store.slug) ? ('store slug: ' + bootstrap.store.slug) : '') +
        buildMetricRow('ログイン画面', '<code>' + escapeHtml(bootstrap.login_url || (window.location.origin + '/admin/')) + '</code>', 'owner / manager / staff / device 共通') +
        buildMetricRow('一時パスワード', '<code>' + escapeHtml(bootstrap.common_password || '作成結果で確認') + '</code>', '作成ごとにランダム生成。初回ログイン後に変更') +
      '</div>' +
      '<div class="data-table-wrap" style="margin-top:1rem;"><table class="data-table"><thead><tr><th>ロール</th><th>username</th><th>表示名</th></tr></thead><tbody>' +
        rows +
      '</tbody></table></div>' +
      '<div class="tenant-create-result__note">外部連携やメニュー・テーブル詳細はまだ未設定です。まずは上記 ID でテナント側管理画面に入り、その後の設定を進めてください。</div>';

    container.classList.add('open');
  }

  function clearTenantCreateResult() {
    var container = document.getElementById('tenant-create-result');
    if (!container) return;
    container.classList.remove('open');
    container.innerHTML = '';
  }

  function escapeHtml(s) {
    s = String(s == null ? '' : s);
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // ── (HELPDESK-P2-NONAI-ALL-20260423: AIコード案内関連の内部関数は削除、posla-supportdesk.js に置換) ──

  function buildCellOnboardingStatusBadge(status) {
    var tone = 'inactive';
    if (status === 'ready_for_cell' || status === 'cell_provisioning') tone = 'pro';
    if (status === 'active') tone = 'active';
    if (status === 'failed') tone = 'inactive';
    return '<span class="badge badge--' + tone + '">' + Utils.escapeHtml(status || '-') + '</span>';
  }

  function renderCellOnboardingRequests(data) {
    var root = document.getElementById('cell-onboarding-requests');
    var onboarding = data.cellOnboarding || {};
    var rows = onboarding.pending || [];
    var html;
    var i;
    var row;
    if (!root) return;

    if (!onboarding.available) {
      root.innerHTML = '<div style="padding:1rem;color:#c62828;">onboarding ledger が未作成です。migration-p1-43 を適用してください。</div>';
      return;
    }

    html = '<div class="data-table-wrap"><table class="data-table"><thead><tr>' +
      '<th>顧客名</th><th>Slug</th><th>経路</th><th>Status</th><th>店舗</th><th>Cell</th><th>更新</th>' +
      '</tr></thead><tbody>';

    if (!rows.length) {
      html += '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);">Cell作成待ちはありません</td></tr>';
    } else {
      for (i = 0; i < rows.length; i++) {
        row = rows[i];
        html += '<tr>' +
          '<td>' + Utils.escapeHtml(row.tenant_name || '-') + '</td>' +
          '<td><code>' + Utils.escapeHtml(row.tenant_slug || '-') + '</code></td>' +
          '<td>' + Utils.escapeHtml(row.request_source || '-') + '</td>' +
          '<td>' + buildCellOnboardingStatusBadge(row.status || '-') + '</td>' +
          '<td>' + Utils.escapeHtml(row.store_name || '-') + '</td>' +
          '<td>' + Utils.escapeHtml(row.cell_id || '未割当') + '</td>' +
          '<td>' + Utils.escapeHtml(row.updated_at || '-') + '</td>' +
          '</tr>';
      }
    }

    html += '</tbody></table></div>';
    root.innerHTML = html;
  }

  // ── Release Plan ──
  var _releasePlanState = {
    cells: [],
    plan: null
  };

  function loadReleasePlan() {
    var statusEl = document.getElementById('release-plan-status');
    if (!statusEl || !PoslaApi || !PoslaApi.getReleasePlan) return;
    statusEl.innerHTML = '<div style="padding:1rem;color:var(--text-muted);">読み込み中...</div>';

    PoslaApi.getReleasePlan().then(function(data) {
      _releasePlanState.cells = data.cells || [];
      _releasePlanState.plan = data.plan || null;
      renderReleasePlan(data);
    }).catch(function(err) {
      statusEl.innerHTML = '<div style="padding:1rem;color:#c62828;">Release Plan の読み込みに失敗しました: ' + Utils.escapeHtml(err.message) + '</div>';
      showToast('Release Plan の読み込みに失敗しました: ' + err.message);
    });
  }

  function releasePlanStatusTone(status) {
    if (status === 'ready') return 'ok';
    if (status === 'warning') return 'fail';
    return 'warn';
  }

  function releasePlanStatusLabel(status) {
    if (status === 'ready') return 'Actions許可';
    if (status === 'manual') return '手動確認';
    if (status === 'warning') return '要確認';
    return '未設定';
  }

  function renderReleasePlan(data) {
    var statusEl = document.getElementById('release-plan-status');
    var scopeSelect = document.getElementById('release-plan-target-scope');
    var cellSelect = document.getElementById('release-plan-target-cell');
    var automationSelect = document.getElementById('release-plan-automation-mode');
    var noteInput = document.getElementById('release-plan-note');
    var cells = data.cells || [];
    var plan = data.plan || {};
    var targetScope = plan.target_scope === 'all_active_cells' ? 'all_active_cells' : 'single_cell';
    var selectedCellId = plan.target_cell_id || plan.recommended_cell_id || '';
    var optionHtml = '';
    var i;
    var cell;
    var meta;

    if (scopeSelect) {
      scopeSelect.value = targetScope;
    }
    if (cellSelect) {
      if (!cells.length) {
        optionHtml = '<option value="">cellがありません</option>';
      } else {
        for (i = 0; i < cells.length; i++) {
          cell = cells[i];
          meta = [];
          if (cell.status) meta.push(cell.status);
          if (cell.environment) meta.push(cell.environment);
          optionHtml += '<option value="' + Utils.escapeHtml(cell.cell_id || '') + '">' +
            Utils.escapeHtml(cell.label || cell.cell_id || '-') +
            (meta.length ? (' (' + Utils.escapeHtml(meta.join(' / ')) + ')') : '') +
            '</option>';
        }
      }
      cellSelect.innerHTML = optionHtml;
      if (selectedCellId) cellSelect.value = selectedCellId;
    }
    if (automationSelect) {
      automationSelect.value = plan.automation_mode || 'manual_only';
    }
    if (noteInput) {
      noteInput.value = plan.note || '';
    }
    updateReleasePlanTargetScopeUi();
    if (statusEl) {
      statusEl.innerHTML = buildReleasePlanStatusHtml(plan, data.cells_available);
    }
  }

  function releasePlanTargetScopeValue() {
    var scopeSelect = document.getElementById('release-plan-target-scope');
    return scopeSelect && scopeSelect.value === 'all_active_cells' ? 'all_active_cells' : 'single_cell';
  }

  function updateReleasePlanTargetScopeUi() {
    var scope = releasePlanTargetScopeValue();
    var targetCellGroup = document.getElementById('release-plan-target-cell-group');
    var cellSelect = document.getElementById('release-plan-target-cell');

    if (targetCellGroup) {
      targetCellGroup.style.display = scope === 'all_active_cells' ? 'none' : '';
    }
    if (cellSelect) {
      cellSelect.disabled = scope === 'all_active_cells';
    }
  }

  function buildReleasePlanStatusHtml(plan, cellsAvailable) {
    plan = plan || {};
    var tone = releasePlanStatusTone(plan.status || 'missing');
    var targetScope = plan.target_scope === 'all_active_cells' ? 'all_active_cells' : 'single_cell';
    var target = targetScope === 'all_active_cells'
      ? ('全active cell' + (plan.target_count ? ' (' + plan.target_count + '件)' : ''))
      : (plan.target_cell_id || '未設定');
    var updated = plan.updated_at ? ('最終更新: ' + plan.updated_at) : '未保存';
    var actionsText = plan.actions_can_run ? 'この計画だけ対象にできます' : '自動実行しません';
    var targetMeta = plan.target_cell && plan.target_cell.app_base_url ? ('app: ' + plan.target_cell.app_base_url) : '';
    var registryText = cellsAvailable ? 'cell registryから選択します' : 'cell registry未検出のため推奨値を表示しています';
    var targetCellsText = '';
    var targetCells = Array.isArray(plan.target_cells) ? plan.target_cells : [];
    var targetCellIds = [];
    var i;

    if (targetScope === 'all_active_cells' && targetCells.length) {
      for (i = 0; i < targetCells.length && i < 5; i++) {
        targetCellIds.push(targetCells[i].cell_id || '-');
      }
      targetCellsText = '対象cell: ' + targetCellIds.join(', ');
      if (targetCells.length > 5) {
        targetCellsText += ' ほか' + (targetCells.length - 5) + '件';
      }
    }

    return '<div class="release-readiness-check release-readiness-check--' + tone + '">' +
      '<div class="release-readiness-check__label">次回デプロイ計画</div>' +
      '<div class="release-readiness-check__value">' + Utils.escapeHtml(releasePlanStatusLabel(plan.status || 'missing')) + '</div>' +
      '<div class="release-readiness-check__detail">' +
        '対象範囲: <code>' + Utils.escapeHtml(target) + '</code><br>' +
        (targetCellsText ? (Utils.escapeHtml(targetCellsText) + '<br>') : '') +
        'Actions: ' + Utils.escapeHtml(actionsText) + '<br>' +
        Utils.escapeHtml(plan.summary || '') +
        (targetMeta ? ('<br>' + Utils.escapeHtml(targetMeta)) : '') +
        '<br>' + Utils.escapeHtml(registryText) +
        '<br>' + Utils.escapeHtml(updated) +
      '</div>' +
    '</div>';
  }

  function saveReleasePlan() {
    var btn = document.getElementById('btn-save-release-plan');
    var cellSelect = document.getElementById('release-plan-target-cell');
    var automationSelect = document.getElementById('release-plan-automation-mode');
    var noteInput = document.getElementById('release-plan-note');
    var payload = {
      target_scope: releasePlanTargetScopeValue(),
      target_cell_id: cellSelect ? cellSelect.value : '',
      automation_mode: automationSelect ? automationSelect.value : 'manual_only',
      note: noteInput ? noteInput.value.trim() : ''
    };

    if (payload.target_scope === 'single_cell' && !payload.target_cell_id) {
      showToast('デプロイ対象cellを選択してください');
      return;
    }
    if (payload.automation_mode === 'actions_allowed' && !payload.note) {
      showToast('Actionsに許可する場合は変更理由を入力してください');
      if (noteInput) noteInput.focus();
      return;
    }

    if (btn) btn.disabled = true;
    PoslaApi.updateReleasePlan(payload).then(function(data) {
      _releasePlanState.cells = data.cells || [];
      _releasePlanState.plan = data.plan || null;
      renderReleasePlan(data);
      showToast('Release Plan を保存しました');
    }).catch(function(err) {
      showToast('Release Plan の保存に失敗しました: ' + err.message);
    }).then(function() {
      if (btn) btn.disabled = false;
    });
  }

  // ── Cell Provisioning ──
  var _cellProvisioningState = {
    items: []
  };

  function loadCellProvisioning() {
    var summaryEl = document.getElementById('cell-provisioning-summary');
    var listEl = document.getElementById('cell-provisioning-list');
    if (summaryEl) summaryEl.innerHTML = '<div style="padding:1rem;color:var(--text-muted);">読み込み中...</div>';
    if (listEl) listEl.innerHTML = '';

    PoslaApi.getCellProvisioning().then(function(data) {
      _cellProvisioningState.items = data.items || [];
      renderCellProvisioning(data);
    }).catch(function(err) {
      if (summaryEl) {
        summaryEl.innerHTML = '<div style="padding:1rem;color:#c62828;">Cell配備状況の読み込みに失敗しました: ' + Utils.escapeHtml(err.message) + '</div>';
      }
      showToast('Cell配備状況の読み込みに失敗しました: ' + err.message);
    });
  }

  function renderCellProvisioning(data) {
    var items = data.items || [];
    var summaryEl = document.getElementById('cell-provisioning-summary');
    var listEl = document.getElementById('cell-provisioning-list');
    var counts = {};
    var i;
    var html;
    var item;

    for (i = 0; i < items.length; i++) {
      counts[items[i].status || 'unknown'] = (counts[items[i].status || 'unknown'] || 0) + 1;
    }

    if (summaryEl) {
      summaryEl.innerHTML =
        _buildSummaryCard('QUEUE', 'Cell配備待ち', [
          _summaryLine('ready_for_cell', Utils.escapeHtml(String(counts.ready_for_cell || 0))),
          _summaryLine('cell_provisioning', Utils.escapeHtml(String(counts.cell_provisioning || 0))),
          _summaryLine('failed', Utils.escapeHtml(String(counts.failed || 0)))
        ]) +
        _buildSummaryCard('ACTIVE', '稼働対象', [
          _summaryLine('active', Utils.escapeHtml(String(counts.active || 0))),
          _summaryLine('total', Utils.escapeHtml(String(items.length)))
        ]);
    }

    if (!listEl) return;
    if (!data.available) {
      listEl.innerHTML = '<div style="padding:1rem;color:#c62828;">Cell provisioning API が利用できません。</div>';
      return;
    }
    if (!items.length) {
      listEl.innerHTML = '<div style="padding:1rem;color:var(--text-muted);">Cell配備対象はありません。</div>';
      return;
    }

    html = '<div class="cell-provisioning-stack">';
    for (i = 0; i < items.length; i++) {
      item = items[i];
      html += buildCellProvisioningItemHtml(item, i);
    }
    html += '</div>';
    listEl.innerHTML = html;
  }

  function buildCellRegistryFieldHtml(index, field, label, value, type) {
    return '<div class="cell-registry-field">' +
      '<label for="cell-registry-' + index + '-' + field + '">' + Utils.escapeHtml(label) + '</label>' +
      '<input class="form-input" type="' + Utils.escapeHtml(type || 'text') + '" id="cell-registry-' + index + '-' + field + '" data-cell-field-index="' + index + '" data-cell-field="' + Utils.escapeHtml(field) + '" value="' + Utils.escapeHtml(value || '') + '">' +
    '</div>';
  }

  function buildCellRegistryEditFieldsHtml(item, index, target) {
    var appBaseUrl = item.app_base_url || target.app_base_url || '';
    var healthUrl = item.health_url || target.health_url || (appBaseUrl ? (String(appBaseUrl).replace(/\/+$/, '') + '/api/monitor/ping.php') : '');

    return '<div class="cell-registry-fields">' +
      buildCellRegistryFieldHtml(index, 'environment', 'Environment', item.registry_environment || target.environment || 'pseudo-prod', 'text') +
      buildCellRegistryFieldHtml(index, 'app_base_url', 'App URL', appBaseUrl, 'url') +
      buildCellRegistryFieldHtml(index, 'health_url', 'Health URL', healthUrl, 'url') +
      buildCellRegistryFieldHtml(index, 'db_host', 'DB host', item.db_host || target.db_host || '', 'text') +
      buildCellRegistryFieldHtml(index, 'db_name', 'DB name', item.db_name || target.db_name || '', 'text') +
      buildCellRegistryFieldHtml(index, 'db_user', 'DB user', item.db_user || target.db_user || '', 'text') +
      buildCellRegistryFieldHtml(index, 'uploads_path', 'Uploads path', item.uploads_path || target.uploads_path || '', 'text') +
      buildCellRegistryFieldHtml(index, 'php_image', 'PHP image', item.php_image || target.php_image || '', 'text') +
      buildCellRegistryFieldHtml(index, 'deploy_version', 'Deploy version', item.deploy_version || target.deploy_version || '', 'text') +
      '<div class="cell-registry-field">' +
        '<label>Cron</label>' +
        '<label class="cell-registry-checkbox"><input type="checkbox" data-cell-field-index="' + index + '" data-cell-field="cron_enabled" ' + (parseInt(item.cron_enabled || 0, 10) ? 'checked' : '') + '> 監視対象</label>' +
      '</div>' +
    '</div>';
  }

  function buildCellProvisioningItemHtml(item, index) {
    var registryTone = item.registry_status === 'active' ? 'ok' : (item.registry_status === 'failed' ? 'danger' : 'warn');
    var itemTone = item.status === 'active' ? 'active' : (item.status === 'failed' ? 'failed' : 'queue');
    var target = item.suggested_target || {};
    var commands = item.commands || [];
    var commandHtml = '';
    var actionHtml = '';
    var i;

    for (i = 0; i < commands.length; i++) {
      commandHtml += '<div class="cell-command-block">' +
        '<div class="cell-command-block__head">' +
          '<span class="cell-command-block__label">' + Utils.escapeHtml(commands[i].label || ('step ' + (i + 1))) + '</span>' +
          '<button class="btn btn-secondary btn-sm" type="button" data-cell-command-copy="' + index + ':' + i + '">コピー</button>' +
        '</div>' +
        '<pre><code>' + Utils.escapeHtml(commands[i].command || '') + '</code></pre>' +
      '</div>';
    }
    if (!commands.length) {
      commandHtml = '<div class="settings-test-result" style="margin-top:0.75rem;color:var(--text-muted);">この状態では実行コマンドはありません。</div>';
    } else {
      commandHtml = '<div class="cell-command-list">' + commandHtml + '</div>';
    }

    if (item.status === 'ready_for_cell') {
      actionHtml =
        '<button class="btn btn-secondary" type="button" data-cell-status-action="cell_provisioning" data-cell-request-id="' + Utils.escapeHtml(item.request_id || '') + '">作業開始</button>' +
        '<button class="btn btn-secondary" type="button" data-cell-status-action="failed" data-cell-request-id="' + Utils.escapeHtml(item.request_id || '') + '">失敗にする</button>';
    } else if (item.status === 'cell_provisioning' || item.status === 'failed') {
      actionHtml =
        '<button class="btn btn-secondary" type="button" data-cell-status-action="cell_provisioning" data-cell-request-id="' + Utils.escapeHtml(item.request_id || '') + '">' + (item.status === 'failed' ? '作業再開' : '作業中に戻す') + '</button>' +
        '<button class="btn" type="button" style="border:1px solid var(--primary);color:var(--primary);" data-cell-status-action="sync_registry" data-cell-index="' + index + '" data-cell-request-id="' + Utils.escapeHtml(item.request_id || '') + '">control registry を active 更新</button>' +
        '<button class="btn btn-secondary" type="button" data-cell-status-action="failed" data-cell-request-id="' + Utils.escapeHtml(item.request_id || '') + '">失敗にする</button>';
    } else if (item.status === 'active') {
      actionHtml =
        '<button class="btn" type="button" style="border:1px solid var(--primary);color:var(--primary);" data-cell-status-action="sync_registry" data-cell-index="' + index + '" data-cell-request-id="' + Utils.escapeHtml(item.request_id || '') + '">control registry を active 更新</button>';
    } else {
      actionHtml = '<span style="color:var(--text-muted);font-size:0.85rem;">支払い確認または申込確認後に作業対象になります。</span>';
    }

    return '<section class="cell-provisioning-item cell-provisioning-item--' + itemTone + '">' +
      '<div class="cell-provisioning-item__head">' +
        '<div>' +
          '<div class="cell-provisioning-item__title">' + Utils.escapeHtml(item.tenant_name || '-') + '</div>' +
          '<div class="cell-provisioning-item__meta"><code>' + Utils.escapeHtml(item.cell_id || '-') + '</code> / ' + Utils.escapeHtml(item.tenant_slug || '-') + '</div>' +
        '</div>' +
        '<div class="tenant-contract">' +
          buildCellOnboardingStatusBadge(item.status || '-') +
          '<span class="status-pill status-pill--' + registryTone + '">registry: ' + Utils.escapeHtml(item.registry_status || 'missing') + '</span>' +
        '</div>' +
      '</div>' +
      '<div class="metric-list">' +
        buildMetricRow('次アクション', Utils.escapeHtml(item.next_action || '-'), '') +
        buildMetricRow('顧客 / 店舗', Utils.escapeHtml(item.tenant_name || '-') + ' / ' + Utils.escapeHtml(item.store_name || '-'), 'tenant_id: ' + (item.tenant_id || '-')) +
        buildMetricRow('Owner', Utils.escapeHtml(item.owner_username || '-'), item.owner_display_name || '') +
        buildMetricRow('App URL', '<code>' + Utils.escapeHtml(item.app_base_url || target.app_base_url || '-') + '</code>', 'health: ' + (item.health_url || target.health_url || '-')) +
        buildMetricRow('DB / uploads', '<code>' + Utils.escapeHtml(target.db_host || '-') + '</code>', target.db_name ? ('db: ' + target.db_name + ' / uploads: ' + target.uploads_path) : '') +
      '</div>' +
      buildCellRegistryEditFieldsHtml(item, index, target) +
      '<div class="settings-inline-actions">' +
        actionHtml +
      '</div>' +
      commandHtml +
    '</section>';
  }

  function handleCellProvisioningClick(e) {
    var copyBtn = e.target.closest('[data-cell-command-copy]');
    var actionBtn;
    if (copyBtn) {
      copyCellProvisioningCommand(copyBtn.getAttribute('data-cell-command-copy'));
      return;
    }

    actionBtn = e.target.closest('[data-cell-status-action]');
    if (!actionBtn) return;
    updateCellProvisioningFromButton(actionBtn);
  }

  function copyCellProvisioningCommand(value) {
    var parts = String(value || '').split(':');
    var itemIndex = parseInt(parts[0], 10);
    var commandIndex = parseInt(parts[1], 10);
    var item = _cellProvisioningState.items[itemIndex] || {};
    var command = item.commands && item.commands[commandIndex] ? item.commands[commandIndex].command : '';
    if (!command) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(command).then(function() {
        showToast('コマンドをコピーしました');
      }).catch(function() {
        showToast('コピーに失敗しました');
      });
      return;
    }
    showToast('このブラウザではコピーできません');
  }

  function readCellProvisioningField(index, field, fallback) {
    var el = document.querySelector('[data-cell-field-index="' + index + '"][data-cell-field="' + field + '"]');
    if (!el) return fallback || '';
    if (el.type === 'checkbox') {
      return el.checked ? 1 : 0;
    }
    return String(el.value || '').trim();
  }

  function readCellProvisioningRegistryPayload(index, item, target) {
    var appBaseUrl = readCellProvisioningField(index, 'app_base_url', item.app_base_url || target.app_base_url || '');
    var healthFallback = item.health_url || target.health_url || (appBaseUrl ? (String(appBaseUrl).replace(/\/+$/, '') + '/api/monitor/ping.php') : '');

    return {
      environment: readCellProvisioningField(index, 'environment', item.registry_environment || target.environment || 'pseudo-prod'),
      app_base_url: appBaseUrl,
      health_url: readCellProvisioningField(index, 'health_url', healthFallback),
      db_host: readCellProvisioningField(index, 'db_host', item.db_host || target.db_host || ''),
      db_name: readCellProvisioningField(index, 'db_name', item.db_name || target.db_name || ''),
      db_user: readCellProvisioningField(index, 'db_user', item.db_user || target.db_user || ''),
      uploads_path: readCellProvisioningField(index, 'uploads_path', item.uploads_path || target.uploads_path || ''),
      php_image: readCellProvisioningField(index, 'php_image', item.php_image || target.php_image || ''),
      deploy_version: readCellProvisioningField(index, 'deploy_version', item.deploy_version || target.deploy_version || ''),
      cron_enabled: readCellProvisioningField(index, 'cron_enabled', parseInt(item.cron_enabled || 0, 10) ? 1 : 0)
    };
  }

  function updateCellProvisioningFromButton(btn) {
    var action = btn.getAttribute('data-cell-status-action');
    var requestId = btn.getAttribute('data-cell-request-id') || '';
    var itemIndex = parseInt(btn.getAttribute('data-cell-index') || '-1', 10);
    var item = _cellProvisioningState.items[itemIndex] || {};
    var target = item.suggested_target || {};
    var registryPayload;
    var payload;

    if (!requestId) return;

    if (action === 'sync_registry') {
      registryPayload = readCellProvisioningRegistryPayload(itemIndex, item, target);
      payload = {
        action: 'sync_registry',
        request_id: requestId,
        cell_id: item.cell_id || target.cell_id || '',
        registry_status: 'active',
        environment: registryPayload.environment || 'pseudo-prod',
        app_base_url: registryPayload.app_base_url || '',
        health_url: registryPayload.health_url || '',
        db_host: registryPayload.db_host || '',
        db_name: registryPayload.db_name || '',
        db_user: registryPayload.db_user || '',
        uploads_path: registryPayload.uploads_path || '',
        php_image: registryPayload.php_image || '',
        deploy_version: registryPayload.deploy_version || '',
        cron_enabled: registryPayload.cron_enabled ? 1 : 0,
        notes: 'Marked active from POSLA Cell配備 UI after smoke.'
      };
    } else {
      payload = {
        action: 'update_status',
        request_id: requestId,
        status: action,
        notes: 'Updated from POSLA Cell配備 UI'
      };
    }

    btn.disabled = true;
    PoslaApi.updateCellProvisioning(payload).then(function() {
      showToast('Cell配備状態を更新しました');
      loadCellProvisioning();
      loadDashboard();
    }).catch(function(err) {
      showToast('Cell配備状態の更新に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

  // ── ダッシュボード ──
  function loadDashboard() {
    return PoslaApi.getDashboard().then(function(data) {
      // 統計カード
      var statsEl = document.getElementById('overview-stats');
      if (statsEl) {
        statsEl.innerHTML =
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.totalTenants)) + '</div><div class="stat-card__label">テナント数</div></div>' +
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.totalStores)) + '</div><div class="stat-card__label">店舗数</div></div>' +
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.totalUsers)) + '</div><div class="stat-card__label">ユーザー数</div></div>' +
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.averageHealthScore || 0)) + '</div><div class="stat-card__label">平均健全性スコア</div></div>' +
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.onboardingTenantCount || 0)) + '</div><div class="stat-card__label">要フォロー導入中</div></div>' +
          '<div class="stat-card"><div class="stat-card__value">' + Utils.escapeHtml(String(data.cellOnboardingPendingCount || 0)) + '</div><div class="stat-card__label">Cell作成待ち</div></div>';
      }

      renderOpMonitoringDelegation(data.releaseMonitoring || {});

      // 契約構成
      var planEl = document.getElementById('plan-distribution');
      if (planEl) {
        var baseCount = parseInt(data.totalTenants, 10) || 0;
        var addonCount = parseInt(data.hqAddonTenants, 10) || 0;
        var html = '<div class="data-table-wrap"><table class="data-table"><thead><tr><th>契約</th><th>テナント数</th></tr></thead><tbody>';
        html += '<tr><td>' + buildTenantContractHtml({ hq_menu_broadcast: 0 }) + '</td><td>' + Utils.escapeHtml(String(baseCount)) + '</td></tr>';
        html += '<tr><td><div class="tenant-contract"><span class="badge badge--active">本部一括配信 契約</span></div></td><td>' + Utils.escapeHtml(String(addonCount)) + '</td></tr>';
        html += '</tbody></table></div>';
        planEl.innerHTML = html;
      }

      // 最近のテナント
      var recentEl = document.getElementById('recent-tenants');
      if (recentEl) {
        var rhtml = '<div class="data-table-wrap"><table class="data-table"><thead><tr><th>テナント名</th><th>Slug</th><th>契約</th><th>状態</th><th>作成日</th></tr></thead><tbody>';
        if (data.recentTenants && data.recentTenants.length > 0) {
          for (var j = 0; j < data.recentTenants.length; j++) {
            var t = data.recentTenants[j];
            var activeClass = parseInt(t.is_active) ? 'active' : 'inactive';
            var activeLabel = parseInt(t.is_active) ? '有効' : '無効';
            var dateStr = t.created_at ? t.created_at.substring(0, 10) : '-';
            rhtml += '<tr>' +
              '<td>' + Utils.escapeHtml(t.name) + '</td>' +
              '<td><code>' + Utils.escapeHtml(t.slug) + '</code></td>' +
              '<td>' + buildTenantContractHtml(t) + '</td>' +
              '<td><span class="badge badge--' + activeClass + '">' + activeLabel + '</span></td>' +
              '<td>' + Utils.escapeHtml(dateStr) + '</td>' +
              '</tr>';
          }
        } else {
          rhtml += '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);">データなし</td></tr>';
        }
        rhtml += '</tbody></table></div>';
        recentEl.innerHTML = rhtml;
      }

      var riskyEl = document.getElementById('risky-tenants');
      if (riskyEl) {
        riskyEl.innerHTML = buildWatchListHtml(data.riskyTenants || [], '現時点で要対応テナントはありません', 'risk');
      }

      var onboardingEl = document.getElementById('onboarding-watchlist');
      if (onboardingEl) {
        onboardingEl.innerHTML = buildWatchListHtml(data.onboardingWatchlist || [], '導入途中のテナントはありません', 'onboarding');
      }

      renderCellOnboardingRequests(data);
    }).catch(function(err) {
      showToast('ダッシュボードの読み込みに失敗しました: ' + err.message);
      throw err;
    });
  }

  // ── テナント一覧 ──
  function loadTenants() {
    PoslaApi.getTenants().then(function(data) {
      var container = document.getElementById('tenants-list');
      if (!container) return;

      var tenants = data.tenants || [];
      var html = '<div class="data-table-wrap"><table class="data-table"><thead><tr>' +
        '<th>テナント名</th><th>Slug</th><th>健全性</th><th>導入状況</th><th>直近異常</th><th>契約</th>' +
        '<th>店舗数</th><th>ユーザー数</th><th>サブスク</th><th>Connect</th><th>状態</th><th>操作</th>' +
        '</tr></thead><tbody>';

      if (tenants.length === 0) {
        html += '<tr><td colspan="12" style="text-align:center;color:var(--text-muted);">テナントがありません</td></tr>';
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
            '<td>' + buildTenantHealthHtml(t) + '</td>' +
            '<td>' + buildTenantProgressHtml(t) + '</td>' +
            '<td>' + buildTenantIncidentHtml(t) + '</td>' +
            '<td>' + buildTenantContractHtml(t) + '</td>' +
            '<td>' + Utils.escapeHtml(String(t.store_count)) + '</td>' +
            '<td>' + Utils.escapeHtml(String(t.user_count)) + '</td>' +
            '<td>' + subHtml + '</td>' +
            '<td>' + connectHtml + '</td>' +
            '<td><span class="badge badge--' + activeClass + '">' + activeLabel + '</span></td>' +
            '<td><div style="display:flex;flex-wrap:wrap;gap:0.35rem;">' +
              '<button class="btn btn-sm btn-outline" data-action="edit-tenant" data-id="' + Utils.escapeHtml(t.id) + '">編集</button>' +
              '<button class="btn btn-sm btn-outline" style="border-color:#f5c2c7;color:#b42318;" data-action="delete-tenant" data-id="' + Utils.escapeHtml(t.id) + '" data-slug="' + Utils.escapeHtml(t.slug) + '" data-name="' + Utils.escapeHtml(t.name) + '">削除</button>' +
            '</div></td>' +
            '</tr>';
        }
      }

      html += '</tbody></table></div>';
      container.innerHTML = html;
    }).catch(function(err) {
      showToast('テナント一覧の読み込みに失敗しました: ' + err.message);
    });
  }

  // ── テナント編集モーダル ──
  function openTenantModal(id) {
    PoslaApi.getTenant(id).then(function(data) {
      var t = data.tenant;
      var summaryEl = document.getElementById('modal-tenant-summary');
      var investigationSummaryEl = document.getElementById('modal-tenant-investigation-summary');
      var investigationChangesEl = document.getElementById('modal-tenant-investigation-changes');
      var timelineEl = document.getElementById('modal-tenant-incident-timeline');
      var opsTimelineEl = document.getElementById('modal-tenant-ops-timeline');
      document.getElementById('modal-tenant-id').value = t.id;
      document.getElementById('modal-tenant-title').textContent = Utils.escapeHtml(t.name) + ' の編集';
      document.getElementById('modal-tenant-name').value = t.name || '';
      document.getElementById('modal-tenant-slug').value = t.slug || '';
      document.getElementById('modal-tenant-plan-label').value = 'POSLA標準（互換値: ' + (t.plan_compat || t.plan || 'standard') + '）';
      document.getElementById('modal-tenant-hq-broadcast').checked = !!parseInt(t.hq_menu_broadcast, 10);
      document.getElementById('modal-tenant-active').value = parseInt(t.is_active) ? '1' : '0';
      if (summaryEl) {
        summaryEl.innerHTML = buildTenantModalSummaryHtml(t);
      }
      if (investigationSummaryEl) {
        investigationSummaryEl.innerHTML = buildTenantInvestigationSummaryHtml(t.investigation_view || {});
      }
      if (investigationChangesEl) {
        investigationChangesEl.innerHTML = buildTenantInvestigationChangesHtml(t.investigation_view || {});
      }
      if (timelineEl) {
        timelineEl.innerHTML = buildTenantTimelineHtml(t.incident_timeline || []);
      }
      if (opsTimelineEl) {
        opsTimelineEl.innerHTML = buildTenantOpsTimelineHtml(t.ops_timeline || []);
      }

      document.getElementById('tenant-modal-overlay').classList.add('open');
    }).catch(function(err) {
      showToast('テナント情報の取得に失敗しました: ' + err.message);
    });
  }

  function closeModal() {
    var summaryEl = document.getElementById('modal-tenant-summary');
    var investigationSummaryEl = document.getElementById('modal-tenant-investigation-summary');
    var investigationChangesEl = document.getElementById('modal-tenant-investigation-changes');
    var timelineEl = document.getElementById('modal-tenant-incident-timeline');
    var opsTimelineEl = document.getElementById('modal-tenant-ops-timeline');
    if (summaryEl) summaryEl.innerHTML = '';
    if (investigationSummaryEl) investigationSummaryEl.innerHTML = '';
    if (investigationChangesEl) investigationChangesEl.innerHTML = '';
    if (timelineEl) timelineEl.innerHTML = '';
    if (opsTimelineEl) opsTimelineEl.innerHTML = '';
    document.getElementById('tenant-modal-overlay').classList.remove('open');
  }

  function saveTenant() {
    var id = document.getElementById('modal-tenant-id').value;
    var updateData = {
      name: document.getElementById('modal-tenant-name').value.trim(),
      is_active: parseInt(document.getElementById('modal-tenant-active').value),
      hq_menu_broadcast: document.getElementById('modal-tenant-hq-broadcast').checked
    };

    var saveBtn = document.getElementById('modal-save-btn');
    saveBtn.disabled = true;

    PoslaApi.updateTenant(id, updateData).then(function() {
      showToast('テナントを更新しました');
      closeModal();
      loadTenants();
      loadDashboard();
    }).catch(function(err) {
      showToast('更新に失敗しました: ' + err.message);
    }).then(function() {
      saveBtn.disabled = false;
    });
  }

  function deleteTenant(id, slug, name) {
    if (!id || !slug) return;

    var title = name || slug;
    var firstConfirm = 'テナント「' + title + '」を削除します。\n\n'
      + 'この操作はPOSLA管理DB上のテナント、初期店舗、初期ユーザー、未使用の導入データを削除します。\n'
      + '注文・会計・予約・勤怠などの実運用データがある場合、API側で削除を拒否します。\n'
      + 'Stripe側の契約や顧客情報、既に作成済みのcell実体は自動削除されません。\n\n'
      + '続行しますか？';
    if (!window.confirm(firstConfirm)) return;

    var confirmSlug = window.prompt('削除確認のため、テナント slug を入力してください: ' + slug);
    if (confirmSlug === null) return;
    confirmSlug = confirmSlug.trim();
    if (confirmSlug !== slug) {
      showToast('slug が一致しないため削除しませんでした');
      return;
    }

    PoslaApi.deleteTenant(id, {
      confirm_slug: confirmSlug,
      acknowledge_external_billing: true
    }).then(function() {
      showToast('テナントを削除しました');
      _featureFlagState.tenantsLoaded = false;
      loadTenants();
      loadDashboard();
      loadFeatureFlagTenants();
    }).catch(function(err) {
      showToast('削除に失敗しました: ' + err.message);
    });
  }

  // ── テナント作成 ──
  function createTenant() {
    var slug = document.getElementById('new-slug').value.trim();
    var name = document.getElementById('new-name').value.trim();
    var hqMenuBroadcast = document.getElementById('new-hq-broadcast').checked;

    if (!slug) { showToast('Slugは必須です'); return; }
    if (!/^[a-z0-9\-]+$/.test(slug)) { showToast('Slugは半角英小文字・数字・ハイフンのみ'); return; }
    if (!name) { showToast('テナント名は必須です'); return; }

    var btn = document.getElementById('btn-create-tenant');
    btn.disabled = true;
    clearTenantCreateResult();

    PoslaApi.createTenant({ slug: slug, name: name, hq_menu_broadcast: hqMenuBroadcast }).then(function(data) {
      showToast('テナントを作成しました');
      renderTenantCreateResult(data);
      document.getElementById('new-slug').value = '';
      document.getElementById('new-name').value = '';
      document.getElementById('new-hq-broadcast').checked = false;
      loadTenants();
      loadDashboard();
    }).catch(function(err) {
      showToast('作成に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

  // ── 設定センター（POSLA共通） ──
  var _apiSettings = {};

  function _buildKeyDisplay(isSet, masked) {
    if (!isSet) return '<span class="ai-status ai-status--unset"></span>未設定';
    return '<span class="ai-status ai-status--set"></span><span>' + Utils.escapeHtml(masked || '********') + '</span>';
  }

  function _buildStatusPill(label, tone) {
    return '<span class="status-pill status-pill--' + tone + '">' + Utils.escapeHtml(label) + '</span>';
  }

  function _summaryLine(label, valueHtml) {
    return '<div><strong>' + Utils.escapeHtml(label) + ':</strong> ' + valueHtml + '</div>';
  }

  function _buildSummaryCard(eyebrow, title, lines) {
    return '<div class="settings-summary-card">' +
      '<div class="settings-summary-card__eyebrow">' + Utils.escapeHtml(eyebrow) + '</div>' +
      '<div class="settings-summary-card__title">' + Utils.escapeHtml(title) + '</div>' +
      '<div class="settings-summary-card__meta">' + lines.join('') + '</div>' +
      '</div>';
  }

  function _setInputValue(id, value) {
    var el = document.getElementById(id);
    if (el) el.value = value || '';
  }

  function _formatAuditDate(value) {
    if (!value) return '-';
    return String(value).replace('T', ' ').substring(0, 16);
  }

  function _buildAuditActor(change) {
    return (change && (change.admin_display_name || change.admin_email)) || '-';
  }

  function _buildAuditActionPill(action) {
    var label = '更新';
    var tone = 'info';
    if (action === 'settings_create') {
      label = '新規';
      tone = 'ok';
    } else if (action === 'settings_clear') {
      label = 'クリア';
      tone = 'warn';
    }
    return '<span class="status-pill status-pill--' + tone + '">' + escapeHtml(label) + '</span>';
  }

  function _buildAuditDiffHtml(change) {
    var oldValue = change && change.old_value ? change.old_value : {};
    var newValue = change && change.new_value ? change.new_value : {};
    return '<div class="settings-history-diff">' +
      '<div class="settings-history-diff__line"><strong>前</strong>' + escapeHtml(oldValue.display || '未設定') + '</div>' +
      '<div class="settings-history-diff__line"><strong>後</strong>' + escapeHtml(newValue.display || '未設定') + '</div>' +
      '</div>';
  }

  function _renderSettingsAuditSummary(summary) {
    var data = summary || {};
    return '<div class="metric-list">' +
      buildMetricRow('最終更新', escapeHtml(_formatAuditDate(data.last_changed_at)), '直近の設定変更時刻') +
      buildMetricRow('更新者', escapeHtml(data.last_changed_by || '-'), '最後に保存した POSLA 管理者') +
      buildMetricRow('直近24hの変更件数', escapeHtml(String(data.changes_24h || 0)), 'setting_key 単位の差分件数') +
      buildMetricRow('直近24hの保存回数', escapeHtml(String(data.batches_24h || 0)), '同時保存を 1 回として集計') +
      '</div>';
  }

  function _renderSettingsAuditList(changes) {
    var list = changes || [];
    var html = '<div class="data-table-wrap"><table class="data-table"><thead><tr>' +
      '<th>日時</th><th>更新者</th><th>種別</th><th>設定</th><th>差分</th>' +
      '</tr></thead><tbody>';
    var i;
    var change;
    var label;

    if (!list.length) {
      html += '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);">変更履歴はまだありません</td></tr>';
      html += '</tbody></table></div>';
      return html;
    }

    for (i = 0; i < list.length; i++) {
      change = list[i];
      label = (change.new_value && change.new_value.label) || (change.old_value && change.old_value.label) || change.entity_id || '-';
      html += '<tr>' +
        '<td>' + escapeHtml(_formatAuditDate(change.created_at)) + '</td>' +
        '<td>' + escapeHtml(_buildAuditActor(change)) + '</td>' +
        '<td>' + _buildAuditActionPill(change.action || 'settings_update') + '</td>' +
        '<td>' + escapeHtml(label) + '</td>' +
        '<td>' + _buildAuditDiffHtml(change) + '</td>' +
        '</tr>';
    }

    html += '</tbody></table></div>';
    return html;
  }

  function _renderConfigHealth(data) {
    var checks = (data && data.checks) || [];
    var overall = (data && data.overall) || {};
    var html = '<div class="metric-list" style="margin-bottom:1rem;">' +
      buildMetricRow('正常', escapeHtml(String(overall.ok_count || 0)), '判定が揃っている連携') +
      buildMetricRow('要確認', escapeHtml(String(overall.warn_count || 0)), '未設定・部分設定・heartbeat 遅延') +
      buildMetricRow('要対応', escapeHtml(String(overall.danger_count || 0)), 'test/live 不一致などの即修正項目') +
      buildMetricRow('対象数', escapeHtml(String(overall.total_count || 0)), '今回チェックしている共通連携数') +
      '</div>';
    var i;
    var check;
    var details;
    var tone;

    if (!checks.length) {
      return html + '<div class="tenant-modal-timeline__empty">まだ判定できる設定がありません。</div>';
    }

    html += '<div class="settings-health-grid">';
    for (i = 0; i < checks.length; i++) {
      check = checks[i];
      details = check.details || [];
      tone = check.tone || 'muted';
      html += '<div class="settings-health-card">' +
        '<div class="settings-health-card__head">' +
          '<div>' +
            '<div class="settings-health-card__title">' + escapeHtml(check.label || '-') + '</div>' +
            '<div class="settings-health-card__summary">' + escapeHtml(check.summary || '-') + '</div>' +
          '</div>' +
          _buildStatusPill(
            tone === 'ok' ? '正常' : (tone === 'danger' ? '要対応' : '要確認'),
            tone === 'danger' ? 'danger' : tone
          ) +
        '</div>' +
        '<ul class="settings-health-card__details">';
      if (details.length) {
        for (var j = 0; j < details.length; j++) {
          html += '<li>' + escapeHtml(details[j]) + '</li>';
        }
      } else {
        html += '<li>詳細なし</li>';
      }
      html += '</ul></div>';
    }
    html += '</div>';
    return html;
  }

  function _settingsChecklistTone(status) {
    if (status === 'ready') return 'ok';
    if (status === 'warning') return 'warn';
    if (status === 'manual') return 'info';
    return 'danger';
  }

  function _settingsChecklistLabel(status) {
    if (status === 'ready') return '設定済み';
    if (status === 'warning') return '要確認';
    if (status === 'manual') return '手動確認';
    return '未設定';
  }

  function _renderSettingsChecklist(data) {
    var groups = (data && data.groups) || [];
    var overall = (data && data.overall) || {};
    var html = '<div class="metric-list" style="margin-bottom:1rem;">' +
      buildMetricRow('設定済み', escapeHtml(String(overall.ready_count || 0)), 'UIまたはenvで確認できた項目') +
      buildMetricRow('要確認', escapeHtml(String(overall.warning_count || 0)), 'ローカルURLなど本番前に見直す項目') +
      buildMetricRow('未設定', escapeHtml(String(overall.missing_count || 0)), '未入力または不足している項目') +
      buildMetricRow('手動確認', escapeHtml(String(overall.manual_count || 0)), 'DNS/VPS/Cloud Runなど外部で確認する項目') +
      '</div>';
    var i;
    var j;
    var group;
    var item;
    var details;
    var tone;

    if (!groups.length) {
      return html + '<div class="tenant-modal-timeline__empty">設定チェックリストはまだありません。</div>';
    }

    for (i = 0; i < groups.length; i++) {
      group = groups[i] || {};
      html += '<div class="settings-card__eyebrow" style="margin:1rem 0 0.35rem;">' + escapeHtml(group.title || '-') + '</div>';
      if (group.description) {
        html += '<div class="settings-card__note" style="margin-bottom:0.6rem;">' + escapeHtml(group.description) + '</div>';
      }
      html += '<div class="settings-health-grid">';
      for (j = 0; j < (group.items || []).length; j++) {
        item = group.items[j] || {};
        details = item.details || [];
        tone = _settingsChecklistTone(item.status || 'missing');
        html += '<div class="settings-health-card">' +
          '<div class="settings-health-card__head">' +
            '<div>' +
              '<div class="settings-health-card__title">' + escapeHtml(item.label || '-') + '</div>' +
              '<div class="settings-health-card__summary">' + escapeHtml(item.classification || '-') + ' / source ' + escapeHtml(item.source || '-') + '</div>' +
            '</div>' +
            _buildStatusPill(_settingsChecklistLabel(item.status || 'missing'), tone) +
          '</div>' +
          '<ul class="settings-health-card__details">' +
            '<li>次: ' + escapeHtml(item.next_action || '-') + '</li>';
        if (details.length) {
          for (var k = 0; k < details.length; k++) {
            html += '<li>' + escapeHtml(details[k]) + '</li>';
          }
        }
        html += '</ul></div>';
      }
      html += '</div>';
    }

    return html;
  }

  function _renderNotificationCenter(data) {
    var center = data || {};
    var googleChat = center.google_chat || {};
    var opsMail = center.ops_mail || {};
    var webPush = center.web_push || {};
    var monitor = center.monitor || {};
    var html = '<div class="settings-health-grid">';

    html += '<div class="settings-health-card">' +
      '<div class="settings-health-card__head">' +
        '<div>' +
          '<div class="settings-health-card__title">Google Chat</div>' +
          '<div class="settings-health-card__summary">' + escapeHtml(googleChat.detail || '通知先未設定') + '</div>' +
        '</div>' +
        _buildStatusPill(googleChat.available ? '送信可' : '未設定', googleChat.available ? 'ok' : 'warn') +
      '</div>' +
      '<ul class="settings-health-card__details">' +
        '<li>現在ルート: ' + escapeHtml(googleChat.route || 'none') + '</li>' +
        '<li>送信は monitor-health 経路を利用</li>' +
      '</ul>' +
    '</div>';

    html += '<div class="settings-health-card">' +
      '<div class="settings-health-card__head">' +
        '<div>' +
          '<div class="settings-health-card__title">運用通知メール</div>' +
          '<div class="settings-health-card__summary">' + escapeHtml(opsMail.recipient || '宛先未設定') + '</div>' +
        '</div>' +
        _buildStatusPill(opsMail.available ? '送信可' : '要確認', opsMail.available ? 'ok' : 'warn') +
      '</div>' +
      '<ul class="settings-health-card__details">' +
        '<li>送信方式: ' + escapeHtml(opsMail.transport || 'none') + '</li>' +
        '<li>本文は下のテストメッセージ欄を共用</li>' +
      '</ul>' +
    '</div>';

    html += '<div class="settings-health-card">' +
      '<div class="settings-health-card__head">' +
        '<div>' +
          '<div class="settings-health-card__title">Web Push</div>' +
          '<div class="settings-health-card__summary">有効購読 ' + escapeHtml(String(webPush.enabled_subscriptions || 0)) + ' 件</div>' +
        '</div>' +
        _buildStatusPill(webPush.available ? '準備完了' : '未設定', webPush.available ? 'ok' : 'warn') +
      '</div>' +
      '<ul class="settings-health-card__details">' +
        '<li>push_test 24h: ' + escapeHtml(String(webPush.push_test_24h || 0)) + ' 件</li>' +
        '<li>最終 push_test: ' + escapeHtml(webPush.last_push_test_at || '未実行') + '</li>' +
      '</ul>' +
    '</div>';

    html += '<div class="settings-health-card">' +
      '<div class="settings-health-card__head">' +
        '<div>' +
          '<div class="settings-health-card__title">監視 heartbeat</div>' +
          '<div class="settings-health-card__summary">' + escapeHtml(monitor.heartbeat || '未到達') + '</div>' +
        '</div>' +
        _buildStatusPill(monitor.heartbeat ? '更新中' : '未到達', monitor.heartbeat ? 'ok' : 'warn') +
      '</div>' +
      '<ul class="settings-health-card__details">' +
        '<li>必要時は「監視を再実行」で確認</li>' +
        '<li>Google Chat テスト送信後に状態更新</li>' +
      '</ul>' +
    '</div>';

    html += '</div>';
    return html;
  }

  function _renderNotifyTestResult(title, lines, tone) {
    var resultEl = document.getElementById('posla-notify-test-result');
    var html = '<strong>' + escapeHtml(title) + '</strong>';
    var i;
    if (lines && lines.length) {
      for (i = 0; i < lines.length; i++) {
        html += '<div>' + escapeHtml(lines[i]) + '</div>';
      }
    }
    if (resultEl) {
      resultEl.innerHTML = html;
      resultEl.style.borderColor = tone === 'danger' ? '#f5c2c7' : (tone === 'warn' ? '#ffe0b2' : '#dfe4f5');
      resultEl.style.background = tone === 'danger' ? '#fff5f5' : (tone === 'warn' ? '#fffaf2' : '#f8faff');
    }
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
      var settingsChecklistEl = document.getElementById('posla-settings-checklist');
      var configHealthEl = document.getElementById('posla-config-health');
      var notifyCenterEl = document.getElementById('posla-notification-center');
      var monitorStatusEl = document.getElementById('posla-monitor-status');
      var auditSummaryEl = document.getElementById('posla-settings-audit-summary');
      var auditListEl = document.getElementById('posla-settings-audit-list');
      if (!statusEl) return;

      var gemini = _getKeyInfo(s, 'gemini_api_key');
      var places = _getKeyInfo(s, 'google_places_api_key');
      var stripeSecret = _getKeyInfo(s, 'stripe_secret_key');
      var stripePub = _getKeyInfo(s, 'stripe_publishable_key');
      var stripeWebhook = _getKeyInfo(s, 'stripe_webhook_secret');
      var stripeSignupWebhook = _getKeyInfo(s, 'stripe_webhook_secret_signup');
      var smaregiSecret = _getKeyInfo(s, 'smaregi_client_secret');
      var googleChat = _getKeyInfo(s, 'google_chat_webhook_url');
      var slackWebhook = _getKeyInfo(s, 'slack_webhook_url');
      var monitorSecret = _getKeyInfo(s, 'monitor_cron_secret');
      var opsCaseToken = _getKeyInfo(s, 'codex_ops_case_token');

      var priceBaseVal = _getPlainValue(s, 'stripe_price_base');
      var priceAddVal = _getPlainValue(s, 'stripe_price_additional_store');
      var priceHqVal = _getPlainValue(s, 'stripe_price_hq_broadcast');
      var connectFeeVal = _getPlainValue(s, 'connect_application_fee_percent');
      var smaregiClientIdVal = _getPlainValue(s, 'smaregi_client_id');
      var opsNotifyEmailVal = _getPlainValue(s, 'ops_notify_email');
      var mailFromEmailVal = _getPlainValue(s, 'mail_from_email');
      var mailFromNameVal = _getPlainValue(s, 'mail_from_name');
      var mailSupportEmailVal = _getPlainValue(s, 'mail_support_email');
      var heartbeatVal = _getPlainValue(s, 'monitor_last_heartbeat');
      var opsPublicUrlVal = _getPlainValue(s, 'codex_ops_public_url');
      var opsCaseEndpointVal = _getPlainValue(s, 'codex_ops_case_endpoint');
      updateOpLinks(opBaseUrlFromSettings(s));

      statusEl.innerHTML =
        _buildSummaryCard('AI / MAPS', 'Gemini・Places', [
          _summaryLine('Gemini', gemini.set ? _buildStatusPill('設定済み', 'ok') : _buildStatusPill('未設定', 'muted')),
          _summaryLine('Places', places.set ? _buildStatusPill('設定済み', 'ok') : _buildStatusPill('未設定', 'muted'))
        ]) +
        _buildSummaryCard('BILLING', 'Stripe Billing', [
          _summaryLine('Secret', stripeSecret.set ? _buildStatusPill('設定済み', 'ok') : _buildStatusPill('未設定', 'muted')),
          _summaryLine('Webhook', (stripeWebhook.set && stripeSignupWebhook.set) ? _buildStatusPill('2/2 設定済み', 'ok') : _buildStatusPill('一部未設定', 'warn')),
          _summaryLine('Price ID', (priceBaseVal && priceAddVal && priceHqVal) ? _buildStatusPill('3/3 設定済み', 'info') : _buildStatusPill('一部未設定', 'warn'))
        ]) +
        _buildSummaryCard('EXTERNAL', 'Connect・スマレジ', [
          _summaryLine('手数料率', connectFeeVal ? Utils.escapeHtml(connectFeeVal) + '%' : '<span style="color:#999;">未設定</span>'),
          _summaryLine('Client ID', smaregiClientIdVal ? Utils.escapeHtml(smaregiClientIdVal) : '<span style="color:#999;">未設定</span>'),
          _summaryLine('Client Secret', smaregiSecret.set ? _buildStatusPill('設定済み', 'ok') : _buildStatusPill('未設定', 'muted'))
        ]) +
        _buildSummaryCard('MONITORING', 'Google Chat・運用通知', [
          _summaryLine('Google Chat', googleChat.set ? _buildStatusPill('設定済み', 'ok') : _buildStatusPill('未設定', 'warn')),
          _summaryLine('運用通知', opsNotifyEmailVal ? Utils.escapeHtml(opsNotifyEmailVal) : '<span style="color:#999;">未設定</span>'),
          _summaryLine('送信元', mailFromEmailVal ? Utils.escapeHtml(mailFromEmailVal) : '<span style="color:#999;">env fallback</span>'),
          _summaryLine('heartbeat', heartbeatVal ? Utils.escapeHtml(heartbeatVal) : '<span style="color:#999;">未到達</span>')
        ]) +
        _buildSummaryCard('OPS BRIDGE', 'OP障害報告', [
          _summaryLine('OP画面URL', opsPublicUrlVal ? _buildStatusPill('設定済み', 'ok') : _buildStatusPill('未設定', 'warn')),
          _summaryLine('障害報告', (opsCaseEndpointVal && opsCaseToken.set) ? _buildStatusPill('設定済み', 'ok') : _buildStatusPill('未設定', 'warn')),
          _summaryLine('監視', _buildStatusPill('OPで確認', 'info'))
        ]);

      if (monitorStatusEl) {
        monitorStatusEl.innerHTML =
          '<div class="settings-monitor-status__row">' +
          '<div class="settings-monitor-status__label">Google Chat</div>' +
          '<div class="settings-monitor-status__value">' + _buildKeyDisplay(googleChat.set, googleChat.masked) + '</div>' +
          '</div>' +
          '<div class="settings-monitor-status__row">' +
          '<div class="settings-monitor-status__label">Slack fallback</div>' +
          '<div class="settings-monitor-status__value">' + _buildKeyDisplay(slackWebhook.set, slackWebhook.masked) + '</div>' +
          '</div>' +
          '<div class="settings-monitor-status__row">' +
          '<div class="settings-monitor-status__label">運用通知メール</div>' +
          '<div class="settings-monitor-status__value">' + (opsNotifyEmailVal ? Utils.escapeHtml(opsNotifyEmailVal) : '<span style="color:#999;">未設定</span>') + '</div>' +
          '</div>' +
          '<div class="settings-monitor-status__row">' +
          '<div class="settings-monitor-status__label">最終 heartbeat</div>' +
          '<div class="settings-monitor-status__value">' + (heartbeatVal ? Utils.escapeHtml(heartbeatVal) : '<span style="color:#999;">未到達</span>') + '</div>' +
          '</div>' +
          '<div class="settings-monitor-status__row">' +
          '<div class="settings-monitor-status__label">DB共有秘密</div>' +
          '<div class="settings-monitor-status__value">' + _buildKeyDisplay(monitorSecret.set, monitorSecret.masked) + '</div>' +
          '</div>';
      }

      _setInputValue('posla-stripe-price-base', priceBaseVal);
      _setInputValue('posla-stripe-price-add', priceAddVal);
      _setInputValue('posla-stripe-price-hq', priceHqVal);
      _setInputValue('posla-connect-fee', connectFeeVal);
      _setInputValue('posla-smaregi-client-id', smaregiClientIdVal);
      _setInputValue('posla-ops-notify-email', opsNotifyEmailVal);
      _setInputValue('posla-mail-from-email', mailFromEmailVal);
      _setInputValue('posla-mail-from-name', mailFromNameVal);
      _setInputValue('posla-mail-support-email', mailSupportEmailVal);
      if (auditSummaryEl) {
        auditSummaryEl.innerHTML = _renderSettingsAuditSummary(data.audit_summary || {});
      }
      if (auditListEl) {
        auditListEl.innerHTML = _renderSettingsAuditList(data.recent_changes || []);
      }
      if (configHealthEl) {
        configHealthEl.innerHTML = _renderConfigHealth(data.config_health || {});
      }
      if (settingsChecklistEl) {
        settingsChecklistEl.innerHTML = _renderSettingsChecklist(data.settings_checklist || {});
      }
      if (notifyCenterEl) {
        notifyCenterEl.innerHTML = _renderNotificationCenter(data.notification_center || {});
      }
    }).catch(function(err) {
      showToast('設定センターの読み込みに失敗しました: ' + err.message);
    });
  }

  function saveApiSettings() {
    var aiKey = document.getElementById('posla-ai-key').value.trim();
    var placesKey = document.getElementById('posla-places-key').value.trim();
    var stripeSecret = document.getElementById('posla-stripe-secret').value.trim();
    var stripePub = document.getElementById('posla-stripe-pub').value.trim();
    var stripeWebhook = document.getElementById('posla-stripe-webhook').value.trim();
    var stripeSignupWebhook = document.getElementById('posla-stripe-webhook-signup').value.trim();
    var priceBase = document.getElementById('posla-stripe-price-base').value.trim();
    var priceAdd = document.getElementById('posla-stripe-price-add').value.trim();
    var priceHq = document.getElementById('posla-stripe-price-hq').value.trim();
    var connectFeeEl = document.getElementById('posla-connect-fee');
    var connectFee = connectFeeEl ? connectFeeEl.value.trim() : '';
    var googleChatWebhook = document.getElementById('posla-google-chat-webhook').value.trim();
    var opsNotifyEmail = document.getElementById('posla-ops-notify-email').value.trim();
    var mailFromEmailEl = document.getElementById('posla-mail-from-email');
    var mailFromNameEl = document.getElementById('posla-mail-from-name');
    var mailSupportEmailEl = document.getElementById('posla-mail-support-email');
    var mailFromEmail = mailFromEmailEl ? mailFromEmailEl.value.trim() : '';
    var mailFromName = mailFromNameEl ? mailFromNameEl.value.trim() : '';
    var mailSupportEmail = mailSupportEmailEl ? mailSupportEmailEl.value.trim() : '';

    var data = {};
    if (aiKey) data.gemini_api_key = aiKey;
    if (placesKey) data.google_places_api_key = placesKey;
    if (stripeSecret) data.stripe_secret_key = stripeSecret;
    if (stripePub) data.stripe_publishable_key = stripePub;
    if (stripeWebhook) data.stripe_webhook_secret = stripeWebhook;
    if (stripeSignupWebhook) data.stripe_webhook_secret_signup = stripeSignupWebhook;
    if (priceBase) data.stripe_price_base = priceBase;
    if (priceAdd) data.stripe_price_additional_store = priceAdd;
    if (priceHq) data.stripe_price_hq_broadcast = priceHq;
    if (connectFee !== '') data.connect_application_fee_percent = connectFee;
    if (googleChatWebhook) data.google_chat_webhook_url = googleChatWebhook;
    if (opsNotifyEmail !== '') data.ops_notify_email = opsNotifyEmail;
    if (mailFromEmail !== '') data.mail_from_email = mailFromEmail;
    if (mailFromName !== '') data.mail_from_name = mailFromName;
    if (mailSupportEmail !== '') data.mail_support_email = mailSupportEmail;

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
      document.getElementById('posla-stripe-webhook-signup').value = '';
      document.getElementById('posla-google-chat-webhook').value = '';
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
    if (!confirm('機密値をクリアします。よろしいですか？')) return;

    var btn = document.getElementById('btn-clear-api');
    btn.disabled = true;

    PoslaApi.updateSettings({
      gemini_api_key: null,
      google_places_api_key: null,
      stripe_secret_key: null,
      stripe_publishable_key: null,
      stripe_webhook_secret: null,
      stripe_webhook_secret_signup: null,
      smaregi_client_secret: null,
      google_chat_webhook_url: null
    }).then(function() {
      document.getElementById('posla-ai-key').value = '';
      document.getElementById('posla-places-key').value = '';
      document.getElementById('posla-stripe-secret').value = '';
      document.getElementById('posla-stripe-pub').value = '';
      document.getElementById('posla-stripe-webhook').value = '';
      document.getElementById('posla-stripe-webhook-signup').value = '';
      document.getElementById('posla-google-chat-webhook').value = '';
      var smSecEl = document.getElementById('posla-smaregi-client-secret');
      if (smSecEl) smSecEl.value = '';
      showToast('機密値をクリアしました');
      loadApiStatus();
    }).catch(function(err) {
      showToast('削除に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

  function runMonitorHealth() {
    var btn = document.getElementById('btn-run-monitor-health');
    if (!btn) return;
    btn.disabled = true;

    PoslaApi.runMonitorAction('run_health_check').then(function() {
      showToast('monitor-health を実行しました');
      loadApiStatus();
    }).catch(function(err) {
      showToast('監視再実行に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

  function sendMonitorTestAlert() {
    var btn = document.getElementById('btn-send-monitor-test');
    if (!btn) return;

    var payload = {};
    var tenantEl = document.getElementById('posla-monitor-tenant');
    var storeEl = document.getElementById('posla-monitor-store');
    var messageEl = document.getElementById('posla-monitor-message');

    if (tenantEl && tenantEl.value.trim()) payload.tenant_id = tenantEl.value.trim();
    if (storeEl && storeEl.value.trim()) payload.store_id = storeEl.value.trim();
    if (messageEl && messageEl.value.trim()) payload.message = messageEl.value.trim();

    btn.disabled = true;
    PoslaApi.runMonitorAction('send_test_alert', payload).then(function(data) {
      var scope = data && data.scope ? data.scope : {};
      _renderNotifyTestResult('Google Chat テスト通知を送信しました', [
        'ルート: ' + (data.destination || 'google_chat'),
        'tenant_id: ' + (scope.tenant_id || '-'),
        'store_id: ' + (scope.store_id || '-'),
        'error_no: ' + (scope.error_no || 'E1025')
      ], 'ok');
      showToast('Google Chat テスト通知を送信しました: ' + (scope.error_no || 'E1025'));
      loadApiStatus();
    }).catch(function(err) {
      _renderNotifyTestResult('Google Chat テスト通知に失敗しました', [err.message || 'unknown'], 'danger');
      showToast('テスト通知に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

  function sendOpsMailTest() {
    var btn = document.getElementById('btn-send-ops-mail-test');
    var messageEl = document.getElementById('posla-monitor-message');
    var payload = {};

    if (!btn) return;
    if (messageEl && messageEl.value.trim()) {
      payload.message = messageEl.value.trim();
    }

    btn.disabled = true;
    PoslaApi.runMonitorAction('send_ops_mail_test', payload).then(function(data) {
      var mail = data && data.mail ? data.mail : {};
      _renderNotifyTestResult('運用通知メールを送信しました', [
        '宛先: ' + (mail.recipient || '-'),
        '送信方式: ' + (mail.transport || '-'),
        '件名: ' + (mail.subject || '-')
      ], 'ok');
      showToast('運用通知メールを送信しました');
      loadApiStatus();
    }).catch(function(err) {
      _renderNotifyTestResult('運用通知メールの送信に失敗しました', [err.message || 'unknown'], 'danger');
      showToast('運用通知メールの送信に失敗しました: ' + err.message);
    }).then(function() {
      btn.disabled = false;
    });
  }

})();
