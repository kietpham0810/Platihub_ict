# Sử dụng Image PHP 8.2 tích hợp sẵn Apache
FROM php:8.2-apache

# Cài đặt extension GD (xử lý ảnh khi cào) và MongoDB (driver kết nối Atlas)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libssl-dev \
        pkg-config \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install gd \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Bật module rewrite và headers của Apache
RUN a2enmod rewrite headers

# Cài Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy toàn bộ mã nguồn Backend vào thư mục web của Docker
COPY . /var/www/html/

# Cài thư viện PHP (mongodb/mongodb...) - vendor/ không commit vào git nên phải build lại ở đây
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

# Cấp quyền đọc/ghi cho thư mục
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Mở cổng 80
EXPOSE 80