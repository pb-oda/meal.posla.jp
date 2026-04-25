#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
POSLA エラー番号カタログ生成スクリプト

入力:  scripts/output/error-audit.tsv  (棚卸し済みの json_error 呼び出し一覧)
出力:
  - api/lib/error-codes.php       … PHP 側レジストリ (コード→番号→説明)
  - docs/error-catalog.md         … 人間用一覧表
  - scripts/output/error-codes.json … 機械可読版

採番ルール:
  E1xxx  システム / DB / インフラ
  E2xxx  入力検証
  E3xxx  認証・認可・セッション・PIN・トークン
  E4xxx  リソース未発見 (404 系)
  E5xxx  注文・KDS・テーブル・セッション
  E6xxx  決済・返金・Stripe・サブスク
  E7xxx  メニュー・在庫
  E8xxx  シフト・勤怠
  E9xxx  顧客・予約・テイクアウト・AI・外部連携

新規コード追加時のチェックリスト:
  1. error-audit.tsv にコード行を追加 (code / http_status / file / line / message)
  2. 既存 E 番号体系を維持したい場合 → PINNED_NUMBERS に明示登録 (推奨)
  3. 新規 E 番号で良い場合 → CATEGORIES の該当カテゴリ集合に追加
     → ただし sorted 順で後続するコードの E 番号がシフトする点に注意
     → 本番に流通している E 番号がある場合は PINNED_NUMBERS 必須
  4. python3 scripts/generate_error_catalog.py で再生成
  5. ガード assert_no_unrecognized_codes() が新コード未登録を検出して停止する

既知の問題 (未解決):
  - E1xxx 内に番号重複あり (例: E1001 = ACTIVATE_FAILED + ALREADY_PAID)
    原因: CATEGORIES に E1 ラベルが 2 つ存在 ('システム / インフラ' と
    '(未分類)' フォールバック) → 両方が cat_num=1000 から再採番するため重複
  - get_error_code_by_no() は逆引き map で後勝ちのため、E1001 → ALREADY_PAID
    のみ返す。ACTIVATE_FAILED は逆引き不可
  - 本番に流通済みの E 番号を変えると顧客サポート記録と齟齬が出るため、
    現時点では維持。根本解消は別タスクで設計検討する
  - 表示 (Markdown / AI helpdesk) は「該当コード | A<br>B」と複数併記される
    ため、ユーザー側の混乱は実害なし

tenant カタログの詳細ブロック (自動保持):
  - 本スクリプトは tenant 用カタログ (docs/manual/tenant/99-error-catalog.md) を
    再生成する際、既存の `### Exxxx` 詳細ブロック (対処方法・確認事項込み) を
    自動で抽出・保持して新ファイルにマージする。
    → 単独実行で詳細ブロックが消える運用問題は解消済み (旧仕様)。
  - 既存ブロックがない errorNo (新規追加コード等) には basic な詳細ブロックを
    auto-generate する。後段で enrich_error_catalog.py が「対処方法」行を追加。
  - 既存ブロックの 確認事項・対処方法 はそのまま維持される。

ガード機能 (運用ミス防止):
  - assert_no_unrecognized_codes(): TSV にあるが CATEGORIES / PINNED_NUMBERS /
    既存 PHP レジストリのいずれにも無いコードを検出して exit 1
  - assert_no_silent_renumbering(): 既存 PHP レジストリの code → errorNo と
    新生成結果を比較し、PINNED 以外の番号変化があれば exit 1
