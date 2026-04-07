/**
 * セッションタイムアウト監視（D-1）
 *
 * 25分操作なしで警告モーダル表示。
 * 30分操作なしでバックエンドが SESSION_TIMEOUT を返す → ログインページへリダイレクト。
 * ユーザー操作（click, keydown, scroll, touchstart）でタイマーリセット。
 *
 * ES5 IIFE パターン
 */
(function () {
  'use strict';

  var WARNING_MS  = 25 * 60 * 1000; // 25分
  var CHECK_API   = '/api/auth/check.php';
  var LOGIN_PATH  = '/admin/index.html';

  var warningTimer = null;
  var overlay      = null;

  // ── タイマー管理 ──

  function resetTimer() {
    if (warningTimer) clearTimeout(warningTimer);
    hideWarning();
    warningTimer = setTimeout(showWarning, WARNING_MS);
  }

  // ── 警告モーダル ──

  function createOverlay() {
    if (overlay) return overlay;

    overlay = document.createElement('div');
    overlay.id = 'session-timeout-overlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;'
      + 'background:rgba(0,0,0,0.5);z-index:10000;display:none;'
      + 'align-items:center;justify-content:center;';

    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:12px;padding:32px 28px;'
      + 'max-width:360px;width:90%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,0.2);';

    var title = document.createElement('h3');
    title.style.cssText = 'margin:0 0 12px;font-size:18px;color:#333;';
    title.textContent = 'セッションタイムアウト';

    var msg = document.createElement('p');
    msg.style.cssText = 'margin:0 0 24px;font-size:14px;color:#666;line-height:1.6;';
    msg.textContent = '5分以内に操作がないとログアウトされます';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.style.cssText = 'background:#2563eb;color:#fff;border:none;border-radius:8px;'
      + 'padding:12px 32px;font-size:15px;cursor:pointer;font-weight:bold;';
    btn.textContent = '操作を続ける';
    btn.addEventListener('click', function () {
      onContinue();
    });

    card.appendChild(title);
    card.appendChild(msg);
    card.appendChild(btn);
    overlay.appendChild(card);
    document.body.appendChild(overlay);

    return overlay;
  }

  function showWarning() {
    var el = createOverlay();
    el.style.display = 'flex';
  }

  function hideWarning() {
    if (overlay) overlay.style.display = 'none';
  }

  function onContinue() {
    hideWarning();
    // check API を呼んで last_active_at をリセット
    fetch(CHECK_API, { method: 'GET', credentials: 'same-origin' })
      .then(function (res) {
        return res.text();
      })
      .then(function (text) {
        var json = JSON.parse(text);
        if (!json.ok) {
          redirectToLogin();
          return;
        }
        resetTimer();
      })
      .catch(function () {
        // ネットワークエラー等 → リダイレクトしない（次回APIコールで判定）
        resetTimer();
      });
  }

  function redirectToLogin() {
    window.location.href = LOGIN_PATH;
  }

  // ── 401検知（グローバル fetch インターセプト） ──

  var originalFetch = window.fetch;
  window.fetch = function () {
    return originalFetch.apply(this, arguments).then(function (response) {
      if (response.status === 401) {
        // レスポンスをクローンして SESSION_TIMEOUT チェック
        var cloned = response.clone();
        cloned.text().then(function (text) {
          try {
            var json = JSON.parse(text);
            if (json.error === 'SESSION_TIMEOUT' || json.error === 'SESSION_REVOKED') {
              redirectToLogin();
            }
          } catch (e) {
            // パース失敗は無視
          }
        });
      }
      return response;
    });
  };

  // ── ユーザー操作イベント監視 ──

  var events = ['click', 'keydown', 'scroll', 'touchstart'];
  for (var i = 0; i < events.length; i++) {
    document.addEventListener(events[i], function () {
      resetTimer();
    }, { passive: true });
  }

  // ── 初期化 ──

  resetTimer();
})();
