# 99. エラーカタログ（番号で AI に質問できます）

> **このページの使い方**
>
> POSLA で操作中にエラーが表示されたら、画面に出た **エラー番号 (`Exxxx`) または英字コード**を控えてください。
> AI ヘルプデスクで「**E2017 って何？**」「**`PIN_INVALID` の対処は？**」のように質問すると、
> 該当エラーの意味と推奨対応を回答します。
>
> 番号は 9 系統に分かれています。先頭の数字でおおまかな分野が分かります（例: `E3xxx` は認証関連）。

## 採番ルール

| 範囲 | カテゴリ |
|------|---------|
| `E1000`〜`E1999` | システム / インフラ |
| `E2000`〜`E2999` | 入力検証 |
| `E3000`〜`E3999` | 認証・認可 |
| `E4000`〜`E4999` | リソース未発見 |
| `E5000`〜`E5999` | 注文・KDS・テーブル |
| `E6000`〜`E6999` | 決済・返金・Stripe・サブスク |
| `E7000`〜`E7999` | メニュー・在庫 |
| `E8000`〜`E8999` | シフト・勤怠 |
| `E9000`〜`E9999` | 顧客・予約・テイクアウト・AI・外部連携 |

## 全エラー一覧 (239 件)


### E3xxx — 認証・認可

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E3001` | `ACCOUNT_DISABLED` | このアカウントは無効化されています |
| `E3002` | `ALREADY_ACTIVE` | このテーブルには既にアクティブなセッションがあります |
| `E3003` | `ALREADY_ONBOARDED` | オンボーディングは既に完了しています |
| `E3004` | `ALREADY_SETUP` | 初期セットアップは完了済みです |
| `E3005` | `BLACKLISTED_CUSTOMER` | 'この顧客は予約不可です: ' . ($bl['blacklist_reason'] ?: '') |
| `E3006` | `CANNOT_DELETE_SELF` | 自分自身は削除できません |
| `E3007` | `DUPLICATE_EMAIL` | このメールアドレスは既に登録されています |
| `E3008` | `DUPLICATE_USERNAME` | このユーザー名は既に使用されています |
| `E3009` | `EMAIL_TAKEN` | このメールアドレスは既に登録されています |
| `E3010` | `FORBIDDEN` | 本人確認に失敗しました |
| `E3011` | `FORBIDDEN_HQ_MENU` | 本部メニューは owner ロールでのみ編集できます |
| `E3012` | `GPS_REQUIRED` | 位置情報の取得が必要です。ブラウザの位置情報を許可してください |
| `E3013` | `INVALID_CREDENTIALS` | メールアドレスまたはパスワードが正しくありません |
| `E3014` | `INVALID_CURRENT_PASSWORD` | 現在のパスワードが正しくありません |
| `E3015` | `INVALID_PIN` | 担当 PIN は 4〜8 桁の数字で入力してください |
| `E3016` | `INVALID_ROLE` | role_hint は kitchen / hall のいずれかです |
| `E3017` | `INVALID_SESSION` | セッションが無効です |
| `E3018` | `INVALID_SIGNATURE` | Webhook署名の検証に失敗しました |
| `E3019` | `INVALID_STAFF` | required_staff は 1〜20 で指定してください |
| `E3020` | `INVALID_TOKEN` | 無効なトークンです |
| `E3021` | `INVALID_USERNAME` | ユーザー名は半角英数字・ハイフン・アンダースコア（3〜50文字）で入力してください |
| `E3022` | `INVALID_USERS` | 一部のスタッフがこの店舗に所属していません |
| `E3023` | `NO_ACTIVE_SESSION` | スタッフが着席操作を行うまでお待ちください。 |
| `E3024` | `PIN_INVALID` | PIN が一致しないか、対象スタッフが出勤中ではありません |
| `E3025` | `PIN_REQUIRED` | レジ金庫の操作には担当スタッフ PIN の入力が必要です |
| `E3026` | `SAME_PASSWORD` | 新しいパスワードは現在のパスワードと異なるものを設定してください |
| `E3027` | `SESSION_COOLDOWN` | お会計が完了しています。新しいご注文はスタッフにお声がけください。 |
| `E3028` | `SESSION_REVOKED` | セッションが無効化されました。再ログインしてください。 |
| `E3029` | `SESSION_TIMEOUT` | セッションがタイムアウトしました。再度ログインしてください。 |
| `E3030` | `STORE_INACTIVE` | 店舗が無効です |
| `E3031` | `TENANT_DISABLED` | このテナントは無効化されています |
| `E3032` | `TENANT_INACTIVE` | テナントが無効です |
| `E3033` | `TOKEN_ALREADY_USED` | このトークンは既に使用済みです |
| `E3034` | `TOKEN_EXPIRED` | このトークンは有効期限切れです |
| `E3035` | `UNAUTHORIZED` | POSLA管理者ログインが必要です |
| `E3036` | `USERNAME_TAKEN` | このユーザー名は既に使用されています |
| `E3037` | `USER_NOT_IN_STORE` | 指定されたユーザーはこの店舗に所属していません |
| `E3038` | `WEAK_PASSWORD` | パスワードは8文字以上で入力してください |
| `E3039` | `WEAK_PIN` | 同じ数字の繰り返しは PIN として使えません |

### E1xxx — システム / インフラ

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E1001` | `ACTIVATE_FAILED` | 有効化に失敗しました |
| `E1002` | `CART_LOG_FAILED` | カートログ記録に失敗しました |
| `E1003` | `CHECKOUT_FAILED` | $checkout['error'] ?: '不明' |
| `E1004` | `CREATE_FAILED` | ユーザー作成に失敗しました |
| `E1005` | `DB_ERROR` | 処理に失敗しました。時間を置いて再試行してください。 |
| `E1006` | `DELETE_FAILED` | 削除に失敗しました |
| `E1007` | `DEVICE_CREATION_FAILED` | デバイスアカウントの作成に失敗しました |
| `E1008` | `EMPTY_BODY` | リクエストボディが空です |
| `E1009` | `EMPTY_CSV` | CSVが空です |
| `E1010` | `FETCH_FAILED` | Geocoding APIの呼び出しに失敗しました |
| `E1011` | `FILE_READ_ERROR` | ファイルの読み込みに失敗しました |
| `E1012` | `FILE_TOO_LARGE` | ファイルサイズは5MB以下にしてください |
| `E1013` | `GENERATE_FAILED` | '領収書の作成に失敗しました: ' . $e->getMessage() |
| `E1014` | `IMPORT_FAILED` | 'インポートに失敗しました: ' . $e->getMessage() |
| `E1015` | `METHOD_NOT_ALLOWED` | POST のみ対応しています |
| `E1016` | `MIGRATION` | PIN 機能のマイグレーションが未適用です (migration-cp1-cashier-pin.sql / migration-l3-shift-management.sql) |
| `E1017` | `MIGRATION_REQUIRED` | この機能にはデータベースの更新が必要です |
| `E1018` | `MISSING_COLUMN` | 必須カラム "' . $req . '" がありません |
| `E1019` | `NO_FILE` | CSVファイルをアップロードしてください |
| `E1020` | `PARSE_FAILED` | Geocoding APIレスポンスの解析に失敗しました |
| `E1021` | `RATE_LIMITED` | リクエスト回数の上限に達しました。しばらくしてからお試しください。 |
| `E1022` | `SAVE_FAILED` | ファイルの保存に失敗しました |
| `E1023` | `SEAT_FAILED` | '着席処理に失敗しました: ' . $e->getMessage() |
| `E1024` | `SEND_FAILED` | $result['error'] ?: '送信失敗' |
| `E1025` | `SERVER_ERROR` | 設定の更新に失敗しました |
| `E1026` | `SYNC_FAILED` | 紐付けの保存に失敗しました |
| `E1027` | `UPDATE_FAILED` | アラートの更新に失敗しました |
| `E1028` | `VERIFICATION_FAILED` | 決済の確認に失敗しました |

