---
feature_id: security-audit
title: セキュリティ監査（敵対的テストカタログ）
chapter: 98
plan: [base]
role: [posla_admin]
audience: internal
keywords: [security, audit, pentest, adversarial, CSRF, XSS, rate-limit, tenant-boundary]
last_updated: 2026-04-21
maintainer: posla
---

# 98. セキュリティ監査（敵対的テストカタログ）

本章は、デモ環境仮完成時点（2026-04-21）の POSLA を**悪意ある攻撃者視点**で精査した結果の記録。本番複製前に Critical 項目の解消を推奨する。

**本章の重要原則**:
- 本章の Critical / High は**実コード + production HTTP で裏取り済み**。
- Medium / Low は agent 指摘を筆者が再評価（確度が担保できないものは §98.8 に分離）。
- `scripts/audit/` 配下に実行可能な検証スクリプトを添付。自動回帰に使える。

---

## 98.0 攻撃者モデル

| モデル | 説明 |
|---|---|
| **a**: 外部匿名 | 未ログイン、URL と QR しか知らない |
| **b**: 悪意あるスタッフ / manager | 正規ログイン、限定ロール |
| **c**: 悪意ある他テナント owner | 別 tenant_id の正規 owner、URL パラメータを差し替えられる |
| **d**: 乗っ取られた device ロール端末 | 正規ログイン、visible_tools 制限、物理的に店舗にある |

## 98.1 重要度定義

- **Critical**: 本番運用前に必ず修正。金銭直接損失 / 個人情報漏洩 / テナント境界破壊 / 認証情報流出
- **High**: 本番運用前に修正推奨。実運用で遭遇する / 手作業リカバリ不可
- **Medium**: 運用開始後 30 日以内に改善
- **Low**: nice-to-have / hardening / 監視強化

## 98.2 検出件数サマリ

| 重要度 | 件数 | 本章での採番 |
|---|---|---|
| Critical | **3** | C-01〜C-03 |
| High | **14** | H-01〜H-14 |
| Medium | **18** | M-01〜M-18 |
| Low（記録のみ） | 10 | L-01〜L-10 |
| 既防御 / 誤検知（reject）| 12 | §98.9 参照 |

---

## 98.3 Critical — 本番複製前に必ず修正

### C-01 `scripts/output/` が非認証で public アクセス可能 🚨

- **攻撃者**: a
- **対象**: `https://eat.posla.jp/scripts/output/helpdesk-prompt-internal.txt` / `error-codes.json` / その他
- **実測**: `curl -I https://eat.posla.jp/scripts/output/helpdesk-prompt-internal.txt` → **HTTP 200**
- **漏洩内容**:
  - `helpdesk-prompt-internal.txt` (1.4MB): 内部マニュアル全編、運用手順、トラブルシュート、決済フロー、DB 構造、認証仕様、エラーコードマップの全文
  - `error-codes.json`: API エラーコード完全マップ（endpoint × HTTP status × error code）
- **想定被害**:
  - 内部仕様の完全リバースエンジニアリング
  - API 攻撃の高精度化（error code から存在有無を識別しやすい）
  - 競合他社・攻撃者による戦略分析の無償提供
- **再現コマンド**: `scripts/audit/05-static-scan.sh` で確認
- **修正**: `scripts/.htaccess` 新設:
  ```
  Require all denied
  ```
  あるいは `public/.htaccess` の `RewriteRule` で `/scripts/` を 404 化
- **確信度**: 高（実測確認済）

### C-02 `public/docs-internal/` が Basic 認証なしで公開されている 🚨

- **攻撃者**: a
- **対象**: `https://eat.posla.jp/public/docs-internal/internal/*.html`
- **実測**: `curl -I https://eat.posla.jp/public/docs-internal/internal/09-pwa.html` → **HTTP 200**、`WWW-Authenticate` ヘッダなし
- **前提との乖離**: MEMORY.md に「docs-internal (Basic認証で保護)」と明記。実態と不一致
- **漏洩内容**: POSLA 社内運営マニュアル 38 章（POSLA admin 手順 / 決済経路 / Stripe Connect / emergency / partial_multi stop 判断 / §9.36〜§9.40 全部）
- **想定被害**: 内部運営フロー全公開、POSLA 運営側の対応手順が攻撃シナリオに組み込める
- **再現コマンド**: 同上
- **修正**: `public/docs-internal/.htaccess` 新設:
  ```
  AuthType Basic
  AuthName "POSLA Internal"
  AuthUserFile /home/odah/.htpasswd-internal
  Require valid-user
  ```
  または Sakura コントロールパネルのディレクトリアクセス制限
- **確信度**: 高（実測確認済）

### C-03 `scripts/` 配下の PHP ビルドスクリプトが public 経由で到達可能 🚨

- **攻撃者**: a
- **対象**: `https://eat.posla.jp/scripts/build-helpdesk-prompt.php`
- **実測**: `curl -I ...` → **HTTP 200**
- **想定被害**: ビルドスクリプトのソースコード読取り / 実行経路があれば internal knowledge base 再生成トリガー / DoS
- **補足**: `scripts/*.py` 系（generate_error_catalog / enrich_error_catalog / generate-sales-pitch-pptx）も同様のリスク
- **修正**: C-01 と同じ `.htaccess` で `/scripts/` 全体 deny
- **確信度**: 高（実測確認済）

