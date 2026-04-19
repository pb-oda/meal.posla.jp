---
feature_id: L-3
title: シフト管理
chapter: 12
plan: [all]
role: [owner, manager, staff]
audience: 店長・マネージャー + スタッフ (自分のシフト閲覧)
keywords: [シフト, カレンダー, 勤怠, 打刻, GPS, AI提案, 人件費, ヘルプ, マルチ店舗]
related: [02-login, 13-reports, 15-owner]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 12. シフト管理

管理ダッシュボードの「シフト管理」タブから利用します。**2026-04-09 以降、全プラン標準提供** (旧 pro プラン以上の制限は廃止)。実体は `api/store/shift/*.php` 系 9 エンドポイント + `public/admin/js/shift-calendar.js` 等 4 つのフロントモジュールで構成され、`shift_templates` / `shift_assignments` / `shift_availabilities` / `attendance_logs` / `shift_settings` / `shift_help_requests` の 6 テーブルで永続化されます (詳細は 12.Z)。

---

## 12.0 はじめにお読みください

::: tip 章の読み方ガイド
- **店長・マネージャー**: 12.1 (サブタブ俯瞰) → 12.2 (テンプレート) → 12.3 (週間カレンダー) → 12.4 (AI 提案) → 12.7 (勤怠一覧) → 12.8 (シフト設定) → 12.9 (集計サマリー) → 12.10 (ヘルプ)
- **スタッフ**: 12.5 (マイシフト) + 12.6 (希望提出) + 12.11 (出退勤打刻)
- **複数店舗運用**: 12.10 (ヘルプ機能)
- **GPS 打刻**: 12.8 (店舗座標+半径) と 12.11.3 (打刻時挙動)
- **device 端末を使っている**: 12.12「device ロールとシフトの関係」で除外ルールを確認
:::

### 役割と使える人

| ロール | 機能 |
|---|---|
| owner | 全店舗のシフト編集 + AI 提案 + ヘルプ要請 |
| manager | 担当店舗のシフト編集 + AI 提案 + ヘルプ送受信 |
| staff | 自分のシフト閲覧 + 希望提出 + 出退勤打刻 |
| device | **割当・打刻ともに不可** (`DEVICE_NOT_ASSIGNABLE`) |

マネージャーはシフトの作成・割当・集計の全機能を使用でき、スタッフは自分のシフト確認・希望提出・勤怠打刻のみ操作できます。device 端末は人ではなく端末用アカウントなので、シフト管理・人件費・スタッフレポートの集計対象から除外されます (詳細は 12.12)。

### この章で扱う内容（章マップ）

- **12.1** サブタブ一覧 (UI 概観)
- **12.2** シフトテンプレート (繰り返しパターン)
- **12.3** 週間カレンダー (割当のメイン画面)
- **12.4** AI シフト提案 (`apply-ai-suggestions.php`)
- **12.5** マイシフト (スタッフ閲覧)
- **12.6** スタッフの希望提出
- **12.7** 勤怠一覧 (マネージャー)
- **12.8** シフト設定 (時給・GPS・締切)
- **12.9** 集計サマリー (人件費・労基法警告)
- **12.10** ヘルプ要請 (店舗間派遣)
- **12.11** 出退勤打刻 (スタッフ画面 + GPS)
- **12.12** device ロールとシフトの関係
- **12.X** FAQ 30 件 (カテゴリ別)
- **12.Y** トラブルシューティング表
- **12.Z** データベース構造 (運営向け)

---

## 12.1 サブタブ一覧

| サブタブ | 対象ロール | 説明 | 主な API |
|---------|-----------|------|---------|
| 週間カレンダー | manager+ | シフトの割当・編集メイン画面 | `assignments.php` |
| マイシフト | staff | 自分の確定シフト閲覧 | `assignments.php?user_id=self` |
| 希望提出 | staff | 希望シフトの提出 (UPSERT) | `availabilities.php` |
| 勤怠一覧 | manager+ | 全スタッフの出退勤記録 | `attendance.php` |
| テンプレート | manager+ | 週次パターン定義 | `templates.php` |
| シフト設定 | manager+ | 各種設定 (GPS / 時給 / 締切) | `settings.php` |
| 集計サマリー | manager+ | 労働時間・人件費・労基法警告 | `summary.php` |
| ヘルプ | manager+ | 他店舗へのスタッフ派遣要請 | `help-requests.php` |

::: tip サブタブの並び順
`shift-calendar.js` と `shift-manager.js` がデフォルトで「週間カレンダー → マイシフト → 希望提出 → 勤怠一覧 → テンプレート → シフト設定 → 集計サマリー → ヘルプ」の順に DOM 描画します。スタッフロールでログインすると週間カレンダー / 勤怠一覧 / テンプレート / シフト設定 / 集計サマリー / ヘルプは非表示。
:::

---

## 12.2 シフトテンプレート

シフトテンプレートは、繰り返し使用するシフトパターン（早番・遅番・通しなど）を定義する機能です。実装は `api/store/shift/templates.php` (CRUD)。

### 12.2.1 操作前のチェックリスト

| # | 確認項目 | 確認方法 | NG 時の対応 |
|---|---------|---------|-----------|
| 1 | あなたが **manager / owner** | 画面右上ユーザー名 | staff は閲覧のみ。manager に依頼 |
| 2 | 対象店舗が選択されている | 上部の店舗ドロップダウン | 未選択ならドロップダウンから店舗選択 |
| 3 | 営業時間がシフト設定で正しい | 12.8 のシフト設定確認 | デフォルトでも保存可能だが要見直し |
| 4 | 同じ曜日・時間帯の重複テンプレートがない | 「テンプレート」サブタブ一覧 | 重複は AI 提案で混乱の元 |

### 12.2.2 テンプレート作成のクリック単位手順

1. **「シフト管理」** タブ → **「テンプレート」** サブタブをクリック
2. 画面右上の **「+ テンプレート追加」** ボタンをクリック
3. モーダルが開きます。以下のフィールドに入力します
4. **「保存」** ボタンをクリック
5. 一覧に新しいテンプレートが追加されます (`day_of_week` 順 → `start_time` 順にソート)

### 12.2.3 入力フィールドの詳細表

| フィールド | 種類 | 必須 | 入力例 | 文字種・形式 / 制約 | 補足 |
|-----------|------|------|--------|-------------------|------|
| 名前 | テキスト | ✅ | `平日ランチ早番` | 1〜100 文字 | サーバーは `mb_strlen` でカウント |
| 曜日 | セレクト | ✅ | `月曜日` | 0=日 〜 6=土 | DB は `day_of_week TINYINT` |
| 開始時刻 | 時刻入力 | ✅ | `10:00` | `HH:MM` または `HH:MM:SS` | サーバー側 regex `^\d{2}:\d{2}(:\d{2})?$` |
| 終了時刻 | 時刻入力 | ✅ | `15:00` | `HH:MM` | 開始 < 終了 (`INVALID_TIME_RANGE`) |
| 役割ヒント | セレクト | 任意 | `kitchen` | `kitchen` / `hall` / 空 | `INVALID_ROLE` 以外不可 |
| 必要人数 | 数値 | ✅ | `3` | 1〜20 | `INVALID_STAFF` |

### 12.2.4 画面遷移 (ASCII)

