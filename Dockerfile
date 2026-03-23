# ============================================
# App image — uses pre-built base image
# Fast builds — only rebuilds when code changes
# ============================================
ARG BASE_IMAGE=avuz-roundcube-base:latest
FROM ${BASE_IMAGE}

WORKDIR /var/www/roundcube

# Copy Roundcube source
COPY --chown=www-data:www-data . /var/www/roundcube/

# Install Composer dependencies
# Roundcube ships composer.json-dist — copy it before running install
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN cp composer.json-dist composer.json \
  && composer install --no-dev --optimize-autoloader --no-scripts \
  && rm -rf /root/.composer

# Copy our custom plugins and skin into public_html (Roundcube 1.6 web root)
RUN cp -r plugins/nextcloud_sso public_html/plugins/ \
  && cp -r skins/avuz public_html/skins/

# Remove dev/unneeded files
RUN rm -rf .git tests .github Dockerfile Dockerfile.base scripts \
  customizations.json CLAUDE.md docker-compose.yml

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
