<?php
// api/bot_sync_digiworld.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Bỏ giới hạn thời gian thực thi của PHP để bot có đủ thời gian cào hàng chục trang
set_time_limit(0); 

require_once '../config/database.php';

$base_url = "https://ict.digiworld.com.vn/san-pham/may-tinh-xach-tay-150.html";

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
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $html = curl_exec($ch);
    
    if(curl_errno($ch)){
        throw new Exception("Lỗi cURL: " . curl_error($ch));
    }
    curl_close($ch);
    return $html;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $insertedCount = 0;
    $skippedCount = 0;
    $page = 1;
    $default_image = "https://via.placeholder.com/400x300?text=No+Image+Available"; 

    // CHUẨN BỊ LỆNH KIỂM TRA TRÙNG LẶP & THÊM MỚI
    $check_query = "SELECT id FROM products WHERE product_name = :name LIMIT 1";
    $stmt_check = $db->prepare($check_query);

    $insert_query = "INSERT INTO products 
                     (product_name, price, is_price_visible, image_url, description, manufacturer, product_type, status, source, specifications) 
                     VALUES (:name, NULL, 0, :image, 'Sản phẩm đồng bộ tự động từ Digiworld', 'Digiworld', 'Thiết bị máy tính', 'pending', 'bot', :specs)";
    $stmt_insert = $db->prepare($insert_query);

    // --- VÒNG LẶP VÔ HẠN: QUÉT ĐẾN KHI HẾT DỮ LIỆU THÌ THÔI ---
    while (true) {
        $current_url = ($page === 1) ? $base_url : $base_url . "?page=" . $page;
        $html = fetchHTML($current_url);
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $nameNodes = $xpath->query("//h2[@class='name']");
        
        // ĐÂY LÀ CHỐT CHẶN: Nếu trang không có thẻ <h2 class="name"> nào, nghĩa là đã qua trang cuối cùng!
        if ($nameNodes->length === 0) {
            break; 
        }

        foreach ($nameNodes as $nameNode) {
            $product_name = trim($nameNode->nodeValue);
            if (empty($product_name)) continue;

            // KIỂM TRA TRÙNG LẶP: Đã có trong DB thì bỏ qua
            $stmt_check->bindParam(":name", $product_name);
            $stmt_check->execute();
            if ($stmt_check->rowCount() > 0) {
                $skippedCount++;
                continue; 
            }

            // Logic lấy ảnh
            $imgNodes = $xpath->query("ancestor::*[.//img][1]//img", $nameNode);
            $image_url = "";
            if ($imgNodes->length > 0) {
                $img = $imgNodes->item(0);
                $possible_attributes = ['data-original', 'data-src', 'data-lazy', 'srcset', 'src'];
                foreach ($possible_attributes as $attr) {
                    $val = trim($img->getAttribute($attr));
                    if (!empty($val)) {
                        if ($attr === 'srcset') {
                            $srcstArray = explode(',', $val);
                            $val = trim(explode(' ', trim($srcstArray[0]))[0]);
                        }
                        if (strpos($val, 'data:image') !== false) continue; 
                        $image_url = $val;
                        break;
                    }
                }
            }

            if (!empty($image_url) && strpos($image_url, 'http') === false) {
                $clean_path = ltrim($image_url, '/');
                $image_url = "https://ict.digiworld.com.vn/" . $clean_path;
            }
            if (empty($image_url)) $image_url = $default_image;

            // Logic lấy thông số kỹ thuật
            $specs = [];
            $specNodes = $xpath->query("ancestor::*[.//div[contains(@class, 'bginfo')]][1]//div[contains(@class, 'bginfo')]//li", $nameNode);
            if ($specNodes !== false) {
                foreach ($specNodes as $sNode) {
                    $text = trim($sNode->nodeValue);
                    if (strpos($text, ':') !== false) {
                        list($key, $val) = explode(':', $text, 2);
                        $specs[trim($key)] = trim($val);
                    } else if (!empty($text)) {
                        $specs['Thông số ' . uniqid()] = $text;
                    }
                }
            }
            $specs_json = !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;

            // TIẾN HÀNH THÊM MỚI
            $stmt_insert->bindParam(":name", $product_name);
            $stmt_insert->bindParam(":image", $image_url);
            $stmt_insert->bindParam(":specs", $specs_json);
            
            if($stmt_insert->execute()) {
                $insertedCount++;
            }
        }
        
        // Tăng số trang lên cho vòng lặp tiếp theo
        $page++;
        
        // Ngủ 1 giây để giả lập người dùng cuộn trang
        sleep(1); 
        
        // Chốt chặn an toàn dự phòng: Chống chạy vô hạn nếu web bị lỗi cấu trúc (max 100 trang)
        if ($page > 100) {
            break;
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Quá trình Auto Crawl đã kết thúc toàn bộ danh mục.",
        "data" => [
            "pages_scanned" => $page - 1,
            "new_inserted" => $insertedCount,
            "skipped_duplicates" => $skippedCount
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi hệ thống Bot Crawler.",
        "error_detail" => $e->getMessage()
    ]);
}
?>