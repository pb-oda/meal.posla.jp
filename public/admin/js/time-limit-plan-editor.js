/**
 * 食べ放題・時間制限プラン管理
 */
var TimeLimitPlanEditor = (function () {
  'use strict';

  var _listEl = null;
  var _countEl = null;
  var _plans = [];
  var _expandedPlanId = null; // 現在メニュー展開中のプランID
  var _planMenuItems = [];    // 展開中プランのメニュー品目
  var _categories = [];       // カテゴリ一覧（キャッシュ）

  function init(listEl, countEl) {
    _listEl = listEl;
    _countEl = countEl;
  }

  function load() {
    _listEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span>読み込み中...</div>';
    AdminApi.getTimeLimitPlans().then(function (data) {
      _plans = data.plans || [];
      _render();
    }).catch(function (err) {
      _listEl.innerHTML = '<div class="empty-state"><div class="empty-state__text">読み込みエラー: ' + _esc(err.message) + '</div></div>';
    });
  }

  function _render() {
    if (_countEl) _countEl.textContent = '(' + _plans.length + '件)';

    if (_plans.length === 0) {
      _listEl.innerHTML = '<div class="card"><div class="empty-state"><div class="empty-state__text">プランが登録されていません</div></div></div>';
      return;
    }

    var html = '<div class="card">';
    _plans.forEach(function (p) {
      var activeLabel = parseInt(p.is_active) ? '<span class="badge badge--available">有効</span>' : '<span class="badge badge--hidden">無効</span>';
      var isExpanded = _expandedPlanId === p.id;
      html += '<div class="list-item' + (isExpanded ? ' list-item--expanded' : '') + '">'
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + _esc(p.name)
        + (p.name_en ? '<span class="i18n-en">' + _esc(p.name_en) + '</span>' : '')
        + '</div>'
        + '<div class="list-item__meta">'
        + p.duration_min + '分 / LO ' + p.last_order_min + '分前 ' + activeLabel
        + '</div>'
        + '</div>'
        + '<div class="list-item__price">&yen;' + Number(p.price).toLocaleString() + '</div>'
        + '<div class="list-item__actions">'
        + '<button class="btn btn-sm btn-outline" data-action="toggle-plan-menu" data-id="' + p.id + '">'
        + (isExpanded ? '閉じる' : 'メニュー管理') + '</button>'
        + '<button class="btn btn-sm btn-outline" data-action="edit-plan" data-id="' + p.id + '">編集</button>'
        + '<button class="btn btn-sm btn-danger" data-action="delete-plan" data-id="' + p.id + '">削除</button>'
        + '</div></div>';

      // メニュー展開エリア
      if (isExpanded) {
        html += _renderPlanMenuSection(p.id);
      }
    });
    html += '</div>';
    _listEl.innerHTML = html;
  }

  function openAddModal() {
    _openModal(null);
  }

  function openEditModal(id) {
    var plan = _plans.find(function (p) { return p.id === id; });
    if (!plan) return;
    _openModal(plan);
  }

  function _openModal(plan) {
    var isEdit = !!plan;
    var title = isEdit ? 'プラン編集' : 'プラン追加';

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = title;

    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group">'
      + '<label class="form-label">プラン名 *</label>'
      + '<input class="form-input" id="modal-plan-name" value="' + _esc(isEdit ? plan.name : '') + '">'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">プラン名（英語）</label>'
      + '<input class="form-input" id="modal-plan-name-en" value="' + _esc(isEdit ? plan.name_en : '') + '">'
      + '</div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">制限時間（分） *</label>'
      + '<input class="form-input" type="number" id="modal-plan-duration" min="1" value="' + (isEdit ? plan.duration_min : '90') + '">'
      + '</div>'
      + '<div class="form-group"><label class="form-label">LO（終了N分前）</label>'
      + '<input class="form-input" type="number" id="modal-plan-lo" min="0" value="' + (isEdit ? plan.last_order_min : '15') + '">'
      + '</div></div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">料金（税込）</label>'
      + '<input class="form-input" type="number" id="modal-plan-price" min="0" value="' + (isEdit ? plan.price : '0') + '">'
      + '</div>'
      + '<div class="form-group"><label class="form-label">表示順</label>'
      + '<input class="form-input" type="number" id="modal-plan-sort" value="' + (isEdit ? plan.sort_order : '0') + '">'
      + '</div></div>'
      + '<div class="form-group">'
      + '<label class="form-label">説明</label>'
      + '<textarea class="form-input" id="modal-plan-desc" rows="2">' + _esc(isEdit ? (plan.description || '') : '') + '</textarea>'
      + '</div>'
      + (isEdit ?
        '<div class="form-group"><label class="form-label">有効</label>'
        + '<select class="form-input" id="modal-plan-active">'
        + '<option value="1"' + (parseInt(plan.is_active) ? ' selected' : '') + '>有効</option>'
        + '<option value="0"' + (!parseInt(plan.is_active) ? ' selected' : '') + '>無効</option>'
        + '</select></div>' : '');

    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="modal-plan-save">' + (isEdit ? '更新' : '追加') + '</button>';

    overlay.classList.add('open');

    document.getElementById('modal-plan-save').addEventListener('click', function () {
      var body = {
        name: document.getElementById('modal-plan-name').value.trim(),
        name_en: document.getElementById('modal-plan-name-en').value.trim(),
        duration_min: parseInt(document.getElementById('modal-plan-duration').value, 10),
        last_order_min: parseInt(document.getElementById('modal-plan-lo').value, 10),
        price: parseInt(document.getElementById('modal-plan-price').value, 10) || 0,
        sort_order: parseInt(document.getElementById('modal-plan-sort').value, 10) || 0,
        description: document.getElementById('modal-plan-desc').value.trim()
      };

      if (!body.name || !body.duration_min) {
        showToast('プラン名と制限時間は必須です', 'error');
        return;
      }

      if (isEdit) {
        var activeEl = document.getElementById('modal-plan-active');
        if (activeEl) body.is_active = parseInt(activeEl.value, 10);
        AdminApi.updateTimeLimitPlan(plan.id, body).then(function () {
          overlay.classList.remove('open');
          showToast('プランを更新しました', 'success');
          load();
        }).catch(function (err) { showToast(err.message, 'error'); });
      } else {
        AdminApi.createTimeLimitPlan(body).then(function () {
          overlay.classList.remove('open');
          showToast('プランを追加しました', 'success');
          load();
        }).catch(function (err) { showToast(err.message, 'error'); });
      }
    });
  }

  function confirmDelete(id) {
    var plan = _plans.find(function (p) { return p.id === id; });
    if (!plan) return;
    if (!confirm('「' + plan.name + '」を削除しますか？')) return;

    AdminApi.deleteTimeLimitPlan(id).then(function () {
      showToast('プランを削除しました', 'success');
      load();
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  function getPlans() {
    return _plans;
  }

  // ============================================================
  // プラン専用メニュー管理
  // ============================================================

  function togglePlanMenu(planId) {
    if (_expandedPlanId === planId) {
      _expandedPlanId = null;
      _planMenuItems = [];
      _render();
    } else {
      _expandedPlanId = planId;
      _loadPlanMenuItems(planId);
    }
  }

  function _loadPlanMenuItems(planId) {
    AdminApi.getPlanMenuItems(planId).then(function (data) {
      _planMenuItems = data.items || [];
      _render();
    }).catch(function (err) {
      showToast('メニュー読み込みエラー: ' + err.message, 'error');
    });
  }

  function _renderPlanMenuSection(planId) {
    var html = '<div class="plan-menu-section" style="padding:0.75rem 1rem 1rem;background:#f8f9fa;border-top:1px solid #e0e0e0;">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">'
      + '<strong style="font-size:0.875rem;">専用メニュー (' + _planMenuItems.length + '品)</strong>'
      + '<button class="btn btn-sm btn-primary" data-action="add-plan-menu" data-plan="' + planId + '">+ 品目追加</button>'
      + '</div>';

    if (_planMenuItems.length === 0) {
      html += '<div style="text-align:center;color:#999;padding:1rem;font-size:0.875rem;">メニューが登録されていません</div>';
    } else {
      _planMenuItems.forEach(function (item) {
        var catLabel = item.category_name ? '<span style="color:#666;font-size:0.75rem;margin-left:0.5rem;">[' + _esc(item.category_name) + ']</span>' : '';
        var activeLabel = parseInt(item.is_active)
          ? ''
          : '<span class="badge badge--hidden" style="margin-left:0.25rem;">非表示</span>';
        html += '<div class="list-item" style="padding:0.5rem 0;border-bottom:1px solid #eee;">'
          + '<div class="list-item__body">'
          + '<div class="list-item__name" style="font-size:0.875rem;">'
          + _esc(item.name)
          + (item.name_en ? '<span class="i18n-en">' + _esc(item.name_en) + '</span>' : '')
          + catLabel + activeLabel
          + '</div>'
          + '</div>'
          + '<div class="list-item__actions">'
          + '<button class="btn btn-sm btn-outline" data-action="edit-plan-menu" data-id="' + item.id + '" data-plan="' + planId + '">編集</button>'
          + '<button class="btn btn-sm btn-danger" data-action="delete-plan-menu" data-id="' + item.id + '" data-plan="' + planId + '">削除</button>'
          + '</div></div>';
      });
    }
    html += '</div>';
    return html;
  }

  function _ensureCategories() {
    if (_categories.length > 0) return Promise.resolve(_categories);
    return AdminApi.getCategories().then(function (data) {
      _categories = data.categories || [];
      return _categories;
    });
  }

  function openAddPlanMenuModal(planId) {
    _ensureCategories().then(function (cats) {
      _openPlanMenuModal(planId, null, cats);
    });
  }

  function openEditPlanMenuModal(itemId, planId) {
    var item = _planMenuItems.find(function (m) { return m.id === itemId; });
    if (!item) return;
    _ensureCategories().then(function (cats) {
      _openPlanMenuModal(planId, item, cats);
    });
  }

  function _openPlanMenuModal(planId, item, cats) {
    var isEdit = !!item;
    var title = isEdit ? 'プランメニュー編集' : 'プランメニュー追加';

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = title;

    var catOptions = '<option value="">カテゴリなし</option>';
    cats.forEach(function (c) {
      var sel = isEdit && item.category_id === c.id ? ' selected' : '';
      catOptions += '<option value="' + c.id + '"' + sel + '>' + _esc(c.name) + '</option>';
    });

    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group">'
      + '<label class="form-label">品目名 *</label>'
      + '<input class="form-input" id="modal-pm-name" value="' + _esc(isEdit ? item.name : '') + '">'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">品目名（英語）</label>'
      + '<input class="form-input" id="modal-pm-name-en" value="' + _esc(isEdit ? item.name_en : '') + '">'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">カテゴリ</label>'
      + '<select class="form-input" id="modal-pm-category">' + catOptions + '</select>'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">説明</label>'
      + '<textarea class="form-input" id="modal-pm-desc" rows="2">' + _esc(isEdit ? (item.description || '') : '') + '</textarea>'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">表示順</label>'
      + '<input class="form-input" type="number" id="modal-pm-sort" value="' + (isEdit ? item.sort_order : '0') + '">'
      + '</div>'
      + (isEdit ?
        '<div class="form-group"><label class="form-label">表示</label>'
        + '<select class="form-input" id="modal-pm-active">'
        + '<option value="1"' + (parseInt(item.is_active) ? ' selected' : '') + '>有効</option>'
        + '<option value="0"' + (!parseInt(item.is_active) ? ' selected' : '') + '>無効</option>'
        + '</select></div>' : '');

    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="modal-pm-save">' + (isEdit ? '更新' : '追加') + '</button>';

    overlay.classList.add('open');

    document.getElementById('modal-pm-save').addEventListener('click', function () {
      var body = {
        plan_id: planId,
        name: document.getElementById('modal-pm-name').value.trim(),
        name_en: document.getElementById('modal-pm-name-en').value.trim(),
        category_id: document.getElementById('modal-pm-category').value || null,
        description: document.getElementById('modal-pm-desc').value.trim(),
        sort_order: parseInt(document.getElementById('modal-pm-sort').value, 10) || 0
      };

      if (!body.name) {
        showToast('品目名は必須です', 'error');
        return;
      }

      if (isEdit) {
        var activeEl = document.getElementById('modal-pm-active');
        if (activeEl) body.is_active = parseInt(activeEl.value, 10);
        AdminApi.updatePlanMenuItem(item.id, body).then(function () {
          overlay.classList.remove('open');
          showToast('メニューを更新しました', 'success');
          _loadPlanMenuItems(planId);
        }).catch(function (err) { showToast(err.message, 'error'); });
      } else {
        AdminApi.createPlanMenuItem(body).then(function () {
          overlay.classList.remove('open');
          showToast('メニューを追加しました', 'success');
          _loadPlanMenuItems(planId);
        }).catch(function (err) { showToast(err.message, 'error'); });
      }
    });
  }

  function confirmDeletePlanMenu(itemId, planId) {
    var item = _planMenuItems.find(function (m) { return m.id === itemId; });
    if (!item) return;
    if (!confirm('「' + item.name + '」を削除しますか？')) return;

    AdminApi.deletePlanMenuItem(itemId).then(function () {
      showToast('メニューを削除しました', 'success');
      _loadPlanMenuItems(planId);
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  function _esc(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  return {
    init: init,
    load: load,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    confirmDelete: confirmDelete,
    getPlans: getPlans,
    togglePlanMenu: togglePlanMenu,
    openAddPlanMenuModal: openAddPlanMenuModal,
    openEditPlanMenuModal: openEditPlanMenuModal,
    confirmDeletePlanMenu: confirmDeletePlanMenu
  };
})();
