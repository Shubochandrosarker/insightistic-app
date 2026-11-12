#!/usr/bin/env bash
# Reproducible, pinned image build on the VPS without GitHub Actions.
# Intentionally BUILD ONLY: production promotion remains a separate step.
set -Eeuo pipefail

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

command -v git >/dev/null || die "Git is required."
command -v docker >/dev/null || die "Docker is required."

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || die "Run inside a Git checkout."
cd "$ROOT"

[[ -z "$(git status --porcelain)" ]] || die "Uncommitted changes: refuse non-reproducible image."
SHA="$(git rev-parse HEAD)"
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || die "Cannot identify a pinned git SHA."

# Match the namespace in the VPS .env.production. NEVER copy secrets here.
NAMESPACE="${IMAGE_NAMESPACE:-ghcr.io/shubochandrosarker/insightistic-app}"
[[ "$NAMESPACE" =~ ^[a-z0-9._/-]+$ ]] || die "Invalid image namespace."

STAMP="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
printf 'Building from %s\nNamespace: %s\n' "$SHA" "$NAMESPACE"

docker build \
  --file docker/api/Dockerfile \
  --build-arg "INSIGHTISTIC_RELEASE=$SHA" \
  --build-arg "INSIGHTISTIC_BUILD_TIME=$STAMP" \
  --tag "$NAMESPACE/api:$SHA" .

docker build \
  --file docker/app/Dockerfile \
  --build-arg "NEXT_PUBLIC_API_URL=" \
  --build-arg "API_PROXY_TARGET=http://api:8000" \
  --tag "$NAMESPACE/app:$SHA" .

docker image inspect "$NAMESPACE/api:$SHA" "$NAMESPACE/app:$SHA" >/dev/null

printf '\nImages built locally; NOT deployed: %s\n' "$SHA"
printf 'Follow docs/VPS-LOCAL-BUILD-DEPLOY.md for release gates and rollback.\n'
