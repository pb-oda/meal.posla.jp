---
feature_id: SETTINGS
title: 店舗設定
chapter: 16
plan: [all]
role: [owner, manager]
audience: 店長以上
keywords: [店舗設定, 営業設定, 税率, レジ開け, 領収書, Googleレビュー, KDSステーション, スタッフ管理, 監査ログ, プリンタ, 予約LP, エラー監視, ブランディング, 外観カスタマイズ, ウェルカムメッセージ]
related: [02-login, 05-tables, 09-takeout, 21-stripe-full, 24-reservations, 26-printer]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 16. 店舗設定

管理ダッシュボード（`dashboard.html`）の「店舗設定」グループ（サブタブ 6 種）では、店舗の各種設定を行います。マネージャー以上のロールで利用できます。

---

## 16.0 はじめにお読みください

::: tip 章の読み方ガイド
- **初回導入時**: 16.2 (営業設定) → 16.4 (スタッフ管理) → 16.10 (ブランディング) の順
- **日次運用**: 16.5 (監査ログ) で操作追跡 / 16.6 (エラー監視) で異常検知
- **テイクアウト導入**: 9 章と合わせて読む
- **予約管理導入**: 24 章 + 16.2「予約LP」セクション
- **プリンタ導入**: 26 章と合わせて読む（U-2）
- **領収書発行運用**: 16.9 (領収書設定タブ) はインボイス番号必須
:::

### 店舗設定タブの全体像

このグループは店舗運営の「設定のハブ」。すべての機能別設定がここに集約されています。

### 使える人

- **owner**: 全設定変更可（全店舗）
- **manager**: 担当店舗の全設定変更可
- **staff**: 閲覧のみ（編集ボタン非表示、API レベルでも `require_role('manager')`）
- **device**: アクセス不可（直接 handy/kds/cashier に飛ばされる）

### 操作前のチェックリスト

- [ ] **manager 以上のロール** でログイン済み
- [ ] **対象店舗が選択済み**（ヘッダー左上の店舗ドロップダウン）
- [ ] 設定変更後 **3 分はキャッシュが残る** ことを理解
- [ ] **税率 / 営業日区切り** を変更する場合、過去注文には反映されないことを理解
- [ ] プリンタ設定する場合は **プリンタの IP アドレス** を控えてある（26 章参照）
- [ ] 決済設定（Stripe）はここではなく **owner-dashboard 「決済設定」タブ** で行うことを理解（15.6）

### 必要なもの

- **管理画面へのログイン** (manager 以上)
- **各機能の設定情報** (プリンタ IP、Google Place ID、インボイス登録番号 等は各サブ章参照)

### 章マップ

```
16.0  はじめにお読みください              ← 今ここ
16.1  サブタブ一覧
16.2  店舗設定タブ (settings) — 営業設定 / レジ / レシート / プリンタ / Googleレビュー / テイクアウト / 予約LP / 外観カスタマイズ
16.3  KDS ステーション (kds-stations)
16.4  スタッフ管理 (staff-mgmt) — staff + device
16.5  監査ログ (audit-log) + アクティブセッション管理
16.6  エラー監視 (error-stats) — CB1
16.7  領収書設定タブ (receipt-settings) — L-5 インボイス
16.8  設定変更の反映タイミング
16.9  テイクアウト設定の連携 (9 章参照)
16.10 ブランディング (色 / ロゴ) — 詳細
16.11 プリンタ設定 (U-2) (26 章参照)
16.12 予約 LP QR 表示 (24 章参照)
16.13 画面遷移 ASCII
16.14 実例ブロック (matsunoya / torimaru)
16.15 トラブルシューティング表
16.16 よくある質問 30 件
16.17 データベース構造 (運営向け)
```

---

## 16.1 サブタブ一覧

| サブタブ | data-tab | 対象ロール | 説明 |
|---------|---|---|------|
| 店舗設定 | `settings` | manager 以上 | 営業日区切り・税率・ラストオーダー・ウェルカムメッセージ・プリンタ・外観カスタマイズ等の総合設定 |
| KDS ステーション | `kds-stations` | manager 以上 | KDS のステーション分割設定（複数キッチン構成用） |
| スタッフ管理 | `staff-mgmt` | manager 以上 | 自店舗のスタッフ + デバイスアカウント管理 |
| 監査ログ | `audit-log` | manager 以上 | 操作履歴の閲覧 + アクティブセッション管理 |
| **エラー監視** | `error-stats` | manager 以上 | 過去 N 時間のエラー集計（CB1、新規） |
| 領収書設定 | `receipt-settings` | manager 以上 | インボイス番号・店舗名・住所・電話・フッター + 発行履歴 |

実装: `dashboard.html` L98-105（`data-group="config"` のサブタブ）

::: tip サブタブの表示 / 非表示
歯車アイコン（`btn-subtab-settings`）から、各サブタブを個別に非表示化できます。よく使うサブタブだけに絞ると操作がシンプルになります。
:::

---

## 16.2 店舗設定タブ (settings)

`public/admin/js/settings-editor.js`（444 行）。最も項目が多いタブ。上から順に以下のセクションが並びます。

### 操作前のチェックリスト

- [ ] 営業日区切り時刻を決めている（深夜営業店は 05:00 推奨）
- [ ] 税率（軽減税率対応する場合は別途 9 章のテイクアウト設定）
- [ ] レジ開けの初期金額の方針が決まっている（つり銭準備金）
- [ ] レシートに印字したい店舗名 / 住所 / 電話番号を準備
- [ ] Google Place ID を取得済（高評価レビュー誘導する場合のみ、任意）

### 16.2.1 営業基本設定

| 設定項目 | フィールド ID | 種類 | 初期値 | 説明 |
|---------|---|---|--------|------|
| 営業日区切り時刻 | `set-cutoff` | time | 05:00 | この時刻でレポートの「1日」が区切られる。深夜営業の場合、翌日の 5:00 までが前日の売上として集計 |
| 税率（%） | `set-tax` | number (step 0.1) | 10 | 店内飲食の標準消費税率。`tax_rate` |
| 1 回の注文品数上限 | `set-max-items` | number | 10 | セルフオーダーでの 1 回の注文の品目上限。`max_items_per_order` |
| 1 回の注文金額上限 | `set-max-amount` | number | 30000 | セルフオーダーでの 1 回の注文の金額上限。`max_amount_per_order` |
| ラストオーダー時刻 | `set-lo-time` | time | (空欄=無効) | 設定するとこの時刻にセルフオーダーの新規注文が自動ブロック。`last_order_time` |

