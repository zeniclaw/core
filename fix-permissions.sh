#!/usr/bin/env bash
# ZeniClaw — Fix file permissions after root operations
# Usage: docker exec zeniclaw_app bash /var/www/html/fix-permissions.sh

set -e

echo "=== ZeniClaw Fix Permissions ==="

echo "[1/4] Ownership → www-data:www-data..."
chown -R www-data:www-data /var/www/html
echo "    done"

echo "[2/4] Directories → 755..."
find /var/www/html -type d -exec chmod 755 {} +
echo "    done"

echo "[3/4] Files → 644..."
find /var/www/html -type f -exec chmod 644 {} +
echo "    done"

echo "[4/4] Executables + storage..."
chmod +x /var/www/html/artisan
chmod +x /var/www/html/clear-cache.sh
chmod +x /var/www/html/fix-permissions.sh
chmod +x /var/www/html/check.sh 2>/dev/null || true
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache
echo "    done"

echo ""
echo "=== Permissions fixed ==="
