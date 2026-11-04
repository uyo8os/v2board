#!/bin/sh
set -eu
cd /app
sh /source/docker/tests/prepare.sh
sh init.sh --no-interaction --email=admin@example.test > storage/logs/install-test.log 2>&1
grep -q '一切就绪' storage/logs/install-test.log
echo 'PASS: Fresh init.sh installation with production dependencies'
php artisan --version
php -r 'echo "PHP ", PHP_VERSION, " / phpredis ", phpversion("redis"), PHP_EOL;'