```
[テンプレート一覧]
  └─[+追加] ──→ [モーダル: 入力] ──保存──→ [一覧 (新規行追加)]
        ↑                                       │
        └──────[キャンセル]────────────────────┘
  └─[編集ペン] ──→ [モーダル: 既存値入りで開く] ──保存──→ [一覧更新]
  └─[削除ゴミ箱] ──→ [確認ダイアログ] ──OK──→ [一覧から消える (is_active=0)]
```

### 12.2.5 テンプレートの一括適用 (週間カレンダーへ)

作成したテンプレートは **週間カレンダー画面**から指定週に一括適用できます。

1. **「週間カレンダー」** サブタブを開く
2. 上部の **「テンプレート適用」** ボタンをクリック
3. モーダルで以下を選択
   - 適用するテンプレート (複数選択可)
   - 開始日 (週の月曜日)
   - 終了日 (最大 28 日 = 4 週分)
4. **「適用」** をクリック
5. 期間内の該当曜日に `shift_assignments` のドラフト行が `note='テンプレート適用'` で生成

### 12.2.6 実例ブロック

#### matsunoya 大宮店 (1店舗目)

| 名前 | 曜日 | 時刻 | 役割 | 必要人数 |
|---|---|---|---|---|
| 平日ランチ早番 | 月〜金 | 10:00-15:00 | hall | 2 |
| 平日ランチキッチン | 月〜金 | 10:00-15:00 | kitchen | 2 |
| 平日ディナー | 月〜金 | 17:00-22:00 | hall | 3 |
| 週末通し | 土・日 | 10:00-22:00 | hall | 4 |

→ 4 週間で計 7 × 4 = 28 日に展開され、約 96 件の draft シフトが自動生成。

#### torimaru 渋谷店 (チェーン本部例)

| 名前 | 曜日 | 時刻 | 役割 | 必要人数 |
|---|---|---|---|---|
| 朝オープン | 月〜日 | 09:00-13:00 | kitchen | 1 |
| ランチピーク | 月〜日 | 11:30-14:00 | hall | 3 |
| 夜ピーク | 木〜土 | 18:00-23:00 | hall | 3 |

### 12.2.7 トラブルシューティング (テンプレート)

| 症状 | 原因 | 対処 |
|---|---|---|
| 「INVALID_TIME」エラー | 時刻形式不正 | `HH:MM` で入力 (例 `9:00` → `09:00`) |
| 「INVALID_TIME_RANGE」 | 終了 ≤ 開始 | 終了時刻を後ろに |
| 「INVALID_ROLE」 | role_hint が `kitchen`/`hall` 以外 | プルダウンから選び直す |
| 削除後も AI 提案で参照される | 論理削除 (`is_active=0`) | DB 上は残るが UI/AI からは見えなくなる |

---

## 12.3 週間カレンダー

シフトの割当・編集を行うメイン画面です。実装は `api/store/shift/assignments.php` + `public/admin/js/shift-calendar.js`。

### 12.3.1 操作前のチェックリスト

| # | 確認項目 | NG 時の対応 |
|---|---------|-----------|
| 1 | 対象店舗ドロップダウンで正しい店舗を選んでいる | 別店舗を選ぶ |
| 2 | 表示週が正しい (上部に `2026-04-13 〜 2026-04-19` など表示) | 「← / →」で週送り |
| 3 | スタッフが事前にユーザー管理タブで作成済み | dashboard ユーザー管理タブで追加 |
| 4 | スタッフに `user_stores` で店舗が紐付いている | ユーザー編集 → 「所属店舗」で当該店舗 ON |

### 12.3.2 カレンダーの表示

- 横軸：曜日（月〜日の1週間）
- 縦軸：スタッフ名 (`role IN ('manager','staff','owner')` のうち当該店舗所属者)
- 各セルにシフト情報（開始〜終了時刻、休憩、役割）が表示されます
- 各セル右下に「人件費」(時給 × 勤務時間) が小さく表示
- セル背景色: `draft=灰色`, `published=水色`, `confirmed=緑`

### 12.3.3 画面遷移 (ASCII)

```
        月       火       水       木       金       土       日
┌──────┬───────┬───────┬───────┬───────┬───────┬───────┬───────┐
│田中  │10-15  │       │10-15  │       │10-15  │       │       │
│      │ hall  │       │ hall  │       │ hall  │       │       │
│      │¥6,000 │       │¥6,000 │       │¥6,000 │       │       │
├──────┼───────┼───────┼───────┼───────┼───────┼───────┼───────┤
│鈴木  │       │17-22  │       │17-22  │17-22  │10-22  │10-22  │
│      │       │ hall  │       │ hall  │ hall  │ hall  │ hall  │
│      │       │¥5,000 │       │¥5,000 │¥5,000 │¥12,000│¥12,000│
└──────┴───────┴───────┴───────┴───────┴───────┴───────┴───────┘
セルクリック → [シフト編集モーダル] → 保存 → セル更新
空きセルクリック → [新規シフト追加モーダル] → 保存 → セル新規描画
```

### 12.3.4 シフト割当のクリック単位手順

1. カレンダーの空きセルをクリック (既存シフトを編集する場合は既存セル)
2. **シフト入力モーダル**が開きます
3. 以下を入力します (詳細表は 12.3.5)
4. **「保存」** ボタンをクリック
5. 1〜2 秒待つと `shift_assignments` に INSERT され、セルが描画更新される
6. ステータス `published` で保存するとスタッフのマイシフトに即座に反映

### 12.3.5 入力フィールドの詳細表

| フィールド | 種類 | 必須 | 入力例 | 文字種・形式 / 制約 |
|-----------|------|------|--------|-------------------|
| スタッフ | セレクト | ✅ | 田中 | `user_stores` 紐付け済み + `role != 'device'` のみ表示 |
| 日付 | 日付入力 | ✅ | `2026-04-15` | `YYYY-MM-DD` |
| 開始時刻 | 時刻入力 | ✅ | `10:00` | `HH:MM` |
| 終了時刻 | 時刻入力 | ✅ | `15:00` | `HH:MM`、開始 < 終了 |
| 休憩時間 (分) | 数値 | 任意 | `60` | 0〜480 |
| 役割 | セレクト | 任意 | `hall` | `kitchen` / `hall` / 空 |
| ステータス | セレクト | ✅ | `draft` | `draft` / `published` / `confirmed` |
| メモ | テキスト | 任意 | `新人指導日` | 自由記述 (TEXT) |

### 12.3.6 既存シフトの編集・削除

- **編集**: 既存セルをクリック → モーダルで値変更 → 保存
- **コピー**: モーダル右下の「コピー」ボタンで別日付に複製
- **削除**: モーダル左下の「削除」ボタン → 確認ダイアログ → 物理削除 (`DELETE FROM shift_assignments`)

### 12.3.7 実例ブロック

#### matsunoya 大宮店 (2026-04-13〜19 週)

```
スタッフ: 田中 (¥1,200/h)、鈴木 (¥1,000/h)、山田 (¥1,500/h、社員=null)
```

- 田中: 月・水・金 10-15 (hall, 各 ¥6,000)
- 鈴木: 火・木 17-22 (hall, 各 ¥5,000)、土・日 10-22 (各 ¥12,000)
- 山田 (社員): 月〜金 09-22 (full、人件費表示なし)

週合計人件費 (アルバイト分): ¥6,000×3 + ¥5,000×2 + ¥12,000×2 = **¥52,000**

