/**
 * オフライン検知・警告バナー（U-3）
 *
 * navigator.onLine + online/offline イベントで接続状態を監視。
 * オフライン時は赤バナー、復帰時は緑バナーを3秒表示。
 *
 * ES5 IIFE パターン
 */
var OfflineDetector = (function () {
  'use strict';

  var _isOnline = navigator.onLine;
  var _callbacks = [];
  var _banner = null;
  var _hideTimer = null;
  var _bodyPadding = 0;

  // ── バナー生成 ──

  function getBanner() {
    if (_banner) return _banner;
    _banner = document.createElement('div');
    _banner.id = 'offline-banner';
    _banner.style.cssText = 'position:fixed;top:0;left:0;width:100%;z-index:9999;'
      + 'padding:10px 16px;text-align:center;font-size:14px;font-weight:bold;'
      + 'display:none;transition:opacity 0.3s;box-sizing:border-box;';
    document.body.appendChild(_banner);
    return _banner;
  }

  function showBanner(text, bgColor) {
    var b = getBanner();
    if (_hideTimer) {
      clearTimeout(_hideTimer);
      _hideTimer = null;
    }
    b.textContent = text;
    b.style.background = bgColor;
    b.style.color = '#fff';
    b.style.display = 'block';
    b.style.opacity = '1';

    // body を押し下げ
    var h = b.offsetHeight || 40;
    _bodyPadding = h;
    document.body.style.paddingTop = h + 'px';
  }

  function hideBanner(delay) {
    if (_hideTimer) clearTimeout(_hideTimer);
    _hideTimer = setTimeout(function () {
      var b = getBanner();
      b.style.opacity = '0';
      setTimeout(function () {
        b.style.display = 'none';
        document.body.style.paddingTop = '0';
        _bodyPadding = 0;
      }, 300);
    }, delay || 0);
  }

  // ── 状態変更ハンドラ ──

  function handleOffline() {
    _isOnline = false;
    showBanner('\u26A0 \u30A4\u30F3\u30BF\u30FC\u30CD\u30C3\u30C8\u63A5\u7D9A\u304C\u3042\u308A\u307E\u305B\u3093', '#d32f2f');
    notifyCallbacks(false);
  }

  function handleOnline() {
    _isOnline = true;
    showBanner('\u2713 \u63A5\u7D9A\u304C\u5FA9\u65E7\u3057\u307E\u3057\u305F', '#2e7d32');
    hideBanner(3000);
    notifyCallbacks(true);
  }

  function notifyCallbacks(online) {
    for (var i = 0; i < _callbacks.length; i++) {
      try { _callbacks[i](online); } catch (e) { /* ignore */ }
    }
  }

  // ── パブリック API ──

  function onStatusChange(callback) {
    if (typeof callback === 'function') {
      _callbacks.push(callback);
    }
  }

  function isOnline() {
    return _isOnline;
  }

  // ── イベントリスナー登録 ──

  window.addEventListener('offline', handleOffline);
  window.addEventListener('online', handleOnline);

  // 初期状態がオフラインの場合
  if (!navigator.onLine) {
    // DOM構築完了後にバナー表示
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', handleOffline);
    } else {
      handleOffline();
    }
  }

  return {
    onStatusChange: onStatusChange,
    isOnline: isOnline
  };
})();
