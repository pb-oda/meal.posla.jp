---
title: 運用トラブルシューティング
chapter: 06
plan: [all]
role: [posla-admin]
audience: POSLA 運営チーム (内部)
keywords: [troubleshoot, 運営, POSLA, error_log, monitor, audit_log, sakura]
last_updated: 2026-04-19
maintainer: POSLA運営
---

# 6. 運用トラブルシューティング

POSLA運営（プラスビリーフ）がテナントからの問い合わせやシステム障害に対応するためのガイドです。本章は **L1（一次受け）→ L2（設定変更）→ L3（システム障害）** の各段階で参照できるように構成しています。

::: warning ⚠️ 現行 sandbox 環境前提の具体例

本章の障害対応コマンド例（SSH / mysql / tail / grep）は **現行 sandbox 環境（さくらのレンタルサーバ、`odah@odah.sakura.ne.jp` / `/home/odah/www/eat-posla/` / `mysql80.odah.sakura.ne.jp` / `/home/odah/log/php_errors.log`）を前提とした実例**です。障害切り分けの原則は vendor-neutral ですが、コマンド中のパス・ホスト名は本番では読み替えてください。

**§6.15「現行 sandbox（Sakura）障害時の対応」は、現行 sandbox 固有の参考情報**です。本番インフラが決まったら同等のプロバイダ障害対応フローに置き換えてください。
:::

::: tip この章を読む順番（おすすめ）
1. お客様問い合わせの一次対応 → **6.1 テナントからの問い合わせ対応** + **6.2 問い合わせ別対応フロー**
2. error_log を見る人 → **6.9-b エラーカタログと error_log** + **6.11 error_log 調査クエリ集**
3. 障害切り分け → **6.8 障害判別フローチャート** + **6.12 monitor_events の見方**
4. メンテ計画 → **6.10 メンテナンス時の手順**
5. よくある質問 → **6.20 FAQ 30 件**
:::

## 6.0 トラブル対応の前提チェックリスト

問い合わせを受けたら、回答する前に以下を必ず確認してください。

| # | 確認項目 | 確認方法 | NG 時 |
|---|---|---|---|
| 1 | テナント名・店舗名・ユーザー名 | 問い合わせ本文 | 不明なら聞き返す |
| 2 | 発生時刻（分単位） | 問い合わせ本文 / スクショ | 「今」でも可、必ず控える |
| 3 | エラーコード（`[Exxxx]`） | スクリーンショット | あればカタログ確認、なければ症状から推定 |
| 4 | 症状の再現性（毎回/たまに） | 聞き取り | 「たまに」なら時間帯ログを取る |
| 5 | ブラウザ・端末 | 聞き取り | KDS/レジ/ハンディは Android Chrome が標準環境。iOS Safari は業務端末としては非推奨 (音声操作・通知音・PWAインストールに制約) |
| 6 | サーバー応答 | `curl https://eat.posla.jp/api/monitor/ping.php` | 落ちてれば全体障害 |
| 7 | 直近 1 時間の error_log 状況 | 6.11 のクエリ実行 | 多発なら共通障害 |
| 8 | 該当テナントの `is_active` | `SELECT is_active, subscription_status FROM tenants WHERE slug=?` | 0 なら停止中 |

## 6.1 テナントからの問い合わせ対応

### 「ログインできない」

**確認手順：**
1. テナントが有効か確認（POSLA管理画面のテナント管理）
2. アカウントが有効か確認（テナントのオーナーに確認依頼）
3. パスワードが正しいか確認（オーナーまたはマネージャーがパスワードリセット可能）
   - スタッフがパスワードを忘れた場合、上司（オーナー / マネージャー）からリセットしてもらう
   - スタッフが現在のパスワードを覚えている場合は、画面右上の「パスワード変更」ボタンから自分で変更可能（P1-5）

### 「パスワード変更ができない」

**症状：** 「パスワードは8文字以上で、英字と数字を含めてください」エラーが表示される

**原因：** P1-5 で導入されたパスワードポリシーに違反している

**パスワードポリシー要件：**
- 8文字以上
- A-Z または a-z を1文字以上
- 0-9 を1文字以上

**対処：** 上記要件を全て満たすパスワードに変更してもらう（例：`tanaka123`、`Yamada2026`）

**症状：** 「現在のパスワードが正しくありません」エラー

**対処：** 現在のパスワードを忘れている場合は、オーナー / マネージャーに依頼してリセットしてもらう

**「このテナントは無効化されています」エラー：**
- POSLA管理画面でテナントの`is_active`が0になっている
- テナントを有効化する：テナント編集 → 有効に変更

### 「機能が表示されない」

**確認手順：**
1. テナントのプランを確認（POSLA管理画面）
2. 該当機能がプランに含まれているか確認（プラン機能表参照）
3. 含まれていない場合、プランのアップグレードを案内

### 「AIが動かない」 / 「AIサービスは現在利用できません」

**症状：** テナントから「AIアシスタントが動かない」「AIサービスは現在利用できません。POSLA運営にお問い合わせください、と表示される」という問い合わせ

**確認手順：**
1. POSLA管理画面の「API設定」で `gemini_api_key` が設定されているか確認
2. キーが有効か確認（Google Cloud Console > APIキー認証情報）
3. APIの利用量制限・課金設定を確認（無料枠超過時のエラーになっていないか）
4. サーバー側でレスポンスを直接叩いて確認：
   - `POST /api/store/ai-generate.php`（要認証）
   - エラーコード `AI_NOT_CONFIGURED`(503) → APIキー未設定
   - エラーコード `GEMINI_ERROR`(502) → Gemini API側の問題（キー無効、課金停止、レート制限等）

::: tip P1-6: サーバープロキシ経由
管理画面のAIアシスタントは P1-6 以降、サーバー側プロキシ `api/store/ai-generate.php` 経由で Gemini API を呼び出します。フロントエンド（ai-assistant.js）は APIキーを直接保持しません。トラブル時はサーバーログ（Apache error log）を確認してください。
:::

### 「決済ができない」

**確認手順：**
1. Stripe 設定パターン A（自前キー）/ B（POSLA Connect 経由）のいずれかが有効か確認（`tenants.stripe_gateway` / `tenants.stripe_secret_key` / `tenants.connect_onboarding_complete`）
2. パターン B の場合、Stripe Connect 接続が完了しているか確認（`connect_onboarding_complete=1`）
3. Stripe アカウントの `charges_enabled` が `true` か確認
4. Stripe Dashboard で該当アカウントのステータスを確認

### 「レジ開け（または閉め / 入金 / 出金）で PIN を求められる」（CB1d、2026-04-19）

**症状：** テナントから「2026-04-19 から、レジ開けでも PIN を要求されるようになって面倒」「マネージャーなのに PIN がいる」等の問い合わせ

**確認・回答：**
1. CB1d の仕様変更（**会計・返金に加えて、レジ開け / レジ閉め / 入金 / 出金の 4 操作にも担当スタッフ PIN が必須**）。仕様であって障害ではない
2. **マネージャーも例外なし**（バイパスなし）。設計理由は「現金が動くすべての操作の責任所在を明確化、特に出金の不正抑止」
3. 事前必要事項を案内：
   - そのスタッフ（マネージャー含む）が個人スタッフアカウントで管理画面にログイン
   - **【🟢 出勤】** で出勤打刻
   - 「レジ担当 PIN」未設定なら設定（dashboard → スタッフ管理 → 編集）
