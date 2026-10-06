#!/bin/sh
set -e

# Apache must listen on the port the platform assigns.
PORT="${PORT:-80}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Sessions and the logo live on disk; make sure Apache can write them.
mkdir -p /var/www/html/assets/uploads
chown -R www-data:www-data /var/www/html/assets/uploads

# --- Apache MPM -----------------------------------------------------------
# mod_php needs exactly one MPM, prefork. "AH00534: More than one MPM loaded" means
# another one is enabled too. Fix it here, at start-up, whatever the build left behind,
# and print what was found so the deploy log shows the state either way.
MODS=/etc/apache2/mods-enabled
echo "[entrypoint] port ${PORT}"
echo "[entrypoint] MPM modules enabled before: $(ls $MODS | grep '^mpm_' | tr '\n' ' ' || true)"
for m in event worker; do
    rm -f "$MODS/mpm_$m.load" "$MODS/mpm_$m.conf"
done
for ext in load conf; do
    [ -e "$MODS/mpm_prefork.$ext" ] || ln -s "../mods-available/mpm_prefork.$ext" "$MODS/mpm_prefork.$ext"
done
echo "[entrypoint] MPM modules enabled after:  $(ls $MODS | grep '^mpm_' | tr '\n' ' ' || true)"
# Any other place that loads an MPM would still conflict - show it.
grep -rIn "LoadModule mpm_" /etc/apache2 2>/dev/null | grep -v "/mods-available/" || true

# Validate before starting. If it is still broken, the reason is printed here, in the
# deploy log, instead of the container just disappearing.
echo "[entrypoint] checking Apache configuration..."
apache2ctl configtest
echo "[entrypoint] configuration OK, starting Apache"

# Installed as /usr/local/bin/apache2-foreground; the image's original is renamed.
exec apache2-foreground.real "$@"
