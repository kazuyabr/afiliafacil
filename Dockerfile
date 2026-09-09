FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install dom xml zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . .

EXPOSE 9876

CMD ["php", "-S", "0.0.0.0:9876", "router.php"]
