FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        git \
        unzip \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_mysql \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm"]
