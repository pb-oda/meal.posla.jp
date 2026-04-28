#!/bin/sh
set -eu

exec php /var/www/html/scripts/cloudrun/cron-runner.php "$@"
