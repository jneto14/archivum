# syntax=docker/dockerfile:1

# Archivum production image.
#
# One image serves all three roles — web, queue worker, scheduler — and they
# differ only by the command in `compose.prod.yaml`. That is deliberate rather
# than economical: attachment text extraction runs on the queue, so a worker
# without tesseract, poppler and ghostscript would report every attachment as
# unreadable while the web container looked perfectly healthy.
#
# This is NOT the image in `docker/8.5`. That one is Sail's development
# runtime, published during ARC-84 to get the OCR binaries into local dev, and
# it carries `artisan serve`, Node, xdebug, pcov and two database clients.

# Pinned to the FrankenPHP major, not to a minor: `1.11-php8.5` is frozen on
# PHP 8.5.3 while `1-php8.5` tracks 8.5.9, so pinning tighter here means
# shipping known-old PHP patches. Override the arg to pin harder for a release.
ARG FRANKENPHP_VERSION=1-php8.5


# ---------------------------------------------------------------------------
# Stage 1 — build the PHP dependencies and the frontend bundle.
#
# Both happen here, in one stage with PHP available, because `vite build` runs
# the Wayfinder plugin, which shells out to `php artisan wayfinder:generate`.
# A Node-only stage cannot build this frontend.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS build

WORKDIR /app

# The extensions artisan needs to boot during the build. `imagick` is not
# optional even here: `spatie/pdf-to-image` declares ext-imagick as a platform
# requirement, so `composer install` refuses to resolve without it.
RUN install-php-extensions imagick pdo_mysql intl gd bcmath zip exif

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Dependencies before source, so editing application code does not re-resolve
# them. `--no-scripts` because package discovery needs the app files, which
# arrive in the next layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# Package discovery and the Wayfinder generator both boot the framework, which
# wants these directories to exist even though nothing here survives the stage.
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump \
    && npm run build


# ---------------------------------------------------------------------------
# Stage 2 — the runtime.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS runtime

WORKDIR /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    SERVER_NAME=":80"

RUN install-php-extensions \
        imagick \
        redis \
        pdo_mysql \
        intl \
        gd \
        bcmath \
        zip \
        exif \
        pcntl \
        opcache

# Attachment text extraction shells out to these; they are binaries, not PHP
# extensions, and composer does not bring them. The same list lives in
# `docker/8.5/Dockerfile` and `.github/workflows/tests.yml` —
# `OcrToolchainParityTest` fails if the three ever drift apart.
#
#   tesseract-ocr + language packs  OCR for scans and photos. Without a pack
#                                   tesseract runs and recognises nothing.
#   poppler-utils                   pdftotext, to read a PDF's embedded text
#                                   layer before falling back to OCR.
#   ghostscript                     Imagick's PDF delegate, for rasterizing
#                                   scanned PDFs into images to OCR.
#
# Unlike Ubuntu, Debian trixie ships no ImageMagick policy blocking PDFs, so
# there is nothing to unpick here — see the sed step in the CI workflow.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        tesseract-ocr \
        tesseract-ocr-eng \
        tesseract-ocr-por \
        poppler-utils \
        ghostscript \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-archivum.ini
COPY docker/production/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/production/entrypoint.sh /usr/local/bin/archivum-entrypoint

COPY --from=build /app/vendor ./vendor
COPY --from=build /app/public/build ./public/build
COPY . .

# Runs unprivileged. The capability is what lets a non-root process still bind
# port 80; /data and /config are Caddy's own state.
RUN useradd --system --create-home --uid 10001 archivum \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chmod +x /usr/local/bin/archivum-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R archivum:archivum /app/storage /app/bootstrap/cache /data /config

USER archivum

EXPOSE 80

# The base image probes Caddy's admin endpoint on :2019, which the Caddyfile
# turns off — so the inherited check can only ever fail. `/up` is Laravel's own
# health route, registered in bootstrap/app.php, and it exercises PHP rather
# than just the listener. The worker and scheduler run no web server at all and
# switch this off in compose.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["archivum-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
