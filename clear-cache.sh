#!/usr/bin/env bash
# ZeniClaw — Full cache clear after updates
# Usage: docker exec zeniclaw_app bash /var/www/html/clear-cache.sh

set -e

echo "=== ZeniClaw Cache Clear ==="

echo "[1/7] Config cache..."
php /var/www/html/artisan config:clear

echo "[2/7] Route cache..."
php /var/www/html/artisan route:clear
php /var/www/html/artisan route:cache

echo "[3/7] View cache..."
php /var/www/html/artisan view:clear

echo "[4/7] Event cache..."
php /var/www/html/artisan event:clear 2>/dev/null || true

echo "[5/7] OPcache..."
php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache reset OK'; } else { echo 'OPcache not enabled'; }" && echo ""

echo "[6/7] PHP-FPM reload..."
if command -v supervisorctl &>/dev/null; then
    supervisorctl restart php-fpm
elif pgrep php-fpm &>/dev/null; then
    kill -USR2 $(pgrep -o php-fpm)
    echo "php-fpm: reloaded"
else
    echo "php-fpm: not found (skipped)"
fi

echo "[7/7] Laravel bootstrap cache..."
rm -f /var/www/html/bootstrap/cache/packages.php
rm -f /var/www/html/bootstrap/cache/services.php
php /var/www/html/artisan package:discover --ansi 2>/dev/null || true

echo ""
echo "=== All caches cleared ==="
