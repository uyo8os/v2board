#!/bin/sh

# Sourced by init.sh and update.sh; use the PHP selected by the operator.
run_composer() {
    if [ -f composer.phar ]; then
        php composer.phar "$@"
    elif command -v composer >/dev/null 2>&1; then
        composer "$@"
    else
        echo 'Downloading the verified Composer installer...'
        php -r '
            $signature = file_get_contents("https://composer.github.io/installer.sig");
            $installer = file_get_contents("https://getcomposer.org/installer");
            if (!$signature || !$installer || !hash_equals(trim($signature), hash("sha384", $installer))) {
                fwrite(STDERR, "Composer installer verification failed.\n");
                exit(1);
            }
            if (file_put_contents("composer-setup.php", $installer) === false) exit(1);
        '
        php composer-setup.php --quiet --2 --filename=composer.phar
        rm -f composer-setup.php
        php composer.phar "$@"
    fi
}

install_dependencies() {
    if [ ! -f composer.lock ]; then
        echo 'composer.lock is missing. Restore the release lock file before deploying.' >&2
        exit 1
    fi
    run_composer validate --strict --no-check-publish
    run_composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    run_composer check-platform-reqs --no-dev
    run_composer audit --no-dev
}
