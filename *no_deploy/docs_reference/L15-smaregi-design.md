# L-15 スマレジ連携 設計書

## 概要

POSLAのセルフオーダー + KDS と、既存のスマレジPOSレジを併用するための連携機能。
将来的にスマレジからPOSLAへの完全移行も想定した設計。

---

## 1. DB設計

### 1-1. テナントテーブルへのカラム追加

```sql
-- migration-l15-smaregi.sql

ALTER TABLE tenants
  ADD COLUMN smaregi_contract_id VARCHAR(50) DEFAULT NULL
    COMMENT 'スマレジ契約ID' AFTER connect_onboarding_complete,
  ADD COLUMN smaregi_client_id VARCHAR(100) DEFAULT NULL
    COMMENT 'スマレジアプリ Client ID' AFTER smaregi_contract_id,
  ADD COLUMN smaregi_client_secret VARCHAR(200) DEFAULT NULL
    COMMENT 'スマレジアプリ Client Secret' AFTER smaregi_client_id,
  ADD COLUMN smaregi_access_token TEXT DEFAULT NULL
    COMMENT 'スマレジ アクセストークン（暗号化推奨）' AFTER smaregi_client_secret,
  ADD COLUMN smaregi_refresh_token TEXT DEFAULT NULL
    COMMENT 'スマレジ リフレッシュトークン' AFTER smaregi_access_token,
  ADD COLUMN smaregi_token_expires_at DATETIME DEFAULT NULL
    COMMENT 'アクセストークン有効期限' AFTER smaregi_refresh_token,
  ADD COLUMN smaregi_connected_at DATETIME DEFAULT NULL
    COMMENT 'スマレジ連携日時' AFTER smaregi_token_expires_at;
```

> **設計判断**: スマレジの認証は「契約ID」単位（= オーナーアカウント単位）。
> 1テナント = 1スマレジ契約 なので、テナントテーブルに保持する。
> アプリアクセストークンは有効期限3600秒のため、refresh_tokenで都度更新する。

### 1-2. 店舗マッピングテーブル（新規）

