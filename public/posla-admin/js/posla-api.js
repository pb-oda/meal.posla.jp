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
    }
  };
})();
