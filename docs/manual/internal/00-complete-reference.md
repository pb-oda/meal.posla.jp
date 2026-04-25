---
title: POSLA 全体リファレンス
---

# 0. POSLA 全体リファレンス

この章は、プラスビリーフ社内向けの「最初に読む」総合リファレンスです。顧客向けマニュアルでは省いた、API、DB、認証、テナント境界、決済、AI連携、運用、障害対応、テスト観点までを1本にまとめます。

## 0.1 このドキュメントの位置づけ

| 区分 | 公開先 | 目的 |
|---|---|---|
| 顧客向け | `/docs-tenant/` | 店舗様が日々の操作を理解するための丁寧な操作ガイド |
| 現場向け | `/operations/` | スタッフ教育、開店から閉店までの細かい手順 |
| 社内向け | `/docs-internal/` | POSLA運営・開発・保守担当が全体を理解するための詳細資料 |

社内向けビルドは `VP_MODE=internal vitepress build` で生成し、`public/docs-internal/` に出力され、配信 URL は `/docs-internal/` です。顧客向けビルドでは `internal/**`、`operations/**`、`tenant/99-error-catalog.md` を除外します。

## 0.2 技術スタックと制約

POSLAは、あえて小さな構成で作っています。フレームワークやビルドツールをアプリ本体へ導入しない前提です。

| 項目 | 採用 |
|---|---|
| サーバー | 素のPHP |
| フロント | Vanilla JS、ES5 IIFE |
| DB | MySQL 5.7 |
| ドキュメント | VitePress |
| 認証 | PHPセッション |
| APIレスポンス | `ok / data / error / serverTime` の統一形式 |
| 決済 | Stripe Billing、Stripe Connect、Stripe Terminal |
| AI | Gemini、Google PlacesはPOSLA共通キーで管理 |

守るべき制約:

- ES5互換を維持し、`const`、`let`、arrow function、`async/await` は使わない。
- JavaScriptはIIFEパターンを維持する。
- アプリ本体にはフレームワークを入れない。
- SQLは必ずプリペアドステートメントを使う。
- `innerHTML` へユーザー入力を入れる場合は必ずエスケープする。
- 認証・認可・レスポンス形式を勝手に変えない。

## 0.2.1 業務端末の標準環境 (2026-04-20 確定)

| 用途 | 標準環境 | サポート対象外 |
|---|---|---|
| KDS | Android タブレット 10〜11" + Chrome 最新版 | iPad/Safari, Fire, 古い Android (9 以前), Samsung Internet, アプリ内ブラウザ |
| レジ | Android タブレット + Chrome 最新版 (補: PC + Chrome/Edge) | iPad/Safari, Fire |
| ハンディ | Android スマホ/小型タブレット + Chrome 最新版 | iPhone/Safari, Fire, 古い Android |
| 管理画面 | PC + Chrome 最新版 (補: PC + Edge / Android Chrome) | IE, 古いブラウザ |
| 顧客セルフメニュー / 予約 / テイクアウト | iPhone/Android 問わず主要ブラウザ全般 | (制限なし、業務端末限定の警告は出さない) |

KDS / レジ / ハンディの 4 画面 (`public/kds/index.html`, `public/kds/cashier.html`, `public/handy/index.html`, `public/handy/pos-register.html`) には `public/shared/js/standard-env-banner.js` (ES5 IIFE) を読み込み、Android Chrome 以外でアクセスされた場合に控えめな注意バナーを画面上部に表示する。詳細は `09-pwa.md` の §9.10。

## 0.3 ディレクトリ構成

