# 松の屋MT（matsunoya-mt）システム完全仕様書

> **バージョン:** 2.11.0
> **最終更新:** 2026-04-08（Bundle G/H + P1-12 観測チャネル + P1-19/19b/19c 決済 i18n + P1-20 smaregi 自動翻訳 + P1-25 webhook 堅牢化 + B-09 照合順序修正 + order_items 定義追加）
> **対象:** AI・開発者向け超詳細ドキュメント

---

## 目次

1. [システム概要](#1-システム概要)
2. [技術スタック・アーキテクチャ](#2-技術スタックアーキテクチャ)
3. [ディレクトリ構成](#3-ディレクトリ構成)
4. [データベーススキーマ](#4-データベーススキーマ)
5. [認証・認可](#5-認証認可)
6. [API リファレンス](#6-api-リファレンス)
7. [コアビジネスロジック](#7-コアビジネスロジック)
8. [フロントエンド詳細](#8-フロントエンド詳細)
9. [データフロー](#9-データフロー)
10. [デプロイ・運用](#10-デプロイ運用)

---

## 1. システム概要

### 1.1 プロダクト概要

**松の屋MT（matsunoya-mt）** は、多店舗レストラン向けマルチテナント型セルフオーダー＆KDS（Kitchen Display System）統合システムである。

顧客がQRコードからセルフオーダーし、注文がリアルタイムにキッチンのKDSへ表示され、スタッフのハンディ端末やPOSレジとも連携する。テナント（企業/ブランド）→ 店舗 → ユーザーの階層構造により、本部メニューの一元管理と店舗別カスタマイズを両立する。

### 1.2 主要機能一覧

| 機能 | 説明 |
|------|------|
| **マルチテナント** | tenant → store → user の3階層。テナント横断のデータ分離 |
| **セルフオーダー** | 顧客がQRコードスキャン → メニュー表示 → カート → 注文送信 |
| **KDS（キッチンディスプレイ）** | 3列カンバンボード（受付/調理中/完成）+ ステーション別ルーティング |
| **POSレジ** | スタンドアロン会計ページ（cashier.html）。3カラム（テーブル一覧/品目/レジ）、割引、テンキー、レシートプレビュー、領収書発行（L-5）、売上サマリー、取引ジャーナル、レジ開閉、キーボードショートカット、操作音フィードバック。合流会計（複数テーブル一括決済）・分割会計（品目選択式個別決済＋支払済み追跡＋全品完了自動クローズ）|
| **ハンディPOS** | スタッフ用注文入力端末。イートイン/テイクアウト対応 |
| **メニュー管理** | 本部テンプレート + 店舗オーバーライド + 店舗限定メニュー |
| **オプション/トッピング** | 単一選択/複数選択のオプショングループ。店舗別価格上書き |
| **食べ放題プラン** | 時間制限付きプラン。プラン専用メニュー。タイマー管理 |
| **コース料理** | フェーズ制自動/手動発火。コーステンプレート + フェーズ管理 |
| **品切れ管理** | KDS画面から即時トグル。テンプレート/ローカル両対応。音声コマンド対応。2秒バージョンチェックで顧客メニュー・ハンディに即時反映 |
| **CSV入出力** | メニュー、オーバーライド、ローカル品目、プラン、コース、食材、レシピ |
| **売上レポート** | 日次/期間/クロス店舗レポート。ABC分析。サービスタイム分析 |
| **レジ精算** | 現金/カード/QR。レジ開閉。過不足計算 |
| **在庫管理** | 食材マスタ + レシピ（BOM）。棚卸し。決済時自動在庫引き落とし |
| **フロアマップ** | テーブル状態リアルタイム表示。着席/食事中/会計待ち/時間超過 |
| **カート行動分析** | 追加/削除イベントログ。ちゅうちょランキング |
| **AI音声コマンド** | KDS画面でハンズフリー操作。Web Speech API + ファストパス + 任意のGemini補助で音声→ステータス更新。品切れ管理画面でも音声操作（品切れトグル・一覧表示）。ノイズフィルタリング・自動リフレッシュ・確認ポップアップ・10秒取り消し対応 |
| **AIアシスタント** | SNS投稿文生成 + 売上トレンド分析 + 周辺競合調査 + 需要予測・発注提案（Gemini + Places API）。モダンカード表示（テーブル変換・バッジ・グラデーション背景）|
| **バスケット分析** | 併売分析レポート。品目ペアのsupport/confidence/lift算出。ペアランキングテーブル+品目出現頻度バーチャート。AIウェイターのおすすめ精度向上に活用 |
| **決済ゲートウェイ連携** | Stripe対面決済端末（Stripe Reader S700）連携。Stripe Terminal JS SDKでWi-Fi経由端末自動検出。パターンA（自前Stripeキー＝`tenants.stripe_secret_key`）とパターンB（POSLA経由Stripe Connect）の両対応。`get_stripe_auth_for_tenant()` がモードを自動判定し、`/api/connect/status.php` が `terminal_pattern: 'A' / 'B' / null` を返す。決済中オーバーレイ+結果トースト。graceful degradation（キー未設定時はローカル記録のみ）。※P1-2でSquareサポートは完全削除 |
| **Googleレビュー連携** | 満足度4点以上の顧客にGoogleマップレビューリンクを表示。store_settings.google_place_id設定。6秒トースト+多言語対応 |
| **事前注文・テイクアウト予約（L-1）** | 顧客がURLから事前にテイクアウト注文。受取時間15分刻みスロット管理。オンライン決済（Stripe Checkout）対応。店舗ダッシュボードでテイクアウト注文管理タブ。ステータス追跡（受付→調理中→準備完了→受取済）。レート制限・冪等キー・品切れチェック。KDSカード視認性強化（「テイクアウト」バッジ+受取時間+背景グラデーション）。セルフオーダーmenu.htmlからテイクアウト導線リンク（有効店舗のみ表示）。KDS品目ready時ハンディ通知対応（table_id=NULL→LEFT JOIN+ダミーテーブル'TAKEOUT'で通知作成） |
| **owner/manager画面分離** | owner-dashboard.html（本部管理専用）と dashboard.html（店舗運営）にロール別リダイレクト |
| **リアルタイム混雑予報** | 認証不要の公開ページ。テーブル使用率から混雑度を4段階表示（empty/normal/busy/full）。人気メニュー上位3品・品切れ情報・店舗情報を表示。30秒自動更新 |
| **AIウェイター** | 顧客メニュー画面のチャットウィジェット。Gemini APIでメニュー質問に自動応答。おすすめ提案→カート追加連携。サーバーサイドプロキシでAPIキー非露出 |
| **AIキッチンダッシュボード** | KDS画面のAI調理支援パネル。料理品目単位で優先順位分析（urgent/next/waiting）。調理工程の長さ・同時仕上げ・待ち時間を考慮。60秒自動更新 |
| **テーブル呼び出し** | 顧客メニュー画面から「🔔 呼び出し」ボタンでスタッフを呼び出し。理由選択（注文したい/お会計/食器下げ/その他）。KDS・ハンディに赤バナー+ビープ音でリアルタイム通知。対応済みボタンで消去。3分クールダウン |
| **調理進捗表示** | セルフオーダー画面に注文の調理状況をリアルタイム表示。5秒ポーリングで品目別ステータスバッジ（受付/調理中/完成/提供済）+進捗バー。セッション終了検知連携 |
| **満足度評価** | 品目提供後にお客様が5段階フェイスUI（😠😕😐🙂😄）で評価。提供済み検知→自動プロンプト→15秒自動クローズ。低評価はKDS/ハンディにアラート表示。評価キューで複数品目順次評価 |
| **フロアマップ強化** | テーブル状態にno_order（着席済み未注文）追加。品目別進捗バー（受付/調理/完成/提供済）。テーブルメモ表示。サマリーバー（在席数/空席/未注文/会計要求/LO状態）|
| **テーブルメモ** | セッション単位のメモ機能。プリセットタグ（アレルギー/VIP/子連れ等）+自由テキスト。ハンディ着席時入力+テーブル状況タブからメモ編集ボタンで随時編集。フロアマップでモーダル編集。KDS/ハンディに表示 |
| **ラストオーダー管理** | 店舗設定で定時LO時刻設定+フロアマップから手動一斉LO発動。サーバー側注文ブロック（HTTP 409 LAST_ORDER_PASSED）。セルフオーダーにLOバナー+注文ボタン無効化 |
| **KDS↔ハンディ双方向通知** | KDSで品目ready時に自動でcall_alertsに通知作成。ハンディに🍳品目完成通知を表示（タイプ別ビープ音）。「配膳完了」ボタンでorder_itemをservedに連動更新。全品提供済で親注文も自動served |
| **キッチン→スタッフ音声呼び出し** | KDS画面から音声コマンド「スタッフ呼んで」「ホール呼んで」等でハンディに呼び出し通知。call_alertsにtype=kitchen_call作成。KDSに👨‍🍳ポップアップ確認表示。ハンディにオレンジボーダー+3連ビープ×2セット+バイブレーション（Android）。音声有効化バナーで確実な通知音再生 |
| **自動着席・会計後無効化** | QRスキャン時にアクティブセッションがなければ自動でtable_sessionを作成（スタッフ操作不要）。会計後はトークンが変更され、30秒ポーリングで前の客のメニュー画面に「セッション終了」オーバーレイを表示して操作不可にする |
| **注文メモ・アレルギー** | セルフオーダー/ハンディからメモ（自由テキスト200字）とアレルギー特定原材料7品目を注文に付加。KDSにメモ（金色）とアレルギー（赤色）バナーで目立つ表示 |
| **おすすめ・人気バッジ** | 管理画面から「今日のおすすめ」を設定（5種バッジ: おすすめ/人気/新着/限定/本日限り）。過去30日注文データから自動算出する人気ランキングバッジ（🥇🥈🥉 + TOP10）。セルフオーダー画面に「おすすめ」カテゴリタブとバッジを表示 |
| **カロリー・アレルゲン表示** | メニュー品目にカロリー（kcal）と特定原材料等28品目のアレルゲン情報を設定可能。セルフオーダー画面に自動表示 |
| **セキュリティ監査ログ** | ログイン/ログアウト/パスワード変更/設定変更等のセキュリティイベントを記録。owner=テナント全体、manager=自店舗のログを閲覧可能 |
| **アクティブセッション管理** | ログイン中セッション一覧表示。リモートログアウト（他端末の強制ログアウト）。同時セッション数制限（owner:5, manager:3, staff:2） |
| **セッションアイドルタイムアウト** | 25分操作なしで警告モーダル表示（「5分以内に操作がないとログアウトされます」）→ 30分で自動ログアウト。マウス/キー/タッチ/スクロールでアクティビティ検出。フロントは `public/js/session-monitor.js`、サーバーは `api/lib/auth.php` の `SESSION_IDLE_TIMEOUT = 1800` で判定し `SESSION_TIMEOUT` を返却 |
| **オフライン検知** | ネットワーク切断時に赤バナー表示。オフライン中の注文をlocalStorageにキューイング、復帰時に自動リトライ |
| **ライト/ダークテーマ** | KDS/レジ/ハンディ/管理画面にテーマ切替ボタン。CSS変数でテーマ実装。localStorageで設定永続化 |
| **店舗別外観カスタマイズ** | セルフオーダー画面とテイクアウト画面のテーマカラー・ロゴ画像・表示名を店舗ごとにカスタマイズ可能。管理画面の設定タブから変更。未設定時はデフォルト配色（セルフオーダー: #ff6f00 オレンジ、テイクアウト: #2196F3 水色）を維持 |
| **スマレジ連携（L-15）** | 外部POSスマレジとのOAuth2連携。店舗マッピング（POSLA店舗↔スマレジ店舗）。メニューインポート（スマレジ商品→POSLAメニュー）。注文→スマレジ仮販売自動同期（ベストエフォート）。POSLA管理画面でClient ID/Secret一元管理。サンドボックス/本番切替対応 |
| **領収書発行（L-5）** | KDSレジ画面で決済完了後に「領収書」ボタン表示。宛名入力（デフォルト「上様」）→ HTML領収書をwindow.openで新ウィンドウ生成→window.print()でサーマルプリンター出力（72mm幅）。適格簡易請求書対応（T+13桁登録番号・税率別内訳・事業者名表示）。receiptsテーブルに発行記録保存（番号: R-YYYYMMDD-NNNN当日連番）。管理画面で領収書設定（登録番号・事業者名・店舗情報）+ 発行履歴一覧 + 再印刷機能。PDF生成なし・ブラウザ印刷方式 |
| **セルフレジ（L-8）** | セルフオーダー画面（menu.html）から直接オンライン決済。store_settings.self_checkout_enabledで店舗別ON/OFF。「お会計」ボタン→未払い注文一覧+税率別内訳モーダル→決済手段ロゴ列（VISA/Mastercard/JCB/AMEX/Apple Pay/Google Pay）+「お支払いに進む」ボタン→Stripe Checkout（Connect/直接自動分岐）→決済完了→注文paid更新+セッション終了+在庫減算（トランザクション+冪等性）。完了画面で「領収書を表示」→30分限定お会計明細HTML表示（スクリーンショット/AirPrint対応）。紙の領収書はスタッフ発行（L-5）。5言語翻訳対応。クレジットカード（VISA/Mastercard/JCB/AMEX）+ Apple Pay / Google Pay / Link 対応（Stripe Dashboard の Payment methods 設定で有効化、コード変更不要） |
| **メニュー多言語対応（L-4）** | セルフオーダー画面で5言語切替（ja/en/zh-Hans/zh-Hant/ko）。ヘッダー国旗ドロップダウンで即時切替。メニュー名・カテゴリ名・オプション名はmenu_translationsテーブルでキャッシュ管理。UI文言（ボタン・ラベル・メッセージ約22キー）はui_translationsテーブルで管理。管理画面「一括翻訳」ボタンでGemini 2.0 Flash APIによる自動翻訳（30件/バッチ）。`_t(key, fallbackJa)` / `_getLocalizedName(obj)` ヘルパー関数。AIウェイター・呼び出しボタン等の外部JSも `window.__posla_lang` / `window.__posla_t` グローバルブリッジで連動。既存のname_enカラムは維持、追加言語はmenu_translationsで管理 |
| **ウェルカムメッセージ（N-5）** | QRコードスキャン後にモーダルで店舗からの挨拶と目安調理時間を表示。sessionStorageで同一セッション中の重複表示を防止。多言語対応 |
| **追加注文・再注文（N-10）** | セルフオーダー画面に前回注文内容を表示し、ワンタップで再注文。品切れチェック付きで品切れメニューは自動除外。order-history APIで注文履歴取得 |
| **スタッフ評価・不正検知（M-1, M-3）** | 管理ダッシュボード「スタッフ評価」タブ。スタッフ別の注文実績・キャンセル率・満足度・レジ差異を表示。キャンセル率異常・レジ差異自動検出・深夜帯不審操作に対し4種のルールでアラート表示。audit_log+orders+cash_log+satisfaction_ratings集計 |
| **回転率・調理時間分析（M-2）** | 管理ダッシュボード「レポート」→「回転率・客層」サブタブ。テーブル別回転率・平均滞在時間・ランチ/ディナー比較・時間帯別効率。品目別平均/最大調理時間、目標超過率20%以上で赤文字警告 |
| **客層・価格帯分析（M-5）** | M-2と統合表示。組人数別分析（1人〜6人以上）・6段階価格帯分布・時間帯別客数・客単価（注文/人）。table_sessions+orders既存データで集計 |
| **AI需要予測・発注提案（M-6）** | AIアシスタントタブ内「需要予測・発注提案」セクション。過去7日間の売上実績+曜日パターン+在庫量+レシピBOMをGemini 2.0 Flash APIに送信し、本日の売れ筋予測と食材の具体的発注数量を提案 |
| **POSLA管理画面（L-10）** | POSLA運営専用画面（public/posla-admin/）。posla_adminsテーブルで独立認証。ダッシュボード（テナント数/店舗数/ユーザー数/プラン分布）+テナント管理CRUD+テナント作成+API設定（POSLA共通Gemini/Places APIキー管理）。既存テナント画面から完全分離 |
| **プラン基盤・機能制限（L-16）** | tenants.plan ENUM（standard/pro/enterprise）+plan_featuresマスタテーブル。check_plan_feature()/get_tenant_plan()/get_plan_features()でAPI認可。me.phpにplan+features返却。owner-app.jsでプラン外タブを非表示制御 |
| **サブスクリプション課金（L-11 / α-1）** | Stripe Checkout+Customer Portal+Webhook。単一プラン構成（基本料金 1店舗目 ¥20,000、2店舗目以降 ¥17,000、本部一括メニュー配信アドオン +¥3,000/店舗）。posla_settingsでStripe Billingキー一元管理（`stripe_price_base` / `stripe_price_additional_store` / `stripe_price_hq_broadcast`）。subscription_eventsテーブルでイベント記録。オーナーダッシュボード「契約・プラン」タブ |
| **Stripe Connect決済代行（L-13）** | Stripe Connect Express+Destination Charges。自前Stripeを持たない店舗向けPOSLA決済。Connectオンボーディングフロー（本人確認+銀行口座登録）。対面（Terminal JS SDK+S700）/オンライン（Checkout Session）両対応。手数料率はposla_settings管理。POSLA管理画面でConnect状態表示 |
| **クロス店舗レポート** | オーナーダッシュボード「クロス店舗レポート」タブ。複数店舗の売上・注文数・客単価を横並びで比較表示 |
| **ABC分析** | オーナーダッシュボード「ABC分析」タブ。全メニューを売上貢献度でA（上位70%）/B（次20%）/C（残10%）に自動分類。メニュー入れ替え候補の可視化 |
| **シフト管理・勤怠連携（L-3）** | Phase 1（MVP）: シフトテンプレート（早番/遅番等のパターン定義）・週次カレンダー（シフト割当＋公開＋テンプレート適用）・希望シフト提出（スタッフが希望日時を申請）・勤怠打刻（出勤/退勤ボタン＋GPS記録）・自動退勤（cron 5分間隔で打刻忘れ検出）・勤務サマリー（週次/月次集計）。Phase 2: AI最適シフト提案（Gemini API＋売上・希望・勤怠データ連動）・人件費シミュレーション（店舗デフォルト＋スタッフ個別時給＋深夜割増25%自動計算＋カレンダーリアルタイム表示）・労基法チェック（週40h超過・休憩不足・連続6日勤務・1日8h超・深夜勤務の6ルール自動警告）。proプラン以上。管理画面「シフト」グループに6サブタブ。オーナーダッシュボードにシフト概況タブ。Phase 3: 店舗間ヘルプ要請（人手不足時に他店舗へスタッフ派遣を依頼→承認→shift_assignments自動作成。pending/approved/rejected/cancelledワークフロー）・統合シフトビュー（オーナーダッシュボードで全店舗のシフト充足率・人件費・ヘルプ要請を横断表示）。enterprise限定 |
| **GPS出退勤・端末用途制御（L-3b）** | 店舗単位のGPS出退勤制御（shift_settings.gps_required/store_lat/store_lng/gps_radius_meters）。Haversine距離計算で打刻時に店舗圏内判定。旧スタッフ表示ツール制御（shift_settings.staff_visible_tools）は現在、device ロールの遷移先補助値として残す。スタッフ個人アカウントにハンディ/KDS/POSレジ導線は出さない。GPS設定画面に「📍 現在地から店舗位置を設定」ボタン（Geolocation API、全幅・primary表示で目立つUI） |
| **デバイス単位ツール表示制御（L-3b2）** | user_stores.visible_tools（CSV: handy,kds,register、NULL=店舗設定に従う）で端末用途を個別設定。優先順位: デバイス個別設定 > 店舗デフォルト設定 > KDS。DeviceEditor / staff-management.php?kind=device で管理し、me.phpがstores配列にuserVisibleToolsを返却 |

### 1.3 ユーザーロール

| ロール | 権限レベル | できること |
|--------|-----------|-----------|
| **owner** | 3（最高） | テナント全体管理、全店舗暗黙アクセス、店舗CRUD、ユーザーCRUD、クロス店舗レポート |
| **manager** | 2 | 割当店舗の管理、メニュー編集、レポート閲覧、設定変更、CSV入出力 |
| **staff** | 1 | 割当店舗のスタッフ向け管理画面、シフト確認、希望提出、勤怠打刻、担当スタッフ PIN による会計責任者記録 |
| **device** | 0 | KDS / POSレジ / ハンディの店舗備品端末。シフト、勤怠、人件費、スタッフ評価の対象外 |

#### 認証方式とアカウント運用

- **認証方式：ユーザー名 + パスワード。メールアドレスは不要。**
  - 飲食店スタッフはメールアドレスを持っていない・教えたくないケースが多いため、ユーザー名のみでログイン可能にしている
  - ユーザー名はテナント内で一意（例：tanaka、suzuki、yamada01）
- **1人1アカウント（必須ルール）。** POSLAを利用する全スタッフに個別アカウントを作成すること。共有アカウントは禁止
  - 監査ログ・勤怠打刻・スタッフ評価・セッション管理・シフト管理の全てが「誰が操作したか」を前提に設計されている
  - アカウント未作成のスタッフはシフト管理・勤怠の対象外になる
- **アカウント作成権限は階層型：**
  - owner → manager・staffの両方を作成可能（全店舗対象）。オーナーダッシュボード「ユーザー管理」タブで操作
  - manager → 自分の所属店舗のstaffのみ作成可能。管理ダッシュボードの「ユーザー管理」で操作
  - staff → アカウント作成権限なし
- **アカウント作成手順：** ユーザー管理タブ → ユーザー名・パスワード・ロール・所属店舗を設定 → スタッフにユーザー名とパスワードを伝える
- **パスワード変更：** スタッフは初回ログイン後に自分でパスワードを変更可能

---

## 2. 技術スタック・アーキテクチャ

### 2.1 技術スタック

| レイヤー | 技術 |
|----------|------|
| **バックエンド** | 素のPHP（フレームワークなし）|
| **フロントエンド** | Vanilla JavaScript（ES5互換IIFE パターン）+ HTML5 + CSS3 |
| **データベース** | MySQL 5.7+（InnoDB, utf8mb4_unicode_ci）|
| **ホスティング** | さくらインターネット共用サーバー（nginx → Apache リバースプロキシ）|
| **ビルドツール** | なし（パッケージマネージャ不使用）|
| **外部ライブラリ** | QRCode.js（CDN）のみ |
| **外部API** | Google Gemini 2.0 Flash（音声コマンド解析、SNS生成、売上分析、競合分析）、Google Places/Geocoding API（競合調査、サーバーサイドプロキシ経由） |
| **ブラウザAPI** | Web Speech API（KDS音声コマンド、Chrome推奨） |
| **画像保存** | サーバーローカル `uploads/menu/` |

### 2.2 アーキテクチャ図

```
┌─────────────────────────────────────────────────────────┐
│                      クライアント                        │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐   │
│  │ 顧客     │ │ 管理画面 │ │ KDS      │ │ ハンディ │   │
│  │ (QRコード)│ │ (admin)  │ │ (kitchen)│ │ (POS)    │   │
│  └────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬─────┘   │
│       │            │            │            │          │
│       ▼            ▼            ▼            ▼          │
│  ┌──────────────────────────────────────────────────┐   │
│  │              REST API (JSON)                      │   │
│  │  /api/customer/  /api/auth/  /api/kds/           │   │
│  │  /api/owner/     /api/store/                      │   │
│  └──────────────────────┬───────────────────────────┘   │
│                         │                               │
│  ┌──────────────────────▼───────────────────────────┐   │
│  │              PHP ライブラリ層                      │   │
│  │  db.php  response.php  auth.php                   │   │
│  │  business-day.php  menu-resolver.php              │   │
│  └──────────────────────┬───────────────────────────┘   │
│                         │                               │
│  ┌──────────────────────▼───────────────────────────┐   │
│  │              MySQL 5.7+                           │   │
│  │              44テーブル                            │   │
│  └──────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### 2.3 API 設計原則

- **レスポンス統一フォーマット:**
  - 成功: `{ "ok": true, "data": {...}, "serverTime": "ISO8601" }`
  - エラー: `{ "ok": false, "error": { "code": "ERROR_CODE", "message": "日本語メッセージ", "status": 400 }, "serverTime": "ISO8601" }`
- **CORS:** `Access-Control-Allow-Origin: *`（全オリジン許可）
- **認証:** PHPセッション（Cookie ベース、8時間有効、httponly、SameSite=Lax）
- **ID生成:** UUID v4（`random_bytes(16)` ベース）
- **JSON パース:** フロントエンドは安全な `res.text()` → `JSON.parse()` パターン統一

---

## 3. ディレクトリ構成

```
matsunoya-mt/
├── api/
│   ├── config/
│   │   └── database.php              # DB接続設定
│   ├── lib/
│   │   ├── db.php                     # PDOシングルトン + UUID生成
│   │   ├── response.php               # 統一JSON レスポンス関数
│   │   ├── auth.php                   # セッション認証・ロール階層・店舗アクセス制御
│   │   ├── business-day.php           # 営業日計算（cutoff対応）
│   │   ├── menu-resolver.php          # メニュー解決（テンプレート+オーバーライド+ローカル統合）
│   │   ├── smaregi-client.php         # スマレジAPI共通クライアント（トークン管理+リフレッシュ+API呼出）
│   │   ├── stripe-billing.php         # Stripe Billing共通関数（L-11）
│   │   ├── stripe-connect.php         # Stripe Connect共通関数（L-13）
│   │   ├── payment-gateway.php        # 決済ゲートウェイ分岐ロジック（Connect/直接/現金）
│   │   ├── rate-limiter.php           # レート制限ユーティリティ
│   │   ├── order-items.php            # 注文品目ヘルパー
│   │   ├── inventory-sync.php         # 在庫同期ヘルパー（C-5）
│   │   └── audit-log.php              # 監査ログ記録ヘルパー（S-2）
│   ├── auth/
│   │   ├── login.php                  # POST ログイン
│   │   ├── logout.php                 # DELETE ログアウト
│   │   ├── me.php                     # GET 現在ユーザー情報
│   │   └── check.php                  # GET セッション確認（アイドルタイムアウト用）
│   ├── customer/
│   │   ├── table-session.php          # GET テーブルセッション（QRスキャン後）
│   │   ├── menu.php                   # GET メニュー取得（通常/プラン/コース）
│   │   ├── orders.php                 # POST 注文送信（LO時ブロック対応）
│   │   ├── order-status.php           # GET 注文ステータス（品目別進捗表示用）
│   │   ├── satisfaction-rating.php    # POST 満足度評価送信（5段階）
│   │   ├── cart-event.php             # POST カート操作ログ
│   │   ├── ai-waiter.php             # POST AIウェイター（Geminiプロキシ、認証不要）
│   │   ├── call-staff.php           # POST スタッフ呼び出し（認証不要）
│   │   ├── validate-token.php       # GET セッショントークン検証（会計後無効化検出用）
│   │   ├── order-history.php        # GET 注文履歴（再注文セクション用）
│   │   ├── takeout-orders.php       # POST/GET テイクアウト注文作成・スロット取得・ステータス確認（L-1）
│   │   ├── takeout-payment.php      # GET テイクアウトオンライン決済検証（L-1）
│   │   ├── ui-translations.php      # GET UI翻訳テキスト取得（L-4、認証不要）
│   │   ├── get-bill.php             # GET 未払い注文+税率内訳取得（L-8、session_token認証）
│   │   ├── checkout-session.php     # POST Stripe Checkout Session作成（L-8）
│   │   ├── checkout-confirm.php     # POST 決済確認+注文paid+セッション終了（L-8）
│   │   └── receipt-view.php         # GET お会計明細取得（L-8、30分限定）
│   ├── kds/
│   │   ├── orders.php                 # GET 注文ポーリング（ステーション別）
│   │   ├── update-status.php          # PATCH ステータス更新（order単位、ready時通知作成）
│   │   ├── update-item-status.php     # PATCH 品目単位ステータス更新（ready時通知作成、全品served時親注文連動）
│   │   ├── close-table.php            # POST テーブル会計
│   │   ├── cash-log.php               # GET/POST レジ開閉ログ
│   │   ├── advance-phase.php          # POST コースフェーズ手動発火
│   │   ├── sold-out.php               # GET/PATCH 品切れ管理
│   │   ├── ai-kitchen.php            # POST AIキッチンダッシュボード（Gemini品目分析）
│   │   └── call-alerts.php          # GET/PATCH/POST 呼び出しアラート（staff_call + product_ready + kitchen_call対応、配膳完了→served連動）
│   ├── owner/
│   │   ├── tenants.php                # GET/PATCH テナント情報
│   │   ├── stores.php                 # CRUD 店舗管理
│   │   ├── users.php                  # CRUD ユーザー管理
│   │   ├── categories.php             # CRUD カテゴリ管理
│   │   ├── menu-templates.php         # CRUD メニューテンプレート
│   │   ├── option-groups.php          # CRUD オプショングループ＋チョイス
│   │   ├── upload-image.php           # POST 画像アップロード
│   │   ├── menu-csv.php               # GET/POST メニューCSV
│   │   ├── cross-store-report.php     # GET クロス店舗レポート
│   │   ├── analytics-abc.php          # GET ABC分析
│   │   ├── ingredients.php            # CRUD 食材マスタ
│   │   ├── recipes.php                # CRUD レシピ（BOM）
│   │   ├── ingredients-csv.php        # GET/POST 食材CSV
│   │   └── recipes-csv.php            # GET/POST レシピCSV
│   ├── public/
│   │   └── store-status.php           # GET 公開店舗ステータス（認証不要、混雑度・人気メニュー・品切れ）
│   └── store/
│       ├── settings.php               # GET/PATCH 店舗設定
│       ├── tables.php                 # CRUD テーブル管理
│       ├── tables-status.php          # GET フロアマップ用状態（品目進捗+メモ+LO状態）
│       ├── table-sessions.php         # CRUD テーブルセッション（メモ対応）
│       ├── last-order.php             # GET/POST ラストオーダー状態管理
│       ├── local-items.php            # CRUD 店舗限定メニュー
│       ├── menu-overrides.php         # GET/PATCH メニューオーバーライド
│       ├── handy-order.php            # POST/PATCH ハンディ注文
│       ├── kds-stations.php           # CRUD KDSステーション
│       ├── time-limit-plans.php       # CRUD 食べ放題プラン
│       ├── plan-menu-items.php        # CRUD プラン専用メニュー
│       ├── course-templates.php       # CRUD コーステンプレート
│       ├── course-phases.php          # POST/PATCH/DELETE コースフェーズ
│       ├── process-payment.php        # POST POS決済処理
│       ├── payments-journal.php       # GET 取引ジャーナル（本日決済一覧）
│       ├── receipt.php                # POST/GET 領収書発行・取得（L-5）
│       ├── receipt-settings.php       # GET/PATCH 領収書設定（L-5）
│       ├── sales-report.php           # GET 売上レポート
│       ├── register-report.php        # GET レジ精算レポート
│       ├── order-history.php          # GET 注文履歴（ページネーション）
│       ├── upload-image.php           # POST 画像アップロード
│       ├── local-items-csv.php        # GET/POST ローカル品目CSV
│       ├── menu-overrides-csv.php     # GET/POST オーバーライドCSV
│       ├── time-limit-plans-csv.php   # GET/POST プランCSV
│       ├── plan-menu-items-csv.php    # GET/POST プランメニューCSV
│       ├── course-csv.php             # GET/POST コースCSV
│       ├── places-proxy.php          # GET Google Places/Geocoding プロキシ
│       ├── menu-version.php          # GET メニュー変更検知（軽量バージョンチェック）
│       ├── staff-management.php      # CRUD 店舗スタッフ管理（manager権限）
│       ├── daily-recommendations.php # GET/POST/DELETE 今日のおすすめ管理
│       ├── audit-log.php             # GET セキュリティ監査ログ
│       ├── active-sessions.php       # GET/DELETE アクティブセッション管理
│       ├── basket-analysis.php       # GET バスケット分析（併売ペア集計、M-4）
│       ├── demand-forecast-data.php  # GET 需要予測用データ集計（M-6）
│       ├── staff-report.php           # GET スタッフ別評価レポート（M-1）
│       ├── turnover-report.php        # GET 回転率・客層分析レポート（M-2）
│       ├── translate-menu.php         # POST メニュー一括翻訳（L-4、Gemini API）
│       ├── takeout-management.php     # GET/PATCH テイクアウト注文管理（L-1）
│       └── terminal-intent.php        # POST Stripe Terminal決済インテント作成（L-13）
│   ├── posla/                          # L-10: POSLA運営管理API
│   │   ├── auth-helper.php             # ヘルパー: セッション管理・認証ユーティリティ
│   │   ├── login.php                   # POST POSLA管理者ログイン
│   │   ├── logout.php                  # DELETE POSLA管理者ログアウト
│   │   ├── me.php                      # GET 現在の管理者情報
│   │   ├── setup.php                   # POST 初期管理者作成（1回限り）
│   │   ├── dashboard.php               # GET ダッシュボード統計（テナント数・プラン分布等）
│   │   ├── settings.php                # GET/PATCH POSLA共通設定（APIキー等）
│   │   └── tenants.php                 # GET/POST/PATCH テナントCRUD
│   ├── subscription/                   # L-11: サブスクリプションAPI
│   │   ├── checkout.php                # POST Stripeチェックアウトセッション作成
│   │   ├── portal.php                  # POST Stripeカスタマーポータルセッション作成
│   │   ├── status.php                  # GET サブスクリプション状態取得
│   │   └── webhook.php                 # POST Stripe Webhook受信
│   ├── connect/                        # L-13: Stripe Connect API
│   │   ├── onboard.php                 # POST Connectオンボーディング開始
│   │   ├── callback.php                # GET Connectコールバック
│   │   ├── status.php                  # GET Connect接続状態
│   │   └── terminal-token.php          # POST Stripe Terminal接続トークン
│   └── smaregi/                        # L-15: スマレジ連携API
│       ├── auth.php                   # GET OAuth認可リクエスト開始
│       ├── callback.php               # GET OAuthコールバック（トークン交換+contract_id取得）
│       ├── status.php                 # GET スマレジ接続状態
│       ├── disconnect.php             # POST スマレジ連携解除
│       ├── stores.php                 # GET スマレジ店舗一覧取得
│       ├── store-mapping.php          # GET/POST 店舗マッピングCRUD
│       ├── import-menu.php            # POST スマレジ商品→POSLAメニューインポート
│       └── sync-order.php             # POST 注文→スマレジ仮販売送信（関数+エンドポイント二重構造）
├── public/
│   ├── admin/
│   │   ├── index.html                 # ログインページ（ロール別リダイレクト）
│   │   ├── dashboard.html             # 店舗管理ダッシュボードSPA（manager/staff用）
│   │   ├── owner-dashboard.html       # 本部管理ダッシュボード（owner専用）
│   │   ├── bk_dashboard.html          # dashboard.htmlのバックアップ（本番未使用）
│   │   ├── css/
│   │   │   └── admin.css              # 管理画面スタイルシート（1978行）
│   │   └── js/
│   │       ├── admin-api.js           # APIクライアント（762行）
│   │       ├── store-selector.js      # 店舗セレクター
│   │       ├── store-editor.js        # 店舗CRUD
│   │       ├── user-editor.js         # ユーザーCRUD（L-3b2: 表示ツールチェックボックス付き）
│   │       ├── category-editor.js     # カテゴリCRUD
│   │       ├── menu-template-editor.js # HQメニューCRUD
│   │       ├── menu-override-editor.js # 店舗オーバーライド
│   │       ├── local-item-editor.js   # 店舗限定メニューCRUD
│   │       ├── option-group-editor.js # オプションCRUD
│   │       ├── table-editor.js        # テーブルCRUD＋QRコード生成
│   │       ├── floor-map.js           # フロアマップ（5秒ポーリング）
│   │       ├── kds-station-editor.js  # KDSステーション設定
│   │       ├── course-editor.js       # コースCRUD
│   │       ├── time-limit-plan-editor.js # 食べ放題プランCRUD
│   │       ├── settings-editor.js     # 店舗設定フォーム
│   │       ├── sales-report.js        # 売上レポートUI
│   │       ├── register-report.js     # レジレポートUI
│   │       ├── order-history.js       # 注文履歴UI
│   │       ├── turnover-report.js     # 回転率・調理時間・客層分析レポートUI（M-2+M-5）
│   │       ├── staff-report.js        # スタッフ評価・監査・不正検知レポートUI（M-1+M-3）
│   │       ├── basket-analysis.js     # バスケット分析（併売分析）レポートUI（M-4）
│   │       ├── inventory-manager.js   # 在庫管理UI
│   │       ├── audit-log-viewer.js    # 監査ログビューア（S-2）
│   │       ├── abc-analytics.js       # ABC分析UI
│   │       ├── cross-store-report.js  # クロス店舗レポートUI
│   │       ├── ai-assistant.js       # AIアシスタント（SNS生成・売上分析・競合調査・需要予測/発注提案・モダン表示・テーブル変換）
│   │       ├── daily-recommendation-editor.js # 今日のおすすめ管理（N-1）
│   │       ├── receipt-settings.js    # 領収書設定UI（L-5）
│   │       ├── shift-help.js        # L-3 Phase 3: 店舗間ヘルプ要請UI（送信/受信一覧・作成・承認/却下・キャンセル）
│   │       └── owner-app.js         # owner-dashboard制御（統合シフトビュー含む）
│   ├── customer/
│   │   ├── menu.html                  # セルフオーダーSPA（787行）
│   │   ├── takeout.html                    # L-1: テイクアウト注文ページ（5セクション切替・進捗バー・モバイルファースト）
│   │   ├── css/
│   │   │   └── customer.css           # 顧客画面スタイル（オレンジテーマ）
│   │   └── js/
│   │       ├── cart-manager.js        # localStorage カート管理
│   │       ├── order-sender.js        # 冪等注文送信＋リトライ（LO時エラーハンドリング）
│   │       ├── order-tracker.js
│   │   │   └── takeout-app.js              # L-1: テイクアウト注文ページJS（IIFE・メニュー取得・カート・スロット・決済リダイレクト・ステータスポーリング）      # 調理進捗表示（5秒ポーリング、品目別ステータス、DOM自己挿入）
│   │       ├── ai-waiter.js          # AIウェイターチャットウィジェット（DOM自己挿入）
│   │       └── call-staff.js        # スタッフ呼び出しボタン（DOM自己挿入、クールダウン付き）
│   ├── kds/
│   │   ├── index.html                 # KDSメイン（キッチン専用、ステーションボタンバー）
│   │   ├── cashier.html               # スタンドアロンPOSレジ（3カラム）
│   │   ├── accounting.html            # → cashier.html へリダイレクト
│   │   ├── sold-out.html              # 品切れ管理画面
│   │   ├── css/
│   │   │   ├── kds.css                # KDSスタイル（ダークテーマ）
│   │   │   └── cashier.css            # POSレジスタイル（ダークプロテーマ #0f1923）
│   │   └── js/
│   │       ├── kds-auth.js            # KDS認証＋店舗選択
│   │       ├── polling-data-source.js # 3秒ポーリングデータソース
│   │       ├── kds-renderer.js        # カンバンボードレンダラー
│   │       ├── cashier-app.js         # スタンドアロンPOSレジモジュール（CashierApp IIFE）
│   │       ├── accounting-renderer.js # 会計ビューレンダラー（レガシー）
│   │       ├── cashier-renderer.js    # POSレジレンダラー（レガシー、KDS統合時代のもの）
│   │       ├── sold-out-renderer.js   # 品切れ管理レンダラー
│   │       ├── voice-commander.js    # 音声コマンダー（Web Speech API + Gemini）
│   │       ├── ai-kitchen.js        # AIキッチンダッシュボード（DOM自己挿入パネル）
│   │       └── kds-alert.js        # 呼び出しアラート（DOM自己挿入、product_ready+kitchen_callフィルタ除外）
│   ├── live/
│   │   ├── index.html                 # リアルタイム混雑予報（認証不要、30秒自動更新）
│   │   └── css/
│   │       └── live.css               # 混雑予報スタイル（POSLAグリーンテーマ）
│   ├── handy/
│   │   ├── index.html                 # ハンディPOSエントリ
│   │   ├── pos-register.html          # POSレジエントリ
│   │   ├── css/
│   │   │   ├── handy.css              # ハンディスタイル（インディゴダークテーマ）
│   │   │   └── pos-register.css       # POSレジスタイル
│   │   └── js/
│   │       ├── handy-app.js           # ハンディアプリ（1059行）
│   │       ├── pos-register.js        # POSレジアプリ（679行）
│   │       └── handy-alert.js       # 呼び出し+品目完成+キッチン呼び出しアラート（DOM自己挿入、配膳完了→served連動、音声有効化バナー）
│   ├── posla-admin/                   # L-10: POSLA運営管理画面
│   │   ├── index.html                 # POSLA管理者ログインページ
│   │   ├── dashboard.html             # POSLA管理ダッシュボードSPA
│   │   └── js/
│   │       ├── posla-api.js           # POSLA管理APIクライアント
│   │       └── posla-app.js           # POSLA管理ダッシュボード制御
│   ├── js/
│   │   ├── session-monitor.js         # セッションアイドルタイムアウト監視（25分警告→30分ログアウト）
│   │   ├── offline-detector.js        # オフライン検知バナー + 注文キュー
│   │   ├── theme-switcher.js          # ライト/ダークテーマ切替
│   │   ├── shift-manager.js           # L-3: シフト管理モジュール（テンプレート/割当/設定/勤怠一覧/サマリー）
│   │   ├── shift-calendar.js          # L-3: シフトカレンダーUI（週次表示/希望シフト）
│   │   └── attendance-clock.js        # L-3: 勤怠打刻UI（出勤/退勤ボタン常時両方表示、GPS対応、DOM自己挿入）
│   └── shared/
│       └── js/
│           └── utils.js               # 共有ユーティリティ
├── sql/
│   ├── schema.sql                     # 基本スキーマ（13テーブル）※ai_api_key/google_places_api_keyはP1-6c/P1-22でDROP済
│   ├── seed.sql                       # 基本シードデータ
│   ├── seed-a1-options.sql            # オプションサンプル
│   ├── seed-a3-kds-stations.sql       # KDSステーションサンプル
│   ├── seed-b-courses-plans.sql       # コース・食べ放題プランサンプル
│   ├── seed-c5-inventory.sql          # 食材・レシピサンプル
│   ├── seed-tenant-momonoya.sql       # テスト用2テナント目
│   ├── migration-a1-options.sql       # オプション/トッピング（5テーブル）
│   ├── migration-a3-kds-stations.sql  # KDSステーション（2テーブル）
│   ├── migration-a4-removed-items.sql # orders に removed_items 追加
│   ├── migration-b-phase-b.sql        # Phase B 拡張（4テーブル + ALTER）
│   ├── migration-b2-plan-menu-items.sql # プラン専用メニュー（1テーブル）
│   ├── migration-b3-course-session.sql  # コースセッション（ALTER）
│   ├── migration-c4-payments.sql      # 決済テーブル（1テーブル）
│   ├── migration-c5-inventory.sql     # 在庫管理（2テーブル）
│   ├── migration-c5-cart-events.sql   # カートイベント（1テーブル）
│   ├── migration-d1-ai-settings.sql  # tenants ALTER (ai_api_key) ※P1-6cでDROP（履歴保持目的のみ）
│   ├── migration-d2-places-api-key.sql # tenants ALTER (google_places_api_key) ※P1-22でDROP（履歴保持目的のみ）
│   ├── migration-e1-username.sql      # users ALTER
│   ├── migration-e2-override-image.sql # store_menu_overrides ALTER (image_url)
│   ├── migration-e3-call-alerts.sql   # スタッフ呼び出しアラート（1テーブル）
│   ├── migration-f1-token-expiry.sql  # tables ALTER (session_token_expires_at)
│   ├── migration-f2-order-items.sql   # order_items ALTER (allergen_selections)
│   ├── migration-g1-audit-log.sql     # audit_log テーブル（セキュリティ監査ログ）
│   ├── migration-g2-user-sessions.sql # user_sessions テーブル（アクティブセッション管理）
│   ├── migration-h1-welcome-message.sql # store_settings ALTER (welcome_message)
│   ├── migration-i1-order-memo-allergen.sql # orders ALTER (memo), order_items ALTER (allergen_selections)
│   ├── migration-i2-menu-enhancements.sql # menu_templates/store_local_items ALTER + daily_recommendations
│   ├── migration-i3-satisfaction-ratings.sql # satisfaction_ratings テーブル（満足度評価）
│   ├── migration-i4-table-memo.sql       # table_sessions ALTER (memo)
│   ├── migration-i5-last-order-kds-notify.sql # store_settings ALTER (LO) + call_alerts ALTER (type/item)
│   ├── migration-demo-test-users.sql  # デモ用テスト管理者アカウント
│   ├── migration-i6-table-merge-split.sql # payments ALTER + order_items ALTER (テーブル合流分割)
│   ├── migration-i7-kitchen-call.sql  # call_alerts ALTER (kitchen_call追加)
│   ├── migration-l1-takeout.sql       # store_settings ALTER + orders ALTER（テイクアウト）
│   ├── migration-l4-translations.sql  # menu_translations + ui_translations テーブル新設
│   ├── migration-l4-ui-translations-v2.sql # ui_translations 追加キー約55件
│   ├── migration-l5-receipts.sql      # store_settings ALTER + receipts テーブル新設
│   ├── migration-l7-google-place-id.sql # store_settings ALTER (google_place_id)
│   ├── migration-l10-posla-admin.sql  # posla_admins テーブル新設
│   ├── migration-l10b-posla-settings.sql # posla_settings テーブル新設
│   ├── migration-l11-subscription.sql # tenants ALTER + subscription_events テーブル新設
│   ├── migration-l13-stripe-connect.sql # tenants ALTER (stripe_connect_*)
│   ├── migration-l15-smaregi.sql      # tenants ALTER + smaregi_* テーブル新設
│   ├── migration-l16-plan-tier.sql    # tenants.plan ENUM変更 + plan_features テーブル新設
│   ├── migration-n12-store-branding.sql # store_settings ALTER (ブランド設定)
│   ├── migration-plan-remove-lite.sql # tenants.plan ENUM変更（lite削除）
│   ├── migration-c3-payment-gateway.sql # store_settings ALTER（決済ゲートウェイ）
│   ├── migration-l8-self-checkout.sql # store_settings ALTER (self_checkout_enabled)（L-8）
│   ├── migration-l8-ui-translations.sql # ui_translations INSERT（L-8チェックアウト用翻訳キー）
│   ├── migration-l3-shift-management.sql # L-3: shift_settings + shift_templates + shift_assignments + shift_availabilities + attendance_logs（5テーブル新設）
│   ├── migration-l3b-gps-staff-control.sql # L-3b: shift_settings ALTER (store_lat, store_lng, gps_radius_meters, gps_required, staff_visible_tools)
│   ├── migration-l3b2-user-visible-tools.sql # L-3b2: user_stores ALTER (visible_tools VARCHAR(100) NULL)
│   ├── migration-l3p2-labor-cost.sql    # L-3 Phase 2: shift_settings ALTER (default_hourly_rate) + user_stores ALTER (hourly_rate)
│   ├── migration-l3p3-multi-store-shift.sql # L-3 Phase 3: shift_help_requests + shift_help_assignments + shift_assignments ALTER (help_request_id)
│   ├── migration-p1-6c-drop-tenants-ai-key.sql       # P1-6c: tenants DROP COLUMN ai_api_key
│   ├── migration-p1-22-drop-tenants-google-places-key.sql # P1-22: tenants DROP COLUMN google_places_api_key
│   ├── migration-p1-11-subscription-events-unique.sql     # P1-11: subscription_events.stripe_event_id UNIQUE
│   ├── migration-p1-6d-posla-admin-sessions.sql           # P1-6d: posla_admin_sessions テーブル新設
│   ├── migration-p1-remove-square.sql                     # P1-2: tenants.payment_gateway ENUM から square 削除
│   ├── migration-p1-27-torimaru-demo-tenant.sql           # P1-27: 鳥丸グループ enterprise デモテナント（5店舗）seed
│   ├── migration-p119-checkout-i18n.sql                   # P1-19: ui_translations INSERT（アレルギー7キー + gap 2キー × 4言語）
│   ├── migration-p119b-receipt-rollback.sql               # P1-19b: ui_translations DELETE（receipt_* 13キー × 4言語 = 52行、領収書は日本語固定化）
│   ├── migration-p119c-fix-encoding.sql                   # P1-19c: ui_translations DELETE+INSERT（zh-Hans/ko 二重エンコード修正、SET NAMES utf8mb4 明示）
│   └── migration-p133-collation-fix.sql                   # B-2026-04-08-09: daily_recommendations/call_alerts/satisfaction_ratings を utf8mb4_unicode_ci に CONVERT
├── uploads/
│   └── menu/                          # メニュー画像保存先
├── tools/
│   └── generate-demo-images.php       # デモ用メニュー画像生成（使い捨て）
└── docs/
    ├── SYSTEM_SPECIFICATION.md        # 本ドキュメント
    └── L15-smaregi-troubleshooting-log.md # スマレジ連携トラブルシューティング記録+API仕様+本番チェックリスト
```

---

## 4. データベーススキーマ

### 4.1 全体ER図（概念）

```
tenants ─────┬──── stores ──────┬──── store_settings (1:1)
             │                  ├──── tables ──── table_sessions
             │                  ├──── orders
             │                  ├──── payments
             │                  ├──── cash_log
             │                  ├──── kds_stations ──── kds_routing_rules → categories
             │                  ├──── store_menu_overrides → menu_templates
             │                  ├──── store_local_items ──── local_item_options → option_groups
             │                  ├──── store_option_overrides → option_choices
             │                  ├──── time_limit_plans ──── plan_menu_items
             │                  ├──── course_templates ──── course_phases
             │                  ├──── cart_events
             │                  ├──── call_alerts
             │                  ├──── daily_recommendations → menu_templates / store_local_items
             │                  ├──── audit_log
             │                  ├──── user_sessions → users
             │                  ├──── satisfaction_ratings → orders / order_items
             │                  └──── order_rate_log
             │
             ├──── users ──── user_stores (M:N → stores)
             ├──── categories
             ├──── menu_templates ──── menu_template_options (M:N → option_groups)
             │                   └──── recipes → ingredients
             ├──── option_groups ──── option_choices
             ├──── ingredients
             ├──── smaregi_store_mapping → stores (L-15)
             ├──── smaregi_product_mapping → menu_templates (L-15)
             ├──── menu_translations (L-4)
             └──── shift_help_requests ──── shift_help_assignments → users (L-3 Phase 3)
                   (from_store_id → stores, to_store_id → stores)

plan_features (plan, feature_key)   ← テナント非依存
posla_settings (setting_key)        ← POSLA運営共通
posla_admins                        ← POSLA運営管理者
subscription_events → tenants (L-11)
ui_translations (lang, msg_key)     ← テナント非依存

stores ──── receipts → payments (L-5)
```

**合計 52 テーブル**（schema.sql 13テーブル + マイグレーションで追加 39テーブル。2026-04-08 時点）

### 4.2 SQL実行順序

```
1. schema.sql                    — 基本13テーブル
2. seed.sql                      — デモテナント "matsunoya"
3. migration-a1-options.sql      — オプション 5テーブル
4. seed-a1-options.sql           — オプションサンプル
5. migration-a3-kds-stations.sql — KDSステーション 2テーブル
6. seed-a3-kds-stations.sql      — KDSサンプル
7. migration-a4-removed-items.sql — orders ALTER
8. migration-b-phase-b.sql       — Phase B 4テーブル + ALTER
9. migration-b2-plan-menu-items.sql — plan_menu_items 1テーブル
10. migration-b3-course-session.sql — table_sessions ALTER
11. seed-b-courses-plans.sql    — コース・プランサンプル
12. migration-c4-payments.sql     — payments 1テーブル
13. migration-c5-inventory.sql    — ingredients + recipes 2テーブル
14. seed-c5-inventory.sql        — 食材・レシピサンプル
15. migration-c5-cart-events.sql  — cart_events 1テーブル
16. migration-d1-ai-settings.sql — tenants ALTER (ai_api_key) ※schema.sqlに統合済、既存環境のみ必要
17. migration-d2-places-api-key.sql — tenants ALTER (google_places_api_key)
18. migration-e1-username.sql    — users ALTER
19. migration-e2-override-image.sql — store_menu_overrides ALTER (image_url)
20. migration-e3-call-alerts.sql — call_alerts 1テーブル
21. migration-f1-token-expiry.sql — tables ALTER (session_token_expires_at)
22. migration-f2-order-items.sql — order_items ALTER (allergen_selections)
23. migration-g1-audit-log.sql   — audit_log 1テーブル
24. migration-g2-user-sessions.sql — user_sessions 1テーブル
25. migration-h1-welcome-message.sql — store_settings ALTER (welcome_message)
26. seed-tenant-momonoya.sql     — テスト用2テナント目
27. migration-i1-order-memo-allergen.sql — orders ALTER (memo), order_items ALTER (allergen_selections)
28. migration-i2-menu-enhancements.sql — menu_templates/store_local_items ALTER (calories, allergens) + daily_recommendations 1テーブル
29. migration-i3-satisfaction-ratings.sql — satisfaction_ratings 1テーブル
30. migration-i4-table-memo.sql — table_sessions ALTER (memo)
31. migration-i5-last-order-kds-notify.sql — store_settings ALTER (LO関連3カラム) + call_alerts ALTER (type/order_item_id/item_name)
32. migration-demo-test-users.sql — デモ用テスト管理者アカウント (test1-test5)
33. migration-i6-table-merge-split.sql — payments ALTER (merged_table_ids, merged_session_ids) + order_items ALTER (payment_id)
34. migration-i7-kitchen-call.sql — call_alerts ALTER (type ENUM に kitchen_call 追加)
35. migration-l1-takeout.sql — store_settings ALTER (テイクアウト設定6カラム) + orders ALTER (テイクアウト関連)
36. migration-l7-google-place-id.sql — store_settings ALTER (google_place_id)
37. migration-n12-store-branding.sql — store_settings ALTER (brand_color, brand_logo_url, brand_display_name)
38. migration-l15-smaregi.sql — tenants ALTER (スマレジ認証5カラム) + smaregi_store_mapping + smaregi_product_mapping + orders ALTER (smaregi_transaction_id) + posla_settings INSERT (smaregi_client_id/secret)
39. migration-l5-receipts.sql — store_settings ALTER (registration_number, business_name) + receipts テーブル新設
40. migration-l10-posla-admin.sql — posla_admins テーブル新設
41. migration-l10b-posla-settings.sql — posla_settings テーブル新設（gemini_api_key, google_places_api_key）
42. migration-l11-subscription.sql — tenants ALTER (stripe_customer_id, stripe_subscription_id, subscription_status, current_period_end) + subscription_events テーブル新設 + posla_settings INSERT (stripe_*_key, stripe_price_*)
43. migration-l13-stripe-connect.sql — tenants ALTER (stripe_connect_*) + posla_settings INSERT (stripe_connect_*)
44. migration-l16-plan-tier.sql — tenants.plan ENUM変更 (free/standard/premium → lite/standard/pro/enterprise) + plan_features テーブル新設（18機能×4プラン）
45. migration-plan-remove-lite.sql — tenants.plan ENUM変更（lite削除: standard/pro/enterprise の3段階）
46. migration-l4-translations.sql — menu_translations テーブル新設 + ui_translations テーブル新設 + 5言語UI初期データ投入
47. migration-l4-ui-translations-v2.sql — ui_translations に追加キー約55件投入（ボタン・モーダル・AI・呼出等の全UI文言）
48. migration-c3-payment-gateway.sql — store_settings ALTER (決済ゲートウェイ設定カラム)
49. migration-l8-self-checkout.sql — store_settings ALTER (self_checkout_enabled セルフレジ有効フラグ)
50. migration-l8-ui-translations.sql — ui_translations INSERT (L-8チェックアウト用翻訳キー 5言語×16キー)
51. migration-l3-shift-management.sql — shift_settings + shift_templates + shift_assignments + shift_availabilities + attendance_logs（5テーブル新設）
52. migration-l3b-gps-staff-control.sql — shift_settings ALTER (GPS出退勤 + スタッフ表示ツール制御)
53. migration-l3b2-user-visible-tools.sql — user_stores ALTER (visible_tools: ユーザー単位ツール表示制御)
54. migration-l3p2-labor-cost.sql — shift_settings ALTER (default_hourly_rate) + user_stores ALTER (hourly_rate)
55. migration-l3p3-multi-store-shift.sql — shift_help_requests + shift_help_assignments 新設、shift_assignments ALTER (help_request_id)、plan_features INSERT (shift_help_request)
56. migration-p1-6c-drop-tenants-ai-key.sql — tenants DROP COLUMN ai_api_key（P1-6c、posla_settings.gemini_api_key へ完全移行）
57. migration-p1-22-drop-tenants-google-places-key.sql — tenants DROP COLUMN google_places_api_key（P1-22、posla_settings.google_places_api_key へ完全移行）
58. migration-p1-11-subscription-events-unique.sql — subscription_events ALTER (stripe_event_id に UNIQUE INDEX 追加。P1-11、webhook 重複イベントの冪等性確保)
59. migration-p1-6d-posla-admin-sessions.sql — posla_admin_sessions テーブル新設（P1-6d、POSLA管理者の他セッション無効化サポート）
60. migration-p1-remove-square.sql — tenants.payment_gateway ENUM から 'square' 削除（P1-2、Square 決済ゲートウェイ廃止）
61. migration-p1-27-torimaru-demo-tenant.sql — 鳥丸グループ enterprise デモテナント seed（5店舗: 渋谷/新宿/池袋/銀座/恵比寿）
62. migration-p119-checkout-i18n.sql — ui_translations INSERT（P1-19、アレルギー7キー [Group A] + checkout_not_confirmed/btn_close [Group C] × 4言語 = 36行）
63. migration-p119b-receipt-rollback.sql — ui_translations DELETE（P1-19b、receipt_* 13キー × 4言語 = 52行。領収書は日本語固定化、Blob URL 方式で文字化け回避）
64. migration-p119c-fix-encoding.sql — ui_translations DELETE+INSERT（P1-19c、zh-Hans/ko の btn_close/allergen_*/checkout_not_confirmed 二重エンコード修正。SET NAMES utf8mb4 を先頭明示）
65. migration-p133-collation-fix.sql — daily_recommendations/call_alerts/satisfaction_ratings を utf8mb4_unicode_ci に CONVERT（B-2026-04-08-09、MySQL 8.0 デフォルト utf8mb4_0900_ai_ci からの混入修正）
```

> **恒久対策（2026-04-08 以降）:** 今後追加する全マイグレーション SQL は先頭に `SET NAMES utf8mb4;` を明示する（P1-19c 教訓）。MySQL クライアント接続のデフォルト charset が utf8mb4 でない環境で INSERT すると、zh-Hans/ko 等の多バイト文字列が二重エンコードされる事故を防止。

### 4.3 テーブル定義詳細

#### 4.3.1 `tenants` — テナント（企業/ブランド）

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `slug` | VARCHAR(50) | NOT NULL, UNIQUE | URL識別子（例: "matsunoya"）|
| `name` | VARCHAR(200) | NOT NULL | 企業表示名（日本語）|
| `name_en` | VARCHAR(200) | DEFAULT NULL | 企業表示名（英語）|
| `plan` | ENUM('standard','pro','enterprise') | NOT NULL, DEFAULT 'standard' | 契約プラン（3段階: standard/pro/enterprise） |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | 有効フラグ |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP | |
| ~~`ai_api_key`~~ | ~~VARCHAR(200)~~ | ~~DROPPED~~ | ※廃止済み（P1-6c で DROP）。現在は `posla_settings.gemini_api_key` で一元管理（L-10b） |
| ~~`google_places_api_key`~~ | ~~VARCHAR(200)~~ | ~~DROPPED~~ | ※廃止済み（P1-22 で DROP）。現在は `posla_settings.google_places_api_key` で一元管理（L-10b） |
| `payment_gateway` | ENUM('none','stripe') | NOT NULL, DEFAULT 'none' | 使用する決済ゲートウェイ（C-3、P1-2でsquare削除） |
| `stripe_secret_key` | VARCHAR(200) | DEFAULT NULL | Stripe Secret Key（C-3、店舗自前＝パターンA） |
| `stripe_customer_id` | VARCHAR(100) | DEFAULT NULL | Stripe Customer ID（L-11サブスクリプション） |
| `stripe_subscription_id` | VARCHAR(100) | DEFAULT NULL | Stripe Subscription ID（L-11） |
| `subscription_status` | VARCHAR(50) | DEFAULT NULL | サブスクリプション状態（active/past_due/canceled等） |
| `current_period_end` | INT | DEFAULT NULL | 現在の請求期間終了（UNIXタイムスタンプ） |
| `stripe_connect_account_id` | VARCHAR(100) | DEFAULT NULL | Stripe Connect Account ID（L-13、acct_...） |
| `connect_onboarding_complete` | TINYINT(1) | NOT NULL, DEFAULT 0 | Connectオンボーディング完了フラグ（L-13） |
| `updated_at` | DATETIME | NOT NULL, AUTO UPDATE | |

**FK:** なし
**INDEX:** `slug` UNIQUE

---

#### 4.3.1b `subscription_events` — サブスクリプションイベント記録（L-11）

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL | テナントID |
| `event_type` | VARCHAR(50) | NOT NULL | イベント種別（checkout.session.completed等） |
| `stripe_event_id` | VARCHAR(100) | DEFAULT NULL, **UNIQUE** (P1-11) | StripeイベントID（evt_...）。P1-11で UNIQUE INDEX 追加（webhook 重複イベントの冪等性確保） |
| `data` | TEXT | DEFAULT NULL | イベントデータ（JSON） |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP | |

**FK:** `tenant_id` → `tenants.id`
**INDEX:** `stripe_event_id` UNIQUE（P1-11、`api/subscription/webhook.php` で `INSERT IGNORE` パターンによる冪等性チェック）

---

#### 4.3.2 `stores` — 店舗

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL, FK → tenants(id) | 所属テナント |
| `slug` | VARCHAR(50) | NOT NULL | テナント内URL識別子（例: "shibuya"）|
| `name` | VARCHAR(200) | NOT NULL | 店舗名 |
| `name_en` | VARCHAR(200) | DEFAULT NULL | 英語名 |
| `timezone` | VARCHAR(50) | NOT NULL, DEFAULT 'Asia/Tokyo' | タイムゾーン |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | 有効フラグ |
| `created_at` | DATETIME | | |
| `updated_at` | DATETIME | | |

**FK:** `tenant_id` → `tenants(id)` ON UPDATE CASCADE
**INDEX:** `(tenant_id, slug)` UNIQUE, `(tenant_id)`

---

#### 4.3.3 `users` — ユーザー

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL, FK → tenants(id) | 所属テナント |
| `email` | VARCHAR(254) | NOT NULL, UNIQUE | ログインメール |
| `password_hash` | VARCHAR(255) | NOT NULL | `password_hash()` 出力 |
| `display_name` | VARCHAR(100) | NOT NULL, DEFAULT '' | 表示名 |
| `role` | ENUM('owner','manager','staff') | NOT NULL, DEFAULT 'staff' | ロール |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | 有効フラグ |
| `last_login_at` | DATETIME | DEFAULT NULL | 最終ログイン |
| `created_at` | DATETIME | | |
| `updated_at` | DATETIME | | |

**FK:** `tenant_id` → `tenants(id)` ON UPDATE CASCADE
**INDEX:** `email` UNIQUE, `(tenant_id)`

---

#### 4.3.4 `user_stores` — ユーザー ↔ 店舗紐付け（M:N）

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `user_id` | VARCHAR(36) | PK(複合), FK → users(id) ON DELETE CASCADE | |
| `store_id` | VARCHAR(36) | PK(複合), FK → stores(id) ON DELETE CASCADE | |
| `visible_tools` | VARCHAR(100) | NULL, DEFAULT NULL | L-3b2: CSV形式（handy,kds,register）。NULL=店舗設定に従う |
| `hourly_rate` | INT | NULL | L-3 Phase 2: スタッフ個別時給（円）。NULL=店舗デフォルトに従う |
| `created_at` | DATETIME | | |

**注意:** ownerロールは `user_stores` に行を持たない（テナント内全店舗へ暗黙アクセス）

**ツール表示優先順位（L-3b2）:** `user_stores.visible_tools`（個別）> `shift_settings.staff_visible_tools`（店舗）> 全表示

**時給優先順位（L-3 Phase 2）:** `user_stores.hourly_rate`（個別）> `shift_settings.default_hourly_rate`（店舗デフォルト）> NULL（人件費計算スキップ）

---

#### 4.3.5 `store_settings` — 店舗設定（1:1）

| カラム | 型 | デフォルト | 説明 |
|--------|-----|-----------|------|
| `store_id` | VARCHAR(36) | PK, FK → stores(id) ON DELETE CASCADE | |
| `max_items_per_order` | INT | 10 | 1注文の最大品数 |
| `max_amount_per_order` | INT | 30000 | 1注文の最大金額（円）|
| `max_quantity_per_item` | INT | 5 | 1品目の最大数量 |
| `max_toppings_per_item` | INT | 5 | 1品目の最大トッピング数 |
| `rate_limit_orders` | INT | 3 | レート制限: 最大注文数 |
| `rate_limit_window_min` | INT | 5 | レート制限: ウィンドウ（分）|
| `day_cutoff_time` | TIME | '05:00:00' | 営業日カットオフ時刻 |
| `default_open_amount` | INT | 30000 | レジ開けデフォルト金額 |
| `overshort_threshold` | INT | 1000 | 過不足アラート閾値 |
| `payment_methods_enabled` | VARCHAR(100) | 'cash,card,qr' | 有効な決済方法 |
| `receipt_store_name` | VARCHAR(100) | NULL | レシート店舗名 |
| `receipt_address` | VARCHAR(200) | NULL | レシート住所 |
| `receipt_phone` | VARCHAR(20) | NULL | レシート電話番号 |
| `tax_rate` | DECIMAL(5,2) | 10.00 | デフォルト税率（%）|
| `receipt_footer` | VARCHAR(200) | NULL | レシートフッター |
| `last_order_time` | TIME | NULL | 定時ラストオーダー時刻（例: 21:30:00）※ migration-i5 |
| `last_order_active` | TINYINT(1) | 0 | 手動ラストオーダーフラグ（1=発動中）※ migration-i5 |
| `last_order_activated_at` | DATETIME | NULL | 手動ラストオーダー発動時刻 ※ migration-i5 |
| `brand_color` | VARCHAR(7) | NULL | テーマカラー（#RRGGBB形式）。NULL=デフォルト配色 ※ migration-n12 |
| `brand_logo_url` | VARCHAR(500) | NULL | ロゴ画像URL ※ migration-n12 |
| `brand_display_name` | VARCHAR(100) | NULL | ヘッダー表示名（上書き）※ migration-n12 |
| `registration_number` | VARCHAR(20) | NULL | 適格請求書発行事業者登録番号（T+13桁）※ migration-l5 |
| `business_name` | VARCHAR(100) | NULL | 事業者正式名称 ※ migration-l5 |
| `self_checkout_enabled` | TINYINT(1) | NOT NULL, DEFAULT 0 | セルフレジ有効フラグ ※ migration-l8。P1-10b で管理UI追加（管理画面「設定」タブ→「レジ設定」セクション、`api/store/settings.php` PATCH 経由で manager+ が変更可、audit_log 記録あり） |

---

#### 4.3.6a `receipts` — 領収書発行記録 ※ migration-l5

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL | テナントスコープ |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) | 店舗 |
| `payment_id` | VARCHAR(36) | NOT NULL | payments テーブルの ID |
| `receipt_number` | VARCHAR(20) | NOT NULL, UNIQUE(store_id, receipt_number) | 領収書番号（R-YYYYMMDD-NNNN 当日連番）|
| `receipt_type` | ENUM('receipt','invoice') | NOT NULL, DEFAULT 'receipt' | 領収書 or 適格簡易請求書 |
| `addressee` | VARCHAR(100) | NULL | 宛名（会社名・個人名。未入力時「上様」）|
| `subtotal_10` | INT | NOT NULL, DEFAULT 0 | 10%対象税抜金額 |
| `tax_10` | INT | NOT NULL, DEFAULT 0 | 10%消費税額 |
| `subtotal_8` | INT | NOT NULL, DEFAULT 0 | 8%対象税抜金額 |
| `tax_8` | INT | NOT NULL, DEFAULT 0 | 8%消費税額 |
| `total_amount` | INT | NOT NULL, DEFAULT 0 | 合計金額 |
| `pdf_path` | VARCHAR(255) | NULL | 未使用（NULL固定。将来のPDF保存用に予約）|
| `issued_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 発行日時 |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 作成日時 |

**INDEX:** `idx_store_date(store_id, issued_at)`, `idx_payment(payment_id)`

---

#### 4.3.6 `categories` — メニューカテゴリ

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL, FK → tenants(id) | テナントスコープ |
| `name` | VARCHAR(100) | NOT NULL | カテゴリ名（例: "定食", "丼物"）|
| `name_en` | VARCHAR(100) | DEFAULT NULL | 英語名 |
| `sort_order` | INT | NOT NULL, DEFAULT 0 | 表示順 |

**INDEX:** `(tenant_id)`, `(tenant_id, sort_order)`

---

#### 4.3.7 `menu_templates` — 本部メニューテンプレート

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL, FK → tenants(id) | テナントスコープ |
| `category_id` | VARCHAR(36) | NOT NULL, FK → categories(id) | カテゴリ |
| `name` | VARCHAR(200) | NOT NULL | 品名 |
| `name_en` | VARCHAR(200) | DEFAULT NULL | 英語名 |
| `base_price` | INT | NOT NULL | 本部基準価格（税込円）|
| `description` | TEXT | | 説明文 |
| `description_en` | TEXT | | 英語説明文 |
| `image_url` | VARCHAR(500) | DEFAULT NULL | 画像パス |
| `calories` | INT | DEFAULT NULL | カロリー（kcal）※ migration-i2 |
| `allergens` | JSON | DEFAULT NULL | アレルゲン情報 `["egg","milk",...]` ※ migration-i2 |
| `is_sold_out` | TINYINT(1) | DEFAULT 0 | 本部レベル品切れ |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |
| `sort_order` | INT | DEFAULT 0 | 表示順 |

**INDEX:** `(tenant_id)`, `(category_id)`, `(sort_order)`

---

#### 4.3.8 `store_menu_overrides` — 店舗メニューオーバーライド

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) ON DELETE CASCADE | |
| `template_id` | VARCHAR(36) | NOT NULL, FK → menu_templates(id) ON DELETE CASCADE | |
| `price` | INT | DEFAULT NULL | 上書き価格（NULL=本部価格継承）|
| `is_hidden` | TINYINT(1) | DEFAULT 0 | この店舗で非表示 |
| `is_sold_out` | TINYINT(1) | DEFAULT 0 | 店舗レベル品切れ |
| `image_url` | VARCHAR(500) | DEFAULT NULL | 店舗独自画像（NULL=テンプレート画像継承）※ migration-e2 |
| `sort_order` | INT | DEFAULT NULL | 上書き表示順 |

**INDEX:** `(store_id, template_id)` UNIQUE

**設計思想:** NULLは「本部から継承」を意味する。値が設定されている場合のみ上書きが適用される。画像も同様にNULL=テンプレート画像を使用。

---

#### 4.3.9 `store_local_items` — 店舗限定メニュー

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) ON DELETE CASCADE | |
| `category_id` | VARCHAR(36) | NOT NULL, FK → categories(id) | |
| `name` | VARCHAR(200) | NOT NULL | 品名 |
| `name_en` | VARCHAR(200) | DEFAULT NULL | 英語名 |
| `price` | INT | NOT NULL | 価格（税込円）|
| `description` | TEXT | | 説明 |
| `description_en` | TEXT | | 英語説明 |
| `image_url` | VARCHAR(500) | DEFAULT NULL | 画像 |
| `calories` | INT | DEFAULT NULL | カロリー（kcal）※ migration-i2 |
| `allergens` | JSON | DEFAULT NULL | アレルゲン情報 `["egg","milk",...]` ※ migration-i2 |
| `is_sold_out` | TINYINT(1) | DEFAULT 0 | 品切れ |
| `sort_order` | INT | DEFAULT 0 | 表示順 |

---

#### 4.3.10 `tables` — テーブル

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) ON DELETE CASCADE | |
| `table_code` | VARCHAR(20) | NOT NULL | 表示コード（T01等）|
| `capacity` | INT | DEFAULT 4 | 席数 |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |
| `session_token` | VARCHAR(64) | DEFAULT NULL | テーブル使用サイクル識別トークン |
| `pos_x` | INT | DEFAULT 0 | フロアマップX座標 |
| `pos_y` | INT | DEFAULT 0 | フロアマップY座標 |
| `width` | INT | DEFAULT 80 | フロアマップ幅 |
| `height` | INT | DEFAULT 80 | フロアマップ高さ |
| `shape` | ENUM('rect','circle','ellipse') | DEFAULT 'rect' | テーブル形状 |
| `floor` | VARCHAR(30) | DEFAULT '1F' | フロア名 |

**INDEX:** `(store_id, table_code)` UNIQUE, `(store_id, floor)`

---

#### 4.3.11 `orders` — 注文

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) | |
| `table_id` | VARCHAR(36) | DEFAULT NULL, FK → tables(id) | テイクアウトはNULL |
| `items` | JSON | NOT NULL | 注文品目 `[{id, name, price, qty, options?}]` |
| `removed_items` | JSON | DEFAULT NULL | カートから除外された品目 |
| `total_amount` | INT | NOT NULL | 合計金額 |
| `status` | ENUM('pending','preparing','ready','served','paid','cancelled') | DEFAULT 'pending' | ステータス |
| `order_type` | ENUM('dine_in','takeout','handy') | DEFAULT 'dine_in' | 注文種別 |
| `staff_id` | VARCHAR(36) | DEFAULT NULL | ハンディ注文のスタッフ |
| `customer_name` | VARCHAR(100) | DEFAULT NULL | テイクアウト顧客名 |
| `customer_phone` | VARCHAR(30) | DEFAULT NULL | テイクアウト電話番号 |
| `pickup_at` | DATETIME | DEFAULT NULL | テイクアウト受取予定時刻 |
| `course_id` | VARCHAR(36) | DEFAULT NULL | コースID |
| `current_phase` | INT | DEFAULT NULL | コースフェーズ番号 |
| `payment_method` | ENUM('cash','card','qr') | DEFAULT NULL | 決済方法 |
| `received_amount` | INT | DEFAULT NULL | 預かり金 |
| `change_amount` | INT | DEFAULT NULL | お釣り |
| `idempotency_key` | VARCHAR(64) | UNIQUE | 冪等キー（重複送信防止）|
| `session_token` | VARCHAR(64) | DEFAULT NULL | 注文時のテーブルセッショントークン |
| `memo` | VARCHAR(200) | DEFAULT NULL | 注文メモ（ネギ抜き等）※ migration-i1 |
| `created_at` | DATETIME | | 注文受付時刻 |
| `updated_at` | DATETIME | | |
| `prepared_at` | DATETIME | DEFAULT NULL | 調理開始時刻 |
| `ready_at` | DATETIME | DEFAULT NULL | 完成時刻 |
| `served_at` | DATETIME | DEFAULT NULL | 提供時刻 |
| `paid_at` | DATETIME | DEFAULT NULL | 会計時刻 |

**ステータス遷移:** `pending` → `preparing` → `ready` → `served` → `paid`
**特殊遷移:** 任意のステータスから `cancelled` へ遷移可能

**INDEX:** `(store_id)`, `(table_id)`, `(status)`, `(store_id, created_at)`, `(order_type)`, `(pickup_at)`, `(staff_id)`, `(idempotency_key)` UNIQUE

---

#### 4.3.11b `order_items` — 注文品目（行単位ステータス管理） ※ migration-f2 (C-4)

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `order_id` | VARCHAR(36) | NOT NULL, FK → orders(id) ON DELETE CASCADE | 親注文 |
| `payment_id` | VARCHAR(36) | DEFAULT NULL | 支払済み追跡（migration-i6、テーブル合流分割会計で使用）|
| `store_id` | VARCHAR(36) | NOT NULL | マルチテナント境界確保用の冗長カラム |
| `menu_item_id` | VARCHAR(36) | DEFAULT NULL | `menu_templates.id` または `store_local_items.id`（スナップショット優先のため FK なし）|
| `name` | VARCHAR(100) | NOT NULL | 品目名（注文時点スナップショット）|
| `price` | INT | NOT NULL, DEFAULT 0 | 単価（注文時点）|
| `qty` | INT | NOT NULL, DEFAULT 1 | 数量 |
| `options` | JSON | DEFAULT NULL | オプション情報（選択肢配列）|
| `allergen_selections` | JSON | NULL | アレルギー選択（migration-i1 / migration-f2）。特定原材料7品目 egg/milk/wheat/shrimp/crab/buckwheat/peanut |
| `status` | ENUM('pending','preparing','ready','served','cancelled') | NOT NULL, DEFAULT 'pending' | KDS品目単位ステータス（C-4）|
| `prepared_at` | DATETIME | DEFAULT NULL | 調理開始時刻 |
| `ready_at` | DATETIME | DEFAULT NULL | 完成時刻 |
| `served_at` | DATETIME | DEFAULT NULL | 提供時刻 |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE | |

**FK:** `order_id` → `orders(id)` ON DELETE CASCADE
**INDEX:** `idx_oi_order (order_id)`, `idx_oi_store_status (store_id, status)`, `idx_order_items_payment_id (payment_id)`

**用途:** C-4（KDS品目単位ステータス管理）で orders.items JSON と並行運用。KDS の品目単位完成/提供操作、テーブル合流分割会計時の支払済み追跡、バスケット分析、回転率レポート（品目別調理時間）、スタッフレポートで参照。レシピ連動の在庫減算（`inventory-sync.php`）も order_items ベース。孤立レコードは履歴保全の副産物で UI フォールバック `oi.item_name` で吸収（B-2026-04-08-11 対応不要判定）。

---

#### 4.3.12 `cash_log` — 入出金ログ

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) | |
| `user_id` | VARCHAR(36) | DEFAULT NULL | 操作スタッフ |
| `type` | ENUM('open','close','cash_sale','cash_in','cash_out') | NOT NULL | 種別 |
| `amount` | INT | NOT NULL | 金額 |
| `note` | VARCHAR(200) | DEFAULT NULL | メモ |
| `created_at` | DATETIME | | |

---

#### 4.3.13 `order_rate_log` — レート制限ログ

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | INT | PK, AUTO_INCREMENT | |
| `store_id` | VARCHAR(36) | NOT NULL | |
| `table_id` | VARCHAR(36) | NOT NULL | |
| `session_id` | VARCHAR(128) | NOT NULL | クライアントセッション |
| `ordered_at` | DATETIME | | |

---

#### 4.3.14 `option_groups` — オプショングループ

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL, FK → tenants(id) | テナントスコープ |
| `name` | VARCHAR(100) | NOT NULL | グループ名（例: "ごはんの量"）|
| `name_en` | VARCHAR(100) | DEFAULT NULL | 英語名 |
| `selection_type` | ENUM('single','multi') | DEFAULT 'single' | single=ラジオ, multi=チェック |
| `min_select` | INT | DEFAULT 0 | 最小選択数（0=任意）|
| `max_select` | INT | DEFAULT 1 | 最大選択数 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |

---

#### 4.3.15 `option_choices` — オプション選択肢

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `group_id` | VARCHAR(36) | NOT NULL, FK → option_groups(id) ON DELETE CASCADE | |
| `name` | VARCHAR(100) | NOT NULL | 選択肢名（例: "大盛り"）|
| `name_en` | VARCHAR(100) | DEFAULT NULL | 英語名 |
| `price_diff` | INT | DEFAULT 0 | 価格差分（マイナス可）|
| `is_default` | TINYINT(1) | DEFAULT 0 | デフォルト選択 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |

---

#### 4.3.16 `menu_template_options` — メニュー ↔ オプション紐付け

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `template_id` | VARCHAR(36) | PK(複合), FK → menu_templates(id) ON DELETE CASCADE | |
| `group_id` | VARCHAR(36) | PK(複合), FK → option_groups(id) ON DELETE CASCADE | |
| `is_required` | TINYINT(1) | DEFAULT 0 | 必須フラグ |
| `sort_order` | INT | DEFAULT 0 | 表示順 |

---

#### 4.3.17 `store_option_overrides` — 店舗別オプション上書き

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) ON DELETE CASCADE | |
| `choice_id` | VARCHAR(36) | NOT NULL, FK → option_choices(id) ON DELETE CASCADE | |
| `price_diff` | INT | DEFAULT NULL | 上書き価格差（NULL=継承）|
| `is_available` | TINYINT(1) | DEFAULT 1 | 0=この店舗で非表示 |

**INDEX:** `(store_id, choice_id)` UNIQUE

---

#### 4.3.18 `local_item_options` — ローカルメニュー ↔ オプション紐付け

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `local_item_id` | VARCHAR(36) | PK(複合), FK → store_local_items(id) ON DELETE CASCADE | |
| `group_id` | VARCHAR(36) | PK(複合), FK → option_groups(id) ON DELETE CASCADE | |
| `is_required` | TINYINT(1) | DEFAULT 0 | 必須フラグ |
| `sort_order` | INT | DEFAULT 0 | 表示順 |

---

#### 4.3.19 `kds_stations` — KDSステーション

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) ON DELETE CASCADE | |
| `name` | VARCHAR(100) | NOT NULL | ステーション名（例: "キッチン", "ドリンク"）|
| `name_en` | VARCHAR(100) | DEFAULT NULL | 英語名 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |

---

#### 4.3.20 `kds_routing_rules` — KDSルーティング

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `station_id` | VARCHAR(36) | PK(複合), FK → kds_stations(id) ON DELETE CASCADE | |
| `category_id` | VARCHAR(36) | PK(複合), FK → categories(id) ON DELETE CASCADE | |

**意味:** このステーションには、このカテゴリの注文品目が表示される

---

#### 4.3.21 `table_sessions` — テーブルセッション

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) ON DELETE CASCADE | |
| `table_id` | VARCHAR(36) | NOT NULL, FK → tables(id) ON DELETE CASCADE | |
| `status` | ENUM('seated','eating','bill_requested','paid','closed') | DEFAULT 'seated' | |
| `guest_count` | INT | DEFAULT NULL | 人数 |
| `started_at` | DATETIME | | セッション開始 |
| `closed_at` | DATETIME | DEFAULT NULL | セッション終了 |
| `plan_id` | VARCHAR(36) | DEFAULT NULL | 食べ放題プランID |
| `time_limit_min` | INT | DEFAULT NULL | 制限時間（分）|
| `last_order_min` | INT | DEFAULT NULL | ラストオーダー（終了N分前）|
| `expires_at` | DATETIME | DEFAULT NULL | 有効期限 |
| `last_order_at` | DATETIME | DEFAULT NULL | ラストオーダー締切 |
| `course_id` | VARCHAR(36) | DEFAULT NULL | コースID |
| `current_phase_number` | INT | DEFAULT NULL | 現在フェーズ番号 |
| `phase_fired_at` | DATETIME | DEFAULT NULL | 現フェーズ発火時刻 |
| `memo` | TEXT | DEFAULT NULL | セッションメモ（アレルギー・VIP・特記事項等）※ migration-i4 |

**ステータス遷移:** `seated` → `eating` → `bill_requested` → `paid` / `closed`

---

#### 4.3.22 `time_limit_plans` — 食べ放題プラン

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) ON DELETE CASCADE | |
| `name` | VARCHAR(100) | NOT NULL | プラン名（例: "90分食べ放題"）|
| `name_en` | VARCHAR(100) | DEFAULT '' | 英語名 |
| `duration_min` | INT | NOT NULL | 制限時間（分）|
| `last_order_min` | INT | DEFAULT 15 | ラストオーダー（終了N分前）|
| `price` | INT | DEFAULT 0 | プラン料金（税込）|
| `description` | TEXT | DEFAULT NULL | 説明 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |

---

#### 4.3.23 `course_templates` — コーステンプレート

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) ON DELETE CASCADE | |
| `name` | VARCHAR(100) | NOT NULL | コース名（例: "松コース"）|
| `name_en` | VARCHAR(100) | DEFAULT '' | 英語名 |
| `price` | INT | DEFAULT 0 | コース料金（税込）|
| `description` | TEXT | DEFAULT NULL | 説明 |
| `phase_count` | INT | DEFAULT 1 | フェーズ数 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |

