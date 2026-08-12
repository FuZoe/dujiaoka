FROM webdevops/php-nginx:7.4
ENV WEB_DOCUMENT_ROOT=/app/public \
    TZ=Asia/Shanghai
WORKDIR /app
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY . /app
COPY docker/supervisor-queue.conf /opt/docker/etc/supervisor.d/laravel-queue.conf
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --ignore-platform-reqs --optimize-autoloader \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs public/uploads \
    && php artisan admin:publish --assets --force \
    && touch install.lock \
    && chmod +x /app/docker/entrypoint.sh \
    && chown -R application:application storage public/uploads bootstrap/cache \
    && chmod -R ug+rwX storage public/uploads bootstrap/cache
EXPOSE 80
CMD ["/app/docker/entrypoint.sh"]
