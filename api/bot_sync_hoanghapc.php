<?php
// api/bot_sync_hoanghapc.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Chống timeout và giới hạn bộ nhớ khi cào dữ liệu nặng
set_time_limit(0); 
ini_set('memory_limit', '512M'); 

require_once '../config/database.php';

// HÀM LẤY HTML CHỐNG BLOCK
function fetchHTML($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // Giả mạo trình duyệt thật để Hoàng Hà PC không chặn
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); 
    
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

function resolveAbsoluteUrl($url, $baseUrl) {
    $url = trim($url);
    if (empty($url)) {
        return '';
    }
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $baseParts = parse_url($baseUrl);
    if (empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return $url;
    }
    $scheme = $baseParts['scheme'];
    $host = $baseParts['host'];
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

    if (strpos($url, '/') === 0) {
        return "$scheme://$host$port" . $url;
    }

    $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return "$scheme://$host$port" . rtrim($dir, '/') . '/' . ltrim($url, '/');
}

function extractImageUrlFromNode($node, $baseUrl) {
    $attrs = ['content', 'data-src', 'data-original', 'data-lazy-src', 'data-lazy', 'data-srcset', 'srcset', 'src'];
    foreach ($attrs as $attr) {
        if (!$node->hasAttribute($attr)) {
            continue;
        }
        $value = trim($node->getAttribute($attr));
        if ($value === '') {
            continue;
        }
        if (stripos($value, 'data:image') === 0) {
            continue;
        }
        if ($attr === 'srcset' || $attr === 'data-srcset') {
            $parts = preg_split('/\s*,\s*/', $value);
            foreach ($parts as $part) {
                $candidate = trim(explode(' ', $part)[0]);
                if ($candidate !== '') {
                    $value = $candidate;
                    break;
                }
            }
        }
        return resolveAbsoluteUrl($value, $baseUrl);
    }
    return '';
}

function findProductImageUrl($xpath, $baseUrl) {
    $queries = [
        "//meta[@property='og:image']",
        "//meta[@property='og:image:secure_url']",
        "//meta[@name='twitter:image']",
        "//meta[@name='og:image']",
        "//div[contains(@class, 'product-image')]//img",
        "//div[contains(@class, 'gallery')]//img",
        "//div[contains(@class, 'thumb')]//img",
        "//figure//img",
        "//picture//img",
        "//img[contains(@class, 'p-image') or contains(@class, 'product-image') or contains(@class, 'p-picture') or contains(@class, 'product-gallery') or contains(@class, 'p-thumb') or contains(@class, 'slider') or contains(@class, 'lazy') or contains(@class, 'thumbnail')]",
        "//img"
    ];

    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        foreach ($nodes as $node) {
            $image = extractImageUrlFromNode($node, $baseUrl);
            if (!empty($image)) {
                return $image;
            }
        }
    }

    $styleNodes = $xpath->query("//*[contains(@style, 'background-image')]");
    foreach ($styleNodes as $node) {
        $style = $node->getAttribute('style');
        if (preg_match('/background-image\s*:\s*url\(([^)]+)\)/i', $style, $m)) {
            $url = trim($m[1], "'\" ");
            if ($url !== '' && stripos($url, 'data:image') !== 0) {
                return resolveAbsoluteUrl($url, $baseUrl);
            }
        }
    }

    return '';
}

