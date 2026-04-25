# L-3 シフト管理・勤怠連携 — 設計ドキュメント

> 作成日: 2026-04-06
> ステータス: 設計確定 → 実装待ち
> プラン: pro / enterprise
> 前提: 1人1アカウント必須（CLAUDE.md参照）

---

## 1. 概要

POSLAにシフト管理・勤怠打刻機能を追加する。
POS売上データと連動したAIシフト提案が最大の差別化ポイント。

### 1.1 ユーザーストーリー

| ロール | やりたいこと |
|--------|-------------|
| オーナー | 全店舗のシフト状況・人件費を一画面で把握したい |
| マネージャー | 週次シフトを効率的に作成し、スタッフに通知したい |
| マネージャー | 売上予測に基づいて適正人数を知りたい |
| スタッフ | スマホから希望シフトを提出したい |
| スタッフ | 確定シフトを自分の画面で確認したい |
| スタッフ | 出勤・退勤をワンタップで打刻したい |

### 1.2 フェーズ分け

| フェーズ | 内容 | 見積り |
|----------|------|--------|
| Phase 1（MVP） | シフトテンプレート、希望提出、シフト編集・確定、勤怠打刻 | 7-10日 |
| Phase 2（AI強化） | AI最適シフト提案、人件費シミュレーション、労基法チェック | 5-7日 |
| Phase 3（マルチ店舗） | 店舗間ヘルプ要請、統合シフトビュー（enterprise） | 3-5日 |

**この設計ドキュメントは Phase 1（MVP）をカバーする。**

---

## 2. データベース設計

### 2.1 新規テーブル（5テーブル）

新規マイグレーションファイル: `sql/migration-l3-shift-management.sql`

#### shift_templates（シフトテンプレート）

繰り返し使う週次パターンを保存する。「平日ランチ」「週末ディナー」など。