---

#### 4.3.24 `course_phases` — コースフェーズ

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `course_id` | VARCHAR(36) | NOT NULL, FK → course_templates(id) ON DELETE CASCADE | |
| `phase_number` | INT | NOT NULL | フェーズ番号（1, 2, 3...）|
| `name` | VARCHAR(100) | NOT NULL | フェーズ名（例: "前菜", "メイン", "デザート"）|
| `name_en` | VARCHAR(100) | DEFAULT '' | 英語名 |
| `items` | JSON | NOT NULL | 品目リスト `[{id, name, name_en?, qty}]` |
| `auto_fire_min` | INT | DEFAULT NULL | 自動発火（前フェーズ完了後N分）。NULLは手動 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |

**INDEX:** `(course_id, phase_number)` UNIQUE

---

#### 4.3.25 `plan_menu_items` — プラン専用メニュー

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `plan_id` | VARCHAR(36) | NOT NULL, FK → time_limit_plans(id) ON DELETE CASCADE | |
| `category_id` | VARCHAR(36) | DEFAULT NULL | カテゴリ（参照のみ、FK制約なし）|
| `name` | VARCHAR(100) | NOT NULL | 品名 |
| `name_en` | VARCHAR(100) | DEFAULT '' | 英語名 |
| `description` | TEXT | DEFAULT NULL | 説明 |
| `image_url` | VARCHAR(255) | DEFAULT NULL | 画像 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |

