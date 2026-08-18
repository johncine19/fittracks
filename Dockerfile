FROM php:8.2-apache

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy project files
COPY . /var/www/html/

# Set correct permissions
WORKDIR /var/www/html
RUN mkdir -p /var/www/html/storage /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/assets/uploads

EXPOSE 80
