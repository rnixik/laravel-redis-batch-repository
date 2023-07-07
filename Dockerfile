FROM php:8.1-cli

RUN apt-get update && apt-get install unzip libzip-dev -y \
    && pecl install -o -f redis \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
ENV PATH="${PATH}:/var/www/.composer/vendor/bin"
