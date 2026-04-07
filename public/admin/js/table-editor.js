/**
 * テーブル管理（QRコード付き）
 */
var TableEditor = (function () {
  'use strict';

  var _listEl = null;
  var _countEl = null;
  var _tables = [];

  function init(listEl, countEl) {
    _listEl = listEl;
    _countEl = countEl;
  }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _listEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';
    AdminApi.getTables().then(function (res) {
      _tables = res.tables || [];
      render();
    }).catch(function (err) {
      _listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function render() {
    _countEl.textContent = '(' + _tables.length + ')';
    if (_tables.length === 0) {
      _listEl.innerHTML = '<div class="empty-state"><div class="empty-state__icon">🪑</div><p class="empty-state__text">テーブルがありません</p></div>';
      return;
    }

    var html = '';
    _tables.forEach(function (t) {
      var statusBadge = t.is_active == 1
        ? '<span class="badge badge--available">有効</span>'
        : '<span class="badge badge--sold-out">無効</span>';
      html += '<div class="list-item">'
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + Utils.escapeHtml(t.table_code) + ' ' + statusBadge + '</div>'
        + '<div class="list-item__meta">定員: ' + t.capacity + '名</div></div>'
        + '<div class="list-item__actions">'
        + '<button class="btn btn-sm btn-outline" data-action="show-qr" data-id="' + t.id + '" data-name="' + Utils.escapeHtml(t.table_code) + '">QR</button>'
        + '<button class="btn btn-sm btn-outline" data-action="edit-table" data-id="' + t.id + '">編集</button>'
        + '<button class="btn btn-sm btn-danger" data-action="delete-table" data-id="' + t.id + '">削除</button>'
        + '</div></div>';
    });
    _listEl.innerHTML = html;
  }

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'テーブルを追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">テーブルコード *</label><input class="form-input" id="table-code" placeholder="T01"></div>'
      + '<div class="form-group"><label class="form-label">定員</label><input class="form-input" id="table-capacity" type="number" value="4"></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-table">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-table').onclick = function () {
      var code = document.getElementById('table-code').value.trim();
      if (!code) { showToast('テーブルコードは必須です', 'error'); return; }
      this.disabled = true;
      AdminApi.createTable({
        table_code: code,
        capacity: parseInt(document.getElementById('table-capacity').value, 10) || 4
      }).then(function () {
        overlay.classList.remove('open');
        showToast('テーブルを追加しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-table');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditModal(id) {
    var t = _tables.find(function (x) { return x.id === id; });
    if (!t) return;
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'テーブルを編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">テーブルコード</label><input class="form-input" id="table-code" value="' + Utils.escapeHtml(t.table_code) + '"></div>'
      + '<div class="form-group"><label class="form-label">定員</label><input class="form-input" id="table-capacity" type="number" value="' + (t.capacity || 4) + '"></div>'
      + '<div class="form-group"><label class="form-label">有効</label>'
      + '<label class="toggle"><input type="checkbox" id="table-active" ' + (t.is_active == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-table">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-table').onclick = function () {
      this.disabled = true;
      AdminApi.updateTable(id, {
        table_code: document.getElementById('table-code').value.trim(),
        capacity: parseInt(document.getElementById('table-capacity').value, 10) || 4,
        is_active: document.getElementById('table-active').checked
      }).then(function () {
        overlay.classList.remove('open');
        showToast('テーブルを更新しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-table');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDelete(id) {
    var t = _tables.find(function (x) { return x.id === id; });
    if (!t || !confirm('「' + t.table_code + '」を削除しますか？')) return;
    AdminApi.deleteTable(id).then(function () {
      showToast('テーブルを削除しました', 'success');
      load();
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  function showQR(id, name) {
    var storeId = AdminApi.getCurrentStore();
    // 現在のページ（/xxx/public/admin/dashboard.html）からベースパスを算出
    var basePath = window.location.pathname.replace(/\/public\/admin\/.*$/, '');
    var url = window.location.origin + basePath + '/public/customer/menu.html?store_id=' + storeId + '&table_id=' + id;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = name + ' QRコード';
    overlay.querySelector('.modal__body').innerHTML =
      '<div style="text-align:center"><div id="qr-container"></div><p style="margin-top:1rem;font-size:0.8125rem;color:#999;word-break:break-all">' + Utils.escapeHtml(url) + '</p></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">閉じる</button>';
    overlay.classList.add('open');

    if (typeof QRCode !== 'undefined') {
      new QRCode(document.getElementById('qr-container'), { text: url, width: 200, height: 200 });
    }
  }

  return {
    init: init,
    load: load,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    confirmDelete: confirmDelete,
    showQR: showQR
  };
})();
