/**
 * オプショングループ管理（owner専用）
 * グループ一覧 + 選択肢の追加/編集/削除
 */
var OptionGroupEditor = (function () {
  'use strict';

  var _listEl = null;
  var _countEl = null;
  var _groups = [];

  function init(listEl, countEl) {
    _listEl = listEl;
    _countEl = countEl;
  }

  function load() {
    _listEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';
    AdminApi.getOptionGroups().then(function (res) {
      _groups = res.groups || [];
      render();
    }).catch(function (err) {
      _listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function render() {
    if (_countEl) _countEl.textContent = '(' + _groups.length + ')';
    if (_groups.length === 0) {
      _listEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">オプショングループがありません</p></div>';
      return;
    }

    var html = '';
    _groups.forEach(function (g) {
      var typeBadge = g.selection_type === 'multi'
        ? '<span class="badge badge--info">複数選択</span>'
        : '<span class="badge badge--available">単一選択</span>';
      var activeBadge = g.is_active == 1 ? '' : ' <span class="badge badge--sold-out">無効</span>';
      var linkedBadge = g.linkedMenuCount
        ? '<span class="badge">' + g.linkedMenuCount + 'メニュー</span>'
        : '';

      // 選択肢一覧
      var choicesHtml = '';
      if (g.choices && g.choices.length > 0) {
        choicesHtml = '<div class="og-choices">';
        g.choices.forEach(function (c) {
          var diffStr = c.price_diff > 0 ? '+' + c.price_diff + '円' : (c.price_diff < 0 ? c.price_diff + '円' : '±0');
          var defMark = c.is_default == 1 ? '<span class="badge badge--available" style="font-size:0.625rem">既定</span>' : '';
          choicesHtml += '<div class="og-choice">'
            + '<span class="og-choice__name">' + Utils.escapeHtml(c.name)
            + (c.name_en ? ' <span style="color:#999;font-size:0.75rem">(' + Utils.escapeHtml(c.name_en) + ')</span>' : '')
            + ' ' + defMark + '</span>'
            + '<span class="og-choice__price">' + diffStr + '</span>'
            + '<button class="btn btn-sm btn-outline" data-action="edit-choice" data-id="' + c.id + '" data-group="' + g.id + '">編集</button>'
            + '<button class="btn btn-sm btn-danger" data-action="delete-choice" data-id="' + c.id + '">削除</button>'
            + '</div>';
        });
        choicesHtml += '</div>';
      } else {
        choicesHtml = '<div class="og-choices"><p style="color:#999;font-size:0.8125rem;padding:0.5rem">選択肢なし</p></div>';
      }

      html += '<div class="card" style="margin-bottom:1rem">'
        + '<div class="list-item" style="border-bottom:1px solid #eee">'
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + Utils.escapeHtml(g.name)
        + (g.name_en ? ' <span style="color:#999;font-size:0.8125rem">(' + Utils.escapeHtml(g.name_en) + ')</span>' : '')
        + ' ' + typeBadge + activeBadge + ' ' + linkedBadge + '</div>'
        + '<div class="list-item__meta">選択: ' + g.min_select + '〜' + g.max_select + '</div>'
        + '</div>'
        + '<div class="list-item__actions">'
        + '<button class="btn btn-sm btn-outline" data-action="add-choice" data-group="' + g.id + '">+ 選択肢</button>'
        + '<button class="btn btn-sm btn-outline" data-action="edit-group" data-id="' + g.id + '">編集</button>'
        + '<button class="btn btn-sm btn-danger" data-action="delete-group" data-id="' + g.id + '">削除</button>'
        + '</div></div>'
        + choicesHtml
        + '</div>';
    });
    _listEl.innerHTML = html;
  }

  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'オプショングループ追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">グループ名 *</label><input class="form-input" id="og-name" placeholder="ごはんの量"></div>'
      + '<div class="form-group"><label class="form-label">English</label><input class="form-input" id="og-name-en" placeholder="Rice Size"></div>'
      + '<div class="form-group"><label class="form-label">選択タイプ</label>'
      + '<select class="form-input" id="og-type"><option value="single">単一選択（ラジオ）</option><option value="multi">複数選択（チェック）</option></select></div>'
      + '<div class="form-group"><label class="form-label">最小選択数</label><input class="form-input" id="og-min" type="number" value="1" min="0"></div>'
      + '<div class="form-group"><label class="form-label">最大選択数</label><input class="form-input" id="og-max" type="number" value="1" min="1"></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-og">保存</button>';
    overlay.classList.add('open');

    // 単一↔複数切替でmin/max自動変更
    document.getElementById('og-type').addEventListener('change', function () {
      if (this.value === 'single') {
        document.getElementById('og-min').value = 1;
        document.getElementById('og-max').value = 1;
      } else {
        document.getElementById('og-min').value = 0;
        document.getElementById('og-max').value = 3;
      }
    });

    document.getElementById('btn-save-og').onclick = function () {
      var name = document.getElementById('og-name').value.trim();
      if (!name) { showToast('グループ名は必須です', 'error'); return; }
      this.disabled = true;
      AdminApi.createOptionGroup({
        name: name,
        name_en: document.getElementById('og-name-en').value.trim(),
        selection_type: document.getElementById('og-type').value,
        min_select: parseInt(document.getElementById('og-min').value, 10),
        max_select: parseInt(document.getElementById('og-max').value, 10)
      }).then(function () {
        overlay.classList.remove('open');
        showToast('グループを追加しました', 'success');
        load();
      }).catch(function (err) { showToast(err.message, 'error'); })
      .finally(function () {
        var btn = document.getElementById('btn-save-og');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditModal(id) {
    var g = _groups.find(function (x) { return x.id === id; });
    if (!g) return;
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'グループ編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">グループ名</label><input class="form-input" id="og-name" value="' + Utils.escapeHtml(g.name) + '"></div>'
      + '<div class="form-group"><label class="form-label">English</label><input class="form-input" id="og-name-en" value="' + Utils.escapeHtml(g.name_en || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">選択タイプ</label>'
      + '<select class="form-input" id="og-type"><option value="single"' + (g.selection_type === 'single' ? ' selected' : '') + '>単一選択（ラジオ）</option>'
      + '<option value="multi"' + (g.selection_type === 'multi' ? ' selected' : '') + '>複数選択（チェック）</option></select></div>'
      + '<div class="form-group"><label class="form-label">最小選択数</label><input class="form-input" id="og-min" type="number" value="' + (g.min_select || 0) + '" min="0"></div>'
      + '<div class="form-group"><label class="form-label">最大選択数</label><input class="form-input" id="og-max" type="number" value="' + (g.max_select || 1) + '" min="1"></div>'
      + '<div class="form-group"><label class="form-label">有効</label>'
      + '<label class="toggle"><input type="checkbox" id="og-active" ' + (g.is_active == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-og">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-og').onclick = function () {
      this.disabled = true;
      AdminApi.updateOptionGroup(id, {
        name: document.getElementById('og-name').value.trim(),
        name_en: document.getElementById('og-name-en').value.trim(),
        selection_type: document.getElementById('og-type').value,
        min_select: parseInt(document.getElementById('og-min').value, 10),
        max_select: parseInt(document.getElementById('og-max').value, 10),
        is_active: document.getElementById('og-active').checked
      }).then(function () {
        overlay.classList.remove('open');
        showToast('グループを更新しました', 'success');
        load();
      }).catch(function (err) { showToast(err.message, 'error'); })
      .finally(function () {
        var btn = document.getElementById('btn-save-og');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDeleteGroup(id) {
    var g = _groups.find(function (x) { return x.id === id; });
    if (!g) return;
    if (!confirm('「' + g.name + '」を削除しますか？\n（選択肢もすべて削除されます）')) return;
    AdminApi.deleteOptionGroup(id).then(function () {
      showToast('グループを削除しました', 'success');
      load();
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  function openAddChoiceModal(groupId) {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = '選択肢を追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">選択肢名 *</label><input class="form-input" id="oc-name" placeholder="大盛り"></div>'
      + '<div class="form-group"><label class="form-label">English</label><input class="form-input" id="oc-name-en" placeholder="Large"></div>'
      + '<div class="form-group"><label class="form-label">価格差（円）</label><input class="form-input" id="oc-price" type="number" value="0"></div>'
      + '<div class="form-group"><label class="form-label">デフォルト</label>'
      + '<label class="toggle"><input type="checkbox" id="oc-default"><span class="toggle__slider"></span></label></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-oc">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-oc').onclick = function () {
      var name = document.getElementById('oc-name').value.trim();
      if (!name) { showToast('選択肢名は必須です', 'error'); return; }
      this.disabled = true;
      AdminApi.addOptionChoice({
        group_id: groupId,
        name: name,
        name_en: document.getElementById('oc-name-en').value.trim(),
        price_diff: parseInt(document.getElementById('oc-price').value, 10) || 0,
        is_default: document.getElementById('oc-default').checked
      }).then(function () {
        overlay.classList.remove('open');
        showToast('選択肢を追加しました', 'success');
        load();
      }).catch(function (err) { showToast(err.message, 'error'); })
      .finally(function () {
        var btn = document.getElementById('btn-save-oc');
        if (btn) btn.disabled = false;
      });
    };
  }

  function openEditChoiceModal(choiceId, groupId) {
    var g = _groups.find(function (x) { return x.id === groupId; });
    if (!g) return;
    var c = (g.choices || []).find(function (x) { return x.id === choiceId; });
    if (!c) return;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = '選択肢を編集';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">選択肢名</label><input class="form-input" id="oc-name" value="' + Utils.escapeHtml(c.name) + '"></div>'
      + '<div class="form-group"><label class="form-label">English</label><input class="form-input" id="oc-name-en" value="' + Utils.escapeHtml(c.name_en || '') + '"></div>'
      + '<div class="form-group"><label class="form-label">価格差（円）</label><input class="form-input" id="oc-price" type="number" value="' + (c.price_diff || 0) + '"></div>'
      + '<div class="form-group"><label class="form-label">デフォルト</label>'
      + '<label class="toggle"><input type="checkbox" id="oc-default" ' + (c.is_default == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-oc">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-oc').onclick = function () {
      this.disabled = true;
      AdminApi.updateOptionChoice(choiceId, {
        name: document.getElementById('oc-name').value.trim(),
        name_en: document.getElementById('oc-name-en').value.trim(),
        price_diff: parseInt(document.getElementById('oc-price').value, 10) || 0,
        is_default: document.getElementById('oc-default').checked
      }).then(function () {
        overlay.classList.remove('open');
        showToast('選択肢を更新しました', 'success');
        load();
      }).catch(function (err) { showToast(err.message, 'error'); })
      .finally(function () {
        var btn = document.getElementById('btn-save-oc');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDeleteChoice(choiceId) {
    if (!confirm('この選択肢を削除しますか？')) return;
    AdminApi.deleteOptionChoice(choiceId).then(function () {
      showToast('選択肢を削除しました', 'success');
      load();
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  return {
    init: init,
    load: load,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    confirmDeleteGroup: confirmDeleteGroup,
    openAddChoiceModal: openAddChoiceModal,
    openEditChoiceModal: openEditChoiceModal,
    confirmDeleteChoice: confirmDeleteChoice
  };
})();
