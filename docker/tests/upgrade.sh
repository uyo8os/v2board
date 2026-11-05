#!/bin/sh
set -eu
cd /app
php docker/tests/fixtures.php seed
php artisan config:cache

# Installation must never overwrite a populated database or its existing key.
if php artisan v2board:install --no-interaction --email=another@example.test > storage/logs/reinstall-test.log 2>&1; then
    echo 'FAIL: Reinstallation unexpectedly succeeded' >&2; exit 1
fi
php docker/tests/fixtures.php preserved

# A database upgrade must refuse to run outside maintenance mode and preserve data.
if php artisan v2board:update > storage/logs/maintenance-required-test.log 2>&1; then
    echo 'FAIL: Database upgrade succeeded outside maintenance mode' >&2; exit 1
fi
grep -q '请先进入维护模式' storage/logs/maintenance-required-test.log
php docker/tests/fixtures.php preserved
echo 'PASS: Database upgrade requires maintenance mode and preserves data'

# Exercise the default Git path on fix with a real, local upstream release.
git init -b fix
git config user.name 'V2Board acceptance'
git config user.email 'acceptance@example.test'
git add .
git commit -qm 'Acceptance release'
git clone --bare . /tmp/v2board-acceptance-remote.git
git remote add origin /tmp/v2board-acceptance-remote.git
git fetch origin
git branch --set-upstream-to=origin/fix fix
git clone /tmp/v2board-acceptance-remote.git /tmp/v2board-acceptance-next
git -C /tmp/v2board-acceptance-next config user.name 'V2Board acceptance'
git -C /tmp/v2board-acceptance-next config user.email 'acceptance@example.test'
echo release-2 > /tmp/v2board-acceptance-next/release-sentinel
git -C /tmp/v2board-acceptance-next add release-sentinel
git -C /tmp/v2board-acceptance-next commit -qm 'Next fix release'
git -C /tmp/v2board-acceptance-next push origin fix

# Run all upgrade paths in maintenance mode, before starting any service workers.
php artisan down
# A Laravel 8 package manifest must be cleared before Composer boots Laravel 13.
printf '%s\n' '<?php return ["eager" => ["Facade\\Ignition\\IgnitionServiceProvider"]];' > bootstrap/cache/services.php
sh update.sh > storage/logs/git-upgrade-test.log 2>&1
test "$(git branch --show-current)" = fix
test "$(cat release-sentinel)" = release-2
php docker/tests/fixtures.php preserved
sh update.sh --local > storage/logs/repeat-upgrade-test.log 2>&1
php docker/tests/fixtures.php preserved

echo local-change >> release-sentinel
if sh update.sh > storage/logs/dirty-upgrade-test.log 2>&1; then
    echo 'FAIL: Update accepted modified tracked files' >&2; exit 1
fi
grep -q local-change release-sentinel
git restore release-sentinel
echo 'PASS: Git update follows fix, preserves local work, and can be repeated'

cp database/update.sql /tmp/v2board-acceptance-update.sql
printf '\nTHIS IS INVALID SQL;\n' >> database/update.sql
if php artisan v2board:update > storage/logs/sql-failure-test.log 2>&1; then
    echo 'FAIL: Invalid upgrade SQL unexpectedly succeeded' >&2; exit 1
fi
cp /tmp/v2board-acceptance-update.sql database/update.sql
php docker/tests/fixtures.php preserved
echo 'PASS: Invalid upgrade SQL returns a failure'

mkdir -p /tmp/v2board-failing-composer
printf '#!/bin/sh\nif [ "$1" = validate ]; then exit 0; fi\nexit 42\n' > /tmp/v2board-failing-composer/composer
chmod +x /tmp/v2board-failing-composer/composer
if PATH="/tmp/v2board-failing-composer:$PATH" sh update.sh --local > storage/logs/composer-failure-test.log 2>&1; then
    echo 'FAIL: Composer failure unexpectedly succeeded' >&2; exit 1
fi
php docker/tests/fixtures.php preserved
echo 'PASS: Composer failure stops deployment'
php artisan config:cache
composer install --no-interaction --prefer-dist --no-ansi > storage/logs/composer-dev-test.log 2>&1
# Verify that a full dependency update remains installable and passes the audit,
# then restore the delivered lock file so all following checks exercise it.
cp composer.lock /tmp/v2board-acceptance-composer.lock
composer update --no-interaction --prefer-dist --no-ansi > storage/logs/composer-update-test.log 2>&1
composer audit --locked
cp /tmp/v2board-acceptance-composer.lock composer.lock
composer install --no-interaction --prefer-dist --no-ansi > storage/logs/composer-locked-test.log 2>&1
composer check-platform-reqs
composer audit --locked
# The upgrade paths are complete; HTTP tests and runtime must run normally.
php artisan up
php -r 'if (!in_array("sqlite", PDO::getAvailableDrivers(), true)) { fwrite(STDERR, "FAIL: PDO SQLite is required for security regression tests\n"); exit(1); }'
vendor/bin/phpunit --no-progress --colors=never --fail-on-deprecation --fail-on-warning
chown -R www-data:www-data storage bootstrap/cache config/theme
