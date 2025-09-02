FROM php:8.1-cli

WORKDIR /app

COPY . /app

RUN apt-get update && apt-get install -y libzip-dev && \
    docker-php-ext-install zip && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

CMD ["php", "-S", "0.0.0.0:10000", "-t", "public"]
