# DisinfEntry - PHP 8.2 + Apache, for Railway / Render / any Docker host.
FROM php:8.2-apache

# pdo_mysql is the only extension the app needs that the base image lacks
# (mbstring, session, json etc. are already built in). ZipArchive is not needed:
# the Excel export falls back to its own zip writer.
# mod_php needs the prefork MPM. Make that explicit: if another MPM is also loaded
# Apache refuses to start ("AH00534: More than one MPM loaded") and the container
# crashes straight away.
RUN docker-php-ext-install pdo_mysql \
    && (a2dismod mpm_event mpm_worker || true) \
    && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Let the app's .htaccess files take effect (config/includes protection, headers).
COPY docker/apache.conf /etc/apache2/conf-available/disinfentry.conf
RUN a2enconf disinfentry

COPY . /var/www/html/

# Uploads (the logo) must be writable by Apache. Mount a volume here to keep them
# across deploys.
RUN mkdir -p /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/assets/uploads

# Wrap the image's own start script instead of adding an ENTRYPOINT. The wrapper
# (docker/entrypoint.sh) binds Apache to Railway's PORT, repairs the MPM set-up and
# validates the config, then runs the real apache2-foreground. Because it takes over
# the name `apache2-foreground`, it also runs when a platform setting (a custom
# "start command") launches that name directly, which an ENTRYPOINT would not cover.
RUN test -x /usr/local/bin/apache2-foreground \
    && mv /usr/local/bin/apache2-foreground /usr/local/bin/apache2-foreground.real
COPY docker/entrypoint.sh /usr/local/bin/apache2-foreground
RUN chmod +x /usr/local/bin/apache2-foreground

CMD ["apache2-foreground"]
