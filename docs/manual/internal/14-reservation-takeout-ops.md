---
title: 予約・テイクアウト実装リファレンス
---

# 予約・テイクアウト実装リファレンス

この章は、社内向けの実装確認用リファレンスです。2026-04-30 時点の擬似本番環境コードを確認して記述しています。

お客様向けの説明は、次の章に分けています。

- テイクアウト: `/tenant/09-takeout`
- 予約管理: `/tenant/24-reservations`

## 対象範囲

| 領域 | 顧客向け画面 | 店舗向け画面 | 主なAPI |
|---|---|---|---|
| テイクアウト | `public/customer/takeout.html` | 管理画面のテイクアウト管理 | `api/customer/takeout-orders.php`, `api/store/takeout-management.php` |
| 予約 | `public/customer/reserve.html`, `public/customer/reserve-detail.html` | 管理画面の予約ボード | `api/customer/reservation-create.php`, `api/customer/reservation-update.php`, `api/customer/reservation-cancel.php`, `api/store/reservations.php` |
| 運営管理連携 | なし | 管理画面の運営管理 | `api/store/admin-ops.php` |

この章では、実装済みの挙動だけを記載します。SMS、LINE、予約金、キャンセル待ちなどは領域ごとに実装範囲が異なるため、混同しないでください。

## 管理画面の運営管理連携

`public/admin/js/admin-ops-dashboard.js` と `api/store/admin-ops.php` は、予約・テイクアウトを含む店舗運営の集約ビューを提供します。予約・テイクアウト単体の機能ではありませんが、店長が異常を見つける入口として重要です。

| action | 予約・テイクアウトとの関係 |
|---|---|
| `cockpit` | 当日の予約件数、遅刻注意、テイクアウト遅延リスクをKPIカードに出す |
| `search` | 予約、顧客、注文を横断検索する |
| `sales_drilldown` | テイクアウトを含む注文の売上を日別→商品→注文→会計履歴で追跡する |
| `setup` | 予約設定、テイクアウト設定が作成済みか初期設定チェックに出す |
| `terminals` | セルフメニュー最終通信を `cart_events` から見る。予約画面・テイクアウト画面の通信監視ではない |
| `cart` | セルフメニューのカート投入/削除と、注文内キャンセルを分析する |

`admin-ops.php` は manager 以上を要求し、`require_store_param()` と `require_store_access()` で店舗境界を確認します。検索や集計は `tenant_id` / `store_id` を必ず条件に含めます。

運営コックピットのテイクアウト遅延リスクは、`orders.order_type='takeout'` かつ `pickup_at` が近い未完了注文を見ます。予約遅刻は、当日範囲内で予約時刻を過ぎた `pending` / `confirmed` を見ます。

## テイクアウト実装

### 構成ファイル

| 種別 | ファイル |
|---|---|
| 顧客向けHTML | `public/customer/takeout.html` |
| 顧客向けJS | `public/customer/js/takeout-app.js` |
| 顧客向けAPI | `api/customer/takeout-orders.php` |
| 店舗向けAPI | `api/store/takeout-management.php` |
| 店舗向けJS | `public/admin/js/takeout-manager.js` |
| 店舗設定API | `api/store/settings.php` |
| 店舗設定JS | `public/admin/js/settings-editor.js` |
| 主なマイグレーション | `sql/migration-l1-takeout.sql`, `sql/migration-p1-65-takeout-operations.sql`, `sql/migration-p1-66-takeout-customer-convenience.sql`, `sql/migration-l17-3a-takeout-line-links.sql` |

### テイクアウトで使う主なDB

