/**
 * 管理画面 APIクライアント（マルチテナント対応）
 *
 * ロール対応・store_idコンテキスト付き
 */
var AdminApi = (function () {
  'use strict';

  var BASE = '../../api';

  // 現在選択中の店舗ID（StoreSelector が設定）
  var _currentStoreId = null;

  function setCurrentStore(storeId) {
    _currentStoreId = storeId;
  }

  function getCurrentStore() {
    return _currentStoreId;
  }

  function request(method, path, body) {
    var opts = {
      method: method,
      headers: {},
      credentials: 'same-origin'
    };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(BASE + path, opts).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); }
        catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || 'エラーが発生しました';
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    });
  }

  // store_idクエリ付きGET
  function storeGet(path, extraParams) {
    var params = [];
    if (_currentStoreId) params.push('store_id=' + encodeURIComponent(_currentStoreId));
    if (extraParams) {
      Object.keys(extraParams).forEach(function (k) {
        if (extraParams[k] !== null && extraParams[k] !== undefined) {
          params.push(k + '=' + encodeURIComponent(extraParams[k]));
        }
      });
    }
    var qs = params.length > 0 ? '?' + params.join('&') : '';
    return request('GET', path + qs);
  }

  // --- 認証 ---
  function login(email, password) {
    return request('POST', '/auth/login.php', { email: email, password: password });
  }

  function logout() {
    return request('DELETE', '/auth/logout.php');
  }

  function me() {
    return request('GET', '/auth/me.php');
  }

  // --- テナント（owner） ---
  function getTenant() {
    return request('GET', '/owner/tenants.php');
  }

  function updateTenant(data) {
    return request('PATCH', '/owner/tenants.php', data);
  }

  // --- 店舗管理（owner） ---
  function getStores() {
    return request('GET', '/owner/stores.php');
  }

  function getStore(id) {
    return request('GET', '/owner/stores.php?id=' + encodeURIComponent(id));
  }

  function createStore(data) {
    return request('POST', '/owner/stores.php', data);
  }

  function updateStore(id, data) {
    return request('PATCH', '/owner/stores.php?id=' + encodeURIComponent(id), data);
  }

  function deleteStore(id) {
    return request('DELETE', '/owner/stores.php?id=' + encodeURIComponent(id));
  }

  // --- ユーザー管理（owner） ---
  function getUsers() {
    return request('GET', '/owner/users.php');
  }

  function createUser(data) {
    return request('POST', '/owner/users.php', data);
  }

  function updateUser(id, data) {
    return request('PATCH', '/owner/users.php?id=' + encodeURIComponent(id), data);
  }

  function deleteUser(id) {
    return request('DELETE', '/owner/users.php?id=' + encodeURIComponent(id));
  }

  // --- スタッフ管理（manager用・store_idベース） ---
  function getStaff() {
    return storeGet('/store/staff-management.php');
  }

  function createStaff(data) {
    return request('POST', '/store/staff-management.php', data);
  }

  function updateStaff(id, data) {
    return request('PATCH', '/store/staff-management.php?id=' + encodeURIComponent(id), data);
  }

  function deleteStaff(id) {
    return request('DELETE', '/store/staff-management.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- デバイスアカウント管理（P1a: KDS/レジ端末専用） ---
  // staff-management.php に kind=device を渡して切り替える
  function getDevices() {
    return storeGet('/store/staff-management.php', { kind: 'device' });
  }

  function createDevice(data) {
    data.kind = 'device';
    return request('POST', '/store/staff-management.php', data);
  }

  function updateDevice(id, data) {
    return request('PATCH', '/store/staff-management.php?id=' + encodeURIComponent(id) + '&kind=device&store_id=' + encodeURIComponent(_currentStoreId), data);
  }

  function deleteDevice(id) {
    return request('DELETE', '/store/staff-management.php?id=' + encodeURIComponent(id) + '&kind=device&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- カテゴリ管理（owner） ---
  function getCategories() {
    return request('GET', '/owner/categories.php');
  }

  function createCategory(data) {
    return request('POST', '/owner/categories.php', data);
  }

  function updateCategory(id, data) {
    return request('PATCH', '/owner/categories.php?id=' + encodeURIComponent(id), data);
  }

  function deleteCategory(id) {
    return request('DELETE', '/owner/categories.php?id=' + encodeURIComponent(id));
  }

  // --- 本部メニューテンプレート（owner） ---
  function getMenuTemplates(categoryId) {
    var qs = categoryId ? '?category_id=' + encodeURIComponent(categoryId) : '';
    return request('GET', '/owner/menu-templates.php' + qs);
  }

  function createMenuTemplate(data) {
    return request('POST', '/owner/menu-templates.php', data);
  }

  function updateMenuTemplate(id, data) {
    return request('PATCH', '/owner/menu-templates.php?id=' + encodeURIComponent(id), data);
  }

  function deleteMenuTemplate(id) {
    return request('DELETE', '/owner/menu-templates.php?id=' + encodeURIComponent(id));
  }

  // --- 画像アップロード（owner） ---
  function uploadImage(file) {
    var formData = new FormData();
    formData.append('image', file);
    return fetch(BASE + '/owner/upload-image.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); }
        catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || '画像アップロードに失敗しました';
          return Promise.reject(new Error(msg));
        }
        return json;
      });
    });
  }

  // --- 店舗メニューオーバーライド ---
  function getMenuOverrides() {
    return storeGet('/store/menu-overrides.php');
  }

  function updateMenuOverride(templateId, data) {
    data.store_id = _currentStoreId;
    data.template_id = templateId;
    return request('PATCH', '/store/menu-overrides.php', data);
  }

  // --- 店舗限定メニュー ---
  function getLocalItems() {
    return storeGet('/store/local-items.php');
  }

  function createLocalItem(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/local-items.php', data);
  }

  function updateLocalItem(id, data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/local-items.php?id=' + encodeURIComponent(id), data);
  }

  function deleteLocalItem(id) {
    return request('DELETE', '/store/local-items.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- テーブル管理 ---
  function getTables() {
    return storeGet('/store/tables.php');
  }

  function createTable(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/tables.php', data);
  }

  function updateTable(id, data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/tables.php?id=' + encodeURIComponent(id), data);
  }

  function deleteTable(id) {
    return request('DELETE', '/store/tables.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- 店舗設定 ---
  function getSettings() {
    return storeGet('/store/settings.php');
  }

  function updateSettings(data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/settings.php', data);
  }

  // --- 売上レポート ---
  function getSalesReport(from, to) {
    return storeGet('/store/sales-report.php', { from: from, to: to });
  }

  // --- レジ分析 ---
  function getRegisterReport(from, to) {
    return storeGet('/store/register-report.php', { from: from, to: to });
  }

  // --- 注文履歴 ---
  function getOrderHistory(params) {
    return storeGet('/store/order-history.php', params);
  }

  // --- 回転率・調理時間・客層分析 ---
  function getTurnoverReport(from, to) {
    return storeGet('/store/turnover-report.php', { from: from, to: to });
  }

  // --- スタッフ別評価・監査レポート ---
  function getStaffReport(from, to) {
    return storeGet('/store/staff-report.php', { from: from, to: to });
  }

  // --- 併売分析（バスケット分析） ---
  function getBasketAnalysis(from, to, minSupport) {
    return storeGet('/store/basket-analysis.php', { from: from, to: to, min_support: minSupport });
  }

  // --- 需要予測データ ---
  function getDemandForecastData() {
    return storeGet('/store/demand-forecast-data.php');
  }

  // --- 横断レポート（owner） ---
  function getCrossStoreReport(from, to, storeId) {
    var params = 'from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
    if (storeId) params += '&store_id=' + encodeURIComponent(storeId);
    return request('GET', '/owner/cross-store-report.php?' + params);
  }

  // --- オプショングループ（owner） ---
  function getOptionGroups() {
    return request('GET', '/owner/option-groups.php');
  }

  function createOptionGroup(data) {
    return request('POST', '/owner/option-groups.php', data);
  }

  function updateOptionGroup(id, data) {
    return request('PATCH', '/owner/option-groups.php?id=' + encodeURIComponent(id), data);
  }

  function deleteOptionGroup(id) {
    return request('DELETE', '/owner/option-groups.php?id=' + encodeURIComponent(id));
  }

  function addOptionChoice(data) {
    return request('POST', '/owner/option-groups.php?action=add-choice', data);
  }

  function updateOptionChoice(id, data) {
    return request('PATCH', '/owner/option-groups.php?action=update-choice&id=' + encodeURIComponent(id), data);
  }

  function deleteOptionChoice(id) {
    return request('DELETE', '/owner/option-groups.php?action=delete-choice&id=' + encodeURIComponent(id));
  }

  // --- オプション紐付け ---
  function getTemplateOptionLinks(templateId) {
    return request('GET', '/owner/option-groups.php?action=template-links&template_id=' + encodeURIComponent(templateId));
  }

  function syncTemplateOptionLinks(templateId, groups) {
    return request('POST', '/owner/option-groups.php?action=sync-template-links', {
      template_id: templateId,
      groups: groups
    });
  }

  function getLocalItemOptionLinks(localItemId) {
    return request('GET', '/owner/option-groups.php?action=local-links&local_item_id=' + encodeURIComponent(localItemId));
  }

  function syncLocalItemOptionLinks(localItemId, groups) {
    return request('POST', '/owner/option-groups.php?action=sync-local-links', {
      local_item_id: localItemId,
      groups: groups
    });
  }

  // --- KDSステーション（store） ---
  function getKdsStations() {
    return storeGet('/store/kds-stations.php');
  }

  function createKdsStation(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/kds-stations.php?store_id=' + encodeURIComponent(_currentStoreId), data);
  }

  function updateKdsStation(id, data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/kds-stations.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId), data);
  }

  function deleteKdsStation(id) {
    return request('DELETE', '/store/kds-stations.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- テーブル状態（フロアマップ用） ---
  function getTablesStatus(floor) {
    var extra = {};
    if (floor) extra.floor = floor;
    return storeGet('/store/tables-status.php', extra);
  }

  // --- 食べ放題プラン ---
  function getTimeLimitPlans() {
    return storeGet('/store/time-limit-plans.php');
  }

  // --- 食べ放題プラン専用メニュー ---
  function getPlanMenuItems(planId) {
    return storeGet('/store/plan-menu-items.php', { plan_id: planId });
  }

  function createPlanMenuItem(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/plan-menu-items.php', data);
  }

  function updatePlanMenuItem(id, data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/plan-menu-items.php?id=' + encodeURIComponent(id), data);
  }

  function deletePlanMenuItem(id) {
    return request('DELETE', '/store/plan-menu-items.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  function createTimeLimitPlan(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/time-limit-plans.php', data);
  }

  function updateTimeLimitPlan(id, data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/time-limit-plans.php?id=' + encodeURIComponent(id), data);
  }

  function deleteTimeLimitPlan(id) {
    return request('DELETE', '/store/time-limit-plans.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- テーブルセッション ---
  function getTableSessions() {
    return storeGet('/store/table-sessions.php');
  }

  function startTableSession(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/table-sessions.php', data);
  }

  function updateTableSession(id, data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/table-sessions.php?id=' + encodeURIComponent(id), data);
  }

  function closeTableSession(id) {
    return request('DELETE', '/store/table-sessions.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- コーステンプレート ---
  function getCourseTemplates() {
    return storeGet('/store/course-templates.php');
  }

  function createCourseTemplate(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/course-templates.php', data);
  }

  function updateCourseTemplate(id, data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/course-templates.php?id=' + encodeURIComponent(id), data);
  }

  function deleteCourseTemplate(id) {
    return request('DELETE', '/store/course-templates.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- コースフェーズ ---
  function addCoursePhase(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/course-phases.php', data);
  }

  function updateCoursePhase(id, data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/course-phases.php?id=' + encodeURIComponent(id), data);
  }

  function deleteCoursePhase(id) {
    return request('DELETE', '/store/course-phases.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // --- 店舗画像アップロード ---
  function uploadStoreImage(file) {
    var formData = new FormData();
    formData.append('image', file);
    formData.append('store_id', _currentStoreId);
    return fetch(BASE + '/store/upload-image.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); }
        catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || '画像アップロードに失敗しました';
          return Promise.reject(new Error(msg));
        }
        return json;
      });
    });
  }

  // --- 原材料管理（owner） ---
  function getIngredients() {
    return request('GET', '/owner/ingredients.php');
  }

  function createIngredient(data) {
    return request('POST', '/owner/ingredients.php', data);
  }

  function updateIngredient(id, data) {
    return request('PATCH', '/owner/ingredients.php?id=' + encodeURIComponent(id), data);
  }

  function deleteIngredient(id) {
    return request('DELETE', '/owner/ingredients.php?id=' + encodeURIComponent(id));
  }

  function stocktakeIngredients(items) {
    return request('PATCH', '/owner/ingredients.php?action=stocktake', { items: items });
  }

  // --- レシピ管理（owner） ---
  function getRecipes(menuTemplateId) {
    return request('GET', '/owner/recipes.php?menu_template_id=' + encodeURIComponent(menuTemplateId));
  }

  function addRecipe(data) {
    return request('POST', '/owner/recipes.php', data);
  }

  function updateRecipe(id, quantity) {
    return request('PATCH', '/owner/recipes.php?id=' + encodeURIComponent(id), { quantity: quantity });
  }

  function deleteRecipe(id) {
    return request('DELETE', '/owner/recipes.php?id=' + encodeURIComponent(id));
  }

  // --- ABC分析（owner） ---
  function getAbcAnalytics(from, to, storeId) {
    var params = 'from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
    if (storeId) params += '&store_id=' + encodeURIComponent(storeId);
    return request('GET', '/owner/analytics-abc.php?' + params);
  }

  // --- 原材料CSV ---
  function exportIngredientsCsv() {
    window.open(BASE + '/owner/ingredients-csv.php', '_blank');
  }

  function importIngredientsCsv(file) {
    var formData = new FormData();
    formData.append('csv_file', file);
    return fetch(BASE + '/owner/ingredients-csv.php', {
      method: 'POST', body: formData, credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) return Promise.reject(new Error((json.error && json.error.message) || 'インポートに失敗しました'));
        return json.data;
      });
    });
  }

  // --- レシピCSV ---
  function exportRecipesCsv() {
    window.open(BASE + '/owner/recipes-csv.php', '_blank');
  }

  function importRecipesCsv(file) {
    var formData = new FormData();
    formData.append('csv_file', file);
    return fetch(BASE + '/owner/recipes-csv.php', {
      method: 'POST', body: formData, credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) return Promise.reject(new Error((json.error && json.error.message) || 'インポートに失敗しました'));
        return json.data;
      });
    });
  }

  // --- 限定メニューCSV ---
  function exportLocalItemsCsv() {
    if (!_currentStoreId) return;
    window.open(BASE + '/store/local-items-csv.php?store_id=' + encodeURIComponent(_currentStoreId), '_blank');
  }

  function importLocalItemsCsv(file) {
    var formData = new FormData();
    formData.append('csv_file', file);
    formData.append('store_id', _currentStoreId);
    return fetch(BASE + '/store/local-items-csv.php', {
      method: 'POST', body: formData, credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) return Promise.reject(new Error((json.error && json.error.message) || 'インポートに失敗しました'));
        return json.data;
      });
    });
  }

  // --- 店舗メニューオーバーライドCSV ---
  function exportOverridesCsv() {
    if (!_currentStoreId) return;
    window.open(BASE + '/store/menu-overrides-csv.php?store_id=' + encodeURIComponent(_currentStoreId), '_blank');
  }

  function importOverridesCsv(file) {
    var formData = new FormData();
    formData.append('csv_file', file);
    formData.append('store_id', _currentStoreId);
    return fetch(BASE + '/store/menu-overrides-csv.php', {
      method: 'POST', body: formData, credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) return Promise.reject(new Error((json.error && json.error.message) || 'インポートに失敗しました'));
        return json.data;
      });
    });
  }

  // --- 食べ放題プランCSV ---
  function exportTimeLimitPlansCsv() {
    if (!_currentStoreId) return;
    window.open(BASE + '/store/time-limit-plans-csv.php?store_id=' + encodeURIComponent(_currentStoreId), '_blank');
  }

  function importTimeLimitPlansCsv(file) {
    var formData = new FormData();
    formData.append('csv_file', file);
    formData.append('store_id', _currentStoreId);
    return fetch(BASE + '/store/time-limit-plans-csv.php', {
      method: 'POST', body: formData, credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) return Promise.reject(new Error((json.error && json.error.message) || 'インポートに失敗しました'));
        return json.data;
      });
    });
  }

  // --- プラン専用メニューCSV ---
  function exportPlanMenuItemsCsv() {
    if (!_currentStoreId) return;
    window.open(BASE + '/store/plan-menu-items-csv.php?store_id=' + encodeURIComponent(_currentStoreId), '_blank');
  }

  function importPlanMenuItemsCsv(file) {
    var formData = new FormData();
    formData.append('csv_file', file);
    formData.append('store_id', _currentStoreId);
    return fetch(BASE + '/store/plan-menu-items-csv.php', {
      method: 'POST', body: formData, credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) return Promise.reject(new Error((json.error && json.error.message) || 'インポートに失敗しました'));
        return json.data;
      });
    });
  }

  // --- コース料理CSV ---
  function exportCourseCsv() {
    if (!_currentStoreId) return;
    window.open(BASE + '/store/course-csv.php?store_id=' + encodeURIComponent(_currentStoreId), '_blank');
  }

  function importCourseCsv(file) {
    var formData = new FormData();
    formData.append('csv_file', file);
    formData.append('store_id', _currentStoreId);
    return fetch(BASE + '/store/course-csv.php', {
      method: 'POST', body: formData, credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) return Promise.reject(new Error((json.error && json.error.message) || 'インポートに失敗しました'));
        return json.data;
      });
    });
  }

  // --- メニューCSVエクスポート/インポート（owner） ---
  function exportMenuCsv() {
    window.open(BASE + '/owner/menu-csv.php', '_blank');
  }

  function importMenuCsv(file) {
    var formData = new FormData();
    formData.append('csv_file', file);
    return fetch(BASE + '/owner/menu-csv.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        if (!body) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(body); }
        catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || 'インポートに失敗しました';
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    });
  }

  return {
    setCurrentStore: setCurrentStore,
    getCurrentStore: getCurrentStore,
    login: login,
    logout: logout,
    me: me,
    getTenant: getTenant,
    updateTenant: updateTenant,
    getStores: getStores,
    getStore: getStore,
    createStore: createStore,
    updateStore: updateStore,
    deleteStore: deleteStore,
    getUsers: getUsers,
    createUser: createUser,
    updateUser: updateUser,
    deleteUser: deleteUser,
    getStaff: getStaff,
    createStaff: createStaff,
    updateStaff: updateStaff,
    deleteStaff: deleteStaff,
    getDevices: getDevices,
    createDevice: createDevice,
    updateDevice: updateDevice,
    deleteDevice: deleteDevice,
    getCategories: getCategories,
    createCategory: createCategory,
    updateCategory: updateCategory,
    deleteCategory: deleteCategory,
    getMenuTemplates: getMenuTemplates,
    createMenuTemplate: createMenuTemplate,
    updateMenuTemplate: updateMenuTemplate,
    deleteMenuTemplate: deleteMenuTemplate,
    uploadImage: uploadImage,
    getMenuOverrides: getMenuOverrides,
    updateMenuOverride: updateMenuOverride,
    getLocalItems: getLocalItems,
    createLocalItem: createLocalItem,
    updateLocalItem: updateLocalItem,
    deleteLocalItem: deleteLocalItem,
    getTables: getTables,
    createTable: createTable,
    updateTable: updateTable,
    deleteTable: deleteTable,
    getSettings: getSettings,
    updateSettings: updateSettings,
    getSalesReport: getSalesReport,
    getRegisterReport: getRegisterReport,
    getOrderHistory: getOrderHistory,
    getTurnoverReport: getTurnoverReport,
    getStaffReport: getStaffReport,
    getBasketAnalysis: getBasketAnalysis,
    getDemandForecastData: getDemandForecastData,
    getCrossStoreReport: getCrossStoreReport,
    uploadStoreImage: uploadStoreImage,
    getOptionGroups: getOptionGroups,
    createOptionGroup: createOptionGroup,
    updateOptionGroup: updateOptionGroup,
    deleteOptionGroup: deleteOptionGroup,
    addOptionChoice: addOptionChoice,
    updateOptionChoice: updateOptionChoice,
    deleteOptionChoice: deleteOptionChoice,
    getTemplateOptionLinks: getTemplateOptionLinks,
    syncTemplateOptionLinks: syncTemplateOptionLinks,
    getLocalItemOptionLinks: getLocalItemOptionLinks,
    syncLocalItemOptionLinks: syncLocalItemOptionLinks,
    getKdsStations: getKdsStations,
    createKdsStation: createKdsStation,
    updateKdsStation: updateKdsStation,
    deleteKdsStation: deleteKdsStation,
    getTablesStatus: getTablesStatus,
    getTimeLimitPlans: getTimeLimitPlans,
    createTimeLimitPlan: createTimeLimitPlan,
    updateTimeLimitPlan: updateTimeLimitPlan,
    deleteTimeLimitPlan: deleteTimeLimitPlan,
    getPlanMenuItems: getPlanMenuItems,
    createPlanMenuItem: createPlanMenuItem,
    updatePlanMenuItem: updatePlanMenuItem,
    deletePlanMenuItem: deletePlanMenuItem,
    getTableSessions: getTableSessions,
    startTableSession: startTableSession,
    updateTableSession: updateTableSession,
    closeTableSession: closeTableSession,
    getCourseTemplates: getCourseTemplates,
    createCourseTemplate: createCourseTemplate,
    updateCourseTemplate: updateCourseTemplate,
    deleteCourseTemplate: deleteCourseTemplate,
    addCoursePhase: addCoursePhase,
    updateCoursePhase: updateCoursePhase,
    deleteCoursePhase: deleteCoursePhase,
    exportMenuCsv: exportMenuCsv,
    importMenuCsv: importMenuCsv,
    exportOverridesCsv: exportOverridesCsv,
    importOverridesCsv: importOverridesCsv,
    exportTimeLimitPlansCsv: exportTimeLimitPlansCsv,
    importTimeLimitPlansCsv: importTimeLimitPlansCsv,
    exportPlanMenuItemsCsv: exportPlanMenuItemsCsv,
    importPlanMenuItemsCsv: importPlanMenuItemsCsv,
    exportCourseCsv: exportCourseCsv,
    importCourseCsv: importCourseCsv,
    getAbcAnalytics: getAbcAnalytics,
    exportIngredientsCsv: exportIngredientsCsv,
    importIngredientsCsv: importIngredientsCsv,
    exportRecipesCsv: exportRecipesCsv,
    importRecipesCsv: importRecipesCsv,
    exportLocalItemsCsv: exportLocalItemsCsv,
    importLocalItemsCsv: importLocalItemsCsv,
    getIngredients: getIngredients,
    createIngredient: createIngredient,
    updateIngredient: updateIngredient,
    deleteIngredient: deleteIngredient,
    stocktakeIngredients: stocktakeIngredients,
    getRecipes: getRecipes,
    addRecipe: addRecipe,
    updateRecipe: updateRecipe,
    deleteRecipe: deleteRecipe,
    getDailyRecommendations: getDailyRecommendations,
    addDailyRecommendation: addDailyRecommendation,
    removeDailyRecommendation: removeDailyRecommendation,
    getTakeoutOrders: getTakeoutOrders,
    patchTakeoutOrder: patchTakeoutOrder,
    getReceiptSettings: getReceiptSettings,
    updateReceiptSettings: updateReceiptSettings,
    getReceiptsByDate: getReceiptsByDate,
    getReceiptDetail: getReceiptDetail
  };

  // N-1: 今日のおすすめ API
  function getDailyRecommendations(date) {
    return storeGet('/store/daily-recommendations.php', { date: date || null });
  }
  function addDailyRecommendation(data) {
    data.store_id = _currentStoreId;
    return request('POST', '/store/daily-recommendations.php', data);
  }
  function removeDailyRecommendation(id) {
    return request('DELETE', '/store/daily-recommendations.php?id=' + encodeURIComponent(id) + '&store_id=' + encodeURIComponent(_currentStoreId));
  }

  // L-1: テイクアウト管理
  function getTakeoutOrders(date, status) {
    return storeGet('/store/takeout-management.php', { date: date, status: status });
  }

  function patchTakeoutOrder(orderId, status) {
    return request('PATCH', '/store/takeout-management.php', { store_id: _currentStoreId, order_id: orderId, status: status });
  }

  // L-5: 領収書/インボイス
  function getReceiptSettings() {
    return storeGet('/store/receipt-settings.php');
  }
  function updateReceiptSettings(data) {
    data.store_id = _currentStoreId;
    return request('PATCH', '/store/receipt-settings.php', data);
  }
  function getReceiptsByDate(date) {
    return storeGet('/store/receipt.php', { date: date });
  }
  function getReceiptDetail(receiptId) {
    return request('GET', '/store/receipt.php?id=' + encodeURIComponent(receiptId) + '&detail=1');
  }
})();
