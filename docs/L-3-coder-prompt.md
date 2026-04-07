# L-3 シフト管理・勤怠連携 — コーダー向け実装プロンプト

> このドキュメントは L-3-shift-design.md の設計に基づき、コーダー（Claude等）が実装するためのプロンプトを Step ごとに記載する。
> 各 Step は独立して渡せるようになっている。前の Step の完了を前提とする。

---

## 共通ルール（全Stepに適用）

CLAUDE.md の全ルールを遵守すること。特に:

- ES5互換（`var` + コールバック。`const`/`let`/arrow/`async-await` 禁止）
- 全SQLはプリペアドステートメント
- IIFEパターン維持
- マルチテナント境界（全クエリに `tenant_id` + `store_id`）
- `api/lib/response.php` の統一レスポンス形式
- `api/lib/audit-log.php` の `write_audit_log()` で操作記録
- ユーザー入力は `Utils.escapeHtml()` 経由で `innerHTML` に挿入
- 既存ファイルの変更は最小限。指示された箇所のみ

---

## Step 1: マイグレーション + plan_features + 設定API

### 目的
L-3 に必要な全テーブルを作成し、プラン機能フラグを追加し、シフト設定APIを実装する。

### 作成するファイル

**1. `sql/migration-l3-shift-management.sql`**

以下の5テーブルを作成:

```sql
-- L-3 シフト管理・勤怠連携
-- 新規テーブル5つ + plan_features追加

-- 1. shift_templates（シフトテンプレート）
CREATE TABLE shift_templates (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    day_of_week TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    required_staff INT NOT NULL DEFAULT 1,
    role_hint VARCHAR(20) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_st_tenant_store (tenant_id, store_id),
    INDEX idx_st_day (tenant_id, store_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. shift_assignments（シフト割当）
CREATE TABLE shift_assignments (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    shift_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_minutes INT NOT NULL DEFAULT 0,
    role_type VARCHAR(20) NULL,
    status ENUM('draft','published','confirmed') NOT NULL DEFAULT 'draft',
    note TEXT NULL,
    created_by VARCHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_sa_tenant_store_date (tenant_id, store_id, shift_date),
    INDEX idx_sa_user_date (tenant_id, user_id, shift_date),
    INDEX idx_sa_status (tenant_id, store_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. shift_availabilities（希望シフト）
CREATE TABLE shift_availabilities (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    target_date DATE NOT NULL,
    availability ENUM('available','preferred','unavailable') NOT NULL,
    preferred_start TIME NULL,
    preferred_end TIME NULL,
    note TEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_avail_unique (tenant_id, store_id, user_id, target_date),
    INDEX idx_avail_store_date (tenant_id, store_id, target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. attendance_logs（勤怠打刻）
CREATE TABLE attendance_logs (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    shift_assignment_id VARCHAR(36) NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    break_minutes INT NOT NULL DEFAULT 0,
    status ENUM('working','completed','absent','late') NOT NULL DEFAULT 'working',
    clock_in_method ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    clock_out_method ENUM('manual','auto','timeout') NOT NULL DEFAULT 'manual',
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_att_tenant_store_date (tenant_id, store_id, clock_in),
    INDEX idx_att_user (tenant_id, user_id, clock_in),
    INDEX idx_att_status (tenant_id, store_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. shift_settings（シフト設定）
CREATE TABLE shift_settings (
    store_id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    submission_deadline_day TINYINT NOT NULL DEFAULT 5,
    default_break_minutes INT NOT NULL DEFAULT 60,
    overtime_threshold_minutes INT NOT NULL DEFAULT 480,
    early_clock_in_minutes INT NOT NULL DEFAULT 15,
    auto_clock_out_hours INT NOT NULL DEFAULT 12,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (store_id),
    INDEX idx_ss_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- plan_features 追加
INSERT INTO plan_features (plan, feature_key, enabled) VALUES
('pro', 'shift_management', 1),
('enterprise', 'shift_management', 1);
```

**2. `api/store/shift/settings.php`**

シフト設定の取得・更新API。

```
GET  /api/store/shift/settings.php?store_id=xxx  → シフト設定取得
PATCH /api/store/shift/settings.php              → シフト設定更新
```

実装パターン:
```php
<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'PATCH']);
$user = require_auth();
require_role('manager');

$pdo = get_db();
$tenantId = $user['tenant_id'];

// プランチェック
if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'シフト管理はProプラン以上で利用できます', 403);
}

$storeId = require_store_param();
require_store_access($storeId);
```

