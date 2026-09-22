FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install dom xml zip pdo_pgsql pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts || composer update --no-dev --no-interaction --prefer-dist --no-scripts

COPY . .

RUN printf 'upload_max_filesize=24M\npost_max_size=26M\nmax_execution_time=300\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/afiliafacil.ini

EXPOSE 9876

ENV PHP_CLI_SERVER_WORKERS=8

CMD ["sh", "-c", "php bin/migrate.php; php -S 0.0.0.0:9876 router.php"]
