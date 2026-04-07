# L-3 Phase 2 — AI最適シフト提案・人件費シミュレーション・労基法チェック 設計ドキュメント

> **作成日:** 2026-04-06
> **ステータス:** 設計確定 → 実装待ち
> **プラン:** pro / enterprise
> **前提:** Phase 1 実装済み（5テーブル + 8APIエンドポイント + 3JSモジュール）

---

## 1. 概要

### 1.1 Phase 2の目的

Phase 1（MVP）でシフト作成・勤怠打刻の基盤が整った。Phase 2では以下の3機能を追加し、**シフト管理を「手作業」から「データドリブン」へ進化**させる。

| 機能 | 種別 | 差別化ポイント |
|------|------|---------------|
| **AI最適シフト提案** | Gemini API | POS売上データ連動。競合サービスにない強み |
| **人件費シミュレーション** | PHP集計 | シフト編集中にリアルタイムで人件費が見える |
| **労基法チェック** | ルールベース（PHP） | 違反を自動検出して警告バッジ表示 |

### 1.2 Phase 1との依存関係

| Phase 1のデータ | Phase 2での用途 |
|----------------|---------------|
| `shift_templates` | AI提案の枠組み（テンプレート×スタッフの組合せを提案） |
| `shift_assignments` | 人件費シミュレーションの入力。労基法チェック対象 |
| `shift_availabilities` | AI提案がスタッフの希望を考慮 |
| `attendance_logs` | 勤怠実績からAIが傾向を学習。人件費の実績計算 |
| `shift_settings` | 残業閾値・休憩デフォルト値・GPS設定 |
| `summary.php` 既存集計 | スタッフ別労働時間を既に算出。Phase 2はこれを拡張 |
| `demand-forecast-data.php` | 売上データ集計パターン（Phase 2で流用） |
| `ai-assistant.js` → `_callGemini` | Gemini API呼び出しパターン（Phase 2で流用） |

---

## 2. 機能詳細

### 2a. AI最適シフト提案

#### 概要

過去の売上データ・テンプレート・スタッフ希望・勤怠実績をGemini APIに投入し、「来週のおすすめシフト割当」をJSON形式で返す。マネージャーが確認→採用/修正→カレンダーに反映する流れ。

#### 入力データ（PHPサーバーサイドで集計）

| データ | 取得元 | 内容 |
|--------|--------|------|
| 過去売上 | `orders` テーブル（demand-forecast-data.phpパターン） | 直近4週間の時間帯別・曜日別注文数。繁忙/閑散パターン抽出 |
| テンプレート | `shift_templates` | 既存の枠組み（早番/遅番、kitchen/hall、必要人数） |
| スタッフ希望 | `shift_availabilities` | 対象期間の希望（available/preferred/unavailable） |
| 勤怠実績 | `attendance_logs` | 直近4週間のスタッフ別出勤パターン・遅刻率・実労働時間 |
| スタッフ一覧 | `users` + `user_stores` | 所属スタッフのID・表示名 |
| 時給情報 | `user_stores.hourly_rate`（新規カラム）+ `shift_settings.default_hourly_rate`（新規カラム） | 人件費最適化に使用 |

#### 出力（Gemini → JSON）

```json
{
  "suggestions": [
    {
      "user_id": "xxx",
      "template_id": "yyy",
      "shift_date": "2026-04-14",
      "start_time": "10:00",
      "end_time": "15:00",
      "role_type": "hall",
      "reason": "月曜ランチは注文数が多い傾向。田中さんは月曜希望かつ出勤率が高い"
    }
  ],
  "summary": "来週の推奨シフト。月曜ランチにホール2名推奨（平均注文数45件/日）。水曜は閑散傾向のため最少1名で対応可能。",
  "estimated_labor_cost": 185000,
  "warnings": ["鈴木さんは4週連続で土日出勤。負担軽減を検討"]
}
```

#### Geminiプロンプト設計

```
あなたは飲食店のシフト管理AIです。

以下のデータに基づき、{start_date}〜{end_date}の最適なシフト割当を提案してください。

## ルール
- テンプレートの必要人数を満たすように割り当てる
- スタッフの希望（preferred > available > unavailable=割当不可）を尊重する
- 過去の売上データから繁忙/閑散を判断し、人数を調整する
- 連続勤務6日以上を避ける
- 週40時間を超えないようにする
- 各スタッフの勤務時間が偏らないようにする
- 時給を考慮し、人件費を最適化する（ただし繁忙時の人員不足は避ける）

## 出力
以下のJSON形式のみ出力。前置き・解説・補足は禁止。
{suggestionsの構造}
```

