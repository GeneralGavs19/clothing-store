#!/usr/bin/env bash
set -e

# Install PHP dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader

# Generate app key if missing
php artisan key:generate --force || true

# Run migrations and seeds (ignore errors in build)
php artisan migrate --force || true
php artisan db:seed --force || true

# Create storage symlink
php artisan storage:link || true

# Start the app
php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