| パス | 役割 |
|---|---|
| `api/auth/` | ログイン、ログアウト、セッション、パスワード変更、端末登録 |
| `api/customer/` | お客様側セルフオーダー、テイクアウト、予約、AIウェイター |
| `api/store/` | 店舗管理画面、ハンディ、レジ、シフト、予約、レポート |
| `api/kds/` | KDS、調理ステータス、呼び出し、現金ログ |
| `api/owner/` | オーナーダッシュボード、店舗・ユーザー・本部メニュー・横断分析 |
| `api/posla/` | POSLA管理画面、共通設定、テナント管理、監視 |
| `api/subscription/` | POSLA月額課金、Checkout、Portal、Webhook |
| `api/connect/` | Stripe Connect、Terminal連携 |
| `api/smaregi/` | スマレジOAuth、店舗マッピング、商品取込、売上同期 |
| `api/lib/` | 認証、DB、レスポンス、監査ログ、共通ロジック |
| `public/admin/` | ログイン、管理ダッシュボード、オーナーダッシュボード |
| `public/customer/` | お客様セルフメニュー、テイクアウト、予約ページ |
| `public/handy/` | ハンディPOS、簡易レジ |
| `public/kds/` | KDS、会計、レジ、品切れ画面 |
| `public/posla-admin/` | POSLA運営専用管理画面 |
| `sql/` | ベーススキーマと追加マイグレーション |
| `docs/manual/` | 顧客向け・現場向け・社内向けマニュアル |
| `【擬似本番環境】meal.posla.jp/` | container-based の検証環境。本番化前の正本 |

## 0.4 画面と入口

| 入口 | 利用者 | 役割 |
|---|---|---|
| `public/admin/index.html` | オーナー、マネージャー、スタッフ | ログイン画面 |
| `public/admin/dashboard.html` | マネージャー、スタッフ | 店舗運営ダッシュボード |
| `public/admin/owner-dashboard.html` | オーナー | 本部管理、複数店舗管理 |
| `public/customer/menu.html` | 来店客 | テーブルQRからのセルフオーダー |
| `public/customer/takeout.html` | テイクアウト客 | テイクアウト注文 |
| `public/customer/reserve.html` | 予約客 | 予約受付 |
| `public/handy/index.html` | スタッフ | ハンディ注文 |
| `public/kds/index.html` | 厨房 | KDS |
| `public/kds/cashier.html` | レジ担当 | POSレジ |
| `public/posla-admin/index.html` | POSLA運営 | POSLA管理画面 |

ログイン後の基本リダイレクト:

- `owner` は `owner-dashboard.html`
- `manager` と `staff` は `dashboard.html`
- `device` は設定された用途によりKDS、レジ、ハンディへ誘導する

## 0.5 ロールと権限

ロール階層は `owner > manager > staff > device` です。

| ロール | レベル | 境界 |
|---|---:|---|
| `owner` | 3 | テナント内の全店舗に暗黙アクセス可能。ただし店舗が同一テナントか必ず検証する |
| `manager` | 2 | `user_stores` に紐づく店舗のみ |
| `staff` | 1 | `user_stores` に紐づく店舗のみ。管理機能は限定 |
| `device` | 0 | KDS、レジ、ハンディなど備品端末用。勤怠・人件費・スタッフ評価の対象外 |

1人1アカウントは必須です。共有アカウントは禁止です。監査ログ、勤怠、スタッフ評価、シフト、PIN認証がすべて「誰が操作したか」を前提にしているためです。

## 0.6 認証・セッション

認証の中心は `api/lib/auth.php` です。

セッションに持つ主な値:

| キー | 意味 |
|---|---|
| `user_id` | ユーザーID |
| `tenant_id` | テナントID |
| `role` | `owner`、`manager`、`staff`、`device` |
| `username` | ログインユーザー名 |
| `email` | 互換用。ログイン運用では必須ではない |
| `store_ids` | manager/staff/deviceがアクセスできる店舗ID一覧 |
| `login_time` | ログイン時刻 |

セッション仕様:

- Cookie TTLは8時間。
- 無操作30分でアイドルタイムアウト。
- `user_sessions` にセッションを記録し、リモート無効化やパスワード変更後の他端末遮断に使う。
- `last_active_at` は5分に1回更新。
- `SESSION_REVOKED`、`SESSION_TIMEOUT` はフロント側で再ログイン導線へつなぐ。

よく使う認証ヘルパー:

| 関数 | 役割 |
|---|---|
| `require_auth()` | ログイン必須 |
| `require_role($role)` | 最低ロール必須 |
| `require_store_access($store_id)` | 店舗アクセス権を検証 |
| `require_store_param()` | `store_id` を取得してアクセス権を検証 |
| `get_logged_in_user()` | 未ログイン許容で現在ユーザーを取得 |

## 0.7 マルチテナント境界

最重要ルールは、クエリに必ず `tenant_id` または `store_id` の境界を入れることです。

基本原則:

- テナント単位のデータは `tenant_id` で絞る。
- 店舗単位のデータは `store_id` で絞る。
- `owner` でも、対象店舗が同一テナントか検証する。
- `manager`、`staff`、`device` は `user_stores` の所属店舗だけ許可する。
- お客様側APIは未ログインで動くため、`store_id`、`table_id`、`session_token`、予約情報などの検証が特に重要。

境界を崩すと、他店舗の注文、売上、顧客、予約、勤怠が見える重大事故になります。新規APIを作る場合は、まず「このAPIの境界は tenant か store か customer session か」を決めてから実装します。

## 0.8 APIレスポンス規約

共通レスポンスは `api/lib/response.php` です。

成功:

```json
{
  "ok": true,
  "data": {},
  "serverTime": "2026-04-19T00:00:00+09:00"
}
```

失敗:

```json
{
  "ok": false,
  "error": {
    "code": "VALIDATION",
    "message": "入力内容を確認してください",
    "status": 400,
    "errorNo": "E2001"
  },
  "serverTime": "2026-04-19T00:00:00+09:00"
}
```

共通ルール:

- `send_api_headers()` でJSON、`no-store`、許可Originを設定する。
- CORS許可は `POSLA_ALLOWED_ORIGINS` env 経由で設定し、現在の canonical は `https://meal.posla.jp` です。詳細は `internal/production-api-checklist.md §9-0` を参照。
- `require_method()` でHTTPメソッドを限定する。
- `get_json_body()` は空ボディと不正JSONを統一エラーにする。
- `json_error()` は `error_log` テーブルへの記録も試みる。
- フロント側は `res.text()` から `JSON.parse()` する既存パターンを維持する。

## 0.9 APIディレクトリ別の責務

| ディレクトリ | 件数目安 | 主な責務 |
|---|---:|---|
| `api/auth/` | 6 | ログイン、ログアウト、me、端末登録、パスワード変更 |
| `api/customer/` | 26 | セルフオーダー、予約、テイクアウト、AIウェイター、満足度 |
| `api/store/` | 63 | 店舗管理、会計、シフト、予約、レポート、設定 |
| `api/kds/` | 9 | KDS表示、ステータス更新、呼び出し、現金ログ |
| `api/owner/` | 15 | オーナー向け店舗・ユーザー・メニュー・分析 |
| `api/posla/` | 10 | POSLA管理画面、テナント、共通設定、監視 |
| `api/connect/` | 5 | Stripe Connect、Terminal token |
| `api/subscription/` | 4 | 月額課金、Checkout、Portal、Webhook |
| `api/smaregi/` | 8 | スマレジOAuth、店舗連携、商品取込、売上同期 |
| `api/cron/` | 4 | 自動退勤、予約リマインド、予約クリーンアップ、ヘルス監視 |
| `api/lib/` | 20 | 共通ライブラリ |

新規API追加時のチェック:

1. `require_method()` を最初に置く。
2. 認証要否を決める。
3. `tenant_id` / `store_id` 境界を必ず入れる。
4. 全SQLをプリペアドステートメントにする。
5. 成功は `json_response()`、失敗は `json_error()` に統一する。
6. 既存レスポンス形式を壊さない。

## 0.10 DB全体像

ベースは `sql/schema.sql` です。既存の `schema.sql` と既存マイグレーションは編集せず、追加変更は新規マイグレーションで行います。

主要テーブル分類:

| 分類 | テーブル |
|---|---|
| テナント・店舗 | `tenants`, `stores`, `store_settings`, `posla_settings` |
| ユーザー・認証 | `users`, `user_stores`, `user_sessions`, `device_registration_tokens`, `posla_admins`, `posla_admin_sessions` |
| メニュー | `categories`, `menu_templates`, `store_menu_overrides`, `store_local_items`, `option_groups`, `option_choices`, `menu_template_options`, `local_item_options`, `store_option_overrides`, `menu_translations`, `ui_translations` |
| テーブル・注文 | `tables`, `table_sessions`, `table_sub_sessions`, `orders`, `order_items`, `order_rate_log`, `cart_events`, `call_alerts` |
| KDS | `kds_stations`, `kds_routing_rules` |
| 会計・決済 | `payments`, `cash_log`, `receipts`, `subscription_events` |
| プラン・コース | `time_limit_plans`, `plan_menu_items`, `course_templates`, `course_phases`, `reservation_courses` |
| 在庫 | `ingredients`, `recipes`, `daily_recommendations` |
| シフト | `shift_settings`, `shift_templates`, `shift_availabilities`, `shift_assignments`, `shift_help_requests`, `shift_help_assignments`, `attendance_logs` |
| 予約 | `reservation_settings`, `reservations`, `reservation_customers`, `reservation_holds`, `reservation_notifications_log` |
| 外部連携 | `smaregi_store_mapping`, `smaregi_product_mapping` |
| 監査・監視 | `audit_log`, `error_log`, `satisfaction_ratings` (R1で `reason_code`/`reason_text` 追加) |
| 旧互換・機能管理 | `plan_features` |

DBで特に注意すること:

- MySQL 5.7前提。8系専用構文は使わない。
- 文字化けした古いコメントが一部マイグレーションに残っているため、DDL変更時は実DBの文字セットも確認する。
- `plan_features` は現状の個別キー方式を保持するが、機能制限の主役ではない。
- 価格、返金、予約、在庫など金額・数量に関わる更新は二重実行とレース条件を疑う。

## 0.11 機能別の内部マップ

