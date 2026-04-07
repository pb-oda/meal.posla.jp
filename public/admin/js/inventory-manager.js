/**
 * 在庫・レシピ管理（owner専用）
 * タブ1: 原材料一覧 + 棚卸し
 * タブ2: レシピ設定（メニュー品目 → 原材料紐付け）
 */
var InventoryManager = (function () {
  'use strict';

  var _containerEl = null;
  var _countEl = null;
  var _ingredients = [];
  var _menuTemplates = [];
  var _activeSubTab = 'ingredients'; // 'ingredients' | 'recipes'
  var _stocktakeMode = false;

  function init(containerEl, countEl) {
    _containerEl = containerEl;
    _countEl = countEl;
  }

  function load() {
    _containerEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';
    // 原材料一覧 + メニューテンプレート を並行取得
    Promise.all([
      AdminApi.getIngredients(),
      AdminApi.getMenuTemplates()
    ]).then(function (results) {
      _ingredients = results[0].ingredients || [];
      _menuTemplates = results[1].templates || [];
      render();
    }).catch(function (err) {
      _containerEl.innerHTML = '<div class="empty-state"><p class="empty-state__text">' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  function render() {
    if (_countEl) _countEl.textContent = '(' + _ingredients.length + ')';

    var html = '<div class="inv-subtabs">'
      + '<button class="inv-subtab' + (_activeSubTab === 'ingredients' ? ' inv-subtab--active' : '') + '" data-subtab="ingredients">原材料一覧</button>'
      + '<button class="inv-subtab' + (_activeSubTab === 'recipes' ? ' inv-subtab--active' : '') + '" data-subtab="recipes">レシピ設定</button>'
      + '</div>';

    if (_activeSubTab === 'ingredients') {
      html += renderIngredients();
    } else {
      html += renderRecipes();
    }

    _containerEl.innerHTML = html;

    // サブタブ切替
    _containerEl.querySelectorAll('.inv-subtab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        _activeSubTab = this.dataset.subtab;
        _stocktakeMode = false;
        render();
      });
    });

    // 棚卸しモード保存ボタン
    if (_stocktakeMode) {
      var saveBtn = _containerEl.querySelector('#btn-stocktake-save');
      if (saveBtn) {
        saveBtn.addEventListener('click', saveStocktake);
      }
    }

    // CSVファイル入力バインド
    bindCsvInputs();
  }

  // ── 原材料一覧タブ ──
  function renderIngredients() {
    var html = '<div class="inv-toolbar">'
      + '<button class="btn btn-sm btn-outline" data-action="toggle-stocktake">'
      + (_stocktakeMode ? '棚卸しキャンセル' : '棚卸し開始') + '</button>'
      + (_stocktakeMode ? '<button class="btn btn-sm btn-primary" id="btn-stocktake-save">棚卸し保存</button>' : '')
      + '<span style="flex:1"></span>'
      + '<button class="btn btn-sm btn-outline" data-action="export-ingredients-csv">CSVエクスポート</button>'
      + '<button class="btn btn-sm btn-outline" data-action="import-ingredients-csv">CSVインポート</button>'
      + '<input type="file" accept=".csv" id="ingredients-csv-file" style="display:none">'
      + '</div>';

    if (_ingredients.length === 0) {
      html += '<div class="empty-state"><p class="empty-state__text">原材料が登録されていません</p></div>';
      return html;
    }

    html += '<div class="inv-table"><table>'
      + '<thead><tr>'
      + '<th>原材料名</th><th>単位</th><th>在庫数</th><th>原価</th><th>アラート</th><th>レシピ数</th><th>操作</th>'
      + '</tr></thead><tbody>';

    _ingredients.forEach(function (ing) {
      var stockVal = parseFloat(ing.stock_quantity) || 0;
      var threshold = ing.low_stock_threshold !== null ? parseFloat(ing.low_stock_threshold) : null;
      var isLow = threshold !== null && stockVal <= threshold;
      var stockClass = isLow ? ' inv-stock--low' : (stockVal < 0 ? ' inv-stock--negative' : '');
      var activeBadge = ing.is_active == 1 ? '' : ' <span class="badge badge--sold-out">無効</span>';

      html += '<tr class="' + (ing.is_active == 0 ? 'inv-row--inactive' : '') + '">'
        + '<td>' + Utils.escapeHtml(ing.name) + activeBadge + '</td>'
        + '<td>' + Utils.escapeHtml(ing.unit) + '</td>'
        + '<td class="inv-stock' + stockClass + '">';

      if (_stocktakeMode) {
        html += '<input type="number" class="inv-stock-input" data-id="' + ing.id + '" value="' + stockVal + '" step="0.01">';
      } else {
        html += stockVal;
      }

      html += '</td>'
        + '<td>' + (parseFloat(ing.cost_price) || 0) + '円</td>'
        + '<td>' + (threshold !== null ? threshold : '-') + '</td>'
        + '<td>' + (ing.recipe_count || 0) + '</td>'
        + '<td>'
        + '<button class="btn btn-sm btn-outline" data-action="edit-ingredient" data-id="' + ing.id + '">編集</button>'
        + '<button class="btn btn-sm btn-danger" data-action="delete-ingredient" data-id="' + ing.id + '">削除</button>'
        + '</td></tr>';
    });

    html += '</tbody></table></div>';
    return html;
  }

  // ── レシピ設定タブ ──
  function renderRecipes() {
    if (_menuTemplates.length === 0) {
      return '<div class="empty-state"><p class="empty-state__text">メニューテンプレートがありません</p></div>';
    }

    var html = '<div class="inv-toolbar">'
      + '<span style="flex:1"></span>'
      + '<button class="btn btn-sm btn-outline" data-action="export-recipes-csv">CSVエクスポート</button>'
      + '<button class="btn btn-sm btn-outline" data-action="import-recipes-csv">CSVインポート</button>'
      + '<input type="file" accept=".csv" id="recipes-csv-file" style="display:none">'
      + '</div>';

    html += '<div class="inv-recipe-list">';
    _menuTemplates.forEach(function (mt) {
      html += '<div class="inv-recipe-card card" style="margin-bottom:0.75rem;">'
        + '<div class="list-item">'
        + '<div class="list-item__body">'
        + '<div class="list-item__name">' + Utils.escapeHtml(mt.name)
        + ' <span style="color:#999;font-size:0.8125rem">' + Utils.escapeHtml(mt.category_name || '') + '</span>'
        + ' <span class="badge">' + (mt.base_price || 0) + '円</span></div>'
        + '</div>'
        + '<div class="list-item__actions">'
        + '<button class="btn btn-sm btn-outline" data-action="edit-recipe" data-menu-id="' + mt.id + '" data-menu-name="' + Utils.escapeHtml(mt.name) + '">レシピ編集</button>'
        + '</div></div></div>';
    });
    html += '</div>';
    return html;
  }

  // ── 棚卸し保存 ──
  function saveStocktake() {
    var inputs = _containerEl.querySelectorAll('.inv-stock-input');
    var items = [];
    inputs.forEach(function (inp) {
      items.push({ id: inp.dataset.id, stock_quantity: parseFloat(inp.value) || 0 });
    });
    if (items.length === 0) return;

    var btn = _containerEl.querySelector('#btn-stocktake-save');
    if (btn) btn.disabled = true;

    AdminApi.stocktakeIngredients(items).then(function (res) {
      showToast('棚卸しを保存しました（' + res.updated + '件更新）', 'success');
      _stocktakeMode = false;
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
      if (btn) btn.disabled = false;
    });
  }

  // ── 原材料追加モーダル ──
  function openAddModal() {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = '原材料追加';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">原材料名 *</label><input class="form-input" id="ing-name" placeholder="鶏肉"></div>'
      + '<div class="form-group"><label class="form-label">単位</label><input class="form-input" id="ing-unit" value="g" placeholder="g, ml, 個, 枚"></div>'
      + '<div class="form-group"><label class="form-label">初期在庫数</label><input class="form-input" id="ing-stock" type="number" value="0" step="0.01"></div>'
      + '<div class="form-group"><label class="form-label">原価（単位あたり、円）</label><input class="form-input" id="ing-cost" type="number" value="0" step="0.01"></div>'
      + '<div class="form-group"><label class="form-label">在庫少アラート閾値</label><input class="form-input" id="ing-threshold" type="number" step="0.01" placeholder="未設定"></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-ing">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-ing').onclick = function () {
      var name = document.getElementById('ing-name').value.trim();
      if (!name) { showToast('原材料名は必須です', 'error'); return; }
      this.disabled = true;

      var thresholdVal = document.getElementById('ing-threshold').value.trim();
      AdminApi.createIngredient({
        name: name,
        unit: document.getElementById('ing-unit').value.trim() || '個',
        stock_quantity: parseFloat(document.getElementById('ing-stock').value) || 0,
        cost_price: parseFloat(document.getElementById('ing-cost').value) || 0,
        low_stock_threshold: thresholdVal !== '' ? parseFloat(thresholdVal) : null
      }).then(function () {
        overlay.classList.remove('open');
        showToast('原材料を追加しました', 'success');
        load();
      }).catch(function (err) { showToast(err.message, 'error'); })
      .finally(function () {
        var btn = document.getElementById('btn-save-ing');
        if (btn) btn.disabled = false;
      });
    };
  }

  // ── 原材料編集モーダル ──
  function openEditModal(id) {
    var ing = _ingredients.find(function (x) { return x.id === id; });
    if (!ing) return;

    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = '原材料編集';
    var thresholdVal = ing.low_stock_threshold !== null ? ing.low_stock_threshold : '';
    overlay.querySelector('.modal__body').innerHTML =
      '<div class="form-group"><label class="form-label">原材料名</label><input class="form-input" id="ing-name" value="' + Utils.escapeHtml(ing.name) + '"></div>'
      + '<div class="form-group"><label class="form-label">単位</label><input class="form-input" id="ing-unit" value="' + Utils.escapeHtml(ing.unit) + '"></div>'
      + '<div class="form-group"><label class="form-label">在庫数</label><input class="form-input" id="ing-stock" type="number" value="' + (parseFloat(ing.stock_quantity) || 0) + '" step="0.01"></div>'
      + '<div class="form-group"><label class="form-label">原価（単位あたり、円）</label><input class="form-input" id="ing-cost" type="number" value="' + (parseFloat(ing.cost_price) || 0) + '" step="0.01"></div>'
      + '<div class="form-group"><label class="form-label">在庫少アラート閾値</label><input class="form-input" id="ing-threshold" type="number" step="0.01" value="' + thresholdVal + '" placeholder="未設定"></div>'
      + '<div class="form-group"><label class="form-label">有効</label>'
      + '<label class="toggle"><input type="checkbox" id="ing-active" ' + (ing.is_active == 1 ? 'checked' : '') + '><span class="toggle__slider"></span></label></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">キャンセル</button>'
      + '<button class="btn btn-primary" id="btn-save-ing">保存</button>';
    overlay.classList.add('open');

    document.getElementById('btn-save-ing').onclick = function () {
      this.disabled = true;
      var thresholdInput = document.getElementById('ing-threshold').value.trim();
      AdminApi.updateIngredient(id, {
        name: document.getElementById('ing-name').value.trim(),
        unit: document.getElementById('ing-unit').value.trim(),
        stock_quantity: parseFloat(document.getElementById('ing-stock').value) || 0,
        cost_price: parseFloat(document.getElementById('ing-cost').value) || 0,
        low_stock_threshold: thresholdInput !== '' ? parseFloat(thresholdInput) : null,
        is_active: document.getElementById('ing-active').checked
      }).then(function () {
        overlay.classList.remove('open');
        showToast('原材料を更新しました', 'success');
        load();
      }).catch(function (err) { showToast(err.message, 'error'); })
      .finally(function () {
        var btn = document.getElementById('btn-save-ing');
        if (btn) btn.disabled = false;
      });
    };
  }

  function confirmDelete(id) {
    var ing = _ingredients.find(function (x) { return x.id === id; });
    if (!ing) return;
    if (!confirm('「' + ing.name + '」を削除しますか？\n（関連するレシピも削除されます）')) return;
    AdminApi.deleteIngredient(id).then(function () {
      showToast('原材料を削除しました', 'success');
      load();
    }).catch(function (err) { showToast(err.message, 'error'); });
  }

  // ── レシピ編集モーダル ──
  function openRecipeModal(menuId, menuName) {
    var overlay = document.getElementById('admin-modal-overlay');
    overlay.querySelector('.modal__title').textContent = 'レシピ編集: ' + menuName;
    overlay.querySelector('.modal__body').innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';
    overlay.querySelector('.modal__footer').innerHTML =
      '<button class="btn btn-secondary" data-action="modal-cancel">閉じる</button>';
    overlay.classList.add('open');

    AdminApi.getRecipes(menuId).then(function (res) {
      var recipes = res.recipes || [];
      renderRecipeModal(menuId, menuName, recipes);
    }).catch(function (err) {
      overlay.querySelector('.modal__body').innerHTML =
        '<p style="color:red">' + Utils.escapeHtml(err.message) + '</p>';
    });
  }

  function renderRecipeModal(menuId, menuName, recipes) {
    var overlay = document.getElementById('admin-modal-overlay');
    var bodyEl = overlay.querySelector('.modal__body');

    var html = '';
    if (recipes.length === 0) {
      html += '<p style="color:#999;margin-bottom:1rem">レシピ未設定</p>';
    } else {
      html += '<table class="inv-recipe-table" style="width:100%;margin-bottom:1rem">'
        + '<thead><tr><th>原材料</th><th>消費量</th><th>単位</th><th>在庫</th><th></th></tr></thead><tbody>';
      recipes.forEach(function (r) {
        html += '<tr>'
          + '<td>' + Utils.escapeHtml(r.ingredient_name) + '</td>'
          + '<td><input type="number" class="form-input" style="width:80px" data-recipe-id="' + r.id + '" value="' + r.quantity + '" step="0.01" min="0.01"></td>'
          + '<td>' + Utils.escapeHtml(r.ingredient_unit) + '</td>'
          + '<td>' + r.stock_quantity + '</td>'
          + '<td><button class="btn btn-sm btn-danger" data-action="delete-recipe-line" data-recipe-id="' + r.id + '">削除</button></td>'
          + '</tr>';
      });
      html += '</tbody></table>';
    }

    // 原材料追加セレクト
    var usedIds = recipes.map(function (r) { return r.ingredient_id; });
    var available = _ingredients.filter(function (i) { return usedIds.indexOf(i.id) === -1 && i.is_active == 1; });

    html += '<div style="display:flex;gap:0.5rem;align-items:flex-end">'
      + '<div class="form-group" style="flex:1;margin-bottom:0"><label class="form-label">原材料を追加</label>'
      + '<select class="form-input" id="recipe-add-ing">'
      + '<option value="">選択...</option>';
    available.forEach(function (i) {
      html += '<option value="' + i.id + '">' + Utils.escapeHtml(i.name) + '（' + Utils.escapeHtml(i.unit) + '）</option>';
    });
    html += '</select></div>'
      + '<div class="form-group" style="width:80px;margin-bottom:0"><label class="form-label">数量</label>'
      + '<input class="form-input" id="recipe-add-qty" type="number" value="1" step="0.01" min="0.01"></div>'
      + '<button class="btn btn-primary btn-sm" id="btn-add-recipe-line" style="margin-bottom:0">追加</button>'
      + '</div>';

    bodyEl.innerHTML = html;

    // 追加ボタン
    document.getElementById('btn-add-recipe-line').addEventListener('click', function () {
      var ingId = document.getElementById('recipe-add-ing').value;
      var qty = parseFloat(document.getElementById('recipe-add-qty').value) || 1;
      if (!ingId) { showToast('原材料を選択してください', 'error'); return; }
      this.disabled = true;
      var self = this;
      AdminApi.addRecipe({
        menu_template_id: menuId,
        ingredient_id: ingId,
        quantity: qty
      }).then(function () {
        showToast('レシピに追加しました', 'success');
        return AdminApi.getRecipes(menuId);
      }).then(function (res) {
        renderRecipeModal(menuId, menuName, res.recipes || []);
      }).catch(function (err) {
        showToast(err.message, 'error');
        self.disabled = false;
      });
    });

    // 数量変更（blur時に自動保存）
    bodyEl.querySelectorAll('[data-recipe-id]').forEach(function (inp) {
      if (inp.tagName === 'INPUT') {
        inp.addEventListener('change', function () {
          var rid = this.dataset.recipeId;
          var qty = parseFloat(this.value) || 1;
          AdminApi.updateRecipe(rid, qty).catch(function (err) {
            showToast(err.message, 'error');
          });
        });
      }
    });

    // 削除ボタン
    bodyEl.querySelectorAll('[data-action="delete-recipe-line"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var rid = this.dataset.recipeId;
        if (!confirm('このレシピ行を削除しますか？')) return;
        AdminApi.deleteRecipe(rid).then(function () {
          showToast('レシピ行を削除しました', 'success');
          return AdminApi.getRecipes(menuId);
        }).then(function (res) {
          renderRecipeModal(menuId, menuName, res.recipes || []);
        }).catch(function (err) { showToast(err.message, 'error'); });
      });
    });
  }

  // ── イベントハンドラ（コンテナのイベント委譲から呼ばれる） ──
  function handleAction(action, dataset) {
    switch (action) {
      case 'toggle-stocktake':
        _stocktakeMode = !_stocktakeMode;
        render();
        break;
      case 'edit-ingredient':
        openEditModal(dataset.id);
        break;
      case 'delete-ingredient':
        confirmDelete(dataset.id);
        break;
      case 'edit-recipe':
        openRecipeModal(dataset.menuId, dataset.menuName);
        break;
      case 'export-ingredients-csv':
        AdminApi.exportIngredientsCsv();
        break;
      case 'import-ingredients-csv':
        var ingInput = document.getElementById('ingredients-csv-file');
        if (ingInput) ingInput.click();
        break;
      case 'export-recipes-csv':
        AdminApi.exportRecipesCsv();
        break;
      case 'import-recipes-csv':
        var recInput = document.getElementById('recipes-csv-file');
        if (recInput) recInput.click();
        break;
    }
  }

  // ── CSV change ハンドラを render 後にバインド ──
  function bindCsvInputs() {
    var ingInput = _containerEl.querySelector('#ingredients-csv-file');
    if (ingInput) {
      ingInput.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        this.value = '';
        if (!confirm('「' + file.name + '」をインポートしますか？')) return;
        AdminApi.importIngredientsCsv(file).then(function (res) {
          var msg = '作成: ' + res.created + '件、更新: ' + res.updated + '件';
          if (res.errors && res.errors.length > 0) msg += '\nエラー:\n' + res.errors.join('\n');
          showToast(msg, res.errors && res.errors.length > 0 ? 'warning' : 'success');
          load();
        }).catch(function (err) { showToast(err.message, 'error'); });
      });
    }

    var recInput = _containerEl.querySelector('#recipes-csv-file');
    if (recInput) {
      recInput.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        this.value = '';
        if (!confirm('「' + file.name + '」をインポートしますか？')) return;
        AdminApi.importRecipesCsv(file).then(function (res) {
          var msg = '作成: ' + res.created + '件、更新: ' + res.updated + '件';
          if (res.errors && res.errors.length > 0) msg += '\nエラー:\n' + res.errors.join('\n');
          showToast(msg, res.errors && res.errors.length > 0 ? 'warning' : 'success');
          load();
        }).catch(function (err) { showToast(err.message, 'error'); });
      });
    }
  }

  return {
    init: init,
    load: load,
    openAddModal: openAddModal,
    openEditModal: openEditModal,
    confirmDelete: confirmDelete,
    openRecipeModal: openRecipeModal,
    handleAction: handleAction
  };
})();