---

#### 4.3.26 `payments` — 決済記録

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL, FK → stores(id) | |
| `table_id` | VARCHAR(36) | DEFAULT NULL | テーブル（テイクアウトはNULL、合流時は代表テーブル）|
| `merged_table_ids` | JSON | DEFAULT NULL | 合流会計時の全テーブルIDリスト ※ migration-i6 |
| `merged_session_ids` | JSON | DEFAULT NULL | 合流会計時の全セッションIDリスト ※ migration-i6 |
| `session_id` | VARCHAR(36) | DEFAULT NULL | テーブルセッションID |
| `order_ids` | JSON | NOT NULL | 対象注文ID配列 |
| `paid_items` | JSON | DEFAULT NULL | 個別会計品目詳細 |
| `subtotal_10` | INT | DEFAULT 0 | 10%税率小計（税抜）|
| `tax_10` | INT | DEFAULT 0 | 10%税額 |
| `subtotal_8` | INT | DEFAULT 0 | 8%税率小計（税抜）|
| `tax_8` | INT | DEFAULT 0 | 8%税額 |
| `total_amount` | INT | NOT NULL | 総額（税込）|
| `payment_method` | ENUM('cash','card','qr') | DEFAULT 'cash' | 決済方法 |
| `received_amount` | INT | DEFAULT NULL | 預かり金 |
| `change_amount` | INT | DEFAULT NULL | お釣り |
| `is_partial` | TINYINT(1) | DEFAULT 0 | 個別/割り勘決済フラグ |
| `user_id` | VARCHAR(36) | DEFAULT NULL | 操作スタッフ |
| `note` | VARCHAR(200) | DEFAULT NULL | メモ |
| `paid_at` | DATETIME | | 決済時刻 |

