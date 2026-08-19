# Chortke Platform - Production Docker Image
# PHP 8.4 + MariaDB client + Redis + Supervisor for workers

FROM php:8.4-cli

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpng-dev libonig-dev libxml2-dev libicu-dev \
    default-mysql-client supervisor ca-certificates curl dcron \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql mysqli zip bcmath intl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/chortke

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Copy composer first for layer caching
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy application
COPY . .

# Required directories + permissions
RUN mkdir -p storage/logs storage/cache storage/locks /var/log/chortke \
    && chown -R www-data:www-data storage /var/log/chortke \
    && chmod -R 775 storage

# Supervisor config (web + queue + outbox + dlq + cron)
COPY docker/supervisord.conf /etc/supervisor/conf.d/chortke.conf

# Entrypoint (migrations, seeding, health checks)
COPY deploy/docker/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8090

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/chortke.conf"]
