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
                    default_hourly_rate: hourlyRate
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
                  '</div>' +
                  '<div class="shift-card__save">' +
                    '<span class="shift-card__save-hint">変更は翌月シフトの試算から反映</span>' +
                    '<button class="btn btn-primary" id="set-save-wage" type="button">保存</button>' +
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
        _formatDate: function(dt) {
            var y = dt.getFullYear();
            var m = ('0' + (dt.getMonth() + 1)).slice(-2);
            var d = ('0' + dt.getDate()).slice(-2);
            return y + '-' + m + '-' + d;
        }
    };

    window.ShiftManager = ShiftManager;
})();