| テーブル/列 | 用途 |
|---|---|
| `store_settings.takeout_*` | 受付ON/OFF、準備時間、受付時間、枠上限、品数上限、ピーク上限、SLA警告、オンライン決済 |
| `orders.order_type='takeout'` | テイクアウト注文の識別 |
| `orders.pickup_at` | 受取予定時刻 |
| `orders.takeout_pack_checklist` | 梱包チェックJSON |
| `orders.takeout_pack_checked_at` / `takeout_pack_checked_by_user_id` | 梱包完了時刻と確認者 |
| `orders.takeout_ready_notification_status` | 準備完了通知の `not_requested` / `sent` / `failed` / `skipped` |
| `orders.takeout_ops_status` | `normal`, `late_risk`, `late`, `customer_delayed`, `cancel_requested`, `cancelled`, `refund_pending`, `refunded` |
| `orders.takeout_arrived_at` / `takeout_arrival_type` / `takeout_arrival_note` | お客様の到着連絡 |
| `takeout_order_line_link_tokens` | LINE紐付け用の一時トークン |
| `takeout_order_line_links` | 注文とLINEユーザーの紐付け |

### 顧客向け注文フロー

顧客向けテイクアウトは `public/customer/js/takeout-app.js` が制御します。画面ステップは、商品選択、顧客情報入力、支払い、確認、完了です。

`api/customer/takeout-orders.php` の主なGETアクションは次の通りです。

| action | 内容 |
|---|---|
| `settings` | テイクアウト有効状態、受付時間、準備時間、枠上限、ピーク上限、オンライン決済可否などを返す |
| `slots` | 指定日の15分単位受取枠を返す |
| `reorder_suggestions` | 同じ店舗・電話番号の過去注文から再注文候補を返す |
| `status` | 注文詳細、運用状態、通知状態、到着連絡状態を返す |

POST は通常注文作成と `action:"arrival"` の到着連絡に対応しています。

顧客向け注文作成では、現在 `payment_method` は `online` のみ受け付けます。商品価格はリクエスト値を信用せず、サーバー側で現在のメニューから再計算します。オンライン決済は、店舗の Stripe Connect または直接 Stripe 設定が利用可能な場合に作成されます。

### 受取枠と競合防止

受取枠は15分単位で生成されます。判定に使う主な値は次の通りです。

| 値 | 参照元 |
|---|---|
| 受付開始・終了 | `store_settings.takeout_available_from`, `takeout_available_to` |
| 最短準備時間 | `takeout_min_prep_minutes` |
| 追加待ち時間 | `takeout_acceptance_delay_minutes` |
| 枠あたり注文数 | `takeout_slot_capacity` |
| 枠あたり品数 | `takeout_slot_item_capacity` |
| ピーク時間帯 | `takeout_peak_start_time`, `takeout_peak_end_time` |
| ピーク上限 | `takeout_peak_slot_capacity`, `takeout_peak_slot_item_capacity` |

注文作成時はトランザクション内で同じ店舗・同じ受取日時の注文を `FOR UPDATE` でロックし、注文数と品数を再確認します。対象外になるステータスは `cancelled` と `paid` です。

### 再注文候補

`reorder_suggestions` は、店舗IDと電話番号で過去注文を検索します。電話番号は数字のみで10桁または11桁が必要です。

候補になる注文ステータスは `ready`, `served`, `paid` です。運用ステータスが `cancelled` または `refunded` の注文は、該当カラムが存在する環境では候補から除外されます。

フロントでは、再注文時に現在のメニューに存在し、売り切れではない商品だけをカートへ戻します。

### 店舗側テイクアウト管理

店舗向けAPI `api/store/takeout-management.php` は、認証済みスタッフ以上を前提に、店舗アクセス権を確認して処理します。

GET は、指定日・店舗・ステータスで `order_type='takeout'` の注文を返します。集計項目は次の通りです。

| 集計 | 判定 |
|---|---|
| `total` | 対象日のテイクアウト注文数 |
| `sla_risk` | 受取予定時刻が近い未完了注文 |
| `sla_late` | 受取予定時刻を過ぎた未完了注文 |
| `packing_incomplete` | 梱包チェックが完了していない注文 |
| `notify_failed` | 準備完了通知が失敗した注文 |
| `refund_attention` | キャンセル・返金対応が必要な注文 |
| `arrived_waiting` | 到着連絡があり、まだ受渡済みでない注文 |

