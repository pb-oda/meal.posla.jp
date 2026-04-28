# POSLA 新サーバ（コンテナ）移行手順書

> 対象: インフラ担当 / デプロイ担当 / 引き継ぎ担当
> 作成日: 2026-04-25
> この文書は `【擬似本番環境】meal.posla.jp` の**現コード・現 Docker 構成**をそのまま新サーバへ載せるための正本 runbook です。

---

## 0. この手順書の前提

- この擬似本番は `docker compose up -d` で `http://127.0.0.1:8081` に起動する
- app は `php:8.2-apache` ベース、DB は `mysql:5.7`
- `DocumentRoot=/var/www/html/public`、`Alias /api /var/www/html/api`
- cron は `docker/php/startup.sh` から `docker/php/cron-loop.sh` を起動している
- 本番も**コンテナ運用**前提
- 本番 URL はまだ vendor-neutral に扱い、本文中では `<production-domain>` で記述する

この runbook では、デモ環境 `eat.posla.jp` や Sakura 共有サーバの運用は基準にしません。

---

## 1. 先に結論

新サーバでは次の構成を推奨します。

```text
internet
  |
  v
[ reverse proxy / LB ]
  TLS termination
  Host: <production-domain>
  X-Forwarded-Proto: https
  |
  v
[ POSLA app container ]
  php:8.2-apache
  DocumentRoot=/var/www/html/public
  Alias /api /var/www/html/api
  |
  v
[ MySQL 5.7 ]
  private network only
```

判断理由:

- 現コードは Apache 同居構成で検証済み
- `/public` を URL に出さない前提で path が組まれている
- `/api` は Apache Alias 前提
- `monitor-health` は app コンテナ内 CLI / 内部 HTTP の両系統で整合が取りやすい

---

## 2. この移行で引き継ぐもの

| 区分 | 移行対象 | 補足 |
|---|---|---|
| アプリ本体 | `api/`, `public/`, `scripts/`, `sql/`, `privacy/`, `terms/`, `uploads/`, `docker/`, `docker-compose.yml` | 正本コード |
| docs | `docs/`, `public/docs-tenant/`, `public/docs-internal/` | この擬似本番専用 docs |
| DB | 既存 DB dump | `posla_settings` を含む |
| 永続化 | `uploads/`, DB volume | コンテナ再作成で消さない |
| 環境変数 | app / db / mail / cron secret | repo 外を正本にする |
| 外部設定 | DNS, TLS, Stripe webhook, Smaregi redirect, Google Chat, 監視 | ドメインと切替順に依存 |

移行対象から外すもの:

- `.git/`
- `.DS_Store`
- `*no_deploy/`
- `docs/manual/node_modules/`
- `docs/manual/.vitepress/dist/`
- ローカル作業用の `/tmp` 退避物

---

## 3. 新サーバで決めておく値

着手前に次を確定してください。

| 項目 | 例 | 必須 |
|---|---|---|
| 公開ドメイン | `https://<production-domain>` | ✅ |
| SSH 接続先 | `<ssh-user>@<host>` | ✅ |
| 配備ルート | `<deploy-root>` | ✅ |
| app env 正本 | `/etc/posla/<production-domain>.app.env` | ✅ |
| db env 正本 | `/etc/posla/<production-domain>.db.env` | ✅ |
| uploads 永続化先 | `/var/lib/posla/uploads` | ✅ |
| PHP ログ出力先 | `/var/log/posla/php_errors.log` | ✅ |
| DB ホスト | `<production-db-host>` または `db` | ✅ |
| DB 名 / user | `posla_prod`, `posla_app` など | ✅ |
| TLS 終端方式 | reverse proxy / LB / Apache 直 | ✅ |
| cutover 方式 | 停止切替 / DNS 切替 / LB 切替 | ✅ |
| 旧環境バックアップ取得元 | 旧本番 or 擬似本番 clone | ✅ |

---

## 4. 正本ファイル

新サーバ配備の基準はこの tree の次です。

- [docker-compose.yml](../docker-compose.yml)
- [docker/php/Dockerfile](../docker/php/Dockerfile)
- [docker/php/startup.sh](../docker/php/startup.sh)
- [docker/php/cron-loop.sh](../docker/php/cron-loop.sh)
- [docker/php/apache-vhost.conf](../docker/php/apache-vhost.conf)
- [docker/env/app.env.example](../docker/env/app.env.example)
- [docker/env/db.env.example](../docker/env/db.env.example)
- [api/config/app.php](../api/config/app.php)

