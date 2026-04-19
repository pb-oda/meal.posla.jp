/**
 * デバイスアカウント管理 (P1a)
 *
 * device ロール: KDS / レジ端末専用アカウント
 *  - スタッフではないので人件費・シフト・レポート対象外
 *  - 1端末 = 1アカウント
 *  - visible_tools は kds / register / handy のいずれか（複数可、カンマ区切り）
 *  - ログインすると dashboard.html ではなく KDS / レジ / ハンディ画面に直接遷移する
 *    （優先度: handy > register > kds — dashboard.html の device redirect ロジック参照）
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

  // visible_tools チェックボックス（kds / register / handy）
  function _toolCheckboxesHtml(visibleTools) {
    var allowed = visibleTools ? visibleTools.split(',') : ['kds'];
    var tools = [
      { key: 'kds', label: 'KDS（キッチンディスプレイ）' },
      { key: 'register', label: 'POSレジ' },
      { key: 'handy', label: 'ハンディ' }
    ];
    var html = '<div class="form-group"><label class="form-label">用途（KDS / レジ / ハンディ）</label>'
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
      if (!tools) { showToast('KDS / POSレジ / ハンディ のいずれかを選択してください', 'error'); return; }

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
      if (!tools) { showToast('KDS / POSレジ / ハンディ のいずれかを選択してください', 'error'); return; }

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

  // F-DA1: トークン方式でデバイス登録
  function openTokenModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    if (!overlay) return;
    overlay.querySelector('.modal__title').textContent = '登録トークンを発行';

    var bodyHtml =
      '<div class="form-group"><label class="form-label">表示名 *</label><input class="form-input" id="dev-token-name" placeholder="厨房KDS 1番"></div>'
      + _toolCheckboxesHtml(null)
      + '<div class="form-group"><label class="form-label">有効期限</label>'
      + '<select class="form-input" id="dev-token-hours"><option value="1">1時間</option><option value="24" selected>24時間</option><option value="72">3日</option><option value="168">7日</option></select></div>';

    overlay.querySelector('.modal__body').innerHTML = bodyHtml;
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-gen-token">トークン発行</button>';
    overlay.classList.add('open');

    document.getElementById('btn-gen-token').onclick = function () {
      var displayName = document.getElementById('dev-token-name').value.replace(/^\s+|\s+$/g, '');
      var tools = _getToolsValue();
      var hours = parseInt(document.getElementById('dev-token-hours').value, 10) || 24;

      if (!displayName) { showToast('表示名は必須です', 'error'); return; }
      if (!tools) { showToast('用途を選択してください', 'error'); return; }

      this.disabled = true;
      var self = this;
      var storeId = AdminApi.getCurrentStore();

      fetch('../../api/store/device-registration-token.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          store_id: storeId,
          display_name: displayName,
          visible_tools: tools,
          expires_hours: hours
        })
      })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { return { ok: false, error: { message: t.substring(0, 120) } }; }
        });
      })
      .then(function (json) {
        if (!json.ok) {
          showToast((json.error && json.error.message) || 'トークン発行に失敗しました', 'error');
          self.disabled = false;
          return;
        }
        var d = json.data;
        // トークン表示画面に切替
        overlay.querySelector('.modal__title').textContent = 'トークン発行完了';
        overlay.querySelector('.modal__body').innerHTML =
          '<div style="text-align:center;padding:1rem 0">'
          + '<p style="margin-bottom:0.5rem"><strong>' + Utils.escapeHtml(d.displayName) + '</strong></p>'
          + '<p style="color:#666;font-size:0.85rem;margin-bottom:1rem">このトークンは1回だけ使用できます（' + d.expiresHours + '時間有効）</p>'
          + '<div style="background:#f5f5f5;border:1px solid #ddd;border-radius:8px;padding:0.75rem;word-break:break-all;font-family:monospace;font-size:0.85rem;margin-bottom:1rem">'
          + Utils.escapeHtml(d.token) + '</div>'
          + '<p style="font-size:0.85rem;color:#666;margin-bottom:0.5rem">セットアップURL:</p>'
          + '<div style="background:#f5f5f5;border:1px solid #ddd;border-radius:8px;padding:0.75rem;word-break:break-all;font-size:0.75rem;margin-bottom:1rem">'
          + Utils.escapeHtml(d.setupUrl) + '</div>'
          + '<button class="btn btn-outline" id="btn-copy-token">URLをコピー</button>'
          + '</div>';
        overlay.querySelector('.modal__footer').innerHTML =
          '<button class="btn btn-primary" data-action="modal-cancel">閉じる</button>';

        document.getElementById('btn-copy-token').onclick = function () {
          if (navigator.clipboard) {
            navigator.clipboard.writeText(d.setupUrl).then(function () {
              showToast('コピーしました', 'success');
            });
          }
        };
      })
      .catch(function (err) {
        showToast(err.message || '通信エラー', 'error');
        self.disabled = false;
      });
    };
  }

  return {
    init: init,
    load: load,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    openTokenModal: openTokenModal,
    confirmDelete: confirmDelete
  };
})();
