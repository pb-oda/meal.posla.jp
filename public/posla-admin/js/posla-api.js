/**
 * POSLA管理画面 APIクライアント
 */
var PoslaApi = (function() {
  'use strict';

  var BASE = '../../api/posla';

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
    return fetch(BASE + path, opts).then(function(res) {
      return res.text().then(function(text) {
        if (!text) return Promise.reject(new Error('サーバーが空のレスポンスを返しました'));
        var json;
        try { json = JSON.parse(text); }
        catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (json.error && json.error.message) || 'エラーが発生しました';
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    });
  }

  return {
    login: function(email, password) {
      return request('POST', '/login.php', { email: email, password: password });
    },
    logout: function() {
      return request('DELETE', '/logout.php');
    },
    me: function() {
      return request('GET', '/me.php');
    },
    getDashboard: function() {
      return request('GET', '/dashboard.php');
    },
    getTenants: function() {
      return request('GET', '/tenants.php');
    },
    getTenant: function(id) {
      return request('GET', '/tenants.php?id=' + encodeURIComponent(id));
    },
    updateTenant: function(id, data) {
      data.id = id;
      return request('PATCH', '/tenants.php', data);
    },
    createTenant: function(data) {
      return request('POST', '/tenants.php', data);
    },
    getSettings: function() {
      return request('GET', '/settings.php');
    },
    updateSettings: function(data) {
      return request('PATCH', '/settings.php', data);
    },
    getPushVapid: function() {
      return request('GET', '/push-vapid.php');
    },
    runPushVapidAction: function(action, payload) {
      var body = payload || {};
      body.action = action;
      return request('POST', '/push-vapid.php', body);
    },
    runMonitorAction: function(action, payload) {
      var body = payload || {};
      body.action = action;
      return request('POST', '/monitor-actions.php', body);
    },
    getAdminUsers: function() {
      return request('GET', '/admin-users.php');
    },
    createAdminUser: function(data) {
      return request('POST', '/admin-users.php', data);
    },
    updateAdminUser: function(data) {
      return request('PATCH', '/admin-users.php', data);
    },
    getCustomerSupportSessions: function(params) {
      params = params || {};
      var query = [];
      if (params.tenant_id) query.push('tenant_id=' + encodeURIComponent(params.tenant_id));
      if (params.store_id) query.push('store_id=' + encodeURIComponent(params.store_id));
      if (params.status) query.push('status=' + encodeURIComponent(params.status));
      if (params.limit) query.push('limit=' + encodeURIComponent(String(params.limit)));
      return request('GET', '/customer-support.php' + (query.length ? ('?' + query.join('&')) : ''));
    },
    getCustomerSupportDetail: function(sessionId) {
      return request('GET', '/customer-support.php?session_id=' + encodeURIComponent(sessionId));
    },
    // HELPDESK-P2-NONAI-ALL-20260425: AI helpdesk 系は retire 済。
  };
})();
