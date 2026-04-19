---
feature_id: L-15
title: スマレジ連携の全設定 (完全ガイド)
chapter: 22
plan: [all]
role: [owner]
audience: オーナー
keywords: [スマレジ, OAuth, 商品インポート, 店舗マッピング, smaregi, contract_id, refresh_token]
related: [04-menu, 15-owner, 21-stripe-full]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 22. スマレジ連携の全設定 (完全ガイド)

POSLA は株式会社スマレジが提供するクラウド POS「スマレジ」と OAuth2 認証で連携できます。本章ではテナント側でやること・POSLA 運営側でやることを、どちらも完全な手順で記載します。

::: warning 売上書き戻しは未対応
2026-04-19 時点では **スマレジ → POSLA の片方向**（商品マスタ・店舗マスタの読み取り）のみサポートします。POSLA で発生した売上を スマレジに書き戻す機能（`sync-order.php`）はファイル自体は存在しますが運用未対応です。詳細は 22.7 参照。
:::

---

## 22.0 はじめにお読みください

### 22.0.1 この章を読む順番（おすすめ）

1. **22.0.2「自分はどっちのケース？」** で必要な節を判定
2. POSLA 運営側で初期設定が完了しているか確認 → 22.3
3. テナント側で OAuth 接続 → 22.2
4. 店舗マッピング → 22.4
5. 商品インポート → 22.5
6. うまくいかなかったら **22.X FAQ** または **22.Y トラブル表**

### 22.0.2 自分はどっちのケース？

| あなたの状況 | 必要な節 |
|------------|---------|
| スマレジを既に使っている。POSLA も併用したい | 22.2（接続）+ 22.4（店舗マッピング）+ 22.5（商品インポート） |
| スマレジから POSLA に乗り換えたい | 22.5（商品インポート）+ 22.6.2（移行運用） |
| POSLA 運営として初期セットアップをする | 22.3（運営側設定）|
| トークンが期限切れと言われた | 22.Y（トラブル表）+ 22.7.1 |
| マッピングが消えた / 商品が重複した | 22.Y（トラブル表）+ 22.5.4 |

### 22.0.3 必要なもの・所要時間

| # | 項目 | テナント側 | POSLA 運営側 |
|---|------|-----------|-------------|
| 1 | スマレジ契約 | 必須（管理者権限） | — |
| 2 | スマレジ Client ID/Secret | 不要（運営が一括負担） | 必須（開発者コンソール登録） |
| 3 | owner ロール | 必須 | — |
| 4 | 所要時間（接続） | 5 分 | — |
| 5 | 所要時間（マッピング+インポート） | 10〜30 分 | — |
| 6 | 所要時間（運営側初期設定） | — | 30 分（初回のみ） |

### 22.0.4 操作前のチェックリスト

| # | 確認項目 | 確認方法 | NG 時 |
|---|---------|--------|------|
| 1 | あなたが owner ロール | 画面右上 → ロール表示 | manager / staff では不可 |
| 2 | POSLA 側に店舗が 1 つ以上登録 | owner-dashboard「店舗管理」タブ | 先に店舗追加 |
| 3 | スマレジ側に店舗・商品が登録済み | スマレジ管理画面 | スマレジで先に登録 |
| 4 | POSLA 運営が Client ID/Secret を設定済み | エラーで気づく（NOT_CONFIGURED） | 運営に連絡 |
| 5 | ブラウザがポップアップブロックを許可 | ブラウザ設定 | eat.posla.jp を許可 |

---

## 22.1 連携の概要

### 22.1.1 何ができるのか

| 機能 | 方向 | 状態 |
|------|------|------|
| 店舗マッピング（POSLA 店舗 ↔ スマレジ店舗） | 双方向参照 | ✅ 利用可 |
| 商品インポート（スマレジ → POSLA） | スマレジ → POSLA | ✅ 利用可（カテゴリ自動作成 + 自動 EN 翻訳） |
| 商品マッピング（重複検出） | スマレジ → POSLA | ✅ 自動（重複は skipped） |
| 売上書き戻し（POSLA → スマレジ） | POSLA → スマレジ | ⚠ 未対応（`sync-order.php` 仮実装、運用検証中） |
| 価格区分対応（店舗別価格） | スマレジ → POSLA | ⚠ 部分対応（`smaregi_product_mapping.smaregi_price_division_id`） |

### 22.1.2 全体アーキテクチャ

