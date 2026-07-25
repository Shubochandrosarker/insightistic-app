# Insightistic API — production image.
#
# Reproducible: builds from the committed composer.json + composer.lock in
# insightistic-api/ (no more `composer create-project` at build time), and
# serves through PHP-FPM + nginx with OPcache — `php artisan serve` is gone.
#
# The same image runs three roles (compose overrides `command`):
#   web:       supervisord → nginx :8000 + php-fpm     (default CMD)
#   queue:     php artisan queue:work ...
#   scheduler: php artisan schedule:work

# ---- Stage 1: vendor/ from the lockfile --------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY insightistic-api/composer.json insightistic-api/composer.lock ./
RUN composer install \
    --no-dev --no-interaction --no-progress --no-scripts \
    --prefer-dist --optimize-autoloader --ignore-platform-reqs

COPY insightistic-api/ ./
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---- Stage 2: runtime ---------------------------------------------------
FROM php:8.5-fpm-alpine

RUN apk add --no-cache \
        nginx supervisor curl \
        libpq libpng libjpeg-turbo libwebp freetype libzip icu-libs \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
        libzip-dev icu-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip bcmath gd intl opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build-deps

# Production PHP configuration: OPcache on, sane limits.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=off'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini \
    && { \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=25M'; \
        echo 'post_max_size=26M'; \
        echo 'expose_php=off'; \
    } > /usr/local/etc/php/conf.d/insightistic.ini

# FPM pool: workers run as www-data (non-root).
RUN { \
        echo '[www]'; \
        echo 'user = www-data'; \
        echo 'group = www-data'; \
        echo 'listen = 127.0.0.1:9000'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 12'; \
        echo 'pm.start_servers = 3'; \
        echo 'pm.min_spare_servers = 2'; \
        echo 'pm.max_spare_servers = 5'; \
        echo 'pm.max_requests = 500'; \
        echo 'catch_workers_output = yes'; \
    } > /usr/local/etc/php-fpm.d/zz-insightistic.conf

COPY docker/api/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/api/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /app/ /var/www/html/
RUN mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/api/entrypoint.sh /usr/local/bin/insightistic-entrypoint
RUN chmod +x /usr/local/bin/insightistic-entrypoint

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

ENTRYPOINT ["insightistic-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
