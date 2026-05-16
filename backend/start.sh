#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "==> Installing PHP dependencies"
composer install --no-interaction --prefer-dist --optimize-autoloader

echo "==> Ensuring APP_KEY"
php artisan key:generate --force

map_railway_mysql_env() {
  if [ -n "${MYSQLHOST:-}" ]; then
    export DB_CONNECTION=mysql
    export DB_HOST="${MYSQLHOST}"
    export DB_PORT="${MYSQLPORT:-3306}"
    export DB_DATABASE="${MYSQLDATABASE:-railway}"
    export DB_USERNAME="${MYSQLUSER:-root}"
    export DB_PASSWORD="${MYSQLPASSWORD:-}"
    echo "==> Using Railway MySQL: ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
  fi
}

map_railway_mysql_env

if [ "${APP_ENV:-production}" = "production" ]; then
  export APP_DEBUG="${APP_DEBUG:-false}"
  export SESSION_DRIVER="${SESSION_DRIVER:-file}"
  export CACHE_STORE="${CACHE_STORE:-file}"
  export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
fi

echo "==> Running migrations"
php artisan migrate --force

echo "==> Seeding database (idempotent)"
php artisan db:seed --force

php artisan storage:link || true

RAW_PORT="${PORT:-8000}"
PORT_NUM=$(echo "$RAW_PORT" | grep -oE '[0-9]+' || true)
if [ -z "$PORT_NUM" ]; then
  PORT_NUM=8000
fi

echo "==> Starting Laravel on port ${PORT_NUM}"
exec php artisan serve --host=0.0.0.0 --port="${PORT_NUM}"
