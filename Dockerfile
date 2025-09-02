FROM php:8.1-cli

WORKDIR /app

COPY . /app

RUN apt-get update && apt-get install -y libzip-dev curl unzip && \
    docker-php-ext-install zip

EXPOSE 10000

CMD ["php", "-S", "0.0.0.0:10000"]
