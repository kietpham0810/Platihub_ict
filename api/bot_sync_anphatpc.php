<?php
// api/bot_sync_anphatpc.php (Adapted from original 905-line hoanghapc script)
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
    // Giả mạo trình duyệt thật
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
    if (empty($url)) return '';
    if (strpos($url, '//') === 0) return 'https:' . $url;
    if (preg_match('#^https?://#i', $url)) return $url;
    
    $baseParts = parse_url($baseUrl);
    if (empty($baseParts['scheme']) || empty($baseParts['host'])) return $url;
    
    $scheme = $baseParts['scheme'];
    $host = $baseParts['host'];
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

    if (strpos($url, '/') === 0) return "$scheme://$host$port" . $url;

    $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return "$scheme://$host$port" . rtrim($dir, '/') . '/' . ltrim($url, '/');
}

function extractImageUrlFromNode($node, $baseUrl) {
    $attrs = ['content', 'data-src', 'data-original', 'data-lazy-src', 'data-lazy', 'data-srcset', 'srcset', 'src', 'href'];
    foreach ($attrs as $attr) {
        if (!$node->hasAttribute($attr)) continue;
        
        $value = trim($node->getAttribute($attr));
        if ($value === '' || stripos($value, 'data:image') === 0) continue;
        
        if ($attr === 'srcset' || $attr === 'data-srcset') {
            $parts = preg_split('/\s*,\s*/', $value);
            $value = trim(explode(' ', $parts[0])[0]);
        }
        return resolveAbsoluteUrl($value, $baseUrl);
    }
    return '';
}

function findProductImageUrl($xpath, $baseUrl) {
    $queries = [
        "//meta[@property='og:image']",
        "//meta[@property='og:image:secure_url']",
        "//div[contains(@class, 'fotorama')]//a[1]", // An Phat main image
        "//div[contains(@class, 'pro-detail-img')]//img[1]",
    ];
    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        if ($nodes->length > 0) {
            $image = extractImageUrlFromNode($nodes->item(0), $baseUrl);
            if (!empty($image)) return $image;
        }
    }
    return '';
}

function normalizeTextValue($text) {
    return trim(preg_replace('/\s+/u', ' ', $text));
}

function removeVietnameseTones($str) {
    $map = ['à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ','è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ','ì','í','ị','ỉ','ĩ','ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ','ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ'];
    $repl = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d'];
    return str_replace($map, $repl, mb_strtolower($str, 'UTF-8'));
}

// Phân loại sản phẩm cho An Phat PC
function classifyAnPhatProductType($productName, $targetUrl) {
    $urlKey = removeVietnameseTones((string) $targetUrl);
    $nameKey = ' ' . removeVietnameseTones((string) $productName) . ' ';

    if (strpos($urlKey, 'laptop') !== false) return 'Laptop';
    if (strpos($urlKey, 'vga') !== false || strpos($urlKey, 'card-man-hinh') !== false) return 'VGA';
    if (strpos($urlKey, 'man-hinh') !== false) return 'Màn hình';
    if (strpos($urlKey, 'cooling') !== false || strpos($urlKey, 'tan-nhiet') !== false) return 'Tản Nhiệt';
    if (strpos($urlKey, 'ssd') !== false || strpos($urlKey, 'hdd') !== false || strpos($urlKey, 'o-cung') !== false) return 'HDD-SSD';
    if (strpos($urlKey, 'mainboard') !== false || strpos($urlKey, 'bo-mach-chu') !== false) return 'Mainboard';
    if (strpos($urlKey, 'cpu') !== false || strpos($urlKey, 'bo-vi-xu-ly') !== false) return 'CPU';
    if (strpos($urlKey, 'pcap') !== false || strpos($urlKey, 'may-tinh-an-phat') !== false) return 'PC';

    if (strpos($nameKey, ' laptop ') !== false) return 'Laptop';
    if (strpos($nameKey, ' pc ') !== false || strpos($nameKey, ' may bo ') !== false) return 'PC';
    if (strpos($nameKey, ' vga ') !== false || strpos($nameKey, ' card man hinh ') !== false) return 'VGA';
    if (strpos($nameKey, ' man hinh ') !== false) return 'Màn hình';
    if (strpos($nameKey, ' tan nhiet ') !== false) return 'Tản Nhiệt';
    if (strpos($nameKey, ' ssd ') !== false || strpos($nameKey, ' hdd ') !== false) return 'HDD-SSD';
    if (strpos($nameKey, ' mainboard ') !== false || strpos($nameKey, ' main ') !== false) return 'Mainboard';
    if (strpos($nameKey, ' cpu ') !== false) return 'CPU';

    return 'Linh kiện';
}

