<?php
// api/bot_sync_anphatpc.php
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
    // Giả mạo trình duyệt thật để An Phát PC không chặn
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
        "//div[contains(@class, 'fotorama')]//a", // An Phat dùng Fotorama
        "//figure//img",
        "//picture//img",
        "//img[contains(@class, 'p-image') or contains(@class, 'product-image') or contains(@class, 'p-picture') or contains(@class, 'product-gallery') or contains(@class, 'p-thumb') or contains(@class, 'slider') or contains(@class, 'lazy') or contains(@class, 'thumbnail')]",
        "//img"
    ];

    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        foreach ($nodes as $node) {
            // For Fotorama, the image is in the href of an 'a' tag
            if ($node->tagName === 'a') {
                $image = $node->getAttribute('href');
            } else {
                 $image = extractImageUrlFromNode($node, $baseUrl);
            }
           
            if (!empty($image)) {
                return $image;
            }
        }
    }

    $styleNodes = $xpath->query("//*[contains(@style, 'background-image')]");
    foreach ($styleNodes as $node) {
        $style = $node->getAttribute('style');
        if (preg_match('/background-image\s*:\s*url\(([^)]+)\)/i', $style, $m)) {
            $url = trim($m[1], "'" ");
            if ($url !== '' && stripos($url, 'data:image') !== 0) {
                return resolveAbsoluteUrl($url, $baseUrl);
            }
        }
    }

    return '';
}

function normalizeTextValue($text) {
    $value = trim(preg_replace('/\s+/u', ' ', $text));
    return $value;
}

function removeVietnameseTones($str) {
    $map = [
        'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
        'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
        'ì','í','ị','ỉ','ĩ',
        'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
        'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
        'ỳ','ý','ỵ','ỷ','ỹ','đ'
    ];
    $repl = [
        'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
        'e','e','e','e','e','e','e','e','e','e','e',
        'i','i','i','i','i',
        'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
        'u','u','u','u','u','u','u','u','u','u','u',
        'y','y','y','y','y','d'
    ];
    $str = mb_strtolower($str, 'UTF-8');
    return str_replace($map, $repl, $str);
}


function classifyAnPhatProductType($productName, $targetUrl) {
    $urlKey = removeVietnameseTones((string) $targetUrl);
    $nameKey = ' ' . removeVietnameseTones((string) $productName) . ' ';

    if (strpos($urlKey, 'laptop') !== false || strpos($urlKey, 'macbook') !== false) {
        return 'Laptop';
    }
    if (strpos($urlKey, 'vga') !== false || strpos($urlKey, 'card-man-hinh') !== false || strpos($urlKey, 'card-do-hoa') !== false) {
        return 'VGA';
    }
    if (strpos($urlKey, 'man-hinh') !== false || strpos($urlKey, 'manhinh') !== false || strpos($urlKey, 'monitor') !== false) {
        return 'Màn hình';
    }
    if (strpos($urlKey, 'tan-nhiet') !== false || strpos($urlKey, 'cooling') !== false) {
        return 'Tản Nhiệt';
    }
    if (strpos($urlKey, 'o-cung') !== false || strpos($urlKey, 'ocung') !== false || strpos($urlKey, 'ssd') !== false || strpos($urlKey, 'hdd') !== false) {
        return 'HDD-SSD';
    }
    if (strpos($urlKey, 'main') !== false || strpos($urlKey, 'bo-mach-chu') !== false || strpos($urlKey, 'mainboard') !== false) {
        return 'Mainboard';
    }
    if (strpos($urlKey, 'cpu') !== false || strpos($urlKey, 'vi-xu-ly') !== false || strpos($urlKey, 'bo-vi-xu-ly') !== false) {
        return 'CPU';
    }
    if (strpos($urlKey, '/pc') !== false || strpos($urlKey, 'may-tinh-') !== false || strpos($urlKey, 'may-bo') !== false) {
        return 'PC';
    }

    // Fallback theo tên sản phẩm
    if (strpos($nameKey, ' laptop ') !== false || strpos($nameKey, ' macbook ') !== false) {
        return 'Laptop';
    }
    if (strpos($nameKey, ' pc ') !== false || strpos($nameKey, ' may bo ') !== false || strpos($nameKey, ' may tinh ') !== false) {
        return 'PC';
    }
    if (strpos($nameKey, ' vga ') !== false || strpos($nameKey, ' card man hinh ') !== false || strpos($nameKey, ' card do hoa ') !== false) {
        return 'VGA';
    }
    if (strpos($nameKey, ' man hinh ') !== false || strpos($nameKey, ' monitor ') !== false) {
        return 'Màn hình';
    }
    if (strpos($nameKey, ' tan nhiet ') !== false) {
        return 'Tản Nhiệt';
    }
    if (strpos($nameKey, ' ssd ') !== false || strpos($nameKey, ' hdd ') !== false || strpos($nameKey, ' o cung ') !== false) {
        return 'HDD-SSD';
    }
    if (strpos($nameKey, ' mainboard ') !== false || strpos($nameKey, ' bo mach chu ') !== false || strpos($nameKey, ' main ') !== false) {
        return 'Mainboard';
    }
    if (strpos($nameKey, ' cpu ') !== false || strpos($nameKey, ' vi xu ly ') !== false) {
        return 'CPU';
    }

    return 'Linh kiện';
}

