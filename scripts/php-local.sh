#!/usr/bin/env bash
export LD_LIBRARY_PATH="/home/raul/.local/php-root/usr/lib/x86_64-linux-gnu:/home/raul/.local/pgsql/usr/lib/x86_64-linux-gnu:${LD_LIBRARY_PATH:-}"
export PHP_INI_SCAN_DIR="/home/raul/.local/php-root/etc/php/8.5/cli/conf.d"
export PHPRC="/home/raul/.local/php-root/etc/php/8.5/cli/php.ini"
exec /home/raul/.local/php-root/usr/bin/php8.5 "$@"