### E4xxx — リソース未発見

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E4001` | `ADMIN_NOT_FOUND` | 管理者が見つかりません |
| `E4002` | `COURSE_NOT_FOUND` | コースが見つかりません |
| `E4003` | `CUSTOMER_NOT_FOUND` | 顧客が見つかりません |
| `E4004` | `ITEM_NOT_FOUND` | 品目が見つかりません |
| `E4005` | `NOT_FOUND` | メニューが見つかりません |
| `E4006` | `NO_COURSE_SESSION` | アクティブなコースセッションが見つかりません |
| `E4007` | `NO_UNPAID_ORDERS` | 未払いの注文がありません |
| `E4008` | `ORDER_NOT_FOUND` | 注文が見つかりません |
| `E4009` | `PAYMENT_NOT_FOUND` | 支払い情報が見つかりません |
| `E4010` | `PLAN_NOT_FOUND` | プランが見つかりません |
| `E4011` | `RECEIPT_NOT_FOUND` | 領収書が見つかりません |
| `E4012` | `RESERVATION_NOT_FOUND` | 予約が見つかりません |
| `E4013` | `SESSION_NOT_ACTIVE` | アクティブなセッションが見つかりません |
| `E4014` | `STORE_NOT_FOUND` | 店舗が見つかりません |
| `E4015` | `TABLE_NOT_FOUND` | テーブルが見つかりません |
| `E4016` | `TENANT_NOT_FOUND` | テナントが見つかりません |
| `E4017` | `USER_NOT_FOUND` | ユーザーが見つかりません |

### E9xxx — 顧客・予約・テイクアウト・AI・外部連携

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E9001` | `AI_CHAT_DISABLED` | AI予約は無効です |
| `E9002` | `AI_ERROR` | $msg |
| `E9003` | `AI_FAILED` | AIの応答に失敗しました |
| `E9004` | `AI_NOT_CONFIGURED` | AI機能が設定されていません |
| `E9005` | `AI_PARSE_FAILED` | AI応答を解釈できませんでした |
| `E9006` | `CANCEL_DEADLINE_PASSED` | 変更締切時刻を過ぎています |
| `E9007` | `CANNOT_RESERVE` | この番号からは予約できません。お電話で店舗へお問い合わせください |
| `E9008` | `GEMINI_ERROR` | $geminiJson['error']['message'] ?? 'AI APIエラー' |
| `E9009` | `GEMINI_NETWORK` | 'AI通信エラー: ' . $curlError |
| `E9010` | `GEMINI_PARSE` | AIレスポンスの解析に失敗しました |
| `E9011` | `KNOWLEDGE_BASE_MISSING` | ヘルプデスク knowledge base が未生成です。POSLA運営に連絡してください |
| `E9012` | `LEAD_TIME_VIOLATION` | 予約締切時刻を過ぎています |
| `E9013` | `NOT_AVAILABLE` | 事前チェックインできない状態です |
| `E9014` | `PICKUP_TOO_EARLY` | 受取時間は現在から' . $minPrepMinutes . '分以降を指定してください |
| `E9015` | `RESERVATION_DISABLED` | オンライン予約は受け付けていません |
| `E9016` | `SAME_STORE` | 自店舗には要請できません |
| `E9017` | `SELF_CHECKOUT_DISABLED` | セルフレジは有効になっていません |
| `E9018` | `SLOT_FULL` | この時間枠は満席です。別の時間を選択してください |
| `E9019` | `SLOT_UNAVAILABLE` | お選びの時間は満席です。別の時間をお試しください |
| `E9020` | `SMAREGI_API_ERROR` | $errMsg . ' (HTTP=' . $result['status'] . ' detail=' . $detail . ')' |
| `E9021` | `SMAREGI_NOT_CONFIGURED` | スマレジ Client ID が未設定です。POSLA管理者に連絡してください。 |
| `E9022` | `TAKEOUT_DISABLED` | 現在テイクアウトは受け付けていません |

