FROM php:7.4-cli-alpine

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod

RUN set -xe \
    && apk update \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        pcre-dev \
        libzip-dev \
        libxml2-dev \
        zlib-dev \
    && apk add --no-cache libzip libmcrypt

RUN docker-php-ext-install zip json pcntl
RUN pecl install pcov && docker-php-ext-enable pcov
WORKDIR /var/www/app

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

RUN apk del --no-network .build-deps

COPY . /var/www/app
RUN composer install --no-dev --no-ansi --no-interaction --prefer-dist

CMD ["php", "/var/www/app/bin/console", "app:web-server", "-vv"]