### 16.2.2 レジ設定

| 設定項目 | フィールド ID | 種類 | 初期値 | 説明 |
|---------|---|---|--------|------|
| デフォルト開店金額 | `set-open` | number | 30000 | レジ開け時のデフォルトつり銭準備金。`default_open_amount` |
| 過不足閾値 | `set-overshort` | number | 500 | この金額を超える過不足がある場合、レジレポートで警告表示。`overshort_threshold` |
| 利用可能な支払方法 | `pay-cb`（チェックボックス × 3） | multi | 全 ON | `cash` / `card` / `qr`（カンマ区切りで `payment_methods_enabled` に保存） |
| セルフレジ | `set-self-checkout` | toggle | OFF | 顧客がスマホで自己決済できる機能。事前に owner-dashboard で Stripe 設定必須 |

### 16.2.3 レシート設定

::: warning レシート設定の二箇所
このタブでも「店舗名 / 住所 / 電話 / フッター」を設定できますが、**正式な領収書（インボイス対応）** の設定は 16.7「領収書設定」タブで行います。両者の関係は以下:

- このタブの **レシート設定** = 全レシートに印字（簡易レシート / インボイス両方）
- **領収書設定タブ** (16.7) = 適格請求書（インボイス）専用の追加情報（登録番号 T+13 桁、事業者名）
:::

| 設定項目 | フィールド ID | 説明 |
|---------|---|------|
| 店舗名 | `set-receipt-name` | レシートに印刷する店舗名 |
| 住所 | `set-receipt-addr` | レシートに印刷する住所 |
| 電話番号 | `set-receipt-phone` | レシートに印刷する電話番号 |
| フッターテキスト | `set-receipt-footer` | レシート下部に印刷するメッセージ（複数行対応 textarea） |

### 16.2.4 プリンタ設定（U-2）

`public/admin/js/settings-editor.js` L63-90。詳細は 26 章。

| 設定項目 | フィールド ID | 種類 | 初期値 | 説明 |
|---|---|---|---|---|
| プリンタ種別 | `set-printer-type` | select | browser | `browser` / `star` (mC-Print3 WebPRNT) / `epson` (TM-m30 ePOS-Print) / `none` |
| プリンタ IP | `set-printer-ip` | text | — | 例: 192.168.1.50 |
| ポート | `set-printer-port` | number | 80 | 1〜65535 |
| 用紙幅 (mm) | `set-printer-width` | select | 80 | 58 / 80 |
| 自動印刷（キッチン伝票） | `set-printer-auto-kitchen` | toggle | OFF | 注文確定時にキッチン伝票を自動印刷 |
| 自動印刷（レシート） | `set-printer-auto-receipt` | toggle | ON | 会計完了時にレシートを自動印刷 |

**「テスト印刷」** ボタン（`btn-test-print`）で接続確認可能。`PrinterService.print()` 経由でテストパターンを送信。

### 16.2.5 Google レビュー設定

| 設定項目 | フィールド ID | 説明 |
|---------|---|------|
| Google Place ID | `set-google-place-id` | Google マップのプレイス ID。設定するとお客さんが高評価（4〜5 つ星）を付けた際に Google レビューページへのリンクが表示。`google_place_id` |

