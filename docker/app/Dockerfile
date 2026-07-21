FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libicu-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
        exif \
        gd \
        intl \
        pdo_pgsql \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/app/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