- **temperature:** 0.8（多様な提案を生成するため。ただしJSON形式制約あり）
- **maxOutputTokens:** 4096（スタッフ数×7日分のデータを返すため）
- **model:** gemini-2.0-flash

#### UI配置

**カレンダー画面に「AI提案」ボタンを追加する。**

理由: テンプレート適用ボタンと並べることで「手動適用 / AI提案」の自然な選択肢になる。別タブだとワークフローが途切れる。

```
[◀ 前の週] [2026-04-14 〜 2026-04-20] [次の週 ▶]
[テンプレート適用] [🤖 AI提案] [確定する]
```

ボタン押下 → データ集計API呼び出し → Gemini API呼び出し → 結果をダイアログで表示 → 「採用する」ボタンで一括投入。

#### AIデータ集計API

**新規エンドポイント:** `api/store/shift/ai-suggest-data.php`

demand-forecast-data.php と同パターン。PHPでデータを集約し、フロントでGemini直接呼び出し。

```
GET /api/store/shift/ai-suggest-data.php
  ?store_id=xxx
  &start_date=2026-04-14
  &end_date=2026-04-20
```

レスポンス:
```json
{
  "ok": true,
  "data": {
    "templates": [...],
    "availabilities": [...],
    "staffList": [{ "id": "xxx", "display_name": "田中", "hourly_rate": 1200, "recent_hours": 32 }],
    "salesByDayHour": [
      { "weekday": 1, "hour": 11, "avg_orders": 12.5 },
      { "weekday": 1, "hour": 12, "avg_orders": 18.2 }
    ],
    "recentAttendance": [
      { "user_id": "xxx", "days_worked": 5, "late_count": 0, "avg_hours": 7.2, "consecutive_days": 3 }
    ],
    "targetPeriod": { "start_date": "2026-04-14", "end_date": "2026-04-20" }
  }
}
```

#### フロント処理フロー（shift-calendar.js内に追加）

1. 「AI提案」ボタン → `ai-suggest-data.php` 呼び出し
2. データをGeminiプロンプトに埋め込み → `_callGemini()` 呼び出し
3. JSON応答をパース → ダイアログに提案表示（スタッフ×日付のテーブル + 理由 + 推定人件費 + 警告）
4. 「採用する」→ `assignments.php` POST を繰り返して一括登録
5. カレンダーをリロード

---

### 2b. 人件費シミュレーション

#### 概要

シフト割当（確定 or AI提案）に基づき、週次/月次の人件費見積りを表示する。シフト編集中にリアルタイムで「この変更で人件費がいくら変わるか」が見える。

#### 時給情報の設計

**現状のDBに時給カラムはない。** 以下の2カラムを新規追加する。

| 追加先 | カラム | 型 | 説明 |
|--------|--------|-----|------|
| `shift_settings` | `default_hourly_rate` | `INT NULL DEFAULT NULL` | 店舗デフォルト時給（円）。全スタッフ共通の基準値 |
| `user_stores` | `hourly_rate` | `INT NULL DEFAULT NULL` | スタッフ個別時給（円）。NULLの場合は店舗デフォルトを使用 |

**設計判断:**

| 案 | メリット | デメリット | 採否 |
|----|---------|-----------|------|
| A: shift_settings に default_hourly_rate | 店舗単位の一括設定。シンプル | 個人差がつけられない | **採用（ベース）** |
| B: user_stores に hourly_rate | スタッフ個別時給。研修中/ベテランで差をつけられる | 全員に設定が必要 | **採用（オーバーライド）** |
| C: 別テーブル hourly_rates | 時給改定履歴を保持できる | 過剰設計。POSLAの規模では不要 | 不採用 |

**優先順位:** user_stores.hourly_rate（個別） > shift_settings.default_hourly_rate（店舗） > 未設定（人件費計算スキップ）

#### 深夜手当計算

**含める。** 労働基準法第37条第4項に基づき、22:00〜05:00の勤務時間に25%割増を自動計算する。