### 12.3.8 トラブルシューティング (週間カレンダー)

| 症状 | 原因 | 対処 |
|---|---|---|
| スタッフが縦軸に出ない | `user_stores` 未登録 | ユーザー管理 → 該当店舗 ON |
| device 端末が縦軸に出てしまう | `role='device'` 漏れ | スタッフ管理タブで device 化 (12.12) |
| 保存ボタンを押しても反映されない | バリデーションエラー | F12 コンソールで `shift_assignments` POST のレスポンス確認 |
| カレンダーが白紙 | スタッフ未登録 | ユーザー管理で追加 |
| 人件費が表示されない | 時給未設定 | ユーザー管理 → スタッフ編集で時給入力 |

---

## 12.4 AI シフト提案

カレンダー画面に紫色の **「AI 提案」** ボタンがあります。クリックすると、AI が複数データを分析して期間内シフトを一括提案します。実装: `api/store/shift/ai-suggest-data.php` (データ収集) + `api/store/shift/apply-ai-suggestions.php` (一括適用)。

### 12.4.1 操作前のチェックリスト

| # | 確認項目 | 補足 |
|---|---------|------|
| 1 | manager 以上 | sample-suggest は staff でも見られるが `apply` は manager 必須 |
| 2 | 過去 4 週間以上の `shift_assignments` 履歴がある | 履歴ゼロだとパターン認識不能、提案精度低 |
| 3 | スタッフ全員に時給設定済み (`user_stores.hourly_rate`) | 人件費最適化に必要 |
| 4 | テンプレートが定義済み (12.2) | 「必要人数」算出の基準 |
| 5 | スタッフが希望提出済み (12.6) | 希望なしのスタッフは「全日OK」扱い |

### 12.4.2 操作手順 (クリック単位)

1. **「シフト管理」** → **「週間カレンダー」** サブタブ
2. 画面右上の紫色 **「AI 提案」** ボタンをクリック
3. モーダルで以下を入力
   - 適用期間 (開始日 〜 終了日、最大 28 日)
   - 既存シフトの扱い (`replace_existing`)
4. **「AI 提案を生成」** ボタンをクリック → 5〜15 秒待つ
5. プレビュー画面で生成された割当を確認
6. **「適用する」** ボタンをクリック
7. 期間内の `note='AI提案'` の旧 draft が削除され、新しい draft が一括 INSERT
8. 完了後、レスポンスに `{ deleted: N, inserted: M }` が返る

### 12.4.3 入力フィールドの詳細表

| フィールド | 必須 | 例 | 制約 |
|-----------|------|----|------|
| start_date | ✅ | `2026-04-20` | `YYYY-MM-DD` |
| end_date | ✅ | `2026-05-17` | start_date 以降、最大 28 日 |
| replace_existing | ✅ | `true` | `note='AI提案'` のみ削除。**手動作成シフト (`note != 'AI提案'`) は保護される** |
| suggestions[] | ✅ | (AI 自動生成) | `user_id`, `shift_date`, `start_time`, `end_time`, `role_type` |

### 12.4.4 AI が分析するデータ

- テンプレート定義 (12.2)
- スタッフの希望提出状況 (`shift_availabilities`)
- 過去4週間の `shift_assignments` 実績
- 各スタッフの時給 (`user_stores.hourly_rate` または `shift_settings.default_hourly_rate`)
- 直近の出勤状況 (`attendance_logs.status='late'` のカウント)
- 連続勤務日数
- 曜日・時間帯別の平均売上 (`orders.created_at` 集計)

### 12.4.5 ガード: device は割当不可

`apply-ai-suggestions.php` 内のチェック (L116-125):

```php
$stmtDevice = $pdo->prepare(
    "SELECT id, display_name FROM users
     WHERE id IN ({$dPlaceholders}) AND tenant_id = ? AND role = 'device'"
);
$stmtDevice->execute(array_merge($userIds, [$tenantId]));
$deviceRows = $stmtDevice->fetchAll();
if (!empty($deviceRows)) {
    json_error('DEVICE_NOT_ASSIGNABLE',
        'デバイスアカウント(' . $deviceRows[0]['display_name'] . ')はシフト割当の対象外です',
        400);
}
```

→ 提案 `suggestions[]` に device の `user_id` が混じった瞬間に `400 DEVICE_NOT_ASSIGNABLE` で全体ロールバック。1 件でもアウトなら全件 INSERT 中止。

### 12.4.6 画面遷移 (ASCII)

```
[週間カレンダー] ──[AI 提案]──→ [モーダル: 期間 + replace_existing]
                                    │
                                    ▼
                              [生成中... 5-15 秒]
                                    │
                                    ▼
                              [プレビュー: ¥X,XXX 削減見込み]
                                    │
                       ┌────────────┴───────────┐
                       ▼                        ▼
                   [適用する]              [キャンセル]
                       │                        │
                       ▼                        ▼
              [draft 一括 INSERT]         [何もしない]
                       │
                       ▼
              [週間カレンダーに反映]
```

### 12.4.7 実例ブロック

#### matsunoya 大宮店 (2026-04-09 実行)

- 期間: 2026-04-13 〜 2026-04-26 (2 週間)
- 既存: 手動シフト 8 件 (note=null)、過去 AI シフト 0 件
- 結果: `deleted=0, inserted=42`
- AI からのコメント: 「平日ランチに鈴木さんが偏っています。週末に山田さん配置で人件費 ¥8,400 削減見込み」

#### torimaru 渋谷店 (E2E テスト)

- 期間: 2026-04-13 〜 2026-04-19 (1 週間)
- 既存: AI シフト 14 件、手動シフト 3 件
- replace_existing: true
- 結果: `deleted=14, inserted=18` (手動 3 件は保持)

### 12.4.8 トラブルシューティング (AI 提案)

| 症状 | 原因 | 対処 |
|---|---|---|
| 「AI 提案が出ない」 | データ不足 | 過去 3 ヶ月以上の運用後再試行 |
| `DEVICE_NOT_ASSIGNABLE` | device ユーザー混入 | スタッフ管理で device 化を解除 or AI 提案から除外 |
| `USER_NOT_IN_STORE` | `user_stores` 紐付け漏れ | ユーザー管理 → 所属店舗を確認 |
| `INVALID_INPUT` | 期間が逆転 / suggestions 空 | start ≤ end、suggestions ≥ 1 件 |
| 適用後も古い AI シフトが残る | `replace_existing=false` で実行 | `replace_existing=true` で再実行 |
| 提案された人件費が現実と違う | スタッフ時給未設定 | ユーザー管理 → 個別時給を入力 |

---

## 12.5 マイシフト

スタッフは **「マイシフト」** サブタブで、自分の確定したシフトを確認できます。データソースは `api/store/shift/assignments.php?user_id=<自分>`。

### 12.5.1 操作前のチェックリスト (スタッフ視点)

| # | 確認項目 |
|---|---------|
| 1 | 自分のアカウントが `staff` ロールである (device は不可) |
| 2 | 所属店舗 (`user_stores`) が 1 つ以上ある |
| 3 | マネージャーが `published` ステータスで保存済み |

### 12.5.2 表示内容

