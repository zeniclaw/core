#!/bin/bash
set -e

cd /var/www/html

# Copy .env if not exists
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate app key if not set
if grep -q "APP_KEY=$" .env || grep -q "APP_KEY=base64:REPLACE" .env; then
    php artisan key:generate --force
fi

# Ensure storage is linked
php artisan storage:link --force 2>/dev/null || true

# Cache config for production
if [ "${APP_ENV}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Start PHP-FPM
exec php-fpm
