# Use official PHP image with Apache
FROM php:8.2-apache

# Install dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    cron \
    && docker-php-ext-install curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy the PHP script to the Apache document root
COPY index.php /var/www/html/index.php

# Set permissions for the web server
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Copy cron job configuration
COPY crontab /etc/cron.d/jwt-update
RUN chmod 0644 /etc/cron.d/jwt-update \
    && crontab /etc/cron.d/jwt-update

# Expose port 80 for Apache
EXPOSE 80

# Start Apache and cron in the foreground
CMD cron && apache2-foreground
