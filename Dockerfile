FROM php:8.4-fpm

# Proxy support for enterprise environments
ARG HTTP_PROXY=""
ARG HTTPS_PROXY=""
ARG NO_PROXY="localhost,127.0.0.1,db,redis,waha,ollama,app"
ENV http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY} \
    NO_PROXY=${NO_PROXY}

# System deps + Docker CLI (for self-update via docker.sock)
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev libzip-dev libpng-dev libonig-dev \
    libxml2-dev nginx supervisor procps sudo cron \
    && ARCH=$(uname -m) \
    && (curl -fsSL --retry 3 --retry-delay 5 -o /tmp/docker.tgz \
       https://download.docker.com/linux/static/stable/${ARCH}/docker-27.5.1.tgz \
    && tar xzf /tmp/docker.tgz --strip-components=1 -C /usr/local/bin docker/docker \
    && rm -f /tmp/docker.tgz \
    || echo "WARN: Docker CLI download failed (arch=${ARCH}) — skipping, self-update will use host docker") \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pgsql zip mbstring exif pcntl bcmath gd xml

# Redis extension (compile from source when behind proxy, pecl otherwise)
RUN if [ -n "$HTTP_PROXY" ]; then \
        curl -fsSL -o /tmp/redis.tar.gz https://github.com/phpredis/phpredis/archive/refs/tags/6.1.0.tar.gz && \
        mkdir -p /tmp/phpredis && tar xzf /tmp/redis.tar.gz -C /tmp/phpredis --strip-components=1 && \
        cd /tmp/phpredis && phpize && ./configure && make -j$(nproc) && make install && \
        rm -rf /tmp/phpredis /tmp/redis.tar.gz; \
    else \
        pecl install redis; \
    fi && docker-php-ext-enable redis

# Composer (download from GitHub — getcomposer.org may be blocked by proxy)
RUN curl -fsSL -o /usr/bin/composer https://github.com/composer/composer/releases/latest/download/composer.phar \
    && chmod +x /usr/bin/composer

WORKDIR /var/www/html

# Copy app
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Install Node.js + npm, build front-end assets, install Claude Code CLI
RUN apt-get update \
    && apt-get install -y nodejs npm \
    && npm ci \
    && npm run build \
    && rm -rf node_modules \
    && npm install -g @anthropic-ai/claude-code \
    && rm -rf /var/lib/apt/lists/*

# Copy configs
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/nginx/chat.conf /etc/nginx/conf.d/chat.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zeniclaw.ini
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/update-helper.sh /usr/local/bin/zeniclaw-update
RUN chmod +x /entrypoint.sh /usr/local/bin/zeniclaw-update \
    && echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/zeniclaw-update" > /etc/sudoers.d/zeniclaw-update \
    && chmod 0440 /etc/sudoers.d/zeniclaw-update

# Version file for health check
RUN echo "2.31.0" > storage/app/version.txt

# Storage permissions
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Clear proxy env vars at runtime (they're only needed at build time)
ENV http_proxy="" https_proxy="" HTTP_PROXY="" HTTPS_PROXY=""

EXPOSE 80 8888

ENTRYPOINT ["/entrypoint.sh"]
