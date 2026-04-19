---
title: システム運用
chapter: 05
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [operations, 運営, POSLA, sakura, ssh, cron, deploy, mysql, backup]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 5. システム運用

POSLAシステムの運用に関する技術的な情報です。本章は **POSLA 運営（プラスビリーフ社）の運用担当者** が日常的に参照する一次資料です。Sakura レンタルサーバー上での FreeBSD / cshell / mysql80 環境を前提に、SSH デプロイ・バックアップ・cron 設定・障害対応のすべてのコマンドを掲載しています。

::: tip この章を読む順番（おすすめ）
1. まず **5.1 技術スタック** で全体像を把握
2. **5.2 サーバー構成** で接続情報・ディレクトリ・パーミッションを確認
3. デプロイをやる人は **5.4 デプロイ手順** + **5.5 バックアップ運用**
4. DB を触る人は **5.6 マイグレーション** + **5.7 mysql コマンドライン**
5. cron を設定する人は **5.8 cron 設定**
6. 障害調査は **5.9 ログファイル** + **5.10 パーミッション**
7. SSH 鍵を扱う人は **5.11 SSH 鍵管理**
8. よくある質問は **5.20 FAQ 30 件**
:::

## 5.1 技術スタック

| 技術 | バージョン/仕様 |
|------|--------------|
| サーバーサイド | PHP（フレームワークなし） |
| フロントエンド | Vanilla JavaScript（ES5、IIFE パターン） |
| データベース | MySQL 5.7（Sakura mysql80 サーバー、実態は 8.0 系互換だがアプリは 5.7 文法で書く） |
| ビルドツール | なし（生PHPファイルをそのままデプロイ） |
| セッション管理 | PHPセッション（ファイルベース） |
| キャッシュ | OPcache（PHP 標準） |
| HTTPS | Sakura 標準の Let's Encrypt 自動更新 |

### コーディング規約

- **ES5厳守：** `const`/`let`/アロー関数/`async-await`は使用しない。`var`＋コールバック＋IIFEパターン
- **SQLインジェクション対策：** 全SQLはプリペアドステートメント（例外なし）
- **XSS対策：** ユーザー入力は`Utils.escapeHtml()`を通して`innerHTML`に挿入
- **マルチテナント：** 全クエリに`tenant_id`/`store_id`の絞り込みを含める
- **レスポンス形式：** `api/lib/response.php`の統一形式（`ok/data/error/serverTime`）

---

## 5.2 サーバー構成（Sakura レンタルサーバー）

### 5.2.1 接続情報

| 項目 | 値 |
|------|---|
| ホスト名 | `odah.sakura.ne.jp` |
| SSH ユーザー | `odah` |
| SSH ポート | 22 |
| シェル | csh（FreeBSD 標準。`%` プロンプト） |
| OS | FreeBSD（バージョンは Sakura 側で管理） |
| 公開ドキュメントルート | `/home/odah/www/eat-posla/` |
| 本番 URL | `https://eat.posla.jp` |
| Document Root の Web マッピング | `/home/odah/www/` → `https://odah.sakura.ne.jp/`、`/home/odah/www/eat-posla/` → `https://eat.posla.jp/` |
| PHP バージョン | サーバー側設定に従う（コントロールパネル参照） |
| DB ホスト | `mysql80.odah.sakura.ne.jp` |
| DB 名 | `odah_eat-posla` |
| DB ユーザー | `odah_eat-posla` |
| DB パスワード | `odah_eat-posla`（運用ノートに記載） |
| 文字コード | utf8mb4 |

::: warning csh 制約
Sakura のシェルは csh です。**`2>&1` はそのまま動かない**ため、リダイレクトする場合は `bash -c "..."` でラップしてください。例: `ssh odah@odah.sakura.ne.jp 'bash -c "php /home/odah/www/eat-posla/api/cron/monitor-health.php >> /home/odah/log/cron.log 2>&1"'`
:::

### 5.2.2 ディレクトリ構成（本番）

```
/home/odah/
├── www/eat-posla/                  ← ドキュメントルート（HTTPS 公開対象）
│   ├── api/                        ← サーバー API
│   │   ├── auth/                   ← 認証 API（device-register, login, logout 等）
│   │   ├── store/                  ← 店舗運用 API
│   │   ├── customer/               ← お客さん向け API
│   │   ├── kds/                    ← KDS API
│   │   ├── owner/                  ← オーナー API
│   │   ├── posla/                  ← POSLA 管理 API
│   │   ├── subscription/           ← Stripe Billing
│   │   ├── connect/                ← Stripe Connect
│   │   ├── smaregi/                ← スマレジ連携
│   │   ├── monitor/                ← ヘルスチェック (ping.php)
│   │   ├── cron/                   ← cron スクリプト 4 本
│   │   ├── lib/                    ← 共通ライブラリ
│   │   └── config/                 ← database.php（DB接続設定）
│   ├── public/                     ← 静的 HTML/CSS/JS
│   │   ├── admin/                  ← テナント管理ダッシュボード
│   │   ├── posla-admin/            ← POSLA 運営管理画面
│   │   ├── customer/               ← セルフメニュー
│   │   ├── handy/                  ← ハンディ POS
│   │   ├── kds/                    ← KDS + レジ
│   │   ├── docs-tenant/            ← VitePress 公開マニュアル
│   │   └── docs-internal/          ← VitePress 内部マニュアル（Basic 認証）
│   ├── sql/                        ← マイグレーション SQL（参照用、本番では実行のみ）
│   └── _backup_YYYYMMDD/           ← デプロイ前バックアップ（運用ルール）
├── log/                            ← Apache/PHP のログ
│   ├── php_errors.log              ← PHP エラーログ（最重要）
│   ├── cron.log                    ← cron 実行ログ（手動 redirect で記録）
│   └── access.log                  ← Sakura 提供のアクセスログ
└── .ssh/                           ← サーバー側 authorized_keys
```

### 5.2.3 ローカル開発環境

| 項目 | 値 |
|------|---|
| プロジェクトルート | `/Users/odahiroki/Desktop/matsunoya-mt/` |
| SSH 鍵ファイル | `id_ecdsa.pem`（プロジェクトルート、chmod 600 必須） |
| パスフレーズ | `oda0428`（運用ノートに記載、Slack には書かない） |
| Git リモート | `https://github.com/pb-oda/posla-web` |
| 開発用 PHP | `php -S localhost:8000`（ビルトインサーバー） |

---

## 5.3 APIキー管理

### 5.3.1 POSLA共通キー

