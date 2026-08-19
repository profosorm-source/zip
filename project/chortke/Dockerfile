FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    git \
    curl \
    dcron \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    zlib-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    $PHPIZE_DEPS

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        opcache \
        gd \
        intl \
        bcmath

# Install and enable Redis via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy custom PHP configurations
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini

# Copy composer files and install dependencies
COPY composer.json composer.lock* /var/www/html/
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-interaction

# Copy application code
COPY --chown=www-data:www-data . /var/www/html

# Set proper execution permissions for storage
RUN mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/storage/sessions /var/www/html/storage/exports /var/www/html/storage/backups /var/www/html/storage/email_fallback \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 700 /var/www/html/storage

USER www-data

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]
