/**
 * POSLA Offline State Banner (PWA Phase 3 / 2026-04-20)
 *
 * 通信不安定 / 古いデータ (stale) 表示 / 復帰を業務端末に告知するセカンダリバナー。
 * 既存 public/js/offline-detector.js の赤/緑バナー (navigator.onLine ベース) とは
 * 別 DOM (#offline-state-banner) として共存する。既存バナーを壊さない。
 *
 * 表示状態:
 *   - 通信断の一時エラー (showFetchError): 黄色、4 秒で自動 hide
 *     文言: 通信が不安定です。最新情報ではない可能性があります。
 *   - 古いデータを表示中 (markStale): 黄色、手動 hide するまで常時表示
 *     文言: [最終取得: HH:mm。] 古いデータを表示中です。操作は通信復帰後に行ってください。 [再取得]
 *   - 通信復帰 (markFresh + OfflineDetector online イベント): 緑、3 秒で hide
 *     文言: 通信が復帰しました。最新情報を取得しています。
 *
 * 設計方針:
 *   - 顧客画面 public/customer/ では init() 早期 return
 *   - OfflineDetector が無くても動作 (navigator.onLine のみで fallback しない。
 *     画面個別の fetch 失敗時に markStale / showFetchError を明示的に呼ぶ)
 *   - reload を勝手に行わない (業務中の自動 reload 禁止)。再取得は onRetry コールバック
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal/.repeat/.padStart/.includes 不使用
 */
