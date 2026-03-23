#!/bin/bash
set -e

# Version stamp — bump to force re-initialization on next deploy
AVUZ_CONFIG_VERSION="1.6.14-1"
STAMP_FILE="/var/www/roundcube/temp/.avuz_configured"

# ──────────────────────────────────────────────
# PHASE 1: Permissions (every restart)
# ──────────────────────────────────────────────
echo "Fixing permissions..."
chown -R www-data:www-data /var/www/roundcube/logs /var/www/roundcube/temp 2>/dev/null || true
chmod -R 770 /var/www/roundcube/logs /var/www/roundcube/temp 2>/dev/null || true

# ──────────────────────────────────────────────
# PHASE 2: Database initialization (first boot only)
# ──────────────────────────────────────────────
CURRENT_STAMP=$(cat "$STAMP_FILE" 2>/dev/null || echo "")

if [ "$CURRENT_STAMP" != "$AVUZ_CONFIG_VERSION" ]; then
    echo "═══ Initializing Roundcube database ═══"

    DB_DSN="${ROUNDCUBE_DB_DSN:-sqlite:////var/www/roundcube/temp/roundcube.db}"

    # Write resolved DSN to config so initdb can read it
    echo "<?php \$config['db_dsnw'] = '${DB_DSN}';" > /var/www/roundcube/config/db.inc.php

    # Run schema init (safe to re-run — skips if tables exist)
    php /var/www/roundcube/bin/initdb.sh --create-db 2>/dev/null || true

    rm -f /var/www/roundcube/config/db.inc.php

    echo "$AVUZ_CONFIG_VERSION" > "$STAMP_FILE"
    echo "═══ Roundcube initialized ═══"
else
    echo "✓ Roundcube already initialized ($AVUZ_CONFIG_VERSION), skipping"
fi

# ──────────────────────────────────────────────
# PHASE 3: Start services
# ──────────────────────────────────────────────
echo "Starting services..."
exec "$@"
