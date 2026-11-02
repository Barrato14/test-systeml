FROM php:8.2-apache

# Enable Apache rewrite module if needed
RUN a2enmod rewrite

# Copy project files into Apache's web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html