var OfflineStateBanner = (function () {
  'use strict';

  var _banner = null;
  var _retryCallback = null;
  var _scope = null;
  var _lastSuccessAt = 0;
  var _isStaleState = false;
  var _hideTimer = null;

  function _isCustomerScope() {
    var p = (location && location.pathname) || '';
    return p.indexOf('/public/customer/') !== -1 || p.indexOf('/customer/') !== -1;
  }

  function _formatHHMM(ts) {
    if (typeof OfflineSnapshot !== 'undefined' && OfflineSnapshot.formatSavedAt) {
      return OfflineSnapshot.formatSavedAt(ts);
    }
    if (!ts) return '';
    var d = new Date(ts);
    var hh = d.getHours();
    var mm = d.getMinutes();
    return (hh < 10 ? '0' + hh : String(hh)) + ':' + (mm < 10 ? '0' + mm : String(mm));
  }

  function _getBanner() {
    if (_banner) return _banner;
    _banner = document.createElement('div');
    _banner.id = 'offline-state-banner';
    // top:40px は既存 offline-detector の赤バナー (top:0) の下に出すため
    _banner.style.cssText = 'position:fixed;top:40px;left:0;width:100%;z-index:9998;'
      + 'padding:8px 14px;text-align:center;font-size:13px;font-weight:600;'
      + 'display:none;box-sizing:border-box;transition:opacity 0.2s;'
      + 'border-bottom:1px solid rgba(0,0,0,0.08);';
    if (document.body) {
      document.body.appendChild(_banner);
    } else {
      // DOMContentLoaded 前の呼び出しに保険
      document.addEventListener('DOMContentLoaded', function () {
        if (!_banner.parentNode) document.body.appendChild(_banner);
      });
    }
    return _banner;
  }

  function _show(html, bg, fg) {
    var b = _getBanner();
    b.innerHTML = html;
    b.style.background = bg;
    b.style.color = fg;
    b.style.display = 'block';
    b.style.opacity = '1';
    if (_hideTimer) { clearTimeout(_hideTimer); _hideTimer = null; }

    // 再取得ボタン bind (markStale 時のみ存在)
    var btn = b.querySelector('[data-osb-retry]');
    if (btn) {
      btn.onclick = function (e) {
        if (e && e.preventDefault) e.preventDefault();
        if (typeof _retryCallback === 'function') {
          try { _retryCallback(); } catch (err) { /* ignore */ }
        }
      };
    }
  }

  function _hide(delay) {
    var b = _getBanner();
    if (_hideTimer) clearTimeout(_hideTimer);
    _hideTimer = setTimeout(function () {
      b.style.opacity = '0';
      setTimeout(function () { b.style.display = 'none'; }, 200);
    }, delay || 0);
  }

  /**
   * @param options { scope, onRetry }
   *   scope: 'kds' / 'handy' / 'cashier' / 'pos-register' 等 (ログ用)
   *   onRetry: 「再取得」ボタン押下時の callback。既存の forceRefresh / loadMenu 等を渡す
   */
  function init(options) {
    if (_isCustomerScope()) return;
    options = options || {};
    _scope = options.scope || null;
    _retryCallback = (typeof options.onRetry === 'function') ? options.onRetry : null;

    // OfflineDetector 復帰イベントを購読 (offline イベントは既存赤バナーに任せる)
    if (typeof OfflineDetector !== 'undefined' && OfflineDetector.onStatusChange) {
      OfflineDetector.onStatusChange(function (online) {
        if (!online) return;
        if (!_isStaleState) return;
        // 復帰時: 緑の「通信復帰」バナーを 3 秒出す + onRetry 呼び出し
        _show('\u2713 \u901A\u4FE1\u304C\u5FA9\u5E30\u3057\u307E\u3057\u305F\u3002\u6700\u65B0\u60C5\u5831\u3092\u53D6\u5F97\u3057\u3066\u3044\u307E\u3059\u3002',
              '#d4edda', '#155724');
        _hide(3000);
        _isStaleState = false;
        if (typeof _retryCallback === 'function') {
          try { _retryCallback(); } catch (e) {}
        }
      });
    }
  }

  function markStale() {
    if (_isCustomerScope()) return;
    _isStaleState = true;
    var lastText = '';
    if (_lastSuccessAt) {
      lastText = '\u6700\u7D42\u53D6\u5F97: ' + _formatHHMM(_lastSuccessAt) + '\u3002';
    }
    // 固定文言 + 時刻のみ (XSS 混入の余地なし)
    var html = '\u26A0\uFE0F ' + lastText
      + '\u53E4\u3044\u30C7\u30FC\u30BF\u3092\u8868\u793A\u4E2D\u3067\u3059\u3002\u64CD\u4F5C\u306F\u901A\u4FE1\u5FA9\u5E30\u5F8C\u306B\u884C\u3063\u3066\u304F\u3060\u3055\u3044\u3002 '
      + '<button type="button" data-osb-retry style="margin-left:8px;padding:3px 12px;background:#fff;border:1px solid #d0a200;color:#795500;border-radius:3px;cursor:pointer;font-size:12px;">\u518D\u53D6\u5F97</button>';
    _show(html, '#fff3cd', '#795500');
    // hide タイマーなし (手動 / markFresh 呼び出しまで表示し続ける)
  }

  function markFresh() {
    _isStaleState = false;
    _lastSuccessAt = Date.now();
    _hide(0);
  }

  /**
   * 一時的な通信エラー (画面上に既存データは残っている状態)。
   * stale 表示中なら何もしない (より強い stale を維持)。
   */
  function showFetchError() {
    if (_isCustomerScope()) return;
    if (_isStaleState) return;
    _show('\u26A0\uFE0F \u901A\u4FE1\u304C\u4E0D\u5B89\u5B9A\u3067\u3059\u3002\u6700\u65B0\u60C5\u5831\u3067\u306F\u306A\u3044\u53EF\u80FD\u6027\u304C\u3042\u308A\u307E\u3059\u3002',
          '#fff3cd', '#795500');
    _hide(4000);
  }

  function setLastSuccessAt(ts) {
    _lastSuccessAt = ts ? ts : Date.now();
  }

  function isStale() { return _isStaleState; }

  /**
   * 状態変更系 API を叩いて良いか判定する共通ヘルパ (Phase 3 レビュー指摘 #1 対応)。
   *
   * 以下のいずれかに該当すれば true (= 業務更新を止める):
   *   - navigator.onLine が false (OfflineDetector で検知)
   *   - 最後の GET が失敗して stale バナー表示中 (markStale 状態)
   *
   * 呼び出し側は本関数が true を返したら:
   *   - ローカル状態の楽観更新もしない
   *   - fetch/PATCH/POST/DELETE もしない
   *   - toast などで「通信復帰後に操作してください」と通知してリターン
   */
  function isOfflineOrStale() {
    var offline = false;
    if (typeof OfflineDetector !== 'undefined' && OfflineDetector.isOnline) {
      offline = (OfflineDetector.isOnline() === false);
    } else if (typeof navigator !== 'undefined') {
      offline = (navigator.onLine === false);
    }
    return offline || _isStaleState;
  }

  return {
    init: init,
    markStale: markStale,
    markFresh: markFresh,
    showFetchError: showFetchError,
    setLastSuccessAt: setLastSuccessAt,
    isStale: isStale,
    isOfflineOrStale: isOfflineOrStale
  };
})();
