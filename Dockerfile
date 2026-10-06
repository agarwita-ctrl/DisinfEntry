# DisinfEntry - PHP 8.2 + Apache, for Railway / Render / any Docker host.
FROM php:8.2-apache

# pdo_mysql is the only extension the app needs that the base image lacks
# (mbstring, session, json etc. are already built in). ZipArchive is not needed:
# the Excel export falls back to its own zip writer.
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Let the app's .htaccess files take effect (config/includes protection, headers).
COPY docker/apache.conf /etc/apache2/conf-available/disinfentry.conf
RUN a2enconf disinfentry

COPY . /var/www/html/

# Uploads (the logo) must be writable by Apache. Mount a volume here to keep them
# across deploys.
RUN mkdir -p /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/assets/uploads

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Railway injects PORT; the entrypoint makes Apache listen on it (80 if unset).
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
