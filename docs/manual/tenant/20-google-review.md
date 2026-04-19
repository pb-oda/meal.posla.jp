---
feature_id: L-7
title: Google レビュー連携と満足度評価
chapter: 20
plan: [all]
role: [owner, manager]
audience: 店長以上
keywords: [Google, レビュー, Place ID, 満足度, 星評価, リピート率]
related: [16-settings, 17-customer, 13-reports]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 20. Googleレビュー連携と満足度評価

POSLA には、お客様の満足度を収集し、高評価のお客様を Google レビューに自動誘導する仕組みがあります。低評価は内部で店舗にだけ通知され、Google には流れない設計です。

::: tip この章の対象
- **オーナー / 店長**: Google Place ID の取得・設定（20.4 参照）
- **店舗運営チーム全員**: 満足度評価データの活用（20.5 参照）

実装関連:
- 客側 UI: `public/customer/menu.html` L2104
- API: `api/customer/satisfaction-rating.php`（POST、認証なし、セッショントークン検証あり）
- DB: `satisfaction_ratings` テーブル
:::

---

## 20.1 満足度評価の仕組み

### 20.1.1 評価のタイミング

お客様のセルフオーダー画面で、注文した品目が **すべて `served`（提供済）** ステータスになると、満足度評価のプロンプトが画面下部に表示されます。

評価表示の条件（実装: `menu.html`）:
- 注文の品目すべてが `served` ステータス
- セッションが有効（4 時間以内）
- 同じ注文に対して未評価
- 店舗で満足度評価機能が ON（デフォルト ON）

### 20.1.2 評価の方法

星（1〜5）ベースの評価インターフェースが表示され、お客様は直感的に満足度を入力できます。

| 星 | 意味 |
|---|---|
| ⭐ 1 | とても不満 |
| ⭐⭐ 2 | 不満 |
| ⭐⭐⭐ 3 | 普通 |
| ⭐⭐⭐⭐ 4 | 満足 |
| ⭐⭐⭐⭐⭐ 5 | とても満足 |

評価後、コメント欄が表示され、自由記述も可能です（任意）。

### 20.1.3 評価データの活用先

| 活用先 | 説明 |
|---|---|
| **スタッフ評価レポート** | 該当注文を取ったスタッフ（`orders.staff_id`）の平均評価に集計（13 章 13.5） |
| **メニュー別レポート** | 品目ごとの平均評価で人気・不評メニューを把握 |
| **KDS 低評価アラート** | 1〜2 星評価が出ると KDS 画面に **赤バナー**で表示（17.W 参照） |
| **Google レビュー誘導** | 4〜5 星のお客様に Google マップへの投稿リンクを表示（20.2） |

---

## 20.2 Googleレビューへの誘導

### 20.2.1 誘導の仕組み

実装: `public/customer/menu.html` L2104

```javascript
if (rating >= 4 && settings.googlePlaceId) {
  // Google レビュー投稿リンクを表示
  var reviewUrl = 'https://search.google.com/local/writereview?placeid=' 
                  + encodeURIComponent(settings.googlePlaceId);
  // ...
}
```

- **4〜5 星（高評価）** をつけたお客様にのみ、Google レビュー投稿リンクが表示される
- **1〜3 星（中・低評価）** にはリンクは表示されない（マイナスレビューの拡散防止）
- リンクをタップすると、Google マップのレビュー投稿画面が新タブで開く

### 20.2.2 表示メッセージ

```
ありがとうございます！
お客様の声は私たちの励みになります。
よろしければ Google マップでもレビューを書いていただけませんか？
[ ⭐ Google でレビューを書く ]
```

### 20.2.3 リンク URL の形式

```
https://search.google.com/local/writereview?placeid=ChIJrTLr-GyuEmsRBfy61i59si0
```

`placeid=` の後に **Google Place ID** を URL エンコードして付与されます。

---

## 20.3 効果と KPI

### 20.3.1 期待効果

- **Google レビュー投稿数の増加**: 平均 2〜3 倍（POSLA 既存導入店舗の事例）
- **平均評価の向上**: 4 つ星以上だけ誘導するため、Google マップ上の星平均が上がる傾向
- **新規来店促進**: Google マップでお店を見つけたお客様が「行ってみよう」となる確率向上

### 20.3.2 計測すべき指標

- 月次の Google マップ閲覧数
- レビュー投稿数（前年比・前月比）
- 新規来店者数（Google マップ経由）

---

## 20.4 Google Place ID の取得・設定