GETの場合:
- shift_settings から store_id で取得
- レコードがなければデフォルト値を返す（初回アクセス対応）

PATCHの場合:
- `get_json_body()` でリクエスト取得
- バリデーション: submission_deadline_day (1-28), default_break_minutes (0-120), overtime_threshold_minutes (60-720), early_clock_in_minutes (0-60), auto_clock_out_hours (1-24)
- INSERT ... ON DUPLICATE KEY UPDATE で upsert
- audit_log に記録（action: 'settings_update', entity_type: 'shift_settings'）

### 確認手順

**期待通りの場合:**
- マイグレーションSQL実行後、5テーブルが作成されている
- plan_features に shift_management が pro, enterprise に追加されている
- GET /api/store/shift/settings.php でデフォルト設定が返る
- PATCH で更新後、GET で更新値が返る

**期待と異なる場合:**
- SQLエラー → テーブル名・カラム名の衝突を確認（既存テーブルとの重複がないか）
- 403エラー → plan_features の INSERT が正しく実行されたか確認
- 認証エラー → auth.php のセッション確認、store_id パラメータの確認

---

## Step 2: シフトテンプレートCRUD

### 目的
繰り返し使うシフトパターン（「平日ランチ」「週末ディナー」等）を管理するAPIとUIを作成する。

### 作成するファイル

**1. `api/store/shift/templates.php`**

```
GET    ?store_id=xxx                    → テンプレート一覧
POST   (body: {...})                    → テンプレート作成
PATCH  ?id=xxx (body: {...})            → テンプレート更新
DELETE ?id=xxx&store_id=xxx             → テンプレート削除（論理削除: is_active=0）
```

- require_role('manager')
- 全クエリに tenant_id + store_id 絞り込み
- POST/PATCH バリデーション:
  - name: 必須、1-100文字
  - day_of_week: 必須、0-6
  - start_time, end_time: 必須、HH:MM 形式、start < end
  - required_staff: 必須、1-20
  - role_hint: 任意、'kitchen' / 'hall' / null
- DELETE は物理削除ではなく is_active=0 に更新
- 全操作で audit_log 記録（action: 'shift_template_create' / 'shift_template_update' / 'shift_template_delete', entity_type: 'shift_template'）

**2. `public/js/shift-manager.js`（テンプレート部分のみ）**

IIFEパターンで作成。この Step ではテンプレート管理UI部分のみ。

```javascript
(function() {
    'use strict';
    var ShiftManager = {
        storeId: null,
        init: function(storeId) {
            this.storeId = storeId;
            // タブ切替イベントの登録等
        },
        // テンプレートCRUD
        loadTemplates: function(callback) { ... },
        createTemplate: function(data, callback) { ... },
        updateTemplate: function(id, data, callback) { ... },
        deleteTemplate: function(id, callback) { ... },
        renderTemplates: function(templates) { ... }
    };
    window.ShiftManager = ShiftManager;
})();
```

API呼び出しパターン（既存コードに合わせる）:
```javascript
fetch('/api/store/shift/templates.php?store_id=' + ShiftManager.storeId)
    .then(function(res) { return res.text(); })
    .then(function(text) {
        var json = JSON.parse(text);
        if (!json.ok) { /* エラー処理 */ return; }
        callback(json.data);
    });
```

**3. `public/dashboard.html` への追加**

シフトタブのHTML骨格を追加:

```html
<!-- シフトタブ（既存タブの後に追加） -->
<div id="tab-shift" class="tab-content" style="display:none">
    <div class="shift-sub-tabs">
        <button class="shift-sub-tab active" data-view="calendar">週間カレンダー</button>
        <button class="shift-sub-tab" data-view="my-shift">マイシフト</button>
        <button class="shift-sub-tab" data-view="availability">希望提出</button>
        <button class="shift-sub-tab" data-view="attendance">勤怠一覧</button>
        <button class="shift-sub-tab" data-view="templates">テンプレート</button>
        <button class="shift-sub-tab" data-view="shift-settings">設定</button>
    </div>
    <div id="shift-view-calendar" class="shift-view"></div>
    <div id="shift-view-my-shift" class="shift-view" style="display:none"></div>
    <div id="shift-view-availability" class="shift-view" style="display:none"></div>
    <div id="shift-view-attendance" class="shift-view" style="display:none"></div>
    <div id="shift-view-templates" class="shift-view" style="display:none"></div>
    <div id="shift-view-shift-settings" class="shift-view" style="display:none"></div>
</div>
```