---

## 98.4 High — 本番複製前に修正推奨

### H-01 `api/.htaccess` に DB credentials が平文で記載（repo に含まれる）⚠ **Phase 1+3+4 resolved 2026-04-22 / Phase 2 deferred**

- **攻撃者**: c（repo 閲覧権限ある開発者含む）
- **対象**: `meal.posla.jp(正)/api/.htaccess:3-6`
- **実測**: `SetEnv POSLA_DB_PASS odah_eat-posla` が正本 repo に**実値**で格納
- **Web 露出**: `curl /api/.htaccess` → HTTP 403（apache FilesMatch で保護）なので**外部公開は防御済**
- **残リスク**: repo access 権限者（協業者・今後入る人）に DB 認証情報が全公開

#### 解消内容（Phase 1: repo 衛生化、runtime 停止なし）

1. **NEW** `meal.posla.jp(正)/api/.htaccess.example` — placeholder values のテンプレート、commit 対象（新規 dev セットアップ手順を明記）
2. **EDIT** `.gitignore` に `api/.htaccess` 追加 — 以後の commit で credentials 混入を遮断
3. `git rm --cached api/.htaccess` — git index から除外（ファイル実体は local dev 用に残置、untracked）
4. **NO CHANGE**: server `/home/odah/www/eat-posla/api/.htaccess` — production env var 供給源、連続稼働
5. **NO CHANGE**: `api/config/database.php` fallback 値 — 別スライスで placeholder 化予定（env 必須化による runtime リスク回避）

#### production 非回帰（実測）

- `GET /api/monitor/ping.php` → `{"ok":true,"db":"ok"}` DB 接続健全性維持
- Critical 3（C-01/C-02/C-03）全て 403 維持
- HSTS / login rate-limit / Connect callback CSRF 全て不変

#### Phase 3（2026-04-22 午後）: sandbox DB password rotation ✅ resolved

- MySQL 8.0.44 の `odah_eat-posla@%` に対し `SET PASSWORD = '<new>'` で rotation 実行
- 新 password: 48 hex chars（`bin2hex(random_bytes(24))` で server 上生成、shell-safe）
- 旧 password `odah_eat-posla` は production DB 上で無効化確認（ERROR 1045 Access denied）
- 新 password は server `/home/odah/www/eat-posla/api/.htaccess` + local untracked `meal.posla.jp(正)/api/.htaccess` の 2 箇所にのみ保管（chmod 604 / 400）
- **production 非回帰**: `/api/monitor/ping.php` → `{"ok":true,"db":"ok"}` 継続、Critical 3 / HSTS / login rate-limit / Connect callback 全て不変
- 不整合ウィンドウ: `SET PASSWORD` と `mv .htaccess.new` の間 ~1 秒、実測で production ping は成功応答
- docs / scripts/output / bash history / chat log いずれにも新 password 平文を残さない（MYSQL_PWD env var + 直接 sed 経由で処理）
- backup: `_backup_20260422_h01_rot/htaccess-pre-rotation.txt`（旧 `.htaccess`）+ `rotation-record.txt`（タイムスタンプのみ、password なし）

#### Phase 4（2026-04-22 午後）: `database.php` fallback placeholder 化 ✅ resolved

- `meal.posla.jp(正)/api/config/database.php` の fallback 値を `'odah_eat-posla'` / `'mysql80.odah.sakura.ne.jp'` から `''` へ変更（4 key × 2 branch = 8 箇所）
- コメント内の `POSLA_DB_PASS odah_eat-posla` 2 件を `<your-rotated-password>` placeholder に変更
- header comment の `POSLA_DB_NAME=odah_eat-posla` / `POSLA_DB_USER=odah_eat-posla` は DB **名**と **username** への legitimate 言及として残置（Sakura 慣例の identifier、password ではない）
- env var 未設定時は `''` で connection 失敗 → サイレント稼働せず明示的エラー
- `php -l` OK
- **git tracking 状態**: `meal.posla.jp(正)/api/config/database.php` は `.gitignore:14` で ignore 設定済、`git ls-files` 空で **tracked ではない**。従って今回の placeholder 化は **local 作業コピー限りの変更**で、repo 履歴（HEAD/過去 commit）には影響しない

#### ⚠ 例外メモ: local / server 同一性の一時的乖離（2026-04-22 時点）

今回 Phase 4 は **local 側のみ placeholder 化**し、server `/home/odah/www/eat-posla/api/config/database.php` は touch していない。両ファイルは独立した gitignored copy であり、内容が一時的に異なる:

| location | fallback 値 | runtime 影響 |
|---|---|---|
| local `meal.posla.jp(正)/api/config/database.php` | `''` placeholder | local dev は POSLA_ENV=local 分岐で docker-compose env var を読む、fallback は発火しない |
| server `/home/odah/www/eat-posla/api/config/database.php` | `'mysql80.odah...'` / `'odah_eat-posla'`（旧 pw 含む）のまま | production は .htaccess SetEnv 経由で新 pw を供給、fallback は発火しない（envOr は env var 優先）|