4. 「PIN が一致しないか、対象スタッフが出勤中ではありません」エラー時の確認順:
   - `attendance_logs` で `clock_out IS NULL` AND 当日 `clock_in` ありか確認
   - `users.cashier_pin_hash` が NULL でないか確認
   - PIN 入力ミス（テンキーの押し間違い）を疑う

**サーバー側で監査確認：**
```sql
SELECT created_at, action, details FROM audit_log
WHERE store_id = ? AND action IN ('register_open','register_close','cash_in','cash_out')
ORDER BY id DESC LIMIT 20;
```
`details` JSON に `pin_verified` / `cashier_user_id` / `cashier_user_name` が含まれていれば CB1d 適用済み。

---

## 6.2 問い合わせ別対応フロー（典型 7 パターン）

### 6.2.1 「ログインできない」フロー

```
受付 → エラーメッセージ確認
        ├─ 「ユーザー名またはパスワードが正しくありません」
        │   → 大文字小文字確認 → 上司にパスワードリセット依頼
        ├─ 「このテナントは無効化されています」
        │   → POSLA 管理画面で tenants.is_active 確認
        │   → 課金停止が原因なら subscription_status 確認
        ├─ 「このアカウントは無効化されています」
        │   → users.is_active 確認、上司に有効化依頼
        ├─ 「IP がブロックされています」 (E3xxx 系)
        │   → check_rate_limit のレートリミット引っかかり
        │   → /home/odah/log/php_errors.log で IP を特定
        │   → 一時ブロック解除 (rate-limit ファイル削除)
        └─ 「サーバーが空のレスポンスを返しました」
            → サーバー全体障害の可能性 → 6.13 へ
```

**SQL 確認**:
```sql
-- アカウントとテナントを一括確認
SELECT t.slug AS tenant, t.is_active AS t_active, t.subscription_status,
       u.username, u.role, u.is_active AS u_active, u.locked_until
FROM users u JOIN tenants t ON t.id = u.tenant_id
WHERE u.username = ? AND t.slug = ?;
```

### 6.2.2 「決済失敗」フロー

```
受付 → 失敗時刻 + Payment Intent ID を聞く
        ├─ Stripe Webhook 失敗?
        │   → Stripe Dashboard > Developers > Webhooks で配信状態確認
        │   → subscription_events テーブルで error_message 確認
        ├─ Subscription 状態異常?
        │   → tenants.subscription_status を確認
        │   → past_due / canceled なら課金リトライ or Customer Portal へ案内
        ├─ カード本体の問題?
        │   → Stripe Dashboard で PaymentIntent の last_payment_error 確認
        │   → カード会社に問い合わせるよう案内
        └─ Connect onboarding 未完了?
            → tenants.connect_onboarding_complete=1 か確認
            → 0 なら 21.4.x のオンボーディング再案内
```

**SQL 確認**:
```sql
-- 直近の失敗 Webhook
SELECT created_at, event_type, stripe_event_id, error_message
FROM subscription_events
WHERE error_message IS NOT NULL AND error_message <> ''
ORDER BY id DESC LIMIT 20;

-- 該当テナントのサブスク状況
SELECT slug, subscription_status, stripe_customer_id, stripe_subscription_id,
       current_period_end, cancel_at_period_end
FROM tenants WHERE slug = ?;
```

### 6.2.3 「メニュー消えた」フロー

```
受付 → 「全店共通の品が消えた」 or 「自店だけ」?
        ├─ 全店共通（本部マスタ）が消えた
        │   → menu_templates の deleted_at / is_active を確認
        │   → audit_log の menu_delete を確認 (本部 owner が削除した可能性)
        ├─ 自店だけ消えた
        │   → store_menu_overrides で is_hidden=1 になっていないか確認
        │   → 本部一括配信契約 (hq_menu_broadcast=1) なら本部側で hide した可能性
        └─ お客さんセルフメニューだけ消えた
            → 3 分メニューバージョンキャッシュ → リロード案内
            → resolve_store_menu() のフィルタ条件確認
```

**SQL 確認**:
```sql
-- 全店共通テンプレート（テナント単位）
SELECT id, name, category, base_price, is_active, deleted_at
FROM menu_templates
WHERE tenant_id = ? AND name LIKE '%キーワード%';

-- 店舗オーバーライド
SELECT id, template_id, price, is_hidden, is_sold_out, updated_at
FROM store_menu_overrides
WHERE store_id = ? AND template_id = ?;

-- 本部による削除履歴
SELECT created_at, username, action, entity_id, old_value, new_value
FROM audit_log
WHERE tenant_id = ? AND action IN ('menu_delete','menu_update')
  AND entity_type = 'menu_item'
ORDER BY id DESC LIMIT 50;
```

### 6.2.4 「会計差額」フロー

```
受付 → 差額発生日時 + 担当スタッフを聞く
        ├─ 出金記録漏れの可能性
        │   → audit_log で cash_out の記録を確認
        │   → CB1d 以降は PIN 必須なので無記録出金は不可能
        ├─ 返金処理ミス
        │   → orders.refund_status / refund_amount を確認
        ├─ レジ開け時の現金カウント間違い
        │   → cash_register_logs テーブルの opening_amount を確認
        └─ 不正アクセス?
            → audit_log で誰がいつ register_open / cash_out したか追跡
```

**SQL 確認**:
```sql
-- 特定店舗・特定日のレジ操作全件
SELECT created_at, username, role, action, new_value
FROM audit_log
WHERE store_id = ?
  AND DATE(created_at) = '2026-04-19'
  AND action IN ('register_open','register_close','cash_in','cash_out')
ORDER BY created_at;

-- PIN 認証の記録（CB1d 以降）
SELECT created_at, action, JSON_EXTRACT(new_value, '$.pin_verified') AS pin_ok,
       JSON_EXTRACT(new_value, '$.cashier_user_name') AS cashier
FROM audit_log
WHERE store_id = ? AND DATE(created_at) = '2026-04-19'
ORDER BY created_at;
```

### 6.2.5 「KDS に注文が出ない」フロー

```
受付 → どの端末で出ない?
        ├─ 1 端末だけ
        │   → ブラウザリロード (Cmd+Shift+R)
        │   → Network タブで /api/kds/orders.php レスポンス確認
        ├─ 全端末
        │   → store_id ミスマッチ? (URL パラメータ確認)
        │   → orders テーブルに注文が来ているか?
        │   → station 割当の確認 (kds_stations.category_ids)
        └─ オフライン警告中
            → polling 間隔が 30 秒に落ちる仕様
            → ネット復旧で自動的に 3 秒に戻る
```

**SQL 確認**:
```sql
-- 直近 5 分の注文（特定店舗）
SELECT id, status, table_id, created_at,
       JSON_LENGTH(items) AS item_count
FROM orders
WHERE store_id = ?
  AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
ORDER BY created_at DESC;

-- KDS ステーション設定
SELECT id, name, category_ids, is_active
FROM kds_stations WHERE store_id = ?;
```

### 6.2.6 「予約メールが届かない」フロー

```
受付 → 予約 ID または予約日時を聞く
        ├─ reservation_notifications_log で送信ログ確認
        │   ├─ status=success → メーラー側 (迷惑メール / 受信拒否)
        │   ├─ status=failed → error_message 確認 (mail() 失敗)
        │   └─ レコードなし → cron 未起動 or 対象外
        ├─ Sakura mail() 制限超過?
        │   → 短時間大量送信で送信制限される可能性
        └─ reservation_settings.reminder_*_enabled 確認
            → 0 なら送らない仕様
```

