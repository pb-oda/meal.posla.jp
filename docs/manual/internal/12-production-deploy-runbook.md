---
title: 本番デプロイ手順書
chapter: 12
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム / デプロイ担当
keywords: [production deploy, runbook, docker, env, cell, provisioner, backup, rollback]
last_updated: 2026-04-26
maintainer: POSLA運営
---

# 12. 本番デプロイ手順書

この章は、POSLA を本番へ載せるときの **実行順 runbook** です。
外部サービスのキー、callback URL、API 一覧は [本番切替 API チェックリスト](./production-api-checklist.md) を併用します。

::: warning 最初に守ること
- 本番 URL は必ず `https://<production-domain>` にする。`localhost` / `127.0.0.1` / `host.docker.internal` を public URL に残さない
- 【デモ環境】の設定値、DB、uploads をそのまま本番へコピーしない
- DB を public internet へ公開しない
- 全 tenant / 全 cell へ一括 deploy しない。対象 cell を指定して進める
- 決済 provider は差し替え可能にする。app deploy / cell deploy と、決済 provider 固有設定を混ぜない
:::

## 12.1 対象範囲

本 runbook の対象は次の 2 つです。

| 対象 | 役割 |
|---|---|
| control stack | LP、POSLA管理画面、signup webhook、cell registry、on-demand provisioner trigger |
| cell stack | 1 tenant / 1 cell の app + DB + uploads + scheduler |

前提:

- コードは 1 codebase
- build artifact は 1 つを維持し、deploy 先 cell を分ける
- MVP は `cell architecture + 1 tenant / 1 cell`
- 本番 host では `<deploy-root>` に repo を配置する
- production docs は `<production-domain>` などの placeholder を使う

## 12.2 リリース前 freeze

擬似本番で、対象 commit を固定します。

```bash
pwd
git status --short
git log -1 --oneline
docker compose ps
curl -sf http://127.0.0.1:8081/api/monitor/ping.php
scripts/cell/cell.sh test-01 ping
sh scripts/audit/06-pseudo-prod-release.sh
```

確認:

- 意図しない未コミット変更がない
- `AGENTS.md` などローカル作業メモを release commit に含めていない
- POSLA管理画面へログインできる
- 運用確認用 cell `test-01` が ping できる
- docs を変更した場合は `docs/manual` を build 済み

release tag を切る場合:

```bash
git tag posla-prod-YYYYMMDD-01
git push origin posla-prod-YYYYMMDD-01
```

## 12.3 本番 host 準備

本番 host は Docker Compose で POSLA を起動します。

作成するディレクトリ:

```text
<deploy-root>
/etc/posla
/var/log/posla
/var/lib/posla/uploads
/var/backups/posla
```

最低限の方針:

- SSH は `<ssh-user>@<host>` のみ許可
- public inbound は 80 / 443 / SSH に絞る
- MySQL は container network 内、または private network のみにする
- `/etc/posla` に実値 env を置き、repo へ secret を commit しない
- `uploads/` と DB volume は永続化する

## 12.4 コード配置

本番 host で repo を配置します。

```bash
ssh <ssh-user>@<host>
git clone <repository-url> <deploy-root>
cd <deploy-root>
git switch main
git pull --ff-only
git checkout <release-sha-or-tag>
```

既存サーバから切り替える場合も、【デモ環境】から `rsync --delete` しません。
差分適用が必要な場合は、release commit を正本として git で反映します。

## 12.5 env 作成

repo 内の example から `/etc/posla` に実値 env を作ります。

```bash
cp <deploy-root>/docker/env/db.env.example /etc/posla/<production-domain>.db.env
cp <deploy-root>/docker/env/app.production.env.example /etc/posla/<production-domain>.app.env
ln -snf /etc/posla/<production-domain>.db.env <deploy-root>/docker/env/db.env
ln -snf /etc/posla/<production-domain>.app.env <deploy-root>/docker/env/app.env
```

app env の必須確認:

