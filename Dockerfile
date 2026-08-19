FROM php:8.2-apache

# Install PDO MySQL and zip (needed by Composer)
RUN apt-get update && apt-get install -y \
    zip unzip git curl ca-certificates \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean

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
RUN mkdir -p /var/www/html/storage /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/assets/uploads

EXPOSE 80