SLA警告の閾値は `store_settings.takeout_sla_warning_minutes` です。初期値は10分です。

### 店舗側ステータス操作

`PATCH action:"status"` で許可される主な遷移は次の通りです。

| 現在 | 次 |
|---|---|
| `pending_payment` | `pending`, `cancelled` |
| `pending` | `preparing` |
| `preparing` | `ready` |
| `ready` | `served` |

`ready` に更新すると `takeout_send_ready_notification` が呼ばれ、LINE準備完了通知が試行されます。結果は `takeout_ready_notification_status` とエラー情報に残ります。

### 梱包チェック

`PATCH action:"pack"` で保存するチェック項目は固定です。

- `items`
- `chopsticks`
- `bag`
- `sauce`
- `receipt`

全項目が true の場合、梱包完了時刻と確認者が保存されます。

### 到着連絡

顧客向けAPIの `action:"arrival"` は、注文ID、店舗ID、電話番号、到着種別、任意メモを受け取ります。

到着種別は `counter` と `curbside` です。キャンセル済み、受渡済み、会計済みの注文には到着連絡できません。

### 運用ステータス

`PATCH action:"ops"` で保存できる運用ステータスは次の通りです。

- `normal`
- `late_risk`
- `late`
- `customer_delayed`
- `cancel_requested`
- `cancelled`
- `refund_pending`
- `refunded`

`cancelled` にした場合、注文が `served` または `paid` でなければ注文ステータスも `cancelled` に更新されます。返金処理そのものは決済事業者側の状態確認が必要です。

### オンライン決済返金との連動

`api/store/refund-payment.php` は、返金対象の `payments.order_ids` を読み取り、`store_id` と `order_type='takeout'` で再絞り込みした上で、対象注文の `takeout_ops_status` を `refunded` に更新します。あわせて `takeout_ops_note`, `takeout_ops_updated_at`, `takeout_ops_updated_by_user_id` も更新します。

この連動は、テイクアウト運用ボードに「返金済み」を反映するためのものです。決済事業者側の返金処理そのものを代替するものではありません。

### テイクアウト通知の範囲

テイクアウトの準備完了通知は LINE 連携を使います。LINE設定と顧客のLINE紐付けがない場合は送信されません。

テイクアウトについては、現時点でSMS通知は標準実装されていません。SMS実装済みなのは予約通知側の Webhook 連携です。

## 予約実装

### 構成ファイル

| 種別 | ファイル |
|---|---|
| 顧客向け予約画面 | `public/customer/reserve.html` |
| 顧客向け予約詳細 | `public/customer/reserve-detail.html` |
| 顧客向けJS | `public/customer/js/reserve-app.js`, `public/customer/js/reserve-detail-app.js` |
| 顧客向けAPI | `api/customer/reservation-create.php`, `api/customer/reservation-update.php`, `api/customer/reservation-cancel.php`, `api/customer/reservation-detail.php`, `api/customer/reservation-availability.php` |
| 店舗向けAPI | `api/store/reservations.php`, `api/store/reservation-settings.php`, `api/store/reservation-no-show.php`, `api/store/reservation-seat.php`, `api/store/reservation-notify.php` |
| 店舗向けJS | `public/admin/js/reservation-board.js` |
| 共通ライブラリ | `api/lib/reservation-availability.php`, `api/lib/reservation-notifier.php`, `api/lib/reservation-history.php`, `api/lib/reservation-waitlist.php`, `api/lib/reservation-risk.php`, `api/lib/reservation-deposit.php` |
| 主なマイグレーション | `sql/migration-l9-reservations.sql`, `sql/migration-rsv-p1-2-waitlist.sql`, `sql/migration-s3-reservation-uniq.sql`, `sql/migration-p1-61-reservation-arrival-followups.sql`, `sql/migration-p1-62-reservation-waitlist-calls.sql`, `sql/migration-p1-63-reservation-operational-safety.sql`, `sql/migration-p1-64-reservation-autonomy.sql` |

