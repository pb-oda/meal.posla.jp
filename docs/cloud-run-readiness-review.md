# POSLA Cloud Run 前提見直しメモ

作成日: 2026-04-28
対象: `【擬似本番環境】meal.posla.jp`
目的: 既存機能を壊さずに、POSLA を Google Cloud Run 前提へ寄せるための現状整理と移行順を決める。

## 0. 結論

Cloud Run への移行は可能。ただし、現在の `docker-compose.yml` をそのまま Cloud Run に載せるのではなく、POSLA を「stateless な web service + 外部永続サービス」に分解してから移す必要がある。

壊さないための最重要方針:

- 既存の画面 URL / API path / JSON レスポンス形式は変えない
- DB schema は破壊的変更をしない。必要な変更は新規 migration で expand から入る
- ローカル擬似本番の `docker compose up -d` は維持する
- Cloud Run 対応は env / storage / cron / session の差し替え層から進める
- セルフ決済、KDS、注文、会計、予約、在庫、AI、POSLA管理画面は smoke test 対象として毎回確認する

現時点の優先度は「機能追加」ではなく「Cloud Run で消える前提の排除」。

## 1. Cloud Run で前提が変わるもの

Cloud Run の公式仕様では、web service の ingress container は `0.0.0.0` の指定 port で listen する必要がある。`PORT` は Cloud Run から注入される。さらに、コンテナ内ファイルシステムへの書き込みはインスタンス停止で永続化されない。

POSLA 側で影響を受ける現状:

| 現状 | Cloud Run での扱い | 壊さない対応 |
|---|---|---|
| `docker-compose.yml` の `php` が `8081:80` で公開 | Cloud Run は `$PORT` に合わせる必要がある | Apache を `$PORT` 対応にする。ただしローカル compose は現行 `80` のまま維持 |
| `api/`, `public/`, `scripts/` を bind mount | Cloud Run runtime では bind mount 前提にしない | app code を image に焼き込む |
| `uploads/` をローカル filesystem に保存 | Cloud Run の通常 filesystem は永続化されない | Cloud Storage bucket または Cloud Storage volume mount へ移す |
| MySQL container を compose 内で起動 | Cloud Run は DB を同居させない | Cloud SQL for MySQL へ移す |
| Redis container を compose 内で起動 | Cloud Run は Redis を同居させない | Memorystore for Redis / Valkey へ移す |
| app container 内 cron loop | replica 数だけ重複実行される危険がある | web service では `POSLA_CRON_ENABLED=0`、Cloud Scheduler / Cloud Run Jobs へ分離 |
| PHP error log をローカルファイルで tail | Cloud Run では stdout / stderr と Cloud Logging が正 | monitor-health のログ入力元を Cloud Logging 前提に再設計 |
| `/tmp` file based rate limit | instance ごとに分断される | Redis か DB に寄せる |
| env file / `.htaccess` に値を置く運用 | Cloud Run は env と Secret Manager が正 | Secret Manager + Cloud Run env に寄せる |

2026-04-28 時点で、production image、Cloud SQL env 接続、Redis rate limit、uploads 保存先 env 差し替え、`/uploads` Alias、Cloud Run Jobs 用 cron runner、POSLA本体メールの送信元/問い合わせ先UIは実装済み。SendGridは現行標準では使わない。

## 2. 目標構成

```text
internet
  |
  v
[ Cloud Run service: posla-web ]
  - public HTTP
  - app code baked image
  - POSLA_CRON_ENABLED=0
  - DocumentRoot=/var/www/html/public
  - Alias /api /var/www/html/api
  |
  +--> [ Cloud SQL for MySQL ]
  +--> [ Memorystore Redis / Valkey ]
  +--> [ Cloud Storage bucket: uploads ]
  +--> [ Secret Manager ]
  +--> [ Cloud Logging / Monitoring ]

[ Cloud Scheduler ]
  |
  +--> [ Cloud Run Job: posla-cron-5min / posla-cron-hourly ]
```

cell architecture を採る場合:

- 1 tenant / 1 cell を継続するなら、Cloud Run service / Cloud SQL database / uploads prefix / Redis prefix を cell 単位で分ける
- shared service に寄せる場合でも、`tenant_id` / `store_id` 境界と storage prefix は必須
- どちらにしても image は 1 つにし、env と deploy target を分ける

## 3. 既存機能を壊さない移行順

### Phase 0: レビュー固定

実装変更はしない。Cloud Run で衝突する前提だけを洗い出す。

完了条件:

- この文書の確認
- Cloud Run に移す対象と、ローカル compose に残す対象を分ける
- smoke test 一覧を確定する

### Phase 1: production image を作る

目的は bind mount 依存を外すこと。既存の URL / API / DB には触らない。

対応:

- Cloud Run 用 Dockerfile を追加する
- `api/`, `public/`, `scripts/`, `privacy/`, `terms` を image に COPY する
- `uploads/` は image に焼き込まない
- Apache は `$PORT` を読めるようにする
- ローカル `docker-compose.yml` は現状維持する