```sql
CREATE TABLE smaregi_store_mapping (
    id              VARCHAR(36) PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    store_id        VARCHAR(36) NOT NULL       COMMENT 'POSLA店舗ID',
    smaregi_store_id VARCHAR(20) NOT NULL      COMMENT 'スマレジ店舗ID（数値文字列）',
    sync_enabled    TINYINT(1) NOT NULL DEFAULT 1 COMMENT '注文送信を有効にするか',
    last_menu_sync  DATETIME DEFAULT NULL      COMMENT '最後のメニュー同期日時',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_smaregi_map_store (store_id),
    UNIQUE INDEX idx_smaregi_map_ext (tenant_id, smaregi_store_id),
    INDEX idx_smaregi_map_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> **設計判断**: POSLA店舗 ↔ スマレジ店舗は1:1。
> UNIQUE(store_id) で1つのPOSLA店舗に2つのスマレジ店舗が紐づくことを防止。
> sync_enabled で店舗単位で注文送信のON/OFFが可能。

### 1-3. メニューマッピングテーブル（新規）

```sql
CREATE TABLE smaregi_product_mapping (
    id                  VARCHAR(36) PRIMARY KEY,
    tenant_id           VARCHAR(36) NOT NULL,
    menu_template_id    VARCHAR(36) NOT NULL   COMMENT 'POSLAメニューテンプレートID',
    smaregi_product_id  VARCHAR(20) NOT NULL   COMMENT 'スマレジ商品ID',
    smaregi_store_id    VARCHAR(20) NOT NULL   COMMENT 'スマレジ店舗ID',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_smaregi_prod_menu (menu_template_id),
    UNIQUE INDEX idx_smaregi_prod_ext (tenant_id, smaregi_product_id, smaregi_store_id),
    INDEX idx_smaregi_prod_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE CASCADE,
    FOREIGN KEY (menu_template_id) REFERENCES menu_templates(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> **設計判断**: インポート時にPOSLAメニューとスマレジ商品IDの対応を記録。
> 注文送信時にPOSLAの注文アイテム → スマレジ商品IDに変換するために必要。
> スマレジ連携を切った場合、このテーブルは無視されるだけ（POSLAメニューは自立）。

### 1-4. ordersテーブルへのカラム追加

```sql
ALTER TABLE orders
  ADD COLUMN smaregi_transaction_id VARCHAR(50) DEFAULT NULL
    COMMENT 'スマレジに送信した仮販売の取引ID' AFTER session_token;
```

> **設計判断**: 注文がスマレジに送信済みかどうかを追跡。
> NULLならスマレジ未送信（非連携店舗 or 送信失敗）。

---

## 2. API設計

### 2-1. OAuth認証フロー

```
[オーナー] → owner-dashboard.html「スマレジ連携」ボタン
    ↓
[POSLA] → api/smaregi/auth.php（GET）
    → スマレジ認可エンドポイントにリダイレクト
    → https://id.smaregi.dev/authorize?
        response_type=code&
        client_id={POSLA_APP_CLIENT_ID}&
        scope=pos.products:read+pos.transactions:read+pos.transactions:write+pos.stores:read&
        redirect_uri={POSLA_CALLBACK_URL}&
        state={csrf_token}
    ↓
[スマレジ] → ユーザーがログイン＋許可
    ↓
[スマレジ] → api/smaregi/callback.php にリダイレクト（code付き）
    ↓
[POSLA] → code → アクセストークン取得
    → tenants テーブルに保存
    → owner-dashboard.html?smaregi=success にリダイレクト
```

### 2-2. 新規APIエンドポイント一覧

| ファイル | HTTP | Auth | 概要 |
|----------|------|------|------|
| `api/smaregi/auth.php` | GET | owner | OAuth認可開始（スマレジにリダイレクト） |
| `api/smaregi/callback.php` | GET | — | OAuthコールバック（code→token交換） |
| `api/smaregi/status.php` | GET | owner | 連携状態確認（接続済み/未接続/トークン期限切れ） |
| `api/smaregi/disconnect.php` | POST | owner | 連携解除（トークン削除） |
| `api/smaregi/stores.php` | GET | owner | スマレジ店舗一覧取得（マッピングUI用） |
| `api/smaregi/store-mapping.php` | GET/POST | owner | 店舗マッピングのCRUD |
| `api/smaregi/import-menu.php` | POST | owner | スマレジ商品 → POSLAメニューテンプレート変換・登録 |
| `api/smaregi/sync-order.php` | POST | internal | POSLA注文 → スマレジ仮販売送信（内部呼出し） |

### 2-3. コアライブラリ

| ファイル | 概要 |
|----------|------|
| `api/lib/smaregi-client.php` | スマレジAPI共通クライアント（トークン管理・自動リフレッシュ・リクエスト送信） |

**smaregi-client.phpの主要関数:**

```
get_smaregi_client($tenant_id)     — テナントの認証情報を取得
refresh_token_if_needed($tenant)   — 有効期限チェック → 必要なら自動更新
smaregi_api_get($tenant, $path)    — GET リクエスト
smaregi_api_post($tenant, $path, $body) — POST リクエスト
```

### 2-4. 必要なスコープ

| スコープ | 用途 |
|----------|------|
| `pos.products:read` | 商品マスタ取得（メニューインポート） |
| `pos.transactions:read` | 取引照会（将来の売上同期用、初期は未使用） |
| `pos.transactions:write` | 仮販売登録（注文送信） |
| `pos.stores:read` | 店舗一覧取得（マッピングUI用） |

---

## 3. UI設計（owner-dashboard.html）

### 3-1. 新タブ「外部POS連携」

owner-app.js に新タブを追加。決済設定タブの隣。

```
[店舗管理] [ユーザー管理] [メニュー] ... [決済設定] [外部POS連携] [契約・プラン]
```

### 3-2. タブ内のセクション構成

**セクション1：スマレジ接続状態**
```
┌─────────────────────────────────────────┐
│  スマレジ連携                            │
│                                          │
│  状態: ● 接続済み（契約ID: abc123）       │
│  接続日: 2026-04-05                      │
│                                          │
│  [連携を解除する]                         │
│                                          │
│  ---- or (未接続時) ----                  │
│                                          │
│  状態: ○ 未接続                          │
│                                          │
│  [スマレジと連携する]                     │
│  ↑ クリック → OAuth認証画面へ             │
└─────────────────────────────────────────┘
```

**セクション2：店舗マッピング** （接続済みの場合のみ表示）
```
┌─────────────────────────────────────────┐
│  店舗マッピング                          │
│                                          │
│  POSLA店舗        スマレジ店舗    注文送信│
│  ─────────────────────────────────────── │
│  渋谷店           [▼ 渋谷店 ]    [✓]    │
│  新宿店           [▼ 新宿店 ]    [✓]    │
│  池袋店           [▼ 未設定 ]    [ ]    │
│                                          │
│  [保存]                                  │
└─────────────────────────────────────────┘
```

**セクション3：メニューインポート** （マッピング済み店舗がある場合のみ表示）
```
┌─────────────────────────────────────────┐
│  メニューインポート                       │
│                                          │
│  スマレジの商品マスタをPOSLAのメニュー    │
│  テンプレートとして取り込みます。          │
│  ※ 既存のPOSLAメニューは上書きされません  │
│                                          │
│  対象店舗: [▼ 渋谷店 ]                   │
│                                          │
│  [インポート開始]                         │
│                                          │
│  最終インポート: 2026-04-05 14:30         │
└─────────────────────────────────────────┘
```

---

## 4. 注文フロー設計

### 4-1. 通常の注文フロー（スマレジ連携あり）

```
[顧客] セルフオーダーで注文
    ↓
[POSLA] api/customer/orders.php — 注文をDBに保存（従来通り）
    ↓
[POSLA] KDSに注文表示 → 調理開始（従来通り）
    ↓
[POSLA] 注文保存後、非同期でスマレジに送信
    api/smaregi/sync-order.php を内部呼出し
    ↓
[POSLA → スマレジ]
    POST /{contract_id}/pos/transactions
    {
      "storeId": "{smaregi_store_id}",
      "terminalId": 1,
      "transactionHeadDivision": "2",  ← 仮販売
      "details": [
        {
          "productId": "{smaregi_product_id}",  ← マッピングテーブルで変換
          "salesPrice": 980,
          "quantity": 2
        },
        ...
      ]
    }
    ↓
[スマレジ] 仮販売として登録
    → スタッフがスマレジPOSで仮販売を呼び出して正式会計
```

### 4-2. 送信失敗時の動作

- スマレジへの送信が失敗しても、POSLAの注文は正常に保存される（KDSに表示される）
- orders.smaregi_transaction_id が NULL のまま残る
- オーナー画面で「スマレジ未送信の注文」を確認・再送信できるUI（将来）

### 4-3. 連携OFF時の動作

- smaregi_store_mapping.sync_enabled = 0 の店舗は送信をスキップ
- テナントの smaregi_contract_id が NULL の場合も全スキップ
- **POSLAの注文フローは一切影響を受けない**（これが最重要設計原則）

### 4-4. スマレジ連携を切った場合（乗り換え）

- オーナーが「連携を解除する」を押す
- tenants の smaregi_* カラムをNULLに
- smaregi_store_mapping の sync_enabled を全0に
- **POSLAのメニューテンプレート、カテゴリ、注文履歴はそのまま残る**
- POSLAのキャッシャーで会計するだけ

---

## 5. メニューインポート設計

### 5-1. スマレジ商品 → POSLAメニューへの変換ルール

| スマレジ | → | POSLA |
|----------|---|-------|
| 部門（categoryId） | → | categories（名前で既存チェック、なければ新規作成） |
| 商品名（productName） | → | menu_templates.name |
| 販売価格（price） | → | menu_templates.base_price |
| 商品コード（productCode） | → | smaregi_product_mapping.smaregi_product_id |
| 画像 | → | ※ スマレジAPIに商品画像エンドポイントがあれば取得、なければNULL |

### 5-2. インポートの動作

1. スマレジAPI GET `/{contract_id}/pos/products` で商品一覧を取得
2. 各商品について：
   - smaregi_product_mapping に既存エントリがあればスキップ（重複インポート防止）
   - カテゴリが未作成なら新規作成
   - menu_templates に INSERT
   - smaregi_product_mapping に紐付け記録
3. インポート結果をレスポンスで返す（追加件数、スキップ件数、エラー件数）

### 5-3. 二重インポート防止

- smaregi_product_mapping で既にマッピング済みの商品はスキップ
- オーナーが「再インポート」したい場合は、マッピングを削除してから再実行
- 既存のPOSLAメニューは一切削除しない（追加のみ）

---

## 6. ファイル一覧（新規・変更）

### 新規ファイル
| ファイル | 概要 |
|----------|------|
| `sql/migration-l15-smaregi.sql` | DB変更（テナントカラム追加 + 2テーブル新規 + ordersカラム追加） |
| `api/lib/smaregi-client.php` | スマレジAPIクライアント（トークン管理・リクエスト共通処理） |
| `api/smaregi/auth.php` | OAuth認可開始 |
| `api/smaregi/callback.php` | OAuthコールバック |
| `api/smaregi/status.php` | 連携状態確認 |
| `api/smaregi/disconnect.php` | 連携解除 |
| `api/smaregi/stores.php` | スマレジ店舗一覧取得 |
| `api/smaregi/store-mapping.php` | 店舗マッピングCRUD |
| `api/smaregi/import-menu.php` | メニューインポート |
| `api/smaregi/sync-order.php` | 注文 → スマレジ仮販売送信 |

### 変更ファイル
| ファイル | 変更内容 |
|----------|----------|
| `api/customer/orders.php` | 注文保存後にスマレジ送信を呼び出す（非同期） |
| `public/admin/owner-dashboard.html` | 「外部POS連携」タブ追加 |
| `public/admin/js/owner-app.js` | タブ制御 + スマレジ連携UI |

### 既存への影響
- **orders.php**: 末尾に5行程度追加（smaregi sync呼出し）。既存フローに影響なし
- **owner-dashboard.html**: タブ1つ追加。既存タブに影響なし
- **owner-app.js**: セクション追加。既存機能に影響なし

---

## 7. POSLAアプリマーケット登録

スマレジ・デベロッパーズでPOSLAをアプリとして登録する必要あり。

### 必要な手続き
1. https://developers.smaregi.jp/ でデベロッパーアカウント作成
2. アプリ新規登録（パブリックアプリ）
3. Client ID / Client Secret を取得
4. Callback URL を登録
5. サンドボックス環境でテスト
6. 審査 → アプリマーケット公開

### POSLA側の設定
- Client ID / Client Secret は `posla_settings` テーブルで管理
  - key: `smaregi_client_id`, `smaregi_client_secret`
- POSLA管理画面（posla-admin）のAPI設定タブに追加