::: tip Place ID の調べ方
[Google Place ID Finder](https://developers.google.com/maps/documentation/places/web-service/place-id) で店舗名を検索 → `ChIJxxxxx...` をコピー。
:::

### 16.2.6 テイクアウト設定

| 設定項目 | フィールド ID | 種類 | 初期値 | 説明 |
|---|---|---|---|---|
| テイクアウト受付 | `set-takeout-enabled` | toggle | OFF | `takeout_enabled` |
| 最短準備時間（分） | `set-takeout-prep` | number | 30 | `takeout_min_prep_minutes` |
| 時間枠あたり最大注文数 | `set-takeout-capacity` | number | 5 | `takeout_slot_capacity` |
| 受付開始時刻 | `set-takeout-from` | time | 10:00 | `takeout_available_from` |
| 受付終了時刻 | `set-takeout-to` | time | 20:00 | `takeout_available_to` |
| オンライン決済 | `set-takeout-online` | toggle | OFF | **2026-04-18 以降は実質必須**（無断キャンセル防止）。`takeout_online_payment` |

ON にすると下に **テイクアウト QR コード** + URL + 4 ボタン（URL コピー / QR 画像コピー / 印刷）が表示されます。

### 16.2.7 予約 LP（L-9）

L-9 セクション。**1 つの URL で「予約も空き状況確認も両方カバー」**。Google マイビジネス / Instagram / 店頭ポスターすべて同じ URL で OK。

| ボタン | 機能 |
|---|---|
| **URL をコピー** | クリップボードにコピー |
| **新タブで開く** | 別タブで `customer/reserve.html` を開く |
| **QR 画像コピー** | PNG 形式でクリップボードにコピー |
| **印刷** | A4 印刷用 HTML を別ウィンドウで開く（タイトル「📅 ご予約はこちら」） |

詳細は 24 章。

### 16.2.8 外観カスタマイズ

| 設定項目 | フィールド ID | 種類 | 既定 | 説明 |
|---------|---|---|---|------|
| テーマカラー | `set-brand-color` + `set-brand-color-picker` | color + text | (空欄) | セルフオーダー画面のメインカラー。HEX 値（例: `#ff6f00`）。**「デフォルトに戻す」** ボタンあり |
| 表示名 | `set-brand-display-name` | text | (空欄) | ヘッダーに表示するブランド名。`brand_display_name` |
| ロゴ画像 URL | `set-brand-logo-url` | text | (空欄) | ヘッダーロゴの画像 URL。ライブプレビュー付き（`brand-logo-preview`） |

::: tip デフォルト配色
- セルフオーダー: `#ff6f00`（オレンジ）
- テイクアウト: `#2196F3`（ブルー）

これらは未設定時の既定。テーマカラーを設定すると両方とも上書きされます。
:::

### 16.2.9 入力フィールド一覧（営業設定の代表 10 項目）

| フィールド ID | 必須 | 検証 | 既定 |
|---|---|---|---|
| `set-cutoff` | ✅ | HH:MM | 05:00 |
| `set-tax` | ✅ | 0〜30 | 10 |
| `set-max-items` | ✅ | 1〜100 | 10 |
| `set-max-amount` | ✅ | 1〜1000000 | 30000 |
| `set-lo-time` | — | HH:MM or 空 | 空（無効） |
| `set-open` | ✅ | 0〜1000000 | 30000 |
| `set-overshort` | ✅ | 0〜100000 | 500 |
| `set-receipt-name` | — | 〜100 文字 | (空欄) |
| `set-receipt-addr` | — | 〜200 文字 | (空欄) |
| `set-receipt-phone` | — | 〜20 文字 | (空欄) |

### クリック単位の操作手順

1. 上部メニューから **「店舗設定」** 親タブ → サブタブ **「店舗設定」** を選択
2. 各セクション（営業 / レジ / レシート / プリンタ / Google / テイクアウト / 予約 / 外観）を上から下にスクロール
3. 必要箇所を編集
4. 一番下の **「設定を保存」** ボタン（`btn-save-settings`）を押す
5. トースト「設定を保存しました」が出れば成功
6. プリンタ設定変更時は **「テスト印刷」** で接続確認

### 画面遷移 ASCII

```
[dashboard.html] → [店舗設定 親タブ] → [サブタブ "settings"]
        ↓
[SettingsEditor.load()]
   ├ AdminApi.getSettings()  → GET /api/store/settings.php?store_id=
   ↓
[renderForm(s)]  ← 大きなフォーム
   ├ 営業 / レジ / レシート / プリンタ / Google / テイクアウト / 予約 / 外観
   ↓
[btn-save-settings click]
   ↓
[PATCH /api/store/settings.php (Body = 全フィールド)]
   ├ 認証: require_role('manager') + tenant 境界チェック
   ↓
[Toast 「設定を保存しました」]
```

### トラブルシューティング表（営業設定）

| 症状 | 原因 | 対処 |
|---|---|---|
| 税率変更が反映されない | キャッシュ | 注文を新規作成から試す（既存は元の税率） |
| 営業日が区切れない | `day_cutoff_time` 不整合 | 24h 表記の HH:MM を確認 |
| ラストオーダーが効かない | `last_order_time` 空欄 | 時刻を入力（空欄＝無効） |
| QR 決済の集計が来ない | POSLA は記録のみ | PayPay / d 払い等の外部アプリで実決済 |
| プリンタテスト失敗 | IP 違い / プリンタ電源 | 26 章参照 |
| Google レビュー誘導が出ない | Place ID 未設定 / 評価 ≤ 3 | ID 入力 + 4-5 つ星のときのみ表示（仕様） |

---

## 16.3 KDS ステーション (kds-stations)

複数キッチン構成（例: 焼き場 + 寿司場 + ドリンク場）の店舗で、KDS 画面をカテゴリ別に分割するための設定。`public/admin/js/kds-station-editor.js`。

### 操作前のチェックリスト

- [ ] カテゴリ管理（dashboard 「カテゴリ管理」タブ）で **担当を分けたい単位でカテゴリ化済み**
- [ ] 各キッチンに **iPad / タブレット** が配備済み
- [ ] device ロールアカウントを作成し **`visible_tools=kds`** を設定（16.4 参照）

### クリック単位の操作手順

1. **「KDS ステーション」** サブタブ
2. 右上の **「+ ステーション追加」** ボタン
3. モーダルで以下を入力:
   - **ステーション名 ***: 例「厨房」
   - **English**: 例「Kitchen」（任意）
   - **担当カテゴリ**: チェックボックス（複数選択可）
4. **「保存」** → 一覧に追加
5. KDS 画面（`/public/kds/index.html`）でステーション選択ドロップダウンが現れる

### 入力フィールド表

| フィールド ID | 種類 | 必須 | 説明 |
|---|---|---|---|
| `st-name` | text | ✅ | ステーション名（日本語） |
| `st-name-en` | text | — | English 名 |
| `.station-cat-check` | checkbox（複数） | — | 担当カテゴリの ID 群 |

詳細は 7.10「KDS ステーションの設定」を参照してください。

---

## 16.4 スタッフ管理 (staff-mgmt)

マネージャーは自店舗のスタッフ + デバイスアカウントを管理できます。owner-dashboard の「ユーザー管理」（15.5）とは管理対象が異なります。

### 操作前のチェックリスト

- [ ] **1 人 1 アカウント徹底**（CLAUDE.md 必須ルール）
- [ ] パスワードポリシー（8 文字 + 英 + 数）を満たす初期パスワード準備
- [ ] device 作成時、用途（KDS / レジ / ハンディ）が決まっている

### サブセクション

| セクション | 内容 |
|---|---|
| スタッフ一覧 | role='staff' のユーザー（自店舗のみ） |
| デバイス一覧 | role='device' のユーザー（自店舗のみ、P1a で追加） |

### スタッフの作成

| フィールド | 種類 | 必須 | 説明 |
|-----------|------|------|------|
| 表示名 | text | ✅ | 例: 田中太郎 |
| ユーザー名 | text | ✅ | ログイン ID（テナント内ユニーク） |
| パスワード | password | ✅ | 8 文字以上 + 英字 + 数字 |
| visible_tools | multi-checkbox | — | handy / kds / register（カンマ区切り。staff 用は 2026-04-18 で UI 削除、payload からも除外） |
| 時給 | number | — | 円。`user_stores.hourly_wage` |

::: warning visible_tools の運用変更（Task #15）
2026-04-18 以降、**staff のスタッフ作成 / 編集モーダルから visible_tools UI は削除**されました（Task #15）。device アカウント側でツール設定を行う運用に統一。
:::

### デバイスの作成（P1a）

**「+ デバイス追加」** ボタン（manager 以上）→ モーダル:

| フィールド | 必須 | 説明 |
|---|---|---|
| 表示名 | ✅ | 例「KDS-01」「レジ-A」 |
| ユーザー名 | ✅ | 例「kds01」「register-a」 |
| パスワード | ✅ | 8 文字 + 英 + 数 |
| visible_tools | ✅ | `handy` / `register` / `kds`（複数可、カンマ区切り） |

サーバー側バリデーション: `api/store/staff-management.php` の `_validate_visible_tools()` で許可値以外は 400。

### スタッフの編集

- 表示名の変更
- パスワードの変更（自分でも変更可、`POST /api/auth/change-password.php`）
- 有効 / 無効の切替

::: warning マネージャーの制限
マネージャーが管理できるのは **自分の所属店舗のスタッフ + デバイス** のみです。他店舗のスタッフや他のマネージャーのアカウントは変更できません。他店舗のスタッフ管理はオーナーダッシュボードの「ユーザー管理」タブ（15.5）から行います。
:::

### device ロールの特殊ルール

- シフト管理 / 人件費 / スタッフレポート / 監査ログのスタッフ集計から除外
- ログイン後 dashboard.html ではなく **直接 handy/kds/cashier に遷移**（visible_tools の優先度: handy > register > kds）
- シフト割当（`api/store/shift/apply-ai-suggestions.php`）に device を指定するとサーバーが `DEVICE_NOT_ASSIGNABLE` エラーで拒否

詳細は CLAUDE.md「device ロール（P1a）」セクション参照。

### 画面遷移 ASCII

```
[サブタブ "staff-mgmt"]
   ├ スタッフ一覧 (role=staff)
   │   └ +追加 / 編集 / 削除
   └ デバイス一覧 (role=device)
       └ +デバイス追加 / 編集 / 削除
       
POST /api/store/staff-management.php?kind=staff
POST /api/store/staff-management.php?kind=device
PATCH .../?kind=staff&id=xxx
DELETE .../?kind=device&id=xxx
```

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| スタッフ作成失敗 | ユーザー名重複 / パスワード不一致 | 別 username、ポリシー確認 |
| visible_tools が UI に出ない | Task #15 で staff 用は削除済 | device 側で設定 |
| `DEVICE_NOT_ASSIGNABLE` | シフトに device 割当試行 | staff のみ割当可 |
| device がログイン後 dashboard に遷移 | visible_tools 未設定 | handy / kds / register のいずれか必須 |

---

## 16.5 監査ログ (audit-log)

店舗内の全操作の履歴を閲覧できます。`public/admin/js/audit-log-viewer.js`（383 行）。

### 操作前のチェックリスト

- [ ] manager 以上のロール
- [ ] 期間を絞り込みたい場合、日付範囲を決めている（既定: 今日）
- [ ] 操作種別 / スタッフを絞り込みたい場合、ドロップダウン値を確認

### クリック単位の操作手順

1. **「監査ログ」** サブタブを開く
2. 上部フィルタ:
   - **期間**: `audit-from` 〜 `audit-to`（既定: 今日 〜 今日）
   - **操作**: ドロップダウン（メニュー変更 / 注文キャンセル / 会計完了 / 返金 / 設定変更 / ログイン 等 28 種、`ACTION_LABELS`）
   - **スタッフ**: ドロップダウン（操作実行者）
3. **「検索」** ボタン → 表表示
4. 1 ページ 50 件、下部にページャ
5. 行クリックで詳細展開（before / after JSON 表示）

### 操作種別ラベル（抜粋）

| action コード | 表示 |
|---|---|
| `menu_update` | メニュー変更 |
| `menu_soldout` | 品切れ変更 |
| `staff_create` | スタッフ作成 |
| `device_create` | デバイス作成（P1a 追加） |
| `order_cancel` | 注文キャンセル |
| `payment_complete` | 会計完了 |
| `payment_refund` | 返金 |
| `cashier_pin_used` | レジ担当 PIN 使用 |
| `cashier_pin_failed` | レジ担当 PIN 失敗（5 回失敗で 10 分ロック） |
| `register_open` / `register_close` | レジ開け / 締め |
| `attendance_clock_in` / `attendance_clock_out` | 出勤 / 退勤打刻 |
| `settings_update` | 設定変更 |
| `login` / `logout` | ログイン / ログアウト |

### アクティブセッション管理（将来実装予定）

::: warning UI 未実装
管理画面側のアクティブセッション一覧 / リモートログアウト UI は **将来実装予定**です（現状は API のみ提供）。`active-sessions.php` を直接叩けば取得・削除可能ですが、admin 画面からは呼び出していません。02 章 02-login の記述に揃えています。
:::

API は以下のとおり提供済みです。

| 表示情報 | 説明 |
|---------|------|
| 端末情報 | ブラウザ / OS（User-Agent パース） |
| IPアドレス | REMOTE_ADDR（X-Forwarded-For は信頼しない） |
| ログイン日時 | `user_sessions.created_at` |
| 最終操作日時 | `user_sessions.last_active_at` |

将来 UI が実装された際は、不審なセッションや退勤後のログアウト忘れに対して **「ログアウト」** ボタンでリモートログアウトできるようになる予定です（API: `DELETE /api/store/active-sessions.php?session_id=xxx`）。

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| ログが表示されない | 期間 / 店舗フィルタ | 期間を広げる / 店舗選択を再確認 |
| 自分の操作が出ない | 反映タイミング | 数秒待ってリロード |
| CSV エクスポートしたい | Phase 2 で実装予定 | 現状は画面閲覧のみ |
| 「`cashier_pin_failed` が連続」 | ブルートフォース可能性 | 16.6 エラー監視と合わせて確認、IP ロック検討 |

---

## 16.6 エラー監視 (error-stats) — CB1

過去 N 時間の `error_log` を集計表示する新規タブ（CB1、`public/admin/js/error-stats-viewer.js`）。

### 操作前のチェックリスト

- [ ] manager 以上のロール
- [ ] エラーカタログ（`docs-internal/error-catalog.md` 等）を理解しておく

### クリック単位の操作手順

1. **「エラー監視」** サブタブを開く
2. 期間ドロップダウン: 直近 1 時間 / 6 時間 / **24 時間（既定）** / 7 日間
3. **「再読込」** ボタンまたは期間変更で自動更新
4. サマリー（件数 / カテゴリ別 / HTTP ステータス別） + 「頻発エラー トップ 10」テーブル
5. エラー番号 `[Exxxx]` をクリックで別タブに「エラーカタログ」を開く

### エラーカテゴリ

| プレフィックス | 意味 |
|---|---|
| E1 | システム |
| E2 | 入力検証 |
| E3 | 認証・認可 |
| E4 | 未発見 (404) |
| E5 | 注文・KDS |
| E6 | 決済・返金 |
| E7 | メニュー・在庫 |
| E8 | シフト・勤怠 |
| E9 | 顧客・予約・AI |

### 入力フィールド表

| フィールド ID | 種類 | 既定 | 説明 |
|---|---|---|---|
| `error-stats-hours` | select | 24 | 1 / 6 / 24 / 168 |
| `error-stats-refresh` | button | — | 再読込 |

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| 「エラーログテーブルが未作成です」 | error_log マイグレーション未実行 | POSLA 運営に問い合わせ |
| 件数 0 | 健全 / 期間内エラーなし | OK。週次で確認推奨 |
| 同じエラー番号が大量 | 特定機能の不具合 | AI ヘルプデスクに「[E2017] とは？」と質問 |

---

## 16.7 領収書設定タブ (receipt-settings) — L-5

インボイス対応の領収書（適格請求書）の設定 + 発行履歴。`public/admin/js/receipt-settings.js`（230 行）。

### 操作前のチェックリスト

- [ ] **適格請求書発行事業者の登録番号**（`T` + 13 桁）を国税庁から取得済
- [ ] 事業者名（屋号ではなく登記名）を準備
- [ ] レシート印字内容（店舗名 / 住所 / 電話）を 16.2.3 と整合させる

### クリック単位の操作手順

1. **「領収書設定」** サブタブを開く
2. 上部フォーム:
   - **適格請求書発行事業者登録番号** (`rcpt-reg-number`): T1234567890123 形式
   - **事業者名** (`rcpt-biz-name`): 株式会社○○
   - **店舗名** (`rcpt-store-name`): ○○店
   - **住所** (`rcpt-address`): 東京都...
   - **電話番号** (`rcpt-phone`): 03-xxxx-xxxx
   - **フッター** (`rcpt-footer`): ご来店ありがとうございました
3. **「設定を保存」** ボタン
4. 下部に **「発行済み領収書」** 一覧（日付選択 → 表示）
5. 各行の **「再発行」** ボタンで PDF / 印刷可

### 入力フィールド表

| フィールド ID | 必須 | 検証 | 説明 |
|---|---|---|---|
| `rcpt-reg-number` | — | `^T\d{13}$` | インボイス制度の登録番号 |
| `rcpt-biz-name` | — | 〜100 文字 | 法人名 / 屋号 |
| `rcpt-store-name` | — | 〜100 文字 | 店舗名 |
| `rcpt-address` | — | 〜200 文字 | 住所 |
| `rcpt-phone` | — | 〜20 文字 | 電話番号 |
| `rcpt-footer` | — | 〜500 文字 | お礼メッセージ等 |

### トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| 登録番号エラー | T 抜け / 桁不足 | T + 数字 13 桁 |
| 領収書に登録番号が出ない | 設定保存後のキャッシュ | 注文を新規発行 |
| 再発行ボタンが反応しない | 元注文が削除済 | 物理削除は通常ない、要調査 |
| 発行履歴が空 | 期間内に会計なし | 日付を変更 |

---

## 16.8 設定変更の反映タイミング

設定変更は保存後すぐに反映されます。ただし、以下の点に注意してください。

| 画面 | 反映タイミング |
|---|---|
| セルフオーダー画面 (menu.html) | お客さんが画面をリロードするか、メニューバージョンチェック（**3 分間隔**）で更新 |
| KDS 画面 | 次のポーリング（**3 秒間隔**）で反映 |
| ハンディ POS | 次のメニューバージョンチェック（**3 分間隔**）で反映 |
| レジ画面 (cashier) | 次のポーリングで反映 |
| 管理ダッシュボード（自分が編集中） | 即時反映（リロード不要） |

::: tip 急ぎで反映したい場合
全端末で **「キャッシュを無視して再読込」**（Cmd+Shift+R / Ctrl+Shift+F5）を実行してください。
:::

---

## 16.9 テイクアウト設定の連携

テイクアウト関連の設定項目は 16.2.6 「テイクアウト設定」セクション + 9 章を参照。主要項目:

- テイクアウト受付 ON/OFF (`takeout_enabled`)
- 受付開始 / 終了時刻 (`takeout_available_from` / `takeout_available_to`)
- スロット容量（同時受付可能数、`takeout_slot_capacity`）
- オンライン決済必須化 (`takeout_online_payment`)

::: warning オンライン決済限定運用（2026-04-18〜）
テイクアウトは 2026-04-18 より **オンライン決済限定**（店頭払い廃止、無断キャンセル防止のため）。`takeout_online_payment` を ON かつ Stripe 設定済の店舗のみ注文受付。
:::

---

## 16.10 ブランディング（色 / ロゴ）

16.2.8 の外観カスタマイズの詳細補足。**セルフオーダー画面（menu.html）** と **テイクアウト画面（takeout.html）** にのみ反映されます。管理画面（dashboard / owner-dashboard）の配色は変更されません。

### 設定項目の関係

| 項目 | 反映先 | 既定 |
|---|---|---|
| `brand_color` | menu.html / takeout.html のヘッダー / ボタン / アクセントカラー | menu=`#ff6f00` / takeout=`#2196F3` |
| `brand_display_name` | menu.html ヘッダー左の店舗名 | `stores.name` |
| `brand_logo_url` | menu.html ヘッダー左にロゴ画像 | なし |

### 推奨サイズ・形式

- **ロゴ**: 横長 (4:1)、高さ 100px 以上、PNG 透過推奨、200KB 以下
- **色**: HEX 6 桁 (`#RRGGBB`)。コントラスト比 WCAG AA 準拠を推奨

### ライブプレビュー

ロゴ URL を入力すると `brand-preview` 領域に即座にサムネイル表示されます。404 / 認証エラー画像はプレビューが空になるので、CDN を使う場合は CORS / 公開設定を確認。

---

## 16.11 プリンタ設定 (U-2)

詳細は 16.2.4 + 26 章。Star / Epson ネットワークプリンタ対応。

設定項目:

- プリンタ種別 (`none` / `browser` / `star` / `epson`)
- プリンタ IP + ポート
- 用紙幅 (58 / 80mm)
- 自動印刷 (キッチン伝票 / レシート)
- テスト印刷ボタン

`public/shared/js/printer-service.js` (`PrinterService.print(config, mode, lines)`) でプリンタ呼び出し。レシート / キッチン伝票 / テスト の 3 モード。

---

## 16.12 予約 LP QR 表示 (L-9)

16.2.7 の詳細補足。店舗設定の一番下に **「📅 予約 LP」** セクション。QR コード + URL + 4 ボタン (URL コピー / 新タブ / QR 画像コピー / 印刷)。

**重要**: 1 つの URL で予約も空き状況確認も両方カバー。Google マイビジネス / Instagram / 店頭ポスターすべて同じ URL で OK。詳細は 24 章。

---

## 16.13 画面遷移 ASCII

```
[dashboard.html] (manager / staff)
        ↓
[親タブ "店舗設定" (data-group=config)]
        ↓
[サブタブ群]
   │
   ├─ settings (店舗設定本体) → SettingsEditor.load()
   │     └ 営業 / レジ / レシート / プリンタ / Google / テイクアウト / 予約 / 外観
   │
   ├─ kds-stations → KdsStationEditor.load()
   │
   ├─ staff-mgmt
   │     ├ スタッフ一覧 (role=staff)
   │     └ デバイス一覧 (role=device, P1a)
   │
   ├─ audit-log → AuditLogViewer.load()
   │     └ アクティブセッション一覧 + リモートログアウト（UI 未実装、API のみ）
   │
   ├─ error-stats → ErrorStatsViewer.load(storeId) ← CB1
   │
   └─ receipt-settings → ReceiptSettings.load()
         ├ インボイス設定保存
         └ 発行済み領収書一覧 + 再発行
```

---

## 16.14 実例ブロック (matsunoya / torimaru)

### 例 1: matsunoya（チェーン本部、3 店舗）

- 営業日区切り: 05:00（深夜営業含む）
- 税率: 10%
- ラストオーダー: 23:30
- レジ開け: ¥30,000、過不足閾値 ¥1,000
- 決済: cash + card + qr（3 種すべて ON）
- レシート: 「松の屋 渋谷店」+ 住所 + 電話 + フッター「またのご来店をお待ちしております」
- インボイス: T1234567890123 + 株式会社松の屋
- KDS ステーション: 厨房 / ドリンク場 の 2 つ
- スタッフ: manager 1 + staff 8 + device 3（KDS / レジ / ハンディ）
- プリンタ: Star mC-Print3 (192.168.1.50, 80, 80mm)、自動印刷両方 ON
- Google Place ID: ChIJxxxxx（高評価誘導 ON）
- テイクアウト: ON、10:00-20:00、容量 5、オンライン決済 ON
- ブランディング: 赤 `#d32f2f` + ロゴ + 「松の屋」

### 例 2: torimaru（焼鳥店、1 店舗）

- 営業日区切り: 05:00
- 税率: 10%
- ラストオーダー: 22:30
- 決済: cash + card のみ（QR は未対応）
- レシート: 「鳥丸」+ 住所 + 電話 + フッター「ありがとうございました」
- インボイス: 未登録（個人事業主、免税事業者）→ 領収書設定タブは空欄
- KDS ステーション: 焼き場 のみ（1 つ）
- スタッフ: manager 1 + staff 4 + device 2（KDS / レジ）
- プリンタ: Epson TM-m30 (192.168.1.60, 80, 80mm)
- テイクアウト: OFF（席数少なく回転重視）
- ブランディング: 茶色 `#5d4037` + ロゴなし + 「鳥丸」

### 例 3: momonoya（ランチカフェ、1 店舗、個人）

- 営業日区切り: 03:00（早朝開店なし、夜営業なし）
- 税率: 10%
- ラストオーダー: 14:00
- 決済: cash のみ
- レシート: 簡易（店舗名のみ）
- インボイス: 未登録
- KDS ステーション: なし（厨房 1 つ、デフォルト）
- スタッフ: owner 1 のみ（兼マネージャー兼スタッフ）
- プリンタ: ブラウザ標準
- テイクアウト: ON、11:00-13:30、容量 3、オンライン決済 ON
- ブランディング: ピンク `#e91e63` + ロゴ + 「桃の屋」

---

## 16.15 トラブルシューティング表

### 16.15.1 一般的な問題

| 症状 | 原因 | 対処 |
|---|---|---|
| 「権限がありません」 | staff でアクセス | manager 以上でログイン |
| 保存ボタンが動かない | JS エラー | リロード（Cmd+Shift+R） |
| 設定が反映されない | キャッシュ | 各画面でリロード or 3 分待つ（16.8） |
| 店舗未選択メッセージ | 店舗 ID 未確定 | ヘッダー左の店舗ドロップダウンで選択 |
| Toast 「保存しました」が出ない | API エラー | F12 → Network タブで HTTP ステータス確認 |

### 16.15.2 サブタブ別

| サブタブ | 症状 | 対処 |
|---|---|---|
| 営業設定 | 税率変更が反映されない | 注文を新規作成から試す |
| KDS ステーション | ステーション一覧に出ない | 7.10 + `is_active=1` 確認 |
| スタッフ管理 | スタッフ作成失敗 | ユーザー名重複 or パスワード不一致 |
| スタッフ管理 | device がログイン後 dashboard に行く | visible_tools 未設定 |
| 監査ログ | ログが表示されない | 期間指定・店舗選択確認 |
| エラー監視 | 「テーブル未作成」 | POSLA 運営に問い合わせ |
| 領収書設定 | レシートに反映されない | プリンタ設定（26 章）も確認、登録番号正規表現確認 |

### 16.15.3 権限関連

| 症状 | 原因 | 対処 |
|---|---|---|
| 他店舗の設定を変更したい | manager は自店舗のみ | owner でログイン → owner-dashboard |
| 監査ログにアクセスできない | manager 以上のみ | owner or manager |
| 領収書設定のフィールドが readonly | staff でアクセス | manager 以上に切替 |

---

## 16.16 よくある質問 30 件

### A. 営業設定（5 件）

**Q1.** 営業日区切りは何時が良い?
**A1.** 深夜営業店は 05:00 推奨（デフォルト）。早朝店なら 03:00 等。売上集計の区切り時刻。

**Q2.** 税率を途中で変更すると過去データは?
**A2.** 過去の注文は元の税率のまま。新規注文から新税率が適用される。

**Q3.** 注文上限を超えたい
**A3.** `max_items_per_order` / `max_amount_per_order` を店舗設定で変更。ただしセキュリティ上推奨しない。

**Q4.** ラストオーダーを動的変更したい
**A4.** 手動で設定変更 or 「ラストオーダー時刻」設定。営業時間帯で柔軟に。

**Q5.** 軽減税率（テイクアウト 8%）を設定したい
**A5.** 現状はテイクアウト全体一律。9 章「テイクアウト管理」のテイクアウト用税率設定 (`takeout_tax_rate`) を参照。

### B. レジ設定（4 件）

**Q6.** 決済方法の追加は?
**A6.** 現状 cash / card / qr の 3 種のみ。他決済は将来対応。

**Q7.** QR 決済はどの事業者と連携?
**A7.** POSLA 側では「QR 決済」として記録のみ。PayPay / d 払い等の外部アプリで実決済を行う。

**Q8.** セルフレジを有効にする条件
**A8.** owner-dashboard で Stripe 設定（自前キー or Connect）が必須。設定なしで ON にしてもセルフ決済は失敗する。

**Q9.** つり銭準備金の運用
**A9.** デフォルト ¥30,000 を朝レジ開け時に投入。閉店時にレジ締めで過不足チェック。

### C. レシート / 領収書（5 件）

**Q10.** レシート 1 枚に会社名と店舗名 両方入れたい
**A10.** フッターテキストに「(株) XX 運営」等を追加。複数行 OK。または 16.7 領収書設定タブの「事業者名」+「店舗名」両方を埋める。

**Q11.** レシートに QR コードを印刷できる?
**A11.** `printer-service.js` の lines に `{type:'qr'}` を入れると可能。UI 化は Phase 2。

**Q12.** インボイス未登録だが領収書を出したい
**A12.** 16.7 で登録番号を空欄のまま店舗名 / 住所 / 電話のみ設定。簡易レシートとして発行可能（インボイス制度の取扱税額控除は不可）。

**Q13.** 領収書を再発行したい
**A13.** 16.7 「発行済み領収書」一覧 → 該当行の **「再発行」** ボタン。

**Q14.** 領収書の店舗名と請求書の事業者名の違い
**A14.** 店舗名 = 屋号（「松の屋 渋谷店」）、事業者名 = 法人登記名（「株式会社松の屋」）。

### D. Google レビュー（2 件）

**Q15.** Place ID が分からない
**A15.** Google Place ID Finder（developers.google.com/maps）で店舗名検索。

**Q16.** 低評価は表示したくない
**A16.** POSLA では 4-5 つ星の時のみレビュー誘導。低評価時は非表示（仕様）。

### E. 外観カスタマイズ（3 件）

**Q17.** テーマカラーを店舗ごとに変えたい
**A17.** 可能。店舗設定の「テーマカラー」で店舗別に色変更。「デフォルトに戻す」ボタンあり。

**Q18.** ロゴ画像の推奨サイズ
**A18.** 横長（4:1）で高さ 100px 以上。PNG 透過推奨、200KB 以下。

**Q19.** 管理画面の配色も変えたい
**A19.** 不可。管理画面はデフォルトのまま（仕様）。お客さん向け画面（menu.html / takeout.html）のみカスタマイズ可能。

### F. スタッフ管理（4 件）

**Q20.** 他店舗のスタッフを管理したい
**A20.** オーナーのみ可。owner-dashboard の「ユーザー管理」で全店舗対象。

**Q21.** パスワードポリシーは?
**A21.** 8 文字以上 + 英字 + 数字必須（P1-5、`api/lib/password-policy.php`）。

**Q22.** device アカウントとは?
**A22.** KDS / レジ端末専用ログイン（P1a、CLAUDE.md 参照）。1 端末 1 アカウント。シフトや勤怠の対象外。

**Q23.** staff の visible_tools が設定できない
**A23.** Task #15 で UI 削除済み。device 側で設定する運用に統一。

### G. 監査ログ / エラー監視（5 件）

**Q24.** 過去何ヶ月分の履歴?
**A24.** 全期間保存（消去機能なし）。DB 容量次第で将来アーカイブ検討。

**Q25.** CSV エクスポートできる?
**A25.** Phase 2 で実装予定。現状は画面閲覧のみ。

**Q26.** 「`cashier_pin_failed` が大量」が監査ログに出る
**A26.** PIN ブルートフォース可能性。レートリミッタ（`api/lib/rate-limiter.php`）が 5 回失敗で 10 分ロック。エラー監視タブで E3 系の頻度確認。

**Q27.** エラー監視の「未作成です」とは
**A27.** error_log マイグレーション未実行。POSLA 運営に依頼。

**Q28.** リモートログアウトで自分のセッションも消えた
**A28.** 自分のセッション ID と他端末のセッション ID を間違えた可能性。再ログインしてください。

### H. テイクアウト・予約・プリンタ（2 件）

**Q29.** テイクアウトで現金払いにしたい
**A29.** 2026-04-18 以降はオンライン決済必須（無断キャンセル防止）。現金受付は不可。

**Q30.** プリンタなしでも会計できる?
**A30.** 可能。デフォルトは「ブラウザ標準（browser）」で OS の印刷ダイアログを使う。プリンタ「none」も選択可（印刷自体スキップ）。詳細 26 章。

---

## 16.17 データベース構造 (POSLA 運営向け)

::: tip 通常テナントは読まなくて OK

### 16.17.1 主要テーブル

- **store_settings** — 店舗設定本体 (1 店舗 = 1 行)
  - 営業: `day_cutoff_time`, `tax_rate`, `max_items_per_order`, `max_amount_per_order`, `last_order_time`, `rate_limit_orders`, `rate_limit_window_min`
  - レジ: `default_open_amount`, `overshort_threshold`, `payment_methods_enabled`, `self_checkout_enabled`
  - レシート: `receipt_store_name`, `receipt_address`, `receipt_phone`, `receipt_footer`
  - インボイス: `registration_number`, `business_name` (L-5)
  - Google: `google_place_id`
  - 外観: `brand_color`, `brand_logo_url`, `brand_display_name`, `welcome_message`
  - テイクアウト: `takeout_enabled`, `takeout_min_prep_minutes`, `takeout_slot_capacity`, `takeout_available_from`, `takeout_available_to`, `takeout_online_payment`, `takeout_tax_rate`
  - プリンタ (U-2): `printer_type`, `printer_ip`, `printer_port`, `printer_paper_width`, `printer_auto_kitchen`, `printer_auto_receipt`
- **stores** — 店舗情報 (`name`, `name_en`, `slug`, `is_active`)
- **audit_log** — 操作履歴 (`actor`, `action`, `entity_type`, `entity_id`, `before`, `after`, `created_at`)
- **error_log** — エラーログ (`error_code`, `category`, `message`, `http_status`, `created_at`、CB1)
- **user_sessions** — アクティブセッション管理 (G-2)
- **users** — `role` ENUM('owner', 'manager', 'staff', 'device') (P1a で device 追加)
- **user_stores** — スタッフと店舗の紐付け（時給含む）
- **reservation_settings** — 予約関連 (24 章)
- **kds_stations** / **kds_station_categories** — KDS ステーション (7 章)
- **receipts** — 発行済み領収書（再発行用、L-5）

### 16.17.2 API エンドポイント

| エンドポイント | メソッド | 説明 |
|---|---|---|
| `/api/store/settings.php?store_id=xxx` | GET | 全設定取得 |
| `/api/store/settings.php` | PATCH | 設定更新（Body = フィールド全部） |
| `/api/store/receipt-settings.php` | GET / PATCH | インボイス設定 (L-5) |
| `/api/store/receipt.php?store_id=xxx&date=YYYY-MM-DD` | GET | 発行済み領収書一覧 |
| `/api/store/receipt.php?id=xxx&detail=1` | GET | 領収書詳細（再印刷用、receipt + items + store + payment 同梱） |
| `/api/store/audit-log.php?store_id=&from=&to=&action=&user_id=&limit=&offset=` | GET | 監査ログ |
| `/api/store/active-sessions.php?store_id=` | GET | アクティブセッション一覧 |
| `/api/store/active-sessions.php?session_id=xxx` | DELETE | リモートログアウト |
| `/api/store/error-stats.php?store_id=&hours=N` | GET | エラー集計 (CB1) |
| `/api/store/kds-stations.php` | GET / POST / PATCH / DELETE | KDS ステーション CRUD |
| `/api/store/staff-management.php?kind=staff\|device` | GET / POST / PATCH / DELETE | スタッフ + デバイス CRUD |

### 16.17.3 フロントエンド

| ファイル | 行数 | 説明 |
|---|---|---|
| `public/admin/dashboard.html` | 1643 | メイン HTML、全サブタブの container |
| `public/admin/js/settings-editor.js` | 444 | 16.2 店舗設定本体 |
| `public/admin/js/receipt-settings.js` | 230 | 16.7 領収書 (L-5) |
| `public/admin/js/audit-log-viewer.js` | 383 | 16.5 監査ログ |
| `public/admin/js/error-stats-viewer.js` | 183 | 16.6 エラー監視 (CB1) |
| `public/admin/js/kds-station-editor.js` | 176 | 16.3 KDS ステーション |
| `public/admin/js/user-editor.js` | 共有 | 16.4 スタッフ + デバイス |
| `public/shared/js/printer-service.js` | — | プリンタ呼び出し (U-2) |

### 16.17.4 権限チェック (auth.php)

- 全 API で `require_role('manager')` または `require_role('owner')`
- `require_store_access(store_id)` でテナント境界二重チェック
- staff アクセス API（読み取り系の一部）は `require_auth()` のみ

### 16.17.5 バージョン履歴の補足

| 項目 | 追加バージョン |
|---|---|
| `printer_*` 全 6 カラム | U-2 (2026-04-18 デプロイ済み) |
| `welcome_message` | L-2 |
| `brand_*` 4 カラム | L-4 |
| `takeout_*` 8 カラム | L-7 |
| `error_log` テーブル | CB1 (2026-04-19) |
| `registration_number` / `business_name` | L-5 |
| `device` ロール | P1a (2026-04-08) |

:::

---

## 関連章

- [2. ログイン・アカウント](./02-login.md)
- [5. テーブル管理](./05-tables.md)
- [9. テイクアウト管理](./09-takeout.md)
- [15. オーナーダッシュボード](./15-owner.md)
- [21. Stripe 決済](./21-stripe-full.md)
- [24. 予約管理](./24-reservations.md)
- [26. プリンタ](./26-printer.md)

---

## 更新履歴

- **以前**: 基本機能 (124 行)
- **2026-04-18**: フル粒度に書き直し、U-2 プリンタ + L-9 予約 LP + FAQ + トラブル + 技術補足 追加（393 行）
- **2026-04-19**: Phase B Batch-7 で 16.6 (エラー監視 CB1) / 16.7 (領収書設定タブ詳細 L-5) / 16.10 (ブランディング詳細) / 16.13 (画面遷移) / 16.14 (実例) / FAQ 20 件 → 30 件 に増強