### 予約で使う主なDB

| テーブル/列 | 用途 |
|---|---|
| `reservation_settings` | Web予約ON/OFF、受付日数、滞在時間、枠間隔、人数範囲、キャンセル期限、予約金、リマインド、SMS Webhook、Web枠ルール |
| `reservations` | 予約本体。日時、人数、ステータス、席、コース、メモ、予約金、編集トークン、テーブルセッション連携 |
| `reservation_customers` | 顧客台帳。来店回数、no-show、キャンセル、好み、注意点、アレルギー、VIP、ブラックリスト |
| `reservation_holds` | 空席判定時やキャンセル待ち通知時の仮押さえ |
| `reservation_notifications_log` | メール/LINE/SMS Webhookの通知ログ、再送回数、次回再送、店長確認対象 |
| `reservation_change_logs` | 誰が、いつ、どの項目を、何から何へ変更したか |
| `reservation_waitlist_candidates` | キャンセル待ち候補、通知状態、仮押さえ、予約化済みリンク |
| `reservation_courses` | 予約で選択されたコース情報 |

### Web予約作成

`api/customer/reservation-create.php` は、IP単位のレート制限を行った上で、店舗のWeb予約設定、入力値、空席、ブラックリスト、高リスク予約金を確認します。

主な処理は次の通りです。

1. 店舗が有効か確認します。
2. `online_enabled` が有効か確認します。
3. 名前、電話番号、メール、人数、日時を検証します。
4. リードタイム、最大受付日数、人数範囲を確認します。
5. ブラックリスト対象の電話番号を拒否します。
6. `compute_slot_availability` で空席を確認します。
7. 必要であればキャンセル待ちを登録します。
8. 高リスク予約金の要否を判定します。
9. トランザクション内で同日周辺の予約をロックし、空席を再確認します。
10. 予約を登録し、編集トークンと詳細URLを返します。

予約金が必要な場合はチェックアウトURLを返します。予約金が不要な場合は予約確認通知を送信します。

### 空席判定

空席判定は `api/lib/reservation-availability.php` に集約されています。

判定対象は次の通りです。

| 項目 | 内容 |
|---|---|
| 営業時間 | `open_time`, `close_time`, `last_order_offset_min` |
| 定休日 | `weekly_closed_days` |
| リードタイム | `lead_time_hours` |
| 最大受付日数 | `max_advance_days` |
| 予約枠間隔 | `slot_interval_min` |
| 滞在時間 | `default_duration_min` |
| 人数範囲 | `min_party_size`, `max_party_size` |
| 前後バッファ | `buffer_before_min`, `buffer_after_min` |
| Web予約ルール | `web_phone_only_min_party_size`, ピーク時間の組数・人数上限、席エリアフィルタ |
| 既存予約 | `cancelled`, `no_show` 以外の有効予約 |
| 仮押さえ | キャンセル待ち通知時に作成される `reservation_holds` |
| 席割当 | 1卓、2卓結合、3卓結合の順で割当可能か確認 |

顧客向けWeb予約では、設定により大人数を電話のみへ誘導できます。ピーク時間帯は、Web予約の組数・人数上限を別途設定できます。

### 店舗側予約API

`api/store/reservations.php` はスタッフ以上の認証と店舗アクセス権を確認します。

GET の主な用途は次の通りです。

| 用途 | 内容 |
|---|---|
| 日別一覧 | 指定日の予約、待ち候補、席、設定サマリーを返す |
| 詳細取得 | 予約詳細、変更履歴、通知履歴を返す |
| 空席確認 | スタッフ向けの空席候補を返す |

