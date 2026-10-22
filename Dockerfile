FROM php:8.2-apache

# Install MySQL extensions (for connecting to Aiven MySQL)
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache rewrite module (needed if your project uses .htaccess / pretty URLs)
RUN a2enmod rewrite

# Copy your project files into Apache's web root
COPY . /var/www/html/

# Fix permissions so Apache (www-data) can read/execute files
RUN chown -R www-data:www-data /var/www/html

# Start Apache in the foreground (this is what Render expects)
CMD ["apache2-foreground"]