重要:

- 本番で正本にするのは **この擬似本番 tree**
- `eat.posla.jp` のデモ環境は配備ベースにしない
- 2 環境の `rsync --delete` は禁止

---

## 5. ディレクトリ構成

新サーバでは次を作成します。

```text
<deploy-root>/
├── api/
├── public/
├── scripts/
├── sql/
├── privacy/
├── terms/
├── docs/
├── uploads/
├── docker/
│   ├── db/init/
│   ├── env/
│   └── php/
└── docker-compose.yml

/etc/posla/
├── <production-domain>.app.env
├── <production-domain>.db.env
└── <production-domain>.mail.env    # relay を別ファイル管理する場合のみ

/var/lib/posla/uploads/
/var/log/posla/
```

推奨の実運用:

- `<deploy-root>/docker/env/app.env` は `/etc/posla/<production-domain>.app.env` への symlink
- `<deploy-root>/docker/env/db.env` は `/etc/posla/<production-domain>.db.env` への symlink
- `uploads/` は bind mount か named volume のどちらかで永続化する

---

## 6. 環境変数の正本

### 6.1 app env の正本

推奨配置:

- `/etc/posla/<production-domain>.app.env`

最低限必要なキー:

| 変数名 | 用途 |
|---|---|
| `TZ` | タイムゾーン |
| `POSLA_DB_HOST` | DB 接続先 |
| `POSLA_DB_NAME` | DB 名 |
| `POSLA_DB_USER` | DB user |
| `POSLA_DB_PASS` | DB password |
| `POSLA_APP_BASE_URL` | 正規 URL |
| `POSLA_FROM_EMAIL` | 送信元メール |
| `POSLA_SUPPORT_EMAIL` | 問い合わせ先 |
| `POSLA_ALLOWED_ORIGINS` | CORS origin |
| `POSLA_ALLOWED_HOSTS` | Host / Origin 検証 |
| `POSLA_PHP_ERROR_LOG` | PHP error_log 出力先 |
| `POSLA_CRON_SECRET` | HTTP cron / 手動監視実行の共有秘密 |
| `POSLA_SESSION_STORE` | PHP セッション保存先。production は `redis` |
| `POSLA_REDIS_HOST` | Redis 接続先 |
| `POSLA_REDIS_SESSION_PREFIX` | cell ごとの session key prefix |

例:

```dotenv
TZ=Asia/Tokyo

POSLA_DB_HOST=<production-db-host>
POSLA_DB_NAME=posla_prod
POSLA_DB_USER=posla_app
POSLA_DB_PASS=__REPLACE_DB_PASSWORD__

POSLA_APP_BASE_URL=https://<production-domain>
POSLA_FROM_EMAIL=noreply@<production-domain>
POSLA_SUPPORT_EMAIL=info@<production-domain>
POSLA_ALLOWED_ORIGINS=https://<production-domain>
POSLA_ALLOWED_HOSTS=<production-domain>

POSLA_PHP_ERROR_LOG=/var/log/posla/php_errors.log
POSLA_CRON_SECRET=__REPLACE_CRON_SECRET__

POSLA_SESSION_STORE=redis
POSLA_SESSION_REDIS_REQUIRED=1
POSLA_SESSION_GC_MAXLIFETIME_SEC=28800
POSLA_REDIS_HOST=redis
POSLA_REDIS_PORT=6379
POSLA_REDIS_DATABASE=0
POSLA_REDIS_SESSION_PREFIX=posla:posla-control:sess:
POSLA_REDIS_TIMEOUT_SEC=1.5
POSLA_REDIS_READ_TIMEOUT_SEC=1.5
```

注意:

- `POSLA_LOCAL_DB_*` と `POSLA_ENV=local` はローカル Docker 擬似本番専用です。本番では通常使いません
- `POSLA_APP_BASE_URL` と `POSLA_ALLOWED_ORIGINS` / `POSLA_ALLOWED_HOSTS` はセットで合わせる
- `api/.htaccess` に secrets を置く運用は採らない
- Redis は PHP セッション本体を保持します。`user_sessions` / `posla_admin_sessions` は DB 側の失効台帳として引き続き使います

### 6.2 db env の正本

推奨配置:

- `/etc/posla/<production-domain>.db.env`

最低限必要なキー:

```dotenv
MYSQL_ROOT_PASSWORD=__REPLACE_ROOT_PASSWORD__
MYSQL_DATABASE=posla_prod
MYSQL_USER=posla_app
MYSQL_PASSWORD=__REPLACE_DB_PASSWORD__
DB_HOST=db
TZ=Asia/Tokyo
```

### 6.3 env の compose 側配線

初回配置:

```bash
mkdir -p /etc/posla
cp <deploy-root>/docker/env/app.env.example /etc/posla/<production-domain>.app.env
cp <deploy-root>/docker/env/db.env.example /etc/posla/<production-domain>.db.env
ln -snf /etc/posla/<production-domain>.app.env <deploy-root>/docker/env/app.env
ln -snf /etc/posla/<production-domain>.db.env <deploy-root>/docker/env/db.env
```

確認:

```bash
ls -l <deploy-root>/docker/env/app.env
ls -l <deploy-root>/docker/env/db.env
docker compose -f <deploy-root>/docker-compose.yml config -q
```

### 6.4 メール配送の扱い

メール設定は 3 層に分けて管理します。

| 層 | 管理するもの | 正本 |
|---|---|---|
| app env | `POSLA_FROM_EMAIL`, `POSLA_SUPPORT_EMAIL` | `/etc/posla/<production-domain>.app.env` |
| DB 設定 | `ops_notify_email`, `google_chat_webhook_url` など | `posla_settings` |
| 配送経路 | sendmail / Postfix / msmtp / SMTP relay | OS / コンテナ側の infra 設定 |

注意:

- `ops_notify_email` は通知先であり SMTP 認証情報ではない
- SMTP password は repo や DB に入れない
- relay を別ファイル化する場合のみ `/etc/posla/<production-domain>.mail.env` を作る

---

## 7. 事前バックアップ

切替前に必ず退避します。

1. DB dump
2. `uploads/`
3. 現行 env
4. webhook / redirect URI の設定値

例:

```bash
mysqldump --single-transaction --routines --triggers \
  -h <old-db-host> -u <user> -p <db_name> > posla-prod-YYYYMMDD.sql

rsync -av <ssh-user>@<old-host>:/path/to/uploads/ ./uploads-backup-YYYYMMDD/
scp <ssh-user>@<old-host>:/etc/posla/<production-domain>.app.env ./backup/
scp <ssh-user>@<old-host>:/etc/posla/<production-domain>.db.env ./backup/
```

期待通りだった場合:

- DB dump が作成できる
- `uploads/` を欠落なく退避できる

期待と異なった場合:

- cutover を止める
- DB dump か uploads が欠けた状態で先へ進まない

---

## 8. 新サーバ準備

### 8.1 必要ディレクトリ作成

```bash
ssh <ssh-user>@<host>
mkdir -p <deploy-root>
mkdir -p /etc/posla
mkdir -p /var/log/posla
mkdir -p /var/lib/posla/uploads
```

権限要件:

- app コンテナから `uploads/` に書ける
- `POSLA_PHP_ERROR_LOG` の親ディレクトリに書ける

### 8.2 コード同期

ローカルから新サーバへ同期する例:

```bash
rsync -av \
  --exclude '.git/' \
  --exclude '.DS_Store' \
  --exclude '*no_deploy/' \
  --exclude 'docs/manual/node_modules/' \
  --exclude 'docs/manual/.vitepress/dist/' \
  ./ <ssh-user>@<host>:<deploy-root>/
```

同期後の最低確認:

```bash
ssh <ssh-user>@<host> "test -f <deploy-root>/docker-compose.yml && echo ok"
ssh <ssh-user>@<host> "test -f <deploy-root>/docker/php/startup.sh && echo ok"
ssh <ssh-user>@<host> "test -f <deploy-root>/docker/php/cron-loop.sh && echo ok"
```

---

## 9. Compose 起動

### 9.1 app / db env を配置して validation

```bash
ssh <ssh-user>@<host>
cp <deploy-root>/docker/env/app.env.example /etc/posla/<production-domain>.app.env
cp <deploy-root>/docker/env/db.env.example /etc/posla/<production-domain>.db.env
vi /etc/posla/<production-domain>.app.env
vi /etc/posla/<production-domain>.db.env
ln -snf /etc/posla/<production-domain>.app.env <deploy-root>/docker/env/app.env
ln -snf /etc/posla/<production-domain>.db.env <deploy-root>/docker/env/db.env
docker compose -f <deploy-root>/docker-compose.yml config -q
```