| 要素 | 説明 |
|------|------|
| 日付 | `shift_date` |
| 開始時刻〜終了時刻 | `start_time` 〜 `end_time` |
| 休憩時間 | `break_minutes` (分) |
| 役割 | `role_type` (kitchen / hall) |
| ステータス | `draft` / `published` / `confirmed` |
| ヘルプ元店舗名 | 他店舗 ヘルプ要請で割り当てられた場合に表示 (12.10) |
| メモ | `note` (例: `新人指導日`) |

### 12.5.3 画面遷移

```
[ログイン (staff)] ──→ [スタッフ画面]
                          ├─ 勤怠打刻 (出勤/退勤ボタン)
                          └─ シフト管理
                              ├─ マイシフト ← ここ
                              └─ 希望提出
```

### 12.5.4 実例: matsunoya スタッフ「田中」

| 日付 | 時刻 | 休憩 | 役割 | 状態 | メモ |
|---|---|---|---|---|---|
| 2026-04-15 (水) | 10:00-15:00 | 0 | hall | published | — |
| 2026-04-17 (金) | 10:00-15:00 | 0 | hall | published | — |
| 2026-04-19 (日) | 10:00-15:00 | 60 | hall | draft | (確定前) |

---

## 12.6 スタッフの希望提出

スタッフは **「希望提出」** サブタブから、シフトの希望を提出します。実装: `api/store/shift/availabilities.php`。

### 12.6.1 操作前のチェックリスト

| # | 確認項目 | 補足 |
|---|---------|------|
| 1 | 希望提出締切 (`shift_settings.submission_deadline_day`) を過ぎていない | デフォルト毎月 5 日 |
| 2 | 提出対象期間が「翌月以降」 | 当月の希望は提出済み前提 |
| 3 | 提出する日付が **自分のシフト確定日** より前 | 確定後は変更不可 |

### 12.6.2 提出方法 (クリック単位)

1. **「希望提出」** サブタブを開く
2. カレンダーで希望を入れたい日付をクリック (1 日単位 or 範囲選択)
3. 各日付について以下を入力します
4. **「提出」** ボタンをクリック
5. 最大 31 日分を一括提出可能

### 12.6.3 入力フィールドの詳細表

| フィールド | 種類 | 例 | 制約 |
|-----------|------|----|------|
| 可否 | セレクト | `available` | `available` (出勤可) / `preferred` (希望) / `unavailable` (出勤不可) |
| 希望開始時刻 | 時刻入力 | `10:00` | `HH:MM` または空 |
| 希望終了時刻 | 時刻入力 | `22:00` | `HH:MM` または空、開始 < 終了 |
| メモ | テキスト | `授業終了後` | 自由記述 |

### 12.6.4 更新方式 (UPSERT)

- DB の `UNIQUE INDEX (tenant_id, store_id, user_id, target_date)` で 1 日 1 行
- 同じ日に再提出すると上書き (`ON DUPLICATE KEY UPDATE`)
- 削除は `unavailable` での再提出か、フロントの「クリア」ボタン

### 12.6.5 画面遷移

```
[希望提出タブ] → [カレンダー (空欄 = 未提出)]
                    │
                    ▼
              [日付クリック] → [入力フィールド表示]
                    │
                    ▼
              [複数日選択でまとめて入力]
                    │
                    ▼
              [提出ボタン] → POST /availabilities.php
                    │
                    ▼
              [カレンダーに「✓」マーク付与]
```

### 12.6.6 実例: matsunoya スタッフ「鈴木」(2026-05 月分)

| 日付 | 可否 | 希望時間 | メモ |
|---|---|---|---|
| 2026-05-01 (金) | preferred | 17:00-22:00 | 大学3限後 |
| 2026-05-02 (土) | available | 10:00-22:00 | 通し可能 |
| 2026-05-03 (日) | unavailable | — | 帰省 |
| 2026-05-04 (祝) | unavailable | — | 帰省 |
| 2026-05-05 (祝) | available | 10:00-15:00 | ランチのみ |

→ AI シフト提案 (12.4) 実行時、5/3-5/4 にはこのスタッフが配置されない。

---

## 12.7 勤怠一覧

マネージャーは **「勤怠一覧」** サブタブで、全スタッフの出退勤記録を確認できます。実装: `api/store/shift/attendance.php` の GET。

### 12.7.1 操作前のチェックリスト

| # | 確認項目 |
|---|---------|
| 1 | manager 以上 |
| 2 | 集計期間 (start_date ≤ end_date) |
| 3 | 修正対象は本人ではない (本人の打刻訂正は self-service 不可) |

### 12.7.2 表示内容

| カラム | 説明 |
|---|---|
| スタッフ名 | `users.display_name` |
| 日付 | `DATE(clock_in)` |
| 出勤時刻 | `clock_in` (HH:MM) |
| 退勤時刻 | `clock_out` (HH:MM、null=勤務中) |
| 休憩 | `break_minutes` |
| 実労働 (分) | `(clock_out - clock_in) - break_minutes` |
| ステータス | `working` / `completed` / `absent` / `late` |
| 打刻方法 | `manual` (手動 = ボタン押下) / `auto` (システム) |
| 紐付くシフト | `shift_assignment_id` (リンクで該当シフトへ飛ぶ) |
| メモ | `note` (修正時に追記) |

### 12.7.3 マネージャーによる修正手順

1. **「勤怠一覧」** で対象行を見つける
2. 行右側の **「編集」** をクリック
3. モーダルで `clock_in` / `clock_out` / `break_minutes` / `note` を編集
4. **「保存」** をクリック → PATCH `/api/store/shift/attendance.php?id=xxx`
5. 監査ログに `attendance_update` で旧値・新値が記録される

### 12.7.4 ステータスの遷移

```
[未出勤] ──[clock-in]──→ [working] ──[clock-out]──→ [completed]
                            │
                            └──[12h 経過 + 自動退勤]──→ [completed (auto)]

[シフト時間+15分後 未出勤] ──[システム]──→ [absent] (バッチ処理予定)
[シフト時間+15分以内に出勤] ──→ [late]
```

### 12.7.5 実例: matsunoya 「田中」(2026-04-15 水)

| 項目 | 値 |
|---|---|
| 出勤打刻 | 10:03 (manual) |
| 退勤打刻 | 15:01 (manual) |
| 休憩 | 0 (5h未満勤務) |
| 実労働 | 298 分 |
| ステータス | completed |
| 紐付くシフト | shift-assignment-uuid (10-15 hall) |
| 早出警告 | なし (15 分以内) |

---

## 12.8 シフト設定

**「シフト設定」** サブタブで以下の項目を設定します。実装: `api/store/shift/settings.php` (UPSERT)。

### 12.8.1 操作前のチェックリスト

| # | 確認項目 | 補足 |
|---|---------|------|
| 1 | manager 以上 | staff 不可 |
| 2 | GPS 必須化を ON にする場合、店舗の正確な緯度経度が確定している | Google Maps で右クリック → 座標コピー |
| 3 | 時給を設定する場合、社員/バイトの区別ができている | 社員は時給 null で人件費集計から除外 |

### 12.8.2 設定項目の詳細表

