/**
 * カテゴリ管理（HQ — owner用、テナント単位）
 */
var CategoryEditor = (function () {
  'use strict';

  var _listEl = null;
  var _countEl = null;
  var _categories = [];

  function init(listEl, countEl) {
    _listEl = listEl;
    _countEl = countEl;
  }

  function load() {
    _listEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    AdminApi.getCategories().then(function (res) {
      _categories = res.categories || [];
      render();
    }).catch(function (err) {
      _listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function render() {
    _countEl.textContent = '(' + _categories.length + ')';

    if (_categories.length === 0) {
      _listEl.innerHTML = '<div class="empty-state"><div class="empty-state__icon">📂</div><p class="empty-state__text">カテゴリがありません</p></div>';
      return;
    }

    var html = '';
    _categories.forEach(function (c, i) {
      html += '<div class="list-item">'
        + '<span class="list-item__order">' + (i + 1) + '</span>'
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + Utils.escapeHtml(c.name)
        + (c.name_en ? ' <span class="i18n-en">' + Utils.escapeHtml(c.name_en) + '</span>' : '') + '</div>'
        + '</div>'
        + '<div class="list-item__actions">'
        + '<button class="btn btn-sm btn-outline" data-action="edit-category" data-id="' + c.id + '">編集</button>'
        + '<button class="btn btn-sm btn-danger" data-action="delete-category" data-id="' + c.id + '">削除</button>'
        + '</div></div>';
    });
    _listEl.innerHTML = html;
  }

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'カテゴリを追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">カテゴリ名 *</label><input class="form-input" id="cat-name" placeholder="メイン"></div>'
      + '<div class="form-group"><label class="form-label">カテゴリ名（英語）</label><input class="form-input" id="cat-name-en" placeholder="Main"></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-cat">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-cat').onclick = function () {
      var name = document.getElementById('cat-name').value.trim();
      if (!name) { showToast('カテゴリ名は必須です', 'error'); return; }
      this.disabled = true;
      AdminApi.createCategory({
        name: name,
        name_en: document.getElementById('cat-name-en').value.trim()
      }).then(function () {
        closeModal();
        showToast('カテゴリを追加しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-cat');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditModal(id) {
    var cat = _categories.find(function (c) { return c.id === id; });
    if (!cat) return;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'カテゴリを編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">カテゴリ名</label><input class="form-input" id="cat-name" value="' + Utils.escapeHtml(cat.name) + '"></div>'
      + '<div class="form-group"><label class="form-label">カテゴリ名（英語）</label><input class="form-input" id="cat-name-en" value="' + Utils.escapeHtml(cat.name_en || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">表示順</label><input class="form-input" id="cat-order" type="number" value="' + (cat.sort_order || 0) + '"></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-cat">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-cat').onclick = function () {
      this.disabled = true;
      AdminApi.updateCategory(id, {
        name: document.getElementById('cat-name').value.trim(),
        name_en: document.getElementById('cat-name-en').value.trim(),
        sort_order: parseInt(document.getElementById('cat-order').value, 10) || 0
      }).then(function () {
        closeModal();
        showToast('カテゴリを更新しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-cat');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDelete(id) {
    var cat = _categories.find(function (c) { return c.id === id; });
    if (!cat) return;
    if (!confirm('「' + cat.name + '」を削除しますか？')) return;

    AdminApi.deleteCategory(id).then(function () {
      showToast('カテゴリを削除しました', 'success');
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  function closeModal() {
    document.getElementById('admin-modal-overlay').classList.remove('open');
  }

  function getCategories() {
    return _categories;
  }

  return {
    init: init,
    load: load,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    confirmDelete: confirmDelete,
    closeModal: closeModal,
    getCategories: getCategories
  };
})();