### 9.2 起動

```bash
cd <deploy-root>
docker compose up -d --build
docker compose ps
docker compose logs --tail=100 db
docker compose logs --tail=100 php
```

期待通りだった場合:

- `db` が healthy
- `redis` が healthy
- `php` が起動し、Apache が listen している
- ログに `scheduler started` が出る

期待と異なった場合:

- DB 接続失敗なら `POSLA_DB_*` と `MYSQL_*` を見直す
- Apache 起動失敗なら `docker/php/apache-vhost.conf` の mount を確認する
- scheduler 不在なら `docker/php/startup.sh` と `docker/php/cron-loop.sh` の配置と実行権限を確認する

---

## 10. DB restore

### 10.1 基本方針

- 本番移行は **既存 dump restore が正**
- `schema.sql + migration + seed` は検証用であり、既存本番データ移行の代替にしない

### 10.2 restore 手順

```bash
cd <deploy-root>
docker compose up -d db
docker compose exec -T db mysql -uroot -p'__REPLACE_ROOT_PASSWORD__' posla_prod < /path/to/posla-prod-YYYYMMDD.sql
```

注意:

- restore 対象 DB は空にしておく
- seed 済み DB に上から import しない
- 先に誤って初期化していた場合は drop / create してから restore し直す

### 10.3 restore 後の存在確認

```sql
SHOW TABLES LIKE 'posla_admin_sessions';
SHOW TABLES LIKE 'push_subscriptions';
SHOW TABLES LIKE 'push_send_log';
SHOW TABLES LIKE 'error_log';
SHOW COLUMNS FROM subscription_events LIKE 'error_message';
SHOW COLUMNS FROM users LIKE 'role';
SHOW COLUMNS FROM payments LIKE 'void_status';
```

不足があれば対応する `sql/migration-*.sql` を個別適用します。全 migration の一括流し直しはしません。

### 10.4 `posla_settings` の確認

```sql
SELECT setting_key,
       CASE
         WHEN setting_value IS NULL THEN 'NULL'
         WHEN setting_value = '' THEN 'EMPTY'
         ELSE CONCAT('LEN=', CHAR_LENGTH(setting_value))
       END AS state
FROM posla_settings
WHERE setting_key IN (
  'gemini_api_key',
  'google_places_api_key',
  'stripe_secret_key',
  'stripe_publishable_key',
  'stripe_webhook_secret',
  'stripe_price_base',
  'stripe_price_additional_store',
  'stripe_price_hq_broadcast',
  'connect_application_fee_percent',
  'smaregi_client_id',
  'smaregi_client_secret',
  'google_chat_webhook_url',
  'ops_notify_email',
  'web_push_vapid_public',
  'web_push_vapid_private_pem'
)
ORDER BY setting_key;
```

ここで `gemini_api_key` が空なら tenant 側の AI 機能（AIウェイター / 予約AI解析 / AIキッチン / SNS生成 / 需要予測 など）は使えません。

---

## 11. uploads 永続化

例:

```bash
rsync -av ./uploads-backup-YYYYMMDD/ /var/lib/posla/uploads/
```

確認項目:

- `uploads/menu/` が存在する
- コンテナ再起動で消えない
- app コンテナから書き込みできる

---

## 12. cron / scheduler

現構成では、app コンテナ起動時に `docker/php/startup.sh` が `docker/php/cron-loop.sh` を起動します。

現在の役割:

- `monitor-health.php` を 5 分ごとに実行
- `last_heartbeat` を更新

確認:

```bash
docker compose -f <deploy-root>/docker-compose.yml logs --tail=100 php | grep posla-cron
curl -sf https://<production-domain>/api/monitor/ping.php
```

追加で host cron を使う場合は、container env を使うため CLI 実行を優先します。

例:

```bash
*/5 * * * * cd <deploy-root> && docker compose exec -T php php /var/www/html/api/cron/monitor-health.php >> /var/log/posla/cron-monitor-health.log 2>&1
0 * * * * cd <deploy-root> && docker compose exec -T php php /var/www/html/api/cron/reservation-cleanup.php >> /var/log/posla/cron-reservation-cleanup.log 2>&1
*/5 * * * * cd <deploy-root> && docker compose exec -T php php /var/www/html/api/cron/reservation-reminders.php >> /var/log/posla/cron-reservation-reminders.log 2>&1
```

