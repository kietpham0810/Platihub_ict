# Sử dụng Image PHP 8.2 tích hợp sẵn Apache
FROM php:8.2-apache

# Cài đặt extension PDO MySQL và GD (xử lý/xóa watermark ảnh khi cào)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install pdo pdo_mysql gd

# Bật module rewrite và headers của Apache
RUN a2enmod rewrite headers

# Copy toàn bộ mã nguồn Backend vào thư mục web của Docker
COPY . /var/www/html/

# Cấp quyền đọc/ghi cho thư mục
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Mở cổng 80
EXPOSE 80