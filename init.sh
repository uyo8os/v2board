#!/bin/sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
. ./scripts/composer.sh

install_dependencies
php artisan v2board:install "$@"

if [ -f /etc/init.d/bt ]; then
    chown -R www storage bootstrap/cache
fi