HTTP cron を使う場合のみ `POSLA_CRON_SECRET` を外部実行元でも一致させます。

---

## 13. reverse proxy / DNS / TLS

確認項目:

1. `https://<production-domain>` で証明書が有効
2. reverse proxy が app コンテナの `80` へ流している
3. `X-Forwarded-Proto: https` を付与している
4. DB は外部公開しない
5. `/.well-known/` やヘルスチェックの経路が proxy で潰れていない

---

## 14. 外部サービスの再設定

切替時に見直すもの:

- Stripe webhook URL
- signup / reservation / takeout の callback URL
- Stripe Connect redirect URL
- Smaregi redirect URI
- Google Chat 通知先
- 外部 uptime 監視 URL
- PWA / Web Push の VAPID 管理画面確認

原則:

- ドメインが変わるなら `POSLA_APP_BASE_URL` と外部 callback をまとめて更新する
- ドメインが変わらないなら callback 変更不要なものもあるが、切替後に必ず実疎通を取る

---

## 15. smoke test

### 15.1 基本疎通

```bash
curl -i https://<production-domain>/
curl -i https://<production-domain>/api/monitor/ping.php
curl -i https://<production-domain>/admin/
curl -i https://<production-domain>/customer/menu.html
curl -i https://<production-domain>/customer/reserve.html
curl -i https://<production-domain>/customer/takeout.html
curl -i https://<production-domain>/handy/index.html
curl -i https://<production-domain>/kds/index.html
curl -i https://<production-domain>/posla-admin/dashboard.html
curl -i https://<production-domain>/docs-tenant/
curl -i https://<production-domain>/docs-internal/
curl -i https://<production-domain>/privacy/
curl -i https://<production-domain>/terms/
```

期待値:

- 主要ページは `200`
- `/api/monitor/ping.php` は `200` かつ `db:"ok"`

### 15.2 アプリ導線

最低限これを実施します。

1. POSLA 管理者ログイン
2. テナント owner ログイン
3. 顧客メニュー表示
4. KDS / handy / cashier 表示
5. `PWA / Web Push` タブで VAPID 状態取得
6. `monitor-health` 手動実行
7. `last_heartbeat` 更新確認

### 15.3 監視と通知

条件が揃っていれば実施:

- Google Chat テスト通知
- PWA 購読統計取得
- AI helpdesk 応答

注意:

- `gemini_api_key` 未設定なら AI helpdesk は失敗する
- mail relay 未設定ならメール送信テストは失敗してもアプリコード不具合とは限らない

### 15.4 docs

必要に応じてサーバ上で再ビルド:

```bash
cd <deploy-root>/docs/manual
npm ci
npm run build:all
```

生成先:

- `public/docs-tenant/`
- `public/docs-internal/`

---

## 16. ロールバック

戻す対象はコードだけでは足りません。次をセットで戻します。

1. 旧 DB dump
2. 旧 env
3. 旧 `uploads/`
4. webhook / redirect URI
5. DNS / reverse proxy の向き先

ロールバック例:

```bash
docker compose -f <deploy-root>/docker-compose.yml down
rsync -av ./rollback-code/ <ssh-user>@<host>:<deploy-root>/
scp ./backup/<production-domain>.app.env <ssh-user>@<host>:/etc/posla/<production-domain>.app.env
scp ./backup/<production-domain>.db.env <ssh-user>@<host>:/etc/posla/<production-domain>.db.env
docker compose -f <deploy-root>/docker-compose.yml up -d --build
```

その後、DB restore / uploads restore / smoke test を必ず実施します。

---

## 17. 切替当日の最終チェック

- [ ] `<deploy-root>` に必要ファイルが揃っている
- [ ] `/etc/posla/<production-domain>.app.env` と `db.env` が正しい
- [ ] `docker compose config -q` が通る
- [ ] `db` healthy / `php` up
- [ ] `scheduler started` がログに出る
- [ ] `https://<production-domain>/api/monitor/ping.php` が `ok:true`
- [ ] POSLA 管理 / owner / device のログインが通る
- [ ] docs が `200`
- [ ] webhook / callback / Google Chat / Stripe / Smaregi の再確認が済んでいる
