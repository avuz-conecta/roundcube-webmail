# IMAP connection-caching proxy — Debian sidecar.
# Uses Debian's OFFICIAL up-imapproxy binary (glibc, battle-tested) instead of a
# hand-compiled Alpine/musl build, which segfaulted on LOGIN. stunnel terminates
# TLS to Zoho (imapproxy has no native implicit-993).
FROM debian:12-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends up-imapproxy stunnel4 ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && mkdir -p /var/run/imapproxy \
 && chown nobody:nogroup /var/run/imapproxy

COPY imapproxy.conf      /etc/imapproxy.conf
COPY stunnel.conf        /etc/stunnel/imapproxy.conf
COPY entrypoint.sh       /entrypoint.sh
RUN chmod +x /entrypoint.sh

# imapproxy listens here; Roundcube connects to this over the stack network
EXPOSE 143

ENTRYPOINT ["/entrypoint.sh"]