**SQL 確認**:
```sql
-- 予約通知ログ
SELECT created_at, reservation_id, notification_type, status, error_message
FROM reservation_notifications_log
WHERE reservation_id = ?
ORDER BY created_at;

-- 予約設定
SELECT store_id, reminder_24h_enabled, reminder_2h_enabled
FROM reservation_settings WHERE store_id = ?;

-- 直近 1 時間の送信失敗
SELECT COUNT(*) AS failed
FROM reservation_notifications_log
WHERE status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

### 6.2.7 「PIN を求められる」フロー（CP1 / CB1d 仕様）

```
受付 → どの操作で?
        ├─ 会計確定 → 仕様 (CP1, P1b 既存)
        ├─ 返金 → 仕様 (CR1)
        ├─ レジ開け / 閉め / 入金 / 出金 → 仕様 (CB1d, 2026-04-19)
        └─ それ以外 → バグの可能性、コード確認
```

**回答テンプレート**: 6.9.1 / 6.9.2 / 6.1「レジ開け…」を参照。

---

## 6.3 Webhook障害

### Webhookが受信されない

**確認手順：**
1. Stripe Dashboardの「Developers」→「Webhooks」でエンドポイントの状態を確認
2. 最近のイベントの配信ステータスを確認
3. エンドポイントURLが正しいか確認
4. `stripe_webhook_secret`が正しいか確認

### Webhookの署名検証失敗

**原因：**
- `stripe_webhook_secret`の値が間違っている
- サーバーの時計がずれている（5分以上のズレで検証失敗）

**対処：**
1. Stripe Dashboardで最新のWebhookシークレットを確認
2. POSLA管理画面で更新
3. サーバーの時刻同期を確認（NTP）

---

## 6.4 データベース関連

### マイグレーション実行

新しいマイグレーションファイルを適用する手順：

1. マイグレーションファイルの内容を確認
2. **必ずバックアップを取得してから実行**
3. MySQLクライアントでSQLを実行
4. 実行結果を確認

詳細手順は **05-operations.md 5.6.3** を参照。

### テーブルの確認

よく使う確認クエリ：

```sql
-- テナントの状態確認
SELECT id, slug, name, plan, is_active, subscription_status
FROM tenants WHERE slug = 'xxx';

-- テナントの店舗数確認
SELECT t.name, COUNT(s.id) as store_count
FROM tenants t LEFT JOIN stores s ON t.id = s.tenant_id
GROUP BY t.id;

-- posla_settingsの確認
SELECT setting_key,
  CASE WHEN LENGTH(setting_value) > 8
    THEN CONCAT(LEFT(setting_value, 4), '****')
    ELSE setting_value END as masked_value
FROM posla_settings;
```

---

## 6.5 パフォーマンス

### ポーリングによる負荷

KDSの3秒ポーリングは、店舗数×端末数のリクエストが発生します。大規模テナントの場合：

| 端末数 | リクエスト/分 |
|--------|------------|
| 5台 | 100リクエスト/分 |
| 10台 | 200リクエスト/分 |
| 20台 | 400リクエスト/分 |

**対策：**
- MySQLのインデックスが適切に設定されていることを確認
- PHPのOPcacheが有効であることを確認
- 必要に応じてnginxのキャッシュを設定

---

## 6.6 セキュリティインシデント対応

### 不正アクセスの疑い

1. 管理ダッシュボードの「監査ログ」で不審な操作を確認
2. アクティブセッション一覧で不審なセッションをリモートログアウト
3. 該当アカウントのパスワードを変更
4. 必要に応じてアカウントを無効化

### テナントアカウントの緊急停止

POSLA管理画面からテナントを無効化すると、そのテナントの全ユーザーが即座にログイン不能になります。既存セッションは次回操作時にリダイレクトされます。

```sql
-- 緊急停止（即時反映）
UPDATE tenants SET is_active = 0 WHERE slug = 'xxxxx';

-- 全アクティブセッション強制ログアウト（より強力）
DELETE FROM user_sessions
WHERE user_id IN (SELECT id FROM users WHERE tenant_id = (SELECT id FROM tenants WHERE slug = 'xxxxx'));
```

---

## 6.7 バックアップ

### 推奨バックアップ対象

| 対象 | 頻度 | 方法 |
|------|------|------|
| MySQLデータベース | 毎日 | mysqldump |
| アップロード画像 | 毎日 | ファイルコピー |
| posla_settings | 変更時 | 手動バックアップ |

詳細手順は **05-operations.md 5.5** を参照。

### リストア手順

1. バックアップファイルを確認
2. MySQLにインポート
3. 動作確認

---

## 6.8 障害判別フローチャート（POSLA 運営側）

### 6.8.1 「動かない」と問い合わせがあったときの初動

```
1. 該当テナント名・店舗名・ユーザー名を確認
2. 症状を具体的に聞く（エラーメッセージ・スクリーンショット）
3. 発生時刻を確認
   ↓
4. ヘルスチェック確認:
   - https://eat.posla.jp/api/monitor/ping.php → 200 OK?
   - サーバー負荷（Sakura コンパネ）
   - monitor_events に異常記録ないか
   ↓
5. 該当テナントのログイン試行:
   - phpMyAdmin でテスト用 owner アカウントの password_hash 取得
   - test ログイン試行
   ↓
6. 該当テナントの DB 状態確認:
   - tenants.is_active=1?
   - tenants.subscription_status=active?
   - users.is_active=1?
   ↓
7. 該当機能のコード確認（API 直接 curl）
```

### 6.8.2 緊急時のサービス停止判断

POSLA サーバー全体に重大な脆弱性が発覚した場合の手順:

1. Slack / メールで全運営チームに即連絡
2. 該当機能の API を Sakura 側で 503 Service Unavailable に切替（`.htaccess` で）
3. テナント全体に状況通知メール（事前テンプレート使用）
4. 修正後、段階的にロールアウト
5. 事後インシデントレポート作成

---

## 6.9 よくある問い合わせと回答テンプレート

### 6.9.1 「ログインできない」

```
お問い合わせありがとうございます。POSLA 運営チームです。

以下を確認させてください:
1. ログイン URL は https://eat.posla.jp/public/admin/ ですか?
2. ユーザー名・パスワードに大文字小文字の入力ミスはありませんか?
3. 他の端末でも同じ問題が出ますか?
4. Wi-Fi は繋がっていますか?

弊社で確認した結果、お客様のアカウントは現在正常に登録されています。
パスワードリセットが必要な場合は、本人確認書類（契約時の代表者名 + 契約メール）をお送りください。
```

### 6.9.2 「決済が反映されない」

```
お問い合わせありがとうございます。

Stripe 側の確認が必要です:
1. Stripe Dashboard で該当決済（PaymentIntent ID）の状態をご確認ください
2. POSLA 管理画面で「取引履歴」の該当日時を確認

POSLA 側で webhook 受信ログを確認しますので、以下をお知らせください:
- 取引日時
- お客様の注文 ID（あれば）
- Stripe の Payment Intent ID（あれば）
```

### 6.9.3 「メニュー変更が反映されない」

```
セルフメニューには 3 分のキャッシュがあります。

お客様のスマホでメニューを再読み込み（プルダウン更新）すれば反映されます。
それでも反映されない場合、お知らせください。
```

### 6.9.4 「予約が取れない」

```
お問い合わせありがとうございます。

