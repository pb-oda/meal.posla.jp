#!/usr/bin/env bash
# 05-static-scan.sh
# 本番サーバー + ローカル repo の静的チェック。
# C-01 / C-02 / C-03 / H-01 / H-11 / H-14 を検出する。
#
# Exit codes:
#   0: 全チェック pass
#   1: Critical 検出
#   2: High 検出（Critical はないが改善要）
#   3: Medium 以下検出

set -u
HOST="${PROD_HOST:-eat.posla.jp}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FAIL_CRITICAL=0
FAIL_HIGH=0
FAIL_MEDIUM=0

log()  { printf "[%s] %s\n" "$1" "${*:2}"; }
fail_c() { log "CRITICAL" "$*"; FAIL_CRITICAL=$((FAIL_CRITICAL+1)); }
fail_h() { log "HIGH"     "$*"; FAIL_HIGH=$((FAIL_HIGH+1)); }
fail_m() { log "MEDIUM"   "$*"; FAIL_MEDIUM=$((FAIL_MEDIUM+1)); }
pass()   { log "PASS"     "$*"; }

echo "=== 98-security-audit static scan ($(date)) ==="
echo "host: $HOST / root: $ROOT"
echo

# ---------- C-01: scripts/output/ 公開 ----------
echo "[C-01] scripts/output/ public accessibility"
code=$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/scripts/output/helpdesk-prompt-internal.txt")
if [ "$code" = "200" ]; then
  fail_c "scripts/output/helpdesk-prompt-internal.txt is HTTP 200 (publicly accessible)"
elif [ "$code" = "403" ] || [ "$code" = "404" ]; then
  pass "scripts/output/helpdesk-prompt-internal.txt returns $code (blocked)"
else
  fail_m "scripts/output/helpdesk-prompt-internal.txt returns unexpected $code"
fi

code=$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/scripts/output/error-codes.json")
if [ "$code" = "200" ]; then
  fail_c "scripts/output/error-codes.json is HTTP 200"
else
  pass "scripts/output/error-codes.json returns $code"
fi

# ---------- C-02: public/docs-internal/ 認証 ----------
echo
echo "[C-02] public/docs-internal/ Basic auth"
headers=$(curl -s -I "https://$HOST/public/docs-internal/internal/09-pwa.html")
code=$(echo "$headers" | awk 'NR==1 {print $2}')
auth=$(echo "$headers" | grep -i '^WWW-Authenticate')
if [ "$code" = "200" ] && [ -z "$auth" ]; then
  fail_c "public/docs-internal/ is HTTP 200 with no WWW-Authenticate header"
elif [ "$code" = "401" ] || [ -n "$auth" ]; then
  pass "public/docs-internal/ requires authentication"
else
  fail_m "public/docs-internal/ returns $code / auth header: $auth"
fi

# ---------- C-03: scripts/ 公開 ----------
echo
echo "[C-03] scripts/ directory accessibility"
code=$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/scripts/build-helpdesk-prompt.php")
if [ "$code" = "200" ]; then
  fail_c "scripts/build-helpdesk-prompt.php is HTTP 200 (executable / readable)"
else
  pass "scripts/build-helpdesk-prompt.php returns $code"
fi

# ---------- Confirm defended paths ----------
echo
echo "[Confirm] already-defended paths"
for p in "/api/.htaccess" "/sql/schema.sql" "/_backup_20260421_sync/"; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST$p")
  case "$code" in
    403|404) pass "$p returns $code (blocked)";;
    200)     fail_c "$p is HTTP 200 (should be blocked)";;
    *)       fail_m "$p returns unexpected $code";;
  esac
done

# ---------- H-01: DB credentials in repo .htaccess ----------
echo
echo "[H-01] DB credentials hardcoded in repo"
if [ -f "$ROOT/api/.htaccess" ]; then
  if grep -qE "POSLA_DB_PASS\s+odah_eat-posla" "$ROOT/api/.htaccess" 2>/dev/null; then
    fail_h "api/.htaccess contains real DB password 'odah_eat-posla'"
  else
    pass "api/.htaccess does not contain a recognizable DB password"
  fi
fi

# ---------- H-02: login brute force rate limit ----------
echo
echo "[H-02] login rate-limit"
if grep -lq "check_rate_limit.*login" "$ROOT/api/auth/login.php" 2>/dev/null; then
  pass "api/auth/login.php has rate limit"
else
  fail_h "api/auth/login.php has no check_rate_limit() call"
fi

