---
feature_id: doc-audit
title: ドキュメント完全性監査
chapter: 97
plan: [base]
role: [posla_admin]
audience: internal
keywords: [audit, documentation, gap, pre-production]
last_updated: 2026-04-21
maintainer: posla
---

# 97. ドキュメント完全性監査（Pre-Production Gap Analysis）

本章は、デモ環境仮完成時点（2026-04-21）での docs と実装の gap を記録する。本番複製前に P0/P1 項目の解消を推奨する。

## 97.0 本監査のスコープと制約

- 対象 docs source: `docs/manual/internal/*.md` / `docs/manual/tenant/*.md`
- 対象 code: `api/**/*.php` / `public/**` / `sql/**` / `scripts/**`
- 方法: 5 分野並列 Explore agent による read-only 監査（2026-04-21 20:45 JST 実施）
- 再現性: 本章 §97.X の指摘は「実コード確認で裏取り済み」の項目のみ。agent が未検証とした項目は §97.6 に分離

重要度の定義:
- **P0**: 本番運用開始時点で必ず解消。運用担当者が事故を起こす、テナントが法務トラブルになる
- **P1**: 運用開始 30 日以内に解消。問い合わせ増加・誤操作中リスク
- **P2**: 運用の質を上げる改善項目。急がない

---

## 97.1 A: 実装済み・docs 未記載（裏取り済み）

### 97.1.1 device ロール（P1a）の tenant docs 記載不足 [P1]

- 実装: `api/store/staff-management.php:26-38` `_validate_visible_tools()`、`api/lib/auth.php`（role ENUM に `'device'` 追加、P1a）、CLAUDE.md に詳細記載
- docs 状況:
  - `docs/manual/tenant/15-owner.md` §1.8 に簡易言及のみ
  - `docs/manual/tenant/02-login.md` に device の記載なし
  - device 作成方法 / visible_tools 設定 / シフト・人件費除外 の詳細が tenant docs から欠落
- 運用影響: owner/manager が device アカウントの目的・作り方・ログイン後遷移を docs から読めない
- 追記先推奨: `tenant/15-owner.md` に新設節「端末アカウント（device ロール）」

### 97.1.2 相席用サブセッション（F-QR1）の tenant docs 不在 [P1]

- 実装: `sql/migration-fqr1-sub-sessions.sql` + `api/customer/table-session.php` + `api/store/sub-sessions.php`
- docs 状況: `docs/manual/internal/00-complete-reference.md` §2.34 にテーブル定義のみ。tenant docs にサブセッション QR 発行手順なし
- 運用影響: 相席グループが入店した時の QR 発行・個別会計の仕組みを tenant が知らない
- 追記先推奨: `tenant/05-tables.md` に新設節「相席時の追加 QR 発行」

### 97.1.3 緊急レジモード（PWA Phase 4）の tenant docs 不足 [P1]

- 実装: `internal/09-pwa.md` §9.17 に 80 行以上の詳細、`api/store/emergency-payments.php` + `public/kds/js/emergency-register.js`
- docs 状況: `tenant/08-cashier.md` に緊急レジの説明なし
- 運用影響: テナントが通信断時に緊急レジで何が起きるのかを docs から読めず、管理者解決フロー（§9.19）も tenant docs に未反映
- 追記先推奨: `tenant/08-cashier.md` §8.X「通信障害時の会計（緊急レジモード）」

### 97.1.4 P1-5 パスワードポリシーの tenant 向け明示 [P1]

- 実装: `api/lib/password-policy.php` `validate_password_strength()`（8 文字以上、英字必須、数字必須）
- docs 状況: `CLAUDE.md` に記載、`tenant/02-login.md` にパスワード要件の明示なし
- 運用影響: パスワード設定時にエラーで弾かれた理由を tenant が把握できない
- 追記先推奨: `tenant/02-login.md` にパスワード要件 1 段落追加

### 97.1.5 Staff / Cashier PIN 運用手順の欠落 [P1]

- 実装: `api/store/process-payment.php:50-95`（PIN 必須化、CP1）+ `api/store/refund-payment.php:39-77`（CP1b）
- docs 状況: `internal/04-payment.md` §4.9 に仕様あり、tenant docs に PIN 設定 UI 手順 / 忘れた時の対応が欠落
- 運用影響: スタッフが PIN 要求されても設定方法・リセット方法を知らない
- 追記先推奨: `tenant/15-owner.md` に「スタッフ PIN 管理」節

### 97.1.6 4d-5c-bb-A〜G takeout void / refund 拡張 の tenant docs 反映 [P2]

- 実装: `internal/09-pwa.md` §9.29〜§9.35 / `internal/05-operations.md` §5.13q〜§5.13w
- docs 状況: `tenant/09-takeout.md` は §5.13* に基づく update 履歴を反映済だが、操作 UI の説明が簡略
- 追記先推奨: tenant/09-takeout.md で「POS cashier からの takeout 会計取消」「Connect Checkout refund」の区別を 1 段落追記

### 97.1.7 Stripe Connect 3 Pattern routing の tenant 向け説明 [P2]

- 実装: `api/lib/payment-gateway.php` / `api/store/refund-payment.php:167-224`（Pattern A/B/C）
- docs 状況: `tenant/21-stripe-full.md` に Pattern A/B/C の区別・確認方法の記載なし
- 追記先推奨: `tenant/21-stripe-full.md` に「自店舗はどのパターンで動いているか確認する方法」

---

## 97.2 B: docs 記載・実装なし / 廃止されたもの [P2]

### 97.2.1 `api/kds/close-table.php` deprecated だが docs に明示なし

