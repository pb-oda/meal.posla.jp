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
OUT_MD_TENANT  = os.path.join(ROOT, 'docs/manual/tenant/99-error-catalog.md')      # テナント向け (AI helpdesk が参照)
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

# message と http_status から人間向けの説明文 (action) を派生させるテンプレ
# (将来 i18n 対応が必要ならここを差し替え)


def categorize(code: str):
    for cat_id, cat_label, codes in CATEGORIES:
        if code in codes:
            return cat_id, cat_label
    # フォールバック
    return 'E1', 'システム / インフラ (未分類)'


def build_md(by_category, catalog, audience='dev'):
    """audience: 'dev' / 'tenant' / 'internal'"""
    if audience == 'tenant':
        title = '99. エラーカタログ（番号で AI に質問できます）'
        intro = [
            '',
            '> **このページの使い方**',
            '>',
            '> POSLA で操作中にエラーが表示されたら、画面に出た **エラー番号 (`Exxxx`) または英字コード**を控えてください。',
            '> AI ヘルプデスクで「**E2017 って何？**」「**`PIN_INVALID` の対処は？**」のように質問すると、',
            '> 該当エラーの意味と推奨対応を回答します。',
            '>',
            '> 番号は 9 系統に分かれています。先頭の数字でおおまかな分野が分かります（例: `E3xxx` は認証関連）。',
            '',
        ]
        show_files = False
    elif audience == 'internal':
        title = '99. エラーカタログ（POSLA 運営用 / 全 233 件）'
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
        lines += [
            '',
            '## AI ヘルプデスクへの質問例',
            '',
            '- 「**E2017 とは？**」 → 該当する操作と対処を回答',
            '- 「**`MISSING_STORE` はどう直す？**」 → 同上',
            '- 「**E3015 が出ました**」 → 認証系エラーとして対処手順を案内',
            '',
            '番号がうまく見つからない場合は、画面に出た**エラーメッセージそのまま**を貼り付けて質問してください。',
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
        msg = r['message']
        agg['messages'][msg] = agg['messages'].get(msg, 0) + 1
        agg['occurrences'].append({'file': r['file'], 'line': int(r['line'])})

    # カテゴリ別に分類して採番
    by_category = OrderedDict()
    for code in sorted(code_aggr.keys()):
        cat_id, cat_label = categorize(code)
        by_category.setdefault((cat_id, cat_label), []).append(code)

    # 連番割当
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

    # ── 出力 3b: テナント向け (AI ヘルプデスク参照用) ──
    md_lines = build_md(by_category, catalog, audience='tenant')
    os.makedirs(os.path.dirname(OUT_MD_TENANT), exist_ok=True)
    with open(OUT_MD_TENANT, 'w', encoding='utf-8') as f:
        f.write('\n'.join(md_lines))
    print(f'Wrote {OUT_MD_TENANT}')

    # ── 出力 3c: POSLA 運営向け (internal AI ヘルプデスク参照用) ──
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
