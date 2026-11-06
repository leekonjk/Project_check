FROM php:8.4-fpm-alpine
RUN docker-php-ext-install pdo_mysql
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN php -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);'
WORKDIR /app
