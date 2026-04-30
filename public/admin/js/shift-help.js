/**
 * shift-help.js — 店舗間ヘルプ要請モジュール（L-3 Phase 3）
 *
 * マネージャー用: ヘルプ要請の作成・一覧・承認・却下・キャンセル
 * α-1 では本部一括メニュー配信以外の機能は全契約者に標準提供
 *
 * ES5 IIFE パターン
 */
var ShiftHelp = (function() {
    'use strict';

    var API_BASE = '/api/store/shift/';
    var _containerId = null;
    var _storeId = null;
    var _otherStores = [];
    var _positions = [];
    var _activeTab = 'sent'; // 'sent' | 'received'

    function esc(str) {
        if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(str);
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function roleLabel(code) {
        if (!code) return '指定なし';
        for (var i = 0; i < _positions.length; i++) {
            if (_positions[i].code === code) {
                return _positions[i].label || _positions[i].code;
            }
        }
        if (code === 'hall') return 'ホール';
        if (code === 'kitchen') return 'キッチン';
        return code;
    }

    function apiCall(method, url, body, cb) {
        var opts = { method: method, headers: { 'Content-Type': 'application/json' } };
        if (body && method !== 'GET') opts.body = JSON.stringify(body);
        fetch(url, opts)
            .then(function(res) { return res.text(); })
            .then(function(text) {
                var json = JSON.parse(text);
                if (!json.ok) { cb(null, json.error || { message: 'Unknown error' }); return; }
                cb(json.data);
            })
            .catch(function(err) { cb(null, { message: err.message }); });
    }

    function notify(message, type) {
        if (typeof showToast === 'function') {
            showToast(message, type || 'info');
        } else {
            alert(message);
        }
    }

    function todayString() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    function attendanceFollowupLabel(status) {
        if (status === 'contacted') return '連絡済み';
        if (status === 'late_notice') return '遅刻連絡あり';
        if (status === 'absent') return '欠勤処理済み';
        return '';
    }

    // ── 初期化 ──
    function init(containerId, storeId) {
        _containerId = containerId;
        _storeId = storeId;
        _activeTab = 'sent';

        // 他店舗リスト取得
        apiCall('GET', API_BASE + 'help-requests.php?action=list-stores&store_id=' + encodeURIComponent(storeId), null, function(data, err) {
            if (data && data.stores) {
                _otherStores = data.stores;
            }
            apiCall('GET', API_BASE + 'positions.php?store_id=' + encodeURIComponent(storeId), null, function(posData) {
                _positions = posData && posData.positions ? posData.positions : [
                    { code: 'hall', label: 'ホール', is_active: 1 },
                    { code: 'kitchen', label: 'キッチン', is_active: 1 }
                ];
                loadRequests();
            });
        });
    }

    // ── 要請一覧取得 ──
    function loadRequests() {
        var container = document.getElementById(_containerId);
        if (!container) return;
        container.innerHTML = '<p class="help-loading">読み込み中...</p>';

        apiCall('GET', API_BASE + 'help-requests.php?store_id=' + encodeURIComponent(_storeId), null, function(data, err) {
            if (err) {
                container.innerHTML = '<p class="help-error">ヘルプ要請の取得に失敗しました: ' + esc(err.message || '') + '</p>';
                return;
            }
            apiCall('GET', API_BASE + 'today-status.php?store_id=' + encodeURIComponent(_storeId), null, function(todayData) {
                apiCall('GET', API_BASE + 'swap-requests.php?store_id=' + encodeURIComponent(_storeId), null, function(swapData) {
                    apiCall('GET', API_BASE + 'field-ops.php?action=dashboard&store_id=' + encodeURIComponent(_storeId), null, function(fieldOps) {
                        _renderRequests(data.sent || [], data.received || [], todayData || null, (swapData && swapData.requests) ? swapData.requests : [], fieldOps || null);
                    });
                });
            });
        });
    }

    // ── 一覧描画 ──
    function _renderRequests(sent, received, todayData, swapRequests, fieldOps) {
        var container = document.getElementById(_containerId);
        if (!container) return;

        var html = '<div class="shift-section-header">' +
            '<h3>店舗間ヘルプ要請</h3>' +
            '<button class="btn btn-sm btn-primary" id="help-create-btn">+ 新規要請</button>' +
            '</div>';

        html += _buildManagerCheck(todayData, swapRequests || [], fieldOps);
        html += _buildTodayStatus(todayData);
        html += _buildFieldOps(fieldOps);
        html += _buildSwapRequests(swapRequests || []);

        // 送信/受信タブ
        html += '<div class="help-tabs">' +
            '<button class="btn btn-sm help-tab-btn' + (_activeTab === 'sent' ? ' btn-primary' : '') + '" data-help-tab="sent">送信 (' + sent.length + ')</button>' +
            '<button class="btn btn-sm help-tab-btn' + (_activeTab === 'received' ? ' btn-primary' : '') + '" data-help-tab="received">受信 (' + received.length + ')</button>' +
            '</div>';

        // 送信一覧
        html += '<div class="help-tab-content" id="help-tab-sent"' + (_activeTab === 'sent' ? '' : ' hidden') + '>';
        if (sent.length === 0) {
            html += '<p class="help-empty">送信した要請はありません</p>';
        } else {
            html += _buildTable(sent, 'sent');
        }
        html += '</div>';

        // 受信一覧
        html += '<div class="help-tab-content" id="help-tab-received"' + (_activeTab === 'received' ? '' : ' hidden') + '>';
        if (received.length === 0) {
            html += '<p class="help-empty">受信した要請はありません</p>';
        } else {
            html += _buildTable(received, 'received');
        }
        html += '</div>';

        container.innerHTML = html;

        // イベントバインド
        // タブ切替
        var tabBtns = container.querySelectorAll('.help-tab-btn');
        for (var t = 0; t < tabBtns.length; t++) {
            tabBtns[t].addEventListener('click', function() {
                _activeTab = this.getAttribute('data-help-tab');
                var allTabs = container.querySelectorAll('.help-tab-btn');
                for (var i = 0; i < allTabs.length; i++) {
                    allTabs[i].classList.toggle('btn-primary', allTabs[i].getAttribute('data-help-tab') === _activeTab);
                }
                document.getElementById('help-tab-sent').hidden = (_activeTab !== 'sent');
                document.getElementById('help-tab-received').hidden = (_activeTab !== 'received');
            });
        }

        // 新規要請ボタン
        var createBtn = document.getElementById('help-create-btn');
        if (createBtn) {
            createBtn.addEventListener('click', function() { _showCreateDialog(); });
        }

        // 操作ボタン
        var actionBtns = container.querySelectorAll('[data-help-action]');
        for (var a = 0; a < actionBtns.length; a++) {
            actionBtns[a].addEventListener('click', function() {
                var act = this.getAttribute('data-help-action');
                var reqId = this.getAttribute('data-request-id');
                if (act === 'approve') _approveRequest(reqId);
                else if (act === 'reject') _rejectRequest(reqId);
                else if (act === 'cancel') _cancelRequest(reqId);
            });
        }

        var swapBtns = container.querySelectorAll('[data-swap-action]');
        for (var sw = 0; sw < swapBtns.length; sw++) {
            swapBtns[sw].addEventListener('click', function() {
                var act = this.getAttribute('data-swap-action');
                var reqId = this.getAttribute('data-request-id');
                var assignmentId = this.getAttribute('data-assignment-id');
                if (act === 'approve') _approveSwapRequest(
                    reqId,
                    assignmentId,
                    this.getAttribute('data-request-type'),
                    this.getAttribute('data-candidate-id'),
                    this.getAttribute('data-candidate-status'),
                    this.getAttribute('data-candidate-name')
                );
                else if (act === 'reject') _patchSwapRequest(reqId, 'reject');
                else if (act === 'cancel') _patchSwapRequest(reqId, 'cancel');
                else if (act === 'fill') _showAbsenceFillDialog(assignmentId);
                else if (act === 'thread') _showShiftRequestThread(reqId);
            });
        }

        var attendanceBtns = container.querySelectorAll('[data-attendance-action]');
        for (var af = 0; af < attendanceBtns.length; af++) {
            attendanceBtns[af].addEventListener('click', function() {
                var assignmentId = this.getAttribute('data-assignment-id');
                var status = this.getAttribute('data-attendance-status');
                var openFill = this.getAttribute('data-attendance-action') === 'absent-fill';
                _handleAttendanceFollowup(assignmentId, status, openFill);
            });
        }

        _bindFieldOpsEvents(container, fieldOps);
    }

    function _briefNames(rows, nameKey, limit) {
        var names = [];
        for (var i = 0; i < Math.min(rows.length, limit || 3); i++) {
            names.push(rows[i][nameKey] || rows[i].display_name || rows[i].requester_name || rows[i].requester_username || '');
        }
        return names.join('、') + (rows.length > (limit || 3) ? ' 他' + (rows.length - (limit || 3)) + '件' : '');
    }

    function _shortageRows(todayData) {
        var rows = [];
        var coverage = todayData && todayData.coverage ? todayData.coverage : [];
        for (var i = 0; i < coverage.length; i++) {
            if ((coverage[i].shortage_count || 0) > 0) rows.push(coverage[i]);
        }
        return rows;
    }

    function _absenceFillRows(swapRequests) {
        var rows = [];
        for (var i = 0; i < swapRequests.length; i++) {
            if (swapRequests[i].request_type === 'absence' && swapRequests[i].status === 'approved' && !swapRequests[i].replacement_user_id) {
                rows.push(swapRequests[i]);
            }
        }
        return rows;
    }

    function _pendingTaskRows(fieldOps) {
        var rows = [];
        var tasks = fieldOps && fieldOps.task_assignments ? fieldOps.task_assignments : [];
        for (var i = 0; i < tasks.length; i++) {
            if (tasks[i].status === 'pending') rows.push(tasks[i]);
        }
        return rows;
    }

    function _changedShiftRows(fieldOps) {
        var rows = [];
        var assignments = fieldOps && fieldOps.assignments ? fieldOps.assignments : [];
        for (var i = 0; i < assignments.length; i++) {
            if (assignments[i].confirmation_required) rows.push(assignments[i]);
        }
        return rows;
    }

    function _buildManagerCheck(todayData, swapRequests, fieldOps) {
        var items = [];
        var noClock = todayData && todayData.no_clock_in ? todayData.no_clock_in : [];
        var pendingReq = todayData && todayData.pending_requests ? todayData.pending_requests : [];
        var absences = _absenceFillRows(swapRequests || []);
        var shortages = _shortageRows(todayData);
        var breaks = fieldOps && fieldOps.break_plan ? fieldOps.break_plan : [];
        var tasks = _pendingTaskRows(fieldOps);
        var changed = _changedShiftRows(fieldOps);

        if (noClock.length > 0) {
            items.push({ level: 'error', title: '遅刻/未打刻', count: noClock.length, body: _briefNames(noClock, 'display_name', 3), action: '連絡確認' });
        }
        if (pendingReq.length > 0) {
            items.push({ level: 'error', title: '交代・欠勤未対応', count: pendingReq.length, body: _briefNames(pendingReq, 'display_name', 3), action: '承認判断' });
        }
        if (absences.length > 0) {
            items.push({ level: 'error', title: '欠勤穴埋め', count: absences.length, body: _briefNames(absences, 'requester_name', 3), action: '穴埋め', assignment_id: absences[0].shift_assignment_id });
        }
        if (shortages.length > 0) {
            items.push({ level: 'warning', title: '時間帯不足', count: shortages.length, body: shortages[0].time + ' 予定' + shortages[0].scheduled_count + ' / 必要' + shortages[0].required_count, action: '人数確認' });
        }
        if (breaks.length > 0) {
            items.push({ level: 'warning', title: '休憩不足', count: breaks.length, body: _briefNames(breaks, 'display_name', 3), action: '休憩調整' });
        }
        if (tasks.length > 0) {
            items.push({ level: 'info', title: '未完了作業', count: tasks.length, body: _briefNames(tasks, 'display_name', 3), action: '完了確認' });
        }
        if (changed.length > 0) {
            items.push({ level: 'info', title: '変更未確認', count: changed.length, body: _briefNames(changed, 'display_name', 3), action: '声かけ' });
        }

        var html = '<section class="shift-manager-check">' +
            '<div class="shift-manager-check__head"><h4>今日の責任者チェック</h4><span>' + esc((fieldOps && fieldOps.date) || (todayData && todayData.date) || todayString()) + '</span></div>';
        if (items.length === 0) {
            html += '<div class="shift-manager-check__ok">今すぐ見る項目はありません</div></section>';
            return html;
        }
        html += '<div class="shift-manager-check__grid">';
        for (var i = 0; i < items.length; i++) {
            html += '<div class="shift-manager-check__item shift-manager-check__item--' + esc(items[i].level) + '">' +
                '<strong>' + esc(items[i].title) + '<em>' + items[i].count + '</em></strong>' +
                '<span>' + esc(items[i].body || '') + '</span>';
            if (items[i].assignment_id) {
                html += '<button class="btn btn-sm btn-primary" data-swap-action="fill" data-assignment-id="' + esc(items[i].assignment_id) + '">' + esc(items[i].action) + '</button>';
            } else {
                html += '<small>' + esc(items[i].action) + '</small>';
            }
            html += '</div>';
        }
        html += '</div></section>';
        return html;
    }

    function _buildTodayStatus(data) {
        if (!data) return '';
        var m = data.metrics || {};
        var html = '<section class="shift-urgent-panel">' +
            '<div class="shift-urgent-panel__head"><h4>今日の不足</h4>' +
            '<span>未打刻 ' + (m.no_clock_in_count || 0) + ' / 申請 ' + (m.pending_request_count || 0) + ' / 欠勤 ' + (m.approved_absence_count || 0) + ' / 勤務中 ' + (m.working_count || 0) + '</span></div>';
        var hasRows = false;
        if (data.no_clock_in && data.no_clock_in.length > 0) {
            hasRows = true;
            html += '<div class="shift-urgent-panel__block"><strong>開始済み未打刻</strong><div class="shift-attendance-list">';
            for (var i = 0; i < Math.min(data.no_clock_in.length, 5); i++) {
                var row = data.no_clock_in[i];
                var statusLabel = attendanceFollowupLabel(row.followup_status);
                html += '<div class="shift-attendance-row">' +
                    '<div><strong>' + esc(row.display_name) + '</strong><span>' + esc(row.start_time + '-' + row.end_time) + '</span>';
                if (statusLabel) {
                    html += '<em class="shift-followup-badge">' + esc(statusLabel) + (row.followup_by ? ' / ' + esc(row.followup_by) : '') + '</em>';
                }
                html += '</div><div class="shift-attendance-actions">' +
                    '<button class="btn btn-sm" data-attendance-action="followup" data-attendance-status="contacted" data-assignment-id="' + esc(row.assignment_id) + '">連絡済み</button>' +
                    '<button class="btn btn-sm" data-attendance-action="followup" data-attendance-status="late_notice" data-assignment-id="' + esc(row.assignment_id) + '">遅刻連絡あり</button>' +
                    '<button class="btn btn-sm help-cancel-btn" data-attendance-action="followup" data-attendance-status="absent" data-assignment-id="' + esc(row.assignment_id) + '">欠勤にする</button>' +
                    '<button class="btn btn-sm btn-primary" data-attendance-action="absent-fill" data-attendance-status="absent" data-assignment-id="' + esc(row.assignment_id) + '">穴埋め</button>' +
                    '</div></div>';
            }
            html += '</div></div>';
        }
        if (data.pending_requests && data.pending_requests.length > 0) {
            hasRows = true;
            html += '<div class="shift-urgent-panel__block"><strong>未対応の交代/欠勤</strong><ul>';
            for (var pr = 0; pr < Math.min(data.pending_requests.length, 5); pr++) {
                html += '<li>' + esc(data.pending_requests[pr].display_name + ' ' + data.pending_requests[pr].start_time + '-' + data.pending_requests[pr].end_time) + '</li>';
            }
            html += '</ul></div>';
        }
        if (data.approved_absences && data.approved_absences.length > 0) {
            hasRows = true;
            html += '<div class="shift-urgent-panel__block"><strong>承認済み欠勤</strong><ul>';
            for (var ar = 0; ar < Math.min(data.approved_absences.length, 5); ar++) {
                html += '<li>' + esc(data.approved_absences[ar].display_name + ' ' + data.approved_absences[ar].start_time + '-' + data.approved_absences[ar].end_time) + '</li>';
            }
            html += '</ul></div>';
        }
        var shortages = [];
        for (var c = 0; c < (data.coverage || []).length; c++) {
            if ((data.coverage[c].shortage_count || 0) > 0) shortages.push(data.coverage[c]);
        }
        if (shortages.length > 0) {
            hasRows = true;
            html += '<div class="shift-urgent-panel__block"><strong>時間帯不足</strong><ul>';
            for (var s = 0; s < Math.min(shortages.length, 5); s++) {
                html += '<li>' + esc(shortages[s].time) + ' 予定' + shortages[s].scheduled_count + ' / 必要' + shortages[s].required_count + '</li>';
            }
            html += '</ul></div>';
        }
        if (data.callable_candidates && data.callable_candidates.length > 0) {
            hasRows = true;
            html += '<div class="shift-urgent-panel__block"><strong>今日呼べる候補</strong><div class="help-candidate-preview__list">';
            for (var cc = 0; cc < Math.min(data.callable_candidates.length, 6); cc++) {
                html += '<span class="help-candidate-preview__chip">' + esc(data.callable_candidates[cc].display_name) + '<small>' + esc(data.callable_candidates[cc].reason) + '</small></span>';
            }
            html += '</div></div>';
        }
        if (!hasRows) {
            html += '<p class="help-empty">今日の緊急不足はありません。</p>';
        }
        html += '</section>';
        return html;
    }

    function _buildSwapRequests(rows) {
        var pending = [];
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].status === 'pending') pending.push(rows[i]);
        }
        var html = '<section class="shift-swap-panel"><div class="shift-urgent-panel__head"><h4>交代・欠勤申請</h4><span>未対応 ' + pending.length + '件</span></div>';
        if (rows.length === 0) {
            html += '<p class="help-empty">交代・欠勤申請はありません。</p></section>';
            return html;
        }
        html += '<table class="shift-table help-table"><thead><tr><th>日付</th><th>スタッフ</th><th>種別</th><th>候補</th><th>状態</th><th>操作</th></tr></thead><tbody>';
        for (var r = 0; r < Math.min(rows.length, 20); r++) {
            var row = rows[r];
            var type = row.request_type === 'absence' ? '欠勤' : '交代';
            var status = { pending: '未対応', approved: '承認済', rejected: '却下', cancelled: 'キャンセル' }[row.status] || row.status;
            var handledBy = row.responded_by_name || row.responded_by_username || '';
            var statusHtml = esc(status);
            if (row.status !== 'pending' && handledBy) {
                statusHtml += '<br><small>対応: ' + esc(handledBy) + '</small>';
            }
            var candidate = esc(row.replacement_name || row.candidate_name || '-');
            if (row.candidate_user_id) {
                candidate += '<br><small>' + esc(_candidateAcceptanceLabel(row.candidate_acceptance_status)) + '</small>';
            }
            var actions = '';
            var unread = parseInt(row.unread_count || 0, 10);
            actions += '<button class="btn btn-sm" data-swap-action="thread" data-request-id="' + esc(row.id) + '">やり取り' + (unread > 0 ? ' (' + unread + ')' : '') + '</button> ';
            if (row.status === 'pending') {
                actions += '<button class="btn btn-sm btn-primary" data-swap-action="approve" data-request-id="' + esc(row.id) + '" data-assignment-id="' + esc(row.shift_assignment_id) + '" data-request-type="' + esc(row.request_type) + '" data-candidate-id="' + esc(row.candidate_user_id || '') + '" data-candidate-status="' + esc(row.candidate_acceptance_status || '') + '" data-candidate-name="' + esc(row.candidate_name || row.candidate_username || '') + '">承認</button> ' +
                    '<button class="btn btn-sm help-cancel-btn" data-swap-action="reject" data-request-id="' + esc(row.id) + '">却下</button>';
            } else if (row.request_type === 'absence' && row.status === 'approved' && !row.replacement_user_id) {
                actions += '<button class="btn btn-sm btn-primary" data-swap-action="fill" data-request-id="' + esc(row.id) + '" data-assignment-id="' + esc(row.shift_assignment_id) + '">穴埋め</button>';
            }
            html += '<tr><td>' + esc(row.shift_date || '') + '<br>' + esc((row.start_time || '').substring(0, 5)) + '-' + esc((row.end_time || '').substring(0, 5)) + '</td>' +
                '<td>' + esc(row.requester_name || row.requester_username || '') + '</td>' +
                '<td>' + type + '</td><td>' + candidate + '</td><td>' + statusHtml + '</td><td>' + actions + '</td></tr>';
            if (row.reason || row.response_note) {
                html += '<tr class="help-note-row"><td colspan="6">' + esc(row.reason || row.response_note || '') + '</td></tr>';
            }
        }
        html += '</tbody></table></section>';
        return html;
    }

    function _buildFieldOps(data) {
        if (!data) {
            return '<section class="shift-field-ops"><div class="shift-urgent-panel__head"><h4>現場シフト運用</h4><span>取得できませんでした</span></div></section>';
        }
        var html = '<section class="shift-field-ops">' +
            '<div class="shift-urgent-panel__head"><h4>現場シフト運用</h4><span>' + esc(data.date || '') + '</span></div>';
        html += _buildFieldSuggestions(data.action_suggestions || []);
        html += '<div class="shift-field-grid">';
        html += _buildOpenShiftPanel(data);
        html += _buildTaskPanel(data);
        html += _buildBreakLaborPanel(data);
        html += _buildSkillPanel(data);
        html += _buildDeadlineLogPanel(data);
        html += '</div></section>';
        return html;
    }

    function _buildFieldSuggestions(rows) {
        var html = '<div class="shift-action-list">';
        if (!rows || rows.length === 0) {
            html += '<span class="shift-action-list__ok">今すぐの対応候補はありません</span>';
        } else {
            for (var i = 0; i < Math.min(rows.length, 6); i++) {
                html += '<span class="shift-action-list__item shift-action-list__item--' + esc(rows[i].level || 'info') + '">' + esc(rows[i].message || '') + '</span>';
            }
        }
        html += '</div>';
        return html;
    }

    function _skillLabel(data, code) {
        var skills = data && data.skills ? data.skills : [];
        for (var i = 0; i < skills.length; i++) {
            if (skills[i].code === code) return skills[i].label || skills[i].code;
        }
        return code || '-';
    }

    function _staffName(data, userId) {
        var staff = data && data.staff ? data.staff : [];
        for (var i = 0; i < staff.length; i++) {
            if (staff[i].id === userId) return staff[i].display_name || staff[i].username || '';
        }
        return '';
    }

    function _roleOptions(selected) {
        var html = '<option value="">指定なし</option>';
        for (var i = 0; i < _positions.length; i++) {
            if (_positions[i].is_active === 0) continue;
            html += '<option value="' + esc(_positions[i].code) + '"' + (selected === _positions[i].code ? ' selected' : '') + '>' + esc(_positions[i].label || _positions[i].code) + '</option>';
        }
        return html;
    }

    function _skillOptions(data, selected, includeEmpty) {
        var html = includeEmpty ? '<option value="">指定なし</option>' : '';
        var skills = data && data.skills ? data.skills : [];
        for (var i = 0; i < skills.length; i++) {
            if (skills[i].is_active === 0) continue;
            html += '<option value="' + esc(skills[i].code) + '"' + (selected === skills[i].code ? ' selected' : '') + '>' + esc(skills[i].label || skills[i].code) + '</option>';
        }
        return html;
    }

    function _staffOptions(data, selected) {
        var html = '<option value="">選択</option>';
        var staff = data && data.staff ? data.staff : [];
        for (var i = 0; i < staff.length; i++) {
            html += '<option value="' + esc(staff[i].id) + '"' + (selected === staff[i].id ? ' selected' : '') + '>' + esc(staff[i].display_name || staff[i].username) + '</option>';
        }
        return html;
    }

    function _buildOpenShiftPanel(data) {
        var rows = data.open_shifts || [];
        var html = '<div class="shift-field-card shift-field-card--wide">' +
            '<div class="shift-field-card__head"><h5>空きシフト募集</h5><button class="btn btn-sm btn-primary" id="field-open-create">募集作成</button></div>';
        if (rows.length === 0) {
            html += '<p class="help-empty">募集中の空きシフトはありません。</p></div>';
            return html;
        }
        html += '<table class="shift-table shift-field-table"><thead><tr><th>日時</th><th>条件</th><th>応募</th><th>状態</th></tr></thead><tbody>';
        for (var i = 0; i < Math.min(rows.length, 12); i++) {
            var r = rows[i];
            var apps = r.applications || [];
            var appHtml = apps.length === 0 ? '<span class="muted">応募なし</span>' : '';
            for (var a = 0; a < apps.length; a++) {
                var app = apps[a];
                appHtml += '<div class="shift-field-application"><span>' + esc(app.display_name || app.username || '') + ' / ' + esc(app.status) + '</span>';
                if (r.status === 'open' && app.status === 'applied') {
                    appHtml += '<span><button class="btn btn-sm btn-primary" data-open-action="approve" data-application-id="' + esc(app.id) + '">承認</button> ' +
                        '<button class="btn btn-sm help-cancel-btn" data-open-action="reject" data-application-id="' + esc(app.id) + '">却下</button></span>';
                }
                appHtml += '</div>';
            }
            html += '<tr><td>' + esc(r.shift_date) + '<br>' + esc((r.start_time || '').substring(0, 5)) + '-' + esc((r.end_time || '').substring(0, 5)) + '</td>' +
                '<td>' + esc(roleLabel(r.role_type)) + '<br><small>' + esc(r.required_skill_code ? _skillLabel(data, r.required_skill_code) : 'スキル指定なし') + '</small></td>' +
                '<td>' + appHtml + '</td><td>' + esc(r.status) + '</td></tr>';
        }
        html += '</tbody></table></div>';
        return html;
    }

    function _buildTaskPanel(data) {
        var tasks = data.task_assignments || [];
        var html = '<div class="shift-field-card">' +
            '<div class="shift-field-card__head"><h5>今日の担当作業</h5><button class="btn btn-sm btn-primary" id="field-task-add">作業追加</button></div>';
        if (tasks.length === 0) {
            html += '<p class="help-empty">担当作業はありません。</p></div>';
            return html;
        }
        html += '<div class="shift-task-list">';
        for (var i = 0; i < tasks.length; i++) {
            var t = tasks[i];
            var done = t.status === 'done';
            html += '<div class="shift-task-row' + (done ? ' is-done' : '') + '">' +
                '<span><strong>' + esc(t.task_label) + '</strong><small>' + esc(t.display_name || '') + (t.note ? ' / ' + esc(t.note) : '') + '</small></span>' +
                '<button class="btn btn-sm" data-task-action="' + (done ? 'pending' : 'done') + '" data-task-id="' + esc(t.id) + '">' + (done ? '戻す' : '完了') + '</button>' +
                '</div>';
        }
        html += '</div></div>';
        return html;
    }

    function _buildBreakLaborPanel(data) {
        var labor = data.labor_landing || {};
        var laborCostText = (typeof labor.scheduled_labor_cost === 'number') ? labor.scheduled_labor_cost.toLocaleString() + '円' : '未計算';
        var laborRatioText = (typeof labor.labor_cost_ratio === 'number') ? labor.labor_cost_ratio + '%' : '-';
        var html = '<div class="shift-field-card"><div class="shift-field-card__head"><h5>休憩・人件費</h5><span>今日</span></div>';
        html += '<div class="shift-labor-meter">' +
            '<span>売上 ' + (labor.revenue || 0).toLocaleString() + '円</span>' +
            '<span>人件費 ' + laborCostText + '</span>' +
            '<span>率 ' + laborRatioText + ' / 目標 ' + esc(String(labor.target_labor_cost_ratio || 30)) + '%</span>' +
            '</div>';
        var breaks = data.break_plan || [];
        if (breaks.length === 0) {
            html += '<p class="help-empty">休憩不足はありません。</p>';
        } else {
            for (var i = 0; i < Math.min(breaks.length, 6); i++) {
                html += '<div class="shift-break-row"><strong>' + esc(breaks[i].display_name) + '</strong><span>' + breaks[i].break_minutes + '分 / 必要' + breaks[i].required_break_minutes + '分 / 目安 ' + esc(breaks[i].suggested_time) + '</span></div>';
            }
        }
        var risks = data.experience_risks || [];
        if (risks.length > 0) {
            html += '<div class="shift-field-warn">経験者なし: ' + esc(risks[0].time) + (risks.length > 1 ? ' 他' + (risks.length - 1) + '件' : '') + '</div>';
        }
        html += '</div>';
        return html;
    }

    function _buildSkillPanel(data) {
        var skills = data.skills || [];
        var gaps = data.skill_gaps || [];
        var html = '<div class="shift-field-card"><div class="shift-field-card__head"><h5>スキル・資格</h5><button class="btn btn-sm" id="field-skill-add">スキル追加</button></div>';
        html += '<div class="shift-skill-chip-list">';
        for (var i = 0; i < skills.length; i++) {
            if (skills[i].is_active === 0) continue;
            html += '<span class="shift-skill-chip">' + esc(skills[i].label || skills[i].code) + '</span>';
        }
        if (skills.length === 0) html += '<span class="muted">スキル未登録</span>';
        html += '</div>';
        if (gaps.length > 0) {
            html += '<div class="shift-field-warn">';
            for (var g = 0; g < Math.min(gaps.length, 3); g++) {
                html += '<div>' + esc(gaps[g].message) + '</div>';
            }
            html += '</div>';
        }
        html += '<div class="shift-field-mini-form">' +
            '<select id="field-staff-skill-user">' + _staffOptions(data, '') + '</select>' +
            '<select id="field-staff-skill-code">' + _skillOptions(data, '', false) + '</select>' +
            '<button class="btn btn-sm btn-primary" id="field-staff-skill-save">スタッフに付与</button>' +
            '</div>';
        html += '<div class="shift-field-mini-form">' +
            '<select id="field-position-role">' + _roleOptions('') + '</select>' +
            '<select id="field-position-skill">' + _skillOptions(data, '', false) + '</select>' +
            '<input type="number" id="field-position-count" min="0" max="20" value="1">' +
            '<button class="btn btn-sm btn-primary" id="field-position-skill-save">持ち場条件</button>' +
            '</div>';
        html += '</div>';
        return html;
    }

    function _buildDeadlineLogPanel(data) {
        var gaps = data.deadline_gaps || [];
        var logs = data.change_logs || [];
        var html = '<div class="shift-field-card shift-field-card--wide"><div class="shift-field-card__head"><h5>未提出・変更差分</h5><span>7日分</span></div>';
        if (gaps.length === 0) {
            html += '<p class="help-empty">直近の希望未提出はありません。</p>';
        } else {
            html += '<div class="shift-deadline-list">';
            for (var i = 0; i < Math.min(gaps.length, 4); i++) {
                html += '<span>' + esc(gaps[i].target_date) + ' 未提出 ' + gaps[i].missing_count + '人</span>';
            }
            html += '</div>';
        }
        if (logs.length > 0) {
            html += '<div class="shift-change-log">';
            for (var l = 0; l < Math.min(logs.length, 6); l++) {
                html += '<div><strong>' + esc(logs[l].action) + '</strong><span>' + esc(logs[l].username || '') + ' ' + esc(logs[l].created_at || '') + '</span></div>';
            }
            html += '</div>';
        }
        html += '</div>';
        return html;
    }

    function _bindFieldOpsEvents(container, fieldOps) {
        var createOpen = document.getElementById('field-open-create');
        if (createOpen) createOpen.addEventListener('click', function() { _showOpenShiftDialog(fieldOps); });

        var openBtns = container.querySelectorAll('[data-open-action]');
        for (var i = 0; i < openBtns.length; i++) {
            openBtns[i].addEventListener('click', function() {
                _handleOpenApplication(this.getAttribute('data-application-id'), this.getAttribute('data-open-action'));
            });
        }

        var taskAdd = document.getElementById('field-task-add');
        if (taskAdd) taskAdd.addEventListener('click', function() { _showTaskDialog(fieldOps); });

        var taskBtns = container.querySelectorAll('[data-task-action]');
        for (var t = 0; t < taskBtns.length; t++) {
            taskBtns[t].addEventListener('click', function() {
                _patchTaskStatus(this.getAttribute('data-task-id'), this.getAttribute('data-task-action'));
            });
        }

        var skillAdd = document.getElementById('field-skill-add');
        if (skillAdd) skillAdd.addEventListener('click', function() { _showSkillDialog(); });

        var staffSkill = document.getElementById('field-staff-skill-save');
        if (staffSkill) staffSkill.addEventListener('click', function() { _saveStaffSkill(); });

        var posSkill = document.getElementById('field-position-skill-save');
        if (posSkill) posSkill.addEventListener('click', function() { _savePositionSkill(); });
    }

    function _showOpenShiftDialog(data) {
        var overlay = document.createElement('div');
        overlay.className = 'shift-dialog-overlay';
        overlay.innerHTML = '<div class="shift-dialog">' +
            '<h3>空きシフト募集</h3>' +
            '<label>日付<input type="date" id="open-shift-date" class="form-input" value="' + esc(data && data.date ? data.date : todayString()) + '"></label>' +
            '<label>開始<input type="time" id="open-shift-start" class="form-input" value="17:00"></label>' +
            '<label>終了<input type="time" id="open-shift-end" class="form-input" value="22:00"></label>' +
            '<label>休憩<input type="number" id="open-shift-break" class="form-input" min="0" max="240" value="0"></label>' +
            '<label>持ち場<select id="open-shift-role" class="form-input">' + _roleOptions('') + '</select></label>' +
            '<label>必要スキル<select id="open-shift-skill" class="form-input">' + _skillOptions(data, '', true) + '</select></label>' +
            '<label>メモ<input type="text" id="open-shift-note" class="form-input"></label>' +
            '<div class="shift-dialog-actions"><button class="btn btn-primary" id="open-shift-save">作成</button><button class="btn" id="open-shift-cancel">キャンセル</button></div>' +
            '</div>';
        document.body.appendChild(overlay);
        document.getElementById('open-shift-cancel').addEventListener('click', function() { document.body.removeChild(overlay); });
        document.getElementById('open-shift-save').addEventListener('click', function() {
            apiCall('POST', API_BASE + 'field-ops.php?action=create-open-shift&store_id=' + encodeURIComponent(_storeId), {
                shift_date: document.getElementById('open-shift-date').value,
                start_time: document.getElementById('open-shift-start').value,
                end_time: document.getElementById('open-shift-end').value,
                break_minutes: parseInt(document.getElementById('open-shift-break').value || '0', 10),
                role_type: document.getElementById('open-shift-role').value || null,
                required_skill_code: document.getElementById('open-shift-skill').value || null,
                note: document.getElementById('open-shift-note').value
            }, function(result, err) {
                if (err) { notify('募集作成に失敗しました: ' + (err.message || ''), 'error'); return; }
                document.body.removeChild(overlay);
                notify('募集を作成しました', 'success');
                loadRequests();
            });
        });
    }

    function _handleOpenApplication(applicationId, action) {
        var note = '';
        if (action === 'reject') {
            note = prompt('却下理由（任意）', '') || '';
        } else if (!confirm('この応募を承認してシフトを作成しますか？')) {
            return;
        }
        apiCall('PATCH', API_BASE + 'field-ops.php?action=handle-open-shift&store_id=' + encodeURIComponent(_storeId), {
            application_id: applicationId,
            decision: action,
            response_note: note
        }, function(result, err) {
            if (err) { notify('処理に失敗しました: ' + (err.message || ''), 'error'); return; }
            notify(action === 'approve' ? '応募を承認しました' : '応募を却下しました', 'success');
            loadRequests();
        });
    }

    function _showTaskDialog(data) {
        var overlay = document.createElement('div');
        overlay.className = 'shift-dialog-overlay';
        var templates = data && data.task_templates ? data.task_templates : [];
        var tplOptions = '<option value="">直接入力</option>';
        for (var i = 0; i < templates.length; i++) {
            tplOptions += '<option value="' + esc(templates[i].id) + '">' + esc(templates[i].label) + '</option>';
        }
        overlay.innerHTML = '<div class="shift-dialog">' +
            '<h3>担当作業を追加</h3>' +
            '<label>日付<input type="date" id="task-date" class="form-input" value="' + esc(data && data.date ? data.date : todayString()) + '"></label>' +
            '<label>作業<select id="task-template" class="form-input">' + tplOptions + '</select></label>' +
            '<label>作業名<input type="text" id="task-label" class="form-input" placeholder="直接入力時のみ"></label>' +
            '<label>担当<select id="task-user" class="form-input">' + _staffOptions(data, '') + '</select></label>' +
            '<label>メモ<input type="text" id="task-note" class="form-input"></label>' +
            '<div class="shift-dialog-actions"><button class="btn btn-primary" id="task-save">追加</button><button class="btn" id="task-cancel">キャンセル</button></div>' +
            '</div>';
        document.body.appendChild(overlay);
        document.getElementById('task-cancel').addEventListener('click', function() { document.body.removeChild(overlay); });
        document.getElementById('task-save').addEventListener('click', function() {
            apiCall('POST', API_BASE + 'field-ops.php?action=add-task&store_id=' + encodeURIComponent(_storeId), {
                task_date: document.getElementById('task-date').value,
                task_template_id: document.getElementById('task-template').value,
                task_label: document.getElementById('task-label').value,
                user_id: document.getElementById('task-user').value,
                note: document.getElementById('task-note').value
            }, function(result, err) {
                if (err) { notify('作業追加に失敗しました: ' + (err.message || ''), 'error'); return; }
                document.body.removeChild(overlay);
                notify('作業を追加しました', 'success');
                loadRequests();
            });
        });
    }

    function _patchTaskStatus(taskId, status) {
        apiCall('PATCH', API_BASE + 'field-ops.php?action=task-status&store_id=' + encodeURIComponent(_storeId), {
            task_id: taskId,
            status: status
        }, function(result, err) {
            if (err) { notify('作業更新に失敗しました: ' + (err.message || ''), 'error'); return; }
            loadRequests();
        });
    }

    function _handleAttendanceFollowup(assignmentId, status, openFill) {
        var message = status === 'contacted' ? '連絡済みにしました' :
            (status === 'late_notice' ? '遅刻連絡ありにしました' : '欠勤にしました');
        apiCall('POST', API_BASE + 'field-ops.php?action=attendance-followup&store_id=' + encodeURIComponent(_storeId), {
            shift_assignment_id: assignmentId,
            status: status
        }, function(result, err) {
            if (err) {
                notify('未打刻対応に失敗しました: ' + (err.message || ''), 'error');
                return;
            }
            notify(message, 'success');
            if (openFill && result && result.needs_fill) {
                _showAbsenceFillDialog(assignmentId);
                return;
            }
            loadRequests();
        });
    }

    function _showSkillDialog() {
        var code = prompt('スキルコード（半角英数字・例: cashier_close）', '');
        if (code === null) return;
        var label = prompt('表示名', '');
        if (label === null) return;
        apiCall('POST', API_BASE + 'field-ops.php?action=save-skill&store_id=' + encodeURIComponent(_storeId), {
            code: code,
            label: label,
            is_active: 1,
            sort_order: 100
        }, function(result, err) {
            if (err) { notify('スキル保存に失敗しました: ' + (err.message || ''), 'error'); return; }
            notify('スキルを保存しました', 'success');
            loadRequests();
        });
    }

    function _saveStaffSkill() {
        var userId = document.getElementById('field-staff-skill-user').value;
        var skill = document.getElementById('field-staff-skill-code').value;
        if (!userId || !skill) { notify('スタッフとスキルを選択してください', 'error'); return; }
        apiCall('POST', API_BASE + 'field-ops.php?action=assign-staff-skill&store_id=' + encodeURIComponent(_storeId), {
            user_id: userId,
            skill_code: skill,
            level: 1
        }, function(result, err) {
            if (err) { notify('スキル付与に失敗しました: ' + (err.message || ''), 'error'); return; }
            notify('スタッフスキルを保存しました', 'success');
            loadRequests();
        });
    }

    function _savePositionSkill() {
        var role = document.getElementById('field-position-role').value;
        var skill = document.getElementById('field-position-skill').value;
        var count = parseInt(document.getElementById('field-position-count').value || '1', 10);
        if (!role || !skill) { notify('持ち場とスキルを選択してください', 'error'); return; }
        apiCall('POST', API_BASE + 'field-ops.php?action=save-position-skill&store_id=' + encodeURIComponent(_storeId), {
            role_type: role,
            skill_code: skill,
            required_count: count
        }, function(result, err) {
            if (err) { notify('持ち場条件の保存に失敗しました: ' + (err.message || ''), 'error'); return; }
            notify('持ち場条件を保存しました', 'success');
            loadRequests();
        });
    }

    // ── テーブル構築 ──
    function _buildTable(rows, type) {
        var html = '<table class="shift-table help-table"><thead><tr>';
        html += '<th>日付</th><th>時間帯</th>';
        html += (type === 'sent') ? '<th>送信先</th>' : '<th>送信元</th>';
        html += '<th>人数</th><th>役割</th><th>状態</th><th>派遣スタッフ</th><th>操作</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var statusBadge = _statusBadge(r.status);
            var storeName = (type === 'sent') ? esc(r.to_store_name || '') : esc(r.from_store_name || '');
            var roleText = r.role_hint ? esc(roleLabel(r.role_hint)) : '-';

            // 派遣スタッフ
            var staffNames = '';
            if (r.assigned_staff && r.assigned_staff.length > 0) {
                var names = [];
                for (var s = 0; s < r.assigned_staff.length; s++) {
                    names.push(esc(r.assigned_staff[s].display_name || r.assigned_staff[s].username));
                }
                staffNames = names.join(', ');
            }

            // 操作ボタン
            var actions = '';
            if (type === 'sent' && r.status === 'pending') {
                actions = '<button class="btn btn-sm help-cancel-btn" data-help-action="cancel" data-request-id="' + esc(r.id) + '">キャンセル</button>';
            } else if (type === 'received' && r.status === 'pending') {
                actions = '<button class="btn btn-sm btn-primary" data-help-action="approve" data-request-id="' + esc(r.id) + '">承認</button> ' +
                    '<button class="btn btn-sm help-cancel-btn" data-help-action="reject" data-request-id="' + esc(r.id) + '">却下</button>';
            }

            html += '<tr>' +
                '<td>' + esc(r.requested_date || '') + '</td>' +
                '<td>' + esc((r.start_time || '').substring(0, 5)) + '-' + esc((r.end_time || '').substring(0, 5)) + '</td>' +
                '<td>' + storeName + '</td>' +
                '<td>' + r.requested_staff_count + '名</td>' +
                '<td>' + roleText + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + (staffNames || '-') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';

            // メモ行（あれば）
            if (r.note || r.response_note) {
                html += '<tr class="help-note-row"><td colspan="8">';
                if (r.note) html += '依頼メモ: ' + esc(r.note);
                if (r.note && r.response_note) html += ' / ';
                if (r.response_note) html += '回答: ' + esc(r.response_note);
                html += '</td></tr>';
            }
        }

        html += '</tbody></table>';
        return html;
    }

    // ── ステータスバッジ ──
    function _statusBadge(status) {
        var labels = { pending: '待機中', approved: '承認済', rejected: '却下', cancelled: 'キャンセル' };
        var l = labels[status] || status;
        var modifier = (labels[status]) ? status : 'pending';
        return '<span class="shift-status-badge shift-status-badge--' + modifier + '">' + esc(l) + '</span>';
    }

    // ── 新規要請ダイアログ ──
    function _showCreateDialog(prefill) {
        var overlay = document.createElement('div');
        overlay.className = 'shift-dialog-overlay';

        // 店舗オプション
        var storeOpts = '';
        for (var i = 0; i < _otherStores.length; i++) {
            storeOpts += '<option value="' + esc(_otherStores[i].id) + '">' + esc(_otherStores[i].name) + '</option>';
        }

        // デフォルト日付: 明日
        var tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        var defDate = tomorrow.getFullYear() + '-' + ('0' + (tomorrow.getMonth() + 1)).slice(-2) + '-' + ('0' + tomorrow.getDate()).slice(-2);
        if (prefill && prefill.requested_date) {
            defDate = prefill.requested_date;
        }
        var defStart = prefill && prefill.start_time ? String(prefill.start_time).substring(0, 5) : '17:00';
        var defEnd = prefill && prefill.end_time ? String(prefill.end_time).substring(0, 5) : '22:00';
        var defRole = prefill && prefill.role_hint ? prefill.role_hint : '';
        var defNote = prefill && prefill.note ? prefill.note : '';
        var roleOptions = '<option value="">指定なし</option>';
        for (var rp = 0; rp < _positions.length; rp++) {
            if (_positions[rp].is_active === 0) continue;
            roleOptions += '<option value="' + esc(_positions[rp].code) + '"' + (_positions[rp].code === defRole ? ' selected' : '') + '>' + esc(_positions[rp].label || _positions[rp].code) + '</option>';
        }

        overlay.innerHTML = '<div class="shift-dialog">' +
            '<h3>ヘルプ要請</h3>' +
            '<label>送信先店舗<select id="help-to-store" class="form-input">' +
            '<option value="">-- 選択 --</option>' + storeOpts + '</select></label>' +
            '<label>日付<input type="date" id="help-date" class="form-input" value="' + esc(defDate) + '"></label>' +
            '<label>開始時刻<input type="time" id="help-start" class="form-input" value="' + esc(defStart) + '"></label>' +
            '<label>終了時刻<input type="time" id="help-end" class="form-input" value="' + esc(defEnd) + '"></label>' +
            '<label>必要人数<input type="number" id="help-count" class="form-input" min="1" max="10" value="1"></label>' +
            '<label>持ち場<select id="help-role" class="form-input">' + roleOptions + '</select></label>' +
            '<div id="help-candidate-preview" class="help-candidate-preview">送信先・日時を選ぶと候補スタッフを表示します。</div>' +
            '<label>メモ<input type="text" id="help-note" class="form-input" placeholder="要請理由など" value="' + esc(defNote) + '"></label>' +
            '<div class="shift-dialog-actions">' +
            '<button class="btn btn-primary" id="help-send-btn">送信</button>' +
            '<button class="btn" id="help-cancel-btn">キャンセル</button>' +
            '</div></div>';

        document.body.appendChild(overlay);

        document.getElementById('help-cancel-btn').addEventListener('click', function() {
            document.body.removeChild(overlay);
        });

        function refreshCandidates() {
            _loadCreateCandidates(overlay);
        }

        document.getElementById('help-to-store').addEventListener('change', refreshCandidates);
        document.getElementById('help-date').addEventListener('change', refreshCandidates);
        document.getElementById('help-start').addEventListener('change', refreshCandidates);
        document.getElementById('help-end').addEventListener('change', refreshCandidates);
        document.getElementById('help-role').addEventListener('change', refreshCandidates);
        refreshCandidates();

        document.getElementById('help-send-btn').addEventListener('click', function() {
            var toStore = document.getElementById('help-to-store').value;
            if (!toStore) {
                if (typeof showToast === 'function') showToast('送信先店舗を選択してください', 'error');
                return;
            }

            var data = {
                store_id: _storeId,
                to_store_id: toStore,
                requested_date: document.getElementById('help-date').value,
                start_time: document.getElementById('help-start').value,
                end_time: document.getElementById('help-end').value,
                requested_staff_count: parseInt(document.getElementById('help-count').value, 10),
                role_hint: document.getElementById('help-role').value || null,
                note: document.getElementById('help-note').value
            };

            apiCall('POST', API_BASE + 'help-requests.php?store_id=' + encodeURIComponent(_storeId), data, function(result, err) {
                if (err) {
                    if (typeof showToast === 'function') showToast('要請の送信に失敗しました: ' + (err.message || ''), 'error');
                    return;
                }
                document.body.removeChild(overlay);
                if (typeof showToast === 'function') showToast('ヘルプ要請を送信しました', 'success');
                loadRequests();
            });
        });
    }

    // ── ヘルプ送信前の候補スタッフ表示 ──
    function _loadCreateCandidates(overlay) {
        var box = overlay.querySelector('#help-candidate-preview');
        if (!box) return;

        var toStore = overlay.querySelector('#help-to-store').value;
        var date = overlay.querySelector('#help-date').value;
        var start = overlay.querySelector('#help-start').value;
        var end = overlay.querySelector('#help-end').value;
        var role = overlay.querySelector('#help-role').value;

        if (!toStore || !date || !start || !end) {
            box.innerHTML = '送信先・日時を選ぶと候補スタッフを表示します。';
            return;
        }
        if (start >= end) {
            box.innerHTML = '<span class="help-candidate-preview__warn">終了時刻は開始時刻より後にしてください。</span>';
            return;
        }

        box.innerHTML = '候補を確認中...';
        apiCall('GET', API_BASE + 'help-requests.php?action=candidates&store_id=' + encodeURIComponent(_storeId) +
            '&target_store_id=' + encodeURIComponent(toStore) +
            '&requested_date=' + encodeURIComponent(date) +
            '&start_time=' + encodeURIComponent(start) +
            '&end_time=' + encodeURIComponent(end) +
            '&role_hint=' + encodeURIComponent(role), null, function(data, err) {
            if (err) {
                box.innerHTML = '<span class="help-candidate-preview__warn">候補を取得できませんでした。</span>';
                return;
            }
            _renderCandidatePreview(box, data.candidates || [], data.excluded || []);
        });
    }

    function _candidateAvailabilityLabel(v) {
        if (v === 'preferred') return '希望';
        if (v === 'available') return '出勤可';
        if (v === 'unavailable') return '不可';
        return '未提出';
    }

    function _candidateAcceptanceLabel(v) {
        if (v === 'pending') return '本人承諾待ち';
        if (v === 'accepted') return '本人承諾済';
        if (v === 'declined') return '本人辞退';
        return '承諾不要';
    }

    function _renderCandidatePreview(box, candidates, excluded) {
        var html = '<div class="help-candidate-preview__title">候補スタッフ</div>';
        if (candidates.length === 0) {
            html += '<div class="help-candidate-preview__empty">条件に合う候補がいません。時間帯または送信先店舗を変えて確認してください。</div>';
        } else {
            html += '<div class="help-candidate-preview__list">';
            for (var i = 0; i < Math.min(candidates.length, 6); i++) {
                var c = candidates[i];
                var time = '';
                if (c.preferred_start && c.preferred_end) {
                    time = ' ' + String(c.preferred_start).substring(0, 5) + '-' + String(c.preferred_end).substring(0, 5);
                }
                html += '<span class="help-candidate-preview__chip">' +
                    esc(c.display_name || '') +
                    '<small>' + esc(_candidateAvailabilityLabel(c.availability) + time + (c.role_match ? '' : ' / 役割要確認')) + '</small>' +
                    '</span>';
            }
            html += '</div>';
        }
        if (excluded.length > 0) {
            html += '<details class="help-candidate-preview__excluded"><summary>除外 ' + excluded.length + '人</summary>';
            for (var e = 0; e < Math.min(excluded.length, 8); e++) {
                html += '<div>' + esc(excluded[e].display_name || '') + ': ' + esc(excluded[e].reason || '') + '</div>';
            }
            html += '</details>';
        }
        box.innerHTML = html;
    }

    // ── 承認ダイアログ ──
    function _approveRequest(requestId) {
        // to_store のスタッフ一覧を取得
        apiCall('GET', API_BASE + 'help-requests.php?action=list-staff&store_id=' + encodeURIComponent(_storeId) + '&target_store_id=' + encodeURIComponent(_storeId), null, function(data, err) {
            if (err) {
                if (typeof showToast === 'function') showToast('スタッフ一覧の取得に失敗しました', 'error');
                return;
            }
            var staffList = (data && data.staff) ? data.staff : [];

            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';

            var staffHtml = '';
            if (staffList.length === 0) {
                staffHtml = '<p class="help-empty-inline">この店舗にスタッフが登録されていません</p>';
            } else {
                for (var i = 0; i < staffList.length; i++) {
                    var s = staffList[i];
                    staffHtml += '<label class="help-staff-label">' +
                        '<input type="checkbox" class="help-staff-cb" value="' + esc(s.id) + '"> ' +
                        esc(s.display_name || s.username) +
                        '</label>';
                }
            }

            overlay.innerHTML = '<div class="shift-dialog">' +
                '<h3>ヘルプ要請の承認</h3>' +
                '<p>派遣するスタッフを選択してください:</p>' +
                '<div class="help-staff-list">' + staffHtml + '</div>' +
                '<label>回答メモ<input type="text" id="help-approve-note" class="form-input" placeholder="任意"></label>' +
                '<div class="shift-dialog-actions">' +
                '<button class="btn btn-primary" id="help-approve-btn">承認</button>' +
                '<button class="btn" id="help-approve-cancel">キャンセル</button>' +
                '</div></div>';

            document.body.appendChild(overlay);

            document.getElementById('help-approve-cancel').addEventListener('click', function() {
                document.body.removeChild(overlay);
            });

            document.getElementById('help-approve-btn').addEventListener('click', function() {
                var checks = overlay.querySelectorAll('.help-staff-cb:checked');
                var userIds = [];
                for (var c = 0; c < checks.length; c++) {
                    userIds.push(checks[c].value);
                }
                if (userIds.length === 0) {
                    if (typeof showToast === 'function') showToast('スタッフを選択してください', 'error');
                    return;
                }

                var patchData = {
                    store_id: _storeId,
                    action: 'approve',
                    assigned_user_ids: userIds,
                    response_note: document.getElementById('help-approve-note').value
                };

                apiCall('PATCH', API_BASE + 'help-requests.php?id=' + encodeURIComponent(requestId) + '&store_id=' + encodeURIComponent(_storeId), patchData, function(result, err) {
                    if (err) {
                        if (typeof showToast === 'function') showToast('承認に失敗しました: ' + (err.message || ''), 'error');
                        return;
                    }
                    document.body.removeChild(overlay);
                    // 警告表示
                    if (result && result.warnings && result.warnings.length > 0) {
                        if (typeof showToast === 'function') showToast('承認しました（警告: ' + result.warnings.join(' / ') + '）', 'success');
                        if (window.console && console.warn) console.warn('Help approve warnings:', result.warnings);
                    } else {
                        if (typeof showToast === 'function') showToast('承認しました', 'success');
                    }
                    loadRequests();
                });
            });
        });
    }

    // ── 却下ダイアログ ──
    function _rejectRequest(requestId) {
        var overlay = document.createElement('div');
        overlay.className = 'shift-dialog-overlay';

        overlay.innerHTML = '<div class="shift-dialog">' +
            '<h3>ヘルプ要請の却下</h3>' +
            '<label>却下理由<input type="text" id="help-reject-note" class="form-input" placeholder="理由を入力"></label>' +
            '<div class="shift-dialog-actions">' +
            '<button class="btn help-reject-btn" id="help-reject-btn">却下</button>' +
            '<button class="btn" id="help-reject-cancel">キャンセル</button>' +
            '</div></div>';

        document.body.appendChild(overlay);

        document.getElementById('help-reject-cancel').addEventListener('click', function() {
            document.body.removeChild(overlay);
        });

        document.getElementById('help-reject-btn').addEventListener('click', function() {
            var patchData = {
                store_id: _storeId,
                action: 'reject',
                response_note: document.getElementById('help-reject-note').value
            };

            apiCall('PATCH', API_BASE + 'help-requests.php?id=' + encodeURIComponent(requestId) + '&store_id=' + encodeURIComponent(_storeId), patchData, function(result, err) {
                if (err) {
                    if (typeof showToast === 'function') showToast('却下に失敗しました: ' + (err.message || ''), 'error');
                    return;
                }
                document.body.removeChild(overlay);
                if (typeof showToast === 'function') showToast('却下しました');
                loadRequests();
            });
        });
    }

    // ── キャンセル ──
    function _cancelRequest(requestId) {
        if (!confirm('このヘルプ要請をキャンセルしますか？')) return;

        var patchData = {
            store_id: _storeId,
            action: 'cancel'
        };

        apiCall('PATCH', API_BASE + 'help-requests.php?id=' + encodeURIComponent(requestId) + '&store_id=' + encodeURIComponent(_storeId), patchData, function(result, err) {
            if (err) {
                if (typeof showToast === 'function') showToast('キャンセルに失敗しました: ' + (err.message || ''), 'error');
                return;
            }
            if (typeof showToast === 'function') showToast('キャンセルしました');
            loadRequests();
        });
    }

    function _approveSwapRequest(requestId, assignmentId, requestType, candidateId, candidateStatus, candidateName) {
        var overlay = document.createElement('div');
        overlay.className = 'shift-dialog-overlay';
        var canReplace = candidateId && candidateStatus === 'accepted';
        var replacementHtml = '<p>本人承諾済みの候補だけ、承認と同時にシフト担当者へ差し替えできます。</p>';
        if (canReplace) {
            replacementHtml += '<label class="shift-swap-candidate"><input type="radio" name="swap-replacement" value="' + esc(candidateId) + '" checked> ' +
                esc(candidateName || '承諾済み候補') + ' <small>本人承諾済</small></label>';
            replacementHtml += '<label class="shift-swap-candidate"><input type="radio" name="swap-replacement" value=""> 交代なしで承認</label>';
        } else if (requestType === 'absence') {
            replacementHtml += '<label class="shift-swap-candidate"><input type="radio" name="swap-replacement" value="" checked> 交代なしで欠勤を承認</label>';
            if (candidateId) {
                replacementHtml += '<p class="help-empty-inline">候補状態: ' + esc(_candidateAcceptanceLabel(candidateStatus)) + '</p>';
            }
        } else {
            replacementHtml += '<p class="help-error">交代承認には候補スタッフ本人の承諾が必要です。先に「やり取り」で確認するか、候補の回答を待ってください。</p>';
        }
        overlay.innerHTML = '<div class="shift-dialog">' +
            '<h3>交代・欠勤申請の承認</h3>' +
            '<div id="swap-approve-candidates">' + replacementHtml + '</div>' +
            '<label>対応メモ<input type="text" id="swap-approve-note" class="form-input" placeholder="任意"></label>' +
            '<div class="shift-dialog-actions">' +
            '<button class="btn btn-primary" id="swap-approve-go"' + (!canReplace && requestType === 'swap' ? ' disabled' : '') + '>承認</button>' +
            '<button class="btn" id="swap-approve-cancel">キャンセル</button>' +
            '</div></div>';
        document.body.appendChild(overlay);

        document.getElementById('swap-approve-cancel').addEventListener('click', function() {
            document.body.removeChild(overlay);
        });

        document.getElementById('swap-approve-go').addEventListener('click', function() {
            var checked = overlay.querySelector('input[name="swap-replacement"]:checked');
            apiCall('PATCH', API_BASE + 'swap-requests.php?id=' + encodeURIComponent(requestId) + '&store_id=' + encodeURIComponent(_storeId), {
                action: 'approve',
                replacement_user_id: checked ? checked.value : '',
                response_note: document.getElementById('swap-approve-note').value
            }, function(data, err) {
                if (err) {
                    if (typeof showToast === 'function') showToast('承認に失敗しました: ' + (err.message || ''), 'error');
                    return;
                }
                document.body.removeChild(overlay);
                if (typeof showToast === 'function') showToast('承認しました', 'success');
                if (data && data.needs_fill && data.assignment) {
                    _showAbsenceFillDialog(data.assignment.id);
                } else {
                    loadRequests();
                }
            });
        });
    }

    function _showAbsenceFillDialog(assignmentId) {
        var overlay = document.createElement('div');
        overlay.className = 'shift-dialog-overlay';
        overlay.innerHTML = '<div class="shift-dialog shift-dialog--wide">' +
            '<h3>欠勤の穴埋め</h3>' +
            '<div id="absence-fill-body">候補を確認中...</div>' +
            '<div class="shift-dialog-actions">' +
            '<button class="btn" id="absence-fill-close">閉じる</button>' +
            '</div></div>';
        document.body.appendChild(overlay);

        document.getElementById('absence-fill-close').addEventListener('click', function() {
            document.body.removeChild(overlay);
            loadRequests();
        });

        apiCall('GET', API_BASE + 'swap-requests.php?action=candidates&store_id=' + encodeURIComponent(_storeId) +
            '&assignment_id=' + encodeURIComponent(assignmentId), null, function(data, err) {
            var box = document.getElementById('absence-fill-body');
            if (!box) return;
            if (err) {
                box.innerHTML = '<p class="help-error">候補を取得できませんでした。</p>';
                return;
            }
            var assignment = data.assignment || {};
            var html = '<p class="shift-fill-summary">' + esc(assignment.shift_date || '') + ' ' +
                esc((assignment.start_time || '').substring(0, 5)) + '-' +
                esc((assignment.end_time || '').substring(0, 5)) + ' / ' +
                esc(roleLabel(assignment.role_type)) + '</p>';
            var candidates = data.candidates || [];
            if (candidates.length === 0) {
                html += '<p class="help-empty">同店舗ですぐ入れそうな候補はいません。</p>';
            } else {
                html += '<div class="help-candidate-preview__list">';
                for (var i = 0; i < Math.min(candidates.length, 8); i++) {
                    var c = candidates[i];
                    var av = c.availability === 'preferred' ? '希望' : (c.availability === 'available' ? '出勤可' : '未提出');
                    html += '<span class="help-candidate-preview__chip">' + esc(c.display_name) +
                        '<small>' + esc(av + (c.role_match ? '' : ' / 持ち場要確認')) + '</small></span>';
                }
                html += '</div>';
            }
            html += '<div class="shift-fill-actions">' +
                '<button class="btn btn-primary" id="absence-fill-open-shift">空きシフト募集を作成</button>' +
                '<button class="btn" id="absence-fill-help">他店舗ヘルプ要請</button>' +
                '</div>';
            box.innerHTML = html;

            document.getElementById('absence-fill-open-shift').addEventListener('click', function() {
                apiCall('POST', API_BASE + 'field-ops.php?action=create-open-shift&store_id=' + encodeURIComponent(_storeId), {
                    shift_date: assignment.shift_date,
                    start_time: (assignment.start_time || '').substring(0, 5),
                    end_time: (assignment.end_time || '').substring(0, 5),
                    break_minutes: 0,
                    role_type: assignment.role_type || null,
                    required_skill_code: null,
                    note: '欠勤穴埋め'
                }, function(result, createErr) {
                    if (createErr) {
                        notify('募集作成に失敗しました: ' + (createErr.message || ''), 'error');
                        return;
                    }
                    notify('空きシフト募集を作成しました', 'success');
                    document.body.removeChild(overlay);
                    loadRequests();
                });
            });

            document.getElementById('absence-fill-help').addEventListener('click', function() {
                document.body.removeChild(overlay);
                _showCreateDialog({
                    requested_date: assignment.shift_date,
                    start_time: assignment.start_time,
                    end_time: assignment.end_time,
                    role_hint: assignment.role_type,
                    note: '欠勤穴埋めのヘルプ要請'
                });
            });
        });
    }

    function _showShiftRequestThread(requestId) {
        var overlay = document.createElement('div');
        overlay.className = 'shift-dialog-overlay';
        overlay.innerHTML = '<div class="shift-dialog shift-dialog--wide">' +
            '<h3>申請メッセージ</h3>' +
            '<div id="help-request-thread-body" class="shift-request-thread">読み込み中...</div>' +
            '<label>メッセージ<textarea id="help-request-thread-message" rows="3" placeholder="スタッフへの確認事項を入力"></textarea></label>' +
            '<div class="shift-dialog-actions">' +
            '<button class="btn btn-primary" id="help-request-thread-send">送信</button>' +
            '<button class="btn" id="help-request-thread-close">閉じる</button>' +
            '</div></div>';
        document.body.appendChild(overlay);

        function renderMessages() {
            apiCall('GET', API_BASE + 'request-messages.php?store_id=' + encodeURIComponent(_storeId) + '&request_id=' + encodeURIComponent(requestId), null, function(data, err) {
                var body = document.getElementById('help-request-thread-body');
                if (!body) return;
                if (err) {
                    body.innerHTML = '<p class="help-error">メッセージを取得できませんでした: ' + esc(err.message || '') + '</p>';
                    return;
                }
                var messages = data.messages || [];
                if (messages.length === 0) {
                    body.innerHTML = '<p class="help-empty">まだメッセージはありません。</p>';
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

        document.getElementById('help-request-thread-close').addEventListener('click', function() {
            document.body.removeChild(overlay);
            loadRequests();
        });
        document.getElementById('help-request-thread-send').addEventListener('click', function() {
            var input = document.getElementById('help-request-thread-message');
            var message = input.value;
            if (!message.trim()) {
                notify('メッセージを入力してください', 'error');
                return;
            }
            apiCall('POST', API_BASE + 'request-messages.php?store_id=' + encodeURIComponent(_storeId), {
                request_id: requestId,
                message: message
            }, function(result, err) {
                if (err) {
                    notify('送信に失敗しました: ' + (err.message || ''), 'error');
                    return;
                }
                input.value = '';
                renderMessages();
            });
        });
        renderMessages();
    }

    function _patchSwapRequest(requestId, action) {
        var label = action === 'reject' ? '却下' : 'キャンセル';
        if (!confirm('この申請を' + label + 'しますか？')) return;
        apiCall('PATCH', API_BASE + 'swap-requests.php?id=' + encodeURIComponent(requestId) + '&store_id=' + encodeURIComponent(_storeId), {
            action: action
        }, function(data, err) {
            if (err) {
                if (typeof showToast === 'function') showToast(label + 'に失敗しました: ' + (err.message || ''), 'error');
                return;
            }
            if (typeof showToast === 'function') showToast(label + 'しました');
            loadRequests();
        });
    }

    return {
        init: init,
        loadRequests: loadRequests
    };
})();
