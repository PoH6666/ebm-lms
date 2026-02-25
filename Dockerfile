FROM php:8.2-apache

# Enable mysqli extension
RUN docker-php-ext-install mysqli

# Copy all files to Apache web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Railway uses PORT env variable — configure Apache to use it
RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]
