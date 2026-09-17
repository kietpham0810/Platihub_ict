<?php
// cli/seed_crawl_categories.php
// Ghi/đè cây danh mục cào lọc (dropdown Danh mục -> Nhóm -> Hãng -> Dòng con)
// vào MongoDB collection `configs`, meta_key='crawl_category_tree'. Frontend
// đọc qua get_configs.php (đã có sẵn, trả toàn bộ configs theo meta_key).
// Chạy: php cli/seed_crawl_categories.php
// An toàn chạy lại nhiều lần (upsert - ghi đè toàn bộ cây mỗi lần chạy).

require_once __DIR__ . '/../config/database.php';

$tree = [
    'Laptop' => [
        'label' => 'Laptop Gaming - Đồ Họa',
        'url' => 'https://www.anphatpc.com.vn/laptop-gaming-do-hoa.html',
        'children' => [
            [
                'label' => 'Laptop Gaming',
                'children' => [
                    [
                        'label' => 'Acer',
                        'url' => 'https://www.anphatpc.com.vn/laptop-acer-gaming_dm1675.html',
                        'children' => [
                            ['label' => 'Acer Aspire 7', 'url' => 'https://www.anphatpc.com.vn/laptop-acer-aspire-7_dm1786.html'],
                            ['label' => 'Acer Nitro', 'url' => 'https://www.anphatpc.com.vn/laptop-gaming-nitro.html'],
                            ['label' => 'Acer Predator Helios Neo', 'url' => 'https://www.anphatpc.com.vn/laptop-acer-predator-helios-neo.html'],
                            ['label' => 'Acer Nitro Propanel', 'url' => 'https://www.anphatpc.com.vn/laptop-gaming-acer-nitro-propanel.html'],
                            ['label' => 'Acer Helios Neo 16', 'url' => 'https://www.anphatpc.com.vn/laptop-acer-helios-neo-16.html'],
                        ],
                    ],
                    [
                        'label' => 'Asus',
                        'url' => 'https://www.anphatpc.com.vn/laptop-asus-gaming_dm1684.html',
                        'children' => [
                            ['label' => 'Asus TUF Gaming', 'url' => 'https://www.anphatpc.com.vn/asus-tuf-gaming-series_dm1821.html'],
                            ['label' => 'Asus ROG', 'url' => 'https://www.anphatpc.com.vn/republic-of-gamers_dm1062.html'],
                            ['label' => 'Asus Zephyrus', 'url' => 'https://www.anphatpc.com.vn/asus-zephyrus-gaming_dm1822.html'],
                            ['label' => 'Asus Vivobook Gaming', 'url' => 'https://www.anphatpc.com.vn/asus-vivobook-gaming.html'],
                        ],
                    ],
                    ['label' => 'Gigabyte', 'url' => 'https://www.anphatpc.com.vn/laptop-gaming-gigabyte.html'],
                    [
                        'label' => 'Lenovo',
                        'url' => 'https://www.anphatpc.com.vn/laptop-lenovo-gaming_dm1901.html',
                        'children' => [
                            ['label' => 'Lenovo LOQ', 'url' => 'https://www.anphatpc.com.vn/lenovo-loq.html'],
                        ],
                    ],
                    [
                        'label' => 'MSI',
                        'url' => 'https://www.anphatpc.com.vn/laptop-gaming-msi_dm2406.html',
                        'children' => [
                            ['label' => 'MSI Katana', 'url' => 'https://www.anphatpc.com.vn/laptop-msi-katana.html'],
                            ['label' => 'MSI Thin', 'url' => 'https://www.anphatpc.com.vn/laptop-msi-thin.html'],
                            ['label' => 'MSI Crosshair', 'url' => 'https://www.anphatpc.com.vn/laptop-msi-crosshair.html'],
                            ['label' => 'MSI Raider', 'url' => 'https://www.anphatpc.com.vn/laptop-msi-raider.html'],
                            ['label' => 'MSI Vector', 'url' => 'https://www.anphatpc.com.vn/laptop-msi-vector.html'],
                            ['label' => 'MSI Titan', 'url' => 'https://www.anphatpc.com.vn/laptop-msi-titan.html'],
                        ],
                    ],
                    [
                        'label' => 'HP',
                        'url' => 'https://www.anphatpc.com.vn/laptop-hp-gaming_dm1657.html',
                        'children' => [
                            ['label' => 'HP Victus', 'url' => 'https://www.anphatpc.com.vn/laptop-hp-victus.html'],
                        ],
                    ],
                    [
                        'label' => 'Dell',
                        'url' => 'https://www.anphatpc.com.vn/laptop-dell-gaming_dm1672.html',
                        'children' => [
                            ['label' => 'Dell Gaming G15', 'url' => 'https://www.anphatpc.com.vn/laptop-dell-gaming-g15.html'],
                        ],
                    ],
                ],
            ],
            [
                'label' => 'Laptop Đồ Họa',
                'children' => [
                    ['label' => 'Acer', 'url' => 'https://www.anphatpc.com.vn/laptop-do-hoa-acer.html'],
                    ['label' => 'Asus', 'url' => 'https://www.anphatpc.com.vn/laptop-do-hoa-asus.html'],
                    ['label' => 'Dell', 'url' => 'https://www.anphatpc.com.vn/laptop-do-hoa-dell.html'],
                    ['label' => 'HP', 'url' => 'https://www.anphatpc.com.vn/laptop-do-hoa-hp.html'],
                    ['label' => 'Lenovo', 'url' => 'https://www.anphatpc.com.vn/laptop-do-hoa-lenovo.html'],
                    ['label' => 'MSI', 'url' => 'https://www.anphatpc.com.vn/laptop-do-hoa-msi.html'],
                ],
            ],
        ],
    ],
];

$database = new Database();
$db = $database->getConnection();

$json = json_encode($tree, JSON_UNESCAPED_UNICODE);

$db->configs->updateOne(
    ['meta_key' => 'crawl_category_tree'],
    ['$set' => ['meta_key' => 'crawl_category_tree', 'meta_value' => $json]],
    ['upsert' => true]
);

echo "Đã ghi cây danh mục cào lọc (crawl_category_tree) vào MongoDB.\n";
echo "Số danh mục lớn: " . count($tree) . "\n";
