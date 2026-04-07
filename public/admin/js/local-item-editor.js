/**
 * 店舗限定メニュー管理（manager用）
 */
var LocalItemEditor = (function () {
  'use strict';

  var _container = null;
  var _items = [];

  // N-7: アレルゲンチェックボックス生成
  var _allergenList = [
    {key:'egg',label:'卵'},{key:'milk',label:'乳'},{key:'wheat',label:'小麦'},
    {key:'shrimp',label:'えび'},{key:'crab',label:'かに'},{key:'buckwheat',label:'そば'},
    {key:'peanut',label:'落花生'},{key:'almond',label:'アーモンド'},{key:'cashew',label:'カシューナッツ'},
    {key:'walnut',label:'くるみ'},{key:'sesame',label:'ごま'},{key:'salmon',label:'さけ'},
    {key:'mackerel',label:'さば'},{key:'squid',label:'いか'},{key:'abalone',label:'あわび'},
    {key:'ikura',label:'いくら'},{key:'soy',label:'大豆'},{key:'matsutake',label:'まつたけ'},
    {key:'peach',label:'もも'},{key:'apple',label:'りんご'},{key:'kiwi',label:'キウイ'},
    {key:'banana',label:'バナナ'},{key:'orange',label:'オレンジ'},{key:'gelatin',label:'ゼラチン'},
    {key:'chicken',label:'鶏肉'},{key:'beef',label:'牛肉'},{key:'pork',label:'豚肉'}
  ];

  function _buildAllergenCheckboxes(prefix, currentAllergens) {
    var selected = {};
    if (currentAllergens && Array.isArray(currentAllergens)) {
      currentAllergens.forEach(function (a) { selected[a] = true; });
    } else if (typeof currentAllergens === 'string' && currentAllergens) {
      try { var arr = JSON.parse(currentAllergens); if (Array.isArray(arr)) arr.forEach(function (a) { selected[a] = true; }); } catch (e) {}
    }
    var html = '<div class="allergen-check-group" id="' + prefix + '-allergens" style="display:flex;flex-wrap:wrap;gap:6px">';
    _allergenList.forEach(function (al) {
      var checked = selected[al.key] ? ' checked' : '';
      html += '<label style="display:inline-flex;align-items:center;gap:3px;font-size:0.85rem;cursor:pointer">'
        + '<input type="checkbox" value="' + al.key + '"' + checked + '>'
        + '<span>' + al.label + '</span></label>';
    });
    html += '</div>';
    return html;
  }

  function _collectAllergens(prefix) {
    var container = document.getElementById(prefix + '-allergens');
    if (!container) return null;
    var checked = container.querySelectorAll('input:checked');
    var result = [];
    for (var i = 0; i < checked.length; i++) {
      result.push(checked[i].value);
    }
    return result.length > 0 ? result : null;
  }

  function init(container) {
    _container = container;
  }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    AdminApi.getLocalItems().then(function (res) {
      _items = res.items || [];
      render();
    }).catch(function (err) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function render() {
    if (_items.length === 0) {
      _container.innerHTML = '<div class="empty-state"><div class="empty-state__icon">🏠</div><p class="empty-state__text">店舗限定メニューはありません</p></div>';
      return;
    }

    var html = '';
    _items.forEach(function (item) {
      var img = item.image_url
        ? '<img class="list-item__image" src="../../' + Utils.escapeHtml(item.image_url) + '" alt="">'
        : '<div class="list-item__image--placeholder">🏠</div>';
      var soldOutBtn = item.is_sold_out == 1
        ? '<button class="btn btn-sm btn-danger" data-action="toggle-local-sold-out" data-id="' + item.id + '">品切れ</button>'
        : '<button class="btn btn-sm btn-outline" data-action="toggle-local-sold-out" data-id="' + item.id + '" style="color:#999;border-color:#ccc">販売中</button>';

      html += '<div class="list-item">' + img
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + Utils.escapeHtml(item.name) + ' <span class="badge badge--local">限定</span></div>'
        + '<div class="list-item__meta">' + Utils.escapeHtml(item.category_name || '') + '</div></div>'
        + '<span class="list-item__price">' + Utils.formatYen(item.price) + '</span>'
        + '<div class="list-item__actions">'
        + soldOutBtn + ' '
        + '<button class="btn btn-sm btn-outline" data-action="edit-local" data-id="' + item.id + '">編集</button> '
        + '<button class="btn btn-sm btn-danger" data-action="delete-local" data-id="' + item.id + '">削除</button>'
        + '</div></div>';
    });
    _container.innerHTML = '<div class="card">' + html + '</div>';
  }

  // ── オプショングループ選択UI ──
  function _renderOptionGroupsHtml(allGroups, linkedGroupIds, linkedRequired) {
    if (!allGroups || allGroups.length === 0) {
      return '<div class="form-group"><label class="form-label">オプショングループ</label>'
        + '<p style="color:#999;font-size:0.85rem;">オプショングループが未登録です</p></div>';
    }
    var html = '<div class="form-group"><label class="form-label">オプショングループ</label>'
      + '<div class="option-group-list" id="local-option-groups">';
    allGroups.forEach(function (g) {
      var checked = linkedGroupIds.indexOf(g.id) !== -1;
      var required = linkedRequired[g.id] || false;
      var info = g.selection_type + ', ' + g.min_select + '〜' + g.max_select;
      html += '<div class="option-group-list__item">'
        + '<label class="option-group-list__label">'
        + '<input type="checkbox" value="' + g.id + '"' + (checked ? ' checked' : '') + '> '
        + Utils.escapeHtml(g.name) + ' <span class="option-group-list__info">(' + Utils.escapeHtml(info) + ')</span>'
        + '</label>'
        + '<label class="option-group-list__required">'
        + '<input type="checkbox" class="local-og-required" data-gid="' + g.id + '"' + (required ? ' checked' : '') + '> 必須'
        + '</label>'
        + '</div>';
    });
    html += '</div></div>';
    return html;
  }

  function _collectOptionGroups(containerId) {
    var container = document.getElementById(containerId);
    if (!container) return [];
    var groups = [];
    var checkboxes = container.querySelectorAll('input[type="checkbox"][value]');
    for (var i = 0; i < checkboxes.length; i++) {
      if (checkboxes[i].checked) {
        var gid = checkboxes[i].value;
        var reqCb = container.querySelector('.local-og-required[data-gid="' + gid + '"]');
        groups.push({
          group_id: gid,
          is_required: reqCb && reqCb.checked ? 1 : 0
        });
      }
    }
    return groups;
  }

  function buildCategoryOptions(selectedId) {
    var cats = CategoryEditor.getCategories();
    return cats.map(function (c) {
      return '<option value="' + c.id + '"' + (c.id === selectedId ? ' selected' : '') + '>' + Utils.escapeHtml(c.name) + '</option>';
    }).join('');
  }

  // ── 画像アップロードUI共通 ──
  function _imageUploadHtml(existingUrl) {
    var hasImage = existingUrl ? ' image-upload--has-image' : '';
    var previewSrc = existingUrl ? '../../' + Utils.escapeHtml(existingUrl) : '';
    var previewStyle = existingUrl ? '' : 'display:none';
    return '<div class="form-group"><label class="form-label">画像</label>'
      + '<div class="image-upload' + hasImage + '" id="local-image-upload">'
      + '<input type="file" accept="image/*" class="image-upload__input" id="local-image-file">'
      + '<div class="image-upload__label"><strong>クリックして画像を選択</strong></div>'
      + '<img class="image-upload__preview" id="local-image-preview" src="' + previewSrc + '" style="' + previewStyle + '">'
      + '<button type="button" class="image-upload__remove" id="local-image-remove">&times;</button></div>'
      + '<input type="hidden" id="local-image-url" value="' + Utils.escapeHtml(existingUrl || '') + '"></div>';
  }

  function _setupLocalImageUpload() {
    var uploadArea = document.getElementById('local-image-upload');
    var fileInput = document.getElementById('local-image-file');
    var preview = document.getElementById('local-image-preview');
    var removeBtn = document.getElementById('local-image-remove');
    var urlInput = document.getElementById('local-image-url');

    uploadArea.addEventListener('click', function (e) {
      if (e.target === removeBtn || e.target.closest('.image-upload__remove')) return;
      fileInput.click();
    });

    fileInput.addEventListener('change', function () {
      var file = fileInput.files[0];
      if (!file) return;
      _compressAndUploadLocal(file, preview, uploadArea, urlInput);
    });

    removeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      urlInput.value = '';
      preview.style.display = 'none';
      uploadArea.classList.remove('image-upload--has-image');
    });
  }

  function _compressAndUploadLocal(file, preview, uploadArea, urlInput) {
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
          AdminApi.uploadStoreImage(compressedFile).then(function (res) {
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

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = '店舗限定メニューを追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-local" disabled>保存</button>';
    overlay.classList.add('open');

    AdminApi.getOptionGroups().then(function (res) { return res.groups || []; }).catch(function () { return []; })
    .then(function (allGroups) {
      overlay.querySelector('.modal__body').innerHTML =
        '<div class="form-group"><label class="form-label">メニュー名 *</label><input class="form-input" id="local-name"></div>'
        + '<div class="form-group"><label class="form-label">メニュー名（英語）</label><input class="form-input" id="local-name-en"></div>'
        + '<div class="form-group"><label class="form-label">カテゴリ *</label><select class="form-input" id="local-category">' + buildCategoryOptions('') + '</select></div>'
        + '<div class="form-group"><label class="form-label">価格（税込）</label><input class="form-input" id="local-price" type="number" value="0"></div>'
        + '<div class="form-group"><label class="form-label">説明</label><textarea class="form-input" id="local-desc"></textarea></div>'
        + '<div class="form-group"><label class="form-label">カロリー (kcal)</label>'
        + '<input class="form-input" id="local-calories" type="number" min="0" placeholder="未設定"></div>'
        + '<div class="form-group"><label class="form-label">アレルギー特定原材料</label>'
        + _buildAllergenCheckboxes('local', null) + '</div>'
        + _imageUploadHtml('')
        + _renderOptionGroupsHtml(allGroups, [], {});

      _setupLocalImageUpload();
      document.getElementById('btn-save-local').disabled = false;
    });

    document.getElementById('btn-save-local').onclick = function () {
      var name = document.getElementById('local-name').value.trim();
      var catId = document.getElementById('local-category').value;
      if (!name || !catId) { showToast('メニュー名とカテゴリは必須です', 'error'); return; }
      this.disabled = true;
      var addCalVal = document.getElementById('local-calories').value;
      AdminApi.createLocalItem({
        name: name,
        name_en: document.getElementById('local-name-en').value.trim(),
        category_id: catId,
        price: parseInt(document.getElementById('local-price').value, 10) || 0,
        description: document.getElementById('local-desc').value.trim(),
        image_url: document.getElementById('local-image-url').value,
        calories: addCalVal !== '' ? parseInt(addCalVal, 10) : null,
        allergens: _collectAllergens('local')
      }).then(function (res) {
        // 新規作成後にオプショングループ紐付け
        var groups = _collectOptionGroups('local-option-groups');
        if (groups.length > 0 && res && res.id) {
          return AdminApi.syncLocalItemOptionLinks(res.id, groups);
        }
      }).then(function () {
        overlay.classList.remove('open');
        showToast('店舗限定メニューを追加しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-local');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditModal(id) {
    var item = _items.find(function (x) { return x.id === id; });
    if (!item) return;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = '店舗限定メニューを編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-local" disabled>保存</button>';
    overlay.classList.add('open');

    // オプショングループ一覧 + 紐付け情報を並列fetch
    var pGroups = AdminApi.getOptionGroups().then(function (res) { return res.groups || []; }).catch(function () { return []; });
    var pLinks = AdminApi.getLocalItemOptionLinks(id).then(function (res) { return res.links || []; }).catch(function () { return []; });

    Promise.all([pGroups, pLinks]).then(function (results) {
      var allGroups = results[0];
      var links = results[1];
      var linkedIds = [];
      var linkedRequired = {};
      links.forEach(function (l) {
        linkedIds.push(l.group_id);
        linkedRequired[l.group_id] = l.is_required == 1;
      });

      overlay.querySelector('.modal__body').innerHTML =
        '<div class="form-group"><label class="form-label">メニュー名</label><input class="form-input" id="local-name" value="' + Utils.escapeHtml(item.name) + '"></div>'
        + '<div class="form-group"><label class="form-label">メニュー名（英語）</label><input class="form-input" id="local-name-en" value="' + Utils.escapeHtml(item.name_en || '') + '"></div>'
        + '<div class="form-group"><label class="form-label">カテゴリ</label><select class="form-input" id="local-category">' + buildCategoryOptions(item.category_id) + '</select></div>'
        + '<div class="form-group"><label class="form-label">価格（税込）</label><input class="form-input" id="local-price" type="number" value="' + (item.price || 0) + '"></div>'
        + '<div class="form-group"><label class="form-label">品切れ</label>'
        + '<label class="toggle"><input type="checkbox" id="local-soldout" ' + (item.is_sold_out == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>'
        + '<div class="form-group"><label class="form-label">カロリー (kcal)</label>'
        + '<input class="form-input" id="local-calories" type="number" min="0" placeholder="未設定" value="' + (item.calories != null ? item.calories : '') + '"></div>'
        + '<div class="form-group"><label class="form-label">アレルギー特定原材料</label>'
        + _buildAllergenCheckboxes('local', item.allergens) + '</div>'
        + _imageUploadHtml(item.image_url || '')
        + _renderOptionGroupsHtml(allGroups, linkedIds, linkedRequired);

      _setupLocalImageUpload();
      document.getElementById('btn-save-local').disabled = false;
    });

    document.getElementById('btn-save-local').onclick = function () {
      this.disabled = true;
      var editCalVal = document.getElementById('local-calories').value;
      AdminApi.updateLocalItem(id, {
        name: document.getElementById('local-name').value.trim(),
        name_en: document.getElementById('local-name-en').value.trim(),
        category_id: document.getElementById('local-category').value,
        price: parseInt(document.getElementById('local-price').value, 10) || 0,
        is_sold_out: document.getElementById('local-soldout').checked,
        image_url: document.getElementById('local-image-url').value,
        calories: editCalVal !== '' ? parseInt(editCalVal, 10) : null,
        allergens: _collectAllergens('local')
      }).then(function () {
        // オプショングループ紐付け同期
        var groups = _collectOptionGroups('local-option-groups');
        return AdminApi.syncLocalItemOptionLinks(id, groups);
      }).then(function () {
        overlay.classList.remove('open');
        showToast('メニューを更新しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-local');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDelete(id) {
    var item = _items.find(function (x) { return x.id === id; });
    if (!item) return;
    if (!confirm('「' + item.name + '」を削除しますか？')) return;
    AdminApi.deleteLocalItem(id).then(function () {
      showToast('削除しました', 'success');
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  function toggleSoldOut(id) {
    var item = _items.find(function (x) { return x.id === id; });
    if (!item) return;
    var newVal = item.is_sold_out == 1 ? false : true;
    AdminApi.updateLocalItem(id, { is_sold_out: newVal }).then(function () {
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
    confirmDelete: confirmDelete,
    toggleSoldOut: toggleSoldOut
  };
})();
