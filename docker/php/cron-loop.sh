#!/bin/sh
set -eu

echo "[posla-cron] scheduler started"

while true
do
  echo "[posla-cron] monitor-health start $(date '+%Y-%m-%d %H:%M:%S')"
  php /var/www/html/api/cron/monitor-health.php || true
  sleep 300
done
