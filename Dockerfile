FROM php:8.2-cli

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

# Set working directory
WORKDIR /app

# Copy application files
COPY . /app

# Create necessary directories
RUN mkdir -p /app/file_storage /app/logs \
    && chmod -R 777 /app/file_storage /app/logs

# Expose port
EXPOSE 8080

# Start PHP built-in server using shell to expand $PORT
CMD php -S 0.0.0.0:${PORT:-8080}