```text
POSLA_ENVIRONMENT=production
POSLA_DB_HOST=db
POSLA_DB_NAME=<production-db-name>
POSLA_DB_USER=<production-db-user>
POSLA_DB_PASS=<production-db-password>
POSLA_APP_BASE_URL=https://<production-domain>
POSLA_ALLOWED_ORIGINS=https://<production-domain>
POSLA_ALLOWED_HOSTS=<production-domain>
POSLA_FROM_EMAIL=<no-reply-address>
POSLA_SUPPORT_EMAIL=<support-address>
POSLA_PHP_ERROR_LOG=/var/log/posla/php-error.log
POSLA_CRON_SECRET=<secret>
POSLA_SESSION_STORE=redis
POSLA_SESSION_REDIS_REQUIRED=1
POSLA_SESSION_GC_MAXLIFETIME_SEC=28800
POSLA_REDIS_HOST=redis
POSLA_REDIS_PORT=6379
POSLA_REDIS_DATABASE=0
POSLA_REDIS_SESSION_PREFIX=posla:posla-control:sess:
POSLA_PROVISIONER_TRIGGER_URL=http://127.0.0.1:19091/run
POSLA_PROVISIONER_TRIGGER_SECRET=<secret>
POSLA_CELL_APP_URL_PATTERN=https://{tenant_slug}.<production-domain>
```

`POSLA_SESSION_STORE=redis` は本番必須です。PHP コンテナを rebuild / replace してもログインセッションを維持するため、PHP の `$_SESSION` 本体を Redis に置きます。`user_sessions` / `posla_admin_sessions` は引き続き DB 側の有効性台帳として使います。

よく迷うenvの書き方:

`POSLA_CRON_SECRET` / `POSLA_OPS_READ_SECRET` / `POSLA_OP_LAUNCH_SECRET` は、POSLA管理画面の `設定センター > env Secret発行ヘルパー` で用途を選んで発行します。画面には保存されないため、発行直後に必要なenvやOP側設定へ同じ値を設定します。CLIで作る場合は `openssl rand -hex 32` を使います。

| env | 何を書くか | UIで代替できるか |
|---|---|---|
| `POSLA_ALLOWED_ORIGINS` | ブラウザのOrigin。`https://meal.posla.jp` のように scheme あり、path なし。複数ならカンマ区切り | できません。CORS/CSRFの入口なので起動envで固定します |
| `POSLA_ALLOWED_HOSTS` | Host名だけ。`meal.posla.jp` のように scheme なし、path なし。複数ならカンマ区切り | できません。Host検証の入口なので起動envで固定します |
| `POSLA_CRON_SECRET` | POSLA内部cron / monitor-actions用の32文字以上のランダム値 | 保存はできません。発行ヘルパーで作成し、cron実行元とPOSLA本体の起動envで合わせます |
| `POSLA_OPS_READ_SECRET` | OPがPOSLAの `cell-snapshot.php` をread-onlyで読むための32文字以上のランダム値 | 保存はできません。発行ヘルパーで作成し、POSLA本体とOPの設定で同じ値にします |
| `POSLA_OP_LAUNCH_SECRET` | POSLA管理画面からOP sessionを発行する32文字以上のランダム値 | 保存はできません。発行ヘルパーで作成し、POSLA本体とOPのenvで同じ値にします |
| `POSLA_OP_CASE_TOKEN` | POSLAからOPへ障害報告を送るToken | 原則UIで保存します。envはUI保存を使わない固定運用時だけ |

db env の必須確認:

```text
MYSQL_ROOT_PASSWORD=<root-password>
MYSQL_DATABASE=<production-db-name>
MYSQL_USER=<production-db-user>
MYSQL_PASSWORD=<production-db-password>
POSLA_OPS_DB_READONLY_USER=posla_ops_ro
POSLA_OPS_DB_READONLY_PASSWORD=<ops-readonly-password>
POSLA_OPS_DB_READONLY_HOST=<ops-db-source-host>
TZ=Asia/Tokyo
```

`POSLA_OPS_DB_READONLY_*` は、外部運用基盤がDBをread-onlyで確認するための資格情報です。DB初期化時に `docker/db/init/02-ops-readonly-user.sh` が `SELECT` のみを付与します。本番ではDBポートを公開せず、`POSLA_OPS_DB_READONLY_HOST` とネットワーク/Firewallの両方で外部運用基盤の接続元に限定します。

placeholder 残りを確認します。

```bash
grep -R "__REPLACE\\|<production" /etc/posla/<production-domain>.*.env
```

何か表示された場合は deploy しません。実値へ置換してから進めます。

## 12.6 reverse proxy / TLS / DNS

本番公開は `https://<production-domain>` に集約します。

確認:

