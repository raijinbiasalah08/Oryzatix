#!/bin/bash
set -e

# Render's generateValue creates a base64-encoded 256-bit secret. Laravel
# expects encoded APP_KEY values to carry the "base64:" prefix so it decodes
# them to the 32-byte key required by AES-256-CBC.
if [[ -n "${APP_KEY:-}" && "${APP_KEY}" != base64:* ]]; then
    decoded_key_length="$(php -r '$key = base64_decode((string) getenv("APP_KEY"), true); echo $key === false ? 0 : strlen($key);')"
    if [[ "$decoded_key_length" == "32" && "${#APP_KEY}" -ne 32 ]]; then
        export APP_KEY="base64:${APP_KEY}"
        echo "[Oryzatix] Normalized generated APP_KEY for Laravel."
    fi
fi

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
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
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