scriptタグ追加（既存のscriptタグの後、voice-commander.js等を削除しないこと）:
```html
<script src="/public/js/shift-manager.js"></script>
```

タブボタン追加（既存のタブナビゲーション内）:
```html
<button class="tab-btn" data-tab="shift">シフト</button>
```

### 確認手順

**期待通りの場合:**
- テンプレートの作成・一覧・更新・削除が動作する
- dashboard.html にシフトタブが表示され、テンプレート管理画面が動作する
- audit_log にテンプレート操作が記録される

**期待と異なる場合:**
- 404 → api/store/shift/ ディレクトリが作成されているか確認
- タブが表示されない → dashboard.html のタブ切替JS（既存のインラインスクリプト）にシフトタブの条件が追加されているか確認
- プランチェックエラー → Step 1 の plan_features INSERT が実行済みか確認

---

## Step 3: シフト割当CRUD + 週間カレンダー

### 目的
マネージャーが週単位でシフトを作成・編集・公開するAPIとカレンダーUIを作成する。

### 作成するファイル

**1. `api/store/shift/assignments.php`**

```
GET    ?store_id=xxx&start_date=2026-04-07&end_date=2026-04-13  → 週間シフト一覧
POST   (body: {...})                                              → シフト割当作成
PATCH  ?id=xxx (body: {...})                                      → シフト割当更新
DELETE ?id=xxx&store_id=xxx                                       → シフト割当削除
POST   ?action=publish (body: {store_id, start_date, end_date})   → ドラフト→公開一括更新
POST   ?action=apply-template (body: {store_id, template_ids[], start_date, end_date, user_assignments: [{template_id, user_id}]})  → テンプレートから一括生成
```

GETの仕様:
- staff は自分のシフトのみ（WHERE user_id = ?）
- manager+ は全スタッフのシフト
- ユーザー情報（display_name）をJOINで返す
- レスポンス例:
```json
{
    "assignments": [...],
    "users": [{"id": "xxx", "display_name": "田中", "username": "tanaka"}]
}
```

apply-templateの仕様:
- テンプレートの day_of_week と指定期間を照合
- user_assignments で「どのテンプレートに誰を割り当てるか」を指定
- 既存のシフト（同一日・同一ユーザー）がある場合はスキップ（上書きしない）
- 生成されたシフトは status='draft'

publishの仕様:
- 指定期間内のdraftをpublishedに一括更新
- audit_log に 'shift_publish' で記録

**2. `public/js/shift-calendar.js`**

週間カレンダーの描画・操作。IIFEパターン。

```javascript
(function() {
    'use strict';
    var ShiftCalendar = {
        currentWeekStart: null,
        assignments: [],
        users: [],

        init: function(containerId, storeId) { ... },
        loadWeek: function(startDate) { ... },
        render: function() { ... },
        // 週移動
        prevWeek: function() { ... },
        nextWeek: function() { ... },
        // セル操作
        onCellClick: function(userId, date) { ... },
        showEditDialog: function(assignment) { ... },
        // テンプレート適用
        showApplyTemplateDialog: function() { ... },
        // 公開
        publishWeek: function() { ... }
    };
    window.ShiftCalendar = ShiftCalendar;
})();
```

カレンダー描画:
- テーブル構造: 行=スタッフ、列=日付（月〜日）
- ヘッダー行: 日付 + 曜日
- 各セル: 時間帯表示（10:00-18:00）。色でステータス表示（draft=薄灰、published=青、confirmed=緑）
- 最下行: 日別合計人数
- 「<前の週」「次の週>」ナビゲーション
- 「テンプレート適用」ボタン、「公開」ボタン

セルクリック → 編集ダイアログ:
- 開始時刻、終了時刻、休憩時間、役割（kitchen/hall/指定なし）、メモ
- 保存/削除ボタン

dashboard.html に scriptタグ追加:
```html
<script src="/public/js/shift-calendar.js"></script>
```

### 確認手順

**期待通りの場合:**
- 週間カレンダーが表示され、シフトの追加・編集・削除が動作する
- テンプレート適用で一括シフト生成ができる
- 公開操作で draft → published に変わる
- staff ログインでは自分のシフトのみ表示される

**期待と異なる場合:**
- カレンダーが空 → GET API のレスポンスを確認。start_date/end_date のフォーマット（YYYY-MM-DD）を確認
- テンプレート適用が動かない → apply-template の user_assignments パラメータ構造を確認
- 権限エラー → require_role の指定を確認（GET は staff+、POST/PATCH/DELETE は manager+）

