/**
 * ユーザー管理（owner / manager共用）
 *
 * owner: AdminApi.getUsers() → /api/owner/users.php（全ユーザーCRUD）
 * manager: AdminApi.getStaff() → /api/store/staff-management.php（自店舗staffのみ）
 */
var UserEditor = (function () {
  'use strict';

  var _container = null;
  var _users = [];
  var _stores = [];
  var _role = 'owner'; // デフォルト（owner-dashboard.htmlから呼ばれた場合）

  function _isManager() {
    return _role !== 'owner';
  }

  function init(container, role) {
    _container = container;
    _role = role || 'owner';
  }

  function load() {
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    _stores = StoreSelector.getStores();

    var fetcher = _isManager() ? AdminApi.getStaff() : AdminApi.getUsers();
    fetcher.then(function (res) {
      _users = res.users || [];
      render();
    }).catch(function (err) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function roleBadge(role) {
    var cls = { owner: 'badge--owner', manager: 'badge--manager', staff: 'badge--staff' };
    var labels = { owner: 'オーナー', manager: 'マネージャー', staff: 'スタッフ' };
    return '<span class="badge ' + (cls[role] || '') + '">' + (labels[role] || role) + '</span>';
  }

  function render() {
    if (_users.length === 0) {
      _container.innerHTML = '<div class="empty-state"><div class="empty-state__icon">👤</div><p class="empty-state__text">ユーザーがいません</p></div>';
      return;
    }

    var html = '<div class="card"><div class="data-table-wrap"><table class="data-table">'
      + '<thead><tr><th>名前</th><th>ユーザー名</th><th>メール</th><th>ロール</th><th>担当店舗</th><th>ステータス</th><th></th></tr></thead><tbody>';

    _users.forEach(function (u) {
      var storeNames = u.role === 'owner' ? '<em>全店舗</em>' : (u.store_names.length > 0 ? Utils.escapeHtml(u.store_names.join(', ')) : '-');
      var status = u.is_active == 1
        ? '<span class="badge badge--available">有効</span>'
        : '<span class="badge badge--sold-out">無効</span>';

      html += '<tr>'
        + '<td><strong>' + Utils.escapeHtml(u.display_name || '-') + '</strong></td>'
        + '<td><code>' + Utils.escapeHtml(u.username || '-') + '</code></td>'
        + '<td>' + Utils.escapeHtml(u.email || '-') + '</td>'
        + '<td>' + roleBadge(u.role) + '</td>'
        + '<td>' + storeNames + '</td>'
        + '<td>' + status + '</td>'
        + '<td class="text-right">'
        + '<button class="btn btn-sm btn-outline" data-action="edit-user" data-id="' + u.id + '">編集</button> '
        + '<button class="btn btn-sm btn-danger" data-action="delete-user" data-id="' + u.id + '">削除</button>'
        + '</td></tr>';
    });

    html += '</tbody></table></div></div>';
    _container.innerHTML = html;
  }

  function storeCheckboxes(selectedIds) {
    return _stores.map(function (s) {
      var checked = selectedIds.indexOf(s.id) >= 0 ? ' checked' : '';
      return '<label class="settings-toggle"><input type="checkbox" class="settings-toggle__input store-cb" value="' + s.id + '"' + checked + '> ' + Utils.escapeHtml(s.name) + '</label>';
    }).join('');
  }

  // L-3 Phase 2: 時給入力欄HTML生成
  function hourlyRateHtml(hourlyRate) {
    var val = (hourlyRate !== null && hourlyRate !== undefined) ? hourlyRate : '';
    return '<div class="form-group" id="user-hourly-rate-group">'
      + '<label class="form-label">時給（円）</label>'
      + '<input class="form-input" id="user-hourly-rate" type="number" min="0" max="10000" value="' + val + '" placeholder="空欄＝店舗デフォルト">'
      + '<p style="color:#666;font-size:0.8rem;margin-top:4px;">空欄の場合は店舗のデフォルト時給が適用されます</p>'
      + '</div>';
  }

  function getHourlyRateValue() {
    var el = document.getElementById('user-hourly-rate');
    if (!el || el.value === '') return null;
    var v = parseInt(el.value, 10);
    return isNaN(v) ? null : v;
  }

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = _isManager() ? 'スタッフを追加' : 'ユーザーを追加';

    var bodyHtml =
      '<div class="form-group"><label class="form-label">表示名</label><input class="form-input" id="user-name" placeholder="山田太郎"></div>'
      + '<div class="form-group"><label class="form-label">ユーザー名 *</label><input class="form-input" id="user-username" type="text" placeholder="shibuya-staff" pattern="[a-zA-Z0-9_-]{3,50}"></div>'
      + '<div class="form-group"><label class="form-label">メールアドレス（任意）</label><input class="form-input" id="user-email" type="email" placeholder="user@example.com"></div>'
      + '<div class="form-group"><label class="form-label">パスワード *</label><input class="form-input" id="user-password" type="password" placeholder="6文字以上"></div>';

    if (!_isManager()) {
      bodyHtml += '<div class="form-group"><label class="form-label">ロール</label><select class="form-input" id="user-role"><option value="staff">スタッフ</option><option value="manager">マネージャー</option><option value="owner">オーナー</option></select></div>'
        + '<div class="form-group" id="user-stores-group"><label class="form-label">担当店舗</label><div class="settings-toggle-group">' + storeCheckboxes([]) + '</div></div>';
    }

    // L-3 Phase 2: 時給入力欄
    if (_isManager()) {
      bodyHtml += hourlyRateHtml(null);
    }

    overlay.querySelector('.modal__body').innerHTML = bodyHtml;
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-user">保存</button>';
    overlay.classList.add('open');

    if (!_isManager()) {
      // ロール変更で店舗選択の表示/非表示
      document.getElementById('user-role').addEventListener('change', function () {
        document.getElementById('user-stores-group').style.display = this.value === 'owner' ? 'none' : '';
      });
    }

    document.getElementById('btn-save-user').onclick = function () {
      var username = document.getElementById('user-username').value.trim();
      var email = document.getElementById('user-email').value.trim();
      var password = document.getElementById('user-password').value;
      if (!username || !password) { showToast('ユーザー名とパスワードは必須です', 'error'); return; }

      this.disabled = true;

      if (_isManager()) {
        // manager: staffのみ作成、store_idは現在選択中の店舗
        var payload = {
          username: username,
          password: password,
          display_name: document.getElementById('user-name').value.trim(),
          store_id: AdminApi.getCurrentStore()
        };
        if (email) payload.email = email;
        // L-3 Phase 2: hourly_rate
        var hr = getHourlyRateValue();
        if (hr !== null) payload.hourly_rate = hr;

        AdminApi.createStaff(payload).then(function () {
          overlay.classList.remove('open');
          showToast('スタッフを追加しました', 'success');
          load();
        }).catch(function (err) {
          showToast(err.message, 'error');
        }).finally(function () {
          var btn = document.getElementById('btn-save-user');
          if (btn) btn.disabled = false;
        });
      } else {
        // owner: 既存ロジック
        var storeIds = [];
        document.querySelectorAll('.store-cb:checked').forEach(function (cb) {
          storeIds.push(cb.value);
        });

        var payload = {
          username: username,
          password: password,
          display_name: document.getElementById('user-name').value.trim(),
          role: document.getElementById('user-role').value,
          store_ids: storeIds
        };
        if (email) payload.email = email;

        AdminApi.createUser(payload).then(function () {
          overlay.classList.remove('open');
          showToast('ユーザーを追加しました', 'success');
          load();
        }).catch(function (err) {
          showToast(err.message, 'error');
        }).finally(function () {
          var btn = document.getElementById('btn-save-user');
          if (btn) btn.disabled = false;
        });
      }
    };
  }

  function openEditModal(id) {
    var u = _users.find(function (x) { return x.id === id; });
    if (!u) return;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = _isManager() ? 'スタッフを編集' : 'ユーザーを編集';

    var bodyHtml =
      '<div class="form-group"><label class="form-label">表示名</label><input class="form-input" id="user-name" value="' + Utils.escapeHtml(u.display_name || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">ユーザー名</label><input class="form-input" id="user-username" type="text" value="' + Utils.escapeHtml(u.username || '') + '" pattern="[a-zA-Z0-9_-]{3,50}"></div>'
      + '<div class="form-group"><label class="form-label">メール</label><input class="form-input" id="user-email" type="email" value="' + Utils.escapeHtml(u.email || '') + '" disabled></div>'
      + '<div class="form-group"><label class="form-label">新しいパスワード（変更する場合のみ）</label><input class="form-input" id="user-password" type="password" placeholder="空欄のまま＝変更なし"></div>'
      + '<div class="form-group"><label class="form-label">レジ担当 PIN（CP1）</label>'
      + '<input class="form-input" id="user-cashier-pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" placeholder="空欄＝変更なし、4〜8桁の数字"' + (u.cashier_pin_set ? ' aria-describedby="pin-hint"' : '') + '>'
      + '<small id="pin-hint" style="color:#666;display:block;margin-top:0.25rem;">' + (u.cashier_pin_set ? '✅ PIN 設定済み（変更する場合のみ入力）' : '⚠️ PIN 未設定（POSレジ会計のため設定推奨）') + '</small>'
      + '</div>';

    if (!_isManager()) {
      bodyHtml += '<div class="form-group"><label class="form-label">ロール</label><select class="form-input" id="user-role">'
        + '<option value="staff"' + (u.role === 'staff' ? ' selected' : '') + '>スタッフ</option>'
        + '<option value="manager"' + (u.role === 'manager' ? ' selected' : '') + '>マネージャー</option>'
        + '<option value="owner"' + (u.role === 'owner' ? ' selected' : '') + '>オーナー</option>'
        + '</select></div>'
        + '<div class="form-group" id="user-stores-group"' + (u.role === 'owner' ? ' style="display:none"' : '') + '>'
        + '<label class="form-label">担当店舗</label><div class="settings-toggle-group">' + storeCheckboxes(u.store_ids) + '</div></div>';
    }

    bodyHtml += '<div class="form-group"><label class="form-label">有効</label>'
      + '<label class="toggle"><input type="checkbox" id="user-active" ' + (u.is_active == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>';

    // L-3 Phase 2: 時給入力欄（staff/managerのみ）
    if (u.role === 'staff' || u.role === 'manager') {
      var currentHr = null;
      if (_isManager()) {
        currentHr = u.hourly_rate !== undefined ? u.hourly_rate : null;
      } else {
        var curStore = AdminApi.getCurrentStore ? AdminApi.getCurrentStore() : null;
        if (curStore && u.hourly_rate_by_store && u.hourly_rate_by_store[curStore] !== undefined) {
          currentHr = u.hourly_rate_by_store[curStore];
        }
      }
      bodyHtml += hourlyRateHtml(currentHr);
    }

    overlay.querySelector('.modal__body').innerHTML = bodyHtml;
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-user">保存</button>';
    overlay.classList.add('open');

    if (!_isManager()) {
      document.getElementById('user-role').addEventListener('change', function () {
        document.getElementById('user-stores-group').style.display = this.value === 'owner' ? 'none' : '';
      });
    }

    document.getElementById('btn-save-user').onclick = function () {
      this.disabled = true;

      // CP1: PIN 入力値を取得（manager/owner 共通）
      var newPin = (document.getElementById('user-cashier-pin') || {}).value || '';

      if (_isManager()) {
        // manager: username, display_name, password, is_active
        var data = {
          username: document.getElementById('user-username').value.trim(),
          display_name: document.getElementById('user-name').value.trim(),
          is_active: document.getElementById('user-active').checked,
          store_id: AdminApi.getCurrentStore()
        };
        // L-3 Phase 2: hourly_rate
        data.hourly_rate = getHourlyRateValue();
        var pw = document.getElementById('user-password').value;
        if (pw) data.password = pw;

        AdminApi.updateStaff(id, data).then(function () {
          // CP1: PIN 入力があれば別 API で保存
          if (newPin) {
            return _saveCashierPin(id, newPin);
          }
        }).then(function () {
          overlay.classList.remove('open');
          showToast('スタッフを更新しました' + (newPin ? '（PIN も設定）' : ''), 'success');
          load();
        }).catch(function (err) {
          showToast(err.message, 'error');
        }).finally(function () {
          var btn = document.getElementById('btn-save-user');
          if (btn) btn.disabled = false;
        });
      } else {
        // owner: 既存ロジック
        var storeIds = [];
        document.querySelectorAll('.store-cb:checked').forEach(function (cb) {
          storeIds.push(cb.value);
        });

        var data = {
          username: document.getElementById('user-username').value.trim(),
          display_name: document.getElementById('user-name').value.trim(),
          role: document.getElementById('user-role').value,
          is_active: document.getElementById('user-active').checked,
          store_ids: storeIds
        };
        var pw = document.getElementById('user-password').value;
        if (pw) data.password = pw;
        // L-3 Phase 2: hourly_rate_by_store
        var curStore = AdminApi.getCurrentStore ? AdminApi.getCurrentStore() : null;
        if (curStore) {
          var hrMap = {};
          hrMap[curStore] = getHourlyRateValue();
          data.hourly_rate_by_store = hrMap;
        }

        AdminApi.updateUser(id, data).then(function () {
          // CP1: PIN 入力があれば別 API で保存
          if (newPin) {
            return _saveCashierPin(id, newPin);
          }
        }).then(function () {
          overlay.classList.remove('open');
          showToast('ユーザーを更新しました' + (newPin ? '（PIN も設定）' : ''), 'success');
          load();
        }).catch(function (err) {
          showToast(err.message, 'error');
        }).finally(function () {
          var btn = document.getElementById('btn-save-user');
          if (btn) btn.disabled = false;
        });
      }
    };
  }

  /**
   * CP1: 担当スタッフ PIN 設定
   */
  function _saveCashierPin(userId, pin) {
    return fetch('../../api/store/set-cashier-pin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_id: userId, pin: pin }),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (text) {
        var json;
        try { json = JSON.parse(text); }
        catch (e) { throw new Error('PIN 設定 API のレスポンス解析失敗'); }
        if (!json.ok) throw new Error((json.error && json.error.message) || 'PIN 設定エラー');
        return json;
      });
    });
  }

  function confirmDelete(id) {
    var u = _users.find(function (x) { return x.id === id; });
    if (!u) return;
    if (!confirm('「' + (u.display_name || u.username || u.email) + '」を削除しますか？この操作は元に戻せません。')) return;

    var deleter = _isManager() ? AdminApi.deleteStaff(id) : AdminApi.deleteUser(id);
    deleter.then(function () {
      showToast(_isManager() ? 'スタッフを削除しました' : 'ユーザーを削除しました', 'success');
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
