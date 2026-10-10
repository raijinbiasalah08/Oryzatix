#!/usr/bin/env bash
set -e

echo "[Oryzatix] Running Composer install..."
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

echo "[Oryzatix] Generating storage symlink..."
php artisan storage:link || true

echo "[Oryzatix] Caching Laravel configs & routes..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "[Oryzatix] Build completed successfully."