予約 LP からの予約は以下の条件で受付されます:
- 営業時間内
- スロット容量内
- ブラックリスト電話番号でない

該当時間に予約が満枠になっている可能性があります。
管理画面の「予約管理」 →「予約設定」でスロット容量を確認できます。
```

### 6.9.5 「レジ開け / 閉めで PIN を求められる」（CB1d 以降）

```
お問い合わせありがとうございます。

2026-04-19 のアップデート（CB1d）で、現金が動くすべての操作（会計・返金・レジ開け / 閉め / 入金 / 出金）で
担当スタッフ PIN が必須になりました。マネージャー / オーナーも例外なく PIN が必要です。

これは「現金操作の責任所在を明確化し、特に出金の不正抑止を強化する」ためのセキュリティ仕様変更で、
障害ではありません。

PIN を設定していないスタッフがいる場合は:
1. オーナー or マネージャーが管理画面 → スタッフ管理 → 該当スタッフの編集
2. 「レジ担当 PIN」欄に 4 桁の数字を設定
3. スタッフ本人に PIN を伝える

「PIN が一致しない / 出勤中ではない」エラーが出る場合は:
1. そのスタッフが当日 出勤打刻しているか
2. 入力ミスがないか
を確認してください。
```

### 6.9.6 「KDS に注文が出ない」

```
お問い合わせありがとうございます。

以下を確認させてください:
1. KDS 画面の「全品目」ボタンを押してフィルタを解除してみてください
2. 画面上部に「オフライン」赤バナーが出ていませんか?
3. ブラウザを Cmd+Shift+R（強制リロード）してみてください
4. 別の端末（スマホ等）で同じ KDS URL を開いて同じ症状か確認してください

弊社側で注文の到達状況を確認しますので、以下をお知らせください:
- 該当の注文時刻（分単位）
- テーブル番号
- 店舗名
```

### 6.9.7 「予約メールが届かない」

```
お問い合わせありがとうございます。

予約メールが届かない原因として以下が考えられます:
1. お客様の迷惑メールフォルダに振り分けられている
2. お客様のメールアドレスが間違って入力されている
3. 弊社サーバーから送信に失敗している（稀）

弊社で送信履歴を確認しますので、以下をお知らせください:
- 予約 ID（管理画面の予約管理から確認可）
- 予約日時
- お客様のメールアドレス
```

---

## 6.9-b エラーカタログと error_log（2026-04-19 新設）

POSLA の全 API エラー（233 件、Phase A + B + CB1）には **E1xxx〜E9xxx の 4 桁番号**が振られています。フロントには `errorNo` フィールドで返却され、画面には `[E3024] PIN が一致しません` のように表示されます。

### 6.9-b.1 構成

| 要素 | 役割 | ファイル |
|---|---|---|
| エラーレジストリ | E 番号 ↔ コード文字列 ↔ メッセージの対応表 | `api/lib/error-codes.php` |
| 自動記録 | `json_error()` 呼び出し時に全エラーを `error_log` テーブルに INSERT | `api/lib/response.php` |
| 集計 API | テナント側「エラー監視」サブタブのバックエンド | `api/store/error-stats.php` |
| 監視 cron | 5 分ごとに `error_log` を集計、閾値超過で `monitor_events` 昇格 + Slack 通知 | `api/cron/monitor-health.php` |
| カタログ生成 | 全エラーを Markdown 一覧に出力 | `scripts/generate_error_catalog.py` → `docs/manual/tenant/99-error-catalog.md` |

### 6.9-b.2 error_log テーブルの参照

```sql
-- 直近 1 時間のエラー件数（E 番号別）
SELECT error_no, COUNT(*) AS cnt
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY error_no
ORDER BY cnt DESC LIMIT 20;

-- 特定テナント・店舗のエラー
SELECT created_at, error_no, error_code, message, endpoint, user_id
FROM error_log
WHERE tenant_id = ? AND store_id = ?
ORDER BY created_at DESC LIMIT 100;

-- 監視で昇格した重大イベント
SELECT * FROM monitor_events
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY created_at DESC;
```

### 6.9-b.3 エラーレジストリの使い方

`api/lib/error-codes.php` に以下のヘルパ関数あり:

- `get_error_no($code)` — エラーコード文字列（例 `PIN_INVALID`）→ E 番号（例 `E3024`）
- `get_error_code_by_no($errorNo)` — 逆引き

新規 API でエラーを返す際は、必ず `error-codes.php` のレジストリに採番してから `json_error($code, $message)` を呼ぶ（`json_error()` 内で `errorNo` フィールドが自動付与される）。

### 6.9-b.4 顧客対応フロー

お客様から「`E2017` というエラーが出ました」と連絡が来た場合:

1. **まずカタログを確認**:
   - tenant 公開版 → `https://eat.posla.jp/public/docs-tenant/tenant/99-error-catalog.html`
   - リポジトリ → `docs/manual/tenant/99-error-catalog.md`
   - または管理画面の **AI ヘルプデスク FAB** で `[E2017]` と聞く（運用上の最速ルート）
2. カタログ記載の対処方法をお客様に案内
3. 対処不能なら error_log を SQL で確認 → 詳細コンテキスト把握
4. 多発しているなら monitor_events を確認、Slack 通知で既知化されているか確認

### 6.9-b.5 monitor-health cron

5 分ごとに以下を実行:

- 直近 5 分の `error_log` を集計
- 閾値（E 番号別、デフォルト ≥10 件 / 5 分）超過なら `monitor_events` に INSERT
- monitor_events INSERT と同時に Slack webhook 通知発火

cron 設定例（FreeBSD crontab）:
```
*/5 * * * * /usr/local/bin/php /home/odah/www/eat-posla/api/cron/monitor-health.php
```

実行ログは `/home/odah/www/eat-posla/logs/monitor-health.log` に追記。

### 6.9-b.6 新規エラーコード追加手順 (R1 ガード機構付き)

新しい API でエラーを返す際の手順。`scripts/generate_error_catalog.py` に **採番固定 + 未登録検出 + サイレント採番変更検出** のガードが入っているため、運用ミスを防げます。

**手順:**

1. PHP で `json_error('NEW_CODE', '...', 4xx)` を呼ぶようにコードを書く
2. `scripts/output/error-audit.tsv` に該当行を追記
   ```
   NEW_CODE	400	api/path/to/file.php	行番号	メッセージ
   ```
3. `python3 scripts/generate_error_catalog.py` を実行
4. ガードが exit 1 で止まる → 表示される推奨アクション (A/B/C) に従う
   - **(A) 既存 E 番号体系のシフトを許容する場合 (新規プロジェクト・リリース前のみ)**: `CATEGORIES` の該当カテゴリ集合に追加。本番に流通している E 番号がある場合は選ばない
   - **(B) 既存 E 番号を保持する場合 (本番運用中・推奨)**: `PINNED_NUMBERS` に明示登録（カテゴリ内の次の空き番号を割当）
   - **(C) 一時的にスキップする場合**: `EXCLUDE` にコード名を追加
5. `python3 scripts/generate_error_catalog.py` を再実行 → exit 0 で完了
6. `php scripts/build-helpdesk-prompt.php` で AI ヘルプデスク KB 再生成
7. `cd docs/manual && npm run build:tenant && npm run build:internal` でドキュメントビルド
8. `api/lib/error-codes.php` / `docs/manual/{tenant,internal}/99-error-catalog.md` / `scripts/output/helpdesk-prompt-*.txt` をデプロイ

