FROM php:8.5-cli-alpine

RUN apk add --no-cache icu-libs libpng libzip oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpng-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd intl \
    && apk del .build-deps

COPY docker-compose/crontab /etc/crontabs/root
CMD ["crond", "-f"]
