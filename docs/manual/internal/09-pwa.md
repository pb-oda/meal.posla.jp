---
feature_id: pwa-staff
title: スタッフ画面 PWA 化
chapter: 9
plan: [base]
role: [posla_admin, owner, manager]
audience: internal
keywords: [PWA, ServiceWorker, manifest, ホーム画面, オフライン, スタッフ]
related: [/internal/05-operations.md, /internal/06-troubleshoot.md]
last_updated: 2026-04-19
maintainer: posla
---

# 9. スタッフ画面 PWA 化 (meal.posla.jp)

## 9.0 はじめにお読みください

POSLA の **スタッフ向け業務画面のみ** を PWA (Progressive Web App) として構成し、タブレットやスマホのホーム画面に追加 → 1 タップでアプリのように起動できるようにする。**顧客側のセルフメニュー / 予約 / テイクアウト画面は意図的に PWA から除外** している。

::: warning 顧客セルフメニューはブラウザ運用
顧客が QR からアクセスするセルフメニュー (`public/customer/menu.html` 等) には Service Worker / manifest を一切載せない。
理由:
- 1 回限りのスキャン → 注文 → 会計でセッションが終わる短期利用フロー
- ホーム画面追加されると古いキャッシュが残るリスク (来店ごとにメニュー差分があるため)
- セッション情報・QR トークン・テーブル ID が URL に含まれるため、過去のキャッシュが他人に流用されるリスク
- 二重防御: SW の fetch handler でも `/public/customer/` および `/api/customer/` を含む request は強制 passthrough
:::

## 9.1 対象画面とスコープ

| スコープ | パス | 用途 | manifest theme | アイコン |
|---|---|---|---|---|
| `/public/admin/` | `index.html` `dashboard.html` `owner-dashboard.html` `device-setup.html` | 管理者・店長操作 | `#1976d2` (青) | 管 |
| `/public/kds/` | `index.html` `cashier.html` `sold-out.html` | キッチン・レジ | `#2e7d32` (緑) | 厨 |
| `/public/handy/` | `index.html` `pos-register.html` | ハンディ POS | `#ff6f00` (橙) | 配 |

::: info accounting.html を除外する理由
`public/kds/accounting.html` は 2026-04-02 以降 `cashier.html` への redirect 専用。空ボディなので PWA 化しない。
:::

## 9.2 ファイル構成

各スコープに 3 ファイル + アイコン 4 種を配置:

```
public/admin/
├── manifest.webmanifest      ← Web App Manifest
├── sw.js                     ← Service Worker (scope: /public/admin/)
├── pwa-register.js           ← クライアント登録スクリプト
└── icons/
    ├── icon.svg              ← マスターベクター
    ├── icon-192.png          ← Android 推奨
    ├── icon-512.png          ← splash 用
    └── apple-touch-icon.png  ← iOS Safari 用 (180x180)
```

`public/kds/` `public/handy/` も同構成。

## 9.3 キャッシュ戦略

| 対象 | 戦略 | 理由 |
|---|---|---|
| `/api/*` | **キャッシュしない (passthrough)** | 業務データ整合性。注文・会計は常に最新 |
| `/api/customer/*` | passthrough (二重防御) | 同上 |
| `/public/customer/*` | passthrough (二重防御) | 顧客側 PWA 化禁止 |
| POST / PATCH / DELETE / PUT | passthrough | mutating request はキャッシュしない |
| URL に `token` `session` `checkout` `reservation` `store_id` `table_id` `pin` `staff_pin` 含む | passthrough | 個別セッション・取引 ID は他人に流用される |
| HTML | network-first → 失敗時キャッシュ | オフライン時もログイン画面等を表示 |
| CSS / JS / 画像 / フォント / manifest | stale-while-revalidate | 更新は次回読込で取得 |

## 9.4 業務データ整合性のための非対応

以下は意図的に **実装しない**:

- オフライン注文の自動キュー (会計差分の温床)
- オフライン会計の自動再送 (二重課金リスク)
- バックグラウンド同期 (Background Sync API)
- プッシュ通知 (Web Push)
- IndexedDB によるメニュー / 注文ローカルストア

オフライン時は HTML のキャッシュ表示のみで、業務操作は不可 (network-first で 503 表示)。

