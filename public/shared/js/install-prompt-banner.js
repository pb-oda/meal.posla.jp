/**
 * POSLA PWA インストール案内バナー
 *
 * 目的:
 *   POSLA をホーム画面に追加 (PWA インストール) して 1 タップ起動できることを案内する。
 *   業務集中度を下げないよう控えめに、画面下部に表示する。
 *
 * 対象:
 *   public/admin/dashboard.html (店舗運営)
 *   public/admin/owner-dashboard.html (オーナー管理)
 *   public/kds/index.html (KDS)
 *   public/kds/cashier.html (レジ)
 *   public/handy/index.html (ハンディ)
 *   public/handy/pos-register.html (ハンディ内レジ)
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
 *     - androidOnly: true にすると Android Chrome 以外では案内を出さない。
 *     - manualAndroidHint: true にすると beforeinstallprompt が発火しない場合も
 *       Android Chrome 向けに「Chrome メニューからホーム画面に追加」の補助案内を出す。
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

  function _isAndroidChrome() {
    var ua = navigator.userAgent || '';
    var isAndroid = /Android/i.test(ua);
    var isChrome = /Chrome\/\d+/i.test(ua) && !/Edg\/|EdgA\/|OPR\/|SamsungBrowser|UCBrowser|MiuiBrowser|HuaweiBrowser/i.test(ua);
    return isAndroid && isChrome;
  }

  function _option(options, key, fallback) {
    if (options && typeof options[key] !== 'undefined') return options[key];
    return fallback;
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

  function _baseBannerStyle(options, bgOverride, fgOverride) {
    var bg = bgOverride || _option(options, 'background', '#1b5e20');
    var fg = fgOverride || _option(options, 'color', '#fff');
    return [
      'position:fixed',
      'bottom:12px',
      'left:50%',
      'transform:translateX(-50%)',
      'z-index:2147483000',
      'background:' + bg,
      'color:' + fg,
      'border-radius:8px',
      'padding:' + _option(options, 'padding', '10px 14px'),
      'font-size:' + _option(options, 'fontSize', '13px'),
      'line-height:1.4',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Yu Gothic UI",sans-serif',
      'display:flex',
      'align-items:center',
      'gap:10px',
      'box-shadow:0 4px 12px rgba(0,0,0,0.25)',
      'max-width:calc(100% - 24px)'
    ].join(';');
  }

  function _btnStyle(primary, options) {
    var primaryColor = _option(options, 'primaryColor', _option(options, 'background', '#1b5e20'));
    var textColor = _option(options, 'color', '#fff');
    var bg = primary ? '#fff' : 'transparent';
    var fg = primary ? primaryColor : textColor;
    var border = primary ? '#fff' : 'rgba(255,255,255,0.6)';
    return [
      'flex:0 0 auto',
      'background:' + bg,
      'border:1px solid ' + border,
      'color:' + fg,
      'border-radius:' + _option(options, 'buttonRadius', '4px'),
      'padding:' + _option(options, 'buttonPadding', '5px 12px'),
      'font-size:' + _option(options, 'buttonFontSize', '12px'),
      'cursor:pointer',
      'line-height:1.2',
      'font-weight:' + (primary ? '700' : '500')
    ].join(';');
  }

  function _removeBanner(scope) {
    var n = document.getElementById(BANNER_ID_PREFIX + scope);
    if (n && n.parentNode) n.parentNode.removeChild(n);
  }

  function _renderInstallable(scope, options) {
    var current = document.getElementById(BANNER_ID_PREFIX + scope);
    if (current) {
      if (current.getAttribute('data-posla-install-kind') === 'manual' && current.parentNode) {
        current.parentNode.removeChild(current);
      } else {
        return;
      }
    }
    if (!document.body) return;

    var banner = document.createElement('div');
    banner.id = BANNER_ID_PREFIX + scope;
    banner.setAttribute('role', 'status');
    banner.setAttribute('data-posla-install-kind', 'prompt');
    banner.style.cssText = _baseBannerStyle(options);

    var span = document.createElement('span');
    span.style.cssText = 'flex:1 1 auto;min-width:0';
    span.textContent = _option(options, 'message', '📱 POSLA をホーム画面に追加して 1 タップで起動できます。インストールしますか?');

    var installBtn = document.createElement('button');
    installBtn.type = 'button';
    installBtn.textContent = _option(options, 'installText', 'インストール');
    installBtn.style.cssText = _btnStyle(true, options);
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
    laterBtn.textContent = _option(options, 'laterText', '後で');
    laterBtn.setAttribute('aria-label', '後で');
    laterBtn.style.cssText = _btnStyle(false, options);
    laterBtn.addEventListener('click', function () {
      _setDismissed(scope);
      _removeBanner(scope);
    });

    banner.appendChild(span);
    banner.appendChild(installBtn);
    banner.appendChild(laterBtn);
    document.body.appendChild(banner);
  }

  function _renderIOSHint(scope, options) {
    if (document.getElementById(BANNER_ID_PREFIX + scope)) return;
    if (!document.body) return;

    var banner = document.createElement('div');
    banner.id = BANNER_ID_PREFIX + scope;
    banner.setAttribute('role', 'status');
    banner.setAttribute('data-posla-install-kind', 'ios');
    banner.style.cssText = _baseBannerStyle(options, '#0d47a1', '#fff');

    var span = document.createElement('span');
    span.style.cssText = 'flex:1 1 auto;min-width:0';
    span.textContent = '📱 POSLA をホーム画面に追加するには、Safari の共有ボタン (□↑) →「ホーム画面に追加」を選んでください。';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.textContent = '閉じる';
    closeBtn.setAttribute('aria-label', '閉じる');
    closeBtn.style.cssText = _btnStyle(false, options);
    closeBtn.addEventListener('click', function () {
      _setDismissed(scope);
      _removeBanner(scope);
    });

    banner.appendChild(span);
    banner.appendChild(closeBtn);
    document.body.appendChild(banner);
  }

  function _renderAndroidManualHint(scope, options) {
    if (document.getElementById(BANNER_ID_PREFIX + scope)) return;
    if (!document.body) return;

    var banner = document.createElement('div');
    banner.id = BANNER_ID_PREFIX + scope;
    banner.setAttribute('role', 'status');
    banner.setAttribute('data-posla-install-kind', 'manual');
    banner.style.cssText = _baseBannerStyle(options);

    var span = document.createElement('span');
    span.style.cssText = 'flex:1 1 auto;min-width:0';
    span.textContent = _option(options, 'manualMessage', 'Chrome 右上のメニューから「ホーム画面に追加」を選ぶと、次回から 1 タップで起動できます。');

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.textContent = _option(options, 'closeText', '閉じる');
    closeBtn.setAttribute('aria-label', '閉じる');
    closeBtn.style.cssText = _btnStyle(false, options);
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
    var androidOnly = options && options.androidOnly === true;
    var manualAndroidHint = options && options.manualAndroidHint === true;
    var manualDelayMs = (options && typeof options.manualDelayMs === 'number') ? options.manualDelayMs : 2500;

    if (_isCustomerScope()) return;
    if (_isStandalone()) return;
    if (_dismissed(scope)) return;
    if (androidOnly && !_isAndroidChrome()) return;

    // 1. beforeinstallprompt キャプチャ (Android Chrome / Desktop Chrome / Edge)
    //    PWA インストール条件 (HTTPS + manifest + SW + 一定の利用) を満たすと発火する。
    window.addEventListener('beforeinstallprompt', function (e) {
      if (androidOnly && !_isAndroidChrome()) return;
      e.preventDefault();
      _deferredPrompt = e;
      if (document.body) {
        _renderInstallable(scope, options);
      } else {
        document.addEventListener('DOMContentLoaded', function () {
          _renderInstallable(scope, options);
        });
      }
    });

    if (manualAndroidHint && _isAndroidChrome()) {
      window.setTimeout(function () {
        if (_deferredPrompt) return;
        if (_dismissed(scope)) return;
        if (document.body) {
          _renderAndroidManualHint(scope, options);
        } else {
          document.addEventListener('DOMContentLoaded', function () {
            _renderAndroidManualHint(scope, options);
          });
        }
      }, manualDelayMs);
    }

    // 2. iOS Safari フォールバック
    //    iOS は beforeinstallprompt 非対応のため、共有メニュー案内を表示する。
    //    options.iosHint=false の場合 (業務端末用) は表示しない
    //    → 業務端末では iOS は標準環境外のため、StandardEnvBanner で別途注意を出す前提
    if (!androidOnly && iosHintEnabled && _isIOS()) {
      if (document.body) {
        _renderIOSHint(scope, options);
      } else {
        document.addEventListener('DOMContentLoaded', function () {
          _renderIOSHint(scope, options);
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
