# Insightistic VPS GHCR Deployment

This runbook deploys Insightistic as a separate Docker Compose project at
`/opt/stacks/insightistic`. It assumes Traefik already owns public HTTP/HTTPS on
the VPS. The Insightistic compose file does not publish host port 80.

## 1. GitHub Actions Setup

The workflow at `.github/workflows/docker-ghcr.yml` builds and publishes:

- `ghcr.io/<owner>/<repo>/app:latest`
- `ghcr.io/<owner>/<repo>/app:<commit-sha>`
- `ghcr.io/<owner>/<repo>/api:latest`
- `ghcr.io/<owner>/<repo>/api:<commit-sha>`

The workflow uses the built-in `GITHUB_TOKEN`, so no Docker password secret is
required for publishing from the same repository.

Repository settings:

1. Go to `Settings -> Actions -> General`.
2. Set workflow permissions to `Read and write permissions`.
3. Keep `Allow GitHub Actions to create and approve pull requests` off unless a
   separate automation needs it.
4. Push to `main` or run the workflow manually from the Actions tab.

## 2. GHCR Package Permissions

After the first successful workflow run:

1. Open the repository package page in GitHub Packages.
2. Confirm both images exist: `app` and `api`.
3. If the repository is private, grant the repository Actions access to the
   packages from package settings.
4. For VPS pulls from a private package, create a GitHub PAT with `read:packages`
   permission. If GitHub requires it for private repo package access, include
   `repo` scope too.

Login on the VPS:

```bash
echo 'YOUR_GITHUB_PAT' | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin
```

## 3. VPS Folder Structure

Create the stack folder:

```bash
sudo mkdir -p /opt/stacks/insightistic/nginx
sudo chown -R $USER:$USER /opt/stacks/insightistic
```

Expected files:

```text
/opt/stacks/insightistic/
  docker-compose.prod.yml
  .env.production
  nginx/
    default.conf
```

Create the Traefik network only if it does not already exist:

```bash
docker network ls
docker network create traefik
```

If the existing Traefik network has a different name, set `TRAEFIK_NETWORK` in
`.env.production` to that exact network name.

## 4. Prepare Environment

Create `.env.production` from `.env.production.example` and fill real values on
the VPS only.

Minimum production values to change:

```env
IMAGE_NAMESPACE=ghcr.io/shubochandrosarker/insightistic-app
IMAGE_TAG=latest
TRAEFIK_NETWORK=traefik
TRAEFIK_ENTRYPOINT=websecure
TRAEFIK_CERT_RESOLVER=letsencrypt
APP_HOST=app.insightistic.com
API_HOST=api.insightistic.com
APP_KEY=base64:CHANGE_ME_GENERATE_WITH_ARTISAN_KEY_GENERATE
DB_PASSWORD=change-this-db-password
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
BILLING_PROVIDER=paddle
PADDLE_ENV=production
PADDLE_API_KEY=
PADDLE_CLIENT_TOKEN=
PADDLE_WEBHOOK_SECRET=
FEATURE_PADDLE=true
SEED_BILLING_CATALOG=true
```

Register the Paddle notification destination as
`https://api.insightistic.com/api/billing/webhook/paddle`. Use separate
sandbox values for staging; never mix sandbox price IDs or webhook secrets
with the live environment.

Generate the Laravel key after the API image exists:

```bash
docker run --rm --entrypoint php ghcr.io/shubochandrosarker/insightistic-app/api:latest artisan key:generate --show
```

Put that output into `APP_KEY`. Use the same `APP_KEY` for `api`, `queue`, and
`scheduler`; the compose file does this automatically from `.env.production`.

## 5. Deploy

From `/opt/stacks/insightistic`:

```bash
docker compose --env-file .env.production -f docker-compose.prod.yml config
docker compose --env-file .env.production -f docker-compose.prod.yml pull
docker compose --env-file .env.production -f docker-compose.prod.yml up -d
docker compose --env-file .env.production -f docker-compose.prod.yml ps
```

Check logs:

```bash
docker compose --env-file .env.production -f docker-compose.prod.yml logs -f api
docker compose --env-file .env.production -f docker-compose.prod.yml logs -f app
docker compose --env-file .env.production -f docker-compose.prod.yml logs -f nginx
```

Manual update:

```bash
cd /opt/stacks/insightistic
docker compose --env-file .env.production -f docker-compose.prod.yml pull
docker compose --env-file .env.production -f docker-compose.prod.yml up -d
docker image prune -f
```

## 6. Watchtower Auto-Update

The production compose file adds these labels to the app, API, queue,
scheduler, and nginx containers:

```text
com.centurylinklabs.watchtower.enable=true
com.centurylinklabs.watchtower.scope=insightistic
```

If Watchtower is not already running, start a scoped instance:

```bash
docker run -d \
  --name watchtower-insightistic \
  --restart unless-stopped \
  -v /var/run/docker.sock:/var/run/docker.sock \
  containrrr/watchtower \
  --label-enable \
  --scope insightistic \
  --interval 300 \
  --cleanup
```

If you already run Watchtower globally, make sure it uses label filtering or it
may update unrelated projects.

## 7. Routing

Traefik receives public HTTPS traffic and sends both hostnames to the internal
`nginx` container. Nginx then routes by hostname:

- `app.insightistic.com` -> `app:3000`
- `api.insightistic.com` -> `api:8000`

No `ports:` mapping is used in `docker-compose.prod.yml`.