```
┌─────────────┐         OAuth2 (Authorization Code Flow)        ┌─────────────┐
│  テナント    │ ─[1] 接続ボタン → /api/smaregi/auth.php ─→     │   スマレジ   │
│  (owner)     │                                                 │   ID Server  │
│              │ ←─[2] Client 認可画面 ─                         │ id.smaregi   │
│              │                                                 │   .dev       │
│              │ ─[3] code 付き callback ─→ /callback.php       │              │
│              │                                                 │              │
│              │   POSLA Server: code + Client ID/Secret →      │              │
│              │     access_token + refresh_token + contract_id │              │
│              │                                                 │              │
│              │   tenants テーブルに保存                        │              │
│              │     smaregi_access_token, refresh_token,        │              │
│              │     contract_id, token_expires_at               │              │
└─────────────┘                                                 └─────────────┘

[以降の API 呼び出し]
POSLA Server ─[Bearer Token]→ api.smaregi.dev/{contract_id}/pos/products
              ←[商品 JSON]─

[トークン期限]
有効期限 5 分前 → POSLA Server: refresh_token で再取得 → DB 更新
```

### 22.1.3 登場人物・データの流れ

| 役割 | 説明 |
|------|------|
| **POSLA 運営** | スマレジ開発者コンソールで OAuth クライアントを登録。Client ID/Secret を `posla_settings` に保存 |
| **テナント (owner)** | POSLA 画面から OAuth 接続を開始。接続後はトークンが `tenants` 行に保存される |
| **スマレジ ID Server** | `id.smaregi.dev` — 認可エンドポイント・トークンエンドポイント・userinfo |
| **スマレジ API Server** | `api.smaregi.dev/{contract_id}` — POS 機能 API（商品・店舗・取引） |

### 22.1.4 エンドポイント一覧

#### スマレジ側

| エンドポイント | 用途 |
|---|------|
| `https://id.smaregi.dev/authorize` | OAuth 認可画面 |
| `https://id.smaregi.dev/authorize/token` | アクセストークン交換 / リフレッシュ |
| `https://id.smaregi.dev/userinfo` | ユーザー情報 + contract_id 取得 |
| `https://api.smaregi.dev/{contract_id}/pos/stores` | 店舗一覧 |
| `https://api.smaregi.dev/{contract_id}/pos/products` | 商品一覧 |

#### POSLA 側

| エンドポイント | 実装ファイル | 説明 |
|--------------|---|------|
| `GET /api/smaregi/auth.php` | ✅ | OAuth 開始（スマレジへリダイレクト） |
| `GET /api/smaregi/callback.php` | ✅ | OAuth コールバック（認可後、トークン保存） |
| `GET /api/smaregi/status.php` | ✅ | 接続状態取得（`smaregi_access_token` の有無） |
| `GET /api/smaregi/stores.php` | ✅ | スマレジ店舗一覧取得 + POSLA 店舗一覧 + 既存マッピング |
| `GET/POST /api/smaregi/store-mapping.php` | ✅ | POSLA ↔ スマレジ店舗マッピングの取得・保存 |
| `POST /api/smaregi/import-menu.php` | ✅ | スマレジ商品を POSLA メニューとしてインポート |
| `POST /api/smaregi/sync-order.php` | ⚠ | POSLA 注文をスマレジへ同期（運用未対応） |
| `POST /api/smaregi/disconnect.php` | ✅ | 接続解除 |

全エンドポイントは `require_role('owner')` を経由。

---

## 22.2 テナント側の OAuth 接続手順

### 22.2.1 接続前の最終チェック

| # | 確認項目 |
|---|---------|
| 1 | スマレジ管理画面に管理者権限でログインできる |
| 2 | スマレジに対象店舗が登録済み |
| 3 | スマレジに商品マスタが登録済み |
| 4 | POSLA 運営が Client ID/Secret 設定済み（22.3.2） |

### 22.2.2 操作手順（クリック単位 + 画面遷移）

#### ステップ 1：接続タブを開く

1. owner アカウントで `https://eat.posla.jp/public/admin/index.html` にログイン
2. 自動で `owner-dashboard.html` にリダイレクト
3. 上部タブ **「外部 POS 連携」** をクリック

#### この時点での画面表示

```
┌──────────────────────────────────────────┐
│  外部 POS 連携                             │
│                                          │
│  接続状態: ● 未接続                        │
│                                          │
│  [スマレジと接続]                          │
│                                          │
│  接続後にできること:                        │
│   - 店舗マッピング                         │
│   - 商品マスタの一括インポート              │
│   - 商品マッピング（重複検出）              │
└──────────────────────────────────────────┘
```

#### ステップ 2：「スマレジと接続」ボタンをクリック

1. **「スマレジと接続」** ボタンをクリック
2. ブラウザが自動で `https://id.smaregi.dev/authorize?response_type=code&client_id=...&scope=...&state=...` にリダイレクト
3. URL バーが `id.smaregi.dev` ドメインに変わっていることを確認

::: tip CSRF state について
POSLA 側は `bin2hex(random_bytes(16))` で生成した state パラメータをセッションに保存し、コールバック時に `hash_equals()` で検証します。これにより CSRF 攻撃を防ぎます。
:::

#### ステップ 3：スマレジ側で認可

1. スマレジのログイン画面が出たら、契約管理者アカウントでログイン
2. 認可画面で「POSLA がリクエストしているスコープ」が表示される
   - `openid`
   - `offline_access`（refresh_token 取得に必要）
   - `pos.products:read`
   - `pos.transactions:read`
   - `pos.transactions:write`
   - `pos.stores:read`
