FROM php:8.2-cli

# Enable mysqli extension
RUN docker-php-ext-install mysqli

# Copy all files
COPY . /app

WORKDIR /app

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "/app"]
