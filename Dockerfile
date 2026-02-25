FROM php:8.2-apache

# Fix Apache MPM conflict
RUN a2dismod mpm_event mpm_worker 2>/dev/null; a2enmod mpm_prefork

# Enable mysqli extension
RUN docker-php-ext-install mysqli

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy all files to Apache web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
