---
feature_id: php-coverage-matrix
title: PHP ファイル docs 網羅性マトリクス
chapter: 96
plan: [base]
role: [posla_admin]
audience: internal
keywords: [audit, documentation, coverage, php, traceability, pre-production]
last_updated: 2026-04-23
maintainer: posla
---

# 96. PHP Coverage Matrix（実装ファイル ↔ docs 対応表）

本章は `api/**/*.php` と `scripts/**/*.php` の**全 PHP ファイル**が docs のどこで説明されているか／されていないかを**機械的に突合**した内部監査用トレーサビリティ台帳である。本番複製前に高優先度の未記載を解消するための作業入口として使う。

**97 章 (doc-audit) との違い:** 97 章は「人手で深堀りした gap 報告」、本章は「全件機械的マトリクス」。相補関係。

---

## 96.0 スコープと判定基準

### スコープ
- **対象コード**: `api/**/*.php` (209) + `scripts/**/*.php` (3) = **212 ファイル**
- **対象 docs**:
  - `docs/manual/internal/**/*.md`
  - `docs/manual/tenant/**/*.md`
  - `docs/manual/operations/**/*.md`
  - `docs/SYSTEM_SPECIFICATION.md`
  - `scripts/output/helpdesk-prompt-internal.txt`
  - `scripts/output/helpdesk-prompt-tenant.txt`
- **判定方式**: 各 PHP の `basename` を docs 内に全文検索（`grep -F -l`）してマッチファイル数を計測
- **実施日**: 2026-04-23

### coverage_status の判定ルール
- **🔴 missing**: 全 docs source で 0 件
- **🟡 partial**: manual 章 (internal/tenant/operations) で合計 1〜2 ファイル、または SYSTEM_SPEC / helpdesk-prompt にのみ言及
- **✅ documented**: manual 章で合計 **3 ファイル以上** に言及

**重要な限界**: 「存在として言及」レベルの判定しか行わない。1 行の caller コメントと深い運用節は同じ "1 hit" として数える。実運用手順の質は別途精査が必要。97 章 §97.X はその精査結果の代表例。

