#!/usr/bin/env bash
# 04-rate-limit.sh
# 各 endpoint の rate-limit 発動確認。
# デフォルトは dry-run（コード grep のみ）。
# --execute を付けると実際に burst する（本番負荷注意）。

set -u
HOST="${PROD_HOST:-eat.posla.jp}"
MODE="${1:-dry-run}"
FAIL=0

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

echo "=== rate-limit ($(date)) ==="
echo "host: $HOST / mode: $MODE"

# ---------- static: coverage matrix ----------
echo
echo "[1] rate-limit coverage matrix"
printf "%-40s %-20s\n" "ENDPOINT" "RATE LIMIT"
echo "-----------------------------------------------------------------"

for f in "$ROOT"/api/customer/*.php "$ROOT"/api/store/*.php "$ROOT"/api/auth/*.php; do
  name=$(basename "$f")
  line=$(grep -E "check_rate_limit\(['\"]([^'\"]+)['\"],\s*([0-9]+),\s*([0-9]+)" "$f" 2>/dev/null | head -1)
  if [ -n "$line" ]; then
    limit=$(echo "$line" | sed -E "s/.*check_rate_limit\(['\"]([^'\"]+)['\"], *([0-9]+), *([0-9]+).*/\2 req \/ \3 sec/")
    printf "%-40s %-20s\n" "$name" "$limit"
  else
    printf "%-40s %-20s\n" "$name" "NONE"
  fi
done

# ---------- execute: burst test ----------
if [ "$MODE" = "execute" ]; then
  echo
  echo "[2] Live burst test (use sparingly — hits production)"
  # cart-event: 60 / 600s とされている
  echo "cart-event.php burst 70 requests..."
  for i in $(seq 1 70); do
    code=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
      -H 'Content-Type: application/json' \
      -d '{"store_id":"x","item_id":"x","item_name":"x","action":"add"}' \
      "https://$HOST/api/customer/cart-event.php")
    printf "."
    if [ "$code" = "429" ]; then
      echo
      echo "PASS: rate-limit triggered at request $i (HTTP 429)"
      break
    fi
    if [ "$i" -eq 70 ] && [ "$code" != "429" ]; then
      echo
      echo "FAIL: no 429 after 70 requests — rate-limit may be broken"
      FAIL=$((FAIL+1))
    fi
  done
else
  echo
  echo "[2] burst test skipped (use '--execute' to run)"
fi

echo
echo "=== Summary ==="
echo "Failures: $FAIL"
exit $([ $FAIL -eq 0 ] && echo 0 || echo 1)
