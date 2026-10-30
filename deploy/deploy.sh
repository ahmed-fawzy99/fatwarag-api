#!/usr/bin/env bash
# Releases one image tag on the server. GitHub Actions copies this file and compose.prod.yaml to
# /srv/fatwarag and runs it after the tests and the image build are green:
#   bash /srv/fatwarag/deploy.sh sha-<commit>
# Rolling back is the same command with an older tag (migrations are not rolled back).
set -euo pipefail

cd "$(dirname "$0")"

TAG="${1:?usage: deploy.sh <image tag>}"
export APP_IMAGE="ghcr.io/ahmed-fawzy99/fatwarag-api:${TAG}"

compose() {
  docker compose --env-file .env.production -f compose.prod.yaml "$@"
}

exec 9>/tmp/fatwarag-deploy.lock
flock -w 900 9 || { echo "Another release is still running." >&2; exit 1; }

echo "==> Pulling ${APP_IMAGE}"
docker pull --quiet "$APP_IMAGE"

echo "==> Starting PostgreSQL and Redis"
compose up -d --wait pgsql redis

echo "==> Migrating"
compose run --rm --no-deps app php artisan migrate --force

echo "==> Replacing the app, the worker, the scheduler and the tunnel"
compose up -d --wait --remove-orphans app worker scheduler tunnel

echo "$TAG" > RELEASE
docker image prune -af --filter "until=168h" --filter "label=org.opencontainers.image.source=https://github.com/ahmed-fawzy99/fatwarag-api" >/dev/null

echo "==> Released ${TAG}"