### 20.4.1 Place ID とは

Google マップ上の **店舗を一意に識別する ID** です。例: `ChIJrTLr-GyuEmsRBfy61i59si0`

### 20.4.2 Place ID の取得方法

#### 方法 A: Google の公式ツール（推奨）

1. ブラウザで [Place ID Finder](https://developers.google.com/maps/documentation/places/web-service/place-id) を開く
2. 検索ボックスに **店舗名** または **住所** を入力（例: 「松乃家 渋谷店」）
3. 候補から自店舗を選択
4. 地図の下に表示される **Place ID 文字列**（`ChIJ...` で始まる）をコピー

#### 方法 B: Google マップから取得

1. [Google Maps](https://www.google.com/maps) で自店舗を検索
2. 店舗のページを開く
3. URL の中に `!1s` の後ろに表示される ID を確認
4. ただし、この ID は内部 ID であり Place ID とは違うフォーマット — 方法 A 推奨

### 20.4.3 POSLA への設定方法

1. owner / manager で `dashboard.html` にログイン
2. 上部タブ **【店舗設定】** → サブタブ **【店舗設定】**
3. 画面を下にスクロール → **「Google レビュー設定」** セクション
4. **「Google Place ID」** 入力欄に取得した ID を貼り付け
5. **【設定を保存】** ボタン
6. お客様のセルフオーダー画面に即反映（3 分のメニューキャッシュ後）

### 20.4.4 設定後の確認

1. お客様役で QR を読んでセルフオーダー画面を開く
2. テスト注文を入れて全品目を `served` まで進める
3. 満足度評価ダイアログで **5 星** を選ぶ
4. **Google レビュー投稿リンク**が表示されればOK
5. リンクをタップして Google マップのレビュー画面が開けばOK

---

## 20.5 評価データの活用ストーリー

### 20.5.1 月次定例ミーティング

毎月の店長会議で:
1. 13 章 13.5 スタッフ評価レポートを開く
2. 過去 30 日の評価平均を確認
3. **下位 3 名のスタッフ** に対する研修プラン
4. **上位 3 名** にボーナス・表彰
5. メニュー別評価で **下位 3 品** の改善・廃止検討

### 20.5.2 KDS 低評価リアルタイム対応

1. 営業中、KDS 画面に **赤バナーで低評価アラート**が表示される
2. その場でキッチンで品質確認（同じ問題が続いていないか）
3. お客様がまだ店内にいれば、ホールスタッフが伺ってお詫び
4. 必要なら割引・サービス品で対応
5. 翌朝のミーティングで原因を深掘り

### 20.5.3 Google レビュー継続的 KPI

- **目標**: 月 5 件以上の新規 Google レビュー
- **目標**: 平均星評価 **4.5 以上** を維持
- **対策**: 評価が落ちたら原因究明（メニュー・スタッフ・接客フロー）

---

## 20.6 関連設定との連携

### 20.6.1 16 章「店舗設定」との関係

Google Place ID は **16-settings.md** の 16.X セクションで設定します。本章はその活用方法を解説。

### 20.6.2 13 章「レポート」との関係

満足度評価データは **13-reports.md** の 13.5 スタッフ評価レポートに自動集計されます。

### 20.6.3 17 章「セルフオーダー画面」との関係

お客様視点の挙動詳細は **17-customer.md** の 17.W 満足度評価セクション参照。

### 20.6.4 7 章「KDS」との関係

低評価アラートの KDS 画面表示は **07-kds.md** の 7.7.X 低評価アラート参照。

---

## 20.X よくある質問

**Q1.** Place ID の取得方法

**A1.** [Google Place ID Finder](https://developers.google.com/maps/documentation/places/web-service/place-id) で店舗名検索が最も簡単・確実。

**Q2.** 低評価のお客様も Google に誘導される？

**A2.** **誘導されません**（4〜5 星のみ）。1〜3 星は店舗に内部通知のみ。Google マップ上の評価が下がるリスクを減らします。

**Q3.** 設定したのにレビューリンクが出ない

**A3.** 以下を確認:
- セルフメニューを再読み込み（3 分のメニューキャッシュ）
- Place ID が正しい形式（`ChIJ...` で始まる）
- 評価が 4 以上か（3 以下なら表示しない仕様）

**Q4.** Place ID 設定なしでも満足度評価は使える？

**A4.** はい。満足度評価データは内部レポート用にも使えます。Google レビュー誘導だけが無効になる。

**Q5.** 評価データはどこに保存される？

**A5.** `satisfaction_ratings` テーブル。`orders.id` と紐付けて保存。

**Q6.** 評価 UI のデザインを変えたい

**A6.** 現状はカスタマイズ不可（POSLA 標準デザイン）。将来的にロゴ色・テーマカラーが反映される予定。

**Q7.** お客様が複数回評価できる？

**A7.** 同じ注文に対しては 1 回のみ。同じテーブルセッションで別の注文があれば、それぞれ評価可。

**Q8.** 英語のお客様にも対応している？

**A8.** はい。多言語対応（5 言語）で評価 UI が翻訳されます。Google レビュー画面は Google 側で多言語対応。

**Q9.** Stripe Reader S700 とは関係ある？

**A9.** ありません。S700 はカード決済用、満足度評価は注文後の完全に別フロー。

**Q10.** Google レビュー数が増えない

**A10.** 以下を確認:
- 平均評価が 4 未満（リンク表示されない）
- お客様がリンクをタップしていない（リンクが目立たない可能性）
- Google アカウントを持っていない（投稿不可）
- 営業件数自体が少ない（来店数増加の施策も必要）

---

## 20.Y トラブルシューティング

| 症状 | 原因 | 対処 |
|---|---|---|
| 評価ダイアログが出ない | 全品目が served になっていない | KDS で全品 served にする |
| 評価ダイアログが出ない | 既に評価済み | 同じ注文には 1 回のみ |
| Google リンクが出ない | 評価 3 以下 / Place ID 未設定 | 4-5 星かつ Place ID 設定要 |
| Google リンクが古い店舗を開く | Place ID 入力ミス | Place ID 再取得・再設定 |
| 評価データがレポートに反映されない | キャッシュ | 翌日まで待つ（一部レポートは日次集計） |

---

## 20.Z 技術補足

### DB
- `satisfaction_ratings` テーブル
  - `id`, `tenant_id`, `store_id`, `table_id`, `order_id`, `order_item_id`（任意）, `menu_item_id`, `item_name`, `rating` (1-5), `comment`, `created_at`
- `store_settings.google_place_id` カラム
- `store_settings.satisfaction_rating_enabled` カラム（デフォルト 1）

### API
- `POST /api/customer/satisfaction-rating.php` — 評価送信（認証なし、セッショントークン検証あり）
  - パラメータ: `store_id`, `table_id`, `session_token`, `order_id`, `order_item_id`, `menu_item_id`, `item_name`, `rating`
  - エラー: `MISSING_FIELDS` (400), `INVALID_RATING` (400, 1-5 範囲外), `INVALID_SESSION` (401)

### フロント
- `public/customer/menu.html` L2104 (高評価判定 + Google リンク表示)
- 評価 UI レンダリング: 同じ menu.html 内のインラインスクリプト

### 連携先 URL
```
https://search.google.com/local/writereview?placeid={URLエンコードされた placeId}
```

---

## 関連章
- [13. レポート](./13-reports.md) — スタッフ評価レポート集計
- [16. 店舗設定](./16-settings.md) — Google Place ID の設定手順
- [17. セルフオーダー](./17-customer.md) — 17.W 満足度評価詳細
- [7. KDS](./07-kds.md) — 7.7.X 低評価アラート

## 20.7 操作前チェックリスト（Google Place ID 設定前）

| # | 確認項目 | 確認方法 | NG時の対応 |
|---|---|---|---|
| 1 | Google マップ上に **店舗が登録済み** | https://maps.google.com で店舗名検索 | Google ビジネスプロフィール ([https://business.google.com](https://business.google.com)) で新規登録 |
| 2 | ビジネスプロフィールが **オーナー確認済み** | 「あなたが管理しています」表示 | はがき / 電話で確認 (Google 案内に従う) |
| 3 | 店舗名・住所が **正確** に登録 | Google マップで詳細確認 | ビジネスプロフィールで修正 |
| 4 | 自分のロールが **owner / manager** | dashboard.html ヘッダー右上 | owner / manager に依頼 |
| 5 | POSLA に **店舗 GPS 座標** が設定済み | 16 章 16.X 参照 | 店舗設定タブで緯度経度を入力 |
| 6 | 満足度評価機能が **ON** (デフォルト ON) | 店舗設定 → satisfaction_rating_enabled | ON に切り替え |

## 20.8 入力フィールド詳細表

### 20.8.1 Google レビュー設定（店舗設定タブ）

| フィールド | DB カラム | 必須 | 形式 | 例 |
|---|---|:---:|---|---|
| Google Place ID | `store_settings.google_place_id` | 任意 | `ChIJ` で始まる文字列 | `ChIJrTLr-GyuEmsRBfy61i59si0` |
| 満足度評価機能 | `store_settings.satisfaction_rating_enabled` | 必須 | `0` / `1` | `1` (ON) |

### 20.8.2 満足度評価 API リクエスト（お客様側）

| フィールド | 必須 | 形式 | 例 |
|---|:---:|---|---|
| `store_id` | ✅ | INT | `2` |
| `table_id` | ✅ | INT | `5` |
| `session_token` | ✅ | 64 文字 hex | `a1b2c3...` |
| `order_id` | ✅ | INT | `12345` |
| `order_item_id` | 任意 | INT | `67890` |
| `menu_item_id` | 任意 | INT | `100` |
| `item_name` | 任意 | 文字列 (最大 200) | `カルボナーラ` |
| `rating` | ✅ | INT (1-5) | `5` |
| `comment` | 任意 | 文字列 (最大 1000) | `美味しかった` |

エラーコード:
- `MISSING_FIELDS` (400): 必須項目欠落
- `INVALID_RATING` (400): 1-5 範囲外
- `INVALID_SESSION` (401): セッショントークン不正

## 20.9 画面遷移の ASCII 描写

```
[お客様セルフオーダー (menu.html)]
        │
        │ 注文 → 全品 served
        ▼
[満足度評価ダイアログ]
   ⭐⭐⭐⭐⭐
   選択 + コメント (任意)
        │
        │ POST /api/customer/satisfaction-rating.php
        ▼
[評価保存 (satisfaction_ratings)]
        │
        ├─ 4-5 星 + Place ID あり ──→ [Google レビュー誘導]
        │                                    │
        │                                    ▼
        │                          https://search.google.com/local/writereview?placeid=...
        │                                    │
        │                                    ▼
        │                          [Google マップ レビュー投稿]
        │
        ├─ 1-2 星 ──→ [KDS 赤バナー警告]
        │                  │
        │                  ▼
        │             ホールスタッフが伺う
        │
        └─ 全評価 ──→ [スタッフ評価レポート / メニュー別レポート]
```

## 20.10 実例

### 20.10.1 matsunoya 渋谷店: Google レビューで月 8 件獲得

**設定**:
- Place ID: `ChIJrTLr-GyuEmsRBfy61i59si0` (松乃家 渋谷店)
- 満足度評価: ON
- 平均提供時間: 12 分

**結果 (1 ヶ月)**:
- 満足度評価ダイアログ表示数: 480 件
- 評価実施率: 約 35% → 168 件評価
- 4-5 星評価: 142 件 (84%)
- Google レビュー実投稿数: 8 件 (誘導から約 6%)
- Google マップ平均星: 4.2 → 4.4 に上昇

### 20.10.2 torimaru: 個人店、Place ID 未設定で運用

**設定**:
- Place ID: 未設定 (Google マップ未登録)
- 満足度評価: ON

**結果**:
- 満足度評価のみ取得 → スタッフ評価レポートに活用
- 1-2 星評価が出たら店主が即座に対応
- Google レビュー誘導は無効 (リンク表示されない)

**学び**: Google マップ未登録の店舗でも、満足度評価データだけで運用改善に使える。

## 20.11 競合調査機能との関係

**Google Places API キー** ([POSLA 共通 API 設定](./16-settings.md)) は以下の機能で共通利用されます:

| 機能 | 利用 API | 章 |
|---|---|---|
| **Place ID 取得 (本章)** | Place ID Finder (手動) | 20.4 |
| **競合調査** | Places API Nearby Search | [14 章 14.4](./14-ai.md) |
| **住所ジオコーディング** | Geocoding API | 16 章 |

API キー設定:
- POSLA 共通: `posla_settings.google_places_api_key`
- 取得: `GET /api/posla/settings.php` (POSLA 管理者認証)
- 利用エンドポイント: `api/store/places-proxy.php` (CORS 回避 + キー非露出)

::: warning キー未設定時の挙動
`google_places_api_key` が未設定だと:
- Place ID 設定欄は使える (手動取得・貼り付けのため)
- ただし「競合調査」機能は `NO_API_KEY` (400) エラー
- POSLA 運営にキー設定を依頼してください
:::

## 20.12 Google レビュー実装の技術詳細

### 20.12.1 高評価判定ロジック (menu.html L2104)

```javascript
if (rating >= 4 && settings.googlePlaceId) {
  var reviewUrl = 'https://search.google.com/local/writereview?placeid='
                  + encodeURIComponent(settings.googlePlaceId);
  // [⭐ Google でレビューを書く] ボタンを表示
}
```

### 20.12.2 セキュリティ

- API は `require_auth()` なし (お客様セルフオーダー)
- 代わりに `session_token` で改ざん防止
- `hash_equals($dbToken, (string)$inputToken)` でタイミング攻撃防御
- 同一注文への二重投稿は DB UNIQUE 制約で拒否

### 20.12.3 評価データのプライバシー

- お客様の個人情報は **保存しない** (匿名のテーブルセッション単位)
- コメント欄は最大 1000 文字、HTML エスケープ後 DB 保存
- スタッフ評価レポートでは個別評価が見える (店長が確認可能)

## 20.13 トラブルシューティング表（拡張版）

| 症状 | 原因 | 対処 |
|---|---|---|
| 評価ダイアログが出ない | 全品 served になっていない | KDS で全品を提供済にする |
| 評価ダイアログが出ない | 既に評価済み (DB UNIQUE) | 同じ注文には 1 回のみ |
| 評価ダイアログが出ない | satisfaction_rating_enabled = 0 | 店舗設定で ON に |
| Google レビューリンクが出ない | 評価が 3 以下 | 仕様 (4-5 星のみ表示) |
| Google レビューリンクが出ない | Place ID 未設定 | 16 章 16.X で設定 |
| Google レビューリンクが出ない | Place ID 形式不正 | `ChIJ` で始まる文字列を再取得 |
| リンクが古い店舗を開く | Place ID 入力ミス | Place ID Finder で正しいものを再取得 |
| リンクをタップしても投稿画面が開かない | お客様の Google 未ログイン | お客様が Google アカウントでログインする必要あり |
| 評価データがレポートに反映されない | 集計キャッシュ | 翌日朝の日次集計後に反映 |
| KDS の赤バナーが出ない | KDS 側の low-rating 設定 | 7.7.X 参照 |
| 多言語のお客様に英語で評価 UI が出ない | 翻訳機能の問題 | 4 章 翻訳機能のトラブル参照 |
| Place ID が変わってしまった | Google ビジネスプロフィールで店舗統合・移転 | 新 Place ID を取得して再設定 |

## 20.14 KPI ダッシュボードの作り方（推奨）

### 20.14.1 月次で追うべき指標

| 指標 | 目標値 | 取得方法 |
|---|---|---|
| 満足度評価実施率 | 30% 以上 | `satisfaction_ratings.count / orders.count` |
| 平均星評価 | 4.5 以上 | `AVG(rating)` |
| 4-5 星比率 | 80% 以上 | `COUNT(rating>=4) / COUNT(*)` |
| Google レビュー実投稿数 | 月 5 件以上 | Google ビジネスプロフィール ダッシュボード |
| Google マップ平均星 | 4.3 以上 | 同上 |
| Google マップ閲覧数 | 前月比 +5% | 同上 |

### 20.14.2 改善施策

- 評価実施率が低い → 評価ダイアログのデザイン改善 (POSLA 運営に要望)
- 平均星が低い → スタッフ研修、メニュー改善、提供時間短縮
- Google レビュー数が伸びない → 高評価客にホールスタッフが「Google レビューもお願いします」と一声

## 20.X よくある質問

**Q1.** Place ID の取得方法

**A1.** [Google Place ID Finder](https://developers.google.com/maps/documentation/places/web-service/place-id) で店舗名検索が最も簡単・確実。

**Q2.** 低評価のお客様も Google に誘導される？

**A2.** **誘導されません**（4〜5 星のみ）。1〜3 星は店舗に内部通知のみ。Google マップ上の評価が下がるリスクを減らします。

**Q3.** 設定したのにレビューリンクが出ない

**A3.** 以下を確認:
- セルフメニューを再読み込み（3 分のメニューキャッシュ）
- Place ID が正しい形式（`ChIJ...` で始まる）
- 評価が 4 以上か（3 以下なら表示しない仕様）

**Q4.** Place ID 設定なしでも満足度評価は使える？

**A4.** はい。満足度評価データは内部レポート用にも使えます。Google レビュー誘導だけが無効になる。

**Q5.** 評価データはどこに保存される？

**A5.** `satisfaction_ratings` テーブル。`orders.id` と紐付けて保存。

**Q6.** 評価 UI のデザインを変えたい

**A6.** 現状はカスタマイズ不可（POSLA 標準デザイン）。将来的にロゴ色・テーマカラーが反映される予定。

**Q7.** お客様が複数回評価できる？

**A7.** 同じ注文に対しては 1 回のみ。同じテーブルセッションで別の注文があれば、それぞれ評価可。

**Q8.** 英語のお客様にも対応している？

**A8.** はい。多言語対応（5 言語）で評価 UI が翻訳されます。Google レビュー画面は Google 側で多言語対応。

**Q9.** Stripe Reader S700 とは関係ある？

**A9.** ありません。S700 はカード決済用、満足度評価は注文後の完全に別フロー。

**Q10.** Google レビュー数が増えない

**A10.** 以下を確認:
- 平均評価が 4 未満（リンク表示されない）
- お客様がリンクをタップしていない（リンクが目立たない可能性）
- Google アカウントを持っていない（投稿不可）
- 営業件数自体が少ない（来店数増加の施策も必要）

**Q11.** Google ビジネスプロフィールが必要？

**A11.** はい。Place ID は Google ビジネスプロフィールに登録された店舗のみ取得可能です。

**Q12.** Google レビューに返信できる？

**A12.** POSLA からは不可。Google ビジネスプロフィール画面から店舗オーナーが返信してください。

**Q13.** Place ID は変わる可能性がある？

**A13.** 通常は変わりません。ただし店舗統合・移転・閉店などで変わる場合があります。年 1 回確認推奨。

**Q14.** 満足度評価の集計はリアルタイム？

**A14.** スタッフ評価レポートは日次集計、KDS 低評価アラートはリアルタイム (3 秒ポーリング)。

**Q15.** 競合調査機能との API キー共有は安全？

**A15.** はい。POSLA 共通 API キーは `posla_settings` 一括管理で、サーバーサイドプロキシ経由なので、クライアントには露出しません。

---

## 20.Y トラブルシューティング

| 症状 | 原因 | 対処 |
|---|---|---|
| 評価ダイアログが出ない | 全品目が served になっていない | KDS で全品 served にする |
| 評価ダイアログが出ない | 既に評価済み | 同じ注文には 1 回のみ |
| Google リンクが出ない | 評価 3 以下 / Place ID 未設定 | 4-5 星かつ Place ID 設定要 |
| Google リンクが古い店舗を開く | Place ID 入力ミス | Place ID 再取得・再設定 |
| 評価データがレポートに反映されない | キャッシュ | 翌日まで待つ（一部レポートは日次集計） |

---

## 20.Z 技術補足

### DB
- `satisfaction_ratings` テーブル
  - `id`, `tenant_id`, `store_id`, `table_id`, `order_id`, `order_item_id`（任意）, `menu_item_id`, `item_name`, `rating` (1-5), `comment`, `created_at`
- `store_settings.google_place_id` カラム
- `store_settings.satisfaction_rating_enabled` カラム（デフォルト 1）

### API
- `POST /api/customer/satisfaction-rating.php` — 評価送信（認証なし、セッショントークン検証あり）
  - パラメータ: `store_id`, `table_id`, `session_token`, `order_id`, `order_item_id`, `menu_item_id`, `item_name`, `rating`
  - エラー: `MISSING_FIELDS` (400), `INVALID_RATING` (400, 1-5 範囲外), `INVALID_SESSION` (401)

### フロント
- `public/customer/menu.html` L2104 (高評価判定 + Google リンク表示)
- 評価 UI レンダリング: 同じ menu.html 内のインラインスクリプト

### 連携先 URL
```
https://search.google.com/local/writereview?placeid={URLエンコードされた placeId}
```

---

## 関連章
- [13. レポート](./13-reports.md) — スタッフ評価レポート集計
- [14. AI アシスタント](./14-ai.md) — 競合調査機能 (Places API 共有)
- [16. 店舗設定](./16-settings.md) — Google Place ID の設定手順
- [17. セルフオーダー](./17-customer.md) — 17.W 満足度評価詳細
- [7. KDS](./07-kds.md) — 7.7.X 低評価アラート

## 更新履歴
- **2026-04-19 (Batch-12)**: 操作前チェックリスト / 入力フィールド詳細表 / 画面遷移 ASCII / matsunoya/torimaru 実例 / 競合調査連携 / KPI ダッシュボード / FAQ 拡張
- **2026-04-19**: 全面拡充（活用ストーリー、KPI、技術詳細、トラブル）
- **2026-04-18**: フル粒度化