確認:

- `http://127.0.0.1:8081/api/monitor/ping.php`
- POSLA管理画面ログイン
- owner / manager / staff ログイン
- 顧客メニュー、注文、KDS、会計画面の表示

### Phase 2: Cloud SQL 接続を追加する

目的は DB を外部化すること。schema やクエリ仕様は変えない。

対応:

- `POSLA_DB_HOST` で Cloud SQL 接続を選べる状態を作る
- private IP か Cloud SQL connector / Unix socket のどちらを使うか決める
- Cloud SQL connection 数を抑えるため、Cloud Run の max instances / concurrency を初期は小さくする
- migration は cell 単位、backup 後に実行する

注意:

- Cloud SQL for MySQL の engine version は要確認。現状は MySQL 5.7 前提なので、MySQL 8.0 に上げる場合は照合順序、予約語、SQL mode を別途検証する
- Cloud Run の scale-out で DB 接続数が増える。最初から無制限 autoscale にしない

### Phase 3: Redis を必須依存に寄せる

目的は session / rate limit / duplicate suppression を複数 instance で共有すること。

対応:

- PHP session は既存の `POSLA_SESSION_STORE=redis` を本番前提にする
- `POSLA_REDIS_SESSION_PREFIX` に cell / environment を含める
- file based rate limiter は Redis 実装を追加して差し替える
- Redis 障害時にログインが壊れる設計になるため、Memorystore の可用性設定と監視を入れる

DB session を選ぶ場合:

- 初期コストは下がるが、全リクエストで DB write が増える
- 注文、KDS polling、会計、AI、監視が同じ DB に乗るため、ピーク時に DB が詰まりやすい
- Cloud Run 前提では Redis を最初から入れる方が後戻りが少ない

### Phase 4: uploads を Cloud Storage へ移す

目的は menu image 等の uploaded file を instance から独立させること。

安全な進め方:

1. まず storage path を tenant / store prefix 付きに統一する
2. 既存 filesystem driver を残す
3. Cloud Storage driver または Cloud Storage volume mount を追加する
4. upload / read / delete の互換 smoke test を作る

推奨:

- MVP は Cloud Storage volume mount でもよい
- 長期的には PHP 側に storage adapter を置き、Cloud Storage API で扱う方が排他・エラー処理・権限管理を制御しやすい

注意:

- Cloud Storage FUSE は完全な POSIX filesystem ではない
- 同一ファイル名への同時書き込みは避ける。POSLA は UUID ファイル名なので現状の方針は相性がよい

### Phase 5: cron を web から分離する

目的は replica 増加時の二重実行を防ぐこと。

対応:

- Cloud Run web service は `POSLA_CRON_ENABLED=0`
- `monitor-health.php`, `reservation-cleanup.php`, `reservation-reminders.php`, `auto-clock-out.php` を Cloud Scheduler / Cloud Run Jobs から実行する
- Cloud Run Jobs は `/usr/local/bin/posla-cron-job-cloudrun.sh` を command にする
- 5分 job は `POSLA_CRON_TASK=every-5-minutes`
- 1時間 job は `POSLA_CRON_TASK=hourly`

確認:

- `/api/monitor/ping.php` の `cron_lag_sec`
- `last_heartbeat` 更新
- 予約通知、予約掃除、自動退勤の実行ログ

### Phase 6: logging / monitoring を Cloud Run 前提へ寄せる

目的は file tail 監視をやめ、Cloud Logging と DB event を正にすること。

対応:

- PHP error log は stdout / stderr へ出す
- `monitor-health.php` は DB event と Cloud Logging ベースで検知する
- 外部 uptime は `https://<production-domain>/api/monitor/ping.php`
- OP 連携先は `<op-domain>` の read-only probe として整理する

### Phase 7: 本番 cutover

cutover は一度に全部切り替えない。

推奨順:

1. staging Cloud Run service に擬似本番 DB clone で接続
2. smoke test 全通
3. Stripe webhook / callback を staging で検証
4. 1 cell / 1 tenant で canary
5. 問題なければ本番 domain を Cloud Run service に向ける

## 4. 壊してはいけない smoke test

Cloud Run 対応の各 Phase 後に、最低限これを確認する。

| 区分 | 確認内容 |
|---|---|
| 基本 | `/api/monitor/ping.php` が `ok:true`、DB `ok` |
| 認証 | POSLA管理者、owner、manager、staff、device login |
| 注文 | 顧客メニューから注文作成、`orders` / `order_items` 登録 |
| KDS | 新規注文がKDSに表示され、status 更新できる |
| 会計 | cashier 表示、支払い確定、支払い履歴 |
| セルフ決済 | Stripe 設定ありで「お会計」表示、OFF / 未設定では非表示 |
| Stripe webhook | checkout / subscription / payment event の受信と冪等処理 |
| QR決済 | POSLA無関係の読込支払い後、レジ側入力でDB登録できる |
| 予約 | 予約作成、reminder、cleanup |
| 在庫 | 注文後の在庫影響、仕入登録 |
| uploads | メニュー画像 upload / 表示 / 上書きなし |
| AI | Gemini APIキー取得、AIウェイター、SNS生成、需要予測 |
| OP連携 | read-only snapshot / ping / alert 表示 |

