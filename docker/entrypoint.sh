#!/bin/sh
set -e

# Apache must listen on the port the platform assigns.
PORT="${PORT:-80}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Sessions and the logo live on disk; make sure Apache can write them.
mkdir -p /var/www/html/assets/uploads
chown -R www-data:www-data /var/www/html/assets/uploads

# Validate the configuration before starting. If it is broken, the reason is printed
# here, in the deploy log, instead of the container just disappearing.
echo "[entrypoint] listening on port ${PORT}; checking Apache configuration..."
apache2ctl configtest
echo "[entrypoint] configuration OK, starting Apache"

exec "$@"