function parseProductSpecifications($xpath) {
    $specs = [];
    $nodes = $xpath->query("//div[@id='js-pro-specs-data']//table//tr");
    if ($nodes->length == 0) {
         $nodes = $xpath->query("//div[contains(@class, 'pro-content-body')]//table//tr");
    }

    foreach ($nodes as $row) {
        $cells = $xpath->query('.//td', $row);
        if ($cells->length >= 2) {
            $label = normalizeTextValue($cells->item(0)->nodeValue);
            $value = normalizeTextValue($cells->item(1)->nodeValue);
            if ($label !== '' && $value !== '') {
                $specs[$label] = $value;
            }
        }
    }
    return $specs;
}

function getRequestProtocol() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
    if (!empty($_SERVER['REQUEST_SCHEME'])) return strtolower($_SERVER['REQUEST_SCHEME']);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return 'https';
    return 'http';
}

function normalizeImageUrl($url) {
    $url = trim($url);
    if ($url === '') return '';
    if (strpos($url, '//') === 0) return 'https:' . $url;
    return preg_replace('#^http://#i', 'https://', $url);
}

function getPublicImageUrl($localFile) {
    $filename = basename($localFile);
    $publicPath = '/images/anphatpc/' . $filename; // Changed directory
    $publicPath = preg_replace('#/+#', '/', $publicPath);

    if (!empty($_SERVER['HTTP_HOST'])) {
        return getRequestProtocol() . '://' . $_SERVER['HTTP_HOST'] . $publicPath;
    }
    return $publicPath;
}

function downloadRemoteImage($imageUrl, $saveDir) {
    $imageUrl = trim($imageUrl);
    if (empty($imageUrl) || stripos($imageUrl, 'data:image') === 0) return null;

    if (!is_dir($saveDir)) @mkdir($saveDir, 0755, true);
    
    $ext = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    $filename = md5($imageUrl) . '.' . $ext;
    $localFile = rtrim($saveDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (file_exists($localFile)) return getPublicImageUrl($localFile);

    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($imageData !== false && $httpCode === 200 && file_put_contents($localFile, $imageData)) {
        return getPublicImageUrl($localFile);
    }
    return $imageUrl;
}

if (!defined('IMGUR_CLIENT_ID')) {
    define('IMGUR_CLIENT_ID', '139e72807f61c3c');
}

function fetchImageData($imageUrl) {
    if (empty($imageUrl = trim($imageUrl)) || stripos($imageUrl, 'data:image') === 0) return null;
    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data !== false && $httpCode === 200 && strlen($data) > 100) ? $data : null;
}

