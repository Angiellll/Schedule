# 使用 PHP 8.1 CLI
FROM php:8.1-cli

WORKDIR /app

# 複製專案檔案到容器
COPY . /app

# 安裝 zip (如果需要) 和 curl
RUN apt-get update && apt-get install -y libzip-dev curl unzip && \
    docker-php-ext-install zip

# 暴露 Render 會使用的端口
EXPOSE 10000

# CMD 綁定 $PORT 環境變數，Render 自動提供
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app"]
