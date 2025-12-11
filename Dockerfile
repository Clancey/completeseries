FROM php:8.2-apache

# Install curl extension for server-side API calls
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Create data directory for persistent storage
RUN mkdir -p /data && chown www-data:www-data /data && chmod 755 /data

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/

EXPOSE 80

CMD ["apache2-foreground"]