---

#### 4.3.27 `ingredients` — 食材マスタ

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL, FK → tenants(id) | テナントスコープ |
| `name` | VARCHAR(100) | NOT NULL | 食材名 |
| `unit` | VARCHAR(20) | DEFAULT '個' | 単位（g, ml, 個, 枚等）|
| `stock_quantity` | DECIMAL(10,2) | DEFAULT 0 | 現在在庫数（マイナス可）|
| `cost_price` | DECIMAL(10,2) | DEFAULT 0 | 単位原価 |
| `low_stock_threshold` | DECIMAL(10,2) | DEFAULT NULL | 在庫アラート閾値 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |
| `is_active` | TINYINT(1) | DEFAULT 1 | 有効フラグ |

---

#### 4.3.28 `recipes` — レシピ（BOM）

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `menu_template_id` | VARCHAR(36) | NOT NULL, FK → menu_templates(id) ON DELETE CASCADE | |
| `ingredient_id` | VARCHAR(36) | NOT NULL, FK → ingredients(id) ON DELETE CASCADE | |
| `quantity` | DECIMAL(10,2) | DEFAULT 1 | 1食あたり消費量 |

**INDEX:** `(menu_template_id, ingredient_id)` UNIQUE

---

#### 4.3.29 `cart_events` — カート操作ログ

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | BIGINT | PK, AUTO_INCREMENT | |
| `store_id` | VARCHAR(36) | NOT NULL | |
| `table_id` | VARCHAR(36) | DEFAULT NULL | |
| `session_token` | VARCHAR(64) | DEFAULT NULL | |
| `item_id` | VARCHAR(36) | NOT NULL | メニュー品目ID |
| `item_name` | VARCHAR(100) | NOT NULL | 品名（非正規化）|
| `item_price` | INT | DEFAULT 0 | 価格（非正規化）|
| `action` | ENUM('add','remove') | NOT NULL | カート操作 |
| `created_at` | DATETIME | | |

**注:** FK制約なし（高スループットログ用に軽量設計）

#### 4.3.30 `call_alerts` — スタッフ呼び出しアラート

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL | FK → stores |
| `table_id` | VARCHAR(36) | NOT NULL | FK → tables |
| `table_code` | VARCHAR(20) | NOT NULL | テーブル番号（非正規化、表示用）|
| `reason` | VARCHAR(100) | NOT NULL | 呼び出し理由 |
| `type` | ENUM('staff_call','product_ready','kitchen_call') | DEFAULT 'staff_call' | アラート種別 ※ migration-i5, i7 |
| `order_item_id` | VARCHAR(36) | DEFAULT NULL | 関連品目ID（product_ready時）※ migration-i5 |
| `item_name` | VARCHAR(100) | DEFAULT NULL | 品目名（表示用）※ migration-i5 |
| `status` | ENUM('pending','acknowledged') | DEFAULT 'pending' | 状態 |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |
| `acknowledged_at` | DATETIME | DEFAULT NULL | 対応日時 |

**インデックス:** `(store_id, status)` — 未対応アラートの効率的取得用
**設計:** staff_call: 同一テーブルから再呼び出し時は古い pending を DELETE して新規 INSERT。product_ready: KDSでready時に品目ごとに自動作成、「配膳完了」で対応済み+order_itemをservedに連動更新。kitchen_call: KDS音声コマンドから作成（table_id='KITCHEN', table_code='キッチン'）、既存pending削除後に新規INSERT（連打防止）

#### 4.3.31 `daily_recommendations` — 今日のおすすめ

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL | FK → stores |
| `menu_item_id` | VARCHAR(36) | NOT NULL | メニュー品目ID |
| `source` | ENUM('template','local') | DEFAULT 'template' | 品目ソース |
| `badge_type` | ENUM('recommend','popular','new','limited','today_only') | DEFAULT 'recommend' | バッジ種別 |
| `comment` | VARCHAR(200) | DEFAULT NULL | おすすめコメント |
| `display_date` | DATE | NOT NULL | 表示日 |
| `sort_order` | INT | DEFAULT 0 | 表示順 |
| `created_by` | VARCHAR(36) | DEFAULT NULL | 設定者ユーザーID |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |

**インデックス:** `(store_id, display_date)`, `(menu_item_id)`

#### 4.3.32 `satisfaction_ratings` — 満足度評価 ※ migration-i3

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | NOT NULL | FK → stores |
| `order_id` | VARCHAR(36) | NOT NULL | FK → orders |
| `order_item_id` | VARCHAR(36) | DEFAULT NULL | FK → order_items |
| `menu_item_id` | VARCHAR(36) | DEFAULT NULL | メニュー品目ID |
| `item_name` | VARCHAR(200) | DEFAULT NULL | 品目名（非正規化）|
| `rating` | TINYINT | NOT NULL | 1-5（1=最低、5=最高）|
| `session_token` | VARCHAR(64) | DEFAULT NULL | テーブルトークン |
| `table_id` | VARCHAR(36) | DEFAULT NULL | FK → tables |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |

**インデックス:** `(store_id, created_at)`, `(order_id)`, `(menu_item_id, store_id)`

---

#### 4.3.33 `audit_log` — セキュリティ監査ログ ※ migration-g1

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | BIGINT UNSIGNED | PK AUTO_INCREMENT | 連番ID |
| `tenant_id` | VARCHAR(36) | NOT NULL | FK → tenants |
| `store_id` | VARCHAR(36) | DEFAULT NULL | FK → stores（テナントレベル操作はNULL） |
| `user_id` | VARCHAR(36) | NOT NULL | 操作ユーザー |
| `username` | VARCHAR(50) | DEFAULT NULL | ユーザー名スナップショット |
| `role` | ENUM | DEFAULT NULL | owner/manager/staff |
| `action` | VARCHAR(50) | NOT NULL | 操作種別（menu_update, staff_create, order_cancel 等） |
| `entity_type` | VARCHAR(50) | NOT NULL | 対象種別（menu_item, user, order, settings 等） |
| `entity_id` | VARCHAR(36) | DEFAULT NULL | 対象レコードID |
| `old_value` | JSON | DEFAULT NULL | 変更前の値 |
| `new_value` | JSON | DEFAULT NULL | 変更後の値 |
| `reason` | TEXT | DEFAULT NULL | 操作理由 |
| `ip_address` | VARCHAR(45) | DEFAULT NULL | |
| `user_agent` | TEXT | DEFAULT NULL | |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |

**インデックス:** `(tenant_id, store_id)`, `(user_id)`, `(action)`, `(entity_type, entity_id)`, `(created_at)`

#### 4.3.34 `user_sessions` — アクティブセッション管理 ※ migration-g2

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | BIGINT UNSIGNED | PK AUTO_INCREMENT | 連番ID |
| `user_id` | VARCHAR(36) | NOT NULL | FK → users |
| `tenant_id` | VARCHAR(36) | NOT NULL | FK → tenants |
| `session_id` | VARCHAR(128) | NOT NULL, UNIQUE | PHPセッションID |
| `ip_address` | VARCHAR(45) | DEFAULT NULL | |
| `user_agent` | TEXT | DEFAULT NULL | |
| `device_label` | VARCHAR(100) | DEFAULT NULL | 簡易デバイス名（Chrome/Windows等） |
| `login_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |
| `last_active_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |
| `is_active` | TINYINT(1) | DEFAULT 1 | |

**インデックス:** `(user_id)`, `(session_id)`, `(user_id, is_active)`

#### 4.3.35 `smaregi_store_mapping` — スマレジ店舗マッピング ※ migration-l15

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL | FK → tenants |
| `store_id` | VARCHAR(36) | NOT NULL, UNIQUE | FK → stores（POSLA店舗ID） |
| `smaregi_store_id` | VARCHAR(20) | NOT NULL | スマレジ店舗ID |
| `sync_enabled` | TINYINT(1) | DEFAULT 1 | 注文送信有効フラグ |
| `last_menu_sync` | DATETIME | DEFAULT NULL | 最終メニュー同期日時 |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP | |

**インデックス:** `UNIQUE (store_id)`, `UNIQUE (tenant_id, smaregi_store_id)`, `(tenant_id)`

#### 4.3.36 `smaregi_product_mapping` — スマレジ商品マッピング ※ migration-l15

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL | FK → tenants |
| `menu_template_id` | VARCHAR(36) | NOT NULL, UNIQUE | FK → menu_templates（POSLAメニューID） |
| `smaregi_product_id` | VARCHAR(20) | NOT NULL | スマレジ商品ID |
| `smaregi_store_id` | VARCHAR(20) | NOT NULL | スマレジ店舗ID |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |

**インデックス:** `UNIQUE (menu_template_id)`, `UNIQUE (tenant_id, smaregi_product_id, smaregi_store_id)`, `(tenant_id)`

**tenants テーブル追加カラム（migration-l15）:**

| カラム | 型 | 説明 |
|--------|-----|------|
| `smaregi_contract_id` | VARCHAR(50) | スマレジ契約ID（サンドボックス: sb_xxxxx） |
| `smaregi_access_token` | TEXT | アクセストークン |
| `smaregi_refresh_token` | TEXT | リフレッシュトークン（offline_accessスコープで取得） |
| `smaregi_token_expires_at` | DATETIME | アクセストークン有効期限 |
| `smaregi_connected_at` | DATETIME | スマレジ連携日時 |

**orders テーブル追加カラム（migration-l15）:**

| カラム | 型 | 説明 |
|--------|-----|------|
| `smaregi_transaction_id` | VARCHAR(50) | スマレジ仮販売の取引ID（成功時に保存） |

#### 4.3.37 `menu_translations` — メニュー翻訳キャッシュ ※ migration-l4

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL, FK → tenants(id) ON DELETE CASCADE | テナントスコープ |
| `entity_type` | ENUM('menu_item','local_item','category','option_group','option_choice') | NOT NULL | 翻訳対象の種類 |
| `entity_id` | VARCHAR(36) | NOT NULL | 翻訳対象のID |
| `lang` | VARCHAR(10) | NOT NULL | 言語コード（en, zh-Hans, zh-Hant, ko） |
| `name` | VARCHAR(200) | NULL | 翻訳後の名前 |
| `description` | TEXT | NULL | 翻訳後の説明文 |
| `translated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 翻訳実行日時 |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 作成日時 |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE | 更新日時 |

**INDEX:** `UNIQUE uk_entity_lang(entity_type, entity_id, lang)`, `idx_tenant(tenant_id)`, `idx_entity(entity_type, entity_id)`

Gemini 2.0 Flash API で一括翻訳した結果をキャッシュ保存。既存の `name_en` カラムは維持し、追加言語（zh-Hans, zh-Hant, ko）はこのテーブルで管理。`menu-resolver.php` の `attach_translations()` がメニュー解決時に一括取得して各エンティティに紐付ける。

#### 4.3.38 `ui_translations` — UI文言翻訳 ※ migration-l4

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | INT | PK, AUTO_INCREMENT | |
| `lang` | VARCHAR(10) | NOT NULL | 言語コード（ja, en, zh-Hans, zh-Hant, ko） |
| `msg_key` | VARCHAR(100) | NOT NULL | メッセージキー（例: btn_add, cart_title） |
| `msg_value` | TEXT | NOT NULL | 翻訳テキスト |

**INDEX:** `UNIQUE uk_lang_key(lang, msg_key)`

セルフオーダー画面のボタン・ラベル等のUI文言。5言語 × 約22キー = 約110行。`ui-translations.php` API で言語指定取得。フロントの `_t(key, fallbackJa)` 関数で参照。

#### 4.3.39 `plan_features` — プラン別機能フラグ ※ migration-l16

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `plan` | ENUM('standard','pro','enterprise') | PK（複合） | プラン名（※liteは廃止済み: migration-plan-remove-lite.sql） |
| `feature_key` | VARCHAR(50) | PK（複合） | 機能キー |
| `enabled` | TINYINT(1) | NOT NULL, DEFAULT 0 | 有効フラグ |

18個の機能キー: `self_order`, `handy_pos`, `floor_map`, `table_merge_split`, `inventory`, `ai_waiter`, `ai_voice`, `ai_forecast`, `takeout`, `payment_gateway`, `advanced_reports`, `basket_analysis`, `audit_log`, `multi_session_control`, `offline_detection`, `satisfaction_rating`, `multilingual`, `hq_menu_broadcast`。`auth.php` の `check_plan_feature()` / `get_plan_features()` で参照。`me.php` がログイン時にフロントへ返却。

#### 4.3.40 `posla_settings` — POSLA共通設定 ※ migration-l10b

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `setting_key` | VARCHAR(50) | PK | 設定キー |
| `setting_value` | TEXT | NULL | 設定値 |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE | 更新日時 |

POSLA運営が一元管理する共通設定。初期キー: `gemini_api_key`, `google_places_api_key`。L-11で追加（α-1 移行後の現行構成）: `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `stripe_price_base`（1店舗目の基本料金）, `stripe_price_additional_store`（2店舗目以降・15%引）, `stripe_price_hq_broadcast`（本部一括メニュー配信アドオン・任意）。L-13で追加: `stripe_connect_*` 関連。L-15で追加: `smaregi_client_id`, `smaregi_client_secret`。POSLA管理画面（`/public/posla-admin/`）から設定。

#### 4.3.41 `posla_admins` — POSLA管理者 ※ migration-l10

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `email` | VARCHAR(255) | NOT NULL, UNIQUE | メールアドレス |
| `password_hash` | VARCHAR(255) | NOT NULL | bcryptハッシュ |
| `display_name` | VARCHAR(100) | NOT NULL, DEFAULT '' | 表示名 |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | 有効フラグ |
| `last_login_at` | DATETIME | NULL | 最終ログイン |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 作成日時 |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE | 更新日時 |

POSLA運営専用の管理者アカウント。テナント管理者とは完全分離。認証は `api/posla/login.php`（ログイン）/ `api/posla/logout.php`（ログアウト）/ `api/posla/me.php`（現在のユーザー）/ `api/posla/auth-helper.php`（`require_posla_admin()` ヘルパー）の4ファイルで構成。

#### 4.3.41b `posla_admin_sessions` — POSLA管理者セッション ※ migration-p1-6d

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| `admin_id` | VARCHAR(36) | NOT NULL | `posla_admins.id` 参照（FK 制約はなし、ロール分離のため明示的な FK は付けない） |
| `session_id` | VARCHAR(128) | NOT NULL, UNIQUE | PHP `session_id()` の値 |
| `ip_address` | VARCHAR(45) | DEFAULT NULL | IPv4/IPv6 両対応 |
| `user_agent` | TEXT | DEFAULT NULL | リクエストヘッダ |
| `login_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |
| `last_active_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |
| `is_active` | TINYINT(1) | DEFAULT 1 | （現状 INSERT/DELETE 運用のため 0 化はしない） |

**INDEX:** `uk_posla_session` UNIQUE(session_id), `idx_admin` (admin_id), `idx_admin_active` (admin_id, is_active)

P1-6d 新設。`api/posla/login.php` でログイン成功時に INSERT、`api/posla/change-password.php` でパスワード変更成功時に「現セッション以外」のレコードを DELETE。失敗時もログイン/パスワード変更フロー自体は止めない（try-catch で error_log）。

#### 4.3.42 `shift_settings` — シフト設定（1:1 店舗） ※ migration-l3

| カラム | 型 | デフォルト | 説明 |
|--------|-----|-----------|------|
| `store_id` | VARCHAR(36) | PK, FK → stores(id) ON DELETE CASCADE | |
| `submission_deadline_day` | TINYINT | 3（水曜） | 希望シフト提出締切曜日（0=日〜6=土） |
| `default_break_minutes` | INT | 60 | デフォルト休憩時間（分） |
| `overtime_threshold_hours` | DECIMAL(4,1) | 8.0 | 残業判定閾値（時間） |
| `auto_clock_out_hours` | DECIMAL(4,1) | 12.0 | 自動退勤閾値（時間）。cron実行時に超過レコードを自動退勤 |
| `store_lat` | DECIMAL(10,7) | NULL | L-3b: 店舗緯度（GPS出退勤用） |
| `store_lng` | DECIMAL(10,7) | NULL | L-3b: 店舗経度 |
| `gps_radius_meters` | INT | 200 | L-3b: GPS許容範囲（メートル） |
| `gps_required` | TINYINT(1) | 0 | L-3b: GPS出退勤を強制するか |
| `staff_visible_tools` | VARCHAR(100) | NULL | L-3b: 店舗デフォルトの表示ツール（CSV: handy,kds,register）。NULL=全表示 |
| `default_hourly_rate` | INT | NULL | L-3 Phase 2: 店舗デフォルト時給（円）。NULL=人件費計算スキップ |
| `created_at` | DATETIME | | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP | |

UPSERT方式（`api/store/shift/settings.php`）。未登録店舗は初回PATCH時にINSERT。

#### 4.3.43 `shift_templates` — シフトテンプレート ※ migration-l3

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | FK → stores(id) ON DELETE CASCADE | |
| `name` | VARCHAR(100) | NOT NULL | テンプレート名（例: 早番、遅番、通し） |
| `day_of_week` | TINYINT | NOT NULL | 曜日（0=日〜6=土） |
| `start_time` | TIME | NOT NULL | 開始時刻 |
| `end_time` | TIME | NOT NULL | 終了時刻 |
| `required_staff` | INT | DEFAULT 1 | 必要人数 |
| `role_hint` | VARCHAR(50) | NULL | 推奨ロール（例: kitchen, hall） |
| `created_at` | DATETIME | | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP | |

曜日ごとのシフトパターン定義。カレンダー画面の「テンプレート適用」で一括割当に使用。

#### 4.3.44 `shift_assignments` — シフト割当 ※ migration-l3

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | FK → stores(id) ON DELETE CASCADE | |
| `user_id` | VARCHAR(36) | FK → users(id) ON DELETE CASCADE | |
| `date` | DATE | NOT NULL | シフト日 |
| `start_time` | TIME | NOT NULL | 開始時刻 |
| `end_time` | TIME | NOT NULL | 終了時刻 |
| `break_minutes` | INT | DEFAULT 60 | 休憩時間（分） |
| `status` | ENUM | 'draft','published','confirmed' DEFAULT 'draft' | 状態 |
| `role_type` | VARCHAR(50) | NULL | ロール（kitchen, hall等） |
| `created_at` | DATETIME | | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP | |

確定したシフト割当。テンプレート適用またはカレンダーから手動追加。

#### 4.3.45 `shift_availabilities` — 希望シフト ※ migration-l3

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | FK → stores(id) ON DELETE CASCADE | |
| `user_id` | VARCHAR(36) | FK → users(id) ON DELETE CASCADE | |
| `date` | DATE | NOT NULL | 希望日 |
| `availability` | ENUM | 'available','unavailable','preferred' NOT NULL | 可否 |
| `preferred_start` | TIME | NULL | 希望開始時刻 |
| `preferred_end` | TIME | NULL | 希望終了時刻 |
| `note` | TEXT | NULL | メモ |
| `created_at` | DATETIME | | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP | |

スタッフが提出する希望シフト。AI提案の入力データとしても使用（Phase 2）。

#### 4.3.46 `attendance_logs` — 勤怠ログ ※ migration-l3

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `store_id` | VARCHAR(36) | FK → stores(id) ON DELETE CASCADE | |
| `user_id` | VARCHAR(36) | FK → users(id) ON DELETE CASCADE | |
| `clock_in` | DATETIME | NOT NULL | 出勤打刻 |
| `clock_out` | DATETIME | NULL | 退勤打刻（NULL=勤務中） |
| `break_start` | DATETIME | NULL | 休憩開始 |
| `break_end` | DATETIME | NULL | 休憩終了 |
| `clock_in_lat` | DECIMAL(10,7) | NULL | L-3b: 出勤時GPS緯度 |
| `clock_in_lng` | DECIMAL(10,7) | NULL | L-3b: 出勤時GPS経度 |
| `clock_out_lat` | DECIMAL(10,7) | NULL | L-3b: 退勤時GPS緯度 |
| `clock_out_lng` | DECIMAL(10,7) | NULL | L-3b: 退勤時GPS経度 |
| `status` | ENUM | 'working','on_break','completed','auto_clocked_out' DEFAULT 'working' | 状態 |
| `created_at` | DATETIME | | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP | |

出退勤の実績記録。`auto_clocked_out` はcronによる自動退勤。GPS座標はshift_settings.gps_required有効時に記録。

#### 4.3.47 `shift_help_requests` — 店舗間ヘルプ要請 ※ migration-l3p3

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `tenant_id` | VARCHAR(36) | NOT NULL, INDEX | |
| `from_store_id` | VARCHAR(36) | NOT NULL | 要請元（人手不足の店舗） |
| `to_store_id` | VARCHAR(36) | NOT NULL | 要請先（スタッフを派遣する店舗） |
| `requested_date` | DATE | NOT NULL | ヘルプ希望日 |
| `start_time` | TIME | NOT NULL | 開始時刻 |
| `end_time` | TIME | NOT NULL | 終了時刻 |
| `requested_staff_count` | INT | NOT NULL, DEFAULT 1 | 必要人数 |
| `role_hint` | VARCHAR(20) | NULL | kitchen / hall / NULL |
| `status` | ENUM | 'pending','approved','rejected','cancelled' DEFAULT 'pending' | |
| `requesting_user_id` | VARCHAR(36) | NOT NULL | 依頼者 |
| `responding_user_id` | VARCHAR(36) | NULL | 承認/却下者 |
| `note` | TEXT | NULL | 依頼メモ |
| `response_note` | TEXT | NULL | 回答メモ |
| `created_at` | DATETIME | | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP | |

enterprise限定。ワークフロー: pending→approved（スタッフ指名→shift_assignments自動作成）/rejected/cancelled。

#### 4.3.48 `shift_help_assignments` — ヘルプ派遣スタッフ ※ migration-l3p3

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| `id` | VARCHAR(36) | PK | UUID |
| `help_request_id` | VARCHAR(36) | NOT NULL, FK → shift_help_requests(id) CASCADE | |
| `user_id` | VARCHAR(36) | NOT NULL, FK → users(id) CASCADE | 派遣されるスタッフ |
| `shift_assignment_id` | VARCHAR(36) | NULL | 自動作成されたshift_assignmentsのID |
| `created_at` | DATETIME | | |

承認時に指名されたスタッフを記録する中間テーブル。shift_assignment_idで自動作成シフトと紐付け。

**shift_assignments追加カラム（L-3 Phase 3）:** `help_request_id` VARCHAR(36) NULL — ヘルプ要請経由のシフトを識別。NULLは通常シフト。

---

## 5. 認証・認可

### 5.1 認証方式

- **PHPセッション** ベース（Cookie認証）
- セッション有効期限: **8時間**
- Cookie属性: `httponly=true`, `SameSite=Lax`, `path=/`
- ログイン時に `session_regenerate_id(true)` でセッション固定攻撃防止
- パスワードハッシュ: PHP `password_hash(PASSWORD_DEFAULT)` + `password_verify()`

#### パスワードポリシー（P1-5）

`api/lib/password-policy.php` の `validate_password_strength(string $password)` で検証。違反時は `WEAK_PASSWORD` (400) を返却。

| ルール | 説明 |
|-------|------|
| 最低長 | 8文字以上 |
| 英字必須 | A-Z または a-z を1文字以上 |
| 数字必須 | 0-9 を1文字以上 |

**適用箇所:**
- `POST /api/owner/users.php`（オーナーがユーザー作成）
- `PATCH /api/owner/users.php`（オーナーがパスワード更新）
- `POST /api/store/staff-management.php`（マネージャーがスタッフ作成）
- `PATCH /api/store/staff-management.php`（マネージャーがスタッフ更新）
- `POST /api/auth/change-password.php`（テナントユーザーが自分で変更）
- `POST /api/posla/change-password.php`（POSLA管理者が自分で変更）

### 5.2 セッション構造

```php
$_SESSION = [
    'user_id'    => 'UUID',           // ユーザーID
    'tenant_id'  => 'UUID',           // テナントID
    'role'       => 'owner|manager|staff',  // ロール
    'email'      => 'user@example.com',     // メールアドレス
    'store_ids'  => ['UUID', ...],    // アクセス可能店舗ID（ownerは空配列）
    'login_time' => 1234567890,       // ログイン時刻（UNIX timestamp）
];
```

### 5.3 認可ロジック

```
ロール階層: owner(3) > manager(2) > staff(1)

require_auth()        → 認証済みであること（ロール不問）
require_role('staff')    → staff以上（全員OK）
require_role('manager')  → manager以上
require_role('owner')    → ownerのみ

require_store_access(store_id):
  - owner → テナント内の店舗か検証（全店舗暗黙アクセス）
  - manager/staff → user_stores に明示的な行が必要
```

### 5.4 認証不要エンドポイント

以下は顧客向けで認証なし:
- `api/customer/table-session.php` — QRスキャン後のテーブル確認
- `api/customer/menu.php` — メニュー取得
- `api/customer/orders.php` — 注文送信
- `api/customer/cart-event.php` — カート操作ログ
- `api/customer/call-staff.php` — スタッフ呼び出し
- `api/customer/validate-token.php` — セッショントークン検証（会計後の無効化検出用ポーリング）
- `api/customer/order-history.php` — 注文履歴（再注文セクション用）
- `api/store/menu-version.php` — メニューバージョンチェック（顧客メニュー・ハンディが使用）

---

## 6. API リファレンス

### 6.1 認証 API (`api/auth/`)

#### POST `/api/auth/login.php`
- **認証:** 不要
- **Body:** `{ "email": "string", "password": "string" }`
- **成功レスポンス:**
```json
{
  "ok": true,
  "data": {
    "user": { "id", "email", "displayName", "role", "tenantId", "tenantName" },
    "stores": [{ "id", "slug", "name", "nameEn" }]
  }
}
```
- **エラー:** `MISSING_FIELDS`(400), `INVALID_CREDENTIALS`(401), `ACCOUNT_DISABLED`(403), `TENANT_DISABLED`(403)
- **処理:** パスワード検証 → ユーザー/テナント有効性チェック → 同時セッション制限チェック（owner:5, manager:3, staff:2、超過時は最古セッション無効化）→ セッション生成 → `user_sessions` にレコード追加 → 店舗一覧取得 → `last_login_at` 更新

#### DELETE `/api/auth/logout.php`
- **認証:** 不要（セッション存在時に破棄）
- **レスポンス:** `{ "ok": true, "data": { "message": "ログアウトしました" } }`
- **処理:** セッション破棄 → `user_sessions` からレコード削除 → `audit_log` に記録

#### GET `/api/auth/me.php`
- **認証:** 必要
- **レスポンス:** `user` + `stores` + `plan` + `features`
  - `stores[].staffVisibleTools`: 店舗設定のスタッフ表示ツール（CSV or null）
  - `stores[].userVisibleTools`: ユーザー個別の表示ツール（CSV or null）（L-3b2、manager/staffのみ。ownerはnull）
