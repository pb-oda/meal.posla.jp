/**
 * テーマ切り替え（U-1）
 *
 * html.light-theme クラスの付与/除去で CSS 変数を切り替える。
 * localStorage 'mt_theme' に永続化。デフォルトはダーク。
 *
 * ES5 IIFE パターン
 */
var ThemeSwitcher = (function () {
  'use strict';

  var STORAGE_KEY = 'mt_theme';
  var _btn = null;

  function _getTheme() {
    return localStorage.getItem(STORAGE_KEY) || 'dark';
  }

  function _apply(theme) {
    if (theme === 'light') {
      document.documentElement.classList.add('light-theme');
    } else {
      document.documentElement.classList.remove('light-theme');
    }
    _updateIcon();
  }

  function set(theme) {
    if (theme !== 'light' && theme !== 'dark') return;
    try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* ignore */ }
    _apply(theme);
  }

  function toggle() {
    var current = _getTheme();
    set(current === 'dark' ? 'light' : 'dark');
  }

  function _updateIcon() {
    if (!_btn) return;
    var isLight = document.documentElement.classList.contains('light-theme');
    _btn.textContent = isLight ? '\u2600' : '\uD83C\uDF19';
    _btn.title = isLight ? '\u30C0\u30FC\u30AF\u30E2\u30FC\u30C9' : '\u30E9\u30A4\u30C8\u30E2\u30FC\u30C9';
  }

  function injectButton(container) {
    if (!container) return;
    _btn = document.createElement('button');
    _btn.type = 'button';
    _btn.style.cssText = 'background:transparent;border:1px solid rgba(255,255,255,0.2);'
      + 'color:inherit;font-size:1.125rem;padding:0.15rem 0.5rem;border-radius:4px;'
      + 'cursor:pointer;line-height:1;';
    _btn.addEventListener('click', function () { toggle(); });
    container.insertBefore(_btn, container.firstChild);
    _updateIcon();
  }

  // 初期化: ページ読み込み時にテーマ適用
  _apply(_getTheme());

  return {
    set: set,
    toggle: toggle,
    injectButton: injectButton
  };
})();
