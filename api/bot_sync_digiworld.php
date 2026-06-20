<?php
// api/bot_sync_digiworld.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout ngắn cho từng trang chi tiết
    
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $insertedCount = 0;
    $skippedCount = 0;
    $hiddenCount = 0; 
    $page = 1;
    $default_image = "https://via.placeholder.com/400x300?text=No+Image+Available"; 

    $check_query = "SELECT id FROM products WHERE product_name = :name LIMIT 1";
    $stmt_check = $db->prepare($check_query);

    $insert_query = "INSERT INTO products 
                     (product_name, price, is_price_visible, image_url, description, manufacturer, product_type, status, source, specifications) 
                     VALUES (:name, NULL, 0, :image, 'Sản phẩm đồng bộ tự động từ Digiworld', 'Digiworld', 'Thiết bị máy tính', :status, 'bot', :specs)";
    $stmt_insert = $db->prepare($insert_query);

    while (true) {
        $current_url = ($page === 1) ? $base_slug . ".html" : $base_slug . "-page" . $page . ".html";
        $html = fetchHTML($current_url);
        
        if (!$html) break;

        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $nameNodes = $xpath->query("//h2[@class='name']");
        if ($nameNodes->length === 0) break; 

        foreach ($nameNodes as $nameNode) {
            $product_name = trim($nameNode->nodeValue);
            if (empty($product_name)) continue;

            $stmt_check->bindParam(":name", $product_name);
            $stmt_check->execute();
            if ($stmt_check->rowCount() > 0) {
                $skippedCount++;
                continue; 
            }

            // --- LẤY URL HÌNH ẢNH ---
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

            // KIỂM DUYỆT ẢNH
            $img_lower = strtolower($image_url);
            if ($image_url === $default_image || $image_url === 'https://ict.digiworld.com.vn/' || strlen($image_url) < 35 || strpos($img_lower, 'no-image') !== false || strpos($img_lower, 'placeholder') !== false) {
                $hiddenCount++;
                continue; 
            }

            // ==========================================
            // THUẬT TOÁN DEEP CRAWL: VÀO TRANG CHI TIẾT LẤY THÔNG SỐ
            // ==========================================
            $specs = [];
            $linkNode = $xpath->query(".//a", $nameNode);
            
            if ($linkNode->length > 0) {
                $detail_url = trim($linkNode->item(0)->getAttribute('href'));
                if (!empty($detail_url) && strpos($detail_url, 'http') === false) {
                    $detail_url = "https://ict.digiworld.com.vn/" . ltrim($detail_url, '/');
                }

                // Gửi request cào trang chi tiết
                $detail_html = fetchHTML($detail_url);
                if ($detail_html) {
                    $detail_dom = new DOMDocument();
                    @$detail_dom->loadHTML(mb_convert_encoding($detail_html, 'HTML-ENTITIES', 'UTF-8'));
                    $detail_xpath = new DOMXPath($detail_dom);

                    // Tìm tất cả các thẻ TR (dòng) trong bảng thông số kỹ thuật
                    $rows = $detail_xpath->query("//table//tr");
                    foreach ($rows as $row) {
                        $cols = $detail_xpath->query(".//td | .//th", $row);
                        // Đảm bảo dòng có ít nhất 2 cột (Tên thông số và Giá trị)
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
            $status = 'pending';

            // ĐẨY VÀO DATABASE
            $stmt_insert->bindParam(":name", $product_name);
            $stmt_insert->bindParam(":image", $image_url);
            $stmt_insert->bindParam(":specs", $specs_json);
            $stmt_insert->bindParam(":status", $status); 
            
            if($stmt_insert->execute()) {
                $insertedCount++;
            }
        }
        
        $page++;
        
        // GIỚI HẠN AN TOÀN ĐỂ KHÔNG BỊ RENDER ĐÁ VĂNG (Chỉ quét tối đa 3 trang mỗi lần gọi API)
        if ($page > 3) { 
            break;
        }
    }

    $log_message = "[" . date('Y-m-d H:i:s') . "] DEEP CRAWL: Scanned " . ($page - 1) . " pages. Inserted: $insertedCount (Skipped bad images: $hiddenCount). Skipped Duplicates: $skippedCount\n";
    file_put_contents(__DIR__ . '/sync_digiworld_log.txt', $log_message, FILE_APPEND);

    echo json_encode([
        "status" => "success",
        "message" => "Deep Crawl hoàn tất an toàn.",
        "data" => [
            "pages_scanned" => $page - 1,
            "new_inserted" => $insertedCount,
            "skipped_bad_images" => $hiddenCount,
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