FROM php:8.4-fpm

# System deps
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev libzip-dev libpng-dev libonig-dev \
    libxml2-dev nginx supervisor procps docker.io docker-compose sudo cron \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pgsql zip mbstring exif pcntl bcmath gd xml

# Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy app
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Install Node.js, build front-end assets, install Claude Code CLI
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm ci \
    && npm run build \
    && rm -rf node_modules \
    && npm install -g @anthropic-ai/claude-code \
    && rm -rf /var/lib/apt/lists/*

# Copy configs
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zeniclaw.ini
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/update-helper.sh /usr/local/bin/zeniclaw-update
RUN chmod +x /entrypoint.sh /usr/local/bin/zeniclaw-update \
    && echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/zeniclaw-update" > /etc/sudoers.d/zeniclaw-update \
    && chmod 0440 /etc/sudoers.d/zeniclaw-update

# Version file for health check
RUN echo "2.6.0" > storage/app/version.txt

# Storage permissions
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
