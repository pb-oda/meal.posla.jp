/**
 * shift-calendar.js — 週間シフトカレンダー（L-3）
 *
 * マネージャー用の週間カレンダー表示・編集機能。
 * 行=スタッフ、列=日付（月〜日）。セルタップで編集ダイアログ。
 */
(function() {
    'use strict';

    var API_BASE = '/api/store/shift/';
    var DAY_NAMES = ['日', '月', '火', '水', '木', '金', '土'];

    function esc(str) {
        if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(str);
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function formatDate(dt) {
        var y = dt.getFullYear();
        var m = ('0' + (dt.getMonth() + 1)).slice(-2);
        var d = ('0' + dt.getDate()).slice(-2);
        return y + '-' + m + '-' + d;
    }

    function apiCall(method, endpoint, body, callback) {
        var opts = { method: method, headers: { 'Content-Type': 'application/json' } };
        if (body && method !== 'GET') opts.body = JSON.stringify(body);
        fetch(API_BASE + endpoint, opts)
            .then(function(res) { return res.text(); })
            .then(function(text) {
                var json = JSON.parse(text);
                if (!json.ok) { callback(null, json.error); return; }
                callback(json.data);
            })
            .catch(function(err) { callback(null, { message: err.message }); });
    }

    // ── Gemini API呼び出し（ai-assistant.jsと同パターン） ──
    function callGemini(apiKey, prompt, maxTokens, onSuccess, onError) {
        var url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' + encodeURIComponent(apiKey);
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                contents: [{ parts: [{ text: prompt }] }],
                generationConfig: { temperature: 0.8, maxOutputTokens: maxTokens }
            })
        })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            var json = JSON.parse(text);
            if (json.error) { onError(json.error.message || 'Gemini APIエラー'); return; }
            var t = '';
            if (json.candidates && json.candidates[0] && json.candidates[0].content && json.candidates[0].content.parts) {
                t = json.candidates[0].content.parts[0].text || '';
            }
            onSuccess(t);
        })
        .catch(function(err) { onError(err.message); });
    }

    // GeminiレスポンスからJSON部分を抽出
    function extractJson(text) {
        // ```json ... ``` フェンスを除去
        var match = text.match(/```json\s*([\s\S]*?)```/);
        if (match) return match[1].trim();
        // ``` ... ``` フェンスを除去
        match = text.match(/```\s*([\s\S]*?)```/);
        if (match) return match[1].trim();
        // そのまま返す
        return text.trim();
    }

    // 深夜時間計算（フロントサイド）: start_time, end_time (HH:MM) から22:00-05:00の重複分数
    function calcNightMinutesFront(startTime, endTime) {
        var sh = parseInt(startTime.substring(0, 2), 10);
        var sm = parseInt(startTime.substring(3, 5), 10);
        var eh = parseInt(endTime.substring(0, 2), 10);
        var em = parseInt(endTime.substring(3, 5), 10);
        var startMin = sh * 60 + sm;
        var endMin = eh * 60 + em;
        if (endMin <= startMin) endMin += 1440; // 日跨ぎ

        var nightMins = 0;
        // 深夜帯: 0:00-5:00 (0-300), 22:00-24:00 (1320-1440)
        var bands = [[0, 300], [1320, 1440], [1440, 1740]]; // 翌日 0:00-5:00 = 1440-1740
        for (var b = 0; b < bands.length; b++) {
            var overlapStart = Math.max(startMin, bands[b][0]);
            var overlapEnd = Math.min(endMin, bands[b][1]);
            if (overlapEnd > overlapStart) nightMins += overlapEnd - overlapStart;
        }
        return nightMins;
    }

    var ShiftCalendar = {
        containerId: null,
        storeId: null,
        weekStart: null,
        assignments: [],
        users: [],
        availabilities: [],
        hourlyRates: null,
        _initialized: false,
        _aiApiKey: null,

        init: function(containerId, storeId) {
            this.containerId = containerId;
            this.storeId = storeId;

            if (!this.weekStart) {
                // 今週の月曜日
                var today = new Date();
                var day = today.getDay();
                var diff = today.getDate() - day + (day === 0 ? -6 : 1);
                this.weekStart = new Date(today.setDate(diff));
                this.weekStart.setHours(0, 0, 0, 0);
            }

            this.loadWeek();
            this._initialized = true;
        },

        loadWeek: function() {
            var self = this;
            var container = document.getElementById(self.containerId);
            if (!container) return;
            container.innerHTML = '<p>読み込み中...</p>';

            var start = formatDate(self.weekStart);
            var endDt = new Date(self.weekStart);
            endDt.setDate(endDt.getDate() + 6);
            var end = formatDate(endDt);

            apiCall('GET', 'assignments.php?store_id=' + self.storeId + '&start_date=' + start + '&end_date=' + end, null, function(data, err) {
                if (err) {
                    container.innerHTML = '<p class="error">シフトデータの取得に失敗しました</p>';
                    return;
                }
                self.assignments = data.assignments || [];
                self.users = data.users || [];
                self.availabilities = data.availabilities || [];
                self.hourlyRates = data.hourly_rates || null;
                self.render();
            });
        },

        render: function() {
            var self = this;
            var container = document.getElementById(self.containerId);
            if (!container) return;

            var start = formatDate(self.weekStart);
            var endDt = new Date(self.weekStart);
            endDt.setDate(endDt.getDate() + 6);
            var end = formatDate(endDt);

            // ── ヘッダー: 週ナビ + アクション ──
            var html = '<div class="shift-section-header">' +
                '<button class="btn btn-sm" id="cal-prev">◀ 前の週</button>' +
                '<h3>' + start + ' 〜 ' + end + '</h3>' +
                '<button class="btn btn-sm" id="cal-next">次の週 ▶</button>' +
                '</div>' +
                '<div class="cal-actions">' +
                '<button class="btn btn-sm" id="cal-apply-tpl">テンプレート適用</button> ' +
                '<button class="btn btn-sm" id="cal-ai-suggest" style="background:#7c4dff;color:#fff;">AI提案</button> ' +
                '<button class="btn btn-sm btn-primary" id="cal-publish">確定する</button>' +
                '</div>';

            // ── カレンダーテーブル ──
            // 日付配列
            var dates = [];
            var dt = new Date(self.weekStart);
            for (var d = 0; d < 7; d++) {
                dates.push({ str: formatDate(dt), date: new Date(dt), dow: dt.getDay() });
                dt.setDate(dt.getDate() + 1);
            }

            html += '<div class="cal-scroll"><table class="shift-table shift-calendar-table">';

            // ヘッダー行
            html += '<thead><tr><th class="cal-name-col">スタッフ</th>';
            for (var h = 0; h < dates.length; h++) {
                var dInfo = dates[h];
                var isWeekend = (dInfo.dow === 0 || dInfo.dow === 6);
                html += '<th class="cal-day-col' + (isWeekend ? ' weekend' : '') + '">' +
                        (dInfo.date.getMonth() + 1) + '/' + dInfo.date.getDate() +
                        '<br><small>' + DAY_NAMES[dInfo.dow] + '</small></th>';
            }
            html += '</tr></thead><tbody>';

            // 割当マップ: user_id -> date -> [assignments]
            var assignMap = {};
            for (var a = 0; a < self.assignments.length; a++) {
                var asg = self.assignments[a];
                if (!assignMap[asg.user_id]) assignMap[asg.user_id] = {};
                if (!assignMap[asg.user_id][asg.shift_date]) assignMap[asg.user_id][asg.shift_date] = [];
                assignMap[asg.user_id][asg.shift_date].push(asg);
            }

            // 希望マップ: user_id -> date -> availability
            var availMap = {};
            for (var v = 0; v < self.availabilities.length; v++) {
                var av = self.availabilities[v];
                if (!availMap[av.user_id]) availMap[av.user_id] = {};
                availMap[av.user_id][av.target_date] = av;
            }

            // スタッフ行
            if (self.users.length === 0) {
                html += '<tr><td colspan="8" class="empty-msg">この店舗にスタッフが登録されていません</td></tr>';
            }
            for (var u = 0; u < self.users.length; u++) {
                var usr = self.users[u];
                html += '<tr><td class="cal-name-col">' + esc(usr.display_name || usr.username) + '</td>';

                for (var c = 0; c < dates.length; c++) {
                    var dateStr = dates[c].str;
                    var cellAssignments = (assignMap[usr.id] && assignMap[usr.id][dateStr]) || [];
                    var cellAvail = (availMap[usr.id] && availMap[usr.id][dateStr]) || null;

                    var cellClass = 'cal-cell';
                    var cellContent = '';

                    // 希望の背景色 + 希望時間帯表示
                    var availHint = '';
                    if (cellAvail) {
                        if (cellAvail.availability === 'preferred') cellClass += ' avail-preferred';
                        else if (cellAvail.availability === 'unavailable') cellClass += ' avail-unavailable';
                        else cellClass += ' avail-available';
                        // 希望時間帯をツールチップ風に表示
                        if (cellAvail.availability !== 'unavailable' && cellAvail.preferred_start && cellAvail.preferred_end) {
                            availHint = '<div style="font-size:0.65rem;color:#666;line-height:1.2;">希望 ' +
                                esc(cellAvail.preferred_start.substring(0, 5)) + '-' +
                                esc(cellAvail.preferred_end.substring(0, 5)) + '</div>';
                        }
                    }

                    if (cellAssignments.length > 0) {
                        for (var s = 0; s < cellAssignments.length; s++) {
                            var sa = cellAssignments[s];
                            var statusClass = 'shift-' + esc(sa.status);
                            var isHelp = !!sa.help_request_id;
                            var helpClass = isHelp ? ' shift-help' : '';
                            var helpBadge = isHelp ? '<span class="help-badge">ヘルプ</span>' : '';
                            var helperInfo = (isHelp && sa.helper_store_name) ?
                                '<br><small style="color:#e65100;">(' + esc(sa.helper_store_name) + ')</small>' : '';
                            cellContent += '<div class="cal-shift ' + statusClass + helpClass + '" data-id="' + sa.id + '">' +
                                helpBadge +
                                esc(sa.start_time.substring(0, 5)) + '-' + esc(sa.end_time.substring(0, 5)) +
                                (sa.role_type ? '<br><small>' + esc(sa.role_type) + '</small>' : '') +
                                helperInfo +
                                '</div>';
                        }
                        cellContent += availHint;
                    } else {
                        cellContent = availHint + '<div class="cal-empty">+</div>';
                    }

                    html += '<td class="' + cellClass + '" data-user="' + usr.id + '" data-date="' + dateStr + '">' +
                            cellContent + '</td>';
                }

                html += '</tr>';
            }

            // 合計行 + L-3 Phase 2: 推定人件費
            var weekLaborCost = 0;
            var dayCosts = [];
            var hasRates = self.hourlyRates && self.hourlyRates.default !== null;
            html += '<tr class="cal-total-row"><td class="cal-name-col"><strong>合計</strong></td>';
            for (var t = 0; t < dates.length; t++) {
                var totalCount = 0;
                var dayCost = 0;
                for (var tu = 0; tu < self.users.length; tu++) {
                    var uid = self.users[tu].id;
                    if (assignMap[uid] && assignMap[uid][dates[t].str]) {
                        var dayAssigns = assignMap[uid][dates[t].str];
                        totalCount += dayAssigns.length;
                        // 人件費計算
                        if (self.hourlyRates) {
                            var rate = (self.hourlyRates.by_user && self.hourlyRates.by_user[uid] !== undefined)
                                ? self.hourlyRates.by_user[uid] : self.hourlyRates.default;
                            if (rate !== null) {
                                for (var da = 0; da < dayAssigns.length; da++) {
                                    var sa2 = dayAssigns[da];
                                    var sStart = (sa2.start_time || '').substring(0, 5);
                                    var sEnd = (sa2.end_time || '').substring(0, 5);
                                    if (sStart && sEnd) {
                                        var sh2 = parseInt(sStart.substring(0, 2), 10);
                                        var sm2 = parseInt(sStart.substring(3, 5), 10);
                                        var eh2 = parseInt(sEnd.substring(0, 2), 10);
                                        var em2 = parseInt(sEnd.substring(3, 5), 10);
                                        var totalMins = (eh2 * 60 + em2) - (sh2 * 60 + sm2);
                                        if (totalMins < 0) totalMins += 1440;
                                        totalMins -= (sa2.break_minutes || 0);
                                        if (totalMins < 0) totalMins = 0;
                                        var nightMins = calcNightMinutesFront(sStart, sEnd);
                                        var normalMins = totalMins - nightMins;
                                        if (normalMins < 0) normalMins = 0;
                                        dayCost += Math.round((normalMins / 60) * rate + (nightMins / 60) * rate * 1.25);
                                    }
                                }
                            }
                        }
                    }
                }
                weekLaborCost += dayCost;
                dayCosts.push(dayCost);
                html += '<td class="cal-total-cell">' + totalCount + '人</td>';
            }
            html += '</tr>';

            // 人件費合計行
            if (hasRates || weekLaborCost > 0) {
                html += '<tr class="cal-total-row"><td class="cal-name-col" style="font-size:0.85rem;color:#666;">推定人件費</td>';
                for (var lc = 0; lc < dayCosts.length; lc++) {
                    html += '<td class="cal-total-cell" style="font-size:0.8rem;color:#666;">' +
                        (dayCosts[lc] > 0 ? '¥' + dayCosts[lc].toLocaleString() : '') + '</td>';
                }
                html += '</tr>';
            }

            html += '</tbody></table></div>';

            // ── 凡例 ──
            html += '<div class="cal-legend">' +
                '<span class="cal-legend-item"><span class="shift-draft-swatch"></span>下書き</span>' +
                '<span class="cal-legend-item"><span class="shift-published-swatch"></span>確定済</span>' +
                '<span class="cal-legend-item"><span class="shift-confirmed-swatch"></span>確認済</span>' +
                '<span class="cal-legend-item"><span class="avail-preferred-swatch"></span>希望</span>' +
                '<span class="cal-legend-item"><span class="avail-unavailable-swatch"></span>不可</span>' +
                '<span class="cal-legend-item"><span class="shift-help-swatch"></span>ヘルプ</span>' +
                '</div>';

            // L-3 Phase 2: 週間推定人件費
            if (weekLaborCost > 0) {
                html += '<div style="margin-top:0.5rem;font-size:0.9rem;color:#333;">推定週間人件費: <strong>¥' + weekLaborCost.toLocaleString() + '</strong></div>';
            }

            container.innerHTML = html;

            // ── イベントバインド ──
            // 週ナビ
            document.getElementById('cal-prev').addEventListener('click', function() {
                self.weekStart.setDate(self.weekStart.getDate() - 7);
                self.loadWeek();
            });
            document.getElementById('cal-next').addEventListener('click', function() {
                self.weekStart.setDate(self.weekStart.getDate() + 7);
                self.loadWeek();
            });

            // セルクリック
            var cells = container.querySelectorAll('.cal-cell');
            for (var cl = 0; cl < cells.length; cl++) {
                cells[cl].addEventListener('click', function(e) {
                    var userId = this.getAttribute('data-user');
                    var date = this.getAttribute('data-date');

                    // 既存シフトクリック → 編集
                    var shiftEl = e.target.closest('.cal-shift');
                    if (shiftEl) {
                        var shiftId = shiftEl.getAttribute('data-id');
                        var existing = null;
                        for (var f = 0; f < self.assignments.length; f++) {
                            if (self.assignments[f].id === shiftId) { existing = self.assignments[f]; break; }
                        }
                        if (existing) self._showEditDialog(existing);
                    } else {
                        // 空セルクリック → 新規
                        self._showEditDialog({
                            user_id: userId,
                            shift_date: date,
                            start_time: '09:00',
                            end_time: '18:00',
                            break_minutes: 0,
                            role_type: null,
                            note: ''
                        });
                    }
                });
            }

            // テンプレート適用
            document.getElementById('cal-apply-tpl').addEventListener('click', function() {
                self._showApplyTemplateDialog(start, end);
            });

            // L-3 Phase 2: AI提案
            document.getElementById('cal-ai-suggest').addEventListener('click', function() {
                self._showAiSuggestDialog(start, end);
            });

            // 公開
            document.getElementById('cal-publish').addEventListener('click', function() {
                if (!confirm(start + ' 〜 ' + end + ' のシフトを確定しますか？\n確定するとスタッフに表示されます。')) return;
                apiCall('POST', 'assignments.php?action=publish&store_id=' + encodeURIComponent(self.storeId), {
                    store_id: self.storeId,
                    start_date: start,
                    end_date: end
                }, function(data, err) {
                    if (err) { alert('確定に失敗しました: ' + (err.message || '')); return; }
                    alert(data.published_count + '件のシフトを確定しました');
                    self.loadWeek();
                });
            });
        },

        // ── 編集ダイアログ ──
        _showEditDialog: function(assignment) {
            var self = this;
            var isNew = !assignment.id;
            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';

            // スタッフ名を取得
            var staffName = '';
            for (var i = 0; i < self.users.length; i++) {
                if (self.users[i].id === assignment.user_id) {
                    staffName = self.users[i].display_name || self.users[i].username;
                    break;
                }
            }

            overlay.innerHTML = '<div class="shift-dialog">' +
                '<h3>' + (isNew ? 'シフト追加' : 'シフト編集') + ': ' + esc(staffName) + ' (' + assignment.shift_date + ')</h3>' +
                '<label>開始時刻<input type="time" id="asg-start" value="' + (assignment.start_time || '09:00').substring(0, 5) + '"></label>' +
                '<label>終了時刻<input type="time" id="asg-end" value="' + (assignment.end_time || '18:00').substring(0, 5) + '"></label>' +
                '<label>休憩(分)<input type="number" id="asg-break" min="0" max="180" value="' + (assignment.break_minutes || 0) + '"></label>' +
                '<label>役割<select id="asg-role"><option value="">指定なし</option>' +
                '<option value="kitchen"' + (assignment.role_type === 'kitchen' ? ' selected' : '') + '>kitchen</option>' +
                '<option value="hall"' + (assignment.role_type === 'hall' ? ' selected' : '') + '>hall</option></select></label>' +
                '<label>メモ<input type="text" id="asg-note" value="' + esc(assignment.note || '') + '"></label>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="asg-save">保存</button>' +
                (isNew ? '' : '<button class="btn btn-danger" id="asg-delete">削除</button>') +
                '<button class="btn" id="asg-cancel">キャンセル</button>' +
                '</div></div>';

            document.body.appendChild(overlay);

            document.getElementById('asg-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });

            document.getElementById('asg-save').addEventListener('click', function() {
                var data = {
                    store_id: self.storeId,
                    user_id: assignment.user_id,
                    shift_date: assignment.shift_date,
                    start_time: document.getElementById('asg-start').value,
                    end_time: document.getElementById('asg-end').value,
                    break_minutes: parseInt(document.getElementById('asg-break').value, 10),
                    role_type: document.getElementById('asg-role').value || null,
                    note: document.getElementById('asg-note').value
                };

                if (isNew) {
                    apiCall('POST', 'assignments.php?store_id=' + encodeURIComponent(self.storeId), data, function(result, err) {
                        if (err) { alert('追加に失敗しました: ' + (err.message || '')); return; }
                        document.body.removeChild(overlay);
                        self.loadWeek();
                    });
                } else {
                    apiCall('PATCH', 'assignments.php?id=' + assignment.id + '&store_id=' + self.storeId, data, function(result, err) {
                        if (err) { alert('更新に失敗しました: ' + (err.message || '')); return; }
                        document.body.removeChild(overlay);
                        self.loadWeek();
                    });
                }
            });

            if (!isNew) {
                document.getElementById('asg-delete').addEventListener('click', function() {
                    if (!confirm('このシフトを削除しますか？')) return;
                    apiCall('DELETE', 'assignments.php?id=' + assignment.id + '&store_id=' + self.storeId, null, function(result, err) {
                        if (err) { alert('削除に失敗しました'); return; }
                        document.body.removeChild(overlay);
                        self.loadWeek();
                    });
                });
            }
        },

        // ── テンプレート適用ダイアログ ──
        _showApplyTemplateDialog: function(startDate, endDate) {
            var self = this;

            // テンプレート読み込み
            apiCall('GET', 'templates.php?store_id=' + self.storeId, null, function(data, err) {
                if (err) { alert('テンプレート取得に失敗しました'); return; }
                var templates = data.templates || [];
                if (templates.length === 0) {
                    alert('テンプレートが登録されていません。先に「テンプレート」タブで作成してください。');
                    return;
                }

                var overlay = document.createElement('div');
                overlay.className = 'shift-dialog-overlay';

                var html = '<div class="shift-dialog shift-dialog-wide">' +
                    '<h3>テンプレート適用: ' + startDate + ' 〜 ' + endDate + '</h3>' +
                    '<p>各テンプレートに割り当てるスタッフを選択してください。</p>' +
                    '<div class="tpl-assign-list">';

                for (var t = 0; t < templates.length; t++) {
                    var tpl = templates[t];
                    html += '<div class="tpl-assign-row">' +
                        '<div class="tpl-info">' +
                        '<strong>' + esc(tpl.name) + '</strong> ' +
                        DAY_NAMES[tpl.day_of_week] + ' ' +
                        tpl.start_time.substring(0, 5) + '-' + tpl.end_time.substring(0, 5) +
                        ' (' + tpl.required_staff + '人)' +
                        '</div>';
                    // スタッフチェックボックス
                    html += '<div class="tpl-staff-checks">';
                    for (var u = 0; u < self.users.length; u++) {
                        html += '<label class="tpl-staff-label">' +
                            '<input type="checkbox" class="tpl-staff-cb" data-tpl="' + tpl.id + '" data-user="' + self.users[u].id + '"> ' +
                            esc(self.users[u].display_name || self.users[u].username) +
                            '</label>';
                    }
                    html += '</div></div>';
                }

                html += '</div>' +
                    '<div class="shift-dialog-actions">' +
                    '<button class="btn btn-primary" id="tpl-apply-go">適用</button>' +
                    '<button class="btn" id="tpl-apply-cancel">キャンセル</button>' +
                    '</div></div>';

                overlay.innerHTML = html;
                document.body.appendChild(overlay);

                document.getElementById('tpl-apply-cancel').addEventListener('click', function() {
                    document.body.removeChild(overlay);
                });

                document.getElementById('tpl-apply-go').addEventListener('click', function() {
                    var checks = overlay.querySelectorAll('.tpl-staff-cb:checked');
                    var userAssignments = [];
                    for (var c = 0; c < checks.length; c++) {
                        userAssignments.push({
                            template_id: checks[c].getAttribute('data-tpl'),
                            user_id: checks[c].getAttribute('data-user')
                        });
                    }
                    if (userAssignments.length === 0) {
                        alert('少なくとも1人のスタッフを選択してください');
                        return;
                    }

                    apiCall('POST', 'assignments.php?action=apply-template&store_id=' + encodeURIComponent(self.storeId), {
                        store_id: self.storeId,
                        start_date: startDate,
                        end_date: endDate,
                        user_assignments: userAssignments
                    }, function(result, err) {
                        if (err) { alert('適用に失敗しました: ' + (err.message || '')); return; }
                        alert(result.created + '件作成、' + result.skipped + '件スキップ（既存あり）');
                        document.body.removeChild(overlay);
                        self.loadWeek();
                    });
                });
            });
        },

        // ── AI最適シフト提案ダイアログ（L-3 Phase 2）──
        _showAiSuggestDialog: function(startDate, endDate) {
            var self = this;

            // ダイアログ表示
            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';
            overlay.innerHTML = '<div class="shift-dialog shift-dialog-wide">' +
                '<h3>AI最適シフト提案</h3>' +
                '<p>' + startDate + ' 〜 ' + endDate + '</p>' +
                '<div id="ai-suggest-content" style="min-height:200px;"><p>データを収集中...</p></div>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="ai-suggest-apply" style="display:none">全て採用</button>' +
                '<button class="btn" id="ai-suggest-cancel">閉じる</button>' +
                '</div></div>';
            document.body.appendChild(overlay);

            document.getElementById('ai-suggest-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });

            // 1. APIキー取得
            var settingsUrl = '/api/store/settings.php?store_id=' + encodeURIComponent(self.storeId) + '&include_ai_key=1';
            fetch(settingsUrl).then(function(r) { return r.text(); }).then(function(text) {
                var json = JSON.parse(text);
                if (!json.ok || !json.data || !json.data.ai_api_key) {
                    document.getElementById('ai-suggest-content').innerHTML = '<p class="error">AIのAPIキーが設定されていません。POSLA管理画面でAPIキーを設定してください。</p>';
                    return;
                }
                self._aiApiKey = json.data.ai_api_key;

                // 2. データ収集
                document.getElementById('ai-suggest-content').innerHTML = '<p>AI提案用データを収集中...</p>';
                apiCall('GET', 'ai-suggest-data.php?store_id=' + self.storeId + '&start_date=' + startDate + '&end_date=' + endDate, null, function(suggestData, err) {
                    if (err) {
                        document.getElementById('ai-suggest-content').innerHTML = '<p class="error">データ取得に失敗しました: ' + esc(err.message || '') + '</p>';
                        return;
                    }

                    // 3. Geminiプロンプト構築
                    document.getElementById('ai-suggest-content').innerHTML = '<p>AIが最適なシフトを提案中...</p>';
                    var prompt = self._buildAiPrompt(suggestData, startDate, endDate);

                    callGemini(self._aiApiKey, prompt, 4096, function(responseText) {
                        // 4. JSON解析
                        var jsonStr = extractJson(responseText);
                        try {
                            var result = JSON.parse(jsonStr);
                        } catch (e) {
                            document.getElementById('ai-suggest-content').innerHTML = '<p class="error">AIの応答をパースできませんでした。再試行してください。</p>' +
                                '<details><summary>生データ</summary><pre style="font-size:0.75rem;max-height:200px;overflow:auto;">' + esc(responseText) + '</pre></details>';
                            return;
                        }
                        self._renderAiSuggestResult(overlay, result, suggestData, startDate, endDate);
                    }, function(errMsg) {
                        document.getElementById('ai-suggest-content').innerHTML = '<p class="error">AI提案エラー: ' + esc(errMsg) + '</p>';
                    });
                });
            }).catch(function(err) {
                document.getElementById('ai-suggest-content').innerHTML = '<p class="error">設定取得エラー: ' + esc(err.message) + '</p>';
            });
        },

        _buildAiPrompt: function(data, startDate, endDate) {
            var weekdayNames = ['日', '月', '火', '水', '木', '金', '土'];
            var prompt = 'あなたは飲食店のシフト管理AIです。\n\n';
            prompt += '以下のデータに基づき、' + startDate + '〜' + endDate + 'の最適なシフト割当を提案してください。\n\n';
            prompt += '## ルール\n';
            prompt += '- テンプレートの必要人数を満たすように割り当てる\n';
            prompt += '- スタッフの希望（preferred > available > unavailable=割当不可）を尊重する\n';
            prompt += '- 過去の売上データから繁忙/閑散を判断し、人数を調整する\n';
            prompt += '- 連続勤務6日以上を避ける\n';
            prompt += '- 週40時間を超えないようにする\n';
            prompt += '- 各スタッフの勤務時間が偏らないようにする\n';
            prompt += '- スタッフ一覧に含まれる全員にシフトを割り当てること（実績がない新人も含む）\n';
            prompt += '- 時給を考慮し、人件費を最適化する（ただし繁忙時の人員不足は避ける）\n\n';

            prompt += '## テンプレート\n';
            prompt += JSON.stringify(data.templates) + '\n\n';

            prompt += '## スタッフ希望\n';
            prompt += JSON.stringify(data.availabilities) + '\n\n';

            prompt += '## スタッフ一覧（直近4週間の勤怠含む）\n';
            prompt += JSON.stringify(data.staffList) + '\n\n';

            prompt += '## 過去4週間の時間帯別平均売上\n';
            prompt += JSON.stringify(data.salesByDayHour) + '\n\n';

            prompt += '## 出力\n';
            prompt += '以下のJSON形式のみ出力。前置き・解説・補足は禁止。\n';
            prompt += '{"suggestions":[{"user_id":"xxx","shift_date":"YYYY-MM-DD","start_time":"HH:MM","end_time":"HH:MM","role_type":"hall or kitchen or null","reason":"理由"}],';
            prompt += '"summary":"全体の提案理由",';
            prompt += '"estimated_labor_cost":0,';
            prompt += '"warnings":["警告メッセージ"]}\n';

            return prompt;
        },

        _renderAiSuggestResult: function(overlay, result, suggestData, startDate, endDate) {
            var self = this;
            var content = document.getElementById('ai-suggest-content');
            if (!content) return;

            var suggestions = result.suggestions || [];
            var summary = result.summary || '';
            var estCost = result.estimated_labor_cost || 0;
            var warnings = result.warnings || [];

            // スタッフ名マップ
            var nameMap = {};
            for (var i = 0; i < suggestData.staffList.length; i++) {
                nameMap[suggestData.staffList[i].id] = suggestData.staffList[i].display_name;
            }

            var html = '';

            // サマリー
            if (summary) {
                html += '<div style="background:#e8f5e9;border-radius:8px;padding:10px 14px;margin-bottom:10px;">' + esc(summary) + '</div>';
            }

            // 推定人件費
            if (estCost > 0) {
                html += '<div style="margin-bottom:10px;">推定週間人件費: <strong>¥' + estCost.toLocaleString() + '</strong></div>';
            }

            // 警告
            for (var w = 0; w < warnings.length; w++) {
                html += '<div style="color:#e65100;margin-bottom:4px;">⚠ ' + esc(warnings[w]) + '</div>';
            }

            // 提案テーブル
            if (suggestions.length > 0) {
                html += '<table class="shift-table" style="margin-top:10px;"><thead><tr>';
                html += '<th>スタッフ</th><th>日付</th><th>開始</th><th>終了</th><th>役割</th><th>理由</th>';
                html += '</tr></thead><tbody>';
                for (var s = 0; s < suggestions.length; s++) {
                    var sg = suggestions[s];
                    html += '<tr>';
                    html += '<td>' + esc(nameMap[sg.user_id] || sg.user_id) + '</td>';
                    html += '<td>' + esc(sg.shift_date) + '</td>';
                    html += '<td>' + esc(sg.start_time) + '</td>';
                    html += '<td>' + esc(sg.end_time) + '</td>';
                    html += '<td>' + esc(sg.role_type || '-') + '</td>';
                    html += '<td style="font-size:0.8rem;max-width:200px;">' + esc(sg.reason || '') + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
            } else {
                html += '<p>提案なし</p>';
            }

            content.innerHTML = html;

            // 採用ボタン表示
            var applyBtn = document.getElementById('ai-suggest-apply');
            if (applyBtn && suggestions.length > 0) {
                applyBtn.style.display = '';
                applyBtn.addEventListener('click', function() {
                    applyBtn.disabled = true;
                    applyBtn.textContent = '登録中...';
                    self._applyAiSuggestions(overlay, suggestions);
                });
            }
        },

        _applyAiSuggestions: function(overlay, suggestions) {
            var self = this;
            var completed = 0;
            var errors = 0;

            function next() {
                if (completed + errors >= suggestions.length) {
                    alert('AI提案を適用しました（' + completed + '件成功、' + errors + '件失敗）');
                    document.body.removeChild(overlay);
                    self.loadWeek();
                    return;
                }
                var sg = suggestions[completed + errors];
                var data = {
                    store_id: self.storeId,
                    user_id: sg.user_id,
                    shift_date: sg.shift_date,
                    start_time: sg.start_time,
                    end_time: sg.end_time,
                    break_minutes: 0,
                    role_type: sg.role_type || null,
                    note: 'AI提案'
                };
                apiCall('POST', 'assignments.php?store_id=' + encodeURIComponent(self.storeId), data, function(result, err) {
                    if (err) { errors++; } else { completed++; }
                    next();
                });
            }
            next();
        },

        // ── 外部API ──
        prevWeek: function() {
            this.weekStart.setDate(this.weekStart.getDate() - 7);
            this.loadWeek();
        },
        nextWeek: function() {
            this.weekStart.setDate(this.weekStart.getDate() + 7);
            this.loadWeek();
        }
    };

    window.ShiftCalendar = ShiftCalendar;
})();