### E8xxx — シフト・勤怠

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E8001` | `ALREADY_CLOCKED_IN` | 既に出勤中です（' . $existing['clock_in'] . '〜） |
| `E8002` | `INVALID_BREAK` | break_minutes は 0〜480 の範囲で指定してください |
| `E8003` | `NOT_CLOCKED_IN` | 出勤打刻がありません |
| `E8004` | `NO_ASSIGNMENTS` | user_assignments を指定してください |
| `E8005` | `NO_MAPPING` | この店舗はスマレジ店舗にマッピングされていません |
| `E8006` | `NO_PHASE` | コースにフェーズが設定されていません |
| `E8007` | `NO_STAFF` | 派遣するスタッフを選択してください |
| `E8008` | `NO_STORES` | 店舗を1つ以上登録してください |

### E5xxx — 注文・KDS・テーブル

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E5001` | `ALREADY_FINAL` | この予約は既に確定状態です |
| `E5002` | `COURSE_PARTY_MISMATCH` | このコースの対象人数外です |
| `E5003` | `DEVICE_NOT_ASSIGNABLE` | デバイスアカウント(' . $deviceRows[0]['display_name'] . ')はシフト割当の対象外です |
| `E5004` | `EMPTY_CART` | カートが空です |
| `E5005` | `INVALID_AVAILABILITY` | availabilities[$i].availability は available / preferred / unavailable のいずれかです |
| `E5006` | `INVALID_STATUS` | 無効なステータスです |
| `E5007` | `INVALID_TABLE` | テーブルが店舗に属していません |
| `E5008` | `INVALID_TABLE_IDS` | 指定テーブルが店舗に属していません |
| `E5009` | `LAST_ORDER_PASSED` | 現在ラストオーダーのため注文を受け付けておりません |
| `E5010` | `NO_ITEMS` | 注文に品目がありません |
| `E5011` | `NO_TABLE_ASSIGNED` | 着席先テーブルが指定されていません |
| `E5012` | `SESSION_ORDER_LIMIT` | このセッションの注文上限に達しました。スタッフにお声がけください。 |
| `E5013` | `SOLD_OUT` | implode('、', $soldOutNames) . 'は品切れです。カートから削除してください。' |
| `E5014` | `SUB_SESSION_LIMIT` | サブセッションの上限（10）に達しています |
| `E5015` | `TABLE_CAPACITY_SHORT` | 指定テーブルの収容人数が不足です |
| `E5016` | `TABLE_IN_USE` | テーブルは使用中です |
| `E5017` | `TABLE_OCCUPIED` | テーブルは既に使用中です |

