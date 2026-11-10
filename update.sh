#!/bin/sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

case "${1:-}" in
    --local) shift ;;
    '')
        command -v git >/dev/null 2>&1 || { echo 'Git is required.' >&2; exit 1; }
        git rev-parse --is-inside-work-tree >/dev/null
        # Replace tracked program files with the configured release, including fix.
        upstream=$(git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}')
        remote=$(git config --get "branch.$(git branch --show-current).remote")
        git fetch "$remote"
        git reset --hard "$upstream"
        exec sh ./update.sh --local
        ;;
    *) echo 'Usage: sh update.sh [--local]' >&2; exit 1 ;;
esac

[ -f .env ] || { echo '.env is missing. Install the project first.' >&2; exit 1; }
. ./scripts/composer.sh

# Discard manifests that may reference providers removed by a major update.
rm -f bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/packages.php
install_dependencies
php artisan v2board:update

if [ -f /etc/init.d/bt ]; then
    chown -R www storage bootstrap/cache
fi
echo 'Reload PHP-FPM and restart the Workerman service to load the updated code.'