**ガード機構の役割:**

| ガード | 検出する内容 | 目的 |
|---|---|---|
| `assert_no_unrecognized_codes()` | TSV にあるが CATEGORIES / PINNED_NUMBERS / 既存 PHP レジストリのいずれにも無いコード | 新コードの採番ルートが未定義なまま自動採番されるのを防ぐ。番号シフト事故の予防 |
| `assert_no_silent_renumbering()` | 既存 PHP レジストリの code → errorNo と新生成結果を比較し、PINNED 以外で番号が変化したコード | CATEGORIES から既存コードを誤って削除した場合などに発火。本番流通中の E 番号がサイレントに変わるのを防ぐ |

**なぜ既存 errorNo を不用意に変えてはいけないか:**

- AI ヘルプデスク（`scripts/output/helpdesk-prompt-tenant.txt`）が E 番号で参照される
- 顧客サポートで「`E2017` が出ました」と問い合わせを受けた記録が残っている
- 顧客側のスクリーンショットや業務メモに E 番号が書かれていることがある
- 本番運用中に E 番号がシフトすると、過去の記録と齟齬が出てサポート品質が落ちる

**`PINNED_NUMBERS` の役割:**

`scripts/generate_error_catalog.py` の `PINNED_NUMBERS` 辞書に `('カテゴリID', 'カテゴリラベル', 番号)` を登録すると、その番号で固定割当されます。CATEGORIES への追加でアルファベット順以降の番号がシフトする問題を回避できます。

例 (R1 で追加した `INVALID_REASON`):
```python
PINNED_NUMBERS = {
    'INVALID_REASON': ('E2', '入力検証', 2074),  # E2073 (VALIDATION) の次空き
}
```

**tenant 詳細ブロックの自動保持:**

generator は `docs/manual/tenant/99-error-catalog.md` の `### Exxxx` 詳細ブロックを再生成時に自動保持します（`_extract_existing_detail_blocks()` で再生成前に抽出 → 新カタログにマージ）。新規 errorNo は basic な詳細ブロックを auto-generate し、`enrich_error_catalog.py` で「対処方法」行が追記されます。

### 6.9-b.7 既存 E1xxx 番号重複の扱い (既知課題)

`api/lib/error-codes.php` の E1xxx には複数のコードが同じ番号を共有する箇所があります（例: `ACTIVATE_FAILED` と `ALREADY_PAID` が両方とも `E1001`）。

**原因:**
- `CATEGORIES` に E1 のラベルが 2 つ存在 (`システム / インフラ` と `(未分類)` フォールバック)
- 両方とも `cat_num=1000` から再採番するため同じ番号になる

**現時点の方針 (互換性優先):**
- 本番に流通済みの E 番号を変えると顧客サポート記録と齟齬が出るため、**現時点では維持**
- 表示 (Markdown / AI ヘルプデスク) は「該当コード | A&lt;br&gt;B」と複数併記されるためユーザー側の混乱は実害なし
- `get_error_code_by_no()` は逆引き map の後勝ちで一方しか返せない（例: `E1001` → `ALREADY_PAID` のみ）が、現状運用での使用箇所は限定的
- 根本解消は別タスクで設計検討予定

**何をすると問題が起きるか:**
- `categorize()` のフォールバック先カテゴリラベルを変更 → 番号シフトが大量発生
- E1 集合の中身を並び替え → 番号シフト

`assert_no_silent_renumbering()` がこれを検出して exit 1 します。

---

## 6.9-c RevisionGate (軽量リビジョン確認) 関連のトラブル

詳しい設計は `internal/05-operations.md` §5.14b を参照。

### 6.9-c.1 「KDS で orders.php がほぼ呼ばれていない」と問い合わせ / 内部調査

**正常な挙動です。** RevisionGate により、注文・品目に変更が無い間は重い `kds/orders.php` をスキップし、軽量な `revisions.php` だけが叩かれます。

確認手順:
1. ブラウザ DevTools → Network → `revisions.php` が 3 秒間隔で叩かれているか
2. 30 秒に 1 回は強制的に `orders.php` が叩かれるか (MAX_GAP_MS = 30000)
3. 注文 / 品目状態を変更したら次の 3 秒以内に `orders.php` が叩かれるか

5 分以上 `orders.php` がゼロなら逆に異常 (RevisionGate が常に false / クライアント側のキャッシュ異常 / `_lastFullFetchAt` の時刻ずれを疑う)。

### 6.9-c.2 「revisions.php が遅い」「KDS の反応が悪くなった」

`revisions.php` は MAX クエリ × 1〜2 件で完結する想定 (10ms 以内)。応答が遅い場合:

| 症状 | 確認 | 対処 |
|---|---|---|
| 50ms 以上常時かかる | `EXPLAIN` で `idx_order_updated` / `idx_oi_store_status` / `idx_store_status` が効いているか | インデックス再構築 (`ANALYZE TABLE`) |
| 5xx が頻発 | `error_log` で `revisions.php` の例外確認 | DB 接続エラー / テーブル未作成のグレースフル分岐確認 |
| KDS が「読み込み中…」のまま | フロントの `RevisionGate.shouldFetch()` が Promise pending | `_pending` バッチが解決されていない可能性。ブラウザ再読込 |

### 6.9-c.3 「コース料理が自動進行しない」

コース自動発火 (`check_course_auto_fire`) は `kds/orders.php` 内で実行されます。RevisionGate でスキップされる時間帯でも、**MAX_GAP_MS = 30 秒** で必ず full fetch されるため、auto_fire_min が分単位なら問題なく進行します。

進行しない場合の確認:
1. KDS 画面が開かれているか (画面が閉じていれば fetch 自体が走らない)
2. `polling-data-source.js` の `_lastFullFetchAt` が更新されているか (DevTools コンソール)
3. `course_phases.auto_fire_min` の設定値 (NULL なら手動発火待ち)
4. `table_sessions.phase_fired_at` の値 (発火時刻が未来なら未到達)

### 6.9-c.4 「呼び出しアラートの elapsed_seconds が止まっている」

通常はアラート表示中、KDS / ハンディの呼び出しポーラー (`kds-alert.js` / `handy-alert.js`) はゲート bypass で 5 秒ごとに full fetch します (`_lastAlertIds.length > 0` 判定)。止まる場合:

- ブラウザタブが非アクティブで `setInterval` がスロットルされている可能性 → タブをアクティブにする
- `_lastAlertIds` がクリアされて空配列になっていないか (DevTools)
- `RevisionGate.invalidate('call_alerts', storeId)` が ack 後に呼ばれているか (関連バグなら原因)



---

## 6.10 メンテナンス時の手順

### 6.10.1 計画メンテナンス

1. 1 週間前にテナント全体に告知メール
2. メンテナンス時刻 30 分前に再告知
3. メンテナンス開始時に `index.html` を「メンテナンス中」ページに差し替え
4. 作業実施
5. 動作確認
6. 復旧告知

### 6.10.2 緊急メンテナンス

1. 即時に `index.html` を「緊急メンテナンス中」ページに差し替え
2. テナント全体に状況通知（Slack / メール）
3. 復旧見込み時刻を伝える
4. 作業実施・復旧
5. 事後報告

### 6.10.3 メンテナンスページの差し替え方法

