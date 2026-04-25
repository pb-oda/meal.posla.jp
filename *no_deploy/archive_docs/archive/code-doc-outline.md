# POSLA エンジニア向けコードドキュメント — 目次構成（案）

> 対象読者：新規参画エンジニア / 外部開発者 / 将来の保守担当
> 方針：「読みやすさ最優先」。全ファイルを網羅しつつ、構造→詳細の順で理解できる
> 形式：HTML（コード例はシンタックスハイライト付き）

---

## 第1部：アーキテクチャ概要

### 1.1 技術スタック
- PHP（素、フレームワークなし）
- Vanilla JS（ES5 IIFE、var + コールバック、const/let/arrow禁止）
- MySQL 5.7
- なぜこの構成か（設計意図、制約の背景）

### 1.2 ディレクトリ構成
- 全体マップ（ツリー図）
- 各ディレクトリの役割

### 1.3 リクエストの流れ（図解）
- ブラウザ → HTML → JS → fetch → PHP API → MySQL → JSON応答
- 認証の挟まり方
- レスポンス形式（{ok, data, serverTime}）

### 1.4 マルチテナント設計
- tenant_id / store_id の関係
- テナント分離のルール（全クエリにtenant_id必須）
- ロール階層（owner > manager > staff）とアクセス制御

### 1.5 メニューの3層構造（Compositeパターン）
- テンプレート（HQ） → オーバーライド（店舗） → ローカルアイテム
- menu-resolver.php の合成ロジック
- 図解

### 1.6 決済アーキテクチャ
- 2つの決済パス（パターンA：自前Stripeキー / パターンB：POSLA Connect）
- 分岐ロジック（`get_stripe_auth_for_tenant()` in payment-gateway.php、process-payment.php）
- Stripe Billing（サブスクリプション）
- Stripe Connect（Express Account + Destination Charges）
- Stripe Terminal（JS SDK + ConnectionToken、パターンA/B両対応）

---

## 第2部：バックエンド（PHP API）

### 2.1 コアライブラリ（api/lib/）

#### 2.1.1 response.php — 統一レスポンス
- 関数一覧と使い方
- エラーレスポンスの形式
- CORSヘッダーの設定

#### 2.1.2 auth.php — 認証・認可
- セッション管理の仕組み
- require_auth() の動作
- require_role() の動作
- store_access_check() の動作
- セッションタイムアウト（D-1）

#### 2.1.3 db.php — データベース接続
- get_db() の使い方
- PDO設定（エラーモード、フェッチモード）

#### 2.1.4 audit-log.php — 監査ログ
- write_audit_log() のパラメータ
- ログ対象のアクション種別

#### 2.1.5 business-day.php — 営業日計算
- EODカットオフの概念
- 日跨ぎ営業の扱い

#### 2.1.6 rate-limiter.php — レート制限
- トークンバケットアルゴリズム
- 適用対象（パブリックエンドポイント）

#### 2.1.7 menu-resolver.php — メニュー合成
- resolve() の内部ロジック
- テンプレート + オーバーライド + ローカルのマージ順序

#### 2.1.8 order-items.php — 注文アイテム操作
- CRUD関数一覧
- オプション・トッピングの扱い

#### 2.1.9 payment-gateway.php — 決済ゲートウェイ
- get_stripe_auth_for_tenant() の分岐ロジック（パターンA/B/none）
- execute_stripe_connect_payment()
- create_stripe_connect_checkout_session()
- create_stripe_connect_terminal_intent()
- create_stripe_terminal_intent()（パターンA用、P1-1）

#### 2.1.10 stripe-billing.php — サブスクリプション
- create_checkout_session()
- create_portal_session()
- get_subscription_status()
- 価格テーブル（posla_plans）

#### 2.1.11 stripe-connect.php — Stripe Connect
- create_connect_account()
- create_account_link()
- create_terminal_connection_token()
- calculate_application_fee()

#### 2.1.12 inventory-sync.php — 在庫同期
- 在庫減算ロジック
- メニュー品切れとの連動

### 2.2 認証API（api/auth/）
- login.php — ログインフロー（入力検証 → パスワード照合 → セッション発行）
- check.php — セッション検証（タイムアウトチェック）
- me.php — ユーザー情報取得
- logout.php — ログアウト（セッション破棄 + 監査ログ）

### 2.3 顧客API（api/customer/）
- table-session.php — QRスキャン → テーブルセッション発行
- menu.php — 合成メニュー取得
- orders.php — 注文作成（冪等性キー）
- order-status.php — 注文ステータスポーリング
- order-history.php — セッション内注文履歴
- cart-event.php — カートイベントログ
- call-staff.php — スタッフ呼び出し
- ai-waiter.php — AIチャット（Gemini）
- satisfaction-rating.php — 満足度評価
- validate-token.php — トークン検証
- takeout-orders.php — テイクアウト注文
- takeout-payment.php — テイクアウト決済確認

