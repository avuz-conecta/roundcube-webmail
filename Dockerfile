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
COPY plugins/password/drivers/zoho_broker.php /var/www/roundcube/plugins/password/drivers/zoho_broker.php
COPY skins/avuz /var/www/roundcube/skins/avuz

# Set permissions
RUN chown -R www-data:www-data /var/www/roundcube \
  && chmod -R 755 /var/www/roundcube \
  && chmod -R 770 /var/www/roundcube/logs \
  && chmod -R 770 /var/www/roundcube/temp

# Copy runtime configs
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD curl -fsS http://localhost/ -o /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]
