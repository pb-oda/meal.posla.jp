/**
 * shift-manager.js — シフト管理メインモジュール（L-3）
 *
 * テンプレート管理、希望提出、設定、集計サマリーの各サブビューを制御する。
 * 週間カレンダーは shift-calendar.js、勤怠打刻は attendance-clock.js が担当。
 */
(function() {
    'use strict';

    var API_BASE = '/api/store/shift/';

    // ─── ユーティリティ ───
    function apiGet(endpoint, params, callback) {
        var qs = Object.keys(params).map(function(k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        var url = API_BASE + endpoint + (qs ? '?' + qs : '');
        fetch(url).then(function(res) { return res.text(); }).then(function(text) {
            var json = JSON.parse(text);
            if (!json.ok) {
                console.error('[ShiftManager] API error:', json.error);
                callback(null, json.error);
                return;
            }
            callback(json.data);
        }).catch(function(err) {
            console.error('[ShiftManager] fetch error:', err);
            callback(null, { message: err.message });
        });
    }

    function apiPost(endpoint, body, callback) {
        var url = API_BASE + endpoint;
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function(res) { return res.text(); }).then(function(text) {
            var json = JSON.parse(text);
            if (!json.ok) {
                callback(null, json.error);
                return;
            }
            callback(json.data);
        }).catch(function(err) {
            callback(null, { message: err.message });
        });
    }

    function apiPatch(endpoint, body, callback) {
        var url = API_BASE + endpoint;
        fetch(url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function(res) { return res.text(); }).then(function(text) {
            var json = JSON.parse(text);
            if (!json.ok) {
                callback(null, json.error);
                return;
            }
            callback(json.data);
        }).catch(function(err) {
            callback(null, { message: err.message });
        });
    }

    function apiDelete(endpoint, callback) {
        var url = API_BASE + endpoint;
        fetch(url, { method: 'DELETE' }).then(function(res) { return res.text(); }).then(function(text) {
            var json = JSON.parse(text);
            if (!json.ok) {
                callback(null, json.error);
                return;
            }
            callback(json.data);
        }).catch(function(err) {
            callback(null, { message: err.message });
        });
    }

    function esc(str) {
        if (typeof Utils !== 'undefined' && Utils.escapeHtml) {
            return Utils.escapeHtml(str);
        }
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    var DAY_NAMES = ['日', '月', '火', '水', '木', '金', '土'];

    // ─── ShiftManager オブジェクト ───
    var ShiftManager = {
        storeId: null,
        userRole: null,
        currentView: 'calendar',

        init: function(storeId, userRole) {
            this.storeId = storeId;
            this.userRole = userRole || 'staff';
            this._bindSubTabs();
        },

        // ── サブタブ切替 ──
        _bindSubTabs: function() {
            var self = this;
            var btns = document.querySelectorAll('.shift-sub-tab');
            for (var i = 0; i < btns.length; i++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        self._switchView(btn.getAttribute('data-view'));
                    });
                })(btns[i]);
            }
        },

        _switchView: function(view) {
            this.currentView = view;
            var btns = document.querySelectorAll('.shift-sub-tab');
            var views = document.querySelectorAll('.shift-view');
            for (var i = 0; i < btns.length; i++) {
                btns[i].classList.toggle('active', btns[i].getAttribute('data-view') === view);
            }
            for (var j = 0; j < views.length; j++) {
                views[j].style.display = (views[j].id === 'shift-view-' + view) ? '' : 'none';
            }
            // ロール制限: staff はテンプレート・設定・勤怠一覧タブ非表示
            if (this.userRole === 'staff') {
                var mgr = document.querySelectorAll('[data-view="templates"],[data-view="shift-settings"],[data-view="attendance"]');
                for (var k = 0; k < mgr.length; k++) {
                    mgr[k].style.display = 'none';
                }
            }
            // 各ビュー初期化
            if (view === 'templates')      this.loadTemplates();
            if (view === 'availability')   this.loadAvailabilities();
            if (view === 'shift-settings') this.loadSettings();
            if (view === 'attendance')     this.loadAttendance();
            if (view === 'my-shift')       this.loadMyShift();
            if (view === 'calendar' && typeof ShiftCalendar !== 'undefined') {
                ShiftCalendar.init('shift-view-calendar', this.storeId);
            }
        },

        // ──────────────────────────────
        // テンプレート管理
        // ──────────────────────────────
        loadTemplates: function() {
            var self = this;
            var container = document.getElementById('shift-view-templates');
            if (!container) return;
            container.innerHTML = '<p>読み込み中...</p>';

            apiGet('templates.php', { store_id: self.storeId }, function(data, err) {
                if (err) {
                    container.innerHTML = '<p class="error">テンプレートの取得に失敗しました</p>';
                    return;
                }
                self._renderTemplates(container, data.templates);
            });
        },

        _renderTemplates: function(container, templates) {
            var self = this;
            var html = '<div class="shift-section-header">' +
                       '<h3>シフトテンプレート</h3>' +
                       '<button class="btn btn-primary" id="btn-add-template">＋ テンプレート追加</button>' +
                       '</div>';

            if (templates.length === 0) {
                html += '<p class="empty-msg">テンプレートがありません。「テンプレート追加」から作成してください。</p>';
            } else {
                html += '<table class="shift-table"><thead><tr>' +
                        '<th>名前</th><th>曜日</th><th>開始</th><th>終了</th><th>必要人数</th><th>役割</th><th>操作</th>' +
                        '</tr></thead><tbody>';
                for (var i = 0; i < templates.length; i++) {
                    var t = templates[i];
                    html += '<tr>' +
                            '<td>' + esc(t.name) + '</td>' +
                            '<td>' + DAY_NAMES[t.day_of_week] + '</td>' +
                            '<td>' + esc(t.start_time).substring(0, 5) + '</td>' +
                            '<td>' + esc(t.end_time).substring(0, 5) + '</td>' +
                            '<td>' + t.required_staff + '人</td>' +
                            '<td>' + esc(t.role_hint || '指定なし') + '</td>' +
                            '<td>' +
                            '<button class="btn btn-sm btn-edit-template" data-id="' + t.id + '">編集</button> ' +
                            '<button class="btn btn-sm btn-danger btn-delete-template" data-id="' + t.id + '">削除</button>' +
                            '</td></tr>';
                }
                html += '</tbody></table>';
            }

            container.innerHTML = html;

            // イベントバインド
            var addBtn = document.getElementById('btn-add-template');
            if (addBtn) {
                addBtn.addEventListener('click', function() { self._showTemplateDialog(null); });
            }
            var editBtns = container.querySelectorAll('.btn-edit-template');
            for (var j = 0; j < editBtns.length; j++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        var tpl = null;
                        for (var k = 0; k < templates.length; k++) {
                            if (templates[k].id === btn.getAttribute('data-id')) {
                                tpl = templates[k];
                                break;
                            }
                        }
                        self._showTemplateDialog(tpl);
                    });
                })(editBtns[j]);
            }
            var delBtns = container.querySelectorAll('.btn-delete-template');
            for (var m = 0; m < delBtns.length; m++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        if (!confirm('このテンプレートを削除しますか？')) return;
                        apiDelete('templates.php?id=' + btn.getAttribute('data-id') + '&store_id=' + self.storeId, function(data, err) {
                            if (err) { alert('削除に失敗しました'); return; }
                            self.loadTemplates();
                        });
                    });
                })(delBtns[m]);
            }
        },

        _showTemplateDialog: function(existing) {
            var self = this;
            var isEdit = !!existing;
            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';

            var dayHtml = '';
            if (isEdit) {
                // 編集時は単一選択
                var dayOptions = '';
                for (var d = 0; d < 7; d++) {
                    var sel = (existing.day_of_week === d) ? ' selected' : '';
                    dayOptions += '<option value="' + d + '"' + sel + '>' + DAY_NAMES[d] + '</option>';
                }
                dayHtml = '<label>曜日<select id="tpl-dow">' + dayOptions + '</select></label>';
            } else {
                // 新規時はチェックボックスで複数選択
                dayHtml = '<div style="margin-bottom:8px;"><span style="font-weight:600;">曜日</span>' +
                    ' <a href="#" id="tpl-dow-all" style="font-size:0.85rem;margin-left:8px;">全選択</a>' +
                    ' <a href="#" id="tpl-dow-none" style="font-size:0.85rem;margin-left:4px;">全解除</a></div>' +
                    '<div id="tpl-dow-checks" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">';
                for (var d = 0; d < 7; d++) {
                    dayHtml += '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;">' +
                        '<input type="checkbox" class="tpl-dow-cb" value="' + d + '"> ' + DAY_NAMES[d] + '</label>';
                }
                dayHtml += '</div>';
            }

            overlay.innerHTML = '<div class="shift-dialog">' +
                '<h3>' + (isEdit ? 'テンプレート編集' : 'テンプレート追加') + '</h3>' +
                '<label>名前<input type="text" id="tpl-name" value="' + esc(existing ? existing.name : '') + '"></label>' +
                dayHtml +
                '<label>開始時刻<input type="time" id="tpl-start" value="' + (existing ? existing.start_time.substring(0, 5) : '09:00') + '"></label>' +
                '<label>終了時刻<input type="time" id="tpl-end" value="' + (existing ? existing.end_time.substring(0, 5) : '17:00') + '"></label>' +
                '<label>必要人数<input type="number" id="tpl-staff" min="1" max="20" value="' + (existing ? existing.required_staff : 1) + '"></label>' +
                '<label>役割<select id="tpl-role"><option value="">指定なし</option>' +
                '<option value="kitchen"' + (existing && existing.role_hint === 'kitchen' ? ' selected' : '') + '>kitchen</option>' +
                '<option value="hall"' + (existing && existing.role_hint === 'hall' ? ' selected' : '') + '>hall</option>' +
                '</select></label>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="tpl-save">保存</button>' +
                '<button class="btn" id="tpl-cancel">キャンセル</button>' +
                '</div></div>';

            document.body.appendChild(overlay);

            // 全選択/全解除（新規時のみ）
            if (!isEdit) {
                document.getElementById('tpl-dow-all').addEventListener('click', function(e) {
                    e.preventDefault();
                    var cbs = document.querySelectorAll('.tpl-dow-cb');
                    for (var i = 0; i < cbs.length; i++) cbs[i].checked = true;
                });
                document.getElementById('tpl-dow-none').addEventListener('click', function(e) {
                    e.preventDefault();
                    var cbs = document.querySelectorAll('.tpl-dow-cb');
                    for (var i = 0; i < cbs.length; i++) cbs[i].checked = false;
                });
            }

            document.getElementById('tpl-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });

            document.getElementById('tpl-save').addEventListener('click', function() {
                var baseData = {
                    store_id: self.storeId,
                    name: document.getElementById('tpl-name').value,
                    start_time: document.getElementById('tpl-start').value,
                    end_time: document.getElementById('tpl-end').value,
                    required_staff: parseInt(document.getElementById('tpl-staff').value, 10),
                    role_hint: document.getElementById('tpl-role').value || null
                };

                if (isEdit) {
                    baseData.day_of_week = parseInt(document.getElementById('tpl-dow').value, 10);
                    apiPatch('templates.php?id=' + existing.id + '&store_id=' + self.storeId, baseData, function(result, err) {
                        if (err) { alert('保存に失敗しました: ' + (err.message || '')); return; }
                        document.body.removeChild(overlay);
                        self.loadTemplates();
                    });
                } else {
                    // 複数曜日を順次POST
                    var cbs = document.querySelectorAll('.tpl-dow-cb:checked');
                    if (cbs.length === 0) { alert('曜日を1つ以上選択してください'); return; }
                    var days = [];
                    for (var i = 0; i < cbs.length; i++) days.push(parseInt(cbs[i].value, 10));
                    var done = 0;
                    var errors = 0;
                    var saveBtn = document.getElementById('tpl-save');
                    saveBtn.disabled = true;
                    saveBtn.textContent = '保存中...';

                    function postNext() {
                        if (done + errors >= days.length) {
                            document.body.removeChild(overlay);
                            if (errors > 0) alert(days.length + '件中 ' + errors + '件失敗しました');
                            self.loadTemplates();
                            return;
                        }
                        var data = {};
                        for (var k in baseData) data[k] = baseData[k];
                        data.day_of_week = days[done + errors];
                        apiPost('templates.php?store_id=' + encodeURIComponent(self.storeId), data, function(result, err) {
                            if (err) { errors++; } else { done++; }
                            postNext();
                        });
                    }
                    postNext();
                    return;
                }
            });
        },

        // ──────────────────────────────
        // 希望提出
        // ──────────────────────────────
        _availWeekStart: null,

        loadAvailabilities: function() {
            var self = this;
            var container = document.getElementById('shift-view-availability');
            if (!container) return;

            if (!self._availWeekStart) {
                // 今週の月曜日
                var today = new Date();
                var day = today.getDay();
                var diff = today.getDate() - day + (day === 0 ? -6 : 1);
                self._availWeekStart = new Date(today.setDate(diff + 7)); // 来週を初期表示
                self._availWeekStart.setHours(0, 0, 0, 0);
            }

            var start = self._formatDate(self._availWeekStart);
            var endDt = new Date(self._availWeekStart);
            endDt.setDate(endDt.getDate() + 6);
            var end = self._formatDate(endDt);

            container.innerHTML = '<p>読み込み中...</p>';

            apiGet('availabilities.php', { store_id: self.storeId, start_date: start, end_date: end }, function(data, err) {
                if (err) {
                    container.innerHTML = '<p class="error">読み込みに失敗しました</p>';
                    return;
                }
                self._renderAvailabilities(container, data.availabilities, start, endDt);
            });
        },

        _renderAvailabilities: function(container, existing, startStr, endDt) {
            var self = this;
            var startDt = new Date(startStr + 'T00:00:00');

            // 既存データをマップ化
            var existMap = {};
            for (var e = 0; e < existing.length; e++) {
                existMap[existing[e].target_date] = existing[e];
            }

            var html = '<div class="shift-section-header">' +
                       '<button class="btn btn-sm" id="avail-prev">◀ 前の週</button>' +
                       '<h3>' + startStr + ' 〜 ' + self._formatDate(endDt) + ' シフト希望</h3>' +
                       '<button class="btn btn-sm" id="avail-next">次の週 ▶</button>' +
                       '</div>';

            html += '<div class="avail-form">';
            var dt = new Date(startDt);
            for (var d = 0; d < 7; d++) {
                var dateStr = self._formatDate(dt);
                var ex = existMap[dateStr] || {};
                var avail = ex.availability || 'available';
                var pStart = (ex.preferred_start || '').substring(0, 5);
                var pEnd = (ex.preferred_end || '').substring(0, 5);

                html += '<div class="avail-day" data-date="' + dateStr + '">' +
                        '<div class="avail-day-label">' + (dt.getMonth() + 1) + '/' + dt.getDate() + '(' + DAY_NAMES[dt.getDay()] + ')</div>' +
                        '<div class="avail-btns">' +
                        '<button class="avail-btn' + (avail === 'available' ? ' active' : '') + '" data-val="available">○ 出勤可</button>' +
                        '<button class="avail-btn' + (avail === 'preferred' ? ' active' : '') + '" data-val="preferred">◎ 希望</button>' +
                        '<button class="avail-btn' + (avail === 'unavailable' ? ' active' : '') + '" data-val="unavailable">× 不可</button>' +
                        '</div>' +
                        '<div class="avail-times"' + (avail === 'unavailable' ? ' style="display:none"' : '') + '>' +
                        '<input type="time" class="avail-start" value="' + (pStart || '09:00') + '"> 〜 ' +
                        '<input type="time" class="avail-end" value="' + (pEnd || '18:00') + '">' +
                        '</div></div>';
                dt.setDate(dt.getDate() + 1);
            }

            html += '<div class="avail-note-wrap">' +
                    '<label>メモ<textarea id="avail-note" rows="2" placeholder="午前のみ希望、など"></textarea></label>' +
                    '</div>';
            html += '<button class="btn btn-primary" id="avail-submit">提出する</button>';
            html += '</div>';

            container.innerHTML = html;

            // 可/希望/不可ボタン切替
            var availBtns = container.querySelectorAll('.avail-btn');
            for (var b = 0; b < availBtns.length; b++) {
                availBtns[b].addEventListener('click', function() {
                    var parent = this.parentElement;
                    var btns = parent.querySelectorAll('.avail-btn');
                    for (var x = 0; x < btns.length; x++) btns[x].classList.remove('active');
                    this.classList.add('active');
                    var times = this.parentElement.parentElement.querySelector('.avail-times');
                    if (times) {
                        times.style.display = this.getAttribute('data-val') === 'unavailable' ? 'none' : '';
                    }
                });
            }

            // 週ナビ
            document.getElementById('avail-prev').addEventListener('click', function() {
                self._availWeekStart.setDate(self._availWeekStart.getDate() - 7);
                self.loadAvailabilities();
            });
            document.getElementById('avail-next').addEventListener('click', function() {
                self._availWeekStart.setDate(self._availWeekStart.getDate() + 7);
                self.loadAvailabilities();
            });

            // 提出
            document.getElementById('avail-submit').addEventListener('click', function() {
                var days = container.querySelectorAll('.avail-day');
                var items = [];
                var note = document.getElementById('avail-note').value;
                for (var i = 0; i < days.length; i++) {
                    var day = days[i];
                    var active = day.querySelector('.avail-btn.active');
                    if (!active) continue;
                    var item = {
                        target_date: day.getAttribute('data-date'),
                        availability: active.getAttribute('data-val'),
                        note: note
                    };
                    if (item.availability !== 'unavailable') {
                        item.preferred_start = day.querySelector('.avail-start').value;
                        item.preferred_end = day.querySelector('.avail-end').value;
                    }
                    items.push(item);
                }

                apiPost('availabilities.php?store_id=' + encodeURIComponent(self.storeId), { store_id: self.storeId, availabilities: items }, function(data, err) {
                    if (err) { alert('提出に失敗しました: ' + (err.message || '')); return; }
                    alert('希望を提出しました');
                    self.loadAvailabilities();
                });
            });
        },

        // ──────────────────────────────
        // マイシフト
        // ──────────────────────────────
        _myShiftWeekStart: null,

        loadMyShift: function() {
            var self = this;
            var container = document.getElementById('shift-view-my-shift');
            if (!container) return;

            if (!self._myShiftWeekStart) {
                var today = new Date();
                var day = today.getDay();
                var diff = today.getDate() - day + (day === 0 ? -6 : 1);
                self._myShiftWeekStart = new Date(today.setDate(diff));
                self._myShiftWeekStart.setHours(0, 0, 0, 0);
            }

            var start = self._formatDate(self._myShiftWeekStart);
            var endDt = new Date(self._myShiftWeekStart);
            endDt.setDate(endDt.getDate() + 6);
            var end = self._formatDate(endDt);

            container.innerHTML = '<p>読み込み中...</p>';

            apiGet('assignments.php', { store_id: self.storeId, start_date: start, end_date: end }, function(data, err) {
                if (err) {
                    container.innerHTML = '<p class="error">読み込みに失敗しました</p>';
                    return;
                }
                self._renderMyShift(container, data.assignments, start, end);
            });
        },

        _renderMyShift: function(container, assignments, start, end) {
            var self = this;
            var html = '<div class="shift-section-header">' +
                       '<button class="btn btn-sm" id="myshift-prev">◀ 前の週</button>' +
                       '<h3>マイシフト ' + start + ' 〜 ' + end + '</h3>' +
                       '<button class="btn btn-sm" id="myshift-next">次の週 ▶</button>' +
                       '</div>';

            if (assignments.length === 0) {
                html += '<p class="empty-msg">この週のシフトはまだありません。</p>';
            } else {
                html += '<table class="shift-table"><thead><tr>' +
                        '<th>日付</th><th>開始</th><th>終了</th><th>休憩</th><th>役割</th><th>ステータス</th>' +
                        '</tr></thead><tbody>';
                for (var i = 0; i < assignments.length; i++) {
                    var a = assignments[i];
                    var statusLabel = { draft: '下書き', published: '確定', confirmed: '確認済' };
                    var statusClass = { draft: 'status-draft', published: 'status-published', confirmed: 'status-confirmed' };
                    html += '<tr>' +
                            '<td>' + esc(a.shift_date) + '</td>' +
                            '<td>' + esc(a.start_time).substring(0, 5) + '</td>' +
                            '<td>' + esc(a.end_time).substring(0, 5) + '</td>' +
                            '<td>' + a.break_minutes + '分</td>' +
                            '<td>' + esc(a.role_type || '-') + '</td>' +
                            '<td><span class="' + (statusClass[a.status] || '') + '">' + (statusLabel[a.status] || esc(a.status)) + '</span></td>' +
                            '</tr>';
                }
                html += '</tbody></table>';
            }

            container.innerHTML = html;

            document.getElementById('myshift-prev').addEventListener('click', function() {
                self._myShiftWeekStart.setDate(self._myShiftWeekStart.getDate() - 7);
                self.loadMyShift();
            });
            document.getElementById('myshift-next').addEventListener('click', function() {
                self._myShiftWeekStart.setDate(self._myShiftWeekStart.getDate() + 7);
                self.loadMyShift();
            });
        },

        // ──────────────────────────────
        // 勤怠一覧（manager+）
        // ──────────────────────────────
        loadAttendance: function() {
            var self = this;
            var container = document.getElementById('shift-view-attendance');
            if (!container) return;

            // 今週
            var today = new Date();
            var day = today.getDay();
            var diff = today.getDate() - day + (day === 0 ? -6 : 1);
            var weekStart = new Date(today);
            weekStart.setDate(diff);
            var weekEnd = new Date(weekStart);
            weekEnd.setDate(weekEnd.getDate() + 6);

            var start = self._formatDate(weekStart);
            var end = self._formatDate(weekEnd);

            container.innerHTML = '<p>読み込み中...</p>';

            apiGet('attendance.php', { store_id: self.storeId, start_date: start, end_date: end }, function(data, err) {
                if (err) {
                    container.innerHTML = '<p class="error">勤怠データの取得に失敗しました</p>';
                    return;
                }
                self._renderAttendance(container, data.attendance, start, end);
            });
        },

        _renderAttendance: function(container, records, start, end) {
            var self = this;
            var html = '<div class="shift-section-header">' +
                       '<h3>勤怠一覧 ' + start + ' 〜 ' + end + '</h3>' +
                       '</div>';

            if (records.length === 0) {
                html += '<p class="empty-msg">この期間の勤怠データはありません。</p>';
            } else {
                html += '<table class="shift-table"><thead><tr>' +
                        '<th>スタッフ</th><th>出勤</th><th>退勤</th><th>休憩</th><th>ステータス</th><th>操作</th>' +
                        '</tr></thead><tbody>';
                for (var i = 0; i < records.length; i++) {
                    var r = records[i];
                    var clockOut = r.clock_out || '—';
                    var statusLabel = { working: '勤務中', completed: '完了', absent: '欠勤', late: '遅刻' };
                    html += '<tr>' +
                            '<td>' + esc(r.display_name || r.username) + '</td>' +
                            '<td>' + esc(r.clock_in) + '</td>' +
                            '<td>' + esc(clockOut) + '</td>' +
                            '<td>' + r.break_minutes + '分</td>' +
                            '<td>' + (statusLabel[r.status] || esc(r.status)) + '</td>' +
                            '<td><button class="btn btn-sm btn-edit-attendance" data-id="' + r.id + '">修正</button></td>' +
                            '</tr>';
                }
                html += '</tbody></table>';
            }

            container.innerHTML = html;

            var editBtns = container.querySelectorAll('.btn-edit-attendance');
            for (var j = 0; j < editBtns.length; j++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        var rec = null;
                        for (var k = 0; k < records.length; k++) {
                            if (records[k].id === btn.getAttribute('data-id')) { rec = records[k]; break; }
                        }
                        if (rec) self._showAttendanceEditDialog(rec);
                    });
                })(editBtns[j]);
            }
        },

        _showAttendanceEditDialog: function(record) {
            var self = this;
            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';
            overlay.innerHTML = '<div class="shift-dialog">' +
                '<h3>勤怠修正: ' + esc(record.display_name || record.username) + '</h3>' +
                '<label>出勤<input type="datetime-local" id="att-in" value="' + (record.clock_in || '').replace(' ', 'T').substring(0, 16) + '"></label>' +
                '<label>退勤<input type="datetime-local" id="att-out" value="' + (record.clock_out || '').replace(' ', 'T').substring(0, 16) + '"></label>' +
                '<label>休憩(分)<input type="number" id="att-break" min="0" max="480" value="' + record.break_minutes + '"></label>' +
                '<label>修正理由<input type="text" id="att-note" placeholder="打刻忘れ等" required></label>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="att-save">保存</button>' +
                '<button class="btn" id="att-cancel">キャンセル</button>' +
                '</div></div>';

            document.body.appendChild(overlay);

            document.getElementById('att-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });

            document.getElementById('att-save').addEventListener('click', function() {
                var note = document.getElementById('att-note').value;
                if (!note.trim()) { alert('修正理由を入力してください'); return; }

                var clockIn = document.getElementById('att-in').value.replace('T', ' ');
                var clockOut = document.getElementById('att-out').value.replace('T', ' ');

                var body = {
                    clock_in: clockIn,
                    break_minutes: parseInt(document.getElementById('att-break').value, 10),
                    note: note
                };
                if (clockOut) body.clock_out = clockOut;

                apiPatch('attendance.php?id=' + record.id + '&store_id=' + self.storeId, body, function(data, err) {
                    if (err) { alert('修正に失敗しました: ' + (err.message || '')); return; }
                    document.body.removeChild(overlay);
                    self.loadAttendance();
                });
            });
        },

        // ──────────────────────────────
        // 設定
        // ──────────────────────────────
        loadSettings: function() {
            var self = this;
            var container = document.getElementById('shift-view-shift-settings');
            if (!container) return;
            container.innerHTML = '<p>読み込み中...</p>';

            apiGet('settings.php', { store_id: self.storeId }, function(data, err) {
                if (err) {
                    container.innerHTML = '<p class="error">設定の取得に失敗しました</p>';
                    return;
                }
                self._renderSettings(container, data);
            });
        },

        _renderSettings: function(container, settings) {
            var self = this;

            // L-3b: スタッフ表示ツールのチェック状態
            var visTools = settings.staff_visible_tools ? settings.staff_visible_tools.split(',') : [];
            var allTools = !settings.staff_visible_tools; // NULL = 全表示
            function isToolChecked(tool) {
                return allTools || visTools.indexOf(tool) !== -1;
            }

            var html = '<div class="shift-section-header"><h3>シフト設定</h3></div>' +
                '<div class="shift-settings-form">' +
                '<label>希望提出締切日（毎月N日）<input type="number" id="set-deadline" min="1" max="28" value="' + settings.submission_deadline_day + '"></label>' +
                '<label>デフォルト休憩時間（分）<input type="number" id="set-break" min="0" max="120" value="' + settings.default_break_minutes + '"></label>' +
                '<label>残業判定閾値（分/日）<input type="number" id="set-overtime" min="60" max="720" value="' + settings.overtime_threshold_minutes + '"></label>' +
                '<label>早出打刻許容（分）<input type="number" id="set-early" min="0" max="60" value="' + settings.early_clock_in_minutes + '"></label>' +
                '<label>自動退勤（時間）<input type="number" id="set-auto" min="1" max="24" value="' + settings.auto_clock_out_hours + '"></label>' +

                // L-3b: GPS出退勤制御
                '<hr style="margin:1.5rem 0;border-color:#e0e0e0;">' +
                '<h4 style="margin-bottom:0.5rem;">GPS出退勤制御</h4>' +
                '<label>GPS出退勤 <select id="set-gps-required">' +
                '<option value="0"' + (settings.gps_required === 0 ? ' selected' : '') + '>無効</option>' +
                '<option value="1"' + (settings.gps_required === 1 ? ' selected' : '') + '>有効（必須）</option>' +
                '</select></label>' +
                '<div id="gps-detail-fields" style="' + (settings.gps_required === 1 ? '' : 'display:none;') + '">' +
                '<button class="btn btn-primary" id="set-gps-detect" type="button" style="margin-bottom:1rem;width:100%;padding:0.6rem;">📍 現在地から店舗位置を設定</button>' +
                '<label>店舗緯度 <input type="number" id="set-lat" step="0.0000001" min="-90" max="90" value="' + (settings.store_lat !== null ? settings.store_lat : '') + '" placeholder="35.6812362"></label>' +
                '<label>店舗経度 <input type="number" id="set-lng" step="0.0000001" min="-180" max="180" value="' + (settings.store_lng !== null ? settings.store_lng : '') + '" placeholder="139.7671248"></label>' +
                '<label>許容半径（m） <input type="number" id="set-radius" min="50" max="1000" value="' + settings.gps_radius_meters + '"></label>' +
                '</div>' +

                // L-3b: スタッフ表示ツール
                '<hr style="margin:1.5rem 0;border-color:#e0e0e0;">' +
                '<h4 style="margin-bottom:0.5rem;">スタッフ表示ツール</h4>' +
                '<p style="color:#666;font-size:0.85rem;margin-bottom:0.5rem;">チェックなし＝全ツール表示。チェックしたツールのみスタッフ画面に表示されます。</p>' +
                '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:1rem;"><input type="checkbox" id="set-tool-handy"' + (isToolChecked('handy') ? ' checked' : '') + '> ハンディPOS</label>' +
                '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:1rem;"><input type="checkbox" id="set-tool-kds"' + (isToolChecked('kds') ? ' checked' : '') + '> KDS</label>' +
                '<label style="display:inline-flex;align-items:center;gap:4px;"><input type="checkbox" id="set-tool-register"' + (isToolChecked('register') ? ' checked' : '') + '> POSレジ</label>' +

                // L-3 Phase 2: デフォルト時給
                '<hr style="margin:1.5rem 0;border-color:#e0e0e0;">' +
                '<h4 style="margin-bottom:0.5rem;">人件費設定</h4>' +
                '<label>デフォルト時給（円） <input type="number" id="set-hourly-rate" min="0" max="10000" value="' + (settings.default_hourly_rate !== null ? settings.default_hourly_rate : '') + '" placeholder="未設定"></label>' +
                '<p style="color:#666;font-size:0.85rem;margin-top:0.25rem;">スタッフ個別の時給はユーザー管理で設定できます。空欄＝人件費計算なし</p>' +

                '<div style="margin-top:1.5rem;"><button class="btn btn-primary" id="set-save">保存</button></div>' +
                '</div>';

            container.innerHTML = html;

            // GPS有効/無効の切替で詳細フィールド表示制御
            var gpsSelect = document.getElementById('set-gps-required');
            var gpsDetail = document.getElementById('gps-detail-fields');
            gpsSelect.addEventListener('change', function() {
                gpsDetail.style.display = this.value === '1' ? '' : 'none';
            });

            // 現在地取得ボタン
            var detectBtn = document.getElementById('set-gps-detect');
            detectBtn.addEventListener('click', function() {
                if (!navigator.geolocation) {
                    alert('このブラウザは位置情報に対応していません');
                    return;
                }
                detectBtn.textContent = '📍 取得中...';
                detectBtn.disabled = true;
                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        document.getElementById('set-lat').value = pos.coords.latitude.toFixed(7);
                        document.getElementById('set-lng').value = pos.coords.longitude.toFixed(7);
                        detectBtn.textContent = '📍 現在地から店舗位置を設定';
                        detectBtn.disabled = false;
                    },
                    function(err) {
                        alert('位置情報の取得に失敗しました: ' + err.message);
                        detectBtn.textContent = '📍 現在地から店舗位置を設定';
                        detectBtn.disabled = false;
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            });

            // 保存ボタン
            document.getElementById('set-save').addEventListener('click', function() {
                var latVal = document.getElementById('set-lat').value;
                var lngVal = document.getElementById('set-lng').value;

                // スタッフ表示ツール CSV 生成
                var toolChecks = [];
                if (document.getElementById('set-tool-handy').checked) toolChecks.push('handy');
                if (document.getElementById('set-tool-kds').checked) toolChecks.push('kds');
                if (document.getElementById('set-tool-register').checked) toolChecks.push('register');
                // 全チェックまたは全未チェックの場合は NULL（全表示）
                var toolsValue = (toolChecks.length === 3 || toolChecks.length === 0) ? null : toolChecks.join(',');

                // L-3 Phase 2: デフォルト時給
                var hourlyRateVal = document.getElementById('set-hourly-rate').value;
                var hourlyRate = hourlyRateVal !== '' ? parseInt(hourlyRateVal, 10) : null;

                var body = {
                    store_id: self.storeId,
                    submission_deadline_day: parseInt(document.getElementById('set-deadline').value, 10),
                    default_break_minutes: parseInt(document.getElementById('set-break').value, 10),
                    overtime_threshold_minutes: parseInt(document.getElementById('set-overtime').value, 10),
                    early_clock_in_minutes: parseInt(document.getElementById('set-early').value, 10),
                    auto_clock_out_hours: parseInt(document.getElementById('set-auto').value, 10),
                    gps_required: parseInt(gpsSelect.value, 10),
                    store_lat: latVal !== '' ? parseFloat(latVal) : null,
                    store_lng: lngVal !== '' ? parseFloat(lngVal) : null,
                    gps_radius_meters: parseInt(document.getElementById('set-radius').value, 10),
                    staff_visible_tools: toolsValue,
                    default_hourly_rate: hourlyRate
                };

                apiPatch('settings.php?store_id=' + self.storeId, body, function(data, err) {
                    if (err) { alert('保存に失敗しました: ' + (err.message || '')); return; }
                    alert('設定を保存しました');
                });
            });
        },

        // ──────────────────────────────
        // 集計サマリー（L-3 Phase 2）
        // ──────────────────────────────
        _summaryWeekStart: null,
        _summaryPeriod: 'weekly',

        loadSummary: function() {
            var self = this;
            var container = document.getElementById('shift-view-summary');
            if (!container) return;

            if (!self._summaryWeekStart) {
                var today = new Date();
                var day = today.getDay();
                var diff = today.getDate() - day + (day === 0 ? -6 : 1);
                self._summaryWeekStart = new Date(today.setDate(diff));
                self._summaryWeekStart.setHours(0, 0, 0, 0);
            }

            var dateParam;
            if (self._summaryPeriod === 'weekly') {
                dateParam = self._formatDate(self._summaryWeekStart);
            } else {
                dateParam = self._summaryWeekStart.getFullYear() + '-' + ('0' + (self._summaryWeekStart.getMonth() + 1)).slice(-2);
            }

            container.innerHTML = '<p>読み込み中...</p>';

            apiGet('summary.php', {
                store_id: self.storeId,
                period: self._summaryPeriod,
                date: dateParam
            }, function(data, err) {
                if (err) {
                    container.innerHTML = '<p class="error">集計データの取得に失敗しました</p>';
                    return;
                }
                self._renderSummary(container, data);
            });
        },

        _renderSummary: function(container, data) {
            var self = this;
            var html = '';

            // ナビゲーション
            html += '<div class="shift-section-header">';
            html += '<button class="btn btn-sm" id="summary-prev">◀ 前</button>';
            html += '<h3>集計サマリー ' + esc(data.period) + '</h3>';
            html += '<button class="btn btn-sm" id="summary-next">次 ▶</button>';
            html += '</div>';

            // 期間切替
            html += '<div style="margin-bottom:1rem;">';
            html += '<button class="btn btn-sm' + (self._summaryPeriod === 'weekly' ? ' btn-primary' : '') + '" id="summary-weekly">週次</button> ';
            html += '<button class="btn btn-sm' + (self._summaryPeriod === 'monthly' ? ' btn-primary' : '') + '" id="summary-monthly">月次</button>';
            html += '</div>';

            // 労基法警告パネル
            if (data.labor_warnings && data.labor_warnings.length > 0) {
                var errors = [];
                var warnings = [];
                var infos = [];
                for (var w = 0; w < data.labor_warnings.length; w++) {
                    var lw = data.labor_warnings[w];
                    if (lw.level === 'error') errors.push(lw);
                    else if (lw.level === 'warning') warnings.push(lw);
                    else infos.push(lw);
                }
                var alertCount = errors.length + warnings.length;
                if (alertCount > 0) {
                    html += '<div style="background:#fff3e0;border:1px solid #ff9800;border-radius:8px;padding:12px 16px;margin-bottom:1rem;">';
                    html += '<strong style="color:#e65100;">⚠ 労基法チェック（' + alertCount + '件の警告）</strong>';
                    for (var ei = 0; ei < errors.length; ei++) {
                        html += '<div style="color:#c62828;margin-top:4px;">🔴 ' + esc(errors[ei].message) + '</div>';
                    }
                    for (var wi = 0; wi < warnings.length; wi++) {
                        html += '<div style="color:#e65100;margin-top:4px;">🟡 ' + esc(warnings[wi].message) + '</div>';
                    }
                    html += '</div>';
                }
                if (infos.length > 0) {
                    html += '<div style="background:#e3f2fd;border:1px solid #2196f3;border-radius:8px;padding:12px 16px;margin-bottom:1rem;">';
                    for (var ii = 0; ii < infos.length; ii++) {
                        html += '<div style="color:#1565c0;margin-top:2px;">🔵 ' + esc(infos[ii].message) + '</div>';
                    }
                    html += '</div>';
                }
            }

            // 合計
            html += '<div style="display:flex;gap:1.5rem;margin-bottom:1rem;flex-wrap:wrap;">';
            html += '<div style="background:var(--bg-secondary,#f5f5f5);padding:12px 16px;border-radius:8px;"><strong>総労働時間</strong><br><span style="font-size:1.5rem;">' + data.total_hours + 'h</span></div>';
            if (data.labor_cost_total > 0) {
                html += '<div style="background:var(--bg-secondary,#f5f5f5);padding:12px 16px;border-radius:8px;"><strong>総人件費</strong><br><span style="font-size:1.5rem;">¥' + data.labor_cost_total.toLocaleString() + '</span></div>';
            }
            if (data.night_premium_total > 0) {
                html += '<div style="background:var(--bg-secondary,#f5f5f5);padding:12px 16px;border-radius:8px;"><strong>深夜手当合計</strong><br><span style="font-size:1.5rem;">¥' + data.night_premium_total.toLocaleString() + '</span></div>';
            }
            html += '</div>';

            // スタッフ別テーブル
            html += '<h4>スタッフ別</h4>';
            html += '<table class="shift-table"><thead><tr>';
            html += '<th>スタッフ</th><th>出勤日数</th><th>労働時間</th><th>残業</th><th>深夜</th><th>遅刻</th><th>時給</th><th>人件費</th>';
            html += '</tr></thead><tbody>';
            for (var s = 0; s < data.staff_summary.length; s++) {
                var st = data.staff_summary[s];
                // 警告アイコン
                var warnIcon = '';
                if (data.labor_warnings) {
                    for (var lw2 = 0; lw2 < data.labor_warnings.length; lw2++) {
                        if (data.labor_warnings[lw2].user_id === st.user_id && data.labor_warnings[lw2].level === 'error') {
                            warnIcon = ' 🔴';
                            break;
                        }
                        if (data.labor_warnings[lw2].user_id === st.user_id && data.labor_warnings[lw2].level === 'warning') {
                            warnIcon = ' 🟡';
                        }
                    }
                }
                html += '<tr>';
                html += '<td>' + esc(st.display_name || st.username) + warnIcon + '</td>';
                html += '<td>' + st.days_worked + '日</td>';
                html += '<td>' + st.total_hours + 'h</td>';
                html += '<td>' + st.overtime_hours + 'h</td>';
                html += '<td>' + (st.night_hours || 0) + 'h</td>';
                html += '<td>' + st.late_count + '回</td>';
                html += '<td>' + (st.hourly_rate !== null ? '¥' + st.hourly_rate.toLocaleString() : '-') + '</td>';
                html += '<td>' + (st.labor_cost !== null ? '¥' + st.labor_cost.toLocaleString() : '-') + '</td>';
                html += '</tr>';
            }
            html += '</tbody></table>';

            // 日別テーブル
            if (data.daily_summary.length > 0) {
                html += '<h4 style="margin-top:1.5rem;">日別</h4>';
                html += '<table class="shift-table"><thead><tr>';
                html += '<th>日付</th><th>出勤人数</th><th>労働時間</th><th>人件費</th>';
                html += '</tr></thead><tbody>';
                for (var di = 0; di < data.daily_summary.length; di++) {
                    var dd = data.daily_summary[di];
                    html += '<tr>';
                    html += '<td>' + esc(dd.date) + '</td>';
                    html += '<td>' + dd.staff_count + '人</td>';
                    html += '<td>' + dd.total_hours + 'h</td>';
                    html += '<td>' + (dd.labor_cost !== null ? '¥' + dd.labor_cost.toLocaleString() : '-') + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
            }

            container.innerHTML = html;

            // ナビゲーションイベント
            document.getElementById('summary-prev').addEventListener('click', function() {
                if (self._summaryPeriod === 'weekly') {
                    self._summaryWeekStart.setDate(self._summaryWeekStart.getDate() - 7);
                } else {
                    self._summaryWeekStart.setMonth(self._summaryWeekStart.getMonth() - 1);
                }
                self.loadSummary();
            });
            document.getElementById('summary-next').addEventListener('click', function() {
                if (self._summaryPeriod === 'weekly') {
                    self._summaryWeekStart.setDate(self._summaryWeekStart.getDate() + 7);
                } else {
                    self._summaryWeekStart.setMonth(self._summaryWeekStart.getMonth() + 1);
                }
                self.loadSummary();
            });
            document.getElementById('summary-weekly').addEventListener('click', function() {
                self._summaryPeriod = 'weekly';
                self.loadSummary();
            });
            document.getElementById('summary-monthly').addEventListener('click', function() {
                self._summaryPeriod = 'monthly';
                self.loadSummary();
            });
        },

        // ─── ヘルパー ───
        _formatDate: function(dt) {
            var y = dt.getFullYear();
            var m = ('0' + (dt.getMonth() + 1)).slice(-2);
            var d = ('0' + dt.getDate()).slice(-2);
            return y + '-' + m + '-' + d;
        }
    };

    window.ShiftManager = ShiftManager;
})();
