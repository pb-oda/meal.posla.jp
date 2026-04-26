/**
 * POSLA管理画面 サポートコンソール
 *
 * - 管理者ユーザー管理タブ
 * - 顧客サポートタブ (read-only)
 */
var PoslaSupportConsole = (function() {
  'use strict';

  var _selectedSessionId = '';
  var _detailState = null;

  function mountAdminUsers(rootEl) {
    if (!rootEl) return;
    ensureStyle();
    if (!rootEl.getAttribute('data-posla-admin-users-mounted')) {
      rootEl.innerHTML = buildAdminUsersShell();
      bindAdminUsersEvents(rootEl);
      rootEl.setAttribute('data-posla-admin-users-mounted', '1');
    }
    loadAdminUsers();
  }

  function mountSupport(rootEl) {
    if (!rootEl) return;
    ensureStyle();
    if (!rootEl.getAttribute('data-posla-customer-support-mounted')) {
      rootEl.innerHTML = buildSupportShell();
      bindSupportEvents(rootEl);
      rootEl.setAttribute('data-posla-customer-support-mounted', '1');
    }
    loadCustomerSessions();
    mountSupportdesk();
  }

  function ensureStyle() {
    if (document.getElementById('posla-support-console-style')) return;
    var style = document.createElement('style');
    style.id = 'posla-support-console-style';
    style.textContent =
      '.posla-support-console{display:flex;flex-direction:column;gap:1rem;}'
      + '.posla-support-console__card{border:1px solid rgba(15,23,42,0.08);border-radius:18px;background:#fff;box-shadow:0 16px 36px rgba(15,23,42,0.06);overflow:hidden;}'
      + '.posla-support-console__body{padding:1.25rem 1.35rem;}'
      + '.posla-support-console__head{display:flex;justify-content:space-between;align-items:flex-start;gap:0.75rem;margin-bottom:1rem;}'
      + '.posla-support-console__title{display:block;font-size:1.05rem;font-weight:700;color:#132238;}'
      + '.posla-support-console__desc{display:block;margin-top:0.3rem;font-size:0.9rem;line-height:1.6;color:#526072;}'
      + '.posla-support-console__meta{font-size:0.86rem;color:#6b7280;text-align:right;white-space:nowrap;}'
      + '.posla-support-console__summary{display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.9rem;}'
      + '.posla-support-pill{display:inline-flex;align-items:center;gap:0.35rem;padding:0.38rem 0.65rem;border-radius:999px;background:#eef2ff;color:#233876;font-size:0.82rem;font-weight:600;}'
      + '.posla-support-pill--warn{background:#fff4db;color:#9a5b00;}'
      + '.posla-support-pill--danger{background:#fee2e2;color:#b42318;}'
      + '.posla-support-pill--ok{background:#dcfce7;color:#166534;}'
      + '.posla-support-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0.75rem;align-items:end;margin-bottom:1rem;}'
      + '.posla-support-form-grid .form-group{margin-bottom:0;}'
      + '.posla-support-form-actions{display:flex;gap:0.5rem;align-items:center;justify-content:flex-end;}'
      + '.posla-support-inline-note{min-height:1.25rem;font-size:0.86rem;color:#6b7280;margin-bottom:0.75rem;}'
      + '.posla-support-inline-note--error{color:#b42318;}'
      + '.posla-support-inline-note--ok{color:#166534;}'
      + '.posla-support-inline-note--warn{color:#9a5b00;}'
      + '.posla-support-table-wrap{overflow:auto;border:1px solid rgba(15,23,42,0.08);border-radius:14px;}'
      + '.posla-support-table{width:100%;border-collapse:collapse;font-size:0.9rem;}'
      + '.posla-support-table th,.posla-support-table td{padding:0.75rem 0.8rem;border-bottom:1px solid rgba(15,23,42,0.06);text-align:left;vertical-align:top;}'
      + '.posla-support-table th{background:#f8fafc;color:#475467;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;}'
      + '.posla-support-table tr:last-child td{border-bottom:none;}'
      + '.posla-support-actions{display:flex;flex-wrap:wrap;gap:0.35rem;}'
      + '.posla-support-session-shell{display:grid;grid-template-columns:minmax(300px,0.9fr) minmax(420px,1.1fr);gap:0.85rem;min-height:520px;}'
      + '.posla-support-session-list{border:1px solid rgba(15,23,42,0.08);border-radius:14px;background:#f8fafc;overflow:auto;max-height:720px;}'
      + '.posla-support-session-item{padding:0.85rem 0.9rem;border-bottom:1px solid rgba(15,23,42,0.08);cursor:pointer;background:#fff;}'
      + '.posla-support-session-item:last-child{border-bottom:none;}'
      + '.posla-support-session-item.is-active{background:#eef4ff;border-left:4px solid #3559e0;padding-left:0.65rem;}'
      + '.posla-support-session-item__top{display:flex;justify-content:space-between;gap:0.75rem;align-items:flex-start;}'
      + '.posla-support-session-item__title{font-weight:700;color:#132238;}'
      + '.posla-support-session-item__meta{margin-top:0.2rem;font-size:0.82rem;color:#667085;line-height:1.5;}'
      + '.posla-support-session-item__stats{display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.55rem;}'
      + '.posla-support-chip{display:inline-flex;align-items:center;padding:0.26rem 0.55rem;border-radius:999px;background:#eef2ff;color:#233876;font-size:0.76rem;font-weight:700;}'
      + '.posla-support-chip--danger{background:#fee2e2;color:#b42318;}'
      + '.posla-support-chip--warn{background:#fff4db;color:#9a5b00;}'
      + '.posla-support-chip--ok{background:#dcfce7;color:#166534;}'
      + '.posla-support-session-detail{border:1px solid rgba(15,23,42,0.08);border-radius:14px;background:#fff;overflow:auto;max-height:720px;}'
      + '.posla-support-detail-body{padding:1rem 1.05rem;}'
      + '.posla-support-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0.75rem;margin-bottom:1rem;}'
      + '.posla-support-detail-box{padding:0.8rem 0.9rem;border-radius:14px;background:#f8fafc;border:1px solid rgba(15,23,42,0.06);}'
      + '.posla-support-detail-box__label{display:block;font-size:0.78rem;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:0.04em;}'
      + '.posla-support-detail-box__value{display:block;margin-top:0.3rem;font-size:0.95rem;color:#132238;line-height:1.55;}'
      + '.posla-support-detail-section{margin-top:1rem;}'
      + '.posla-support-detail-section__title{font-size:0.92rem;font-weight:700;color:#132238;margin-bottom:0.5rem;}'
      + '.posla-support-detail-actions{display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.9rem;}'
      + '.posla-support-empty{padding:1.5rem;color:#667085;text-align:center;}'
      + '.posla-support-filterbar{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 170px auto;gap:0.65rem;align-items:end;margin-bottom:0.95rem;}'
      + '.posla-support-helpdesk-host{min-height:240px;}'
      + '@media (max-width:1100px){.posla-support-session-shell{grid-template-columns:1fr;}.posla-support-filterbar,.posla-support-form-grid,.posla-support-detail-grid{grid-template-columns:1fr;}}';
    document.head.appendChild(style);
  }

  function buildAdminUsersShell() {
    return ''
      + '<div class="posla-support-console">'
      + '  <section class="posla-support-console__card">'
      + '    <div class="posla-support-console__body">'
      + '      <div class="posla-support-console__head">'
      + '        <div>'
      + '          <span class="posla-support-console__title">POSLA 管理者ユーザー管理</span>'
      + '          <span class="posla-support-console__desc">POSLA 管理者の追加、表示名変更、無効化、削除、パスワード再設定を行います。追加時にはログイン案内メールを自動送信し、初期パスワードの変更を促します。</span>'
      + '        </div>'
      + '        <div class="posla-support-console__meta" id="posla-admin-users-meta">読み込み中...</div>'
      + '      </div>'
      + '      <div class="posla-support-console__summary" id="posla-admin-users-summary"></div>'
      + '      <div class="posla-support-form-grid">'
      + '        <div class="form-group"><label class="form-label" for="posla-admin-user-email">メールアドレス</label><input class="form-input" type="email" id="posla-admin-user-email" placeholder="operator@meal.posla.jp"></div>'
      + '        <div class="form-group"><label class="form-label" for="posla-admin-user-name">表示名</label><input class="form-input" type="text" id="posla-admin-user-name" placeholder="POSLA Operator"></div>'
      + '        <div class="form-group"><label class="form-label" for="posla-admin-user-password">初期パスワード</label><input class="form-input" type="password" id="posla-admin-user-password" placeholder="初期パスワード"></div>'
      + '      </div>'
      + '      <div class="posla-support-form-actions"><button class="btn btn-primary" type="button" id="btn-posla-admin-user-create">管理者を追加</button></div>'
      + '      <div class="posla-support-inline-note" id="posla-admin-users-note">未実行</div>'
      + '      <div class="posla-support-table-wrap" id="posla-admin-users-list"></div>'
      + '    </div>'
      + '  </section>'
      + '</div>';
  }

  function buildSupportShell() {
    return ''
      + '<div class="posla-support-console">'
      + '  <section class="posla-support-console__card">'
      + '    <div class="posla-support-console__body">'
      + '      <div class="posla-support-console__head">'
      + '        <div>'
      + '          <span class="posla-support-console__title">顧客サポートビュー</span>'
      + '          <span class="posla-support-console__desc">現在の customer セッションを read-only で把握し、注文状況、直近カート操作、顧客URL を確認します。サポート用途の閲覧専用です。</span>'
      + '        </div>'
      + '        <div class="posla-support-console__meta" id="posla-customer-support-meta">読み込み中...</div>'
      + '      </div>'
      + '      <div class="posla-support-console__summary" id="posla-customer-support-summary"></div>'
      + '      <div class="posla-support-filterbar">'
      + '        <div class="form-group"><label class="form-label" for="posla-customer-support-tenant">テナントID</label><input class="form-input" type="text" id="posla-customer-support-tenant" placeholder="空欄で全件"></div>'
      + '        <div class="form-group"><label class="form-label" for="posla-customer-support-store">店舗ID</label><input class="form-input" type="text" id="posla-customer-support-store" placeholder="空欄で全件"></div>'
      + '        <div class="form-group"><label class="form-label" for="posla-customer-support-status">状態</label><select class="form-input" id="posla-customer-support-status"><option value="active">active</option><option value="all">all</option><option value="bill_requested">bill_requested</option><option value="eating">eating</option><option value="seated">seated</option></select></div>'
      + '        <div class="posla-support-form-actions"><button class="btn" type="button" id="btn-posla-customer-support-refresh">最新状態を取得</button></div>'
      + '      </div>'
      + '      <div class="posla-support-session-shell">'
      + '        <div class="posla-support-session-list" id="posla-customer-support-list"><div class="posla-support-empty">読み込み中...</div></div>'
      + '        <div class="posla-support-session-detail" id="posla-customer-support-detail"><div class="posla-support-empty">左の一覧から対象セッションを選択してください。</div></div>'
      + '      </div>'
      + '    </div>'
      + '  </section>'
      + '  <section class="posla-support-console__card">'
      + '    <div class="posla-support-console__body">'
      + '      <div class="posla-support-console__head">'
      + '        <div>'
      + '          <span class="posla-support-console__title">運用ナレッジ</span>'
      + '          <span class="posla-support-console__desc">よくある問い合わせ、確認手順、既存サポートセンターの情報を下段にまとめます。</span>'
      + '        </div>'
      + '      </div>'
      + '      <div class="posla-support-helpdesk-host" id="posla-supportdesk-host"></div>'
      + '    </div>'
      + '  </section>'
      + '</div>';
  }

  function bindAdminUsersEvents(rootEl) {
    if (!rootEl) return;

    rootEl.addEventListener('click', function(e) {
      var target = e.target;
      var action = target && target.getAttribute ? target.getAttribute('data-action') : '';
      if (!action) return;

      if (action === 'admin-rename') {
        handleAdminRename(target.getAttribute('data-id'), target.getAttribute('data-name'));
        return;
      }
      if (action === 'admin-toggle') {
        handleAdminToggle(target.getAttribute('data-id'), target.getAttribute('data-active') === '1', target.getAttribute('data-self') === '1');
        return;
      }
      if (action === 'admin-password') {
        handleAdminPasswordReset(target.getAttribute('data-id'), target.getAttribute('data-self') === '1');
        return;
      }
      if (action === 'admin-delete') {
        handleAdminDelete(target.getAttribute('data-id'), target.getAttribute('data-email'), target.getAttribute('data-self') === '1');
      }
    });

    var createBtn = document.getElementById('btn-posla-admin-user-create');
    if (createBtn) {
      createBtn.addEventListener('click', createAdminUser);
    }
  }

  function bindSupportEvents(rootEl) {
    if (!rootEl) return;

    rootEl.addEventListener('click', function(e) {
      var target = e.target;
      var action = target && target.getAttribute ? target.getAttribute('data-action') : '';
      if (!action) return;

      if (action === 'select-support-session') {
        loadCustomerSessionDetail(target.getAttribute('data-session-id'));
        return;
      }
      if (action === 'copy-customer-url') {
        handleCopyUrl(target.getAttribute('data-url'));
        return;
      }
      if (action === 'open-support-preview') {
        handleOpenSupportPreview(target.getAttribute('data-url'));
      }
    });

    var refreshBtn = document.getElementById('btn-posla-customer-support-refresh');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', loadCustomerSessions);
    }
  }

  function mountSupportdesk() {
    var hostEl = document.getElementById('posla-supportdesk-host');
    if (!hostEl) return;
    if (typeof PoslaSupportdesk === 'undefined' || !PoslaSupportdesk || !PoslaSupportdesk.mount) {
      hostEl.innerHTML = '<div class="posla-support-empty" style="color:#b42318;">posla-supportdesk.js の読み込みに失敗しています。</div>';
      return;
    }
    PoslaSupportdesk.mount(hostEl);
  }

  function loadAdminUsers() {
    var metaEl = document.getElementById('posla-admin-users-meta');
    var listEl = document.getElementById('posla-admin-users-list');
    var summaryEl = document.getElementById('posla-admin-users-summary');
    if (!metaEl || !listEl || !summaryEl) return;

    metaEl.textContent = '読み込み中...';
    listEl.innerHTML = '<div class="posla-support-empty">POSLA 管理者を読み込み中...</div>';
    summaryEl.innerHTML = '';

    PoslaApi.getAdminUsers().then(function(data) {
      renderAdminUsers(data);
    }).catch(function(err) {
      metaEl.textContent = '取得失敗';
      listEl.innerHTML = '<div class="posla-support-empty" style="color:#b42318;">管理者一覧の取得に失敗しました: ' + esc(err.message) + '</div>';
    });
  }

  function renderAdminUsers(data) {
    var metaEl = document.getElementById('posla-admin-users-meta');
    var listEl = document.getElementById('posla-admin-users-list');
    var summaryEl = document.getElementById('posla-admin-users-summary');
    if (!metaEl || !listEl || !summaryEl) return;

    var admins = data && data.admins ? data.admins : [];
    var summary = data && data.summary ? data.summary : { total: 0, active: 0, inactive: 0 };
    var currentAdminId = data && data.current_admin_id ? data.current_admin_id : '';
    var html = '';
    var i;
    var row;
    var activeBadge;
    var actionHtml;

    summaryEl.innerHTML =
      buildPill('総数 ' + summary.total, '') +
      buildPill('有効 ' + summary.active, 'ok') +
      buildPill('無効 ' + summary.inactive, summary.inactive ? 'warn' : '');
    metaEl.textContent = admins.length + ' 件 / 最終更新 ' + formatDateTime(new Date().toISOString());

    if (!admins.length) {
      listEl.innerHTML = '<div class="posla-support-empty">POSLA 管理者が見つかりません。</div>';
      return;
    }

    html += '<table class="posla-support-table"><thead><tr>'
      + '<th>表示名</th><th>メール</th><th>状態</th><th>最終ログイン</th><th>有効セッション</th><th>操作</th>'
      + '</tr></thead><tbody>';

    for (i = 0; i < admins.length; i++) {
      row = admins[i];
      activeBadge = (parseInt(row.is_active, 10) === 1)
        ? '<span class="posla-support-chip posla-support-chip--ok">有効</span>'
        : '<span class="posla-support-chip posla-support-chip--danger">無効</span>';
      actionHtml = '<div class="posla-support-actions">'
        + '<button class="btn btn-sm btn-outline" type="button" data-action="admin-rename" data-id="' + esc(row.id) + '" data-name="' + esc(row.display_name || '') + '">表示名変更</button>'
        + '<button class="btn btn-sm btn-outline" type="button" data-action="admin-password" data-id="' + esc(row.id) + '" data-self="' + (row.id === currentAdminId ? '1' : '0') + '"' + (row.id === currentAdminId ? ' disabled' : '') + '>PW再設定</button>'
        + '<button class="btn btn-sm ' + (parseInt(row.is_active, 10) === 1 ? 'btn-outline' : 'btn-primary') + '" type="button" data-action="admin-toggle" data-id="' + esc(row.id) + '" data-self="' + (row.id === currentAdminId ? '1' : '0') + '" data-active="' + (parseInt(row.is_active, 10) === 1 ? '1' : '0') + '"' + (row.id === currentAdminId ? ' disabled' : '') + '>'
        + (parseInt(row.is_active, 10) === 1 ? '無効化' : '再有効化')
        + '</button>'
        + '<button class="btn btn-sm btn-outline" style="border-color:#f5c2c7;color:#b42318;" type="button" data-action="admin-delete" data-id="' + esc(row.id) + '" data-email="' + esc(row.email || '') + '" data-self="' + (row.id === currentAdminId ? '1' : '0') + '"' + (row.id === currentAdminId ? ' disabled' : '') + '>削除</button>'
        + '</div>';

      html += '<tr>'
        + '<td>' + esc(row.display_name || '-') + (row.id === currentAdminId ? ' <span class="posla-support-chip">現在のアカウント</span>' : '') + '</td>'
        + '<td><code>' + esc(row.email || '-') + '</code></td>'
        + '<td>' + activeBadge + '</td>'
        + '<td>' + esc(formatDateTime(row.last_login_at || '')) + '</td>'
        + '<td>' + esc(String(row.active_session_count || 0)) + '</td>'
        + '<td>' + actionHtml + '</td>'
        + '</tr>';
    }

    html += '</tbody></table>';
    listEl.innerHTML = html;
  }

  function createAdminUser() {
    var emailEl = document.getElementById('posla-admin-user-email');
    var nameEl = document.getElementById('posla-admin-user-name');
    var passwordEl = document.getElementById('posla-admin-user-password');
    var noteEl = document.getElementById('posla-admin-users-note');
    var btn = document.getElementById('btn-posla-admin-user-create');
    if (!emailEl || !nameEl || !passwordEl || !noteEl || !btn) return;

    var email = trim(emailEl.value);
    var displayName = trim(nameEl.value);
    var password = passwordEl.value;
    if (!email || !displayName || !password) {
      setInlineNote(noteEl, 'メールアドレス、表示名、初期パスワードを入力してください', 'error');
      return;
    }

    btn.disabled = true;
    setInlineNote(noteEl, '管理者を作成中...', '');
    PoslaApi.createAdminUser({
      email: email,
      display_name: displayName,
      password: password
    }).then(function(data) {
      emailEl.value = '';
      nameEl.value = '';
      passwordEl.value = '';
      if (data && data.mail_sent === false) {
        setInlineNote(noteEl, data.mail_warning || 'POSLA 管理者は追加済みですが、通知メールは送信できませんでした。初期パスワードを手動で案内してください。', 'warn');
      } else {
        setInlineNote(noteEl, 'POSLA 管理者を追加しました。ログイン案内メールも送信しました。', 'ok');
      }
      loadAdminUsers();
    }).catch(function(err) {
      setInlineNote(noteEl, '作成に失敗しました: ' + err.message, 'error');
    }).then(function() {
      btn.disabled = false;
    });
  }

  function handleAdminRename(id, currentName) {
    if (!id) return;
    var nextName = window.prompt('新しい表示名を入力してください', currentName || '');
    if (nextName === null) return;
    nextName = trim(nextName);
    if (!nextName) {
      window.alert('表示名は空にできません');
      return;
    }
    PoslaApi.updateAdminUser({ id: id, display_name: nextName }).then(function() {
      loadAdminUsers();
      if (typeof window.PoslaShowToast === 'function') window.PoslaShowToast('表示名を更新しました');
    }).catch(function(err) {
      window.alert('表示名の更新に失敗しました: ' + err.message);
    });
  }

  function handleAdminToggle(id, isActive, isSelf) {
    if (!id) return;
    if (isSelf) {
      window.alert('現在ログイン中のアカウントはこの画面から変更できません');
      return;
    }
    var nextActive = isActive ? 0 : 1;
    var confirmMsg = isActive
      ? 'この POSLA 管理者を無効化します。よろしいですか？'
      : 'この POSLA 管理者を再有効化します。よろしいですか？';
    if (!window.confirm(confirmMsg)) return;

    PoslaApi.updateAdminUser({ id: id, is_active: nextActive }).then(function() {
      loadAdminUsers();
      if (typeof window.PoslaShowToast === 'function') window.PoslaShowToast(isActive ? '管理者を無効化しました' : '管理者を再有効化しました');
    }).catch(function(err) {
      window.alert('管理者状態の更新に失敗しました: ' + err.message);
    });
  }

  function handleAdminPasswordReset(id, isSelf) {
    if (!id) return;
    if (isSelf) {
      window.alert('自分自身のパスワード変更は右上メニューの「パスワード変更」を使ってください');
      return;
    }
    var nextPassword = window.prompt('新しいパスワードを入力してください');
    if (nextPassword === null) return;
    nextPassword = String(nextPassword);
    if (!nextPassword) {
      window.alert('新しいパスワードを入力してください');
      return;
    }
    var confirmPassword = window.prompt('確認のため、もう一度同じパスワードを入力してください');
    if (confirmPassword === null) return;
    if (nextPassword !== String(confirmPassword)) {
      window.alert('確認用パスワードが一致しません');
      return;
    }
    PoslaApi.updateAdminUser({ id: id, new_password: nextPassword }).then(function() {
      loadAdminUsers();
      if (typeof window.PoslaShowToast === 'function') window.PoslaShowToast('管理者パスワードを再設定しました');
    }).catch(function(err) {
      window.alert('パスワード再設定に失敗しました: ' + err.message);
    });
  }

  function handleAdminDelete(id, email, isSelf) {
    if (!id) return;
    if (isSelf) {
      window.alert('現在ログイン中の管理者は削除できません');
      return;
    }

    var firstConfirm = 'POSLA 管理者「' + (email || id) + '」を削除します。\n\n'
      + '削除するとこの管理者のセッションは失効し、管理者一覧から消えます。\n'
      + '最後の有効管理者はAPI側で削除を拒否します。\n\n'
      + '続行しますか？';
    if (!window.confirm(firstConfirm)) return;

    var confirmEmail = window.prompt('削除確認のため、管理者メールアドレスを入力してください: ' + (email || ''));
    if (confirmEmail === null) return;
    confirmEmail = trim(confirmEmail);
    if (confirmEmail !== email) {
      window.alert('メールアドレスが一致しないため削除しませんでした');
      return;
    }

    PoslaApi.deleteAdminUser({
      id: id,
      confirm_email: confirmEmail
    }).then(function() {
      loadAdminUsers();
      if (typeof window.PoslaShowToast === 'function') window.PoslaShowToast('管理者を削除しました');
    }).catch(function(err) {
      window.alert('管理者削除に失敗しました: ' + err.message);
    });
  }

  function loadCustomerSessions() {
    var summaryEl = document.getElementById('posla-customer-support-summary');
    var listEl = document.getElementById('posla-customer-support-list');
    var metaEl = document.getElementById('posla-customer-support-meta');
    var tenantEl = document.getElementById('posla-customer-support-tenant');
    var storeEl = document.getElementById('posla-customer-support-store');
    var statusEl = document.getElementById('posla-customer-support-status');
    if (!summaryEl || !listEl || !metaEl || !tenantEl || !storeEl || !statusEl) return;

    summaryEl.innerHTML = '';
    metaEl.textContent = '読み込み中...';
    listEl.innerHTML = '<div class="posla-support-empty">顧客セッションを読み込み中...</div>';

    PoslaApi.getCustomerSupportSessions({
      tenant_id: trim(tenantEl.value),
      store_id: trim(storeEl.value),
      status: statusEl.value,
      limit: 30
    }).then(function(data) {
      renderCustomerSessions(data);
    }).catch(function(err) {
      metaEl.textContent = '取得失敗';
      listEl.innerHTML = '<div class="posla-support-empty" style="color:#b42318;">顧客セッションの取得に失敗しました: ' + esc(err.message) + '</div>';
      renderCustomerDetailEmpty('顧客セッションを取得できませんでした。');
    });
  }

  function renderCustomerSessions(data) {
    var summaryEl = document.getElementById('posla-customer-support-summary');
    var listEl = document.getElementById('posla-customer-support-list');
    var metaEl = document.getElementById('posla-customer-support-meta');
    if (!summaryEl || !listEl || !metaEl) return;

    var sessions = data && data.sessions ? data.sessions : [];
    var summary = data && data.summary ? data.summary : { total: 0, bill_requested: 0, needs_attention: 0 };
    var html = '';
    var i;
    var row;

    summaryEl.innerHTML =
      buildPill('セッション ' + summary.total, '') +
      buildPill('会計待ち ' + summary.bill_requested, summary.bill_requested ? 'warn' : '') +
      buildPill('要確認 ' + summary.needs_attention, summary.needs_attention ? 'danger' : 'ok');
    metaEl.textContent = sessions.length + ' 件 / 最終更新 ' + formatDateTime(new Date().toISOString());

    if (!sessions.length) {
      listEl.innerHTML = '<div class="posla-support-empty">表示対象の customer セッションはありません。</div>';
      renderCustomerDetailEmpty('active な customer セッションはありません。');
      _selectedSessionId = '';
      _detailState = null;
      return;
    }

    for (i = 0; i < sessions.length; i++) {
      row = sessions[i];
      html += buildSupportSessionItem(row, row.id === _selectedSessionId);
    }
    listEl.innerHTML = html;

    if (!_selectedSessionId || !sessionExists(sessions, _selectedSessionId)) {
      _selectedSessionId = sessions[0].id;
    }
    loadCustomerSessionDetail(_selectedSessionId);
  }

  function buildSupportSessionItem(row, active) {
    var state = row.state || {};
    var stateKind = stateKindFromCode(state.code);
    return ''
      + '<div class="posla-support-session-item' + (active ? ' is-active' : '') + '" data-action="select-support-session" data-session-id="' + esc(row.id) + '">'
      + '  <div class="posla-support-session-item__top">'
      + '    <div>'
      + '      <div class="posla-support-session-item__title">' + esc(row.tenant_name || '-') + ' / ' + esc(row.store_name || '-') + '</div>'
      + '      <div class="posla-support-session-item__meta">卓 ' + esc(row.table_code || '-') + ' / ' + esc(row.session_status || '-') + ' / token ' + esc(row.session_token_preview || '-') + '</div>'
      + '    </div>'
      + '    <span class="posla-support-chip posla-support-chip--' + esc(stateKind) + '">' + esc(state.label || '-') + '</span>'
      + '  </div>'
      + '  <div class="posla-support-session-item__meta">started: ' + esc(formatDateTime(row.started_at || '')) + ' / guests: ' + esc(String(row.guest_count || 0)) + '</div>'
      + '  <div class="posla-support-session-item__stats">'
      + buildChip('注文 ' + String(row.open_order_count || 0), row.open_order_count ? 'warn' : '')
      + buildChip('カート操作 ' + String(row.cart_event_count || 0), row.cart_event_count ? '' : '')
      + buildChip('¥' + formatYen(row.total_amount || 0), '')
      + (state.inactive_min != null ? buildChip('停止 ' + state.inactive_min + '分', state.inactive_min >= 15 ? 'danger' : '') : '')
      + '  </div>'
      + '</div>';
  }

  function loadCustomerSessionDetail(sessionId) {
    var detailEl = document.getElementById('posla-customer-support-detail');
    if (!detailEl || !sessionId) return;
    _selectedSessionId = sessionId;
    detailEl.innerHTML = '<div class="posla-support-empty">セッション詳細を読み込み中...</div>';
    markSelectedSession();

    PoslaApi.getCustomerSupportDetail(sessionId).then(function(data) {
      _detailState = data || null;
      renderCustomerSessionDetail(data || {});
      markSelectedSession();
    }).catch(function(err) {
      _detailState = null;
      detailEl.innerHTML = '<div class="posla-support-empty" style="color:#b42318;">セッション詳細の取得に失敗しました: ' + esc(err.message) + '</div>';
    });
  }

  function renderCustomerSessionDetail(data) {
    var detailEl = document.getElementById('posla-customer-support-detail');
    if (!detailEl) return;

    var session = data && data.session ? data.session : {};
    var state = session.state || {};
    var orders = data && data.orders ? data.orders : [];
    var cartEvents = data && data.cart_events ? data.cart_events : [];
    var itemStatus = data && data.item_status ? data.item_status : {};
    var stateKind = stateKindFromCode(state.code);
    var orderHtml = buildOrdersTable(orders);
    var cartHtml = buildCartEventsTable(cartEvents);
    var itemStatusHtml = buildItemStatusLine(itemStatus);
    var actionHtml = '';

    if (session.customer_menu_url) {
      actionHtml += '<button class="btn btn-outline" type="button" data-action="copy-customer-url" data-url="' + esc(session.customer_menu_url) + '">顧客メニューURLをコピー</button>';
    }
    if (session.support_preview_url) {
      actionHtml += '<button class="btn" type="button" data-action="open-support-preview" data-url="' + esc(session.support_preview_url) + '">サポートプレビューを開く</button>';
    }

    detailEl.innerHTML = ''
      + '<div class="posla-support-detail-body">'
      + '  <div class="posla-support-console__head">'
      + '    <div>'
      + '      <span class="posla-support-console__title">' + esc(session.tenant_name || '-') + ' / ' + esc(session.store_name || '-') + ' / 卓 ' + esc(session.table_code || '-') + '</span>'
      + '      <span class="posla-support-console__desc">' + esc(state.note || '顧客セッションの状態を表示しています。') + '</span>'
      + '    </div>'
      + '    <div class="posla-support-console__meta"><span class="posla-support-chip posla-support-chip--' + esc(stateKind) + '">' + esc(state.label || '-') + '</span></div>'
      + '  </div>'
      + '  <div class="posla-support-detail-actions">' + (actionHtml || '<span class="posla-support-chip">現在の customer URL は生成できません</span>') + '</div>'
      + '  <div class="posla-support-detail-grid">'
      + '    <div class="posla-support-detail-box"><span class="posla-support-detail-box__label">セッション</span><span class="posla-support-detail-box__value"><code>' + esc(session.id || '-') + '</code><br>status: ' + esc(session.session_status || '-') + '<br>token: ' + esc(session.session_token_preview || '-') + '</span></div>'
      + '    <div class="posla-support-detail-box"><span class="posla-support-detail-box__label">開始情報</span><span class="posla-support-detail-box__value">started: ' + esc(formatDateTime(session.started_at || '')) + '<br>guests: ' + esc(String(session.guest_count || 0)) + '<br>plan: ' + esc(session.plan_name || '-') + '</span></div>'
      + '    <div class="posla-support-detail-box"><span class="posla-support-detail-box__label">直近操作</span><span class="posla-support-detail-box__value">last cart: ' + esc(formatDateTime(session.last_cart_at || '')) + '<br>last order: ' + esc(formatDateTime(session.last_order_at || '')) + '<br>inactive: ' + esc(state.inactive_min != null ? (state.inactive_min + ' 分') : '-') + '</span></div>'
      + '    <div class="posla-support-detail-box"><span class="posla-support-detail-box__label">注文サマリー</span><span class="posla-support-detail-box__value">open orders: ' + esc(String(session.open_order_count || 0)) + '<br>cart events: ' + esc(String(session.cart_event_count || 0)) + '<br>bill total: ¥' + esc(formatYen(session.total_amount || 0)) + '</span></div>'
      + '  </div>'
      + '  <div class="posla-support-detail-section"><div class="posla-support-detail-section__title">order_items 状態</div><div>' + itemStatusHtml + '</div></div>'
      + '  <div class="posla-support-detail-section"><div class="posla-support-detail-section__title">注文一覧</div>' + orderHtml + '</div>'
      + '  <div class="posla-support-detail-section"><div class="posla-support-detail-section__title">直近カート操作</div>' + cartHtml + '</div>'
      + '  <div class="posla-support-detail-section"><div class="posla-support-detail-section__title">API確認URL</div>'
      + '    <div class="posla-support-detail-box"><span class="posla-support-detail-box__value">'
      + 'bill API: ' + (session.customer_bill_api_url ? ('<code>' + esc(session.customer_bill_api_url) + '</code>') : '-') + '<br>'
      + 'order-status API: ' + (session.customer_order_status_api_url ? ('<code>' + esc(session.customer_order_status_api_url) + '</code>') : '-')
      + '    </span></div>'
      + '  </div>'
      + '</div>';
  }

  function renderCustomerDetailEmpty(message) {
    var detailEl = document.getElementById('posla-customer-support-detail');
    if (!detailEl) return;
    detailEl.innerHTML = '<div class="posla-support-empty">' + esc(message || '対象セッションを選択してください。') + '</div>';
  }

  function buildOrdersTable(orders) {
    var html = '';
    var i;
    var row;
    if (!orders.length) {
      return '<div class="posla-support-empty">注文はまだありません。</div>';
    }
    html += '<div class="posla-support-table-wrap"><table class="posla-support-table"><thead><tr><th>注文ID</th><th>状態</th><th>合計</th><th>商品概要</th><th>作成</th></tr></thead><tbody>';
    for (i = 0; i < orders.length; i++) {
      row = orders[i];
      html += '<tr>'
        + '<td><code>' + esc(row.id || '-') + '</code></td>'
        + '<td>' + buildChip(esc(row.status || '-'), stateKindFromOrderStatus(row.status || '')) + '</td>'
        + '<td>¥' + esc(formatYen(row.total_amount || 0)) + '</td>'
        + '<td>' + esc(row.item_summary || '-') + '<br><span style="color:#667085;font-size:0.82rem;">items: ' + esc(String(row.item_count || 0)) + '</span></td>'
        + '<td>' + esc(formatDateTime(row.created_at || '')) + '</td>'
        + '</tr>';
    }
    html += '</tbody></table></div>';
    return html;
  }

  function buildCartEventsTable(events) {
    var html = '';
    var i;
    var row;
    if (!events.length) {
      return '<div class="posla-support-empty">カート操作ログはありません。</div>';
    }
    html += '<div class="posla-support-table-wrap"><table class="posla-support-table"><thead><tr><th>時刻</th><th>操作</th><th>商品</th><th>価格</th></tr></thead><tbody>';
    for (i = 0; i < events.length; i++) {
      row = events[i];
      html += '<tr>'
        + '<td>' + esc(formatDateTime(row.created_at || '')) + '</td>'
        + '<td>' + buildChip(row.action === 'remove' ? 'remove' : 'add', row.action === 'remove' ? 'danger' : 'ok') + '</td>'
        + '<td>' + esc(row.item_name || '-') + '<br><span style="color:#667085;font-size:0.82rem;"><code>' + esc(row.item_id || '-') + '</code></span></td>'
        + '<td>¥' + esc(formatYen(row.item_price || 0)) + '</td>'
        + '</tr>';
    }
    html += '</tbody></table></div>';
    return html;
  }

  function buildItemStatusLine(map) {
    var parts = [];
    var key;
    for (key in map) {
      if (!Object.prototype.hasOwnProperty.call(map, key)) continue;
      parts.push(buildChip(key + ': ' + map[key], stateKindFromOrderStatus(key)));
    }
    return parts.length ? parts.join(' ') : '<span class="posla-support-chip">record なし</span>';
  }

  function handleCopyUrl(url) {
    url = String(url || '');
    if (!url) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function() {
        if (typeof window.PoslaShowToast === 'function') window.PoslaShowToast('顧客URLをコピーしました');
      }).catch(function() {
        window.prompt('このURLをコピーしてください', url);
      });
      return;
    }

    window.prompt('このURLをコピーしてください', url);
  }

  function handleOpenSupportPreview(url) {
    url = String(url || '');
    if (!url) return;
    if (!window.confirm('read-only のサポートプレビューを新しいタブで開きます。注文・会計・呼び出しは実行できません。続けますか？')) {
      return;
    }
    window.open(url, '_blank');
  }

  function markSelectedSession() {
    var nodes = document.querySelectorAll('[data-action="select-support-session"]');
    var i;
    var node;
    for (i = 0; i < nodes.length; i++) {
      node = nodes[i];
      if (node.getAttribute('data-session-id') === _selectedSessionId) {
        node.classList.add('is-active');
      } else {
        node.classList.remove('is-active');
      }
    }
  }

  function sessionExists(rows, id) {
    var i;
    for (i = 0; i < rows.length; i++) {
      if (rows[i].id === id) return true;
    }
    return false;
  }

  function setInlineNote(el, text, kind) {
    if (!el) return;
    el.className = 'posla-support-inline-note';
    if (kind === 'error') el.className += ' posla-support-inline-note--error';
    if (kind === 'ok') el.className += ' posla-support-inline-note--ok';
    if (kind === 'warn') el.className += ' posla-support-inline-note--warn';
    el.textContent = text;
  }

  function stateKindFromCode(code) {
    code = String(code || '');
    if (code === 'bill_requested' || code === 'inactive') return 'danger';
    if (code === 'browsing' || code === 'pending' || code === 'ready') return 'warn';
    if (code === 'preparing' || code === 'eating') return 'ok';
    return '';
  }

  function stateKindFromOrderStatus(code) {
    code = String(code || '');
    if (code === 'cancelled') return 'danger';
    if (code === 'pending') return 'warn';
    if (code === 'preparing' || code === 'ready' || code === 'served' || code === 'paid') return 'ok';
    return '';
  }

  function buildPill(label, kind) {
    return '<span class="posla-support-pill' + (kind ? ' posla-support-pill--' + esc(kind) : '') + '">' + esc(label) + '</span>';
  }

  function buildChip(label, kind) {
    return '<span class="posla-support-chip' + (kind ? ' posla-support-chip--' + esc(kind) : '') + '">' + esc(label) + '</span>';
  }

  function esc(value) {
    if (typeof Utils !== 'undefined' && Utils && Utils.escapeHtml) {
      return Utils.escapeHtml(String(value == null ? '' : value));
    }
    value = String(value == null ? '' : value);
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function trim(value) {
    return String(value == null ? '' : value).replace(/^\s+|\s+$/g, '');
  }

  function formatDateTime(value) {
    if (!value) return '-';
    return String(value).replace('T', ' ').replace(/\.\d+Z?$/, '').replace(/Z$/, '');
  }

  function formatYen(value) {
    value = parseInt(value, 10);
    if (isNaN(value)) value = 0;
    return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  return {
    mountAdminUsers: mountAdminUsers,
    mountSupport: mountSupport
  };
})();
