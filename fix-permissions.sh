#!/usr/bin/env bash
# ZeniClaw — Fix file permissions
# Works both inside the container and on the host.
# Usage: sudo bash fix-permissions.sh

set -e

# Auto-detect app root: use script's own directory
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Sanity check: must contain artisan
if [ ! -f "$APP_ROOT/artisan" ]; then
    echo "ERROR: Cannot find artisan in $APP_ROOT — are you in the ZeniClaw repo?"
    exit 1
fi

# Detect if running inside the container (www-data exists and we're serving the app)
# or on the host (repo is just a git checkout)
if id "www-data" &>/dev/null && [ -f /entrypoint.sh ]; then
    OWNER="www-data:www-data"
    echo "=== ZeniClaw Fix Permissions (container mode) ==="
else
    # On host: keep ownership as the user who owns the repo (or the caller)
    OWNER="$(stat -c '%U:%G' "$APP_ROOT")"
    echo "=== ZeniClaw Fix Permissions (host mode) ==="
fi

echo "    App root: $APP_ROOT"
echo "    Owner: $OWNER"

echo "[1/5] Ownership → $OWNER (excluding .git)..."
find "$APP_ROOT" -maxdepth 0 -exec chown "$OWNER" {} + 2>/dev/null || true
find "$APP_ROOT" -mindepth 1 -maxdepth 1 ! -name '.git' -exec chown -R "$OWNER" {} + 2>/dev/null || true
echo "    done"

echo "[2/5] .git → $OWNER..."
[ -d "$APP_ROOT/.git" ] && chown -R "$OWNER" "$APP_ROOT/.git" || echo "    .git not present (skipped)"
echo "    done"

echo "[3/5] Directories → 755..."
find "$APP_ROOT" -type d -exec chmod 755 {} +
echo "    done"

echo "[4/5] Files → 644..."
find "$APP_ROOT" -type f -exec chmod 644 {} +
echo "    done"

echo "[5/5] Executables + storage..."
for f in artisan update.sh fix-permissions.sh clear-cache.sh check.sh install.sh entrypoint.sh; do
    [ -f "$APP_ROOT/$f" ] && chmod +x "$APP_ROOT/$f"
done
chmod -R 775 "$APP_ROOT/storage"
chmod -R 775 "$APP_ROOT/bootstrap/cache"
# .env must stay readable but not world-readable
[ -f "$APP_ROOT/.env" ] && chmod 660 "$APP_ROOT/.env"
echo "    done"

echo ""
echo "=== Permissions fixed ==="
