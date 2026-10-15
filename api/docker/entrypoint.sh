#!/bin/sh
set -e

# Refuse to run production with the development defaults baked into docker-compose.yml.
if [ "$APP_ENV" = "production" ] && { [ "$ADMIN_TOKEN" = "dev-admin-token" ] || [ "$APP_KEY" = "base64:Hr3B76dIUXyBys2e3c2r1nGACL3Fjlhu1DtO0l/nENk=" ]; }; then
  echo "Refusing to start: ADMIN_TOKEN/APP_KEY still use development defaults." >&2
  exit 1
fi

# Wait for Postgres.
if [ -n "$DB_HOST" ]; then
  i=0
  until php -r "new PDO('pgsql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:5432).';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" >/dev/null 2>&1; do
    i=$((i+1)); [ $i -gt 60 ] && { echo "Postgres not reachable" >&2; exit 1; }
    sleep 1
  done
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"