**security 観点**: server 側 fallback の旧 password `odah_eat-posla` は **既に Sakura DB で失効**しているため、仮に fallback が万一発火しても Access denied で即失敗する。**機能上の差分も security blocker も無い**。

**将来の正規化**: 次回 api/ 系の通常デプロイで server の database.php も placeholder 版に揃える（単純上書き）。env var が既に正しく供給されているので runtime 影響なし。

- **production 非回帰**: `/api/monitor/ping.php` → `db:"ok"` 継続

#### Phase 2（履歴 cleanup）: 別スライス保留

- **理由**: `git filter-repo` は force-push と既存 clone への影響調整が必要、別 slice で実施
- **重要**: rotation 済なので、commit `57f3848` に残っている旧 credential は既に**無効化済**。漏洩リスクの severity は大幅低下
- **前提条件**: runtime secret rotation は Phase 3 で完了（Phase 2 の blocker 解除）

#### 他の `odah_eat-posla` 文字列が含まれる tracked ファイル（別論点）

- `docker-compose.yml` / `docker/README.md` / `docker/db/init/01-schema.sh` — Docker local dev 用、DB 名と**旧 password が同値**だったため Docker ローカル環境は rotation で影響。local dev 再セットアップ時は .htaccess.example / docker-compose.override.yml で新 password を提供
- `docs/manual/internal/**.md` + `public/docs-internal/**.html` + `scripts/output/helpdesk-prompt-internal.txt` — DB **名**（`odah_eat-posla`）への言及、password として同値だった Sakura の慣例を記録。旧 password 言及は **docs 更新時に placeholder 化推奨**（別スライス）
- `sql/migration-*.sql` — 主に DB 名言及、password は含まれない想定（grep 確認は別スライス）

- **確信度**: 高（rotation + placeholder 化 + 非回帰確認すべて完了。Phase 2 は security 実害 mitigated 後の衛生化として別スライス実施）

### H-02 `api/auth/login.php` に brute force rate limit なし ✅ **resolved 2026-04-21**

- **攻撃者**: c
- **対象**: `api/auth/login.php` 全体
- **解消内容**: `require_once '../lib/rate-limiter.php'` + `check_rate_limit('login', 10, 300);` を `require_method(['POST']);` 直後に追加（1IP あたり 10 回 / 5 分）
- **非回帰確認（production 実測）**: GET → 405 / POST 空 body → 400 MISSING_FIELDS / POST 不在 user → 401 INVALID_CREDENTIALS、既存 response 形式不変
- **未対応**: 失敗時 `write_audit_log` での brute force 追跡（別スライス候補）
- **backup**: `_backup_20260421_highfix/login.php`

### H-03 15+ customer endpoint で rate-limit 未適用 ✅ **resolved 2026-04-22（webhook 1 件を設計上意図除外）**

- **攻撃者**: a
- **対象**: `api/customer/*.php` 全件

#### Phase 1（2026-04-22 午前、6 件）: ブルート/DoS 高リスク
- `validate-token.php`: 60/300（ポーリング想定）
- `receipt-view.php`: 20/300（payment_id ブルート、30 分 TTL 窓内）
- `get-bill.php`: 30/300（session_token 推測）
- `satisfaction-rating.php`: 10/300（評価スパム / low_rating Push 詐称）
- `takeout-payment.php`: 30/300（決済確認ポーリング濫用）
- `checkout-session.php`: 10/300（Checkout Session 大量作成 DoS）

#### Phase 2（2026-04-22 午後、8 件）: 残 customer endpoint
- `menu.php`: 60/300 / `order-history.php`: 30/300 / `checkout-confirm.php`: 20/300 / `order-status.php`: 60/300 / `reservation-availability.php`: 30/300 / `reservation-detail.php`: 30/300 / `reservation-precheckin.php`: 30/300 / `ui-translations.php`: 60/300

#### 意図的除外 1 件
- `reservation-deposit-webhook.php`: Stripe webhook。`verify_webhook_signature()` HMAC で保護、rate-limit は legitimate retry を壊す副作用が大きい

#### 注
- `reservation-update.php` は audit 時点で既に適用済（line 16 に `check_rate_limit('reserve-update:' . IP, 10, 60)`）。Agent D 指摘が 1 件過大だった
- 適用済 customer endpoint: **25 / 26**（webhook 1 件意図的除外）

#### production 実測（Phase 2）
各 endpoint 正常応答（200/400/403/404）、50x なし、既存バリデーション不変

- **backup**: `_backup_20260422_hardening/`（Phase 1）、`_backup_20260422_h034_14/`（Phase 2）
- **確信度**: 高

### H-04 POST API 群で CSRF 対策が SameSite=Lax のみ ⚠ **partial-resolved 2026-04-22（admin state-changing subset 閉じる）**

- **攻撃者**: c
- **対象**: `api/store/**.php` POST / `api/customer/*.php` POST

