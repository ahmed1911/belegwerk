FROM php:8.4-cli AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

FROM base AS vendor
WORKDIR /app
RUN curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

FROM base
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY public ./public
COPY src ./src
ENV PHP_CLI_SERVER_WORKERS=4 \
    GOTENBERG_URL=http://gotenberg:3000
EXPOSE 8080
USER www-data
CMD ["php", "-d", "memory_limit=256M", "-S", "0.0.0.0:8080", "-t", "public"]