- reverse proxy が `Host` を保持する
- `X-Forwarded-Proto: https` を app へ渡す
- TLS 証明書が有効
- HTTP は HTTPS へ redirect
- DNS TTL を切替前に短くしている
- `https://<production-domain>/api/monitor/ping.php` を外形監視対象にする

## 12.7 初回 boot

```bash
cd <deploy-root>
docker compose config -q
docker compose up -d redis
docker compose up -d db
docker compose ps
docker compose up -d --build
docker compose ps
docker compose logs --tail=100 php
```

期待状態:

- `db` と `php` が `Up`
- `redis` が `Up` かつ `healthy`
- `php` log に致命的な `Fatal error` がない
- production guard が `localhost` public URL を理由に停止していない

Redis session 設定確認:

```bash
docker compose exec -T php php -r 'require "/var/www/html/api/lib/session-store.php"; posla_configure_session_store(); echo ini_get("session.save_handler"), "\n", ini_get("session.save_path"), "\n";'
```

期待値:

- `session.save_handler` が `redis`
- `session.save_path` が `tcp://redis:6379?...prefix=posla%3A...%3Asess%3A`

## 12.8 DB restore / migration

本番移行時は、必ず restore 前に backup を確保します。

```bash
mkdir -p /var/backups/posla
docker compose exec -T db mysqldump \
  --default-character-set=utf8mb4 \
  -uroot -p'<root-password>' <production-db-name> \
  > /var/backups/posla/control-before-YYYYMMDD-HHMM.sql
```

既存 dump を復元する場合:

```bash
docker compose exec -T db mysql \
  --default-character-set=utf8mb4 \
  -uroot -p'<root-password>' <production-db-name> \
  < /path/to/control-dump.sql
```

注意:

- `schema.sql` を直接編集しない
- 追加変更は新規 migration として適用する
- cell DB の migration は対象 cell ごとに実行し、migration ledger を残す
- 日本語 tenant / store 名を扱うため `utf8mb4` を明示する

通常レジ方針変更を含む release では、少なくとも以下の migration 適用を確認する。

| migration | 目的 | 確認カラム |
|---|---|---|
| `sql/migration-p1-46-register-payment-detail.sql` | 通常レジのカード / QR / 電子マネー詳細を保存 | `payments.payment_method_detail`, `orders.payment_method_detail` |
| `sql/migration-p1-47-register-close-reconciliation.sql` | レジ締め照合情報を保存 | `cash_log.expected_amount`, `cash_log.difference_amount`, `cash_log.cash_sales_amount`, `cash_log.card_sales_amount`, `cash_log.qr_sales_amount`, `cash_log.reconciliation_note` |

## 12.9 POSLA管理画面 初期設定

`https://<production-domain>/posla-admin/` にアクセスし、POSLA管理者でログインします。

確認するタブ:

| タブ | 確認内容 |
|---|---|
| テナント管理 | 運用確認用 tenant、onboarding request、cell status |
| Cell配備 | `ready_for_cell` / `active` の状態、推奨コマンド |
| Feature Flags | tenant / cell 単位の表示制御 |
| API設定 | AI / Places / 通知 / 決済 provider / Smaregi |
| PWA / Web Push | VAPID 公開鍵 / 秘密鍵 |
| 監視・通知 | Google Chat、内部 heartbeat、monitor health、外部運用基盤側の外形監視 |
| OP / runner連携 | alert endpoint、case endpoint、runner疎通、Google Chat通知 |
| 管理者ユーザー | 管理者アカウント、不要管理者の削除 |

API設定で本番前に必ず入れる値:

| 設定 | 値 |
|---|---|
| 決済 provider | 本番 provider 確定後の live key / webhook secret / Price ID |
| Google Chat / 運用通知 | 本番の通知先 |
| 外部障害報告連携 Endpoint | `https://<ops-domain>/api/ingest/posla-case` |
| 外部障害報告連携 Token | OP側 `POSLA_OPS_CASE_TOKEN` と同じ値 |
| 外部監視アラート連携 Endpoint | `https://<ops-domain>/api/alerts.php` |
| 外部監視アラート連携 Token | 接続先側の ingest token と同じ値 |

`POSLA_OP_CASE_ENDPOINT` / `POSLA_OP_CASE_TOKEN` / `POSLA_OP_ALERT_ENDPOINT` / `POSLA_OP_ALERT_TOKEN` を env で設定した場合は、env が POSLA管理画面の値より優先されます。

## 12.10 決済 provider 設定

