# 99. エラーカタログ（POSLA 運営用 / 全 233 件）

POSLA サポート対応用のエラー一覧。テナントから「コード `MISSING_STORE` が出る」「`E2017` が出る」と問い合わせがあった際の引き当て表として使う。

- 番号付与は `scripts/generate_error_catalog.py` で自動生成 (元データ: `scripts/output/error-audit.tsv`)
- PHP 側のレジストリは `api/lib/error-codes.php` (`get_error_no()` / `get_error_code_by_no()`)
- Phase B で `json_error()` レスポンスに `errorNo` を含める計画

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

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E3001` | `ACCOUNT_DISABLED` | 403 | このアカウントは無効化されています | 1 | api/auth/login.php |
| `E3002` | `ALREADY_ACTIVE` | 409 | このテーブルには既にアクティブなセッションがあります | 1 | api/store/table-sessions.php |
| `E3003` | `ALREADY_ONBOARDED` | 400 | オンボーディングは既に完了しています | 1 | api/connect/onboard.php |
| `E3004` | `ALREADY_SETUP` | 403 | 初期セットアップは完了済みです | 1 | api/posla/setup.php |
| `E3005` | `BLACKLISTED_CUSTOMER` | 403 | 'この顧客は予約不可です: ' . ($bl['blacklist_reason'] ?: '') | 1 | api/store/reservations.php |
| `E3006` | `CANNOT_DELETE_SELF` | 400 | 自分自身は削除できません | 2 | api/owner/users.php, api/store/staff-management.php |
| `E3007` | `DUPLICATE_EMAIL` | 409 | このメールアドレスは既に登録されています | 2 | api/owner/users.php, api/store/staff-management.php |
| `E3008` | `DUPLICATE_USERNAME` | 409 | このユーザー名は既に使用されています | 4 | api/owner/users.php, api/store/staff-management.php |
| `E3009` | `EMAIL_TAKEN` | 409 | このメールアドレスは既に登録されています | 1 | api/signup/register.php |
| `E3010` | `FORBIDDEN` | 403 | 本人確認に失敗しました | 36 | api/customer/reservation-cancel.php, api/customer/reservation-deposit-checkout.php, api/customer/reservation-detail.php …他15 |
| `E3011` | `FORBIDDEN_HQ_MENU` | 403 | 本部メニューは owner ロールでのみ編集できます | 3 | api/owner/menu-templates.php |
| `E3012` | `GPS_REQUIRED` | 400 | 位置情報の取得が必要です。ブラウザの位置情報を許可してください | 2 | api/store/shift/attendance.php |
| `E3013` | `INVALID_CREDENTIALS` | 401 | メールアドレスまたはパスワードが正しくありません | 2 | api/auth/login.php, api/posla/login.php |
| `E3014` | `INVALID_CURRENT_PASSWORD` | 401 | 現在のパスワードが正しくありません | 2 | api/auth/change-password.php, api/posla/change-password.php |
| `E3015` | `INVALID_PIN` | 400 | 担当 PIN は 4〜8 桁の数字で入力してください | 5 | api/customer/table-session.php, api/kds/cash-log.php, api/store/process-payment.php …他2 |
| `E3016` | `INVALID_ROLE` | 400 | role_hint は kitchen / hall のいずれかです | 6 | api/owner/users.php, api/store/shift/assignments.php, api/store/shift/help-requests.php …他1 |
| `E3017` | `INVALID_SESSION` | 403 | セッションが無効です | 14 | api/customer/checkout-confirm.php, api/customer/checkout-session.php, api/customer/get-bill.php …他4 |
| `E3018` | `INVALID_SIGNATURE` | 400 | Webhook署名の検証に失敗しました | 1 | api/subscription/webhook.php |
| `E3019` | `INVALID_STAFF` | 400 | required_staff は 1〜20 で指定してください | 2 | api/store/shift/templates.php |
| `E3020` | `INVALID_TOKEN` | 404 | 無効なトークンです | 2 | api/auth/device-register.php, api/signup/activate.php |
| `E3021` | `INVALID_USERNAME` | 400 | ユーザー名は半角英数字・ハイフン・アンダースコア（3〜50文字）で入力してください | 5 | api/owner/users.php, api/signup/register.php, api/store/staff-management.php |
| `E3022` | `INVALID_USERS` | 400 | 一部のスタッフがこの店舗に所属していません | 1 | api/store/shift/help-requests.php |
| `E3023` | `NO_ACTIVE_SESSION` | 403 | スタッフが着席操作を行うまでお待ちください。 | 1 | api/customer/table-session.php |
| `E3024` | `PIN_INVALID` | 401 | PIN が一致しないか、対象スタッフが出勤中ではありません | 3 | api/kds/cash-log.php, api/store/process-payment.php, api/store/refund-payment.php |
| `E3025` | `PIN_REQUIRED` | 400 | レジ金庫の操作には担当スタッフ PIN の入力が必要です | 4 | api/customer/table-session.php, api/kds/cash-log.php, api/store/process-payment.php …他1 |
| `E3026` | `SAME_PASSWORD` | 400 | 新しいパスワードは現在のパスワードと異なるものを設定してください | 2 | api/auth/change-password.php, api/posla/change-password.php |
| `E3027` | `SESSION_COOLDOWN` | 403 | お会計が完了しています。新しいご注文はスタッフにお声がけください。 | 1 | api/customer/table-session.php |
| `E3028` | `SESSION_REVOKED` | 401 | セッションが無効化されました。再ログインしてください。 | 1 | api/lib/auth.php |
| `E3029` | `SESSION_TIMEOUT` | 401 | セッションがタイムアウトしました。再度ログインしてください。 | 1 | api/lib/auth.php |
| `E3030` | `STORE_INACTIVE` | 403 | 店舗が無効です | 1 | api/auth/device-register.php |
| `E3031` | `TENANT_DISABLED` | 403 | このテナントは無効化されています | 1 | api/auth/login.php |
| `E3032` | `TENANT_INACTIVE` | 403 | テナントが無効です | 1 | api/auth/device-register.php |
| `E3033` | `TOKEN_ALREADY_USED` | 409 | このトークンは既に使用済みです | 1 | api/auth/device-register.php |
| `E3034` | `TOKEN_EXPIRED` | 410 | このトークンは有効期限切れです | 1 | api/auth/device-register.php |
| `E3035` | `UNAUTHORIZED` | 401 | POSLA管理者ログインが必要です | 2 | api/lib/auth.php, api/posla/auth-helper.php |
| `E3036` | `USERNAME_TAKEN` | 409 | このユーザー名は既に使用されています | 1 | api/signup/register.php |
| `E3037` | `USER_NOT_IN_STORE` | 400 | 指定されたユーザーはこの店舗に所属していません | 2 | api/store/shift/apply-ai-suggestions.php, api/store/shift/assignments.php |
| `E3038` | `WEAK_PASSWORD` | 400 | パスワードは8文字以上で入力してください | 3 | api/lib/password-policy.php |
| `E3039` | `WEAK_PIN` | 400 | 同じ数字の繰り返しは PIN として使えません | 2 | api/store/set-cashier-pin.php |

### E1xxx — システム / インフラ

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E1001` | `ACTIVATE_FAILED` | 500 | 有効化に失敗しました | 1 | api/signup/activate.php |
| `E1002` | `CART_LOG_FAILED` | 503 | カートログ記録に失敗しました | 1 | api/customer/cart-event.php |
| `E1003` | `CHECKOUT_FAILED` | 503 | $checkout['error'] ?: '不明' | 2 | api/customer/checkout-session.php, api/customer/reservation-deposit-checkout.php |
| `E1004` | `CREATE_FAILED` | 500 | ユーザー作成に失敗しました | 2 | api/owner/users.php, api/store/staff-management.php |
| `E1005` | `DB_ERROR` | 500 | 処理に失敗しました。時間を置いて再試行してください。 | 17 | api/customer/reservation-create.php, api/customer/satisfaction-rating.php, api/customer/takeout-orders.php …他11 |
| `E1006` | `DELETE_FAILED` | 500 | 削除に失敗しました | 6 | api/owner/ingredients.php, api/owner/menu-templates.php, api/owner/option-groups.php …他3 |
| `E1007` | `DEVICE_CREATION_FAILED` | 500 | デバイスアカウントの作成に失敗しました | 1 | api/auth/device-register.php |
| `E1008` | `EMPTY_BODY` | 400 | リクエストボディが空です | 2 | api/lib/response.php, api/subscription/webhook.php |
| `E1009` | `EMPTY_CSV` | 400 | CSVが空です | 8 | api/owner/ingredients-csv.php, api/owner/menu-csv.php, api/owner/recipes-csv.php …他5 |
| `E1010` | `FETCH_FAILED` | 502 | Geocoding APIの呼び出しに失敗しました | 3 | api/store/ai-generate.php, api/store/places-proxy.php |
| `E1011` | `FILE_READ_ERROR` | 500 | ファイルの読み込みに失敗しました | 8 | api/owner/ingredients-csv.php, api/owner/menu-csv.php, api/owner/recipes-csv.php …他5 |
| `E1012` | `FILE_TOO_LARGE` | 400 | ファイルサイズは5MB以下にしてください | 2 | api/owner/upload-image.php, api/store/upload-image.php |
| `E1013` | `GENERATE_FAILED` | 500 | '領収書の作成に失敗しました: ' . $e->getMessage() | 1 | api/store/receipt.php |
| `E1014` | `IMPORT_FAILED` | 500 | 'インポートに失敗しました: ' . $e->getMessage() | 8 | api/owner/ingredients-csv.php, api/owner/menu-csv.php, api/owner/recipes-csv.php …他5 |
| `E1015` | `METHOD_NOT_ALLOWED` | 405 | POST のみ対応しています | 2 | api/lib/response.php, api/subscription/webhook.php |
| `E1016` | `MIGRATION` | 500 | PIN 機能のマイグレーションが未適用です (migration-cp1-cashier-pin.sql / migration-l3-shift-management.sql) | 13 | api/kds/cash-log.php, api/owner/ingredients-csv.php, api/owner/ingredients.php …他9 |
| `E1017` | `MIGRATION_REQUIRED` | 500 | この機能にはデータベースの更新が必要です | 12 | api/auth/device-register.php, api/store/course-phases.php, api/store/course-templates.php …他8 |
| `E1018` | `MISSING_COLUMN` | 400 | 必須カラム "' . $req . '" がありません | 8 | api/owner/ingredients-csv.php, api/owner/menu-csv.php, api/owner/recipes-csv.php …他5 |
| `E1019` | `NO_FILE` | 400 | CSVファイルをアップロードしてください | 10 | api/owner/ingredients-csv.php, api/owner/menu-csv.php, api/owner/recipes-csv.php …他7 |
| `E1020` | `PARSE_FAILED` | 502 | Geocoding APIレスポンスの解析に失敗しました | 3 | api/store/ai-generate.php, api/store/places-proxy.php |
| `E1021` | `RATE_LIMITED` | 429 | リクエスト回数の上限に達しました。しばらくしてからお試しください。 | 1 | api/lib/rate-limiter.php |
| `E1022` | `SAVE_FAILED` | 500 | ファイルの保存に失敗しました | 2 | api/owner/upload-image.php, api/store/upload-image.php |
| `E1023` | `SEAT_FAILED` | 500 | '着席処理に失敗しました: ' . $e->getMessage() | 1 | api/store/reservation-seat.php |
| `E1024` | `SEND_FAILED` | 500 | $result['error'] ?: '送信失敗' | 1 | api/store/reservation-notify.php |
| `E1025` | `SERVER_ERROR` | 500 | 設定の更新に失敗しました | 1 | api/store/settings.php |
| `E1026` | `SYNC_FAILED` | 400 | 紐付けの保存に失敗しました | 3 | api/owner/option-groups.php, api/smaregi/sync-order.php |
| `E1027` | `UPDATE_FAILED` | 500 | アラートの更新に失敗しました | 2 | api/kds/call-alerts.php, api/owner/users.php |
| `E1028` | `VERIFICATION_FAILED` | 500 | 決済の確認に失敗しました | 1 | api/customer/takeout-payment.php |

