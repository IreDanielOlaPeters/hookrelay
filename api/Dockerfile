# HookRelay API / worker / scheduler image (FrankenPHP + PHP 8.3)
FROM dunglas/frankenphp:1-php8.3-bookworm

RUN install-php-extensions pdo_pgsql pgsql redis pcntl bcmath intl zip opcache \
    && apt-get update && apt-get install -y --no-install-recommends git unzip curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-hookrelay.ini
COPY docker/entrypoint.sh /usr/local/bin/hookrelay-entrypoint

WORKDIR /app

# Install dependencies first for better layer caching.
ARG COMPOSER_FLAGS="--prefer-dist --no-interaction --no-progress"
COPY composer.json composer.lock ./
RUN composer install ${COMPOSER_FLAGS} --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

ENV SERVER_NAME=":8080"
EXPOSE 8080

ENTRYPOINT ["hookrelay-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