#### Phase 1（2026-04-22 午前）: helper 追加 + 1 endpoint 適用
- `api/lib/auth.php` に新 helper `verify_origin()`。Origin 優先、Referer fallback、両方なしは通過（curl / サーバー間連携対応）、不一致は `FORBIDDEN_ORIGIN` 403
- `api/owner/users.php` DELETE に opt-in 適用

#### Phase 2（2026-04-22 午後）: admin state-changing 6 endpoint に拡張
追加 opt-in 適用:
- `api/store/payment-void.php` POST（金銭、require_auth の前に verify_origin）
- `api/store/refund-payment.php` POST（金銭、require_role の前に verify_origin）
- `api/store/emergency-payment-transfer.php` POST（緊急会計→payments 転記）
- `api/store/emergency-payment-manual-transfer.php` POST（手入力売上計上）
- `api/owner/users.php` POST（user 作成）
- `api/owner/users.php` PATCH（user 更新）

合計適用 endpoint: **7 箇所**（DELETE 既存含む）

#### production 実測（悪意 Origin spoof テスト）
```
POST /api/store/payment-void.php  + Origin: attacker.example.com → 403 FORBIDDEN_ORIGIN ✅
POST /api/store/refund-payment.php + Origin: attacker.example.com → 403 FORBIDDEN_ORIGIN ✅
POST /api/owner/users.php + Origin: attacker.example.com → 401（require_role 先行、設計通り）
POST /api/store/payment-void.php + Origin: eat.posla.jp → 401 UNAUTHORIZED（auth で弾く、正常）
```

CSRF 攻撃経路（authenticated session + attacker.com Origin）は全て 403 で遮断される設計。

#### 残り（別スライス）
- 全 customer POST / PATCH 自動適用: 既存 QR / PIN 経路の Origin 付与有無の互換性テスト要、別スライス
- 全 emergency-payment 系（残 5 件）への適用: 同様の opt-in で次スライス可能
- SameSite=Lax → Strict 変更: owner-dashboard の外部開き動線への影響確認要

- **backup**: `_backup_20260422_hardening/`（Phase 1）、`_backup_20260422_h034_14/`（Phase 2）
- **確信度**: 高

### H-05 session_token / QR トークンに rotation / IP pinning なし ⚠ **deferred 2026-04-22（別スライス、staging 必須）**

- **攻撃者**: a
- **対象**: `api/customer/table-session.php` / 各 api/customer の session_token 検証
- **現状**: `bin2hex(random_bytes(16))` で 128bit エントロピー、TTL 4 時間、rotation なし、IP / User-Agent pinning なし
- **想定被害**: QR 撮影 / 同 Wi-Fi の盗聴で session_token 取得 → 別端末から 4 時間利用可
- **deferred 理由**: H-06 / H-13 と一緒に処理するには影響範囲が大きい
  - schema 追加: `tables.session_token_seen_ua` / `session_token_seen_ip` / `session_token_last_used_at`
  - app 変更: `api/customer/*.php` の session_token 検証 10+ endpoint に pinning ロジック
  - UX 影響: QR 経由顧客の別端末タップで session 切断される可能性（相席 / 家族間 QR 共有の挙動再定義が必要）
  - 運用: rotation で「古いタブ」が突如 403 になる挙動の tenant 向け docs 説明が必要
- **推奨 sub-slice 分割**:
  - Phase 1: schema 追加のみ（columns 追加、app は未使用 = no-op）
  - Phase 2: 最初のアクセス時に UA/IP を記録（観測のみ、拒否しない）
  - Phase 3: 不一致時の 403 拒否（staging 相当環境で QR / 相席 / 長時間滞在を回帰テスト）
  - Phase 4: 毎 POST で token rotation（最も影響大、慎重に）
- **確信度**: 高（仕様設計 + staging 必須）

### H-06 audit_log テーブルが append-only 化されていない ✅ **resolved 2026-04-22**

- **攻撃者**: b（DB 直叩き可能な悪意ある admin）
- **対象**: `audit_log` テーブル（DB schema）
- **解消内容**:
  - NEW `sql/migration-h06-audit-log-append-only.sql` を作成・production 適用
  - `audit_log_no_update` BEFORE UPDATE trigger で `SIGNAL SQLSTATE '45000'` 発行
  - `audit_log_no_delete` BEFORE DELETE trigger で同様
  - app 層は INSERT のみ使用、runtime 影響なし（既存 `audit-log.php` / CP1 / 4d-5 系の INSERT 路線は不変）
- **production 実測（2026-04-22、本番影響は管理可能な最小限）**:
  - INSERT → 成功（id=1060 付与、`tenant_id='h06-test'` で test row を 1 件追加）
  - UPDATE → **ERROR 1644 (45000): audit_log is append-only: UPDATE denied (H-06)** ✅
  - DELETE → **ERROR 1644 (45000): audit_log is append-only: DELETE denied (H-06)** ✅
  - 1,059 既存 rows 不変、append されたのは test row 1 件のみ