- **用途:** ページロード時の認証チェック、店舗セレクター用データ、ツール表示制御

#### GET `/api/auth/check.php`
- **認証:** 必要
- **レスポンス:** `{ "ok": true, "data": { "valid": true } }`
- **用途:** `session-monitor.js` からのアイドルタイムアウト確認用エンドポイント。25分警告モーダルの「操作を続ける」ボタン押下時に呼び出され、サーバー側で `last_active_at` を更新してセッションを延長する。30分無操作到達後はサーバーが `SESSION_TIMEOUT` エラーを返却

#### POST `/api/auth/change-password.php`（P1-5）
- **認証:** 必要（テナントユーザー: owner / manager / staff）
- **Body:**
```json
{
  "current_password": "現在のパスワード",
  "new_password": "新しいパスワード"
}
```
- **処理:**
  1. `password_verify()` で現在のパスワードを検証
  2. 新パスワードと現パスワードが同一でないか確認
  3. `validate_password_strength()` でパスワードポリシー検証（8文字以上、英字＋数字必須）
  4. `users.password_hash` を `password_hash(PASSWORD_DEFAULT)` で更新
  5. **他セッション無効化:** `UPDATE user_sessions SET is_active = 0 WHERE user_id=? AND tenant_id=? AND session_id <> ?`（自セッションは保持）。`user_sessions` テーブル未作成時は try-catch でグレースフルデグレード
  6. `audit_log` に `password_changed` を記録
- **レスポンス:** `{ "ok": true, "data": { "message": "パスワードを変更しました" } }`
- **エラー:** `MISSING_FIELDS`(400), `INVALID_CURRENT_PASSWORD`(401), `SAME_PASSWORD`(400), `WEAK_PASSWORD`(400)
- **実装メモ:** プロンプト仕様では「他セッション削除（DELETE）」だったが、実装では `is_active = 0` への論理無効化に変更（既存のセッション管理パターンに合わせるため）

---

### 6.2 顧客 API (`api/customer/`) — 全て認証不要

#### GET `/api/customer/table-session.php`
- **パラメータ:** `?store_id=xxx&table_id=xxx`
- **レスポンス:**
```json
{
  "table": { "id", "tableCode", "capacity" },
  "store": { "name", "nameEn" },
  "sessionToken": "hex_string",
  "plan": { "id", "name", "timeLimitMin", "expiresAt", "lastOrderAt" } | null,
  "course": { "id", "name", "price", "currentPhase", "phases": [...] } | null
}
```
- **処理:** テーブル有効性確認 → アクティブセッションがなければ自動作成（`status='seated'`）→ `session_token` 取得/生成 → プラン/コースセッション検索
- **自動着席:** `table_sessions` に `status NOT IN ('paid','closed')` のレコードがなければ、新しいセッションを自動作成しトークンも新規発行。スタッフの「着席」ボタン操作が不要になる

#### GET `/api/customer/menu.php`
- **パラメータ:** `?store_id=xxx` + オプションで `&plan_id=xxx` or `&course_id=xxx`
- **3モード:**
  1. **通常モード:** `menu-resolver.php` で統合メニュー返却
  2. **プランモード:** `plan_menu_items` からプラン専用メニュー（全品¥0）
  3. **コースモード:** `course_phases` からフェーズ別品目表示
- **レスポンス:**
```json
{
  "store": { "id", "name", "nameEn" },
  "settings": { "maxItems", "maxAmount", "taxRate" },
  "categories": [
    {
      "categoryId", "categoryName", "categoryNameEn",
      "items": [
        {
          "menuItemId", "source": "template|local",
          "categoryId", "name", "nameEn", "price",
          "description", "imageUrl", "soldOut",
          "calories": 350 | null,
          "allergens": ["egg","milk"] | null,
          "optionGroups": [
            {
              "groupId", "groupName", "selectionType",
              "minSelect", "maxSelect", "isRequired",
              "choices": [{ "choiceId", "name", "priceDiff", "isDefault" }]
            }
          ]
        }
      ]
    }
  ],
  "recommendations": [
    { "menu_item_id", "source", "badge_type", "comment", "sort_order" }
  ],
  "popularity": { "menu_item_id": rank_number }
}
```
- **recommendations:** 当日の `daily_recommendations` テーブルから取得。テーブル未作成時は空配列
- **popularity:** 過去30日の `order_items` 注文数 TOP10 のランキング。`{ "item-uuid": 1, ... }`

#### GET `/api/customer/validate-token.php`
- **パラメータ:** `?store_id=xxx&table_id=xxx&token=xxx`
- **レスポンス:** `{ "valid": true|false }`
- **用途:** メニュー画面が30秒間隔でポーリング。会計後にトークンが変更された場合 `false` を返し、メニュー画面に「セッション終了」オーバーレイを表示

#### POST `/api/customer/orders.php`
- **Body:**
```json
{
  "store_id": "uuid",
  "table_id": "uuid",
  "items": [{ "id", "name", "price", "qty", "options"?, "allergen_selections"?: ["egg","milk"] }],
  "removed_items"?: [...],
  "idempotency_key": "unique_string",
  "session_token": "hex_string",
  "memo"?: "ネギ抜きで"
}
```
- **バリデーション:**
  - **ラストオーダーチェック:** プラン別LO（`table_sessions.last_order_at`）+ 店舗全体LO（`store_settings.last_order_active` / `last_order_time`）を確認
  - 品数チェック: `totalQty <= max_items_per_order`
  - 金額チェック: `totalAmount <= max_amount_per_order`（プランセッション中はスキップ）
  - **品切れチェック:** `menu-resolver` でメニュー全体を取得し、注文品目が品切れの場合 HTTP 409 エラー（`SOLD_OUT`）を返す。品名を含むメッセージで顧客にカートからの削除を促す
- **冪等性:** `idempotency_key` が既存注文と一致する場合、重複注文を防止し `duplicate: true` を返す
- **レスポンス:** `{ "ok": true, "order_id": "uuid" }` (201)
- **エラー:** `SOLD_OUT`(409) — 品切れ品目がカートに含まれている場合、`LAST_ORDER_PASSED`(409) — ラストオーダー終了後の注文

#### POST `/api/customer/cart-event.php`
- **Body:** `{ "store_id", "table_id"?, "session_token"?, "item_id", "item_name", "item_price"?, "action": "add|remove" }`
- **用途:** ちゅうちょ分析データ収集

#### POST `/api/customer/ai-waiter.php`
- **Body:**
```json
{
  "store_id": "uuid",
  "message": "おすすめは何ですか？",
  "history": [{ "role": "user|model", "content": "..." }]
}
```
- **処理:**
  1. `posla_settings.gemini_api_key` から POSLA共通APIキーを取得（L-10b。サーバーサイド、フロントに非露出）
  2. `menu-resolver` でメニュー全体を取得しシステムプロンプトに埋め込み
  3. 会話履歴（最大20メッセージ）+ 今回のメッセージで Gemini API 呼び出し
  4. AIの返答テキスト中のメニュー名を検出し `suggested_items` として返却（品切れ品除外、最大3品）
- **レスポンス:**
```json
{
  "message": "焼き魚定食がおすすめです🐟...",
  "suggested_items": [
    { "id": "menu-item-uuid", "name": "焼き魚定食", "price": 980 }
  ]
}
```
- **エラー:** `AI_NOT_CONFIGURED`(503) — `posla_settings.gemini_api_key` 未設定、`GEMINI_ERROR`(502) — Gemini API通信エラー
- **前置き除去:** `_cleanGeminiResponse()` で「はい」「承知」「了解」等の前置き行を自動除去

#### POST `/api/customer/call-staff.php`
- **認証:** 不要（顧客操作）
- **Body:**
```json
{
  "store_id": "uuid",
  "table_id": "uuid",
  "reason": "お会計をお願いします"
}
```
- **処理:**
  1. `store_id` + `table_id` で `tables` テーブルから `table_code` を取得（信頼性のためDB参照）
  2. 同一テーブルの既存 pending アラートを DELETE（重複防止）
  3. 新規アラートを INSERT（UUID生成）
- **レスポンス:** `{ "alert_id": "uuid" }`
- **エラー:** `MISSING_FIELDS`(400), `TABLE_NOT_FOUND`(404)
- **グレースフルデグレード:** `call_alerts` テーブル未作成時は `TABLE_NOT_AVAILABLE`(503) を返却

#### GET `/api/customer/order-history.php`
- **認証:** 不要（セッショントークン検証）
- **パラメータ:** `?store_id=xxx&table_id=xxx&session_token=xxx`
- **処理:** 同一テーブルセッション内の過去注文（cancelled 除外）を返す。session_token が tables テーブルの値と一致し、有効期限内の場合のみ応答
- **レスポンス:**
```json
{ "orders": [{ "id": "uuid", "items": [...], "total_amount": 1500, "created_at": "...", "status": "served" }] }
```
- **エラー:** `MISSING_FIELDS`(400), `INVALID_SESSION`(403)

#### POST `/api/customer/satisfaction-rating.php`
- **認証:** 不要（セッショントークン検証）
- **Body:** `{ store_id, table_id, session_token, rating: 1-5, comment?: string }`
- **処理:** 5段階満足度評価を `satisfaction_ratings` テーブルに INSERT。セッション終了前の会計モーダルから送信。同一セッションで重複送信された場合は最新を優先（INSERT → 集計側 `MAX(created_at)` or 最新1件で扱う）
- **レスポンス:** `{ "ok": true }`
- **エラー:** `MISSING_FIELDS`(400), `INVALID_SESSION`(403), `INVALID_RATING`(400)
- **プラン制限:** `plan_features.satisfaction_rating` が有効なテナントのみ表示（フロントエンド側でもフィルタ）。集計は owner/manager 側 `staff-report.php` から参照

#### GET `/api/customer/ui-translations.php`
- **認証:** 不要
- **パラメータ:** `?lang=ja|en|zh-Hans|zh-Hant|ko`（省略時 `ja`）
- **処理:** `ui_translations` テーブルから指定言語のUI文言を取得し、key-value マップで返す（L-4）
- **レスポンス:**
```json
{ "lang": "ja", "translations": { "btn_add": "追加", "cart_title": "カート", ... } }
```
- **グレースフルデグレード:** `ui_translations` テーブル未作成時は空オブジェクトを返却

#### GET/POST `/api/customer/takeout-orders.php`
- **認証:** 不要（L-1 テイクアウト）
- **GETアクション:**
  - `?action=settings&store_id=xxx` — テイクアウト設定（有効フラグ・準備時間・受付時間帯・スロット容量・決済可否・店舗情報）
  - `?action=slots&store_id=xxx&date=YYYY-MM-DD` — 指定日の利用可能タイムスロット一覧（15分刻み）
  - `?action=status&order_id=xxx&phone=xxx` — 注文ステータス確認（電話番号照合）
- **POST:** テイクアウト注文作成
  - **Body:** `store_id`, `items[]`（qty/price）, `customer_name`, `customer_phone`（10-11桁）, `pickup_at`, `memo?`, `payment_method?`（cash|online）, `idempotency_key?`
  - **レスポンス:** `{ "order_id": "uuid", "status": "pending|pending_payment", "checkout_url": "..." }`
  - オンライン決済時は Stripe のチェックアウトURLを返す
- **レート制限:** 10 req/h per key

#### GET `/api/customer/takeout-payment.php`
- **認証:** 不要（L-1 テイクアウト）
- **パラメータ:** `?order_id=xxx&session_id=xxx`（Stripe Checkout Session）
- **処理:** オンライン決済の確認。Stripe API に問い合わせ、支払い完了時に `takeout_orders.status` を `pending_payment` → `pending` に更新し、`payments` テーブルに記録
- **レスポンス:**
```json
{ "order_id": "uuid", "payment_confirmed": true, "status": "pending" }
```

#### GET `/api/customer/get-bill.php`（L-8）
- **認証:** 不要（session_token検証）
- **パラメータ:** `?store_id=xxx&table_id=xxx&session_token=xxx`
- **処理:** 未払い注文一覧（status NOT IN paid/cancelled）を取得し、税率別内訳（10%/8%）を算出。self_checkout_enabled + payment_gateway からセルフレジ利用可否を判定
- **レスポンス:**
```json
{
  "orders": [{ "id": "uuid", "items": [...], "total_amount": 1500 }],
  "summary": { "subtotal_10": 1000, "tax_10": 100, "subtotal_8": 500, "tax_8": 40, "total": 1640 },
  "self_checkout_enabled": true,
  "payment_available": true
}
```
- **エラー:** `MISSING_FIELDS`(400), `INVALID_SESSION`(403), `NO_UNPAID_ORDERS`(404)
- **グレースフルデグレード:** self_checkout_enabledカラム未存在時は0扱い

#### POST `/api/customer/checkout-session.php`（L-8）
- **認証:** 不要（session_token検証）
- **Body:** `{ "store_id": "uuid", "table_id": "uuid", "session_token": "xxx" }`
- **処理:** 未払い注文の合計金額でStripe Checkout Sessionを作成。Stripe Connect（L-13）を優先し、未設定時は直接Stripeにfallback
- **レスポンス:** `{ "checkout_url": "https://checkout.stripe.com/...", "session_id": "cs_xxx", "total": 1640 }`
- **エラー:** `SELF_CHECKOUT_DISABLED`(403), `NO_UNPAID_ORDERS`(404), `PAYMENT_NOT_CONFIGURED`(503), `CHECKOUT_FAILED`(502)

#### POST `/api/customer/checkout-confirm.php`（L-8）
- **認証:** 不要（session_token + stripe_session_id検証）
- **Body:** `{ "store_id": "uuid", "table_id": "uuid", "session_token": "xxx", "stripe_session_id": "cs_xxx" }`
- **処理:** Stripe決済を検証（Connect/直接自動分岐）→ payments INSERT → 注文status='paid' → session_token再生成 → table_sessions.status='paid' → 在庫減算。トランザクションで一括処理。external_payment_idで冪等性保証
- **レスポンス:** `{ "payment_id": "uuid", "total": 1640, "subtotal_10": 1000, "tax_10": 100, "subtotal_8": 500, "tax_8": 40 }`
- **エラー:** `PAYMENT_NOT_CONFIRMED`(402), `NO_UNPAID_ORDERS`(404), `PAYMENT_GATEWAY_ERROR`(502)

#### GET `/api/customer/receipt-view.php`（L-8）
- **認証:** 不要（payment_id + store_id + 30分時間制限）
- **パラメータ:** `?payment_id=xxx&store_id=xxx`
- **処理:** 決済後30分以内のみアクセス可能なお会計明細。品目一覧・税率別内訳・店舗情報・適格簡易請求書情報を返す
- **レスポンス:** store（店舗名・住所・登録番号）+ payment（金額・方法・日時）+ items（品目一覧）+ summary（税率別内訳）
- **エラー:** `PAYMENT_NOT_FOUND`(404), `RECEIPT_EXPIRED`(403)

---

### 6.3 公開 API (`api/public/`) — 全て認証不要

#### GET `/api/public/store-status.php`
- **パラメータ:** `?store_id=xxx`
- **認証:** 不要（一般公開）
- **用途:** リアルタイム混雑予報ページ用
- **レスポンス:**
  - `store` — 店舗名・住所・電話（store_settings から取得）
  - `tables` — total / occupied / available
  - `congestion` — `empty`(0-30%) / `normal`(31-60%) / `busy`(61-80%) / `full`(81-100%)
  - `congestion_label` — 日本語ラベル
  - `wait_minutes` — 推定待ち時間（0 / 10 / 20分）
  - `popular_items` — 本日の売上上位3品（営業日cutoff対応、orders.items JSONをPHPで集計）
  - `sold_out_items` — 品切れ品目一覧（menu-resolver経由）
- **セキュリティ:** 個人情報一切なし。テーブル詳細・注文内容は返さない。存在しないstore_idは404

---

### 6.4 KDS API (`api/kds/`)

#### GET `/api/kds/orders.php`
- **認証:** staff以上
- **パラメータ:** `?store_id=xxx&station_id=xxx&view=kitchen|accounting`
- **ポーリング間隔:** フロントエンドから3秒ごと
- **レスポンス:**
```json
{
  "orders": [{
    "id", "table_id", "table_code", "items": [...],
    "total_amount", "status", "created_at", "updated_at",
    "prepared_at", "ready_at", "served_at", "paid_at",
    "order_type", "staff_id", "customer_name", "pickup_at",
    "course_id", "current_phase", "course_name"?, "phase_name"?
  }],
  "stations": [{ "id", "name", "name_en" }],
  "businessDay": "YYYY-MM-DD"
}
```
- **ステーションフィルタリング:** `station_id` 指定時、そのステーションのルーティングルールに紐づくカテゴリの品目のみ返す。該当品目0件の注文は除外
- **ビュー:** `kitchen` → `pending/preparing/ready` のみ、`accounting` → `paid` 以外全て
- **コース自動発火:** ポーリング内で `check_course_auto_fire()` を実行

#### PATCH `/api/kds/update-status.php`
- **認証:** staff以上
- **Body:** `{ "order_id", "status", "store_id" }`
- **タイムスタンプ自動記録:** `preparing` → `prepared_at`, `ready` → `ready_at`, `served` → `served_at`, `paid` → `paid_at`
- **order_items連動:** 注文内の全品目（cancelled以外）も同じステータスに更新
- **ready通知:** ready一括更新時に品目ごとに `call_alerts`（type=product_ready）を自動作成→ハンディに通知。LEFT JOINでテイクアウト（table_id=NULL）にも対応（table_id='TAKEOUT', table_code='テイクアウト 顧客名'）

#### PATCH `/api/kds/update-item-status.php`
- **認証:** staff以上
- **Body:** `{ "item_id", "status", "store_id" }`
- **ステータス遷移:** pending → preparing → ready → served（+ cancelled）
- **ready通知:** ready時に `call_alerts`（type=product_ready）を自動作成→ハンディに通知。LEFT JOINでテイクアウト（table_id=NULL）にも対応
- **自動完了:** 全品目が served/cancelled なら親注文も自動で `served` に更新
- **レスポンス:** `{ "item_id", "status", "order_completed": bool }`

#### POST `/api/kds/close-table.php`
- **認証:** staff以上
- **Body:** `{ "store_id", "table_id"?, "order_ids"?[], "payment_method", "received_amount"? }`
- **処理:**
  1. 注文取得（`table_id` または `order_ids` で検索）
  2. プランセッション確認 → プラン料金 × 人数 = 請求額
  3. 全注文を `paid` に更新
  4. `session_token` リセット（新トークン生成）
  5. `table_sessions` を `paid` に更新
  6. 現金支払い時は `cash_log` に `cash_sale` 記録
  7. 全てトランザクション内で実行

#### GET/POST `/api/kds/cash-log.php`
- **GET:** `?store_id=xxx` → 当日のレジログ一覧
- **POST:** `{ "store_id", "type": "open|close|cash_in|cash_out", "amount", "note"? }`

#### POST `/api/kds/advance-phase.php`
- **Body:** `{ "store_id", "table_id" }`
- **処理:** 現フェーズ注文を `served` → 次フェーズ注文を自動生成 → セッション更新
- **完了時:** 全フェーズ完了なら `{ "completed": true }` を返す

#### GET/PATCH `/api/kds/sold-out.php`
- **GET:** `?store_id=xxx` → `menu-resolver` でメニュー一覧（品切れ状態付き）
- **PATCH:** `{ "store_id", "menu_item_id", "source": "template|local", "is_sold_out": bool }`
  - `template` → `store_menu_overrides` をUPSERT
  - `local` → `store_local_items` を直接UPDATE

#### POST `/api/kds/ai-kitchen.php`
- **認証:** staff以上
- **リクエスト:**
  ```json
  {
    "store_id": "s-xxx",
    "items": [
      {
        "order_id": "xxx",
        "table_code": "T01",
        "item_name": "カツ丼",
        "item_id": "mt-007",
        "qty": 1,
        "status": "pending",
        "elapsed_seconds": 180
      }
    ]
  }
  ```
- **設計思想:** キッチンは「テーブル単位」ではなく「料理品目単位」で動く。品目ごとの調理工程・所要時間を考慮した優先順位をAIが提案
- **処理フロー:**
  1. `posla_settings.gemini_api_key` からPOSLA共通APIキーを取得（L-10b）
  2. 品目リストをテキスト化（テーブル | 品名 x数量 | ステータス | 経過時間）
  3. Gemini 2.0 Flash に送信（temperature: 0.3）
  4. JSON形式で優先度分類を返却
- **レスポンス:**
  ```json
  {
    "urgent": [{"table_code":"T01","item_name":"カツ丼","qty":1,"reason":"理由"}],
    "next": [...],
    "waiting": [...],
    "summary": "全体の方針を1行で",
    "pace_status": "good|normal|busy"
  }
  ```
- **優先度判定ルール:** 待ち時間長い品目優先 / 調理工程が長い品目（揚げ物・煮込み）は早めに着手 / 同テーブル品目の同時仕上げ / 短時間品目（サラダ・ドリンク）は後から合わせる

#### GET `/api/kds/call-alerts.php`
- **認証:** staff以上
- **パラメータ:** `?store_id=xxx`
- **レスポンス:**
```json
{
  "alerts": [
    {
      "id": "uuid",
      "table_code": "T01",
      "reason": "お会計をお願いします",
      "created_at": "2026-04-01 12:00:00",
      "elapsed_seconds": 45,
      "type": "staff_call",
      "item_name": null
    }
  ]
}
```
- **type:** `staff_call`（顧客呼び出し）/ `product_ready`（品目完成通知）/ `kitchen_call`（キッチン→スタッフ呼び出し）。migration-i5, i7
- **KDS画面フィルタ:** KdsAlert は `product_ready` と `kitchen_call` を除外（調理側に完成通知は不要、kitchen_callは自身が送信したアラート）
- **グレースフルデグレード:** `call_alerts` テーブル未作成時は空配列、`type` カラム未作成時は従来形式で返却

#### POST `/api/kds/call-alerts.php`
- **認証:** staff以上
- **Body:** `{ "store_id": "必須", "reason": "任意（デフォルト: キッチンから呼び出し）" }`
- **処理:**
  1. `require_store_access` で店舗アクセス権検証
  2. 既存の pending kitchen_call を DELETE（連打防止）
  3. 新規 kitchen_call アラート作成（`table_id='KITCHEN'`, `table_code='キッチン'`）
- **レスポンス:** `{ "alert_id": "uuid" }`
- **用途:** KDS音声コマンド「スタッフ呼んで」から呼び出し

#### PATCH `/api/kds/call-alerts.php`
- **認証:** staff以上
- **Body:** `{ "alert_id": "uuid", "status": "acknowledged" }`
- **処理:**
  1. `status` を `acknowledged` に更新、`acknowledged_at` を現在時刻に設定
  2. **product_ready 連動:** アラートの `type` が `product_ready` かつ `order_item_id` が存在する場合、該当 order_item を `served` に自動更新（`served_at = NOW()`）
  3. **注文自動完了:** 全品目が served/cancelled なら親注文も自動で `served` に更新
- **レスポンス:** `{ "alert_id", "status", "item_served": bool, "order_completed": bool }`
- **用途:** KDS「対応済み」ボタン、ハンディ「配膳完了」「対応済み」ボタンから呼び出し

---

### 6.5 オーナー API (`api/owner/`)

#### テナント管理
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `tenants.php` | GET | owner | テナント情報取得 |
| `tenants.php` | PATCH | owner | テナント名更新。※L-10b 以降、AI/Places APIキーは `posla_settings` でPOSLA運営側が一元管理するため、テナント側からの更新は廃止。決済キー（Stripe）は別系統 |

#### 店舗管理
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `stores.php` | GET | owner | 一覧/詳細 |
| `stores.php` | POST | owner | 新規作成（`store_settings` も自動作成）|
| `stores.php` | PATCH | owner | 更新 |
| `stores.php` | DELETE | owner | 論理削除（`is_active=0`）|

#### ユーザー管理
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `users.php` | GET | owner | 全テナントユーザー一覧（`visible_tools_by_store` マップ含む）|
| `users.php` | POST | owner | 新規作成（パスワードハッシュ、`user_stores` 紐付け、`visible_tools_by_store` 対応）|
| `users.php` | PATCH | owner | 更新（パスワード変更可、店舗割当全置換、`visible_tools_by_store` 個別更新対応）|
| `users.php` | DELETE | owner | 物理削除（自分自身は削除不可）|

#### メニュー管理
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `categories.php` | CRUD | manager+ | カテゴリ管理。削除時に参照チェック |
| `menu-templates.php` | CRUD | manager+ | HQメニューテンプレート。削除時にオーバーライドもカスケード |
| `option-groups.php` | CRUD + サブ操作 | manager+ | オプショングループ + 選択肢CRUD + メニュー紐付け管理（下記詳細）|
| `upload-image.php` | POST | manager+ | 画像アップロード（JPEG/PNG/WebP/GIF、最大5MB）|

**option-groups.php サブ操作一覧:**

| action パラメータ | メソッド | 説明 |
|------------------|---------|------|
| `add-choice` | POST | オプション選択肢を追加 |
| `update-choice` | PATCH | 選択肢を更新（`?id=xxx`） |
| `delete-choice` | DELETE | 選択肢を削除（`?id=xxx`） |
| `template-links` | GET | テンプレートに紐付いたオプショングループ一覧（`?template_id=xxx`）|
| `sync-template-links` | POST | テンプレートの紐付けを全置換（body: `{ template_id, groups: [{group_id, is_required}] }`）|
| `local-links` | GET | ローカル品目に紐付いたオプショングループ一覧（`?local_item_id=xxx`）|
| `sync-local-links` | POST | ローカル品目の紐付けを全置換（body: `{ local_item_id, groups: [{group_id, is_required}] }`）|
| `menu-csv.php` | GET/POST | manager+ | メニューCSVエクスポート/インポート |

#### レポート・分析
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `cross-store-report.php` | GET | owner | クロス店舗レポート（売上比較、サービスタイム、品目ランキング、キャンセル、ちゅうちょ）|
| `analytics-abc.php` | GET | manager+ | ABC分析（A=累積売上70%まで、B=90%まで、C=残り）|

#### シフト（`api/owner/shift/`）
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `unified-view.php` | GET | owner | L-3 Phase 3: 全店舗シフト横断サマリー。?period=weekly\|monthly&date=YYYY-MM-DD。店舗別の出勤人数・人件費・充足率・ヘルプ要請件数を一括返却。enterprise限定（shift_help_request） |

#### 在庫管理
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `ingredients.php` | CRUD + 棚卸し | manager+ | 食材マスタ。`?action=stocktake` で一括在庫更新 |
| `recipes.php` | CRUD | manager+ | レシピ（メニュー→食材マッピング）|
| `ingredients-csv.php` | GET/POST | manager+ | 食材CSV |
| `recipes-csv.php` | GET/POST | manager+ | レシピCSV（名前ベースマッチング）|

---

### 6.6 店舗 API (`api/store/`)

#### 設定・テーブル
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `settings.php` | GET/PATCH | manager+ | 店舗設定（カットオフ、税率、レシート、制限等）|
| `tables.php` | CRUD | GET: staff+, 書込: manager+ | テーブル管理（フロアマップ座標含む）|
| `tables-status.php` | GET | staff+ | フロアマップ用テーブル状態（セッション+注文集計+品目進捗+メモ+LO状態）|
| `table-sessions.php` | CRUD | staff+ | セッションライフサイクル（着席→食事→会計→完了）。POST/PATCHでメモ対応 |
| `last-order.php` | GET/POST | GET:認証不要, POST:manager+ | ラストオーダー状態取得/手動LO切替 |

#### メニュー・オーバーライド
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `local-items.php` | CRUD | manager+ | 店舗限定メニュー。カロリー/アレルゲン対応（migration-i2）|
| `menu-overrides.php` | GET/PATCH | manager+ | テンプレートの店舗上書き（UPSERT）。画像オーバーライド対応（migration-e2）。PATCH時にcalories/allergensを含めるとmenu_templatesを直接更新（migration-i2）|
| `daily-recommendations.php` | GET/POST/DELETE | staff+ | 今日のおすすめ管理。日付指定で取得、品目+バッジ種別+コメントで追加、ID指定で削除 |
| `upload-image.php` | POST | manager+ | 画像アップロード |
| `translate-menu.php` | POST | manager+ | メニュー一括翻訳（L-4）。全カテゴリ・品目・オプションを指定言語に翻訳。Gemini 2.0 Flash APIで30件/バッチ。menu_translationsテーブルにON DUPLICATE KEY UPDATEでキャッシュ保存。**P1-20 で関数抽出**: メインロジックを `translate_menu_core(PDO $pdo, string $tenantId, string $storeId, array $langs, bool $force): array` 関数として切り出し、`TranslateMenuException` private クラス + `TRANSLATE_MENU_CORE_ONLY` 定数ガードで POST ハンドラ二重起動を防止。`api/smaregi/import-menu.php` から直接呼び出し可能にし、スマレジインポート自動翻訳を実現 |

