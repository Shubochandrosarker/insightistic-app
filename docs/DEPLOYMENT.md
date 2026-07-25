# Deployment

Runbooks: `docs/VPS-GHCR-DEPLOYMENT.md` (Traefik VPS) and
`docs/HOSTINGER-VPS.md`. This page covers the upgraded runtime, the safe
deploy procedure and the required secret rotation.

## ⚠️ One-time: rotate the leaked credentials

`docker-compose.hostinger-url.yml` used to contain a hardcoded `APP_KEY`
and `DB_PASSWORD` in git history. Treat both as compromised:

1. Generate a new DB password; update the DB role and the deploy env.
2. `APP_KEY` rotation is invasive: it encrypts `connector_secret`,
   `cloudflare_api_token` and peppers license-key hashes. Procedure:
   maintenance window → decrypt-and-reencrypt the encrypted columns with a
   re-key script (or accept forced reconnects) → **licenses must be
   regenerated** (hashes are keyed to APP_KEY) → plugin sites re-activate
   with their new keys. Schedule this deliberately; do not rotate casually.
3. Purge the old values from any deployed `.env` files and CI secrets.

## Runtime shape (docker-compose.prod.yml)

- `db` — `pgvector/pgvector:pg16` (Postgres + vector extension)
- `redis` — cache/queues/rate limits (appendonly)
- `api` — PHP-FPM 8.4 + nginx + OPcache (supervisord), healthcheck `/up`
- `queue` — `queue:work --tries=3 --backoff=10,60,300 --timeout=600`
- `scheduler` — `schedule:work`
- `app` — Next.js, `nginx` — internal router behind Traefik
- `migrate` — one-shot (`--profile ops`), **the only thing that migrates**

## Standard deploy (staging first, then production)

```bash
# 0. Backup
docker compose exec db pg_dump -U insightistic insightistic | gzip > backup-$(date +%F).sql.gz

# 1. Pull new images
docker compose -f docker-compose.prod.yml pull

# 2. Migrate (one-shot, during the window)
docker compose -f docker-compose.prod.yml --profile ops run --rm migrate

# 3. Roll services (entrypoint re-caches config/routes)
docker compose -f docker-compose.prod.yml up -d api queue scheduler app nginx

# 4. Queue restart (pick up new code)
docker compose exec api php artisan queue:restart

# 5. Health checks
curl -fsS https://api.insightistic.com/up
curl -fsS https://app.insightistic.com/ -o /dev/null -w '%{http_code}\n'

# 6. Smoke tests
#    login → dashboard overview loads → licenses page → knowledge page →
#    copilot answers (rule mode ok) → Stripe webhook test event
```

## Rollback

```bash
docker compose -f docker-compose.prod.yml down api queue scheduler
IMAGE_TAG=<previous-sha> docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml --profile ops run --rm api php artisan migrate:rollback --step=N --force   # only if the new migrations must come out
# restore DB from the step-0 dump only as a last resort
```

All new migrations have working `down()` (verified in CI: up → rollback → up).

## First super admin (once per environment)

```bash
docker compose exec api php artisan insightistic:make-super-admin owner@example.com
```

## External services checklist

PostgreSQL+pgvector · Redis · S3-compatible bucket (optional; knowledge +
reports) · Stripe (keys, webhook, price ids on plans) · Google OAuth
(consent screen + redirect URIs) · Mail provider · AI provider key
(optional — rule mode works without) · Embeddings key (optional — FTS-only
without) · Sentry DSN (optional).