Gemini API/Google Places APIのキーはPOSLA運営が一括負担します。`posla_settings`テーブルで一元管理されています。

**管理 UI:** POSLA 管理画面（`https://eat.posla.jp/public/posla-admin/dashboard.html`）→「API設定」タブ

**取得 API:**

| エンドポイント | 用途 | 認証 |
|---|---|---|
| `GET /api/posla/settings.php` | 全設定値の取得 | POSLA 管理者セッション |
| `GET /api/store/settings.php?include_ai_key=1` | テナント側で AI キーを取得（後方互換、voice-commander 等） | テナント認証 |
| `POST /api/store/ai-generate.php` | Gemini プロキシ（推奨経路、P1-6 以降） | テナント認証 |

**サーバープロキシ:**
- `api/store/places-proxy.php` — Google Places API（CORS 回避＋キー非露出）
- `api/store/ai-generate.php` — Gemini API（フロントに API キーを渡さない）

### 5.3.2 テナント個別キー

決済キー（Stripe自前キー / Stripe Connect）のみテナント個別です。`owner-dashboard.html`の「決済設定」タブで管理します。

| キー | 保存先 | 用途 |
|---|---|---|
| `stripe_secret_key` | `tenants.stripe_secret_key` | パターン A（自前 Stripe） |
| `stripe_publishable_key` | `tenants.stripe_publishable_key` | フロントの Stripe.js 初期化 |
| `stripe_webhook_secret` | `tenants.stripe_webhook_secret` | Webhook 署名検証 |
| Stripe Connect Account ID | `tenants.stripe_connect_account_id` | パターン B（Connect 経由） |

### 5.3.3 DB 認証情報の環境変数管理（S3 #9 / Q2=A、2026-04-19）

2026-04-19 以降、`api/config/database.php` は **環境変数を優先参照**し、未設定時は従来のハードコード値にフォールバックします。

**優先順位**:
1. `getenv('POSLA_DB_HOST')` → `api/config/database.php` のハードコード値
2. `getenv('POSLA_DB_NAME')` → 〃
3. `getenv('POSLA_DB_USER')` → 〃
4. `getenv('POSLA_DB_PASS')` → 〃

**Apache 環境での渡し方**: `api/.htaccess` に以下を記載

```apache
SetEnv POSLA_DB_HOST mysql80.odah.sakura.ne.jp
SetEnv POSLA_DB_NAME odah_eat-posla
SetEnv POSLA_DB_USER odah_eat-posla
SetEnv POSLA_DB_PASS xxxxxxxx
```

**運用ルール**:
- 本番環境では **必ず `api/.htaccess` に `SetEnv` で渡す**こと（コードリポジトリにパスワードを含めない）
- `api/.htaccess` は git 管理外（`.gitignore` 対象）。デプロイ時は手動 scp で 1 度だけ配置 → 以降はサーバー側に残す
- ローカル開発は環境変数未設定でフォールバック値が使われるため、追加設定不要
- パスワード変更時は `.htaccess` を更新 → Apache 再起動不要（`.htaccess` は per-request 読み込み）

**確認方法**:
```bash
ssh odah@odah.sakura.ne.jp 'cat /home/odah/www/eat-posla/api/.htaccess | grep SetEnv'
```

