/**
 * POSLA Wake Lock helper (Phase 1 / KDS 専用)
 *
 * 目的:
 *   KDS 画面表示中に画面が暗くならないように Screen Wake Lock を取得・維持する。
 *   端末の省電力設定・OS設定・ブラウザ制限で失敗する可能性があるため、補助機能として実装。
 *
 * 動作仕様:
 *   - navigator.wakeLock が無いブラウザ: 何もしない (status='unsupported')
 *   - 取得成功 (status='active'): ヘッダ等の表示要素を「画面維持: 有効」に
 *   - 取得失敗 (status='blocked'): 「画面維持: 端末設定を確認」表示
 *   - visibilitychange で画面が visible に戻ったら再取得を試す
 *   - pagehide / beforeunload で release を試みる
 *   - 失敗を throw しない / console.error を撒かない
 *   - ユーザー操作 (タップ等) を起点に request するのが最も成功率が高い
 *     → KDS の起動オーバーレイ「KDSを開始」ボタン押下時に WakeLockHelper.acquire() を呼ぶ運用
 *
 * 使い方:
 *   <script src="../shared/js/wake-lock-helper.js?v=20260420-pwa1"></script>
 *   <script>
 *     // ステータス表示要素を登録 (任意)
 *     WakeLockHelper.attachStatusElement(document.getElementById('wake-lock-status'));
 *     // 起動ボタン押下時に request
 *     document.getElementById('btn-start').addEventListener('click', function () {
 *       WakeLockHelper.acquire();
 *     });
 *   </script>
 *
 * ES5 互換 (CLAUDE.md): const/let/arrow/async-await/template-literal を使わない
 * innerHTML は使用せず textContent のみ (XSS 経路ゼロ)
 *
 * 顧客画面 (public/customer/) では絶対に使用しない (該当 HTML から読み込まないこと)
 */
var WakeLockHelper = (function () {
  'use strict';

  var _sentinel = null;          // WakeLockSentinel
  var _statusEl = null;          // 状態表示用 DOM 要素 (任意)
  var _autoReacquire = true;     // visibilitychange で再取得するか
  var _attached = false;         // visibilitychange / pagehide リスナーを attach 済みか

  // 表示状態のラベル (textContent のみ。innerHTML は使わない)
  var LABELS = {
    'active'      : '画面維持: 有効',
    'blocked'     : '画面維持: 端末設定を確認',
    'released'    : '画面維持: 一時停止',
    'unsupported' : '画面維持: 未対応',
    'pending'     : '画面維持: 確認中'
  };

  function _isSupported() {
    return !!(navigator && navigator.wakeLock && typeof navigator.wakeLock.request === 'function');
  }

  function _setStatus(state) {
    if (!_statusEl) return;
    _statusEl.textContent = LABELS[state] || LABELS['pending'];
    // data 属性も付与 (CSS で色付けしたい場合用)
    _statusEl.setAttribute('data-wake-lock', state);
  }

  function attachStatusElement(el) {
    if (!el) return;
    _statusEl = el;
    if (!_isSupported()) {
      _setStatus('unsupported');
    } else {
      _setStatus(_sentinel ? 'active' : 'pending');
    }
  }

  function _attachSystemListeners() {
    if (_attached) return;
    _attached = true;

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible' && _autoReacquire) {
        // 画面が戻ってきたら sentinel を再取得 (元の sentinel は OS 側で release 済の可能性)
        if (!_sentinel || _sentinel.released) {
          _request();
        }
      }
    });

    // ページ離脱時に明示 release (OS 側でも自動だが念のため)
    window.addEventListener('pagehide', function () {
      _release();
    });
  }

  function _request() {
    if (!_isSupported()) {
      _setStatus('unsupported');
      return;
    }
    try {
      var p = navigator.wakeLock.request('screen');
      if (!p || typeof p.then !== 'function') {
        _setStatus('blocked');
        return;
      }
      p.then(function (sentinel) {
        _sentinel = sentinel;
        _setStatus('active');
        // OS が release した場合のハンドラ (バッテリー低下等)
        if (sentinel.addEventListener) {
          sentinel.addEventListener('release', function () {
            _setStatus('released');
            _sentinel = null;
          });
        }
      }).catch(function () {
        // バッテリーセーバー / OS設定 / ユーザージェスチャ要件未達 など
        _setStatus('blocked');
      });
    } catch (e) {
      _setStatus('blocked');
    }
  }

  function _release() {
    if (!_sentinel) return;
    try {
      var p = _sentinel.release();
      if (p && typeof p.then === 'function') {
        p.catch(function () {});
      }
    } catch (e) {
      // ignore
    }
    _sentinel = null;
  }

  /**
   * Wake Lock の取得を要求する。
   * 推奨呼び出しタイミング: ユーザー操作 (クリック / タップ) のハンドラ内。
   * 自動取得だけに依存しないこと (ブラウザによっては user gesture が必須)。
   */
  function acquire() {
    _attachSystemListeners();
    _request();
  }

  /**
   * Wake Lock を明示的に release する。
   * 通常は呼ぶ必要がないが、画面遷移前等に呼んでも安全。
   */
  function release() {
    _autoReacquire = false;
    _release();
    _setStatus('released');
  }

  /**
   * 自動再取得を再開する。
   */
  function enableAutoReacquire() {
    _autoReacquire = true;
  }

  function isSupported() {
    return _isSupported();
  }

  // ステータス要素が attach される前に init されるケースに備えて、初期状態だけ評価しておく
  // (DOM はまだ存在しなくてよい — attachStatusElement 時に再評価される)
  return {
    attachStatusElement: attachStatusElement,
    acquire: acquire,
    release: release,
    enableAutoReacquire: enableAutoReacquire,
    isSupported: isSupported
  };
})();