### 2.4 KDS API（api/kds/）
- orders.php — 注文取得（ステーション別フィルタ）
- update-status.php — ステータス遷移（pending → preparing → ready）
- update-item-status.php — 個別アイテム完了
- advance-phase.php — コースフェーズ進行
- sold-out.php — 品切れ管理
- call-alerts.php — スタッフ呼び出しアラート
- cash-log.php — 開局・閉局
- close-table.php — テーブル会計完了
- ai-kitchen.php — AI調理優先度分析

### 2.5 店舗管理API（api/store/ — 35ファイル）
#### 2.5.1 テーブル・セッション管理
- tables.php, table-sessions.php, tables-status.php, active-sessions.php

#### 2.5.2 メニュー管理
- menu-version.php, menu-overrides.php, menu-overrides-csv.php
- local-items.php, local-items-csv.php

#### 2.5.3 コース・プラン管理
- course-phases.php, course-templates.php, course-csv.php
- time-limit-plans.php, time-limit-plans-csv.php
- plan-menu-items.php, plan-menu-items-csv.php

#### 2.5.4 日替わり・設定
- daily-recommendations.php, settings.php, staff-management.php

#### 2.5.5 注文・決済
- handy-order.php, process-payment.php, last-order.php
- order-history.php, payments-journal.php

#### 2.5.6 テイクアウト
- takeout-management.php

#### 2.5.7 レポート
- sales-report.php, register-report.php, staff-report.php
- turnover-report.php, basket-analysis.php, demand-forecast-data.php

#### 2.5.8 その他
- kds-stations.php, audit-log.php, places-proxy.php
- terminal-intent.php, upload-image.php

### 2.6 オーナーAPI（api/owner/ — 14ファイル）
- tenants.php, users.php, stores.php
- categories.php, menu-templates.php, menu-csv.php
- option-groups.php, recipes.php, recipes-csv.php
- ingredients.php, ingredients-csv.php
- course-templates.php, analytics-abc.php, cross-store-report.php
- upload-image.php

### 2.7 POSLA管理API（api/posla/ — 8ファイル）
- login.php, me.php, logout.php, auth-helper.php
- dashboard.php, tenants.php, settings.php, setup.php

### 2.8 Stripe Connect API（api/connect/ — 4ファイル）
- onboard.php, callback.php, status.php, terminal-token.php

### 2.9 サブスクリプションAPI（api/subscription/ — 4ファイル）
- checkout.php, portal.php, status.php, webhook.php

### 2.10 パブリックAPI（api/public/）
- store-status.php

---

## 第3部：フロントエンド（HTML + JS）

### 3.1 フロントエンドの設計原則
- ES5 IIFE パターンの解説
- DOM自己挿入パターン（ai-waiter.js, call-staff.js, voice-commander.js）
- fetch → res.text() → JSON.parse() パターン
- AdminApi / PoslaApi の使い方
- 5秒ポーリングパターン

### 3.2 共通ユーティリティ（shared/js/utils.js）
- 全関数一覧と説明
- formatYen(), escapeHtml(), getPresetRange(), generateUUID(), dateFormat()

### 3.3 グローバルモジュール
- offline-detector.js — オフライン検知バナー
- session-monitor.js — セッションタイムアウト管理
- theme-switcher.js — ダーク/ライトテーマ切り替え

### 3.4 顧客画面（public/customer/）
#### 3.4.1 menu.html — セルフオーダー画面
- HTML構造
- インラインスクリプトの初期化フロー
- cart-manager.js（localStorage永続化、オフラインファースト）
- order-sender.js（冪等送信、リトライロジック）
- order-tracker.js（ステータスポーリング、通知）
- ai-waiter.js（DOM自己挿入、Geminiチャット）
- call-staff.js（DOM自己挿入、呼び出しボタン）

#### 3.4.2 takeout.html — テイクアウト画面
- takeout-app.js のフロー

### 3.5 管理画面（public/admin/）
#### 3.5.1 index.html — ログイン・ロール判定
#### 3.5.2 dashboard.html — 店舗ダッシュボード（manager/staff）
- インラインスクリプトによるタブ制御
- 各タブモジュールの読み込みと初期化

#### 3.5.3 owner-dashboard.html — オーナーダッシュボード
- owner-app.js によるタブ制御
- store-selector.js（店舗ドロップダウン）
- 各タブモジュール一覧

#### 3.5.4 全エディタモジュール（26ファイル）
- admin-api.js, store-editor.js, user-editor.js
- category-editor.js, menu-template-editor.js, option-group-editor.js
- local-item-editor.js, menu-override-editor.js
- table-editor.js, floor-map.js
- kds-station-editor.js, daily-recommendation-editor.js
- course-editor.js, time-limit-plan-editor.js
- inventory-manager.js
- sales-report.js, register-report.js, staff-report.js
- turnover-report.js, basket-analysis.js
- abc-analytics.js, cross-store-report.js
- order-history.js, takeout-manager.js
- audit-log-viewer.js, settings-editor.js
- ai-assistant.js

