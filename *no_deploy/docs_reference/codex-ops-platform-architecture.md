# POSLA 初期本番構成と Codex 運用支援基盤

> 対象: インフラエンジニア / サーバ移行担当 / 運用設計担当
> 作成日: 2026-04-24
> 目的: **今回の新サーバ移行で最初に必要な APP-DB 構成**を先に明確化し、その後に **将来の Codex 運用支援基盤**をどう接続するかを 1 本の資料で共有する

---

## 1. 最初に伝えたいこと

今回いちばん優先するのは、**POSLA を新サーバで安全に起動できる APP-DB 構成を作ること**です。
Codex 運用支援は今後必ず作りますが、最初の段階ではまだ同居させません。

まず作る構成はこれです。

```text
    +-------------------+        private app-db         +-------------------+
    |     POSLA APP     | <---------------------------> |     POSLA DB      |
    |   meal.posla.jp   |                               |      MySQL        |
    +-------------------+                               +-------------------+
```

この段階でインフラ担当が押さえるべき論点は、主に次の 6 つです。

1. APP と DB をどう分離するか
2. document root と URL 前提をどう置くか
3. env をどこに置くか
4. web と cron に同じ env をどう供給するか
5. DB dump / migration / DB 内設定をどう引き継ぐか
6. PWA push / mail / uploads / webhook のような周辺要件をどう漏らさず移すか

---

## 2. 今回の初期構成

### 2.1 まずは APP-DB の 2 層で始める

- APP: PHP 8.2 + Apache または同等の Web 実行環境
- DB: MySQL 5.7 互換
- DB は public に出さない
- APP から DB へ private に接続する
- 監視・運用補助・将来の Codex はこの 2 層の外側に追加する

### 2.2 いきなり URL 構造を変えない

現行 runtime は、**document root 配下に `api/` と `public/` が並ぶ構成**を前提にしています。
Docker の現行設定も同じです。

- `DocumentRoot /var/www/html`
- `/api/...`
- `/public/...`
- `/` は `/public/index.html` へリダイレクト

つまり、**今回の新サーバ移行の初回では `/public` 前提を維持する方が安全**です。
`meal.posla.jp` を最終的にきれいな URL にしたいとしても、それは **第2段階の URL 整理タスク**として切り分けるべきです。

初回移行で優先するのは:

- コードをそのまま動かす
- env を正しく入れる
- DB を正しく移す
- cron / push / mail / uploads を壊さない

です。

---

## 3. サーバ上の推奨配置

### 3.1 推奨ディレクトリ構成

```text
/var/www/meal.posla.jp/           # deploy root（document root 兼 app root）
  api/
  public/
  scripts/
  sql/
  uploads/

/etc/posla/meal.posla.env         # env ファイル（document root 外）
/var/log/posla/php_errors.log     # PHP error_log 出力先
/var/lib/posla/uploads/menu/      # 永続化ストレージ候補
```

### 3.2 env はどこに置くか

**推奨は document root の外**です。

- 第一候補: `/etc/posla/meal.posla.env`
- root 権限が弱い環境の次善策: `/home/<user>/secure/meal.posla.env`
- 非推奨:
  - `/<deploy-root>/.env`
  - `/<deploy-root>/api/.env`
  - `/<deploy-root>/public/.env`
  - git 管理下への commit

理由:

- 外から直接読まれない
- deploy 対象と secrets を分離できる
- web と cron の両方に同じ値を供給しやすい

### 3.3 env の供給方式

推奨順位は次の通りです。

1. **server-level env**
   - Apache vhost
   - PHP-FPM pool
   - systemd `EnvironmentFile=`
2. **wrapper script + source**
   - cron 用に `/etc/posla/meal.posla.env` を読み込む
3. **`.htaccess` SetEnv**
   - Apache 環境では使える
   - ただし **cron/CLI には効かない**

要点:

- **web と cron が同じ env を読む**ようにする
- `.htaccess` だけで完結させない

---

## 4. APP サーバで最低限必要なもの

### 4.1 ランタイム要件