| 機能 | 画面 | 主なAPI | 主なDB | 注意点 |
|---|---|---|---|---|
| ログイン | `public/admin/index.html` | `api/auth/login.php`, `api/auth/me.php`, `api/auth/logout.php` | `users`, `user_sessions`, `user_stores` | username + password。メール不要 |
| 端末登録 | `public/admin/device-setup.html` | `api/auth/device-register.php`, `api/store/device-registration-token.php` | `device_registration_tokens`, `users` | deviceは人ではない |
| 管理画面 | `public/admin/dashboard.html` | `api/store/*` | 多数 | staffはシフト中心 |
| オーナー画面 | `public/admin/owner-dashboard.html` | `api/owner/*` | `tenants`, `stores`, `users`, `menu_templates` | ownerでも同一tenant検証必須 |
| メニュー | dashboard各メニューJS | `api/owner/menu-templates.php`, `api/store/menu-overrides.php`, `api/store/local-items.php` | メニュー系テーブル | 本部マスタと店舗上書きを混同しない |
| テーブル | dashboard、customer | `api/store/tables.php`, `api/store/table-open.php`, `api/customer/table-session.php` | `tables`, `table_sessions` | QRとテーブルの貼り間違いが事故になる |
| 注文 | customer、handy | `api/customer/orders.php`, `api/store/handy-order.php` | `orders`, `order_items` | サーバー側価格再計算を守る |
| KDS | `public/kds/index.html` | `api/kds/orders.php`, `api/kds/update-item-status.php` | `orders`, `order_items`, `kds_stations` | 3秒ポーリング。WebSocket前提ではない |
| 会計 | `public/kds/cashier.html` | `api/store/process-payment.php`, `api/store/refund-payment.php` | `payments`, `cash_log`, `receipts` | PIN必須操作と二重決済防止 |
| テイクアウト | `public/customer/takeout.html` | `api/customer/takeout-orders.php`, `api/store/takeout-management.php` | `orders`, `payments`, `store_settings` | オンライン決済と受取枠を確認 |
| コース | dashboard | `api/store/time-limit-plans.php`, `api/store/course-templates.php` | `time_limit_plans`, `course_templates`, `course_phases` | ラストオーダーと会計反映 |
| 在庫 | dashboard | `api/owner/ingredients.php`, `api/owner/recipes.php`, `api/lib/inventory-sync.php` | `ingredients`, `recipes` | 自動品切れ同期は販売画面へ影響 |
| シフト | dashboard | `api/store/shift/*` | シフト系テーブル、`attendance_logs` | deviceを人件費集計に入れない |
| レポート | dashboard、owner | `api/store/sales-report.php`, `api/owner/cross-store-report.php` | `orders`, `payments`, `audit_log` | 営業日区切りに注意 |
| 満足度・低評価分析 | dashboard 「満足度分析」、owner 横断レポート | `api/customer/satisfaction-rating.php` (送信), `api/store/rating-report.php` (店舗集計), `api/owner/cross-store-report.php` の `ratingSummary` | `satisfaction_ratings` (R1で `reason_code`/`reason_text` 追加) | 顧客評価の reason は rating ≤ 2 のみ保存。reason_code は許可リスト制 (`api/lib/rating-reasons.php`)、reason_text は trim+255文字+任意。低評価はスタッフ責任の断定でなく品目・時間帯・運営改善の参考データとして扱う。店舗別 (営業日区切り) と全店舗合算 (横断レポート簡易レンジ) で集計範囲が微妙に異なる |
| AI | dashboard、customer | `api/store/ai-generate.php`, `api/customer/ai-waiter.php`, `api/store/places-proxy.php` | `posla_settings`, 各分析元テーブル | Gemini/PlacesキーはPOSLA共通管理 |
| 予約 | customer、dashboard | `api/customer/reservation-create.php`, `api/store/reservations.php` | `reservations`, `reservation_settings`, `reservation_customers` | 二重予約と予約金の扱い |
| スマレジ | dashboard、API | `api/smaregi/*` | `smaregi_store_mapping`, `smaregi_product_mapping` | 店舗マッピングずれが売上ずれになる |
| POSLA管理 | `public/posla-admin/dashboard.html` | `api/posla/*` | `tenants`, `posla_settings`, `posla_admins` | 顧客画面と完全分離 |
| リアルタイム風更新 (RevisionGate) | KDS / ハンディ呼び出し | `api/store/revisions.php` (軽量チャネル: `kds_orders`, `call_alerts`) + `public/shared/js/revision-gate.js` | `orders.updated_at`, `order_items.updated_at`, `call_alerts.created_at`/`acknowledged_at` (DB変更なし) | WebSocket / SSE ではない。既存ポーリング (3秒/5秒) を維持したまま、変更が無ければ重い API をスキップする。失敗時はフェイルオープンで従来通り fetch。詳細は `internal/05-operations.md` §5.14b |
| 業務端末・周辺機器 | KDS / レジ / ハンディ | (該当 API なし。設定は端末側の OS / Chrome 側) | (DB なし) | 標準環境は Android 端末 + Chrome 最新版。iPad/Safari は業務端末としては推奨外 (管理画面の確認用途のみ可)。KDS では任意でマイク付き片耳 Bluetooth ヘッドセットを推奨 (動作保証外、特定型番案内はしない)。詳細は `tenant/23-kds-devices.md` / `internal/09-pwa.md` |
| Web Push 通知 (Phase 2a) | admin / owner / KDS / handy | `api/push/config.php`, `api/push/subscribe.php`, `api/push/unsubscribe.php`, `api/lib/push.php` (送信スタブ) | `push_subscriptions` (R-PWA2) | **Phase 2a は購読保存 + フロント UI + SW 受信 + バッジまで**。実送信 (VAPID JWT + AES-GCM 暗号化) は **Phase 2b** に分離 (Composer 未導入のため)。VAPID 鍵は `posla_settings.web_push_vapid_{public,private}` で POSLA 共通管理。秘密鍵はフロント絶対非露出。Push は補助通知でポーリング/RevisionGate を置換しない (fail-open)。詳細は `internal/09-pwa.md` §9.13 |

## 0.12 決済の全体像

決済は3系統あります。

