/**
 * POSLA PWA インストール案内バナー (スタッフ管理画面用)
 *
 * 目的:
 *   POSLA をホーム画面に追加 (PWA インストール) して 1 タップ起動できることを案内する。
 *   業務集中度を下げないよう控えめに、画面下部に表示する。
 *
 * 対象:
 *   public/admin/dashboard.html (店舗運営)
 *   public/admin/owner-dashboard.html (オーナー管理)
 *
 * 顧客セルフメニュー (public/customer/) では絶対に表示しない (二重防御で URL 判定 skip)。
 *
 * 表示条件:
 *   - 既にインストール済み (display-mode: standalone) → 表示しない
 *   - 「後で」/「閉じる」を押した後 → 表示しない (localStorage 永続)
 *   - Android Chrome / PC Chrome / PC Edge: `beforeinstallprompt` 発火後に
 *     「ホーム画面に追加しますか?」+ [インストール] [後で] ボタンを表示
 *   - iOS Safari: イベント非対応のため、共有メニュー手順を案内するバナーを表示
 *   - 上記以外の環境 (PC Safari、古いブラウザ等): 表示しない
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal を使用しない。
 * innerHTML は固定文言のみ (ユーザー入力を絶対に挿入しない)。
 *
 * 使い方:
 *   <script src="../shared/js/install-prompt-banner.js?v=20260420-install"></script>
 *   <script>InstallPromptBanner.init('admin');</script>
 *
 *   scope は 'admin' / 'owner' 等を推奨。閉じた状態を画面別に保存するため。
 *
 *   オプション (Phase 1 拡張・後方互換性あり):
 *     InstallPromptBanner.init('kds', { iosHint: false });
 *     - iosHint: false にすると iOS Safari の「共有メニュー → ホーム画面に追加」
 *       案内を出さない。業務端末 (KDS / レジ / ハンディ) は Android Chrome 標準環境のため、
 *       iOS 利用は標準環境バナー側で別途案内する想定。
 *
 *   既存呼び出し InstallPromptBanner.init('admin') / init('owner') は無変更で動作する。
 */
