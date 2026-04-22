---
feature_id: I-1
title: 運用監視エージェント (Phase 1)
chapter: 07
audience: POSLA 運営チーム (内部)
keywords: [監視, 異常検知, Slack, UptimeRobot, cron]
related: [06-troubleshoot]
last_updated: 2026-04-22
maintainer: POSLA運営
---

# 7. 運用監視エージェント (I-1 Phase 1)

POSLA 本番運用で、サーバーやアプリケーションの異常を検知して **Slack + メール** でアラート通知する仕組み。

::: warning これは社内向け資料です
テナント (飲食店オーナー) は読まなくて OK。POSLA 運営チームの運用手順です。
:::

::: warning ⚠️ 現行 sandbox 環境前提の具体例

本章の cron 登録手順・PHP パス・ログ path は **現行 sandbox 環境（さくらのレンタルサーバ、`/home/odah/www/eat-posla/` / `/home/odah/log/php_errors.log` / `/usr/local/bin/php` / Sakura コンパネの Cron 画面）を前提とした実例**です。監視ロジック（正系 cron / 副系 uptime probe / Slack + メール通知）は vendor-neutral です。本番インフラが決まったら具体値を読み替えてください。

`support@posla.jp` は **POSLA 運営窓口** の暫定メールアドレス。本番運用時には適切な運営メールアドレスに置換してください。
:::

---

## 7.1 アーキテクチャ (AI 停止リスク対応)

2 系統構成で、片方がダウンしても他方で検知:

### 正系: サーバー内 cron（現行 sandbox 参考）

- 5 分毎に `api/cron/monitor-health.php` を実行
- PHP エラーログ / Stripe Webhook / メール送信失敗を検知
- 異常時: monitor_events に記録 + Slack 通知 + (連続 3 件で) 運営通知先（support@posla.jp）

### 副系: 外部 uptime サービス

- **UptimeRobot** / **cron-job.org** / **Better Uptime** 等 (外部)
- 5 分毎に `https://eat.posla.jp/api/monitor/ping.php` を HTTP GET
- 応答なし / `ok: false` → POSLA運営窓口（support@posla.jp）に直接アラート
- **Sakura 全体ダウン時** でも外部から検知可能

---

## 7.2 初期セットアップ手順 (Hiro が初回のみ実施)

### Step 1: cron 登録（現行 sandbox 参考）

現行 sandbox（Sakura）の場合: コンパネ → 共有サーバー → Cron 設定。本番インフラでは該当 infra のスケジューラ（crontab / systemd timer / managed cron）に読み替え:

```bash
# 5分毎
*/5 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/monitor-health.php
```

::: warning ⚠ `.htaccess` は cron / CLI で読まれない（重要）
CLI の cron は `api/.htaccess` を読みません。これは現行 sandbox（さくらの共有レンタルサーバ）でも本番 VPS でも共通の制約です。サーバ移行や本番ドメイン切替時は、少なくとも以下を **cron 実行環境（crontab 変数 or wrapper script or systemd EnvironmentFile）** にも別途渡してください:

- `POSLA_DB_HOST` / `POSLA_DB_NAME` / `POSLA_DB_USER` / `POSLA_DB_PASS`
- `POSLA_APP_BASE_URL` / `POSLA_FROM_EMAIL` / `POSLA_SUPPORT_EMAIL`
- `POSLA_ALLOWED_ORIGINS` / `POSLA_ALLOWED_HOSTS`

`POSLA_CRON_SECRET` は **HTTP 経由で `api/cron/monitor-health.php` を叩く場合のみ**必須。CLI 実行だけなら不要。

