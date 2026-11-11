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

## Traefik labels: routing is opt-in per service

Incident 2026-11-07: `insightistic-api:ai-pause-20261107` was made with
`docker commit` from the routed api container, so the image carried
`traefik.enable=true` + the `insightistic-api` router. queue and scheduler run
the same image, joined the same Traefik service, and ~2/3 of
`api.insightistic.com` requests returned 502 (only api listens on :8000).

Rules:

1. Both Dockerfiles set `LABEL traefik.enable="false"`. Only the compose
   service that serves HTTP sets `traefik.enable=true` (container labels
   override image labels).
2. Worker services (queue, scheduler, migrate) always set
   `traefik.enable=false` explicitly as well, in case an image ever carries
   router labels again.
3. Never ship a release with `docker commit`. Build from a git sha
   (`deploy-<sha>` tag). If an emergency commit is unavoidable, strip routing:
   `docker commit --change 'LABEL traefik.enable=false' ...`.
4. After every deploy run `scripts/check-traefik-labels.sh insightistic-app api app`
   on the VPS, and curl `https://api.insightistic.com/up` 20 times: all must be 200.

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
#    copilot answers (rule mode ok) → Paddle webhook test event →
#    /api/version release fingerprint
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
reports) · Paddle (production API key, client token, webhook secret, catalog
price IDs, `BILLING_PROVIDER=paddle`, `FEATURE_PADDLE=true`) · Google OAuth
(consent screen + redirect URIs) · Search Console property access · Mail
provider · AI provider key (optional — rule mode is disclosed and works
without) · Embeddings key (optional — FTS-only without) · Sentry DSN
(optional) · optional consent-gated Google Ads/Meta conversion credentials.

The production compose file requires explicit billing settings; do not rely
on a Stripe default. Check the returned release SHA before and after a rollout
so a healthy container cannot be mistaken for the intended image.
