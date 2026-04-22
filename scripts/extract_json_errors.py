#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Scan api/ for json_error(...) calls and emit a TSV catalog.

Output columns: code, http_status, file, line, message
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
API_DIR = os.path.join(ROOT, "api")
OUT_TSV = os.path.join(ROOT, "scripts/output/error-audit.tsv")


def parse_php_args(src, start_idx):
    """
    Given src and an index pointing to '(' after json_error,
    walk through, respecting strings/parens, and return list of raw arg strings.
    """
    assert src[start_idx] == '('
    i = start_idx + 1
    depth = 1
    args = []
    cur = []
    in_str = None  # quote char
    escape = False
    while i < len(src):
        c = src[i]
        if in_str:
            cur.append(c)
            if escape:
                escape = False
            elif c == '\\' and in_str != "'":
                # in single-quoted PHP strings, only \' and \\ are escapes; we approximate
                escape = True
            elif c == '\\' and in_str == "'":
                # handle \' and \\ in single-quoted
                escape = True
            elif c == in_str:
                in_str = None
            i += 1
            continue
        if c == '"' or c == "'":
            in_str = c
            cur.append(c)
            i += 1
            continue
        if c == '(' or c == '[' or c == '{':
            depth += 1
            cur.append(c)
            i += 1
            continue
        if c == ')' or c == ']' or c == '}':
            depth -= 1
            if depth == 0:
                # end of call
                arg = ''.join(cur).strip()
                if arg or args:
                    args.append(arg)
                return args, i
            cur.append(c)
            i += 1
            continue
        if c == ',' and depth == 1:
            args.append(''.join(cur).strip())
            cur = []
            i += 1
            continue
        # PHP comments inside calls: be naive, skip // and # and /* */
        if c == '/' and i + 1 < len(src) and src[i+1] == '/':
            # line comment
            j = src.find('\n', i)
            if j == -1:
                j = len(src)
            i = j
            continue
        if c == '/' and i + 1 < len(src) and src[i+1] == '*':
            j = src.find('*/', i + 2)
            if j == -1:
                j = len(src)
            else:
                j += 2
            i = j
            continue
        if c == '#' and depth == 1:
            j = src.find('\n', i)
            if j == -1:
                j = len(src)
            i = j
            continue
        cur.append(c)
        i += 1
    # malformed
    args.append(''.join(cur).strip())
    return args, i


def find_calls_in_file(path):
    with open(path, 'r', encoding='utf-8') as f:
        src = f.read()
    # build line index: position -> line number (1-based)
    line_starts = [0]
    for idx, ch in enumerate(src):
        if ch == '\n':
            line_starts.append(idx + 1)

    def line_of(pos):
        # binary search
        lo, hi = 0, len(line_starts) - 1
        while lo < hi:
            mid = (lo + hi + 1) // 2
            if line_starts[mid] <= pos:
                lo = mid
            else:
                hi = mid - 1
        return lo + 1

    results = []
    pat = re.compile(r'\bjson_error\s*\(')
    for m in pat.finditer(src):
        # ensure we're not inside a string. Approximate: count unescaped quotes before this position on the line.
        # Skip if the match is inside a single-line comment.
        line_no = line_of(m.start())
        line_start = line_starts[line_no - 1]
        prefix = src[line_start:m.start()]
        # naive strip strings out of prefix
        clean = []
        in_s = None
        esc = False
        for ch in prefix:
            if in_s:
                if esc:
                    esc = False
                elif ch == '\\':
                    esc = True
                elif ch == in_s:
                    in_s = None
                continue
            if ch == '"' or ch == "'":
                in_s = ch
                continue
            clean.append(ch)
        cleaned_prefix = ''.join(clean)
        if '//' in cleaned_prefix or '#' in cleaned_prefix:
            continue
        # check if we are inside a /* ... */ block comment by counting
        # unmatched /* before this position (with strings already stripped via raw scan)
        # Do a quick scan from beginning of file (with strings ignored).
        scan_clean = []
        in_s2 = None
        esc2 = False
        for ch in src[:m.start()]:
            if in_s2:
                if esc2:
                    esc2 = False
                elif ch == '\\':
                    esc2 = True
                elif ch == in_s2:
                    in_s2 = None
                continue
            if ch == '"' or ch == "'":
                in_s2 = ch
                continue
            scan_clean.append(ch)
        before = ''.join(scan_clean)
        # find last /* and last */
        last_open = before.rfind('/*')
        last_close = before.rfind('*/')
        if last_open != -1 and last_open > last_close:
            # inside block comment
            continue
        # find the '('
        paren = src.find('(', m.start())
        if paren == -1:
            continue
        try:
            args, end = parse_php_args(src, paren)
        except Exception:
            continue
        results.append((line_no, args))
    return results