### E1xxx — システム / インフラ (未分類)

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E1001` | `ALREADY_PAID` | 指定された品目には既に支払済みのものが含まれています |
| `E1002` | `AMOUNT_MISMATCH` | 金額がサーバー側計算値と一致しません |
| `E1003` | `INVALID_ITEM` | 存在しないメニュー品目が含まれています |
| `E1004` | `INVALID_OPTION` | 存在しないオプションが含まれています |
| `E1005` | `INVALID_QUANTITY` | 数量が不正です |
| `E1006` | `STRIPE_MISMATCH` | 決済情報が一致しません (metadata 不足) |

### E6xxx — 決済・返金・Stripe・サブスク

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E6001` | `ALREADY_REFUNDED` | この決済は既に返金処理中または返金済みです |
| `E6002` | `ALREADY_SUBSCRIBED` | 既にサブスクリプションがあります。Customer Portal から変更してください |
| `E6003` | `CASH_REFUND` | 現金決済はシステム返金できません。手動で返金してください。 |
| `E6004` | `CONNECT_NOT_CONFIGURED` | Stripe Connect が設定されていません |
| `E6005` | `DEPOSIT_CHECKOUT_FAILED` | '予約金決済の準備に失敗しました: ' . ($checkout['error'] ?: 'unknown') |
| `E6006` | `GATEWAY_ERROR` | '決済に失敗しました: ' . ($gwResult['error'] ?? '不明なエラー') |
| `E6007` | `GATEWAY_NOT_CONFIGURED` | Stripe が設定されていません |
| `E6008` | `INVALID_PLAN` | プランが不正です |
| `E6009` | `NOT_CONNECTED` | Stripe Terminal が利用できる設定がありません |
| `E6010` | `NO_DEPOSIT` | この予約に予約金は不要です |
| `E6011` | `NO_GATEWAY` | 決済ゲートウェイが設定されていません |
| `E6012` | `NO_SUBSCRIPTION` | サブスクリプションが未設定です。先にCheckoutを完了してください |
| `E6013` | `ONLINE_PAYMENT_REQUIRED` | テイクアウトはオンライン決済のみご利用いただけます |
| `E6014` | `PAYMENT_GATEWAY_ERROR` | Stripe設定が見つかりません |
| `E6015` | `PAYMENT_NOT_AVAILABLE` | 決済処理に失敗しました。お手数ですが店舗にお問い合わせください |
| `E6016` | `PAYMENT_NOT_CONFIGURED` | オンライン決済が設定されていません |
| `E6017` | `PAYMENT_NOT_CONFIRMED` | $result['error'] ?: '決済が確認できませんでした' |
| `E6018` | `PLAN_REQUIRED` | シフト管理はProプラン以上で利用できます |
| `E6019` | `PLATFORM_KEY_MISSING` | プラットフォームの Stripe キーが設定されていません |
| `E6020` | `PRICE_NOT_CONFIGURED` | 料金プラン設定が未完了です |
| `E6021` | `RECEIPT_EXPIRED` | 領収書の表示期限が切れています。スタッフにお声がけください |
| `E6022` | `REFUND_FAILED` | '返金に失敗しました: ' . ($refundResult['error'] ?? '不明なエラー') |
| `E6023` | `STRIPE_ERROR` | $createResult['error'] |
| `E6024` | `STRIPE_NOT_CONFIGURED` | プラットフォームのStripe APIキーが設定されていません |