```bash
# 1. 既存の index.html をバックアップ
expect -c "
spawn ssh -i id_ecdsa.pem odah@odah.sakura.ne.jp \"bash -c '
  cp /home/odah/www/eat-posla/public/index.html /home/odah/www/eat-posla/_backup_$(date +%Y%m%d)/public/
'\"
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 2. メンテナンスページを配置
expect -c "
spawn scp -i id_ecdsa.pem maintenance.html odah@odah.sakura.ne.jp:/home/odah/www/eat-posla/public/index.html
expect \"Enter passphrase\" { send \"oda0428\r\" }
expect eof
"

# 3. 復旧時は元の index.html を戻す
```

### 6.10.4 .htaccess による全 API の 503 化（緊急）

```apache
# /home/odah/www/eat-posla/api/.htaccess に以下を追加
<IfModule mod_rewrite.c>
  RewriteEngine On
  # メンテナンス中: monitor/ping.php と特定 IP（運営）以外は 503
  RewriteCond %{REMOTE_ADDR} !^xxx\.xxx\.xxx\.xxx$
  RewriteCond %{REQUEST_URI} !^/api/monitor/ping\.php$
  RewriteRule .* - [R=503,L]
</IfModule>

ErrorDocument 503 /maintenance.html
Header set Retry-After "3600"
```

---

## 6.11 error_log 調査クエリ集（運用支援）

### 6.11.1 エラー多発の原因特定

```sql
-- トップ 10 エラー（直近 24 時間、テナント別）
SELECT t.slug, e.error_no, e.code, COUNT(*) AS cnt
FROM error_log e
LEFT JOIN tenants t ON t.id = e.tenant_id
WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY t.slug, e.error_no, e.code
ORDER BY cnt DESC LIMIT 10;

-- 5xx エラーのみ（システム障害候補）
SELECT created_at, error_no, code, message, request_path, ip_address
FROM error_log
WHERE http_status >= 500
  AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
ORDER BY created_at DESC LIMIT 50;

-- 特定エンドポイントのエラー集計
SELECT error_no, code, COUNT(*) AS cnt, MAX(message) AS sample
FROM error_log
WHERE request_path LIKE '/api/customer/checkout-confirm.php%'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY error_no, code
ORDER BY cnt DESC;
```

### 6.11.2 ブルートフォース・攻撃検知

```sql
-- 認証エラー (401/403) の上位 IP
SELECT ip_address, COUNT(*) AS cnt, MAX(created_at) AS last_seen
FROM error_log
WHERE http_status IN (401, 403)
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY ip_address
HAVING cnt >= 20
ORDER BY cnt DESC;

-- 同じパス・同じ IP の連打
SELECT ip_address, request_path, COUNT(*) AS cnt
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
GROUP BY ip_address, request_path
HAVING cnt >= 50
ORDER BY cnt DESC;
```

### 6.11.3 時間帯分析

```sql
-- 時間帯別エラー数（過去 24 時間、1 時間刻み）
SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS cnt
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY hour ORDER BY hour;

-- 曜日別エラー傾向（過去 30 日）
SELECT DAYNAME(created_at) AS day_of_week,
       HOUR(created_at) AS hour,
       COUNT(*) AS cnt
FROM error_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY day_of_week, hour
ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), hour;
```

### 6.11.4 実例ブロック

**例 1: PIN 連打を検知**

```
SELECT * FROM error_log WHERE code='PIN_INVALID'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
```
→ 出力例:
```
created_at           | error_no | code        | ip_address       | request_path
2026-04-19 13:45:01  | E3024    | PIN_INVALID | 192.168.10.55    | /api/store/process-payment.php
2026-04-19 13:45:03  | E3024    | PIN_INVALID | 192.168.10.55    | /api/store/refund-payment.php
... (10 件続く) ...
```
→ 対処: 専用の PIN 検証 API は存在しないため、`request_path` が `process-payment.php` / `refund-payment.php` のどちらかを確認し、`cashier_pin_failed` 監査ログとあわせて PIN 誤入力か出勤状態不整合かを切り分ける。

**例 2: AI_NOT_CONFIGURED 多発**

```
SELECT created_at, message FROM error_log WHERE code='AI_NOT_CONFIGURED'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 5;
```
→ 出力:
```
created_at           | message
2026-04-19 09:15:22  | AI サービスは現在利用できません。POSLA運営にお問い合わせください
...
```
→ 対処: `posla_settings.gemini_api_key` が空。API 設定タブで再投入。

---

## 6.12 monitor_events の見方

### 6.12.1 severity 別対応

| severity | 意味 | 対応 |
|---|---|---|
| `info` | 通常イベント（heartbeat 等） | 監視のみ |
| `warn` | 警告（同一エラー多発、Webhook 失敗等） | 1 時間以内に対応、根本原因調査 |
| `error` | 5xx エラー多発、外部 API 障害 | 30 分以内に対応、必要なら緊急メンテ |
| `critical` | サービス停止級（DB ダウン、認証完全停止等） | 即時対応、全運営に Slack 通知 + 電話 |

### 6.12.2 確認クエリ

```sql
-- 直近 24 時間の monitor_events
SELECT id, event_type, severity, source, title, created_at, notified_email
FROM monitor_events
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;

-- 同じイベントが繰り返されているか
SELECT title, COUNT(*) AS cnt, MIN(created_at) AS first, MAX(created_at) AS last
FROM monitor_events
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY title
HAVING cnt >= 3
ORDER BY cnt DESC;

-- 既読化（対応済みフラグはまだ無いので、note カラム追加 or 別管理）
-- 削除する場合
DELETE FROM monitor_events WHERE id = ?;
```

---

## 6.13 audit_log の調査クエリ

### 6.13.1 「誰が何をしたか」の追跡

```sql
-- 特定スタッフの直近 50 操作
SELECT created_at, action, entity_type, entity_id, store_id,
       LEFT(JSON_UNQUOTE(new_value), 100) AS new_summary
FROM audit_log
WHERE user_id = ?
ORDER BY id DESC LIMIT 50;

-- 特定の店舗・特定日の全操作
SELECT created_at, username, role, action, entity_type, entity_id
FROM audit_log
WHERE store_id = ? AND DATE(created_at) = '2026-04-19'
ORDER BY created_at;

-- メニュー削除を行った人を特定
SELECT created_at, username, role, entity_id, JSON_EXTRACT(old_value, '$.name') AS deleted_name
FROM audit_log
WHERE action = 'menu_delete'
  AND tenant_id = ?
ORDER BY id DESC LIMIT 20;

-- 設定変更履歴
SELECT created_at, username, action, entity_type,
       LEFT(JSON_UNQUOTE(old_value), 80) AS before,
       LEFT(JSON_UNQUOTE(new_value), 80) AS after
FROM audit_log
WHERE action = 'settings_update' AND store_id = ?
ORDER BY id DESC LIMIT 20;
```

### 6.13.2 不正操作疑いの検出

```sql
-- 短時間で大量の cash_out
SELECT username, COUNT(*) AS cnt, SUM(JSON_EXTRACT(new_value, '$.amount')) AS total
FROM audit_log
WHERE action = 'cash_out'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY username
HAVING cnt >= 5
ORDER BY total DESC;

-- 営業時間外のログイン
SELECT created_at, username, role, ip_address
FROM audit_log
WHERE action = 'login'
  AND (HOUR(created_at) < 6 OR HOUR(created_at) > 23)
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY created_at DESC;

-- 同じ user_id で異なる IP からの login
SELECT user_id, COUNT(DISTINCT ip_address) AS distinct_ips,
       GROUP_CONCAT(DISTINCT ip_address) AS ips
FROM audit_log
WHERE action = 'login'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY user_id
HAVING distinct_ips >= 3;
```

