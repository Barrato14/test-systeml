FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libpq-dev \
    git \
    unzip \
    python3 \
    python3-venv \
    python3-dev \
    build-essential \
    cmake \
    libopenblas-dev \
    liblapack-dev \
    libjpeg62-turbo-dev \
    supervisor \
    && docker-php-ext-install curl pdo pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite
RUN echo "DirectoryIndex index.php index.html" \
    > /etc/apache2/conf-available/directory-index.conf \
    && a2enconf directory-index

COPY . /var/www/html/
WORKDIR /var/www/html

RUN composer install --no-dev --optimize-autoloader

RUN python3 -m venv /opt/face-venv \
    && /opt/face-venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/face-venv/bin/pip install --no-cache-dir -r /var/www/html/face-service/requirements.txt

COPY deploy/supervisord.conf /etc/supervisor/conf.d/emis.conf

RUN chown -R www-data:www-data /var/www/html

ENV PORT=10000
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost *:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000

CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
