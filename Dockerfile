
FROM php:8.2-apache

# Install required system packages and PostgreSQL dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libpq-dev \
    git \
    unzip \
    && docker-php-ext-install curl pdo pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache URL rewriting
RUN a2enmod rewrite

# Set index.php as the default landing page
RUN echo "DirectoryIndex index.php index.html" \
    > /etc/apache2/conf-available/directory-index.conf \
    && a2enconf directory-index

# Copy all project files into Apache's web root
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Diagnose PWA files during build
RUN echo "===== PWA FILE CHECK =====" \
    && ls -lah /var/www/html/pwa-icons/ \
    && ls -lah /var/www/html/manifest.json \
    && ls -lah /var/www/html/sw.js \
    && ls -lah /var/www/html/js/pwa.js \
    && echo "===== END CHECK ====="

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html

# Configure Apache for Render
ENV PORT=10000

RUN sed -i 's/80/${PORT}/g' \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/ports.conf

EXPOSE 10000

CMD ["apache2-foreground"]
