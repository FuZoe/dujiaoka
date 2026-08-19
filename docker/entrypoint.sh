#!/bin/sh
set -eu

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs public/uploads/images public/uploads/files /tmp/logs
chown -R application:application storage public/uploads bootstrap/cache
chmod -R ug+rwX storage public/uploads bootstrap/cache
# yansongda/pay writes its diagnostic log to the system temp directory by
# default. PHP-FPM runs as application, so make that directory writable on
# every container start instead of relying on an image-layer owner.
chown -R application:application /tmp/logs
chmod -R ug+rwX /tmp/logs

# Older deployment images may start without all of Dcat's published assets.
if [ ! -s public/vendor/dcat-admin/adminlte/adminlte.css ] \
    || [ ! -s public/vendor/dcat-admin/dcat/css/dcat-app.css ] \
    || [ ! -s public/vendor/dcat-admin/dcat/plugins/vendors.min.js ] \
    || [ ! -s public/vendor/dcat-admin/dcat/js/dcat-app.js ]; then
    php artisan admin:publish --assets --force
fi

test -s public/vendor/dcat-admin/adminlte/adminlte.css
test -s public/vendor/dcat-admin/dcat/css/dcat-app.css
test -s public/vendor/dcat-admin/dcat/plugins/vendors.min.js
test -s public/vendor/dcat-admin/dcat/js/dcat-app.js

attempt=0
until php artisan newzoe:bootstrap; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Shop bootstrap failed after ${attempt} attempts" >&2
        exit 1
    fi
    sleep 3
done

php artisan migrate --force
php artisan newzoe:bootstrap
php artisan view:clear
php artisan route:clear
exec supervisord