計算ロジック（PHP）:
```
深夜勤務分数 = シフトの22:00〜翌05:00にかかる時間（分）
通常分数 = 総勤務分数 - 休憩分数 - 深夜勤務分数
人件費 = (通常分数 / 60 × 時給) + (深夜勤務分数 / 60 × 時給 × 1.25)
```

#### 出力

| 項目 | 内容 |
|------|------|
| **スタッフ別** | スタッフ名・勤務時間・通常時給額・深夜割増額・合計 |
| **日別** | 日付・出勤人数・合計人件費 |
| **週次/月次合計** | 総勤務時間・総人件費・深夜手当合計 |

#### UI配置

**既存summary.php / サマリータブを拡張。** summary.phpが既にスタッフ別労働時間・日別集計を返しているので、人件費カラムを追加する。

```
サマリー表示（既存）:
  スタッフ | 出勤日数 | 労働時間 | 残業 | 遅刻

サマリー表示（Phase 2拡張）:
  スタッフ | 出勤日数 | 労働時間 | 残業 | 深夜 | 遅刻 | 時給 | 人件費
  ─────────────────────────────────────────────────
  田中      5日       40.0h     0h    0h    0回   ¥1,200  ¥48,000
  鈴木      4日       35.5h     0h    3.5h  1回   ¥1,100  ¥40,388
  ─────────────────────────────────────────────────
  合計      9日       75.5h     0h    3.5h  1回           ¥88,388
  （うち深夜手当: ¥1,138）
```

#### シフト編集時リアルタイム表示

カレンダーの合計行にも週間の推定人件費を表示する:

```
合計   2人    2人    2人    2人    2人    2人    2人  | 推定 ¥168,000/週
```

この値はフロントサイドでシフト割当データ × 時給 × 深夜判定で計算する（APIコールなし）。

---

### 2c. 労基法チェック

#### 概要

シフト割当と勤怠実績をルールベース（Gemini不使用）で検査し、労基法違反の可能性がある箇所に警告を表示する。

#### チェック項目

| # | チェック | 基準 | 根拠 | 重要度 |
|---|---------|------|------|--------|
| 1 | **週40時間超過** | 週の合計労働時間 > 40時間 | 労基法第32条 | 🔴 error |
| 2 | **休憩不足（6h超）** | 6時間超の勤務で45分未満の休憩 | 労基法第34条 | 🟡 warning |
| 3 | **休憩不足（8h超）** | 8時間超の勤務で60分未満の休憩 | 労基法第34条 | 🔴 error |
| 4 | **連続勤務** | 6日以上連続勤務（7日目が法定休日侵害） | 労基法第35条 | 🔴 error |
| 5 | **深夜勤務** | 22:00〜05:00の勤務がある | 労基法第37条第4項 | 🔵 info（割増計算用） |
| 6 | **1日の労働時間** | 1日8時間超 | 労基法第32条第2項 | 🟡 warning |

#### 休憩データについて

**現状確認:** attendance_logs テーブルには `break_minutes` カラムが**既に存在する**（Phase 1設計書 + attendance.php現物で確認済み）。退勤時に6時間未満=0分、6時間以上=default_break_minutes（設定値、デフォルト60分）で自動計算される。マネージャーがPATCHで修正も可能。

shift_assignments にも `break_minutes` カラムが存在する（デフォルト0、編集ダイアログで入力可能）。

**したがって、休憩用の新規カラム追加は不要。** 既存データでチェック可能。

#### 実装方式

PHPサーバーサイドで判定。既存の `summary.php` を拡張するか、新規エンドポイントを作成する。

**方針: summary.php を拡張してレスポンスに `labor_warnings` を追加する。**

理由: summary.phpは既にスタッフ別労働時間を集計しており、そのデータに対してチェックを実行するのが自然。別エンドポイントにすると同じクエリを2回実行することになる。

#### 判定ロジック（PHP疑似コード）