#### 注文
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `takeout-management.php` | GET/PATCH | manager+ | テイクアウト注文管理。GET（日付・ステータスフィルター付き注文一覧）。PATCH（ステータス遷移: pending→preparing→ready→served） |
| `handy-order.php` | POST/PATCH | staff+ | ハンディ注文（イートイン/テイクアウト）。PATCH で修正。memo対応 |
| `payments-journal.php` | GET | staff+ | 取引ジャーナル（本日の決済一覧、スタッフ名・テーブルコード付き。営業日境界対応）|

#### プラン・コース
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `time-limit-plans.php` | CRUD | manager+ | 食べ放題プラン |
| `plan-menu-items.php` | CRUD | manager+ | プラン専用メニュー |
| `course-templates.php` | CRUD | manager+ | コーステンプレート（フェーズ含む）|
| `course-phases.php` | POST/PATCH/DELETE | manager+ | コースフェーズ個別操作 |

#### KDS
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `kds-stations.php` | CRUD | manager+ | KDSステーション + ルーティングルール |

#### レポート
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `sales-report.php` | GET | manager+ | 売上レポート（品目ランキング、時間帯別、テーブル別、サービスタイム）|
| `register-report.php` | GET | manager+ | レジ精算レポート（決済方法別、レジログ、過不足）|
| `order-history.php` | GET | manager+ | 注文履歴（ページネーション、フィルタ、検索）|
| `turnover-report.php` | GET | manager+ | 回転率・調理時間・客層分析（M-2+M-5）。テーブル別回転率、ランチ/ディナー比較、時間帯別効率、品目別調理時間（目標超過率）、組人数別分析、価格帯分布、時間帯別客数。table_sessions+orders+order_items集計 |
| `staff-report.php` | GET | manager+ | スタッフ評価・監査・不正検知（M-1+M-3）。スタッフ別注文実績・キャンセル率・満足度、レジ差異検出、深夜帯不審操作、異常検知アラート。audit_log+orders(staff_id)+cash_log+satisfaction_ratings集計 |
| `basket-analysis.php` | GET | manager+ | バスケット分析（M-4）。品目ペアnC2生成、support/confidence/lift算出、TOP30ペア+TOP20品目。order_items/orders.items集計。`?from=&to=&min_support=` |
| `demand-forecast-data.php` | GET | manager+ | 需要予測用データ集計（M-6）。直近7日品目別売上、曜日パターン、在庫状況、レシピBOM、店舗名。ドリンクカテゴリ自動除外。AIアシスタントがGeminiに送信するデータソース |
| `process-payment.php` | POST | staff+ | POSレジ会計処理。cash/card/qr対応。外部決済ゲートウェイ連携（Stripe、C-3 / L-13）。gateway情報DB記録。合流/分割/個別会計対応 |
| `receipt.php` | POST | staff+ | 領収書発行（L-5）。`{ payment_id, receipt_type, addressee }` → receiptsテーブルにレコード作成 + receipt/store/items/payment情報を返却。receipt_number自動採番（R-YYYYMMDD-NNNN）。PDF生成なし |
| `receipt.php` | GET | staff+ | 領収書取得（L-5）。`?payment_id=xxx` で支払い別一覧、`?store_id=xxx&date=YYYY-MM-DD` で日付別一覧、`?id=xxx&detail=1` で再印刷用詳細データ（items/store/payment情報付き）|
| `receipt-settings.php` | GET/PATCH | manager+ | 領収書設定（L-5）。registration_number（T+13桁）/business_name/receipt_store_name/receipt_address/receipt_phone/receipt_footer の取得・更新。PATCH時は監査ログ記録 |
| `terminal-intent.php` | POST | staff+ | Stripe Terminal決済インテント作成（L-13）。物理カードリーダーでの対面決済用。PaymentIntent作成→Terminal JS SDKが端末に送信 |

#### メニューバージョン
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `menu-version.php` | GET | なし | メニュー変更検知（軽量エンドポイント）。`?store_id=xxx` → `{ version: "2026-03-31 15:30:45" }`。`store_menu_overrides` と `store_local_items` の `MAX(updated_at)` を返す。顧客メニュー・ハンディが2秒ごとにポーリングし、バージョン変更時のみフルメニュー取得 |

#### スタッフ管理
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `staff-management.php` | CRUD | manager+ | 店舗スタッフ管理。自店舗のスタッフ一覧取得・作成・更新・削除 |

#### 外部API プロキシ
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `places-proxy.php` | GET | staff+ | Google Places/Geocoding サーバーサイドプロキシ。`?action=geocode&address=...` または `?action=nearby&lat=...&lng=...&radius=...`。APIキーは`posla_settings`テーブルから取得（L-10bで一元管理に移行） |
| `ai-generate.php` | POST | 認証必須 | Gemini 2.0 Flash サーバーサイドプロキシ（P1-6）。APIキーをブラウザに露出させないための統一プロキシ。`posla_settings.gemini_api_key` を読み出して呼び出す |

**ai-generate.php の詳細（P1-6）:**

- **Body:**
```json
{
  "prompt": "AIへの指示文",
  "temperature": 0.7,
  "max_tokens": 1024
}
```
- **パラメータ:**
  - `prompt` — 必須
  - `temperature` — 任意。0-2 にクランプ。未指定時 0.7
  - `max_tokens` — 任意。1-8192 にクランプ。未指定時 1024
- **モデル:** `gemini-2.0-flash`（ハードコード。クライアント指定不可）
- **レスポンス:**
```json
{ "ok": true, "data": { "text": "Geminiが生成したテキスト" } }
```
- **エラー:**
  - `MISSING_PROMPT`(400) — `prompt` 未指定
  - `AI_NOT_CONFIGURED`(503) — `posla_settings.gemini_api_key` 未設定
  - `GEMINI_ERROR`(502) — Gemini API 通信失敗
- **呼び出し元（admin系）:** `public/admin/js/ai-assistant.js` の `_callGemini()` / `_forecastWithGemini()`
- **実装メモ（プロンプト仕様との差異）:**
  - リクエスト Body: プロンプト仕様では `{ prompt, temperature, model }` だったが、実装では `{ prompt, temperature, max_tokens }`（クライアントからのモデル指定は許可しない方針）
  - モデル指定: プロンプト仕様では「ホワイトリスト（gemini-2.0-flash / gemini-1.5-flash / gemini-1.5-pro）」だったが、実装では `gemini-2.0-flash` ハードコード
  - レスポンス: プロンプト仕様では `{ text, model, raw }` だったが、実装では `{ text }` のみ

#### CSV入出力
| エンドポイント | 対象データ |
|---------------|-----------|
| `local-items-csv.php` | 店舗限定メニュー |
| `menu-overrides-csv.php` | メニューオーバーライド |
| `time-limit-plans-csv.php` | 食べ放題プラン |
| `plan-menu-items-csv.php` | プラン専用メニュー |
| `course-csv.php` | コース＋フェーズ |

**CSV共通仕様:**
- エンコーディング: UTF-8（BOM付き `\xEF\xBB\xBF` — Excel互換）
- インポート: トランザクション内実行、UPSERT方式
- レスポンス: `{ created: N, updated: N, errors: ["行2: ..."] }`

#### セキュリティ・セッション
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `audit-log.php` | GET | manager+ | セキュリティ監査ログ取得。owner=テナント全体、manager=自店舗+自身のテナントレベル操作。日付/ユーザー/アクションでフィルタ |
| `active-sessions.php` | GET/DELETE | manager+ | アクティブセッション一覧取得。DELETE でリモートログアウト（セッション無効化） |

#### シフト管理（L-3）（`api/store/shift/`）
| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `settings.php` | GET/PATCH | manager+ | シフト設定（UPSERT）。submission_deadline_day, default_break_minutes, overtime_threshold_hours, auto_clock_out_hours, GPS設定, staff_visible_tools, default_hourly_rate |
| `templates.php` | CRUD | manager+ | シフトテンプレート（早番/遅番等のパターン定義）。曜日・時間帯・必要人数・推奨ロール |
| `assignments.php` | CRUD | manager+ | シフト割当。カレンダーからの手動追加・編集・削除。`?action=apply-template` でテンプレート一括適用 |
| `availabilities.php` | CRUD | staff+ | 希望シフト提出。スタッフが日付・可否・希望時間帯を申請。manager+は全スタッフ分閲覧可 |
| `attendance.php` | POST/PATCH/GET | staff+ | 勤怠打刻。POST=出勤、PATCH=退勤/休憩。GET=自分の勤怠状況。GPS座標記録（gps_required時）。Haversine距離で店舗圏内判定 |
| `summary.php` | GET | manager+ | 勤務サマリー。`?period=weekly\|monthly&start_date=YYYY-MM-DD`。staff_summary（スタッフ別集計）+ daily_summary（日別集計）+ assigned_map。Phase 2: labor_cost（人件費）+ night_premium（深夜割増25%）+ labor_warnings（労基法警告6ルール） |
| `ai-suggest-data.php` | GET | manager+ | L-3 Phase 2: AI提案用データ集計。templates, availabilities, staffList（時給・実績・連続勤務日数）, salesByDayHour（過去4週平均）を一括返却。フロントエンドがGemini APIに送信するデータソース |
| `apply-ai-suggestions.php` | POST | manager+ | P1-21: AI提案シフトを `shift_assignments` に一括反映。DELETE + INSERT トランザクション、`note='AI提案'` タグで手動追加シフトを保護（既存手動シフトは削除しない）。マルチテナント境界 + staff 403 ガード + 監査ログ記録。`shift-calendar.js` から呼出 |
| `help-requests.php` | GET/POST/PATCH | manager+ | L-3 Phase 3: 店舗間ヘルプ要請。GET=送信/受信一覧（?action=list-stores で他店舗一覧、?action=list-staff で対象店舗スタッフ一覧も取得可）。POST=要請作成。PATCH=承認（スタッフ指名→shift_assignments自動作成）/却下/キャンセル。enterprise限定（shift_help_request） |

### 6.7 サブスクリプション API (`api/subscription/`)（L-11）

| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `checkout.php` | POST | owner | Stripe Checkoutセッション作成。プランを指定して新規サブスクリプション申込 |
| `portal.php` | POST | owner | Stripe Customer Portalセッション作成。プラン変更・カード変更・解約 |
| `status.php` | GET | owner | 現在のサブスクリプション状態（プラン・ステータス・次回請求日） |
| `webhook.php` | POST | なし | Stripeからのwebhookイベント受信（署名検証あり）。checkout.session.completed / customer.subscription.updated / customer.subscription.deleted / invoice.paid / invoice.payment_failed |

**ヘルパー:** `api/lib/stripe-billing.php` — Stripe Billing共通関数（get_stripe_config / create_stripe_customer / create_checkout_session / create_portal_session / get_subscription / verify_webhook_signature）

**P1-25 webhook 堅牢化（2026-04-08）:** `api/subscription/webhook.php` の switch ブロック全体を try-catch で包み `\Exception` を捕捉 → 500 で Stripe のリトライを誘発（DB一時障害向け、P1-11 の `stripe_event_id` UNIQUE により再送安全）。`INSERT subscription_events` は別の try-catch で包み、失敗時は 200 + `error_log()` で継続（構造的バグによるリトライループ回避）。`if (!$tenant) break;` 3箇所（checkout.session.completed / customer.subscription.updated / customer.subscription.deleted）に `[P1-25] *_tenant_not_found` ログを追加し、tenant 未特定時は INSERT 自体をスキップ（`subscription_events.tenant_id` NOT NULL への空文字フォールバック廃止）。既存 idempotency check の `error_log` 2件も `[P1-25]` プレフィックスで統一。なお P1-25 以前に記録された `tenant_id=''` のゴーストレコード 5件（全て checkout.session.completed）は監査ログのため保持し、改変しない。

### 6.8 Stripe Connect API (`api/connect/`)（L-13）

| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `onboard.php` | POST | owner | Stripe Connect Express Accountの作成 + オンボーディングURL生成 |
| `callback.php` | GET | なし | オンボーディング完了後のリダイレクトコールバック。ステータス確認→DB更新→owner-dashboard.htmlに遷移 |
| `status.php` | GET | any | Connect接続状態確認（connected / charges_enabled / payouts_enabled） |
| `terminal-token.php` | POST | manager+ | Stripe Terminal ConnectionToken発行（カードリーダー接続用） |

**ヘルパー:** `api/lib/stripe-connect.php` — Stripe Connect共通関数（get_platform_stripe_config / create_connect_account / create_account_link / get_connect_account / create_terminal_connection_token / calculate_application_fee）

### 6.9 スマレジ連携 API (`api/smaregi/`)（L-15）

| エンドポイント | メソッド | 認証 | 説明 |
|---------------|---------|------|------|
| `auth.php` | GET | owner | OAuth認可リクエスト開始。スマレジ認証画面にリダイレクト |
| `callback.php` | GET | なし | OAuthコールバック。認可コード→トークン交換→userinfoでcontract_id取得→DB保存 |
| `status.php` | GET | owner | スマレジ接続状態（connected/contract_id/expires_at） |
| `disconnect.php` | POST | owner | スマレジ連携解除（トークン・contract_id削除） |
| `stores.php` | GET | owner | スマレジ店舗一覧取得（`GET /pos/stores`） |
| `store-mapping.php` | GET/POST | owner | 店舗マッピング一覧取得/保存（POSLA店舗↔スマレジ店舗） |
| `import-menu.php` | POST | owner | スマレジ商品→POSLAメニューインポート（`GET /pos/products`→menu_templates+smaregi_product_mapping）。**P1-20 で自動翻訳統合**: インポート成功時（`$imported > 0`）に `translate_menu_core($pdo, $tenantId, $storeId, ['en'], false)` を呼び出し、新規取り込み品目を英語に自動翻訳。失敗時は `[P1-20] auto_translate_failed` ログ + レスポンスに `auto_translate: {ok:false, warning:'auto_translate_failed'}` を含めて継続。zh-Hans/ko は手動一括翻訳ボタンのまま（owner-dashboard の「メニュー翻訳」タブ） |
| `sync-order.php` | POST | any | 注文→スマレジ仮販売送信（`POST /pos/transactions/temporaries`）。require_onceで関数としても利用可能 |

**ヘルパー:** `api/lib/smaregi-client.php` — スマレジAPI共通関数（get_tenant_smaregi / refresh_token_if_needed / smaregi_api_request）

**認証情報の構造（2層）:**
- **Client ID / Client Secret**（アプリ認証情報）: POSLAというアプリケーション自体の身分証明。スマレジ開発者ダッシュボードで取得し、POSLA管理画面（`/public/posla-admin/`）で `posla_settings` テーブルに保存。全テナント共通。OAuth認可リクエスト時とトークン交換時に使用
- **Access Token / Refresh Token**（テナント認証情報）: テナントごとに異なるスマレジデータアクセス用トークン。テナントがOAuth認可した際に発行され、`tenants` テーブルにテナント単位で保存

**OAuth認可フロー:**
1. `auth.php` → スマレジ `id.smaregi.dev/authorize` にリダイレクト（scope: openid offline_access pos.products:read pos.transactions:read pos.transactions:write pos.stores:read）
2. ユーザーが認可 → `callback.php` にリダイレクト（認可コード付き）
3. `callback.php` → トークンエンドポイントで access_token/refresh_token 取得
4. `callback.php` → userinfo エンドポイントで contract_id 取得（openidスコープ必須）
5. DB保存 → owner-dashboard.html にリダイレクト

**注文同期フロー:**
- `api/customer/orders.php` の注文確定時に `sync_order_to_smaregi()` をベストエフォートで呼び出し（try-catch、失敗しても注文は成功）
- スマレジ仮販売登録API（`POST /pos/transactions/temporaries`）にフラット構造・全値文字列型で送信
- 成功時、`orders.smaregi_transaction_id` に仮販売取引IDを保存

**スマレジAPI URL（現在はサンドボックス）:**
- API: `https://api.smaregi.dev/{contract_id}/pos/...`
- 認証: `https://id.smaregi.dev/...`

**本番移行時:** 3ファイル5箇所のドメインを `.dev` → `.jp` に変更 + 本番用Client ID/Secret取得 + テナント再接続が必要。詳細手順は `docs/L15-smaregi-troubleshooting-log.md` の「本番移行手順」セクションを参照

**決済の分岐ロジック（`payment-gateway.php`）:**
- `stripe_connect_account_id` あり → POSLA(プラットフォーム)のAPIキーでDestination Charges決済。application_feeで手数料徴収
- `stripe_secret_key` あり（店舗自前） → 従来のC-3直接決済
- どちらもなし → 現金のみ

### 6.10 POSLA管理 API (`api/posla/`)（L-10）

POSLA運営専用API。テナント管理者とは完全分離された認証体系（PHPセッション、8時間有効）。

| エンドポイント | メソッド | 認証 | 説明 |
|---|---|---|---|
| `login.php` | POST | 不要 | POSLA管理者ログイン。セッション開始・`last_login_at`更新 |
| `logout.php` | DELETE | 必須 | セッション破棄 |
| `me.php` | GET | 必須 | 現在の管理者情報（id, email, display_name）取得 |
| `setup.php` | POST | 不要 | 初期管理者作成（posla_adminsが0件の場合のみ実行可。本番デプロイ後に削除推奨） |
| `dashboard.php` | GET | 必須 | ダッシュボード統計（テナント数・店舗数・ユーザー数・プラン分布・直近テナント5件） |
| `settings.php` | GET/PATCH | 必須 | POSLA共通設定CRUD（APIキーはGETでマスク表示） |
| `tenants.php` | GET/POST/PATCH | 必須 | テナントCRUD（一覧・新規作成・プラン変更・有効/無効切替） |
| `change-password.php` | POST | 必須 | POSLA管理者の自己パスワード変更（P1-5）|

**change-password.php の詳細（P1-5）:**

- **Body:**
```json
{
  "current_password": "現在のパスワード",
  "new_password": "新しいパスワード"
}
```
- **処理:**
  1. `password_verify()` で現在のパスワードを検証
  2. 新パスワードと現パスワードが同一でないか確認
  3. `validate_password_strength()` でパスワードポリシー検証
  4. `posla_admins.password_hash` を更新
  5. パスワード変更イベントを `error_log()` で記録
- **レスポンス:** `{ "ok": true, "data": { "message": "パスワードを変更しました" } }`
- **エラー:** `MISSING_FIELDS`(400), `INVALID_CURRENT_PASSWORD`(401), `SAME_PASSWORD`(400), `WEAK_PASSWORD`(400)
- **実装メモ:**
  - 他セッション無効化: P1-6d で `posla_admin_sessions` テーブルを新設し実装済み。`api/posla/login.php` でログイン時に `session_id` を INSERT、`api/posla/change-password.php` でパスワード変更時に「現セッション以外」の同一 admin_id レコードを DELETE する。攻撃者がセッションを窃取済みの場合の乗っ取り防止。失敗してもログイン/パスワード変更処理は止めない（既存挙動を壊さない）
  - 監査ログ: プロンプト仕様では `audit_log` への記録だったが、`audit_log` テーブルはテナントスコープ前提（`tenant_id` NOT NULL）のため使用不可。代わりに `error_log()` でサーバーログに出力

- **認証ヘルパー:** `auth-helper.php` — `start_posla_session()` + `require_posla_admin()` を提供。Secure Cookie（httponly, SameSite=Lax）

---

## 7. コアビジネスロジック

### 7.1 メニュー解決（menu-resolver.php）

```
入力: store_id
出力: カテゴリ配列 > 品目配列（テンプレート + ローカル統合）

処理フロー:
1. 店舗からtenant_idを取得
2. テナントの全カテゴリを取得
3. menu_templates LEFT JOIN store_menu_overrides で
   テンプレート + 店舗オーバーライドを結合
   - 価格: COALESCE(override_price, base_price)
   - 品切れ: COALESCE(smo.is_sold_out, mt.is_sold_out)
   - 画像: override_image_url が非空ならそちらを優先、NULLならテンプレート画像
   - 表示順: COALESCE(smo.sort_order, mt.sort_order)
   - is_hidden = 1 のものは除外
   - migration-e2 未適用時はグレースフルデグレード（override_image_url なし）
   - calories, allergens を取得（migration-i2）。未適用時はnull
4. store_local_items で店舗限定メニューを取得
   - calories, allergens も取得（migration-i2 未適用時はグレースフルデグレード）
5. オプション一括読み込み（テンプレート用 + ローカル用）
   - menu_template_options → option_groups → option_choices
   - local_item_options → option_groups → option_choices
   - store_option_overrides で店舗別価格上書き/非表示
   - テーブル未作成時はグレースフルデグレード
6. カテゴリマップ構築 → テンプレート品目マージ → ローカル品目マージ
7. 空カテゴリ除外
```

### 7.1.1 メニュー編集経路（プラン別運用ルール）

POSLAのメニューは3層構造：

| テーブル | 所有 | 役割 |
|---|---|---|
| `menu_templates` | テナント単位 | 本部マスタ。全店共通の name / name_en / category_id / base_price / image_url / calories / allergens を保持 |
| `store_menu_overrides` | 店舗単位 | 店舗差分。NULL=本部継承、値あり=店舗上書き。`is_sold_out` / `price` / `is_hidden` / `image_url` |
| `store_local_items` | 店舗単位 | 店舗限定品。本部マスタに紐づかない、その店舗だけのオリジナル品目 |

お客さん側のセルフメニューでは `api/lib/menu-resolver.php` の `resolve_store_menu()` が3層を統合してから返す（7.1 参照）。

#### enterprise の編集ルール（厳守、P1-28 / P1-29 で実装）

| やりたいこと | 編集する画面 | 編集できる人 | 書き込み先テーブル |
|---|---|---|---|
| 全店共通の品目を追加・編集（name / name_en / category / base_price / image / calories / allergens） | owner-dashboard 「**本部メニュー**」タブ | owner ロールのみ | `menu_templates` |
| 店舗価格上書き / 非表示 | dashboard 「**店舗メニュー**」タブ | manager | `store_menu_overrides.price` / `is_hidden` |
| 店舗の売り切れトグル | dashboard 「**店舗メニュー**」タブ | manager / staff | `store_menu_overrides.is_sold_out` |
| 店舗独自の画像 | dashboard 「**店舗メニュー**」タブ | manager | `store_menu_overrides.image_url` |
| 店舗独自の品目追加（その店だけのメニュー） | dashboard 「**限定メニュー**」タブ | manager | `store_local_items` |

**制限**:
- enterprise では店舗側「店舗メニュー」タブから本部マスタ項目（name / name_en / category_id / calories / allergens / base_price）は readonly。フィールドが disabled 表示され、保存時も `updateMenuTemplate` API は呼ばれない（P1-29）。
- API レベルでも防御線あり: `api/owner/menu-templates.php` の POST/PATCH/DELETE は enterprise テナントの場合 owner ロール以外を 403 で拒否。`api/store/menu-overrides.php` の calories/allergens 直接更新ブロックは enterprise ではスキップされる（P1-29）。
- 判定基準は `plan_features.hq_menu_broadcast`（プラン名のハードコード禁止）。

#### standard / pro の編集ルール

| やりたいこと | 編集する画面 |
|---|---|
| 品目の追加・編集（全項目） | dashboard 「**店舗メニュー**」タブ（owner / manager 兼用） |
| 店舗独自品の追加 | dashboard 「**限定メニュー**」タブ |

- standard / pro では owner-dashboard に「本部メニュー」タブは出ない（プラン制限、`features.hq_menu_broadcast=0`）。
- dashboard 「店舗メニュー」タブから事実上 `menu_templates` を全項目編集できる（既存運用、変更しない）。

#### 全プラン共通

- **店舗独自メニューの作成は「限定メニュー」タブを使う**。「店舗メニュー」タブで新規追加するのではない（こちらは本部マスタ + 店舗オーバーライド統合表示）。
- お客さん側のセルフメニューでは「本部由来」「店舗由来」の区別はなく、同じカテゴリ内に並んで表示される。

### 7.2 営業日計算（business-day.php）

```
目的: 深夜営業店舗で「営業日」を正しく判定する

例: cutoff = 05:00 の場合
  - 3月30日 03:00 → 営業日は「3月29日」
  - 3月30日 06:00 → 営業日は「3月30日」
  - 営業日 3月29日 の範囲: 3月29日 05:00 〜 3月30日 05:00

関数:
  get_cutoff_time(store_id)     → デフォルト 05:00:00
  get_business_day(store_id)    → { date, start, end, cutoff }
  get_business_day_range(from, to) → { start, end }
```

### 7.3 コースフェーズ自動発火

```
トリガー: KDSポーリング（3秒ごと）内で check_course_auto_fire() を実行

ロジック:
1. アクティブなコースセッション（status IN seated,eating）を取得
2. 各セッションの現フェーズの全注文を確認
3. 全品が served or cancelled → 次フェーズの auto_fire_min を確認
   - auto_fire_min = NULL → 手動発火待ち（スキップ）
   - auto_fire_min = 0 → 即座に発火
   - auto_fire_min > 0 → phase_fired_at + N分後に発火
4. 発火: 次フェーズの items から注文を自動生成（全品¥0）
5. セッションの current_phase_number と phase_fired_at を更新
```

### 7.4 POS決済処理（process-payment.php）

```
4モード:
1. 全額決済:
   - 全注文を paid に更新
   - table_sessions を paid に更新
   - session_token をリセット

2. 個別/分割決済 (is_partial):
   - payments テーブルに記録のみ
   - 注文はオープンのまま
   - selected_item_ids 指定時: 対象 order_items.payment_id を更新（支払済み追跡）
   - 全品目が支払済み（payment_id IS NOT NULL）になったら自動クローズ
     → orders → paid, table_sessions → paid, session_token リセット

3. 合流会計 (merged_table_ids):
   - 複数テーブルの注文をまとめて1回で決済
   - 全テーブルの orders を paid に更新
   - 全テーブルの table_sessions を paid に更新
   - 全テーブルの session_token をリセット
   - payments.merged_table_ids / merged_session_ids にJSON保存

4. 合流 + 分割の組み合わせ:
   - 合流会計でも個別決済（is_partial）と組み合わせ可能

税率計算:
  - 店内飲食: 10%（標準税率）
  - テイクアウト: 8%（軽減税率）
  - 税額 = Math.floor(税込価格 - 税込価格 / (1 + 税率))

在庫引落し（BOMフロー）:
  - recipes + ingredients テーブルが存在する場合のみ実行（migration-c5依存）
  - フロー: order_items → menu_template_id → recipes → ingredients
  - 各注文品目の qty × recipes.quantity_needed を食材の stock_quantity から減算
  - low_stock_threshold 以下になった食材はアラート対象（棚卸しUIで確認）

グレースフルデグラデーション:
  - merged_table_ids / merged_session_ids カラム未適用時は従来INSERT にフォールバック
  - order_items.payment_id カラム未適用時は分割追跡をスキップ
```

### 7.5 テーブルセッションライフサイクル

```
着席:
  A) 手動着席（POST table-sessions.php）:
     ├─ 通常: status = 'seated'
     ├─ 食べ放題プラン: + plan_id, time_limit_min, expires_at, last_order_at 計算
     └─ コース: + course_id, current_phase_number=1, フェーズ1注文を自動生成

  B) 自動着席（GET table-session.php — QRスキャン時）:
     └─ アクティブセッション(status NOT IN paid/closed)がなければ自動作成
        → session_token も新規生成（前の客のトークンを無効化）

ステータス遷移:
  seated → eating → bill_requested → paid / closed

会計時（close-table.php / process-payment.php）:
  - 全注文を paid
  - session_token リセット（次の客用に新トークン）
  - table_sessions を paid/closed
  → 前の客のメニュー画面は validate-token ポーリングでトークン不一致を検出
  → 「セッション終了」オーバーレイ表示、操作不可に

セッション削除時（DELETE table-sessions.php）:
  - 該当テーブルの未会計注文を自動キャンセル（status → cancelled）
```

---

## 8. フロントエンド詳細

### 8.1 管理画面 (`public/admin/`)

**エントリポイント:** `index.html`（ログイン）→ ロール別リダイレクト
- **owner** → `owner-dashboard.html`（本部管理専用）
- **manager/staff** → `dashboard.html`（店舗運営）

