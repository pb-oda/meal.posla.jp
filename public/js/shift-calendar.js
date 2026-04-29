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

    // ── Gemini API呼び出し（ai-generate.php プロキシ経由 / P1-6b） ──
    function callGemini(prompt, maxTokens, onSuccess, onError) {
        fetch('/api/store/ai-generate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                prompt: prompt,
                temperature: 0.8,
                max_tokens: maxTokens
            })
        })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            var json = JSON.parse(text);
            if (!json.ok) {
                var msg = (json.error && json.error.message) || 'AIプロキシエラー';
                if (json.error && json.error.code === 'AI_NOT_CONFIGURED') {
                    msg = 'POSLA管理画面でGemini APIキーを設定してください';
                }
                onError(msg);
                return;
            }
            onSuccess((json.data && json.data.text) || '');
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
        positions: [],
        publishCheck: null,
        publishCheckLoading: false,
        _initialized: false,

        init: function(containerId, storeId) {
            var self = this;
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

            this._loadPositions(function() {
                self.loadWeek();
            });
            this._initialized = true;
        },

        _loadPositions: function(callback) {
            var self = this;
            apiCall('GET', 'positions.php?store_id=' + encodeURIComponent(self.storeId), null, function(data, err) {
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
            var positions = this.positions && this.positions.length ? this.positions : [
                { code: 'hall', label: 'ホール', is_active: 1 },
                { code: 'kitchen', label: 'キッチン', is_active: 1 }
            ];
            var seen = {};
            var html = '<option value="">指定なし</option>';
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
                self.publishCheck = null;
                self.render();
                self._loadPublishCheck(start, end);
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
                '<button class="btn btn-sm cal-ai-btn" id="cal-ai-suggest">AI提案</button> ' +
                '<button class="btn btn-sm btn-primary" id="cal-publish">確定する</button>' +
                '</div>';

            html += '<div id="cal-publish-check" class="cal-publish-check">' +
                '<div class="cal-publish-check__loading">公開前チェックを読み込み中...</div>' +
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
                            availHint = '<div class="cal-avail-hint">希望 ' +
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
                                '<br><small class="cal-helper-info">(' + esc(sa.helper_store_name) + ')</small>' : '';
                            cellContent += '<div class="cal-shift ' + statusClass + helpClass + '" data-id="' + sa.id + '">' +
                                helpBadge +
                                esc(sa.start_time.substring(0, 5)) + '-' + esc(sa.end_time.substring(0, 5)) +
                                (sa.role_type ? '<br><small>' + esc(self._roleLabel(sa.role_type)) + '</small>' : '') +
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
                html += '<tr class="cal-total-row cal-cost-row"><td class="cal-name-col">推定人件費</td>';
                for (var lc = 0; lc < dayCosts.length; lc++) {
                    html += '<td class="cal-total-cell">' +
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
                html += '<div class="cal-week-cost">推定週間人件費: <strong>¥' + weekLaborCost.toLocaleString() + '</strong></div>';
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
                var confirmMsg = start + ' 〜 ' + end + ' のシフトを確定しますか？\n確定するとスタッフに表示されます。';
                if (self.publishCheck && self.publishCheck.metrics) {
                    var m = self.publishCheck.metrics;
                    var alertCount = (m.error_count || 0) + (m.warning_count || 0);
                    if (alertCount > 0) {
                        confirmMsg += '\n\n公開前チェック: 要確認 ' + alertCount + '件';
                        if (m.error_count > 0) confirmMsg += '\n赤の警告があります。公開前に確認してください。';
                    }
                }
                if (!confirm(confirmMsg)) return;
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

        _loadPublishCheck: function(startDate, endDate) {
            var self = this;
            self.publishCheckLoading = true;
            apiCall('GET', 'publish-check.php?store_id=' + encodeURIComponent(self.storeId) +
                '&start_date=' + encodeURIComponent(startDate) +
                '&end_date=' + encodeURIComponent(endDate), null, function(data, err) {
                self.publishCheckLoading = false;
                if (err) {
                    self.publishCheck = { error: err };
                } else {
                    self.publishCheck = data;
                }
                self._renderPublishCheckPanel();
            });
        },

        _renderPublishCheckPanel: function() {
            var self = this;
            var panel = document.getElementById('cal-publish-check');
            if (!panel) return;

            if (!self.publishCheck) {
                panel.innerHTML = '<div class="cal-publish-check__loading">公開前チェックを読み込み中...</div>';
                return;
            }
            if (self.publishCheck.error) {
                panel.innerHTML = '<div class="cal-publish-check__error">公開前チェックを取得できませんでした</div>';
                return;
            }

            var data = self.publishCheck;
            var m = data.metrics || {};
            var warnings = data.warnings || [];
            var coverage = data.coverage || [];
            var missing = data.missing_availabilities || [];
            var diffs = data.attendance_diffs || [];
            var helps = data.help_candidates || [];
            var laborRisks = data.labor_risks || [];
            var unconfirmed = data.unconfirmed_assignments || [];
            var alertCount = (m.error_count || 0) + (m.warning_count || 0);
            var stateClass = alertCount > 0 ? ' cal-publish-check--warn' : ' cal-publish-check--ok';

            var html = '<div class="cal-publish-check__head' + stateClass + '">' +
                '<div>' +
                '<strong>公開前チェック</strong>' +
                '<span class="cal-publish-check__summary">' +
                '赤 ' + (m.error_count || 0) + ' / 黄 ' + (m.warning_count || 0) + ' / 情報 ' + (m.info_count || 0) +
                '</span>' +
                '</div>' +
                '<button class="btn btn-sm" id="cal-publish-check-refresh">再チェック</button>' +
                '</div>';

            html += '<div class="cal-publish-check__metrics">' +
                '<span>スタッフ ' + (m.staff_count || 0) + '人</span>' +
                '<span>下書き ' + (m.draft_count || 0) + '件</span>' +
                '<span>公開済 ' + (m.published_count || 0) + '件</span>' +
                '<span>確定確認済 ' + (m.confirmed_count || 0) + '件</span>' +
                '<span>未確認 ' + (m.unconfirmed_count || 0) + '件</span>' +
                (m.scheduled_labor_cost !== null && m.scheduled_labor_cost !== undefined ? '<span>予定人件費 ¥' + Number(m.scheduled_labor_cost).toLocaleString() + '</span>' : '') +
                (m.labor_cost_ratio !== null && m.labor_cost_ratio !== undefined ? '<span>予定人件費率 ' + m.labor_cost_ratio + '%</span>' : '') +
                '</div>';

            if (warnings.length > 0) {
                html += '<div class="cal-publish-check__warnings">';
                for (var w = 0; w < Math.min(warnings.length, 10); w++) {
                    var item = warnings[w];
                    html += '<div class="cal-publish-check__warning cal-publish-check__warning--' + esc(item.level || 'info') + '">' +
                        esc(item.message || '') +
                        '</div>';
                }
                if (warnings.length > 10) {
                    html += '<div class="cal-publish-check__more">ほか ' + (warnings.length - 10) + ' 件</div>';
                }
                html += '</div>';
            } else {
                html += '<div class="cal-publish-check__okline">人数・役割・休憩・希望提出・勤怠差分に大きな警告はありません。</div>';
            }

            html += '<div class="cal-publish-check__grid">';
            html += '<section class="cal-publish-check__block"><h4>時間帯別</h4>';
            var shortageRows = [];
            for (var c = 0; c < coverage.length; c++) {
                var roleShortage = false;
                var reqRoles = coverage[c].required_roles || {};
                var roleCounts = coverage[c].role_counts || {};
                for (var rk in reqRoles) {
                    if (reqRoles.hasOwnProperty(rk) && (reqRoles[rk] || 0) > (roleCounts[rk] || 0)) {
                        roleShortage = true;
                        break;
                    }
                }
                if ((coverage[c].shortage_count || 0) > 0 || roleShortage) {
                    shortageRows.push(coverage[c]);
                }
            }
            if (shortageRows.length === 0) {
                html += '<p>不足なし</p>';
            } else {
                html += '<table class="shift-table cal-publish-check__mini"><thead><tr><th>日時</th><th>予定/必要</th><th>予測</th></tr></thead><tbody>';
                for (var sr = 0; sr < Math.min(shortageRows.length, 6); sr++) {
                    var row = shortageRows[sr];
                    html += '<tr><td>' + esc(row.date) + '<br>' + esc(row.time) + '</td>' +
                        '<td>' + row.scheduled_count + ' / ' + row.required_count + '人</td>' +
                        '<td>注文' + row.forecast_orders + ' / 予約' + row.reservation_party_size + '名</td></tr>';
                }
                html += '</tbody></table>';
            }
            html += '</section>';

            html += '<section class="cal-publish-check__block"><h4>希望未提出</h4>';
            if (missing.length === 0) {
                html += '<p>未提出なし</p>';
            } else {
                html += '<p>' + missing.length + '人が未提出または一部未提出です。</p>' +
                    '<button class="btn btn-sm" id="cal-copy-reminders">リマインド文コピー</button>' +
                    '<div class="cal-publish-check__chips">';
                for (var mi = 0; mi < Math.min(missing.length, 8); mi++) {
                    html += '<span>' + esc(missing[mi].display_name) + ' ' + missing[mi].missing_days + '日</span>';
                }
                html += '</div>';
            }
            html += '</section>';

            html += '<section class="cal-publish-check__block"><h4>未確認</h4>';
            if (unconfirmed.length === 0) {
                html += '<p>未確認なし</p>';
            } else {
                html += '<ul class="cal-publish-check__list">';
                for (var ui = 0; ui < Math.min(unconfirmed.length, 5); ui++) {
                    html += '<li>' + esc(unconfirmed[ui].display_name + ' ' + unconfirmed[ui].shift_date + ' ' + unconfirmed[ui].start_time + '-' + unconfirmed[ui].end_time) + '</li>';
                }
                html += '</ul>';
            }
            html += '</section>';

            html += '<section class="cal-publish-check__block"><h4>実績差分</h4>';
            if (diffs.length === 0) {
                html += '<p>差分なし</p>';
            } else {
                html += '<ul class="cal-publish-check__list">';
                for (var di = 0; di < Math.min(diffs.length, 5); di++) {
                    html += '<li>' + esc(diffs[di].message) + '</li>';
                }
                html += '</ul>';
            }
            html += '</section>';

            html += '<section class="cal-publish-check__block"><h4>労務リスク</h4>';
            if (laborRisks.length === 0) {
                html += '<p>大きなリスクなし</p>';
            } else {
                html += '<ul class="cal-publish-check__list">';
                for (var lr = 0; lr < Math.min(laborRisks.length, 5); lr++) {
                    html += '<li>' + esc(laborRisks[lr].message || '') + '</li>';
                }
                html += '</ul>';
            }
            html += '</section>';

            html += '<section class="cal-publish-check__block"><h4>ヘルプ候補</h4>';
            if (helps.length === 0) {
                html += '<p>候補表示なし</p>';
            } else {
                html += '<ul class="cal-publish-check__list">';
                for (var hi = 0; hi < Math.min(helps.length, 4); hi++) {
                    var help = helps[hi];
                    var names = [];
                    for (var hc = 0; hc < Math.min((help.candidates || []).length, 3); hc++) {
                        names.push((help.candidates[hc].store_name || '') + ' ' + (help.candidates[hc].display_name || ''));
                    }
                    html += '<li>' + esc(help.date + ' ' + help.time) + ': ' + (names.length ? esc(names.join(' / ')) : '候補なし') + '</li>';
                }
                html += '</ul>';
            }
            html += '</section></div>';

            panel.innerHTML = html;

            var refresh = document.getElementById('cal-publish-check-refresh');
            if (refresh && data.period) {
                refresh.addEventListener('click', function() {
                    self._loadPublishCheck(data.period.start_date, data.period.end_date);
                });
            }

            var copyBtn = document.getElementById('cal-copy-reminders');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var lines = [];
                    for (var i = 0; i < missing.length; i++) {
                        lines.push(missing[i].reminder_text);
                    }
                    self._copyText(lines.join('\n'));
                });
            }
        },

        _copyText: function(text) {
            if (!text) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    alert('リマインド文をコピーしました');
                }, function() {
                    alert(text);
                });
                return;
            }
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                alert('リマインド文をコピーしました');
            } catch (e) {
                alert(text);
            }
            document.body.removeChild(ta);
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
                '<label>持ち場<select id="asg-role">' + self._roleOptionsHtml(assignment.role_type || null) + '</select></label>' +
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
                '<div id="ai-suggest-content" class="cal-ai-content"><p>データを収集中...</p></div>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="ai-suggest-apply" hidden>全て採用</button>' +
                '<button class="btn" id="ai-suggest-cancel">閉じる</button>' +
                '</div></div>';
            document.body.appendChild(overlay);

            document.getElementById('ai-suggest-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });

            // 1. データ収集（APIキーチェックは ai-generate.php 側で行うため事前取得不要 / P1-6b）
            document.getElementById('ai-suggest-content').innerHTML = '<p>AI提案用データを収集中...</p>';
            apiCall('GET', 'ai-suggest-data.php?store_id=' + self.storeId + '&start_date=' + startDate + '&end_date=' + endDate, null, function(suggestData, err) {
                if (err) {
                    document.getElementById('ai-suggest-content').innerHTML = '<p class="error">データ取得に失敗しました: ' + esc(err.message || '') + '</p>';
                    return;
                }

                // 2. Geminiプロンプト構築
                document.getElementById('ai-suggest-content').innerHTML = '<p>AIが最適なシフトを提案中...</p>';
                var prompt = self._buildAiPrompt(suggestData, startDate, endDate);

                callGemini(prompt, 4096, function(responseText) {
                    // 3. JSON解析
                    var jsonStr = extractJson(responseText);
                    try {
                        var result = JSON.parse(jsonStr);
                    } catch (e) {
                        document.getElementById('ai-suggest-content').innerHTML = '<p class="error">AIの応答をパースできませんでした。再試行してください。</p>' +
                            '<details><summary>生データ</summary><pre class="cal-ai-raw">' + esc(responseText) + '</pre></details>';
                        return;
                    }
                    self._renderAiSuggestResult(overlay, result, suggestData, startDate, endDate);
                }, function(errMsg) {
                    document.getElementById('ai-suggest-content').innerHTML = '<p class="error">AI提案エラー: ' + esc(errMsg) + '</p>';
                });
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

            prompt += '## 店舗の持ち場\n';
            prompt += JSON.stringify(data.positions || [{ code: 'hall', label: 'ホール' }, { code: 'kitchen', label: 'キッチン' }]) + '\n\n';

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
            prompt += '{"suggestions":[{"user_id":"xxx","shift_date":"YYYY-MM-DD","start_time":"HH:MM","end_time":"HH:MM","role_type":"持ち場codeまたはnull","reason":"理由"}],';
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
                html += '<div class="cal-ai-summary">' + esc(summary) + '</div>';
            }

            // 推定人件費
            if (estCost > 0) {
                html += '<div class="cal-ai-cost">推定週間人件費: <strong>¥' + estCost.toLocaleString() + '</strong></div>';
            }

            // 警告
            for (var w = 0; w < warnings.length; w++) {
                html += '<div class="cal-ai-warn">⚠ ' + esc(warnings[w]) + '</div>';
            }

            // 提案テーブル
            if (suggestions.length > 0) {
                html += '<table class="shift-table cal-ai-table"><thead><tr>';
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
                    html += '<td class="cal-ai-reason">' + esc(sg.reason || '') + '</td>';
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
                applyBtn.hidden = false;
                applyBtn.addEventListener('click', function() {
                    applyBtn.disabled = true;
                    applyBtn.textContent = '登録中...';
                    self._applyAiSuggestions(overlay, suggestions);
                });
            }
        },

        _applyAiSuggestions: function(overlay, suggestions) {
            var self = this;

            // 既存 AI 提案の削除を伴うため確認
            var msg = 'AI提案 ' + suggestions.length + ' 件を適用します。\n' +
                      '同期間の既存AI提案は削除されます（手動作成分は維持）。\nよろしいですか？';
            if (!confirm(msg)) {
                var applyBtn = document.getElementById('ai-suggest-apply');
                if (applyBtn) {
                    applyBtn.disabled = false;
                    applyBtn.textContent = '全て採用';
                }
                return;
            }

            // 期間計算（suggestions の shift_date から min/max を取得）
            var dates = suggestions.map(function(s) { return s.shift_date; }).sort();
            var startDate = dates[0];
            var endDate = dates[dates.length - 1];

            var payload = {
                store_id: self.storeId,
                start_date: startDate,
                end_date: endDate,
                suggestions: suggestions,
                replace_existing: true
            };

            apiCall('POST', 'apply-ai-suggestions.php?store_id=' + encodeURIComponent(self.storeId), payload, function(result, err) {
                if (err) {
                    alert('AI提案の適用に失敗しました: ' + (err.message || '不明なエラー'));
                    var applyBtn = document.getElementById('ai-suggest-apply');
                    if (applyBtn) {
                        applyBtn.disabled = false;
                        applyBtn.textContent = '全て採用';
                    }
                    return;
                }
                alert('AI提案を適用しました（削除 ' + (result.deleted || 0) + ' 件 / 新規 ' + (result.inserted || 0) + ' 件）');
                if (overlay && overlay.parentNode) {
                    document.body.removeChild(overlay);
                }
                self.loadWeek();
            });
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
