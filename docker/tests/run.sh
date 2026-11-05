#!/bin/sh
set -eu
# Git Bash must pass Linux container paths to Docker unchanged.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
cd "$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
project=${COMPOSE_PROJECT_NAME:-v2board-fix-acceptance}
compose() { docker compose -p "$project" -f docker/compose.test.yaml "$@"; }
compose build test
compose up -d mysql redis
compose run --rm test sh /source/docker/tests/install.sh
compose run --rm test sh docker/tests/upgrade.sh
compose run --rm test php docker/tests/security-log.php
compose up -d php-fpm workerman horizon nginx
compose exec -T php-fpm php docker/tests/configure-proxy.php
compose restart php-fpm workerman horizon
compose exec -T nginx nginx -s reload
compose run --rm test sh -c 'i=0; until curl -fsS http://nginx/api/v1/guest/comm/config >/dev/null && curl -fsS http://nginx:81/api/v1/guest/comm/config >/dev/null; do i=$((i+1)); [ "$i" -lt 30 ] || exit 1; sleep 1; done; php docker/tests/runtime.php'
compose exec -T php-fpm php artisan horizon:status
compose run --rm test php docker/tests/proxy-ip.php
compose exec -T nginx nginx -t
compose exec -T php-fpm php-fpm -t
compose exec -T mysql sh -c 'MYSQL_PWD=$MYSQL_PASSWORD mysql -u$MYSQL_USER -Nse "SELECT VERSION()"'
compose exec -T redis redis-server --version
compose ps
echo 'ALL INSTALLATION, UPGRADE AND RUNTIME CHECKS PASSED'
echo "Test resources retained in Compose project: $project"
