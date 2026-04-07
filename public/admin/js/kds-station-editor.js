/**
 * KDSステーション管理（manager以上、店舗単位）
 * ステーション一覧 + カテゴリ割り当て
 */
var KdsStationEditor = (function () {
  'use strict';

  var _listEl = null;
  var _stations = [];
  var _categories = []; // カテゴリ一覧（チェックボックス用）

  function init(listEl) {
    _listEl = listEl;
  }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _listEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    // ステーションとカテゴリを並行取得
    Promise.all([
      AdminApi.getKdsStations(),
      AdminApi.getCategories()
    ]).then(function (results) {
      _stations = results[0].stations || [];
      _categories = results[1].categories || [];
      render();
    }).catch(function (err) {
      _listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function render() {
    if (_stations.length === 0) {
      _listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">KDSステーションがありません</p><p style="color:#999;font-size:0.8125rem">ステーションを追加すると、KDS画面でカテゴリ別にフィルタできます</p></div>';
      return;
    }

    var html = '';
    _stations.forEach(function (s) {
      var activeBadge = s.is_active == 1
        ? '<span class="badge badge--available">有効</span>'
        : '<span class="badge badge--sold-out">無効</span>';

      var catNames = (s.categories || []).map(function (c) { return c.category_name; }).join(', ');
      if (!catNames) catNames = '<span style="color:#999">未設定</span>';

      html += '<div class="list-item">'
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + Utils.escapeHtml(s.name)
        + (s.name_en ? ' <span style="color:#999;font-size:0.8125rem">(' + Utils.escapeHtml(s.name_en) + ')</span>' : '')
        + ' ' + activeBadge + '</div>'
        + '<div class="list-item__meta">カテゴリ: ' + catNames + '</div>'
        + '</div>'
        + '<div class="list-item__actions">'
        + '<button class="btn btn-sm btn-outline" data-action="edit-station" data-id="' + s.id + '">編集</button>'
        + '<button class="btn btn-sm btn-danger" data-action="delete-station" data-id="' + s.id + '">削除</button>'
        + '</div></div>';
    });
    _listEl.innerHTML = html;
  }

  function buildCategoryCheckboxes(selectedIds) {
    if (_categories.length === 0) return '<p style="color:#999">カテゴリがありません</p>';
    var html = '<div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:0.5rem">';
    _categories.forEach(function (c) {
      var checked = selectedIds.indexOf(c.id) >= 0 ? ' checked' : '';
      html += '<label style="display:block;padding:0.25rem 0;cursor:pointer">'
        + '<input type="checkbox" class="station-cat-check" value="' + c.id + '"' + checked + '> '
        + Utils.escapeHtml(c.name)
        + (c.name_en ? ' (' + Utils.escapeHtml(c.name_en) + ')' : '')
        + '</label>';
    });
    html += '</div>';
    return html;
  }

  function getCheckedCategoryIds() {
    var ids = [];
    var checks = document.querySelectorAll('.station-cat-check:checked');
    for (var i = 0; i < checks.length; i++) {
      ids.push(checks[i].value);
    }
    return ids;
  }

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'KDSステーション追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">ステーション名 *</label><input class="form-input" id="st-name" placeholder="厨房"></div>'
      + '<div class="form-group"><label class="form-label">English</label><input class="form-input" id="st-name-en" placeholder="Kitchen"></div>'
      + '<div class="form-group"><label class="form-label">担当カテゴリ</label>' + buildCategoryCheckboxes([]) + '</div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-station">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-station').onclick = function () {
      var name = document.getElementById('st-name').value.trim();
      if (!name) { showToast('ステーション名は必須です', 'error'); return; }
      this.disabled = true;
      AdminApi.createKdsStation({
        name: name,
        name_en: document.getElementById('st-name-en').value.trim(),
        category_ids: getCheckedCategoryIds()
      }).then(function () {
        overlay.classList.remove('open');
        showToast('ステーションを追加しました', 'success');
        load();
      }).catch(function (err) { showToast(err.message, 'error'); })
      .finally(function () {
        var btn = document.getElementById('btn-save-station');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditModal(id) {
    var s = _stations.find(function (x) { return x.id === id; });
    if (!s) return;

    var selectedCatIds = (s.categories || []).map(function (c) { return c.category_id; });

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'ステーション編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">ステーション名</label><input class="form-input" id="st-name" value="' + Utils.escapeHtml(s.name) + '"></div>'
      + '<div class="form-group"><label class="form-label">English</label><input class="form-input" id="st-name-en" value="' + Utils.escapeHtml(s.name_en || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">有効</label>'
      + '<label class="toggle"><input type="checkbox" id="st-active" ' + (s.is_active == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>'
      + '<div class="form-group"><label class="form-label">担当カテゴリ</label>' + buildCategoryCheckboxes(selectedCatIds) + '</div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-station">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-station').onclick = function () {
      this.disabled = true;
      AdminApi.updateKdsStation(id, {
        name: document.getElementById('st-name').value.trim(),
        name_en: document.getElementById('st-name-en').value.trim(),
        is_active: document.getElementById('st-active').checked,
        category_ids: getCheckedCategoryIds()
      }).then(function () {
        overlay.classList.remove('open');
        showToast('ステーションを更新しました', 'success');
        load();
      }).catch(function (err) { showToast(err.message, 'error'); })
      .finally(function () {
        var btn = document.getElementById('btn-save-station');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDelete(id) {
    var s = _stations.find(function (x) { return x.id === id; });
    if (!s || !confirm('「' + s.name + '」を削除しますか？')) return;
    AdminApi.deleteKdsStation(id).then(function () {
      showToast('ステーションを削除しました', 'success');
      load();
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  return {
    init: init,
    load: load,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    confirmDelete: confirmDelete
  };
})();
