<?php
// api/bot_sync_digiworld.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

set_time_limit(0); 
ini_set('memory_limit', '512M'); 

require_once '../config/database.php';

$base_slug = "https://ict.digiworld.com.vn/san-pham/may-tinh-xach-tay-150";

function fetchHTML($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7",
        "Cache-Control: max-age=0"
    ]);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
    
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Tự động thiết lập bảng cấu hình tiến trình nếu chưa có
    $db->exec("CREATE TABLE IF NOT EXISTS bot_configs (
        meta_key VARCHAR(50) PRIMARY KEY,
        meta_value VARCHAR(255)
    )");

    $stmt_config = $db->prepare("SELECT meta_value FROM bot_configs WHERE meta_key = 'current_page' LIMIT 1");
    $stmt_config->execute();
    $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
    $start_page = $config ? (int)$config['meta_value'] : 1;

    $insertedCount = 0;
    $updatedCount = 0; // 🌟 Thêm bộ đếm cập nhật thông số
    $skippedCount = 0;
    $hiddenCount = 0; 
    $page = $start_page;
    $pages_to_crawl = 2; 
    $max_page = $start_page + $pages_to_crawl;
    $default_image = "https://via.placeholder.com/400x300?text=No+Image+Available"; 

    // 🌟 SỬA ĐỔI TIÊU CHUẨN: Lấy thêm cả id và specifications để kiểm tra trạng thái
    $check_query = "SELECT id, specifications FROM products WHERE product_name = :name LIMIT 1";
    $stmt_check = $db->prepare($check_query);

    $insert_query = "INSERT INTO products 
                     (product_name, price, is_price_visible, image_url, description, manufacturer, product_type, status, source, specifications) 
                     VALUES (:name, NULL, 0, :image, 'Sản phẩm đồng bộ tự động từ Digiworld', 'Digiworld', 'Thiết bị máy tính', 'pending', 'bot', :specs)";
    $stmt_insert = $db->prepare($insert_query);

    // Lệnh UPDATE dùng khi sản phẩm đã có tên nhưng chưa có thông số chi tiết
    $update_query = "UPDATE products SET specifications = :specs WHERE id = :id";
    $stmt_update = $db->prepare($update_query);

    $has_data = false;

    while ($page < $max_page) {
        $current_url = ($page === 1) ? $base_slug . ".html" : $base_slug . "-page" . $page . ".html";
        $html = fetchHTML($current_url);
        
        if (!$html) break;

        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $nameNodes = $xpath->query("//h2[@class='name']");
        if ($nameNodes->length === 0) break; 

        $has_data = true;

        foreach ($nameNodes as $nameNode) {
            $product_name = trim($nameNode->nodeValue);
            if (empty($product_name)) continue;

            $stmt_check->bindParam(":name", $product_name);
            $stmt_check->execute();
            $existing_product = $stmt_check->fetch(PDO::FETCH_ASSOC);

            $is_duplicate_but_need_specs = false;

            if ($existing_product) {
                // Nếu sản phẩm đã có dữ liệu thông số kỹ thuật rồi -> Bỏ qua hoàn toàn
                if (!empty($existing_product['specifications'])) {
                    $skippedCount++;
                    continue; 
                }
                // Nếu tên trùng nhưng thông số đang rỗng -> Đánh dấu để chạy luồng UPDATE
                $is_duplicate_but_need_specs = true;
            }

            // Trích xuất URL ảnh
            $imgNodes = $xpath->query("ancestor::*[.//img][1]//img", $nameNode);
            $image_url = "";
            if ($imgNodes->length > 0) {
                $img = $imgNodes->item(0);
                $possible_attributes = ['data-original', 'data-src', 'data-lazy', 'srcset', 'src'];
                foreach ($possible_attributes as $attr) {
                    $val = trim($img->getAttribute($attr));
                    if (!empty($val)) {
                        if ($attr === 'srcset') $val = trim(explode(' ', trim(explode(',', $val)[0]))[0]);
                        if (strpos($val, 'data:image') !== false) continue; 
                        $image_url = $val;
                        break;
                    }
                }
            }
            if (!empty($image_url) && strpos($image_url, 'http') === false) {
                $image_url = "https://ict.digiworld.com.vn/" . ltrim($image_url, '/');
            }
            if (empty($image_url)) $image_url = $default_image;

            // Bộ lọc ảnh lỗi
            $img_lower = strtolower($image_url);
            if ($image_url === $default_image || $image_url === 'https://ict.digiworld.com.vn/' || strlen($image_url) < 35 || strpos($img_lower, 'no-image') !== false || strpos($img_lower, 'placeholder') !== false) {
                $hiddenCount++;
                continue; 
            }

            // 🔍 DEEP CRAWL ENGINE: Bóc tách bảng dữ liệu kỹ thuật từ trang chi tiết sản phẩm
            $specs = [];
            $linkNode = $xpath->query(".//a", $nameNode);
            if ($linkNode->length > 0) {
                $detail_url = trim($linkNode->item(0)->getAttribute('href'));
                if (!empty($detail_url) && strpos($detail_url, 'http') === false) {
                    $detail_url = "https://ict.digiworld.com.vn/" . ltrim($detail_url, '/');
                }

                $detail_html = fetchHTML($detail_url);
                if ($detail_html) {
                    $detail_dom = new DOMDocument();
                    @$detail_dom->loadHTML(mb_convert_encoding($detail_html, 'HTML-ENTITIES', 'UTF-8'));
                    $detail_xpath = new DOMXPath($detail_dom);

                    $rows = $detail_xpath->query("//table//tr");
                    foreach ($rows as $row) {
                        $cols = $detail_xpath->query(".//td | .//th", $row);
                        if ($cols->length >= 2) {
                            $key = trim($cols->item(0)->nodeValue);
                            $val = trim($cols->item(1)->nodeValue);
                            if (!empty($key) && !empty($val)) {
                                $specs[$key] = $val;
                            }
                        }
                    }
                }
            }
            
            $specs_json = !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;

            // Xử lý ghi dữ liệu thông minh dựa trên trạng thái thực tế
            if ($is_duplicate_but_need_specs) {
                $stmt_update->bindParam(":specs", $specs_json);
                $stmt_update->bindParam(":id", $existing_product['id']);
                if ($stmt_update->execute()) {
                    $updatedCount++;
                }
            } else {
                $stmt_insert->bindParam(":name", $product_name);
                $stmt_insert->bindParam(":image", $image_url);
                $stmt_insert->bindParam(":specs", $specs_json);
                if ($stmt_insert->execute()) {
                    $insertedCount++;
                }
            }
        }
        $page++;
    }

    // Điều hướng vòng lặp trang
    if (!$has_data || $page > 40) {
        $next_page = 1; 
    } else {
        $next_page = $page;
    }

    $stmt_update_config = $db->prepare("INSERT INTO bot_configs (meta_key, meta_value) VALUES ('current_page', :next_page) 
                                        ON DUPLICATE KEY UPDATE meta_value = :next_page2");
    $stmt_update_config->bindValue(':next_page', $next_page);
    $stmt_update_config->bindValue(':next_page2', $next_page);
    $stmt_update_config->execute();

    echo json_encode([
        "status" => "success",
        "message" => "Đồng bộ dữ liệu thông số tự động hoàn tất.",
        "data" => [
            "scanned_pages" => "$start_page -> " . ($page - 1),
            "next_page_schedule" => $next_page,
            "new_inserted" => $insertedCount,
            "updated_specifications" => $updatedCount, // 🌟 Log chi tiết số sản phẩm vừa được bù thông số thành công
            "skipped_bad_images" => $hiddenCount,
            "skipped_duplicates" => $skippedCount
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi vận hành hệ thống Bot.",
        "error_detail" => $e->getMessage()
    ]);
}
?>