- **rollback**: `DROP TRIGGER IF EXISTS audit_log_no_update; DROP TRIGGER IF EXISTS audit_log_no_delete;`
- **test 痕跡**: id=1060 / `tenant_id='h06-test'` / `action='test_insert'` の 1 行が audit_log に**意図的に残存**（trigger invariant「一度書いたら消えない」を守るため cleanup しない、test tag で識別可）
- **将来 cleanup**: GDPR 等で legitimate delete が必要な場合、rollback SQL で trigger 外して cleanup 後 reapply
- **backup**: `_backup_20260422_h06_h13/audit_log_backup.tsv`（1,059 rows の TSV 形式 snapshot）
- **確信度**: 高

### H-07 `tenants.stripe_secret_key` が DB 平文保存

- **攻撃者**: b（DB dump 奪取）
- **対象**: `sql/schema.sql` の `tenants` テーブル
- **現状**: Stripe secret key が平文 VARCHAR で保存、KMS / アプリ層暗号化なし
- **想定被害**: DB backup / dump が漏れた時点で全テナントの Stripe 決済乗っ取り可能
- **修正**:
  - 短期: DB backup を暗号化 + backup permission 最小化
  - 中期: `openssl_encrypt` で platform key 暗号化 + env 変数で復号 key
  - 長期: Pattern B（Stripe Connect）に統一し `stripe_connect_account_id` のみ保持、secret key は platform 側のみ
- **確信度**: 高

### H-08 Web Push VAPID 秘密鍵が `posla_settings` に平文保存

- **攻撃者**: c（POSLA admin 奪取）
- **対象**: `posla_settings.setting_value` where `setting_key='web_push_vapid_private_pem'`
- **現状**: PEM 平文、約 241 char
- **想定被害**: 全テナントの push 通知を詐称、phishing 配布
- **修正**: H-07 と同じ方針。短期は POSLA admin の 2FA + session IP 固定
- **確信度**: 高

### H-09 `idempotency_key` がクライアント生成（予測可能）

- **攻撃者**: b
- **対象**: `api/customer/orders.php` 等で client が生成した `idempotency_key` を信頼
- **現状**: 値の検証は UNIQUE 制約のみ、client が `"1"` `"2"` などで送ると conflict が発生
- **想定被害**: 他顧客の idempotency_key と衝突させて注文失敗を誘発 / 注文履歴推測
- **修正**: server 側で UUID を自動付与、client 側送信は「再送重複チェック用ヒント」扱いに変更
- **確信度**: 中

### H-10 `api/connect/callback.php` に state parameter / HMAC なし（CSRF）✅ **resolved 2026-04-22**

- **攻撃者**: c
- **対象**: `api/connect/callback.php:15`
- **解消内容**:
  - `api/connect/onboard.php`: `require_role('owner')` で既にアクティブな session に `bin2hex(random_bytes(16))` で 32 桁 state token を発行、`$_SESSION['connect_state_' . $tenantId]` に保存、callback URL の `state` param に付与
  - `api/connect/callback.php`: `start_auth_session()` で session 継続、`$_GET['state']` と session 値を `hash_equals` 照合、失敗時は owner-dashboard へ `connect=state_error` でリダイレクト、成功時は session から state を削除（one-time use）
- **production 実測**:
  - state なし → HTTP 302 Location: `.../connect=state_error` ✅
  - 偽造 state → HTTP 302 Location: `.../connect=state_error` ✅
  - CSRF `<img src="...callback.php?tenant_id=X">` 経路は state 検証で完全遮断
- **backup**: `_backup_20260422_hardening/connect-callback.php` / `connect-onboard.php`
- **確信度**: 高

### H-11 HSTS ヘッダが未設定 ✅ **resolved 2026-04-21**

- **攻撃者**: a
- **対象**: 全 HTTPS 応答
- **解消内容**: NEW `meal.posla.jp(正)/.htaccess` に `<IfModule mod_headers.c>` 内で `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"` を配置（preload なし）
- **production 実測**: `/` / `/public/admin/index.html` / `/api/auth/me.php` / `/api/auth/login.php` / `/public/docs-tenant/` / `/api/customer/menu.php` の全 6 URL で `strict-transport-security: max-age=31536000; includeSubDomains` が header に付与確認
- **未対応**: preload list 登録（恒久対策側で判断）
- **backup**: 不要（新規 .htaccess 追加のみ）

### H-12 `api/owner/users.php` の DELETE 権限境界が未確認 ✅ **resolved 2026-04-22（誤検知確定）**

- **攻撃者**: c
- **対象**: `api/owner/users.php` DELETE handler
- **検証結果（実コード read）**: **境界は完全防御済**。DELETE handler（line 302-336）が以下を実装:
  - line 305: `CANNOT_DELETE_SELF` — 自己削除防止
  - line 307: `WHERE id = ? AND tenant_id = ?` — テナント境界
  - line 313-323: manager の場合、`role === 'staff'` 以外は 403、さらに `user_stores.store_id` の交差確認（P1b-1 で追加）
- **判定**: Agent B の「未検証」指摘は実コード未読の推測。tenant owner が自己組織内の owner/manager/staff を削除できる仕様は妥当（POSLA admin と tenant owner は別レイヤー）
- **追加 hardening**: H-04 の `verify_origin()` opt-in 適用で CSRF 層を追加
- **確信度**: 高（実コード読破）