POSLA の本番 deploy 手順は、決済 provider に依存させません。
決済 provider が Stripe でも、別 provider でも、差し替える範囲はこの節に閉じます。

### 現在の実装標準: Stripe

Stripe を使う場合、POSLA管理画面の API 設定へ次を入れます。

| setting_key | 内容 |
|---|---|
| `stripe_secret_key` | live secret key |
| `stripe_publishable_key` | live publishable key |
| `stripe_webhook_secret` | subscription webhook signing secret |
| `stripe_webhook_secret_signup` | signup webhook signing secret |
| `stripe_price_base` | 基本料金 Price ID |
| `stripe_price_additional_store` | 追加店舗 Price ID |
| `stripe_price_hq_broadcast` | 本部一括配信 Price ID |
| `connect_application_fee_percent` | Connect 手数料率 |

Stripe 側の URL:

| 用途 | URL |
|---|---|
| signup webhook | `https://<production-domain>/api/signup/webhook.php` |
| subscription webhook | `https://<production-domain>/api/subscription/webhook.php` |
| Connect callback | `https://<production-domain>/api/connect/callback.php` |

### 別 provider を選ぶ場合

別 provider へ変える場合も、次の app deploy 手順は変えません。

- Docker Compose 起動
- env 実値化
- control DB restore
- cell deploy
- on-demand provisioner
- smoke test

差し替える対象:

- LP 申込 checkout 作成 API
- provider webhook 署名検証
- `posla_tenant_onboarding_requests` を `ready_for_cell` にするイベント処理
- POSLA管理画面の決済設定ラベル
- provider 固有の API key / price / plan 設定

## 12.11 on-demand provisioner

新規申込後の cell 作成は、定期 timer ではなく on-demand trigger を基本にします。

本番 host で trigger service を起動します。

```bash
cd <deploy-root>
POSLA_PROVISIONER_TRIGGER_SECRET='<secret>' \
POSLA_CELL_APP_URL_PATTERN='https://{tenant_slug}.<production-domain>' \
  php scripts/cell/provisioner-trigger-server.php
```

常駐化する場合は systemd などで管理します。

```ini
[Unit]
Description=POSLA on-demand cell provisioner trigger
After=network.target docker.service

[Service]
Type=simple
WorkingDirectory=<deploy-root>
Environment=POSLA_PROVISIONER_TRIGGER_SECRET=<secret>
Environment=POSLA_CELL_APP_URL_PATTERN=https://{tenant_slug}.<production-domain>
ExecStart=/usr/bin/php scripts/cell/provisioner-trigger-server.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

dry-run:

```bash
cd <deploy-root>
POSLA_CELL_APP_URL_PATTERN='https://{tenant_slug}.<production-domain>' \
  php scripts/cell/provision-ready-cells.php --limit=1 --dry-run
```

通常フロー:

1. LP 申込
2. 決済 provider でカード登録 / trial 開始
3. webhook が control DB を `ready_for_cell` に更新
4. Web 側が `POSLA_PROVISIONER_TRIGGER_URL` を呼ぶ
5. provisioner が対象 tenant の cell だけ作成
6. smoke 成功後に `active`

## 12.12 cell deploy

既存 cell または運用確認用 cell は、対象を指定して作業します。

```bash
scripts/cell/cell.sh <cell-id> config
scripts/cell/cell.sh <cell-id> backup
scripts/cell/cell.sh <cell-id> build
scripts/cell/cell.sh <cell-id> deploy
scripts/cell/cell.sh <cell-id> register-db
POSLA_CELL_SMOKE_STRICT=1 scripts/cell/cell.sh <cell-id> smoke
```

`build` は未指定時に現在の Git commit を `POSLA_DEPLOY_VERSION` と image tag へ刻印します。`deploy` 後は `ping.php` の `deploy_version` と外部運用基盤のDeploy記録で、対象cellに入ったcommit / artifact / smoke結果 / rollback候補を確認します。

tenant 初期化が必要な場合:

```bash
POSLA_OWNER_PASSWORD='<initial-owner-password>' \
  scripts/cell/cell.sh <cell-id> onboard-tenant <tenant-slug> '<tenant-name>' '<store-name>' owner '<owner-name>' '<owner-email>'
