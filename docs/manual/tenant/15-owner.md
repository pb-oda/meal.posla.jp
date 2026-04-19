---
feature_id: OWNER
title: オーナーダッシュボード
chapter: 15
plan: [all]
role: [owner]
audience: テナントオーナーのみ
keywords: [オーナー, 本部, クロス店舗レポート, ABC分析, ユーザー管理, 決済設定, AI分析, 本部メニュー, FAB, AIヘルプデスク]
related: [02-login, 10-plans, 13-reports, 16-settings, 21-stripe-full]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 15. オーナーダッシュボード

オーナーロールでログインすると、オーナーダッシュボード (`owner-dashboard.html`) に自動遷移します。テナント全体 (複数店舗) の管理を行うための画面です。

---

## 15.0 はじめにお読みください

::: tip 章の読み方ガイド
- **初回ログイン時**: 15.1 (タブ一覧) → 15.2 (クロス店舗レポート) から
- **多店舗展開する方**: 15.5 (ユーザー管理) + 16 章 (店舗設定) 必読
- **決済設定**: 15.7 + 21 章 (Stripe)
- **本部メニュー配信**: 15.10 + 10 章 (プラン)
- **AI ヘルプデスク (FAB)**: 15.13 で右下のオレンジ ? ボタンの使い方
:::

### オーナーダッシュボードの役割

- **全店舗のクロス集計**: 店舗を横断した売上・顧客分析
- **テナント全体の管理**: ユーザー・決済・AI・サブスク
- **本部メニュー配信** (アドオン): チェーン店向け
- 個別店舗の設定は 各店舗の `dashboard.html` へ