# ---------- H-03: customer endpoints missing rate-limit ----------
echo
echo "[H-03] customer endpoints rate-limit coverage"
missing=0
for f in "$ROOT"/api/customer/*.php; do
  name=$(basename "$f")
  case "$name" in
    # rate-limit を不要とする read-only なものは除外候補だが、保守的に全 POST-able を検査
    cart-event.php|table-session.php|orders.php|call-staff.php|takeout-orders.php)
      grep -q "check_rate_limit" "$f" || { fail_h "$name missing check_rate_limit"; missing=$((missing+1)); }
      ;;
    *)
      grep -q "check_rate_limit" "$f" || log "INFO" "$name has no rate limit (read-only?)"
      ;;
  esac
done
if [ $missing -eq 0 ]; then pass "known POST-able customer endpoints have rate-limit"; fi

# ---------- H-11: HSTS header ----------
echo
echo "[H-11] HSTS header"
hsts=$(curl -s -I "https://$HOST/" | grep -i 'strict-transport-security')
if [ -n "$hsts" ]; then
  pass "HSTS: $hsts"
else
  fail_h "HSTS header not set"
fi

# ---------- M: hardcoded secrets in public/ ----------
echo
echo "[M] hardcoded secret scan in public/"
stripe_hits=$(grep -rE "sk_(test|live)_[A-Za-z0-9]{20,}" "$ROOT/public" 2>/dev/null | grep -v 'docs-internal' | head -5)
if [ -n "$stripe_hits" ]; then
  echo "$stripe_hits"
  fail_h "hardcoded Stripe secret key found in public/"
else
  pass "no stripe secret key in public/"
fi
google_hits=$(grep -rE "AIzaSy[A-Za-z0-9_-]{20,}" "$ROOT/public" 2>/dev/null | head -5)
if [ -n "$google_hits" ]; then
  echo "$google_hits"
  fail_h "hardcoded Google API key found in public/"
else
  pass "no Google API key in public/"
fi

# ---------- PHP syntax check ----------
echo
echo "[static] php -l on all api/**.php"
errors=0
while IFS= read -r f; do
  php -l "$f" >/dev/null 2>&1 || { fail_m "php -l failed: $f"; errors=$((errors+1)); }
done < <(find "$ROOT/api" -name '*.php' -type f)
if [ $errors -eq 0 ]; then pass "all PHP files pass syntax check"; fi

# ---------- SQL concat anti-pattern ----------
echo
echo "[static] SQL concat antipattern grep"
# $var が SQL string 内部に埋め込まれているケース（prepared statement なら ? プレースホルダ）
# 閉じ quote 前に $varname が現れる危険パターンのみ検出
sql_hits=$(grep -rnE "(query|prepare)\(\"[^\"]*\\\$[a-zA-Z_]" "$ROOT/api" --include='*.php' 2>/dev/null | head -5)
sql_hits2=$(grep -rnE "(query|prepare)\('[^']*\\\$[a-zA-Z_]" "$ROOT/api" --include='*.php' 2>/dev/null | head -5)
if [ -n "$sql_hits" ] || [ -n "$sql_hits2" ]; then
  [ -n "$sql_hits" ] && echo "$sql_hits"
  [ -n "$sql_hits2" ] && echo "$sql_hits2"
  fail_m "potential SQL concat pattern found"
else
  pass "no obvious SQL concat anti-pattern"
fi

# ---------- innerHTML without escape ----------
echo
echo "[static] innerHTML without escapeHtml in public/"
# innerHTML = $variable と innerHTML = '<...>' + $variable の 2 パターン
count=$(grep -rnE "innerHTML\s*=\s*" "$ROOT/public" --include='*.js' 2>/dev/null | grep -v "escapeHtml\|innerHTML\s*=\s*''" | wc -l)
if [ "$count" -gt 30 ]; then
  fail_m "$count innerHTML assignments without obvious escapeHtml (review manually)"
else
  pass "innerHTML assignments look mostly escaped ($count raw)"
fi

# ---------- Summary ----------
echo
echo "=== Summary ==="
echo "Critical: $FAIL_CRITICAL"
echo "High:     $FAIL_HIGH"
echo "Medium:   $FAIL_MEDIUM"

if [ $FAIL_CRITICAL -gt 0 ]; then exit 1; fi
if [ $FAIL_HIGH -gt 0 ]; then exit 2; fi
if [ $FAIL_MEDIUM -gt 0 ]; then exit 3; fi
exit 0
