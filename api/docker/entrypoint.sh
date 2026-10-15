#!/bin/sh
set -e

# Refuse to run production with development-default secrets (APP_KEY, ADMIN_TOKEN, RELAY_OUTBOUND_SECRET).
php artisan relay:preflight

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
  # Idempotent: demo user + demo data when DEMO_MODE=true, removes the demo user otherwise.
  php artisan relay:bootstrap --no-interaction
fi

exec "$@"
