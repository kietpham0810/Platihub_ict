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

function fetchUrl($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
        "Accept: */*",
        "Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
    ], $headers));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function fetchJSON($url, $headers = []) {
    $result = fetchUrl($url, $headers);
    if ($result === false || $result === null) {
        return null;
    }
    $json = json_decode($result, true);
    return is_array($json) ? $json : null;
}

function parseHoangHaCategoryParams($html) {
    $category = '';
    $collection = '';
    $show = 30;

    if (preg_match('/show_more_product\(\s*["\']([^"\']+)["\']\s*,\s*["\']([^"\']*)["\']\s*\)/i', $html, $matches)) {
        $category = trim($matches[1]);
        $collection = trim($matches[2]);
    }

    if (preg_match('/Module\s*=\s*\{[^}]*\bid\s*:\s*["\']([^"\']+)["\']/i', $html, $matches) && $category === '') {
        $category = trim($matches[1]);
    }

    if (preg_match('/category\s*=\s*["\']?(\d+)["\']?/i', $html, $matches) && $category === '') {
        $category = trim($matches[1]);
    }

    if (preg_match('/const\s+product_per_page\s*=\s*(\d+)/i', $html, $matches)) {
        $show = intval($matches[1]);
        if ($show <= 0) {
            $show = 30;
        }
    }

    return [
        'category' => $category,
        'collection' => $collection,
        'show' => $show
    ];
}

function buildHoangHaCategoryApiUrl($baseUrl, $category, $collection, $show, $page) {
    $baseParts = parse_url($baseUrl);
    if (empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return '';
    }

    $baseHost = $baseParts['scheme'] . '://' . $baseParts['host'];
    return $baseHost . '/ajax/get_json.php?action=product&action_type=product-list&hotType=&category=' . urlencode($category) . '&collection=' . urlencode($collection) . '&sort=order&show=' . intval($show) . '&page=' . intval($page);
}