### E2xxx — 入力検証

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E2001` | `AMOUNT_EXCEEDED` | 金額が上限（¥' . number_format($maxAmount) . '）を超えています |
| `E2002` | `EMAIL_REQUIRED` | メールアドレスを入力してください |
| `E2003` | `INVALID_ACTION` | actionパラメータが不正です |
| `E2004` | `INVALID_AMOUNT` | 合計金額が不正です |
| `E2005` | `INVALID_CATEGORY` | 無効なカテゴリです |
| `E2006` | `INVALID_COLOR` | カラーコードは #RRGGBB 形式で入力してください |
| `E2007` | `INVALID_COUNT` | 必要人数は1〜10の範囲で指定してください |
| `E2008` | `INVALID_DATE` | start_date, end_date を YYYY-MM-DD 形式で指定してください |
| `E2009` | `INVALID_DATETIME` | 日時を解釈できません |
| `E2010` | `INVALID_DAY` | day_of_week は 0（日）〜 6（土）で指定してください |
| `E2011` | `INVALID_EMAIL` | メールアドレスが不正です |
| `E2012` | `INVALID_FORMAT` | 許可されていないファイル形式です（JPEG/PNG/WebP/GIF のみ） |
| `E2013` | `INVALID_INPUT` | mappings 配列が必要です |
| `E2014` | `INVALID_JSON` | リクエストのJSONが不正です |
| `E2015` | `INVALID_KIND` | kind は staff または device のみ指定可能です |
| `E2016` | `INVALID_NAME` | テンプレート名は1〜100文字で入力してください |
| `E2017` | `INVALID_PARTY_SIZE` | 人数が範囲外です |
| `E2018` | `INVALID_PAYLOAD` | イベントデータが不正です |
| `E2019` | `INVALID_PERIOD` | period は weekly / monthly のいずれかです |
| `E2020` | `INVALID_PHONE` | 電話番号が不正です |
| `E2021` | `INVALID_RATING` | 評価は1〜5の整数で指定してください |
| `E2022` | `INVALID_REG_NUMBER` | 登録番号は T + 13桁の数字で入力してください（例: T1234567890123） |
| `E2023` | `INVALID_SHAPE` | 無効なshapeです（' . implode('/', $allowedShapes) . '） |
| `E2024` | `INVALID_SLUG` | スラッグは英小文字・数字・ハイフンのみ使用可能です |
| `E2025` | `INVALID_SOURCE` | source は "template" または "local" です |
| `E2026` | `INVALID_STORE` | 送信先店舗が見つかりません |
| `E2027` | `INVALID_TIME` | 時刻は HH:MM 形式で指定してください |
| `E2028` | `INVALID_TIME_RANGE` | 終了時刻は開始時刻より後にしてください |
| `E2029` | `INVALID_TRANSITION` | $current . ' から ' . $newStatus . ' への遷移はできません' |
| `E2030` | `INVALID_TYPE` | 無効な種別です |
| `E2031` | `INVALID_VALUE` | $key . ' は 1 以上' |
| `E2032` | `INVALID_VISIBLE_TOOLS` | visible_tools に不正な値: ' . $p . ' (kds/register/handy のみ可) |
| `E2033` | `MAX_AMOUNT_EXCEEDED` | 金額上限を超えています。店員をお呼びください。 |
| `E2034` | `MAX_ITEMS_EXCEEDED` | 1回の注文は' . $maxItems . '品までです。店員をお呼びください。 |
| `E2035` | `MISSING_ADDRESS` | 住所は必須です |
| `E2036` | `MISSING_ALERT_ID` | alert_id は必須です |
| `E2037` | `MISSING_EMAIL` | メールアドレスは必須です |
| `E2038` | `MISSING_FIELDS` | store_id, table_id, session_token は必須です |
| `E2039` | `MISSING_ID` | idが必要です |
| `E2040` | `MISSING_MESSAGE` | メッセージを入力してください |
| `E2041` | `MISSING_NAME` | テナント名は必須です |
| `E2042` | `MISSING_ORDER` | order_idが必要です |
| `E2043` | `MISSING_ORDER_ID` | order_id は必須です |
| `E2044` | `MISSING_PARAM` | id と store_id が必要です |
| `E2045` | `MISSING_PARAMS` | idとstore_idが必要です |
| `E2046` | `MISSING_PASSWORD` | パスワードは必須です |
| `E2047` | `MISSING_PAYMENT` | payment_id は必須です |
| `E2048` | `MISSING_PHONE` | 電話番号が必要です |
| `E2049` | `MISSING_PICKUP` | 受取時間を選択してください |
| `E2050` | `MISSING_PLAN` | plan_idが必要です |
| `E2051` | `MISSING_PROMPT` | プロンプトが空です |
| `E2052` | `MISSING_SESSION` | session_idが必要です |
| `E2053` | `MISSING_SLUG` | slugは必須です |
| `E2054` | `MISSING_STATUS` | statusが必要です |
| `E2055` | `MISSING_STORE` | store_id は必須です |
| `E2056` | `MISSING_TOKEN` | signup_token が必要です |
| `E2057` | `MISSING_TO_STORE` | 送信先店舗を指定してください |
| `E2058` | `MISSING_USER` | user_id を指定してください |
| `E2059` | `NO_API_KEY` | Google Places APIキーが設定されていません（POSLA管理画面で設定してください） |
| `E2060` | `NO_CHANGES` | 更新項目がありません |
| `E2061` | `NO_DATA` | availabilities 配列を指定してください |
| `E2062` | `NO_EMAIL` | メールアドレスが登録されていません |
| `E2063` | `NO_FIELDS` | 更新項目がありません |
| `E2064` | `NO_REG_NUMBER` | 適格簡易請求書の発行には登録番号の設定が必要です |
| `E2065` | `OUT_OF_RANGE` | 店舗から' . round($dist) . 'm離れています（許容: ' . $gpsSetting['gps_radius_meters'] . 'm） |
| `E2066` | `PAST_DATE` | 過去日は予約できません |
| `E2067` | `PHONE_REQUIRED` | 電話番号を入力してください |
| `E2068` | `TEXT_TOO_LONG` | 入力が長すぎます |
| `E2069` | `TOO_EARLY` | 予約時刻 30 分前から利用できます |
| `E2070` | `TOO_FAR` | 受付期間を超えています |
| `E2071` | `TOO_MANY` | 一度に提出できるのは最大31日分です |
| `E2072` | `TOO_MANY_ITEMS` | 品数が上限（' . $maxItems . '品）を超えています |
| `E2073` | `VALIDATION` | store_id は必須です |

### E7xxx — メニュー・在庫

| 番号 | コード | メッセージ |
|------|--------|-----------|
| `E7001` | `CONFLICT` | この注文は既に他の操作で確定されました。画面を更新してご確認ください。 |
| `E7002` | `DUPLICATE` | この原材料は既にレシピに登録されています |
| `E7003` | `DUPLICATE_CODE` | このテーブルコードは既に使用されています |
| `E7004` | `DUPLICATE_SLUG` | このスラッグは既に使用されています |
| `E7005` | `HAS_REFERENCES` | メニューが紐付いているカテゴリは削除できません |

## AI ヘルプデスクへの質問例

- 「**E2017 とは？**」 → 該当する操作と対処を回答
- 「**`MISSING_STORE` はどう直す？**」 → 同上
- 「**E3015 が出ました**」 → 認証系エラーとして対処手順を案内

番号がうまく見つからない場合は、画面に出た**エラーメッセージそのまま**を貼り付けて質問してください。
