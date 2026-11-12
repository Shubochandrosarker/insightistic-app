# Insightistic Hostinger VPS fallback: local images, no GitHub Actions

Use only for the existing VPS Compose deployment at /opt/stacks/insightistic
(after confirming that path and the actual Compose project). This is a manual
release procedure: production deployment is NOT performed by this document.
AppSumo Licensing API v2 is independent of Stripe/Paddle subscription billing.

## Prerequisites

- Authorized VPS SSH or an ONLINE Remote Desktop Commander connection.
- A read-only GitHub deploy key configured on the VPS.
- Docker with Compose and sufficient CPU/disk space.
- A separate STAGING environment and a successful end-to-end redemption test.
- Full database backup. NEVER change or delete the current Postgres volume.

Check the running setup with:

    docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
    docker compose --env-file /opt/stacks/insightistic/.env.production -f /opt/stacks/insightistic/docker-compose.prod.yml ps
    df -h
    free -h

## Build from the pinned Git revision

Use a separate source checkout (not the live Compose config folder):

    git clone git@github.com:Shubochandrosarker/insightistic-app.git /opt/stacks/insightistic-src
    cd /opt/stacks/insightistic-src
    git fetch origin main
    git checkout --detach origin/main
    git rev-parse HEAD
    export IMAGE_NAMESPACE=ghcr.io/shubochandrosarker/insightistic-app
    bash scripts/vps-local-build.sh

The script builds locally tagged app and API Docker images with the full
commit SHA. It does NOT publish images to GHCR and does NOT deploy anything.
Registry access to Docker Hub, npm and Composer is still required for builds.

## Configure runtime secrets on the VPS ONLY

The existing /opt/stacks/insightistic/.env.production must be kept intact;
never replace it with an example file or check it into GitHub. In a protected
terminal editor, add missing entries:

    APPSUMO_LICENSING_BASE_URL=https://api.licensing.appsumo.com/v2
    APPSUMO_LICENSING_API_KEY=REDACTED
    APPSUMO_OAUTH_CLIENT_ID=REDACTED
    APPSUMO_OAUTH_CLIENT_SECRET=REDACTED
    APPSUMO_OAUTH_REDIRECT_URI=https://api.insightistic.com/api/appsumo/oauth/callback
    INSIGHTISTIC_TRIAL_DAYS=7

Also verify GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and
INTEGRATIONS_GOOGLE_REDIRECT_URI for Google Analytics/Search Console.
Do NOT blindly change BILLING_PROVIDER or STRIPE_ALLOW_LIVE on an existing
production tenant; reconcile the current billing ledger first. Never paste
secrets or .env values into chats or command output.

AppSumo Partner Portal should retain these two already configured URLs:

- POST https://api.insightistic.com/api/appsumo/webhook
- GET  https://api.insightistic.com/api/appsumo/oauth/callback

## Backup and preflight

Run from /opt/stacks/insightistic. Restrict backup permissions:

    umask 077
    mkdir -p "$HOME/insightistic-backups"
    TS="$(date -u +%Y%m%dT%H%M%SZ)"
    cp -p docker-compose.prod.yml "$HOME/insightistic-backups/compose-$TS.yml"
    cp -p .env.production "$HOME/insightistic-backups/env-$TS.bak"
    set -o pipefail
    docker compose --env-file .env.production -f docker-compose.prod.yml exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$HOME/insightistic-backups/pg-$TS.sql.gz"
    gzip -t "$HOME/insightistic-backups/pg-$TS.sql.gz"

Protect and preferably encrypt/off-host the backup. Check it is non-empty.
DO NOT change APP_KEY; doing so can invalidate encrypted provider tokens and
plugin licenses.

## Promote AFTER staging acceptance

From the VPS shell:

    SRC=/opt/stacks/insightistic-src
    STACK=/opt/stacks/insightistic
    SHA="$(git -C "$SRC" rev-parse HEAD)"
    cp "$SRC/docker-compose.prod.yml" "$STACK/docker-compose.prod.yml"
    cp "$SRC/docker-compose.local-images.yml" "$STACK/docker-compose.local-images.yml"
    cd "$STACK"
    export IMAGE_TAG="$SHA"
    export INSIGHTISTIC_RELEASE="$SHA"
    docker compose --env-file .env.production -f docker-compose.prod.yml -f docker-compose.local-images.yml config --quiet
    docker compose --env-file .env.production -f docker-compose.prod.yml -f docker-compose.local-images.yml --profile ops run --rm migrate
    docker compose --env-file .env.production -f docker-compose.prod.yml -f docker-compose.local-images.yml up -d --no-build api queue scheduler app nginx
    docker compose --env-file .env.production -f docker-compose.prod.yml -f docker-compose.local-images.yml exec -T api php artisan queue:restart

The local-images overlay disables pulling API/app images and disables their
Watchtower updates. Always deploy with the overlay, or a future plain Compose
up may pull older GHCR images. Prefer running migrations only if pending;
never roll back schema as an automatic code rollback.

## Verify

    curl -fsS https://api.insightistic.com/up
    curl -fsS https://api.insightistic.com/api/version
    curl -fsS https://api.insightistic.com/api/appsumo/oauth/callback

Release fingerprint MUST match the locally built SHA. Test the official
SIGNED AppSumo test webhook using the Partner Portal (do not send invented
customer license events). Then verify new/existing customer claims, MFA,
all 3 tiers, upgrades/refunds, signed plugin handshake, dashboard import,
Google/Bing/Cloudflare data and PDF reports on staging first.

Run backend Pest against a disposable PostgreSQL test DB and frontend build/
typecheck/component tests before production. GitHub Actions startup_failure
has no passing job evidence; its root cause is not yet confirmed to be
billing, so local builds do not replace the QA gate.

If anything fails, restore the previous pinned IMAGE_TAG and re-run Compose
with the same overlay. Do not restore production DB unless a qualified DBA
confirms it is unavoidable and has checked the migration compatibility.