---

## Step 4: 希望提出

### 目的
スタッフが勤務可能日時を提出し、マネージャーがシフト作成時に参照できるようにする。

### 作成するファイル

**1. `api/store/shift/availabilities.php`**

```
GET    ?store_id=xxx&start_date=xxx&end_date=xxx  → 希望一覧
POST   (body: {store_id, availabilities: [{target_date, availability, preferred_start, preferred_end, note}]})  → 希望一括提出
PATCH  ?id=xxx (body: {...})                       → 希望修正
```

GETの仕様:
- staff は自分の希望のみ
- manager+ は全スタッフの希望（シフト作成時の参照用）

POSTの仕様:
- 一括提出（1週間分をまとめて送信）
- 既存の希望がある日は REPLACE（UNIQUE INDEX で制御）
- availability: 'available' / 'preferred' / 'unavailable'
- preferred_start / preferred_end は availability が 'available' / 'preferred' の場合のみ

**2. shift-manager.js に希望提出UIを追加**

`shift-view-availability` コンテナに描画。
- 週選択（カレンダーと同じ週ナビゲーション）
- 日付ごとに 可/希望/不可 の3択ボタン
- 可・希望の場合は時間帯入力欄を表示
- メモ入力欄
- 「提出する」ボタン

### 確認手順

**期待通りの場合:**
- スタッフが希望を提出でき、提出済みの希望が表示される
- マネージャーがカレンダー画面で希望を参照できる（半透明の背景色等で表示）
- 同一日の再提出で上書きされる

**期待と異なる場合:**
- 重複エラー → UNIQUE INDEX の動作確認。INSERT ... ON DUPLICATE KEY UPDATE パターンを使用しているか確認
- 時間入力が空 → availability='unavailable' の場合は preferred_start/end が NULL になることを確認

---

## Step 5: 勤怠打刻

### 目的
スタッフがダッシュボード上でワンタップで出退勤を記録できるようにする。

### 作成するファイル

**1. `api/store/shift/attendance.php`**

```
POST   ?action=clock-in  (body: {store_id})           → 出勤打刻
POST   ?action=clock-out (body: {store_id})            → 退勤打刻
GET    ?store_id=xxx&start_date=xxx&end_date=xxx       → 勤怠履歴
PATCH  ?id=xxx (body: {clock_in, clock_out, break_minutes, note})  → 勤怠修正（manager+）
```

clock-in の仕様:
- 既に working 状態のレコードがあればエラー（二重打刻防止）
- 当日のshift_assignmentsがあれば shift_assignment_id を自動紐付け
- shift_assignments の start_time より early_clock_in_minutes 以上早い場合は打刻可能だが注意メッセージを返す
- audit_log に 'attendance_clock_in' で記録

clock-out の仕様:
- working 状態のレコードを検索して clock_out を記録
- status を 'completed' に更新
- break_minutes はデフォルト値（shift_settings.default_break_minutes）を自動設定。後で修正可能
- audit_log に 'attendance_clock_out' で記録

PATCH（勤怠修正）の仕様:
- manager+ のみ
- old_value / new_value を audit_log に記録
- 修正理由（note）を必須とする

**2. `public/js/attendance-clock.js`**

ヘッダーに打刻ボタンを自己挿入するモジュール。IIFEパターン。

```javascript
(function() {
    'use strict';
    var AttendanceClock = {
        storeId: null,
        status: null, // 'idle' / 'working'
        currentAttendance: null,

        init: function(storeId) {
            this.storeId = storeId;
            this._insertUI();
            this._checkStatus();
        },
        _insertUI: function() {
            // ヘッダー要素を探してボタンを挿入
            var header = document.querySelector('.dashboard-header') || document.querySelector('header');
            if (!header) return;
            var container = document.createElement('div');
            container.id = 'attendance-clock-container';
            // ... ボタン生成
            header.appendChild(container);
        },
        _checkStatus: function() {
            // GET attendance で当日の working レコードを確認
            // → idle なら「出勤する」ボタン、working なら「退勤する」ボタン + 経過時間
        },
        clockIn: function() { ... },
        clockOut: function() { ... },
        _updateUI: function() { ... },
        _formatElapsed: function(clockInTime) { ... }
    };
    window.AttendanceClock = AttendanceClock;
})();
```