- PHP 8.2
- Apache + mod_rewrite、または同等構成
- PHP extensions:
  - `pdo_mysql`
  - `mbstring`
  - `intl`
  - `zip`
  - `bcmath`
  - `openssl`
  - `curl`
  - `gmp` 推奨
- `mb_send_mail()` が通る送信経路
- `sys_get_temp_dir()` へ書き込み可能

### 4.2 APP 側で書き込みが必要な場所

| パス / 機能 | 用途 | 条件 |
|---|---|---|
| `uploads/menu/` | メニュー画像保存 | 永続化必須 |
| `sys_get_temp_dir()/posla_rate_limit` | rate limit 状態 | 書込必須 |
| `sys_get_temp_dir()/posla_helpdesk_cache` | 残置 API 用一時キャッシュ | 書込推奨 |
| `POSLA_PHP_ERROR_LOG` の出力先 | PHP エラーログ | 親 dir 書込必須 |

### 4.3 HTTPS / reverse proxy

secure cookie 判定は runtime 側で以下を見ます。

- `HTTPS`
- `HTTP_X_FORWARDED_PROTO=https`
- `SERVER_PORT=443`

そのため、TLS 終端が proxy 側にある場合は **`X-Forwarded-Proto: https` を APP に渡す設定が必須**です。

これが欠けると:

- secure cookie が付かない
- POSLA 管理者ログインが不安定になる
- セッション運用が壊れる

---

## 5. DB サーバで最低限必要なもの

### 5.1 DB 方針

- MySQL 5.7 互換
- APP からのみ接続
- public 開放しない
- dump / restore で戻せる前提にする

### 5.2 初回移行時の原則

- 旧環境から dump を取る
- 新環境へ restore する
- migration tracking table は無いので **存在確認ベースで不足 migration を追加適用**する

### 5.3 優先確認する migration

- `sql/migration-l4-translations.sql`
- `sql/migration-self-p1-4-guest-alias.sql`
- `sql/migration-inv-p1-5-auto-consumption.sql`
- `sql/migration-rsv-p1-2-waitlist.sql`
- `sql/migration-p1-6d-posla-admin-sessions.sql`

加えて、少なくとも次の存在確認は先に行うこと。

- `posla_admin_sessions`
- `error_log`
- `push_subscriptions`
- `push_send_log`
- `orders.stock_consumed_at`
- `users.role=device`

---

## 6. 必須 env 一覧

詳細の canonical source は `docs/production-api-checklist.md` です。
ここでは、サーバ移行担当が **最初に必ず埋めるべき env** を抜き出します。

| 変数名 | web | cron | 役割 | 未設定時の問題 |
|---|---|---|---|---|
| `POSLA_DB_HOST` | ✅ | ✅ | DB 接続先 | DB 接続失敗 |
| `POSLA_DB_NAME` | ✅ | ✅ | DB 名 | DB 接続失敗 |
| `POSLA_DB_USER` | ✅ | ✅ | DB ユーザー | DB 接続失敗 |
| `POSLA_DB_PASS` | ✅ | ✅ | DB パスワード | DB 接続失敗 |
| `POSLA_APP_BASE_URL` | ✅ | ✅ | 正規 URL | 旧ドメイン URL 漏れ |
| `POSLA_FROM_EMAIL` | ✅ | ✅ | 送信元メール | 旧メールアドレス / 配達失敗 |
| `POSLA_SUPPORT_EMAIL` | ✅ | ✅ | 問い合わせ先 | 表示内容ずれ |
| `POSLA_ALLOWED_ORIGINS` | ✅ | ✅ | CORS | 新ドメインで CORS 失敗 |
| `POSLA_ALLOWED_HOSTS` | ✅ | ✅ | Host/Origin 検証 | CSRF / origin 検証失敗 |
| `POSLA_PHP_ERROR_LOG` | ✅ | ✅ | PHP error log | ログが silent に消える |
| `POSLA_CRON_SECRET` | 条件付き | 条件付き | HTTP cron 用秘密 | HTTP cron 403 |

### 6.1 local 専用 env

以下は Docker local 専用です。本番では通常使いません。

