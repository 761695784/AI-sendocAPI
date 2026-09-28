FROM php:8.3-cli

RUN apt-get update -y && apt-get install -y --no-install-recommends \
        git unzip zip libzip-dev libonig-dev libcurl4-openssl-dev pkg-config libssl-dev \
        libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev mariadb-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring sockets pcntl zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

# Dépendances figées par composer.lock (aucun "composer require" ici).
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 8181

# APP_KEY et JWT_SECRET doivent être fournis par l'environnement (.env ou variables),
# JAMAIS régénérés au démarrage : cela invaliderait les sessions et les données chiffrées.
# Les migrations sont appliquées sans jamais vider la base (pas de migrate:refresh).
CMD php artisan migrate --force \
    && (php artisan storage:link || true) \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8181
