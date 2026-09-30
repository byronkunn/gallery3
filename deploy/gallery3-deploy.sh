#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR=/var/www/gallery3
cd "$APP_DIR"

/usr/bin/git config --global --add safe.directory "$APP_DIR" >/dev/null 2>&1 || true
/usr/bin/git pull --ff-only origin main
/usr/bin/composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
/usr/bin/npm ci --ignore-scripts
/usr/bin/npm run build
/usr/bin/php artisan migrate --force
/usr/bin/php artisan optimize:clear
/usr/bin/php artisan config:cache
/usr/bin/php artisan route:cache
/usr/bin/php artisan view:cache
/usr/bin/chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
/usr/bin/systemctl restart php8.5-fpm
/usr/bin/systemctl restart gallery3-queue
/usr/bin/systemctl restart gallery3-reverb
