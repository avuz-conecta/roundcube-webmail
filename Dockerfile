# ============================================
# App image — uses pre-built base image
# Strategy: start from the official release tarball (complete, pre-compiled)
# then overlay our customizations (config, skin, SSO plugin)
# ============================================
ARG BASE_IMAGE=avuz-roundcube-base:latest
FROM ${BASE_IMAGE}

ARG RC_VERSION=1.6.14

WORKDIR /var/www/roundcube

# Download the official release tarball — fully compiled, no build step needed
RUN curl -sL "https://github.com/roundcube/roundcubemail/releases/download/${RC_VERSION}/roundcubemail-${RC_VERSION}-complete.tar.gz" \
    -o /tmp/rc-release.tar.gz \
  && tar -xzf /tmp/rc-release.tar.gz -C /tmp \
  && cp -r /tmp/roundcubemail-${RC_VERSION}/. /var/www/roundcube/ \
  && rm -rf /tmp/rc-release.tar.gz /tmp/roundcubemail-${RC_VERSION}

# Overlay our customizations on top of the release
COPY config/config.inc.php /var/www/roundcube/config/config.inc.php
COPY plugins/nextcloud_sso /var/www/roundcube/plugins/nextcloud_sso
COPY plugins/avuz_prefetch /var/www/roundcube/plugins/avuz_prefetch
COPY plugins/avuz_filters /var/www/roundcube/plugins/avuz_filters
COPY plugins/avuz_poll_scope /var/www/roundcube/plugins/avuz_poll_scope
COPY plugins/password/drivers/zoho_broker.php /var/www/roundcube/plugins/password/drivers/zoho_broker.php
COPY skins/avuz /var/www/roundcube/skins/avuz
# elastic mail.html: the "Sent date" sort option is removed. Zoho advertises no
# SORT capability, so ordering by the Date: header forces Roundcube to FETCH the
# date of EVERY message in the folder and sort locally — measured at 82s on a
# 20k-message INBOX, against ~1.2s for arrival. Without this COPY the build uses
# the stock template and the option comes back. See customizations.json.
COPY skins/elastic/templates/mail.html /var/www/roundcube/skins/elastic/templates/mail.html
# Core patches (overlay individual patched files from the release tarball).
# rcube_washtml.php: '=' base64-padding fix — without this COPY the build uses
# the stock (buggy) file and blank-signature images persist. See customizations.json.
COPY program/lib/Roundcube/rcube_washtml.php /var/www/roundcube/program/lib/Roundcube/rcube_washtml.php
# rcube_session.php: three-way session merge — without this COPY a long-running
# request writes back its stale copy of compose_data and silently erases
# attachments uploaded while it was in flight. See customizations.json.
COPY program/lib/Roundcube/rcube_session.php /var/www/roundcube/program/lib/Roundcube/rcube_session.php
# search.php: progressive search — render partial cross-folder results instead of
# holding the UI blank until every folder finishes. Without this COPY the build
# uses the stock file and searches look like a hang again.
COPY program/actions/mail/search.php /var/www/roundcube/program/actions/mail/search.php
# rcube_imap_generic.php + rcube_imap_search.php: pipelined multi-folder search.
# Without these COPYs the build uses the stock files and the pipelining is inert —
# searches silently fall back to one SELECT+SEARCH round trip per folder.
COPY program/lib/Roundcube/rcube_imap_generic.php /var/www/roundcube/program/lib/Roundcube/rcube_imap_generic.php
COPY program/lib/Roundcube/rcube_imap_search.php /var/www/roundcube/program/lib/Roundcube/rcube_imap_search.php
# rcube_imap.php: search time limit read from config (imap_search_timelimit)
# instead of a hardcoded 60s. With progressive search that value is the repaint
# interval. Without this COPY the build uses the stock file and the setting is
# silently ignored.
COPY program/lib/Roundcube/rcube_imap.php /var/www/roundcube/program/lib/Roundcube/rcube_imap.php

# Set permissions
RUN chown -R www-data:www-data /var/www/roundcube \
  && chmod -R 755 /var/www/roundcube \
  && chmod -R 770 /var/www/roundcube/logs \
  && chmod -R 770 /var/www/roundcube/temp

# PHP-FPM pool + php.ini overrides. Both live in the app image (fast rebuild)
# rather than Dockerfile.base, which would need a base recompile. The pool file
# replaces the base image's www.conf outright — see docker/php-fpm-www.conf for
# the reasoning behind each value.
COPY docker/php-fpm-www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php-avuz.ini /usr/local/etc/php/conf.d/zz-avuz.ini
COPY docker/perf-prepend.php /usr/local/etc/php/perf-prepend.php

# Copy runtime configs
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD curl -fsS http://localhost/healthz -o /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]