#### owner-dashboard.html（本部管理）
- タブ構成: クロス店舗レポート / ABC分析 / 店舗管理 / ユーザー管理 / AIアシスタント / 契約・プラン / 決済設定 / シフト概況 / 外部POS連携
- 全店舗横断で操作可能
- `owner-app.js` がタブ切替・モジュール初期化を制御
- AIアシスタント: SNS生成は店舗選択不要（手入力フォーム）、売上分析・競合調査は店舗選択ドロップダウン付き
- シフト概況（L-3 Phase 3）: 個別店舗サマリー + 全店舗統合ビュー（enterprise限定。出勤人数・人件費・充足率・ヘルプ要請件数。人件費比較バーチャート）

#### dashboard.html（店舗運営）
- ヘッダー: テナント名、店舗セレクター、ショートカットリンク（ハンディ/KDS/レジ、manager向け）、「注文画面」リンク（スタッフには非表示）
- 出退勤ボタン: スタッフのみ表示（マネージャー/オーナーには非表示）。出勤・退勤ボタンを常に両方表示し、状態に応じて有効/無効を切り替え。未出勤時は出勤ボタン(緑)がアクティブ・退勤ボタン(グレー)が無効。勤務中は出勤済(グレー)が無効・退勤ボタン(赤)がアクティブ+経過時間表示
- ツール表示制御（L-3b/L-3b2）: 現行UIでは staffロールにハンディ/KDS/レジの導線を出さない。`userVisibleTools` / `staffVisibleTools` は deviceロールの自動遷移先（handy > register > kds）を決めるために使う。
- グループタブ: メニュー管理 / プラン・コース / テーブル・フロア / 在庫・レシピ / レポート / 店舗設定 / AIアシスタント / シフト管理
- 各グループ内にサブタブ（ロール別表示制御）:

| タブ | `data-role-min` | `data-requires-store` | 説明 |
|------|----------------|----------------------|------|
| カテゴリ | owner | No | カテゴリCRUD |
| HQメニュー | owner | No | メニューテンプレートCRUD |
| 店舗メニュー | manager | Yes | オーバーライド管理 |
| 店舗限定 | manager | Yes | ローカルメニューCRUD |
| オプション | owner | No | オプショングループ + 選択肢 |
| テーブル | - | Yes | テーブルCRUD + QRコード |
| フロアマップ | - | Yes | テーブル状態（5秒ポーリング、品目進捗バー、メモ、サマリーバー、LOボタン）|
| 売上レポート | manager | Yes | 売上レポート（日次サマリー・品目ランキング・時間帯別・テーブル別・サービスタイム）|
| 回転率・客層 | manager | Yes | 回転率・調理時間・客層分析（M-2+M-5）。テーブル別回転/ランチディナー/時間帯効率/調理時間/組人数別/価格帯/時間帯別客数 |
| スタッフ評価 | manager | Yes | スタッフ別実績・キャンセル分析・レジ差異・深夜帯操作・異常検知アラート（M-1+M-3）|
| バスケット分析 | manager | Yes | 併売ペアランキング（support/confidence/lift）・品目出現頻度バーチャート・活用ヒント（M-4）|
| レジ分析 | manager | Yes | レジ精算レポート |
| 注文履歴 | manager | Yes | ページネーション付き注文一覧 |
| 食べ放題 | manager | Yes | プラン + プランメニューCRUD |
| コース | manager | Yes | コース + フェーズCRUD |
| 在庫・レシピ | manager(max) | Yes | 食材 + レシピ + 棚卸し |
| 設定 | manager | Yes | 店舗設定フォーム（営業/レジ/レシート/Googleレビュー設定） |
| KDSステーション | manager | Yes | ステーション + ルーティング |
| スタッフ管理 | manager | Yes | 店舗スタッフCRUD |
| 監査ログ | manager | Yes | セキュリティ監査ログビューア（日付/ユーザー/アクションフィルタ）|
| AIアシスタント | manager | Yes | SNS投稿文生成 + 売上トレンド分析 + 周辺競合調査（Gemini + Places API）+ AI需要予測・発注提案（7日間売上+在庫+BOM→Gemini予測、ドリンク除外）。モダンカード表示（テーブル変換・バッジ・グラデーション背景）|
| テイクアウト | テイクアウト注文 | manager+ | テイクアウト注文一覧・ステータス管理（受付/調理中/準備完了/受取済）。日付・ステータスフィルター。30秒自動リフレッシュ。顧客名・電話・受取時間・注文サマリー表示 |
| カレンダー | - | Yes | L-3: 週次シフトカレンダー（manager=全スタッフ編集、staff=自分の希望提出）|
| テンプレート | manager | Yes | L-3: シフトテンプレート管理（早番/遅番/深夜等のパターン定義）|
| 集計サマリー | manager | Yes | L-3 Phase 2: 人件費・勤務時間集計（深夜割増・労基法チェック・スタッフ別詳細）|
| ヘルプ | manager | Yes | L-3 Phase 3: 店舗間ヘルプ要請（送信/受信一覧・作成・承認スタッフ指名・却下・キャンセル）。enterprise限定（shift_help_request） |

**JSモジュール構成（IIFE パターン）:**
```javascript
var ModuleName = (function() {
  var _state = {};
  function init(listEl) { /* 初期化 */ }
  function render() { /* DOM レンダリング */ }
  function handleAction(action, id) { /* CRUD操作 */ }
  return { init: init };
})();
```

**今日のおすすめ管理（DailyRecommendationEditor）:**
- `daily-recommendation-editor.js` — IIFE モジュール
- dashboard.html の「店舗メニュー」セクション内に `<details>` で配置
- メニューテンプレート + 店舗限定品目をドロップダウンで選択
- バッジ種別（おすすめ/人気/新着/限定/本日限り）とコメントを設定
- 日別管理（当日分のみ表示・追加・削除）
- `AdminApi.getDailyRecommendations()` / `addDailyRecommendation()` / `removeDailyRecommendation()` を使用

**カロリー・アレルゲン編集（N-7）:**
- `menu-override-editor.js`: 編集モーダルにカロリー入力欄（整数）+ アレルゲン28品目チェックボックスを追加。保存時に `menu_templates` を直接更新
- `local-item-editor.js`: 追加・編集モーダルにカロリー入力欄 + アレルゲン28品目チェックボックスを追加
- アレルゲン28品目: 卵/乳/小麦/そば/落花生/えび/かに/アーモンド/あわび/いか/いくら/オレンジ/カシューナッツ/キウイ/牛肉/くるみ/ごま/さけ/さば/大豆/鶏肉/バナナ/豚肉/まつたけ/もも/やまいも/りんご/ゼラチン
- 両モジュールとも `_allergenList` 配列と `_buildAllergenCheckboxes()` / `_collectAllergens()` ヘルパーを独立実装（IIFE分離のため）

**画像アップロード:** クライアントサイドで800px最大・JPEG 0.8品質に圧縮してからアップロード
- `menu-template-editor.js`: 本部テンプレート画像 → `api/owner/upload-image.php`
- `local-item-editor.js`: 店舗限定メニュー画像 → `api/store/upload-image.php`
- `menu-override-editor.js`: 店舗オーバーライド画像 → `api/store/upload-image.php`（migration-e2 要）
- 共通UI: `.image-upload` クラス（クリック選択、プレビュー、×削除ボタン）

---

### 8.2 顧客画面 (`public/customer/`)

**エントリポイント:** `menu.html`（QRコードからアクセス）

**URL パラメータ:** `?store_id=xxx&table_id=xxx`

**モジュール:**
- **CartManager** — localStorage ベースのカート管理。オプション/トッピング対応。`cartKey = itemId + '__' + choiceIds` で一意キー生成
- **OrderSender** — `idempotency_key` 付き冪等送信。送信失敗時のリトライロジック。memo/allergens のオフライン保存・復帰対応

**画面フロー:**
```
QRスキャン → table-session API（自動着席）→ ウェルカムモーダル → メニュー表示
  → カテゴリ選択（「おすすめ」タブ含む）→ 品目タップ → オプションモーダル（あれば）
  → カート追加 → カート確認（メモ入力・アレルギー選択）→ 注文送信
  → 完了トースト → 再注文セクション更新
  （会計後 → validate-token ポーリングでセッション終了検出 → 操作不可オーバーレイ）
```

**特殊モード:**
- **プランモード:** `plan_id` パラメータ付き → 全品¥0表示、金額上限チェックなし
- **コースモード:** `course_id` パラメータ付き → フェーズ別カテゴリ表示
- **プレビューモード:** `table_id` なし → カートバー・トークン検証なし、メニュー閲覧のみ

**メニューバッジ表示:**
- **限定バッジ:** `source === 'local'` の品目に「限定」バッジ
- **おすすめバッジ (N-1):** `daily_recommendations` から取得。5種類: おすすめ(橙)/人気(ピンク)/新着(緑)/限定(紫)/本日限り(赤・点滅)。おすすめバッジがある場合は限定バッジを非表示
- **人気ランキングバッジ (N-6):** 過去30日の注文数から自動算出。TOP3は🥇🥈🥉メダル、4-10位は「TOP N」バッジ
- **おすすめセクション:** おすすめ設定がある場合、カテゴリタブ先頭に「おすすめ」タブを追加。おすすめ品目をコメント付きで表示

**カロリー・アレルゲン表示 (N-7):**
- カロリー: 品目カードに「NNNkcal」表示（null時は非表示）
- アレルゲン: 特定原材料等28品目をオレンジ枠タグで表示

**注文メモ・アレルギー選択 (N-3):**
- カートモーダル内にメモ入力欄（200字）とアレルギーチップ（特定原材料7品目）
- 注文送信後にメモ・チップをリセット
- KDSではメモを金色左ボーダー、アレルギーを赤色左ボーダーで表示

**セッション終了検知:**
- `validate-token.php` を30秒間隔でポーリング
- 会計後にトークンが変更された場合、「ご利用ありがとうございました」オーバーレイを全画面表示
- 全ポーリング（メニューバージョン・プランタイマー・コース進行・トークン検証）を停止

**品切れ即時反映:**
- `menu-version.php` を2秒ごとにポーリング（`checkMenuVersion`）
- バージョン変更検知時のみフルメニュー再取得（recommendations/popularityも更新）
- カート内の品切れ品目を自動検出・削除し、アラート表示
- サーバーサイドでも注文送信時に品切れチェック（HTTP 409）

**テイクアウト導線:** ヘッダーにテイクアウトリンク（「テイクアウト」ボタン、オレンジ枠）を表示。初期非表示。メニュー読み込み後に `GET /customer/takeout-orders.php?action=settings` で `takeout_enabled` を確認し、有効な場合のみリンク表示。`takeout.html?store_id=xxx` への相対リンク。

**テーマ:** オレンジ基調（`#e65100`）、モバイルファースト

**AIウェイター（ai-waiter.js）:**
- DOM自己挿入パターン（voice-commander.js と同方式）。`menu.html` に `<script>` 追加のみで有効化
- 初回ロード時に `ai-waiter.php` へ可用性チェック → `AI_NOT_CONFIGURED` の場合はボタン非表示
- フローティングボタン（右下、オレンジ、「🤖 AIに聞く」）→ フルスクリーンチャットパネル
- ユーザー（右寄せ・オレンジ）/ AI（左寄せ・グレー）のチャットバブル
- タイピングインジケーター（バウンスアニメーション）
- `suggested_items` カード表示 → 「カートに追加」→ `CartManager.addItem()` 連携
- カート追加後に `refreshCartBar()` でカートバッジ直接DOM更新
- トースト通知（カート追加成功）
- 会話履歴保持（最大10往復、`MAX_HISTORY`）
- Enter キー送信対応

**調理進捗表示（order-tracker.js
│   │   │   └── takeout-app.js              # L-1: テイクアウト注文ページJS（IIFE・メニュー取得・カート・スロット・決済リダイレクト・ステータスポーリング））:**
- DOM自己挿入パターン。`menu.html` に `<script>` 追加のみで有効化
- `GET /api/customer/order-status.php` を5秒間隔でポーリング
- 品目別ステータスバッジ（受付/調理中/完成/提供済）+ 全体進捗バー
- セッション終了検知と連携（セッション終了時はポーリング停止）
- 注文がない場合は非表示

**満足度評価（satisfaction-rating.js / menu.html内）:**
- 品目が `served` になると自動で評価プロンプトを表示
- 5段階フェイスUI（😠😕😐🙂😄）
- 15秒自動クローズ + 手動スキップ
- 評価キューで複数品目を順次評価
- 低評価（1-2）はKDS/ハンディにアラート表示

**スタッフ呼び出し（call-staff.js）:**
- DOM自己挿入パターン。`menu.html` に `<script>` 追加のみで有効化
- URLパラメータから `store_id` / `table_id` を取得。プレビューモード（table_id なし）では非表示
- ヘッダーに「🔔 呼び出し」ボタンを挿入（オレンジ、ENボタンの前）
- タップ → 理由選択モーダル（4択: 注文したい / お会計をお願いします / 食器を下げてください / その他）
- `POST /api/customer/call-staff.php` で送信
- 3分クールダウン（ボタンにカウントダウン表示、再送防止）
- トースト通知（「スタッフを呼びました」）

---

### 8.3 KDS画面 (`public/kds/`)

**エントリポイント:** `index.html`（キッチン）、`cashier.html`（POSレジ）

**KDSキッチン（index.html）:**
- 3列カンバンボード（受付/調理中/完成）
- ステーションボタンバーで「全品目」または各ステーション切替
- ステーションバー直下に選択中ステーションの状況帯を表示。注文数、未完了品目数、受付/調理中/提供待ち、最上位の注意理由を集計する
- 選択中ステーションでdanger/warning/prep/提供待ちが増えた時だけ通知音を鳴らす。初回表示とステーション切替直後は鳴らさない
- ヘッダーの `開店前チェック` でKDS通信、自動更新、通知音、マイク入力、画面維持、選択ステーションを確認する
- 会計機能は含まない（cashier.htmlに分離済み）
- **注文メモ表示:** メモ付き注文は金色左ボーダー（`.kds-order-memo`）で目立つ表示
- **アレルギー表示:** アレルギー選択がある注文は赤色左ボーダー（`.kds-order-allergen`）+ ⚠️アイコンで警告表示

**モジュール（キッチン）:**
- **KdsAuth** — 認証＋店舗選択（`localStorage: mt_kds_store`）
- **PollingDataSource** — 3秒間隔ポーリング（将来のWebSocket移行を想定した抽象化）
- **KdsRenderer** — カンバンレンダラー。ステータス遷移ボタン付き注文カード。テイクアウト注文は「テイクアウト」バッジ（オレンジ拡大）+受取時間バッジ+薄オレンジ背景グラデーションで視認性強化。経過時間、予約時刻、テイクアウト受取時刻からnormal/prep/warning/dangerを判定し、`getOperationalSummary()`でステーション状況帯用の集計を返す
- **SoldOutRenderer** — 品切れトグル画面（30秒ポーリング）+ 音声コマンド（品切れトグル・一覧表示）
- **VoiceCommander** — 音声コマンダー（`voice-commander.js`）
- **AiKitchen** — AIキッチンダッシュボード（`ai-kitchen.js`）。Gemini APIで料理品目単位の調理優先順位を分析。ボトムパネルUI、60秒自動更新。`GET /api/kds/orders.php` から注文取得→品目展開→`POST /api/kds/ai-kitchen.php` に送信
- **KdsAlert** — 呼び出しアラート（`kds-alert.js`）。DOM自己挿入（ヘッダー直後に赤バナー）。5秒ポーリングで `GET /api/kds/call-alerts.php` を監視。`product_ready` と `kitchen_call` はフィルタ除外（調理側に完成通知は不要、kitchen_callはKDS自身が送信したアラート）。新着アラート検知時にWeb Audio APIでビープ音（880Hz+1100Hz）。「対応済み」ボタンで `PATCH` → 即座に再ポーリング

**VoiceCommander 詳細:**

音声でKDSのステータス更新を行うハンズフリーモジュール。Web Speech APIを入口にし、厨房で頻出する操作はGeminiを使わないファストパスで処理する。Geminiは端末ごとの「AI補助ON」時だけ、ファストパスで処理できない曖昧な発話の補助解析に使う。

```
状態遷移: OFF → STANDBY（緑枠）→ PROCESSING（赤点滅）→ STANDBY
```

| 項目 | 仕様 |
|------|------|
| **ON/OFF** | ヘッダーボタンのタップでトグル。DOM自己生成（scriptタグ削除禁止）。ON状態は `localStorage: mt_kds_voice_active` で永続化（KDS ↔ sold-out.html 間で共有） |
| **AI補助ON/OFF** | ヘッダーの音声ボタン横に `AI補助ON / AI補助OFF` を表示。保存先は `localStorage: mt_kds_voice_ai_fallback`。初期値OFF。OFF時は未対応の言い回しをGeminiへ送らず停止、ON時だけGeminiフォールバックを使う |
| **音声診断** | ヘッダーに `音声診断` ボタンを表示。音声でも「音声診断」「マイク診断」「音声状態」「マイク状態」で開く。端末内 `localStorage: mt_kds_voice_diag_v1` に成功/失敗、final回数、AI補助使用、自動リフレッシュ、no-speech/network/audio-capture回数を保存。診断画面を開いている間だけWeb Audio APIでマイク入力レベルを表示し、閉じるとMediaStreamを停止する |
| **音声認識** | `continuous: true` + `interimResults: true` で常時リスニング |
| **コマンド検出** | テーブル番号パターン(`T01`/`テーブル1`/`1番`等) または動作語（調理/完成/提供/済み等）を含む発話のみ処理 |
| **interim確定** | コマンドパターン検出後1.5秒タイマーで確定（Chrome が final result を返さないケース対策） |
| **onend再開** | Chrome無音タイムアウト対策。通常は500ms後に再開、エラー時は最大5秒までバックオフ |
| **ウォッチドッグ** | 20秒ごとに音声認識の状態を監視。毎回再起動するのではなく、条件を満たした時だけハードリセット |
| **定期ハードリセット** | 12分ごとに `abort()` → `recognition = null` → 350ms後に新規作成。長時間稼働で認識品質が落ちる問題への予防策 |
| **品質低下検知** | 90秒応答なし、3分final未確定、interimのみ45秒継続、3分内no-speech 4回、network 2回、audio-capture 2回で自動リフレッシュ |
| **自動リフレッシュ抑止** | API処理中、Gemini解析中、確認ダイアログ中、手動停止中、すでにハードリセット中は実行しない |
| **ノイズフィルタリング** | キッチン雑音対策。最小文字数(2)・最大文字数(60)・最低信頼度(0.4)でフィルタ。短い/長い/低信頼度の結果を除去 |
| **10秒取り消し** | ステータス更新・品目更新・一括操作後、10秒間 `戻す` UIを表示。音声の「戻して」「今のなし」でも実行 |
| **確認ダイアログ** | 一括操作・取消・コース次フェーズ・低確信度Gemini解析で表示。声で「実行」または「やめる」。5秒無回答で自動キャンセル |
| **Gemini解析** | AI補助ON時のみ、ファストパスで処理できない発話を `POST ../../api/store/ai-generate.php` に送信。注文一覧とメニュー一覧をコンテキストとして渡し、JSONのみを返させる |
| **確信度** | Geminiが `confidence: "low"` を返した場合、確認ダイアログで確認 |
| **表示モードファストパス** | 「まとめ表示」「提供順」「エクスペディター」でExpeditor表示、「通常表示」「3列」で通常表示 |
| **卓操作ファストパス** | `T01 完成` / `T01 調理開始` / `T01 提供済み` / `T01 全品完成` / `T01 キャンセル` / `T01 残り` / `T01 注意確認` などをGemini不要で処理 |
| **品切れファストパス** | 「〇〇品切れ」「〇〇販売再開」「品切れ一覧」「品切れ管理」はGeminiを経由せずローカル実行 |
| **品切れ確認ポップアップ** | 品切れトグル実行後、成功（緑/赤ポップアップ）・失敗（暗赤ポップアップ）を中央表示。pop-inアニメーション |
| **品切れ一覧オーバーレイ** | 品切れ品目の一覧を中央オーバーレイ表示（6秒 or タップで閉じる）+ 該当行のハイライト |
| **スタッフ呼び出しファストパス** | 「スタッフ呼んで」「ホール呼んで」等でGemini不要の即時実行。人物キーワード（スタッフ/ホール）+ 動作キーワード（呼んで/読んで/よんで/来て/お願い/コール）の組み合わせ検出で助詞「を」「に」等に依存しない。成功時は👨‍🍳ポップアップ（3秒自動消滅）を中央表示 |
| **AIキー状態確認** | `GET ../../api/store/settings.php?store_id=...` で `ai_api_key_set` を確認。キー本体はフロントに出さない |
| **ブラウザ** | Chrome推奨。Web Speech APIの日本語モードは英数字(`T01`等)を正しく認識しにくい |

**動作語一覧:**
```
調理, 開始, 完成, 提供, 済み, すみ, キャンセル, 取消, 取り消,
できた, できました, あがり, 上がり, アップ, サーブ, サーヴ,
準備, じゅんび, ready, レディ,
品切れ, 品切, 売り切れ, 売切, 販売再開, 再開, 解除, 復活,
品切れ確認, 品切れ一覧, 品切れ管理,
AI補助, AI解析, AIフォールバック,
ライトモード, ダークモード, 明るく, 暗く, テーマ,
まとめ, 提供順, エクスペディター, 通常, 3列,
戻して, 今のなし, 直前, 残り, 未完成, 注意, アレルギー,
次フェーズ, 次のフェーズ, 全品,
音声診断, マイク診断
```

**Expeditorまとめ表示:**
- `public/kds/index.html` の表示モードバーで通常3列/まとめ表示を切替
- 保存先は `localStorage: kds_view_mode`
- `KdsRenderer.setMode('expeditor')` で1カラム表示
- 提供待ち品目を持つ注文を先頭、次にdanger/warning/prep優先度、最後に経過時間が長い順で表示
- 上部に提供待ち数、未完成品数、卓ごとの残り/提供/調理/受付を最大12卓まで表示
- 混在状態の注文では一括ボタンを出さず、品目ごとの操作を促す

**遅延優先表示:**
- 経過10分以上でwarning、20分以上でdanger
- テイクアウト受取時刻まで20分以内でprep
- テイクアウト受取時刻まで10分以内でwarning、受取時刻超過でdanger
- 予約時刻まで20分以内でprep
- 予約時刻到達でwarning、予約時刻から10分以上超過でdanger
- 予約時刻は `api/kds/orders.php` が現在の卓セッションに紐づく予約から `reservation_reserved_at` として返す。予約系テーブル未適用時は無視する

**注意表示:**
- `orders.memo` を金色左ボーダーで表示
- `order_items.allergen_selections` を赤色左ボーダーで表示
- メモ・オプションから辛さ指定、抜き/なし指定、温度/温め指定、焼き加減/仕上げ指定を検出し、オレンジ系の注意表示として出す

**カンバンボード:**
```
┌──────────┐  ┌──────────┐  ┌──────────┐
│  受付(N)  │  │ 調理中(N) │  │  完成(N)  │
│  orange   │  │  yellow   │  │  green    │
│           │  │           │  │           │
│ [注文カード] │ │ [注文カード] │ │ [注文カード] │
│  Table T01│  │  Table T02│  │  Table T03│
│  3:25経過 │  │  5:10経過 │  │  8:00経過 │
│  品目一覧 │  │  品目一覧 │  │  品目一覧 │
│ [調理開始]│  │ [完成]    │  │ [提供済]  │
│ [キャンセル]│ │ [キャンセル]│ │           │
└──────────┘  └──────────┘  └──────────┘
```

**品切れ管理画面（sold-out.html）:**
- 全メニューの品切れ状態をトグル管理
- 30秒ポーリングで自動更新
- **音声コマンド対応:** KDSと同じVoice Commander機能（KdsAuth.init()完了待ちリトライ機構付き）
  - 「〇〇売り切れ」→ 品切れON + 確認ポップアップ
  - 「〇〇再開」→ 品切れOFF + 確認ポップアップ
  - 「品切れ一覧」等 → ファストパス（Gemini不要）→ オーバーレイ表示 + 行ハイライト
  - ノイズフィルタリング・3分リフレッシュ搭載

**テーマ:** ダークテーマ（`#1a1a2e` 背景、`#263238` ヘッダー）

---

### 8.3.1 スタンドアロンPOSレジ (`public/kds/cashier.html`)

**エントリポイント:** `cashier.html`

**ヘッダー（52px、グラデーション背景）:**
```
[POSレジ] [店舗名] ──── [スタッフ名] [HH:MM:SS] [取引履歴] [レジ開閉] [音声]
```

**3カラムレイアウト:**
```
┌───────────┬─────────────────┬──────────────────┐
│ 左240px    │ 中 flex:1       │ 右420px          │
│            │                 │                  │
│ 本日の売上  │ ☑ 焼魚定食 ×1   │ [割引: 金額|%]    │
│ ¥5,330 5件 │ ☑ 味噌汁   ×1   │                  │
│ ■■■□□ 比率│ ☐ ビール   ×2   │ [現金][カード][QR]│
│ 現金 ¥5,330│                 │  (グロー効果)     │
│ クレカ ¥0  │ 小計   ¥1,980  │ 合計  ¥1,980     │
│ QR    ¥0   │ 10%(税¥164)     │ 預かり ¥2,000    │
│ 予想在高   │ 8% (税¥13)      │ お釣り ¥20       │
│ ¥5,330    │ 割引  -¥0       │  (2rem+shadow)   │
│────────────│ 合計   ¥1,980  │                  │
│ 会計待ち 3 │  (1.625rem太字) │ [テンキーパッド]  │
│ [T01 ¥980 ]│                 │ [¥1000][¥5000]   │
│  15分      │                 │ [¥10000][ぴったり]│
│ [T02 ¥1200]│                 │ [7][8][9] 62px   │
│  72分 (赤) │                 │ [4][5][6]        │
│ [TO  ¥500] │                 │ [1][2][3]        │
│            │                 │ [00][0][C]       │
│            │                 │ [← 1字削除      ]│
│            │                 │                  │
│            │                 │ [レシート][会計する]│
│            │                 │  (gradient+glow) │
└───────────┴─────────────────┴──────────────────┘
```

**モジュール:**
- **CashierApp** (`cashier-app.js`) — スタンドアロンPOSレジ IIFE モジュール
  - KdsAuth で認証・店舗取得
  - 5秒間隔ポーリング（orders + table-sessions）
  - 30秒間隔で売上サマリー取得（sales-report + register-report）
  - テーブル選択 → 品目チェックボックス（個別会計対応）
  - 割引（金額/パーセント、適用/取消ボタン）
  - テンキー（現金時のみ表示、クイック金額 + BSキー付き、62px大型）
  - レシートプレビューモーダル（印刷対応、72mm感熱紙、backdrop-filter blur）
  - 領収書発行（L-5）：決済完了後に「領収書」ボタン条件表示→宛名入力→receipt.php POST→新ウィンドウHTML印刷。`_lastPaymentId`で決済直後のみ有効化。適格簡易請求書（registration_number設定時）自動判定
  - 二重送信防止（`_isSubmitting` フラグ + ポーリング一時停止）
  - 確認ダイアログ付き決済送信（process-payment.php）
  - 決済完了後に売上サマリー自動更新
  - 共通HTMLヘルパー（`_renderPayMethodsHtml`, `_renderAmountHtml`, `_renderTenkeyHtml`）

**v2 追加機能（2026-03-31）:**

| 機能 | 説明 |
|------|------|
| **時計表示** | ヘッダーに HH:MM:SS、1秒間隔更新 |
| **スタッフ名表示** | ヘッダーに KdsAuth.init() で取得した display_name |
| **取引ジャーナル** | スライドパネル（右420px）。本日の全決済一覧（時刻/テーブル/金額/方法/スタッフ）。payments-journal.php 連携。件数・合計のサマリーバー |
| **レジ開閉** | スライドパネル。レジ開け（開始在高入力→cash_log type=open）、入出金（cash_in/cash_out）、レジ閉め（実際金額→予想在高差額リアルタイム計算→close）。cash-log.php 活用 |
| **キーボードショートカット** | 0-9=テンキー、BS=削除、Enter=会計、Esc=クリア/閉じ、F1/F2/F3=現金/カード/QR、J=ジャーナル、R=レシート |
| **操作音フィードバック** | AudioContext ビープ音生成（ES5互換）。click=短音、success=上昇3音、error=低音。ON/OFF永続化（localStorage） |
| **売上比率バー** | 決済方法別の水平バー（現金=緑、カード=青、QR=紫） |
| **経過時間表示** | テーブルカードにセッション開始からの経過分数。30分超=黄色、60分超=赤色 |

**O-2 合流・分割会計（2026-04-03）:**