```php
$warnings = [];

// 1. 週40時間チェック（weeklyモード時のみ）
foreach ($staffSummary as $staff) {
    if ($staff['total_minutes'] > 40 * 60) {
        $warnings[] = [
            'type'    => 'weekly_overtime',
            'level'   => 'error',
            'user_id' => $staff['user_id'],
            'message' => $staff['display_name'] . 'の週労働時間が40時間を超えています（' . $staff['total_hours'] . 'h）',
        ];
    }
}

// 2-3. 休憩チェック（attendance_logs から個別判定）
foreach ($attendanceRecords as $log) {
    $workedMinutes = $log['worked_minutes'];
    $breakMinutes  = $log['break_minutes'];
    if ($workedMinutes > 480 && $breakMinutes < 60) {
        $warnings[] = [...]; // 8h超で60分未満
    } elseif ($workedMinutes > 360 && $breakMinutes < 45) {
        $warnings[] = [...]; // 6h超で45分未満
    }
}

// 4. 連続勤務チェック（勤怠日付の連続日数を計算）
// 5. 深夜勤務検出（clock_in/clock_out が 22:00〜05:00 にかかるか）
// 6. 1日8時間チェック
```

#### UI表示

**カレンダー画面:**
- 違反があるセルに警告バッジ（🔴/🟡）を重ねて表示
- セルをタップすると警告詳細がツールチップで表示

**サマリー画面:**
- スタッフ行に警告アイコン
- 画面上部に警告一覧パネル

```
⚠️ 労基法チェック（2件の警告）
🔴 田中: 週労働時間 42.5h（上限40h超過）
🟡 鈴木: 4/15(火) 7h勤務で休憩0分（6h超は45分以上必要）
```

---

## 3. データベース変更

### 3.1 新規カラム（2テーブル）

**マイグレーションファイル:** `sql/migration-l3p2-labor-cost.sql`

```sql
-- ============================================
-- L-3 Phase 2: 人件費シミュレーション用カラム追加
-- ============================================

-- 店舗デフォルト時給
ALTER TABLE shift_settings
  ADD COLUMN default_hourly_rate INT NULL DEFAULT NULL
  COMMENT '店舗デフォルト時給（円）。NULL=未設定';

-- スタッフ個別時給
ALTER TABLE user_stores
  ADD COLUMN hourly_rate INT NULL DEFAULT NULL
  COMMENT 'スタッフ個別時給（円）。NULL=店舗デフォルトを使用';
```

### 3.2 既存テーブルへの影響

| テーブル | 影響 |
|---------|------|
| shift_settings | `default_hourly_rate` カラム追加のみ。既存カラムは変更なし |
| user_stores | `hourly_rate` カラム追加のみ。既存の `visible_tools`（L-3b2）は変更なし |
| shift_templates | 変更なし |
| shift_assignments | 変更なし |
| shift_availabilities | 変更なし |
| attendance_logs | 変更なし（break_minutesは既存） |

### 3.3 新規テーブル

**なし。** 全て既存テーブルへのカラム追加で対応。

---

## 4. API設計

### 4.1 新規エンドポイント

| メソッド | エンドポイント | ロール | 説明 |
|----------|---------------|--------|------|
| GET | `/api/store/shift/ai-suggest-data.php` | manager+ | AI提案用データ集計 |

### 4.2 既存エンドポイント拡張

| エンドポイント | 変更内容 |
|---------------|---------|
| `summary.php` GET | レスポンスに `labor_warnings`, `labor_cost` セクション追加 |
| `settings.php` GET/PATCH | `default_hourly_rate` フィールド追加 |
| `assignments.php` GET | レスポンスに `hourly_rates` マップ追加（カレンダーでの人件費リアルタイム計算用） |

### 4.3 新規API詳細

#### GET /api/store/shift/ai-suggest-data.php

**リクエスト:**
```
?store_id=xxx&start_date=2026-04-14&end_date=2026-04-20
```

**レスポンス:**
```json
{
  "ok": true,
  "data": {
    "templates": [
      {
        "id": "tpl-1",
        "name": "平日ランチ",
        "day_of_week": 1,
        "start_time": "10:00:00",
        "end_time": "15:00:00",
        "required_staff": 2,
        "role_hint": "hall"
      }
    ],
    "availabilities": [
      {
        "user_id": "usr-1",
        "target_date": "2026-04-14",
        "availability": "preferred",
        "preferred_start": "10:00:00",
        "preferred_end": "18:00:00"
      }
    ],
    "staffList": [
      {
        "id": "usr-1",
        "display_name": "田中太郎",
        "hourly_rate": 1200,
        "recent_total_hours": 32.5,
        "recent_days_worked": 4,
        "late_count": 0,
        "consecutive_days_current": 3
      }
    ],
    "salesByDayHour": [
      { "weekday": 1, "weekday_name": "月", "hour": 11, "avg_orders": 12.5, "avg_revenue": 45000 },
      { "weekday": 1, "weekday_name": "月", "hour": 12, "avg_orders": 18.2, "avg_revenue": 68000 }
    ],
    "targetPeriod": {
      "start_date": "2026-04-14",
      "end_date": "2026-04-20"
    },
    "storeName": "松の屋 渋谷店"
  }
}
```