function parseProductSpecifications($xpath) {
    $specs = [];
     $rowQueries = [
        "//div[@id='js-pro-specs-data']//table//tr",
        "//div[contains(@class, 'pro-content-body')]//table//tr",
        "//div[contains(@class, 'product-spec')]//tr",
        "//div[contains(@class, 'specification')]//tr",
        "//div[contains(@class, 'specs')]//tr",
        "//table//tr"
    ];

    $rows = [];
    foreach ($rowQueries as $query) {
        $nodes = $xpath->query($query);
        if ($nodes->length > 0) {
            foreach ($nodes as $node) {
                $rows[] = $node;
            }
             // Found specs, break early
            if (!empty($rows)) break;
        }
    }
    
    $headerLabels = [
        'stt', 'ma hang', 'ten hang', 'thoi han bao hanh', 'bao hanh',
        'don gia', 'thanh tien', 'so luong', 'don vi', 'ghi chu'
    ];

    foreach ($rows as $row) {
        if (!$row) {
            continue;
        }

        $label = '';
        $value = '';
       
        $cellNodes = $xpath->query('.//th|.//td', $row);
        $cells = [];
        foreach ($cellNodes as $cell) {
            $cells[] = normalizeTextValue($cell->textContent);
        }
        $cellCount = count($cells);
        
        if ($cellCount >= 2) {
            $label = $cells[0];
            $value = $cells[1];
        } else {
            continue;
        }
       

        if ($label === '' || $value === '') {
            continue;
        }

        $labelKey = removeVietnameseTones($label);
        $labelKey = trim(preg_replace('/[^a-z0-9 ]+/', ' ', $labelKey));
        $labelKey = preg_replace('/\s+/', ' ', $labelKey);
        if (in_array($labelKey, $headerLabels, true)) {
            continue;
        }

        if (!isset($specs[$label]) || $specs[$label] === '') {
            $specs[$label] = $value;
        }
    }

    return $specs;
}

function getRequestProtocol() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
    }
    if (!empty($_SERVER['REQUEST_SCHEME'])) {
        return strtolower($_SERVER['REQUEST_SCHEME']);
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    return 'http';
}

function normalizeImageUrl($url) {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    if (preg_match('#^https?://#i', $url)) {
        return preg_replace('#^http://#i', 'https://', $url);
    }
    return $url;
}


if (!defined('IMGUR_CLIENT_ID')) {
    define('IMGUR_CLIENT_ID', '139e72807f61c3c');
}

function fetchImageData($imageUrl) {
    $imageUrl = trim($imageUrl);
    if (empty($imageUrl) || stripos($imageUrl, 'data:image') === 0) {
        return null;
    }

    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data === false || $httpCode !== 200 || strlen($data) < 100) {
        return null;
    }
    return $data;
}


