FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    zlib1g-dev \
    libonig-dev \
    libxml2-dev \
    curl \
    nginx \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring zip exif pcntl bcmath gd

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy app files
COPY . /app

# Install PHP deps
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Set permissions for Laravel (may need adjustment depending on environment)
RUN mkdir -p storage/framework cache && chown -R www-data:www-data /app/storage /app/bootstrap/cache || true

# Generate key (will be overridden by env var if set)
RUN php artisan key:generate --ansi || true

# Expose port used by php artisan serve
EXPOSE 8000

# Default start command
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]
