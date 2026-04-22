/**
 * attendance-clock.js — 勤怠打刻ボタン（L-3 / L-3b GPS対応）
 *
 * ダッシュボードのヘッダーに出勤/退勤ボタンを自己挿入する。
 * 出勤・退勤ボタンは常に両方表示し、状態に応じて有効/無効を切り替える。
 * 勤務中は経過時間をリアルタイム表示。
 * L-3b: GPS必須設定時は位置情報を取得してサーバーに送信。
 */
(function() {
    'use strict';

    var API_BASE = '/api/store/shift/';

    function esc(str) {
        if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(str);
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    var _btnStyle = 'border:none;padding:6px 16px;border-radius:6px;font-weight:bold;';
    var _disabledStyle = _btnStyle + 'background:#ccc;color:#999;cursor:default;opacity:0.5;';

    var AttendanceClock = {
        storeId: null,
        status: 'idle',       // 'idle' | 'working'
        currentRecord: null,  // { id, clock_in }
        _timer: null,
        _container: null,
        _gpsData: null,       // L-3b: { gpsRequired, storeLat, storeLng, gpsRadiusMeters }
        _isStaffPanel: false, // Batch-UI-1.6: staff-home へ挿入されている場合 true

        init: function(storeId, gpsData, targetSelector) {
            this.storeId = storeId;
            this._gpsData = gpsData || null;
            this._targetSelector = targetSelector || null;
            this._isStaffPanel = this._targetSelector === '#staff-attendance-slot';
            this._insertUI();
            this._checkStatus();
        },

        _insertUI: function() {
            // 既存のコンテナがあれば再利用
            var existing = document.getElementById('attendance-clock-container');
            if (existing) {
                this._container = existing;
                return;
            }

            // 挿入先: 指定があればそれ、なければヘッダーへフォールバック
            var target = null;
            if (this._targetSelector) {
                target = document.querySelector(this._targetSelector);
            }
            if (!target) {
                target = document.querySelector('.dashboard-header') ||
                         document.querySelector('.header-bar') ||
                         document.querySelector('header') ||
                         document.body;
            }

            var container = document.createElement('div');
            container.id = 'attendance-clock-container';

            if (this._isStaffPanel) {
                // Batch-UI-1.6: class ベース（admin.css の .staff-clock-* で装飾）
                container.className = 'staff-clock';
                container.innerHTML =
                    '<div id="att-clock-status" class="staff-clock__status"></div>' +
                    '<div class="staff-clock__buttons">' +
                    '<button id="att-clock-in-btn" type="button" class="staff-clock__btn staff-clock__btn--in" style="display:none;">🟢 出勤する</button>' +
                    '<button id="att-clock-out-btn" type="button" class="staff-clock__btn staff-clock__btn--out" style="display:none;">🔴 退勤する</button>' +
                    '</div>';
            } else {
                // 既存の header フォールバック経路（互換のため不変）
                container.style.cssText = 'display:inline-flex;align-items:center;gap:8px;margin-left:auto;padding:4px 12px;';
                container.innerHTML =
                    '<span id="att-clock-status"></span>' +
                    '<button id="att-clock-in-btn" class="btn" style="display:none">出勤</button>' +
                    '<button id="att-clock-out-btn" class="btn" style="display:none">退勤</button>';
            }

            target.appendChild(container);
            this._container = container;
        },

        _checkStatus: function() {
            var self = this;
            var today = new Date();
            var dateStr = today.getFullYear() + '-' +
                          ('0' + (today.getMonth() + 1)).slice(-2) + '-' +
                          ('0' + today.getDate()).slice(-2);

            fetch(API_BASE + 'attendance.php?store_id=' + self.storeId +
                  '&start_date=' + dateStr + '&end_date=' + dateStr)
                .then(function(res) { return res.text(); })
                .then(function(text) {
                    var json = JSON.parse(text);
                    if (!json.ok) {
                        if (self._container) self._container.style.display = 'none';
                        return;
                    }
                    if (json.data.working) {
                        self.status = 'working';
                        self.currentRecord = json.data.working;
                    } else {
                        self.status = 'idle';
                        self.currentRecord = null;
                    }
                    self._updateUI();
                })
                .catch(function() {
                    if (self._container) self._container.style.display = 'none';
                });
        },

        _updateUI: function() {
            var self = this;
            var statusEl = document.getElementById('att-clock-status');
            var inBtn = document.getElementById('att-clock-in-btn');
            var outBtn = document.getElementById('att-clock-out-btn');
            if (!statusEl || !inBtn || !outBtn) return;

            // ボタンをクリーン（イベントリスナー除去）
            var newIn = inBtn.cloneNode(true);
            inBtn.parentNode.replaceChild(newIn, inBtn);
            inBtn = newIn;
            var newOut = outBtn.cloneNode(true);
            outBtn.parentNode.replaceChild(newOut, outBtn);
            outBtn = newOut;

            // 両方表示
            inBtn.style.display = '';
            outBtn.style.display = '';

            if (self._timer) {
                clearInterval(self._timer);
                self._timer = null;
            }

            if (self.status === 'working' && self.currentRecord) {
                // 勤務中: 出勤=無効、退勤=有効
                if (self._isStaffPanel) {
                    inBtn.textContent = '出勤済';
                    inBtn.disabled = true;

                    outBtn.textContent = '🔴 退勤する';
                    outBtn.disabled = false;
                    outBtn.addEventListener('click', function() { self.clockOut(); });
                } else {
                    inBtn.textContent = '出勤済';
                    inBtn.style.cssText = _disabledStyle;
                    inBtn.disabled = true;

                    outBtn.textContent = '退勤する';
                    outBtn.style.cssText = _btnStyle + 'background:#e74c3c;color:#fff;cursor:pointer;';
                    outBtn.disabled = false;
                    outBtn.addEventListener('click', function() { self.clockOut(); });
                }

                self._updateElapsed(statusEl);
                self._timer = setInterval(function() { self._updateElapsed(statusEl); }, 60000);
            } else {
                // 未出勤: 出勤=有効、退勤=無効
                statusEl.textContent = '';

                if (self._isStaffPanel) {
                    inBtn.textContent = '🟢 出勤する';
                    inBtn.disabled = false;
                    inBtn.addEventListener('click', function() { self.clockIn(); });

                    outBtn.textContent = '退勤';
                    outBtn.disabled = true;
                } else {
                    inBtn.textContent = '出勤する';
                    inBtn.style.cssText = _btnStyle + 'background:#27ae60;color:#fff;cursor:pointer;';
                    inBtn.disabled = false;
                    inBtn.addEventListener('click', function() { self.clockIn(); });

                    outBtn.textContent = '退勤';
                    outBtn.style.cssText = _disabledStyle;
                    outBtn.disabled = true;
                }
            }
        },

        _updateElapsed: function(statusEl) {
            if (!this.currentRecord || !this.currentRecord.clock_in) return;
            var clockIn = new Date(this.currentRecord.clock_in.replace(' ', 'T'));
            var now = new Date();
            var diffMs = now - clockIn;
            var hours = Math.floor(diffMs / 3600000);
            var minutes = Math.floor((diffMs % 3600000) / 60000);
            var clockInTime = this.currentRecord.clock_in.substring(11, 16);
            if (this._isStaffPanel) {
                statusEl.innerHTML = '<span class="staff-clock__working">勤務中</span> ' +
                                     esc(clockInTime) + '〜 (' + hours + 'h' + ('0' + minutes).slice(-2) + 'm)';
            } else {
                statusEl.innerHTML = '<span style="color:#27ae60;font-weight:bold;">勤務中</span> ' +
                                     esc(clockInTime) + '~ (' + hours + 'h' + ('0' + minutes).slice(-2) + 'm)';
            }
        },

        // L-3b: GPS必須かどうか判定
        _isGpsRequired: function() {
            return this._gpsData && this._gpsData.gpsRequired === 1
                && this._gpsData.storeLat !== null && this._gpsData.storeLng !== null;
        },

        // L-3b: 位置情報取得ラッパー
        _getLocation: function(callback) {
            if (!navigator.geolocation) {
                callback(null, '位置情報がサポートされていません');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    callback({ lat: pos.coords.latitude, lng: pos.coords.longitude });
                },
                function(err) {
                    var msg = '位置情報の取得に失敗しました';
                    if (err.code === 1) msg = '位置情報の取得が拒否されました。ブラウザの設定を確認してください';
                    if (err.code === 2) msg = '位置情報を利用できません';
                    if (err.code === 3) msg = '位置情報の取得がタイムアウトしました';
                    callback(null, msg);
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        },

        // L-3b: 打刻リクエスト送信（GPS座標付き）
        _sendClockRequest: function(action, coords) {
            var self = this;
            var payload = { store_id: self.storeId };
            if (coords) {
                payload.lat = coords.lat;
                payload.lng = coords.lng;
            }

            fetch(API_BASE + 'attendance.php?action=' + action + '&store_id=' + encodeURIComponent(self.storeId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(res) { return res.text(); })
            .then(function(text) {
                var json = JSON.parse(text);
                if (!json.ok) {
                    alert((action === 'clock-in' ? '出勤' : '退勤') + '打刻に失敗しました: ' + (json.error ? json.error.message : ''));
                    return;
                }
                if (action === 'clock-in') {
                    self.status = 'working';
                    self.currentRecord = { id: json.data.id, clock_in: json.data.clock_in };
                    self._updateUI();
                    if (json.data.warning) {
                        alert(json.data.warning);
                    }
                } else {
                    self.status = 'idle';
                    self.currentRecord = null;
                    self._updateUI();
                }
            })
            .catch(function(err) {
                alert((action === 'clock-in' ? '出勤' : '退勤') + '打刻エラー: ' + err.message);
            });
        },

        clockIn: function() {
            var self = this;
            if (self._isGpsRequired()) {
                self._getLocation(function(coords, errMsg) {
                    if (!coords) {
                        alert('出勤打刻には位置情報が必要です: ' + errMsg);
                        return;
                    }
                    self._sendClockRequest('clock-in', coords);
                });
            } else {
                self._sendClockRequest('clock-in', null);
            }
        },

        clockOut: function() {
            var self = this;
            if (!confirm('退勤しますか？')) return;

            if (self._isGpsRequired()) {
                self._getLocation(function(coords, errMsg) {
                    if (!coords) {
                        alert('退勤打刻には位置情報が必要です: ' + errMsg);
                        return;
                    }
                    self._sendClockRequest('clock-out', coords);
                });
            } else {
                self._sendClockRequest('clock-out', null);
            }
        }
    };

    window.AttendanceClock = AttendanceClock;
})();