### priority の判定ルール
- **high**: 金銭・認証・予約・LINE・subscription・cron・運用監視・本番移行・Stripe に直結 (paths に `auth|payment|cashier|checkout|stripe|reservation|line-|cron|monitor|push|posla|signup|rate-limiter|password|emergency|terminal|sub-sessions` 等)
- **medium**: 管理補助・CSV・同期・店舗設定 (owner/**, shift/**, translate, menu-*, smaregi, staff-report 等)
- **low**: 補助スクリプト・業務日計算・画像アップ・config (`business-day`, `scripts/`, `config/`, `upload-image`)

---

## 96.1 Q1. 全 PHP ファイル数

| カテゴリ | ファイル数 |
|---|---:|
| `api/auth/` | 6 |
| `api/customer/` | 27 |
| `api/store/` | 66 |
| `api/store/shift/` | 9 |
| `api/owner/` | 17 |
| `api/owner/shift/` | 1 |
| `api/kds/` | 9 |
| `api/lib/` | 30 |
| `api/connect/` | 5 |
| `api/subscription/` | 4 |
| `api/line/` | 1 |
| `api/cron/` | 4 |
| `api/monitor/` | 1 |
| `api/push/` | 4 |
| `api/smaregi/` | 8 |
| `api/posla/` | 11 |
| `api/signup/` | 3 |
| `api/public/` | 1 |
| `api/config/` | 2 |
| `scripts/` | 3 |
| **計** | **212** |

---

## 96.2 coverage_status × priority 分布

| status ＼ priority | high | medium | low | 計 |
|---|---:|---:|---:|---:|
| ✅ documented | 56 | 21 | 1 | **78** |
| 🟡 partial | 52 | 62 | 6 | **120** |
| 🔴 missing | 13 | 1 | 0 | **14** |
| 計 | 121 | 84 | 7 | **212** |

### 粗いシェア
- **documented**: 37% (78/212) — 3 章以上で言及されている「概ね覚えがある」群
- **partial**: 57% (120/212) — 1〜2 章でしか触れられていない「仕様書リンクはあるが運用手順が薄い」群
- **missing**: 7% (14/212) — どの docs にも basename が出てこない「完全未記載」群

---

## 96.3 Q2. docs で明示的に説明されている主要 PHP

**✅ documented (78件)** のうち high priority (56件) の上位評価 (internal ≥ 4 言及):

- `api/customer/checkout-confirm.php` (internal×6) / `checkout-session.php` (×4) / `orders.php` (×6) / `table-session.php` (×4) / `takeout-orders.php` (×5) / `takeout-payment.php` (×4)
- `api/store/process-payment.php` (×7) / `refund-payment.php` (×9) / `payment-void.php` (×4) / `emergency-payments.php` (×5) / `emergency-payment-manual-transfer.php` (×4) / `receipt.php` (×4) / `staff-management.php` (×4) / `terminal-intent.php` (×3) / `sub-sessions.php` (×3) / `tables-status.php` (×3)
- `api/kds/update-status.php` (×4) / `update-item-status.php` (×4) / `orders.php` (×6) / `close-table.php` (×5)
- `api/auth/login.php` (×7) / `device-register.php` (×3) / `change-password.php` (×3)
- `api/lib/auth.php` (×7) / `payment-gateway.php` (×4) / `rate-limiter.php` (×4) / `response.php` (×4)
- `api/connect/status.php` (×7) / `onboard.php` (×4) / `terminal-token.php` (×3) / `disconnect.php` (×3)
- `api/subscription/status.php` (×7) / `webhook.php` (×6) / `checkout.php` (×3) / `portal.php` (×3)
- `api/signup/register.php` (×6) / `webhook.php` (×6) / `activate.php` (×3)
- `api/smaregi/auth.php` (×7) / `status.php` (×7) / `disconnect.php` (×3)
- `api/posla/login.php` (×7) / `settings.php` (×5) / `tenants.php` (×4) / `monitor-events.php` (×5)
- `api/cron/monitor-health.php` (×5) / `api/monitor/ping.php` (×6)
- `api/push/subscribe.php` (×4) / `config.php` (×3) / `test.php` (×3)
- `api/line/webhook.php` (×6)

これらは「**基幹の money / 認証 / KDS / サブスクリプション / 外部連携**」の中核で、docs ↔ 実装の突合が複数章から可能。

---

## 96.4 Q3 + Q4. 未記載 / 説明不足 (priority 別)

### 96.4.1 🔴 High Priority Missing (13件)

**本番移行前に docs 化必須の最優先群。** すべて **L-9 予約管理** と **L-17 LINE 連携** の内部ライブラリ／管理 API。

| path | purpose |
|---|---|
| `api/customer/takeout-line-token.php` | L-17 Phase 3A: テイクアウト LINE ひも付け one-time token (customer API) |
| `api/lib/line-link-token.php` | LINE ひも付け one-time token helper (L-17 Phase 2A-2) |
| `api/lib/line-link.php` | LINE 顧客ひも付け helper (L-17 Phase 2A-1) |
| `api/lib/line-messaging.php` | LINE Messaging API 薄ラッパ (L-17 Phase 2B-1) |
| `api/lib/reservation-deposit.php` | L-9 予約管理 — Stripe デポジット (前金決済) ライブラリ |
| `api/lib/reservation-notifier.php` | L-9 予約管理 — メール送信ライブラリ |
| `api/lib/takeout-line-link.php` | L-17 Phase 3A: テイクアウト LINE ひも付け helper |
| `api/lib/takeout-line-token.php` | L-17 Phase 3A: テイクアウト one-time link token helper |
| `api/owner/line-customer-links.php` | LINE 顧客ひも付け owner API (L-17 Phase 2A-1) |
| `api/owner/line-link-tokens.php` | LINE ひも付け one-time token owner API (L-17 Phase 2A-2) |
| `api/owner/line-settings.php` | tenant 単位 LINE 連携設定 API (owner 専用) |
| `api/store/reservation-courses.php` | L-9 予約管理 — コース管理 |
| `api/store/reservation-stats.php` | L-9 予約管理 — 予約レポート |

**構成:**
- LINE 連携 (L-17): **9 ファイル** (customer ×1 + lib ×5 + owner ×3)
- 予約管理 (L-9): **4 ファイル** (lib ×2 + store ×2)

> 既存 `api/line/webhook.php` のみが ✅ documented (internal×6)。L-17 の**土台だけ** docs 化されていて、**実際の顧客ひも付けフロー/one-time token/Messaging API 薄ラッパ/テイクアウト連携**が全て未記載。

### 96.4.2 🔴 Medium Priority Missing (1件)

| path | purpose |
|---|---|
| `api/store/weekly-summary.php` | RPT-P1-1: 週次サマリー API |

RPT-P1-1 は最近完了 (tasks.md Top 10 の #7) で、実装直後のため docs 反映がまだ。owner-dashboard 「週次サマリー」カードの説明として operations manual への 1 段落追記で解消可能。

### 96.4.3 🟡 High Priority Partial (52件) — 要深化

1〜2 章のみ言及で運用手順としては薄い「基幹だが docs 薄」群。次スプリント以降で深化候補。

**主な塊:**
1. **L-9 予約管理の客側 API (8件)**: `customer/reservation-*.php` 全て partial (internal×1〜2)
2. **L-9 予約管理の店舗側 API (6件)**: `store/reservation-{customers,no-show,notify,seat,settings,table-open}.php` が partial
3. **予約 cron (2件)**: `cron/reservation-{cleanup,reminders}.php` は internal×1 のみ (運用手順なし)
4. **PWA 緊急会計 Phase 4 系 (5件)**: `emergency-payment-{external-method,ledger,manual-void,resolution,transfer-void}.php` が internal×2 のみ
5. **Core lib (基幹支援)**: `audit-log.php` `db.php` `inventory-sync.php` `menu-resolver.php` `password-policy.php` `stripe-billing.php` `stripe-connect.php` が internal×1〜2
6. **認証・セッション周辺**: `auth/check.php` (spec only)、`customer/validate-token.php` (×1)、`store/active-sessions.php` (spec only)、`store/set-cashier-pin.php` (×1)

詳細な 52 件リストは §96.6 のカテゴリ別テーブル参照。

---

## 96.5 Q5. docs が十分な範囲 / 未整備の範囲

### ✅ 十分 (documented ≥ 3 章、かつ 97 章で P0/P1 指摘なし)
- **セルフ会計フロー** (customer/checkout-*, table-session, get-bill, orders, takeout-*)
- **POS レジ会計** (store/process-payment, refund-payment, payment-void)
- **Stripe Connect オンボーディング〜運用** (connect/onboard, status, terminal-token, disconnect)
- **サブスクリプション課金** (subscription/**)
- **A5 新規申込** (signup/**)
- **スマレジ連携 (OAuth)** (smaregi/auth, status, disconnect)
- **認証コア** (auth/login, logout, me, device-register, change-password)
- **POSLA 管理者機能** (posla/login, tenants, settings, monitor-events)
- **KDS 基本操作** (kds/update-status, update-item-status, orders, close-table)
- **PWA 緊急レジ (メイン同期 API + 手入力/転記 メイン)** (emergency-payments, emergency-payment-transfer, emergency-payment-manual-transfer)

### 🟡 部分的 (partial 最多 — 存在言及あり、運用手順が弱い)
- **L-9 予約管理 全域** (customer + store + cron + lib、14+ ファイルが partial or missing)
- **PWA 緊急会計 Phase 4d-* サブ API** (5 件 partial)
- **シフト管理 L-3 全般** (settings のみ documented、他 9 件 partial)
- **スマレジ CSV / 差分 / 注文同期** (callback, import-menu, store-mapping, stores, sync-order)
- **owner 管理 CSV 系** (categories, ingredients-csv, menu-csv, recipes-csv, option-groups, recipes)

### 🔴 未整備 (即 docs 追加必要)
- **L-17 LINE 連携** (webhook only、顧客ひも付けフロー 9 件が完全未記載)
- **L-9 予約管理の Stripe デポジット / Notifier ライブラリ** (4 件未記載)
- **RPT-P1-1 週次サマリー** (1 件、直近実装分の docs 反映待ち)

---

## 96.6 カテゴリ別マトリクス (全 212 件)

<!-- 表の見方:
  status: ✅=documented / 🟡=partial / 🔴=missing
  priority: **high** (金銭/認証/予約/LINE/subscription/cron/監視/Stripe) / medium / low
  evidence: どの docs source に何ファイル言及があるか。spec = SYSTEM_SPECIFICATION.md
  ※ evidence 数は helpdesk-prompt-internal.txt を除いた独立 source のみカウント
-->

### auth (6)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| ✅ documented | **high** | `api/auth/change-password.php` | P1-5: 自己パスワード変更API（テナントユーザー向け） | internal×3, spec |
| 🟡 partial | **high** | `api/auth/check.php` | セッション確認 API（D-1: セッションタイムアウト対応） | spec |
| ✅ documented | **high** | `api/auth/device-register.php` | F-DA1: デバイス登録（ワンタイムトークン方式） | internal×3 |
| ✅ documented | **high** | `api/auth/login.php` | ログイン API | internal×7, spec |
| ✅ documented | **high** | `api/auth/logout.php` | ログアウト API | internal×3, spec |
| ✅ documented | **high** | `api/auth/me.php` | 現在ユーザー情報 API | internal×3, spec |

### customer (27)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | medium | `api/customer/ai-waiter.php` | AIウェイター API（認証不要） | internal×2, spec |
| ✅ documented | medium | `api/customer/call-staff.php` | スタッフ呼び出し API（認証不要） | internal×3, spec |
| 🟡 partial | **high** | `api/customer/cart-event.php` | カート操作ログ API（認証なし） | internal×2, spec |
| ✅ documented | **high** | `api/customer/checkout-confirm.php` | L-8: セルフレジ — 決済確認 + 注文完了処理 | internal×6, spec |
| ✅ documented | **high** | `api/customer/checkout-session.php` | L-8: セルフレジ — Stripe Checkout Session 作成 | internal×4, spec |
| ✅ documented | **high** | `api/customer/get-bill.php` | L-8: セルフレジ — 未払い注文一覧取得 | internal×3, spec |
| 🟡 partial | **high** | `api/customer/menu.php` | 顧客用メニュー取得 API（認証なし） | internal×2, spec |
| 🟡 partial | **high** | `api/customer/order-history.php` | 顧客注文履歴取得 API（認証なし） | internal×1, spec |
| 🟡 partial | **high** | `api/customer/order-status.php` | 顧客注文ステータス ポーリング API（認証なし） | internal×2, spec |
| ✅ documented | **high** | `api/customer/orders.php` | 顧客注文送信 API（認証なし） | internal×6, spec |
| ✅ documented | medium | `api/customer/receipt-view.php` | L-8: セルフレジ — 顧客向け領収書データ取得 | internal×3, spec |
| 🟡 partial | **high** | `api/customer/reservation-ai-parse.php` | L-9 予約管理 (客側) — AI 自由文 → 予約情報抽出 | internal×1 |
| 🟡 partial | **high** | `api/customer/reservation-availability.php` | L-9 予約管理 (客側) — 空席計算 + 店舗設定取得 | internal×2 |
| 🟡 partial | **high** | `api/customer/reservation-cancel.php` | L-9 予約管理 (客側) — キャンセル | internal×1 |
| 🟡 partial | **high** | `api/customer/reservation-create.php` | L-9 予約管理 (客側) — 予約作成 + ホールド消費 | internal×2 |
| 🟡 partial | **high** | `api/customer/reservation-deposit-checkout.php` | L-9 予約管理 (客側) — デポジット決済の Checkout URL 再発行 | internal×1 |
| 🟡 partial | **high** | `api/customer/reservation-deposit-webhook.php` | L-9 予約管理 — Stripe Webhook (デポジット決済結果) | internal×1 |
| 🟡 partial | **high** | `api/customer/reservation-detail.php` | L-9 予約管理 (客側) — 予約照会 | internal×2 |
| 🟡 partial | **high** | `api/customer/reservation-precheckin.php` | L-9 予約管理 (客側) — 事前チェックイン (来店前のメニュー閲覧 + 注文準備) | internal×2 |
| 🟡 partial | **high** | `api/customer/reservation-update.php` | L-9 予約管理 (客側) — 予約変更 | internal×2 |
| ✅ documented | medium | `api/customer/satisfaction-rating.php` | 満足度評価送信 API（認証なし） | internal×5, spec |
| ✅ documented | **high** | `api/customer/table-session.php` | テーブルセッション API（認証なし） | internal×4, spec |
| 🔴 missing | **high** | `api/customer/takeout-line-token.php` | L-17 Phase 3A: テイクアウト LINE ひも付け one-time token (customer API) | — |
| ✅ documented | **high** | `api/customer/takeout-orders.php` | テイクアウト注文 API（顧客向け・認証不要） | internal×5, spec |
| ✅ documented | **high** | `api/customer/takeout-payment.php` | テイクアウト オンライン決済確認 API（顧客向け・認証不要） | internal×4, spec |
| 🟡 partial | medium | `api/customer/ui-translations.php` | L-4: UI翻訳取得 API（認証不要） | internal×1, spec |
| 🟡 partial | **high** | `api/customer/validate-token.php` | セッショントークン検証 API（認証なし・軽量） | internal×1, spec |

### kds (9)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | **high** | `api/kds/advance-phase.php` | コースフェーズ手動発火 API | internal×1, spec |
| 🟡 partial | **high** | `api/kds/ai-kitchen.php` | AIシェフ API | internal×1, spec |
| ✅ documented | medium | `api/kds/call-alerts.php` | 呼び出しアラート API（認証必要：device 以上） | internal×3, spec |
| 🟡 partial | **high** | `api/kds/cash-log.php` | レジ開け/締め API | internal×1, spec |
| ✅ documented | medium | `api/kds/close-table.php` | テーブル会計 API (※ 97.2.1 で deprecated 指摘あり) | internal×5, spec |
| ✅ documented | medium | `api/kds/orders.php` | KDS注文ポーリング API | internal×6, spec |
| 🟡 partial | medium | `api/kds/sold-out.php` | KDS 品切れ管理 API | internal×2, spec |
| ✅ documented | **high** | `api/kds/update-item-status.php` | KDS品目単位ステータス更新 API | internal×4, spec |
| ✅ documented | **high** | `api/kds/update-status.php` | KDS注文ステータス更新 API | internal×4, spec |

### store (66)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | **high** | `api/store/active-sessions.php` | アクティブセッション管理 API（S-3） | spec |
| ✅ documented | medium | `api/store/ai-generate.php` | Gemini API プロキシ | internal×5, spec |
| 🟡 partial | **high** | `api/store/audit-log.php` | 監査ログ閲覧 API（S-2 Phase 2） | internal×1, spec |
| 🟡 partial | medium | `api/store/basket-analysis.php` | 併売分析（バスケット分析）API | spec |
| 🟡 partial | medium | `api/store/course-csv.php` | コース料理 CSV エクスポート/インポート API | spec |
| 🟡 partial | medium | `api/store/course-phases.php` | コースフェーズ API | internal×1, spec |
| 🟡 partial | medium | `api/store/course-templates.php` | コース料理テンプレート API | internal×2, spec |
| 🟡 partial | medium | `api/store/daily-recommendations.php` | 今日のおすすめ管理 API（スタッフ以上） | spec |
| 🟡 partial | medium | `api/store/demand-forecast-data.php` | 需要予測用データ集計 API | spec |
| 🟡 partial | medium | `api/store/device-registration-token.php` | F-DA1: デバイス登録トークン生成 API | internal×2 |
| 🟡 partial | **high** | `api/store/emergency-payment-external-method.php` | 緊急会計 外部分類編集 API (PWA Phase 4d-3) | internal×2 |
| 🟡 partial | **high** | `api/store/emergency-payment-ledger.php` | 緊急会計台帳 読み取り API (PWA Phase 4b-2) | internal×2 |
| ✅ documented | **high** | `api/store/emergency-payment-manual-transfer.php` | 緊急会計 手入力売上計上 API (PWA Phase 4d-4a) | internal×4 |
| 🟡 partial | **high** | `api/store/emergency-payment-manual-void.php` | 緊急会計 手入力計上 void API (PWA Phase 4d-5a) | internal×2 |
| 🟡 partial | **high** | `api/store/emergency-payment-resolution.php` | 緊急会計 管理者解決 API (PWA Phase 4c-1) | internal×2 |
| 🟡 partial | **high** | `api/store/emergency-payment-transfer-void.php` | 緊急会計 注文紐付き転記 void API (PWA Phase 4d-5b) | internal×2 |
| ✅ documented | **high** | `api/store/emergency-payment-transfer.php` | 緊急会計 → 通常 payments 転記 API (PWA Phase 4c-2) | internal×3 |
| ✅ documented | **high** | `api/store/emergency-payments.php` | 緊急レジモード同期 API (PWA Phase 4) | internal×5 |
| ✅ documented | medium | `api/store/error-stats.php` | Phase B (CB1): エラー統計 API | internal×3 |
| ✅ documented | medium | `api/store/handy-order.php` | ハンディ注文 API（スタッフ以上） | internal×5, spec |
| 🟡 partial | medium | `api/store/kds-stations.php` | KDSステーション管理 API | spec |
| 🟡 partial | medium | `api/store/last-order.php` | ラストオーダー管理 API (O-3) | internal×1, spec |
| 🟡 partial | medium | `api/store/local-items-csv.php` | 店舗限定メニュー CSV API | spec |
| 🟡 partial | medium | `api/store/local-items.php` | 店舗限定メニュー API | internal×1, spec |
| 🟡 partial | medium | `api/store/menu-overrides-csv.php` | 店舗メニューオーバーライド CSV API | spec |
| 🟡 partial | medium | `api/store/menu-overrides.php` | 店舗メニューオーバーライド API | internal×1, spec |
| 🟡 partial | medium | `api/store/menu-version.php` | メニュー変更検知（軽量エンドポイント） | spec |
| 🟡 partial | medium | `api/store/order-history.php` | 注文履歴 API（ページネーション付き） | internal×1, spec |
| ✅ documented | **high** | `api/store/payment-void.php` | 通常会計 payments void API (PWA Phase 4d-5c-ba) | internal×4 |
| 🟡 partial | **high** | `api/store/payments-journal.php` | 取引ジャーナル API | internal×2, spec |
| ✅ documented | medium | `api/store/places-proxy.php` | Google Places / Geocoding プロキシ API | internal×3, spec |
| 🟡 partial | medium | `api/store/plan-menu-items-csv.php` | プラン専用メニュー CSV API | spec |
| 🟡 partial | medium | `api/store/plan-menu-items.php` | 食べ放題プラン専用メニュー API | internal×1, spec |
| ✅ documented | **high** | `api/store/process-payment.php` | POSレジ会計処理 API | internal×7, spec |
| 🟡 partial | medium | `api/store/rating-report.php` | 満足度・低評価分析レポート (R1) | internal×1 |
| 🟡 partial | **high** | `api/store/receipt-settings.php` | L-5: 領収書/インボイス設定 API | internal×1, spec |
| ✅ documented | **high** | `api/store/receipt.php` | L-5: 領収書/インボイス レコード管理 API | internal×4, spec |
| ✅ documented | **high** | `api/store/refund-payment.php` | C-R1: 返金 API | internal×9 |
| 🟡 partial | medium | `api/store/register-report.php` | レジ分析 API | internal×2, spec |
| 🔴 missing | **high** | `api/store/reservation-courses.php` | L-9 予約管理 — コース管理 | — |
| 🟡 partial | **high** | `api/store/reservation-customers.php` | L-9 予約管理 — 顧客台帳 | internal×1 |
| 🟡 partial | **high** | `api/store/reservation-no-show.php` | L-9 予約管理 — no-show 処理 | internal×1 |
| 🟡 partial | **high** | `api/store/reservation-notify.php` | L-9 予約管理 — 手動通知再送 | internal×1 |
| 🟡 partial | **high** | `api/store/reservation-seat.php` | L-9 予約管理 — 着席処理 | internal×2 |
| 🟡 partial | **high** | `api/store/reservation-settings.php` | L-9 予約管理 — 店舗別設定 | internal×1 |
| 🔴 missing | **high** | `api/store/reservation-stats.php` | L-9 予約管理 — 予約レポート | — |
| ✅ documented | **high** | `api/store/reservations.php` | L-9 予約管理 — 店舗側 CRUD API | internal×3 |
| ✅ documented | medium | `api/store/revisions.php` | 店舗リビジョン取得 API（ポーリング軽量ゲート） | internal×3 |
| ✅ documented | medium | `api/store/sales-report.php` | 売上レポート API | internal×3, spec |
| 🟡 partial | **high** | `api/store/set-cashier-pin.php` | CP1: 担当スタッフ PIN 設定 API | internal×1 |
| ✅ documented | medium | `api/store/settings.php` | 店舗設定 API | internal×5, spec |
| ✅ documented | **high** | `api/store/staff-management.php` | スタッフ管理 API（manager用） | internal×4, spec |
| 🟡 partial | medium | `api/store/staff-report.php` | スタッフ別評価 + 監査・不正検知レポート API | spec |
| ✅ documented | **high** | `api/store/sub-sessions.php` | F-QR1: サブセッション管理 API | internal×3 |
| 🟡 partial | **high** | `api/store/table-open.php` | L-9 テーブル開放認証 — スタッフホワイトリスト方式 | internal×2 |
| 🟡 partial | **high** | `api/store/table-sessions.php` | テーブルセッション API | internal×1, spec |
| ✅ documented | **high** | `api/store/tables-status.php` | テーブル状態 API（フロアマップ用） | internal×3, spec |
| 🟡 partial | **high** | `api/store/tables.php` | テーブル管理 API | internal×2, spec |
| ✅ documented | medium | `api/store/takeout-management.php` | テイクアウト注文管理 API（店舗向け・認証必須） | internal×3, spec |
| ✅ documented | **high** | `api/store/terminal-intent.php` | Stripe Terminal PaymentIntent 作成 API | internal×3, spec |
| 🟡 partial | medium | `api/store/time-limit-plans-csv.php` | 食べ放題プラン CSV API | spec |
| 🟡 partial | medium | `api/store/time-limit-plans.php` | 食べ放題・時間制限プラン API | internal×1, spec |
| 🟡 partial | medium | `api/store/translate-menu.php` | L-4: メニュー一括翻訳 API | internal×1, spec |
| 🟡 partial | medium | `api/store/turnover-report.php` | 回転率・調理時間・客層分析レポート API | internal×2, spec |
| 🟡 partial | low | `api/store/upload-image.php` | 画像アップロード API（店舗用） | internal×1, spec |
| 🔴 missing | medium | `api/store/weekly-summary.php` | RPT-P1-1: 週次サマリー API | — |

### shift (10)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | medium | `api/owner/shift/unified-view.php` | 統合シフトビュー API（L-3 Phase 3） | internal×1, spec |
| 🟡 partial | medium | `api/store/shift/ai-suggest-data.php` | AI最適シフト提案 — データ集計 API（L-3 Phase 2） | internal×1, spec |
| 🟡 partial | medium | `api/store/shift/apply-ai-suggestions.php` | AI シフト提案 一括適用 API（L-3 / P1-21） | internal×1, spec |
| 🟡 partial | medium | `api/store/shift/assignments.php` | シフト割当 API（L-3） | internal×1, spec |
| 🟡 partial | medium | `api/store/shift/attendance.php` | 勤怠打刻 API（L-3） | internal×1, spec |
| 🟡 partial | medium | `api/store/shift/availabilities.php` | 希望シフト API（L-3） | internal×1, spec |
| 🟡 partial | medium | `api/store/shift/help-requests.php` | ヘルプ要請 API（L-3 Phase 3） | internal×2, spec |
| ✅ documented | medium | `api/store/shift/settings.php` | シフト設定 API（L-3） | internal×5, spec |
| 🟡 partial | medium | `api/store/shift/summary.php` | シフト集計 API（L-3） | internal×1, spec |
| 🟡 partial | medium | `api/store/shift/templates.php` | シフトテンプレート API（L-3） | internal×2, spec |

### owner (17)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | medium | `api/owner/analytics-abc.php` | ABC分析・売上ダッシュボード API（オーナー専用） | spec |
| 🟡 partial | medium | `api/owner/categories.php` | カテゴリ管理 API（オーナー専用、テナント単位） | internal×1, spec |
| 🟡 partial | medium | `api/owner/cross-store-report.php` | 全店舗横断レポート API（オーナー専用） | internal×1, spec |
| 🟡 partial | medium | `api/owner/ingredients-csv.php` | 原材料 CSV API | internal×1, spec |
| 🟡 partial | medium | `api/owner/ingredients.php` | 原材料マスタ API（オーナー専用） | internal×2, spec |
| 🔴 missing | **high** | `api/owner/line-customer-links.php` | LINE 顧客ひも付け owner API (L-17 Phase 2A-1) | — |
| 🔴 missing | **high** | `api/owner/line-link-tokens.php` | LINE ひも付け one-time token owner API (L-17 Phase 2A-2) | — |
| 🔴 missing | **high** | `api/owner/line-settings.php` | tenant 単位 LINE連携設定 API（owner 専用） | — |
| 🟡 partial | medium | `api/owner/menu-csv.php` | 本部メニューテンプレート CSV API | internal×1, spec |
| 🟡 partial | medium | `api/owner/menu-templates.php` | 本部メニューテンプレート API（オーナー専用） | internal×2, spec |
| 🟡 partial | medium | `api/owner/option-groups.php` | オプショングループ管理 API（オーナー専用） | internal×1, spec |
| 🟡 partial | medium | `api/owner/recipes-csv.php` | レシピ CSV API | internal×1, spec |
| 🟡 partial | medium | `api/owner/recipes.php` | レシピ構成 API（オーナー専用） | internal×2, spec |
| 🟡 partial | medium | `api/owner/stores.php` | 店舗管理 API（オーナー専用） | internal×2, spec |
| ✅ documented | medium | `api/owner/tenants.php` | テナント情報 API（オーナー専用） | internal×4, spec |
| 🟡 partial | medium | `api/owner/upload-image.php` | 画像アップロード API（オーナー用） | internal×1, spec |
| ✅ documented | medium | `api/owner/users.php` | ユーザー管理 API（オーナー・マネージャー共用） | internal×3, spec |

### lib (30)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | **high** | `api/lib/audit-log.php` | 監査ログ共通ライブラリ（S-2 Phase 1） | internal×1, spec |
| ✅ documented | **high** | `api/lib/auth.php` | 認証・認可ライブラリ | internal×7, spec |
| 🟡 partial | low | `api/lib/business-day.php` | 営業日計算ヘルパー（店舗別 cutoff 対応） | spec |
| 🟡 partial | **high** | `api/lib/db.php` | データベース接続 & UUID生成 | internal×1, spec |
| ✅ documented | medium | `api/lib/error-codes.php` | POSLA エラーコード レジストリ (Phase A) | internal×5 |
| 🟡 partial | **high** | `api/lib/inventory-sync.php` | 在庫→品切れ自動連動ヘルパー（S-5） | internal×1, spec |
| 🔴 missing | **high** | `api/lib/line-link-token.php` | LINE ひも付け one-time token helper (L-17 Phase 2A-2) | — |
| 🔴 missing | **high** | `api/lib/line-link.php` | LINE 顧客ひも付け helper (L-17 Phase 2A-1) | — |
| 🔴 missing | **high** | `api/lib/line-messaging.php` | LINE Messaging API 薄ラッパ (L-17 Phase 2B-1) | — |
| 🟡 partial | **high** | `api/lib/menu-resolver.php` | メニュー解決ロジック | spec |
| 🟡 partial | medium | `api/lib/order-items.php` | order_items テーブル書き込みヘルパー | spec |
| 🟡 partial | medium | `api/lib/order-validator.php` | 注文アイテムのサーバー側再検証 (P0 #1 + #2 — 改ざん防止) | internal×2 |
| 🟡 partial | **high** | `api/lib/password-policy.php` | P1-5: パスワードポリシー検証 | internal×2, spec |
| ✅ documented | **high** | `api/lib/payment-gateway.php` | 決済ゲートウェイライブラリ | internal×4, spec |
| 🟡 partial | medium | `api/lib/posla-settings.php` | POSLA共通設定（posla_settings）アクセスヘルパー | internal×1 |
| ✅ documented | medium | `api/lib/push.php` | Web Push 共通ヘルパ (PWA Phase 2b 実送信版) | internal×3 |
| ✅ documented | **high** | `api/lib/rate-limiter.php` | ファイルベース IP レートリミッター（S-1） | internal×4, spec |
| 🟡 partial | medium | `api/lib/rating-reasons.php` | 低評価理由 (reason_code) 許可リスト + 共通ヘルパ | internal×1 |
| 🟡 partial | **high** | `api/lib/reservation-availability.php` | L-9 予約管理 — 空席計算共通ライブラリ | internal×2 |
| 🔴 missing | **high** | `api/lib/reservation-deposit.php` | L-9 予約管理 — Stripe デポジット (前金決済) ライブラリ | — |
| 🔴 missing | **high** | `api/lib/reservation-notifier.php` | L-9 予約管理 — メール送信ライブラリ | — |
| ✅ documented | **high** | `api/lib/response.php` | API 共通レスポンス関数 | internal×4, spec |
| 🟡 partial | medium | `api/lib/smaregi-client.php` | L-15: スマレジAPI共通クライアント | spec |
| 🟡 partial | **high** | `api/lib/stripe-billing.php` | Stripe Billing ヘルパーライブラリ | internal×1, spec |
| 🟡 partial | **high** | `api/lib/stripe-connect.php` | Stripe Connect ヘルパーライブラリ | internal×1, spec |
| 🔴 missing | **high** | `api/lib/takeout-line-link.php` | L-17 Phase 3A: テイクアウト LINE ひも付け helper | — |
| 🔴 missing | **high** | `api/lib/takeout-line-token.php` | L-17 Phase 3A: テイクアウト one-time link token helper | — |
| 🟡 partial | medium | `api/lib/web-push-encrypt.php` | Web Push Message Encryption (RFC 8291 aes128gcm) 純 PHP | internal×2 |
| 🟡 partial | medium | `api/lib/web-push-sender.php` | Web Push 送信 — cURL POST + 410/404 ハンドリング | internal×2 |
| 🟡 partial | medium | `api/lib/web-push-vapid.php` | VAPID JWT (ES256) 署名 — RFC 8292 / 純 PHP | internal×2 |

### stripe-connect (5)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | **high** | `api/connect/callback.php` | Stripe Connect オンボーディング完了コールバック | internal×2, spec |
| ✅ documented | **high** | `api/connect/disconnect.php` | P1-1c: Stripe Connect 解除 | internal×3, spec |
| ✅ documented | **high** | `api/connect/onboard.php` | Stripe Connect オンボーディング開始 API | internal×4, spec |
| ✅ documented | **high** | `api/connect/status.php` | Stripe Connect 状態確認 API | internal×7, spec |
| ✅ documented | **high** | `api/connect/terminal-token.php` | Stripe Terminal Connection Token 発行 API | internal×3, spec |

### subscription (4)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| ✅ documented | **high** | `api/subscription/checkout.php` | サブスクリプション Checkout API | internal×3, spec |
| ✅ documented | **high** | `api/subscription/portal.php` | Stripe Customer Portal API | internal×3, spec |
| ✅ documented | **high** | `api/subscription/status.php` | サブスクリプション状態取得 API | internal×7, spec |
| ✅ documented | **high** | `api/subscription/webhook.php` | Stripe Webhook 受信エンドポイント | internal×6, spec |

### line (1)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| ✅ documented | medium | `api/line/webhook.php` | tenant 単位 LINE webhook 受信口（Phase 1 土台） | internal×6, spec |

### cron (4)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | **high** | `api/cron/auto-clock-out.php` | 自動退勤 cron スクリプト（L-3） | internal×1, spec |
| ✅ documented | **high** | `api/cron/monitor-health.php` | I-1 運用支援エージェント Phase 1: ヘルスチェック + 異常検知 | internal×5 |
| 🟡 partial | **high** | `api/cron/reservation-cleanup.php` | L-9 予約管理 — クリーンアップ (cron) | internal×1 |
| 🟡 partial | **high** | `api/cron/reservation-reminders.php` | L-9 予約管理 — 自動リマインダー送信 (cron) | internal×1 |

### monitor (1)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| ✅ documented | **high** | `api/monitor/ping.php` | I-1 外部 uptime サービス向け死活応答 | internal×6, spec |

### push (4)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| ✅ documented | **high** | `api/push/config.php` | Web Push 設定取得 API (PWA Phase 2a) | internal×3 |
| ✅ documented | **high** | `api/push/subscribe.php` | Web Push 購読登録 API (PWA Phase 2a) | internal×4 |
| ✅ documented | **high** | `api/push/test.php` | Web Push テスト送信 API (PWA Phase 2b) | internal×3 |
| 🟡 partial | **high** | `api/push/unsubscribe.php` | Web Push 購読解除 API (PWA Phase 2a) | internal×2 |

### smaregi (8)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| ✅ documented | **high** | `api/smaregi/auth.php` | L-15: スマレジ OAuth 認可開始 | internal×7, spec |
| 🟡 partial | medium | `api/smaregi/callback.php` | L-15: スマレジ OAuth コールバック | internal×2, spec |
| ✅ documented | **high** | `api/smaregi/disconnect.php` | L-15: スマレジ連携解除 | internal×3, spec |
| 🟡 partial | medium | `api/smaregi/import-menu.php` | L-15: スマレジ商品 → POSLAメニューテンプレート インポート | internal×1, spec |
| ✅ documented | medium | `api/smaregi/status.php` | L-15: スマレジ連携状態確認 | internal×7, spec |
| 🟡 partial | medium | `api/smaregi/store-mapping.php` | L-15: 店舗マッピング CRUD | internal×1, spec |
| 🟡 partial | medium | `api/smaregi/stores.php` | L-15: スマレジ店舗一覧取得 | internal×2, spec |
| 🟡 partial | medium | `api/smaregi/sync-order.php` | L-15: POSLA 注文 → スマレジ仮販売（layaway）送信 | internal×1, spec |

### posla-admin (11)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | **high** | `api/posla/auth-helper.php` | POSLA 管理者 認証ヘルパー | internal×1, spec |
| ✅ documented | **high** | `api/posla/change-password.php` | P1-5: POSLA 管理者 自己パスワード変更 API | internal×3, spec |
| 🟡 partial | **high** | `api/posla/dashboard.php` | POSLA 管理者 ダッシュボード API | internal×2, spec |
| ✅ documented | **high** | `api/posla/login.php` | POSLA 管理者 ログイン API | internal×7, spec |
| ✅ documented | **high** | `api/posla/logout.php` | POSLA 管理者 ログアウト API | internal×3, spec |
| ✅ documented | **high** | `api/posla/me.php` | POSLA 管理者 認証チェック API | internal×3, spec |
| ✅ documented | **high** | `api/posla/monitor-events.php` | I-1 監視イベント一覧 API (POSLA 管理者向け) | internal×5 |
| 🟡 partial | **high** | `api/posla/push-vapid.php` | POSLA 管理画面 PWA/Push タブ用情報 API (Phase 2b 拡張) | internal×2 |
| ✅ documented | **high** | `api/posla/settings.php` | POSLA 共通設定 API | internal×5, spec |
| ✅ documented | **high** | `api/posla/setup.php` | POSLA 管理者 初期セットアップ API | internal×3, spec |
| ✅ documented | **high** | `api/posla/tenants.php` | POSLA 管理者 テナント CRUD API | internal×4, spec |

### signup (3)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| ✅ documented | **high** | `api/signup/activate.php` | A5: 申込アクティベーション (Webhook フォールバック) | internal×3 |
| ✅ documented | **high** | `api/signup/register.php` | A5: 新規テナント申込 API (未ログイン・認証不要) | internal×6 |
| ✅ documented | **high** | `api/signup/webhook.php` | A5: 新規申込 Stripe Webhook | internal×6, spec |

### public (1)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | medium | `api/public/store-status.php` | 公開 API: 店舗ステータス（認証不要） | spec |

### config (2)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | low | `api/config/app.php` | アプリケーション全体の基底設定 | internal×1 |
| 🟡 partial | low | `api/config/database.php` | データベース接続設定 | internal×2, spec |

### scripts (3)

| status | priority | path | purpose | evidence |
|---|---|---|---|---|
| 🟡 partial | low | `scripts/build-helpdesk-prompt.php` | AI ヘルプデスク knowledge base 結合スクリプト | internal×2 |
| 🟡 partial | low | `scripts/generate-vapid-keys.php` | VAPID 鍵生成 CLI スクリプト (PWA Phase 2b) | internal×2 |
| ✅ documented | low | `scripts/p1-27-generate-torimaru-sample-orders.php` | P1-27: torimaru デモテナント サンプル注文生成 | internal×3 |

---

## 96.7 結論

### 結論1: docs は**基幹 money / 認証 / KDS / Stripe / サブスク / POSLA 管理**の範囲で十分
- 会計フロー・サブスクリプション課金・Stripe Connect・KDS コア・POSLA 管理者の各 API は internal 章で多重に参照されており、「実装と docs が噛み合う」状態。本番複製にあたって critical path の docs ブロッカーはない (97 章の P0 ゼロ と整合)。

### 結論2: **partial 57% = "言及あり／運用手順薄"** が最大塊
- 120 ファイルが partial。主に L-9 予約管理・L-3 シフト・スマレジ同期・CSV 系・lib のコア支援ライブラリ。これらは 97 章 §97.1.X や §97.4.X で個別に指摘されている通り「docs に出てはくるが、何をするものか・いつ使うか・失敗時の挙動が書かれていない」群。
- 運用引き継ぎ品質を上げたいなら、この 120 件を対象にした「使いどころ＋壊れ方」の追記がメインターゲット。

### 結論3: **完全未記載 14 件は L-17 LINE + L-9 予約管理** に集中
- 13/14 が **L-17 LINE 連携**または **L-9 予約管理**の内部実装。`api/line/webhook.php` だけ documented で他は全部 missing という偏り。L-17 は現在 phase 2-3 実装中で docs 側の章建ては未着手と推測。
- 残り 1 件 `weekly-summary.php` は RPT-P1-1 の直近実装 (2026-04-23 完了)。docs 追記待ち。

---

## 96.8 次スプリント提案

### DOC-LINE-17 (最優先)
- 新設章候補: `docs/manual/internal/XX-line-integration.md`
- 内容: L-17 Phase 2A (顧客ひも付け) / 2B (Messaging API) / 3A (テイクアウト連携) の 3 フェーズを API・ライブラリ・トークンライフサイクル含めて説明
- 対象ファイル: §96.4.1 の L-17 関連 9 ファイル + `api/line/webhook.php` (既 documented)

### DOC-RES-L9 (予約管理の深化)
- 既存 `internal/05-operations.md` §5.X の予約管理節を拡張、または `internal/XX-reservation.md` 新設
- 対象: missing 4 件 + partial 14 件 (customer/reservation-* + store/reservation-* + cron/reservation-*)
- 特に missing の `reservation-deposit.php` (前金決済) と `reservation-notifier.php` (通知送信) は**金銭・SLA 両方に関わる**ので最優先

### DOC-RPT-P1 (週次サマリー追記)
- 1 ファイルのみ。operations manual の「オーナーダッシュボード 週次サマリーカード」節に 1 段落追加すれば解消

### DOC-HIGH-PARTIAL-BATCH (partial 52 件の漸進化)
- 「言及 → 運用手順」へのアップグレード。1 スプリント 10 件ずつで 5 スプリント想定
- 優先: emergency-payment-* サブ API、core lib、core auth session、PWA push unsubscribe

---

## 96.9 メソドロジー・制約

### 実施方法
1. `find api scripts -name '*.php'` で全 212 件列挙
2. 各ファイルから docstring 1 行目を awk 抽出 → `/tmp/php_purpose.txt`
3. 各 basename を `grep -rl --include='*.md' -F` で docs 全体に対して検索 → ヒット file 数カウント
4. 判定ルール (§96.0) で coverage_status と priority 自動決定
5. カテゴリ別に分類して表形式に

### 既知の限界
- **basename 衝突リスク**: 複数ディレクトリに同名ファイルが無い想定。実際 `upload-image.php` は owner/ と store/ に存在するが docs は区別されている。**誤ヒットの検出は手作業**。
- **言及 ≠ 運用手順**: `internal×5` の hit が「詳細な運用節 5 つ」か「リンク 5 本」かは区別しない。質の評価は 97 章の人手監査が補完する位置づけ。
- **helpdesk-prompt**: internal/tenant manual から自動生成されるので、独立ソースとして加算しない (重複カウント回避)。
- **SYSTEM_SPECIFICATION.md**: 巨大な 1 ファイルで「言及あり/なし」判定しか取れない。partial 昇格の補助ソースとして扱う。

### 再生成方法
- `/tmp/php_purpose.txt` / `/tmp/php_docs_coverage.txt` / `/tmp/php_matrix.txt` を再生成する bash スクリプトは本章 §96.9 の手順に沿う。恒常的な CI 化は不要 (本番移行時の 1 回監査が目的)。

---

## 96.10 更新履歴

- **2026-04-23**: 初版。全 212 ファイル機械突合、status × priority 分布を確定。L-17 LINE 連携 9 件未記載が最大の gap と判明。
