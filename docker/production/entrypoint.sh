#!/bin/sh
set -e

# Caches are built here rather than in the image because they bake in the
# environment, and the environment only exists at run time. Building them at
# image time would freeze whatever placeholder values the build had.
#
# Every role runs this — web, worker and scheduler — so a worker never runs
# against a stale config cache. Only the web role migrates.

if [ "${ARCHIVUM_ROLE:-web}" = "web" ] && [ "${ARCHIVUM_MIGRATE:-true}" = "true" ]; then
    echo "==> Running migrations"
    php artisan migrate --force --no-interaction
fi

echo "==> Caching configuration, routes and views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