| 設定項目 | DB カラム | 入力範囲 | デフォルト | 説明 |
|---------|----------|---------|---------|------|
| 希望提出締切日 | `submission_deadline_day` | 1〜28 | 5 | 毎月 N 日までに翌月希望提出 |
| デフォルト休憩時間 (分) | `default_break_minutes` | 0〜120 | 60 | 6h 以上勤務時の自動休憩 |
| 残業閾値 (分) | `overtime_threshold_minutes` | 60〜720 | 480 | 8h=480分 |
| 早出許容時間 (分) | `early_clock_in_minutes` | 0〜60 | 15 | この時間より前に打刻すると警告 |
| 自動退勤時間 (h) | `auto_clock_out_hours` | 1〜24 | 12 | 打刻忘れ対策、12h 後に強制 completed |
| GPS 必須 | `gps_required` | 0/1 | 0 | 1 で出退勤時 GPS 検証 |
| 店舗緯度 | `store_lat` | -90〜90 | null | 例: `35.6762` (大宮) |
| 店舗経度 | `store_lng` | -180〜180 | null | 例: `139.6503` (大宮) |
| GPS 許容半径 (m) | `gps_radius_meters` | 50〜1000 | 200 | Haversine 距離 |
| デフォルト時給 (¥) | `default_hourly_rate` | 1〜10000 | null | スタッフ個別時給未設定時のフォールバック |
| スタッフ表示ツール | `staff_visible_tools` | `handy,kds,register` | `handy` | カンマ区切り。staff ログイン後の遷移先 |

### 12.8.3 操作手順

1. **「シフト設定」** サブタブを開く
2. 編集したい項目に値を入力
3. 画面下部の **「保存」** ボタンをクリック
4. PATCH `/api/store/shift/settings.php?store_id=xxx`
5. 監査ログに `settings_update` で旧値・新値が記録される
6. 画面上部に「保存しました」のトースト

### 12.8.4 GPS 設定の正しい入手方法

1. Google Maps で店舗の正面入口を表示
2. その地点で**右クリック**
3. 表示された座標 (例: `35.681236, 139.767125`) をコピー
4. 緯度 (1 つ目) / 経度 (2 つ目) に分けて入力
5. 半径は通常の入口レベルなら **50-100m** 推奨 (路面店の場合)
6. 駅地下街など GPS 弱い場所は **200-300m** に拡張

### 12.8.5 実例: matsunoya 大宮店

```
submission_deadline_day:    5      (毎月5日締切)
default_break_minutes:     60      (8h勤務で1h休憩)
overtime_threshold_minutes: 480    (8h超過で残業扱い)
early_clock_in_minutes:    15      (15分前まで早出OK)
auto_clock_out_hours:      12      (打刻忘れは12h後自動退勤)
gps_required:              1       (GPS必須)
store_lat:                 35.9067 (大宮駅東口付近)
store_lng:                 139.6233
gps_radius_meters:         150     (店舗半径150m)
default_hourly_rate:       1100    (¥1,100/h)
staff_visible_tools:       handy   (スタッフはハンディから入る)
```

### 12.8.6 トラブルシューティング (シフト設定)

| 症状 | 原因 | 対処 |
|---|---|---|
| `INVALID_VALUE submission_deadline_day` | 28 超 | 1〜28 で入力 |
| `INVALID_VALUE store_lat` | 90 超 | -90〜90 で入力 |
| GPS 必須にしたが打刻時に検証されない | 緯度経度が null | lat/lng を両方入力すること |
| `staff_visible_tools` でスタッフがハンディに飛ばない | カンマ区切り表記ミス | `handy,kds,register` のみ許可 |
| 時給を入れたのに人件費 ¥0 | スタッフが社員 (時給 null) | 個別の `user_stores.hourly_rate` 確認 |

---

## 12.9 集計サマリー

**「集計サマリー」** サブタブで、労働時間と人件費の集計を確認します。実装: `api/store/shift/summary.php`。

### 12.9.1 操作前のチェックリスト

| # | 確認項目 |
|---|---------|
| 1 | 対象期間の `attendance_logs` が `completed` または `working` ステータス |
| 2 | スタッフ全員の時給が確定している (人件費を見るため) |
| 3 | 残業閾値が正しく設定されている (12.8) |

### 12.9.2 集計期間

- **週単位**: `?period=weekly&date=2026-04-13` → 7 日間 (月〜日)
- **月単位**: `?period=monthly&date=2026-04` → 月初〜月末
- **カスタム**: フロント側でリクエストを分割

### 12.9.3 スタッフ別集計

| 項目 | 算出方法 |
|------|------|
| 合計勤務時間 | `Σ ((clock_out - clock_in) - break_minutes)` |
| 出勤日数 | `attendance_logs` の DISTINCT `DATE(clock_in)` |
| 残業時間 | 1 日あたり `overtime_threshold_minutes` 超過分の合計 |
| 時給 | `user_stores.hourly_rate`、null なら `shift_settings.default_hourly_rate` |
| 人件費 | 時給 × 勤務時間 (深夜割増含む) |

### 12.9.4 深夜割増計算

- 22:00 〜 翌 05:00 の勤務時間は **時給 × 1.25** で集計
- 計算は `summary.php` 内で 1 分単位ループ (深夜帯クロス対応)

### 12.9.5 労基法コンプライアンスチェック

| 警告 | 条件 |
|------|------|
| 週40時間超過 | 1 週間の勤務合計が 2400 分 (40h) を超えた場合 |
| 休憩不足 (8h以上) | 8h以上の勤務で 60 分未満の休憩 |
| 休憩不足 (6h以上) | 6h以上の勤務で 45 分未満の休憩 |
| 連続勤務 6 日超 | 中日に休みなく連続出勤 |
| 月 100h 残業超 | 月 6,000 分超の残業 |

### 12.9.6 日別詳細

各スタッフの日別詳細データも確認できます (展開ボタン)。

| 項目 | 説明 |
|------|------|
| 日付 | `YYYY-MM-DD` |
| 実労働時間 (分) | clock_in → clock_out − break |
| 休憩時間 (分) | `break_minutes` |
| 早出 / 遅刻 | shift 開始時刻との差分 |
| 残業 (分) | overtime_threshold 超過分 |

### 12.9.7 実例: matsunoya 大宮店 2026-04 月

| スタッフ | 勤務h | 出勤日 | 残業h | 時給 | 人件費 | 警告 |
|---|---|---|---|---|---|---|
| 田中 | 84 | 14 | 0 | ¥1,200 | ¥100,800 | — |
| 鈴木 | 92 | 12 | 4 | ¥1,000 | ¥93,000 | 休憩不足 1 件 |
| 山田 (社員) | 178 | 22 | 18 | null | 集計外 | 連続勤務 7 日 1 件 |
| **合計** | **354h** | — | **22h** | — | **¥193,800** | **2 件** |

---

## 12.10 ヘルプ要請（全契約者に標準提供）

同じテナント内の他店舗にスタッフの派遣を要請できます（2026-04-09 以降、旧 enterprise プラン制限は撤廃済み）。実装: `api/store/shift/help-requests.php`。

::: tip プラン判定の注意
内部で `check_plan_feature($pdo, $tenantId, 'shift_help_request')` を呼んでいますが、α-1 構成では `auth.php` の `check_plan_feature()` が `hq_menu_broadcast` 以外は常に `true` を返すため、全契約者が利用可能です。
:::

### 12.10.1 操作前のチェックリスト

| # | 確認項目 |
|---|---------|
| 1 | manager 以上 |
| 2 | 同じテナント内に 2 店舗以上ある (1 店舗だと送り先がない) |
| 3 | 派遣先店舗のマネージャーと事前に電話/Slack で合意済み (システム送信は形式手続き) |

