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

    function notify(message, type) {
        if (typeof showToast === 'function') {
            showToast(message, type || 'info');
        } else {
            alert(message);
        }
    }

    var DAY_NAMES = ['日', '月', '火', '水', '木', '金', '土'];

    // ─── ShiftManager オブジェクト ───
    var ShiftManager = {
        storeId: null,
        userRole: null,
        currentView: 'calendar',
        positions: [],

        init: function(storeId, userRole) {
            this.storeId = storeId;
            this.userRole = userRole || 'staff';
            this._bindSubTabs();
            this._loadPositions();
        },

        _loadPositions: function(callback) {
            var self = this;
            apiGet('positions.php', { store_id: self.storeId }, function(data, err) {
                if (!err && data && data.positions) {
                    self.positions = data.positions;
                } else {
                    self.positions = [
                        { code: 'hall', label: 'ホール', is_active: 1 },
                        { code: 'kitchen', label: 'キッチン', is_active: 1 }
                    ];
                }
                if (callback) callback();
            });
        },

        _roleOptionsHtml: function(selected) {
            var html = '<option value="">指定なし</option>';
            var seen = {};
            var positions = this.positions && this.positions.length ? this.positions : [
                { code: 'hall', label: 'ホール', is_active: 1 },
                { code: 'kitchen', label: 'キッチン', is_active: 1 }
            ];
            for (var i = 0; i < positions.length; i++) {
                if (positions[i].is_active === 0) continue;
                seen[positions[i].code] = true;
                html += '<option value="' + esc(positions[i].code) + '"' +
                    (selected === positions[i].code ? ' selected' : '') + '>' +
                    esc(positions[i].label || positions[i].code) + '</option>';
            }
            if (selected && !seen[selected]) {
                html += '<option value="' + esc(selected) + '" selected>' + esc(selected) + '</option>';
            }
            return html;
        },

        _roleLabel: function(code) {
            if (!code) return '指定なし';
            var positions = this.positions && this.positions.length ? this.positions : [];
            for (var i = 0; i < positions.length; i++) {
                if (positions[i].code === code) {
                    return positions[i].label || positions[i].code;
                }
            }
            if (code === 'hall') return 'ホール';
            if (code === 'kitchen') return 'キッチン';
            return code;
        },

        _changeValueLabel: function(field, value) {
            if (value === null || typeof value === 'undefined' || value === '') {
                return 'なし';
            }
            if (field === 'role_type') {
                return this._roleLabel(value);
            }
            if (field === 'break_minutes') {
                return String(value) + '分';
            }
            return String(value);
        },

        _renderChangeDetail: function(assignment) {
            var details = assignment.confirmation_reset_detail || [];
            var html = '';
            if (details && details.length) {
                html += '<div class="shift-change-detail">';
                for (var i = 0; i < details.length; i++) {
                    html += '<span class="shift-change-detail__item"><strong>' + esc(details[i].label || '変更') + '</strong> ' +
                        esc(this._changeValueLabel(details[i].field, details[i].before_value)) +
                        ' → ' +
                        esc(this._changeValueLabel(details[i].field, details[i].after_value)) +
                        '</span>';
                }
                html += '</div>';
                return html;
            }
            return '<span class="shift-change-detail__fallback">' + esc(assignment.confirmation_reset_reason || 'シフト変更') + '</span>';
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
        // スタッフホーム
        // ──────────────────────────────
        _staffHomeData: null,

        loadStaffHome: function() {
            var self = this;
            var summary = document.getElementById('staff-home-summary');
            var todos = document.getElementById('staff-home-todos');
            var attendance = document.getElementById('staff-home-attendance-history');
            if (!summary || !todos || !attendance || !self.storeId) return;

            self._showStaffHomeSections();
            summary.innerHTML = '<p class="empty-msg">読み込み中...</p>';
            todos.innerHTML = '<p class="empty-msg">読み込み中...</p>';
            attendance.innerHTML = '<p class="empty-msg">読み込み中...</p>';

            apiGet('staff-home.php', { store_id: self.storeId }, function(data, err) {
                if (err) {
                    summary.innerHTML = '<p class="error">スタッフ画面の取得に失敗しました</p>';
                    todos.innerHTML = '';
                    attendance.innerHTML = '';
                    return;
                }
                self._staffHomeData = data;
                self._renderStaffHomeSummary(summary, data);
                self._renderStaffHomeTodos(todos, data);
                self._renderStaffHomeAttendance(attendance, data);
            });
        },

        _showStaffHomeSections: function() {
            var ids = [
                'staff-home-summary-section',
                'staff-home-todos-section',
                'staff-home-attendance-history-section'
            ];
            for (var i = 0; i < ids.length; i++) {
                var el = document.getElementById(ids[i]);
                if (el) el.style.display = '';
            }
        },

        _openStaffShiftTab: function(tabId) {
            var groupBtn = document.querySelector('.tab-nav__btn[data-group="shift"]');
            if (groupBtn) groupBtn.click();
            var subBtn = document.querySelector('.sub-tab-nav__btn[data-tab="' + tabId + '"]');
            if (subBtn) subBtn.click();
        },

        _renderStaffHomeSummary: function(container, data) {
            var self = this;
            var a = data.today_assignment || data.next_assignment;
            var isToday = data.today_assignment ? true : false;
            var working = data.working;
            var html = '<div class="staff-home-status">';
            html += '<div class="staff-home-status__main"><div>';
            if (a) {
                html += '<p class="staff-home-status__title">' +
                    (isToday ? '本日のシフト' : '次のシフト') + ' ' +
                    esc(a.shift_date || '') + ' ' + esc(a.start_time || '') + '-' + esc(a.end_time || '') +
                    '</p>';
                html += '<div class="staff-home-status__meta">' +
                    '<span class="staff-home-status__badge">' + esc(self._roleLabel(a.role_type)) + '</span>' +
                    '<span class="staff-home-status__badge">休憩 ' + esc(String(a.break_minutes || 0)) + '分</span>' +
                    '<span class="staff-home-status__badge staff-home-status__badge--' + (working ? 'work' : 'muted') + '">' +
                    (working ? '勤務中' : '未出勤') + '</span>';
                if (a.status === 'published') {
                    html += '<span class="staff-home-status__badge staff-home-status__badge--warn">' +
                        (a.confirmation_required ? '変更あり' : '未確認') + '</span>';
                } else if (a.status === 'confirmed') {
                    html += '<span class="staff-home-status__badge staff-home-status__badge--work">確認済</span>';
                }
                html += '</div>';
                if (a.note) {
                    html += '<p class="staff-home-status__note">' + esc(a.note) + '</p>';
                }
            } else {
                html += '<p class="staff-home-status__title">本日のシフトはありません</p>' +
                    '<p class="staff-home-status__note">募集シフトや希望提出が必要な場合は、下の未対応から確認してください。</p>';
            }
            html += '</div><div class="staff-home-status__actions">';
            if (a && a.status === 'published') {
                html += '<button type="button" class="btn btn-primary staff-home-confirm-shift" data-id="' + esc(a.id) + '">確認する</button>';
            }
            html += '<button type="button" class="btn btn-sm staff-home-open-myshift">マイシフト</button>';
            html += '</div></div>';
            html += '</div>';
            container.innerHTML = html;

            var confirmBtn = container.querySelector('.staff-home-confirm-shift');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function() {
                    apiPost('assignments.php?action=confirm&store_id=' + encodeURIComponent(self.storeId), {
                        id: confirmBtn.getAttribute('data-id')
                    }, function(result, err) {
                        if (err) { notify('確認に失敗しました: ' + (err.message || ''), 'error'); return; }
                        self.loadStaffHome();
                    });
                });
            }
            var openBtn = container.querySelector('.staff-home-open-myshift');
            if (openBtn) {
                openBtn.addEventListener('click', function() { self._openStaffShiftTab('shift-my'); });
            }
        },

        _renderStaffHomeTodos: function(container, data) {
            var self = this;
            var m = data.metrics || {};
            var items = [];
            if ((m.pending_confirmation_count || 0) > 0) {
                items.push({
                    label: 'シフト確認',
                    value: m.pending_confirmation_count + '件',
                    desc: '変更または未確認のシフトがあります。',
                    tab: 'shift-my',
                    warn: true
                });
            }
            if ((m.pending_task_count || 0) > 0) {
                items.push({
                    label: '担当作業',
                    value: m.pending_task_count + '件',
                    desc: '今日以降の未完了作業があります。',
                    tab: 'shift-my',
                    warn: true
                });
            }
            if ((m.open_shift_count || 0) > 0) {
                items.push({
                    label: '募集シフト',
                    value: m.open_shift_count + '件',
                    desc: '応募できる募集シフトがあります。',
                    tab: 'shift-my',
                    warn: false
                });
            }
            if ((m.pending_request_count || 0) > 0) {
                items.push({
                    label: '申請中',
                    value: m.pending_request_count + '件',
                    desc: '交代・欠勤申請が責任者確認待ちです。',
                    tab: 'shift-my',
                    warn: false
                });
            }
            if ((m.candidate_accept_count || 0) > 0) {
                items.push({
                    label: '交代候補',
                    value: m.candidate_accept_count + '件',
                    desc: '交代候補として引き受け可否の回答待ちです。',
                    tab: 'shift-my',
                    warn: true
                });
            }
            if ((m.unread_request_notification_count || 0) > 0) {
                var notices = data.unread_request_notifications || [];
                items.push({
                    label: '責任者返信',
                    value: m.unread_request_notification_count + '件',
                    desc: notices.length > 0 ? (notices[0].title || '申請に返信があります。') : '申請に返信があります。',
                    tab: 'shift-my',
                    warn: true
                });
            }
            if ((m.pending_correction_count || 0) > 0) {
                items.push({
                    label: '打刻修正',
                    value: m.pending_correction_count + '件',
                    desc: '責任者確認待ちの打刻修正申請があります。',
                    correction: true,
                    warn: false
                });
            }
            if (items.length === 0) {
                container.innerHTML = '<div class="staff-home-empty">未対応はありません。</div>';
                return;
            }

            var html = '';
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                html += '<div class="staff-home-todo' + (item.warn ? ' staff-home-todo--warn' : '') + '">' +
                    '<div class="staff-home-todo__label">' + esc(item.label) + '</div>' +
                    '<div class="staff-home-todo__value">' + esc(item.value) + '</div>' +
                    '<p class="staff-home-todo__desc">' + esc(item.desc) + '</p>' +
                    '<button type="button" class="btn btn-sm staff-home-todo__action ' +
                    (item.correction ? 'staff-home-open-correction' : 'staff-home-open-tab') + '" data-tab="' + esc(item.tab || '') + '">確認</button>' +
                    '</div>';
            }
            container.innerHTML = html;
            var tabBtns = container.querySelectorAll('.staff-home-open-tab');
            for (var t = 0; t < tabBtns.length; t++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        self._openStaffShiftTab(btn.getAttribute('data-tab') || 'shift-my');
                    });
                })(tabBtns[t]);
            }
            var correctionBtns = container.querySelectorAll('.staff-home-open-correction');
            for (var c = 0; c < correctionBtns.length; c++) {
                correctionBtns[c].addEventListener('click', function() {
                    var att = document.getElementById('staff-home-attendance-history-section');
                    if (att) att.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
        },

        _renderStaffHomeAttendance: function(container, data) {
            var self = this;
            var attendance = data.attendance || [];
            var corrections = data.attendance_corrections || [];
            var html = '<div class="staff-home-attendance__actions">' +
                '<button type="button" class="btn btn-primary" id="staff-home-correction-request">打刻修正を申請</button>' +
                '<button type="button" class="btn btn-sm staff-home-open-myshift">マイシフトを開く</button>' +
                '</div>';

            html += '<div class="staff-home-attendance__list">';
            if (attendance.length === 0) {
                html += '<div class="staff-home-empty">今日の打刻履歴はありません。</div>';
            } else {
                for (var i = 0; i < Math.min(attendance.length, 3); i++) {
                    var a = attendance[i];
                    html += '<div class="staff-home-attendance__row">' +
                        '<div><div class="staff-home-attendance__time">' +
                        esc(self._shortDateTime(a.clock_in)) + ' - ' + esc(a.clock_out ? self._shortDateTime(a.clock_out) : '勤務中') +
                        '</div><div class="staff-home-attendance__sub">休憩 ' + esc(String(a.break_minutes || 0)) + '分 / ' + esc(self._attendanceStatusLabel(a.status)) + '</div></div>' +
                        '<button type="button" class="btn btn-sm staff-home-correction-from-log" data-id="' + esc(a.id) + '">この打刻を修正</button>' +
                        '</div>';
                }
            }
            html += '</div>';

            if (corrections.length > 0) {
                html += '<h4>打刻修正申請</h4><table class="shift-table"><thead><tr><th>日付</th><th>状態</th><th>申請内容</th></tr></thead><tbody>';
                for (var r = 0; r < Math.min(corrections.length, 5); r++) {
                    var cr = corrections[r];
                    html += '<tr><td>' + esc(cr.target_date || '') + '</td>' +
                        '<td>' + esc(self._correctionStatusLabel(cr.status)) + '</td>' +
                        '<td>' + esc(self._correctionSummary(cr)) + '</td></tr>';
                }
                html += '</tbody></table>';
            }

            container.innerHTML = html;
            var reqBtn = document.getElementById('staff-home-correction-request');
            if (reqBtn) {
                reqBtn.addEventListener('click', function() { self._showAttendanceCorrectionRequestDialog(null); });
            }
            var logBtns = container.querySelectorAll('.staff-home-correction-from-log');
            for (var b = 0; b < logBtns.length; b++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        var rec = null;
                        for (var x = 0; x < attendance.length; x++) {
                            if (attendance[x].id === btn.getAttribute('data-id')) {
                                rec = attendance[x];
                                break;
                            }
                        }
                        self._showAttendanceCorrectionRequestDialog(rec);
                    });
                })(logBtns[b]);
            }
            var openBtns = container.querySelectorAll('.staff-home-open-myshift');
            for (var o = 0; o < openBtns.length; o++) {
                openBtns[o].addEventListener('click', function() { self._openStaffShiftTab('shift-my'); });
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
                            '<td>' + esc(self._roleLabel(t.role_hint)) + '</td>' +
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
                dayHtml = '<div class="shift-tpl-dow"><span class="shift-tpl-dow__title">曜日</span>' +
                    ' <a href="#" id="tpl-dow-all" class="shift-tpl-dow__toggle">全選択</a>' +
                    ' <a href="#" id="tpl-dow-none" class="shift-tpl-dow__toggle">全解除</a></div>' +
                    '<div id="tpl-dow-checks" class="shift-tpl-dow__checks">';
                for (var d = 0; d < 7; d++) {
                    dayHtml += '<label class="shift-tpl-dow__label">' +
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
                '<label>持ち場<select id="tpl-role">' + self._roleOptionsHtml(existing ? existing.role_hint : null) + '</select></label>' +
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
            var endStr = self._formatDate(endDt);
            var todayStr = self._formatDate(new Date());

            // 既存データをマップ化
            var existMap = {};
            for (var e = 0; e < existing.length; e++) {
                existMap[existing[e].target_date] = existing[e];
            }

            // Batch-C3: 週ヘッダー (前週ナビ + タイトル + 範囲 + 補助説明)
            var html = '<header class="avail-head">' +
                       '<button class="btn btn-sm avail-head__nav" id="avail-prev">◀ 前の週</button>' +
                       '<div class="avail-head__title-group">' +
                       '<h3 class="avail-head__title">シフト希望</h3>' +
                       '<p class="avail-head__range">' + startStr + ' 〜 ' + endStr + '</p>' +
                       '<p class="avail-head__desc">この週に働ける日と時間帯を教えてください。あとから同じ画面で変更・再提出できます。</p>' +
                       '</div>' +
                       '<button class="btn btn-sm avail-head__nav" id="avail-next">次の週 ▶</button>' +
                       '</header>';

            html += '<div class="avail-form">';
            html += '<div class="avail-day-list">';
            var dt = new Date(startDt);
            for (var d = 0; d < 7; d++) {
                var dateStr = self._formatDate(dt);
                var ex = existMap[dateStr] || {};
                var avail = ex.availability || 'available';
                var pStart = (ex.preferred_start || '').substring(0, 5);
                var pEnd = (ex.preferred_end || '').substring(0, 5);

                // Batch-C3: 文脈 modifier (今日 / 週末 / 過去) と 状態 modifier
                var dayCls = 'avail-day avail-day--state-' + avail;
                if (dateStr === todayStr) dayCls += ' avail-day--today';
                else if (dateStr < todayStr) dayCls += ' avail-day--past';
                var dow = dt.getDay();
                if (dow === 0 || dow === 6) dayCls += ' avail-day--weekend';

                html += '<div class="' + dayCls + '" data-date="' + dateStr + '">' +
                        '<div class="avail-day__head">' +
                        '<div class="avail-day__date">' + (dt.getMonth() + 1) + '/' + dt.getDate() + '</div>' +
                        '<div class="avail-day__dow">' + DAY_NAMES[dow] + '</div>' +
                        '</div>' +
                        '<div class="avail-btns" role="radiogroup" aria-label="' + dateStr + ' の出勤可否">' +
                        '<button type="button" class="avail-btn' + (avail === 'available' ? ' active' : '') + '" data-val="available" aria-pressed="' + (avail === 'available') + '">' +
                        '<span class="avail-btn__icon">○</span><span class="avail-btn__label">出勤可</span>' +
                        '</button>' +
                        '<button type="button" class="avail-btn' + (avail === 'preferred' ? ' active' : '') + '" data-val="preferred" aria-pressed="' + (avail === 'preferred') + '">' +
                        '<span class="avail-btn__icon">◎</span><span class="avail-btn__label">希望</span>' +
                        '</button>' +
                        '<button type="button" class="avail-btn' + (avail === 'unavailable' ? ' active' : '') + '" data-val="unavailable" aria-pressed="' + (avail === 'unavailable') + '">' +
                        '<span class="avail-btn__icon">×</span><span class="avail-btn__label">不可</span>' +
                        '</button>' +
                        '</div>' +
                        '<div class="avail-times"' + (avail === 'unavailable' ? ' style="display:none"' : '') + '>' +
                        '<input type="time" class="avail-start" value="' + (pStart || '09:00') + '" aria-label="開始時刻">' +
                        '<span class="avail-times__sep">〜</span>' +
                        '<input type="time" class="avail-end" value="' + (pEnd || '18:00') + '" aria-label="終了時刻">' +
                        '</div>' +
                        '</div>';
                dt.setDate(dt.getDate() + 1);
            }
            html += '</div>'; // .avail-day-list

            html += '<div class="avail-note-wrap">' +
                    '<label class="avail-note-label" for="avail-note">店長宛てのメモ<span class="avail-note-opt">(任意)</span></label>' +
                    '<textarea id="avail-note" class="avail-note" rows="2" placeholder="例: 試験期間中 / 午前のみ / 通院で中抜けあり"></textarea>' +
                    '</div>';
            html += '<div class="avail-submit-row">' +
                    '<button type="button" class="btn btn-primary avail-submit" id="avail-submit">この週の希望を提出する</button>' +
                    '</div>';
            html += '</div>'; // .avail-form

            container.innerHTML = html;

            // 可/希望/不可ボタン切替 (Batch-C3: day card 側の state modifier も同期)
            var availBtns = container.querySelectorAll('.avail-btn');
            for (var b = 0; b < availBtns.length; b++) {
                availBtns[b].addEventListener('click', function() {
                    var parent = this.parentElement;
                    var btns = parent.querySelectorAll('.avail-btn');
                    for (var x = 0; x < btns.length; x++) {
                        btns[x].classList.remove('active');
                        btns[x].setAttribute('aria-pressed', 'false');
                    }
                    this.classList.add('active');
                    this.setAttribute('aria-pressed', 'true');
                    var val = this.getAttribute('data-val');
                    var times = this.parentElement.parentElement.querySelector('.avail-times');
                    if (times) {
                        times.style.display = val === 'unavailable' ? 'none' : '';
                    }
                    // Batch-C3: day card 側の状態 class を更新
                    var dayCard = this.parentElement.parentElement;
                    if (dayCard) {
                        dayCard.classList.remove('avail-day--state-available', 'avail-day--state-preferred', 'avail-day--state-unavailable');
                        dayCard.classList.add('avail-day--state-' + val);
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
                apiGet('swap-requests.php', { store_id: self.storeId }, function(reqData) {
                    apiGet('field-ops.php', { store_id: self.storeId, action: 'open-shifts' }, function(openData) {
                        apiGet('field-ops.php', { store_id: self.storeId, action: 'my-tasks' }, function(taskData) {
                            self._renderMyShift(
                                container,
                                data.assignments,
                                (reqData && reqData.requests) ? reqData.requests : [],
                                start,
                                end,
                                (openData && openData.open_shifts) ? openData.open_shifts : [],
                                (taskData && taskData.tasks) ? taskData.tasks : []
                            );
                        });
                    });
                });
            });
        },

        _renderMyShift: function(container, assignments, requests, start, end, openShifts, myTasks) {
            var self = this;
            var pendingByAssignment = {};
            for (var pr = 0; pr < requests.length; pr++) {
                if (requests[pr].status === 'pending') {
                    pendingByAssignment[requests[pr].shift_assignment_id] = requests[pr];
                }
            }
            var html = '<div class="shift-section-header">' +
                       '<button class="btn btn-sm" id="myshift-prev">◀ 前の週</button>' +
                       '<h3>マイシフト ' + start + ' 〜 ' + end + '</h3>' +
                       '<button class="btn btn-sm" id="myshift-next">次の週 ▶</button>' +
                       '</div>';

            if (assignments.length === 0) {
                html += '<p class="empty-msg">この週のシフトはまだありません。</p>';
            } else {
                html += '<table class="shift-table"><thead><tr>' +
                        '<th>日付</th><th>開始</th><th>終了</th><th>休憩</th><th>持ち場</th><th>ステータス</th><th>操作</th>' +
                        '</tr></thead><tbody>';
                var todayStr = self._formatDate(new Date());
                for (var i = 0; i < assignments.length; i++) {
                    var a = assignments[i];
                    var statusLabel = { draft: '下書き', published: '確定', confirmed: '確認済' };
                    var statusClass = { draft: 'status-draft', published: 'status-published', confirmed: 'status-confirmed' };
                    if (a.confirmation_required) {
                        statusLabel.published = '変更あり';
                        statusClass.published = 'shift-status-changed';
                    }
                    var actionHtml = '';
                    if (a.status === 'published') {
                        actionHtml += '<button class="btn btn-sm myshift-confirm" data-id="' + esc(a.id) + '">確認</button> ';
                    }
                    if (a.shift_date >= todayStr && a.status !== 'draft') {
                        if (pendingByAssignment[a.id]) {
                            actionHtml += '<span class="shift-request-pending">申請中</span>';
                        } else {
                            actionHtml += '<button class="btn btn-sm myshift-swap" data-id="' + esc(a.id) + '">交代依頼</button> ' +
                                '<button class="btn btn-sm myshift-absence" data-id="' + esc(a.id) + '">欠勤連絡</button>';
                        }
                    }
                    html += '<tr>' +
                            '<td>' + esc(a.shift_date) + '</td>' +
                            '<td>' + esc(a.start_time).substring(0, 5) + '</td>' +
                            '<td>' + esc(a.end_time).substring(0, 5) + '</td>' +
                            '<td>' + a.break_minutes + '分</td>' +
                            '<td>' + esc(self._roleLabel(a.role_type)) + '</td>' +
                            '<td><span class="' + (statusClass[a.status] || '') + '">' + (statusLabel[a.status] || esc(a.status)) + '</span></td>' +
                            '<td>' + (actionHtml || '-') + '</td>' +
                            '</tr>';
                    if (a.confirmation_required) {
                        html += '<tr class="help-note-row"><td colspan="7">変更内容: ' + self._renderChangeDetail(a) + '<div class="shift-change-detail__help">内容を確認してから確認を押してください。</div></td></tr>';
                    }
                }
                html += '</tbody></table>';
            }

            html += self._renderMyOpenShifts(openShifts || []);
            html += self._renderMyTasks(myTasks || []);

            if (requests.length > 0) {
                html += '<h4>交代・欠勤申請</h4><table class="shift-table"><thead><tr>' +
                    '<th>日付</th><th>種別</th><th>状態</th><th>候補/交代</th><th>メモ</th><th>操作</th>' +
                    '</tr></thead><tbody>';
                for (var r = 0; r < Math.min(requests.length, 10); r++) {
                    var req = requests[r];
                    var typeLabel = req.my_relation === 'candidate' ? '交代候補' : (req.request_type === 'absence' ? '欠勤' : '交代');
                    var stLabel = { pending: '未対応', approved: '承認済', rejected: '却下', cancelled: 'キャンセル' }[req.status] || req.status;
                    if (req.my_relation === 'candidate' && req.status === 'pending') {
                        stLabel = self._candidateStatusLabel(req.candidate_acceptance_status);
                    }
                    var handledBy = req.responded_by_name || req.responded_by_username || '';
                    var stHtml = esc(stLabel);
                    if (req.status !== 'pending' && handledBy) {
                        stHtml += '<br><small>対応: ' + esc(handledBy) + '</small>';
                    }
                    var person = req.replacement_name || req.candidate_name || '-';
                    var memo = req.latest_message ?
                        '最新: ' + (req.latest_message_sender || '') + ' / ' + req.latest_message :
                        (req.reason || req.response_note || req.candidate_response_note || '');
                    var unread = parseInt(req.unread_count || 0, 10);
                    var actions = '<button class="btn btn-sm myshift-request-thread" data-id="' + esc(req.id) + '">やり取り' + (unread > 0 ? ' (' + unread + ')' : '') + '</button>';
                    if (req.my_relation === 'candidate' && req.status === 'pending' && req.candidate_acceptance_status === 'pending') {
                        actions += ' <button class="btn btn-sm btn-primary myshift-candidate-response" data-id="' + esc(req.id) + '" data-action="accept-candidate">引き受ける</button>' +
                            ' <button class="btn btn-sm myshift-candidate-response" data-id="' + esc(req.id) + '" data-action="decline-candidate">辞退</button>';
                    }
                    html += '<tr><td>' + esc(req.shift_date || '') + ' ' + esc((req.start_time || '').substring(0, 5)) + '-' + esc((req.end_time || '').substring(0, 5)) + '</td>' +
                        '<td>' + typeLabel + '</td><td>' + stHtml + '</td><td>' + esc(person) + '</td><td>' + esc(memo) + '</td><td>' + actions + '</td></tr>';
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

            var confirmBtns = container.querySelectorAll('.myshift-confirm');
            for (var cb = 0; cb < confirmBtns.length; cb++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        apiPost('assignments.php?action=confirm&store_id=' + encodeURIComponent(self.storeId), {
                            id: btn.getAttribute('data-id')
                        }, function(data, err) {
                            if (err) { alert('確認に失敗しました: ' + (err.message || '')); return; }
                            self.loadMyShift();
                        });
                    });
                })(confirmBtns[cb]);
            }

            var swapBtns = container.querySelectorAll('.myshift-swap');
            for (var sb = 0; sb < swapBtns.length; sb++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        self._showSwapRequestDialog(btn.getAttribute('data-id'));
                    });
                })(swapBtns[sb]);
            }

            var absenceBtns = container.querySelectorAll('.myshift-absence');
            for (var ab = 0; ab < absenceBtns.length; ab++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        var reason = prompt('欠勤理由・責任者へのメモを入力してください（任意）', '');
                        if (reason === null) return;
                        apiPost('swap-requests.php?store_id=' + encodeURIComponent(self.storeId), {
                            shift_assignment_id: btn.getAttribute('data-id'),
                            request_type: 'absence',
                            reason: reason
                        }, function(data, err) {
                            if (err) { alert('欠勤連絡に失敗しました: ' + (err.message || '')); return; }
                            alert('欠勤連絡を送信しました');
                            self.loadMyShift();
                        });
                    });
                })(absenceBtns[ab]);
            }

            var openBtns = container.querySelectorAll('.myshift-open-apply');
            for (var os = 0; os < openBtns.length; os++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        var note = prompt('責任者へのメモ（任意）', '');
                        if (note === null) return;
                        apiPost('field-ops.php?action=apply-open-shift&store_id=' + encodeURIComponent(self.storeId), {
                            open_shift_id: btn.getAttribute('data-id'),
                            note: note
                        }, function(data, err) {
                            if (err) { notify('応募に失敗しました: ' + (err.message || ''), 'error'); return; }
                            notify('募集シフトに応募しました', 'success');
                            self.loadMyShift();
                        });
                    });
                })(openBtns[os]);
            }

            var taskBtns = container.querySelectorAll('.myshift-task-status');
            for (var tb = 0; tb < taskBtns.length; tb++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        apiPatch('field-ops.php?action=task-status&store_id=' + encodeURIComponent(self.storeId), {
                            task_id: btn.getAttribute('data-id'),
                            status: btn.getAttribute('data-status')
                        }, function(data, err) {
                            if (err) { notify('作業更新に失敗しました: ' + (err.message || ''), 'error'); return; }
                            self.loadMyShift();
                        });
                    });
                })(taskBtns[tb]);
            }

            var threadBtns = container.querySelectorAll('.myshift-request-thread');
            for (var rb = 0; rb < threadBtns.length; rb++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        self._showShiftRequestThread(btn.getAttribute('data-id'));
                    });
                })(threadBtns[rb]);
            }

            var candidateBtns = container.querySelectorAll('.myshift-candidate-response');
            for (var crb = 0; crb < candidateBtns.length; crb++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        self._sendCandidateResponse(btn.getAttribute('data-id'), btn.getAttribute('data-action'));
                    });
                })(candidateBtns[crb]);
            }
        },

        _renderMyOpenShifts: function(openShifts) {
            var self = this;
            var html = '<h4>募集シフト</h4>';
            if (!openShifts || openShifts.length === 0) {
                return html + '<p class="empty-msg">応募できる募集シフトはありません。</p>';
            }
            html += '<table class="shift-table"><thead><tr><th>日付</th><th>時間</th><th>持ち場</th><th>条件</th><th>操作</th></tr></thead><tbody>';
            for (var i = 0; i < Math.min(openShifts.length, 12); i++) {
                var os = openShifts[i];
                if (os.status !== 'open') continue;
                var appStatus = os.my_application_status || '';
                var action = appStatus === 'applied' ? '<span class="shift-request-pending">応募済</span>' :
                    '<button class="btn btn-sm myshift-open-apply" data-id="' + esc(os.id) + '">応募</button>';
                html += '<tr><td>' + esc(os.shift_date || '') + '</td>' +
                    '<td>' + esc((os.start_time || '').substring(0, 5)) + '-' + esc((os.end_time || '').substring(0, 5)) + '</td>' +
                    '<td>' + esc(self._roleLabel(os.role_type)) + '</td>' +
                    '<td>' + esc(os.required_skill_code ? os.required_skill_code : '指定なし') + '</td>' +
                    '<td>' + action + '</td></tr>';
            }
            html += '</tbody></table>';
            return html;
        },

        _renderMyTasks: function(myTasks) {
            var html = '<h4>担当作業</h4>';
            if (!myTasks || myTasks.length === 0) {
                return html + '<p class="empty-msg">担当作業はありません。</p>';
            }
            html += '<table class="shift-table"><thead><tr><th>日付</th><th>作業</th><th>状態</th><th>操作</th></tr></thead><tbody>';
            for (var i = 0; i < Math.min(myTasks.length, 20); i++) {
                var t = myTasks[i];
                var done = t.status === 'done';
                html += '<tr><td>' + esc(t.task_date || '') + '</td>' +
                    '<td>' + esc(t.task_label || '') + (t.note ? '<br><small>' + esc(t.note) + '</small>' : '') + '</td>' +
                    '<td>' + (done ? '完了' : '未完了') + '</td>' +
                    '<td><button class="btn btn-sm myshift-task-status" data-id="' + esc(t.id) + '" data-status="' + (done ? 'pending' : 'done') + '">' + (done ? '戻す' : '完了') + '</button></td></tr>';
            }
            html += '</tbody></table>';
            return html;
        },

        _showSwapRequestDialog: function(assignmentId) {
            var self = this;
            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';
            overlay.innerHTML = '<div class="shift-dialog">' +
                '<h3>交代依頼</h3>' +
                '<div id="swap-candidates">候補を確認中...</div>' +
                '<label>責任者へのメモ<input type="text" id="swap-reason" class="form-input" placeholder="理由や補足（任意）"></label>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="swap-send">送信</button>' +
                '<button class="btn" id="swap-cancel">キャンセル</button>' +
                '</div></div>';
            document.body.appendChild(overlay);

            document.getElementById('swap-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });

            apiGet('swap-requests.php', {
                store_id: self.storeId,
                action: 'candidates',
                assignment_id: assignmentId
            }, function(data, err) {
                var box = document.getElementById('swap-candidates');
                if (!box) return;
                if (err) {
                    box.innerHTML = '<p class="error">候補を取得できませんでした</p>';
                    return;
                }
                var html = '<p>交代できそうなスタッフを選ぶと、責任者が承認しやすくなります。</p>' +
                    '<label class="shift-swap-candidate"><input type="radio" name="swap-candidate" value="" checked> 候補を指定しない</label>';
                var candidates = data.candidates || [];
                for (var i = 0; i < Math.min(candidates.length, 8); i++) {
                    var c = candidates[i];
                    var sub = c.availability === 'preferred' ? '希望' : (c.availability === 'available' ? '出勤可' : '未提出');
                    html += '<label class="shift-swap-candidate"><input type="radio" name="swap-candidate" value="' + esc(c.user_id) + '"> ' +
                        esc(c.display_name) + ' <small>' + esc(sub + (c.role_match ? '' : ' / 持ち場要確認')) + '</small></label>';
                }
                box.innerHTML = html;
            });

            document.getElementById('swap-send').addEventListener('click', function() {
                var checked = overlay.querySelector('input[name="swap-candidate"]:checked');
                apiPost('swap-requests.php?store_id=' + encodeURIComponent(self.storeId), {
                    shift_assignment_id: assignmentId,
                    request_type: 'swap',
                    candidate_user_id: checked ? checked.value : '',
                    reason: document.getElementById('swap-reason').value
                }, function(data, err) {
                    if (err) { alert('交代依頼に失敗しました: ' + (err.message || '')); return; }
                    document.body.removeChild(overlay);
                    alert('交代依頼を送信しました');
                    self.loadMyShift();
                });
            });
        },

        _sendCandidateResponse: function(requestId, action) {
            var self = this;
            var isAccept = action === 'accept-candidate';
            var note = prompt(isAccept ? '責任者・申請者へのメモ（任意）' : '辞退理由・補足（任意）', '');
            if (note === null) return;
            apiPatch('swap-requests.php?id=' + encodeURIComponent(requestId) + '&store_id=' + encodeURIComponent(self.storeId), {
                action: action,
                response_note: note
            }, function(data, err) {
                if (err) {
                    alert('回答に失敗しました: ' + (err.message || ''));
                    return;
                }
                notify(isAccept ? '交代候補を引き受けました' : '交代候補を辞退しました', 'success');
                self.loadMyShift();
                self.loadStaffHome();
            });
        },

        _showShiftRequestThread: function(requestId) {
            var self = this;
            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';
            overlay.innerHTML = '<div class="shift-dialog shift-dialog--wide">' +
                '<h3>申請メッセージ</h3>' +
                '<div id="shift-request-thread-body" class="shift-request-thread">読み込み中...</div>' +
                '<label>メッセージ<textarea id="shift-request-thread-message" rows="3" placeholder="責任者・スタッフへの確認事項を入力"></textarea></label>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="shift-request-thread-send">送信</button>' +
                '<button class="btn" id="shift-request-thread-close">閉じる</button>' +
                '</div></div>';
            document.body.appendChild(overlay);

            function renderMessages() {
                apiGet('request-messages.php', {
                    store_id: self.storeId,
                    request_id: requestId
                }, function(data, err) {
                    var body = document.getElementById('shift-request-thread-body');
                    if (!body) return;
                    if (err) {
                        body.innerHTML = '<p class="error">メッセージを取得できませんでした: ' + esc(err.message || '') + '</p>';
                        return;
                    }
                    var messages = data.messages || [];
                    if (messages.length === 0) {
                        body.innerHTML = '<p class="empty-msg">まだメッセージはありません。</p>';
                        return;
                    }
                    var html = '';
                    for (var i = 0; i < messages.length; i++) {
                        var m = messages[i];
                        var system = m.message_type === 'system';
                        html += '<div class="shift-request-message' + (system ? ' shift-request-message--system' : '') + '">' +
                            '<div class="shift-request-message__meta">' +
                            esc(system ? 'システム' : ((m.display_name || m.username || '') + ' / ' + (m.created_at || ''))) +
                            '</div><div class="shift-request-message__body">' + esc(m.message_body || '') + '</div></div>';
                    }
                    body.innerHTML = html;
                    body.scrollTop = body.scrollHeight;
                });
            }

            document.getElementById('shift-request-thread-close').addEventListener('click', function() {
                document.body.removeChild(overlay);
                self.loadMyShift();
                self.loadStaffHome();
            });
            document.getElementById('shift-request-thread-send').addEventListener('click', function() {
                var input = document.getElementById('shift-request-thread-message');
                var message = input.value;
                if (!message.trim()) {
                    alert('メッセージを入力してください');
                    return;
                }
                apiPost('request-messages.php?store_id=' + encodeURIComponent(self.storeId), {
                    request_id: requestId,
                    message: message
                }, function(data, err) {
                    if (err) {
                        alert('送信に失敗しました: ' + (err.message || ''));
                        return;
                    }
                    input.value = '';
                    renderMessages();
                });
            });
            renderMessages();
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
                apiGet('attendance-corrections.php', { store_id: self.storeId, status: 'pending' }, function(reqData) {
                    self._renderAttendance(
                        container,
                        data.attendance,
                        start,
                        end,
                        (reqData && reqData.requests) ? reqData.requests : []
                    );
                });
            });
        },

        _renderAttendance: function(container, records, start, end, correctionRequests) {
            var self = this;
            var html = '<div class="shift-section-header">' +
                       '<h3>勤怠一覧 ' + start + ' 〜 ' + end + '</h3>' +
                       '</div>';

            html += self._renderAttendanceCorrectionRequests(correctionRequests || []);

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

            var reviewBtns = container.querySelectorAll('.btn-review-attendance-correction');
            for (var rb = 0; rb < reviewBtns.length; rb++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        var req = null;
                        for (var q = 0; q < correctionRequests.length; q++) {
                            if (correctionRequests[q].id === btn.getAttribute('data-id')) {
                                req = correctionRequests[q];
                                break;
                            }
                        }
                        if (req) self._showAttendanceCorrectionReviewDialog(req);
                    });
                })(reviewBtns[rb]);
            }
        },

        _renderAttendanceCorrectionRequests: function(requests) {
            var self = this;
            if (!requests || requests.length === 0) {
                return '';
            }
            var html = '<section class="attendance-correction-list">' +
                '<h4 class="attendance-correction-list__title">打刻修正申請</h4>' +
                '<table class="shift-table"><thead><tr>' +
                '<th>スタッフ</th><th>日付</th><th>申請内容</th><th>理由</th><th>操作</th>' +
                '</tr></thead><tbody>';
            for (var i = 0; i < requests.length; i++) {
                var r = requests[i];
                html += '<tr>' +
                    '<td>' + esc(r.display_name || r.username || '') + '</td>' +
                    '<td>' + esc(r.target_date || '') + '</td>' +
                    '<td>' + esc(self._correctionSummary(r)) + '</td>' +
                    '<td>' + esc(r.reason || '') + '</td>' +
                    '<td><button class="btn btn-sm btn-review-attendance-correction" data-id="' + esc(r.id) + '">確認</button></td>' +
                    '</tr>';
            }
            html += '</tbody></table></section>';
            return html;
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

        _showAttendanceCorrectionRequestDialog: function(record) {
            var self = this;
            var today = self._formatDate(new Date());
            var targetDate = record && record.clock_in ? record.clock_in.substring(0, 10) : today;
            var clockIn = record && record.clock_in ? self._datetimeLocalValue(record.clock_in) : (targetDate + 'T09:00');
            var clockOut = record && record.clock_out ? self._datetimeLocalValue(record.clock_out) : '';
            var breakMinutes = record && record.break_minutes !== null && record.break_minutes !== undefined ? record.break_minutes : '';

            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';
            overlay.innerHTML = '<div class="shift-dialog">' +
                '<h3>打刻修正申請</h3>' +
                '<label>対象日<input type="date" id="acr-target-date" value="' + esc(targetDate) + '"></label>' +
                '<label>出勤時刻<input type="datetime-local" id="acr-clock-in" value="' + esc(clockIn) + '"></label>' +
                '<label>退勤時刻<input type="datetime-local" id="acr-clock-out" value="' + esc(clockOut) + '"></label>' +
                '<label>休憩(分)<input type="number" id="acr-break" min="0" max="480" value="' + esc(String(breakMinutes)) + '"></label>' +
                '<label>理由<textarea id="acr-reason" rows="3" placeholder="例: 出勤打刻を忘れた / 退勤時刻を押し間違えた"></textarea></label>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="acr-send">申請する</button>' +
                '<button class="btn" id="acr-cancel">キャンセル</button>' +
                '</div></div>';
            document.body.appendChild(overlay);

            document.getElementById('acr-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });
            document.getElementById('acr-send').addEventListener('click', function() {
                var reason = document.getElementById('acr-reason').value;
                if (!reason.trim()) {
                    alert('申請理由を入力してください');
                    return;
                }
                var outValue = document.getElementById('acr-clock-out').value;
                var breakValue = document.getElementById('acr-break').value;
                var body = {
                    attendance_log_id: record ? record.id : '',
                    target_date: document.getElementById('acr-target-date').value,
                    request_type: record ? 'other' : 'clock_in',
                    requested_clock_in: document.getElementById('acr-clock-in').value.replace('T', ' '),
                    requested_clock_out: outValue ? outValue.replace('T', ' ') : null,
                    requested_break_minutes: breakValue !== '' ? parseInt(breakValue, 10) : null,
                    reason: reason
                };
                apiPost('attendance-corrections.php?store_id=' + encodeURIComponent(self.storeId), body, function(data, err) {
                    if (err) {
                        alert('申請に失敗しました: ' + (err.message || ''));
                        return;
                    }
                    document.body.removeChild(overlay);
                    notify('打刻修正申請を送信しました', 'success');
                    self.loadStaffHome();
                });
            });
        },

        _showAttendanceCorrectionReviewDialog: function(request) {
            var self = this;
            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';
            overlay.innerHTML = '<div class="shift-dialog">' +
                '<h3>打刻修正申請: ' + esc(request.display_name || request.username || '') + '</h3>' +
                '<p class="staff-home-status__note">' + esc(self._correctionSummary(request)) + '</p>' +
                '<p class="staff-home-status__note">理由: ' + esc(request.reason || '') + '</p>' +
                '<label>店長メモ<input type="text" id="acr-response-note" placeholder="承認/却下理由（任意）"></label>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="acr-approve">承認して勤怠へ反映</button>' +
                '<button class="btn btn-danger" id="acr-reject">却下</button>' +
                '<button class="btn" id="acr-review-cancel">閉じる</button>' +
                '</div></div>';
            document.body.appendChild(overlay);

            function send(action) {
                apiPatch('attendance-corrections.php?id=' + encodeURIComponent(request.id) + '&store_id=' + encodeURIComponent(self.storeId), {
                    action: action,
                    response_note: document.getElementById('acr-response-note').value
                }, function(data, err) {
                    if (err) {
                        alert('処理に失敗しました: ' + (err.message || ''));
                        return;
                    }
                    document.body.removeChild(overlay);
                    self.loadAttendance();
                });
            }

            document.getElementById('acr-review-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });
            document.getElementById('acr-approve').addEventListener('click', function() {
                if (!confirm('この申請を承認し、勤怠へ反映しますか？')) return;
                send('approve');
            });
            document.getElementById('acr-reject').addEventListener('click', function() {
                send('reject');
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
                self._loadPositions(function() {
                    data.positions = self.positions;
                    self._renderSettings(container, data);
                });
            });
        },

        _renderSettings: function(container, settings) {
            var self = this;

            // UI-P1-1 (Batch-S1): スタッフ表示ツール設定 UI は削除。staff ロールは
            // dashboard.html の data-role-max="manager" で handy/KDS/POSレジを
            // 無条件非表示にしており、この設定は人のスタッフ UI に効かない。
            // device ロール redirect 用の DB 値は保持するため、ここでは UI 描画
            // と payload 送信を止めるだけ (settings.php PATCH は array_key_exists
            // ベースなので、送らなければ DB 値は変わらない)。

            var parts = [];
            parts.push(
                '<div class="shift-settings-header">' +
                  '<h3 class="shift-settings-header__title">シフト設定</h3>' +
                  '<span class="shift-settings-header__badge">店舗ごと</span>' +
                '</div>' +
                '<div class="shift-settings-cards">'
            );
            parts.push(self._renderSettingsCardBasic(settings));
            parts.push(self._renderSettingsCardGps(settings));
            // Tools カードは意図的に非表示
            parts.push(self._renderSettingsCardWage(settings));
            parts.push(self._renderSettingsCardPositions(settings));
            parts.push('</div>');

            container.innerHTML = parts.join('');

            // GPS有効/無効の切替で詳細フィールド表示制御
            var gpsSelect = document.getElementById('set-gps-required');
            var gpsDetail = document.getElementById('gps-detail-fields');
            if (gpsSelect && gpsDetail) {
                gpsSelect.addEventListener('change', function() {
                    gpsDetail.style.display = this.value === '1' ? '' : 'none';
                });
            }

            // 現在地取得ボタン
            var detectBtn = document.getElementById('set-gps-detect');
            if (detectBtn) {
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
            }

            // 保存ハンドラ: 全カードの保存ボタン（#set-save-basic/gps/wage）+ 既存互換 #set-save
            // UI-P1-1 (Batch-S1): staff_visible_tools は UI 削除に伴い payload からも
            // 除外。settings.php PATCH は array_key_exists ベースで他フィールドだけ
            // UPDATE し、staff_visible_tools の DB 値は不変 (device redirect に影響なし)。
            function handleSave() {
                var latEl = document.getElementById('set-lat');
                var lngEl = document.getElementById('set-lng');
                var latVal = latEl ? latEl.value : '';
                var lngVal = lngEl ? lngEl.value : '';

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
                    default_hourly_rate: hourlyRate,
                    target_labor_cost_ratio: parseFloat(document.getElementById('set-labor-ratio').value || '30')
                };

                apiPatch('settings.php?store_id=' + self.storeId, body, function(data, err) {
                    if (err) { alert('保存に失敗しました: ' + (err.message || '')); return; }
                    alert('設定を保存しました');
                });
            }
            var saveIds = ['set-save', 'set-save-basic', 'set-save-gps', 'set-save-wage'];
            for (var si = 0; si < saveIds.length; si++) {
                var btn = document.getElementById(saveIds[si]);
                if (btn) btn.addEventListener('click', handleSave);
            }

            var addPositionBtn = document.getElementById('set-position-add');
            if (addPositionBtn) {
                addPositionBtn.addEventListener('click', function() {
                    var code = document.getElementById('set-position-code').value;
                    var label = document.getElementById('set-position-label').value;
                    if (!code || !label) { alert('コードと表示名を入力してください'); return; }
                    apiPost('positions.php?store_id=' + encodeURIComponent(self.storeId), {
                        store_id: self.storeId,
                        code: code,
                        label: label,
                        sort_order: 100
                    }, function(data, err) {
                        if (err) { alert('持ち場の保存に失敗しました: ' + (err.message || '')); return; }
                        self.loadSettings();
                    });
                });
            }

            var disableBtns = container.querySelectorAll('.set-position-disable');
            for (var pi = 0; pi < disableBtns.length; pi++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        if (!confirm('この持ち場を非表示にしますか？既存シフトの履歴は残ります。')) return;
                        apiPatch('positions.php?id=' + encodeURIComponent(btn.getAttribute('data-id')) + '&store_id=' + encodeURIComponent(self.storeId), {
                            is_active: 0
                        }, function(data, err) {
                            if (err) { alert('持ち場の更新に失敗しました'); return; }
                            self.loadSettings();
                        });
                    });
                })(disableBtns[pi]);
            }
        },

        // Batch-UI-1: 基本設定カード
        _renderSettingsCardBasic: function(s) {
            return '' +
                '<section class="shift-card">' +
                  '<div class="shift-card__head">' +
                    '<h3 class="shift-card__title"><span class="shift-card__title-icon">⚙</span>基本設定</h3>' +
                    '<p class="shift-card__desc">希望提出・休憩・残業・打刻まわりの運用ルール。間違えると給与計算に影響するため、変更は月初めが無難です。</p>' +
                  '</div>' +
                  '<div class="shift-card__body">' +
                    '<div class="form-field">' +
                      '<label class="form-field__label" for="set-deadline">希望提出締切日 <span class="form-field__label-sub">毎月N日</span></label>' +
                      '<input class="form-field__input" type="number" id="set-deadline" min="1" max="28" value="' + s.submission_deadline_day + '">' +
                      '<p class="form-field__hint">1〜28 の範囲。例: 15 → 毎月15日に翌月希望シフトを締切。</p>' +
                    '</div>' +
                    '<div class="form-field">' +
                      '<label class="form-field__label" for="set-break">デフォルト休憩時間 <span class="form-field__label-sub">分</span></label>' +
                      '<input class="form-field__input" type="number" id="set-break" min="0" max="120" value="' + s.default_break_minutes + '">' +
                      '<p class="form-field__hint">シフト作成時に自動適用される休憩の初期値。スタッフ個別に変更可。</p>' +
                    '</div>' +
                    '<div class="form-field">' +
                      '<label class="form-field__label" for="set-overtime">残業判定閾値 <span class="form-field__label-sub">分/日</span></label>' +
                      '<input class="form-field__input" type="number" id="set-overtime" min="60" max="720" value="' + s.overtime_threshold_minutes + '">' +
                      '<p class="form-field__hint">1 日の労働時間がこの分数を超えた分を残業として集計。法定は 480 分（8 時間）目安。</p>' +
                    '</div>' +
                    '<div class="form-field">' +
                      '<label class="form-field__label" for="set-early">早出打刻許容 <span class="form-field__label-sub">分</span></label>' +
                      '<input class="form-field__input" type="number" id="set-early" min="0" max="60" value="' + s.early_clock_in_minutes + '">' +
                      '<p class="form-field__hint">シフト開始より何分前まで出勤打刻を許可するか。0 = 定刻のみ。</p>' +
                    '</div>' +
                    '<div class="form-field">' +
                      '<label class="form-field__label" for="set-auto">自動退勤 <span class="form-field__label-sub">時間</span></label>' +
                      '<input class="form-field__input" type="number" id="set-auto" min="1" max="24" value="' + s.auto_clock_out_hours + '">' +
                      '<p class="form-field__hint">出勤から N 時間経過しても退勤打刻がない場合、自動退勤で締める。打刻忘れ対策。</p>' +
                    '</div>' +
                  '</div>' +
                  '<div class="shift-card__save">' +
                    '<span class="shift-card__save-hint">保存は全カード共通（ペイロード統合送信）</span>' +
                    '<button class="btn btn-primary" id="set-save-basic" type="button">保存</button>' +
                  '</div>' +
                '</section>';
        },

        // Batch-UI-1: GPS 出退勤カード
        _renderSettingsCardGps: function(s) {
            var gpsOn = (s.gps_required === 1);
            return '' +
                '<section class="shift-card">' +
                  '<div class="shift-card__head">' +
                    '<h3 class="shift-card__title"><span class="shift-card__title-icon">📍</span>GPS 出退勤</h3>' +
                    '<p class="shift-card__desc">店舗の位置から離れた場所での打刻を防ぐ機能。有効時、スタッフの端末で位置情報の許可が必要になります。</p>' +
                  '</div>' +
                  '<div class="shift-card__body">' +
                    '<div class="form-field">' +
                      '<label class="form-field__label" for="set-gps-required">GPS 出退勤</label>' +
                      '<select class="form-field__select" id="set-gps-required">' +
                        '<option value="0"' + (gpsOn ? '' : ' selected') + '>無効</option>' +
                        '<option value="1"' + (gpsOn ? ' selected' : '') + '>有効（必須）</option>' +
                      '</select>' +
                      '<p class="form-field__hint">有効にすると、以下の「店舗緯度・経度・許容半径」の範囲外からは打刻できなくなります。</p>' +
                    '</div>' +
                    '<div id="gps-detail-fields"' + (gpsOn ? '' : ' style="display:none;"') + '>' +
                      '<button class="form-action-button" id="set-gps-detect" type="button">📍 現在地から店舗位置を設定</button>' +
                      '<div class="form-row" style="margin-top:12px;">' +
                        '<div class="form-field">' +
                          '<label class="form-field__label" for="set-lat">店舗緯度</label>' +
                          '<input class="form-field__input" type="number" id="set-lat" step="0.0000001" min="-90" max="90" value="' + (s.store_lat !== null ? s.store_lat : '') + '" placeholder="35.6812362">' +
                        '</div>' +
                        '<div class="form-field">' +
                          '<label class="form-field__label" for="set-lng">店舗経度</label>' +
                          '<input class="form-field__input" type="number" id="set-lng" step="0.0000001" min="-180" max="180" value="' + (s.store_lng !== null ? s.store_lng : '') + '" placeholder="139.7671248">' +
                        '</div>' +
                      '</div>' +
                      '<div class="form-field" style="margin-top:12px;">' +
                        '<label class="form-field__label" for="set-radius">許容半径 <span class="form-field__label-sub">m</span></label>' +
                        '<input class="form-field__input" type="number" id="set-radius" min="50" max="1000" value="' + s.gps_radius_meters + '">' +
                        '<p class="form-field__hint">店舗を中心とした円の半径。街中店舗は 100〜200m、駐車場が広い郊外店舗は 300〜500m が目安。</p>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="shift-card__save">' +
                    '<span class="shift-card__save-hint">現在地取得はブラウザで位置情報許可が必要です</span>' +
                    '<button class="btn btn-primary" id="set-save-gps" type="button">保存</button>' +
                  '</div>' +
                '</section>';
        },

        // Batch-UI-1: スタッフ表示ツールカード
        _renderSettingsCardTools: function(s, isToolChecked) {
            return '' +
                '<section class="shift-card">' +
                  '<div class="shift-card__head">' +
                    '<h3 class="shift-card__title"><span class="shift-card__title-icon">🧰</span>スタッフ表示ツール</h3>' +
                    '<p class="shift-card__desc">スタッフが業務中に開ける端末の種類を絞り込みます。チェックなし ＝ 全ツール表示。チェックしたツールのみスタッフ側に出現します。</p>' +
                  '</div>' +
                  '<div class="shift-card__body">' +
                    '<div class="shift-tool-checks">' +
                      '<label class="shift-tool-check"><input type="checkbox" id="set-tool-handy"' + (isToolChecked('handy') ? ' checked' : '') + '> ハンディPOS</label>' +
                      '<label class="shift-tool-check"><input type="checkbox" id="set-tool-kds"' + (isToolChecked('kds') ? ' checked' : '') + '> KDS</label>' +
                      '<label class="shift-tool-check"><input type="checkbox" id="set-tool-register"' + (isToolChecked('register') ? ' checked' : '') + '> POSレジ</label>' +
                    '</div>' +
                    '<p class="form-field__hint">device ロール（端末アカウント）はこの設定の対象外です（KDS/レジ専用）。人のスタッフだけに適用されます。</p>' +
                  '</div>' +
                  '<div class="shift-card__save">' +
                    '<span class="shift-card__save-hint">全チェックまたは全未チェック ＝ 全ツール表示</span>' +
                    '<button class="btn btn-primary" id="set-save-tools" type="button">保存</button>' +
                  '</div>' +
                '</section>';
        },

        // Batch-UI-1: 人件費設定カード
        _renderSettingsCardWage: function(s) {
            return '' +
                '<section class="shift-card">' +
                  '<div class="shift-card__head">' +
                    '<h3 class="shift-card__title"><span class="shift-card__title-icon">💴</span>人件費設定</h3>' +
                    '<p class="shift-card__desc">シフト集計・レポートで人件費を算出する際の基準値。スタッフ個別に上書きしたい場合は「ユーザー管理」から設定できます。</p>' +
                  '</div>' +
                  '<div class="shift-card__body">' +
                    '<div class="form-field">' +
                      '<label class="form-field__label" for="set-hourly-rate">デフォルト時給 <span class="form-field__label-sub">円</span></label>' +
                      '<input class="form-field__input" type="number" id="set-hourly-rate" min="0" max="10000" value="' + (s.default_hourly_rate !== null ? s.default_hourly_rate : '') + '" placeholder="未設定">' +
                      '<p class="form-field__hint">空欄にすると人件費計算が行われません。深夜（22時〜翌5時）は自動で 1.25 倍されます。</p>' +
                    '</div>' +
                    '<div class="form-field">' +
                      '<label class="form-field__label" for="set-labor-ratio">目標人件費率 <span class="form-field__label-sub">%</span></label>' +
                      '<input class="form-field__input" type="number" id="set-labor-ratio" min="1" max="80" step="0.1" value="' + (s.target_labor_cost_ratio !== null ? s.target_labor_cost_ratio : 30) + '">' +
                      '<p class="form-field__hint">公開前チェックと集計サマリーで、予定/実績の人件費率がこの値を超えると警告します。</p>' +
                    '</div>' +
                  '</div>' +
                  '<div class="shift-card__save">' +
                    '<span class="shift-card__save-hint">変更は翌月シフトの試算から反映</span>' +
                    '<button class="btn btn-primary" id="set-save-wage" type="button">保存</button>' +
                  '</div>' +
                '</section>';
        },

        _renderSettingsCardPositions: function(s) {
            var positions = s.positions || [];
            var rows = '';
            if (positions.length === 0) {
                rows = '<p class="empty-msg">持ち場がありません。</p>';
            } else {
                rows += '<div class="shift-position-list">';
                for (var i = 0; i < positions.length; i++) {
                    var p = positions[i];
                    rows += '<div class="shift-position-row' + (p.is_active === 0 ? ' is-disabled' : '') + '">' +
                        '<span><strong>' + esc(p.label || p.code) + '</strong><small>' + esc(p.code) + '</small></span>' +
                        (p.is_active === 0 ? '<em>非表示</em>' : '<button type="button" class="btn btn-sm set-position-disable" data-id="' + esc(p.id) + '">非表示</button>') +
                        '</div>';
                }
                rows += '</div>';
            }
            return '' +
                '<section class="shift-card">' +
                  '<div class="shift-card__head">' +
                    '<h3 class="shift-card__title"><span class="shift-card__title-icon">▦</span>持ち場</h3>' +
                    '<p class="shift-card__desc">ホール/キッチンだけで足りない場合に、レジ、ドリンク、洗い場、仕込みなどを追加します。公開前チェックの役割不足判定に使われます。</p>' +
                  '</div>' +
                  '<div class="shift-card__body">' +
                    rows +
                    '<div class="shift-position-add">' +
                      '<input class="form-field__input" type="text" id="set-position-code" placeholder="code 例: drink">' +
                      '<input class="form-field__input" type="text" id="set-position-label" placeholder="表示名 例: ドリンク">' +
                      '<button class="btn btn-primary" id="set-position-add" type="button">追加</button>' +
                    '</div>' +
                  '</div>' +
                '</section>';
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
            html += '<div class="shift-summary-period">';
            html += '<button class="btn btn-sm' + (self._summaryPeriod === 'weekly' ? ' btn-primary' : '') + '" id="summary-weekly">週次</button>';
            html += '<button class="btn btn-sm' + (self._summaryPeriod === 'monthly' ? ' btn-primary' : '') + '" id="summary-monthly">月次</button>';
            html += '</div>';

            // 労基法警告パネル (Batch-C: class ベースに変更、admin.css の .shift-summary__* で装飾)
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
                    html += '<div class="shift-summary__warnings">';
                    html += '<strong class="shift-summary__warnings-title">⚠ 労基法チェック（' + alertCount + '件の警告）</strong>';
                    for (var ei = 0; ei < errors.length; ei++) {
                        html += '<div class="shift-summary__warnings-item shift-summary__warnings-item--error">🔴 ' + esc(errors[ei].message) + '</div>';
                    }
                    for (var wi = 0; wi < warnings.length; wi++) {
                        html += '<div class="shift-summary__warnings-item shift-summary__warnings-item--warn">🟡 ' + esc(warnings[wi].message) + '</div>';
                    }
                    html += '</div>';
                }
                if (infos.length > 0) {
                    html += '<div class="shift-summary__infos">';
                    for (var ii = 0; ii < infos.length; ii++) {
                        html += '<div class="shift-summary__infos-item">🔵 ' + esc(infos[ii].message) + '</div>';
                    }
                    html += '</div>';
                }
            }

            // 合計 (Batch-C: class ベースに変更)
            html += '<div class="shift-summary__stats">';
            html += '<div class="shift-summary__stat"><span class="shift-summary__stat-label">総労働時間</span><span class="shift-summary__stat-value">' + data.total_hours + 'h</span></div>';
            if (data.labor_cost_total > 0) {
                html += '<div class="shift-summary__stat"><span class="shift-summary__stat-label">総人件費</span><span class="shift-summary__stat-value">¥' + data.labor_cost_total.toLocaleString() + '</span></div>';
            }
            if (data.labor_cost_ratio !== null && data.labor_cost_ratio !== undefined) {
                html += '<div class="shift-summary__stat"><span class="shift-summary__stat-label">人件費率</span><span class="shift-summary__stat-value">' + data.labor_cost_ratio + '%</span></div>';
            }
            if (data.night_premium_total > 0) {
                html += '<div class="shift-summary__stat"><span class="shift-summary__stat-label">深夜手当合計</span><span class="shift-summary__stat-value">¥' + data.night_premium_total.toLocaleString() + '</span></div>';
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
                html += '<h4 class="shift-summary__daily-h4">日別</h4>';
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
        _shortDateTime: function(value) {
            if (!value) return '';
            return String(value).substring(5, 16);
        },

        _datetimeLocalValue: function(value) {
            if (!value) return '';
            return String(value).replace(' ', 'T').substring(0, 16);
        },

        _attendanceStatusLabel: function(status) {
            return { working: '勤務中', completed: '完了', absent: '欠勤', late: '遅刻' }[status] || status || '';
        },

        _correctionStatusLabel: function(status) {
            return { pending: '責任者確認待ち', approved: '承認済', rejected: '却下', cancelled: '取消' }[status] || status || '';
        },

        _candidateStatusLabel: function(status) {
            return {
                not_required: '承諾不要',
                pending: '承諾待ち',
                accepted: '承諾済',
                declined: '辞退'
            }[status] || status || '';
        },

        _correctionSummary: function(req) {
            var parts = [];
            if (req.requested_clock_in) {
                parts.push('出勤 ' + this._shortDateTime(req.requested_clock_in));
            }
            if (req.requested_clock_out) {
                parts.push('退勤 ' + this._shortDateTime(req.requested_clock_out));
            }
            if (req.requested_break_minutes !== null && typeof req.requested_break_minutes !== 'undefined') {
                parts.push('休憩 ' + req.requested_break_minutes + '分');
            }
            if (parts.length === 0) {
                parts.push('内容確認');
            }
            return parts.join(' / ');
        },

        _formatDate: function(dt) {
            var y = dt.getFullYear();
            var m = ('0' + (dt.getMonth() + 1)).slice(-2);
            var d = ('0' + dt.getDate()).slice(-2);
            return y + '-' + m + '-' + d;
        }
    };

    window.ShiftManager = ShiftManager;
})();