```

守ること:

- 1 回の作業対象は 1 cell
- backup 前に deploy しない
- migration は対象 cell にだけ適用する
- smoke が失敗した cell を `active` 扱いにしない

## 12.13 control smoke test

```bash
curl -sf https://<production-domain>/api/monitor/ping.php
curl -I https://<production-domain>/
curl -I https://<production-domain>/admin/
curl -I https://<production-domain>/customer/menu.html
curl -I https://<production-domain>/handy/
curl -I https://<production-domain>/kds/
curl -I https://<production-domain>/posla-admin/
curl -I https://<production-domain>/docs-tenant/
curl -I https://<production-domain>/docs-internal/
```

画面 smoke:

- POSLA管理者ログイン
- API設定保存
- tenant 新規作成
- tenant 削除ガード確認
- 管理者ユーザー削除ガード確認
- owner ログイン
- テーブル / メニュー / 注文 / レジ画面表示
- PWA / Web Push 鍵状態確認
- 監視通知テスト

## 12.14 signup E2E

本番決済 provider を設定した後に、申込から利用開始までを確認します。

期待フロー:

1. LP から申込
2. カード登録 / trial 開始
3. 完了画面へ戻る
4. control DB に onboarding request が作成される
5. provider webhook 後に `ready_for_cell`
6. on-demand provisioner が対象 cell を作成
7. `active`
8. owner がログインできる

確認 SQL の例:

```sql
SELECT tenant_id, tenant_slug, tenant_name, status, cell_id
FROM posla_tenant_onboarding_requests
ORDER BY created_at DESC
LIMIT 10;

SELECT tenant_id, tenant_slug, tenant_name, cell_id, status, app_base_url
FROM posla_cell_registry
ORDER BY updated_at DESC
LIMIT 10;
```

## 12.15 rollback

rollback は control stack と cell stack を分けて判断します。

control stack の rollback:

```bash
cd <deploy-root>
git checkout <previous-release-sha-or-tag>
docker compose up -d --build
curl -sf https://<production-domain>/api/monitor/ping.php
```

cell rollback:

```bash
scripts/cell/cell.sh <cell-id> backups
scripts/cell/cell.sh <cell-id> rollback-plan latest
POSLA_CELL_RESTORE_CONFIRM=<cell-id> scripts/cell/cell.sh <cell-id> rollback latest
POSLA_CELL_SMOKE_STRICT=1 scripts/cell/cell.sh <cell-id> smoke
```

判断基準:

- code だけの不具合なら image / code rollback を優先
- DB migration 後の rollback は、データ損失の可能性を確認してから実行
- 1 tenant の障害は対象 cell だけ rollback
- 全体障害時も、原因が control stack か provider 側かを分けて確認

## 12.16 post-deploy 監視

deploy 後 30 分は次を確認します。

| 項目 | 見るもの |
|---|---|
| app ping | 外部運用基盤から `/api/monitor/ping.php` |
| DB | read-only 接続、migration ledger |
| signup | onboarding request の停滞 |
| cell | `posla_cell_registry.status`、cell smoke |
| error | errorNo 増加、error_log |
| queue/job | 滞留、heartbeat |
| 決済 | provider webhook delivery |
| rollback | backup が取得済みか |

## 12.17 OP / runner E2E

POSLA本体のsmoke後、OP / runner 連携を本番値で確認します。

1. `https://<op-domain>/api/status.php` が200
2. OPから `https://<production-domain>/api/monitor/ping.php` が読める
3. OPから `cell-snapshot.php` が `X-POSLA-OPS-SECRET` 付きで読める
4. OPのDB read-onlyが成功
5. runner health がOP画面で `ok`
6. runner repo sync が成功し、POSLA repoのcommitが表示される
7. POSLA管理画面から監視テスト通知を送信
8. OP Alert に入る
9. runner / Codex調査が作成される
10. Google Chat に短文通知が届く
11. テスト `monitor_event` を `resolved=1` にする

詳細な運用は [14. OP / runner 連携運用](./13-op-runner-integration.md) を参照します。

## 12.18 Go / No-Go

Go 条件:

- `https://<production-domain>/api/monitor/ping.php` が成功
- POSLA管理画面にログインできる
- env に placeholder が残っていない
- 決済 provider の webhook が成功
- signup E2E が成功
- 運用確認用 cell の smoke が成功
- backup / rollback 手順を確認済み

No-Go 条件:

- public URL に localhost 系が残っている
- DB が public へ露出している
- webhook signing secret 未設定
- `ready_for_cell` が作成されても provisioner が起動しない
- cell smoke が失敗
- rollback 用 backup がない
