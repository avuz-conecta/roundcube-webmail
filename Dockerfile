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

# Pull pre-compiled CSS and JS from the official release tarball.
# The git repo only has LESS/source files; compiled assets are release-only.
ARG RC_VERSION=1.6.14
RUN curl -sL "https://github.com/roundcube/roundcubemail/releases/download/${RC_VERSION}/roundcubemail-${RC_VERSION}-complete.tar.gz" \
    -o /tmp/rc-release.tar.gz \
  && tar -xzf /tmp/rc-release.tar.gz -C /tmp \
  && rsync -a --include="*.min.js" --include="*.min.css" --include="*/" --exclude="*" \
      /tmp/roundcubemail-${RC_VERSION}/ /var/www/roundcube/ \
  && rm -rf /tmp/rc-release.tar.gz /tmp/roundcubemail-${RC_VERSION}

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