::: tip 右下の AI ヘルプデスク FAB（2026-04-19 設置）
オーナーダッシュボードにも管理ダッシュボードと同じ **オレンジ色 ? ボタン**（右下、60×60 円形 FAB、`posla-helpdesk-fab.js`）が常駐します。本部運営の判断で迷ったとき、エラー番号 `[Exxxx]` で出た時、すぐマニュアル全章を AI に問い合わせできます。詳細は [15.13](#1513-ai-ヘルプデスク-fab-cb1c) と [14.6](./14-ai.md#146-ai-ヘルプデスク管理画面共通-fab) を参照。
:::

### 使える人

- **owner ロールのみ**
- manager / staff は dashboard.html に留まる
- device ロール（KDS/レジ専用端末）は当然対象外（直接 handy/kds/cashier に飛ばされる）

### 操作前のチェックリスト

オーナーダッシュボードを開く前に、以下が満たされているか確認してください。

- [ ] **owner ロールのアカウントを持っている** (manager では入れません)
- [ ] **テナントが有効** (`tenants.is_active = 1`)
- [ ] **少なくとも 1 店舗が有効** (`stores.is_active = 1`、ない場合は 15.4 で追加)
- [ ] **ブラウザは Chrome / Edge / Safari の最新版**
- [ ] **Cookie が有効** (セッション維持に必須)
- [ ] **ポップアップブロックが無効** (Stripe Onboarding でリダイレクトする際に必要)

### 章マップ

```
15.0  はじめにお読みください              ← 今ここ
15.1  タブ一覧
15.2  クロス店舗レポート
15.3  ABC 分析
15.4  店舗管理
15.5  ユーザー管理
15.6  決済設定 (Stripe)
15.7  AI アシスタント (オーナー版)
15.8  契約・プラン (Stripe Billing)
15.9  シフト概況 (全契約者)
15.10 本部メニュー (hq_menu_broadcast アドオン契約時)
15.11 外部POS連携 (スマレジ)
15.12 競合調査 (M-1 で予定)
15.13 AI ヘルプデスク FAB (CB1c)
15.14 POSLA 管理画面 (`posla-admin/`) との違い
15.15 画面遷移の全体像 (ASCII)
15.16 実例ブロック (matsunoya / torimaru)
15.17 トラブルシューティング表
15.18 よくある質問 30 件
15.19 データベース構造 (運営向け)
```

---

## 15.1 タブ一覧

| タブ名 | data-tab | 説明 | 表示条件 |
|--------|---|------|---|
| クロス店舗レポート | `cross-store` | 全店舗の売上を横並びで比較 | 常時 |
| ABC分析 | `abc-analytics` | 売上貢献度によるメニュー分類 | 常時 |
| **本部メニュー** | `hq-menu` | 本部マスタメニューの一括編集・全店配信 | **`hq_menu_broadcast=1`（アドオン契約時のみ表示）** |
| 店舗管理 | `stores` | 店舗の追加・編集・削除 | 常時 |
| ユーザー管理 | `users` | 全店舗のアカウント管理 (device は非表示) | 常時 |
| AIアシスタント | `ai-assistant` | 店舗を選択してAI分析 | 常時 |
| 契約・プラン | `subscription` | サブスクリプション管理 (Stripe Billing) | 常時 |
| 決済設定 | `payment` | Stripe（自前キー / POSLA経由Connect） | 常時 |
| シフト概況 | `shift-overview` | 全店舗のシフト概要（全契約者に標準提供） | 常時 |
| 外部POS連携 | `external-pos` | スマレジ連携 | 常時 |

実装: `public/admin/owner-dashboard.html` L50-61。**本部メニュー**タブは `hq_menu_broadcast=1` のテナントのみ `applyPlanRestrictions()` で `display:block` になる（未契約なら `display:none`、`owner-app.js` L158-177）。

::: warning 廃止されたタブ
- **Square** 決済タブ — P1-2 で完全削除済み。決済設定タブは Stripe のみ
- **L-10b** 期に存在した「API キー」タブ — Gemini / Google Places は POSLA 共通管理に移行 (`posla_settings`)。テナント側 owner-dashboard には出ない
:::

---

## 15.2 クロス店舗レポート

全店舗の売上データを横断的に分析します。`public/admin/js/cross-store-report.js`。

### 操作前のチェックリスト

- [ ] 比較したい期間に **2 店舗以上の売上データ** がある (1 店舗だと「店舗別」セクションが意味を持たない)
- [ ] 比較したい期間が **営業日区切り時刻**（`day_cutoff_time`、デフォルト 05:00）と整合する
- [ ] 期間が長すぎない (1 ヶ月超は読み込みに 5 秒以上かかる場合あり)

### クリック単位の操作手順

1. ヘッダー左の **「クロス店舗レポート」** タブを押す
2. プリセットボタン **「今日」「昨日」「今週」「今月」** のいずれかを押す（または `cs-from` / `cs-to` の日付欄を直接編集）
3. 店舗を絞り込みたい場合は左上の **店舗ドロップダウン**（「全店舗合算」/ 個別店舗）を切り替える
4. **「表示」** ボタン（`btn-cs-load`）を押す
5. サマリーカード → 店舗別売上 → 提供時間分析 → 品目ランキング → キャンセル品目 → 迷い品目 の順に下にスクロール

### サマリーカード（6 枚）

| 指標 | 説明 |
|------|------|
| 売上合計 | 全店舗合計（税込）。`payment.amount_total` |
| 注文合計 | 全店舗合計の `orders.id` カウント (status='paid') |
| 店舗数 | 対象店舗数 (`stores.is_active=1` のうちデータがある店舗) |
| 平均注文額 | 売上合計 ÷ 注文合計 |
| キャンセル件数 | `orders.status='cancelled'` の合計 |
| キャンセル額 | キャンセル分の `amount_total` 合計（赤字表示） |

### 店舗別パフォーマンス表

| 列 | 説明 |
|------|------|
| 店舗 | 店舗名 (`stores.name`) |
| 注文数 | 期間内の paid 注文件数 |
| 売上 | 期間内の `amount_total` 合計 |
| 平均注文額 | 売上 ÷ 注文数 |
| キャンセル | 件数 + 比率（赤字、3 件以上で警告色） |
| 比較バー | 最大店舗を 100% としたバーチャート（CSS-only） |

### 提供時間分析（`timingSummary`）

オーダー受付 → 調理開始 → 調理完了 → 提供完了 の 3 段階タイムスタンプを集計。サンプル数 0 のときはセクション自体が出ない。

| サマリー | 説明 |
|------|------|
| 平均提供時間 | 受付 → 提供までの総時間 |
| 受付〜調理開始 | KDS で「調理開始」を押すまで |
| 調理時間 | 「調理開始」→「完成」 |
| 完成〜提供 | 「完成」→ ハンディで「提供完了」 |

下に店舗別の表 (計測数 / 平均 / 最短 / 最長 / 分布バー) が並ぶ。分布バーはオレンジ＝待ち、赤＝調理、緑＝提供の積層比率。

### その他の分析

| 分析項目 | 説明 |
|---------|------|
| 品目ランキング | 全店舗横断でのトップ 10。🥇🥈🥉 メダル付き |
| キャンセル品目 | キャンセルされた品目と機会損失額 |
| 迷い品目（hesitation） | カート追加 → 削除された品目。50% 以上で赤、30% 以上でオレンジ |

### 実例ブロック (matsunoya チェーン)

`matsunoya / momonoya / torimaru` の 3 店舗を持つテナントで、4 月 1 日〜 4 月 18 日を集計した場合の典型的な見え方:

```
売上合計: ¥4,820,000   注文合計: 1,247   店舗数: 3
平均注文額: ¥3,866    キャンセル: 18件 (1.4%)   キャンセル額: ¥58,400

店舗別:
  matsunoya  ████████████████████ 100%  ¥2,100,000  543注文
  momonoya   ████████████ 60%          ¥1,260,000  328注文
  torimaru   ███████████ 55%           ¥1,460,000  376注文
```

### 入力フィールド表

| フィールド ID | 種類 | 必須 | 既定 | 検証 |
|---|---|---|---|---|
| `cs-from` | date | ✅ | 今日 | YYYY-MM-DD |
| `cs-to` | date | ✅ | 今日 | YYYY-MM-DD、`from` 以上 |
| `cs-store-filter` | select | — | 空（全店舗合算） | `StoreSelector.getStores()` の id |

### 画面遷移 ASCII

```
[owner-dashboard] → [クロス店舗レポート タブ click]
        ↓
[CrossStoreReport.load()]
   ├ プリセット renderControls()
   ├ AdminApi.getCrossStoreReport(from, to, storeId)
   │     ↓
   │  GET /api/owner/cross-store-report.php?from=&to=&store_id=
   ↓
[renderReport(el, data)]
   サマリー → 店舗別 → 提供時間 → 品目 → キャンセル → 迷い
```

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| 表示が空 | データなし | 期間を広げる / 店舗フィルタを「全店舗合算」に |
| 売上が合わない | 営業日区切り時刻違い | 16.2 の `day_cutoff_time` を確認 |
| 「店舗別」セクションだけ出ない | 1 店舗のみ | 仕様。複数店舗で意味を持つ |
| 迷い品目が常に空 | `cart_events` 未蓄積 | P1-33 以降で記録開始。古い期間は出ない |

---

## 15.3 ABC分析

メニューを売上貢献度で A・B・C の 3 ランクに分類します。`public/admin/js/abc-analytics.js`。

### 操作前のチェックリスト

- [ ] 期間内に **20 品目以上の販売実績** がある (品目数が少ないと A/B/C の閾値が機能しない)
- [ ] 原価 (`menu_templates.cost`) が登録されている品目がある (粗利を計算するため)
- [ ] 全店舗合算 / 個別店舗のどちらで見たいか決めてある

### クリック単位の操作手順

1. ヘッダー **「ABC分析」** タブを押す
2. プリセット **「今月」**（既定）または **「今日 / 昨日 / 今週」** を押す
3. 必要なら左の **店舗フィルタ** を「全店舗合算」から個別店舗に切替
4. **「表示」** ボタンを押す
5. 上から: サマリーカード（6 枚） → ランク別サマリー（A/B/C） → ABC 分析テーブル → カテゴリ別集計

### 入力フィールド表

| フィールド ID | 種類 | 既定 | 説明 |
|---|---|---|---|
| `abc-from` | date | 月初 | 開始日 |
| `abc-to` | date | 今日 | 終了日 |
| `abc-store-filter` | select | 全店舗合算 | 店舗ドロップダウン |

### サマリーカード（6 項目）

| 指標 | 説明 | 配色 |
|------|------|---|
| 総売上 | — | `abc-card--revenue` |
| 総粗利 | 売上 − 原価 + 粗利率 % | `abc-card--profit` |
| 注文単価 | — | `abc-card--avg` |
| 注文件数 | — | `abc-card--count` |
| 品目数 | 分析対象の品目数 | `abc-card--items` |
| 総原価 | — | `abc-card--cost` |

### ランク別サマリー

| ランク | 基準 | 意味 |
|--------|------|------|
| A | 累積売上構成比 0 〜 70% | 主力商品。維持・強化すべき |
| B | 累積構成比 70 〜 90% | 準主力。改善の余地あり |
| C | 累積構成比 90 〜 100% | 低貢献。見直しまたは廃止を検討 |

### ABC 一覧テーブル

| 列 | 説明 |
|----|------|
| ランクバッジ | A / B / C（色分け、`abc-badge--a/b/c`） |
| 順位 | 売上順 |
| 品名 | — |
| カテゴリ | — |
| 数量 | 販売数 |
| 売上 | — |
| 構成比 | 全体に占める割合 + バーチャート |
| 累積構成比 | — |
| 原価 | — |
| 粗利 | 売上 − 原価（マイナスは赤字 `abc-negative`） |
| 利益率 | 粗利 ÷ 売上 % |

CSSのみのバーチャートで視覚化されます（外部チャートライブラリ不使用、Vanilla JS の制約に従う）。

### カテゴリ別集計

| 列 | 説明 |
|---|---|
| カテゴリ名 | `categories.name` |
| 品目数 / 数量 | 「3 品 / 87 個」 |
| 売上バー | 最大カテゴリ 100% との比率 |
| 売上 / 粗利 / 粗利率 | — |

### 実例ブロック

torimaru での 4 月 1 日〜 4 月 30 日 ABC 分析:

| ランク | 品目数 | 売上 | 粗利 |
|---|---|---|---|
| A | 4 品（鶏皮串、ハイボール、唐揚げ定食、生中） | ¥1,890,000 (74%) | ¥1,134,000 |
| B | 11 品（つくね、ねぎま、ハツ、レバー…） | ¥510,000 (20%) | ¥306,000 |
| C | 28 品（季節限定串各種、エビフライ、サラダ系） | ¥150,000 (6%) | ¥75,000 |

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| 全品目が A ランク | 品目数 < 5 | 期間を広げる |
| 粗利が 0 | 原材料 / レシピ未登録 | 12 章「在庫・レシピ」で登録 |
| 利益率がマイナス（赤字） | 原価 > 売価 | 価格再設定、または C ランクとして廃止検討 |
| 数値が前回と違う | キャンセル含む / 含まない解釈 | API 仕様: paid のみ（cancelled は除外） |

---

## 15.4 店舗管理

テナント内の店舗を管理します。`public/admin/js/store-editor.js`。

### 操作前のチェックリスト

- [ ] 追加する店舗のスラッグを決めている (英小文字・数字・ハイフンのみ、テナント内ユニーク)
- [ ] **無効化したい店舗に売上データがない** (削除は論理削除なので残るが、レポートから消える)
- [ ] 店舗追加が **Stripe Subscription quantity** を増やす（自動課金）ことを理解している (P1-36)

### クリック単位の操作手順（店舗追加）

1. **「店舗管理」** タブ → 右上の **「+ 店舗追加」**（`btn-add-store`）
2. モーダルで以下を入力:
   - **店舗名 ***: 例「渋谷店」
   - **店舗名 (英語)**: 例「Shibuya」(任意)
   - **スラッグ ***: 例「shibuya」(英小文字・数字・ハイフンのみ)
3. **「保存」** を押す
4. トースト「店舗を追加しました」が出れば成功
5. 裏側で `store_settings` のデフォルト行が自動作成される (`api/owner/stores.php` L74-75)
6. P1-36 により Stripe subscription の quantity が +1 自動更新される

### 入力フィールド表（追加）

| フィールド ID | 種類 | 必須 | 検証 | 例 |
|---|---|---|---|---|
| `store-name` | text | ✅ | 1〜100 文字 | 渋谷店 |
| `store-name-en` | text | — | 〜100 文字 | Shibuya |
| `store-slug` | text | ✅ | `^[a-z0-9-]+$`、テナント内ユニーク | shibuya |

### 店舗の編集

- 店舗名・英語名・スラッグの変更
- **有効/無効** の切替（無効にすると店舗の全機能が停止します）

### 店舗の削除（論理削除）

::: danger 注意
店舗を削除すると、その店舗に紐づく全てのデータ（メニュー、注文、シフト等）が影響を受けます。通常は削除ではなく無効化を推奨します。物理削除は POSLA 運営に依頼が必要。
:::

確認ダイアログ「『XX 店』を無効化しますか？」 → OK で `DELETE /api/owner/stores.php?id=xxx` (論理: `is_active=0`)。

### 画面遷移 ASCII

```
[店舗管理 タブ]
    ↓ click 「+ 店舗追加」
[admin-modal-overlay にフォーム描画]
    ↓ 入力 → click 「保存」
[POST /api/owner/stores.php]
    ├ INSERT stores
    ├ INSERT store_settings (デフォルト)
    └ sync_alpha1_subscription_quantity() → Stripe quantity +1
    ↓
[一覧再描画]  + Toast「店舗を追加しました」
```

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| `INVALID_SLUG` 400 | スラッグが大文字 / 全角 | 英小文字 + 数字 + ハイフンのみ |
| `DUPLICATE_SLUG` 409 | テナント内重複 | 別の slug を使用 |
| Stripe quantity 同期失敗 | Stripe キー未設定 / Connect 未完了 | error_log のみ。店舗作成は成功する |
| 無効化後もメニューが残る | キャッシュ | 3 分待つ or リロード |

---

## 15.5 ユーザー管理

テナント内の全ユーザーアカウントを管理します。`public/admin/js/user-editor.js`（dashboard.html / owner-dashboard.html 共用）。

### 操作前のチェックリスト

- [ ] **1 人 1 アカウントを徹底**（CLAUDE.md 必須ルール）
- [ ] スタッフごとにユーザー名 + 初期パスワードを決めている
- [ ] パスワードポリシー（8 文字 + 英 + 数）を満たすパスワードを準備
- [ ] manager 作成時、所属店舗を 1 つ以上選択することを理解している
- [ ] device ロールはここではなく **dashboard.html → スタッフ管理タブ → 「+ デバイス追加」** で作る (16.4 参照)

### クリック単位の操作手順（ユーザー追加）

1. **「ユーザー管理」** タブ → 右上の **「+ ユーザー追加」**（`btn-add-user`）
2. モーダル表示。owner 画面では **`staff` 選択肢が自動除去**される（owner-app.js L1098 `removeStaffRoleOption()`）→ owner / manager のみ作成可能
3. 必須フィールドを埋める
4. **「保存」** を押す
5. パスワードポリシー違反は赤エラー、成功は Toast「ユーザーを追加しました」

### 入力フィールド表

| フィールド | 種類 | 必須 | 説明 |
|-----------|------|------|------|
| 表示名 | text | ✅ | スタッフの表示名（例: 田中太郎） |
| ユーザー名 | text | ✅ | ログイン ID（テナント内ユニーク、英数字 + アンダースコア） |
| メール | text | — | 任意（POSLA はメアド不要） |
| パスワード | password | ✅ | 8 文字以上 + 英字 + 数字（`api/lib/password-policy.php`） |
| ロール | select | ✅ | owner / manager（owner 画面では staff 不可） |
| 所属店舗 | checkbox | ✅ | 複数店舗を選択可能（owner は全店舗に暗黙アクセス） |

### owner 画面でのユーザー一覧フィルタ

owner-dashboard 上では、**`role='staff'` および `role='device'` の行は MutationObserver で自動非表示**になります（owner-app.js L80-87）。staff 管理は各店舗の dashboard.html に委譲する設計。

| ロール | owner 画面に表示? | 管理場所 |
|---|---|---|
| owner | ✅ | owner-dashboard 「ユーザー管理」 |
| manager | ✅ | owner-dashboard 「ユーザー管理」 |
| staff | ❌（非表示） | dashboard.html「スタッフ管理」 |
| device | ❌（API レベルで除外） | dashboard.html「スタッフ管理」→「+ デバイス追加」 |

### スタッフ別の表示ツール設定（manager のみ可）

各スタッフに対して、利用可能な画面（ツール）を店舗ごとに設定できます。

| ツール | 説明 |
|--------|------|
| handy | ハンディ POS 画面 |
| kds | KDS 画面 |
| register | POS レジ画面 |

複数選択可（カンマ区切りで `users.visible_tools` に保存）。staff 用の設定は dashboard.html 側で行う。

### スタッフ別の時給設定

スタッフごと・店舗ごとに個別の時給を設定できます。未設定の場合はシフト設定のデフォルト時給が使用されます。

### パスワード変更（オーナーが他者を変更）

オーナーは全ユーザーのパスワードを変更できます（パスワードを忘れたスタッフのリセット用）。

1. ユーザー一覧で対象ユーザーの **「編集」** ボタンをクリック
2. 「パスワード」欄に新しいパスワードを入力（パスワードポリシー要件あり、下記）
3. **「保存」** をクリック
4. 新しいパスワードをスタッフに **口頭または紙メモで** 伝える（メールに書かない）

::: tip パスワードポリシー（P1-5）
新しいパスワードは以下を全て満たす必要があります。
- 8 文字以上
- 英字（A-Z または a-z）を 1 文字以上含む
- 数字（0-9）を 1 文字以上含む

要件を満たさない場合、保存時に「パスワードは8文字以上で、英字と数字を含めてください」エラーが表示されます。実装は `api/lib/password-policy.php` の `validate_password_strength()`。
:::

### オーナー自身のパスワード変更

オーナーが自分のパスワードを変更する場合は、オーナーダッシュボード**右上の「パスワード変更」ボタン**から行います（ユーザー管理タブを経由する必要はありません）。

1. オーナーダッシュボード右上の **「パスワード変更」** ボタンを押す
2. **現在のパスワード** / **新しいパスワード** / **新しいパスワード（確認）** を入力
3. **「変更する」** を押す
4. 他の端末でログイン中のオーナーセッションは自動的に無効化されます（いま使っている端末は継続）

API: `POST /api/auth/change-password.php`、Body: `{ current_password, new_password }`。

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| `staff` 選択肢が見えない | owner 画面では仕様で除去 | dashboard.html 側で staff 作成 |
| パスワードポリシーエラー | 8 文字未満 / 英のみ / 数のみ | 「abc12345」など英数混在に |
| ユーザー名重複 | テナント内ユニーク制約 | 別の username を使用 |
| 編集ボタンが反応しない | JS 読み込み失敗 | ハードリロード（Cmd+Shift+R） |
| device が一覧に出ない | 仕様 (P1a) | dashboard.html 「スタッフ管理」で確認 |

---

## 15.6 決済設定

Stripe で決済を受け付けるための設定を行います（全契約者に標準提供）。2 つの方式のいずれかを利用できます。詳細は第 21 章「Stripe 決済の全設定」を参照してください。

### 操作前のチェックリスト

- [ ] パターン A / B のどちらにするか **21.0.2 のフローチャート** で判定済み
- [ ] パターン A: 自前 Stripe アカウントが **本人確認完了済**（Stripe ダッシュボードで `charges_enabled=true`）
- [ ] パターン B: 法人 / 個人事業主の本人確認書類（運転免許証 / マイナンバーカード）を準備
- [ ] **POSLA 月額の Stripe Billing**（21.2）は別物。これは「店内決済を Stripe で受ける」設定

### 設定方式

| 方式 | 説明 | 手数料 |
|------|------|------|
| パターン A：自前 Stripe キー | テナントが自分の Stripe アカウントを持っている場合。Stripe Secret Key を登録して直接決済 | 約 3.6%（Stripe のみ） |
| パターン B：POSLA 経由 Connect | テナントが自分の Stripe アカウントを持っていない場合。「Stripe Connect に登録」から開始、POSLA が決済を代行 | 約 3.6%（Stripe）+ 1.0%（POSLA） |

### クリック単位の操作手順（パターン A）

1. **「決済設定」** タブを開く
2. **「使用する決済サービス」** で **「Stripe」** ラジオを選択（自動保存）
3. **「Stripe Secret Key」** 欄に `sk_live_...` または `sk_test_...` を貼り付け
4. **「保存」** を押す → トースト「API キーを保存しました」
5. 表示は `sk_live_***xxxxxx` のようにマスクされる
6. 表示ボタンで一時的に平文表示、削除ボタンで完全削除

### クリック単位の操作手順（パターン B）

1. **「決済設定」** タブを開く
2. ページ下部の **「Stripe Connect（POSLA 経由決済）」** セクションへスクロール
3. **「Stripe Connect に登録して決済を開始する」** ボタンを押す
4. Stripe 側のオンボーディング画面に遷移（事業者情報 / 銀行口座 / 本人確認書類入力）
5. 完了後、`?connect=success` で戻ってくる → 「Stripe Connect: 有効」表示
6. アカウント ID / `charges_enabled` / `payouts_enabled` / `details_submitted` が確認できる

### 入力フィールド表

| フィールド ID | 種類 | 必須 | 検証 | 既定 |
|---|---|---|---|---|
| `payment-gateway` (radio) | radio | ✅ | none / stripe | none |
| `ak-stripe-input` | password | パターン A 必須 | `sk_live_` または `sk_test_` で始まる | — |

### 表示状態のパターン

| Connect 状態 | 表示 |
|---|---|
| 未登録 | 緑「Stripe Connect に登録して決済を開始する」ボタン |
| オンボーディング途中（`charges_enabled=false`） | オレンジ枠「オンボーディング未完了です」+ 「再開」ボタン |
| 有効（`charges_enabled=true`） | 緑枠「✓ Stripe Connect: 有効」+ アカウント ID + 「解除」ボタン |
| 自前キー設定済 + Connect 登録試行 | 青枠「自前の決済アカウントが設定されています」info |

### Stripe Connect の解除

パターン B からパターン A に切り替えたい場合は、**「Stripe Connect を解除」** ボタンで解除できます。解除後も Stripe ダッシュボード側のアカウント自体は残るため、再接続も可能です。

### 画面遷移 ASCII

```
[決済設定 タブ]
   │
   ├─ パターンA入力 → POST /api/owner/tenants.php (stripe_secret_key) → Toast
   │
   └─ パターンB「Connect登録」click
         ↓
      POST /api/connect/onboard.php → Stripe URL 取得 → location.href
         ↓
      [Stripe オンボーディング画面（外部）]
         ↓ (5-15分)
      ?connect=success で戻る
         ↓
      GET /api/connect/status.php → 「Stripe Connect: 有効」表示
```

### 実例ブロック

| シナリオ | 推奨 |
|---|---|
| matsunoya（既に Stripe 法人アカウントあり、月商 200 万） | パターン A（手数料 3.6% のみ） |
| torimaru（2026 年 4 月開業、Stripe 未登録） | パターン B（POSLA 代行で即日開始） |
| momonoya（個人事業主、月商 50 万） | パターン B（手数料合計 4.6%、Stripe 直契約より楽） |

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| Connect Onboarding 画面が開かない | ポップアップブロック | ブラウザ設定で許可 |
| `?connect=pending` で戻る | オンボーディング中断 | 「再開」ボタンで続き |
| `?connect=refresh` で戻る | Stripe 側 URL 期限切れ | もう一度「登録」ボタン |
| パターン A で決済失敗 | キーが test / live ミスマッチ | 21.3 を参照 |
| Connect 解除後も入金が来る | Stripe 側で配信中の決済 | Stripe ダッシュボードで確認 |

---

## 15.7 AI アシスタント (オーナー版)

owner-dashboard 専用の AI 分析タブ。**店舗ドロップダウン付き** で、特定店舗のメニュー・売上を Gemini で分析できる。`public/admin/js/ai-assistant.js` を共有（dashboard.html と同じモジュール）。

### 操作前のチェックリスト

- [ ] POSLA 共通の Gemini API キーが POSLA 運営側で設定済み（`posla_settings.gemini_api_key`、L-10b）
- [ ] 分析したい店舗にメニュー・売上データが入っている
- [ ] AI 提案は **参考情報** であり、最終判断は人間がすることを理解

### クリック単位の操作手順

1. **「AI アシスタント」** タブを開く
2. 上部の **店舗ドロップダウン** で対象店舗を選択（owner 版のみの追加 UI）
3. プリセット質問（「今月の売れ筋」「メニュー改善案」「廃止候補」等）を選ぶ、または自由入力
4. **「送信」** を押す → ローディング → 結果表示
5. 必要なら追加質問（チャット形式）

### 動作モード

| モード | API |
|---|---|
| メニュー改善提案 | `POST /api/store/ai-generate.php` (mode=menu_suggest) |
| 売上分析サマリー | `POST /api/store/ai-generate.php` (mode=sales_analysis) |
| SNS 投稿生成 | `POST /api/store/ai-generate.php` (mode=sns) |

::: warning Gemini プロンプト設計
全プロンプトに「〜のみ出力。前置き・解説・補足は禁止」を含む。Gemini の前置き応答（「はい、承知いたしました…」等）は `_cleanResponse()` で自動除去。
:::

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| `AI_NOT_CONFIGURED` 503 | POSLA 側で Gemini キー未設定 | POSLA 運営に問い合わせ |
| 結果が「はい承知しました」のみ | Gemini の前置き | `_cleanResponse()` が後処理。バグなら報告 |
| 全店舗合算したい | 仕様で店舗単位 | クロス店舗レポート（15.2）と組み合わせる |

---

## 15.8 契約・プラン (Stripe Billing)

POSLA の月額料金（基本 ¥20,000 + 追加店舗 ¥17,000 + 本部配信 ¥3,000）を Stripe Billing で管理。詳細は **21.2** および **10 章「プラン・サブスク」** を参照。

### サブスク状態表示

| 表示 | `subscription_status` | 説明 |
|---|---|---|
| 「未契約」 | `none` / NULL | サブスク未開始。30 日トライアル前 |
| 「トライアル中」（残 N 日） | `trialing` | 31 日目から自動課金 |
| 「契約中」 | `active` | 通常運用 |
| 「未払い」（赤） | `past_due` | 決済失敗。Stripe Customer Portal から再決済 |
| 「キャンセル予定」（黄） | `cancel_at_period_end` | 期末で停止 |
| 「停止中」 | `canceled` | 全機能停止 |

### 操作

| ボタン | 機能 |
|---|---|
| **「契約を開始する」** | `POST /api/subscription/checkout.php` → Stripe Checkout |
| **「Stripe ポータルを開く」** | `POST /api/subscription/portal.php` → Customer Portal（解約 / 支払方法変更） |

---

## 15.9 シフト概況（全契約者に標準提供）

全店舗のシフト状況を横断的に確認します。

### 操作

| 操作 | 説明 |
|------|------|
| 店舗セレクター | 特定の店舗を選択（必須） |
| 期間選択 | 週次 / 月次 |
| 日付入力 | カスタム期間（既定: 今週月曜） |
| **「表示」** | `GET /api/store/shift/summary.php?store_id=&period=&date=` |

### 表示内容

- 期間と合計時間
- スタッフ別労働時間表（勤務日数 / 合計時間 / 残業 / 遅刻）
- 日別配置人数のバーチャート（CSS-only）

### 全店舗概況（`shift_help_request` 機能フラグが true のときのみ）

紫色の **「全店舗概況」** ボタンが追加表示され、`GET /api/owner/shift/unified-view.php` を叩いて全店舗を横断表示する。

---

## 15.10 本部メニュー（`hq_menu_broadcast` アドオン契約時のみ）

### 15.10.1 機能概要

本部一括メニュー配信アドオン（+¥3,000 / 月 / 店舗）を契約しているテナントのみ、owner-dashboard に **【本部メニュー】タブ**（`data-tab="hq-menu"`、`owner-dashboard.html` L53）が出現します。

このタブから本部マスタ（`menu_templates`）を **owner が一括編集** し、全店舗に自動配信できます。同時に、各店舗の dashboard「店舗メニュー」タブでは本部マスタ項目（name, name_en, base_price, calories, allergens, image_url）が **readonly** になります（P1-29）。

### 15.10.2 主要操作

| ボタン | 機能 |
|---|---|
| **【+ メニュー追加】** | 新しい本部メニュー品目を作成（`btn-add-hq-menu`、L89） |
| **【CSV エクスポート】** | 本部メニュー一覧を CSV でダウンロード（`btn-hq-menu-csv-export`、L86） |
| **【CSV インポート】** | 本部メニュー一括登録（`btn-hq-menu-csv-import`、L87） |
| カテゴリフィルタ | `hq-menu-category-filter` ドロップダウンで表示絞り込み（L99） |

### 15.10.3 入力フィールド表（本部メニュー追加）

| フィールド | 種類 | 必須 | 説明 |
|---|---|---|---|
| 品名 | text | ✅ | 全店共通 |
| 品名（英語） | text | — | 多言語対応 |
| カテゴリ | select | ✅ | テナント内カテゴリ |
| 基本価格 | number | ✅ | 税抜（または税込：店舗設定 16.2） |
| 原価 | number | — | ABC 分析用 |
| カロリー | number | — | アレルギー表示用 |
| アレルゲン | multi-select | — | 卵 / 乳 / 小麦 / 蕎麦 / 落花生 / えび / かに / その他 |
| 画像 URL | text | — | アップロード or 直接 URL |

### 15.10.4 本部マスタ編集の効果

owner がここで編集した内容は:

- **全店舗のセルフメニュー・ハンディ・KDS に即反映**（3 分のメニューキャッシュ後、または手動リロード）
- 店舗側が個別にカスタマイズしたい場合 `store_menu_overrides.price / is_sold_out / is_hidden` のみ上書き可能
- 店舗独自品は `store_local_items`（「限定メニュー」タブ）から追加

### 15.10.5 アドオン未契約時

タブ自体が非表示（`applyPlanRestrictions()` で `style.display='none'`）。各店舗の「店舗メニュー」タブから本部マスタを直接編集できる従来運用になります。

### 15.10.6 CSV インポート / エクスポート

CSV フォーマット（UTF-8 BOM 付き、Excel 互換）:

```
id,name,name_en,category_id,base_price,cost,calories,allergens,image_url
,鶏皮串,Chicken Skin Skewer,cat_skewer,180,60,180,"egg,wheat",https://...
```

`id` 空欄 → 新規作成、`id` あり → 既存更新（`POST /api/owner/menu-csv.php`）。

---

## 15.11 外部 POS 連携（スマレジ）

スマレジとの連携機能。詳細は内部マニュアル `docs-internal/features/22-smaregi-full.md`。

### 連携機能

| 機能 | 説明 |
|------|------|
| 接続状態 | スマレジとの OAuth 接続状態を表示 |
| 店舗マッピング | POSLA の店舗とスマレジの店舗を紐づけ |
| メニューインポート | スマレジの商品を POSLA にインポート（非破壊。既存メニューは維持されます） |

### 操作手順

1. **「外部 POS 連携」** タブを開く
2. **「スマレジに接続」** ボタン → スマレジの OAuth 画面へ遷移
3. ?smaregi=success で戻る → 接続済みエリアが展開
4. **店舗マッピング** で POSLA 店舗 ↔ スマレジ店舗を紐づけ → **「マッピングを保存」**
5. **メニューインポート** で対象店舗を選び **「インポート実行」**

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| `?smaregi=error` で戻る | OAuth 拒否 / トークン失効 | 再度「接続」ボタン |
| 店舗マッピングが空 | スマレジ側に店舗がない | スマレジダッシュボードで店舗作成 |
| インポートで重複 | 既存メニューと品名衝突 | スマレジ側で SKU 整理 |

---

## 15.12 競合調査（M-1 で実装予定）

::: warning 未実装
2026-04-19 時点では owner-dashboard に「競合調査」タブはない。M-1 タスク（中期）として、Google Places API 経由で半径 1km の競合店レビューを取得 → AI 分析する機能を予定。
:::

予定している UI:

- 自店舗の Place ID（既に 16.2 で登録済み）から半径ドロップダウン（500m / 1km / 3km）で競合店一覧
- 各店の星評価・レビュー件数・最近のレビュー要約
- 「自店との差分」AI 分析

---

## 15.13 AI ヘルプデスク FAB (CB1c)

owner-dashboard 右下に常駐する **オレンジ色の 60×60 円形 FAB**（`public/shared/js/posla-helpdesk-fab.js`）。

### 操作前のチェックリスト

- [ ] POSLA 共通 Gemini キー設定済み（POSLA 運営の作業）
- [ ] エラー番号 `[Exxxx]` を質問するときは番号をコピーしておく

### クリック単位の操作手順

1. 右下の **オレンジ ? ボタン** をクリック
2. パネルが下から展開（380px × 600px）
3. 上部に質問チップ（「料金は？」「Stripe Reader 接続」等）または自由入力
4. **「送信」** ボタン → AI 応答が下から積み上がる
5. **× ボタン**で閉じる（履歴は同セッション内保持）

### 内部仕様

| 項目 | 値 |
|---|---|
| API | `/api/store/ai-generate.php?mode=helpdesk_tenant` |
| モード変更 | `window.POSLA_HELPDESK_MODE = 'helpdesk_internal'`（POSLA 管理者用） |
| z-index | 9998 (FAB) / 9999 (パネル) |
| 多重ロード防止 | `window.__POSLA_HELPDESK_FAB__ = true` |

詳細は [14.6](./14-ai.md#146-ai-ヘルプデスク管理画面共通-fab) を参照。

---

## 15.14 POSLA 管理画面 (`posla-admin/`) との違い

owner-dashboard は **テナント運営者** 向け。一方で `public/posla-admin/` は **POSLA 運営（プラスビリーフ社）専用**。混同しないよう注意。

| 項目 | owner-dashboard.html | posla-admin/dashboard.html |
|---|---|---|
| URL | `/public/admin/owner-dashboard.html` | `/public/posla-admin/dashboard.html` |
| 対象 | 各テナントのオーナー | POSLA 運営者のみ |
| ログイン | テナント側ユーザー（owner ロール） | POSLA 管理者アカウント（別テーブル `posla_admins`） |
| スコープ | 自テナント内 | 全テナント横断 |
| 主要機能 | クロス店舗レポート / 本部メニュー / 決済設定 | テナント一覧 / 強制ログイン / API キー共通設定 / 解約処理 |
| Gemini キー | 表示しない（POSLA 共通） | 入力・編集できる |

::: danger テナント側からアクセス不可
`/public/posla-admin/` には Basic 認証 + IP 制限がかかっており、テナントは URL を知っていても入れない。問い合わせは AI ヘルプデスクまたは POSLA サポート窓口へ。
:::

---

## 15.15 画面遷移の全体像 (ASCII)

```
[ログイン /public/admin/index.html]
        ↓ (POST /api/auth/login.php)
        ↓ ロール判定
        │
        ├─ owner → /public/admin/owner-dashboard.html  ← この章
        │              │
        │              ├─ クロス店舗レポート (cross-store)
        │              ├─ ABC 分析 (abc-analytics)
        │              ├─ 本部メニュー (hq-menu) ※アドオン契約時のみ
        │              ├─ 店舗管理 (stores)
        │              ├─ ユーザー管理 (users)
        │              ├─ AI アシスタント (ai-assistant)
        │              ├─ 契約・プラン (subscription) → Stripe Checkout / Portal
        │              ├─ 決済設定 (payment) → Stripe Connect Onboarding
        │              ├─ シフト概況 (shift-overview)
        │              └─ 外部POS連携 (external-pos) → スマレジ OAuth
        │
        ├─ manager / staff → /public/admin/dashboard.html (16章)
        │
        └─ device → handy / kds / cashier (P1a, 直接遷移)

[右下 FAB] → AI ヘルプデスクパネル → /api/store/ai-generate.php
```

---

## 15.16 実例ブロック (matsunoya / torimaru)

### 例 1: matsunoya チェーン（3 店舗 + 本部配信契約）

- 契約: 基本 ¥20,000 + 追加店舗 ¥17,000 × 2 + 本部配信 ¥3,000 × 3 = **¥63,000 / 月**
- 決済: パターン A（自前 Stripe）、手数料 3.6%
- ユーザー: owner 1 名、manager 3 名（各店舗）、staff 各店 5-8 名、device 各店 3 台（KDS/レジ/ハンディ）
- 主な使い方:
  - 朝: クロス店舗レポートで前日の 3 店舗売上を比較
  - 月初: ABC 分析で先月の主力 / 廃止候補メニューを抽出
  - 月 1 回: 本部メニュー一括編集 → 全 3 店舗に即配信
  - 不定期: AI アシスタントで季節メニュー提案

### 例 2: torimaru（1 店舗、開業初月）

- 契約: 基本 ¥20,000 / 月（30 日トライアル中）
- 決済: パターン B（Stripe Connect）、手数料 4.6%
- ユーザー: owner 1 名（兼 manager）、staff 4 名、device 2 台（KDS / レジ）
- 主な使い方:
  - 日次: クロス店舗レポートで自店舗の売上確認（フィルタ「torimaru」固定）
  - 週次: ABC 分析で人気串の在庫補充判断
  - 緊急: 「Stripe Reader が接続できない」→ AI ヘルプデスクで [E6012] 検索

### 例 3: momonoya（個人事業、ランチ営業のみ）

- 契約: 基本 ¥20,000 / 月
- 決済: 現金のみ（Stripe 未契約、ゲートウェイ「なし」）
- ユーザー: owner 1 名のみ
- 主な使い方:
  - 月末: クロス店舗レポート → 月次 PDF エクスポート（13 章参照）

---

## 15.17 トラブルシューティング表

### 15.17.1 アクセス・表示

| 症状 | 原因 | 対処 |
|---|---|---|
| owner-dashboard に入れない | ロール違い | owner でログイン |
| ログイン後に dashboard.html に行く | 自動選択で店舗選択済 | URL を `/public/admin/owner-dashboard.html` に直書き |
| 全店舗が表示されない | テナント分離 | 同じテナントの店舗のみ表示（仕様） |
| タブが消えている | プラン制限 | `applyPlanRestrictions()` 参照、本部メニューはアドオン契約必要 |
| 「読み込み中...」のまま | 通信エラー / セッション切れ | F12 → Network タブで HTTP ステータス確認 |

### 15.17.2 レポート

| 症状 | 原因 | 対処 |
|---|---|---|
| 売上が合わない | 営業日区切り時刻 | 16.2 の `day_cutoff_time` 確認 |
| 比較対象がない | データ期間短い | 2 期間以上のデータが必要 |
| 提供時間が「-」 | KDS タイムスタンプ未記録 | 7 章の KDS で「調理開始」「完成」を押す運用 |
| 迷い品目が空 | `cart_events` 未蓄積 | P1-33 以降のデータのみ集計 |

### 15.17.3 ユーザー管理

| 症状 | 原因 | 対処 |
|---|---|---|
| 追加したユーザーがログインできない | `is_active=0` | ユーザー編集で有効化 |
| パスワードリセットメール来ない | メアド未登録 | POSLA はユーザー名 + パスワード（メアド任意） |
| `staff` 選択肢が出ない | owner 画面では仕様 | dashboard.html で staff 作成 |
| device が出ない | API レベルで除外（P1a） | dashboard.html「スタッフ管理」 |

### 15.17.4 決済 / Stripe

| 症状 | 原因 | 対処 |
|---|---|---|
| Connect Onboarding 開かない | ポップアップブロック | ブラウザ設定で許可 |
| `?connect=pending` 戻り | オンボーディング中断 | 「再開」ボタン |
| 決済できない | ゲートウェイ「なし」のまま | ラジオで「Stripe」選択 |

### 15.17.5 本部メニュー

| 症状 | 原因 | 対処 |
|---|---|---|
| タブが見えない | `hq_menu_broadcast=0` | アドオン契約（10 章） |
| 各店舗で反映されない | キャッシュ | 3 分待つ or 各店舗でリロード |
| CSV インポート失敗 | UTF-8 BOM なし / カラム不一致 | エクスポート CSV をテンプレに |

---

## 15.18 よくある質問 30 件

### A. アクセス・権限（5 件）

**Q1.** オーナーでも dashboard.html にアクセスできる?
**A1.** 可能。URL を直接打つか、店舗を選択して通常の管理画面に入れる。クロス分析が不要な日次運用ではこちらが便利。

**Q2.** manager / staff はオーナーダッシュボードを見られる?
**A2.** 不可。`AdminApi.me()` でロールチェック → owner 以外は `index.html` に強制リダイレクト（owner-app.js L21-33）。

**Q3.** オーナーを複数人に分けたい
**A3.** 可能。同じテナントに owner ロール複数ユーザーを作成可。共同経営者・配偶者など。

**Q4.** ログアウトせずに別アカウントで開きたい
**A4.** ブラウザのシークレットウィンドウまたは別ブラウザで。同一ブラウザでは Cookie が共有される。

**Q5.** owner ロール本人のパスワードを忘れた
**A5.** 別の owner（複数いる場合）にリセットしてもらう。1 人しかいない場合は POSLA 運営に依頼（メール認証なし運用のため）。

### B. クロス店舗レポート（5 件）

**Q6.** 店舗別に並べ替えたい
**A6.** 現状は売上順固定。並べ替えは Phase 2 で実装予定（M-2）。

**Q7.** 期間指定を柔軟にしたい
**A7.** 日次 / 週次 / 月次 / カスタム（任意期間）を選択可。プリセット 4 種 + 日付入力直接編集。

**Q8.** 特定店舗を除外したい
**A8.** 「全店舗合算」 → 「個別店舗」フィルタ。複数選択は Phase 2 予定。

**Q9.** CSV / Excel エクスポートしたい
**A9.** クロス店舗レポートは現状未対応。13 章「レポート」の単店舗版にはあり。Phase 2 で追加予定。

**Q10.** 提供時間が異常に長い
**A10.** KDS で「調理開始」「完成」を押し忘れている可能性大。最長値の店舗を 7 章「KDS」運用で見直し。

### C. ABC 分析（4 件）

**Q11.** A ランクが 1 品目しかない
**A11.** 1 品目に売上が極端に偏っている状態。客単価アップ施策（クロスセル）を検討。

**Q12.** 利益率がマイナスの品目
**A12.** 原価 > 売価。価格再設定または C ランクで廃止検討。原材料費高騰時は要注意。

**Q13.** 全品目が C
**A13.** 累積構成比の閾値判定上、品目数が多すぎると起こる。期間を絞る or カテゴリで絞る。

**Q14.** カテゴリ別集計の順序
**A14.** 売上順固定。カテゴリの並び替えはカテゴリ管理タブで設定（dashboard.html）。

### D. ユーザー管理（5 件）

**Q15.** 新しい店長を採用した
**A15.** ユーザー管理タブ → **「+ 追加」** → manager ロール + 店舗指定（複数店舗担当も可）。

**Q16.** 退職したスタッフの処理
**A16.** dashboard.html「スタッフ管理」で `is_active=0` にして無効化（完全削除ではない、監査ログ維持）。

**Q17.** パスワードリセット依頼
**A17.** ユーザー管理 → 該当ユーザー → **「編集」** → パスワード上書き → スタッフに口頭で伝達。

**Q18.** スタッフがメアドを持っていない
**A18.** メアド任意。ユーザー名 + パスワードのみで運用可（CLAUDE.md 必須ルール）。

**Q19.** 1 アカウントを複数スタッフで共有してもいい?
**A19.** **禁止**。監査ログ・勤怠打刻・シフト管理が誰の操作か分からなくなる。

### E. 決済設定（4 件）

**Q20.** Stripe Connect の初期設定
**A20.** 決済設定タブ → 「Stripe Connect に登録」 → Stripe 側で本人確認含む 1〜3 営業日。

**Q21.** 直接決済（自前 Stripe）と Connect どっちがいい?
**A21.** 21 章参照。月商 50 万以下なら Connect、それ以上なら直接がお得。

**Q22.** 決済ゲートウェイを「なし」にすると
**A22.** クレジットカード決済が一切できなくなる。現金 / QR は引き続き利用可（QR は外部アプリ）。

**Q23.** Square 設定はどこ?
**A23.** P1-2 で完全廃止。Stripe のみ。既存 Square ユーザーは個別移行（POSLA 運営に問い合わせ）。

### F. AI アシスタント（3 件）

**Q24.** AI 分析の精度は?
**A24.** Gemini API 使用。データ量が多いほど精度向上。新規店舗は数日〜 1 週間運用後から。

**Q25.** AI が間違った提案をした
**A25.** Gemini の限界。参考情報として扱い、最終判断は人間。トラブル時は AI ヘルプデスク（15.13）にエラー番号付きで質問。

**Q26.** Gemini キーを自分で設定したい
**A26.** **不可**。POSLA 運営が一括負担（CLAUDE.md「APIキーの管理方針」）。テナント設定 UI は廃止。

### G. 本部メニュー / アドオン（3 件）

**Q27.** 本部メニューを有効にしたい
**A27.** サブスク設定で「本部一括配信」アドオン（+¥3,000 / 月 / 店舗）追加。10 章参照。

**Q28.** アドオンを途中解約したら既存メニューは?
**A28.** `menu_templates` データは残る。次回課金期から本部一括編集が無効化（タブ非表示）し、各店舗の dashboard で個別編集モードに戻る。

**Q29.** 本部メニューと店舗メニューの違い
**A29.** 本部メニュー = 全店共通の `menu_templates`、店舗メニュー = `store_menu_overrides` で価格 / 売り切れだけ上書き、限定メニュー = `store_local_items` で完全独自。

### H. サブスク（1 件）

**Q30.** 店舗を追加したら料金は?
**A30.** 2 店舗目以降 ¥17,000 / 月で自動追加（Stripe Subscription quantity、`sync_alpha1_subscription_quantity` で同期、P1-36）。

---

## 15.19 データベース構造 (POSLA 運営向け)

::: tip 通常テナントは読まなくて OK

### 15.19.1 関連テーブル

- **tenants** — テナント本体 (owner 単位)
  - `stripe_customer_id`, `stripe_subscription_id`, `subscription_status`
  - `hq_menu_broadcast` TINYINT (P1-34、アドオン判定)
  - `payment_gateway`, `stripe_secret_key`, `stripe_connect_account_id`
- **stores** — 店舗 (tenant_id に紐付く)
- **users** — owner / manager / staff / device
- **user_stores** — スタッフと店舗の紐付け (manager / staff のみ)
- **menu_templates** — 本部マスタ (tenant_id 単位)
- **store_menu_overrides** — 店舗別上書き
- **subscription_events** — Stripe Webhook ログ
- **posla_settings** — POSLA 共通設定（Gemini / Google Places キー、L-10b）

### 15.19.2 API エンドポイント（2026-04-19 時点）

owner ロール専用（全て `require_role('owner')`）:

#### 店舗・ユーザー管理
- `GET/POST/PATCH/DELETE /api/owner/stores.php` — 店舗 CRUD（削除は論理削除 `is_active=0`、P1-36 で Stripe quantity 同期）
- `GET/POST/PATCH/DELETE /api/owner/users.php` — ユーザー管理（owner は全ロール作成可、manager は `staff` のみ、device はここでは不可）
- `GET/POST/PATCH/DELETE /api/owner/tenants.php` — テナント情報管理 + Stripe キー保存

#### 本部メニュー系（`hq_menu_broadcast=1` 契約時のみ機能する）
- `GET/POST/PATCH/DELETE /api/owner/menu-templates.php` — 本部メニュー CRUD
- `GET/POST /api/owner/menu-csv.php` — 本部メニュー CSV

#### 本部マスタ系
- `GET/POST/PATCH/DELETE /api/owner/categories.php` — カテゴリ CRUD
- `GET/POST/PATCH/DELETE /api/owner/ingredients.php` — 原材料 CRUD
- `GET/POST /api/owner/ingredients-csv.php` — 原材料 CSV
- `GET/POST/PATCH/DELETE /api/owner/recipes.php` — レシピ CRUD
- `GET/POST /api/owner/recipes-csv.php` — レシピ CSV
- `GET/POST/PATCH/DELETE /api/owner/option-groups.php` — オプショングループ CRUD
- `POST /api/owner/upload-image.php` — 画像アップロード

#### レポート系
- `GET /api/owner/cross-store-report.php` — クロス店舗レポート
- `GET /api/owner/analytics-abc.php` — ABC 分析
- `GET /api/owner/shift/unified-view.php` — 統合シフト閲覧（全店舗横断、`shift_help_request=true` 時のみ）

#### 契約・決済
- `POST /api/subscription/checkout.php` — サブスク Checkout セッション
- `POST /api/subscription/portal.php` — Stripe Customer Portal
- `GET /api/subscription/status.php` — サブスク状態確認
- `POST /api/connect/onboard.php` — Stripe Connect オンボード
- `POST /api/connect/disconnect.php` — Connect 切断
- `GET /api/connect/status.php` — Connect 状態確認

#### スマレジ連携
- `GET /api/smaregi/auth.php` — OAuth 開始
- `GET /api/smaregi/stores.php` — スマレジ店舗一覧
- `GET/POST /api/smaregi/store-mapping.php` — 店舗マッピング
- `GET /api/smaregi/status.php` — 連携状態
- `POST /api/smaregi/import-menu.php` — メニュー一括インポート
- `POST /api/smaregi/disconnect.php` — 切断

### 15.19.3 フロントエンド

- `public/admin/owner-dashboard.html` — メイン HTML（396 行）
- `public/admin/js/owner-app.js` — タブ制御 + 認証 + 各 API 呼び出し（1526 行）
- `public/admin/js/cross-store-report.js` — 15.2（261 行）
- `public/admin/js/abc-analytics.js` — 15.3（227 行）
- `public/admin/js/store-editor.js` — 15.4（166 行）
- `public/admin/js/user-editor.js` — 15.5（owner-dashboard でもインクルード）
- `public/admin/js/ai-assistant.js` — 15.7（dashboard と共有）
- `public/admin/js/menu-template-editor.js` — 15.10
- `public/admin/js/category-editor.js` — 15.10 サブ
- `public/shared/js/posla-helpdesk-fab.js` — 15.13（CB1c）

### 15.19.4 hq_menu_broadcast の判定

`check_plan_feature($pdo, $tenantId, 'hq_menu_broadcast')` — この関数のみ `hq_menu_broadcast` カラムを見て判定。他の機能判定はすべて true を返す（全機能標準提供、α-1 設計）。

### 15.19.5 P1-36 Stripe Subscription quantity 同期

`sync_alpha1_subscription_quantity($pdo, $tenantId)` (`api/lib/stripe-billing.php`) を以下のタイミングで呼ぶ:

- 店舗 POST（追加）
- 店舗 PATCH（`is_active` 変更時）
- 店舗 DELETE（論理削除）

エラー時は `error_log` のみで店舗操作自体は止めない。Stripe 側の最新 quantity = `stores.is_active=1` のカウント。

:::

---

## 関連章

- [2. ログイン](./02-login.md)
- [10. プラン・サブスク](./10-plans.md)
- [13. レポート](./13-reports.md)
- [14. AI](./14-ai.md)
- [16. 店舗設定](./16-settings.md)
- [21. Stripe](./21-stripe-full.md)

---

## 更新履歴

- **以前**: 基本機能 (231 行)
- **2026-04-18**: フル粒度化、FAQ + トラブル + 技術補足 追加（506 行）
- **2026-04-19**: Phase B Batch-7 で 15.13 (FAB) / 15.14 (POSLA 管理画面差) / 15.15 (画面遷移) / 15.16 (実例) / FAQ 25 件 → 30 件に増強
