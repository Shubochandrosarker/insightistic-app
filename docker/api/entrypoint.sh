#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rw storage bootstrap/cache || true

if [ ! -f .env ]; then
  cp .env.example .env
fi

php artisan config:clear --no-interaction || true
php artisan route:clear --no-interaction || true
php artisan view:clear --no-interaction || true

if [ "${APP_ENV:-production}" = "production" ] && { [ -z "${APP_KEY}" ] || echo "${APP_KEY}" | grep -q "CHANGE_ME"; }; then
  echo "APP_KEY is required in production. Generate one with: php artisan key:generate --show"
  exit 1
fi

if [ -z "${APP_KEY}" ]; then
  php artisan key:generate --force --no-interaction
fi

php artisan storage:link --no-interaction || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

if [ "${SEED_PLANS:-true}" = "true" ]; then
  php artisan db:seed --class=Database\\Seeders\\PlanSeeder --force --no-interaction || true
fi

exec "$@"