"""

import csv
import json
import os
import sys
from collections import OrderedDict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TSV = os.path.join(ROOT, 'scripts/output/error-audit.tsv')
OUT_PHP        = os.path.join(ROOT, 'api/lib/error-codes.php')
OUT_MD         = os.path.join(ROOT, 'docs/error-catalog.md')                # 開発者向け raw 版
OUT_MD_TENANT  = os.path.join(ROOT, 'docs/manual/tenant/99-error-catalog.md')      # テナント向け (非AI サポートガイドが参照)
OUT_MD_INTERNAL= os.path.join(ROOT, 'docs/manual/internal/99-error-catalog.md')   # POSLA 運営向け
OUT_JSON       = os.path.join(ROOT, 'scripts/output/error-codes.json')

# ── カテゴリ判定ルール (上から順に評価。最初に match した先頭が採用) ──
# tuple: (category_id, prefix_or_exact_match, list_of_string_codes)

CATEGORIES = [
    # ── E1xxx: システム ──────────────────────────────
    ('E1', 'システム / インフラ', {
        'DB_ERROR', 'MIGRATION', 'MIGRATION_REQUIRED', 'SERVER_ERROR',
        'METHOD_NOT_ALLOWED', 'RATE_LIMITED',
        'FILE_READ_ERROR', 'FILE_TOO_LARGE', 'NO_FILE', 'EMPTY_CSV', 'EMPTY_BODY',
        'SAVE_FAILED', 'UPDATE_FAILED', 'DELETE_FAILED', 'CREATE_FAILED',
        'IMPORT_FAILED', 'GENERATE_FAILED', 'SEND_FAILED', 'FETCH_FAILED',
        'SYNC_FAILED', 'VERIFICATION_FAILED', 'ACTIVATE_FAILED', 'SEAT_FAILED',
        'PARSE_FAILED', 'CART_LOG_FAILED', 'CHECKOUT_FAILED',
        'DEVICE_CREATION_FAILED', 'MISSING_COLUMN',
    }),

    # ── E2xxx: 入力検証 ──────────────────────────────
    ('E2', '入力検証', {
        'VALIDATION', 'INVALID_INPUT', 'INVALID_VALUE', 'INVALID_PAYLOAD',
        'INVALID_JSON', 'INVALID_FORMAT', 'NO_FIELDS', 'NO_DATA', 'NO_CHANGES',
        'MISSING_FIELDS', 'MISSING_PARAM', 'MISSING_PARAMS', 'MISSING_ID',
        'MISSING_NAME', 'MISSING_EMAIL', 'MISSING_PHONE', 'MISSING_PASSWORD',
        'MISSING_USER', 'MISSING_SESSION', 'MISSING_STATUS', 'MISSING_STORE',
        'MISSING_TO_STORE', 'MISSING_SLUG', 'MISSING_ADDRESS', 'MISSING_PROMPT',
        'MISSING_MESSAGE', 'MISSING_PICKUP', 'MISSING_PLAN', 'MISSING_ALERT_ID',
        'MISSING_PAYMENT', 'MISSING_ORDER', 'MISSING_ORDER_ID', 'MISSING_TOKEN',
        'INVALID_DATE', 'INVALID_DATETIME', 'INVALID_DAY', 'INVALID_TIME',
        'INVALID_TIME_RANGE', 'INVALID_PERIOD', 'PAST_DATE',
        'INVALID_NAME', 'INVALID_EMAIL', 'INVALID_PHONE', 'INVALID_RATING',
        # 'INVALID_REASON' は PINNED_NUMBERS で E2074 に固定 (既存 E2 番号のシフト防止)
        'INVALID_COLOR', 'INVALID_SHAPE', 'INVALID_TYPE', 'INVALID_KIND',
        'INVALID_AMOUNT', 'INVALID_COUNT', 'INVALID_REG_NUMBER', 'INVALID_SLUG',
        'INVALID_SOURCE', 'INVALID_CATEGORY', 'INVALID_VISIBLE_TOOLS',
        'INVALID_PARTY_SIZE', 'INVALID_STORE',
        'TEXT_TOO_LONG', 'TOO_EARLY', 'TOO_FAR', 'TOO_MANY', 'TOO_MANY_ITEMS',
        'OUT_OF_RANGE', 'AMOUNT_EXCEEDED', 'MAX_AMOUNT_EXCEEDED', 'MAX_ITEMS_EXCEEDED',
        'INVALID_TRANSITION', 'INVALID_ACTION', 'EMAIL_REQUIRED', 'PHONE_REQUIRED',
        'NO_EMAIL', 'NO_API_KEY', 'NO_REG_NUMBER',
    }),

    # ── E3xxx: 認証・認可 ──────────────────────────────
    ('E3', '認証・認可', {
        'UNAUTHORIZED', 'INVALID_CREDENTIALS', 'INVALID_CURRENT_PASSWORD',
        'WEAK_PASSWORD', 'SAME_PASSWORD',
        'FORBIDDEN', 'FORBIDDEN_HQ_MENU', 'ACCOUNT_DISABLED',
        'INVALID_SESSION', 'SESSION_REVOKED', 'SESSION_TIMEOUT',
        'SESSION_COOLDOWN', 'NO_ACTIVE_SESSION',
        'INVALID_SIGNATURE', 'INVALID_TOKEN', 'TOKEN_ALREADY_USED', 'TOKEN_EXPIRED',
        'INVALID_PIN', 'PIN_INVALID', 'PIN_REQUIRED', 'WEAK_PIN',
        'INVALID_USERNAME', 'INVALID_ROLE', 'INVALID_STAFF', 'INVALID_USERS',
        'DUPLICATE_USERNAME', 'DUPLICATE_EMAIL', 'EMAIL_TAKEN', 'USERNAME_TAKEN',
        'CANNOT_DELETE_SELF',
        'TENANT_DISABLED', 'TENANT_INACTIVE', 'STORE_INACTIVE',
        'GPS_REQUIRED', 'BLACKLISTED_CUSTOMER',
        'ALREADY_SETUP', 'ALREADY_ACTIVE', 'ALREADY_ONBOARDED',
        'USER_NOT_IN_STORE',
    }),

    # ── E4xxx: リソース未発見 ──────────────────────────────
    ('E4', 'リソース未発見', {
        'NOT_FOUND', 'USER_NOT_FOUND', 'ADMIN_NOT_FOUND', 'CUSTOMER_NOT_FOUND',
        'STORE_NOT_FOUND', 'TENANT_NOT_FOUND', 'TABLE_NOT_FOUND',
        'ORDER_NOT_FOUND', 'PAYMENT_NOT_FOUND', 'ITEM_NOT_FOUND',
        'COURSE_NOT_FOUND', 'RESERVATION_NOT_FOUND', 'RECEIPT_NOT_FOUND',
        'PLAN_NOT_FOUND', 'NO_COURSE_SESSION', 'SESSION_NOT_ACTIVE',
        'NO_UNPAID_ORDERS',
    }),

    # ── E5xxx: 注文・KDS・テーブル ──────────────────────────────
    ('E5', '注文・KDS・テーブル', {
        'EMPTY_CART', 'NO_ITEMS', 'INVALID_TABLE', 'INVALID_TABLE_IDS',
        'INVALID_STATUS', 'ALREADY_FINAL', 'SOLD_OUT',
        'TABLE_IN_USE', 'TABLE_OCCUPIED', 'TABLE_CAPACITY_SHORT',
        'NO_TABLE_ASSIGNED', 'COURSE_PARTY_MISMATCH',
        'LAST_ORDER_PASSED', 'SESSION_ORDER_LIMIT', 'SUB_SESSION_LIMIT',
        'DEVICE_NOT_ASSIGNABLE', 'INVALID_AVAILABILITY',
    }),

    # ── E6xxx: 決済・返金・Stripe・サブスク ──────────────────────────────
    ('E6', '決済・返金・Stripe・サブスク', {
        'CASH_REFUND', 'ALREADY_REFUNDED', 'REFUND_FAILED',
        'NO_GATEWAY', 'GATEWAY_ERROR', 'GATEWAY_NOT_CONFIGURED',
        'PAYMENT_GATEWAY_ERROR', 'ONLINE_PAYMENT_REQUIRED',
        'PAYMENT_NOT_AVAILABLE', 'PAYMENT_NOT_CONFIGURED', 'PAYMENT_NOT_CONFIRMED',
        'STRIPE_ERROR', 'STRIPE_NOT_CONFIGURED', 'PRICE_NOT_CONFIGURED',
        'PLATFORM_KEY_MISSING', 'CONNECT_NOT_CONFIGURED', 'NOT_CONNECTED',
        'DEPOSIT_CHECKOUT_FAILED', 'NO_DEPOSIT',
        'ALREADY_SUBSCRIBED', 'NO_SUBSCRIPTION',
        'INVALID_PLAN', 'PLAN_REQUIRED',
        'RECEIPT_EXPIRED',
    }),

    # ── E7xxx: メニュー・在庫 ──────────────────────────────
    ('E7', 'メニュー・在庫', {
        'DUPLICATE', 'DUPLICATE_CODE', 'DUPLICATE_SLUG',
        'HAS_REFERENCES', 'CONFLICT',
    }),

    # ── E8xxx: シフト・勤怠 ──────────────────────────────
    ('E8', 'シフト・勤怠', {
        'INVALID_BREAK', 'ALREADY_CLOCKED_IN', 'NOT_CLOCKED_IN',
        'NO_ASSIGNMENTS', 'NO_STAFF', 'NO_PHASE', 'NO_MAPPING', 'NO_STORES',
    }),

    # ── E9xxx: 顧客・予約・テイクアウト・AI・外部 ──────────────────────────────
    ('E9', '顧客・予約・テイクアウト・AI・外部連携', {
        'CANNOT_RESERVE', 'RESERVATION_DISABLED',
        'LEAD_TIME_VIOLATION', 'CANCEL_DEADLINE_PASSED', 'PICKUP_TOO_EARLY',
        'SLOT_FULL', 'SLOT_UNAVAILABLE', 'NOT_AVAILABLE',
        'TAKEOUT_DISABLED', 'SELF_CHECKOUT_DISABLED', 'AI_CHAT_DISABLED',
        'AI_NOT_CONFIGURED', 'AI_FAILED', 'AI_ERROR', 'AI_PARSE_FAILED',
        'GEMINI_ERROR', 'GEMINI_NETWORK', 'GEMINI_PARSE',
        'KNOWLEDGE_BASE_MISSING',
        'SMAREGI_API_ERROR', 'SMAREGI_NOT_CONFIGURED',
        'SAME_STORE',
    }),
]

# 動的・誤検知扱い (採番しない)
EXCLUDE = {'$e->getErrorCode()', 'string $code'}

# ピン留め番号 (既存 E 番号を変えずに新規コードを後付けで割当てるための機構)
#   - 通常の by-category 連番ロジックではコード追加ごとに alphabetical な後続コードがシフトする。
#   - 本番に流通している E 番号 (顧客サポート・ AI ヘルプデスク・スクリーンショット等) との
#     齟齬を避けるため、新規コードはこの PINNED_NUMBERS に明示登録し、
#     カテゴリ内の「次の空き番号」を手動で割り当てる運用とする。
#   - PINNED に登録したコードは CATEGORIES の集合から外しておく (二重採番防止)。
#   - 番号は E<X><nnnn> 形式の 4 桁。X はカテゴリ系統 (1〜9)。
PINNED_NUMBERS = {
    # R1 (2026-04-20): 顧客評価の低評価理由検証で追加。E2073 (VALIDATION) の次空きを使用。
    'INVALID_REASON': ('E2', '入力検証', 2074),
    # push subscribe / test で追加されていた入力検証コード群。E2074 の次空きに固定。
    'INVALID_SCOPE':   ('E2', '入力検証', 2075),
    'STORE_REQUIRED':  ('E2', '入力検証', 2076),
    'INVALID_TARGET':  ('E2', '入力検証', 2077),
    # 4d-5c-ba (2026-04-21): 通常会計 void 導入で receipt / refund の排他ガードを追加。
    #   既存 E6 番号のシフトを防ぐため E6025-E6027 (E6 末尾の次空き) に固定割当。
    'VOIDED_PAYMENT':  ('E6', '決済・返金・Stripe・サブスク', 6025),
    'REFUNDED_PAYMENT':('E6', '決済・返金・Stripe・サブスク', 6026),
    'ALREADY_VOIDED':  ('E6', '決済・返金・Stripe・サブスク', 6027),
    # 4d-5c-bb-F (2026-04-21): takeout online payment の silent-fail 是正で追加。
    #   Stripe 側 decision は完了したが POSLA 側 DB 永続化に失敗したケースの 500。
    'PAYMENT_RECORD_FAILED': ('E6', '決済・返金・Stripe・サブスク', 6028),
    # 既存 error-codes.php に流通している番号を維持 (sorted 順の再計算で動かないように固定)。
    # 新規コード追加で後続 alphabetical コードがシフトするのを防ぐための pinning。
    'INVALID_ITEM':     ('E1', 'システム / インフラ (未分類)', 1003),
    'INVALID_OPTION':   ('E1', 'システム / インフラ (未分類)', 1004),
    'INVALID_QUANTITY': ('E1', 'システム / インフラ (未分類)', 1005),
    'STRIPE_MISMATCH':  ('E1', 'システム / インフラ (未分類)', 1006),
    'RATE_LIMIT':       ('E1', 'システム / インフラ', 1029),
    'INVALID_MODE':     ('E2', '入力検証', 2078),
    'MISSING_TENANT':   ('E2', '入力検証', 2079),
    'FORBIDDEN_FIELD':  ('E3', '認証・認可', 3040),
    'FORBIDDEN_SCOPE':  ('E3', '認証・認可', 3041),
    'FORBIDDEN_ORIGIN': ('E3', '認証・認可', 3042),
    'PIN_RATE_LIMITED': ('E3', '認証・認可', 3043),
    'SESSION_CLOSED':   ('E5', '注文・KDS・テーブル', 5018),
    'PLAN_ALREADY_SET': ('E5', '注文・KDS・テーブル', 5019),
    'COURSE_SET':       ('E5', '注文・KDS・テーブル', 5020),
    # 2026-04-25: 旧 AI helpdesk retire に伴い E9011 を欠番化。
    # 後続の既存 E9 番号はサポート履歴との整合のため維持する。
    'LEAD_TIME_VIOLATION':    ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9012),
    'NOT_AVAILABLE':          ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9013),
    'PICKUP_TOO_EARLY':       ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9014),
    'RESERVATION_DISABLED':   ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9015),
    'SAME_STORE':             ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9016),
    'SELF_CHECKOUT_DISABLED': ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9017),
    'SLOT_FULL':              ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9018),
    'SLOT_UNAVAILABLE':       ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9019),
    'SMAREGI_API_ERROR':      ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9020),
    'SMAREGI_NOT_CONFIGURED': ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9021),
    'TAKEOUT_DISABLED':       ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9022),
    'LINE_NOT_CONFIGURED':    ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9023),
    'NOT_CONFIGURED':         ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9024),
    'ORDER_TERMINAL':         ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9025),
    'TAKEOUT_READY_DISABLED': ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9026),
    'TOKEN_ISSUE_FAILED':     ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9027),
    'REVOKE_FAILED':          ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9028),
    'ALREADY_UNLINKED':       ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9029),
    'UNLINK_FAILED':          ('E9', '顧客・予約・テイクアウト・AI・外部連携', 9030),
}

# message と http_status から人間向けの説明文 (action) を派生させるテンプレ
# (将来 i18n 対応が必要ならここを差し替え)


def categorize(code: str):
    for cat_id, cat_label, codes in CATEGORIES:
        if code in codes:
            return cat_id, cat_label
    # フォールバック (注意: E1 番号重複の原因。新規コードは CATEGORIES か PINNED_NUMBERS に登録すること)
    return 'E1', 'システム / インフラ (未分類)'


def _load_known_php_numbers():
    """既存 PHP レジストリ (api/lib/error-codes.php) から登録済みの code → errorNo を読み出す。
    新規コード検出ガードで「既に番号が割当て済みのコードは新規扱いしない」判定に使う。
    """
    if not os.path.exists(OUT_PHP):
        return {}
    import re as _re
    known = {}
    try:
        with open(OUT_PHP, 'r', encoding='utf-8') as f:
            content = f.read()
        for m in _re.finditer(r"'([A-Z][A-Z0-9_]+)'\s*=>\s*'(E\d{4})'", content):
            known[m.group(1)] = m.group(2)
    except (OSError, IOError):
        pass
    return known


def _extract_existing_detail_blocks(catalog_path):
    """既存の tenant カタログから ### Exxxx の詳細ブロックを抽出する。
    再生成時に summary table のみで上書きしてしまわないよう、ブロックを保持して
    新カタログ末尾にマージするための関数。

    Returns: dict { 'E1001': '### E1001\\n\\n| 項目 | 内容 |\\n...', ... }
    """
    if not os.path.exists(catalog_path):
        return {}
    import re as _re
    try:
        with open(catalog_path, 'r', encoding='utf-8') as f:
            content = f.read()
    except (OSError, IOError):
        return {}

    blocks = {}
    # 各ブロック: '### Exxxx\\n\\n| 項目...' から次の '### ' or '## ' or 末尾まで
    pattern = _re.compile(
        r'^### (E\d{4})\n(.*?)(?=^### |^## |\Z)',
        _re.DOTALL | _re.MULTILINE
    )
    for m in pattern.finditer(content):
        error_no = m.group(1)
        body = m.group(2).rstrip()
        blocks[error_no] = '### ' + error_no + '\n' + body
    return blocks


def _build_default_detail_block(error_no, codes_for_no, catalog):
    """既存ブロックがない errorNo のために、basic な詳細ブロックを生成する。
    enrich_error_catalog.py が後段で「対処方法」行を追記する前提。

    codes_for_no: この errorNo を共有するコードのリスト (重複 E1xxx 等)
    """
    code_label = '該当コード' if len(codes_for_no) > 1 else 'コード'
    code_cells = '<br>'.join('`' + c + '`' for c in codes_for_no)
    msg_cells = '<br>'.join(catalog[c]['message'].replace('|', '\\|').replace('\n', ' ')
                            for c in codes_for_no)
    return ('### ' + error_no + '\n\n'
            + '| 項目 | 内容 |\n'
            + '|---|---|\n'
            + '| エラー番号 | `' + error_no + '` |\n'
            + '| ' + code_label + ' | ' + code_cells + ' |\n'
            + '| 内容 | ' + msg_cells + ' |\n'
            + '| 確認事項 | 入力内容・操作タイミング・店舗状態を確認の上、再度お試しください。'
            + '解決しない場合は POSLA 運営にエラー番号と発生時刻をお伝えください。 |\n')


def assert_no_silent_renumbering(catalog, known_php):
    """既存 PHP レジストリにあったコードの errorNo が変化していないかチェック。
    PINNED_NUMBERS に登録されたコードは除外 (意図した固定番号のため)。

    CATEGORIES から既存コードを誤って外すと、フォールバック側に落ちて番号が
    シフトすることがある。これを生成直後に検出して停止する。
    """
    changes = []
    for code, entry in catalog.items():
        if code in PINNED_NUMBERS:
            continue
        old_no = known_php.get(code)
        if old_no is None:
            continue   # 新規コード — シフトではない
        new_no = entry['errorNo']
        if old_no != new_no:
            changes.append((code, old_no, new_no))

    if not changes:
        return

    print('', file=sys.stderr)
    print('=' * 60, file=sys.stderr)
    print('ERROR: 既存コードの E 番号が変化しました', file=sys.stderr)
    print('=' * 60, file=sys.stderr)
    for code, old, new in changes:
        print('  - {0}: {1} -> {2}'.format(code, old, new), file=sys.stderr)
    print('', file=sys.stderr)
    print('原因の可能性:', file=sys.stderr)
    print('  - CATEGORIES から既存コードを移動 / 削除した', file=sys.stderr)
    print('  - sorted 順を変えるような新規追加が後続コードを押し出した', file=sys.stderr)
    print('', file=sys.stderr)
    print('対応:', file=sys.stderr)
    print('  - 番号を維持する (推奨): 元のカテゴリ位置に戻す or PINNED_NUMBERS で固定', file=sys.stderr)
    print('  - 番号変更を意図する場合: api/lib/error-codes.php を一旦削除して再生成', file=sys.stderr)
    print('  - その場合は顧客サポート / AI helpdesk 文書も併せて更新が必要', file=sys.stderr)
    print('=' * 60, file=sys.stderr)
    sys.exit(1)


def assert_no_unrecognized_codes(code_aggr):
    """TSV にあるが CATEGORIES にも PINNED_NUMBERS にも既存 PHP レジストリにもない
    新規コードを検出して停止する。
    既存の本番 E 番号がうっかりシフトする運用ミスを防ぐためのガード。
    """
    classified_codes = set()
    for _cat_id, _cat_label, codes in CATEGORIES:
        classified_codes.update(codes)

    known_php = _load_known_php_numbers()

    unrecognized = []
    for code in code_aggr.keys():
        if code in EXCLUDE:
            continue
        if code in classified_codes:
            continue
        if code in PINNED_NUMBERS:
            continue
        if code in known_php:
            continue   # 既存 PHP レジストリに既番号あり → 安全 (再生成しても同番号)
        unrecognized.append(code)

    if not unrecognized:
        return

    print('', file=sys.stderr)
    print('=' * 60, file=sys.stderr)
    print('ERROR: 未登録の新規エラーコードを検出しました', file=sys.stderr)
    print('=' * 60, file=sys.stderr)
    for code in unrecognized:
        agg = code_aggr.get(code, {})
        loc = agg.get('first_loc', ('?', '?'))
        print('  - {} (initial: {}:{})'.format(code, loc[0], loc[1]), file=sys.stderr)
    print('', file=sys.stderr)
    print('対応のいずれかを行ってください:', file=sys.stderr)
    print('', file=sys.stderr)
    print('  A) 既存 E 番号体系のシフトを許容する場合 (新規プロジェクト・リリース前):', file=sys.stderr)
    print('     → CATEGORIES の該当カテゴリ集合に code を追加', file=sys.stderr)
    print('     ※ ただし sorted 順以降のコードの E 番号がシフトする', file=sys.stderr)
    print('     ※ 本番に流通している E 番号がある場合はこちらは選ばない', file=sys.stderr)
    print('', file=sys.stderr)
    print('  B) 既存 E 番号を保持する場合 (本番運用中・推奨):', file=sys.stderr)
    print('     → PINNED_NUMBERS に明示登録', file=sys.stderr)
    print('     例: PINNED_NUMBERS = {', file=sys.stderr)
    for code in unrecognized:
        # 推測カテゴリ (頭文字ベースの推奨は controversial なので汎用ヒントのみ)
        print("       '{0}': ('E2', '入力検証', 20XX),  # 該当カテゴリの空き番号を割当て".format(code), file=sys.stderr)
    print('     }', file=sys.stderr)
    print('', file=sys.stderr)
    print('  C) 一時的にスキップしたい場合 → EXCLUDE にコード名を追加', file=sys.stderr)
    print('=' * 60, file=sys.stderr)
    sys.exit(1)


def build_md(by_category, catalog, audience='dev', existing_blocks=None):
    """audience: 'dev' / 'tenant' / 'internal'

    existing_blocks: 既存の tenant カタログから抽出した詳細ブロック (errorNo -> markdown)
                     audience='tenant' のときのみ使用。指定があれば既存ブロックを保持し、
                     新規 errorNo は basic な詳細ブロックを自動生成する。
    """
    if audience == 'tenant':
        title = '99. エラーカタログ（番号で直引きできます）'
        intro = [
            '',
            '> **このページの使い方**',
            '>',
            '> POSLA で操作中にエラーが表示されたら、画面に出た **エラー番号 (`Exxxx`) または英字コード**を控えて、このカタログで検索してください (Ctrl+F / ⌘+F で番号を検索)。',
            '>',
            '> **もっと速く見つけたい場合**: 管理画面右下の **「📚 サポートガイド」** ボタン → 「エラー番号」タブで `E3024` のように入力すると、代表的なコードについては即座に意味と対処を表示します (非AI、誤回答なし)。全件を見る場合は本カタログを参照してください。',
            '>',
            '> 番号は 9 系統に分かれています。先頭の数字でおおまかな分野が分かります（例: `E3xxx` は認証関連）。',
            '',
        ]
        show_files = False
    elif audience == 'internal':
        title = '99. エラーカタログ（POSLA 運営用 / 全 ' + str(len(catalog)) + ' 件）'
        intro = [
            '',
            'POSLA サポート対応用のエラー一覧。テナントから「コード `MISSING_STORE` が出る」「`E2017` が出る」と問い合わせがあった際の引き当て表として使う。',
            '',
            '- 番号付与は `scripts/generate_error_catalog.py` で自動生成 (元データ: `scripts/output/error-audit.tsv`)',
            '- PHP 側のレジストリは `api/lib/error-codes.php` (`get_error_no()` / `get_error_code_by_no()`)',
            '- Phase B で `json_error()` レスポンスに `errorNo` を含める計画',
            '',
        ]
        show_files = True
    else:
        title = 'POSLA エラーカタログ'
        intro = [
            '',
            '自動生成: `scripts/generate_error_catalog.py` / 元データ: `scripts/output/error-audit.tsv`',
            '',
        ]
        show_files = True

    lines = [f'# {title}']
    lines += intro

    lines += [
        '## 採番ルール',
        '',
        '| 範囲 | カテゴリ |',
        '|------|---------|',
    ]
    for cat_id, cat_label, _ in CATEGORIES:
        cat_num = int(cat_id[1:]) * 1000
        lines.append(f'| `E{cat_num:04d}`〜`E{cat_num+999:04d}` | {cat_label} |')

    lines += ['', f'## 全エラー一覧 ({len(catalog)} 件)', '']

    for (cat_id, cat_label), codes in by_category.items():
        cat_num = int(cat_id[1:]) * 1000
        lines += [
            '',
            f'### {cat_id}xxx — {cat_label}',
            '',
        ]
        if show_files:
            lines += [
                '| 番号 | コード | HTTP | メッセージ | 発生件数 | 主な発生箇所 |',
                '|------|--------|------|-----------|---------|------------|',
            ]
        else:
            lines += [
                '| 番号 | コード | メッセージ |',
                '|------|--------|-----------|',
            ]
        for code in sorted(codes):
            entry = catalog[code]
            msg = entry['message'].replace('|', '\\|').replace('\n', ' ')
            if show_files:
                files_str = ', '.join(entry['files'][:3])
                if len(entry['files']) > 3:
                    files_str += f' …他{len(entry["files"]) - 3}'
                lines.append(
                    f"| `{entry['errorNo']}` | `{code}` | {entry['http_status']} | "
                    f"{msg} | {entry['occurrences']} | {files_str} |"
                )
            else:
                lines.append(f"| `{entry['errorNo']}` | `{code}` | {msg} |")

    if audience == 'tenant':
        # 詳細ブロックセクション (errorNo ごと)
        # 既存ブロックがあれば優先して保持、無ければ basic ブロックを auto-generate
        # 重複 E 番号 (E1xxx 等) は同一 errorNo に複数コードが紐付くため errorNo 単位で集約
        lines += ['', '## 番号別の詳細', '']
        # errorNo -> [code, ...] の逆引き
        errno_to_codes = {}
        for code, entry in catalog.items():
            errno_to_codes.setdefault(entry['errorNo'], []).append(code)
        # errorNo を昇順 (E1001, E1002, ...) でソート
        for errno in sorted(errno_to_codes.keys()):
            codes_for_no = sorted(errno_to_codes[errno])
            existing = (existing_blocks or {}).get(errno)
            if existing:
                # 既存ブロックを保持 (enrich_error_catalog.py で 対処方法 が追記されているため)
                lines += ['', existing]
            else:
                # 新規 errorNo: basic ブロックを生成 (enrich.py が後段で対処方法追記する想定)
                lines += ['', _build_default_detail_block(errno, codes_for_no, catalog).rstrip()]

        lines += [
            '',
            '## 検索例',
            '',
            '- `E2017` → エラー番号をそのまま検索',
            '- `PIN_INVALID` → 英字コードで検索',
            '- `MISSING_STORE` → コード名で検索',
            '',
            '番号がうまく見つからない場合は、画面に出た**エラーメッセージや英字コード**をそのまま検索してください。',
            '',
        ]
    else:
        lines += [
            '',
            '## 運用方針',
            '',
            '- 既存の `json_error("CODE", ...)` インターフェースは Phase A では変更しない',
            '- フロントや問い合わせ対応では「コード `CODE` (`Exxxx`)」の併記を推奨',
            '- Phase B で `json_error()` レスポンスに `errorNo` フィールドを追加する案あり',
            '- 新規エラーを追加した際は `scripts/generate_error_catalog.py` を再実行',
            '',
        ]

    return lines


def main():
    if not os.path.exists(TSV):
        print(f'TSV not found: {TSV}', file=sys.stderr)
        sys.exit(1)

    # TSV 読み込み
    rows = []
    with open(TSV, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f, delimiter='\t')
        for r in reader:
            rows.append(r)

    # ユニークコード抽出 (各 code に対する最頻 message と http_status を採用)
    code_aggr = OrderedDict()
    for r in rows:
        code = r['code']
        if code in EXCLUDE:
            continue
        if code not in code_aggr:
            code_aggr[code] = {
                'http_status': r['http_status'],
                'messages': {},  # message -> count
                'occurrences': [],
            }
        agg = code_aggr[code]
        # ガードのために最初に定義された行情報を保持 (新コード検出時のヒント表示用)
        if 'first_loc' not in agg:
            agg['first_loc'] = (r.get('file', ''), r.get('line', ''))
        msg = r['message']
        agg['messages'][msg] = agg['messages'].get(msg, 0) + 1
        agg['occurrences'].append({'file': r['file'], 'line': int(r['line'])})

    # ガード: 未登録コード検出 (本番 E 番号体系の意図しないシフトを防止)
    assert_no_unrecognized_codes(code_aggr)

    # カテゴリ別に分類して採番
    #   PINNED_NUMBERS に登録されたコードは通常の連番ループからは除外し、後段で固定番号を割当てる
    by_category = OrderedDict()
    for code in sorted(code_aggr.keys()):
        if code in PINNED_NUMBERS:
            continue   # 後段で PINNED として登録
        cat_id, cat_label = categorize(code)
        by_category.setdefault((cat_id, cat_label), []).append(code)

    # 連番割当 (PINNED 以外)
    catalog = OrderedDict()
    for (cat_id, cat_label), codes in by_category.items():
        cat_num = int(cat_id[1:]) * 1000  # E1 -> 1000
        for i, code in enumerate(sorted(codes), start=1):
            number = cat_num + i
            agg = code_aggr[code]
            # 最頻 message
            top_msg = sorted(agg['messages'].items(), key=lambda x: -x[1])[0][0]
            catalog[code] = {
                'errorNo': f'E{number:04d}',
                'category': cat_label,
                'http_status': int(agg['http_status']) if agg['http_status'].isdigit() else None,
                'message': top_msg,
                'occurrences': len(agg['occurrences']),
                'files': sorted({o['file'] for o in agg['occurrences']}),
            }

    # PINNED 番号割当 (既存番号のシフト防止のため、特定コードに固定番号を後付けで付与)
    for code, (cat_id, cat_label, number) in PINNED_NUMBERS.items():
        if code not in code_aggr:
            continue   # TSV にまだ載っていない PINNED 予約は無視
        agg = code_aggr[code]
        top_msg = sorted(agg['messages'].items(), key=lambda x: -x[1])[0][0]
        catalog[code] = {
            'errorNo': f'E{number:04d}',
            'category': cat_label,
            'http_status': int(agg['http_status']) if agg['http_status'].isdigit() else None,
            'message': top_msg,
            'occurrences': len(agg['occurrences']),
            'files': sorted({o['file'] for o in agg['occurrences']}),
        }
        # by_category にも反映 (Markdown 出力テーブルに含めるため)
        by_category.setdefault((cat_id, cat_label), []).append(code)

    # ガード: 既存コードの E 番号がうっかりシフトしていないか検証
    #   (CATEGORIES から既存コードを誤って外した場合などに発火)
    known_php = _load_known_php_numbers()
    if known_php:
        assert_no_silent_renumbering(catalog, known_php)

    # tenant カタログ詳細ブロックの自動保持: 上書き前に既存ブロックを抽出しておく
    existing_tenant_blocks = _extract_existing_detail_blocks(OUT_MD_TENANT)

    # ── 出力 1: JSON ──
    os.makedirs(os.path.dirname(OUT_JSON), exist_ok=True)
    with open(OUT_JSON, 'w', encoding='utf-8') as f:
        json.dump(catalog, f, ensure_ascii=False, indent=2)
    print(f'Wrote {OUT_JSON} ({len(catalog)} codes)')

    # ── 出力 2: PHP レジストリ ──
    php_lines = [
        '<?php',
        '/**',
        ' * POSLA エラーコード レジストリ (Phase A)',
        ' *',
        ' * 自動生成: scripts/generate_error_catalog.py',
        ' * ソース:   scripts/output/error-audit.tsv',
        ' *',
        ' * 用途: 既存の文字列 code (e.g. "MISSING_STORE") と E 番号 (e.g. "E2017") の対応表。',
        ' *      フロント表示・障害調査・サポート問い合わせ時の識別子として利用する。',
        ' *',
        ' * 既存の json_error() インターフェースは変更しない (Phase B で番号埋め込みを検討)。',
        ' */',
        '',
        'function get_error_no(string $code): ?string {',
        '    static $map = [',
    ]
    for code in sorted(catalog.keys()):
        entry = catalog[code]
        php_lines.append(f"        '{code}' => '{entry['errorNo']}',")
    php_lines += [
        '    ];',
        "    return $map[$code] ?? null;",
        '}',
        '',
        '/**',
        ' * 番号→既存コードの逆引き',
        ' */',
        'function get_error_code_by_no(string $errorNo): ?string {',
        '    static $reverse = null;',
        '    if ($reverse === null) {',
        '        $reverse = [];',
        '        $map = [',
    ]
    for code in sorted(catalog.keys()):
        entry = catalog[code]
        php_lines.append(f"            '{code}' => '{entry['errorNo']}',")
    php_lines += [
        '        ];',
        '        foreach ($map as $c => $n) { $reverse[$n] = $c; }',
        '    }',
        '    return $reverse[$errorNo] ?? null;',
        '}',
        '',
    ]
    os.makedirs(os.path.dirname(OUT_PHP), exist_ok=True)
    with open(OUT_PHP, 'w', encoding='utf-8') as f:
        f.write('\n'.join(php_lines))
    print(f'Wrote {OUT_PHP}')

    # ── 出力 3a: 開発者向け raw 版 (docs/error-catalog.md) ──
    md_lines = build_md(by_category, catalog, audience='dev')
    os.makedirs(os.path.dirname(OUT_MD), exist_ok=True)
    with open(OUT_MD, 'w', encoding='utf-8') as f:
        f.write('\n'.join(md_lines))
    print(f'Wrote {OUT_MD}')

    # ── 出力 3b: テナント向け (非AI サポートガイド参照用) ──
    md_lines = build_md(by_category, catalog, audience='tenant', existing_blocks=existing_tenant_blocks)
    os.makedirs(os.path.dirname(OUT_MD_TENANT), exist_ok=True)
    with open(OUT_MD_TENANT, 'w', encoding='utf-8') as f:
        f.write('\n'.join(md_lines))
    print(f'Wrote {OUT_MD_TENANT}')

    # ── 出力 3c: POSLA 運営向け ──
    md_lines = build_md(by_category, catalog, audience='internal')
    os.makedirs(os.path.dirname(OUT_MD_INTERNAL), exist_ok=True)
    with open(OUT_MD_INTERNAL, 'w', encoding='utf-8') as f:
        f.write('\n'.join(md_lines))
    print(f'Wrote {OUT_MD_INTERNAL}')

    # サマリー
    print()
    print(f'Categories: {len(by_category)}')
    print(f'Codes:      {len(catalog)}')
    for (cat_id, cat_label), codes in by_category.items():
        print(f'  {cat_id}xxx ({cat_label}): {len(codes)} codes')


if __name__ == '__main__':
    main()