var InstallPromptBanner = (function () {
  'use strict';

  var STORAGE_PREFIX = 'posla.installPrompt.dismissed.';
  var BANNER_ID_PREFIX = 'posla-install-banner-';

  var _deferredPrompt = null;
  var _scope = null;

  function _isCustomerScope() {
    var p = location.pathname || '';
    return p.indexOf('/public/customer/') !== -1
        || p.indexOf('/customer/') !== -1;
  }

  function _isStandalone() {
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
    if (window.navigator && window.navigator.standalone === true) return true;
    if (document.referrer && document.referrer.indexOf('android-app://') === 0) return true;
    return false;
  }

  function _isIOS() {
    var ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/i.test(ua)) return true;
    if (/Macintosh/.test(ua) && navigator.maxTouchPoints && navigator.maxTouchPoints > 1) return true;
    return false;
  }

  function _dismissed(scope) {
    try {
      return window.localStorage && localStorage.getItem(STORAGE_PREFIX + scope) === '1';
    } catch (e) {
      return false;
    }
  }

  function _setDismissed(scope) {
    try {
      window.localStorage && localStorage.setItem(STORAGE_PREFIX + scope, '1');
    } catch (e) {
      // localStorage 利用不可時は次回も表示するだけ
    }
  }

  function _baseBannerStyle() {
    return [
      'position:fixed',
      'bottom:12px',
      'left:50%',
      'transform:translateX(-50%)',
      'z-index:2147483000',
      'background:#1b5e20',
      'color:#fff',
      'border-radius:8px',
      'padding:10px 14px',
      'font-size:13px',
      'line-height:1.4',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Yu Gothic UI",sans-serif',
      'display:flex',
      'align-items:center',
      'gap:10px',
      'box-shadow:0 4px 12px rgba(0,0,0,0.25)',
      'max-width:calc(100% - 24px)'
    ].join(';');
  }

  function _btnStyle(primary) {
    var bg = primary ? '#fff' : 'transparent';
    var fg = primary ? '#1b5e20' : '#fff';
    var border = primary ? '#fff' : 'rgba(255,255,255,0.6)';
    return [
      'flex:0 0 auto',
      'background:' + bg,
      'border:1px solid ' + border,
      'color:' + fg,
      'border-radius:4px',
      'padding:5px 12px',
      'font-size:12px',
      'cursor:pointer',
      'line-height:1.2',
      'font-weight:' + (primary ? '700' : '500')
    ].join(';');
  }

  function _removeBanner(scope) {
    var n = document.getElementById(BANNER_ID_PREFIX + scope);
    if (n && n.parentNode) n.parentNode.removeChild(n);
  }

  function _renderInstallable(scope) {
    if (document.getElementById(BANNER_ID_PREFIX + scope)) return;
    if (!document.body) return;

    var banner = document.createElement('div');
    banner.id = BANNER_ID_PREFIX + scope;
    banner.setAttribute('role', 'status');
    banner.style.cssText = _baseBannerStyle();

    var span = document.createElement('span');
    span.style.cssText = 'flex:1 1 auto;min-width:0';
    span.textContent = '📱 POSLA をホーム画面に追加して 1 タップで起動できます。インストールしますか?';

    var installBtn = document.createElement('button');
    installBtn.type = 'button';
    installBtn.textContent = 'インストール';
    installBtn.style.cssText = _btnStyle(true);
    installBtn.addEventListener('click', function () {
      if (!_deferredPrompt) {
        _removeBanner(scope);
        return;
      }
      _deferredPrompt.prompt();
      var p = _deferredPrompt.userChoice;
      _deferredPrompt = null;
      if (p && typeof p.then === 'function') {
        p.then(function (choice) {
          if (choice && choice.outcome === 'accepted') {
            _setDismissed(scope);
          }
          _removeBanner(scope);
        });
      } else {
        _removeBanner(scope);
      }
    });

    var laterBtn = document.createElement('button');
    laterBtn.type = 'button';
    laterBtn.textContent = '後で';
    laterBtn.setAttribute('aria-label', '後で');
    laterBtn.style.cssText = _btnStyle(false);
    laterBtn.addEventListener('click', function () {
      _setDismissed(scope);
      _removeBanner(scope);
    });

    banner.appendChild(span);
    banner.appendChild(installBtn);
    banner.appendChild(laterBtn);
    document.body.appendChild(banner);
  }

  function _renderIOSHint(scope) {
    if (document.getElementById(BANNER_ID_PREFIX + scope)) return;
    if (!document.body) return;

    var banner = document.createElement('div');
    banner.id = BANNER_ID_PREFIX + scope;
    banner.setAttribute('role', 'status');
    var s = _baseBannerStyle();
    // iOS は補助案内なので落ち着いた色 (青系) に
    s = s.replace('background:#1b5e20', 'background:#0d47a1');
    banner.style.cssText = s;

    var span = document.createElement('span');
    span.style.cssText = 'flex:1 1 auto;min-width:0';
    span.textContent = '📱 POSLA をホーム画面に追加するには、Safari の共有ボタン (□↑) →「ホーム画面に追加」を選んでください。';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.textContent = '閉じる';
    closeBtn.setAttribute('aria-label', '閉じる');
    var btnStyle = _btnStyle(false);
    btnStyle = btnStyle.replace('color:#fff', 'color:#fff');
    closeBtn.style.cssText = btnStyle;
    closeBtn.addEventListener('click', function () {
      _setDismissed(scope);
      _removeBanner(scope);
    });

    banner.appendChild(span);
    banner.appendChild(closeBtn);
    document.body.appendChild(banner);
  }

  function init(scope, options) {
    if (!scope) scope = 'staff';
    _scope = scope;
    // 後方互換: options 未指定 (admin / owner の既存呼び出し) は iosHint=true として動作
    var iosHintEnabled = !(options && options.iosHint === false);

    if (_isCustomerScope()) return;
    if (_isStandalone()) return;
    if (_dismissed(scope)) return;

    // 1. beforeinstallprompt キャプチャ (Android Chrome / Desktop Chrome / Edge)
    //    PWA インストール条件 (HTTPS + manifest + SW + 一定の利用) を満たすと発火する。
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      _deferredPrompt = e;
      if (document.body) {
        _renderInstallable(scope);
      } else {
        document.addEventListener('DOMContentLoaded', function () {
          _renderInstallable(scope);
        });
      }
    });

    // 2. iOS Safari フォールバック
    //    iOS は beforeinstallprompt 非対応のため、共有メニュー案内を表示する。
    //    options.iosHint=false の場合 (業務端末用) は表示しない
    //    → 業務端末では iOS は標準環境外のため、StandardEnvBanner で別途注意を出す前提
    if (iosHintEnabled && _isIOS()) {
      if (document.body) {
        _renderIOSHint(scope);
      } else {
        document.addEventListener('DOMContentLoaded', function () {
          _renderIOSHint(scope);
        });
      }
    }

    // 3. 上記以外 (PC Safari, 古いブラウザ等): 何もしない
  }

  // インストール完了イベントが発火した場合はバナーを片付ける
  window.addEventListener('appinstalled', function () {
    if (_scope) {
      _setDismissed(_scope);
      _removeBanner(_scope);
    }
  });

  return {
    init: init
  };
})();
