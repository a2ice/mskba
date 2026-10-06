#!/usr/bin/env bash
set -euo pipefail

SOURCE="${1:-/var/www/mskba/ops/nginx/mskba-prod.conf}"
TARGET="/etc/nginx/sites-available/mskba-prod"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="${TARGET}.backup-${STAMP}"

if [ ! -f "$SOURCE" ]; then
    echo "Source config not found: $SOURCE" >&2
    exit 1
fi

echo "Backing up $TARGET -> $BACKUP"
sudo cp -a "$TARGET" "$BACKUP"

rollback() {
    echo "Restoring previous Nginx config from $BACKUP" >&2
    sudo cp -a "$BACKUP" "$TARGET"
    sudo nginx -t
    sudo systemctl reload nginx
}

trap rollback ERR

echo "Installing canonical-host Nginx config"
sudo install -o root -g root -m 0644 "$SOURCE" "$TARGET"

echo "Testing Nginx configuration"
sudo nginx -t

echo "Reloading host Nginx"
sudo systemctl reload nginx

trap - ERR

echo "Verifying canonical host policy"
"$(dirname "$0")/check-canonical-host.sh"

echo "Canonical host redirects installed successfully."
