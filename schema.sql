-- Schema suy ra từ code PHP (api/*.php) - không có file migration gốc.
-- Chạy tự động bởi MySQL image khi container "db" khởi tạo lần đầu
-- (thư mục /docker-entrypoint-initdb.d chỉ chạy khi volume dữ liệu còn trống).

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(500) NOT NULL,
    image_url TEXT,
    description TEXT,
    manufacturer VARCHAR(255),
    product_type VARCHAR(100),
    price DECIMAL(15, 0) DEFAULT NULL,
    is_price_visible TINYINT(1) NOT NULL DEFAULT 0,
    specifications JSON DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending' | 'approved'
    source VARCHAR(20) NOT NULL DEFAULT 'manual',  -- 'manual' | 'bot'
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS configs (
    meta_key VARCHAR(100) PRIMARY KEY,
    meta_value TEXT
);

-- Vài sản phẩm mẫu để test FE ngay không cần chạy bot crawl
INSERT INTO products (product_name, image_url, description, manufacturer, product_type, price, is_price_visible, status, source)
VALUES
    ('PC Gaming Mẫu i5-12400F / RTX 3060', 'https://placehold.co/600x400?text=Sample+PC', 'Sản phẩm mẫu để test giao diện local.', 'Platihub', 'PC', 15000000, 1, 'approved', 'manual'),
    ('Laptop Mẫu ThinkPad', 'https://placehold.co/600x400?text=Sample+Laptop', 'Sản phẩm mẫu để test giao diện local.', 'Lenovo', 'Laptop', 12000000, 1, 'approved', 'manual');