| 系統 | 用途 | 主な入口 |
|---|---|---|
| Stripe Billing | POSLA月額利用料 | `api/subscription/*`, `api/signup/*` |
| Stripe Connect | 店舗のカード売上を店舗側へ入金 | `api/connect/*`, `api/lib/stripe-connect.php` |
| Stripe Terminal | 店頭カードリーダー決済 | `api/connect/terminal-token.php`, `api/store/terminal-intent.php`, `api/store/process-payment.php` |

注意点:

- POSLA利用料と店舗売上決済を混同しない。
- Webhookは冪等性を意識する。
- 決済成功後のDB更新は二重実行、途中失敗、返金との競合を疑う。
- 会計、返金、レジ開閉、入金、出金は担当スタッフPINの対象。

## 0.13 AI・Google Placesキー管理

GeminiとGoogle PlacesのAPIキーは、テナント個別ではなくPOSLA共通で管理します。

| キー | 管理場所 | 呼び出し方 |
|---|---|---|
| Gemini | `posla_settings` | サーバー側API経由 |
| Google Places | `posla_settings` | `api/store/places-proxy.php` 経由 |
| Stripe | POSLA共通または店舗別 | Billing、Connect、Terminalで使い分け |

ブラウザへAIキーを渡してはいけません。競合調査もPlaces APIを直接ブラウザから呼ばず、サーバーサイドプロキシを通します。

## 0.14 フロントエンド実装規約

守ること:

- ES5 IIFEを維持する。
- DOM自己挿入系モジュールのscriptタグを削除しない。
- 既存UIボタン・リンクは明示なしに削除しない。
- ユーザー入力を `innerHTML` に入れる場合はエスケープする。
- APIレスポンスは既存の `res.text()` から `JSON.parse()` の流れを維持する。

主なJS:

| 領域 | 主なファイル |
|---|---|
| 管理画面 | `public/admin/js/admin-api.js`, `owner-app.js`, `settings-editor.js`, `menu-*.js`, `reservation-board.js`, `shift-*.js` |
| 顧客画面 | `public/customer/js/cart-manager.js`, `order-sender.js`, `ai-waiter.js`, `reserve-app.js`, `takeout-app.js` |
| KDS | `public/kds/js/kds-auth.js`, `polling-data-source.js`, `kds-renderer.js`, `voice-commander.js`, `cashier-app.js` |
| 共通 | `public/js/session-monitor.js`, `offline-detector.js`, `attendance-clock.js` |
| POSLA管理 | `public/posla-admin/js/posla-api.js`, `posla-app.js` |

## 0.15 PWA方針

現時点の方針:

- 顧客側セルフメニューはブラウザ利用でよい。
- 店舗スタッフ向けの管理画面、KDS、ハンディはPWA化対象。
- `meal.posla.jp(正)` 側では `public/admin/`, `public/kds/`, `public/handy/` に `manifest.webmanifest`, `sw.js`, `pwa-register.js` が追加済み。
- Service Workerはスコープを狭くし、APIレスポンスはキャッシュしない。
- PWA確認時は、デスクトップとモバイル相当でインストール可能性、オフライン時の表示、API非キャッシュを確認する。

## 0.16 監査ログ・エラーログ・監視

| 仕組み | 役割 |
|---|---|
| `audit_log` | 重要操作を記録する。会計、返金、設定変更、ユーザー変更など |
| `error_log` | `json_error()` でAPIエラーを記録する |
| `api/store/error-stats.php` | 店舗側のエラー集計 |
| `api/posla/monitor-events.php` | POSLA管理画面の監視イベント |
| `api/cron/monitor-health.php` | ヘルス監視 |

障害対応では、画面のエラーだけで判断せず、APIステータス、`error_log`、`audit_log`、決済ログ、サーバーログを突き合わせます。

## 0.17 よくある重大事故パターン

