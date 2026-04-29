/**
 * 店舗メニュー管理（manager用）
 */
var MenuOverrideEditor = (function () {
  'use strict';

  var _container = null;
  var _items = [];
  var _isHqLocked = false; // P1-29: enterprise (hq_menu_broadcast=true) では本部マスタフィールドをロック

  function init(container, features) {
    _container = container;
    _isHqLocked = !!(features && features.hq_menu_broadcast === true);
  }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    AdminApi.getMenuOverrides().then(function (res) {
      _items = res.items || [];
      render();
    }).catch(function (err) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  /** 実効価格（オーバーライド優先） */
  function _effectivePrice(item) {
    return item.price !== null ? item.price : item.base_price;
  }

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

  function _buildSelfMenuAttrHtml(prefix, item, disabled, note) {
    var dis = disabled ? ' disabled' : '';
    var prep = item && item.prep_time_min !== null && item.prep_time_min !== undefined ? item.prep_time_min : '';
    return '<div class="form-group"><label class="form-label">セルフメニュー表示' + (note || '') + '</label>'
      + '<div style="display:grid;gap:8px">'
      + '<select class="form-input" id="' + prefix + '-spice-level"' + dis + '>'
      + _buildSpiceOptions(item ? item.spice_level : 0)
      + '</select>'
      + '<div style="display:flex;flex-wrap:wrap;gap:10px;font-size:0.9rem">'
      + '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" id="' + prefix + '-quick-serve"' + dis + (item && item.is_quick_serve == 1 ? ' checked' : '') + '> 早く出る</label>'
      + '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" id="' + prefix + '-vegetarian"' + dis + (item && item.is_vegetarian == 1 ? ' checked' : '') + '> ベジ</label>'
      + '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" id="' + prefix + '-kids-friendly"' + dis + (item && item.is_kids_friendly == 1 ? ' checked' : '') + '> 子ども向け</label>'
      + '</div>'
      + '<input class="form-input" id="' + prefix + '-prep-time-min" type="number" min="1" max="999" placeholder="提供目安分（例: 8）"' + dis + ' value="' + Utils.escapeHtml(String(prep)) + '">'
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
    return '<div style="font-size:0.78rem;color:#6b7280;margin-top:2px">' + Utils.escapeHtml(badges.join(' / ')) + '</div>';
  }

  function render() {
    if (_items.length === 0) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">メニューがありません</p></div>';
      return;
    }

    var html = '<div class="card"><div class="data-table-wrap"><table class="data-table">'
      + '<thead><tr><th style="width:64px">画像</th><th>メニュー名</th><th>カテゴリ</th><th>価格</th><th>非表示</th><th>品切れ</th><th></th></tr></thead><tbody>';

    _items.forEach(function (item) {
      var isHidden = item.is_hidden == 1;
      var badges = '';
      if (isHidden) badges += '<span class="badge badge--hidden">非表示</span> ';

      var imgUrl = item.override_image_url || item.image_url;
      var imgCell = imgUrl
        ? '<td><img class="data-table__image" src="../../' + Utils.escapeHtml(imgUrl) + '" alt=""></td>'
        : '<td><div class="data-table__image--placeholder">&#x1f4f7;</div></td>';

      html += '<tr' + (isHidden ? ' style="opacity:0.5"' : '') + '>'
        + imgCell
        + '<td>' + Utils.escapeHtml(item.name) + ' ' + badges + _buildSelfMenuAttrBadges(item) + '</td>'
        + '<td>' + Utils.escapeHtml(item.category_name) + '</td>'
        + '<td>' + Utils.formatYen(_effectivePrice(item)) + '</td>'
        + '<td>'
        + (isHidden
          ? '<button class="btn btn-sm btn-danger" data-action="toggle-override-hidden" data-id="' + item.template_id + '">非表示</button>'
          : '<button class="btn btn-sm btn-outline" data-action="toggle-override-hidden" data-id="' + item.template_id + '" style="color:#999;border-color:#ccc">表示中</button>')
        + '</td>'
        + '<td>'
        + (item.template_sold_out == 1 ? '<span class="badge badge--sold-out" title="本部品切れ">本部</span> ' : '')
        + (item.override_sold_out == 1
          ? '<button class="btn btn-sm btn-danger" data-action="toggle-override-sold-out" data-id="' + item.template_id + '">品切れ</button>'
          : '<button class="btn btn-sm btn-outline" data-action="toggle-override-sold-out" data-id="' + item.template_id + '" style="color:#999;border-color:#ccc">販売中</button>')
        + '</td>'
        + '<td class="text-right">'
        + '<button class="btn btn-sm btn-outline" data-action="edit-override" data-id="' + item.template_id + '">編集</button>'
        // P1-30: enterprise (hq_menu_broadcast=true) では本部マスタ由来行の削除ボタンを非表示
        // (getMenuOverrides は menu_templates のみ返し store_local_items は混入しないため全件非表示でOK)
        + (_isHqLocked ? '' : ' <button class="btn btn-sm btn-danger" data-action="delete-override-template" data-id="' + item.template_id + '">削除</button>')
        + '</td>'
        + '</tr>';
    });

    html += '</tbody></table></div></div>';
    _container.innerHTML = html;
  }

  // ── 画像アップロードUI共通 ──
  function _ovrImageHtml(existingUrl) {
    var hasImage = existingUrl ? ' image-upload--has-image' : '';
    var previewSrc = existingUrl ? '../../' + Utils.escapeHtml(existingUrl) : '';
    var previewStyle = existingUrl ? '' : 'display:none';
    return '<div class="form-group"><label class="form-label">画像（店舗独自）</label>'
      + '<div class="image-upload' + hasImage + '" id="ovr-image-upload">'
      + '<input type="file" accept="image/*" class="image-upload__input" id="ovr-image-file">'
      + '<div class="image-upload__label"><strong>クリックして画像を選択</strong></div>'
      + '<img class="image-upload__preview" id="ovr-image-preview" src="' + previewSrc + '" style="' + previewStyle + '">'
      + '<button type="button" class="image-upload__remove" id="ovr-image-remove">&times;</button></div>'
      + '<input type="hidden" id="ovr-image-url" value="' + Utils.escapeHtml(existingUrl || '') + '">'
      + '<p style="margin-top:0.25rem;font-size:0.75rem;color:#999;">空欄の場合は本部テンプレートの画像を使用します</p></div>';
  }

  function _setupOvrImageUpload() {
    var uploadArea = document.getElementById('ovr-image-upload');
    var fileInput = document.getElementById('ovr-image-file');
    var preview = document.getElementById('ovr-image-preview');
    var removeBtn = document.getElementById('ovr-image-remove');
    var urlInput = document.getElementById('ovr-image-url');

    uploadArea.addEventListener('click', function (e) {
      if (e.target === removeBtn || e.target.closest('.image-upload__remove')) return;
      fileInput.click();
    });

    fileInput.addEventListener('change', function () {
      var file = fileInput.files[0];
      if (!file) return;
      _compressAndUploadOvr(file, preview, uploadArea, urlInput);
    });

    removeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      urlInput.value = '';
      preview.style.display = 'none';
      uploadArea.classList.remove('image-upload--has-image');
    });
  }

  function _compressAndUploadOvr(file, preview, uploadArea, urlInput) {
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

  // ── オプショングループ選択UI ──
  function _renderOptionGroupsHtml(allGroups, linkedGroupIds, linkedRequired) {
    if (!allGroups || allGroups.length === 0) {
      return '<div class="form-group"><label class="form-label">オプショングループ</label>'
        + '<p style="color:#999;font-size:0.85rem;">オプショングループが未登録です</p></div>';
    }
    var html = '<div class="form-group"><label class="form-label">オプショングループ</label>'
      + '<div class="option-group-list" id="ovr-option-groups">';
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
        + '<input type="checkbox" class="ovr-og-required" data-gid="' + g.id + '"' + (required ? ' checked' : '') + '> 必須'
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
        var reqCb = container.querySelector('.ovr-og-required[data-gid="' + gid + '"]');
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

  function openEditModal(templateId) {
    var item = _items.find(function (x) { return x.template_id === templateId; });
    if (!item) return;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'メニュー編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-ovr" disabled>保存</button>';
    overlay.classList.add('open');

    // オプショングループ一覧 + 紐付け情報を並列fetch
    var pGroups = AdminApi.getOptionGroups().then(function (res) { return res.groups || []; }).catch(function () { return []; });
    var pLinks = AdminApi.getTemplateOptionLinks(templateId).then(function (res) { return res.links || []; }).catch(function () { return []; });

    Promise.all([pGroups, pLinks]).then(function (results) {
      var allGroups = results[0];
      var links = results[1];
      var linkedIds = [];
      var linkedRequired = {};
      links.forEach(function (l) {
        linkedIds.push(l.group_id);
        linkedRequired[l.group_id] = l.is_required == 1;
      });

      // P1-29: enterprise なら本部マスタ系フィールドを disabled、ラベルに注釈
      var dis = _isHqLocked ? ' disabled' : '';
      var hqNote = _isHqLocked ? ' <span style="color:#888;font-size:11px;">（本部マスタ・店舗からは編集不可）</span>' : '';

      overlay.querySelector('.modal__body').innerHTML =
        '<div class="form-group"><label class="form-label">メニュー名' + hqNote + '</label>'
        + '<input class="form-input" id="ovr-name"' + dis + ' value="' + Utils.escapeHtml(item.name) + '"></div>'
        + '<div class="form-group"><label class="form-label">メニュー名（英語）' + hqNote + '</label>'
        + '<input class="form-input" id="ovr-name-en"' + dis + ' value="' + Utils.escapeHtml(item.name_en || '') + '"></div>'
        + '<div class="form-group"><label class="form-label">カテゴリ' + hqNote + '</label>'
        + '<select class="form-input" id="ovr-category"' + dis + '>' + buildCategoryOptions(item.category_id) + '</select></div>'
        + '<div class="form-group"><label class="form-label">価格（税込）</label>'
        + '<input class="form-input" id="ovr-price" type="number" value="' + _effectivePrice(item) + '"></div>'
        + '<div class="form-group"><label class="form-label">非表示（この店舗で表示しない）</label>'
        + '<label class="toggle"><input type="checkbox" id="ovr-hidden" ' + (item.is_hidden == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>'
        + '<div class="form-group"><label class="form-label">品切れ</label>'
        + '<label class="toggle"><input type="checkbox" id="ovr-soldout" ' + (item.override_sold_out == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>'
        + '<div class="form-group"><label class="form-label">カロリー (kcal)' + hqNote + '</label>'
        + '<input class="form-input" id="ovr-calories" type="number" min="0" placeholder="未設定"' + dis + ' value="' + (item.calories != null ? item.calories : '') + '"></div>'
        + '<div class="form-group"><label class="form-label">アレルギー特定原材料' + hqNote + '</label>'
        + _buildAllergenCheckboxes('ovr', item.allergens) + '</div>'
        + _buildSelfMenuAttrHtml('ovr', item, _isHqLocked, hqNote)
        + _ovrImageHtml(item.override_image_url || '')
        + _renderOptionGroupsHtml(allGroups, linkedIds, linkedRequired);

      _setupOvrImageUpload();

      // P1-29: enterprise なら生成済アレルゲンチェックボックスを一括 disabled
      if (_isHqLocked) {
        var allergenBoxes = document.querySelectorAll('#ovr-allergens input[type="checkbox"]');
        for (var ai = 0; ai < allergenBoxes.length; ai++) {
          allergenBoxes[ai].disabled = true;
        }
      }

      document.getElementById('btn-save-ovr').disabled = false;
    });

    document.getElementById('btn-save-ovr').onclick = function () {
      var name = document.getElementById('ovr-name').value.trim();
      if (!name) { showToast('メニュー名は必須です', 'error'); return; }
      var price = parseInt(document.getElementById('ovr-price').value, 10) || 0;
      var imageUrl = document.getElementById('ovr-image-url').value;
      this.disabled = true;

      // P1-29: enterprise なら本部マスタ更新スキップ + calories/allergens を送信しない
      var savePromise;
      if (_isHqLocked) {
        savePromise = AdminApi.updateMenuOverride(templateId, {
          price: price,
          is_hidden: document.getElementById('ovr-hidden').checked,
          is_sold_out: document.getElementById('ovr-soldout').checked,
          image_url: imageUrl || null
        });
      } else {
        // 既存ロジック (standard/pro): 本部マスタ → オーバーライドの順に更新
        var templatePayload = {
          name: name,
          name_en: document.getElementById('ovr-name-en').value.trim(),
          category_id: document.getElementById('ovr-category').value
        };
        var attrs = _collectSelfMenuAttrs('ovr');
        for (var attrKey in attrs) {
          if (attrs.hasOwnProperty(attrKey)) templatePayload[attrKey] = attrs[attrKey];
        }
        savePromise = AdminApi.updateMenuTemplate(templateId, templatePayload).then(function () {
          var caloriesVal = document.getElementById('ovr-calories').value;
          return AdminApi.updateMenuOverride(templateId, {
            price: price,
            is_hidden: document.getElementById('ovr-hidden').checked,
            is_sold_out: document.getElementById('ovr-soldout').checked,
            image_url: imageUrl || null,
            calories: caloriesVal !== '' ? parseInt(caloriesVal, 10) : null,
            allergens: _collectAllergens('ovr')
          });
        });
      }

      savePromise.then(function () {
        // オプショングループ紐付け同期（両プラン共通: 本タスクではスコープ外）
        var groups = _collectOptionGroups('ovr-option-groups');
        return AdminApi.syncTemplateOptionLinks(templateId, groups);
      }).then(function () {
        document.getElementById('admin-modal-overlay').classList.remove('open');
        showToast('メニューを更新しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-ovr');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openAddModal() {
    // P1-29: enterprise では本部マスタへの新規追加を禁止
    if (_isHqLocked) {
      showToast('本部メニューは owner ダッシュボードの「本部メニュー」タブから追加してください', 'error');
      return;
    }
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'メニューを追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-ovr" disabled>保存</button>';
    overlay.classList.add('open');

    AdminApi.getOptionGroups().then(function (res) { return res.groups || []; }).catch(function () { return []; })
    .then(function (allGroups) {
      overlay.querySelector('.modal__body').innerHTML =
        '<div class="form-group"><label class="form-label">メニュー名 *</label>'
        + '<input class="form-input" id="ovr-name"></div>'
        + '<div class="form-group"><label class="form-label">メニュー名（英語）</label>'
        + '<input class="form-input" id="ovr-name-en"></div>'
        + '<div class="form-group"><label class="form-label">カテゴリ *</label>'
        + '<select class="form-input" id="ovr-category">' + buildCategoryOptions('') + '</select></div>'
        + '<div class="form-group"><label class="form-label">価格（税込）</label>'
        + '<input class="form-input" id="ovr-price" type="number" value="0"></div>'
        + _buildSelfMenuAttrHtml('ovr', {}, false, '')
        + _ovrImageHtml('')
        + _renderOptionGroupsHtml(allGroups, [], {});

      _setupOvrImageUpload();
      document.getElementById('btn-save-ovr').disabled = false;
    });

    document.getElementById('btn-save-ovr').onclick = function () {
      var name = document.getElementById('ovr-name').value.trim();
      var catId = document.getElementById('ovr-category').value;
      if (!name || !catId) { showToast('メニュー名とカテゴリは必須です', 'error'); return; }
      this.disabled = true;

      var payload = {
        name: name,
        name_en: document.getElementById('ovr-name-en').value.trim(),
        category_id: catId,
        base_price: parseInt(document.getElementById('ovr-price').value, 10) || 0,
        image_url: document.getElementById('ovr-image-url').value
      };
      var attrs = _collectSelfMenuAttrs('ovr');
      for (var attrKey in attrs) {
        if (attrs.hasOwnProperty(attrKey)) payload[attrKey] = attrs[attrKey];
      }

      AdminApi.createMenuTemplate(payload).then(function (res) {
        // 新規作成後にオプショングループ紐付け
        var groups = _collectOptionGroups('ovr-option-groups');
        if (groups.length > 0 && res && res.id) {
          return AdminApi.syncTemplateOptionLinks(res.id, groups);
        }
      }).then(function () {
        document.getElementById('admin-modal-overlay').classList.remove('open');
        showToast('メニューを追加しました', 'success');
        load();
      }).catch(function (err) {
        showToast(err.message, 'error');
      }).finally(function () {
        var btn = document.getElementById('btn-save-ovr');
        if (btn) btn.disabled = false;
      });
    };
  }

  function toggleSoldOut(templateId) {
    var item = _items.find(function (x) { return x.template_id === templateId; });
    if (!item) return;
    var newVal = item.override_sold_out == 1 ? false : true;
    AdminApi.updateMenuOverride(templateId, { is_sold_out: newVal }).then(function () {
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  function toggleHidden(templateId) {
    var item = _items.find(function (x) { return x.template_id === templateId; });
    if (!item) return;
    var newVal = item.is_hidden == 1 ? false : true;
    AdminApi.updateMenuOverride(templateId, { is_hidden: newVal }).then(function () {
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  function confirmDelete(templateId) {
    var item = _items.find(function (x) { return x.template_id === templateId; });
    if (!item) return;
    if (!confirm('「' + item.name + '」を削除しますか？')) return;
    AdminApi.deleteMenuTemplate(templateId).then(function () {
      showToast('メニューを削除しました', 'success');
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  return {
    init: init,
    load: load,
    openEditModal: openEditModal,
    openAddModal: openAddModal,
    toggleSoldOut: toggleSoldOut,
    toggleHidden: toggleHidden,
    confirmDelete: confirmDelete
  };
})();
