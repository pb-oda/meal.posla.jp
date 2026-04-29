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
                    _renderRequests(data.sent || [], data.received || [], todayData || null, (swapData && swapData.requests) ? swapData.requests : []);
                });
            });
        });
    }

    // ── 一覧描画 ──
    function _renderRequests(sent, received, todayData, swapRequests) {
        var container = document.getElementById(_containerId);
        if (!container) return;

        var html = '<div class="shift-section-header">' +
            '<h3>店舗間ヘルプ要請</h3>' +
            '<button class="btn btn-sm btn-primary" id="help-create-btn">+ 新規要請</button>' +
            '</div>';

        html += _buildTodayStatus(todayData);
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
                if (act === 'approve') _approveSwapRequest(reqId, assignmentId);
                else if (act === 'reject') _patchSwapRequest(reqId, 'reject');
                else if (act === 'cancel') _patchSwapRequest(reqId, 'cancel');
            });
        }
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
            html += '<div class="shift-urgent-panel__block"><strong>開始済み未打刻</strong><ul>';
            for (var i = 0; i < Math.min(data.no_clock_in.length, 5); i++) {
                html += '<li>' + esc(data.no_clock_in[i].display_name + ' ' + data.no_clock_in[i].start_time + '-' + data.no_clock_in[i].end_time) + '</li>';
            }
            html += '</ul></div>';
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
            var candidate = row.replacement_name || row.candidate_name || '-';
            var actions = '';
            if (row.status === 'pending') {
                actions = '<button class="btn btn-sm btn-primary" data-swap-action="approve" data-request-id="' + esc(row.id) + '" data-assignment-id="' + esc(row.shift_assignment_id) + '">承認</button> ' +
                    '<button class="btn btn-sm help-cancel-btn" data-swap-action="reject" data-request-id="' + esc(row.id) + '">却下</button>';
            }
            html += '<tr><td>' + esc(row.shift_date || '') + '<br>' + esc((row.start_time || '').substring(0, 5)) + '-' + esc((row.end_time || '').substring(0, 5)) + '</td>' +
                '<td>' + esc(row.requester_name || row.requester_username || '') + '</td>' +
                '<td>' + type + '</td><td>' + esc(candidate) + '</td><td>' + status + '</td><td>' + actions + '</td></tr>';
            if (row.reason || row.response_note) {
                html += '<tr class="help-note-row"><td colspan="6">' + esc(row.reason || row.response_note || '') + '</td></tr>';
            }
        }
        html += '</tbody></table></section>';
        return html;
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
    function _showCreateDialog() {
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
        var roleOptions = '<option value="">指定なし</option>';
        for (var rp = 0; rp < _positions.length; rp++) {
            if (_positions[rp].is_active === 0) continue;
            roleOptions += '<option value="' + esc(_positions[rp].code) + '">' + esc(_positions[rp].label || _positions[rp].code) + '</option>';
        }

        overlay.innerHTML = '<div class="shift-dialog">' +
            '<h3>ヘルプ要請</h3>' +
            '<label>送信先店舗<select id="help-to-store" class="form-input">' +
            '<option value="">-- 選択 --</option>' + storeOpts + '</select></label>' +
            '<label>日付<input type="date" id="help-date" class="form-input" value="' + defDate + '"></label>' +
            '<label>開始時刻<input type="time" id="help-start" class="form-input" value="17:00"></label>' +
            '<label>終了時刻<input type="time" id="help-end" class="form-input" value="22:00"></label>' +
            '<label>必要人数<input type="number" id="help-count" class="form-input" min="1" max="10" value="1"></label>' +
            '<label>持ち場<select id="help-role" class="form-input">' + roleOptions + '</select></label>' +
            '<div id="help-candidate-preview" class="help-candidate-preview">送信先・日時を選ぶと候補スタッフを表示します。</div>' +
            '<label>メモ<input type="text" id="help-note" class="form-input" placeholder="要請理由など"></label>' +
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

    function _approveSwapRequest(requestId, assignmentId) {
        var overlay = document.createElement('div');
        overlay.className = 'shift-dialog-overlay';
        overlay.innerHTML = '<div class="shift-dialog">' +
            '<h3>交代・欠勤申請の承認</h3>' +
            '<div id="swap-approve-candidates">候補を確認中...</div>' +
            '<label>対応メモ<input type="text" id="swap-approve-note" class="form-input" placeholder="任意"></label>' +
            '<div class="shift-dialog-actions">' +
            '<button class="btn btn-primary" id="swap-approve-go">承認</button>' +
            '<button class="btn" id="swap-approve-cancel">キャンセル</button>' +
            '</div></div>';
        document.body.appendChild(overlay);

        document.getElementById('swap-approve-cancel').addEventListener('click', function() {
            document.body.removeChild(overlay);
        });

        apiCall('GET', API_BASE + 'swap-requests.php?action=candidates&store_id=' + encodeURIComponent(_storeId) +
            '&assignment_id=' + encodeURIComponent(assignmentId), null, function(data, err) {
            var box = document.getElementById('swap-approve-candidates');
            if (!box) return;
            if (err) {
                box.innerHTML = '<p class="help-error">候補を取得できませんでした</p>';
                return;
            }
            var html = '<p>交代スタッフを選ぶと、承認と同時にシフト担当者を差し替えます。欠勤だけ承認する場合は「交代なし」を選びます。</p>' +
                '<label class="shift-swap-candidate"><input type="radio" name="swap-replacement" value="" checked> 交代なし</label>';
            var candidates = data.candidates || [];
            for (var i = 0; i < Math.min(candidates.length, 10); i++) {
                var c = candidates[i];
                var av = c.availability === 'preferred' ? '希望' : (c.availability === 'available' ? '出勤可' : '未提出');
                html += '<label class="shift-swap-candidate"><input type="radio" name="swap-replacement" value="' + esc(c.user_id) + '"> ' +
                    esc(c.display_name) + ' <small>' + esc(av + (c.role_match ? '' : ' / 持ち場要確認')) + '</small></label>';
            }
            box.innerHTML = html;
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
                loadRequests();
            });
        });
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