function uploadImageToImgur($imageData, $clientId) {
    if (empty($imageData) || empty($clientId)) {
        return null;
    }

    $ch = curl_init('https://api.imgur.com/3/image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Client-ID " . $clientId]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        "image" => base64_encode($imageData),
        "type" => "base64"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $httpCode !== 200) {
        return null;
    }

    $json = json_decode($resp, true);
    if (!empty($json['success']) && !empty($json['data']['link'])) {
        return normalizeImageUrl($json['data']['link']);
    }
    return null;
}

function buildCleanImageUrl($imageUrl) {
    $raw = fetchImageData($imageUrl);
    if ($raw === null) {
        return '';
    }
    
    $link = uploadImageToImgur($raw, IMGUR_CLIENT_ID);
    return $link !== null ? $link : '';
}

function fetchAnPhatCategoryProductLinks($categoryUrl, $offset, $batchSize, &$totalLinks) {
    $allLinks = [];
    $totalLinks = 0;
    
    // Thử trang đầu tiên để đếm tổng số sản phẩm và link
    $firstPageHtml = fetchHTML($categoryUrl);
    if (!$firstPageHtml) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($firstPageHtml, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    // Tìm tổng số sản phẩm (An Phát hiển thị trong 1 tag strong)
    $totalNode = $xpath->query("//div[contains(@class, 'product-list-filter')]//div[contains(@class, 'right')]//strong");
    if($totalNode->length > 0) {
        $totalLinks = (int)preg_replace('/[^0-9]/', '', $totalNode->item(0)->nodeValue);
    }

    // An Phát có 20 sản phẩm / trang
    $perPage = 20;
    $startPage = intdiv($offset, $perPage) + 1;
    $pageOffset = $offset % $perPage;
    $currentPage = $startPage;
    $required = $batchSize;
    $links = [];
    
    while ($required > 0) {
        $pageUrl = $categoryUrl . ($currentPage > 1 ? "?page={$currentPage}" : "");
        $html = ($currentPage === $startPage) ? $firstPageHtml : fetchHTML($pageUrl);
        
        if (!$html) break;

        $pageDom = new DOMDocument();
        @$pageDom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $pageXPath = new DOMXPath($pageDom);
        
        $itemNodes = $pageXPath->query("//div[contains(@class, 'p-item')]//a[contains(@class, 'p-img')]");
        $pageItems = [];
        foreach ($itemNodes as $node) {
            $href = $node->getAttribute('href');
            if (!empty($href)) {
                 $pageItems[] = resolveAbsoluteUrl($href, $categoryUrl);
            }
        }

        if (empty($pageItems)) break;

        if ($currentPage === $startPage && $pageOffset > 0) {
            $pageItems = array_slice($pageItems, $pageOffset);
        }

        foreach ($pageItems as $item) {
            if ($required <= 0) break;
            $links[] = $item;
            $required--;
        }

        if ($required <= 0 || count($pageItems) < ($perPage - ($currentPage === $startPage ? $pageOffset : 0))) {
            break;
        }

        $currentPage++;
        if ($totalLinks > 0 && $currentPage > ceil($totalLinks / $perPage)) {
            break;
        }
    }

    return array_unique($links);
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
    
    $insertedCount = 0;
    $updatedCount = 0;
    $processedCount = 0;
    $skippedCount = 0;
    $default_image = "https://via.placeholder.com/400x300?text=An+Phat+PC";
    
    $check_query = "SELECT id, source, image_url FROM products WHERE product_name = :name LIMIT 1";
    $stmt_check = $db->prepare($check_query);

    $insert_query = "INSERT INTO products 
                     (product_name, price, is_price_visible, image_url, description, manufacturer, product_type, status, source, specifications) 
                     VALUES (:name, :price, 1, :image, '', '', :ptype, 'pending', 'bot', :specs)";
    $stmt_insert = $db->prepare($insert_query);

    $update_query = "UPDATE products SET 
                        specifications = :specs, 
                        price = :price, 
                        product_type = :ptype, 
                        manufacturer = CASE WHEN source = 'bot' THEN '' ELSE manufacturer END, 
                        description = CASE WHEN source = 'bot' THEN '' ELSE description END, 
                        image_url = COALESCE(NULLIF(:image_update, ''), image_url) 
                     WHERE id = :id";
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
        // Là trang danh mục, tiến hành cào link theo pagination
        $product_links = fetchAnPhatCategoryProductLinks($target_url, $offset, $batch_size, $total_links);
    }
    
    if (empty($product_links)) {
        throw new Exception("Không tìm thấy sản phẩm nào trong link này để cào.");
    }
    
    // Đối với trang danh mục, `fetchAnPhatCategoryProductLinks` đã xử lý offset/batch
    $current_batch = $product_links;
    if (!$isProductPage && $total_links == 0) {
        $total_links = count($product_links); // Fallback nếu không đếm được tổng
    }


    if (empty($current_batch)) {
        throw new Exception("Không còn sản phẩm để cào tại vị trí offset này.");
    }

    // BƯỚC 2: TIẾN HÀNH THÂM NHẬP VÀ BÓC TÁCH TỪNG SẢN PHẨM
    foreach ($current_batch as $link) {
        usleep(300000); // Ngủ 0.3s để tránh bị block IP
        
        $detail_html = fetchHTML($link);
        if (!$detail_html) {
            $skippedCount++;
            continue;
        }

        $detail_dom = new DOMDocument();
        @$detail_dom->loadHTML(mb_convert_encoding($detail_html, 'HTML-ENTITIES', 'UTF-8'));
        $detail_xpath = new DOMXPath($detail_dom);

        // 1. Lấy Tên PC
        $nameNode = $detail_xpath->query("//div[contains(@class, 'pro-detail-head')]//h1");
        if ($nameNode->length === 0) {
            $skippedCount++;
            continue;
        }
        $product_name = normalizeTextValue($nameNode->item(0)->nodeValue);


        // 2. Lấy Giá
        $priceNode = $detail_xpath->query("//div[contains(@class, 'price-container')]//span[contains(@class, 'price')]");
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
            
            $stmt_update->bindParam(":specs", $specs_json);
            $stmt_update->bindParam(":price", $price_val);
            $stmt_update->bindParam(":ptype", $product_type);
            $stmt_update->bindParam(":image_update", $image_update);
            $stmt_update->bindParam(":id", $existing_product['id']);
            if ($stmt_update->execute()) {
                $updatedCount++;
                $processedCount++;
            }
        } else {
            $image_url = $default_image;
            if (!empty($source_image)) {
                $clean_image = buildCleanImageUrl($source_image);
                $image_url = $clean_image !== '' ? $clean_image : $source_image;
            }

            $stmt_insert->bindParam(":name", $product_name);
            $stmt_insert->bindParam(":price", $price_val);
            $stmt_insert->bindParam(":image", $image_url);
            $stmt_insert->bindParam(":ptype", $product_type);
            $stmt_insert->bindParam(":specs", $specs_json);
            if ($stmt_insert->execute()) {
                $insertedCount++;
                $processedCount++;
            }
        }
    }

    $message = "Đã cào thành công {$processedCount} sản phẩm.";
    $next_offset = $isProductPage ? 1 : $offset + count($current_batch);
    $has_more = !$isProductPage && $next_offset < $total_links;

    if ($has_more) {
        $message = "Đã cào thành công {$processedCount} sản phẩm, Bot phát hiện còn sản phẩm chưa cào, bạn muốn tiếp tục lấy thêm 5 sản phẩm không?";
    }

    echo json_encode([
        "status" => "success",
        "message" => $message,
        "data" => [
            "target_scanned" => $target_url,
            "total_links_found" => $total_links,
            "total_links" => $total_links,
            "batch_count" => count($current_batch),
            "processed_count" => $processedCount,
            "new_inserted" => $insertedCount,
            "updated_specifications" => $updatedCount,
            "skipped" => $skippedCount,
            "next_offset" => $next_offset,
            "has_more" => $has_more,
            "continue_prompt" => $has_more ? "Đã cào thành công {$processedCount} sản phẩm, Bot phát hiện còn sản phẩm chưa cào, bạn muốn tiếp tục lấy thêm 5 sản phẩm không?" : "Đã cào hết danh sách sản phẩm.",
            "continue_label" => $has_more ? "Tiếp tục cào 5 sản phẩm" : "Hoàn tất"
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