| パターン | 原因 | 防止策 |
|---|---|---|
| 他店舗データが見える | `tenant_id` / `store_id` 境界漏れ | APIレビューで境界条件を必須確認 |
| 二重決済 | 決済完了後の再送、途中失敗 | 冪等性キー、既存決済確認、pending状態管理 |
| 返金の記録漏れ | 決済側とPOSLA側の片方だけ更新 | 決済ID、返金ID、監査ログをセットで確認 |
| レジ差額 | 入金・出金・返金の記録漏れ | レジ開閉時に現金ログを確認 |
| 注文がKDSに出ない | 店舗選択、KDSステーション、通信 | `orders` と `order_items`、KDS APIを確認 |
| 予約二重登録 | 同時刻・同テーブルの競合 | 予約可用性チェックとユニーク制約 |
| スタッフ集計に端末が混ざる | `device` ロール除外漏れ | 勤怠・人件費・評価SQLでdevice除外 |
| AIキー漏洩 | ブラウザから直接APIキー使用 | 必ずサーバー側プロキシを使う |

## 0.18 スモークテスト観点

本番相当確認では、最低限以下を通します。

### API・PHP

1. `api/**/*.php` のPHP構文チェック。
2. GET可能なAPIのステータス確認。
3. 認証必須APIが未ログインで401/403を返すこと。
4. 顧客向けAPIが必要パラメータ不足で適切に400を返すこと。
5. `json_error()` のレスポンス形式と `errorNo` を確認。

### 画面

1. ログイン画面。
2. オーナーダッシュボード。
3. 管理ダッシュボード。
4. セルフメニュー。
5. ハンディ。
6. KDS。
7. レジ。
8. POSLA管理画面。

### 機能

1. メニュー表示。
2. テーブルQRから注文。
3. KDS反映。
4. 会計。
5. 返金。
6. テイクアウト注文。
7. 予約作成。
8. 勤怠打刻。
9. シフト作成。
10. レポート表示。
11. AIアシスタント。
12. 決済設定。
13. プリンタテスト。

### ドキュメント

1. `npm run build:tenant`
2. `npm run build:internal`
3. `public/docs-tenant/` に内部向けページが混入していないこと。
4. `public/docs-internal/` に社内向けページが出ていること。
5. 内部リンク切れがないこと。

## 0.19 リリース・変更時の確認

変更時の標準手順:

1. 変更対象と影響範囲を明示する。
2. 既存のAPIインターフェースを変えない。
3. DB変更が必要なら新規マイグレーションを作る。
4. 認証・認可・テナント境界をレビューする。
5. 機能単体テストを行う。
6. 代表画面でスモークテストを行う。
7. 顧客向けマニュアルと社内向けマニュアルの差分を確認する。
8. デプロイ対象ファイルを明示する。

特に、決済、会計、返金、予約、在庫、シフト、認証に触れた場合は、関連機能の横断テストを省略しないでください。

## 0.20 ドキュメント運用

ドキュメントは3層で運用します。

| 層 | 原稿 | 出力 | 方針 |
|---|---|---|---|
| 顧客向け | `docs/manual/tenant/` | `public/docs-tenant/` | 丁寧な操作説明。API/DB/内部事情は出さない |
| 現場向け | `docs/manual/operations/` | internal側のみ公開 | スタッフ教育用。細かい手順を許容 |
| 社内向け | `docs/manual/internal/` | `public/docs-internal/` | API、DB、運用、障害対応、実装判断まで書く |

顧客向けで消した情報は、消滅させず、社内向けの本章または該当する運用章へ移すのが原則です。

## 0.21 参照すべき詳細章

| 目的 | 参照 |
|---|---|
| POSLA管理画面 | [1. POSLA管理画面](./01-posla-admin.md) |
| テナント作成・導入 | [2. テナントオンボーディング](./02-onboarding.md) |
| 課金 | [3. 課金・サブスクリプション](./03-billing.md) |
| 決済設定 | [4. 決済設定](./04-payment.md) |
| システム運用 | [5. システム運用](./05-operations.md) |
| 障害対応 | [6. 運用トラブルシューティング](./06-troubleshoot.md) |
| 監視 | [7. 監視・アラート](./07-monitor.md) |
| サインアップ | [8. サインアップフロー](./08-signup-flow.md) |
| エラー番号 | [99. エラーカタログ](./99-error-catalog.md) |
