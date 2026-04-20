#!/bin/sh
set -e

echo "date.timezone=\"${TZ:-UTC}\"" > "${PHP_INI_DIR}/conf.d/timezone.ini"

mkdir -p \
    /var/www/storage/database \
    /var/www/storage/files \
    /var/www/storage/gear-maintenance \
    /var/www/build/html \
    /var/www/build/api \
    /var/www/var/cache/dev \
    /var/www/var/log

if [ -n "$PUID" ] && [ -n "$PGID" ] && [ "$(id -u)" = "0" ]; then
    echo "Setting permissions for PUID=$PUID PGID=$PGID..."

    chown -R "$PUID:$PGID" \
        /var/www \
        /config/caddy \
        /data/caddy || true

    echo "Permissions have been set"
fi

chmod -R a+rwX /var/www/storage /var/www/build /var/www/var || true

if [ -f /var/www/bin/console ] && [ -d /var/www/vendor ]; then
    php /var/www/bin/console doctrine:migrations:migrate --no-interaction
fi

exec "$@"
