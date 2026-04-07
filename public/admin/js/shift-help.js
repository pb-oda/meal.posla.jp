/**
 * shift-help.js — 店舗間ヘルプ要請モジュール（L-3 Phase 3）
 *
 * マネージャー用: ヘルプ要請の作成・一覧・承認・却下・キャンセル
 * enterprise プラン限定
 *
 * ES5 IIFE パターン
 */
var ShiftHelp = (function() {
    'use strict';

    var API_BASE = '/api/store/shift/';
    var _containerId = null;
    var _storeId = null;
    var _otherStores = [];
    var _activeTab = 'sent'; // 'sent' | 'received'

    function esc(str) {
        if (typeof Utils !== 'undefined' && Utils.escapeHtml) return Utils.escapeHtml(str);
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
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
            loadRequests();
        });
    }

    // ── 要請一覧取得 ──
    function loadRequests() {
        var container = document.getElementById(_containerId);
        if (!container) return;
        container.innerHTML = '<p style="text-align:center;padding:1rem;color:#888;">読み込み中...</p>';

        apiCall('GET', API_BASE + 'help-requests.php?store_id=' + encodeURIComponent(_storeId), null, function(data, err) {
            if (err) {
                container.innerHTML = '<p class="error" style="padding:1rem;">ヘルプ要請の取得に失敗しました: ' + esc(err.message || '') + '</p>';
                return;
            }
            _renderRequests(data.sent || [], data.received || []);
        });
    }

    // ── 一覧描画 ──
    function _renderRequests(sent, received) {
        var container = document.getElementById(_containerId);
        if (!container) return;

        var html = '<div class="shift-section-header">' +
            '<h3>店舗間ヘルプ要請</h3>' +
            '<button class="btn btn-sm btn-primary" id="help-create-btn">+ 新規要請</button>' +
            '</div>';

        // 送信/受信タブ
        html += '<div class="help-tabs" style="display:flex;gap:0;margin-bottom:1rem;">' +
            '<button class="btn btn-sm help-tab-btn' + (_activeTab === 'sent' ? ' btn-primary' : '') + '" data-help-tab="sent">送信 (' + sent.length + ')</button>' +
            '<button class="btn btn-sm help-tab-btn' + (_activeTab === 'received' ? ' btn-primary' : '') + '" data-help-tab="received">受信 (' + received.length + ')</button>' +
            '</div>';

        // 送信一覧
        html += '<div class="help-tab-content" id="help-tab-sent" style="' + (_activeTab === 'sent' ? '' : 'display:none;') + '">';
        if (sent.length === 0) {
            html += '<p style="color:#888;padding:1rem;">送信した要請はありません</p>';
        } else {
            html += _buildTable(sent, 'sent');
        }
        html += '</div>';

        // 受信一覧
        html += '<div class="help-tab-content" id="help-tab-received" style="' + (_activeTab === 'received' ? '' : 'display:none;') + '">';
        if (received.length === 0) {
            html += '<p style="color:#888;padding:1rem;">受信した要請はありません</p>';
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
                document.getElementById('help-tab-sent').style.display = (_activeTab === 'sent') ? '' : 'none';
                document.getElementById('help-tab-received').style.display = (_activeTab === 'received') ? '' : 'none';
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
    }

    // ── テーブル構築 ──
    function _buildTable(rows, type) {
        var html = '<table class="shift-table" style="width:100%;"><thead><tr>';
        html += '<th>日付</th><th>時間帯</th>';
        html += (type === 'sent') ? '<th>送信先</th>' : '<th>送信元</th>';
        html += '<th>人数</th><th>役割</th><th>状態</th><th>派遣スタッフ</th><th>操作</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var statusBadge = _statusBadge(r.status);
            var storeName = (type === 'sent') ? esc(r.to_store_name || '') : esc(r.from_store_name || '');
            var roleLabel = r.role_hint ? esc(r.role_hint) : '-';

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
                actions = '<button class="btn btn-sm" data-help-action="cancel" data-request-id="' + esc(r.id) + '" style="color:#e53935;">キャンセル</button>';
            } else if (type === 'received' && r.status === 'pending') {
                actions = '<button class="btn btn-sm btn-primary" data-help-action="approve" data-request-id="' + esc(r.id) + '">承認</button> ' +
                    '<button class="btn btn-sm" data-help-action="reject" data-request-id="' + esc(r.id) + '" style="color:#e53935;">却下</button>';
            }

            html += '<tr>' +
                '<td>' + esc(r.requested_date || '') + '</td>' +
                '<td>' + esc((r.start_time || '').substring(0, 5)) + '-' + esc((r.end_time || '').substring(0, 5)) + '</td>' +
                '<td>' + storeName + '</td>' +
                '<td>' + r.requested_staff_count + '名</td>' +
                '<td>' + roleLabel + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + (staffNames || '-') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';

            // メモ行（あれば）
            if (r.note || r.response_note) {
                html += '<tr><td colspan="8" style="font-size:0.8rem;color:#666;border-top:none;padding-top:0;">';
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
        var colors = { pending: '#ff9800', approved: '#4caf50', rejected: '#e53935', cancelled: '#9e9e9e' };
        var labels = { pending: '待機中', approved: '承認済', rejected: '却下', cancelled: 'キャンセル' };
        var c = colors[status] || '#999';
        var l = labels[status] || status;
        return '<span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:0.75rem;color:#fff;background:' + c + ';">' + esc(l) + '</span>';
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

        overlay.innerHTML = '<div class="shift-dialog">' +
            '<h3>ヘルプ要請</h3>' +
            '<label>送信先店舗<select id="help-to-store" class="form-input">' +
            '<option value="">-- 選択 --</option>' + storeOpts + '</select></label>' +
            '<label>日付<input type="date" id="help-date" class="form-input" value="' + defDate + '"></label>' +
            '<label>開始時刻<input type="time" id="help-start" class="form-input" value="17:00"></label>' +
            '<label>終了時刻<input type="time" id="help-end" class="form-input" value="22:00"></label>' +
            '<label>必要人数<input type="number" id="help-count" class="form-input" min="1" max="10" value="1"></label>' +
            '<label>役割<select id="help-role" class="form-input">' +
            '<option value="">指定なし</option>' +
            '<option value="hall">hall</option>' +
            '<option value="kitchen">kitchen</option></select></label>' +
            '<label>メモ<input type="text" id="help-note" class="form-input" placeholder="要請理由など"></label>' +
            '<div class="shift-dialog-actions">' +
            '<button class="btn btn-primary" id="help-send-btn">送信</button>' +
            '<button class="btn" id="help-cancel-btn">キャンセル</button>' +
            '</div></div>';

        document.body.appendChild(overlay);

        document.getElementById('help-cancel-btn').addEventListener('click', function() {
            document.body.removeChild(overlay);
        });

        document.getElementById('help-send-btn').addEventListener('click', function() {
            var toStore = document.getElementById('help-to-store').value;
            if (!toStore) { alert('送信先店舗を選択してください'); return; }

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
                if (err) { alert('要請の送信に失敗しました: ' + (err.message || '')); return; }
                document.body.removeChild(overlay);
                if (typeof showToast === 'function') showToast('ヘルプ要請を送信しました', 'success');
                loadRequests();
            });
        });
    }

    // ── 承認ダイアログ ──
    function _approveRequest(requestId) {
        // to_store のスタッフ一覧を取得
        apiCall('GET', API_BASE + 'help-requests.php?action=list-staff&store_id=' + encodeURIComponent(_storeId) + '&target_store_id=' + encodeURIComponent(_storeId), null, function(data, err) {
            if (err) { alert('スタッフ一覧の取得に失敗しました'); return; }
            var staffList = (data && data.staff) ? data.staff : [];

            var overlay = document.createElement('div');
            overlay.className = 'shift-dialog-overlay';

            var staffHtml = '';
            if (staffList.length === 0) {
                staffHtml = '<p style="color:#888;">この店舗にスタッフが登録されていません</p>';
            } else {
                for (var i = 0; i < staffList.length; i++) {
                    var s = staffList[i];
                    staffHtml += '<label style="display:block;margin:4px 0;">' +
                        '<input type="checkbox" class="help-staff-cb" value="' + esc(s.id) + '"> ' +
                        esc(s.display_name || s.username) +
                        '</label>';
                }
            }

            overlay.innerHTML = '<div class="shift-dialog">' +
                '<h3>ヘルプ要請の承認</h3>' +
                '<p>派遣するスタッフを選択してください:</p>' +
                '<div style="max-height:200px;overflow-y:auto;margin-bottom:10px;">' + staffHtml + '</div>' +
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
                if (userIds.length === 0) { alert('スタッフを選択してください'); return; }

                var patchData = {
                    store_id: _storeId,
                    action: 'approve',
                    assigned_user_ids: userIds,
                    response_note: document.getElementById('help-approve-note').value
                };

                apiCall('PATCH', API_BASE + 'help-requests.php?id=' + encodeURIComponent(requestId) + '&store_id=' + encodeURIComponent(_storeId), patchData, function(result, err) {
                    if (err) { alert('承認に失敗しました: ' + (err.message || '')); return; }
                    document.body.removeChild(overlay);
                    // 警告表示
                    if (result && result.warnings && result.warnings.length > 0) {
                        alert('承認しました。\n\n注意:\n' + result.warnings.join('\n'));
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
            '<button class="btn" id="help-reject-btn" style="background:#e53935;color:#fff;">却下</button>' +
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
                if (err) { alert('却下に失敗しました: ' + (err.message || '')); return; }
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
            if (err) { alert('キャンセルに失敗しました: ' + (err.message || '')); return; }
            if (typeof showToast === 'function') showToast('キャンセルしました');
            loadRequests();
        });
    }

    return {
        init: init,
        loadRequests: loadRequests
    };
})();