---

## 6.14 連絡先・エスカレーション

| レベル | 対応 |
|--------|------|
| L1 | テナントからの問い合わせ。ログイン問題、操作方法の案内 |
| L2 | 設定変更。API設定、プラン変更、テナント有効化/無効化 |
| L3 | システム障害。データベース、サーバー、外部API連携の問題 |

---

## 6.15 インフラ全体障害時の対応（現行 sandbox 参考）

### 6.15.1 現行 sandbox 全体障害の確認

```
1. https://help.sakura.ad.jp/  → 障害情報を確認
2. https://twitter.com/sakura_pr  → 公式アナウンス確認
3. ssh / mysql 共に接続不能 → Sakura 側障害確定
```

### 6.15.2 部分障害（DB のみ・SSH のみ等）

| 症状 | 確認 | 対応 |
|---|---|---|
| Web は表示されるが DB エラー | `mysql ...` で接続テスト | DB 障害の可能性、Sakura に連絡 |
| SSH 接続不能、Web は OK | コントロールパネル経由で確認 | Sakura に連絡 |
| メール送信のみ失敗 | Sakura メールサービス障害情報 | mail() 制限 / 障害情報を確認 |
| 全部止まっている | コントロールパネルもダメ | Sakura 全体障害、復旧待ち |

### 6.15.3 復旧後の確認手順

```bash
# 1. ヘルスチェック
curl https://eat.posla.jp/api/monitor/ping.php

# 2. DB 接続確認（credentials は env 経由で供給）
source ~/.posla-sandbox.env
mysql -h "$POSLA_DB_HOST" -u "$POSLA_DB_USER" -p"$POSLA_DB_PASS" "$POSLA_DB_NAME" -e "SELECT 1"

# 3. cron が再開しているか
mysql -h ... -e "SELECT setting_value FROM posla_settings WHERE setting_key='monitor_last_heartbeat'"

# 4. 直近のエラーログ確認
tail -100 /home/odah/log/php_errors.log

# 5. monitor_events で異常な蓄積がないか
mysql -h ... -e "SELECT COUNT(*), severity FROM monitor_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) GROUP BY severity"
```

### 6.15.4 障害後のテナント連絡

```
件名: 【ご報告】POSLA サービス一時停止のお詫び

テナント各位

平素より POSLA をご利用いただきありがとうございます。

2026年04月19日 XX:XX 〜 XX:XX の間、サーバー基盤の障害により、
POSLA サービスが一時的にご利用いただけない状況となっておりました。

【影響範囲】
- 管理ダッシュボードへのログイン
- KDS / レジ / ハンディ POS の動作
- セルフメニューでの注文受付

【原因】
さくらインターネット社の基盤障害（詳細: https://help.sakura.ad.jp/...）

【対応】
基盤障害の復旧を確認後、POSLA 側でも動作確認を実施し、現在は正常に稼働しております。

ご迷惑をおかけしましたことを深くお詫び申し上げます。

POSLA 運営チーム / プラスビリーフ株式会社
```

---

## 6.16 トラブルシューティング表（横断）

| 症状 | 主原因 | 一次対処 | 詳細参照 |
|---|---|---|---|
| ログインできない | テナント無効 / アカウント無効 / パスワード | `tenants.is_active` / `users.is_active` 確認 | 6.1, 6.2.1 |
| 決済失敗 | Stripe Webhook / Connect / カード | `subscription_events.error_message` 確認 | 6.2.2, 6.3 |
| メニュー消えた | 本部削除 / 店舗 hide / キャッシュ | `audit_log` で削除追跡 | 6.2.3 |
| 会計差額 | 出金記録漏れ / 不正 | `audit_log` で cash_out 追跡 | 6.2.4, 6.13 |
| KDS 注文出ない | network / station 設定 / ブラウザキャッシュ | `orders` テーブル直近確認 | 6.2.5 |
| 予約メール未着 | Sakura mail() / 迷惑メール / 設定 OFF | `reservation_notifications_log` 確認 | 6.2.6 |
| PIN 要求 | CB1d 仕様 | 仕様説明 | 6.1, 6.9.5 |
| AI 動かない | API キー未設定 / Gemini 障害 | `posla_settings.gemini_api_key` | 6.1, 6.11.4 |
| サーバー全体ダウン | Sakura 障害 / DB 障害 | ping.php / mysql 接続テスト | 6.15 |
| エラー多発 | バグ / 攻撃 | `error_log` 集計 | 6.11 |

---

## 6.17 障害対応の事後手続き

### 6.17.1 インシデントレポート（テンプレート）

```markdown
# インシデントレポート — YYYY-MM-DD

## 概要
- 発生時刻: YYYY-MM-DD HH:MM 〜 HH:MM
- 影響範囲: （全テナント / 特定テナント / 特定機能）
- 重大度: critical / error / warn / info

## 検知経緯
- monitor-health による Slack 通知
- テナントからの問い合わせ
- 自社モニタリング

## 原因
（技術的原因を簡潔に。例: マイグレーション漏れ、Stripe Webhook シークレット不一致）

## 対応
- 一次対応: 何時何分に何をしたか
- 二次対応: 修正コード or 設定変更
- 確認: 動作確認手順

## 影響したテナント
- テナント名・店舗数・影響時間

## 再発防止
- 監視追加 / 手順見直し / コード改善
```

### 6.17.2 Postmortem の Slack 共有

重大インシデント（severity=critical）は事後 24 時間以内に Slack #posla-ops チャンネルへ Postmortem を投稿。

---

## 6.18 デバッグツール一覧

| ツール | 用途 |
|---|---|
| `https://eat.posla.jp/api/monitor/ping.php` | ヘルスチェック |
| `tail -f /home/odah/log/php_errors.log` | リアルタイム PHP エラー監視 |
| `mysql -e "SELECT * FROM error_log ORDER BY id DESC LIMIT 20"` | API エラー直近 20 件 |
| Chrome DevTools → Network | フロント側のリクエスト/レスポンス確認 |
| Chrome DevTools → Application → Cookies | セッション Cookie 確認 |
| Stripe Dashboard | 決済関連の全状況 |
| Google Cloud Console | Gemini API 利用量・制限確認 |
| Sakura コントロールパネル | サーバー負荷 / メール送信ログ |

---

## 6.19 復旧優先順位（複数障害発生時）

1. **認証 / セッション系** — ログインできなければ何も操作できない（最優先）
2. **DB 接続** — 全機能が依存
3. **会計確定 (POS)** — 営業継続のため
4. **KDS** — キッチンが止まる
5. **セルフオーダー** — お客さん体験
6. **Stripe Billing / Webhook** — 課金漏れ防止
7. **予約 / リマインダー** — 1 日程度の遅延は許容可
8. **AI / 音声系** — 即時性が低い

---

## 6.20 FAQ 30 件

### Q1. テナントから「[E3024] PIN が一致しません」と言われた
A. CP1 / CB1d の仕様。PIN 入力ミス、または該当スタッフが未出勤。`SELECT cashier_pin_hash, ... FROM users WHERE id=?` と `attendance_logs` を確認。

