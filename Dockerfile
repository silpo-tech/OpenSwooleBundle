ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-bookworm

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends procps libzip-dev libssl-dev \
    && chmod +x /usr/local/bin/install-php-extensions && sync \
    && install-php-extensions pcntl zip sockets \
    # cleanup
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN sync && install-php-extensions openswoole

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/project