### H-13 Stripe `external_payment_id` に UNIQUE 制約なし ✅ **resolved 2026-04-22**

- **攻撃者**: b（race 条件悪用）
- **対象**: `payments.external_payment_id` column
- **現物確認（2026-04-22）**:
  - 17 payments に ext_id 設定、17 distinct → **duplicate 0 件** ✅
  - 100 payments は NULL（cash / 手動 card / qr）
- **解消内容**:
  - NEW `sql/migration-h13-payments-ext-id-unique.sql` を作成・production 適用
  - `ALTER TABLE payments ADD UNIQUE KEY uq_external_payment_id (external_payment_id)`
  - MySQL InnoDB の UNIQUE は複数 NULL を許容、cash/manual 100 件に影響なし
- **production 実測（2026-04-22）**:
  - `SHOW INDEX FROM payments` で `uq_external_payment_id` 追加確認
  - 既存 17 rows duplicate violation なし（cardinality=18 で正常）
  - 同一 ext_id で 2 回 INSERT → 2 回目 **ERROR 1062 Duplicate entry 'pi_TEST_UNIQ_H13' for key 'payments.uq_external_payment_id'** ✅
  - test row は cleanup 済（remaining=0）
  - NULL は複数許容（cash/manual 100 件継続）
- **app 層の挙動**:
  - `checkout-confirm.php:179-190` / `takeout-payment.php` の早期 idempotent return は維持（DB 到達前に重複検出、既存 UX 維持）
  - DB UNIQUE は TOCTOU race で app 層を擦り抜けた 2 並列 INSERT を最終防御層として弾く
- **rollback**: `ALTER TABLE payments DROP INDEX uq_external_payment_id;`
- **backup**: `_backup_20260422_h06_h13/payments_ext_id_backup.tsv`（17 rows snapshot）
- **確信度**: 高

### H-14 エラー応答に内部情報が混入する経路 ⚠ **partial-resolved 2026-04-22（public/non-admin subset 解消）**

- **攻撃者**: a
- **対象**: 多数の `json_error('DB_ERROR', '...', 500)`

#### Phase 1: PHP レベル遮断（2026-04-22 午前）

- root `.htaccess` に PHP エラー設定を明示: `php_flag display_errors Off` / `php_flag log_errors On` / `php_flag display_startup_errors Off`
- `mod_php` / `mod_php7` / `mod_php8` の 3 パターンで `<IfModule>` 分岐（Sakura 環境依存吸収）
- PHP 自体の warning / notice / fatal / startup error の browser 表示を遮断

#### Phase 2: アプリレベル — public/non-admin reachable 6 endpoint の getMessage 除去（2026-04-22 午後）

`$e->getMessage()` を `json_error` のメッセージ文字列に連結している 24 箇所のうち、**`require_auth()` or `require_role('staff')` のみで device/staff reachable な 6 endpoint** を優先修正:

| # | endpoint | role 境界 | 既存 error_log | 対応 |
|---|---|---|---|---|
| 1 | `api/kds/orders.php:33` | `require_auth()`（device/staff KDS polling 3s 間隔）| なし | error_log 追加 + 固定文言 |
| 2 | `api/kds/close-table.php:137` | `require_auth()`（deprecated, device/staff）| なし | error_log 追加 + 固定文言 |
| 3 | `api/store/tables-status.php:30` | `require_auth()`（device/staff polling）| なし | error_log 追加 + 固定文言 |
| 4 | `api/store/last-order.php:90` | `require_auth()` | なし | error_log 追加 + 固定文言（migration hint は維持）|
| 5 | `api/store/receipt.php:344` | `require_auth()`（cashier）| 既存 `[L-5 receipt]` | 既存 error_log 維持、browser message から getMessage 削除 |
| 6 | `api/store/reservation-seat.php:94` | `require_role('staff')` | 既存 `[L-9][reservation-seat]` | 既存 error_log 維持、browser message から getMessage 削除 |

#### UI 側 caller 安全性確認

`public/kds/*.js` / `public/admin/*.js` の `json.error.message` 参照 20 箇所を grep で確認、全て toast 表示 / throw Error の汎用テキストとして利用され、**メッセージ文字列をパースしている caller はゼロ**。固定文言への差し替えは安全。

#### production 実測

6 endpoint に対し未認証 GET で 401 / 405 / 400 の期待ガード応答を確認、500 応答での内部情報漏洩経路なし。Critical 3 / HSTS / login rate-limit / Connect CSRF は全て不変。

#### Phase 3 (2026-04-22 午後): admin-only 17 件を解消

admin 認証済のみ到達可能な subset を一括除去:
- **CSV import 8 件**: `api/owner/{ingredients,menu,recipes}-csv.php` / `api/store/{course,local-items,menu-overrides,plan-menu-items,time-limit-plans}-csv.php`（全て `require_role('manager')`）
- **Emergency payment 7 件**: `emergency-payment-{external-method,manual-transfer,manual-void,resolution,transfer-void,transfer}.php` / `emergency-payments.php`（内部で manager|owner role check）
- **Admin-like 2 件**: `payment-void.php:396`（line 76 で manager/owner role 確認）/ `shift/help-requests.php:387`（line 170 で `require_role('manager')`）