| 機能 | 説明 |
|------|------|
| **合流会計** | テーブル一覧ヘッダーの「合流会計」ボタンでモードON → 複数テーブルをチェック選択（オレンジ枠）→ 全テーブルの品目を統合表示（テーブルコードタグ付き）→ 一括決済。合流バッジ表示 |
| **分割会計** | テーブル選択後、品目チェックボックスで支払い対象を選択 → 個別会計送信。支払済み品目はグレーアウト＋「支払済」ラベル＋チェック無効化。全品支払完了で自動セッションクローズ |
| **支払済み品目追跡** | order_items.payment_id で各品目の支払済み状態を追跡（migration-i6）。再選択時にグレーアウト表示で二重支払い防止 |

**CSSテーマ（v2）:**
- CSS変数システム（`--ca-*`）による一元管理
- ヘッダー: 52px、グラデーション背景（linear-gradient）
- テンキー: 62px、角丸10px、押下 scale(0.94) アニメーション
- 支払方法ボタン: padding 0.875rem、選択時グロー効果（box-shadow）
- 金額表示: 合計 1.625rem 太字、お釣り 2rem + text-shadow、不足=赤点滅アニメーション
- 会計ボタン: グラデーション背景 + box-shadow + letter-spacing
- テーブルカード: hover時 translateX(2px)、active時グロー
- モーダル: backdrop-filter blur(8px)
- 全ボタンに transition 0.15s ease

**売上サマリー（左カラム上部）:**
- 本日合計売上 + 件数
- 決済方法別比率バー（現金=緑、カード=青、QR=紫）
- 支払方法別内訳（現金/クレカ/QR）
- 予想在高（レジ開け金 + 現金売上 + 入金 - 出金）
- 30秒間隔で自動更新

**テーブル未選択時:**
- 品目リスト・割引・合計エリアは非表示
- 支払方法ボタン・テンキー・受取/お釣り欄は表示（共通ヘルパーで生成）
- 「会計する」ボタンはdisabled状態

**安全機構:**
- 二重送信防止（`_isSubmitting`フラグ）— 送信中はボタン無効+ポーリング停止
- ポーリング中のテーブル消失時、送信中はレジリセットをスキップ
- 確認ダイアログ（confirm）付き決済 — 金額と支払方法を表示
- テーブル切替時に支払方法を維持（連続会計の利便性）
- planPriceの明示的parseInt（文字列→数値安全変換）
- guestCountのXSSエスケープ
- input内ではキーボードショートカット無効（テンキーのみ）

**テーマ:** ダークプロテーマ（`#0f1923` 背景、`#00c896` アクセント、`ca-` CSSプレフィクス、CSS変数システム）

**レスポンシブ:**
- iPad横（1024px以下）: テーブル列220px、操作パネル380px、テンキー58px
- iPad縦（768px以下）: 縦積みレイアウト、テーブル横スクロール、スライドパネル全幅

**レガシーモジュール（未使用）:**
- **CashierRenderer** (`cashier-renderer.js`) — KDS統合時代のPOSレジUI
- **AccountingRenderer** (`accounting-renderer.js`) — 旧会計ビュー

---

### 8.4 ハンディPOS (`public/handy/`)

**エントリポイント:** `index.html`（ハンディ注文）、`pos-register.html`（POSレジ）

**handy-app.js（1315行）主要機能:**

| セクション | 機能 |
|-----------|------|
| **メニュー** | カテゴリフィルタ、品目タップ → オプションモーダル → カート追加 |
| **カート** | 数量変更、削除、折りたたみ表示 |
| **イートイン** | テーブル選択 → 注文送信 |
| **テイクアウト** | 顧客名、電話番号、受取時刻 → 注文送信 |
| **注文履歴** | 本日の注文一覧、ステータス表示、修正機能 |
| **テーブル状態** | フロアマップ表示、着席モーダル（プラン/コース選択）、メモ編集（プリセット+自由テキスト→PATCH保存） |
| **注文修正** | 既存注文の品目変更 → PATCH送信 |
| **限定バッジ** | `source === 'local'` の品目に左上「限定」バッジ（ピンク、position:absolute） |

**テーブルメモ編集（openMemoModal）:**
- テーブル状況タブの着席中テーブルカードに「メモ編集」ボタンを表示（着席キャンセルと横並び）
- タップ → 既存オプションモーダル（`els.optOverlay`）を再利用してメモ編集モーダルを表示
- プリセットタグ6種（アレルギー注意/VIP/お子様連れ/誕生日/接待/車椅子）+ 自由テキストtextarea
- プリセットタップ → テキストエリアに改行区切りで追記
- 保存 → `apiPatch('/store/table-sessions.php?id=xxx', { store_id, memo })` → toast + `loadTableStatus()` リフレッシュ
- `_lastTableData` にテーブルデータをキャッシュし、クリック時にsession.idで現在のメモを検索
- cloneNode + replaceChild パターンで保存ボタンのリスナー付け替え（着席モーダルと同パターン）

**品切れ即時反映:**
- `menu-version.php` を2秒ごとにポーリング（`checkMenuVersion`）
- バージョン変更時のみフルメニュー再取得（`refreshMenuSilent`）
- `fetch()` で直接実行（`apiFetch()` ではなく）+ `&_t=Date.now()` キャッシュバスター
- マルチ店舗選択オーバーレイ表示中はスキップ（`'overlay'` フラグ）

**画像パス:** `../../` + `item.imageUrl`（APIが返す `uploads/menu/xxx.jpg` をそのまま結合）

**テーマ:** インディゴダークテーマ

**呼び出し+品目完成+キッチン呼び出しアラート（handy-alert.js）:**
- DOM自己挿入パターン。`index.html` に `<script>` 追加のみで有効化
- `localStorage('mt_handy_store')` から store_id 取得（非同期セットを待つリトライ機構、最大30回×500ms）
- ヘッダー直後に赤バナーを挿入。5秒ポーリングで `GET /api/kds/call-alerts.php` を監視
- **3種アラート表示:**
  - `staff_call`: 🔔アイコン + 「T01 からの呼び出し：「理由」」+ 「対応済み」ボタン
  - `product_ready`: 🍳アイコン + 「T01 品目名」+ 緑ボーダー（`.handy-call-item--ready`）+ 「配膳完了」ボタン
  - `kitchen_call`: 👨‍🍳アイコン + 「キッチンから呼び出し」+ オレンジボーダー（`.handy-call-item--kitchen`）+ 「対応済み」ボタン
- **ビープ音（タイプ別3種）:**
  - `staff_call`（お客様呼び出し）: 高音2トーン（880Hz → 1100Hz）
  - `kitchen_call`（キッチン呼び出し）: 3連ビープ×2セット（880Hz→700Hz→880Hz を0.5秒間隔で2回、音量0.4）
  - `product_ready`（品目完成通知）: 低音2トーン（523Hz → 659Hz）
  - 混在時の優先順位: kitchen_call > staff_call > product_ready
- **バイブレーション（navigator.vibrate）:**
  - `kitchen_call`: 長パターン（200ms振動→100ms休止→200ms→100ms→200ms）
  - `staff_call`: 中パターン（200ms→100ms→200ms）
  - `product_ready`: 短パターン（150ms→80ms→150ms）
  - ※ iOSでは `navigator.vibrate()` 非対応のため動作しない
- **通知音プラットフォーム差異:**
  - iOS: 音のみ（起動時「🔔 タップして通知音を有効にする」バナータップ必須）
  - Android: 音 + バイブレーション
- **音声有効化バナー:** 起動時に画面下部にオレンジバナーを表示。タップでAudioContextアンロック+テスト音再生+バナー消去。モバイルブラウザのAutoPlay制限対策
- **AudioContextアンロック:** 起動時にtouchstart/clickイベントでAudioContextを事前作成・resume（バナータップ以外の画面操作でもアンロック）
- **配膳完了連動:** `product_ready` の「配膳完了」押下 → `PATCH call-alerts.php` → order_item を served に自動更新（全品目完了時は親注文も自動 served）

---

### 8.5 共有ユーティリティ (`public/shared/js/utils.js`)

```javascript
var Utils = {
  formatYen(n)           // → "¥1,234"
  escapeHtml(str)        // XSS防止エスケープ
  formatDateTime(dt)     // → "14:30"
  formatDate(dt)         // → "03/30"
  formatDuration(sec)    // → "5:30"
  getPresetRange(key)    // "today"|"yesterday"|"week"|"month" → {from, to}
};
```

### 8.6 共通モジュール (`public/js/`)

**session-monitor.js:**
- セッションアイドルタイムアウト（25分警告モーダル表示 → 30分自動ログアウト）
- `WARNING_MS = 25 * 60 * 1000` 経過で警告モーダル表示（「5分以内に操作がないとログアウトされます」「操作を続ける」ボタン）
- 「操作を続ける」押下で `/api/auth/check.php` を呼び出し `last_active_at` をリセット
- マウス移動・キー入力・タッチ・スクロールでアクティビティ検出しタイマーリセット
- サーバー側（`api/lib/auth.php` の `SESSION_IDLE_TIMEOUT = 1800` 秒）で30分無操作を判定
- 401レスポンス中に `SESSION_TIMEOUT` / `SESSION_REVOKED` を検出した場合、ログイン画面にリダイレクト
- 管理画面（dashboard.html, owner-dashboard.html）、KDS、レジ、ハンディに適用

**offline-detector.js:**
- `navigator.onLine` + `online`/`offline` イベントでネットワーク状態を監視
- オフライン時にヘッダー直下に赤バナー「オフラインです」を表示
- `OrderSender._savePendingOrder()` と連携し、オフライン中の注文を `localStorage(mt_pending_orders)` にキュー
- オンライン復帰時に `OrderSender._flushPendingOrders()` で自動リトライ

**theme-switcher.js:**
- ライト/ダークテーマ切替ボタンをヘッダーに自動挿入（`ThemeSwitcher.injectButton()`）
- `html.light-theme` クラスでCSS変数を上書き
- `localStorage(mt_theme)` で設定永続化
- KDS、レジ、ハンディ、管理画面に適用

---

## 9. データフロー

### 9.1 セルフオーダーフロー

```
顧客 QRスキャン
  │
  ▼
GET /customer/table-session?store_id=X&table_id=Y
  │ → テーブル確認
  │ → table_sessions にアクティブセッションがなければ自動着席（status=seated, session_token生成）
  │ → session_token取得、プラン/コース情報
  │ → ウェルカムモーダル表示
  ▼
GET /customer/menu?store_id=X (&plan_id=P | &course_id=C)
  │ → menu-resolver でメニュー統合取得
  │ → recommendations（今日のおすすめ）+ popularity（人気ランキング）も返却
  ▼
顧客がカートに品目追加（CartManager → localStorage）
  │ → POST /customer/cart-event（行動ログ）
  ▼
POST /customer/orders
  │ → 冪等キーチェック → バリデーション → orders INSERT
  │ → memo, allergen_selections も保存
  ▼
KDS ポーリング（3秒）
  │ GET /kds/orders?store_id=X&station_id=S
  ▼
キッチンスタッフがステータス更新
  │ PATCH /kds/update-status → pending→preparing→ready→served
  ▼
会計
  │ POST /kds/close-table or POST /store/process-payment
  │ → orders.status = paid
  │ → tables.session_token リセット（新トークン生成）
  │ → cash_log 記録
  │ → 在庫引落し（recipes テーブル参照）
  ▼
セッション終了検知（顧客側）
  │ menu.html が30秒間隔で GET /customer/validate-token を実行
  │ → トークン不一致（会計でリセット済み）を検出
  │ → 全ポーリング停止 + 「ご利用ありがとうございました」オーバーレイ表示
  ▼
完了
```

### 9.1.1 品切れ即時反映フロー

```
KDS品切れ画面 or 音声コマンド
  │
  ▼
PATCH /kds/sold-out.php → store_menu_overrides.is_sold_out 更新
  │                        (updated_at も自動更新)
  ▼
顧客メニュー (menu.html) ─── 2秒間隔バージョンチェック
  │ GET /store/menu-version.php?store_id=X
  │ → MAX(updated_at) を比較
  │ → 変更あり → GET /customer/menu.php でフルメニュー再取得
  │ → カート内品切れ品目を自動検出・削除・アラート表示
  ▼
ハンディ (handy-app.js) ─── 2秒間隔バージョンチェック（同じ仕組み）
  │ → raw fetch() + キャッシュバスター(&_t=Date.now())
  ▼
注文送信時（最終防衛ライン）
  │ POST /customer/orders.php → menu-resolver で品切れ再チェック
  │ → 品切れ品目あり → HTTP 409 SOLD_OUT エラー
  ▼
完了（約2秒以内にメニュー・ハンディに反映）
```

### 9.2 ハンディ注文フロー

```
デバイスアカウントでログインしたハンディ画面
  │
  ▼
メニュー選択 → カート構築
  │
  ├─ イートイン: テーブル選択 → POST /store/handy-order (order_type=handy)
  └─ テイクアウト: 顧客名+時刻 → POST /store/handy-order (order_type=takeout)
  │
  ▼
KDS に反映（ポーリング）
```

### 9.3 コースフロー

```
着席（POST /store/table-sessions）
  │ → course_id 指定 → フェーズ1注文を自動生成
  ▼
KDS にフェーズ1品目が表示
  │ → スタッフが調理→提供
  ▼
全品 served 完了
  │
  ├─ auto_fire_min = 0 → 即座にフェーズ2発火
  ├─ auto_fire_min = N → N分後にフェーズ2発火（ポーリング駆動）
  └─ auto_fire_min = NULL → 手動発火待ち
      │ → スタッフが POST /kds/advance-phase
  ▼
フェーズ2品目の注文が自動生成
  │ → KDS にフェーズ2品目が表示
  ▼
全フェーズ完了 → 会計
```

### 9.4 スマレジ注文同期フロー（L-15）

```
顧客 セルフオーダー注文確定
  │ POST /customer/orders.php
  ▼
注文レコード INSERT (orders)
  │
  ▼
sync_order_to_smaregi() ベストエフォート呼出（try-catch）
  │
  ├─ スマレジ未連携 → スキップ（注文は成功）
  ├─ 店舗マッピング未設定 → スキップ
  ├─ 商品マッピングなし → skipped_items としてスキップ
  │
  ▼
マッピング済み品目の金額計算
  │ subtotal = Σ(salesPrice × quantity)
  │ taxInclude = floor(subtotal / 110 * 10)
  │ terminalTranId = orderId先頭10文字
  ▼
POST /pos/transactions/temporaries（仮販売登録）
  │ フラット構造・全値文字列型
  │ transactionHeadDivision="1", status="0"
  ▼
成功 → orders.smaregi_transaction_id に取引ID保存
失敗 → error_log に記録（注文自体は成功）
```

---

## 10. デプロイ・運用

### 10.1 サーバー環境

| 項目 | 値 |
|------|-----|
| **ホスティング** | さくらインターネット共用サーバー |
| **Web サーバー** | nginx → Apache リバースプロキシ |
| **PHP** | 共用サーバー標準バージョン |
| **MySQL** | mysql80.odah.sakura.ne.jp |
| **DB名** | odah_eat-posla |
| **ドキュメントルート** | /home/odah/www/eat-posla/ |

### 10.2 デプロイ方法

- **手動FTPデプロイ**（ユーザーが実施）
- `curl -T` でFTPアップロードするとパーミッション `604` になりApacheが読めないため、**Python `ftplib`** を使用
- `.htaccess` は使わない（`mod_headers` 未対応の可能性、Apache 500→nginx 404キャッシュの原因）

### 10.3 DB初期セットアップ

```sql
-- 1. 基本テーブル作成
SOURCE schema.sql;

-- 2. デモデータ投入
SOURCE seed.sql;

-- 3. マイグレーション順次適用
SOURCE migration-a1-options.sql;
SOURCE seed-a1-options.sql;
SOURCE migration-a3-kds-stations.sql;
SOURCE seed-a3-kds-stations.sql;
SOURCE migration-a4-removed-items.sql;
SOURCE migration-b-phase-b.sql;
SOURCE migration-b2-plan-menu-items.sql;
SOURCE migration-b3-course-session.sql;
SOURCE migration-c4-payments.sql;
SOURCE migration-c5-inventory.sql;
SOURCE migration-c5-cart-events.sql;
-- migration-d1-ai-settings.sql は schema.sql に統合済のためスキップ可
-- 既存環境でai_api_keyカラムがない場合のみ実行:
-- SOURCE migration-d1-ai-settings.sql;
SOURCE migration-d2-places-api-key.sql;
SOURCE migration-e1-username.sql;
SOURCE migration-e2-override-image.sql;
SOURCE migration-e3-call-alerts.sql;
SOURCE migration-f1-token-expiry.sql;
SOURCE migration-f2-order-items.sql;
SOURCE migration-g1-audit-log.sql;
SOURCE migration-g2-user-sessions.sql;
SOURCE migration-h1-welcome-message.sql;
SOURCE migration-i1-order-memo-allergen.sql;
SOURCE migration-i2-menu-enhancements.sql;
SOURCE migration-i3-satisfaction-ratings.sql;
SOURCE migration-i4-table-memo.sql;
SOURCE migration-i5-last-order-kds-notify.sql;
SOURCE migration-l3-shift-management.sql;
SOURCE migration-l3b-gps-staff-control.sql;
SOURCE migration-l3b2-user-visible-tools.sql;
SOURCE migration-l3p2-labor-cost.sql;
SOURCE migration-l3p3-multi-store-shift.sql;

-- P1 プリリリースクリーンアップ
SOURCE migration-p1-6c-drop-tenants-ai-key.sql;       -- tenants.ai_api_key DROP
SOURCE migration-p1-22-drop-tenants-google-places-key.sql; -- tenants.google_places_api_key DROP
SOURCE migration-p1-11-subscription-events-unique.sql;     -- subscription_events.stripe_event_id UNIQUE
SOURCE migration-p1-6d-posla-admin-sessions.sql;           -- posla_admin_sessions 新設
SOURCE migration-p1-remove-square.sql;                     -- P1-2: payment_gateway ENUM から square 削除

-- P1-19 / P1-19b / P1-19c セルフメニュー「お会計」i18n + 領収書修正 + 文字化け修正
SOURCE migration-p119-checkout-i18n.sql;              -- ui_translations INSERT（アレルギー + gap キー × 4言語）
SOURCE migration-p119b-receipt-rollback.sql;          -- ui_translations DELETE（receipt_* 52行、領収書日本語固定化）
SOURCE migration-p119c-fix-encoding.sql;              -- ui_translations DELETE+INSERT（zh-Hans/ko 二重エンコード修正、SET NAMES utf8mb4 明示）

-- B-2026-04-08-09 照合順序ミスマッチ修正
SOURCE migration-p133-collation-fix.sql;              -- daily_recommendations/call_alerts/satisfaction_ratings を utf8mb4_unicode_ci に CONVERT

-- 4. テスト用2テナント目（任意）
SOURCE seed-tenant-momonoya.sql;

-- 5. 鳥丸グループ enterprise デモテナント（任意、5店舗）
SOURCE migration-p1-27-torimaru-demo-tenant.sql;

-- 6. デモ用テストユーザー（任意）
SOURCE migration-demo-test-users.sql;
```

### 10.4 マイグレーション安全性

全マイグレーション依存ファイル（PHP）にはテーブル存在チェックが実装されている:

```php
// テーブル未作成時のグレースフルデグレード
try {
    $pdo->query('SELECT 1 FROM table_name LIMIT 0');
} catch (PDOException $e) {
    // フォールバック処理
}
```

これにより、マイグレーション未適用のまま既存機能が動作し続ける。

### 10.5 セキュリティ対策

| 対策 | 実装 |
|------|------|
| **SQLインジェクション** | 全クエリでプリペアドステートメント使用 |
| **XSS** | `Utils.escapeHtml()` で全ユーザー入力をエスケープ |
| **CSRF** | SameSite=Lax Cookie + セッション認証 |
| **パスワード** | PHP `password_hash(PASSWORD_DEFAULT)` |
| **セッション固定** | ログイン時 `session_regenerate_id(true)` |
| **冪等性** | 注文送信に `idempotency_key` |
| **レート制限** | `order_rate_log` テーブル + `store_settings` 設定 |
| **ファイルアップロード** | MIME タイプ検証（finfo）、5MB制限、UUID ファイル名 |
| **安全なJSON解析** | `res.text()` → `JSON.parse()` パターン統一 |
| **エラーメッセージ** | `err.message` は全て `Utils.escapeHtml()` 経由で `innerHTML` に挿入 |
| **監査ログ** | 主要操作（ログイン/ログアウト/メニュー変更/設定変更等）を `audit_log` テーブルに記録 |
| **同時セッション制限** | ロール別上限（owner:5, manager:3, staff:2）。超過時は最古セッションを強制無効化 |
| **アイドルタイムアウト** | 25分無操作で警告モーダル表示 → 30分で自動ログアウト（`session-monitor.js` + `auth/check.php` + `api/lib/auth.php` の `SESSION_IDLE_TIMEOUT = 1800`） |
| **セッショントークン検証** | 顧客メニューの30秒ポーリングで会計後のトークン無効化を検出 |
| **例外観測チャネル（P1-12）** | `catch (\Exception $e) { }` 空ブロックを一掃し、42ファイル / 66箇所に `error_log('[P1-12][<path>:<line>] <snake_case_label>: ' . $e->getMessage(), 3, '/home/odah/log/php_errors.log')` を追加（Stage 1+2+3、High 50/50 + Medium 17/17 カバー）。Low 34件は `LIMIT 0` スキーマスニッフ等の設計意図ブロックのため対象外。発見実績: B-2026-04-08-12（turnover-report dead branch、Stage 2 で即座に検出）。`.user.ini` が Sakura mod_php で無効のため、`error_log()` 第3引数で絶対パスを直接指定する方式（A2）を採用 |
| **マイグレーション SQL charset（P1-19c）** | 全マイグレーション SQL の先頭に `SET NAMES utf8mb4;` を明示。MySQL クライアント接続のデフォルト charset が utf8mb4 でない環境での二重エンコード事故を防止 |

### 10.6 cronジョブ

| ジョブ | スクリプト | 推奨間隔 | 必須/任意 | 説明 |
|--------|-----------|----------|----------|------|
| **自動退勤** | `api/cron/auto-clock-out.php` | 5分間隔 | 任意 | `shift_settings.auto_clock_out_hours`（デフォルト12時間）を超過した勤務中レコードを自動退勤させる。打刻忘れ対策 |

**自動退勤 cron 設定例（さくらインターネット）:**

```
0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /home/odah/www/eat-posla/api/cron/auto-clock-out.php
```

**補足:**
- CLI実行専用（ブラウザからのアクセスは `php_sapi_name()` チェックで拒否）
- cron未設定でも運用可能。マネージャーが管理画面「勤怠一覧」から手動で退勤時刻を修正できる
- L-3（シフト管理）は proプラン以上で有効。`plan_features` に `shift_management` が存在しない店舗では勤怠APIが無効化されるため、cronが空振りしても問題ない

### 10.7 端末運用フロー

各端末で使用する画面を以下のように確定している:

| 端末 | 画面 | URL | 用途 |
|------|------|-----|------|
| **キッチン端末** | KDS | `kds/index.html` | 調理ステーション別カンバン |
| **レジ端末** | POSレジ | `kds/cashier.html` | 会計・決済・売上確認 |
| **ホール端末** | KDS | `kds/index.html` | ドリンクステーション |
| **事務所PC** | 管理画面 | `admin/dashboard.html` or `owner-dashboard.html` | メニュー管理・レポート・設定 |
| **ハンディ端末** | ハンディ | `handy/index.html` | 注文入力専用（レジ機能なし） |

**画面間リンク方針:**
- KDS → POSレジ: リンクなし（別端末の想定）
- ハンディ → レジ: リンク削除済（運用上不要）
- 管理画面 → KDS/レジ/ハンディ: ヘッダーにショートカットリンク（L-3b/L-3b2でスタッフ向け表示制御あり）
- 管理画面 → 顧客メニュー（注文画面）: ヘッダーにリンク。スタッフには非表示（ハンディで注文確認可能のため不要）
- 出退勤ボタン: スタッフのみヘッダーに表示。マネージャー/オーナーには非表示

### 10.8 既知の未解決バグ

2026-04-08 時点、既知の未解決バグはありません。

**2026-04-08 セッションで解決したバグ:**
- B-2026-04-08-01 owner/store dashboard 分離リファクタで本部メニュータブ消失 → P1-28
- B-2026-04-08-02 本部メニュータブに CSV I/O ボタン欠落 → P1-28b
- B-2026-04-08-03 enterprise 店舗側で本部マスタ項目が編集可能 → P1-29
- B-2026-04-08-04 enterprise 店舗側に追加/CSVインポート/一括翻訳/削除ボタンが残存 → P1-30
- B-2026-04-08-05 店舗メニュー CSV エクスポート HTTP 500（`FROM menu_items` typo）→ P1-30c
- B-2026-04-08-06 一括翻訳機能で再翻訳ができない（`force=true` 未送信）→ P1-31
- B-2026-04-08-07 カテゴリ名「スマレジインポート」が中国語化されない → P1-31b
- B-2026-04-08-08 `cart_events` テーブル未作成 → 全顧客カート操作 503 → P1-33（migration-c5-cart-events.sql 適用）
- B-2026-04-08-09 照合順序ミスマッチ 3テーブル → P1-33（migration-p133-collation-fix.sql）
- B-2026-04-08-10 matsunoya 渋谷店 manager パスワード Demo1234 不一致 → 手動リセット
- B-2026-04-08-11 `order_items` 孤立レコード 13件 → 対応不要判定（UI フォールバック `oi.item_name` で吸収）
- B-2026-04-08-12 `turnover-report.php` dead branch（`target_cook_time_sec` 未実装カラム参照）→ P1-12 Stage 2 が発見 → 該当 try-catch ブロック 13 行削除

---

## 付録

### A. シードデータ構成

**seed.sql:**
- テナント: 松の屋（matsunoya）
- 店舗: 渋谷店（shibuya）、新宿店（shinjuku）
- ユーザー: owner@matsunoya.com (owner), manager@shibuya.matsunoya.com (manager), staff@shibuya.matsunoya.com (staff)
- カテゴリ: 定食、丼物、カレー、一品料理、ドリンク
- メニューテンプレート: 10品以上のサンプルメニュー

**seed-b-courses-plans.sql:**
- コーステンプレート + フェーズサンプル
- 食べ放題プラン + プランメニューサンプル

**seed-c5-inventory.sql:**
- 食材マスタサンプル
- レシピ（BOM）サンプル

**seed-tenant-momonoya.sql:**
- テナント: 桃の屋（momonoya）
- 店舗: 池袋店、渋谷店
- マルチテナントテスト用

### B. 注文アイテムJSON形式

```json
// orders.items カラムの形式
[
  {
    "id": "menu-item-uuid",
    "name": "焼魚定食",
    "price": 980,
    "qty": 1,
    "options": [
      {
        "choiceId": "choice-uuid",
        "choiceName": "大盛り",
        "priceDiff": 100
      }
    ],
    "allergen_selections": ["egg", "milk"]
  }
]
// allergen_selections: order_items テーブルの allergen_selections カラム（JSON）
// 特定原材料7品目: egg, milk, wheat, shrimp, crab, buckwheat, peanut
```

### C. コースフェーズアイテムJSON形式

```json
// course_phases.items カラムの形式
[
  { "id": "item-id", "name": "前菜三種盛り", "name_en": "Appetizer Trio", "qty": 1 },
  { "id": "item-id", "name": "季節のスープ", "name_en": "Seasonal Soup", "qty": 1 }
]
```

### D. フロントエンド localStorage キー

| キー | 画面 | 内容 |
|------|------|------|
| `mt_selected_store` | admin | 選択中の店舗ID |
| `mt_kds_store` | KDS | KDS選択中の店舗ID |
| `mt_kds_station` | KDS | KDS選択中のステーションID（空文字=全品目）※会計は cashier.html に分離済み |
| `mt_kds_voice_active` | KDS/sold-out | 音声コマンドON状態（KDS ↔ sold-out.html 間で共有永続化） |
| `mt_kds_voice_ai_fallback` | KDS | 音声AI補助ON状態。端末ごとに保存し、初期値OFF |
| `mt_kds_voice_diag_v1` | KDS | 端末内の音声診断ログ。成功/失敗、final回数、自動リフレッシュ回数、エラー回数など |
| `kds_view_mode` | KDS | KDS表示モード（`normal` or `expeditor`） |
| `mt_handy_store` | handy | ハンディ選択中の店舗ID |
| `mt_cart_{store_id}_{table_id}` | customer | カートデータ（JSON）|
| `mt_theme` | 全画面 | テーマ設定（`light` or `dark`）|
| `mt_pending_orders` | customer | オフライン中の未送信注文キュー（JSON配列）|
| `mt_kds_voice_sensitivity` | KDS | 音声コマンド感度設定 |

---

*本ドキュメントは松の屋MTシステムのソースコード全体を解析して自動生成されました。*
