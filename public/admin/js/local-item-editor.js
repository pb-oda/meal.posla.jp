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

  function _buildSpiceOptions(selectedLevel) {
    var levels = [
      { value: 0, label: 'なし' },
      { value: 1, label: '控えめ' },
      { value: 2, label: '辛い' },
      { value: 3, label: '激辛' }
    ];
    var selected = parseInt(selectedLevel, 10) || 0;
    var html = '';
    levels.forEach(function (level) {
      html += '<option value="' + level.value + '"' + (level.value === selected ? ' selected' : '') + '>' + level.label + '</option>';
    });
    return html;
  }

  function _buildSelfMenuAttrHtml(prefix, item) {
    var prep = item && item.prep_time_min !== null && item.prep_time_min !== undefined ? item.prep_time_min : '';
    return '<div class="form-group"><label class="form-label">セルフメニュー表示</label>'
      + '<div style="display:grid;gap:8px">'
      + '<select class="form-input" id="' + prefix + '-spice-level">'
      + _buildSpiceOptions(item ? item.spice_level : 0)
      + '</select>'
      + '<div style="display:flex;flex-wrap:wrap;gap:10px;font-size:0.9rem">'
      + '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" id="' + prefix + '-quick-serve"' + (item && item.is_quick_serve == 1 ? ' checked' : '') + '> 早く出る</label>'
      + '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" id="' + prefix + '-vegetarian"' + (item && item.is_vegetarian == 1 ? ' checked' : '') + '> ベジ</label>'
      + '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" id="' + prefix + '-kids-friendly"' + (item && item.is_kids_friendly == 1 ? ' checked' : '') + '> 子ども向け</label>'
      + '</div>'
      + '<input class="form-input" id="' + prefix + '-prep-time-min" type="number" min="1" max="999" placeholder="提供目安分（例: 8）" value="' + Utils.escapeHtml(String(prep)) + '">'
      + '</div></div>';
  }

  function _collectSelfMenuAttrs(prefix) {
    var spice = document.getElementById(prefix + '-spice-level');
    var prep = document.getElementById(prefix + '-prep-time-min');
    return {
      spice_level: spice ? (parseInt(spice.value, 10) || 0) : 0,
      is_quick_serve: !!(document.getElementById(prefix + '-quick-serve') && document.getElementById(prefix + '-quick-serve').checked),
      is_vegetarian: !!(document.getElementById(prefix + '-vegetarian') && document.getElementById(prefix + '-vegetarian').checked),
      is_kids_friendly: !!(document.getElementById(prefix + '-kids-friendly') && document.getElementById(prefix + '-kids-friendly').checked),
      prep_time_min: prep && prep.value !== '' ? (parseInt(prep.value, 10) || null) : null
    };
  }

  function _buildSelfMenuAttrBadges(item) {
    var badges = [];
    if (item.is_quick_serve == 1) badges.push('早出し');
    if (parseInt(item.spice_level, 10) > 0) badges.push('辛さ' + parseInt(item.spice_level, 10));
    if (item.is_vegetarian == 1) badges.push('ベジ');
    if (item.is_kids_friendly == 1) badges.push('子ども');
    if (item.prep_time_min !== null && item.prep_time_min !== undefined && item.prep_time_min !== '') badges.push('約' + parseInt(item.prep_time_min, 10) + '分');
    if (!badges.length) return '';
    return '<div class="list-item__meta">' + Utils.escapeHtml(badges.join(' / ')) + '</div>';
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
        + '<div class="list-item__meta">' + Utils.escapeHtml(item.category_name || '') + '</div>'
        + _buildSelfMenuAttrBadges(item) + '</div>'
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
        + '<div class="form-group"><label class="form-label">説明（英語）</label><textarea class="form-input" id="local-desc-en"></textarea></div>'
        + '<div class="form-group"><label class="form-label">カロリー (kcal)</label>'
        + '<input class="form-input" id="local-calories" type="number" min="0" placeholder="未設定"></div>'
        + '<div class="form-group"><label class="form-label">アレルギー特定原材料</label>'
        + _buildAllergenCheckboxes('local', null) + '</div>'
        + _buildSelfMenuAttrHtml('local', {})
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
      var payload = {
        name: name,
        name_en: document.getElementById('local-name-en').value.trim(),
        category_id: catId,
        price: parseInt(document.getElementById('local-price').value, 10) || 0,
        description: document.getElementById('local-desc').value.trim(),
        description_en: document.getElementById('local-desc-en').value.trim(),
        image_url: document.getElementById('local-image-url').value,
        calories: addCalVal !== '' ? parseInt(addCalVal, 10) : null,
        allergens: _collectAllergens('local')
      };
      var attrs = _collectSelfMenuAttrs('local');
      for (var attrKey in attrs) {
        if (attrs.hasOwnProperty(attrKey)) payload[attrKey] = attrs[attrKey];
      }

      AdminApi.createLocalItem(payload).then(function (res) {
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
        + '<div class="form-group"><label class="form-label">説明</label><textarea class="form-input" id="local-desc">' + Utils.escapeHtml(item.description || '') + '</textarea></div>'
        + '<div class="form-group"><label class="form-label">説明（英語）</label><textarea class="form-input" id="local-desc-en">' + Utils.escapeHtml(item.description_en || '') + '</textarea></div>'
        + '<div class="form-group"><label class="form-label">品切れ</label>'
        + '<label class="toggle"><input type="checkbox" id="local-soldout" ' + (item.is_sold_out == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>'
        + '<div class="form-group"><label class="form-label">カロリー (kcal)</label>'
        + '<input class="form-input" id="local-calories" type="number" min="0" placeholder="未設定" value="' + (item.calories != null ? item.calories : '') + '"></div>'
        + '<div class="form-group"><label class="form-label">アレルギー特定原材料</label>'
        + _buildAllergenCheckboxes('local', item.allergens) + '</div>'
        + _buildSelfMenuAttrHtml('local', item)
        + _imageUploadHtml(item.image_url || '')
        + _renderOptionGroupsHtml(allGroups, linkedIds, linkedRequired);

      _setupLocalImageUpload();
      document.getElementById('btn-save-local').disabled = false;
    });

    document.getElementById('btn-save-local').onclick = function () {
      this.disabled = true;
      var editCalVal = document.getElementById('local-calories').value;
      var payload = {
        name: document.getElementById('local-name').value.trim(),
        name_en: document.getElementById('local-name-en').value.trim(),
        category_id: document.getElementById('local-category').value,
        price: parseInt(document.getElementById('local-price').value, 10) || 0,
        description: document.getElementById('local-desc').value.trim(),
        description_en: document.getElementById('local-desc-en').value.trim(),
        is_sold_out: document.getElementById('local-soldout').checked,
        image_url: document.getElementById('local-image-url').value,
        calories: editCalVal !== '' ? parseInt(editCalVal, 10) : null,
        allergens: _collectAllergens('local')
      };
      var attrs = _collectSelfMenuAttrs('local');
      for (var attrKey in attrs) {
        if (attrs.hasOwnProperty(attrKey)) payload[attrKey] = attrs[attrKey];
      }

      AdminApi.updateLocalItem(id, payload).then(function () {
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