### E4xxx — リソース未発見

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E4001` | `ADMIN_NOT_FOUND` | 404 | 管理者が見つかりません | 1 | api/posla/change-password.php |
| `E4002` | `COURSE_NOT_FOUND` | 404 | コースが見つかりません | 3 | api/customer/menu.php, api/customer/reservation-create.php, api/store/table-sessions.php |
| `E4003` | `CUSTOMER_NOT_FOUND` | 404 | 顧客が見つかりません | 1 | api/store/reservation-customers.php |
| `E4004` | `ITEM_NOT_FOUND` | 404 | 品目が見つかりません | 1 | api/kds/update-item-status.php |
| `E4005` | `NOT_FOUND` | 404 | メニューが見つかりません | 68 | api/connect/disconnect.php, api/customer/menu.php, api/customer/reservation-cancel.php …他37 |
| `E4006` | `NO_COURSE_SESSION` | 404 | アクティブなコースセッションが見つかりません | 1 | api/kds/advance-phase.php |
| `E4007` | `NO_UNPAID_ORDERS` | 404 | 未払いの注文がありません | 3 | api/customer/checkout-confirm.php, api/customer/checkout-session.php, api/customer/get-bill.php |
| `E4008` | `ORDER_NOT_FOUND` | 404 | 注文が見つかりません | 2 | api/kds/update-status.php, api/smaregi/sync-order.php |
| `E4009` | `PAYMENT_NOT_FOUND` | 404 | 支払い情報が見つかりません | 4 | api/customer/receipt-view.php, api/store/receipt.php |
| `E4010` | `PLAN_NOT_FOUND` | 404 | プランが見つかりません | 1 | api/store/table-sessions.php |
| `E4011` | `RECEIPT_NOT_FOUND` | 404 | 領収書が見つかりません | 1 | api/store/receipt.php |
| `E4012` | `RESERVATION_NOT_FOUND` | 404 | 予約が見つかりません | 4 | api/store/reservation-no-show.php, api/store/reservation-notify.php, api/store/reservation-seat.php …他1 |
| `E4013` | `SESSION_NOT_ACTIVE` | 404 | アクティブなセッションが見つかりません | 1 | api/store/sub-sessions.php |
| `E4014` | `STORE_NOT_FOUND` | 404 | 店舗が見つかりません | 16 | api/customer/ai-waiter.php, api/customer/checkout-confirm.php, api/customer/checkout-session.php …他10 |
| `E4015` | `TABLE_NOT_FOUND` | 500 | テーブルが見つかりません | 5 | api/customer/call-staff.php, api/customer/orders.php, api/customer/table-session.php …他2 |
| `E4016` | `TENANT_NOT_FOUND` | 404 | テナントが見つかりません | 3 | api/connect/onboard.php, api/subscription/checkout.php, api/subscription/status.php |
| `E4017` | `USER_NOT_FOUND` | 404 | ユーザーが見つかりません | 1 | api/auth/change-password.php |

### E9xxx — 顧客・予約・テイクアウト・AI・外部連携

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E9001` | `AI_CHAT_DISABLED` | 403 | AI予約は無効です | 1 | api/customer/reservation-ai-parse.php |
| `E9002` | `AI_ERROR` | 502 | $msg | 1 | api/store/ai-generate.php |
| `E9003` | `AI_FAILED` | 503 | AIの応答に失敗しました | 1 | api/customer/reservation-ai-parse.php |
| `E9004` | `AI_NOT_CONFIGURED` | 503 | AI機能が設定されていません | 2 | api/customer/reservation-ai-parse.php, api/lib/posla-settings.php |
| `E9005` | `AI_PARSE_FAILED` | 500 | AI応答を解釈できませんでした | 1 | api/customer/reservation-ai-parse.php |
| `E9006` | `CANCEL_DEADLINE_PASSED` | 400 | 変更締切時刻を過ぎています | 1 | api/customer/reservation-update.php |
| `E9007` | `CANNOT_RESERVE` | 403 | この番号からは予約できません。お電話で店舗へお問い合わせください | 1 | api/customer/reservation-create.php |
| `E9008` | `GEMINI_ERROR` | 502 | $geminiJson['error']['message'] ?? 'AI APIエラー' | 2 | api/customer/ai-waiter.php, api/kds/ai-kitchen.php |
| `E9009` | `GEMINI_NETWORK` | 502 | 'AI通信エラー: ' . $curlError | 2 | api/customer/ai-waiter.php, api/kds/ai-kitchen.php |
| `E9010` | `GEMINI_PARSE` | 502 | AIレスポンスの解析に失敗しました | 2 | api/customer/ai-waiter.php, api/kds/ai-kitchen.php |
| `E9011` | `KNOWLEDGE_BASE_MISSING` | 503 | ヘルプデスク knowledge base が未生成です。POSLA運営に連絡してください | 1 | api/store/ai-generate.php |
| `E9012` | `LEAD_TIME_VIOLATION` | 400 | 予約締切時刻を過ぎています | 2 | api/customer/reservation-create.php, api/customer/reservation-update.php |
| `E9013` | `NOT_AVAILABLE` | 400 | 事前チェックインできない状態です | 1 | api/customer/reservation-precheckin.php |
| `E9014` | `PICKUP_TOO_EARLY` | 400 | 受取時間は現在から' . $minPrepMinutes . '分以降を指定してください | 1 | api/customer/takeout-orders.php |
| `E9015` | `RESERVATION_DISABLED` | 403 | オンライン予約は受け付けていません | 3 | api/customer/reservation-ai-parse.php, api/customer/reservation-availability.php, api/customer/reservation-create.php |
| `E9016` | `SAME_STORE` | 400 | 自店舗には要請できません | 1 | api/store/shift/help-requests.php |
| `E9017` | `SELF_CHECKOUT_DISABLED` | 403 | セルフレジは有効になっていません | 1 | api/customer/checkout-session.php |
| `E9018` | `SLOT_FULL` | 409 | この時間枠は満席です。別の時間を選択してください | 4 | api/customer/reservation-create.php, api/customer/takeout-orders.php |
| `E9019` | `SLOT_UNAVAILABLE` | 409 | お選びの時間は満席です。別の時間をお試しください | 2 | api/customer/reservation-create.php, api/customer/reservation-update.php |
| `E9020` | `SMAREGI_API_ERROR` | 502 | $errMsg . ' (HTTP=' . $result['status'] . ' detail=' . $detail . ')' | 2 | api/smaregi/import-menu.php, api/smaregi/stores.php |
| `E9021` | `SMAREGI_NOT_CONFIGURED` | 500 | スマレジ Client ID が未設定です。POSLA管理者に連絡してください。 | 1 | api/smaregi/auth.php |
| `E9022` | `TAKEOUT_DISABLED` | 400 | 現在テイクアウトは受け付けていません | 1 | api/customer/takeout-orders.php |

