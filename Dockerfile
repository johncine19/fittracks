FROM php:8.2-apache

# Install dependencies, GD (with FreeType, JPEG, WebP), PDO MySQL, and EXIF
RUN apt-get update && apt-get install -y \
    zip unzip git curl ca-certificates \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd exif pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy project files
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Install PHP dependencies via Composer (no dev dependencies, optimized autoloader)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set correct permissions
RUN mkdir -p /var/www/html/storage /var/www/html/assets/uploads /var/www/html/assets/permits \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/assets/uploads /var/www/html/assets/permits

EXPOSE 80
