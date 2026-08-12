#!/bin/sh
set -eu

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs public/uploads/images public/uploads/files
chown -R application:application storage public/uploads bootstrap/cache
chmod -R ug+rwX storage public/uploads bootstrap/cache

# Dcat assets live in the image's public directory. Republish them when an
# older or incomplete image starts without the admin bundle.
if [ ! -f public/vendor/dcat-admin/dcat/css/dcat-app.css ]; then
    php artisan admin:publish --assets --force
fi

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
php artisan view:clear
php artisan route:clear
exec supervisord
