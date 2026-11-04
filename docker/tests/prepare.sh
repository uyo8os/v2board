#!/bin/sh
set -eu
cd /app
if [ -f .env ]; then
    echo 'The acceptance volume already has an installation. Use a fresh Compose project.' >&2
    exit 1
fi
tar -C /source --exclude=./.git --exclude=./.docker-test --exclude=./vendor \
    --exclude=./.env --exclude=./bootstrap/cache/*.php --exclude=./storage/logs/*.log \
    -cf - . | tar -xf -
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
composer install --no-interaction --prefer-dist
composer check-platform-reqs
composer audit
