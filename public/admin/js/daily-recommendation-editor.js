/**
 * 今日のおすすめ管理 (N-1)
 */
var DailyRecommendationEditor = (function () {
  'use strict';

  var _container = null;
  var _menuItems = []; // テンプレート + ローカルアイテム

  var _badgeTypes = [
    { value: 'recommend', label: 'おすすめ' },
    { value: 'popular', label: '人気' },
    { value: 'new', label: '新着' },
    { value: 'limited', label: '限定' },
    { value: 'today_only', label: '本日限り' }
  ];

  function init(container) {
    _container = container;
  }

  function load() {
    if (!AdminApi.getCurrentStore()) return;
    _container.innerHTML = '<span class="loading-spinner"></span> 読み込み中...';

    var today = new Date().toISOString().slice(0, 10);

    // おすすめ一覧 + メニューアイテム一覧を並列取得
    var pRec = AdminApi.getDailyRecommendations(today).catch(function () { return { recommendations: [] }; });
    var pMenu = AdminApi.getMenuOverrides().catch(function () { return { items: [] }; });
    var pLocal = AdminApi.getLocalItems().catch(function () { return { items: [] }; });

    Promise.all([pRec, pMenu, pLocal]).then(function (results) {
      var recs = results[0].recommendations || [];
      var templates = results[1].items || [];
      var locals = results[2].items || [];

      _menuItems = [];
      templates.forEach(function (t) {
        _menuItems.push({ id: t.template_id, name: t.name, source: 'template' });
      });
      locals.forEach(function (l) {
        _menuItems.push({ id: l.id, name: l.name, source: 'local' });
      });

      render(recs);
    }).catch(function (err) {
      _container.innerHTML = '<p style="color:red">' + Utils.escapeHtml(err.message) + '</p>';
    });
  }

  function render(recs) {
    var html = '';

    // 追加フォーム
    html += '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:12px">';
    html += '<div style="flex:1;min-width:160px"><label class="form-label" style="font-size:0.8rem">品目</label>';
    html += '<select class="form-input" id="rec-menu-item" style="font-size:0.85rem">';
    html += '<option value="">選択...</option>';
    _menuItems.forEach(function (m) {
      var tag = m.source === 'local' ? ' [限定]' : '';
      html += '<option value="' + m.id + '" data-source="' + m.source + '">'
        + Utils.escapeHtml(m.name + tag) + '</option>';
    });
    html += '</select></div>';
    html += '<div style="min-width:100px"><label class="form-label" style="font-size:0.8rem">バッジ</label>';
    html += '<select class="form-input" id="rec-badge-type" style="font-size:0.85rem">';
    _badgeTypes.forEach(function (bt) {
      html += '<option value="' + bt.value + '">' + bt.label + '</option>';
    });
    html += '</select></div>';
    html += '<div style="flex:1;min-width:120px"><label class="form-label" style="font-size:0.8rem">コメント</label>';
    html += '<input class="form-input" id="rec-comment" placeholder="例: 本日入荷！" maxlength="200" style="font-size:0.85rem"></div>';
    html += '<button class="btn btn-primary btn-sm" id="btn-add-rec">追加</button>';
    html += '</div>';

    // 一覧
    if (recs.length === 0) {
      html += '<p style="color:#999;font-size:0.85rem">今日のおすすめは設定されていません</p>';
    } else {
      html += '<div style="border:1px solid var(--border-color,#ddd);border-radius:8px;overflow:hidden">';
      recs.forEach(function (r) {
        var badgeLabel = '';
        _badgeTypes.forEach(function (bt) { if (bt.value === r.badge_type) badgeLabel = bt.label; });
        html += '<div style="display:flex;align-items:center;padding:8px 12px;border-bottom:1px solid var(--border-color,#eee);gap:8px">';
        html += '<span style="flex:1;font-size:0.9rem">' + Utils.escapeHtml(r.item_name || r.menu_item_id) + '</span>';
        html += '<span class="badge" style="font-size:0.75rem">' + Utils.escapeHtml(badgeLabel) + '</span>';
        if (r.comment) {
          html += '<span style="font-size:0.8rem;color:#888;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
            + Utils.escapeHtml(r.comment) + '</span>';
        }
        html += '<button class="btn btn-sm btn-danger" data-action="remove-rec" data-id="' + r.id + '">削除</button>';
        html += '</div>';
      });
      html += '</div>';
    }

    _container.innerHTML = html;

    // イベント
    document.getElementById('btn-add-rec').addEventListener('click', addRecommendation);
    _container.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-action="remove-rec"]');
      if (btn) removeRecommendation(btn.dataset.id);
    });
  }

  function addRecommendation() {
    var select = document.getElementById('rec-menu-item');
    var menuItemId = select.value;
    if (!menuItemId) { showToast('品目を選択してください', 'error'); return; }
    var selectedOption = select.options[select.selectedIndex];
    var source = selectedOption.dataset.source || 'template';

    var data = {
      menu_item_id: menuItemId,
      source: source,
      badge_type: document.getElementById('rec-badge-type').value,
      comment: document.getElementById('rec-comment').value.trim() || null,
      display_date: new Date().toISOString().slice(0, 10)
    };

    AdminApi.addDailyRecommendation(data).then(function () {
      showToast('おすすめを追加しました', 'success');
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  function removeRecommendation(id) {
    if (!confirm('このおすすめを削除しますか？')) return;
    AdminApi.removeDailyRecommendation(id).then(function () {
      showToast('削除しました', 'success');
      load();
    }).catch(function (err) {
      showToast(err.message, 'error');
    });
  }

  return {
    init: init,
    load: load
  };
})();