- `POSLA_ENV=local`
- `POSLA_LOCAL_DB_HOST`
- `POSLA_LOCAL_DB_NAME`
- `POSLA_LOCAL_DB_USER`
- `POSLA_LOCAL_DB_PASS`

本番相当の Docker でも、**本番に寄せたいなら `POSLA_DB_*` を使う方が自然**です。

### 6.2 env 配置の実務ルール

- env ファイル自体は repo に置かない
- 値は secrets 管理対象にする
- web と cron の両方に同じ値を供給する
- `.htaccess` を使う場合でも、cron 側は別経路で注入する

---

## 7. env ではなく DB に残る設定

ここがサーバ移行で抜けやすい点です。
**API キーや運用鍵のすべてが env にあるわけではありません。**

### 7.1 `posla_settings` に残る共通設定

少なくとも次は DB dump / restore 後に存在確認が必要です。

| キー | 用途 |
|---|---|
| `gemini_api_key` | AI 全機能・翻訳 |
| `google_places_api_key` | Google Places |
| `stripe_secret_key` | Stripe 共通サブスク課金 |
| `stripe_publishable_key` | Stripe 公開鍵 |
| `stripe_webhook_secret` | Stripe webhook |
| `stripe_webhook_secret_signup` | signup webhook |
| `stripe_webhook_secret_reservation` | reservation webhook |
| `stripe_price_base` | 基本料金 Price ID |
| `stripe_price_additional_store` | 追加店舗 Price ID |
| `stripe_price_hq_broadcast` | HQ 配信アドオン Price ID |
| `connect_application_fee_percent` | Stripe Connect 手数料率 |
| `smaregi_client_id` | スマレジ |
| `smaregi_client_secret` | スマレジ |
| `slack_webhook_url` | 監視通知 |
| `ops_notify_email` | 運営通知メール |
| `monitor_cron_secret` | HTTP 経由の監視 / cron 実行用共有秘密 |
| `web_push_vapid_public` | Web Push 公開鍵 |
| `web_push_vapid_private_pem` | Web Push 秘密鍵 |

### 7.2 テナントごとに DB に残る設定

以下は env ではなく、テナントデータ側に残ります。

| 項目 | 保存先の例 |
|---|---|
| テナント個別 Stripe secret | `tenants.stripe_secret_key` |
| Stripe Connect account | `tenants.stripe_connect_account_id` |
| Connect onboarding 状態 | `tenants.connect_onboarding_complete` |
| Smaregi contract / token | `tenants.smaregi_contract_id`, `tenants.smaregi_access_token`, `tenants.smaregi_refresh_token`, `tenants.smaregi_token_expires_at` |
| LINE channel access token | `tenant_line_settings.channel_access_token` |

**サーバ移行では env だけでなく DB dump の完全性確認が必要**、という意味です。

### 7.3 名前が似ている値の区別

混同しやすいので明示します。

| 名前 | 保存場所 | 用途 |
|---|---|---|
| `POSLA_CRON_SECRET` | env | APP 側の HTTP cron endpoint を叩く時の共有秘密 |
| `monitor_cron_secret` | `posla_settings` | 監視 / 運用側で使う共有秘密 |
| `POSLA_PHP_ERROR_LOG` | env | PHP `error_log()` 出力先 |
| `web_push_vapid_private_pem` | `posla_settings` | Web Push 秘密鍵 |

---

## 8. PWA / Push / Webhook / Mail で落としやすい点

### 8.1 PWA は `/public/...` 前提を維持する

現行の PWA は以下のスコープで構成されています。

- `/public/admin/`
- `/public/kds/`
- `/public/handy/`

そのため初回移行では:

- manifest の `start_url`
- service worker scope
- static asset path

を壊さないことを優先します。
**初回移行と同時に `/public` を URL から外すのは非推奨**です。

### 8.2 Web Push

Web Push の VAPID 鍵は env ではなく DB にあります。

- `web_push_vapid_public`
- `web_push_vapid_private_pem`

ルール:

- 公開鍵は front に返してよい
- 秘密鍵は front に返さない
- Git に置かない
- 不要に再生成しない