def normalize_arg(arg):
    """Strip outer quotes if simple string literal; else return raw with marker."""
    a = arg.strip()
    if not a:
        return ""
    # match a single full string literal (no concatenation/variable)
    # double quoted
    if len(a) >= 2 and a[0] == '"' and a[-1] == '"':
        # ensure no unescaped " inside
        inner = a[1:-1]
        # accept (could still be a single literal)
        return inner
    if len(a) >= 2 and a[0] == "'" and a[-1] == "'":
        inner = a[1:-1]
        return inner
    return a  # dynamic/expression


def tsv_escape(s):
    if s is None:
        return ""
    s = s.replace("\\", "\\\\")
    s = s.replace("\t", "\\t")
    s = s.replace("\r", "\\r")
    s = s.replace("\n", "\\n")
    return s


def main():
    rows = []
    for dirpath, dirnames, filenames in os.walk(API_DIR):
        for fn in filenames:
            if not fn.endswith('.php'):
                continue
            full = os.path.join(dirpath, fn)
            rel = os.path.relpath(full, ROOT)
            try:
                calls = find_calls_in_file(full)
            except Exception as e:
                print("ERR reading", full, e, file=sys.stderr)
                continue
            for line_no, args in calls:
                code = normalize_arg(args[0]) if len(args) >= 1 else ""
                msg = normalize_arg(args[1]) if len(args) >= 2 else ""
                status = normalize_arg(args[2]) if len(args) >= 3 else ""
                rows.append({
                    "code": code,
                    "http_status": status,
                    "file": rel,
                    "line": line_no,
                    "message": msg,
                })

    os.makedirs(os.path.dirname(OUT_TSV), exist_ok=True)
    with open(OUT_TSV, 'w', encoding='utf-8') as f:
        f.write("code\thttp_status\tfile\tline\tmessage\n")
        for r in rows:
            f.write("\t".join([
                tsv_escape(r["code"]),
                tsv_escape(r["http_status"]),
                tsv_escape(r["file"]),
                str(r["line"]),
                tsv_escape(r["message"]),
            ]) + "\n")

    # Summary
    from collections import Counter
    total = len(rows)
    codes = Counter(r["code"] for r in rows)
    domain = Counter()
    for r in rows:
        parts = r["file"].split("/")
        if len(parts) >= 2:
            domain["/".join(parts[:2]) + "/"] += 1
        else:
            domain[parts[0]] += 1

    dynamic_codes = [r for r in rows if r["code"].startswith("$") or "." in r["code"] or " . " in r["code"] or r["code"].startswith("(") or "?" in r["code"]]
    missing_status = [r for r in rows if not r["http_status"]]

    print("TOTAL", total)
    print("UNIQUE_CODES", len(codes))
    print("TOP20")
    for c, n in codes.most_common(20):
        print("  ", n, c)
    print("DOMAIN")
    for d, n in sorted(domain.items(), key=lambda x: -x[1]):
        print("  ", n, d)
    print("DYNAMIC_CODE_COUNT", len(dynamic_codes))
    for r in dynamic_codes[:5]:
        print("  ", r["file"], r["line"], "code=", r["code"][:80])
    print("MISSING_STATUS_COUNT", len(missing_status))
    for r in missing_status[:5]:
        print("  ", r["file"], r["line"], "code=", r["code"])


if __name__ == "__main__":
    main()
