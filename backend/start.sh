#!/usr/bin/env bash
set -euo pipefail

# Install PHP dependencies (non-interactive)
composer install --no-interaction --prefer-dist --optimize-autoloader

# Ensure APP_KEY exists (safe to run multiple times)
php artisan key:generate --force || true

# Run migrations and seeds if DB is reachable (ignore failures during build)
php artisan migrate --force || true
php artisan db:seed --force || true

# Create storage symlink (ignore if exists)
php artisan storage:link || true

# Determine numeric port from $PORT (fallback to 8000)
# Some platforms may set PORT to non-numeric values, causing PHP to error when adding offsets.
RAW_PORT="${PORT:-8000}"
# Extract first sequence of digits from RAW_PORT
PORT_NUM=$(echo "$RAW_PORT" | grep -oE '[0-9]+' || true)
if [ -z "$PORT_NUM" ]; then
	PORT_NUM=8000
fi

echo "Starting server on port $PORT_NUM"

# Start the app using an explicit numeric port
php artisan serve --host=0.0.0.0 --port="$PORT_NUM"
