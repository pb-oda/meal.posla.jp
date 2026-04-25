# L-3 Phase 3 — マルチ店舗シフト管理

- 作成日: 2026-04-06
- ステータス: 設計
- プラン: enterprise 限定
- 前提: Phase 1（MVP）+ Phase 2（AI強化）実装済み

---

## 1. 概要

### 1.1 目的

Phase 3 はシフト管理をマルチ店舗に拡張する。

| # | 機能 | 概要 | 対象ロール |
|---|------|------|-----------|
| 1 | 店舗間ヘルプ要請 | 人手不足時に他店舗へスタッフ派遣を依頼するワークフロー | manager |
| 2 | 統合シフトビュー | オーナーが全店舗のシフト状況を一画面で横断的に把握 | owner |

### 1.2 ユーザーストーリー

**マネージャー（ヘルプ要請）**
- 渋谷店マネージャーとして、金曜夜にスタッフが2名足りない → 新宿店にヘルプ要請を送信
- 新宿店マネージャーとして、渋谷店からの要請を確認 → スタッフ1名を指名して承認
- 承認後、渋谷店のシフトカレンダーにヘルプスタッフが自動表示される

**オーナー（統合ビュー）**
- 全店舗の来週のシフト充足率を一画面で確認
- 人件費合計を店舗横並びで比較
- ヘルプ要請の状態（pending/approved）を横断的に把握

### 1.3 Phase 依存関係

```
Phase 1（MVP）
  └→ Phase 2（AI強化・人件費・労基法）
       └→ Phase 3（マルチ店舗）← 本ドキュメント
```

Phase 3 は Phase 2 の以下に依存:
- `shift_settings.default_hourly_rate` — 統合ビューの人件費集計
- `summary.php` の `labor_cost_total` — 店舗別人件費データ
- `shift_assignments` テーブル — ヘルプスタッフの割当先

---

## 2. 機能詳細

### 2a. 店舗間ヘルプ要請

#### ワークフロー

```
(1) マネージャーA（渋谷店）が要請作成
    → status: pending
    → 新宿店マネージャーに通知表示

(2) マネージャーB（新宿店）が要請を確認
    → 承認: スタッフ指名 → status: approved
    → 却下: 理由を記入 → status: rejected
    → マネージャーAがキャンセル → status: cancelled

(3) 承認後の自動処理
    → 指名されたスタッフの shift_assignments に
      渋谷店(from_store)のレコードを自動作成
    → 渋谷店カレンダーに「ヘルプ」バッジ付きで表示
    → 新宿店カレンダーには「派遣中」バッジで表示
```

#### ステータス遷移

```
pending ──→ approved（承認 + スタッフ指名）
   │
   ├──→ rejected（却下）
   │
   └──→ cancelled（依頼者がキャンセル）
```

#### 表示

- ヘルプスタッフのシフトは **他店舗色**（例: オレンジ背景）で表示
- スタッフ名の横に「(新宿店)」等の出身店舗を表示
- ヘルプ元・ヘルプ先の両方のカレンダーに表示

### 2b. 統合シフトビュー

#### 概要

オーナーダッシュボードの「シフト概況」タブを拡張し、全店舗を一覧表示する。

#### 表示内容

| セクション | 内容 |
|-----------|------|
| 店舗カード一覧 | 店舗名 / 週の出勤人数合計 / 推定人件費 / 充足率 |
| ヘルプ要請一覧 | pending件数 / approved件数 / 要請元→要請先 |
| 人件費比較 | 店舗横並び棒グラフ（テキストベース） |

#### 操作

- 週次/月次切替
- 日付ナビゲーション（前の週/次の週）
- 店舗カードクリック → 既存の1店舗サマリー表示に遷移

---

## 3. データベース設計

### 3.1 新規テーブル: shift_help_requests