## 9.5 バージョニングと更新

各 SW の cache name にバージョン:
- `posla-admin-static-v1`
- `posla-kds-static-v1`
- `posla-handy-static-v1`

新版デプロイ時は `sw.js` の `VERSION = 'v1'` を `'v2'` などに上げる。`activate` ハンドラで `posla-XXX-` prefix の旧 cache を全削除 → 古いアセット混在事故を防止。

`skipWaiting()` + `clients.claim()` を使用しているため、新版 SW は **既存タブを含めて即座に切替わる** (再読込不要)。

::: tip skipWaiting の判断理由
- install 直後に `skipWaiting()` → waiting フェーズをスキップ
- activate 時に `clients.claim()` → 既存タブも即座に新 SW の制御下に置く
- 結果として、**新版デプロイ後、既存タブの次のリクエストから新 SW が応答**する
- API は常に passthrough なので、業務操作中に SW が切り替わってもレスポンス形式不整合は発生しない (REST 呼び出しは SW を通らない)
- 静的アセット (CSS/JS) は順次差し替わる。古いキャッシュは activate 時の `caches.delete()` で除去
- 業務中の突発切替リスク < 古い JS が決済 API レスポンス形式と合わない事故、と判断
:::

## 9.6 ホーム画面への追加方法

### iPhone Safari
1. Safari で対象 URL を開く (例: `https://meal.posla.jp/public/admin/dashboard.html`)
2. 共有ボタン → 「ホーム画面に追加」
3. 名前 (例: `POSLA管理`) を確認 → 追加
4. ホーム画面のアイコンタップで standalone 起動

### Android Chrome
1. Chrome で対象 URL を開く
2. メニュー (︙) → 「ホーム画面に追加」または「アプリをインストール」 (PWA インストールバナーが出る場合あり)
3. ホーム画面アイコンから起動

## 9.7 トラブルシューティング

| 症状 | 原因 | 対処 |
|---|---|---|
| ホーム画面追加メニューが出ない | manifest 読み込み失敗 / icon サイズ不足 | DevTools → Application → Manifest を確認 |
| 古い JS / CSS が表示される | SW キャッシュが残存 | DevTools → Application → Service Workers → Unregister + Storage Clear、または Ctrl+Shift+R |
| API レスポンスが古い | 通常はキャッシュしない設定。されていたら設定異常 | DevTools → Network で `(from ServiceWorker)` 表示がないか確認 |
| オフライン時の表示が崩れる | HTML はキャッシュされるが API は 503 になるので部分崩壊 | 期待通り。業務継続不可なのは仕様 |
| 顧客 menu.html が PWA 化されている | 二重バグ | 本仕様に違反。`public/customer/` 配下に SW 登録があれば即削除 |

## 9.8 確認コマンド

### 顧客側に PWA 登録が混入していないこと
```bash
rg -n "serviceWorker|manifest.webmanifest|pwa-register|navigator.serviceWorker" \
  meal.posla.jp/public/customer
```
→ ヒットゼロが期待値。

### Service Worker が `/api/` をキャッシュしないことの確認
```bash
rg -n "/api/|caches\.put|caches\.open|fetch\(" \
  meal.posla.jp/public/admin/sw.js \
  meal.posla.jp/public/kds/sw.js \
  meal.posla.jp/public/handy/sw.js
```
→ `/api/` 検出は passthrough 分岐のみのはず。

### 各スコープ scope の確認
```bash
grep -l "manifest.webmanifest" meal.posla.jp/public/admin/*.html \
  meal.posla.jp/public/kds/*.html \
  meal.posla.jp/public/handy/*.html
```
→ 全 staff HTML がリストに含まれること。

## 9.9 デプロイ対象ファイル

- `public/admin/manifest.webmanifest` `sw.js` `pwa-register.js` `icons/*`
- `public/kds/manifest.webmanifest` `sw.js` `pwa-register.js` `icons/*`
- `public/handy/manifest.webmanifest` `sw.js` `pwa-register.js` `icons/*`
- 編集された 9 HTML (`<head>` 末尾と `<body>` 末尾に各 1〜3 行追加)

## 9.X 更新履歴

- **2026-04-19**: 初版 (Q1=A スコープ別カラー / Q2=B 全 staff HTML 対象)
