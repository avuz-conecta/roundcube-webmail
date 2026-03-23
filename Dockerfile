# ============================================
# App image — uses pre-built base image
# Fast builds — only rebuilds when code changes
# ============================================
ARG BASE_IMAGE=avuz-roundcube-base:latest
FROM ${BASE_IMAGE}

WORKDIR /var/www/html

# Copy Roundcube source (already in repo)
COPY --chown=www-data:www-data . /var/www/html/

# Install Composer dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader --no-scripts \
  && rm -rf /root/.composer

# Remove dev/unneeded files
RUN rm -rf .git tests .github Dockerfile Dockerfile.base scripts \
  customizations.json CLAUDE.md docker-compose.yml

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
  && chmod -R 755 /var/www/html \
  && chmod -R 770 /var/www/html/logs \
  && chmod -R 770 /var/www/html/temp

# Copy runtime configs
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD curl -f http://localhost/ || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]