UI仕様:
- 未出勤: 緑色の「出勤する」ボタン
- 勤務中: 経過時間表示 + 赤色の「退勤する」ボタン
- 経過時間は1分ごとに更新（setInterval）

dashboard.html に scriptタグ追加:
```html
<script src="/public/js/attendance-clock.js"></script>
```

初期化（dashboard.html のインラインスクリプト内、既存の初期化処理の後に追加）:
```javascript
if (typeof AttendanceClock !== 'undefined' && currentStoreId) {
    AttendanceClock.init(currentStoreId);
}
```

**3. 自動退勤スクリプト（cron）**

`api/cron/auto-clock-out.php`

- shift_settings.auto_clock_out_hours を超過した working レコードを自動退勤
- clock_out_method = 'timeout'
- status = 'completed'
- 5分間隔でcron実行想定

```php
// cronから直接実行（認証不要・CLI実行チェック必須）
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
```

### 確認手順

**期待通りの場合:**
- ダッシュボードヘッダーに打刻ボタンが表示される
- 出勤打刻 → ボタンが「退勤する」に切替 + 経過時間表示
- 退勤打刻 → ボタンが「出勤する」に戻る
- 勤怠一覧に記録が表示される
- マネージャーが勤怠修正できる

**期待と異なる場合:**
- ボタンが表示されない → attendance-clock.js の _insertUI でヘッダー要素のセレクタを確認
- 二重打刻エラー → working 状態チェックのクエリを確認（tenant_id + store_id + user_id + status='working'）
- 経過時間が更新されない → setInterval のスコープを確認（IIFEパターン内で this 参照が正しいか）

---

## Step 6: 集計 + オーナー画面

### 目的
労働時間・人件費の集計APIを作成し、オーナーダッシュボードにシフトタブを追加する。

### 作成するファイル

**1. `api/store/shift/summary.php`**

```
GET ?store_id=xxx&period=weekly&date=2026-04-07   → 週次サマリー
GET ?store_id=xxx&period=monthly&date=2026-04      → 月次サマリー
```

レスポンス:
```json
{
    "period": "2026-04-07 ~ 2026-04-13",
    "total_hours": 120.5,
    "staff_summary": [
        {
            "user_id": "xxx",
            "display_name": "田中",
            "total_hours": 40.0,
            "days_worked": 5,
            "overtime_hours": 0,
            "attendance_count": 5,
            "late_count": 0
        }
    ],
    "daily_summary": [
        {"date": "2026-04-07", "staff_count": 3, "total_hours": 24.0}
    ]
}
```

計算ロジック:
- 労働時間 = clock_out - clock_in - break_minutes（attendance_logs から）
- シフトがあるが出勤なし = absent としてカウント
- overtime_threshold_minutes 超過分 = overtime_hours

**2. `public/owner-dashboard.html` への追加**

シフトタブを追加:
- 店舗選択ドロップダウン（既存のものを共用）
- 週次/月次切替
- スタッフ別労働時間テーブル
- 日別配置人数グラフ（簡易棒グラフ、CSSのみ）

**3. `public/js/owner-app.js` への追加**

シフトタブの初期化・データ読み込み処理を追加（既存のタブ管理ロジックに合わせる）。

### 確認手順

**期待通りの場合:**
- オーナーダッシュボードにシフトタブが表示される
- 店舗を選択すると週次/月次サマリーが表示される
- スタッフ別の労働時間が正しく集計される

**期待と異なる場合:**
- 集計が0 → attendance_logs にデータがあるか確認。clock_out が NULL のレコードは集計対象外になっていないか確認（working 状態は現在時刻までで計算）
- オーナータブが表示されない → owner-app.js のタブ制御ロジックを確認

---

## デプロイファイル一覧（全Step完了時）

### 新規ファイル
```
sql/migration-l3-shift-management.sql
api/store/shift/settings.php
api/store/shift/templates.php
api/store/shift/assignments.php
api/store/shift/availabilities.php
api/store/shift/attendance.php
api/store/shift/summary.php
api/cron/auto-clock-out.php
public/js/shift-manager.js
public/js/shift-calendar.js
public/js/attendance-clock.js
```

### 変更ファイル
```
public/dashboard.html          -- シフトタブHTML追加 + scriptタグ3つ追加 + 初期化コード追加
public/owner-dashboard.html    -- シフトタブHTML追加 + scriptタグ追加
public/js/owner-app.js         -- シフトタブ初期化処理追加
```
