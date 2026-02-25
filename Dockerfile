FROM php:8.2-apache

# Enable mysqli
RUN docker-php-ext-install mysqli

# Copy all project files to web root
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Allow .htaccess overrides
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-enabled/override.conf

RUN a2enmod rewrite

EXPOSE 80
