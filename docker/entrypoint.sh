#!/bin/sh
set -e

# Apache must listen on the port the platform assigns.
PORT="${PORT:-80}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Sessions and the logo live on disk; make sure Apache can write them.
mkdir -p /var/www/html/assets/uploads
chown -R www-data:www-data /var/www/html/assets/uploads

exec "$@"