function getPublicImageUrl($localFile) {
    $filename = basename($localFile);
    $publicPath = '/images/hoanghapc/' . $filename;
    $publicPath = preg_replace('#/+#', '/', $publicPath);

    if (!empty($_SERVER['HTTP_HOST'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $protocol . '://' . $_SERVER['HTTP_HOST'] . $publicPath;
    }
    return $publicPath;
}

function downloadRemoteImage($imageUrl, $saveDir) {
    $imageUrl = trim($imageUrl);
    if (empty($imageUrl) || stripos($imageUrl, 'data:image') === 0) {
        return null;
    }

    if (!is_dir($saveDir)) {
        @mkdir($saveDir, 0755, true);
    }
    $saveDir = rtrim($saveDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    $parsed = parse_url($imageUrl);
    $ext = pathinfo($parsed['path'] ?? '', PATHINFO_EXTENSION);
    if (!preg_match('/^[a-zA-Z0-9]{1,5}$/', $ext)) {
        $ext = 'jpg';
    }

    $filename = md5($imageUrl) . '.' . $ext;
    $localFile = $saveDir . $filename;
    if (file_exists($localFile)) {
        return getPublicImageUrl($localFile);
    }

    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
        "Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($imageData === false || $httpCode !== 200) {
        return null;
    }

    if (file_put_contents($localFile, $imageData) !== false) {
        return getPublicImageUrl($localFile);
    }
    return null;
}

// BẮT LINK TỪ FRONTEND TRUYỀN XUỐNG
$target_url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($target_url)) {
    echo json_encode(["status" => "error", "message" => "Vui lòng dán đường link Hoàng Hà PC cần cào dữ liệu!"]);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $insertedCount = 0;
    $updatedCount = 0; 
    $skippedCount = 0;
    $default_image = "https://via.placeholder.com/400x300?text=Hoang+Ha+PC"; 
    $image_save_dir = realpath(__DIR__ . '/../images/hoanghapc') ?: (__DIR__ . '/../images/hoanghapc');

    // CHUẨN BỊ VŨ KHÍ SQL
    $check_query = "SELECT id, specifications FROM products WHERE product_name = :name LIMIT 1";
    $stmt_check = $db->prepare($check_query);

    $insert_query = "INSERT INTO products 
                     (product_name, price, is_price_visible, image_url, description, manufacturer, product_type, status, source, specifications) 
                     VALUES (:name, :price, 1, :image, 'Sản phẩm đồng bộ từ Hoàng Hà PC', 'Hoàng Hà PC', 'Thiết bị máy tính', 'pending', 'bot', :specs)";
    $stmt_insert = $db->prepare($insert_query);

    $update_query = "UPDATE products SET specifications = :specs, price = :price WHERE id = :id";
    $stmt_update = $db->prepare($update_query);

    // BƯỚC 1: QUÉT LINK GỐC ĐỂ XEM LÀ DANH MỤC HAY SẢN PHẨM LẺ
    $html = fetchHTML($target_url);
    if (!$html) throw new Exception("Không thể truy cập đường link này. Web có thể đang chặn Bot.");

    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $product_links = [];

    // Tìm kiếm các khối sản phẩm (Đặc thù web Hoàng Hà PC thường dùng class p-item hoặc product-item)
    $itemNodes = $xpath->query("//div[contains(@class, 'p-item')]//a[@href] | //div[contains(@class, 'product-item')]//a[@href] | //a[contains(@class, 'p-name')]");
    
    if ($itemNodes->length > 0) {
        // CHẾ ĐỘ CÀO DANH MỤC: Gom toàn bộ link con
        foreach ($itemNodes as $node) {
            $href = $node->getAttribute('href');
            if (!empty($href)) {
                if (strpos($href, 'http') === false) {
                    $href = rtrim("https://hoanghapc.vn", "/") . "/" . ltrim($href, "/");
                }
                $product_links[] = $href;
            }
        }
        $product_links = array_unique($product_links);
        // Cắt giảm tối đa 20 link 1 lần cào để chống sập server Render
        $product_links = array_slice($product_links, 0, 20); 
    } else {
        // CHẾ ĐỘ CÀO SẢN PHẨM LẺ
        $product_links[] = $target_url;
    }

    if (empty($product_links)) {
        throw new Exception("Không tìm thấy sản phẩm nào trong link này để cào.");
    }

    // BƯỚC 2: TIẾN HÀNH THÂM NHẬP VÀ BÓC TÁCH TỪNG SẢN PHẨM
    foreach ($product_links as $link) {
        usleep(300000); // Ngủ 0.3s để tránh bị block IP
        
        $detail_html = fetchHTML($link);
        if (!$detail_html) continue;

        $detail_dom = new DOMDocument();
        @$detail_dom->loadHTML(mb_convert_encoding($detail_html, 'HTML-ENTITIES', 'UTF-8'));
        $detail_xpath = new DOMXPath($detail_dom);

        // 1. Lấy Tên PC (Thường nằm trong thẻ h1)
        $nameNode = $detail_xpath->query("//h1");
        if ($nameNode->length === 0) continue;
        $product_name = trim($nameNode->item(0)->nodeValue);

        // 2. Lấy Giá (Tìm thẻ chứa chữ 'giá' hoặc class price)
        $priceNode = $detail_xpath->query("//span[contains(@class, 'p-price')] | //strong[contains(@class, 'price')] | //span[contains(@class, 'price-detail')]");
        $price_val = 0;
        if ($priceNode->length > 0) {
            $raw_price = $priceNode->item(0)->nodeValue;
            $price_val = (int) preg_replace('/[^0-9]/', '', $raw_price);
        }
        if ($price_val == 0) $price_val = null; // Để null nếu không có giá

        // 3. Lấy Hình Ảnh (Tối ưu hóa theo nhiều nguồn: OG, Twitter, img src, srcset, data-src, background-image)
        $image_url = $default_image;
        $found_image = findProductImageUrl($detail_xpath, $link);
        if (!empty($found_image)) {
            $image_url = $found_image;
            $downloaded = downloadRemoteImage($image_url, $image_save_dir);
            if ($downloaded) {
                $image_url = $downloaded;
            }
        }

        // 4. Lấy Cấu Hình (Lọc chuẩn hóa CPU, Main, RAM, VGA...)
        $specs = [];
        $rows = $detail_xpath->query("//table//tr | //div[contains(@class, 'specifications')]//li");
        
        foreach ($rows as $row) {
            $tds = $detail_xpath->query(".//td", $row);
            $key = ""; $val = "";
            
            if ($tds->length >= 2) {
                $key = trim(strip_tags($tds->item(0)->nodeValue));
                $val = trim(strip_tags($tds->item(1)->nodeValue));
            } else {
                // Xử lý nếu web dùng dạng <li><strong>CPU:</strong> Core i5...</li>
                $text = trim(strip_tags($row->nodeValue));
                if (strpos($text, ':') !== false) {
                    $parts = explode(':', $text, 2);
                    $key = trim($parts[0]);
                    $val = trim($parts[1]);
                }
            }

            if (!empty($key) && !empty($val)) {
                // CHUẨN HÓA KEYWORD ĐỂ LỌC DỄ DÀNG SAU NÀY
                $k_upper = strtoupper($key);
                if (strpos($k_upper, 'CPU') !== false || strpos($k_upper, 'CHIP') !== false) $key = "Vi xử lý (CPU)";
                elseif (strpos($k_upper, 'MAIN') !== false || strpos($k_upper, 'BO MẠCH') !== false) $key = "Mainboard";
                elseif (strpos($k_upper, 'RAM') !== false) $key = "Bộ nhớ RAM";
                elseif (strpos($k_upper, 'VGA') !== false || strpos($k_upper, 'CARD') !== false) $key = "Card đồ họa (VGA)";
                elseif (strpos($k_upper, 'SSD') !== false || strpos($k_upper, 'HDD') !== false || strpos($k_upper, 'Ổ CỨNG') !== false) $key = "Ổ cứng lưu trữ";
                elseif (strpos($k_upper, 'NGUỒN') !== false || strpos($k_upper, 'PSU') !== false) $key = "Nguồn (PSU)";
                elseif (strpos($k_upper, 'CASE') !== false || strpos($k_upper, 'VỎ') !== false) $key = "Vỏ Case";
                elseif (strpos($k_upper, 'TẢN NHIỆT') !== false || strpos($k_upper, 'COOLING') !== false) $key = "Tản nhiệt (Cooling)";
                
                // Tránh ghi đè nếu web ghi 2 dòng cùng tên
                if(!isset($specs[$key])) {
                    $specs[$key] = $val;
                }
            }
        }

        $specs_json = !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;

        // BƯỚC 3: KIỂM TRA TRÙNG LẶP VÀ ĐƯA VÀO KHO
        $stmt_check->bindParam(":name", $product_name);
        $stmt_check->execute();
        $existing_product = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($existing_product) {
            $stmt_update->bindParam(":specs", $specs_json);
            $stmt_update->bindParam(":price", $price_val);
            $stmt_update->bindParam(":id", $existing_product['id']);
            if ($stmt_update->execute()) $updatedCount++;
        } else {
            $stmt_insert->bindParam(":name", $product_name);
            $stmt_insert->bindParam(":price", $price_val);
            $stmt_insert->bindParam(":image", $image_url);
            $stmt_insert->bindParam(":specs", $specs_json);
            if ($stmt_insert->execute()) $insertedCount++;
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Nhiệm vụ cào theo chỉ định đã hoàn tất.",
        "data" => [
            "target_scanned" => $target_url,
            "total_links_found" => count($product_links),
            "new_inserted" => $insertedCount,
            "updated_specifications" => $updatedCount
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi vận hành máy cào: " . $e->getMessage()
    ]);
}
?>