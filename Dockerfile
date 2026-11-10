FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends curl ffmpeg libcurl4-openssl-dev libonig-dev libsodium-dev \
    && docker-php-ext-install pdo_mysql curl mbstring sodium \
    && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf
COPY . /var/www/html/
RUN mkdir -p /var/www/html/storage && chown -R www-data:www-data /var/www/html/storage
CMD ["sh", "scripts/entrypoint.sh"]