3. **「許可」** ボタンをクリック

#### ステップ 4：POSLA に戻る

1. スマレジが POSLA の `https://eat.posla.jp/api/smaregi/callback.php?code=xxx&state=yyy` にリダイレクト
2. POSLA サーバー側で以下が自動実行される：
   - state 検証（CSRF 対策）
   - code → access_token / refresh_token 交換
   - userinfo エンドポイントから contract_id 取得
   - `tenants` テーブルに保存
3. ブラウザが `owner-dashboard.html?smaregi=success` にリダイレクト
4. 「接続状態」が **● 接続済み** に変わる

#### 接続後の画面

```
┌──────────────────────────────────────────┐
│  外部 POS 連携                             │
│                                          │
│  接続状態: ● 接続済み                      │
│  契約 ID: xxxxxxxx                         │
│  接続日時: 2026-04-19 14:30                │
│                                          │
│  [店舗マッピング]  [商品インポート]  [接続解除]│
└──────────────────────────────────────────┘
```

### 22.2.3 入力フィールドの詳細（Stripe Checkout 同様、スマレジ認可画面）

スマレジ認可画面の入力は **スマレジ側のログイン情報のみ** で、POSLA からの追加入力はありません。

| # | フィールド | 場所 | 説明 |
|---|----------|------|------|
| 1 | 契約 ID（メールアドレス） | スマレジログイン | スマレジ側の管理者アカウント |
| 2 | パスワード | スマレジログイン | スマレジ側のパスワード |
| 3 | 認可ボタン（許可/拒否） | スマレジ認可画面 | 「許可」を選択 |

### 22.2.4 接続解除（OAuth トークン無効化）

#### 操作手順

1. owner-dashboard > **「外部 POS 連携」** タブ
2. **「接続解除」** ボタンをクリック
3. 確認ダイアログ「スマレジ連携を解除しますか？商品マッピングは維持されますが、再インポートは再接続が必要です」で **「OK」**
4. POSLA サーバー側で `tenants.smaregi_access_token / refresh_token / token_expires_at` を NULL に更新
5. 接続状態が「未接続」に戻る

::: tip 接続解除しても商品データは残る
接続解除すると OAuth トークンは消えますが、`smaregi_store_mapping` および `smaregi_product_mapping` のレコード、および既にインポート済みの `menu_templates` レコードは **すべて維持** されます。再接続すれば差分インポートが可能です。
:::

---

## 22.3 POSLA 運営側の初期設定（運営者向け）

::: tip 通常テナントは 22.3 を読む必要はありません
ここは POSLA 運営（プラスビリーフ）が初回設定時に 1 度だけ行う作業です。
:::

### 22.3.1 スマレジ開発者コンソールでアプリ登録

