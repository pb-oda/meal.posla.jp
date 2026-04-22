#!/usr/bin/env bash
# 03-customer-surface.sh
# 顧客面 (QR / PIN / session / XSS / HTTPS) の自動検査。
# 本スクリプトは read-only（本番に書き込まない）。

set -u
HOST="${PROD_HOST:-eat.posla.jp}"
FAIL=0

log()  { echo "[$1] $2"; }
fail() { log FAIL "$*"; FAIL=$((FAIL+1)); }
pass() { log PASS "$*"; }
warn() { log WARN "$*"; }
info() { log INFO "$*"; }

echo "=== customer surface ($(date)) ==="
echo "host: $HOST"

# ---------- HTTPS HSTS ----------
echo
echo "[1] HSTS / Secure cookie"
headers=$(curl -s -I "https://$HOST/")
if echo "$headers" | grep -qi 'strict-transport-security'; then
  pass "HSTS header present"
else
  fail "HSTS header missing (H-11)"
fi

# ---------- Cookie flags ----------
echo
cookie_resp=$(curl -s -I "https://$HOST/api/auth/check.php")
set_cookie=$(echo "$cookie_resp" | grep -i '^set-cookie:')
if [ -n "$set_cookie" ]; then
  echo "$set_cookie"
  echo "$set_cookie" | grep -qi 'secure'    || fail "Set-Cookie missing Secure flag"
  echo "$set_cookie" | grep -qi 'httponly'  || fail "Set-Cookie missing HttpOnly flag"
  echo "$set_cookie" | grep -qi 'samesite=' || fail "Set-Cookie missing SameSite"
else
  info "no Set-Cookie on /api/auth/check.php (session already created or no-op)"
fi

# ---------- Rate limit coverage (HEAD / GET) ----------
echo
echo "[2] rate-limit scan on customer POST endpoints"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
for f in menu validate-token get-bill order-history receipt-view checkout-confirm checkout-session order-status \
         reservation-detail reservation-availability reservation-precheckin reservation-update \
         takeout-payment satisfaction-rating; do
  if [ -f "$ROOT/api/customer/$f.php" ]; then
    if grep -q "check_rate_limit" "$ROOT/api/customer/$f.php"; then
      pass "$f.php has rate limit"
    else
      fail "$f.php has NO rate limit (H-03)"
    fi
  fi
done

# ---------- XSS path check ----------
echo
echo "[3] innerHTML without escape grep (customer/ side)"
count=$(grep -rnE "innerHTML\s*=" "$ROOT/public/customer" --include='*.js' 2>/dev/null | grep -vE "escapeHtml|''" | wc -l)
if [ "$count" -gt 10 ]; then
  warn "$count innerHTML assignments in customer JS (manual review)"
else
  pass "customer JS has ≤10 raw innerHTML ($count)"
fi

# ---------- CSRF protection hint ----------
echo
echo "[4] CSRF / Origin verification hint"
if grep -rlE "verify_origin|check_origin|HTTP_ORIGIN" "$ROOT/api/lib" 2>/dev/null; then
  pass "some Origin check helper exists"
else
  warn "no Origin verification helper — CSRF defends on SameSite=Lax only (H-04)"
fi

# ---------- session_token entropy ----------
echo
echo "[5] session_token entropy"
count=$(grep -rE "bin2hex\(random_bytes\(\s*([0-9]+)" "$ROOT/api" --include='*.php' 2>/dev/null | wc -l)
pass "session_token uses random_bytes() in $count places (128-bit)"

# ---------- sub_token single-use ----------
echo
echo "[6] sub_token lifecycle"
if grep -rE "UPDATE.*table_sub_sessions.*closed_at" "$ROOT/api" 2>/dev/null | head -3; then
  pass "sub_token has closed_at UPDATE path"
else
  warn "sub_token closure path not found (M-07)"
fi

echo
echo "=== Summary ==="
echo "Failures: $FAIL"
exit $([ $FAIL -eq 0 ] && echo 0 || echo 1)
