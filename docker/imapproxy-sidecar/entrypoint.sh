#!/bin/sh
set -e

mkdir -p /var/run/imapproxy
chown nobody:nogroup /var/run/imapproxy 2>/dev/null || true

# Debian may name the binary in.imapproxyd or imapproxyd — find it.
IMAPPROXYD="$(command -v in.imapproxyd || command -v imapproxyd || echo /usr/sbin/in.imapproxyd)"

# stunnel first (TLS terminator to Zoho), backgrounded.
stunnel4 /etc/stunnel/imapproxy.conf &

# imapproxy runs foreground as the container's main process.
exec "$IMAPPROXYD" -f /etc/imapproxy.conf
