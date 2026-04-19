# マニュアル整備ステータス

最終更新: 2026-04-18

## 達成状況

**全 28 章 × frontmatter + FAQ + トラブル + 技術補足 = ✅ 整備完了**

`tenant/` 26 章 + index = **13,551 行**
`internal/` **8 章** (07-monitor / 08-signup-flow を含む) = **1,676 行**
合計: **約 15,227 行**

## 章別ステータス

### tenant/

| # | 章 | 行数 | 粒度 | 備考 |
|---|---|---|---|---|
| 01 | introduction | 164 | ★★★☆☆ | 概要・哲学 |
| 02 | login | 477 | ★★★★☆ | frontmatter追加、既存内容詳細 |
| 03 | dashboard | 151 | ★★★☆☆ | 新機能 (予約/プリンタ) 連携 |
| 04 | menu | 539 | ★★★★☆ | 3層構造 + アドオン |
| 05 | tables | 360 | ★★★★☆ | L-9 連携追加 |
| 06 | orders | 994 | ★★★★★ | Heavy 1500行近 |
| 07 | kds | 709 | ★★★★☆ | GPS チェック追加 |
| 08 | cashier | 737 | ★★★★☆ | C-R1 返金機能追加 |
| 09 | takeout | 230 | ★★★★☆ | S3 オンライン限定化 |
| 10 | plans | 225 | ★★★☆☆ | プラン+コース |
| 11 | inventory | 182 | ★★★☆☆ | 在庫・レシピ |
| 12 | shift | 413 | ★★★★☆ | シフト管理 |
| 13 | reports | 413 | ★★★★☆ | 予約レポート追加 |
| 14 | ai | 210 | ★★★☆☆ | AI 機能総合 |
| 15 | owner | 432 | ★★★★☆ | オーナーDashboard |
| 16 | settings | 391 | ★★★★☆ | プリンタ + 予約LP |
| 17 | customer | 227 | ★★★☆☆ | セルフオーダー |
| 18 | security | 197 | ★★★☆☆ | S1-S6 + L-9 |
| 19 | troubleshoot | 201 | ★★★☆☆ | 総合トラブル |
| 20 | google-review | 85 | ★★★☆☆ | Google レビュー |
| 21 | stripe-full | 1881 | ★★★★★ | 既存・基準 |
| 22 | smaregi-full | 311 | ★★★★☆ | スマレジ |
| 23 | kds-devices | 993 | ★★★★★ | 既存・KDS device |
| **24** | **reservations** | **1508** | ★★★★★ | **L-9 完全版** |
| **25** | **table-auth** | **782** | ★★★★☆ | **新着席フロー** |
| **26** | **printer** | **711** | ★★★★☆ | **U-2 プリンタ** |

### internal/

| # | 章 | 状態 |
|---|---|---|
| 01 | posla-admin | frontmatter追加 |
| 02 | onboarding | frontmatter追加 |
| 03 | billing | frontmatter追加 |
| 04 | payment | frontmatter追加 |
| 05 | operations | frontmatter追加 |
| 06 | troubleshoot | frontmatter追加 |
| **07** | **monitor** | ★★★★☆ I-1 新規 |
| **08** | **signup-flow** | ★★★★☆ A5 新規 |

## AIヘルプデスク knowledge base

```
scripts/output/helpdesk-prompt-tenant.txt   : 592KB / 26章 + voice
scripts/output/helpdesk-prompt-internal.txt : 889KB / 34章 + voice + spec
```

`scripts/build-helpdesk-prompt.php` 実行で再生成。

## 次のステップ

| タスク | 状態 |
|---|---|
| Phase 1 (Heavy 3章) | ✅ 完了 |
| Phase 2 (Medium 7章) | ✅ 完了 |
| Phase 3 (Light 残章) | ✅ 完了 |
| 新機能 3章 (24/25/26) | ✅ 完了 |
| internal/ frontmatter | ✅ 完了 |
| **Batch-FINAL** AIヘルプデスクUI実装 | ✅ 実装済 (CB1c) — 全管理画面に共通 FAB (`public/shared/js/posla-helpdesk-fab.js`) 設置済 |
| FINAL-1 究極マニュアル | 🔲 operations/ 配下、Part 0 のみ |

## メンテナンス

各章は独立してメンテナンス可能。新機能追加時は:
1. 該当章に追記 (or 新章追加)
2. frontmatter の `last_updated` 更新
3. `更新履歴` セクションに追記
4. `scripts/build-helpdesk-prompt.php` 再生成