### E8xxx — シフト・勤怠

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E8001` | `ALREADY_CLOCKED_IN` | 400 | 既に出勤中です（' . $existing['clock_in'] . '〜） | 1 | api/store/shift/attendance.php |
| `E8002` | `INVALID_BREAK` | 400 | break_minutes は 0〜480 の範囲で指定してください | 1 | api/store/shift/attendance.php |
| `E8003` | `NOT_CLOCKED_IN` | 400 | 出勤打刻がありません | 1 | api/store/shift/attendance.php |
| `E8004` | `NO_ASSIGNMENTS` | 400 | user_assignments を指定してください | 1 | api/store/shift/assignments.php |
| `E8005` | `NO_MAPPING` | 400 | この店舗はスマレジ店舗にマッピングされていません | 1 | api/smaregi/import-menu.php |
| `E8006` | `NO_PHASE` | 400 | コースにフェーズが設定されていません | 1 | api/store/table-sessions.php |
| `E8007` | `NO_STAFF` | 400 | 派遣するスタッフを選択してください | 1 | api/store/shift/help-requests.php |
| `E8008` | `NO_STORES` | 400 | 店舗を1つ以上登録してください | 1 | api/subscription/checkout.php |

### E5xxx — 注文・KDS・テーブル

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E5001` | `ALREADY_FINAL` | 400 | この予約は既に確定状態です | 3 | api/customer/reservation-cancel.php, api/customer/reservation-update.php, api/store/reservations.php |
| `E5002` | `COURSE_PARTY_MISMATCH` | 400 | このコースの対象人数外です | 1 | api/customer/reservation-create.php |
| `E5003` | `DEVICE_NOT_ASSIGNABLE` | 400 | デバイスアカウント(' . $deviceRows[0]['display_name'] . ')はシフト割当の対象外です | 1 | api/store/shift/apply-ai-suggestions.php |
| `E5004` | `EMPTY_CART` | 400 | カートが空です | 1 | api/customer/takeout-orders.php |
| `E5005` | `INVALID_AVAILABILITY` | 400 | availabilities[$i].availability は available / preferred / unavailable のいずれかです | 2 | api/store/shift/availabilities.php |
| `E5006` | `INVALID_STATUS` | 400 | 無効なステータスです | 12 | api/customer/reservation-deposit-checkout.php, api/kds/call-alerts.php, api/kds/update-item-status.php …他7 |
| `E5007` | `INVALID_TABLE` | 400 | テーブルが店舗に属していません | 1 | api/store/reservation-seat.php |
| `E5008` | `INVALID_TABLE_IDS` | 400 | 指定テーブルが店舗に属していません | 1 | api/store/reservations.php |
| `E5009` | `LAST_ORDER_PASSED` | 409 | 現在ラストオーダーのため注文を受け付けておりません | 3 | api/customer/orders.php |
| `E5010` | `NO_ITEMS` | 400 | 注文に品目がありません | 3 | api/lib/order-validator.php, api/store/receipt.php |
| `E5011` | `NO_TABLE_ASSIGNED` | 400 | 着席先テーブルが指定されていません | 1 | api/store/reservation-seat.php |
| `E5012` | `SESSION_ORDER_LIMIT` | 429 | このセッションの注文上限に達しました。スタッフにお声がけください。 | 1 | api/customer/orders.php |
| `E5013` | `SOLD_OUT` | 409 | implode('、', $soldOutNames) . 'は品切れです。カートから削除してください。' | 2 | api/lib/order-validator.php, api/store/handy-order.php |
| `E5014` | `SUB_SESSION_LIMIT` | 400 | サブセッションの上限（10）に達しています | 1 | api/store/sub-sessions.php |
| `E5015` | `TABLE_CAPACITY_SHORT` | 400 | 指定テーブルの収容人数が不足です | 1 | api/store/reservations.php |
| `E5016` | `TABLE_IN_USE` | 409 | テーブルは使用中です | 1 | api/store/table-open.php |
| `E5017` | `TABLE_OCCUPIED` | 409 | テーブルは既に使用中です | 1 | api/store/reservation-seat.php |

