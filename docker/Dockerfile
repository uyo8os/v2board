FROM composer:2.11.1 AS composer
FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl libzip-dev libonig-dev libxml2-dev libicu-dev \
    && docker-php-ext-install -j2 bcmath mbstring pdo_mysql pcntl sockets zip intl \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev \
    && docker-php-ext-install -j2 dom xml xmlwriter gd \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/composer
WORKDIR /app
CMD ["php-fpm", "-F"]
