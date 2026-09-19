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
        qrencode \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
        exif \
        gd \
        intl \
        pcntl \
        pdo_pgsql \
        zip \
        opcache \
    && ( \
        pecl install redis-6.3.0 \
        || pecl install https://pecl.php.net/get/redis-6.3.0.tgz \
        || ( \
            git clone --depth 1 --branch 6.3.0 https://github.com/phpredis/phpredis.git /tmp/phpredis \
            && cd /tmp/phpredis \
            && phpize \
            && ./configure \
            && make -j"$(nproc)" \
            && make install \
            && cd / \
            && rm -rf /tmp/phpredis \
        ) \
    ) \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/app/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
