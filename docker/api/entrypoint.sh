#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || chmod -R ug+rw storage bootstrap/cache || true

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

if [ "${APP_ENV:-production}" = "production" ] && { [ -z "${APP_KEY}" ] || echo "${APP_KEY}" | grep -q "CHANGE_ME"; }; then
  echo "APP_KEY is required in production. Generate one with: php artisan key:generate --show"
  exit 1
fi

if [ -z "${APP_KEY}" ]; then
  php artisan key:generate --force --no-interaction
fi

php artisan storage:link --no-interaction || true

# Bake configuration/routes for OPcache-friendly production boot. Env vars
# are already present at container start, so caching here is safe — and a
# restart naturally re-caches after env changes.
if [ "${APP_ENV:-production}" = "production" ]; then
  php artisan config:cache --no-interaction
  php artisan route:cache --no-interaction
  php artisan view:cache --no-interaction || true
else
  php artisan config:clear --no-interaction || true
  php artisan route:clear --no-interaction || true
  php artisan view:clear --no-interaction || true
fi

# Migrations: OFF by default in production images — run the dedicated
# one-shot `migrate` compose service (or `php artisan migrate --force` by
# hand) during a deploy window instead of on every container start.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

if [ "${SEED_PLANS:-false}" = "true" ]; then
  php artisan db:seed --class=Database\\Seeders\\PlanSeeder --force --no-interaction || true
fi

# SECURITY: the SUPER_ADMIN_EMAIL auto-promotion seeder was removed. Create
# the first super admin explicitly (one time, from a server shell):
#   docker compose exec api php artisan insightistic:make-super-admin owner@example.com

exec "$@"
