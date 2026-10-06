# DisinfEntry - PHP 8.2 CLI image running PHP's built-in web server, for Railway and
# other Docker hosts.
#
# Why not Apache: the php:apache image kept failing at start-up on Railway with
# "AH00534: More than one MPM loaded". The built-in server is a single PHP process with
# no module system, so that failure cannot occur. docker/router.php re-implements the
# .htaccess protections, because the built-in server does not read .htaccess.
FROM php:8.2-cli

# pdo_mysql is the only extension the app needs that the base image lacks
# (mbstring, session, json etc. are built in). ZipArchive is not needed: the Excel
# export falls back to its own zip writer.
RUN docker-php-ext-install pdo_mysql

WORKDIR /app
COPY . /app

# Run as an unprivileged user. Uploads (the logo) must be writable by it; mount a volume
# at /app/assets/uploads to keep them across deploys.
RUN mkdir -p /app/assets/uploads \
    && chown -R www-data:www-data /app/assets/uploads
USER www-data

# The built-in server is single-process by default, so one slow request (an export,
# a booth sync) would block everyone. Several workers fix that.
ENV PHP_CLI_SERVER_WORKERS=8

# Railway injects PORT (8080 if it is somehow unset). Errors go to the log, not the page.
CMD ["sh", "-c", "exec php -d display_errors=0 -d log_errors=1 -d error_log=/dev/stderr -S 0.0.0.0:${PORT:-8080} -t /app /app/docker/router.php"]