### E1xxx — システム / インフラ (未分類)

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E1001` | `ALREADY_PAID` | 409 | 指定された品目には既に支払済みのものが含まれています | 1 | api/store/process-payment.php |
| `E1002` | `AMOUNT_MISMATCH` | 400 | 金額がサーバー側計算値と一致しません | 1 | api/store/terminal-intent.php |
| `E1003` | `INVALID_ITEM` | 404 | 存在しないメニュー品目が含まれています | 2 | api/lib/order-validator.php, api/store/process-payment.php |
| `E1004` | `INVALID_OPTION` | 404 | 存在しないオプションが含まれています | 1 | api/lib/order-validator.php |
| `E1005` | `INVALID_QUANTITY` | 400 | 数量が不正です | 2 | api/lib/order-validator.php |
| `E1006` | `STRIPE_MISMATCH` | 403 | 決済情報が一致しません (metadata 不足) | 20 | api/customer/checkout-confirm.php, api/customer/takeout-payment.php, api/store/process-payment.php |

### E6xxx — 決済・返金・Stripe・サブスク

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E6001` | `ALREADY_REFUNDED` | 400 | この決済は既に返金処理中または返金済みです | 2 | api/store/refund-payment.php |
| `E6002` | `ALREADY_SUBSCRIBED` | 400 | 既にサブスクリプションがあります。Customer Portal から変更してください | 1 | api/subscription/checkout.php |
| `E6003` | `CASH_REFUND` | 400 | 現金決済はシステム返金できません。手動で返金してください。 | 1 | api/store/refund-payment.php |
| `E6004` | `CONNECT_NOT_CONFIGURED` | 500 | Stripe Connect が設定されていません | 1 | api/store/refund-payment.php |
| `E6005` | `DEPOSIT_CHECKOUT_FAILED` | 503 | '予約金決済の準備に失敗しました: ' . ($checkout['error'] ?: 'unknown') | 1 | api/customer/reservation-create.php |
| `E6006` | `GATEWAY_ERROR` | 502 | '決済に失敗しました: ' . ($gwResult['error'] ?? '不明なエラー') | 1 | api/store/process-payment.php |
| `E6007` | `GATEWAY_NOT_CONFIGURED` | 500 | Stripe が設定されていません | 1 | api/store/refund-payment.php |
| `E6008` | `INVALID_PLAN` | 400 | プランが不正です | 2 | api/posla/tenants.php |
| `E6009` | `NOT_CONNECTED` | 400 | Stripe Terminal が利用できる設定がありません | 5 | api/connect/terminal-token.php, api/smaregi/import-menu.php, api/smaregi/stores.php …他2 |
| `E6010` | `NO_DEPOSIT` | 400 | この予約に予約金は不要です | 1 | api/customer/reservation-deposit-checkout.php |
| `E6011` | `NO_GATEWAY` | 500 | 決済ゲートウェイが設定されていません | 2 | api/customer/takeout-payment.php, api/store/refund-payment.php |
| `E6012` | `NO_SUBSCRIPTION` | 400 | サブスクリプションが未設定です。先にCheckoutを完了してください | 1 | api/subscription/portal.php |
| `E6013` | `ONLINE_PAYMENT_REQUIRED` | 400 | テイクアウトはオンライン決済のみご利用いただけます | 1 | api/customer/takeout-orders.php |
| `E6014` | `PAYMENT_GATEWAY_ERROR` | 502 | Stripe設定が見つかりません | 3 | api/customer/checkout-confirm.php |
| `E6015` | `PAYMENT_NOT_AVAILABLE` | 503 | 決済処理に失敗しました。お手数ですが店舗にお問い合わせください | 3 | api/customer/takeout-orders.php |
| `E6016` | `PAYMENT_NOT_CONFIGURED` | 503 | オンライン決済が設定されていません | 1 | api/customer/checkout-session.php |
| `E6017` | `PAYMENT_NOT_CONFIRMED` | 402 | $result['error'] ?: '決済が確認できませんでした' | 8 | api/customer/checkout-confirm.php, api/customer/takeout-payment.php, api/signup/activate.php |
| `E6018` | `PLAN_REQUIRED` | 403 | シフト管理はProプラン以上で利用できます | 10 | api/owner/shift/unified-view.php, api/store/shift/ai-suggest-data.php, api/store/shift/apply-ai-suggestions.php …他7 |
| `E6019` | `PLATFORM_KEY_MISSING` | 500 | プラットフォームの Stripe キーが設定されていません | 1 | api/store/refund-payment.php |
| `E6020` | `PRICE_NOT_CONFIGURED` | 503 | 料金プラン設定が未完了です | 2 | api/signup/register.php, api/subscription/checkout.php |
| `E6021` | `RECEIPT_EXPIRED` | 403 | 領収書の表示期限が切れています。スタッフにお声がけください | 1 | api/customer/receipt-view.php |
| `E6022` | `REFUND_FAILED` | 502 | '返金に失敗しました: ' . ($refundResult['error'] ?? '不明なエラー') | 1 | api/store/refund-payment.php |
| `E6023` | `STRIPE_ERROR` | 502 | $createResult['error'] | 10 | api/connect/onboard.php, api/connect/terminal-token.php, api/signup/register.php …他4 |
| `E6024` | `STRIPE_NOT_CONFIGURED` | 503 | プラットフォームのStripe APIキーが設定されていません | 8 | api/connect/onboard.php, api/connect/terminal-token.php, api/signup/activate.php …他5 |

