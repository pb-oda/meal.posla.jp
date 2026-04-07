/**
 * 店舗管理（owner用）
 */
var StoreEditor = (function () {
  'use strict';

  var _container = null;
  var _stores = [];

  function init(container) {
    _container = container;
  }

  var _tenant = null;

  function load() {
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';

    var storesP = AdminApi.getStores();
    var tenantP = AdminApi.getTenant();

    Promise.all([storesP, tenantP]).then(function (results) {
      _stores = results[0].stores || [];
      _tenant = results[1].tenant || null;
      render();
    }).catch(function (err) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function renderTenantSection() {
    if (!_tenant) return '';
    var html = '<div class="card" style="margin-bottom:1.5rem"><div class="card__body">'
      + '<h3 style="margin-bottom:1rem;font-size:1rem">テナント設定</h3>'
      + '<div class="form-row">'
      + '<div class="form-group" style="flex:1"><label class="form-label">テナント名</label>'
      + '<p style="margin:0;font-size:0.95rem;font-weight:600">' + Utils.escapeHtml(_tenant.name || '') + '</p></div>'
      + '</div>'
      + '<h4 style="margin:1.5rem 0 0.75rem;font-size:0.9rem">AI設定（Gemini）</h4>'
      + '<div class="form-group"><label class="form-label">APIキー'
      + (_tenant.ai_api_key_set ? ' <span style="color:#43a047;font-weight:normal;font-size:0.8rem">（設定済み）</span>' : '')
      + '</label>'
      + '<div style="display:flex;gap:0.5rem;align-items:center">'
      + '<input class="form-input" id="tenant-ai-key" type="password" value="" placeholder="'
      + (_tenant.ai_api_key_set ? '変更する場合のみ入力' : 'APIキーを入力') + '" style="flex:1">'
      + '<button type="button" class="btn btn-secondary btn-sm" id="btn-toggle-tenant-ai-key" style="white-space:nowrap">表示</button>'
      + '<button type="button" class="btn btn-primary btn-sm" id="btn-save-tenant-ai-key" style="white-space:nowrap">保存</button>'
      + '</div></div>'
      + '</div></div>';
    return html;
  }

  function bindTenantEvents() {
    var toggleBtn = document.getElementById('btn-toggle-tenant-ai-key');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        var inp = document.getElementById('tenant-ai-key');
        if (inp.type === 'password') {
          inp.type = 'text';
          this.textContent = '非表示';
        } else {
          inp.type = 'password';
          this.textContent = '表示';
        }
      });
    }

    var saveBtn = document.getElementById('btn-save-tenant-ai-key');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        var aiKey = document.getElementById('tenant-ai-key').value.trim();
        if (!aiKey) { showToast('APIキーを入力してください', 'error'); return; }

        this.disabled = true;
        AdminApi.updateTenant({ ai_api_key: aiKey }).then(function () {
          showToast('AIキーを保存しました', 'success');
          var inp = document.getElementById('tenant-ai-key');
          if (inp) { inp.value = ''; inp.placeholder = '変更する場合のみ入力'; }
          _tenant.ai_api_key_set = true;
          // ラベルの「設定済み」表示を更新
          var label = inp.closest('.form-group').querySelector('.form-label');
          if (label && label.innerHTML.indexOf('設定済み') < 0) {
            label.innerHTML += ' <span style="color:#43a047;font-weight:normal;font-size:0.8rem">（設定済み）</span>';
          }
        }).catch(function (err) {
          showToast(err.message, 'error');
        }).finally(function () {
          var btn = document.getElementById('btn-save-tenant-ai-key');
          if (btn) btn.disabled = false;
        });
      });
    }
  }

  function render() {
    var tenantHtml = renderTenantSection();

    if (_stores.length === 0) {
      _container.innerHTML = tenantHtml + '<div class="empty-state"><div class="empty-state__icon">🏪</div><p class="empty-state__text">店舗がありません</p></div>';
      bindTenantEvents();
      return;
    }

    var html = tenantHtml + '<div class="card"><div class="data-table-wrap"><table class="data-table">'
      + '<thead><tr><th>店舗名</th><th>スラッグ</th><th>ステータス</th><th>作成日</th><th></th></tr></thead><tbody>';

    _stores.forEach(function (s) {
      var statusBadge = s.is_active == 1
        ? '<span class="badge badge--available">有効</span>'
        : '<span class="badge badge--sold-out">無効</span>';
      html += '<tr>'
        + '<td><strong>' + Utils.escapeHtml(s.name) + '</strong>'
        + (s.name_en ? '<br><span class="i18n-en">' + Utils.escapeHtml(s.name_en) + '</span>' : '') + '</td>'
        + '<td><code>' + Utils.escapeHtml(s.slug) + '</code></td>'
        + '<td>' + statusBadge + '</td>'
        + '<td>' + Utils.formatDateTime(s.created_at) + '</td>'
        + '<td class="text-right">'
        + '<button class="btn btn-sm btn-outline" data-action="edit-store" data-id="' + s.id + '">編集</button> '
        + '<button class="btn btn-sm btn-danger" data-action="delete-store" data-id="' + s.id + '">削除</button>'
        + '</td></tr>';
    });

    html += '</tbody></table></div></div>';
    _container.innerHTML = html;
    bindTenantEvents();
  }

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = '店舗を追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">店舗名 *</label><input class="form-input" id="store-name" placeholder="渋谷店"></div>'
      + '<div class="form-group"><label class="form-label">店舗名（英語）</label><input class="form-input" id="store-name-en" placeholder="Shibuya"></div>'
      + '<div class="form-group"><label class="form-label">スラッグ *</label><input class="form-input" id="store-slug" placeholder="shibuya"><p class="form-hint">英小文字・数字・ハイフンのみ。URLに使用されます。</p></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-store">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-store').onclick = function () {
      var name = document.getElementById('store-name').value.trim();
      var slug = document.getElementById('store-slug').value.trim();
      if (!name || !slug) { showToast('店舗名とスラッグは必須です', 'error'); return; }

      this.disabled = true;
      AdminApi.createStore({
        name: name,
        name_en: document.getElementById('store-name-en').value.trim(),
        slug: slug
      }).then(function () {
        overlay.classList.remove('open');
        showToast('店舗を追加しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-store');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditModal(id) {
    var store = _stores.find(function (s) { return s.id === id; });
    if (!store) return;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = '店舗を編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">店舗名</label><input class="form-input" id="store-name" value="' + Utils.escapeHtml(store.name) + '"></div>'
      + '<div class="form-group"><label class="form-label">店舗名（英語）</label><input class="form-input" id="store-name-en" value="' + Utils.escapeHtml(store.name_en || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">スラッグ</label><input class="form-input" id="store-slug" value="' + Utils.escapeHtml(store.slug) + '"></div>'
      + '<div class="form-group"><label class="form-label">有効</label>'
      + '<label class="toggle"><input type="checkbox" id="store-active" ' + (store.is_active == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-store">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-store').onclick = function () {
      this.disabled = true;
      AdminApi.updateStore(id, {
        name: document.getElementById('store-name').value.trim(),
        name_en: document.getElementById('store-name-en').value.trim(),
        slug: document.getElementById('store-slug').value.trim(),
        is_active: document.getElementById('store-active').checked
      }).then(function () {
        overlay.classList.remove('open');
        showToast('店舗を更新しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-store');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDelete(id) {
    var store = _stores.find(function (s) { return s.id === id; });
    if (!store) return;
    if (!confirm('「' + store.name + '」を無効化しますか？')) return;

    AdminApi.deleteStore(id).then(function () {
      showToast('店舗を無効化しました', 'success');
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  return {
    init: init,
    load: load,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    confirmDelete: confirmDelete
  };
})();