### 3.6 ハンディPOS（public/handy/）
- handy-app.js — 注文入力フロー
- pos-register.js — レジ会計フロー
- handy-alert.js — 呼び出しアラート

### 3.7 KDS画面（public/kds/）
- kds-renderer.js — 3カラムかんばん
- polling-data-source.js — ポーリング抽象化（WebSocket対応準備）
- kds-auth.js — KDSログイン
- kds-alert.js — 呼び出しアラート
- cashier-renderer.js — レジUI
- cashier-app.js — キャッシャーメインアプリ（Stripe Terminal統合）
- ai-kitchen.js — AI調理優先度
- voice-commander.js — 音声コマンド（Web Speech API）
- sold-out-renderer.js — 品切れ管理UI

### 3.8 ライブディスプレイ（public/live/）
- index.html — サイネージ表示

### 3.9 POSLA管理画面（public/posla-admin/）
- posla-api.js — POSLA API クライアント
- posla-app.js — テナント管理・API設定

---

## 第4部：データベース

### 4.1 ER図（主要テーブルの関係）
- テナント → 店舗 → テーブル → 注文 の階層
- メニュー3層構造のテーブル関係
- ユーザー → ロール → 店舗アクセス

### 4.2 主要テーブル定義（schema.sql）
- tenants — テナント管理
- stores — 店舗
- users — ユーザー
- user_store_access — 店舗アクセス権
- menu_categories — カテゴリ
- menu_templates — HQメニューテンプレート
- menu_overrides — 店舗オーバーライド
- local_menu_items — 店舗ローカルメニュー
- option_groups / option_items — オプション
- orders / order_items — 注文
- table_sessions — テーブルセッション
- tables — テーブル
- kds_stations — KDSステーション
- payments — 決済履歴
- audit_log — 監査ログ
- recipes / ingredients — レシピ・食材
- course_templates / course_phases — コース
- time_limit_plans / plan_menu_items — 食べ放題
- subscription_events — サブスクリプション
- posla_settings — POSLA共通設定
- plan_features — プラン機能フラグ

### 4.3 マイグレーション履歴（時系列）
- 全40+マイグレーションの概要と適用順序
- 各スプリント（A〜N）で追加されたテーブル・カラム

### 4.4 インデックス設計
- 主要クエリとそのインデックス

### 4.5 データの流れ（図解）
- 注文フロー（customer → orders → order_items → KDS → payments）
- メニュー合成フロー（templates + overrides + local → resolved menu）
- 決済フロー（process-payment → payments → audit_log）

---

## 第5部：外部連携

### 5.1 Gemini API
- 接続設定（posla_settings.ai_api_key）
- 使用箇所（AIウェイター、AIキッチン、AI需要予測、SNS生成、音声コマンド解析）
- プロンプト設計のルール（temperature設定、前置き除去）

### 5.2 Google Places API
- サーバーサイドプロキシ（places-proxy.php）
- CORS回避 + APIキー非露出

### 5.3 Stripe
#### 5.3.1 Stripe Billing（サブスクリプション管理）
- Checkout Session → Webhook → DB更新
- Customer Portal（プラン変更・カード変更・解約）
#### 5.3.2 Stripe Connect（決済代行）
- Express Account作成 → オンボーディング
- Destination Charges + application_fee
#### 5.3.3 Stripe Terminal（対面決済端末）
- JS SDK統合（cashier-app.js）
- ConnectionToken発行

### 5.4 Web Speech API
- 音声認識（continuous mode）
- Chrome推奨
- 認識パターンとGemini解析

---

## 第6部：セキュリティ

### 6.1 認証・セッション管理
### 6.2 SQLインジェクション対策（プリペアドステートメント）
### 6.3 XSS対策（escapeHtml()）
### 6.4 CSRF対策
### 6.5 レート制限
### 6.6 APIキー管理（サーバーサイドのみ）
### 6.7 マルチテナント分離
### 6.8 監査ログ

---

## 第7部：開発ガイド

### 7.1 開発環境のセットアップ
### 7.2 ローカル開発の手順
### 7.3 コーディング規約（ES5ルール、IIFE必須、var only）
### 7.4 新しいAPIエンドポイントの追加方法
### 7.5 新しいフロントエンドモジュールの追加方法
### 7.6 DBマイグレーションの作成方法
### 7.7 テスト方法
### 7.8 デプロイ手順

---

## 付録

### A. 全ファイル一覧（227ファイル）
### B. API エンドポイント一覧（HTTP/Auth/概要）
### C. JSモジュール依存関係図
### D. DB テーブル一覧（カラム定義付き）
### E. 環境変数・設定項目一覧
### F. エラーコード一覧