### Q2. 全テナントが急に「サーバーエラー」を出している
A. **全体障害**。`https://eat.posla.jp/api/monitor/ping.php` を確認。落ちていれば Sakura 障害情報 / DB 接続テスト → 6.15 へ。

### Q3. 特定テナントだけログインできない
A. `SELECT is_active, subscription_status FROM tenants WHERE slug=?`。`is_active=0` or `subscription_status='canceled'` なら停止中。

### Q4. error_log テーブルが急膨張した
A. 攻撃 or バグ多発の可能性。`SELECT error_no, COUNT(*) FROM error_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) GROUP BY error_no ORDER BY 2 DESC LIMIT 10` で原因 errorNo 特定 → 6.11 へ。

### Q5. monitor_events に同じ警告が連発する
A. 重複防止の 30 分ウィンドウを通り抜けている可能性。タイトル微妙に違う or 30 分超えている。`monitor-health.php` の `_logEvent` 周辺確認。

### Q6. Slack 通知が来ない
A. `posla_settings.slack_webhook_url` が設定されているか確認。`curl -X POST -H "Content-Type:application/json" -d '{"text":"test"}' <webhook_url>` で疎通テスト。

### Q7. cron が動いていない
A. `posla_settings.monitor_last_heartbeat` を確認。5 分以上更新されていなければ monitor-health が止まっている。Sakura の cron 設定 + `cron-monitor-health.log` を確認。

### Q8. お客様から E 番号を聞かれた
A. **AI ヘルプデスク FAB** に `[Exxxx]` と入力するのが最速。次に `docs/manual/tenant/99-error-catalog.md` を grep。

### Q9. デプロイ後に画面が真っ白
A. 1) `php_errors.log` で Fatal/Parse エラー確認、2) JS ファイルの ES5 違反確認（`const`/`let`/arrow）、3) キャッシュバスター漏れで古い JS が読まれていないか確認、4) DB マイグレーション漏れ確認。

### Q10. テナントが「決済が反映されない」と問い合わせ
A. 6.2.2 フロー。Stripe Dashboard で PaymentIntent ID を確認、`subscription_events` の `error_message` 確認。

### Q11. メニューが消えたとテナントが言う
A. 6.2.3 フロー。`audit_log` で `menu_delete` を追跡、本部一括配信契約なら本部側 owner が削除した可能性大。

### Q12. レジの差額調査を依頼された
A. 6.2.4 フロー。CB1d 以降、cash_out も PIN 必須なので `audit_log` に必ず記録あり。当該日の cash_out 全件 + opening_amount を確認。

### Q13. KDS に注文が来ないと言われた
A. 6.2.5 フロー。`orders` テーブル直近 5 分を確認、station 設定確認、ブラウザリロード案内。

### Q14. 予約メールが届かないと言われた
A. 6.2.6 フロー。`reservation_notifications_log` で送信状態確認、Sakura mail() 失敗 or 迷惑メール振り分けが原因の大半。

### Q15. AI が動かないと言われた
A. `posla_settings.gemini_api_key` が空 → 設定。設定済みなら Gemini 側障害（Google Cloud Console 確認）。

### Q16. Stripe Webhook が大量に失敗している
A. webhook secret 不一致 or サーバー時刻ズレ。Stripe Dashboard で配信ステータス確認、`tenants.stripe_webhook_secret` を最新値に更新。

### Q17. 同じ IP から大量の認証失敗
A. ブルートフォース疑い。6.11.2 のクエリで IP 特定、`api/lib/rate-limiter.php` のキャッシュファイルを削除して即時ブロック / 解除。

### Q18. テナントを緊急停止したい
A. `UPDATE tenants SET is_active = 0 WHERE slug=?`。次回操作時に全ユーザーがログイン画面にリダイレクト。即時に既存セッションも無効化したい場合は `DELETE FROM user_sessions WHERE user_id IN (...)`。

### Q19. テスト DB を作りたい
A. Sakura DB 管理画面で別 DB を作成 → `schema.sql` + 全 migration を流す。本番に影響を与えずにリストアテスト・マイグレーション dry-run が可能。

### Q20. メンテナンスページに切り替えたい
A. 6.10.3 / 6.10.4 参照。`index.html` 差し替え or `.htaccess` で全 API を 503 化。

### Q21. 監査ログを 1 年以上保持したい
A. `audit_log` の retention は `DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)` で削除。それより長くしたい場合は別 DB or S3 にアーカイブ。

### Q22. sandbox メール送信制限を超えたら？
A. 短時間大量送信で 30 分〜数時間ブロックされる。予約リマインダーが大量送信タイミングと重なると発生。送信間隔を空けるか、外部 SMTP（SendGrid 等）に切り替える。

### Q23. テナントが自分でパスワードリセットできない
A. パスワードリセット URL の発行は **オーナーまたはマネージャーのみ**。スタッフは上司に依頼するのがフロー。緊急時は POSLA 運営が `users.password_hash` を直接更新できるが、本人確認必須。

### Q24. POSLA 管理画面にアクセスできない
A. POSLA 管理セッション（`migration-p1-6d-posla-admin-sessions.sql`）の問題。`posla_admin_sessions` テーブルを確認、期限切れなら再ログイン。

### Q25. スマレジ連携が切れた
A. `tenants.smaregi_token_expires_at` を確認。リフレッシュトークン失効なら、テナント側で再認証が必要。

### Q26. 本部一括配信アドオンを契約したテナントの店舗側でメニュー編集できない
A. **仕様**（P1-29）。本部マスタは owner のみ「本部メニュー」タブから編集可。店舗は overrides（価格上書き / 非表示 / 売り切れ）と limited items のみ。

### Q27. Connect onboarding が進まないテナント
A. `tenants.connect_onboarding_complete=0` の状態で stuck している。Stripe Dashboard で onboarding URL を再発行 or Stripe Connect Account ID を確認。

### Q28. デバイス自動ログイン（F-DA1）の URL が無効と言われた
A. ワンタイムトークンは使い捨て + 短時間有効期限。`device_registration_tokens.is_used=1` または `expires_at < NOW()` なら無効。再発行が必要。

### Q29. サブセッション QR（F-QR1）でテーブルが結合できない
A. `table_sub_sessions` テーブルと `orders.sub_session_id` を確認。アクティブなサブセッションがあるテーブルは結合不可（仕様）。

### Q30. 返金処理が失敗する
A. `orders.refund_status` を確認。Stripe Refund API のエラーは `error_log` に記録される（`code='REFUND_FAILED'` 等）。現金 / QR は記録のみで物理処理は店舗対応。詳細は 5.17 参照。

---

## X.X 更新履歴
- **2026-04-19**: フル粒度化（6.0 前提チェックリスト・6.2 問い合わせ別対応フロー 7 件・6.10.3/4 メンテ手順詳細・6.11 error_log 調査クエリ集・6.12 monitor_events 見方・6.13 audit_log 調査クエリ・6.15 Sakura 障害対応・6.16 トラブルシューティング表・6.17 インシデントレポート・6.20 FAQ 30 件 を追加）
- **2026-04-19**: 6.9-b「エラーカタログと error_log」を新設。`api/lib/error-codes.php` レジストリ・`api/cron/monitor-health.php` 5 分 cron・Slack 通知運用、お客様から E 番号を聞かれた際の対応フローを追加
- **2026-04-19**: 6.8 障害判別フロー、6.9 問い合わせテンプレート、6.10 メンテナンス手順 追加
- **2026-04-18**: frontmatter 追加、AIヘルプデスク knowledge base として整備