POST は店舗側の予約作成です。`web`, `phone`, `walk_in`, `google`, `external`, `ai_chat` の source を受け付けます。ステータスには `waitlisted` も指定できます。

PATCH は、顧客情報、人数、日時、滞在時間、メモ、タグ、コース、席、ステータス、来店フォロー状態、キャンセル待ち呼び出し状態を更新できます。変更内容は予約履歴に記録されます。

DELETE は予約を `cancelled` に更新し、キャンセル理由、履歴、必要に応じた通知、顧客キャンセル回数、キャンセル待ち通知処理を行います。

### 予約ステータス

主に使う予約ステータスは次の通りです。

- `pending`
- `confirmed`
- `seated`
- `no_show`
- `cancelled`
- `completed`
- `waitlisted`

no-show 確定は `api/store/reservation-no-show.php` で処理します。対象は `confirmed` または `pending` の予約です。予約金の与信がある場合は、設定と決済状態に応じて捕捉処理が呼ばれます。

### 変更履歴

予約履歴は `api/lib/reservation-history.php` を通じて記録されます。店舗側の詳細取得では `change_history` として返されます。

実装上、主に次の変更が履歴化されます。

- 顧客名、電話番号、メール
- 人数
- 予約日時
- 滞在時間
- メモ
- タグ
- コース
- 割り当て席
- ステータス
- 来店フォロー状態
- キャンセル待ち呼び出し状態

「誰が」「いつ」「何を」「変更前」「変更後」を確認できる前提でUIに渡しています。

### リスク判定

店舗側一覧では、予約ごとに運用リスクが付与されます。判定は `api/store/reservations.php` 内の予約シリアライズ処理で行われます。

主なリスクは次の通りです。

| リスク | 内容 |
|---|---|
| 遅刻 | 予約時刻から5分以上経過で警告、15分以上で強い警告 |
| no-show履歴 | no-show回数がある顧客 |
| キャンセル多発 | キャンセル回数が多い顧客 |
| ブラックリスト | 顧客情報でブラックリスト扱い |
| 大人数 | 8名以上の予約 |

対応候補として、電話確認、店長確認、来店フォロー、予約金検討などが返されます。

### 予約金

予約金処理は `api/lib/reservation-deposit.php` と予約作成APIから利用されます。

通常の予約金は、予約設定の `deposit_enabled`, `deposit_per_person`, `deposit_min_party_size` を使います。高リスク予約金は `high_risk_deposit_enabled` が有効な場合に、no-show回数、大人数、ブラックリスト近似などをもとに判定されます。

店舗側で作成する予約では、運用上必要な場合に予約金をスキップする指定ができます。

### リマインド通知と再送

予約通知は `api/lib/reservation-notifier.php` が担当します。

対応している通知タイプには、予約確認、24時間前リマインド、2時間前リマインド、キャンセル、no-show、予約金関連があります。

通知経路は次の通りです。

| 経路 | 実装条件 |
|---|---|
| メール | `posla_send_mail` で送信し、`reservation_notifications_log` に記録 |
| LINE | テナントLINE設定が有効で、対象通知タイプが有効、顧客LINE紐付けがある場合 |
| SMS Webhook | `reservation_settings.sms_enabled=1`、HTTPSのWebhook URL、顧客電話番号がある場合 |

自動リマインドは `api/cron/reservation-reminders.php` で処理します。

| リマインド | 対象 |
|---|---|
| 24時間前 | 現在時刻の23〜25時間後の予約 |
| 2時間前 | 現在時刻の90〜150分後の予約 |

失敗したリマインドは、`reminder_retry_minutes` と `reminder_retry_max` に従って再送対象になります。上限に達したものは店長確認対象として扱われます。

cron をHTTPで実行する場合は `X-POSLA-CRON-SECRET` ヘッダーと `POSLA_CRON_SECRET` の一致が必要です。CLI実行も許可されています。