- 実装: ファイル冒頭 `// @deprecated 2026-04-02 — process-payment.php に統一済み`
- 確認済（§9.38）: 唯一の caller `accounting-renderer.js` は**どの HTML にも script 参照なし** で dead code
- docs 状況: `internal/00-complete-reference.md` / `internal/05-operations.md` に deprecated 表記なし
- 追記先推奨: `internal/05-operations.md` に deprecated 表記 1 行追加

### 97.2.2 `merged_table_ids` UI（合流会計ボタン）の live 実績ゼロ [P2]

- 実装: `public/kds/cashier.html:44`「合流会計」ボタン + `cashier-app.js:1437-1450`
- 確認済（§9.39.2）: 本番で **1 度も使われていない**（`JSON_LENGTH(merged_table_ids) > 0` が 0 件）
- docs 状況: `tenant/08-cashier.md` に合流会計機能の説明なし
- 判断: docs に書く / UI を隠す / 機能を完成させる のどれかが要決定（運用判断待ちでよい）

---

## 97.3 C: エラーカタログ齟齬

### 97.3.1 `api/lib/error-codes.php` vs `99-error-catalog.md` の自動再生成抜け [P1]

- 実装: `scripts/generate_error_catalog.py` で自動生成される仕組みあり
- docs 状況: `99-error-catalog.md` に二重採番・重複エントリの疑い（Agent A 指摘）
- 運用影響: サポート時に「docs 記載のエラーコードが実装と違う」混乱
- 改善案: CI / release 前 hook で `generate_error_catalog.py` 必須実行

### 97.3.2 `E7001 CONFLICT` の発生源メッセージが多義 [P1]

- 実装: 52 箇所で CONFLICT 409 を返却（`api/store/emergency-payment-*` / `payment-void.php` / `refund-payment.php` ほか）
- docs 状況: catalog のメッセージが 1 通りのみで、実際の原因を網羅していない
- 改善案: catalog に「主な発生源 N 箇所」列を追加（`4d-5c-ba` / `emergency 転記` / `partial void` の文脈別）

---

## 97.4 D: 曖昧・運用判断不可 [P1]

### 97.4.1 partial 会計 UI の tenant 向け操作手順欠落

- 実装: `api/store/process-payment.php:191-255`（individual items selection）+ `public/kds/cashier.html` + `cashier-app.js:1460-1477`
- docs 状況: `tenant/08-cashier.md` に「チェックボックスで個別会計」の UI 手順・スクリーンショットなし
- 運用影響: 個別会計を業務で使う時にスタッフが操作方法を知らない
- 追記先推奨: `tenant/08-cashier.md` に「個別会計（分割）の手順」

### 97.4.2 Rate Limiter 上限値の具体的記述不足 [P2]

- 実装: `api/lib/rate-limiter.php` / 各 endpoint の `check_rate_limit('...', N, seconds)` 個別設定
- docs 状況: `99-error-catalog.md` E1021 `RATE_LIMITED` のメッセージ「リクエスト回数の上限に達しました」のみで具体値なし
- 改善案: `06-troubleshoot.md` に endpoint × limit 対応表を追加（PIN 5/10min, cart-event 60/10min 等）

### 97.4.3 partial_multi の「触れない」理由説明なし（§9.40 の tenant 反映） [P2]

- 実装: §9.40 で A 案（void 不可維持）確定
- docs 状況: tenant docs に「なぜ分割会計の行は会計取消ボタンが出ないか」の説明なし
- 改善案: `tenant/08-cashier.md` に注記追加「分割会計は取消非対応、やり直しは全員分返金 → 再会計で」

---

## 97.5 E: 参照整合・リンク [P2]

### 97.5.1 CLAUDE.md → docs への逆参照なし

- 問題: CLAUDE.md の device ロール / PIN / パスワードポリシー記載から docs 詳細へのリンクなし
- 改善案: CLAUDE.md の該当項目末尾に「詳細は internal/05-operations.md §X 参照」を追加

### 97.5.2 internal 章間相互参照の抜け

- `04-payment.md` から `09-pwa.md §9.17`（緊急レジ）へのリンクなし
- `05-operations.md` から `09-pwa.md §9.38〜9.40`（4d-6/4d-7/論点 B）へのリンクは既に §5.13z〜§5.13ab 経由で存在 ✓
- 改善案: `04-payment.md` §4.1 冒頭に緊急レジへの短いリンク追加

---

## 97.6 Agent 指摘で未検証・確度中以下

以下は Agent A が挙げたが、本監査者が実コードで裏取りしなかった項目。再検証を推奨:

- sub_token TTL 実装（Agent A 指摘、`table_sub_sessions` の自動失効の有無）
- owner-dashboard における device 非表示の実装確認（`GET /api/owner/users.php` の `role != 'device'` フィルタ）

---

## 97.7 重要度サマリ

| 区分 | P0 | P1 | P2 |
|---|---|---|---|
| A. 実装済み未記載 | 0 | 5 | 2 |
| B. docs 記載・実装なし | 0 | 0 | 2 |
| C. エラーカタログ齟齬 | 0 | 2 | 0 |
| D. 曖昧・運用判断不可 | 0 | 1 | 2 |
| E. 参照整合 | 0 | 0 | 2 |
| **計** | **0** | **8** | **8** |

**P0 はゼロ**。本番複製の技術ブロッカーはないが、P1 8 件は運用開始前に解消を推奨。

---

## 97.8 更新履歴

- **2026-04-21**: 初版。5 分野並列 Explore agent による gap 監査、実コード裏取りで確度担保。§9.38 / §9.39 / §9.40 の stop 判断 tenant 反映不足を P1 として記録。