```sql
CREATE TABLE shift_help_requests (
    id                  VARCHAR(36) NOT NULL,
    tenant_id           VARCHAR(36) NOT NULL,
    from_store_id       VARCHAR(36) NOT NULL COMMENT '要請元（人手不足の店舗）',
    to_store_id         VARCHAR(36) NOT NULL COMMENT '要請先（スタッフを派遣する店舗）',
    requested_date      DATE NOT NULL COMMENT 'ヘルプ希望日',
    start_time          TIME NOT NULL,
    end_time            TIME NOT NULL,
    requested_staff_count INT NOT NULL DEFAULT 1,
    role_hint           VARCHAR(20) NULL COMMENT 'kitchen / hall / NULL',
    status              ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requesting_user_id  VARCHAR(36) NOT NULL COMMENT '依頼者（from_storeのマネージャー）',
    responding_user_id  VARCHAR(36) NULL COMMENT '承認/却下者（to_storeのマネージャー）',
    note                TEXT NULL COMMENT '依頼メモ',
    response_note       TEXT NULL COMMENT '回答メモ',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_shr_tenant (tenant_id),
    INDEX idx_shr_from (tenant_id, from_store_id, status),
    INDEX idx_shr_to (tenant_id, to_store_id, status),
    INDEX idx_shr_date (tenant_id, requested_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 新規テーブル: shift_help_assignments

ヘルプ要請に対して実際に派遣されるスタッフを管理する中間テーブル。

```sql
CREATE TABLE shift_help_assignments (
    id                  VARCHAR(36) NOT NULL,
    help_request_id     VARCHAR(36) NOT NULL,
    user_id             VARCHAR(36) NOT NULL COMMENT '派遣されるスタッフ',
    shift_assignment_id VARCHAR(36) NULL COMMENT '自動作成されたshift_assignmentsのID',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_sha_request (help_request_id),
    INDEX idx_sha_user (user_id),
    FOREIGN KEY (help_request_id) REFERENCES shift_help_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 設計判断: JSON vs 中間テーブル

| 方式 | Pros | Cons |
|------|------|------|
| JSON カラム (`assigned_user_ids`) | シンプル、テーブル1つで完結 | JOINできない、個別ステータス管理不可、MySQL 5.7のJSON関数が限定的 |
| **中間テーブル（採用）** | JOINで検索可能、shift_assignment_idで紐付け可能、将来の拡張容易 | テーブル1つ増加 |

→ **中間テーブル方式を採用**。shift_assignment_id で自動作成されたシフトとの紐付けが可能。

### 3.3 shift_assignments への追加カラム

```sql
ALTER TABLE shift_assignments
    ADD COLUMN help_request_id VARCHAR(36) NULL DEFAULT NULL
    COMMENT 'ヘルプ要請経由で作成された場合の要請ID',
    ADD INDEX idx_sa_help (help_request_id);
```

これにより:
- `help_request_id IS NOT NULL` → ヘルプスタッフのシフト
- カレンダー表示時に「ヘルプ」バッジを出す判定に使用

### 3.4 plan_features 追加

```sql
INSERT INTO plan_features (plan, feature_key, enabled) VALUES
('enterprise', 'shift_help_request', 1);
```

### 3.5 マイグレーションファイル

ファイル名: `sql/migration-l3p3-multi-store-shift.sql`

```sql
-- L-3 Phase 3: マルチ店舗シフト管理
-- shift_help_requests テーブル
-- shift_help_assignments テーブル
-- shift_assignments.help_request_id カラム
-- plan_features: shift_help_request

CREATE TABLE IF NOT EXISTS shift_help_requests ( ... );
CREATE TABLE IF NOT EXISTS shift_help_assignments ( ... );

ALTER TABLE shift_assignments
    ADD COLUMN IF NOT EXISTS help_request_id VARCHAR(36) NULL DEFAULT NULL
    COMMENT 'ヘルプ要請経由で作成された場合の要請ID',
    ADD INDEX idx_sa_help (help_request_id);

INSERT IGNORE INTO plan_features (plan, feature_key, enabled) VALUES
('enterprise', 'shift_help_request', 1);
```

### 3.6 影響範囲

| テーブル | 変更 | 既存への影響 |
|---------|------|------------|
| shift_help_requests | 新規 | なし |
| shift_help_assignments | 新規 | なし |
| shift_assignments | NULLカラム追加 | なし（NULL = 通常シフト） |
| plan_features | INSERT 1行 | なし |

---

## 4. API設計

### 4.1 新規エンドポイント

| ファイル | メソッド | 用途 | 権限 |
|---------|---------|------|------|
| `api/store/shift/help-requests.php` | GET | ヘルプ要請一覧（送信/受信） | manager+ |
| | POST | ヘルプ要請作成 | manager+ |
| | PATCH | 承認/却下/キャンセル | manager+ |
| `api/owner/shift/unified-view.php` | GET | 全店舗シフト横断サマリー | owner |

### 4.2 既存エンドポイント拡張

| ファイル | 変更 | 内容 |
|---------|------|------|
| `api/store/shift/assignments.php` | GET拡張 | help_request_id を返却。ヘルプスタッフの出身店舗名を付加 |

### 4.3 help-requests.php 詳細

#### GET ?store_id=xxx

送信・受信の両方を返す。

```
認証: require_auth() + require_role('manager')
プラン: check_plan_feature($pdo, $tenantId, 'shift_help_request')
```

レスポンス:
```json
{
    "ok": true,
    "data": {
        "sent": [
            {
                "id": "req-001",
                "from_store_id": "s-shibuya-001",
                "from_store_name": "松の屋 渋谷店",
                "to_store_id": "s-shinjuku-001",
                "to_store_name": "松の屋 新宿店",
                "requested_date": "2026-04-15",
                "start_time": "17:00",
                "end_time": "22:00",
                "requested_staff_count": 2,
                "role_hint": "hall",
                "status": "pending",
                "requesting_user_id": "u-manager-001",
                "requesting_user_name": "渋谷マネージャー",
                "note": "金曜夜の繁忙に備えてホール2名お願いします",
                "assigned_staff": [],
                "created_at": "2026-04-10T10:00:00"
            }
        ],
        "received": [
            {
                "id": "req-002",
                "from_store_id": "s-shinjuku-001",
                "from_store_name": "松の屋 新宿店",
                "to_store_id": "s-shibuya-001",
                "to_store_name": "松の屋 渋谷店",
                "requested_date": "2026-04-16",
                "start_time": "11:00",
                "end_time": "15:00",
                "requested_staff_count": 1,
                "role_hint": null,
                "status": "pending",
                "requesting_user_id": "u-manager-002",
                "requesting_user_name": "新宿マネージャー",
                "note": "ランチ帯に1名足りません",
                "assigned_staff": [],
                "created_at": "2026-04-10T11:00:00"
            }
        ]
    }
}
```

#### POST (要請作成)

リクエスト:
```json
{
    "store_id": "s-shibuya-001",
    "to_store_id": "s-shinjuku-001",
    "requested_date": "2026-04-15",
    "start_time": "17:00",
    "end_time": "22:00",
    "requested_staff_count": 2,
    "role_hint": "hall",
    "note": "金曜夜の繁忙に備えてホール2名お願いします"
}
```

バリデーション:
- `to_store_id` が同一テナント内の別店舗であること
- `requested_date` が今日以降であること
- `start_time < end_time`
- `requested_staff_count` が 1〜10

レスポンス: 作成された要請オブジェクト（201）

#### PATCH ?id=xxx (承認/却下/キャンセル)

**承認（to_store のマネージャー）:**
```json
{
    "store_id": "s-shinjuku-001",
    "action": "approve",
    "assigned_user_ids": ["user-a", "user-b"],
    "response_note": "田中と佐藤を派遣します"
}
```

処理:
1. status を `approved` に更新
2. `shift_help_assignments` に指名スタッフを INSERT
3. 指名スタッフの `shift_assignments` に from_store のレコードを自動作成
   - `help_request_id` をセット
   - `status = 'published'`（承認済みなので即確定）
   - `created_by` = 承認したマネージャー

**却下（to_store のマネージャー）:**
```json
{
    "store_id": "s-shinjuku-001",
    "action": "reject",
    "response_note": "申し訳ありません、当日は全員出勤済みです"
}
```

**キャンセル（from_store のマネージャー）:**
```json
{
    "store_id": "s-shibuya-001",
    "action": "cancel"
}
```

処理: status が `pending` の場合のみキャンセル可能。

### 4.4 unified-view.php 詳細

#### GET ?period=weekly&date=2026-04-14

```
認証: require_auth() + require_role('owner')
プラン: check_plan_feature($pdo, $tenantId, 'shift_help_request')
```

処理:
1. テナント内の全店舗を取得
2. 各店舗の summary.php と同等の集計を実行（ループ）
3. ヘルプ要請の pending/approved 件数を集計

レスポンス:
```json
{
    "ok": true,
    "data": {
        "period": "2026-04-14 〜 2026-04-20",
        "stores": [
            {
                "store_id": "s-shibuya-001",
                "store_name": "松の屋 渋谷店",
                "total_staff_count": 12,
                "total_hours": 84.5,
                "labor_cost_total": 123000,
                "night_premium_total": 4500,
                "assigned_days": 7,
                "template_required_total": 14,
                "fulfillment_rate": 85.7,
                "help_sent_pending": 1,
                "help_sent_approved": 0,
                "help_received_pending": 0,
                "help_received_approved": 1
            },
            {
                "store_id": "s-shinjuku-001",
                "store_name": "松の屋 新宿店",
                "total_staff_count": 8,
                "total_hours": 56.0,
                "labor_cost_total": 89000,
                "night_premium_total": 2000,
                "assigned_days": 7,
                "template_required_total": 10,
                "fulfillment_rate": 80.0,
                "help_sent_pending": 0,
                "help_sent_approved": 0,
                "help_received_pending": 1,
                "help_received_approved": 0
            }
        ],
        "help_requests_summary": {
            "total_pending": 2,
            "total_approved": 1,
            "total_rejected": 0
        }
    }
}
```

---

## 5. フロントエンド設計

### 5.1 ファイル構成

| ファイル | 種別 | 内容 |
|---------|------|------|
| `public/admin/js/shift-help.js` | 新規 | ヘルプ要請 IIFE モジュール |
| `public/admin/js/owner-app.js` | 修正 | 統合シフトビュー追加 |
| `public/admin/dashboard.html` | 修正 | ヘルプサブタブ追加 |
| `public/admin/owner-dashboard.html` | 修正 | scriptタグ追加（不要の可能性あり） |

### 5.2 ヘルプ要請UI（dashboard.html — マネージャー画面）

#### サブタブ追加

シフトグループに「ヘルプ」サブタブを追加:

```html
<button class="sub-tab-btn" data-tab="shift-help">ヘルプ</button>
```

パネル:
```html
<div id="panel-shift-help" class="shift-view">
    <div id="shift-view-help"></div>
</div>
```

#### shift-help.js IIFE 構造

```javascript
var ShiftHelp = (function() {
    'use strict';

    var _storeId = null;
    var _otherStores = []; // 同一テナント内の他店舗

    function init(containerId, storeId) { ... }
    function loadRequests() { ... }
    function _renderRequests(sent, received) { ... }
    function _showCreateDialog() { ... }
    function _approveRequest(requestId) { ... }
    function _rejectRequest(requestId) { ... }
    function _cancelRequest(requestId) { ... }

    return {
        init: init,
        loadRequests: loadRequests
    };
})();
```

#### 要請作成ダイアログ

```
┌─────────────────────────────┐
│ ヘルプ要請                    │
├─────────────────────────────┤
│ 送信先店舗: [新宿店 ▼]        │
│ 日付:      [2026-04-15]      │
│ 開始時刻:  [17:00]           │
│ 終了時刻:  [22:00]           │
│ 必要人数:  [2]               │
│ 役割:      [ホール ▼]        │
│ メモ:      [テキスト入力]      │
│                             │
│     [送信]  [キャンセル]       │
└─────────────────────────────┘
```

#### 受信要請の承認ダイアログ

```
┌─────────────────────────────────┐
│ ヘルプ要請の承認                   │
├─────────────────────────────────┤
│ 渋谷店からの要請:                  │
│ 4/15(金) 17:00-22:00 ホール×2名  │
│ メモ: 金曜夜の繁忙に備えて...       │
│                                 │
│ 派遣するスタッフを選択:             │
│ ☑ 田中一郎                       │
│ ☑ 佐藤美咲                       │
│ ☐ 鈴木花子                       │
│                                 │
│ 回答メモ: [テキスト入力]            │
│                                 │
│     [承認]  [却下]  [キャンセル]    │
└─────────────────────────────────┘
```

スタッフ一覧は to_store の staff ロールユーザーを表示。
該当日に既にシフトが入っているスタッフは「(シフトあり)」警告表示。

#### 要請一覧の表示

**送信タブ:**

| 日付 | 時間帯 | 送信先 | 人数 | 状態 | 操作 |
|------|--------|--------|------|------|------|
| 4/15 | 17:00-22:00 | 新宿店 | 2名 | 待機中 | キャンセル |
| 4/12 | 11:00-15:00 | 新宿店 | 1名 | 承認済 | — |

**受信タブ:**

| 日付 | 時間帯 | 送信元 | 人数 | 状態 | 操作 |
|------|--------|--------|------|------|------|
| 4/16 | 11:00-15:00 | 新宿店 | 1名 | 待機中 | 承認 / 却下 |

### 5.3 統合シフトビュー（owner-dashboard.html）

既存の「シフト概況」タブを拡張する（新タブは作らない）。

#### 現在の「シフト概況」

店舗セレクト → 1店舗のサマリー表示

#### 拡張後

**全店舗モード**（デフォルト）:
```
┌───────────────────────────────────────────┐
│ シフト概況          週次 ▼  ◀ 2026-04-14 〜 04-20 ▶ │
├───────────────────────────────────────────┤
│                                           │
│ ┌─────────────┐ ┌─────────────┐          │
│ │ 渋谷店       │ │ 新宿店       │          │
│ │ 出勤: 20人   │ │ 出勤: 15人   │          │
│ │ 人件費: ¥123K│ │ 人件費: ¥89K │          │
│ │ 充足率: 86%  │ │ 充足率: 80%  │          │
│ │ ヘルプ要請: 1 │ │ ヘルプ要請: 0 │          │
│ │ [詳細を見る]  │ │ [詳細を見る]  │          │
│ └─────────────┘ └─────────────┘          │
│                                           │
│ ── ヘルプ要請 ──                            │
│ 渋谷店 → 新宿店: 4/15 17-22時 ホール×2 [待機中]│
│                                           │
│ ── 人件費比較 ──                            │
│ 渋谷店: ████████████ ¥123,000             │
│ 新宿店: ████████     ¥89,000              │
│                                           │
└───────────────────────────────────────────┘
```

**詳細モード**（店舗カードクリック後）:
既存の loadShiftSummary() を呼び出し、1店舗のサマリーを表示。
「← 全店舗に戻る」リンクで全店舗モードに復帰。

### 5.4 シフトカレンダー表示拡張（shift-calendar.js）

ヘルプスタッフのシフトを区別して表示:

- `help_request_id` が非NULLの shift_assignment → オレンジ背景 + 「ヘルプ」バッジ
- スタッフ名の後に出身店舗名を括弧表示: `田中一郎 (新宿店)`

assignments.php GET レスポンスに追加:
```json
{
    "id": "sa-xxx",
    "user_id": "user-a",
    "help_request_id": "req-001",
    "helper_store_name": "新宿店"
}
```

---

## 6. 実装順序

### Step 1: マイグレーション

`sql/migration-l3p3-multi-store-shift.sql` 作成:
- shift_help_requests テーブル
- shift_help_assignments テーブル
- shift_assignments.help_request_id カラム追加
- plan_features INSERT

### Step 2: help-requests.php

`api/store/shift/help-requests.php` 新規作成:
- GET: 送信/受信の要請一覧
- POST: 要請作成
- PATCH: 承認（+ shift_assignments 自動作成）/ 却下 / キャンセル

### Step 3: shift-help.js

`public/admin/js/shift-help.js` 新規作成:
- IIFE モジュール
- 要請一覧（送信/受信タブ）
- 作成ダイアログ
- 承認/却下ダイアログ

### Step 4: dashboard.html 統合

- ヘルプサブタブ追加
- activateTab に shift-help ケース追加
- shift-help.js の script タグ追加

### Step 5: assignments.php 拡張

- GET レスポンスに help_request_id + helper_store_name 追加
- ヘルプスタッフも users 一覧に含める（help_request_id が存在する shift_date 範囲のスタッフ）

### Step 6: shift-calendar.js ヘルプ表示

- ヘルプスタッフのセルをオレンジ背景 + バッジで表示
- ヘルプスタッフ名に出身店舗名を付記

### Step 7: unified-view.php

`api/owner/shift/unified-view.php` 新規作成:
- 全店舗のサマリー一括取得
- ヘルプ要請集計

### Step 8: owner-app.js 統合ビュー

- initShiftOverview() を拡張
- 全店舗カード表示
- ヘルプ要請一覧
- 人件費比較バー
- 詳細 ↔ 全店舗 切替

### Step 9: キャッシュバスター + テスト

- dashboard.html, owner-dashboard.html の script タグに ?v= 更新

---

## 7. デプロイファイル一覧

### 新規ファイル（4）

| ファイル | 内容 |
|---------|------|
| `sql/migration-l3p3-multi-store-shift.sql` | DB マイグレーション |
| `api/store/shift/help-requests.php` | ヘルプ要請 CRUD + 承認 |
| `api/owner/shift/unified-view.php` | 統合シフトビュー API |
| `public/admin/js/shift-help.js` | ヘルプ要請 UI モジュール |

### 修正ファイル（5）

| ファイル | 変更内容 |
|---------|---------|
| `api/store/shift/assignments.php` | GET に help_request_id, helper_store_name 追加 |
| `public/js/shift-calendar.js` | ヘルプスタッフの色分け表示 |
| `public/admin/js/owner-app.js` | 統合シフトビュー（全店舗モード） |
| `public/admin/dashboard.html` | ヘルプサブタブ + script タグ追加 |
| `public/admin/owner-dashboard.html` | キャッシュバスター更新 |

**合計: 新規4 + 修正5 = 9ファイル**

---

## 8. 見積り

| Step | 内容 | ファイル数 | 規模 |
|------|------|----------|------|
| 1 | マイグレーション | 1 | 小 |
| 2 | help-requests.php | 1 | 大（承認フロー + 自動割当） |
| 3 | shift-help.js | 1 | 大（ダイアログ + 一覧 + タブ） |
| 4 | dashboard.html 統合 | 1 | 小 |
| 5 | assignments.php 拡張 | 1 | 中（JOIN追加 + フィールド追加） |
| 6 | shift-calendar.js ヘルプ表示 | 1 | 中（条件分岐 + スタイル） |
| 7 | unified-view.php | 1 | 大（全店舗ループ集計） |
| 8 | owner-app.js 統合ビュー | 1 | 大（カード + 比較 + 切替） |
| 9 | キャッシュバスター + テスト | 2 | 小 |

**重量ステップ: Step 2, 3, 7, 8**

---

## 9. セルフチェック

- [x] 全テーブルに tenant_id カラムとインデックスがあるか → shift_help_requests: あり
- [x] 全クエリがプリペアドステートメント前提か → 設計段階で確認済み
- [x] ES5 互換か → IIFE + var + コールバック
- [x] プランチェックが全エンドポイントにあるか → check_plan_feature('shift_help_request')
- [x] innerHTML は escapeHtml 前提か → はい
- [x] 既存機能に破壊的変更がないか → shift_assignments に NULL カラム追加のみ
- [x] マイグレーションに IF NOT EXISTS があるか → はい

---

## 10. 注意事項

### 10.1 ヘルプスタッフの勤怠打刻

ヘルプスタッフは from_store（要請元）で勤務する。打刻は from_store の GPS 設定に従う。
attendance_logs には from_store の store_id で記録される。

### 10.2 他店舗スタッフ一覧の取得

承認ダイアログでは to_store のスタッフ一覧を取得する。
`api/store/shift/help-requests.php` の PATCH 時に `assigned_user_ids` の各ユーザーが
to_store に所属していることを検証する。

### 10.3 重複シフトチェック

承認時、指名スタッフが requested_date に既に from_store 以外のシフトを持っている場合は
警告を返す（エラーではなく警告。マネージャーの判断で続行可能）。

### 10.4 ヘルプ要請のキャンセルと割当削除

status が `approved` の要請をキャンセルする場合:
- 自動作成された shift_assignments も削除する（help_request_id で特定）
- shift_help_assignments のレコードも CASCADE で削除

→ Phase 3 では `approved` 後のキャンセルは不可とする（シンプル化）。
  必要であれば手動で shift_assignments を削除する運用。

### 10.5 データ量とパフォーマンス

統合シフトビュー（unified-view.php）は全店舗をループする。
店舗数が多い場合（10+）、レスポンスが遅くなる可能性がある。

対策:
- 店舗数が多い場合はページネーション（1ページ5店舗）
- 集計クエリは summary.php の既存ロジックを関数化して共有（コピペ回避）