全 17 件で `getMessage()` 連結部を削除、`error_log()` 追加で内部調査性維持。本 subset は UI caller パース依存なしを前 slice で確認済（grep 20 箇所、全て汎用 toast/throw）。

#### 残り 1 件（意図的除外）
- **`api/store/translate-menu.php:90`**: `TranslateMenuException` という custom domain exception 経由で `$e->getMessage()` を返す。messages は domain-level で user-facing 意図的設計（翻訳エラー詳細を UI に表示する）、raw PDO ではないため内部情報漏洩リスクなし。**今後も除去対象にしない**

#### production 実測（Phase 3）
- 全 17 endpoint 未認証 POST → 401/403（既存 auth/role guard 維持）
- Critical 3 / HSTS / login rate-limit / Connect CSRF 全て不変
- 全体 `json_error...getMessage` 件数: 24 → **1**（translate-menu のみ、意図的除外）

- **backup**: `_backup_20260422_hardening/`（Phase 1）、`_backup_20260422_h14/`（Phase 2 non-admin 6 件）、`_backup_20260422_h034_14/`（Phase 3 admin-only 17 件）
- **最終確信度**: 高（public/admin 全路線で getMessage 混入解消、残 1 件は意図的 domain exception）

---

## 98.5 Medium — 運用開始後 30 日以内に改善

### M-01 `api/kds/update-status.php` が `'paid'` 値を受け付ける

- **既知**: §9.38 で defense-in-depth 候補として記録済
- **対象**: `api/kds/update-status.php:30` `$validStatuses` に `'paid'` 含む
- **現状**: UI は送らないが device ロール乗っ取り時に任意 order を paid 化可能（手で POST）
- **修正**: `$validStatuses` から `'paid'` を削除（cancelled 含む他の終端 status も検討）
- **確信度**: 高

### M-02 `api/kds/close-table.php` が deprecated だが 404 化されていない

- **既知**: §9.38
- **修正**: ファイル先頭で `http_response_code(410); echo 'Gone'; exit;` を追加
- **確信度**: 高

### M-03 `scripts/p1-27-generate-torimaru-sample-orders.php` が本番に存在する可能性

- **既知**: §9.38 で 7,430 件 demo seeder の発生源
- **修正**: 本番 deploy 除外 / 実行防止フラグ / 最初に `die('disabled')` を仕込む
- **確信度**: 高

### M-04 phone + order_id enumeration（takeout）

- **対象**: `api/customer/takeout-orders.php?action=status`
- **想定被害**: phone（10-11 桁）を固定すれば、UUID と組み合わせで他顧客 takeout 状態を覗ける可能性
- **修正**: rate-limit 追加（phone hash 単位）+ phone ではなく sms で verify
- **確信度**: 中

### M-05〜M-18 省略（別添 `scripts/audit/` 内の comment に詳細）

詳細項目:
- M-05: `cart-event.php` 保存データの consumer 追加時の XSS 露呈リスク
- M-06: `places-proxy` の tenant-level audit log なし
- M-07: sub-session token の single-use 化未実装
- M-08: rate-limiter file が `/tmp` 配置で systemd-tmpfiles により reset リスク
- M-09: Open redirect risk（checkout success_url の Host header 信頼）
- M-10: order.items JSON の taxRate 改ざん path（S3#4 で再計算済）
- M-11: receipt orphan（void 後の領収書再印刷）
- M-12: plan_menu_items の store_id 境界検証 agent B 未確認
- M-13: `reservation-availability.php` 無制限スキャン DoS
- M-14: `customer-phone` / `note` の入力 sanitize 強化
- M-15: `satisfaction-rating.reason_text` 出力側 escape 検証
- M-16: process-payment 同時 POST race（`SELECT ... FOR UPDATE` 済だが担当 PIN 検証 race）
- M-17: refund-payment claim revert の idempotency
- M-18: push subscription の scope=owner 詐称（P1a / Phase 2b で部分対応済、残存リスク評価）

---

## 98.6 Low（記録のみ、運用で観測・改善）

- L-01〜L-10: agent 指摘のうち防御既存 / 実害極小 / 設計上許容のもの（`scripts/audit/` の各スクリプト comment に記載）

---

## 98.7 攻撃者モデル別のテストシナリオ

### a: 外部匿名攻撃者

1. C-01/C-02/C-03 の直接 GET（internal docs / error catalog 入手）
2. QR 撮影 → session_token 取得 → 4 時間別端末から注文（H-05）
3. `/api/auth/login.php` へ 1 万回 POST（H-02）
4. rate-limit なし endpoint の全自動スキャン（H-03）

### b: 悪意あるスタッフ / manager

1. `api/store/staff-management.php` で他店舗 user への DELETE（既防御）
2. `payments.stripe_secret_key` を DB 直接 SELECT（H-07）
3. audit_log DELETE / UPDATE（H-06）
4. `M-01` device による任意 paid 化