**集計期間:** 過去4週間（28日間）の売上・勤怠データ。

#### summary.php GET（拡張）

既存レスポンスに追加:

```json
{
  "ok": true,
  "data": {
    "period": "2026-04-07 ~ 2026-04-13",
    "total_hours": 75.5,
    "staff_summary": [
      {
        "user_id": "xxx",
        "display_name": "田中",
        "total_hours": 40.0,
        "days_worked": 5,
        "overtime_hours": 0,
        "late_count": 0,
        "night_hours": 0,
        "hourly_rate": 1200,
        "labor_cost": 48000,
        "night_premium": 0
      }
    ],
    "daily_summary": [
      {
        "date": "2026-04-07",
        "staff_count": 2,
        "total_hours": 16.0,
        "labor_cost": 19200
      }
    ],
    "assigned_map": { "2026-04-07": 2 },
    "labor_cost_total": 88388,
    "night_premium_total": 1138,
    "labor_warnings": [
      {
        "type": "weekly_overtime",
        "level": "error",
        "user_id": "xxx",
        "display_name": "田中",
        "date": null,
        "message": "田中の週労働時間が40時間を超えています（42.5h）"
      },
      {
        "type": "insufficient_break",
        "level": "warning",
        "user_id": "yyy",
        "display_name": "鈴木",
        "date": "2026-04-09",
        "message": "鈴木 4/9: 7h勤務で休憩0分（6h超は45分以上必要）"
      }
    ]
  }
}
```

#### settings.php PATCH（拡張）

既存フィールドに追加:

```json
{
  "default_hourly_rate": 1100
}
```

バリデーション: `0 < default_hourly_rate <= 10000`（0円以下不可、1万円超は異常値）。NULLも許可（未設定に戻す）。

#### assignments.php GET（拡張）

既存レスポンスに追加:

```json
{
  "assignments": [...],
  "users": [...],
  "availabilities": [...],
  "hourly_rates": {
    "default": 1100,
    "by_user": {
      "usr-1": 1200,
      "usr-3": 1050
    }
  }
}
```

フロント（shift-calendar.js）がこのデータを使い、カレンダー上でリアルタイム人件費計算を行う。

---

## 5. フロントエンド設計

### 5.1 ファイル構成

| ファイル | 変更種別 | 内容 |
|---------|---------|------|
| `public/js/shift-calendar.js` | **修正** | AI提案ボタン + ダイアログ + 人件費リアルタイム行 + 労基法警告バッジ |
| `public/js/shift-manager.js` | **修正** | 設定タブに時給入力欄追加。サマリータブに人件費・警告セクション追加 |
| `public/admin/dashboard.html` | **修正** | shift-manager.jsのキャッシュバスター更新 |

**新規JSファイルは作成しない。** 既存モジュールの拡張で対応する。

理由:
- AI提案はカレンダーのワークフロー内（ボタン→ダイアログ）なので shift-calendar.js に置くのが自然
- 人件費・労基法チェックはサマリー表示の一部なので shift-manager.js に置くのが自然
- 別ファイルに分割するとscriptタグ追加＋読み込み順管理が必要になり複雑化する

### 5.2 Gemini API呼び出しパターン

ai-assistant.js の `_callGemini` を**直接参照せず、同パターンで複製する**。

理由: ai-assistant.js はIIFE内のプライベート関数。外部公開されていない。shift-calendar.js も別のIIFEなので直接呼べない。

shift-calendar.js 内に以下を追加:

```javascript
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
```

APIキー取得: `settings.php?store_id=xxx&include_ai_key=1` から `data.ai_api_key` を取得（既存パターン）。

### 5.3 AI提案ダイアログ

