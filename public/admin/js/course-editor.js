/**
 * コース料理テンプレート管理
 *
 * コースCRUD + フェーズ（前菜→メイン→デザート等）の追加・編集・削除。
 */
var CourseEditor = (function () {
  'use strict';

  var _listEl = null;
  var _countEl = null;
  var _courses = [];

  function init(listEl, countEl) {
    _listEl = listEl;
    _countEl = countEl;
  }

  function load() {
    _listEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span>読み込み中...</div>';
    AdminApi.getCourseTemplates().then(function (data) {
      _courses = data.courses || [];
      _render();
    }).catch(function (err) {
      _listEl.innerHTML = '<div class="empty-state"><div class="empty-state__text">読み込みエラー: ' + _esc(err.message) + '</div></div>';
    });
  }

  function _render() {
    if (_countEl) _countEl.textContent = '(' + _courses.length + '件)';

    if (_courses.length === 0) {
      _listEl.innerHTML = '<div class="card"><div class="empty-state"><div class="empty-state__text">コースが登録されていません</div></div></div>';
      return;
    }

    var html = '';
    _courses.forEach(function (c) {
      var activeLabel = c.isActive ? '<span class="badge badge--available">有効</span>' : '<span class="badge badge--hidden">無効</span>';

      html += '<div class="card" style="margin-bottom:1rem">';
      // コースヘッダー
      html += '<div class="list-item">'
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + _esc(c.name)
        + (c.nameEn ? '<span class="i18n-en">' + _esc(c.nameEn) + '</span>' : '')
        + '</div>'
        + '<div class="list-item__meta">' + c.phaseCount + 'フェーズ ' + activeLabel + '</div>'
        + (c.description ? '<div class="list-item__meta">' + _esc(c.description) + '</div>' : '')
        + '</div>'
        + '<div class="list-item__price">&yen;' + Number(c.price).toLocaleString() + '</div>'
        + '<div class="list-item__actions">'
        + '<button class="btn btn-sm btn-outline" data-action="edit-course" data-id="' + c.id + '">編集</button>'
        + '<button class="btn btn-sm btn-danger" data-action="delete-course" data-id="' + c.id + '">削除</button>'
        + '</div></div>';

      // フェーズ一覧
      if (c.phases && c.phases.length > 0) {
        html += '<div class="course-phases">';
        c.phases.forEach(function (p, idx) {
          var itemsSummary = (p.items || []).map(function (it) {
            return _esc(it.name) + (it.qty > 1 ? ' ×' + it.qty : '');
          }).join('、');
          var fireLabel = p.autoFireMin !== null ? '<span class="badge badge--info">自動 ' + p.autoFireMin + '分後</span>' : '<span class="badge badge--override">手動</span>';

          html += '<div class="course-phase">'
            + '<span class="course-phase__num">' + p.phaseNumber + '</span>'
            + '<div class="course-phase__body">'
            + '<div class="course-phase__name">' + _esc(p.name)
            + (p.nameEn ? ' <span class="i18n-en" style="display:inline">' + _esc(p.nameEn) + '</span>' : '')
            + ' ' + fireLabel + '</div>'
            + (itemsSummary ? '<div class="course-phase__items">' + itemsSummary + '</div>' : '<div class="course-phase__items" style="color:#999">品目未設定</div>')
            + '</div>'
            + '<div class="course-phase__actions">'
            + '<button class="btn btn-sm btn-outline" data-action="edit-phase" data-id="' + p.id + '" data-course="' + c.id + '">編集</button>'
            + '<button class="btn btn-sm btn-danger" data-action="delete-phase" data-id="' + p.id + '">削除</button>'
            + '</div></div>';
        });
        html += '</div>';
      }

      // フェーズ追加ボタン
      html += '<div style="padding:0.5rem 1rem 0.75rem">'
        + '<button class="btn btn-sm btn-outline" data-action="add-phase" data-course="' + c.id + '">+ フェーズ追加</button>'
        + '</div>';
      html += '</div>';
    });

    _listEl.innerHTML = html;
  }

  // ==== コース CRUD ====
  function openAddModal() {
    _openCourseModal(null);
  }

  function openEditModal(id) {
    var course = _courses.find(function (c) { return c.id === id; });
    if (!course) return;
    _openCourseModal(course);
  }

  function _openCourseModal(course) {
    var isEdit = !!course;
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = isEdit ? 'コース編集' : 'コース追加';

    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group">'
      + '<label class="form-label">コース名 *</label>'
      + '<input class="form-input" id="modal-course-name" value="' + _esc(isEdit ? course.name : '') + '">'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">コース名（英語）</label>'
      + '<input class="form-input" id="modal-course-name-en" value="' + _esc(isEdit ? course.nameEn : '') + '">'
      + '</div>'
      + '<div class="form-row">'
      + '<div class="form-group"><label class="form-label">料金（税込）</label>'
      + '<input class="form-input" type="number" id="modal-course-price" min="0" value="' + (isEdit ? course.price : '0') + '">'
      + '</div>'
      + '<div class="form-group"><label class="form-label">表示順</label>'
      + '<input class="form-input" type="number" id="modal-course-sort" value="' + (isEdit ? course.sortOrder : '0') + '">'
      + '</div></div>'
      + '<div class="form-group">'
      + '<label class="form-label">説明</label>'
      + '<textarea class="form-input" id="modal-course-desc" rows="2">' + _esc(isEdit ? (course.description || '') : '') + '</textarea>'
      + '</div>'
      + (isEdit ?
        '<div class="form-group"><label class="form-label">有効</label>'
        + '<select class="form-input" id="modal-course-active">'
        + '<option value="1"' + (course.isActive ? ' selected' : '') + '>有効</option>'
        + '<option value="0"' + (!course.isActive ? ' selected' : '') + '>無効</option>'
        + '</select></div>' : '');

    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="modal-course-save">' + (isEdit ? '更新' : '追加') + '</button>';

    overlay.classList.add('open');

    document.getElementById('modal-course-save').addEventListener('click', function () {
      var body = {
        name: document.getElementById('modal-course-name').value.trim(),
        name_en: document.getElementById('modal-course-name-en').value.trim(),
        price: parseInt(document.getElementById('modal-course-price').value, 10) || 0,
        sort_order: parseInt(document.getElementById('modal-course-sort').value, 10) || 0,
        description: document.getElementById('modal-course-desc').value.trim()
      };

      if (!body.name) { showToast('コース名は必須です', 'error'); return; }

      if (isEdit) {
        var activeEl = document.getElementById('modal-course-active');
        if (activeEl) body.is_active = parseInt(activeEl.value, 10);
        AdminApi.updateCourseTemplate(course.id, body).then(function () {
          overlay.classList.remove('open');
          showToast('コースを更新しました', 'success');
          load();
        }).catch(function (err) { showToast(err.message, 'error'); });
      } else {
        AdminApi.createCourseTemplate(body).then(function () {
          overlay.classList.remove('open');
          showToast('コースを追加しました', 'success');
          load();
        }).catch(function (err) { showToast(err.message, 'error'); });
      }
    });
  }

  function confirmDeleteCourse(id) {
    var course = _courses.find(function (c) { return c.id === id; });
    if (!course) return;
    if (!confirm('「' + course.name + '」とすべてのフェーズを削除しますか？')) return;

    AdminApi.deleteCourseTemplate(id).then(function () {
      showToast('コースを削除しました', 'success');
      load();
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  // ==== フェーズ CRUD ====
  function openAddPhaseModal(courseId) {
    _openPhaseModal(courseId, null);
  }

  function openEditPhaseModal(phaseId, courseId) {
    var course = _courses.find(function (c) { return c.id === courseId; });
    if (!course) return;
    var phase = course.phases.find(function (p) { return p.id === phaseId; });
    if (!phase) return;
    _openPhaseModal(courseId, phase);
  }

  function _openPhaseModal(courseId, phase) {
    var isEdit = !!phase;
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = isEdit ? 'フェーズ編集' : 'フェーズ追加';

    var itemsStr = '';
    if (isEdit && phase.items && phase.items.length > 0) {
      itemsStr = phase.items.map(function (it) {
        return it.name + (it.qty > 1 ? ' ×' + it.qty : '');
      }).join('\n');
    }

    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group">'
      + '<label class="form-label">フェーズ名 *</label>'
      + '<input class="form-input" id="modal-phase-name" placeholder="例: 前菜" value="' + _esc(isEdit ? phase.name : '') + '">'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">フェーズ名（英語）</label>'
      + '<input class="form-input" id="modal-phase-name-en" value="' + _esc(isEdit ? phase.nameEn : '') + '">'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">自動発火（前フェーズ完了後N分）</label>'
      + '<input class="form-input" type="number" id="modal-phase-fire" min="0" placeholder="空欄=手動" value="' + (isEdit && phase.autoFireMin !== null ? phase.autoFireMin : '') + '">'
      + '<div class="form-hint">空欄の場合はKDSから手動で次フェーズを発火します</div>'
      + '</div>'
      + '<div class="form-group">'
      + '<label class="form-label">品目（1行1品、「品名 ×数量」形式）</label>'
      + '<textarea class="form-input" id="modal-phase-items" rows="4" placeholder="サラダ\nスープ ×2">' + _esc(itemsStr) + '</textarea>'
      + '<div class="form-hint">例: 刺身盛り合わせ ×1</div>'
      + '</div>';

    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="modal-phase-save">' + (isEdit ? '更新' : '追加') + '</button>';

    overlay.classList.add('open');

    document.getElementById('modal-phase-save').addEventListener('click', function () {
      var name = document.getElementById('modal-phase-name').value.trim();
      if (!name) { showToast('フェーズ名は必須です', 'error'); return; }

      var fireVal = document.getElementById('modal-phase-fire').value.trim();
      var items = _parseItems(document.getElementById('modal-phase-items').value);

      var body = {
        name: name,
        name_en: document.getElementById('modal-phase-name-en').value.trim(),
        auto_fire_min: fireVal !== '' ? parseInt(fireVal, 10) : null,
        items: items,
        course_id: courseId
      };

      if (isEdit) {
        AdminApi.updateCoursePhase(phase.id, body).then(function () {
          overlay.classList.remove('open');
          showToast('フェーズを更新しました', 'success');
          load();
        }).catch(function (err) { showToast(err.message, 'error'); });
      } else {
        AdminApi.addCoursePhase(body).then(function () {
          overlay.classList.remove('open');
          showToast('フェーズを追加しました', 'success');
          load();
        }).catch(function (err) { showToast(err.message, 'error'); });
      }
    });
  }

  function confirmDeletePhase(phaseId) {
    if (!confirm('このフェーズを削除しますか？')) return;
    AdminApi.deleteCoursePhase(phaseId).then(function () {
      showToast('フェーズを削除しました', 'success');
      load();
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  // 品目テキストをJSON配列にパース
  function _parseItems(text) {
    if (!text || !text.trim()) return [];
    return text.trim().split('\n').map(function (line) {
      line = line.trim();
      if (!line) return null;
      var match = line.match(/^(.+?)\s*[×x]\s*(\d+)\s*$/i);
      if (match) {
        return { name: match[1].trim(), qty: parseInt(match[2], 10) };
      }
      return { name: line, qty: 1 };
    }).filter(Boolean);
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
    confirmDeleteCourse: confirmDeleteCourse,
    openAddPhaseModal: openAddPhaseModal,
    openEditPhaseModal: openEditPhaseModal,
    confirmDeletePhase: confirmDeletePhase
  };
})();
