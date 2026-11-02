FROM php:8.3-apache

# Install dependencies, apply OS security updates, configure GD (with FreeType, JPEG, WebP), PDO MySQL, and EXIF
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    zip unzip git curl ca-certificates \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd exif pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite and harden production PHP configuration
# Note: php.ini-production sets variables_order = "GPCS" (omitting E), which prevents
# $_ENV from being populated by container environment variables on Render.
# Setting variables_order = EGPCS restores $_ENV while keeping production hardening.
RUN a2enmod rewrite \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf "variables_order = EGPCS\nexpose_php = Off\ndisplay_errors = Off\ndisplay_startup_errors = Off\nlog_errors = On\nerror_log = /dev/stderr\n" > "$PHP_INI_DIR/conf.d/zz-security.ini"

# Set working directory
WORKDIR /var/www/html

# Cache optimization: install Composer dependencies before copying the full codebase.
# This layer is reused on every deploy unless composer.json / composer.lock change.
COPY composer.json composer.lock* /var/www/html/
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# Copy project files (owned by root, read-only to the www-data web server process)
COPY . /var/www/html/

# Generate the optimized autoloader now that application files are present
RUN composer dump-autoload --optimize --no-dev

# Secure permissions: ONLY runtime-writable data directories are granted to www-data
RUN mkdir -p /var/www/html/storage /var/www/html/assets/uploads /var/www/html/assets/permits /var/www/html/assets/exercise_animations \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/assets/uploads /var/www/html/assets/permits /var/www/html/assets/exercise_animations \
    && chmod -R 775 /var/www/html/storage /var/www/html/assets/uploads /var/www/html/assets/permits /var/www/html/assets/exercise_animations

EXPOSE 80
