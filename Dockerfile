FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install dom xml zip pdo_pgsql pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts || composer update --no-dev --no-interaction --prefer-dist --no-scripts

COPY . .

EXPOSE 9876

CMD ["sh", "-c", "php bin/migrate.php; php -S 0.0.0.0:9876 router.php"]