### 12.10.2 ヘルプ要請の送信 (クリック単位)

1. **「ヘルプ」** サブタブを開く
2. **「ヘルプ要請を送信」** ボタンをクリック
3. モーダルで以下を入力
4. **「送信」** ボタンをクリック
5. POST `/api/store/shift/help-requests.php` → status=pending で作成

### 12.10.3 入力フィールドの詳細表

| フィールド | 必須 | 例 | 制約 |
|-----------|------|----|------|
| 派遣先店舗 | ✅ | 渋谷店 | 同テナント、`is_active=1`、自店舗以外 |
| 日付 | ✅ | `2026-04-25` | `YYYY-MM-DD`、当日以降 |
| 開始時刻 | ✅ | `17:00` | `HH:MM` |
| 終了時刻 | ✅ | `22:00` | `HH:MM`、開始 < 終了 |
| 必要人数 | ✅ | `1` | 1〜10 |
| 役割ヒント | 任意 | `hall` | `kitchen` / `hall` / 空 |
| メモ | 任意 | `週末イベント補強` | 自由記述 |

### 12.10.4 ヘルプ要請のステータス遷移

```
[作成]
  │
  ▼
[pending] ──── 派遣先 manager が ──→ [approved] ──→ [shift_assignments INSERT]
  │                       │
  │                       └─ 拒否 ──→ [rejected]
  │
  └─ 送信元 manager がキャンセル ──→ [cancelled]
```

| ステータス | 意味 |
|-----------|------|
| `pending` | 要請中（承認待ち） |
| `approved` | 承認済み（スタッフ割当済み） |
| `rejected` | 拒否 |
| `cancelled` | キャンセル |

### 12.10.5 ヘルプ要請の承認手順 (派遣先マネージャー側)