1. [スマレジ開発者コンソール](https://developers.smaregi.dev/) にログイン
2. 左メニュー **「アプリ管理」** → **「新規アプリ作成」**
3. 以下を入力：

| フィールド | 値 | 補足 |
|---------|------|------|
| アプリ名 | POSLA | テナント側に表示される名称 |
| アプリ種別 | サーバーアプリ | クライアント認証付き |
| リダイレクト URI | `https://eat.posla.jp/api/smaregi/callback.php` | 本番 URL（複数登録時はカンマ区切り） |
| サンドボックス用リダイレクト URI | `https://eat-stage.posla.jp/api/smaregi/callback.php` | ステージング |
| スコープ | 下記参照 | 必要最小限 |

#### スコープ一覧

| スコープ | 用途 |
|---------|------|
| `openid` | OpenID Connect（userinfo 取得） |
| `offline_access` | refresh_token 取得（トークン期限切れ対応に必須） |
| `pos.products:read` | 商品マスタ読取 |
| `pos.stores:read` | 店舗マスタ読取 |
| `pos.transactions:read` | 取引履歴読取（将来：売上連携） |
| `pos.transactions:write` | 取引書込（将来：売上書き戻し） |

4. 作成後、画面に **Client ID** と **Client Secret** が表示される
5. **Secret は再表示できない** ので必ず安全な場所に控える

### 22.3.2 POSLA 管理画面に Client ID/Secret 登録

1. POSLA 管理画面 `/public/posla-admin/dashboard.html` に POSLA 運営アカウントでログイン
2. **「API 設定」** タブを開く
3. 以下を入力して **「保存」**：

| キー名 | 値 | 説明 |
|--------|------|------|
| `smaregi_client_id` | スマレジの Client ID | OAuth クライアント識別子 |
| `smaregi_client_secret` | スマレジの Client Secret | OAuth クライアント秘密鍵 |

これらは `posla_settings` テーブルに保存され、`get_smaregi_config($pdo)` が読み出します。

### 22.3.3 OAuth トークン管理（テナントごと）

POSLA はテナントごとに以下のトークンを `tenants` テーブルに保持します。

| カラム | 型 | 説明 |
|-------|---|------|
| `smaregi_contract_id` | VARCHAR | スマレジの契約 ID |
| `smaregi_access_token` | TEXT | アクセストークン（有効期限 1 時間程度） |
| `smaregi_refresh_token` | TEXT | リフレッシュトークン（有効期限 90 日） |
| `smaregi_token_expires_at` | DATETIME | アクセストークンの有効期限 |
| `smaregi_connected_at` | DATETIME | 接続日時 |

### 22.3.4 トークンリフレッシュ仕様

`api/lib/smaregi-client.php` の `refresh_token_if_needed()` が以下のロジックで自動リフレッシュ：

```
1. token_expires_at - 現在時刻 > 5 分 → リフレッシュ不要、現トークンを返す
2. それ以外（5 分以内 or 過去）→ refresh_token で再取得
   ├─ refresh_token がない → エラー（再接続要）
   ├─ Client ID/Secret 未設定 → エラー
   └─ 成功 → DB 更新（access_token, refresh_token, expires_at）
```

`smaregi_api_request()` が呼ばれるたびに先頭で `refresh_token_if_needed()` が走るため、API 呼び出し側はトークン管理を意識しなくて良い。

::: warning refresh_token も有効期限あり（90 日）
`offline_access` スコープで取得した refresh_token は有効期限約 90 日。長期未利用テナントは再接続が必要になるため、定期的なジョブで監視推奨。
:::

### 22.3.5 マッピングテーブル

#### `smaregi_store_mapping`

| カラム | 型 | 説明 |
|-------|---|------|
| `id` | UUID | 主キー |
| `tenant_id` | VARCHAR | テナント ID |
| `store_id` | VARCHAR | POSLA 店舗 ID |
| `smaregi_store_id` | VARCHAR | スマレジ店舗 ID |
| `sync_enabled` | TINYINT | 同期有効フラグ |
| `last_menu_sync` | DATETIME | 最後にメニューインポートした時刻 |
| `created_at` / `updated_at` | DATETIME | タイムスタンプ |

#### `smaregi_product_mapping`

| カラム | 型 | 説明 |
|-------|---|------|
| `id` | UUID | 主キー |
| `tenant_id` | VARCHAR | テナント ID |
| `menu_template_id` | VARCHAR | POSLA メニュー ID（`menu_templates.id`） |
| `smaregi_product_id` | VARCHAR | スマレジ商品 ID |
| `smaregi_store_id` | VARCHAR | スマレジ店舗 ID |
| `smaregi_price_division_id` | VARCHAR | 価格区分 ID（店舗別価格、optional） |
| `created_at` | DATETIME | 作成日時 |

::: tip 重複防止
`smaregi_product_id` でユニーク（テナント内）。同じスマレジ商品を 2 回インポートしても 2 行は作られず、`skipped` カウントが増えるだけ。
:::

---

## 22.4 店舗マッピング

### 22.4.1 操作前チェックリスト

| # | 確認項目 |
|---|---------|
| 1 | OAuth 接続済み（22.2 完了） |
| 2 | POSLA 側に店舗が 1 つ以上登録済み |
| 3 | スマレジ側に店舗が 1 つ以上登録済み |

### 22.4.2 操作手順

1. owner-dashboard > **「外部 POS 連携」** タブ
2. **「店舗マッピング」** ボタンをクリック
3. POSLA サーバー側で `GET /api/smaregi/stores.php` が呼ばれ：
   - スマレジ API: `GET /pos/stores` で店舗一覧取得
   - POSLA DB: 自店舗一覧 + 既存マッピング取得
4. マッピング画面が表示される

### 22.4.3 マッピング画面 ASCII

```
┌──────────────────────────────────────────────┐
│  店舗マッピング                                 │
│                                              │
│  POSLA 店舗      →  スマレジ店舗               │
│  ─────────────────────────────────────────   │
│  本店               [選択 ▼]                   │
│                       本店 (smaregi_id=1)      │
│                       池袋店 (smaregi_id=2)    │
│                                              │
│  支店 A             [本店 (smaregi_id=1) ▼]   │
│                                              │
│  支店 B             [選択 ▼]                   │
│                                              │
│  [キャンセル]  [マッピングを保存]                │
└──────────────────────────────────────────────┘
```

### 22.4.4 マッピング保存の処理

`POST /api/smaregi/store-mapping.php` は **delete-and-reinsert 方式**：

1. `BEGIN TRANSACTION`
2. `DELETE FROM smaregi_store_mapping WHERE tenant_id = ?`
3. 入力された各マッピングを INSERT（テナント所属検証付き）
4. `COMMIT`

::: warning マッピング変更で商品マッピングはどうなる？
店舗マッピング自体は delete-and-reinsert ですが、**`smaregi_product_mapping` は維持** されます。再インポート時に古いマッピングが影響して重複検出される可能性があるので、店舗を切り替えた際は商品マッピングも見直してください。
:::

### 22.4.5 入力例ブロック

```
POSLA 店舗               スマレジ店舗
─────────────────       ───────────────────
桃乃屋 池袋店          → 池袋店 (smaregi_id=10)
桃乃屋 渋谷店          → 渋谷店 (smaregi_id=11)
桃乃屋 新宿店          → 新宿店 (smaregi_id=12)
```

### 22.4.6 マッピング解除

特定店舗のマッピングだけ外したい場合：マッピング画面でドロップダウンを **「（マッピングなし）」** に戻して保存。

---

## 22.5 商品インポート

### 22.5.1 操作前チェックリスト

| # | 確認項目 |
|---|---------|
| 1 | OAuth 接続済み |
| 2 | 店舗マッピング完了 |
| 3 | スマレジ側に商品が登録されている（最低 1 件） |
| 4 | POSLA カテゴリの初期準備（インポート時に未登録カテゴリは自動作成される） |

### 22.5.2 操作手順

1. owner-dashboard > **「外部 POS 連携」** タブ
2. **「商品インポート」** ボタンをクリック
3. 「インポートする店舗を選択してください」モーダル
4. POSLA 店舗を選択（その店舗のスマレジマッピング先から商品取得）
5. **「インポート開始」** ボタンをクリック
6. ボタンが「処理中...」に変わる（商品数次第で 5 秒〜数分）
7. 完了後、結果サマリーが表示される

### 22.5.3 結果サマリー画面

```
┌──────────────────────────────────────┐
│  商品インポート完了                       │
│                                      │
│  インポート店舗: 桃乃屋 池袋店             │
│                                      │
│  新規追加:    35 品                       │
│  スキップ:     5 品（既にマッピング済み）   │
│  エラー:       0 品                       │
│                                      │
│  自動翻訳: ✅ 完了 (35件 → EN)            │
│                                      │
│  [閉じる]                              │
└──────────────────────────────────────┘
```

### 22.5.4 インポートの内部処理

`POST /api/smaregi/import-menu.php` の処理フロー：

```
1. POSLA 店舗の所属検証
2. smaregi_store_mapping から smaregi_store_id 取得
3. スマレジ API: GET /pos/products?limit=1000 (最大 1000 件)
4. 既存の smaregi_product_mapping を smaregi_product_id で索引
5. テナントの categories を name で索引
6. 各商品ループ:
   a. 既にマッピング済み → スキップ (skipped++)
   b. 商品名空 → エラー (errors++)
   c. カテゴリ名で検索 or 新規作成
   d. menu_templates に INSERT (name, base_price, category_id)
   e. smaregi_product_mapping に INSERT (重複防止用)
   f. (imported++)
7. smaregi_store_mapping.last_menu_sync を NOW() に更新
8. P1-20: 自動 EN 翻訳トリガー (translate_menu_core)
   - 新規追加が 0 件なら翻訳スキップ
   - エラー時は warning に格下げして処理続行
9. JSON レスポンス: { imported, skipped, errors, auto_translate }
```

### 22.5.5 インポートされる項目

| スマレジ商品データ | POSLA メニュー側 |
|-----------------|---------------|
| `productName` | `menu_templates.name`（必須） |
| `price` | `menu_templates.base_price` |
| `categoryName` | `categories.name`（なければ自動作成。空なら「スマレジインポート」カテゴリ） |
| `productId` | `smaregi_product_mapping.smaregi_product_id`（重複防止キー） |

::: warning インポートされない項目
以下は現状インポートされません（手動で追加してください）：
- 商品画像
- アレルゲン情報
- カロリー
- 多言語名（自動 EN 翻訳のみ走る）
- 在庫数
:::

### 22.5.6 自動 EN 翻訳トリガ（P1-20）

新規インポートされた商品は **自動的に英語名生成** されます。

- 内部実装：`translate_menu_core($pdo, $tenantId, $storeId, ['en'], false)`
- 翻訳エンジン：Gemini API（POSLA 共通キー、`posla_settings.gemini_api_key`）
- 翻訳対象：`menu_templates.name` → `menu_templates.name_en`
- 失敗時は warning ログのみ、インポート結果は成功扱い

::: tip 翻訳が走らないケース
- インポート 0 件（imported == 0 で翻訳スキップ）
- Gemini API キー未設定（POSLA 運営側で設定要）
:::

### 22.5.7 入力例：再インポート

スマレジ側で商品を追加した後、POSLA 側に反映するには：

1. owner-dashboard > 外部 POS 連携 > 商品インポート
2. 同じ店舗を選択
3. 「インポート開始」
4. 既存商品はスキップ、新商品のみ追加される

---

## 22.6 運用パターン

### 22.6.1 既存スマレジユーザーが POSLA を併用するケース

**目的**: スマレジで商品マスタ・売上を管理しつつ、POSLA の予約・KDS・セルフオーダー機能を活用

**運用フロー**:
1. スマレジに商品マスタ登録（既存運用）
2. POSLA に OAuth 接続
3. **【商品インポート】** で全商品を POSLA メニューに同期
4. POSLA 側ではメニュー表示・注文・KDS・予約のみ使用
5. 売上データは将来的に POSLA → スマレジへ同期予定（`sync-order.php`、運用未対応）

**注意点**:
- スマレジ側で商品名・価格を変更しても POSLA には自動反映されない → 定期的に再インポート要
- 商品の **削除** はインポートで反映されない → POSLA 側で手動削除

### 22.6.2 POSLA に切り替えるケース

**目的**: スマレジから POSLA に完全移行

**手順**:
1. スマレジから商品データをインポート
2. POSLA でメニュー編集・店舗別調整
3. テスト運用 1 週間
4. スマレジ契約を解除（POSLA 単独運用）

### 22.6.3 連携を一時停止するケース

`POST /api/smaregi/disconnect.php` で OAuth トークンを無効化。POSLA メニューは保持されるので、再接続時に差分インポートが可能。

---

## 22.7 売上連携（未対応・将来予定）

### 22.7.1 sync-order.php の現状

`api/smaregi/sync-order.php` ファイルは存在しますが、以下の理由により **本番運用は未対応** です：

- スマレジ側の取引投入 API（`POST /pos/transactions`）スコープ確認待ち
- 税込/税別計算の整合性検証中
- 返金（refund）の双方向同期方針が未確定
- レジ締め（日次集計）との整合性

### 22.7.2 将来計画（Phase 2）

| # | 機能 | 想定時期 |
|---|------|---------|
| 1 | POSLA 注文 → スマレジ取引同期 | Phase 2 |
| 2 | スマレジ商品の自動同期（cron / Webhook） | Phase 2 |
| 3 | カテゴリの双方向同期 | Phase 2 |
| 4 | 返金・取消の双方向 | Phase 3 |
| 5 | レジ締めの自動連携 | Phase 3 |

---

## 22.8 動作確認

### 22.8.1 テナント接続テスト

1. テストテナントでスマレジに接続
2. 以下を確認：
   - 接続ボタンを押すとスマレジへリダイレクト
   - スマレジで認証後、POSLA に戻ってくる
   - `tenants.smaregi_access_token` が保存される
   - `tenants.smaregi_contract_id` が保存される
   - 「接続状態」が「接続済み」と表示される

### 22.8.2 トークンリフレッシュテスト

1. `tenants.smaregi_token_expires_at` を手動で 5 分後に更新
2. API を呼び出す（例：店舗一覧取得）
3. 自動的にトークンがリフレッシュされることを確認
4. 新しい `smaregi_token_expires_at` が保存されていることを確認

### 22.8.3 商品インポートテスト

1. スマレジに 10 件程度の商品を登録
2. POSLA で商品インポートを実行
3. POSLA メニューに 10 件追加されていることを確認
4. `smaregi_product_mapping` に 10 行のマッピングが作成されていることを確認
5. 自動 EN 翻訳が走ったことを確認（`menu_templates.name_en` に値が入る）

---

## 22.X よくある質問（FAQ）

### Q1〜Q5: 連携の前提

**Q1. スマレジを使ってないけど POSLA だけで運用できる？**

A. 完全に独立運用可能。スマレジ連携は「既に使っているお客様向け」の機能。

**Q2. スマレジと POSLA を両方使う必要はある？**

A. 不要。POSLA に一本化できればそれが推奨。移行期間中だけ併用。

**Q3. 連携料金は？**

A. POSLA 側は無料。スマレジ側も OAuth 連携は無料（API 呼出回数制限あり）。

**Q4. 連携設定は店舗ごと？テナントごと？**

A. **OAuth 接続はテナント単位**（1 つの contract_id）。店舗マッピングは店舗ごと。

**Q5. POSLA 運営の Client ID/Secret はテナントごとに違う？**

A. 違わない。POSLA 全テナント共通の OAuth クライアントを使う。各テナントは個別に access_token / refresh_token を保持。

### Q6〜Q10: 商品インポート

**Q6. 商品は何件までインポートできる？**

A. 1 リクエストあたり最大 1000 件（`/pos/products?limit=1000`）。超える場合は将来的にページング実装予定。

**Q7. カテゴリも連携？**

A. スマレジのカテゴリ名で POSLA カテゴリを検索 → なければ自動作成（`categories.name` をキーに）。空カテゴリは「スマレジインポート」というデフォルトカテゴリに集約。

**Q8. 価格はスマレジ準拠？**

A. インポート時はスマレジの価格を使う。以降 POSLA で変更してもスマレジには反映されない。

**Q9. インポート後にスマレジで価格変更した**

A. 再度インポート。重複チェックされ、新商品のみ追加されるが既存品の価格は **更新されない**（現状仕様）。価格更新は POSLA 側で手動編集要。

**Q10. 同じ商品を 2 回インポートすると重複する？**

A. しない。`smaregi_product_id` で重複検出されてスキップされる（`skipped` カウント）。

### Q11〜Q15: トークン・接続

**Q11. OAuth 再接続が必要なタイミング**

A. refresh_token 失効時（デフォルト 90 日）。事前通知なし、API エラーで気づく → 再接続。

**Q12. 連携を OFF にしたい**

A. オーナーダッシュボードの「外部 POS 連携」タブで **「接続解除」** をクリック。商品データ・マッピングは維持される。

**Q13. アクセストークンの有効期限はどれくらい？**

A. 1 時間程度（スマレジ側仕様）。POSLA は 5 分前にリフレッシュ。

**Q14. リフレッシュトークンの有効期限は？**

A. 約 90 日（`offline_access` スコープ）。

**Q15. 接続解除したら商品データも消える？**

A. 消えない。`menu_templates` および `smaregi_*_mapping` レコードは維持される。再接続して差分インポート可能。

### Q16〜Q20: マッピング

**Q16. スマレジの店舗が複数、POSLA の店舗も複数。どう紐付ける？**

A. **店舗マッピング** UI で 1 対 1 で紐付け。1 つのスマレジ店舗を複数 POSLA 店舗に紐付けることはできない（DB 上は可能だが運用非推奨）。

**Q17. マッピングを変更したら商品マッピングはどうなる？**

A. `smaregi_product_mapping` は維持される。古いマッピングが残っているので、必要に応じて手動削除。

**Q18. 価格区分（store 別価格）には対応している？**

A. `smaregi_product_mapping.smaregi_price_division_id` カラムは存在するが、現状のインポート処理は価格区分を考慮しない（基本価格のみ取得）。

**Q19. POSLA 店舗を削除したら、対応する smaregi_store_mapping も消える？**

A. 自動削除はされない（DB のカスケード設定なし）。手動で `DELETE FROM smaregi_store_mapping WHERE store_id = ?`。

**Q20. マッピング変更時に既存メニューの再リンクは？**

A. されない。手動で `smaregi_product_mapping` を見直し。

### Q21〜Q25: 売上・将来機能

**Q21. POSLA で会計したら、その売上はスマレジに反映される？**

A. 現状は片方向（スマレジ → POSLA）のみ。POSLA → スマレジ売上同期は Phase 2 機能（`sync-order.php`、未運用）。

**Q22. スマレジの売上は POSLA に入る？**

A. 現状は商品データのみ連携。売上連携は Phase 2 で検討中。

**Q23. スマレジの予約データは？**

A. 連携対象外。POSLA の予約機能（24 章）を別途使う。

**Q24. スマレジの顧客マスタは連携できる？**

A. 現状は対象外。Phase 3 以降で検討。

**Q25. スマレジの在庫管理機能は？**

A. 連携対象外。在庫は POSLA 側で別途管理（POSLA の在庫機能は将来予定）。

### Q26〜Q30: トラブル・運用

**Q26. OAuth 接続時に「アプリが見つかりません」エラー**

A. POSLA 管理画面の `smaregi_client_id` が未設定。POSLA 運営に連絡してください。

**Q27. OAuth コールバックで「トークン取得失敗」エラー**

A. 以下を確認：
- リダイレクト URI が一致（スマレジ開発者コンソール側）
- Client Secret が正しい
- スマレジ側のアプリが有効化されている

**Q28. 商品インポートで「権限がありません」エラー**

A. OAuth スコープが不足。`pos.products:read` がスコープに含まれていない可能性。スマレジ開発者コンソールでスコープ追加 → POSLA 側で再接続。

**Q29. 文字化けする**

A. POSLA 側は UTF-8 固定。スマレジ側で UTF-8 設定確認。

**Q30. インポートがタイムアウトする**

A. 商品数が多い場合（500 件以上）。現状は 30 秒タイムアウト（`CURLOPT_TIMEOUT => 30`）。複数回に分けるか、運営に連絡してタイムアウト延長。

---

## 22.Y トラブルシューティング表

| 症状 | 原因 | 対処 |
|---|---|---|
| OAuth 認証画面で無限ループ | コールバック URL 不一致 | スマレジ開発者コンソールで URL 確認 |
| 「CSRF 検証失敗」エラー | セッションタイムアウト or 別ブラウザ | 同じブラウザ・同じセッションで完了 |
| 「契約 ID が取得できませんでした」 | userinfo エンドポイントの応答異常 | サーバーログ確認、スマレジサポートに連絡 |
| 商品インポート 0 件 | スコープ不足 | OAuth スコープに `pos.products:read` 追加 |
| トークンリフレッシュ失敗 | refresh_token 失効（90 日経過） | 「接続解除」→ 再接続 |
| タイムアウト | 商品数多い | 分割インポート（次バージョン予定） |
| 文字化け | 文字コード不一致 | POSLA 側は UTF-8 固定、スマレジ側で確認 |
| 再認証を要求される | トークン失効 | 「接続解除」 → 「スマレジと接続」 |
| インポートで一部商品スキップ | 商品名空 / 既にマッピング済み | スマレジで商品名設定 / 重複は仕様 |
| マッピング保存で「INVALID_INPUT」 | mappings 配列形式不正 | フロントエンドの実装確認 |
| インポートで価格 0 円商品が登録される | スマレジで 0 円商品 | スマレジで価格設定 |
| Client ID 設定後すぐ使えない | DB キャッシュ | POSLA 管理画面で再保存 |
| 接続後すぐ「未接続」表示 | ブラウザキャッシュ | Cmd+Shift+R で強制再読み込み |
| API レート制限エラー | スマレジ API の RPS 制限 | 数分待って再試行 |
| 自動翻訳がかからない | Gemini API キー未設定 | POSLA 運営に連絡 |

### 22.Y.1 ログ確認用コマンド集（POSLA 運営向け）

```bash
# 直近のスマレジ関連ログ
ssh odah@odah.sakura.ne.jp 'tail -100 ~/log/php_errors.log | grep -i smaregi'

# 接続状態のテナント一覧
mysql -e "
SELECT id, slug, smaregi_contract_id, smaregi_token_expires_at,
       smaregi_connected_at
FROM tenants
WHERE smaregi_contract_id IS NOT NULL
ORDER BY smaregi_connected_at DESC;
"

# テナントごとの商品マッピング件数
mysql -e "
SELECT tenant_id, COUNT(*) AS mapping_count
FROM smaregi_product_mapping
GROUP BY tenant_id;
"

# 期限切れ間近のトークン
mysql -e "
SELECT id, slug, smaregi_token_expires_at,
       TIMESTAMPDIFF(MINUTE, NOW(), smaregi_token_expires_at) AS minutes_remaining
FROM tenants
WHERE smaregi_token_expires_at IS NOT NULL
  AND smaregi_token_expires_at < DATE_ADD(NOW(), INTERVAL 1 DAY)
ORDER BY smaregi_token_expires_at;
"
```

---

## 22.Z データベース構造（運営向け）

::: tip 通常テナントは読まなくて OK

### 主要テーブル

| テーブル | 説明 |
|---------|------|
| `tenants.smaregi_contract_id` | スマレジ契約 ID |
| `tenants.smaregi_access_token` | OAuth アクセストークン |
| `tenants.smaregi_refresh_token` | リフレッシュトークン |
| `tenants.smaregi_token_expires_at` | アクセストークン有効期限 |
| `tenants.smaregi_connected_at` | 接続日時 |
| `smaregi_store_mapping` | POSLA 店舗 ↔ スマレジ店舗の紐付け |
| `smaregi_product_mapping` | POSLA メニュー ↔ スマレジ商品 |
| `posla_settings.smaregi_client_id` | POSLA 共通の Client ID |
| `posla_settings.smaregi_client_secret` | POSLA 共通の Client Secret |
| `menu_templates` | インポート先（既存テーブル、04 章） |
| `categories` | カテゴリ（自動作成あり、04 章） |

### サーバー側ライブラリ

| ファイル | 関数 | 説明 |
|---------|------|------|
| `api/lib/smaregi-client.php` | `get_smaregi_config()` | Client ID/Secret 取得 |
| | `get_tenant_smaregi()` | テナント認証情報取得 |
| | `refresh_token_if_needed()` | トークン自動リフレッシュ |
| | `smaregi_api_request()` | API 呼出ラッパー（リフレッシュ込み） |

### フロントエンド

- `public/admin/js/smaregi-integration.js` — owner-dashboard の「外部 POS 連携」タブ全体

### 設計ドキュメント

- `docs/L15-smaregi-design.md` — 設計詳細
- `docs/SYSTEM_SPECIFICATION.md` — Smaregi セクション
- スマレジ公式 API ドキュメント: <https://www1.smaregi.dev/>

### セキュリティ

- access_token / refresh_token は DB に **平文保存**（運用上の注意点：DB アクセス権限の最小化）
- API キー（Client Secret）は `posla_settings` に保存（`posla-admin` のみアクセス可）
- 接続解除時に NULL 上書き
- 既知課題（2026-04-18 セキュリティ監査）：error_log に access_token 等が含まれる場合あり → 改善検討中

:::

---

## 関連章

- [4. メニュー管理](./04-menu.md) — インポート先メニューの編集
- [15. オーナーダッシュボード](./15-owner.md) — 外部 POS 連携タブの場所
- [21. Stripe 決済の全設定](./21-stripe-full.md) — 決済連携（同じく外部サービス）

---

## 更新履歴

- **2026-04-19**: フル粒度化（Phase B Batch-11）。OAuth フロー全体図・運営側初期設定・トークンリフレッシュ仕様・自動 EN 翻訳トリガ・FAQ 30 件・トラブル表 15 件・ログ確認コマンド集 追加
- **2026-04-18**: フル粒度化、FAQ + トラブル + 技術補足 追加
- **以前**: 基本ガイド (205 行)