## 5. Cloud Run 用 env 方針

本番値は Secret Manager と Cloud Run env で管理する。repo に実値を置かない。

最低限:

```dotenv
POSLA_ENV=production
POSLA_APP_BASE_URL=https://<production-domain>
POSLA_ALLOWED_ORIGINS=https://<production-domain>
POSLA_ALLOWED_HOSTS=<production-domain>

POSLA_DB_HOST=<cloud-sql-host-or-socket>
POSLA_DB_NAME=<db-name>
POSLA_DB_USER=<secret>
POSLA_DB_PASS=<secret>

POSLA_SESSION_STORE=redis
POSLA_SESSION_REDIS_REQUIRED=1
POSLA_REDIS_HOST=<memorystore-host>
POSLA_REDIS_PORT=6379
POSLA_REDIS_SESSION_PREFIX=posla:<cell-id>:sess:

POSLA_CRON_ENABLED=0
POSLA_CRON_SECRET=<secret>

POSLA_UPLOADS_DIR=/var/www/html/uploads
POSLA_UPLOADS_PUBLIC_PREFIX=uploads

POSLA_MAIL_TRANSPORT=none
```

未確定の env はコードへ直書きしない。まず `docker/env/app.production.env.example` へ placeholder として追加する。

## 6. 意思決定が必要な点

| 論点 | 推奨 | 理由 |
|---|---|---|
| tenant 配置 | 初期は 1 tenant / 1 cell | 障害範囲を限定し、顧客別 rollback ができる |
| Redis | 初期から Memorystore | session / rate limit / cache を後から移す方が危険 |
| uploads | 最初は GCS mount、早期に adapter | 既存コード影響を小さく始められる |
| cron | web から分離 | replica 増で二重実行される |
| DB | Cloud SQL MySQL | Cloud Run と同居 DB は不可 |
| MySQL version | まず現行互換を優先 | MySQL 8 化は別検証に切り出す |
| OP | まず read-only 外部 probe | POSLA本体の可用性とOPの可用性を分離する |

## 7. デプロイ手順

実デプロイの手順は [cloud-run-deploy-runbook.md](./cloud-run-deploy-runbook.md) を正とする。

## 8. やらないこと

この見直しでは次をしない。

- UI の作り替え
- API response 形式の変更
- 認証方式の変更
- 既存 DB migration の編集
- `schema.sql` の直接変更
- 既存 Docker Compose の破壊
- 決済導線の仕様変更
- KDS / 注文 / 会計のロジック変更

## 9. 実装済みスライス

2026-04-28 の対応:

- `docker/php/Dockerfile.cloudrun` を追加
- `docker/php/startup-cloudrun.sh` を追加
- `docker/php/apache-cloudrun.conf.template` を追加
- `docker/php/cron-job-cloudrun.sh` と `scripts/cloudrun/cron-runner.php` を追加
- `docker/env/app.production.env.example` を Cloud Run + Memorystore 前提に更新
- `api/lib/rate-limiter.php` に `POSLA_RATE_LIMIT_STORE=redis` を追加
- `api/lib/db.php` に `database.php` 不在時の env fallback と Cloud SQL Unix socket 用 `socket` 設定を追加
- `api/config/database.php.example` に Cloud SQL Unix socket 用 `socket` 設定例を追加
- `api/lib/uploads.php` を追加し、uploads 保存先を `POSLA_UPLOADS_DIR` で差し替え可能にした
- Apache に `/uploads` Alias を追加
- `api/lib/mail.php` を追加し、POSLA本体メールの送信元/問い合わせ先をUI設定化した。SendGridは現行標準では使わない
- POSLA管理画面の手動監視実行を Cloud Run `$PORT` 対応にした

この対応後も、既存のローカル `docker-compose.yml` は従来通り `docker/php/Dockerfile` と `docker/php/apache-vhost.conf` を使う。

## 10. 参照した公式資料

- Cloud Run container runtime contract: https://cloud.google.com/run/docs/container-contract
- Cloud SQL for MySQL: Connect from Cloud Run: https://cloud.google.com/sql/docs/mysql/connect-run
- Cloud Run secrets: https://cloud.google.com/run/docs/configuring/services/secrets
- Cloud Run Cloud Storage volume mounts: https://cloud.google.com/run/docs/configuring/services/cloud-storage-volume-mounts
- Cloud Run services on a schedule: https://cloud.google.com/run/docs/triggering/using-scheduler
- Cloud Run jobs: https://cloud.google.com/run/docs/create-jobs
- Memorystore Redis from Cloud Run: https://cloud.google.com/memorystore/docs/redis/connect-redis-instance-cloud-run
