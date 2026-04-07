# 5. システム運用

POSLAシステムの運用に関する技術的な情報です。

## 5.1 技術スタック

| 技術 | バージョン/仕様 |
|------|--------------|
| サーバーサイド | PHP（フレームワークなし） |
| フロントエンド | Vanilla JavaScript（ES5、IIFE パターン） |
| データベース | MySQL 5.7 |
| ビルドツール | なし（生PHPファイルをそのままデプロイ） |
| セッション管理 | PHPセッション（ファイルベース） |

### コーディング規約

- **ES5厳守：** `const`/`let`/アロー関数/`async-await`は使用しない。`var`＋コールバック＋IIFEパターン
- **SQLインジェクション対策：** 全SQLはプリペアドステートメント（例外なし）
- **XSS対策：** ユーザー入力は`Utils.escapeHtml()`を通して`innerHTML`に挿入
- **マルチテナント：** 全クエリに`tenant_id`/`store_id`の絞り込みを含める
- **レスポンス形式：** `api/lib/response.php`の統一形式（`ok/data/error/serverTime`）

---

## 5.2 データベース

### マイグレーション管理

- スキーマ定義：`sql/schema.sql`（初期スキーマ、編集禁止）
- マイグレーション：`sql/migration-*.sql`ファイルで管理
- 新しいカラム・テーブルが必要な場合は新規マイグレーションファイルを作成
- 既存テーブルの`ALTER TABLE`も新規マイグレーションに記述

### 主要マイグレーションファイル

| ファイル | 内容 |
|---------|------|
| migration-l10-posla-admin.sql | POSLA管理者テーブル |
| migration-l10b-posla-settings.sql | POSLA設定テーブル |
| migration-l11-subscription.sql | サブスクリプション関連 |
| migration-l13-stripe-connect.sql | Stripe Connect関連 |
| migration-l15-smaregi.sql | スマレジ連携関連 |
| migration-l3p2-ai-shift.sql | シフト管理Phase 2 |
| migration-l3p3-multi-store-shift.sql | シフト管理Phase 3 |
| migration-b-phase-b.sql | テーブルセッション等 |

---

## 5.3 APIキー管理

### POSLA共通キー

Gemini API/Google Places APIのキーはPOSLA運営が一括負担します。`posla_settings`テーブルで一元管理されています。

**取得API：** `GET /api/store/settings.php?include_ai_key=1`（テナント認証必須）

サーバーサイドでのみ使用するキー（Google Places等）は、プロキシ経由で呼び出します。

**Places APIプロキシ：** `api/store/places-proxy.php`（CORS回避＋キー非露出）

### テナント個別キー

決済キー（Square/Stripe Connect）のみテナント個別です。`owner-dashboard.html`の「決済設定」タブで管理します。

---

## 5.4 デプロイ

### デプロイ対象

POSLAはビルドプロセスがないため、PHPファイルとJSファイルをサーバーに直接配置します。

**デプロイ手順：**
1. 変更ファイルをサーバーにアップロード
2. 新しいマイグレーションファイルがある場合、SQLを実行
3. 動作確認

### ディレクトリ構成

```
/public/
  admin/           # テナント管理ダッシュボード
    js/             # 管理画面のJSモジュール
  posla-admin/      # POSLA運営管理画面
    js/             # POSLA管理画面のJS
  customer/         # お客さん向け画面
    js/             # カスタマー画面のJS
  handy/            # ハンディPOS
    js/             # ハンディのJS
  kds/              # KDS＋レジ
    js/             # KDS/レジのJS
  docs/             # VitePressドキュメント（静的ビルド）
/api/
  auth/             # 認証API
  store/            # 店舗API
  customer/         # カスタマーAPI
  kds/              # KDS API
  owner/            # オーナーAPI
  posla/            # POSLA管理API
  subscription/     # サブスクリプションAPI
  connect/          # Stripe Connect API
  smaregi/          # スマレジ連携API
  lib/              # 共通ライブラリ
/sql/
  schema.sql        # 初期スキーマ
  migration-*.sql   # マイグレーションファイル
```

---

## 5.5 セッション管理

### テナントユーザーのセッション

| 設定 | 値 |
|------|-----|
| 有効時間 | 8時間 |
| 無操作タイムアウト | 30分（25分で警告） |
| セッション再生成 | ログイン成功時 |
| マルチデバイス | 同時ログイン許可（警告表示） |

### POSLA管理者のセッション

| 設定 | 値 |
|------|-----|
| 有効時間 | 8時間 |
| Cookie属性 | HttpOnly、SameSite=Lax |
| セッション再生成 | ログイン成功時 |

---

## 5.6 スマレジ連携

### 設定

POSLA管理画面でスマレジのOAuthクライアント情報を設定します。

### OAuthトークン管理

- テナントごとにアクセストークンとリフレッシュトークンを保持
- トークンの有効期限を追跡
- 有効期限の5分前に自動リフレッシュ
- トークン失効時はグレースフルに対応

### テナントのスマレジ関連フィールド

| フィールド | 説明 |
|-----------|------|
| smaregi_contract_id | スマレジの契約ID |
| smaregi_access_token | アクセストークン |
| smaregi_refresh_token | リフレッシュトークン |
| smaregi_token_expires_at | トークン有効期限 |
| smaregi_connected_at | 接続日時 |

### マッピングテーブル

| テーブル | 説明 |
|---------|------|
| smaregi_store_mapping | POSLA店舗 ↔ スマレジ店舗の紐付け |
| smaregi_product_mapping | POSLAメニュー ↔ スマレジ商品の紐付け |

---

## 5.7 ポーリング間隔一覧

各画面のポーリング（自動更新）間隔のまとめです。

| 画面 | ポーリング対象 | 間隔 |
|------|-------------|------|
| KDS | 注文データ | 3秒 |
| KDS（オフライン時） | 注文データ | 30秒 |
| KDS | スタッフ呼び出しアラート | 5秒 |
| KDS | AIキッチンダッシュボード | 60秒 |
| フロアマップ | テーブルステータス | 5秒 |
| ハンディPOS | スタッフ呼び出しアラート | 5秒 |
| ハンディPOS | メニューバージョンチェック | 3分 |
| セルフオーダー | 注文ステータス追跡 | 5秒 |
| セルフオーダー | スタッフ呼び出し応答チェック | 5秒 |
| テイクアウト管理 | 注文一覧 | 30秒 |
| POSレジ | 注文・テーブルデータ | 3秒 |
| POSレジ | 売上サマリー | 5秒 |