```
┌──────────────────────────────────────────┐
│  🤖 AI最適シフト提案                       │
│  2026-04-14 〜 2026-04-20                 │
│                                          │
│  [読み込み中... / 提案結果テーブル]         │
│                                          │
│  スタッフ | 月   | 火   | 水   | 木   | 金   | 土   | 日   │
│  田中    | 10-15| 10-15| --   | 10-15| 10-15| --   | --   │
│  鈴木    | --   | 17-22| 17-22| --   | 17-22| 17-22| 17-22│
│                                          │
│  💰 推定週間人件費: ¥168,000              │
│  💡 月曜ランチは注文数が多い傾向...        │
│  ⚠️ 鈴木さんは4週連続土日出勤            │
│                                          │
│  [全て採用] [選択して採用] [キャンセル]     │
└──────────────────────────────────────────┘
```

### 5.4 人件費リアルタイム表示（カレンダー）

shift-calendar.js の render() 内、合計行の右端に推定人件費を表示:

```javascript
// 人件費計算（フロントサイド）
var weekLaborCost = 0;
// 全assignmentをループし、hourly_ratesから時給取得 → 勤務時間×時給で計算
// 22:00-05:00は×1.25
```

### 5.5 設定タブ拡張（shift-manager.js）

シフト設定ビューに「デフォルト時給」入力欄を追加:

```
シフト設定
  希望提出締切日: [5] 日
  デフォルト休憩: [60] 分
  残業閾値:      [480] 分
  ...
  ──────────────
  💰 デフォルト時給: [1100] 円
  ※ スタッフ個別の時給はユーザー管理で設定できます
```

### 5.6 ユーザー編集に時給欄追加（user-editor.js）

L-3b2で追加した「表示ツール」チェックボックスの下に時給入力欄を追加:

```
表示ツール: [✓] ハンディ [✓] KDS [ ] レジ
時給: [1200] 円（空欄=店舗デフォルト）
```

manager向け: staff-management.php の POST/PATCH に `hourly_rate` パラメータ追加。
owner向け: owner/users.php の PATCH に `hourly_rate_by_store` マップ追加（visible_tools_by_storeと同パターン）。

---

## 6. 実装順序

### Step 1: マイグレーション + 設定API拡張

**対象ファイル:**
- `sql/migration-l3p2-labor-cost.sql` — 新規
- `api/store/shift/settings.php` — PATCH に `default_hourly_rate` 追加

**依存:** なし

### Step 2: 時給UI（設定タブ + ユーザー編集）

**対象ファイル:**
- `public/js/shift-manager.js` — 設定ビューにデフォルト時給入力欄追加
- `public/admin/js/user-editor.js` — 時給入力欄追加
- `api/store/staff-management.php` — hourly_rate パラメータ対応
- `api/owner/users.php` — hourly_rate_by_store パラメータ対応

**依存:** Step 1

### Step 3: 人件費計算（summary.php拡張）

**対象ファイル:**
- `api/store/shift/summary.php` — 人件費計算 + 深夜手当 + labor_cost フィールド追加
- `public/js/shift-manager.js` — サマリー表示に人件費列追加

**依存:** Step 1

### Step 4: 労基法チェック

**対象ファイル:**
- `api/store/shift/summary.php` — labor_warnings 判定ロジック追加
- `public/js/shift-manager.js` — 警告パネル表示
- `public/js/shift-calendar.js` — セル上の警告バッジ

**依存:** Step 3

### Step 5: AI提案データ集計API

**対象ファイル:**
- `api/store/shift/ai-suggest-data.php` — 新規

**依存:** Step 1

### Step 6: AI提案UI + Gemini連携

**対象ファイル:**
- `public/js/shift-calendar.js` — AI提案ボタン + ダイアログ + Gemini呼び出し + 結果採用
- `api/store/shift/assignments.php` — GET レスポンスに `hourly_rates` 追加

**依存:** Step 5

### Step 7: カレンダー人件費リアルタイム表示

**対象ファイル:**
- `public/js/shift-calendar.js` — 合計行に推定人件費表示
- `api/store/shift/assignments.php` — （Step 6で対応済み）

**依存:** Step 6

### Step 8: キャッシュバスター更新 + 統合テスト

**対象ファイル:**
- `public/admin/dashboard.html` — scriptタグのキャッシュバスター更新
- `public/admin/owner-dashboard.html` — 同上（user-editor.js）

