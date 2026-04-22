/**
 * POSLA App Badge ヘルパ (PWA Phase 2a)
 *
 * navigator.setAppBadge / clearAppBadge を使い、PWA インストール済端末のホーム画面
 * アイコンに件数バッジを表示する。
 *
 * 設計方針:
 *   - Badging API 未対応ブラウザでは静かに無効化 (no-op)
 *   - 顧客画面では絶対に動作しない
 *   - バッジは補助表示。業務判断の唯一の根拠にしない
 *   - 負数 / NaN / Infinity は 0 にクランプ
 *   - 0 のときは clearAppBadge を呼ぶ (件数0なのにバッジが残る誤解を防ぐ)
 *
 * 提供 API:
 *   BadgeManager.set(count)  → バッジ件数をセット (0 で消去)
 *   BadgeManager.clear()     → 明示的に消去
 *   BadgeManager.isSupported() → 対応判定
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal を使わない
 */
var BadgeManager = (function () {
  'use strict';

  function _isCustomerScope() {
    var p = location.pathname || '';
    return p.indexOf('/public/customer/') !== -1
        || p.indexOf('/customer/') !== -1;
  }

  function isSupported() {
    return !!(navigator && typeof navigator.setAppBadge === 'function');
  }

  function _normalize(count) {
    var n = parseInt(count, 10);
    if (isNaN(n) || n < 0 || !isFinite(n)) return 0;
    return n;
  }

  function set(count) {
    if (_isCustomerScope()) return;
    if (!isSupported()) return;
    var n = _normalize(count);
    try {
      if (n === 0) {
        // 0 のときは clearAppBadge を使う (仕様上 setAppBadge(0) でも消えるが
        // 一部実装でバッジが残るケース対策で明示的に clear する)
        if (typeof navigator.clearAppBadge === 'function') {
          var p1 = navigator.clearAppBadge();
          if (p1 && typeof p1.then === 'function') p1.catch(function () {});
        } else {
          var p2 = navigator.setAppBadge(0);
          if (p2 && typeof p2.then === 'function') p2.catch(function () {});
        }
        return;
      }
      var p3 = navigator.setAppBadge(n);
      if (p3 && typeof p3.then === 'function') p3.catch(function () {});
    } catch (e) {
      // ignore (バッジは補助機能なので失敗を throw しない)
    }
  }

  function clear() {
    if (_isCustomerScope()) return;
    if (!isSupported()) return;
    try {
      if (typeof navigator.clearAppBadge === 'function') {
        var p = navigator.clearAppBadge();
        if (p && typeof p.then === 'function') p.catch(function () {});
      } else {
        navigator.setAppBadge(0);
      }
    } catch (e) {
      // ignore
    }
  }

  return {
    set: set,
    clear: clear,
    isSupported: isSupported
  };
})();