```sql
CREATE TABLE shift_templates (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,           -- 「平日ランチ」「週末ディナー」
    day_of_week TINYINT NOT NULL,         -- 0=日 1=月 ... 6=土
    start_time TIME NOT NULL,             -- 09:00:00
    end_time TIME NOT NULL,               -- 15:00:00
    required_staff INT NOT NULL DEFAULT 1,-- 必要人数
    role_hint VARCHAR(20) NULL,           -- 'kitchen' / 'hall' / NULL(指定なし)
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_st_tenant_store (tenant_id, store_id),
    INDEX idx_st_day (tenant_id, store_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### shift_assignments（シフト割当）

確定したシフト。誰がいつ働くか。

```sql
CREATE TABLE shift_assignments (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,         -- スタッフのusers.id
    shift_date DATE NOT NULL,             -- 勤務日
    start_time TIME NOT NULL,             -- 10:00:00
    end_time TIME NOT NULL,               -- 18:00:00
    break_minutes INT NOT NULL DEFAULT 0, -- 休憩時間（分）
    role_type VARCHAR(20) NULL,           -- 'kitchen' / 'hall' / NULL
    status ENUM('draft','published','confirmed') NOT NULL DEFAULT 'draft',
    note TEXT NULL,                        -- メモ（「遅番OK」等）
    created_by VARCHAR(36) NOT NULL,      -- 作成者のusers.id
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_sa_tenant_store_date (tenant_id, store_id, shift_date),
    INDEX idx_sa_user_date (tenant_id, user_id, shift_date),
    INDEX idx_sa_status (tenant_id, store_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### shift_availabilities（希望シフト）

スタッフが提出する勤務可能時間。

```sql
CREATE TABLE shift_availabilities (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    target_date DATE NOT NULL,
    availability ENUM('available','preferred','unavailable') NOT NULL,
    preferred_start TIME NULL,            -- 希望開始（availableの場合）
    preferred_end TIME NULL,              -- 希望終了
    note TEXT NULL,                        -- 「午前のみ希望」等
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_avail_unique (tenant_id, store_id, user_id, target_date),
    INDEX idx_avail_store_date (tenant_id, store_id, target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### attendance_logs（勤怠打刻）

出退勤の記録。clock_in/clock_outペア。

```sql
CREATE TABLE attendance_logs (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    store_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    shift_assignment_id VARCHAR(36) NULL, -- 対応するshift_assignments.id（あれば）
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,              -- 退勤時にUPDATE
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
```

#### shift_settings（シフト設定）

店舗ごとのシフト運用パラメータ。

```sql
CREATE TABLE shift_settings (
    store_id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    submission_deadline_day TINYINT NOT NULL DEFAULT 5, -- 毎月N日までに希望提出
    default_break_minutes INT NOT NULL DEFAULT 60,      -- デフォルト休憩時間
    overtime_threshold_minutes INT NOT NULL DEFAULT 480, -- 残業判定（8h=480分）
    early_clock_in_minutes INT NOT NULL DEFAULT 15,     -- 早出打刻許容（分）
    auto_clock_out_hours INT NOT NULL DEFAULT 12,       -- 自動退勤（打刻忘れ対策）
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (store_id),
    INDEX idx_ss_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2.2 plan_features 追加

```sql
INSERT INTO plan_features (plan, feature_key, enabled) VALUES
('pro', 'shift_management', 1),
('enterprise', 'shift_management', 1);
```

### 2.3 既存テーブルへの影響

**なし。** 全て新規テーブル。既存テーブルのALTERは不要。

---

## 3. API設計

### 3.1 新規APIエンドポイント

ディレクトリ: `api/store/shift/`

| メソッド | エンドポイント | ロール | 説明 |
|----------|---------------|--------|------|
| **シフトテンプレート** | | | |
| GET | `/api/store/shift/templates.php` | manager+ | テンプレート一覧 |
| POST | `/api/store/shift/templates.php` | manager+ | テンプレート作成 |
| PATCH | `/api/store/shift/templates.php?id=xxx` | manager+ | テンプレート更新 |
| DELETE | `/api/store/shift/templates.php?id=xxx` | manager+ | テンプレート削除 |
| **シフト割当** | | | |
| GET | `/api/store/shift/assignments.php` | staff+ | シフト一覧（週次） |
| POST | `/api/store/shift/assignments.php` | manager+ | シフト割当作成 |
| PATCH | `/api/store/shift/assignments.php?id=xxx` | manager+ | シフト割当更新 |
| DELETE | `/api/store/shift/assignments.php?id=xxx` | manager+ | シフト割当削除 |
| POST | `/api/store/shift/assignments.php?action=publish` | manager+ | シフト公開（draft→published） |
| POST | `/api/store/shift/assignments.php?action=apply-template` | manager+ | テンプレートから一括生成 |
| **希望シフト** | | | |
| GET | `/api/store/shift/availabilities.php` | staff+ | 希望一覧（自分 or 全員） |
| POST | `/api/store/shift/availabilities.php` | staff+ | 希望提出 |
| PATCH | `/api/store/shift/availabilities.php?id=xxx` | staff+ | 希望修正 |
| **勤怠打刻** | | | |
| POST | `/api/store/shift/attendance.php?action=clock-in` | staff+ | 出勤打刻 |
| POST | `/api/store/shift/attendance.php?action=clock-out` | staff+ | 退勤打刻 |
| GET | `/api/store/shift/attendance.php` | staff+ | 勤怠履歴 |
| PATCH | `/api/store/shift/attendance.php?id=xxx` | manager+ | 勤怠修正（打刻忘れ等） |
| **シフト設定** | | | |
| GET | `/api/store/shift/settings.php` | manager+ | 設定取得 |
| PATCH | `/api/store/shift/settings.php` | manager+ | 設定更新 |
| **集計** | | | |
| GET | `/api/store/shift/summary.php` | manager+ | 週次・月次サマリー（労働時間・人件費） |

### 3.2 レスポンス形式

既存の統一形式を維持:

```json
{
    "ok": true,
    "data": { ... },
    "serverTime": "2026-04-06T12:00:00+09:00"
}
```

### 3.3 認証・認可

- 全エンドポイントで `require_auth()` + `require_store_access($storeId)` を呼ぶ
- `check_plan_feature($pdo, $tenantId, 'shift_management')` でプランチェック
- staff は自分の希望提出・勤怠打刻・自分のシフト閲覧のみ
- manager は自店舗の全操作
- owner は全店舗の全操作

---

## 4. UI設計

### 4.1 画面配置

#### dashboard.html（manager / staff 画面）

既存タブに「シフト」タブを追加。

```
[メニュー] [注文] [KDS] [レポート] [設定] [AIアシスタント] [シフト]  ← 新規
```

タブ内のサブビュー:

| サブビュー | ロール | 内容 |
|-----------|--------|------|
| 週間カレンダー | manager+ | 全スタッフのシフトを週単位で表示・編集 |
| マイシフト | staff+ | 自分の確定シフト一覧 |
| 希望提出 | staff+ | 希望日時を選んで提出 |
| 勤怠一覧 | manager+ | 全スタッフの出退勤記録 |
| テンプレート | manager+ | シフトテンプレートの管理 |
| 設定 | manager+ | シフト関連の設定 |

#### owner-dashboard.html（オーナー画面）

既存の owner-app.js に「シフト」タブを追加。

- 店舗選択ドロップダウンで切替
- 全店舗横断の労働時間・人件費サマリー

#### dashboard.html 共通エリア（ヘッダー）

勤怠打刻ボタンを画面上部に常時表示:

```
[出勤] または [退勤] ← 打刻状態で切替
```

### 4.2 週間カレンダー（メイン画面）

```
         月        火        水        木        金        土        日
       4/7       4/8       4/9      4/10      4/11      4/12      4/13
───────────────────────────────────────────────────────────────────────
田中    10:00     10:00     --       10:00     10:00     --        --
       -18:00    -18:00             -18:00    -18:00
───────────────────────────────────────────────────────────────────────
鈴木    --        11:00     11:00    --        11:00     11:00     11:00
                 -20:00    -20:00             -20:00    -20:00    -20:00
───────────────────────────────────────────────────────────────────────
山田    17:00     --        17:00    17:00     --        17:00     17:00
       -22:00              -22:00   -22:00              -22:00    -22:00
───────────────────────────────────────────────────────────────────────
合計     2人       2人       2人      2人       2人       2人       2人
```

- 行: スタッフ / 列: 日付
- セルタップで編集ダイアログ（開始時刻・終了時刻・休憩・役割）
- ドラフト状態は薄い色、公開済みは通常色、確認済みは緑色
- 「テンプレート適用」ボタンで一括入力
- 「公開」ボタンでスタッフに公開

### 4.3 スタッフ希望提出画面

```
┌─────────────────────────────────┐
│  4月 第2週 シフト希望            │
│                                 │
│  4/7(月)  ○ 出勤可  10:00-18:00│
│  4/8(火)  ◎ 希望    11:00-20:00│
│  4/9(水)  × 出勤不可           │
│  4/10(木) ○ 出勤可  10:00-18:00│
│  4/11(金) ○ 出勤可  10:00-18:00│
│  4/12(土) ◎ 希望    11:00-20:00│
│  4/13(日) × 出勤不可           │
│                                 │
│  メモ: 水曜は通院のため不可     │
│                                 │
│         [提出する]               │
└─────────────────────────────────┘
```

- 日付ごとに 可/希望/不可 の3段階
- 可・希望の場合は時間帯を指定
- メモ欄で補足

### 4.4 勤怠打刻

dashboard.html のヘッダーに打刻ボタンを配置:

**未出勤時:**
```
[🟢 出勤する]  ← 緑ボタン
```

**勤務中:**
```
勤務中 09:02〜  (3h15m経過)  [🔴 退勤する]  ← 赤ボタン
```

- 打刻時に現在時刻を記録
- shift_assignments との紐付けは自動（当日のシフトがあれば紐付け）
- 打刻忘れ対策: auto_clock_out_hours 経過で自動退勤（status='timeout'）

---

## 5. JS構成

### 5.1 新規ファイル

```
public/js/shift-manager.js        -- シフト管理のメインモジュール（IIFE）
public/js/shift-calendar.js       -- 週間カレンダーの描画・操作（IIFE）
public/js/attendance-clock.js     -- 勤怠打刻ボタン（IIFE、ヘッダーに自己挿入）
```

### 5.2 ES5 IIFE パターン

```javascript
// shift-manager.js
(function() {
    'use strict';

    var ShiftManager = {
        init: function(storeId) { ... },
        loadWeek: function(startDate) { ... },
        saveAssignment: function(data) { ... },
        publishWeek: function(startDate) { ... },
        // ...
    };

    window.ShiftManager = ShiftManager;
})();
```

### 5.3 既存コードへの影響

| ファイル | 変更内容 |
|----------|---------|
| `public/dashboard.html` | シフトタブ追加（HTML）、shift-manager.js / shift-calendar.js / attendance-clock.js の script タグ追加 |
| `public/owner-dashboard.html` | シフトタブ追加（HTML）、shift-manager.js の script タグ追加 |
| `public/js/owner-app.js` | シフトタブの初期化処理追加（最小限） |

**既存のscriptタグ（voice-commander.js等）は一切削除しない。**

---

## 6. 勤怠打刻の詳細仕様

### 6.1 打刻ルール

| ルール | 仕様 |
|--------|------|
| 出勤打刻 | 現在時刻を clock_in に記録。シフト開始の early_clock_in_minutes 前から可能 |
| 退勤打刻 | 現在時刻を clock_out に記録 |
| 打刻忘れ | auto_clock_out_hours 経過で自動退勤。status='timeout'。マネージャーに通知 |
| 休憩 | 手動入力（デフォルト値は shift_settings から）。Phase 2 で休憩打刻ボタン追加検討 |
| 修正 | マネージャーのみ。attendance_logs を PATCH。audit_log に記録 |
| シフトなし出勤 | shift_assignment_id = NULL で記録可。急な呼び出し等に対応 |

### 6.2 ログインとの連動

ログイン時に「出勤しますか？」のダイアログは**出さない**。
理由: ログイン＝出勤ではない（設定確認等でログインすることがある）。
打刻は明示的なボタン操作のみ。

### 6.3 audit_log 連携

打刻操作は既存の audit_log に記録:

```
action: 'attendance_clock_in' / 'attendance_clock_out' / 'attendance_edit'
entity_type: 'attendance'
entity_id: attendance_logs.id
```

---

## 7. マルチテナント境界

全テーブル・全クエリに `tenant_id` を含める。
`store_id` でさらに店舗単位に絞り込む。

```sql
-- 例: シフト一覧取得
SELECT * FROM shift_assignments
WHERE tenant_id = ? AND store_id = ? AND shift_date BETWEEN ? AND ?
ORDER BY shift_date, start_time;
```

---

## 8. 実装順序（Phase 1）

### Step 1: マイグレーション + 設定API
- `sql/migration-l3-shift-management.sql` 作成
- `api/store/shift/settings.php` 作成
- plan_features に shift_management 追加

### Step 2: シフトテンプレートCRUD
- `api/store/shift/templates.php` 作成
- dashboard.html にシフトタブ骨格追加
- shift-manager.js のテンプレート管理部分

### Step 3: シフト割当CRUD + 週間カレンダー
- `api/store/shift/assignments.php` 作成
- shift-calendar.js 作成
- テンプレート適用 → 一括生成機能
- ドラフト → 公開 フロー

### Step 4: 希望提出
- `api/store/shift/availabilities.php` 作成
- スタッフ用希望提出UI

### Step 5: 勤怠打刻
- `api/store/shift/attendance.php` 作成
- attendance-clock.js 作成（ヘッダー自己挿入）
- 自動退勤のcronスクリプト

### Step 6: 集計 + オーナー画面
- `api/store/shift/summary.php` 作成
- owner-dashboard.html にシフトタブ追加
- 労働時間・人件費サマリー表示

### Step 7: テスト + 統合
- 全API動作確認
- UI統合テスト
- マルチテナント境界テスト

---

## 9. デプロイファイル一覧（Phase 1 完了時）

### 新規ファイル
```
sql/migration-l3-shift-management.sql
api/store/shift/templates.php
api/store/shift/assignments.php
api/store/shift/availabilities.php
api/store/shift/attendance.php
api/store/shift/settings.php
api/store/shift/summary.php
public/js/shift-manager.js
public/js/shift-calendar.js
public/js/attendance-clock.js
```

### 変更ファイル
```
public/dashboard.html          -- シフトタブ追加 + scriptタグ追加
public/owner-dashboard.html    -- シフトタブ追加 + scriptタグ追加
public/js/owner-app.js         -- シフトタブ初期化追加
```

---

## 10. Phase 2 以降（参考）

### Phase 2: AI強化
- Gemini APIで売上データ×曜日×時間帯から最適スタッフ数を提案
- シフト編集中の人件費リアルタイムシミュレーション
- 労基法チェック: 週40時間超過、6時間超の休憩未設定、深夜割増アラート

### Phase 3: マルチ店舗（enterprise）
- 店舗間ヘルプ要請・承認フロー
- 全店舗統合シフトビュー
- 統合人件費レポート