**依存:** Step 1〜7全て

---

## 7. デプロイファイル一覧（Phase 2 完了時）

### 新規ファイル
```
sql/migration-l3p2-labor-cost.sql          — マイグレーション（2カラム追加）
api/store/shift/ai-suggest-data.php        — AI提案用データ集計API
```

### 変更ファイル
```
api/store/shift/settings.php               — default_hourly_rate 対応
api/store/shift/summary.php                — 人件費計算 + 労基法チェック
api/store/shift/assignments.php            — hourly_rates レスポンス追加
api/store/staff-management.php             — hourly_rate パラメータ対応
api/owner/users.php                        — hourly_rate_by_store パラメータ対応
public/js/shift-calendar.js                — AI提案 + 警告バッジ + 人件費リアルタイム
public/js/shift-manager.js                 — 時給設定UI + サマリー人件費 + 警告パネル
public/admin/js/user-editor.js             — 時給入力欄追加
public/admin/dashboard.html                — キャッシュバスター更新
public/admin/owner-dashboard.html          — キャッシュバスター更新
```

**合計: 新規2ファイル + 変更10ファイル = 12ファイル**

---

## 8. セルフチェック

### 8.1 Phase 1テーブル構造との矛盾

- ✅ attendance_logs.break_minutes は既存。新規カラム不要
- ✅ shift_assignments.break_minutes は既存。編集ダイアログで入力可能
- ✅ shift_settings に default_hourly_rate 追加は新規マイグレーション（既存カラム変更なし）
- ✅ user_stores に hourly_rate 追加は新規マイグレーション（既存の visible_tools とは別カラム）

### 8.2 ai-assistant.js パターンとの一貫性

- ✅ `_callGemini` の複製（同パターン、IIFEスコープ内）
- ✅ `_cleanResponse` は不要（JSON出力のみ要求するため。ただし ```json ``` フェンスの除去は必要）
- ✅ APIキー取得: `settings.php?include_ai_key=1` パターンを踏襲
- ✅ temperature=0.8（一貫）、maxOutputTokens=4096（提案データ量に応じて拡大）

### 8.3 ES5制約

- ✅ var + コールバック。const/let/arrow/async-await なし
- ✅ IIFE パターン維持
- ✅ fetch → .then → .then → .catch パターン

### 8.4 マルチテナント境界

- ✅ ai-suggest-data.php: `require_auth()` + `require_store_param()` + `require_store_access()` + `tenant_id` 絞り込み
- ✅ summary.php 拡張: 既存の tenant_id/store_id 絞り込みを維持
- ✅ assignments.php 拡張: hourly_rates 取得時も tenant_id 絞り込み
- ✅ settings.php 拡張: 既存の UPSERT パターンを維持（store_id + tenant_id で特定）

### 8.5 セキュリティ

- ✅ 全SQLはプリペアドステートメント
- ✅ ユーザー入力は `Utils.escapeHtml()` 経由で innerHTML に挿入
- ✅ Gemini APIキーはサーバーサイド（settings.php）から取得。直接ハードコードなし
- ✅ hourly_rate バリデーション: 0 < rate <= 10000

---

## 9. 注意事項

### Gemini JSON出力の信頼性

Geminiはプロンプトで「JSONのみ出力」と指示しても前置きテキストを付加することがある。対策:
- レスポンスから ` ```json ` ... ` ``` ` フェンスを除去するパーサーを実装
- JSONパース失敗時は「提案を生成できませんでした。再試行してください」とフォールバック
- 重要: AI提案結果を**自動投入しない**。必ずマネージャーの確認→採用操作を経る

### 深夜手当計算の精度

日をまたぐシフト（例: 20:00〜翌02:00）にも対応する必要がある。計算ロジック:
- start_time > end_time の場合、翌日にまたがると判定
- 22:00〜翌05:00の重複分数を算出
- Phase 1の shift_assignments は `start_time >= end_time` をエラーにしているが、深夜シフト対応として将来的にこの制約を緩和する可能性がある。Phase 2では**既存の制約は変更せず**、日またぎシフトは2つのassignment（20:00-24:00 + 00:00-02:00）で表現する

### 人件費の税区分

Phase 2では**税込/税抜の区別はしない**。単純に「時給×時間」で計算する。社会保険料・源泉徴収等の給与計算機能はスコープ外。