詳細: [tenant 18 章 18.W.2](../tenant/18-security.md#18w2-db-認証情報の環境変数化修正-9-q2a)

---

## 5.4 デプロイ手順

POSLA はビルドプロセスがありません。**ローカルで編集 → SSH/SCP でアップロード → chmod 604 → 動作確認** の流れです。

::: warning ルール（厳守）
- **サーバー上でコード編集は禁止。** 全ての編集はローカル（プロジェクトルート）で行い、デプロイのみ SSH 経由
- デプロイ前に **必ず `_backup_YYYYMMDD/` にバックアップを取る**
- DB 変更（マイグレーション）は **デプロイより先に実行**（コード側が新カラムを参照しても落ちないように）
- 公開ファイルは **chmod 604**、PHP 実行ファイルも **chmod 604**、ディレクトリは **chmod 705**
- パスフレーズは expect スクリプトで自動入力（手で打つと csh 履歴に残る）
:::

### 5.4.1 デプロイ前のチェックリスト

| # | 確認項目 | 確認方法 | NG 時 |
|---|---|---|---|
| 1 | 変更ファイル一覧が手元にある | `git status` / `git diff --name-only` | コミットして洗い出す |
| 2 | ローカルで動作確認済み | `php -S localhost:8000 -t public` で動作テスト | 修正してから |
| 3 | ES5 互換チェック | `grep -nE "const |let |=>|async |await " public/admin/js/*.js` で 0 件 | const/let/arrow/async を var + コールバックに書き換え |
| 4 | プリペアドステートメント使用 | 変更した PHP の SQL に `?` プレースホルダ | べた書き SQL を `prepare` + `execute` に修正 |
| 5 | tenant_id / store_id 絞り込み | 新規 SQL の WHERE 句に `tenant_id` / `store_id` | 追加してから |
| 6 | バックアップディレクトリ名 | `_backup_$(date +%Y%m%d)` | 同日 2 回目は `_backup_20260419b/` のように添字を付ける |
| 7 | DB マイグレーションがある場合 | `sql/migration-*.sql` をローカルで dry-run（テスト DB） | 構文エラーを修正 |
| 8 | キャッシュバスター更新 | 変更した JS を読む HTML の `<script src="...?v=YYYYMMDD">` をバンプ | バンプ漏れがあると古い JS がキャッシュされる |
| 9 | SSH 鍵パーミッション | `ls -l id_ecdsa.pem` → `-rw-------` | `chmod 600 id_ecdsa.pem` |
| 10 | 本番 URL の確認 | `eat.posla.jp`（**ハイフン無し**） | `eat-posla.jp` ではない |

### 5.4.2 expect + scp 標準デプロイパターン

```bash
# パスフレーズ自動入力デプロイ（プロジェクトルートで実行）
SSH_KEY=./id_ecdsa.pem
REMOTE=odah@odah.sakura.ne.jp
REMOTE_DIR=/home/odah/www/eat-posla
TODAY=$(date +%Y%m%d)

# 1. リモートにバックアップディレクトリ作成 + 既存ファイルをコピー
expect -c "
spawn ssh -i $SSH_KEY -o StrictHostKeyChecking=no $REMOTE \"bash -c '
  mkdir -p $REMOTE_DIR/_backup_${TODAY}/api/store
  cp $REMOTE_DIR/api/store/staff-management.php $REMOTE_DIR/_backup_${TODAY}/api/store/
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 2. 変更ファイルを scp
expect -c "
spawn scp -i $SSH_KEY api/store/staff-management.php $REMOTE:$REMOTE_DIR/api/store/staff-management.php
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 3. パーミッション設定
expect -c "
spawn ssh -i $SSH_KEY $REMOTE \"chmod 604 $REMOTE_DIR/api/store/staff-management.php\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"
```

### 5.4.3 ASCII 配置イメージ

```
ローカル                                 Sakura
─────────────────                       ───────────────────────────
matsunoya-mt/                            /home/odah/www/eat-posla/
├── api/store/staff-management.php  ───→ ├── api/store/staff-management.php  (chmod 604)
├── public/admin/dashboard.html     ───→ ├── public/admin/dashboard.html     (chmod 604)
└── sql/migration-fda1-*.sql        ───→ └── 別途 mysql コマンドで適用 (5.6 参照)
                                          └── _backup_20260419/
                                              ├── api/store/staff-management.php  (旧版)
                                              └── public/admin/dashboard.html     (旧版)
```

### 5.4.4 デプロイ後の動作確認

| # | 確認 | コマンド/操作 | 期待結果 |
|---|---|---|---|
| 1 | サーバー応答 | `curl https://eat.posla.jp/api/monitor/ping.php` | `{"ok":true,...}` |
| 2 | 変更画面の表示 | Chrome シークレットウィンドウで該当 URL | 変更が反映されている |
| 3 | error_log の確認 | `tail -50 /home/odah/log/php_errors.log` | 新規 Fatal/Parse なし |
| 4 | ログイン疎通 | テスト owner アカウントでログイン | ダッシュボードに遷移 |
| 5 | 変更箇所の機能テスト | 影響範囲のシナリオを実行 | 期待動作 |
| 6 | キャッシュバスター効くか | DevTools → Network → 該当 JS の Status 200 + Response が新版 | バンプが効いている |

### 5.4.5 ロールバック手順

不具合が発覚したら、`_backup_YYYYMMDD/` から戻します。

```bash
expect -c "
spawn ssh -i $SSH_KEY $REMOTE \"bash -c '
  cp $REMOTE_DIR/_backup_${TODAY}/api/store/staff-management.php $REMOTE_DIR/api/store/staff-management.php
  chmod 604 $REMOTE_DIR/api/store/staff-management.php
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"
```

::: warning DB ロールバック
DB マイグレーションのロールバックは **手動 SQL** を書く必要があります。マイグレーション適用前の状態に戻すには事前に `mysqldump` バックアップが必要（5.5.2 参照）。
:::

---

## 5.5 バックアップ運用

### 5.5.1 ファイルバックアップ（`_backup_YYYYMMDD/`）

デプロイのたびに、変更対象ファイルだけを `/home/odah/www/eat-posla/_backup_YYYYMMDD/` 配下に元のディレクトリ構造のままコピーします。

| ルール | 内容 |
|---|---|
| ディレクトリ命名 | `_backup_YYYYMMDD/`（日次） |
| 同日 2 回目以降 | `_backup_20260419b/`, `_backup_20260419c/` のようにアルファベット添字 |
| 保持期間 | 30 日（古いバックアップは月次で手動削除） |
| バックアップ範囲 | 「そのデプロイで上書きされるファイルだけ」（全量コピーは不要） |
| 配置 | 元のディレクトリ構造を保つ（`api/store/settings.php` → `_backup_xxx/api/store/settings.php`） |

::: tip バックアップを取る理由
本番で問題が出たときに **1 ファイル単位で即座に戻せる** ため。`git checkout HEAD~1` ではローカルしか戻らないので、本番上にスナップショットを残すのが運用上必須。
:::

### 5.5.2 DB バックアップ（mysqldump）

毎日 1 回、自動 mysqldump で DB 全体をバックアップします。

```bash
# 手動バックアップ
DBHOST=mysql80.odah.sakura.ne.jp
DBNAME=odah_eat-posla
DBUSER=odah_eat-posla
DBPASS=odah_eat-posla
TODAY=$(date +%Y%m%d-%H%M)

mysqldump --single-transaction --quick --routines \
  -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME \
  | gzip > /home/odah/db_backup/dump_${TODAY}.sql.gz
```

| ファイル名 | 用途 | 取得頻度 | 保持期間 |
|---|---|---|---|
| `dump_YYYYMMDD-HHMM.sql.gz` | フル DB バックアップ | 毎日 03:00（cron） | 7 日 |
| `dump_pre_migration_<name>.sql.gz` | マイグレーション前手動取得 | 手動（マイグレーション適用前必須） | 30 日 |

### 5.5.3 リストア手順

```bash
# 1. 復元先 DB を空にする（or 別 DB を作って検証）
mysql -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME -e "DROP DATABASE IF EXISTS \`${DBNAME}\`; CREATE DATABASE \`${DBNAME}\` DEFAULT CHARACTER SET utf8mb4;"

# 2. ダンプを流し込む
gunzip -c /home/odah/db_backup/dump_20260418-0300.sql.gz | mysql -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME

# 3. 動作確認
mysql -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME -e "SELECT COUNT(*) AS tenants FROM tenants;"
```

### 5.5.4 アップロード画像のバックアップ

| 対象 | 場所 | 頻度 | 方法 |
|---|---|---|---|
| メニュー画像 | `/home/odah/www/eat-posla/public/uploads/menu/` | 週 1 | rsync で運用 PC にミラー |
| 店舗ブランディング画像 | `/home/odah/www/eat-posla/public/uploads/branding/` | 週 1 | 同上 |
| レシート PDF | `/home/odah/www/eat-posla/storage/receipts/` | 月 1 | 同上 |

---

## 5.6 マイグレーション管理

### 5.6.1 基本ルール

- スキーマ定義：`sql/schema.sql`（初期スキーマ、**編集禁止**）
- マイグレーション：`sql/migration-*.sql`ファイルで管理
- 新しいカラム・テーブルが必要な場合は **新規マイグレーションファイル** を作成
- 既存テーブルの`ALTER TABLE`も **新規マイグレーションに記述**
- ファイル命名規則: `migration-<feature_id>-<short_desc>.sql`（例: `migration-fda1-device-tokens.sql`）

### 5.6.2 主要マイグレーションファイル一覧（適用順）

::: tip 適用順について
原則は **古い順**。スキーマに追加 → 後段で再 ALTER するパターンがあるため、新規環境にスキーマを構築する場合は **`schema.sql` → 全 migration を ファイル名昇順** で順次流す。
:::

| # | ファイル | 内容 |
|---|---|---|
| 1 | `schema.sql` | 初期スキーマ（必ず最初に） |
| 2 | `migration-a1-options.sql` | メニューオプション |
| 3 | `migration-a3-kds-stations.sql` | KDS ステーション |
| 4 | `migration-a4-removed-items.sql` | 注文の削除アイテム |
| 5 | `migration-b-phase-b.sql` | テーブルセッション |
| 6 | `migration-b2-plan-menu-items.sql` | プラン別メニュー |
| 7 | `migration-b3-course-session.sql` | コース予約 |
| 8 | `migration-c3-payment-gateway.sql` | 決済ゲートウェイ |
| 9 | `migration-c4-payments.sql` | 決済記録 |
| 10 | `migration-c5-cart-events.sql` | カート行動記録 |
| 11 | `migration-c5-inventory.sql` | 在庫テーブル |
| 12 | `migration-cb1-error-log.sql` | エラーログテーブル（CB1） |
| 13 | `migration-cp1-cashier-pin.sql` | 担当スタッフ PIN |
| 14 | `migration-cr1-refund.sql` | 返金フィールド |
| 15 | `migration-d1-ai-settings.sql` | AI 設定 |
| 16 | `migration-d2-places-api-key.sql` | Google Places キー |
| 17 | `migration-e1-username.sql` | ユーザー名対応 |
| 18 | `migration-e2-override-image.sql` | 店舗別画像オーバーライド |
| 19 | `migration-e3-call-alerts.sql` | スタッフ呼び出し |
| 20 | `migration-f1-token-expiry.sql` | テーブルトークン有効期限 |
| 21 | `migration-f2-order-items.sql` | order_items テーブル |
| 22 | `migration-fda1-device-tokens.sql` | デバイス自動ログイントークン（F-DA1） |
| 23 | `migration-fqr1-sub-sessions.sql` | サブセッション QR（F-QR1） |
| 24 | `migration-g1-audit-log.sql` | 監査ログ |
| 25 | `migration-g2-user-sessions.sql` | アクティブセッション一覧 |
| 26 | `migration-h1-welcome-message.sql` | お客様ウェルカムメッセージ |
| 27 | `migration-i1-monitor.sql` | monitor_events |
| 28 | `migration-i1-order-memo-allergen.sql` | 注文メモ・アレルゲン |
| 29 | `migration-i2-menu-enhancements.sql` | メニュー拡張 |
| 30 | `migration-i3-satisfaction-ratings.sql` | 満足度評価 |
| 31 | `migration-i4-table-memo.sql` | テーブルメモ |
| 32 | `migration-i5-last-order-kds-notify.sql` | ラストオーダー KDS 通知 |
| 33 | `migration-i6-table-merge-split.sql` | テーブル結合・分割 |
| 34 | `migration-i7-kitchen-call.sql` | キッチン呼び出し |
| 35 | `migration-l1-takeout.sql` | テイクアウト |
| 36 | `migration-l3-shift-management.sql` | シフト管理 |
| 37 | `migration-l3b-gps-staff-control.sql` | GPS スタッフ管理 |
| 38 | `migration-l3b2-user-visible-tools.sql` | ユーザー可視ツール |
| 39 | `migration-l3p2-labor-cost.sql` | 人件費 |
| 40 | `migration-l3p3-multi-store-shift.sql` | 多店舗シフト |
| 41 | `migration-l4-translations.sql` | 翻訳テーブル |
| 42 | `migration-l4-ui-translations-v2.sql` | UI 翻訳 v2 |
| 43 | `migration-l5-receipts.sql` | レシート |
| 44 | `migration-l7-google-place-id.sql` | Google Place ID |
| 45 | `migration-l8-self-checkout.sql` | セルフチェックアウト |
| 46 | `migration-l8-ui-translations.sql` | UI 翻訳 |
| 47 | `migration-l9-reservations.sql` | 予約テーブル |
| 48 | `migration-l9-table-auth.sql` | テーブル認証 |
| 49 | `migration-l10-posla-admin.sql` | POSLA 管理者テーブル |
| 50 | `migration-l10b-posla-settings.sql` | POSLA 設定テーブル |
| 51 | `migration-l11-subscription.sql` | サブスクリプション |
| 52 | `migration-l13-stripe-connect.sql` | Stripe Connect |
| 53 | `migration-l15-smaregi.sql` | スマレジ連携 |
| 54 | `migration-l16-plan-tier.sql` | プラン階層（旧） |
| 55 | `migration-n12-store-branding.sql` | 店舗ブランディング |
| 56 | `migration-p1-6c-drop-ai-api-key.sql` | テナント AI キー削除 |
| 57 | `migration-p1-6d-posla-admin-sessions.sql` | POSLA 管理セッション |
| 58 | `migration-p1-11-subscription-events-unique.sql` | sub_events 重複防止 |
| 59 | `migration-p1-22-drop-tenants-google-places-api-key.sql` | テナント Places キー削除 |
| 60 | `migration-p1-27-torimaru-demo-tenant.sql` | torimaru デモ |
| 61 | `migration-p1-34-tenant-hq-broadcast.sql` | 本部一括配信フラグ |
| 62 | `migration-p1-remove-square.sql` | Square 機能削除 |
| 63 | `migration-p119-checkout-i18n.sql` | チェックアウト i18n |
| 64 | `migration-p119b-receipt-rollback.sql` | レシートロールバック |
| 65 | `migration-p119c-fix-encoding.sql` | エンコーディング修正 |
| 66 | `migration-p133-collation-fix.sql` | 照合順序修正 |
| 67 | `migration-p1a-device-role.sql` | device ロール追加（P1a） |
| 68 | `migration-plan-remove-lite.sql` | lite プラン削除 |
| 69 | `migration-s6-session-pin.sql` | セッション PIN（S-6） |
| 70 | `migration-u2-printer.sql` | プリンタ設定 |
| 71 | `migration-s3-refund-pending.sql` | **【適用済 2026-04-19】** payments.refund_status ENUM に 'pending' 追加（S3 #10 二重返金防止） |
| 72 | `migration-s3-reservation-uniq.sql` | **【保留 2026-04-19】** reservations への複合 UNIQUE 追加（S3 #7 二重予約防止）。重複データ 1 件あり、テストデータ削除後に適用 |
| 73 | `migration-s3-fix-comment.sql` | **【スキップ 2026-04-19】** スキーマコメント文字化け修正（store_settings.default_hourly_rate カラム不在のため不要） |

### 5.6.3 マイグレーション実行手順

```bash
# 1. 事前バックアップ（必須）
TODAY=$(date +%Y%m%d-%H%M)
mysqldump --single-transaction -h mysql80.odah.sakura.ne.jp \
  -u odah_eat-posla -podah_eat-posla odah_eat-posla \
  | gzip > /home/odah/db_backup/dump_pre_migration_fda1_${TODAY}.sql.gz

# 2. ローカルから SQL ファイルを scp（事前に SQL をリポジトリに置いてから）
expect -c "
spawn scp -i id_ecdsa.pem sql/migration-fda1-device-tokens.sql odah@odah.sakura.ne.jp:/home/odah/www/eat-posla/sql/
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 3. SSH で SQL を流し込む
expect -c "
spawn ssh -i id_ecdsa.pem odah@odah.sakura.ne.jp \"bash -c '
  mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -podah_eat-posla odah_eat-posla \
    < /home/odah/www/eat-posla/sql/migration-fda1-device-tokens.sql 2>&1
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 4. 適用確認
expect -c "
spawn ssh -i id_ecdsa.pem odah@odah.sakura.ne.jp \"bash -c '
  mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -podah_eat-posla odah_eat-posla \
    -e \"DESCRIBE device_registration_tokens;\"
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"
```

::: warning マイグレーション実行のタイミング
**コードデプロイの直前** に実行する。先にコードをデプロイすると、新カラム参照で 500 エラーになる。逆に、SQL 変更だけ先行するのは OK（旧コードは新カラムを無視するため）。
:::

---

## 5.7 mysql コマンドラインの実用例

### 5.7.1 ログイン

```bash
# 本番 DB に接続
mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -podah_eat-posla odah_eat-posla

# 1 行クエリ実行
mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -podah_eat-posla odah_eat-posla \
  -e "SELECT slug, name, is_active FROM tenants;"
```

### 5.7.2 よく使う調査クエリ

```sql
-- テナントの状態確認
SELECT id, slug, name, is_active, subscription_status, plan, created_at
FROM tenants ORDER BY created_at DESC;

-- テナント別の店舗数
SELECT t.slug, t.name, COUNT(s.id) AS store_count
FROM tenants t LEFT JOIN stores s ON t.id = s.tenant_id
GROUP BY t.id ORDER BY store_count DESC;

-- 直近 1 時間の注文件数（テナント別）
SELECT t.slug, COUNT(o.id) AS orders, SUM(o.total) AS revenue
FROM orders o
JOIN stores s ON s.id = o.store_id
JOIN tenants t ON t.id = s.tenant_id
WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY t.id;

-- posla_settings の確認（値はマスク）
SELECT setting_key,
  CASE WHEN LENGTH(setting_value) > 8
    THEN CONCAT(LEFT(setting_value, 4), '****', RIGHT(setting_value, 2))
    ELSE setting_value END AS masked_value,
  updated_at
FROM posla_settings ORDER BY setting_key;

-- 直近 1 日の error_log 上位 10
SELECT error_no, code, COUNT(*) AS cnt, MAX(message) AS sample
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY error_no, code
ORDER BY cnt DESC LIMIT 10;

-- 監査ログ: 特定スタッフの直近 50 操作
SELECT created_at, action, entity_type, entity_id
FROM audit_log
WHERE user_id = 'xxxxxxxx' ORDER BY id DESC LIMIT 50;

-- アクティブセッション一覧（テナント別）
SELECT t.slug, COUNT(*) AS active_sessions
FROM user_sessions us
JOIN users u ON u.id = us.user_id
JOIN tenants t ON t.id = u.tenant_id
WHERE us.expires_at > NOW()
GROUP BY t.id;
```

### 5.7.3 危険操作（要レビュー）

```sql
-- テナント無効化（全ユーザーがログイン不能になる）
UPDATE tenants SET is_active = 0 WHERE slug = 'xxxxx';

-- スタッフのパスワード強制リセット（password_hash は別途生成）
UPDATE users SET password_hash = ?, must_change_password = 1
WHERE id = ? AND tenant_id = ?;

-- 古い session の一括削除（運用 cron で自動化推奨）
DELETE FROM user_sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- 古い error_log の削除（90 日以上前）
DELETE FROM error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

---

## 5.8 cron 設定

### 5.8.1 cron 一覧（4 本）

| cron | 用途 | 推奨頻度 | スクリプト |
|---|---|---|---|
| auto-clock-out | 退勤打刻忘れの自動退勤（L-3） | 5 分毎 | `api/cron/auto-clock-out.php` |
| monitor-health | エラーログ監視 + Slack 通知（I-1, CB1） | 5 分毎 | `api/cron/monitor-health.php` |
| reservation-cleanup | 期限切れ holds 削除・auto-no-show（L-9） | 1 時間毎 | `api/cron/reservation-cleanup.php` |
| reservation-reminders | 予約リマインダー送信（L-9） | 5〜10 分毎 | `api/cron/reservation-reminders.php` |

### 5.8.2 Sakura crontab エントリ（推奨）

Sakura レンタルサーバーのコントロールパネル → 「CRON」設定で登録します。`/usr/local/bin/php` のパスは Sakura 環境のもの。

```
# auto-clock-out: 5 分毎（00, 05, 10, ... 55 分に実行）
*/5 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/auto-clock-out.php >> /home/odah/log/cron-auto-clock-out.log 2>&1

# monitor-health: 5 分毎
*/5 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/monitor-health.php >> /home/odah/log/cron-monitor-health.log 2>&1

# reservation-cleanup: 毎時 0 分
0 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/reservation-cleanup.php >> /home/odah/log/cron-reservation-cleanup.log 2>&1

# reservation-reminders: 5 分毎
*/5 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/reservation-reminders.php >> /home/odah/log/cron-reservation-reminders.log 2>&1

# DB 日次バックアップ: 毎日 03:00
0 3 * * * mysqldump --single-transaction -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -podah_eat-posla odah_eat-posla | gzip > /home/odah/db_backup/dump_$(date +\%Y\%m\%d).sql.gz

# 古いログ削除: 毎日 04:00（30 日より古い cron ログを削除）
0 4 * * * find /home/odah/log -name "cron-*.log" -mtime +30 -delete
```

::: warning csh で cron を書くときの罠
- `2>&1` は **bash でしか動かない**。Sakura のコントロールパネルから登録する場合は内部的に sh が呼ばれるので OK だが、shell 経由で実行するときは `bash -c "..."` が必要
- `%` 記号は crontab 内では特殊文字（改行扱い）。`date +%Y%m%d` の `%` は `\%` でエスケープ
- パスは絶対パスで書く（cron は PATH が短い）
:::

### 5.8.3 cron 認証ヘッダ

cron スクリプトは CLI 実行が標準ですが、外部 URL から HTTP 経由で叩くこともできます。その場合は共有秘密が必須です。

```php
// api/cron/monitor-health.php の冒頭
$expected = getenv('POSLA_CRON_SECRET') ?: '';
if (!$expected || ($_SERVER['HTTP_X_POSLA_CRON_SECRET'] ?? '') !== $expected) {
    http_response_code(403); echo 'forbidden'; exit;
}
```

呼び出し例:
```bash
curl -H "X-POSLA-CRON-SECRET: xxxxxxxxxxxxxxxxxxxx" \
     https://eat.posla.jp/api/cron/monitor-health.php
```

### 5.8.4 cron が動いているかの確認

```bash
# 直近の実行ログを確認
tail -100 /home/odah/log/cron-monitor-health.log

# heartbeat の確認（monitor-health が動いていれば毎 5 分更新）
mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -podah_eat-posla odah_eat-posla \
  -e "SELECT setting_value FROM posla_settings WHERE setting_key='monitor_last_heartbeat';"

# monitor_events に最近のイベントがあるか
mysql -h mysql80.odah.sakura.ne.jp -u odah_eat-posla -podah_eat-posla odah_eat-posla \
  -e "SELECT id, event_type, severity, title, created_at FROM monitor_events ORDER BY id DESC LIMIT 10;"
```

---

## 5.9 ログファイル

### 5.9.1 ログ一覧

| パス | 内容 | ローテーション |
|---|---|---|
| `/home/odah/log/php_errors.log` | PHP の Fatal/Parse/Warning エラー（**最重要**） | Sakura 標準（容量超過時） |
| `/home/odah/log/cron-*.log` | cron 各スクリプトの stdout/stderr | 5.8.2 のクリーンアップ cron で 30 日 |
| `/home/odah/log/access.log` | Apache アクセスログ（Sakura 提供） | Sakura 標準 |
| DB の `error_log` テーブル | API エラー全件（CB1 で導入） | 90 日（運用 cron で削除） |
| DB の `monitor_events` テーブル | 監視イベント（heartbeat / Slack 通知発火元） | 90 日（同上） |
| DB の `audit_log` テーブル | 全 admin 操作（誰が何をした） | 1 年（同上） |

### 5.9.2 php_errors.log の見方

```bash
# 直近のエラー 50 件
tail -50 /home/odah/log/php_errors.log

# Fatal だけ抽出
grep -E "PHP Fatal" /home/odah/log/php_errors.log | tail -20

# 直近 1 時間のエラー数
awk -v cutoff="$(date -v-1H +'%d-%b-%Y %H:%M:%S')" \
  '$0 > cutoff' /home/odah/log/php_errors.log | wc -l
```

### 5.9.3 error_log（DB）テーブルの SQL クエリ集

```sql
-- 直近 1 時間のエラー件数（E 番号別）
SELECT error_no, code, COUNT(*) AS cnt
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY error_no, code
ORDER BY cnt DESC LIMIT 20;

-- 5xx エラーのみ
SELECT created_at, error_no, code, message, endpoint, request_path
FROM error_log
WHERE http_status >= 500
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY created_at DESC;

-- 認証系エラー（ブルートフォース疑い）
SELECT ip_address, COUNT(*) AS cnt
FROM error_log
WHERE http_status IN (401, 403)
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY ip_address
HAVING cnt >= 10
ORDER BY cnt DESC;

-- 時間帯別エラー数（過去 24 時間）
SELECT DATE_FORMAT(created_at, '%H:00') AS hour, COUNT(*) AS cnt
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY hour ORDER BY hour;
```

---

## 5.10 パーミッション

### 5.10.1 推奨パーミッション一覧

| 対象 | パーミッション | 理由 |
|---|---|---|
| PHP ファイル（公開） | 604 | Apache から read 可能、所有者のみ書き込み可 |
| HTML / CSS / JS | 604 | 同上 |
| ディレクトリ | 705 | 一覧表示は不要、execute（cd）は必要 |
| `api/config/database.php` | 600 | DB パスワード保護（Apache は実行時に PHP 経由で読む） |
| `id_ecdsa.pem`（ローカル） | 600 | SSH 鍵保護（必須） |
| アップロードディレクトリ | 705 + ファイル 604 | 書き込み禁止（PHP からのみ書く場合は別途 chown） |
| ログファイル | 600 | 個人情報含む可能性（IP/UserAgent） |

### 5.10.2 設定方法

```bash
# 単一ファイル
chmod 604 /home/odah/www/eat-posla/api/store/staff-management.php

# ディレクトリ配下を一括（PHP のみ）
find /home/odah/www/eat-posla/api -type f -name "*.php" -exec chmod 604 {} \;

# ディレクトリだけ 705
find /home/odah/www/eat-posla -type d -exec chmod 705 {} \;
```

---

## 5.11 SSH 鍵管理

### 5.11.1 鍵ファイル

| 項目 | 値 |
|---|---|
| ファイル名 | `id_ecdsa.pem` |
| 配置場所 | プロジェクトルート（`/Users/odahiroki/Desktop/matsunoya-mt/`） |
| アルゴリズム | ECDSA（より新しいなら ED25519 を推奨） |
| パスフレーズ | `oda0428`（運用ノートに記載、Slack/メールには書かない） |
| パーミッション | **600 必須**（644 だと SSH が拒否） |

### 5.11.2 expect スクリプトでの自動入力

```bash
# 標準パターン
SSH_KEY=./id_ecdsa.pem
REMOTE=odah@odah.sakura.ne.jp

expect -c "
spawn ssh -i $SSH_KEY -o StrictHostKeyChecking=no $REMOTE \"hostname; uname -a\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"
```

### 5.11.3 鍵をローテーションする場合

1. 新しい鍵を生成: `ssh-keygen -t ed25519 -f id_ed25519_new.pem`
2. 公開鍵を Sakura の `~/.ssh/authorized_keys` に追加
3. 新しい鍵で接続テスト
4. 古い鍵を `authorized_keys` から削除
5. プロジェクトの運用ノート・MEMORY.md を更新

---

## 5.12 セッション管理

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

## 5.13 スマレジ連携

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

## 5.14 ポーリング間隔一覧

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

---

## 5.15 F-DA1 デバイス自動ログイン（ワンタイムトークン）

### 5.15.1 概要

2026-04-18 追加機能。新しい KDS / レジ / ハンディタブレットを設置する際、手動でユーザー名・パスワードを入力せず、**ワンタイムトークン URL** で自動ログインできる仕組み。

### 5.15.2 実装

- トークン発行 API: `api/store/device-registration-token.php`（manager 以上、`require_role`）
- セットアップ HTML: `public/admin/device-setup.html`（トークン URL の遷移先、ページ内 JS が自動 POST）
- 登録 API: `api/auth/device-register.php`（POST、token を検証して device ユーザー新規作成 + セッション確立）
- DB: `device_registration_tokens` テーブル（`sql/migration-fda1-device-tokens.sql`）
- UI: dashboard.html の **【トークン発行】** ボタン（`btn-token-device`、L303）

### 5.15.3 セキュリティ設計

- ワンタイムトークン（`is_used=1` で再利用不可、保存はハッシュ `sha256(plainToken)`）
- 有効期限（発行後 `expires_hours` 時間、デフォルト数時間）
- レートリミット: `check_rate_limit('device_register:' . IP, 3, 3600)` — 同一 IP から 1 時間 3 回
- `SELECT ... FOR UPDATE` でレース条件防止
- tenant / store の `is_active=1` チェック
- device ユーザーのユーザー名は `device_` + UUID 先頭 12 文字、パスワードはランダム 32 桁を `password_hash()` で保存（平文は即破棄）
- 成功時に `audit_log` に `event='device:register'` 記録

### 5.15.4 使用フロー

1. 管理者が dashboard → スタッフ管理 → **【トークン発行】** をクリックし、表示名と `visible_tools` を入力
2. `api/store/device-registration-token.php` がプレーントークンを生成し、`setupUrl`（例: `https://eat.posla.jp/public/admin/device-setup.html?token=xxxx`）を返す
3. URL を新端末の Chrome で開く（QR 化して読み取り可）
4. `device-setup.html` の IIFE が `POST /api/auth/device-register.php` を自動実行
5. サーバーがトークン検証 → device ロールのユーザー新規作成 → user_stores 紐付け → セッション確立
6. トークンは `is_used=1` フラグが立ち、以降は無効
7. レスポンスの `stores[0].userVisibleTools` の優先度（`handy` > `register` > `kds`）で 3 秒後に対応画面へ自動遷移

## 5.16 F-QR1 サブセッション

### 概要

2026-04-18 追加。1 つの物理テーブルに複数グループが着席して別会計する運用（相席）に対応。

### 実装

- API: `api/store/sub-sessions.php`（GET/POST）
- DB: `table_sub_sessions` テーブル、`orders.sub_session_id` 外部キー
- SQL: `sql/migration-fqr1-sub-sessions.sql`
- UI: ハンディの **【📟 個別QR】** ボタン、POSレジのサブセッション別会計

## 5.17 CR1 返金処理

### 概要

2026-04-18 追加。会計済み注文の返金（全額・部分）を Stripe Refund API 経由で実行。

### 実装

- API: `api/store/refund-payment.php`（POST、manager 以上）
- SQL: `sql/migration-cr1-refund.sql`（`orders.refund_status`, `refunded_at`, `refund_amount` カラム）
- UI: POSレジ画面の【📋 取引履歴】→【返金】ボタン
- ロジック:
  - Stripe カード決済 → Stripe Refund API で自動処理、3〜5 営業日でお客様カードに返金
  - 現金 → 記録のみ、物理返却はスタッフ対応
  - QR 決済 → PayPay/d払い管理画面で別途返金、POSLA 側は記録のみ
- 権限: `require_role('manager')`、`WHERE id = ? AND store_id = ?` でテナント境界二重チェック

## 5.18 F-MV1 モニターイベント（POSLA 運営向け）

### 概要

POSLA 本番運用で発生する異常・警告をリアルタイムで記録・通知する仕組み。

### 実装

- API: `api/posla/monitor-events.php`（GET/PATCH、require_auth）
- DB: `monitor_events` テーブル
- ソース: `api/cron/monitor-health.php`（5 分 cron）、`api/monitor/ping.php`（外部 uptime）
- 通知: Slack Webhook + Hiro 個人メール

詳細は 07-monitor.md 参照。

---

## 5.19 トラブルシューティング表（運用作業中によくある問題）

| 症状 | 原因 | 対処 |
|---|---|---|
| `Permission denied (publickey)` | 鍵パーミッション 644 | `chmod 600 id_ecdsa.pem` |
| `Enter passphrase for key` で止まる | expect が拾えていない | `expect "Enter passphrase"` の文字列が一致しているか確認（バージョンで微妙に違う） |
| `mysqldump: Got error: 1045` | DB パスワード誤り | `-p` の直後にスペースなしで連結（`-podah_eat-posla`） |
| `2>&1: command not found` | csh で bash 構文を使った | `bash -c "..."` でラップする |
| デプロイ後 500 エラー | DB マイグレーション未適用 | `php_errors.log` を見て新カラム参照エラーを確認、SQL を流す |
| デプロイしたのに古いまま | キャッシュバスター未バンプ | HTML の `?v=` を更新 |
| cron が動いていない | パスが相対 / `php` のフルパス漏れ | `/usr/local/bin/php` 絶対パスに修正 |
| `monitor_last_heartbeat` が古い | monitor-health cron 停止 | crontab 確認 + `cron-monitor-health.log` 確認 |
| `error_log` テーブルが急膨張 | 攻撃 or バグ多発 | 上位 IP / errorNo を集計、IP ブロック or 修正 |
| Slack 通知が来ない | `slack_webhook_url` 未設定 | `posla_settings` で確認、curl で疎通テスト |

---

## 5.20 FAQ 30 件

### Q1. 本番 URL は `eat.posla.jp` と `eat-posla.jp` のどちら？
A. **`eat.posla.jp`**（ハイフン無し）。ハイフン付きは別ドメインなので絶対に使わないでください。

### Q2. SSH ログインで Permission denied が出る
A. `chmod 600 id_ecdsa.pem` を実行。`644` だと SSH 側で拒否されます。

### Q3. csh で `2>&1` が動かない
A. csh は bash 構文をサポートしません。`bash -c "command 2>&1"` でラップしてください。

### Q4. デプロイのバックアップディレクトリ命名規則は？
A. `_backup_YYYYMMDD/`。同日 2 回目以降は `_backup_20260419b/`, `_backup_20260419c/` のように添字を付けます。

### Q5. バックアップは全量取るべき？
A. いいえ、**そのデプロイで上書きされるファイルだけ** を元のディレクトリ構造のまま `_backup_xxx/` に置きます。全量は `mysqldump`（DB）と週次 rsync（画像）で別途。

### Q6. DB マイグレーションを先にやる？コードを先にやる？
A. **DB を先**。コードを先にデプロイすると、新カラム参照で 500 エラーになります。逆方向（DB だけ先行）は旧コードが新カラムを無視するので安全です。

### Q7. パーミッション 604 と 644 の違いは？
A. 604 = `rw----r--`（その他は read のみ）、644 = `rw-r--r--`（グループも read 可）。Sakura では Apache が「その他」扱いなので 604 で OK、グループに権限を渡す必要なし。

### Q8. cron で `%` がエスケープされない
A. crontab 内で `%` は改行扱い。`date +\%Y\%m\%d` のようにバックスラッシュでエスケープ。

### Q9. `php_errors.log` がどこにあるか分からない
A. `/home/odah/log/php_errors.log`。Sakura のコントロールパネル → 「ログ表示」からも確認できます。

### Q10. mysql コマンドラインのパスワードを履歴に残したくない
A. `~/.my.cnf` にパスワードを書いて `chmod 600`。コマンドラインからは `-p` を省略できます。

### Q11. cron が止まっているか確認したい
A. `posla_settings.monitor_last_heartbeat` が 5 分以内に更新されていれば monitor-health は動作中。それ以外の cron は `cron-*.log` を `tail` で確認。

### Q12. error_log テーブルが急に膨れた
A. 攻撃 or アプリバグの多発。`SELECT error_no, COUNT(*) FROM error_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) GROUP BY error_no ORDER BY 2 DESC LIMIT 10` で原因 errorNo 特定。

### Q13. monitor_events に同じイベントが連発する
A. monitor-health.php の重複防止ロジック（直近 30 分の同タイトルチェック）が効いていない可能性。詳細は `api/cron/monitor-health.php` の `_logEvent` 周辺を確認。

### Q14. Stripe Webhook が失敗していると Slack 通知される
A. monitor-health が `subscription_events` の `error_message` を 5 分間隔で集計、1 件以上あれば warn として記録 + Slack 通知。詳細は `subscription_events` テーブル参照。

### Q15. デプロイ後にキャッシュで古い JS が読まれる
A. `<script src="...?v=YYYYMMDD">` の `?v=` をバンプ。同じ JS を読む全 HTML をバンプし忘れないこと（`grep -r "filename.js" public/`）。

### Q16. テナントを緊急停止したい
A. `UPDATE tenants SET is_active = 0 WHERE slug = ?`。次回操作時に全ユーザーがログイン画面にリダイレクトされます。

### Q17. 古い user_sessions / error_log を削除したい
A. 月次 cron で `DELETE FROM user_sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)` / `DELETE FROM error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)`。

### Q18. PHP のバージョンを確認したい
A. SSH 接続後 `php -v`。Sakura 側のコントロールパネルで PHP バージョン切替が可能。

### Q19. OPcache を無効化してデプロイしたい
A. Sakura の管理画面で OPcache を一時無効化、または `opcache_reset()` を呼ぶスクリプトを実行。通常はファイル更新で自動再読込される。

### Q20. ログファイルが大きすぎて開けない
A. `tail -1000 /home/odah/log/php_errors.log` で末尾だけ取得。または `awk` で日付フィルタ。

### Q21. テスト DB を作りたい
A. Sakura の DB 管理画面で別 DB を作成し、`schema.sql` + 全 migration を流す。本番に影響を与えずに mysqldump リストアテストもできる。

### Q22. expect スクリプトでパスフレーズが一致しない
A. expect の `spawn` 直後の出力を `interact` で確認。バージョンによって `Enter passphrase for key '...':` の表記が異なるので、ワイルドカード `Enter passphrase*` を使う。

### Q23. デプロイ後に動作確認すべき URL は？
A. `https://eat.posla.jp/api/monitor/ping.php`（200 + `{"ok":true}`）+ 該当変更画面の表示。

### Q24. 監査ログ（audit_log）はどれくらい保持？
A. 1 年が目安。`DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)` を月次 cron に。

### Q25. Stripe Connect の Webhook イベントが届かない
A. Stripe Dashboard → Developers → Webhooks で配信ステータス確認。`stripe_webhook_secret` の不一致 or サーバー時刻ズレ（5 分以上）が主原因。

### Q26. メールが届かない（予約リマインダー等）
A. Sakura の `mail()` / `mb_send_mail()` は標準で動くが、`reservation_notifications_log` の `status` を確認。`failed` なら詳細メッセージあり。

### Q27. POSLA 運用秘密（パスフレーズ・DB パス）を共有したい
A. **Slack/メールには書かない**。1Password などのシークレットマネージャー or 直接対面で口頭。

### Q28. SQL マイグレーションの dry-run は？
A. テスト DB で実行 → エラーがなければ本番。MySQL は DDL のロールバックを正式サポートしないため、本番では `mysqldump` バックアップが事実上のロールバック手段。

### Q29. monitor-health.php を手動で動かしたい
A. SSH 後 `php /home/odah/www/eat-posla/api/cron/monitor-health.php`。CLI 実行なら認証不要、結果は標準出力に JSON。

### Q30. 緊急で全テナントを止めたい（重大脆弱性発覚時）
A. `.htaccess` を差し替えて全 API を 503 に。または `index.html` をメンテナンス中ページに置換。詳細は **6.10.2** 緊急メンテナンス手順を参照。

---

## X.X 更新履歴
- **2026-04-19 (S3 セキュリティ修正一式)**: 5.3.3 を新設（DB 認証情報の環境変数管理、`api/.htaccess` の `SetEnv` ディレクティブ運用）。5.6.2 マイグレーション一覧に S3 系 3 件を追加（`migration-s3-refund-pending.sql` 適用済 / `migration-s3-reservation-uniq.sql` 保留 / `migration-s3-fix-comment.sql` スキップ）。
- **2026-04-19**: フル粒度化（Sakura 構成・SSH 接続・デプロイ手順・mysql コマンドライン・cron 設定・パーミッション・FAQ 30 件を追加）
- **2026-04-19**: F-DA1 / F-QR1 / CR1 / F-MV1 の新機能を文書化
- **2026-04-18**: frontmatter 追加、AIヘルプデスク knowledge base として整備
