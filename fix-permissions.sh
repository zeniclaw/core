#!/usr/bin/env bash
# ZeniClaw — Fix file permissions after root operations
# Usage: docker exec zeniclaw_app bash fix-permissions.sh
#    or: sudo bash fix-permissions.sh (from repo directory)

set -e

# Auto-detect app root: use script's own directory
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Sanity check: must contain artisan
if [ ! -f "$APP_ROOT/artisan" ]; then
    echo "ERROR: Cannot find artisan in $APP_ROOT — are you in the ZeniClaw repo?"
    exit 1
fi

echo "=== ZeniClaw Fix Permissions ==="
echo "    App root: $APP_ROOT"

echo "[1/4] Ownership → www-data:www-data..."
chown -R www-data:www-data "$APP_ROOT"
echo "    done"

echo "[2/4] Directories → 755..."
find "$APP_ROOT" -type d -exec chmod 755 {} +
echo "    done"

echo "[3/4] Files → 644..."
find "$APP_ROOT" -type f -exec chmod 644 {} +
echo "    done"

echo "[4/4] Executables + storage..."
chmod +x "$APP_ROOT/artisan"
chmod +x "$APP_ROOT/clear-cache.sh" 2>/dev/null || true
chmod +x "$APP_ROOT/fix-permissions.sh"
chmod +x "$APP_ROOT/check.sh" 2>/dev/null || true
chmod -R 775 "$APP_ROOT/storage"
chmod -R 775 "$APP_ROOT/bootstrap/cache"
echo "    done"

echo ""
echo "=== Permissions fixed ==="