function uploadImageToImgur($imageData, $clientId) {
    if (empty($imageData) || empty($clientId)) return null;
    $ch = curl_init('https://api.imgur.com/3/image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Client-ID " . $clientId]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ["image" => base64_encode($imageData), "type" => "base64"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $httpCode !== 200) return null;
    $json = json_decode($resp, true);
    return !empty($json['success']) && !empty($json['data']['link']) ? normalizeImageUrl($json['data']['link']) : null;
}

// Tải ảnh gốc -> up lên Imgur.
function buildCleanImageUrl($imageUrl) {
    $raw = fetchImageData($imageUrl);
    if ($raw === null) return '';
    // Bỏ qua bước xóa watermark, up thẳng ảnh gốc
    $link = uploadImageToImgur($raw, IMGUR_CLIENT_ID);
    return $link ?? '';
}

// BẮT LINK TỪ FRONTEND TRUYỀN XUỐNG
$target_url = isset($_GET['url']) ? trim($_GET['url']) : '';
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
$batch_size = 5;

if (empty($target_url)) {
    echo json_encode(["status" => "error", "message" => "Vui lòng dán đường link An Phát PC cần cào dữ liệu!"]);
    exit();
}
if (strpos($target_url, 'anphatpc.com.vn') === false) {
    echo json_encode(["status" => "error", "message" => "Link không hợp lệ, chỉ chấp nhận link từ anphatpc.com.vn"]);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $insertedCount = $updatedCount = $processedCount = $skippedCount = 0;
    $default_image = "https://via.placeholder.com/400x300?text=An+Phat+PC";
    $image_save_dir = realpath(__DIR__ . '/../images/anphatpc') ?: (__DIR__ . '/../images/anphatpc');

    // CHUẨN BỊ VŨ KHÍ SQL (Giữ nguyên từ bản gốc, chỉ bỏ :orig)
    $check_query = "SELECT id, source, image_url FROM products WHERE product_name = :name LIMIT 1";
    $stmt_check = $db->prepare($check_query);
    $insert_query = "INSERT INTO products (product_name, price, is_price_visible, image_url, description, manufacturer, product_type, status, source, specifications) VALUES (:name, :price, 1, :image, '', '', :ptype, 'pending', 'bot', :specs)";
    $stmt_insert = $db->prepare($insert_query);
    $update_query = "UPDATE products SET specifications = :specs, price = :price, product_type = :ptype, image_url = COALESCE(NULLIF(:image_update, ''), image_url) WHERE id = :id";
    $stmt_update = $db->prepare($update_query);

    // BƯỚC 1: QUÉT LINK GỐC ĐỂ XEM LÀ DANH MỤC HAY SẢN PHẨM LẺ
    $html = fetchHTML($target_url);
    if (!$html) throw new Exception("Không thể truy cập đường link này. Web có thể đang chặn Bot.");

    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $product_links = [];
    $total_links = 0;

    // Kiểm tra xem đây có phải là trang sản phẩm chi tiết không
    $isProductPage = $xpath->query("//div[contains(@class, 'pro-detail')]")->length > 0;

    if ($isProductPage) {
        $product_links[] = $target_url;
        $total_links = 1;
    } else {
        // Là trang danh mục. An Phát dùng pagination đơn giản `?page=x`
        // Ta sẽ quét tất cả các trang để lấy toàn bộ link sản phẩm
        $all_links = [];
        for ($i = 1; $i <= 50; $i++) { // Giới hạn 50 trang để tránh loop vô tận
            $page_url = $target_url . ($i > 1 ? "?page={$i}" : "");
            $page_html = ($i > 1) ? fetchHTML($page_url) : $html;
            if (!$page_html) break;

            $page_dom = new DOMDocument();
            @$page_dom->loadHTML(mb_convert_encoding($page_html, 'HTML-ENTITIES', 'UTF-8'));
            $page_xpath = new DOMXPath($page_dom);
            
            // Selector cho link sản phẩm trên trang danh mục An Phát
            $nodes = $page_xpath->query("//div[contains(@class, 'p-item')]//a[contains(@class, 'p-img')]");
            if ($nodes->length === 0) break; // Dừng lại nếu trang không có sản phẩm

            foreach ($nodes as $node) {
                $href = $node->getAttribute('href');
                if (!empty($href)) $all_links[] = resolveAbsoluteUrl($href, $target_url);
            }
            usleep(200000); // Ngủ nhẹ giữa các request để tránh bị block
        }
        $product_links = array_unique($all_links);
        $total_links = count($product_links);
    }
    
    // Áp dụng offset và batch size cho danh sách link đã cào được
    $current_batch = array_slice($product_links, $offset, $batch_size);

    if (empty($current_batch)) {
        throw new Exception("Không tìm thấy sản phẩm nào để cào hoặc đã cào hết ở vị trí này.");
    }

    // BƯỚC 2: TIẾN HÀNH THÂM NHẬP VÀ BÓC TÁCH TỪNG SẢN PHẨM
    foreach ($current_batch as $link) {
        usleep(300000); // Ngủ 0.3s để tránh bị block IP
        
        $detail_html = fetchHTML($link);
        if (!$detail_html) { $skippedCount++; continue; }

        $detail_dom = new DOMDocument();
        @$detail_dom->loadHTML(mb_convert_encoding($detail_html, 'HTML-ENTITIES', 'UTF-8'));
        $detail_xpath = new DOMXPath($detail_dom);

        // 1. Lấy Tên sản phẩm
        $nameNode = $detail_xpath->query("//div[contains(@class, 'pro-detail-head')]//h1");
        if ($nameNode->length === 0) { $skippedCount++; continue; }
        $product_name = normalizeTextValue($nameNode->item(0)->nodeValue);

        // 2. Lấy Giá
        $priceNode = $detail_xpath->query("//div[contains(@class, 'price-container')]//div[contains(@class, 'price')]");
        $price_val = 0;
        if ($priceNode->length > 0) {
            $raw_price = $priceNode->item(0)->nodeValue;
            $price_val = (int) preg_replace('/[^0-9]/', '', $raw_price);
        }
        if ($price_val == 0) $price_val = null;

        // 3. Lấy Hình Ảnh
        $source_image = findProductImageUrl($detail_xpath, $link);

        // 4. Lấy Cấu Hình
        $specs = parseProductSpecifications($detail_xpath);
        $specs_json = !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;

        // 5. Phân loại danh mục
        $product_type = classifyAnPhatProductType($product_name, $target_url);

        // BƯỚC 3: KIỂM TRA TRÙNG LẶP VÀ ĐƯA VÀO KHO
        $stmt_check->bindParam(":name", $product_name);
        $stmt_check->execute();
        $existing_product = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($existing_product) {
            $image_update = '';
            $existing_image = $existing_product['image_url'] ?? '';
            if (!empty($source_image) && stripos($existing_image, 'imgur.com') === false) {
                $image_update = buildCleanImageUrl($source_image);
            }
            
            // Không update tên, chỉ update các thông tin còn lại
            $stmt_update->bindParam(":specs", $specs_json);
            $stmt_update->bindParam(":price", $price_val);
            $stmt_update->bindParam(":ptype", $product_type);
            $stmt_update->bindParam(":image_update", $image_update);
            $stmt_update->bindParam(":id", $existing_product['id']);
            if ($stmt_update->execute()) { $updatedCount++; $processedCount++; }
        } else {
            $image_url = $default_image;
            if (!empty($source_image)) {
                $clean_image = buildCleanImageUrl($source_image);
                $image_url = $clean_image ?: $source_image;
            }

            $stmt_insert->bindParam(":name", $product_name);
            $stmt_insert->bindParam(":price", $price_val);
            $stmt_insert->bindParam(":image", $image_url);
            $stmt_insert->bindParam(":ptype", $product_type);
            $stmt_insert->bindParam(":specs", $specs_json);
            if ($stmt_insert->execute()) { $insertedCount++; $processedCount++; }
        }
    }
    
    $next_offset = $offset + count($current_batch);
    $has_more = $next_offset < $total_links;
    $message = "Đã cào thành công {$processedCount} sản phẩm.";
    if ($has_more) {
        $message = "Đã cào thành công {$processedCount} sản phẩm, Bot phát hiện còn sản phẩm chưa cào, bạn muốn tiếp tục lấy thêm " . $batch_size . " sản phẩm không?";
    } else {
         $message = "Đã cào thành công {$processedCount} sản phẩm. Đã hết sản phẩm trong danh mục này.";
    }

    echo json_encode([
        "status" => "success",
        "message" => $message,
        "data" => [
            "target_scanned" => $target_url,
            "total_links" => $total_links,
            "batch_count" => count($current_batch),
            "processed_count" => $processedCount,
            "new_inserted" => $insertedCount,
            "updated_specifications" => $updatedCount,
            "skipped" => $skippedCount,
            "next_offset" => $next_offset,
            "has_more" => $has_more,
            "continue_prompt" => $has_more ? "Tiếp tục cào {$batch_size} sản phẩm" : "Hoàn tất",
            "continue_label" => $has_more ? "Tiếp tục cào {$batch_size} sản phẩm" : "Hoàn tất"
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