**本番 VPS での推奨**: `.htaccess` に頼らず **vhost / PHP-FPM pool env / systemd unit EnvironmentFile / env file + wrapper** 等の server-level env 管理を採用する方が、HTTP と CLI の両経路で env を統一でき、運用負荷・監査性ともに優れます。詳細は [5.3.5 env 供給手段の選択肢](./05-operations.md#535-env-供給手段の選択肢現行-sandbox-vs-本番-vps-推奨) を参照。
:::

### Step 2: Slack Webhook 作成

1. Slack のワークスペース → App → **Incoming Webhooks**
2. チャンネル選択 (例: `#posla-alerts`) → **Add Incoming Webhooks integration**
3. Webhook URL をコピー
4. POSLA 管理画面 → API 設定 → **Slack Webhook URL** に貼り付け (or SQL 直接):

```sql
UPDATE posla_settings SET setting_value = 'https://hooks.slack.com/services/XXX/YYY/ZZZ'
WHERE setting_key = 'slack_webhook_url';
```

### Step 3: 運営メール設定

```sql
UPDATE posla_settings SET setting_value = 'support@posla.jp'
WHERE setting_key = 'ops_notify_email';
```

### Step 4: UptimeRobot (副系) 登録

1. UptimeRobot アカウント作成 (無料)
2. **New Monitor** → Type: `HTTP(s)`
3. Friendly Name: `POSLA Health`
4. URL: `https://eat.posla.jp/api/monitor/ping.php`
5. Monitoring Interval: `5 minutes`
6. Alert Contacts: Hiro個人メール + Slack
7. **Expected Status**: 200 のみ OK (ok:false で 503 返すように設計)

---

## 7.3 検知項目と閾値

| 項目 | 検知条件 | 通知 |
|---|---|---|
| PHP Fatal / Parse / Warning | 直近 5 分の `/home/odah/log/php_errors.log` | Slack (即時) |
| Stripe Webhook 失敗 | subscription_events.error_message 直近 5 分 | Slack (即時) |
| 予約メール送信失敗 | reservation_notifications_log.status='failed' 直近 5 分 | monitor_events のみ |
| 連続異常 | monitor_events の error/critical が 15 分で 3 件以上 | 運営通知先（support@posla.jp） |
| cron ラグ | last_heartbeat から 15 分以上経過 | UptimeRobot → 運営通知先（support@posla.jp） |
| サーバーダウン | ping エンドポイント応答なし | UptimeRobot → 運営通知先（support@posla.jp） |

---

## 7.4 監視イベントの見方

POSLA 管理画面 (posla-admin) で:

- `GET /api/posla/monitor-events.php?since=2026-04-18&limit=100`
- 一覧表示: severity / title / detail / created_at / resolved

### 対応後の処理

対応完了したイベントは以下で resolved マーク:
```
PATCH /api/posla/monitor-events.php { id: "xxx", resolved: 1 }
```

resolved_by にログインユーザー ID が記録される。

---

## 7.5 よくある対応パターン

### ケース A: PHP Fatal エラー

1. Slack 通知受信
2. monitor_events で詳細確認
3. 原因パターン:
   - カラム未存在 → マイグレーション未適用が多い → 該当 migration-*.sql を投入
   - Undefined index → コードのバグ → 該当ファイルを修正
   - Connection refused → DB or 外部 API 障害
4. 修正 → デプロイ → resolved マーク

### ケース B: Stripe Webhook 失敗

1. Slack 通知受信
2. subscription_events テーブルで error_message 確認
3. 典型: テナント紐付け失敗、署名検証失敗
4. Stripe ダッシュボードで Webhook 再送 → 解消確認

### ケース C: メール送信失敗

1. reservation_notifications_log で error_message 確認
2. 典型: SMTPサーバー障害、メアド違反
3. PHP mail() の代わりに SendGrid 等に移行検討 (Phase 2)

---

## 7.6 Phase 2 計画 (AI エージェント)

Phase 1 (通知のみ) の次は、**AI が能動的に調査・原因推定**する機能。

### 想定フロー

```
顧客から「注文が反映されない」問い合わせ
  ↓
AI エージェントが以下を調査:
  ・get_recent_orders(store_id, hours)
  ・get_session_state(table_session_id)
  ・search_php_errors(keyword, since)
  ・check_payment_status(order_id)
  ↓
Gemini Function Calling で原因推定
  ↓
管理画面に「推定原因 + 対処案」提示
```

現状未実装。Phase 2 で段階導入予定。

---

## 7.7 技術詳細

### ファイル構成

```
sql/migration-i1-monitor.sql                      — monitor_events テーブル
api/cron/monitor-health.php                       — 正系 cron
api/monitor/ping.php                              — 外部 uptime 向け
api/posla/monitor-events.php                      — 管理画面用 CRUD
docs/ops-monitor-guide.md                         — 技術ドキュメント
```

### 主要 DB

```sql
CREATE TABLE monitor_events (
  id, event_type, severity, source, title, detail,
  tenant_id, store_id,
  notified_slack, notified_email,
  resolved, resolved_at, resolved_by,
  created_at
);
```

### posla_settings のキー

- `slack_webhook_url` — Slack Incoming Webhook URL
- `ops_notify_email` — 運営緊急時のメール先
- `monitor_cron_secret` — CLI 以外から cron を叩く時の共有秘密
- `monitor_last_heartbeat` — cron 最終実行時刻 (ping で確認される)

---

## 7.8 monitor-events.php API 詳細

### 7.8.1 エンドポイント

`GET /api/posla/monitor-events.php` — モニターイベント一覧取得（POSLA 管理者）
`PATCH /api/posla/monitor-events.php` — イベントを `resolved=1` に更新

### 7.8.2 GET レスポンス例

```json
{
  "ok": true,
  "data": {
    "events": [
      {
        "id": "evt-xxx",
        "event_type": "stripe_webhook_failure",
        "severity": "warning",
        "source": "monitor-health",
        "title": "Stripe Webhook 受信失敗",
        "detail": "checkout.session.completed の処理で例外発生",
        "tenant_id": "t-xxx",
        "store_id": null,
        "notified_slack": 1,
        "notified_email": 0,
        "resolved": 0,
        "created_at": "2026-04-19 10:30:15"
      }
    ]
  }
}
```

### 7.8.3 PATCH リクエスト

```json
{
  "id": "evt-xxx",
  "resolved": 1,
  "resolution_note": "Webhook 設定を修正"
}
```

### 7.8.4 severity 一覧

| severity | 意味 | 通知 |
|---|---|---|
| `info` | 情報のみ | DB のみ記録 |
| `warning` | 注意（1 件で対応必須でない） | Slack 通知 |
| `error` | エラー（即時対応推奨） | Slack + メール（連続 3 件以上） |
| `critical` | 重大障害 | Slack + メール即時 |

### 7.8.5 event_type 一覧

| event_type | 説明 | 検知元 |
|---|---|---|
| `php_error` | PHP エラーログに新規記録 | monitor-health.php (5 分 cron) |
| `stripe_webhook_failure` | Stripe Webhook 処理失敗 | webhook.php |
| `mail_send_failure` | テナント宛メール送信失敗 | 各 mail() 呼び出し |
| `tenant_subscription_failed` | 月次請求失敗 | webhook.php |
| `db_connection_error` | DB 接続失敗 | get_db() |
| `external_api_error` | Gemini / Places / Stripe API 失敗 | 各プロキシ |
| `ping_no_response` | 外部 uptime からの ping が失敗 | UptimeRobot |

---

## 7.9 Slack 通知のフォーマット

### 7.9.1 通常通知

```
:warning: POSLA 監視アラート (warning)
─ event: stripe_webhook_failure
─ tenant: shibuya-ramen
─ detail: checkout.session.completed の処理で例外
─ time: 2026-04-19 10:30:15
─ <https://eat.posla.jp/public/posla-admin/dashboard.html#monitor|管理画面で確認>
```

### 7.9.2 緊急通知

```
:rotating_light: POSLA 緊急アラート (critical)
─ event: db_connection_error
─ source: monitor-health
─ 全テナント影響の可能性あり
─ ↓ POSLA運営窓口（support@posla.jp）にも送信済み ↓
```

---

## 7.10 ヘルスチェック詳細

### 7.10.1 監視項目（monitor-health.php、5 分毎）

| チェック | 頻度 | 異常判定 |
|---|---|---|
| PHP エラーログ | 5 分 | 新規エラー 1 件以上 |
| Stripe Webhook 受信 | 5 分 | 過去 1 時間で受信 0 件かつ通常時間帯 |
| メール送信成功率 | 5 分 | 過去 10 件中 3 件以上失敗 |
| DB 接続 | 5 分 | `SELECT 1` 失敗 |
| ディスク空き容量 | 5 分 | 90% 以上使用 |
| API レスポンス時間 | 5 分 | 平均 5 秒以上 |

### 7.10.2 副系（外部 uptime）

UptimeRobot や cron-job.org が `/api/monitor/ping.php` を 5 分毎に叩く。

レスポンス:
```json
{ "ok": true, "ts": "2026-04-19T10:30:15+09:00" }
```

500 エラー or タイムアウト → 即時 運営通知先（support@posla.jp）

---

## 7.11 自動対応（Phase 2 計画）

将来的に検知 → 自動修復のループを実装予定:

| 異常 | 自動対応 |
|---|---|
| Webhook 受信途絶 | Stripe API で過去 24h の events を fetch → 未処理を補完 |
| メール送信失敗 | リトライキューに追加（最大 3 回） |
| DB スロークエリ | 自動 EXPLAIN → 改善提案を Slack 通知 |
| ディスク空き 90% | 古い `_backup_*` ディレクトリを自動削除 |

---

## 更新履歴

- **2026-04-19**: 7.8 API 詳細 / 7.9 Slack フォーマット / 7.10 ヘルスチェック詳細 / 7.11 Phase 2 計画 追加
- **2026-04-18**: Phase 1 実装 (通知のみ、AI エージェントは Phase 2 で計画)
