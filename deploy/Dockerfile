FROM php:8.4-fpm-alpine
RUN apk add --no-cache libpq-dev sqlite-dev oniguruma-dev libzip-dev icu-dev linux-headers $PHPIZE_DEPS && docker-php-ext-install -j2 pdo_pgsql pdo_sqlite mbstring pcntl bcmath intl zip opcache && pecl install redis && docker-php-ext-enable redis
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
