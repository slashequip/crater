FROM php:8.5-cli-alpine

RUN apk add --no-cache icu-libs libpng libzip oniguruma mariadb-client \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpng-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd intl \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /var/www
COPY uffizzi/.env.example /var/www/.env
COPY docker-compose/crontab /etc/crontabs/root
WORKDIR /var/www
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
CMD ["crond", "-f"]
