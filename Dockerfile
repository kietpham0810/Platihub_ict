# Sử dụng Image PHP 8.2 tích hợp sẵn Apache
FROM php:8.2-apache

# Cài đặt extension PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Bật module rewrite và headers của Apache
RUN a2enmod rewrite headers

# Copy toàn bộ mã nguồn Backend vào thư mục web của Docker
COPY . /var/www/html/

# Cấp quyền đọc/ghi cho thư mục
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Mở cổng 80
EXPOSE 80