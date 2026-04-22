/**
 * POSLA 標準環境バナー (KDS / レジ / ハンディ 共有)
 *
 * 目的:
 *   業務端末は Android 端末 + Google Chrome 最新版を標準環境とする。
 *   Android Chrome 以外でアクセスされた場合に控えめなバナーを画面上部に表示する。
 *
 * 対象:
 *   public/kds/index.html, cashier.html
 *   public/handy/index.html, pos-register.html
 *
 * 顧客セルフメニュー (public/customer/) では絶対に表示しない:
 *   - HTML 側で読み込まないこと
 *   - 二重防御として URL 判定でも skip
 *
 * 表示条件:
 *   - Android Chrome (バージョン問わず): 表示しない
 *   - PC Chrome / PC Edge: 表示しない (管理用途として許容)
 *   - iOS / iPadOS / Safari: 標準サポート外として注意バナー
 *   - Fire タブレット: 標準サポート外として注意バナー
 *   - その他 (古い Android, アプリ内ブラウザ等): 標準サポート外として注意バナー
 *
 * 閉じるボタン押下後は localStorage に画面別キーで記録し、同一ブラウザでは再表示しない。
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal を使用しない。
 * innerHTML は固定文言のみ (ユーザー入力を絶対に挿入しない)。
 * 既存 DOM・既存 script タグには干渉しない。
 *
 * 使い方:
 *   <script src="../shared/js/standard-env-banner.js?v=20260420-env"></script>
 *   <script>StandardEnvBanner.init('kds');</script>
 *
 *   scope は 'kds' / 'cashier' / 'handy' / 'pos-register' のいずれかを推奨。
 */
var StandardEnvBanner = (function () {
  'use strict';

  var STORAGE_PREFIX = 'posla.envBanner.dismissed.';
  var BANNER_ID_PREFIX = 'posla-env-banner-';

  // 顧客画面では絶対に動作させない (二重防御)
  function _isCustomerScope() {
    var p = location.pathname || '';
    return p.indexOf('/public/customer/') !== -1
        || p.indexOf('/customer/') !== -1;
  }

  // ブラウザ環境を分類: 'android-chrome' | 'desktop-ok' | 'ios-safari' | 'fire' | 'other'
  function _classify() {
    var ua = navigator.userAgent || '';

    // Fire タブレット (Silk ブラウザ / KFAUWI など)
    if (/(Silk|KFAPWI|KFAUWI|KFFOWI|KFGIWI|KFMUWI|KFKAWI|KFSAWI|KFSAWA|KFASWI|KFMAWI|KFTRWI|KFTRPWI)/i.test(ua)) {
      return 'fire';
    }

    // iOS / iPadOS (Safari, Chrome on iOS = 中身は WebKit)
    // iPad は Mac として偽装することがあるので、Touch 対応 Mac も iOS 系として扱う
    if (/iPhone|iPad|iPod/i.test(ua)) return 'ios-safari';
    if (/Macintosh/.test(ua) && navigator.maxTouchPoints && navigator.maxTouchPoints > 1) {
      return 'ios-safari';
    }

    // Android Chrome (Samsung Internet や Edge Mobile などは other に倒す)
    var isAndroid = /Android/i.test(ua);
    var isChrome  = /Chrome\/\d+/i.test(ua) && !/Edg\/|EdgA\/|OPR\/|SamsungBrowser|UCBrowser|MiuiBrowser|HuaweiBrowser/i.test(ua);
    if (isAndroid && isChrome) return 'android-chrome';

    // PC (Win/Mac/Linux) の Chrome / Edge は管理用途として許容
    var isMobile = isAndroid || /iPhone|iPad|iPod/i.test(ua);
    if (!isMobile) {
      var isDesktopChrome = /Chrome\/\d+/i.test(ua) && !/Edg\//i.test(ua);
      var isDesktopEdge   = /Edg\//i.test(ua);
      if (isDesktopChrome || isDesktopEdge) return 'desktop-ok';
    }

    return 'other';
  }

  function _messageFor(kind) {
    if (kind === 'fire') {
      return 'この端末は標準サポート対象外の可能性があります。POSLA の業務端末は「Android 端末 + Google Chrome 最新版」を標準環境としています。';
    }
    if (kind === 'ios-safari') {
      return 'この画面は「Android 端末 + Google Chrome 最新版」を標準環境としています。iPad/Safari でも一部画面は表示できますが、音声コマンドや通知音が安定しない場合があります。';
    }
    // other (古い Android、独自ブラウザ、アプリ内ブラウザ など)
    return '標準環境は「Android 端末 + Google Chrome 最新版」です。現在のブラウザでは音声コマンドや通知音が安定しない場合があります。';
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
      // localStorage 利用不可時は次回も表示するだけ。挙動は阻害しない。
    }
  }

  function _render(scope, kind) {
    if (document.getElementById(BANNER_ID_PREFIX + scope)) return;

    var bg, fg, border;
    if (kind === 'fire') {
      bg = '#5d4037'; fg = '#fff'; border = '#3e2723';
    } else if (kind === 'ios-safari') {
      bg = '#fff8e1'; fg = '#5d4037'; border = '#ffca28';
    } else {
      bg = '#fff8e1'; fg = '#5d4037'; border = '#ffca28';
    }

    var banner = document.createElement('div');
    banner.id = BANNER_ID_PREFIX + scope;
    banner.setAttribute('role', 'status');
    banner.style.cssText = [
      'position:fixed',
      'top:0',
      'left:0',
      'right:0',
      'z-index:2147483000',
      'background:' + bg,
      'color:' + fg,
      'border-bottom:1px solid ' + border,
      'padding:6px 12px',
      'font-size:12px',
      'line-height:1.4',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Yu Gothic UI",sans-serif',
      'display:flex',
      'align-items:center',
      'gap:8px',
      'box-shadow:0 1px 4px rgba(0,0,0,0.12)'
    ].join(';');

    // innerHTML は固定文言のみ。動的入力は一切混ぜない。
    var msg = _messageFor(kind);
    var span = document.createElement('span');
    span.style.cssText = 'flex:1 1 auto;min-width:0';
    span.textContent = '⚠️ ' + msg;

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', '閉じる');
    closeBtn.style.cssText = [
      'flex:0 0 auto',
      'background:transparent',
      'border:1px solid ' + border,
      'color:' + fg,
      'border-radius:3px',
      'padding:2px 8px',
      'font-size:12px',
      'cursor:pointer',
      'line-height:1.2'
    ].join(';');
    closeBtn.textContent = '閉じる';
    closeBtn.addEventListener('click', function () {
      _setDismissed(scope);
      var n = document.getElementById(BANNER_ID_PREFIX + scope);
      if (n && n.parentNode) n.parentNode.removeChild(n);
    });

    banner.appendChild(span);
    banner.appendChild(closeBtn);

    if (document.body) {
      document.body.appendChild(banner);
    }
  }

  function init(scope) {
    if (!scope) scope = 'staff';
    if (_isCustomerScope()) return;
    if (_dismissed(scope)) return;

    var kind = _classify();
    if (kind === 'android-chrome' || kind === 'desktop-ok') return;

    if (document.body) {
      _render(scope, kind);
    } else {
      document.addEventListener('DOMContentLoaded', function () {
        _render(scope, kind);
      });
    }
  }

  // テスト用にエクスポート (副作用なし)
  return {
    init: init,
    _classify: _classify
  };
})();