1. **「ヘルプ」** サブタブの **「受信」** タブで要請を確認
2. 行右側の **「承認」** ボタンをクリック
3. ダイアログで派遣するスタッフを選択 (`?action=list-staff` で取得)
4. 回答メモを入力（任意）
5. **「承認」** をクリック → PATCH `?id=xxx&action=approve&assigned_user_ids[]=xxx`
6. 派遣スタッフの `shift_assignments` が自動作成 (`store_id=要請元`、`note=`ヘルプ`)
7. スタッフのマイシフトに「ヘルプ元店舗名」付きで表示 (12.5.2)

### 12.10.6 送信/受信タブ

- **送信タブ**: 自店舗から送信したヘルプ要請の一覧 (`from_store_id=自店舗`)
- **受信タブ**: 他店舗から受信したヘルプ要請の一覧 (`to_store_id=自店舗`)

### 12.10.7 実例: matsunoya チェーン

#### 送信側: 大宮店

```
日付: 2026-04-26 (土)
時刻: 17:00-22:00
人数: 2 名
役割: hall
メモ: 結婚式二次会 30 名予約
派遣先: 渋谷店、池袋店 (両方に送信)
```

#### 受信側: 渋谷店

- 渋谷店マネージャーがログインすると「受信: 1 件 pending」のバッジ
- 「承認」→ スタッフ「中村」を選択 → 中村のマイシフトに「2026-04-26 17-22 hall (大宮店ヘルプ)」が追加

#### 受信側: 池袋店

- 池袋店マネージャーは「受信: 1 件 pending」
- 「拒否」→ 大宮店の送信タブに「rejected」と表示

---

## 12.11 出退勤打刻（スタッフ画面）

### 12.11.1 出勤・退勤ボタンの場所 (2026-04-19 配置変更)

スタッフロールでログインすると、管理ダッシュボードの **「スタッフ画面」**（`panel-staff-home`）の中に **【出勤】** ボタンと **【退勤】** ボタンが表示されます。

以前はヘッダーに常時表示でしたが、UI 整理のため **スタッフ画面に統合**（実装: `attendance-clock.js` の `init()` に `targetSelector` パラメータ追加、`'#staff-attendance-slot'` 指定）。

スタッフ画面の構成（上から順）:
1. ハンディ POS / KDS / POSレジ ショートカット（device 運用前提のため通常は非表示）
2. **勤怠打刻** ← ここに 出勤 / 退勤 ボタン
3. シフト管理（マイシフト / 希望シフト提出）

### 12.11.2 出勤打刻の流れ

1. **「出勤」** ボタンをタップ
2. GPS 検証 (12.11.3 参照)
3. 二重打刻のチェック（既に working の場合は `ALREADY_CLOCKED_IN`）
4. 当日 `shift_assignments` との照合 → 紐付け (`shift_assignment_id`)
5. 早出警告（`early_clock_in_minutes` より前なら `warning` フィールド付与、打刻自体は許可）
6. `attendance_logs` INSERT (`status='working'`, `clock_in_method='manual'`)
7. ボタンが「退勤」に切り替わる

### 12.11.3 GPS 検証の挙動

`shift_settings.gps_required = 1` かつ `store_lat`/`store_lng` が両方設定されている場合のみ実行:

1. ブラウザの `navigator.geolocation.getCurrentPosition()` で現在地取得
2. 失敗 (拒否 / タイムアウト) → `GPS_REQUIRED` エラー
3. Haversine 公式で店舗との距離を計算
4. `gps_radius_meters` を超えたら `OUT_OF_RANGE` エラー (距離をメートル単位で返却)
5. 範囲内なら打刻成功

### 12.11.4 退勤打刻の流れ

1. **「退勤」** ボタンをタップ
2. GPS 検証 (出勤時と同じ)
3. `working` レコードを検索 (なければ `NOT_CLOCKED_IN`)
4. 退勤時刻を `clock_out` に記録
5. 休憩時間自動計算
   - 勤務時間 ≥ 6h (360 分): `default_break_minutes` (デフォルト 60 分) を自動付与
   - 勤務時間 < 6h: 0 分
6. `status` を `working` → `completed` に更新
7. ボタンが「出勤」に戻る

### 12.11.5 勤怠データの記録内容

| フィールド | 説明 |
|-----------|------|
| 打刻方法 | `clock_in_method` / `clock_out_method` (`manual`, `auto`, `timeout`) |
| ステータス | `working`（勤務中）→ `completed`（完了） / `absent` / `late` |
| 出勤時刻 | `clock_in` (DATETIME) |
| 退勤時刻 | `clock_out` (DATETIME, null=勤務中) |
| 休憩時間 | `break_minutes` (INT) |
| 紐付くシフト | `shift_assignment_id` (NULL 可) |
| メモ | `note` (TEXT, 修正時に追記) |

### 12.11.6 実例: matsunoya 田中 (2026-04-15 水)

```
10:03  「出勤」タップ
        GPS 検証: 大宮駅から 38m → OK
        当日 shift_assignments 検索: 10-15 hall に紐付け
        早出警告: なし (15 分以内)
        → INSERT attendance_logs (status='working')

15:01  「退勤」タップ
        GPS 検証: 大宮駅から 32m → OK
        勤務時間: 298 分 (< 360 分)
        休憩: 0 分
        → UPDATE attendance_logs SET clock_out='15:01', status='completed'

→ 集計サマリー (12.9) に「298 分」として反映
```

### 12.11.7 トラブルシューティング (打刻)

| 症状 | 原因 | 対処 |
|---|---|---|
| `GPS_REQUIRED` | ブラウザが位置情報を拒否 | 設定 → サイト設定 → 位置情報 → 許可 |
| `OUT_OF_RANGE` 50m | 距離超過 | 店舗内に入ってから再試行 |
| `ALREADY_CLOCKED_IN` | 二重打刻 | 一度退勤してから再出勤 |
| `NOT_CLOCKED_IN` | 出勤せずに退勤 | 出勤打刻を先に |
| 打刻ボタンが押せない | GPS 範囲外 + gps_required=1 | 店舗内で再試行 or 12.8 で gps_required=0 |
| 早出警告が出るが打刻はできる | `early_clock_in_minutes` 超 | 設定値見直し or そのまま打刻 (警告のみ) |
| 12h 経過で勝手に退勤 | `auto_clock_out_hours` の自動処理 | バッチで `clock_out_method='timeout'`、修正は 12.7 で |

---

## 12.12 device ロールとシフトの関係

### 12.12.1 device は「人ではなく端末」

device は POSLA P1a で追加されたロールで、KDS / レジ / ハンディ端末専用のログインアカウントです。スタッフ (人) ではないため、シフト管理・人件費・スタッフレポート・監査ログのスタッフ集計から **完全に除外**されます。

### 12.12.2 影響範囲

| 機能 | device の扱い |
|---|---|
| 週間カレンダー縦軸 | **表示されない** (`role IN ('manager','staff','owner')` で除外) |
| AI シフト提案の `suggestions[]` | **指定すると `DEVICE_NOT_ASSIGNABLE` エラー** |
| マイシフト | device はそもそも `dashboard.html` に来ない (handy/kds に直接遷移) |
| 希望提出 | 同上 |
| 出退勤打刻 | UI 上「出勤」ボタンが表示されない |
| 集計サマリーの人件費 | `attendance_logs` を持たないので 0 |
| ヘルプ要請の派遣スタッフ選択 | リストに出ない (`role='staff'` フィルタ) |
| 監査ログ | device の操作も `audit_logs` には記録されるが、スタッフレポートには反映なし |

### 12.12.3 device 運用時のガード

`apply-ai-suggestions.php` で `DEVICE_NOT_ASSIGNABLE` チェックをサーバー側で強制 (12.4.5)。フロントの shift-calendar.js でも device を縦軸から除外しているため、二重防御になっています。

### 12.12.4 device → staff への昇格は禁止

device は端末用なので、後から staff に昇格させると、過去の `attendance_logs` ゼロのまま週 0h スタッフが集計に出現する事故が起きます。新しいスタッフが必要なら **新規 staff アカウント作成**してください。

---

## 12.X よくある質問 30 件

### A. 設定・締切

**Q1.** シフトの締め切り日は?
**A1.** `shift_settings.submission_deadline_day` (デフォルト毎月 5 日)。12.8 で変更可。

**Q2.** GPS 打刻を無効化したい
**A2.** 12.8 で `gps_required = 0` に。ただしセキュリティ低下。

**Q3.** デフォルト時給を一括変更したい
**A3.** 12.8 の `default_hourly_rate` を更新。スタッフ個別時給があればそちらが優先。

**Q4.** 早出許容時間を 30 分に伸ばしたい
**A4.** 12.8 の `early_clock_in_minutes` を 30 に。最大 60 分。

**Q5.** 夜勤打刻 (24:00 跨ぎ) は対応?
**A5.** `clock_in='23:00'` `clock_out='07:00'` の翌日打刻でも自動計算。深夜割増 (12.9.4) も適用。

### B. AI 提案・自動化

**Q6.** AI 提案は当たる?
**A6.** 過去 3 ヶ月以上のデータがあれば精度高。新規店舗は数ヶ月後から。

**Q7.** AI 提案で device が混入してエラー
**A7.** 仕様。`DEVICE_NOT_ASSIGNABLE` (12.4.5)。device は端末用なので除外が正しい。

**Q8.** AI 提案を一部だけ適用したい
**A8.** 現状不可。`replace_existing=false` で手動シフトと混在は可能。

**Q9.** AI 提案後に手動修正可能?
**A9.** はい。週間カレンダーで個別編集 → published 化。

**Q10.** AI 提案 API のレスポンスが遅い
**A10.** 集計データ準備に時間がかかる場合あり。期間を 1 週間に絞ると改善。

### C. 打刻・勤怠

**Q11.** 打刻忘れの対処は?
**A11.** マネージャーが「勤怠一覧」で手動追加 (12.7.3)。

**Q12.** 12 時間経過で勝手に退勤になった
**A12.** 仕様。`auto_clock_out_hours` (12.8) のバッチ処理。修正は 12.7 で。

**Q13.** 早退・遅刻の記録は?
**A13.** 予定 (`shift_assignments`) と実績 (`attendance_logs`) を比較表示。差分が「遅刻/早退」として記録 (12.9.6)。

**Q14.** GPS が拒否される (位置情報許可済み)
**A14.** ブラウザのキャッシュクリア → 再ログイン → ブラウザ再起動の順で試す。Chrome 推奨。

**Q15.** スタッフの位置情報を保存している?
**A15.** いいえ。打刻時の検証だけに使い、座標は DB に残しません。

### D. 人件費・レポート

**Q16.** 残業時間を自動集計できる?
**A16.** 集計サマリー (12.9) で表示。8h 超は赤字警告。

**Q17.** スタッフ評価レポートとの連携は?
**A17.** 勤怠データが自動連携 (13 章)。

**Q18.** アルバイトと正社員を分けたい
**A18.** 時給設定で区別可 (社員は時給 null)。集計サマリーで「集計外」表示。

**Q19.** シフト表を印刷したい
**A19.** カレンダー画面で Cmd+P (ブラウザ印刷)。専用 PDF は未対応。

**Q20.** 月次集計を CSV エクスポート
**A20.** 13 章のレポート → 集計 CSV を参照。シフト個別 CSV は未実装。

### E. ヘルプ要請・店舗間

**Q21.** 複数店舗のスタッフを同時管理したい
**A21.** owner のみ可。manager は担当店舗のみ。

**Q22.** ヘルプ要請の条件は?
**A22.** 同じテナント内の店舗間のみ。他社は不可。

**Q23.** ヘルプで派遣された日の人件費はどっちの店舗?
**A23.** `shift_assignments` の `store_id` (= 派遣先) として計上。元店舗の集計には含まれない。

**Q24.** ヘルプ要請を一括承認できる?
**A24.** 現状 1 件ずつ。Phase 2 で一括化検討。

**Q25.** ヘルプ要請が pending のまま残っている
**A25.** 承認/拒否されない限り pending 継続。送信側からキャンセル可能 (`status='cancelled'`)。

### F. その他

**Q26.** シフト変更の通知は?
**A26.** LINE / メール通知は現状なし。スタッフ側がマイシフトで確認。

**Q27.** シフトのコピー機能は?
**A27.** テンプレート機能で実質コピー可。先週のシフトを今週にコピーは Phase 2。

**Q28.** 時給の一括変更
**A28.** ユーザー管理で個別変更。一括変更は未対応 (Phase 2)。

**Q29.** 労務管理ツール (freee 等) と連携できる?
**A29.** 現状連携なし。集計サマリーから手動で freee 入力推奨。CSV エクスポートは Phase 2。

**Q30.** スタッフが間違えて退勤打刻した
**A30.** マネージャーが「勤怠一覧」(12.7) で `clock_out=null`、`status='working'` に戻せば再退勤可能。

---

## 12.Y トラブルシューティング (総合)

| 症状 | 原因 | 対処 |
|---|---|---|
| カレンダーが白紙 | スタッフ未登録 / `user_stores` 未登録 | ユーザー管理で追加 + 所属店舗紐付け |
| AI 提案が出ない | データ不足 | 過去 3 ヶ月以上の運用後再試行 |
| `DEVICE_NOT_ASSIGNABLE` | device ユーザーをシフトに割り当て | スタッフ管理で device 化解除 or 提案から除外 |
| `USER_NOT_IN_STORE` | `user_stores` 未登録 | ユーザー管理 → 所属店舗を確認 |
| 打刻ボタンが押せない | GPS 範囲外 | 店舗内で再試行 |
| 希望提出ボタンが出ない | 期間外 | 店舗設定の希望提出期間確認 |
| ヘルプ送信できない | manager 権限必要 | manager に依頼 |
| 人件費が ¥0 | 時給未設定 | ユーザー管理 → 個別時給入力 |
| 残業警告が大量に出る | `overtime_threshold_minutes` が短い | 12.8 で見直し (480 分推奨) |
| シフト保存後にカレンダーに出ない | `status='draft'` のまま | published に変更 |
| `OUT_OF_RANGE` のメートル数がおかしい | GPS 精度低 (地下街など) | `gps_radius_meters` を 200-300 に拡張 |
| 「保存しました」と出るのに反映されない | ブラウザキャッシュ | Cmd+Shift+R で強制リロード |

---

## 12.Z データベース構造 (運営向け)

::: tip 通常テナントは読まなくて OK

### 主要テーブル

| テーブル | 用途 | 主キー / UNIQUE |
|---|---|---|
| `shift_templates` | 週次パターン定義 | `id` (UUID) |
| `shift_assignments` | 確定シフト | `id` (UUID) |
| `shift_availabilities` | スタッフ希望提出 | `id` (UUID), UNIQUE `(tenant_id, store_id, user_id, target_date)` |
| `attendance_logs` | 出退勤記録 | `id` (UUID) |
| `shift_settings` | 店舗別設定 | `store_id` (1:1) |
| `shift_help_requests` | ヘルプ要請 | `id` (UUID) |

### API（2026-04-18 時点の実ファイル、`api/store/shift/` 配下）

- `GET/POST/PATCH/DELETE /api/store/shift/templates.php` — シフトテンプレート CRUD (manager+)
- `GET/POST/PATCH/DELETE /api/store/shift/assignments.php` — シフト割当 CRUD (manager+)
- `GET/POST/PATCH /api/store/shift/attendance.php` — 出退勤打刻・勤怠管理 (auth+)
  - `?action=clock-in` / `?action=clock-out` (POST、self)
  - GET (manager+ で他人のも閲覧)
  - PATCH (manager+ で勤怠修正)
- `GET/POST/PATCH /api/store/shift/availabilities.php` — シフト希望提出 (auth+)
- `GET /api/store/shift/ai-suggest-data.php` — AI シフト提案データ取得 (manager+)
- `POST /api/store/shift/apply-ai-suggestions.php` — AI 提案を一括適用 (manager+)
- `GET /api/store/shift/summary.php` — 集計サマリー（労働時間・人件費）(manager+)
- `GET/POST/PATCH /api/store/shift/help-requests.php` — 他店舗ヘルプ要請 (manager+)
  - `?action=list-stores` (GET、auth+)
  - `?action=list-staff` (GET、auth+)
- `GET/PATCH /api/store/shift/settings.php` — 店舗別シフト設定 (manager+)

全ての shift 系 API は `check_plan_feature($pdo, $tenantId, 'shift_management')` を通りますが、α-1 構成では常に `true`（全契約者に標準提供）。

### マイグレーション

- `sql/migration-l3-shift-management.sql` — 5 テーブル + plan_features 初期投入
- `sql/migration-l3b-gps-staff-control.sql` — GPS / staff_visible_tools カラム追加
- `sql/migration-l3b2-user-visible-tools.sql` — ユーザー個別 visible_tools
- `sql/migration-l3p2-labor-cost.sql` — `default_hourly_rate` + `user_stores.hourly_rate`
- `sql/migration-l3p3-multi-store-shift.sql` — `shift_help_requests` テーブル追加

### フロント

- `public/admin/js/shift-calendar.js` — 週間カレンダー描画 + AI 提案ボタン
- `public/admin/js/shift-manager.js` — タブ全体制御
- `public/admin/js/shift-help.js` — ヘルプ要請 UI
- `public/admin/js/attendance-clock.js` — 出退勤ボタン (`init({ targetSelector })`)

### 監査ログのイベント名

- `shift_template_create` / `shift_template_update` / `shift_template_delete`
- `shift_assignment_create` / `shift_assignment_update` / `shift_assignment_delete`
- `shift_ai_apply`
- `attendance_clock_in` / `attendance_clock_out` / `attendance_update`
- `availability_submit`
- `help_request_create` / `help_request_approve` / `help_request_reject` / `help_request_cancel`
- `settings_update` (シフト設定)

### 関連 API エラーコード総覧

| コード | HTTP | 発生 API | 説明 |
|---|---|---|---|
| `PLAN_REQUIRED` | 403 | 全 shift 系 | shift_management 無効 (α-1 では起きない) |
| `INVALID_VALUE` | 400 | settings | フィールド範囲外 |
| `INVALID_TIME` | 400 | templates / assignments | HH:MM 形式違反 |
| `INVALID_TIME_RANGE` | 400 | 同上 | 終了 ≤ 開始 |
| `INVALID_STAFF` | 400 | templates | required_staff 範囲外 |
| `INVALID_ROLE` | 400 | 同上 | role_hint 不正値 |
| `DEVICE_NOT_ASSIGNABLE` | 400 | apply-ai-suggestions | device ユーザー混入 |
| `USER_NOT_IN_STORE` | 400 | 同上 | user_stores 未登録 |
| `ALREADY_CLOCKED_IN` | 400 | attendance | 二重打刻 |
| `NOT_CLOCKED_IN` | 400 | attendance | 出勤なし退勤 |
| `GPS_REQUIRED` | 400 | attendance | 位置情報未取得 |
| `OUT_OF_RANGE` | 400 | attendance | 店舗距離超過 |
| `NOT_FOUND` | 404 | templates / assignments | id が見つからない |

:::

---

## 関連章

- [13. レポート](./13-reports.md) — 勤怠データの売上連携
- [15. オーナーダッシュボード](./15-owner.md) — クロス店舗集計
- [18. セキュリティ](./18-security.md) — CP1 PIN・出勤打刻保護
- [02. ログイン](./02-login.md) — staff / device ロールの違い

---

## 更新履歴

- **以前**: 基本機能 (277 行)
- **2026-04-18**: フル粒度化、FAQ + トラブル + 技術補足 追加 (427 行)
- **2026-04-19 (Phase B Batch-8)**: フル粒度展開 — テンプレート/カレンダー/AI 提案/打刻/設定/ヘルプの各節にチェックリスト・クリック単位手順・入力フィールド表・ASCII 画面遷移・実例 (matsunoya/torimaru) を追加。device ロール除外ルール (12.12) を独立節化。FAQ を 15 → 30 件 (カテゴリ別)、トラブルシューティング表を強化。
