/**
 * デバイスアカウント管理 (P1a)
 *
 * device ロール: KDS / レジ端末専用アカウント
 *  - スタッフではないので人件費・シフト・レポート対象外
 *  - 1端末 = 1アカウント
 *  - visible_tools は kds / register のいずれか（または両方）
 *  - ログインすると dashboard.html ではなく KDS / レジ画面に直接遷移する
 *
 * このモジュールは UserEditor とは独立した IIFE。
 * AdminApi の getDevices / createDevice / updateDevice / deleteDevice を使う。
 */
var DeviceEditor = (function () {
  'use strict';

  var _container = null;
  var _devices = [];

  function init(container) {
    _container = container;
  }

  function load() {
    if (!_container) return;
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    AdminApi.getDevices().then(function (res) {
      _devices = res.users || [];
      render();
    }).catch(function (err) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function _toolBadges(visibleTools) {
    if (!visibleTools) return '<span class="badge">未設定</span>';
    var parts = visibleTools.split(',');
    var labels = { kds: 'KDS', register: 'POSレジ', handy: 'ハンディ' };
    var html = '';
    for (var i = 0; i < parts.length; i++) {
      var key = parts[i].replace(/^\s+|\s+$/g, '');
      if (!key) continue;
      html += '<span class="badge">' + Utils.escapeHtml(labels[key] || key) + '</span> ';
    }
    return html;
  }

  function render() {
    if (_devices.length === 0) {
      _container.innerHTML = '<div class="empty-state"><div class="empty-state__icon">📺</div><p class="empty-state__text">デバイスアカウントがありません</p><p class="empty-state__hint">「+ デバイス追加」から KDS / レジ端末用のアカウントを作成できます。</p></div>';
      return;
    }

    var html = '<div class="card"><div class="data-table-wrap"><table class="data-table">'
      + '<thead><tr><th>表示名</th><th>ユーザー名</th><th>用途</th><th>ステータス</th><th></th></tr></thead><tbody>';

    for (var i = 0; i < _devices.length; i++) {
      var d = _devices[i];
      var status = d.is_active == 1
        ? '<span class="badge badge--available">有効</span>'
        : '<span class="badge badge--sold-out">無効</span>';

      html += '<tr>'
        + '<td><strong>' + Utils.escapeHtml(d.display_name || '-') + '</strong></td>'
        + '<td><code>' + Utils.escapeHtml(d.username || '-') + '</code></td>'
        + '<td>' + _toolBadges(d.visible_tools) + '</td>'
        + '<td>' + status + '</td>'
        + '<td class="text-right">'
        + '<button class="btn btn-sm btn-outline" data-action="edit-device" data-id="' + Utils.escapeHtml(d.id) + '">編集</button> '
        + '<button class="btn btn-sm btn-danger" data-action="delete-device" data-id="' + Utils.escapeHtml(d.id) + '">削除</button>'
        + '</td></tr>';
    }

    html += '</tbody></table></div></div>';
    _container.innerHTML = html;

    // バインド
    var btns = _container.querySelectorAll('[data-action="edit-device"]');
    for (var j = 0; j < btns.length; j++) {
      btns[j].addEventListener('click', function () {
        openEditModal(this.getAttribute('data-id'));
      });
    }
    var dels = _container.querySelectorAll('[data-action="delete-device"]');
    for (var k = 0; k < dels.length; k++) {
      dels[k].addEventListener('click', function () {
        confirmDelete(this.getAttribute('data-id'));
      });
    }
  }

  // visible_tools チェックボックス（kds / register のみ）
  function _toolCheckboxesHtml(visibleTools) {
    var allowed = visibleTools ? visibleTools.split(',') : ['kds'];
    var tools = [
      { key: 'kds', label: 'KDS（キッチンディスプレイ）' },
      { key: 'register', label: 'POSレジ' }
    ];
    var html = '<div class="form-group"><label class="form-label">用途（KDS / レジ）</label>'
      + '<p style="color:#666;font-size:0.8rem;margin-bottom:4px;">この端末で表示するアプリを選択してください（少なくとも1つ）</p>';
    for (var i = 0; i < tools.length; i++) {
      var checked = allowed.indexOf(tools[i].key) >= 0 ? ' checked' : '';
      html += '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input device-tool-cb" value="' + tools[i].key + '"' + checked + '> ' + tools[i].label + '</label>';
    }
    html += '</div>';
    return html;
  }

  function _getToolsValue() {
    var checked = [];
    var cbs = document.querySelectorAll('.device-tool-cb:checked');
    for (var i = 0; i < cbs.length; i++) {
      checked.push(cbs[i].value);
    }
    return checked.join(',');
  }

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    if (!overlay) return;
    overlay.querySelector('.modal__title').textContent = 'デバイスを追加';

    var bodyHtml =
      '<div class="form-group"><label class="form-label">表示名 *</label><input class="form-input" id="dev-name" placeholder="厨房KDS 1番"></div>'
      + '<div class="form-group"><label class="form-label">ユーザー名 *</label><input class="form-input" id="dev-username" type="text" placeholder="kds-01" pattern="[a-zA-Z0-9_-]{3,50}"></div>'
      + '<div class="form-group"><label class="form-label">パスワード *</label><input class="form-input" id="dev-password" type="password" placeholder="8文字以上、英字+数字"></div>'
      + _toolCheckboxesHtml(null);

    overlay.querySelector('.modal__body').innerHTML = bodyHtml;
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-device">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-device').onclick = function () {
      var displayName = document.getElementById('dev-name').value.replace(/^\s+|\s+$/g, '');
      var username = document.getElementById('dev-username').value.replace(/^\s+|\s+$/g, '');
      var password = document.getElementById('dev-password').value;
      var tools = _getToolsValue();

      if (!displayName) { showToast('表示名は必須です', 'error'); return; }
      if (!username || !password) { showToast('ユーザー名とパスワードは必須です', 'error'); return; }
      if (!tools) { showToast('KDS または POSレジ のどちらかを選択してください', 'error'); return; }

      this.disabled = true;
      var payload = {
        username: username,
        password: password,
        display_name: displayName,
        store_id: AdminApi.getCurrentStore(),
        visible_tools: tools
      };

      AdminApi.createDevice(payload).then(function () {
        overlay.classList.remove('open');
        showToast('デバイスを追加しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-device');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditModal(id) {
    var d = null;
    for (var i = 0; i < _devices.length; i++) {
      if (_devices[i].id === id) { d = _devices[i]; break; }
    }
    if (!d) return;

    var overlay = document.getElementById('admin-modal-overlay');
    if (!overlay) return;
    overlay.querySelector('.modal__title').textContent = 'デバイスを編集';

    var bodyHtml =
      '<div class="form-group"><label class="form-label">表示名</label><input class="form-input" id="dev-name" value="' + Utils.escapeHtml(d.display_name || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">ユーザー名</label><input class="form-input" id="dev-username" type="text" value="' + Utils.escapeHtml(d.username || '') + '" pattern="[a-zA-Z0-9_-]{3,50}"></div>'
      + '<div class="form-group"><label class="form-label">新しいパスワード（変更する場合のみ）</label><input class="form-input" id="dev-password" type="password" placeholder="空欄＝変更なし"></div>'
      + _toolCheckboxesHtml(d.visible_tools)
      + '<div class="form-group"><label class="form-label">有効</label>'
      + '<label class="toggle"><input type="checkbox" id="dev-active" ' + (d.is_active == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>';

    overlay.querySelector('.modal__body').innerHTML = bodyHtml;
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-device">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-device').onclick = function () {
      var tools = _getToolsValue();
      if (!tools) { showToast('KDS または POSレジ のどちらかを選択してください', 'error'); return; }

      this.disabled = true;
      var data = {
        username: document.getElementById('dev-username').value.replace(/^\s+|\s+$/g, ''),
        display_name: document.getElementById('dev-name').value.replace(/^\s+|\s+$/g, ''),
        is_active: document.getElementById('dev-active').checked,
        store_id: AdminApi.getCurrentStore(),
        visible_tools: tools
      };
      var pw = document.getElementById('dev-password').value;
      if (pw) data.password = pw;

      AdminApi.updateDevice(id, data).then(function () {
        overlay.classList.remove('open');
        showToast('デバイスを更新しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-device');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDelete(id) {
    var d = null;
    for (var i = 0; i < _devices.length; i++) {
      if (_devices[i].id === id) { d = _devices[i]; break; }
    }
    if (!d) return;
    if (!confirm('デバイス「' + (d.display_name || d.username) + '」を削除しますか？\nこの操作は元に戻せません。')) return;

    AdminApi.deleteDevice(id).then(function () {
      showToast('デバイスを削除しました', 'success');
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