function fetchHoangHaCategoryProductLinks($targetUrl, $html, $offset, $batchSize, &$totalLinks, &$apiImageMap) {
    $apiImageMap = [];
    $params = parseHoangHaCategoryParams($html);
    if (empty($params['category'])) {
        return [];
    }

    $perPage = max(5, intval($params['show']));
    $startPage = intdiv($offset, $perPage) + 1;
    $pageOffset = $offset % $perPage;
    $currentPage = $startPage;
    $required = $batchSize;
    $links = [];
    $totalLinks = 0;

    while ($required > 0) {
        $apiUrl = buildHoangHaCategoryApiUrl($targetUrl, $params['category'], $params['collection'], $perPage, $currentPage);
        if (empty($apiUrl)) {
            break;
        }

        $json = fetchJSON($apiUrl, [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $targetUrl,
            'Authorization: Basic ssaaAS76DAs6faFFghs1'
        ]);

        if (!is_array($json) || empty($json['list']) || !is_array($json['list'])) {
            break;
        }

        if ($totalLinks === 0 && isset($json['total'])) {
            $totalLinks = intval($json['total']);
        }

        $items = $json['list'];
        if ($currentPage === $startPage && $pageOffset > 0) {
            $items = array_slice($items, $pageOffset);
        }

        foreach ($items as $item) {
            if ($required <= 0) {
                break;
            }
            $href = '';
            if (!empty($item['productUrl'])) {
                $href = trim($item['productUrl']);
            } elseif (!empty($item['url'])) {
                $href = trim($item['url']);
            }

            if ($href === '') {
                continue;
            }

            $href = resolveAbsoluteUrl($href, $targetUrl);
            $links[] = $href;
            if (!empty($item['productImage']['large'])) {
                $apiImageMap[$href] = normalizeImageUrl($item['productImage']['large']);
            } elseif (!empty($item['productImage'])) {
                $apiImageMap[$href] = normalizeImageUrl($item['productImage']);
            }

            $required--;
        }

        if ($required <= 0) {
            break;
        }

        if (count($items) < ($perPage - ($currentPage === $startPage ? $pageOffset : 0))) {
            break;
        }

        $currentPage++;
        if ($totalLinks > 0) {
            $maxPages = (int) ceil($totalLinks / $perPage);
            if ($currentPage > $maxPages) {
                break;
            }
        }
    }

    return array_unique($links);
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

function normalizeTextValue($text) {
    $value = trim(preg_replace('/\s+/u', ' ', $text));
    return $value;
}

// Bỏ dấu tiếng Việt một cách xác định (không phụ thuộc locale như iconv//TRANSLIT)
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

// Phân loại sản phẩm vào đúng danh mục FE (PC, Laptop, CPU, Mainboard, VGA,
// Linh kiện, Màn hình, HDD-SSD, Tản Nhiệt) dựa trên link danh mục đang cào và
// tên sản phẩm. Trả về giá trị product_type khớp với bộ lọc ở frontend.
function classifyHoangHaProductType($productName, $targetUrl) {
    $urlKey = removeVietnameseTones((string) $targetUrl);
    $nameKey = ' ' . removeVietnameseTones((string) $productName) . ' ';

    // Ưu tiên slug của link danh mục (người dùng cào theo từng danh mục)
    if (strpos($urlKey, 'laptop') !== false || strpos($urlKey, 'macbook') !== false) {
        return 'Laptop';
    }
    if (strpos($urlKey, 'vga') !== false || strpos($urlKey, 'card-man-hinh') !== false || strpos($urlKey, 'card-do-hoa') !== false) {
        return 'VGA';
    }
    if (strpos($urlKey, 'man-hinh') !== false || strpos($urlKey, 'manhinh') !== false || strpos($urlKey, 'monitor') !== false) {
        return 'Màn hình';
    }
    if (strpos($urlKey, 'tan-nhiet') !== false || strpos($urlKey, 'tannhiet') !== false || strpos($urlKey, 'cooling') !== false) {
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
    if (strpos($urlKey, '/pc') !== false || strpos($urlKey, 'pc-') !== false || strpos($urlKey, 'may-tinh-de-ban') !== false || strpos($urlKey, 'may-bo') !== false) {
        return 'PC';
    }

    // Fallback theo tên sản phẩm khi slug không rõ ràng
    if (strpos($nameKey, ' laptop ') !== false || strpos($nameKey, ' macbook ') !== false) {
        return 'Laptop';
    }
    if (strpos($nameKey, ' pc ') !== false || strpos($nameKey, ' may bo ') !== false) {
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
        "//div[contains(@class, 'pd-spec-group')]//table//tr",
        "//div[contains(@class, 'pd-spec-group')]//li",
        "//table[contains(@class, 'product-specs')]//tr",
        "//div[contains(@class, 'specifications')]//li",
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
        }
    }

    // Nhãn của dòng tiêu đề bảng "cấu hình build" (STT | Mã hàng | Tên hàng | Bảo hành)
    // -> cần bỏ qua, không lưu thành thông số.
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
        $rowText = normalizeTextValue($row->textContent);
        if ($rowText === '') {
            continue;
        }

        // Lấy toàn bộ ô (th + td) theo đúng thứ tự xuất hiện để xử lý được cả
        // bảng 2 cột (Nhãn | Giá trị) lẫn bảng build nhiều cột (STT | Loại | Tên).
        $cellNodes = $xpath->query('.//th|.//td', $row);
        $cells = [];
        foreach ($cellNodes as $cell) {
            $cells[] = normalizeTextValue($cell->textContent);
        }
        $cellCount = count($cells);

        if ($cellCount >= 3 && preg_match('/^\d+$/', $cells[0])) {
            // Bảng cấu hình build: cột 0 = STT, cột 1 = loại linh kiện, cột 2 = tên linh kiện
            $label = $cells[1];
            $value = $cells[2];
        } elseif ($cellCount >= 2) {
            $label = $cells[0];
            $value = $cells[1];
        } elseif ($cellCount === 1 && strpos($rowText, ':') === false) {
            // Ô đơn không có dấu ':' -> không đủ dữ liệu, bỏ qua
            continue;
        } elseif (strpos($rowText, ':') !== false) {
            $parts = explode(':', $rowText, 2);
            $label = normalizeTextValue($parts[0]);
            $value = normalizeTextValue($parts[1]);
        } elseif (preg_match('/^(.*?)(\s+[-–:]\s+)(.*)$/u', $rowText, $parts)) {
            $label = normalizeTextValue($parts[1]);
            $value = normalizeTextValue($parts[3]);
        }

        if ($label === '' || $value === '') {
            continue;
        }

        // Bỏ qua dòng tiêu đề của bảng cấu hình build
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

function getPublicImageUrl($localFile) {
    $filename = basename($localFile);
    $publicPath = '/images/hoanghapc/' . $filename;
    $publicPath = preg_replace('#/+#', '/', $publicPath);

    if (!empty($_SERVER['HTTP_HOST'])) {
        return getRequestProtocol() . '://' . $_SERVER['HTTP_HOST'] . $publicPath;
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
        return $imageUrl;
    }

    if (file_put_contents($localFile, $imageData) !== false) {
        $savedUrl = getPublicImageUrl($localFile);
        if (stripos($savedUrl, 'http://') === 0 && getRequestProtocol() === 'https') {
            $savedUrl = 'https://' . substr($savedUrl, 7);
        }
        return $savedUrl;
    }

    return $imageUrl;
}

// BẮT LINK TỪ FRONTEND TRUYỀN XUỐNG
$target_url = isset($_GET['url']) ? trim($_GET['url']) : '';
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
$batch_size = 5;

if (empty($target_url)) {
    echo json_encode(["status" => "error", "message" => "Vui lòng dán đường link Hoàng Hà PC cần cào dữ liệu!"]);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $insertedCount = 0;
    $updatedCount = 0;
    $processedCount = 0;
    $skippedCount = 0;
    $default_image = "https://via.placeholder.com/400x300?text=Hoang+Ha+PC";
    $image_save_dir = realpath(__DIR__ . '/../images/hoanghapc') ?: (__DIR__ . '/../images/hoanghapc');

    // CHUẨN BỊ VŨ KHÍ SQL
    $check_query = "SELECT id, specifications FROM products WHERE product_name = :name LIMIT 1";
    $stmt_check = $db->prepare($check_query);

    $insert_query = "INSERT INTO products 
                     (product_name, price, is_price_visible, image_url, description, manufacturer, product_type, status, source, specifications) 
                     VALUES (:name, :price, 1, :image, 'Sản phẩm đồng bộ từ Hoàng Hà PC', 'Hoàng Hà PC', :ptype, 'pending', 'bot', :specs)";
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
    $apiImageMap = [];
    $total_links = 0;
    $isApiCategoryPage = false;

    // Nếu là trang danh mục Hoàng Hà PC, ưu tiên lấy danh sách sản phẩm qua API nội bộ
    $apiLinks = fetchHoangHaCategoryProductLinks($target_url, $html, $offset, $batch_size, $total_links, $apiImageMap);
    if (!empty($apiLinks)) {
        $product_links = $apiLinks;
        $isApiCategoryPage = true;
    } else {
        // Fallback: tìm kiếm các khối sản phẩm trong HTML tĩnh
        $itemNodes = $xpath->query("//div[contains(@class, 'p-item')]//a[@href] | //div[contains(@class, 'product-item')]//a[@href] | //a[contains(@class, 'p-name')]");
        if ($itemNodes->length > 0) {
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
        } else {
            // CHẾ ĐỘ CÀO SẢN PHẨM LẺ
            $product_links[] = $target_url;
            $total_links = 1;
        }
    }

    if (empty($product_links)) {
        throw new Exception("Không tìm thấy sản phẩm nào trong link này để cào.");
    }

    if ($total_links === 0) {
        $total_links = count($product_links);
    }

    if ($isApiCategoryPage) {
        $current_batch = $product_links;
    } else {
        $current_batch = array_slice($product_links, $offset, $batch_size);
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

        // 1. Lấy Tên PC (Thường nằm trong thẻ h1)
        $nameNode = $detail_xpath->query("//h1");
        if ($nameNode->length === 0) {
            $skippedCount++;
            continue;
        }
        $product_name = trim($nameNode->item(0)->nodeValue);

        // 2. Lấy Giá (Tìm thẻ chứa chữ 'giá' hoặc class price)
        $priceNode = $detail_xpath->query("//span[contains(@class, 'p-price')] | //strong[contains(@class, 'price')] | //span[contains(@class, 'price-detail')]");
        $price_val = 0;
        if ($priceNode->length > 0) {
            $raw_price = $priceNode->item(0)->nodeValue;
            $price_val = (int) preg_replace('/[^0-9]/', '', $raw_price);
        }
        if ($price_val == 0) $price_val = null; // Để null nếu không có giá

        // 3. Lấy Hình Ảnh (giữ URL gốc để frontend riêng biệt có thể truy cập được)
        $image_url = $default_image;
        $image_update = '';
        $found_image = findProductImageUrl($detail_xpath, $link);
        if (!empty($found_image)) {
            $image_url = normalizeImageUrl($found_image);
            $image_update = $image_url;
        } elseif (!empty($apiImageMap[$link])) {
            $image_url = normalizeImageUrl($apiImageMap[$link]);
            $image_update = $image_url;
        }

        // 4. Lấy Cấu Hình (sử dụng parser chung để thu thập đủ cột spec)
        $specs = parseProductSpecifications($detail_xpath);
        $specs_json = !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;

        // 5. Phân loại danh mục để FE lọc đúng (theo link danh mục + tên sản phẩm)
        $product_type = classifyHoangHaProductType($product_name, $target_url);

        // BƯỚC 3: KIỂM TRA TRÙNG LẶP VÀ ĐƯA VÀO KHO
        $stmt_check->bindParam(":name", $product_name);
        $stmt_check->execute();
        $existing_product = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($existing_product) {
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
    $has_more = ($offset + count($current_batch)) < $total_links;
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
            "next_offset" => $offset + count($current_batch),
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