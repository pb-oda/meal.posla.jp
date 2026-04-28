#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
  ''|*[!0-9]*)
    echo "[posla-cloudrun] invalid PORT: $port" >&2
    exit 1
    ;;
esac

printf 'Listen %s\n' "$port" > /etc/apache2/ports.conf
printf 'ServerName localhost\n' > /etc/apache2/conf-available/posla-servername.conf
ln -sf ../conf-available/posla-servername.conf /etc/apache2/conf-enabled/posla-servername.conf
sed "s/__PORT__/${port}/g" \
  /etc/apache2/sites-available/000-default-cloudrun.conf.template \
  > /etc/apache2/sites-available/000-default.conf

case "${POSLA_CRON_ENABLED:-0}" in
  1|true|TRUE|yes|YES|on|ON)
    /usr/local/bin/posla-cron-loop.sh &
    ;;
  *)
    echo "[posla-cron] scheduler disabled by POSLA_CRON_ENABLED=${POSLA_CRON_ENABLED:-}"
    ;;
esac

exec apache2-foreground