### c: 悪意ある他テナント owner

1. `api/connect/callback.php?tenant_id=OTHER` CSRF（H-10）
2. URL パラメータ `store_id` 差し替え → 多数 API で require_store_access が効くか全網羅
3. `api/owner/users.php` DELETE で他 owner 除去試行（H-12）
4. H-01: repo 閲覧権限経由の DB credentials 入手

### d: 乗っ取られた device 端末

1. `api/kds/update-status.php` で任意 order を paid 化（M-01）
2. KDS 系 API → `visible_tools` 範囲外 endpoint 叩き
3. 店舗内で物理的に QR 撮影 → session_token 大量収集（H-05）
4. POST 経路の rate limit 内での継続攻撃

---

## 98.8 agent 指摘で「未検証」のまま残した項目（再検証推奨）

- plan_menu_items の store_id 境界（Agent B）
- course-templates 権限境界（Agent B）
- `api/store/table-merge-split.php` 権限（Agent B）
- smaregi sync の tenant 境界（Agent B）
- reservation-detail の guest 情報 enumeration（Agent B）
- `api/store/ai-generate.php` prompt injection filter（Agent E）

これらは次フェーズで重点的に read + test。

## 98.9 agent 指摘を本監査で reject / 格下げしたもの（根拠つき）

| agent 指摘 | 筆者判断 | 根拠 |
|---|---|---|
| Agent C: Stripe webhook signature validation なし（High）| **既防御 ✅** | `api/subscription/webhook.php:37` で `verify_webhook_signature()` 呼び出し確認 |
| Agent D: cart-event.php item_name XSS（Critical）| **経路なし → Low** | `cart_events` テーブルは insert-only で consumer 側 UI 描画パスなし |
| Agent E: cron endpoint 認証なし（High）| **既防御 ✅** | `api/cron/*.php` 全件 `php_sapi_name() !== 'cli'` で HTTP 経由 403 返却 |
| Agent E: localStorage に stripe_secret_key（Critical）| **誤検知** | `posla-app.js:444-511` は admin 設定画面で server 保存の POST。localStorage 保存なし |
| Agent E: `/api/.htaccess` 直アクセス可（？）| **既防御 ✅** | production HTTP 403（apache FilesMatch `^\.` ） |
| Agent E: `/sql/schema.sql` 公開（？）| **既防御 ✅** | HTTP 404（deploy 対象外） |
| Agent E: `/_backup_*/` 公開（？）| **既防御 ✅** | HTTP 403 |
| Agent B: connect/callback.php auth bypass（Critical）| **格下げ → H-10 High** | Stripe 側で charges_enabled + details_submitted 検証するため DB UPDATE は「本当に onboarding 完了した時のみ」実行。CSRF risk は残るが arbitrary manipulation ではない |
| Scanner: SQL concat 4 件（Medium）| **reject ✅** | 全 4 件（`order-history.php:48` `error-stats.php:59` `reservations.php:52` `audit-log.php:71`）とも `PDO::prepare` + `?` placeholder の canonical 安全パターン。`$where` / `$whereSql` / `$place` / `$whereClause` は hardcoded fragment の連結 / `array_fill(0, count($x), '?')` IN 展開のみで、user input は全て `$params` 経由で bind |
| Scanner: innerHTML 439 箇所（Medium）| **大半 false positive** | ES5 codebase で `Utils.escapeHtml()` 経由の escape がテンプレート文字列内に埋まる形式（例: `'<span>' + Utils.escapeHtml(x) + '</span>'`）が多数、grep が逐語一致できない。個別レビュー推奨だが実害は極小 |

---

## 98.10 検証自動化

`scripts/audit/` ディレクトリに実行可能なスクリプトを配置（§ scripts/audit/README.md 参照）。主要な自動回帰:

- `01-auth-boundary.sh`: tenant 越境 POST の拒否確認
- `02-money-path.sh`: void / refund の重複リクエスト冪等性
- `03-customer-surface.sh`: QR / PIN / session rotation 確認
- `04-rate-limit.sh`: 各 endpoint の rate limit 発動確認
- `05-static-scan.sh`: 本章の C-01/C-02/C-03 + php -l + 禁則 grep（hardcoded secret / SQL concat / unescaped HTML）

## 98.11 修正優先順位（推奨）

1. **即時（Critical 3 件 + H-01）**:
   - C-01: `scripts/.htaccess` で deny
   - C-02: `public/docs-internal/.htaccess` に Basic auth
   - C-03: C-01 で包含
   - H-01: DB password rotation + `.htaccess.example` 化 + git filter-repo
2. **今週中（High 残）**: H-02〜H-14
3. **本番複製前最終チェック**: 本章 §98.10 の 5 スクリプトを CI 化

---

## 98.12 更新履歴

- **2026-04-21**: 初版。5 分野並列 Explore agent の指摘を実コード + production HTTP で裏取り、Critical 3 / High 14 / Medium 18 / Low 10 / Reject 12 に整理。重要度の根拠を全項目に明記。本番複製前の P0 は 3 件（C-01/C-02/C-03）。
