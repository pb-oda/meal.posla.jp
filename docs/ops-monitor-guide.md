# I-1 運用支援エージェント ガイド

## 目的

POSLA サーバーの異常を検知して Hiro / 運営チームに即時通知する。
**AI (Gemini) や POSLA 本体が同時停止してもアラートが届く**設計。

## 2系統の監視

### 正系: Sakura サーバー内 cron

- **実行頻度**: 5分毎
- **対象**: `api/cron/monitor-health.php`
- **やること**:
  1. PHP エラーログ (`/home/odah/log/php_errors.log`) の直近 5 分を走査
  2. `subscription_events` テーブルの失敗ログを検知
  3. `reservation_notifications_log` のメール送信失敗を検知
  4. 異常時: `monitor_events` に記録 + Slack webhook 通知 + 連続3件以上で Hiro メール

### 副系: 外部 uptime サービス

- **実行頻度**: 5分毎 (外部から)
- **対象**: `https://eat.posla.jp/public/api/monitor/ping.php`
- **レスポンス**: `{ ok: true, db: 'ok', last_heartbeat: ..., cron_lag_sec: N }`
- **判定**:
  - 応答なし (サーバーダウン) → 外部サービスが Hiro メールへアラート
  - `ok: false` → 同上
  - `cron_lag_sec > 900` → cron が止まっている警告

**推奨外部サービス**: UptimeRobot (無料) / cron-job.org (無料) / Better Uptime

## 初期設定手順

### 1. Sakura cron 登録 (正系)

```bash
# コンパネ → 共有サーバー → Cron 設定
# 実行コマンド (5分毎):
/usr/local/bin/php /home/odah/www/eat-posla/api/cron/monitor-health.php

# または HTTP 経由 (CLI 不可の場合):
curl -H "X-POSLA-CRON-SECRET: <secret>" https://eat.posla.jp/public/api/cron/monitor-health.php
```

- 環境変数 `POSLA_CRON_SECRET` を設定 (posla_settings.monitor_cron_secret と同値)

### 2. Slack Webhook 設定

1. Slack ワークスペースで Incoming Webhook を作成
2. URL をコピー
3. POSLA管理画面 → API設定 → **Slack Webhook URL** に入力
4. または SQL 直接: `UPDATE posla_settings SET setting_value = '<url>' WHERE setting_key = 'slack_webhook_url';`

### 3. 運営通知メール設定

- `posla_settings.ops_notify_email` に Hiro 個人メール
- デフォルト: `info@posla.jp`
- 重大異常 (連続3件) 時に送信

### 4. 外部 uptime サービス登録 (副系)

UptimeRobot 例:
1. アカウント作成 (無料)
2. 「New Monitor」→ Type: HTTP(s)
3. URL: `https://eat.posla.jp/public/api/monitor/ping.php`
4. 間隔: 5 分
5. アラート先: Hiro個人メール
6. Sakura がダウンしても別系統でメールが届く

## 検知対象の拡張予定

| Phase | 検知対象 | 実装状態 |
|---|---|---|
| 1 | PHP エラー | ✅ |
| 1 | Stripe Webhook 失敗 | ✅ |
| 1 | メール送信失敗 | ✅ |
| 1 | cron ラグ検知 | ✅ |
| 2 | API レスポンスタイム劣化 | 未 |
| 2 | DB スロークエリ | 未 |
| 2 | ディスク使用量 | 未 |
| 3 | AI (Gemini) レート制限 | 未 |
| 3 | ストア別注文量の異常 | 未 |

## 対応フロー

1. Slack / メールで通知受信
2. POSLA管理画面 → 監視イベントタブ で詳細確認
3. 対応完了後、「解決済」ボタンで `resolved=1`
4. 連続発生する場合は根本原因を特定 (ログ詳細確認)

## 今後の自動化 (Phase 2 以降)

- **自動修正**: 既知パターンの異常 (例: cart_events 未作成 → 自動マイグレーション実行)
- **自動エスカレーション**: 15分経過で未対応なら別チャンネルに再送
- **AI 要因分析**: Gemini でエラーログ分析 → 原因推定 (OpenAI API 経由、Gemini 自体停止時対応)