**秘密鍵を再生成すると既存購読が事実上無効化される**ため、移行では「引き継ぐ」が正解です。

### 8.3 Mail

APP は `mb_send_mail()` を使う箇所があります。
そのため infra 側では:

- sendmail / Postfix / SMTP relay などの送信経路
- 新ドメイン側の SPF / DKIM / DMARC

を準備しておく必要があります。

### 8.4 外部 webhook / callback

少なくとも次は本番ドメインで確認が必要です。

- Stripe webhook
- Stripe Connect return URL
- Smaregi redirect URI
- signup / reservation の callback / redirect

---

## 9. 初回サーバ移行の進め方

1. APP サーバと DB サーバを用意する
2. APP の document root / deploy root を決める
3. env ファイルを document root 外に置く
4. web と cron に同じ env を供給する
5. コードを配置する
6. DB dump を restore する
7. migration 不足を確認して適用する
8. `posla_settings` と tenant 側秘密情報の存在確認をする
9. `uploads/menu/` 永続化と権限を確認する
10. `POSLA_PHP_ERROR_LOG` の出力先を確認する
11. HTTPS / proxy header を確認する
12. smoke test を行う

### 9.1 smoke test の最小セット

- `/api/monitor/ping.php` → `200` かつ `db:"ok"`
- `/api/auth/me.php` → 未認証で `401`
- `/api/posla/me.php` → 未認証で `401`
- POSLA 管理画面ログイン
- tenant ログイン
- cron 1 ターン成功
- `POSLA_PHP_ERROR_LOG` へ書込成功
- メニュー画像アップロード成功
- メール送信成功

---

## 10. 初回 Docker 検証での考え方

本番相当を Docker で先に再現する場合も、最初は同じ思想で進めます。

```text
    +-------------------+        docker network         +-------------------+
    |   APP container   | <---------------------------> |    DB container   |
    +-------------------+                               +-------------------+
```

ポイント:

- 最初は `APP-DB` の 2 コンテナでよい
- Docker でも current runtime の `/api` + `/public` 前提を維持する
- env は image に焼き込まず、外から注入する
- 実 DB を直結するより clone DB の方が安全

---

## 11. 将来の Codex 運用支援をどう足すか

初回移行が安定したら、次段階で Codex を足します。

```text
                    +---------------------------+
                    |      Codex UI / API       |
                    |   (運用支援 Web 画面)      |
                    +-------------+-------------+
                                  |
                    private network / VPN / bastion
                                  |
          +-----------------------+-----------------------+
          |                                               |
          v                                               v
 +-------------------+                         +-------------------+
 |   POSLA APP       | <------ app-db -------> |     POSLA DB      |
 |   meal.posla.jp   |                         |      MySQL        |
 +-------------------+                         +-------------------+
          |
          +------------------> 他サービスA
          |
          +------------------> 他サービスB
```

### 11.1 なぜ外に置くか

- POSLA 障害時にも生きていてほしい
- product 内に強い運用権限を置きたくない
- 将来の他サービスでも共通化したい

### 11.2 初期の Codex は read-only で始める

- docs 検索
- ログ参照
- read-only SQL
- health check
- 原因候補提示
- 手順提示

初期段階ではまだやらないこと:

- 本番コード修正
- destructive SQL
- 自動 deploy
- credential rotation
- DB write

### 11.3 権限は段階制にする

- `read_only`
- `propose_only`
- `approved_execute`

---

## 12. この資料をインフラ担当がどう使うか

インフラ担当者が最初に見るべき順番はこれです。

1. この資料で **APP-DB の初期構成** を掴む
2. `docs/production-api-checklist.md` で **env の canonical 一覧** を見る
3. `docs/manual/internal/10-server-migration.md` で **cutover 手順** を見る
4. `docs/manual/internal/05-operations.md` で **日々の運用と鍵管理** を確認する

---

## 13. ひとことでいうと

**今回まず作るのは APP-DB の安定した本番構成であり、env は document root 外に置き、DB 内設定・PWA push・mail・uploads まで含めて移行する。その上で将来 Codex を外部の運用支援基盤として接続する。**
