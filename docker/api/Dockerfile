FROM php:8.3-cli

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libpng-dev \
    && docker-php-ext-install pdo_pgsql zip bcmath gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN composer create-project laravel/laravel /var/www/html "12.*" --no-interaction --prefer-dist \
    && composer require laravel/sanctum barryvdh/laravel-dompdf stripe/stripe-php --no-interaction --no-scripts

COPY insightistic-api/ /tmp/insightistic-api/
RUN cp -a /tmp/insightistic-api/. /var/www/html/ \
    && rm -rf /tmp/insightistic-api \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

COPY docker/api/entrypoint.sh /usr/local/bin/insightistic-entrypoint
RUN chmod +x /usr/local/bin/insightistic-entrypoint

EXPOSE 8000
ENTRYPOINT ["insightistic-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
