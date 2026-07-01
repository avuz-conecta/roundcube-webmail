#!/bin/sh
set -e

mkdir -p /var/run/imapproxy
chown nobody:nogroup /var/run/imapproxy 2>/dev/null || true

# Debian ships the binary as imapproxyd (no in. prefix).
IMAPPROXYD="$(command -v imapproxyd || command -v in.imapproxyd || echo /usr/sbin/imapproxyd)"

# stunnel first (TLS terminator to Zoho), backgrounded; give it a moment to bind
# before imapproxy's startup ServerInit connects to it.
stunnel4 /etc/stunnel/imapproxy.conf &
sleep 2

# imapproxy runs foreground as the container's main process.
exec "$IMAPPROXYD" -f /etc/imapproxy.conf
