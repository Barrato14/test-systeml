FROM php:8.2-apache

# Install required system packages
RUN apt-get update && apt-get install -y libcurl4-openssl-dev git unzip && docker-php-ext-install curl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache URL rewriting
RUN a2enmod rewrite

# Explicitly set index.php as the default landing page file
RUN echo "DirectoryIndex index.php index.html" > /etc/apache2/conf-available/directory-index.conf && a2enconf directory-index

# Copy all project files into Apache's web root
COPY . /var/www/html/

# Set working directory and install PHP dependencies via Composer
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

# Set correct file permissions
RUN chown -R www-data:www-data /var/www/html

# Configure Apache to listen on Render's assigned port
ENV PORT=10000
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf