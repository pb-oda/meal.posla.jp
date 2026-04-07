/**
 * 本部メニューテンプレート管理（owner用）
 */
var MenuTemplateEditor = (function () {
  'use strict';

  var _container = null;
  var _templates = [];
  var _filterCategoryId = '';

  function init(container) {
    _container = container;
  }

  function load() {
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    AdminApi.getMenuTemplates().then(function (res) {
      _templates = res.templates || [];
      render();
    }).catch(function (err) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function render() {
    var items = _filterCategoryId
      ? _templates.filter(function (t) { return t.category_id === _filterCategoryId; })
      : _templates;

    if (items.length === 0) {
      _container.innerHTML = '<div class="empty-state"><div class="empty-state__icon">🍽️</div><p class="empty-state__text">メニューがありません</p></div>';
      return;
    }

    var html = '';
    items.forEach(function (t) {
      var img = t.image_url
        ? '<img class="list-item__image" src="../../' + Utils.escapeHtml(t.image_url) + '" alt="">'
        : '<div class="list-item__image--placeholder">🍽️</div>';

      var soldOutBtn = t.is_sold_out == 1
        ? '<button class="btn btn-sm btn-danger" data-action="toggle-sold-out" data-id="' + t.id + '">品切れ</button>'
        : '<button class="btn btn-sm btn-outline" data-action="toggle-sold-out" data-id="' + t.id + '" style="color:#999;border-color:#ccc">販売中</button>';

      html += '<div class="list-item">'
        + img
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + Utils.escapeHtml(t.name)
        + (t.name_en ? ' <span class="i18n-en">' + Utils.escapeHtml(t.name_en) + '</span>' : '') + '</div>'
        + '<div class="list-item__meta">' + Utils.escapeHtml(t.category_name || '') + '</div>'
        + '</div>'
        + '<span class="list-item__price">' + Utils.formatYen(t.base_price) + '</span>'
        + '<div class="list-item__actions">'
        + soldOutBtn + ' '
        + '<button class="btn btn-sm btn-outline" data-action="edit-template" data-id="' + t.id + '">編集</button> '
        + '<button class="btn btn-sm btn-danger" data-action="delete-template" data-id="' + t.id + '">削除</button>'
        + '</div></div>';
    });
    _container.innerHTML = '<div class="card">' + html + '</div>';
  }

  function filterByCategory(categoryId) {
    _filterCategoryId = categoryId;
    render();
  }

  function refreshCategoryFilter() {
    var sel = document.getElementById('template-category-filter');
    if (!sel) return;
    var cats = CategoryEditor.getCategories();
    var html = '<option value="">すべてのカテゴリ</option>';
    cats.forEach(function (c) {
      html += '<option value="' + c.id + '">' + Utils.escapeHtml(c.name) + '</option>';
    });
    sel.innerHTML = html;
  }

  function buildCategoryOptions(selectedId) {
    var cats = CategoryEditor.getCategories();
    return cats.map(function (c) {
      return '<option value="' + c.id + '"' + (c.id === selectedId ? ' selected' : '') + '>' + Utils.escapeHtml(c.name) + '</option>';
    }).join('');
  }

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'メニューを追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">メニュー名 *</label><input class="form-input" id="tmpl-name"></div>'
      + '<div class="form-group"><label class="form-label">メニュー名（英語）</label><input class="form-input" id="tmpl-name-en"></div>'
      + '<div class="form-group"><label class="form-label">カテゴリ *</label><select class="form-input" id="tmpl-category">' + buildCategoryOptions('') + '</select></div>'
      + '<div class="form-group"><label class="form-label">基本価格（税込）</label><input class="form-input" id="tmpl-price" type="number" value="0"></div>'
      + '<div class="form-group"><label class="form-label">説明</label><textarea class="form-input" id="tmpl-desc"></textarea></div>'
      + '<div class="form-group"><label class="form-label">説明（英語）</label><textarea class="form-input" id="tmpl-desc-en"></textarea></div>'
      + '<div class="form-group"><label class="form-label">画像</label>'
      + '<div class="image-upload" id="tmpl-image-upload"><input type="file" accept="image/*" class="image-upload__input" id="tmpl-image-file">'
      + '<div class="image-upload__label"><strong>クリックして画像を選択</strong></div>'
      + '<img class="image-upload__preview" id="tmpl-image-preview" style="display:none">'
      + '<button type="button" class="image-upload__remove" id="tmpl-image-remove">&times;</button></div>'
      + '<input type="hidden" id="tmpl-image-url" value=""></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-tmpl">保存</button>';
    overlay.classList.add('open');

    setupImageUpload();

    document.getElementById('btn-save-tmpl').onclick = function () {
      var name = document.getElementById('tmpl-name').value.trim();
      var catId = document.getElementById('tmpl-category').value;
      if (!name || !catId) { showToast('メニュー名とカテゴリは必須です', 'error'); return; }
      this.disabled = true;

      AdminApi.createMenuTemplate({
        name: name,
        name_en: document.getElementById('tmpl-name-en').value.trim(),
        category_id: catId,
        base_price: parseInt(document.getElementById('tmpl-price').value, 10) || 0,
        description: document.getElementById('tmpl-desc').value.trim(),
        description_en: document.getElementById('tmpl-desc-en').value.trim(),
        image_url: document.getElementById('tmpl-image-url').value
      }).then(function () {
        CategoryEditor.closeModal();
        showToast('メニューを追加しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-tmpl');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditModal(id) {
    var t = _templates.find(function (x) { return x.id === id; });
    if (!t) return;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'メニューを編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">メニュー名</label><input class="form-input" id="tmpl-name" value="' + Utils.escapeHtml(t.name) + '"></div>'
      + '<div class="form-group"><label class="form-label">メニュー名（英語）</label><input class="form-input" id="tmpl-name-en" value="' + Utils.escapeHtml(t.name_en || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">カテゴリ</label><select class="form-input" id="tmpl-category">' + buildCategoryOptions(t.category_id) + '</select></div>'
      + '<div class="form-group"><label class="form-label">基本価格（税込）</label><input class="form-input" id="tmpl-price" type="number" value="' + (t.base_price || 0) + '"></div>'
      + '<div class="form-group"><label class="form-label">説明</label><textarea class="form-input" id="tmpl-desc">' + Utils.escapeHtml(t.description || '') + '</textarea></div>'
      + '<div class="form-group"><label class="form-label">説明（英語）</label><textarea class="form-input" id="tmpl-desc-en">' + Utils.escapeHtml(t.description_en || '') + '</textarea></div>'
      + '<div class="form-group"><label class="form-label">品切れ</label>'
      + '<label class="toggle"><input type="checkbox" id="tmpl-soldout" ' + (t.is_sold_out == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>'
      + '<div class="form-group"><label class="form-label">画像</label>'
      + '<div class="image-upload' + (t.image_url ? ' image-upload--has-image' : '') + '" id="tmpl-image-upload">'
      + '<input type="file" accept="image/*" class="image-upload__input" id="tmpl-image-file">'
      + '<div class="image-upload__label"><strong>クリックして画像を選択</strong></div>'
      + '<img class="image-upload__preview" id="tmpl-image-preview" src="' + (t.image_url ? '../../' + Utils.escapeHtml(t.image_url) : '') + '" style="' + (t.image_url ? '' : 'display:none') + '">'
      + '<button type="button" class="image-upload__remove" id="tmpl-image-remove">&times;</button></div>'
      + '<input type="hidden" id="tmpl-image-url" value="' + Utils.escapeHtml(t.image_url || '') + '"></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-tmpl">保存</button>';
    overlay.classList.add('open');

    setupImageUpload();

    document.getElementById('btn-save-tmpl').onclick = function () {
      this.disabled = true;
      AdminApi.updateMenuTemplate(id, {
        name: document.getElementById('tmpl-name').value.trim(),
        name_en: document.getElementById('tmpl-name-en').value.trim(),
        category_id: document.getElementById('tmpl-category').value,
        base_price: parseInt(document.getElementById('tmpl-price').value, 10) || 0,
        description: document.getElementById('tmpl-desc').value.trim(),
        description_en: document.getElementById('tmpl-desc-en').value.trim(),
        is_sold_out: document.getElementById('tmpl-soldout').checked,
        image_url: document.getElementById('tmpl-image-url').value
      }).then(function () {
        CategoryEditor.closeModal();
        showToast('メニューを更新しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-tmpl');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDelete(id) {
    var t = _templates.find(function (x) { return x.id === id; });
    if (!t) return;
    if (!confirm('「' + t.name + '」を削除しますか？全店舗のオーバーライドも削除されます。')) return;

    AdminApi.deleteMenuTemplate(id).then(function () {
      showToast('メニューを削除しました', 'success');
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  function setupImageUpload() {
    var uploadArea = document.getElementById('tmpl-image-upload');
    var fileInput = document.getElementById('tmpl-image-file');
    var preview = document.getElementById('tmpl-image-preview');
    var removeBtn = document.getElementById('tmpl-image-remove');
    var urlInput = document.getElementById('tmpl-image-url');

    uploadArea.addEventListener('click', function (e) {
      if (e.target === removeBtn || e.target.closest('.image-upload__remove')) return;
      fileInput.click();
    });

    fileInput.addEventListener('change', function () {
      var file = fileInput.files[0];
      if (!file) return;
      compressAndUpload(file, preview, uploadArea, urlInput);
    });

    removeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      urlInput.value = '';
      preview.style.display = 'none';
      uploadArea.classList.remove('image-upload--has-image');
    });
  }

  function compressAndUpload(file, preview, uploadArea, urlInput) {
    var reader = new FileReader();
    reader.onload = function (e) {
      var img = new Image();
      img.onload = function () {
        var canvas = document.createElement('canvas');
        var maxSize = 800;
        var w = img.width, h = img.height;
        if (w > maxSize || h > maxSize) {
          if (w > h) { h = Math.round(h * maxSize / w); w = maxSize; }
          else { w = Math.round(w * maxSize / h); h = maxSize; }
        }
        canvas.width = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        canvas.toBlob(function (blob) {
          uploadArea.classList.add('image-upload--uploading');
          var compressedFile = new File([blob], 'menu.jpg', { type: 'image/jpeg' });
          AdminApi.uploadImage(compressedFile).then(function (res) {
            if (res.url) {
              urlInput.value = res.url;
              preview.src = '../../' + res.url;
              preview.style.display = '';
              uploadArea.classList.add('image-upload--has-image');
            }
          }).catch(function (err) {
            showToast('画像アップロード失敗: ' + err.message, 'error');
          }).finally(function () {
            uploadArea.classList.remove('image-upload--uploading');
          });
        }, 'image/jpeg', 0.8);
      };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  }

  function toggleSoldOut(id) {
    var t = _templates.find(function (x) { return x.id === id; });
    if (!t) return;
    var newVal = t.is_sold_out == 1 ? false : true;
    AdminApi.updateMenuTemplate(id, { is_sold_out: newVal }).then(function () {
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  return {
    init: init,
    load: load,
    filterByCategory: filterByCategory,
    refreshCategoryFilter: refreshCategoryFilter,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    confirmDelete: confirmDelete,
    toggleSoldOut: toggleSoldOut
  };
})();
