#!/bin/bash
set -e

# Render assigns a dynamic port via $PORT (defaults to 10000 on Render)
PORT="${PORT:-10000}"

echo "[Oryzatix] Configuring Apache port to ${PORT}..."
sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/*.conf

# Link storage if not already linked
if [ ! -L /var/www/html/public/storage ]; then
    echo "[Oryzatix] Linking storage directory..."
    php artisan storage:link || true
fi

# Run migrations if configured
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[Oryzatix] Running database migrations..."
    php artisan migrate --force || echo "[Oryzatix] Migrations encountered an error, continuing startup..."
fi

# Optimize Laravel caches
echo "[Oryzatix] Caching configuration and routes..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "[Oryzatix] Starting Apache web server on port ${PORT}..."
exec apache2-foreground