### E2xxx — 入力検証

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E2001` | `AMOUNT_EXCEEDED` | 400 | 金額が上限（¥' . number_format($maxAmount) . '）を超えています | 1 | api/customer/takeout-orders.php |
| `E2002` | `EMAIL_REQUIRED` | 400 | メールアドレスを入力してください | 1 | api/customer/reservation-create.php |
| `E2003` | `INVALID_ACTION` | 400 | actionパラメータが不正です | 4 | api/customer/cart-event.php, api/customer/takeout-orders.php, api/store/places-proxy.php …他1 |
| `E2004` | `INVALID_AMOUNT` | 400 | 合計金額が不正です | 1 | api/customer/checkout-session.php |
| `E2005` | `INVALID_CATEGORY` | 400 | 無効なカテゴリです | 2 | api/owner/menu-templates.php |
| `E2006` | `INVALID_COLOR` | 400 | カラーコードは #RRGGBB 形式で入力してください | 1 | api/store/settings.php |
| `E2007` | `INVALID_COUNT` | 400 | 必要人数は1〜10の範囲で指定してください | 1 | api/store/shift/help-requests.php |
| `E2008` | `INVALID_DATE` | 400 | start_date, end_date を YYYY-MM-DD 形式で指定してください | 19 | api/customer/reservation-availability.php, api/customer/takeout-orders.php, api/owner/shift/unified-view.php …他9 |
| `E2009` | `INVALID_DATETIME` | 400 | 日時を解釈できません | 8 | api/customer/reservation-create.php, api/customer/reservation-update.php, api/store/reservations.php …他1 |
| `E2010` | `INVALID_DAY` | 400 | day_of_week は 0（日）〜 6（土）で指定してください | 2 | api/store/shift/templates.php |
| `E2011` | `INVALID_EMAIL` | 400 | メールアドレスが不正です | 7 | api/customer/reservation-create.php, api/owner/users.php, api/signup/register.php …他3 |
| `E2012` | `INVALID_FORMAT` | 400 | 許可されていないファイル形式です（JPEG/PNG/WebP/GIF のみ） | 2 | api/owner/upload-image.php, api/store/upload-image.php |
| `E2013` | `INVALID_INPUT` | 400 | mappings 配列が必要です | 10 | api/smaregi/store-mapping.php, api/store/shift/apply-ai-suggestions.php |
| `E2014` | `INVALID_JSON` | 400 | リクエストのJSONが不正です | 1 | api/lib/response.php |
| `E2015` | `INVALID_KIND` | 400 | kind は staff または device のみ指定可能です | 1 | api/store/staff-management.php |
| `E2016` | `INVALID_NAME` | 400 | テンプレート名は1〜100文字で入力してください | 3 | api/customer/reservation-create.php, api/store/shift/templates.php |
| `E2017` | `INVALID_PARTY_SIZE` | 400 | 人数が範囲外です | 5 | api/customer/reservation-availability.php, api/customer/reservation-create.php, api/customer/reservation-update.php …他1 |
| `E2018` | `INVALID_PAYLOAD` | 400 | イベントデータが不正です | 1 | api/subscription/webhook.php |
| `E2019` | `INVALID_PERIOD` | 400 | period は weekly / monthly のいずれかです | 2 | api/owner/shift/unified-view.php, api/store/shift/summary.php |
| `E2020` | `INVALID_PHONE` | 400 | 電話番号が不正です | 3 | api/customer/reservation-create.php, api/customer/takeout-orders.php, api/signup/register.php |
| `E2021` | `INVALID_RATING` | 400 | 評価は1〜5の整数で指定してください | 1 | api/customer/satisfaction-rating.php |
| `E2022` | `INVALID_REG_NUMBER` | 400 | 登録番号は T + 13桁の数字で入力してください（例: T1234567890123） | 1 | api/store/receipt-settings.php |
| `E2023` | `INVALID_SHAPE` | 400 | 無効なshapeです（' . implode('/', $allowedShapes) . '） | 1 | api/store/tables.php |
| `E2024` | `INVALID_SLUG` | 400 | スラッグは英小文字・数字・ハイフンのみ使用可能です | 3 | api/owner/stores.php, api/posla/tenants.php |
| `E2025` | `INVALID_SOURCE` | 400 | source は "template" または "local" です | 1 | api/kds/sold-out.php |
| `E2026` | `INVALID_STORE` | 400 | 送信先店舗が見つかりません | 1 | api/store/shift/help-requests.php |
| `E2027` | `INVALID_TIME` | 400 | 時刻は HH:MM 形式で指定してください | 10 | api/store/reservation-settings.php, api/store/shift/assignments.php, api/store/shift/availabilities.php …他2 |
| `E2028` | `INVALID_TIME_RANGE` | 400 | 終了時刻は開始時刻より後にしてください | 6 | api/store/shift/assignments.php, api/store/shift/availabilities.php, api/store/shift/help-requests.php …他1 |
| `E2029` | `INVALID_TRANSITION` | 400 | $current . ' から ' . $newStatus . ' への遷移はできません' | 1 | api/store/takeout-management.php |
| `E2030` | `INVALID_TYPE` | 400 | 無効な種別です | 3 | api/kds/cash-log.php, api/store/receipt.php, api/store/reservation-notify.php |
| `E2031` | `INVALID_VALUE` | 400 | $key . ' は 1 以上' | 13 | api/store/reservation-settings.php, api/store/shift/settings.php |
| `E2032` | `INVALID_VISIBLE_TOOLS` | 400 | visible_tools に不正な値: ' . $p . ' (kds/register/handy のみ可) | 1 | api/store/staff-management.php |
| `E2033` | `MAX_AMOUNT_EXCEEDED` | 400 | 金額上限を超えています。店員をお呼びください。 | 1 | api/customer/orders.php |
| `E2034` | `MAX_ITEMS_EXCEEDED` | 400 | 1回の注文は' . $maxItems . '品までです。店員をお呼びください。 | 1 | api/customer/orders.php |
| `E2035` | `MISSING_ADDRESS` | 400 | 住所は必須です | 1 | api/store/places-proxy.php |
| `E2036` | `MISSING_ALERT_ID` | 400 | alert_id は必須です | 2 | api/customer/call-staff.php, api/kds/call-alerts.php |
| `E2037` | `MISSING_EMAIL` | 400 | メールアドレスは必須です | 1 | api/posla/setup.php |
| `E2038` | `MISSING_FIELDS` | 400 | store_id, table_id, session_token は必須です | 44 | api/auth/change-password.php, api/auth/login.php, api/customer/call-staff.php …他30 |
| `E2039` | `MISSING_ID` | 400 | idが必要です | 39 | api/customer/reservation-detail.php, api/owner/categories.php, api/owner/ingredients.php …他21 |
| `E2040` | `MISSING_MESSAGE` | 400 | メッセージを入力してください | 1 | api/customer/ai-waiter.php |
| `E2041` | `MISSING_NAME` | 400 | テナント名は必須です | 6 | api/customer/takeout-orders.php, api/posla/setup.php, api/posla/tenants.php …他2 |
| `E2042` | `MISSING_ORDER` | 400 | order_idが必要です | 3 | api/customer/takeout-orders.php, api/customer/takeout-payment.php, api/store/takeout-management.php |
| `E2043` | `MISSING_ORDER_ID` | 400 | order_id は必須です | 1 | api/smaregi/sync-order.php |
| `E2044` | `MISSING_PARAM` | 400 | id と store_id が必要です | 16 | api/customer/reservation-ai-parse.php, api/customer/reservation-cancel.php, api/customer/reservation-deposit-checkout.php …他10 |
| `E2045` | `MISSING_PARAMS` | 400 | idとstore_idが必要です | 8 | api/customer/table-session.php, api/store/course-phases.php, api/store/course-templates.php …他5 |
| `E2046` | `MISSING_PASSWORD` | 400 | パスワードは必須です | 1 | api/posla/setup.php |
| `E2047` | `MISSING_PAYMENT` | 400 | payment_id は必須です | 1 | api/store/receipt.php |
| `E2048` | `MISSING_PHONE` | 400 | 電話番号が必要です | 2 | api/customer/takeout-orders.php |
| `E2049` | `MISSING_PICKUP` | 400 | 受取時間を選択してください | 1 | api/customer/takeout-orders.php |
| `E2050` | `MISSING_PLAN` | 400 | plan_idが必要です | 1 | api/store/plan-menu-items.php |
| `E2051` | `MISSING_PROMPT` | 400 | プロンプトが空です | 1 | api/store/ai-generate.php |
| `E2052` | `MISSING_SESSION` | 400 | session_idが必要です | 1 | api/customer/takeout-payment.php |
| `E2053` | `MISSING_SLUG` | 400 | slugは必須です | 1 | api/posla/tenants.php |
| `E2054` | `MISSING_STATUS` | 400 | statusが必要です | 1 | api/store/takeout-management.php |
| `E2055` | `MISSING_STORE` | 400 | store_id は必須です | 42 | api/customer/ai-waiter.php, api/customer/menu.php, api/customer/reservation-availability.php …他32 |
| `E2056` | `MISSING_TOKEN` | 400 | signup_token が必要です | 1 | api/signup/activate.php |
| `E2057` | `MISSING_TO_STORE` | 400 | 送信先店舗を指定してください | 1 | api/store/shift/help-requests.php |
| `E2058` | `MISSING_USER` | 400 | user_id を指定してください | 1 | api/store/shift/assignments.php |
| `E2059` | `NO_API_KEY` | 400 | Google Places APIキーが設定されていません（POSLA管理画面で設定してください） | 1 | api/store/places-proxy.php |
| `E2060` | `NO_CHANGES` | 400 | 更新項目がありません | 3 | api/customer/reservation-update.php, api/posla/settings.php, api/posla/tenants.php |
| `E2061` | `NO_DATA` | 400 | availabilities 配列を指定してください | 1 | api/store/shift/availabilities.php |
| `E2062` | `NO_EMAIL` | 400 | メールアドレスが登録されていません | 1 | api/store/reservation-notify.php |
| `E2063` | `NO_FIELDS` | 400 | 更新項目がありません | 25 | api/owner/categories.php, api/owner/ingredients.php, api/owner/menu-templates.php …他21 |
| `E2064` | `NO_REG_NUMBER` | 400 | 適格簡易請求書の発行には登録番号の設定が必要です | 1 | api/store/receipt.php |
| `E2065` | `OUT_OF_RANGE` | 400 | 店舗から' . round($dist) . 'm離れています（許容: ' . $gpsSetting['gps_radius_meters'] . 'm） | 2 | api/store/shift/attendance.php |
| `E2066` | `PAST_DATE` | 400 | 過去日は予約できません | 2 | api/customer/reservation-availability.php, api/store/shift/help-requests.php |
| `E2067` | `PHONE_REQUIRED` | 400 | 電話番号を入力してください | 1 | api/customer/reservation-create.php |
| `E2068` | `TEXT_TOO_LONG` | 400 | 入力が長すぎます | 1 | api/customer/reservation-ai-parse.php |
| `E2069` | `TOO_EARLY` | 400 | 予約時刻 30 分前から利用できます | 1 | api/customer/reservation-precheckin.php |
| `E2070` | `TOO_FAR` | 400 | 受付期間を超えています | 2 | api/customer/reservation-availability.php, api/customer/reservation-create.php |
| `E2071` | `TOO_MANY` | 400 | 一度に提出できるのは最大31日分です | 1 | api/store/shift/availabilities.php |
| `E2072` | `TOO_MANY_ITEMS` | 400 | 品数が上限（' . $maxItems . '品）を超えています | 1 | api/customer/takeout-orders.php |
| `E2073` | `VALIDATION` | 400 | store_id は必須です | 41 | api/auth/device-register.php, api/kds/advance-phase.php, api/kds/close-table.php …他13 |

### E7xxx — メニュー・在庫

| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |
|------|--------|------|-----------|---------|------------|
| `E7001` | `CONFLICT` | 409 | この注文は既に他の操作で確定されました。画面を更新してご確認ください。 | 3 | api/customer/checkout-confirm.php, api/store/handy-order.php, api/store/process-payment.php |
| `E7002` | `DUPLICATE` | 409 | この原材料は既にレシピに登録されています | 1 | api/owner/recipes.php |
| `E7003` | `DUPLICATE_CODE` | 409 | このテーブルコードは既に使用されています | 1 | api/store/tables.php |
| `E7004` | `DUPLICATE_SLUG` | 409 | このスラッグは既に使用されています | 3 | api/owner/stores.php, api/posla/tenants.php |
| `E7005` | `HAS_REFERENCES` | 409 | メニューが紐付いているカテゴリは削除できません | 1 | api/owner/categories.php |

## 運用方針

- 既存の `json_error("CODE", ...)` インターフェースは Phase A では変更しない
- フロントや問い合わせ対応では「コード `CODE` (`Exxxx`)」の併記を推奨
- Phase B で `json_error()` レスポンスに `errorNo` フィールドを追加する案あり
- 新規エラーを追加した際は `scripts/generate_error_catalog.py` を再実行
