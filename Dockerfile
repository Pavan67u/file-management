FROM php:8.2-apache

# Install required PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Install additional extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Create necessary directories
RUN mkdir -p /var/www/html/file_storage /var/www/html/logs \
    && chmod -R 777 /var/www/html/file_storage /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html

# Configure Apache to listen on PORT env variable
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Expose port
EXPOSE 8080

# Start Apache
CMD ["apache2-foreground"]