### キャンセル待ちと仮押さえ

キャンセル待ちは `api/lib/reservation-waitlist.php` が担当します。

満席時に `join_waitlist` が指定されると、条件に応じて待ち候補を登録します。キャンセルや no-show で空席が出た場合、候補の中から条件に合う1件に空席案内を送ります。

空席案内時には `reservation_holds` に仮押さえを作成します。保持時間は `waitlist_lock_minutes` で、最小5分、最大120分に丸められます。

現在の空席案内通知は、顧客メールアドレスがある候補に対するメール送信です。LINEやSMSでのキャンセル待ち空席案内は、この処理では実装されていません。

### 顧客向け予約変更・キャンセル

`public/customer/js/reserve-detail-app.js` と顧客向けAPIにより、予約詳細ページから内容確認、予約金支払い、日時・人数・メモ変更、キャンセルができます。

`api/customer/reservation-update.php` は、予約IDと編集トークンを検証します。`cancelled`, `seated`, `no_show`, `completed` の予約は変更できません。キャンセル期限を過ぎた予約も変更できません。変更時は空席を再確認し、問題がなければ履歴を記録します。

### ハンディ・KDS・レジへの予約文脈連携

予約から着席した卓では、`table_sessions.reservation_id` を起点に予約情報と `reservation_customers` の常連情報を各業務画面へ渡します。

| 画面 | 表示内容 |
|---|---|
| ハンディ | テーブルカード、注意バッジ、予約一覧に予約者名、人数、予約時刻、メモ、好み、アレルギー、注意点、来店回数、VIP、リスク、通知未対応を表示 |
| KDS | 注文カードに予約者名、人数、予約時刻、コース、メモ、好み、注意点、来店回数を表示。アレルギーは「予約アレルギー」として強調 |
| POSレジ | テーブル一覧と会計詳細に予約者名、人数、予約時刻、コース、メモ、好み、アレルギー、注意点、来店回数を表示 |

KDS API `api/kds/orders.php` は、予約系テーブルが未適用の環境でも落ちないよう、カラム・テーブル存在確認を行い、存在する場合だけ `reservation_context` をレスポンスに含めます。

## 社内確認時の注意

### 実装済みとして書いてよいこと

- テイクアウトの時間枠上限、品数上限、ピーク上限
- テイクアウトオンライン決済返金成功後の運用ステータス `refunded` 反映
- テイクアウトの梱包チェック、SLA警告、到着連絡、準備完了LINE通知
- 予約のWeb予約枠ルール、ピーク制御、大人数電話誘導
- 予約の変更履歴、リマインド自動送信、失敗再送、店長確認対象化
- 予約の高リスク予約金判定
- 予約のキャンセル待ち仮押さえ
- 予約通知のメール、LINE、SMS Webhook
- ハンディ・KDS・POSレジでの予約文脈、常連情報、アレルギー、注意点表示

### 実装済みとして書いてはいけないこと

- テイクアウトSMS通知
- テイクアウトの現金払いWeb注文
- 予約ボードのリアルタイムWebSocket更新
- キャンセル待ち空席案内のLINE/SMS自動送信
- POSLA画面上だけでオンライン決済返金が完了するという説明

### デプロイ時の確認

新規環境へ反映する場合は、既存の `schema.sql` を直接編集せず、該当マイグレーションが適用済みか確認してください。

特に確認するマイグレーションは次の通りです。

- `sql/migration-p1-61-reservation-arrival-followups.sql`
- `sql/migration-p1-62-reservation-waitlist-calls.sql`
- `sql/migration-p1-63-reservation-operational-safety.sql`
- `sql/migration-p1-64-reservation-autonomy.sql`
- `sql/migration-p1-65-takeout-operations.sql`
- `sql/migration-p1-66-takeout-customer-convenience.sql`
