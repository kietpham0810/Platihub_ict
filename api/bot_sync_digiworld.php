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
        "Accept: text/html,application/xhtml+xml",
        "Accept-Language: vi-VN,vi;q=0.9",
        "Cache-Control: max-age=0"
    ]);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64)");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
    
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $db->exec("CREATE TABLE IF NOT EXISTS bot_configs (
        meta_key VARCHAR(50) PRIMARY KEY,
        meta_value VARCHAR(255)
    )");

    $stmt_config = $db->prepare("SELECT meta_value FROM bot_configs WHERE meta_key = 'current_page' LIMIT 1");
    $stmt_config->execute();
    $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
    $start_page = $config ? (int)$config['meta_value'] : 1;

    $insertedCount = 0;
    $updatedCount = 0; 
    $skippedCount = 0;
    $hiddenCount = 0; 
    $page = $start_page;
    $pages_to_crawl = 2; // Rút ngắn lại để hạ tầng dễ thở
    $max_page = $start_page + $pages_to_crawl;
    $default_image = "https://via.placeholder.com/400x300?text=No+Image+Available"; 

    $check_query = "SELECT id, specifications FROM products WHERE product_name = :name LIMIT 1";
    $stmt_check = $db->prepare($check_query);

    $insert_query = "INSERT INTO products 
                     (product_name, price, is_price_visible, image_url, description, manufacturer, product_type, status, source, specifications) 
                     VALUES (:name, NULL, 0, :image, 'Sản phẩm đồng bộ tự động từ Digiworld', 'Digiworld', 'Thiết bị máy tính', 'pending', 'bot', :specs)";
    $stmt_insert = $db->prepare($insert_query);

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
                $specs_str = $existing_product['specifications'];
                if (!empty($specs_str) && $specs_str !== 'null' && $specs_str !== '[]') {
                    // GIẢI MÃ JSON ĐỂ KIỂM TRA CHÍNH XÁC (Tránh lỗi Unicode Encoding)
                    $decoded = json_decode($specs_str, true);
                    $is_basic = false;
                    if (is_array($decoded)) {
                        foreach (array_keys($decoded) as $k) {
                            if (strpos($k, 'Cấu hình') !== false) {
                                $is_basic = true; break;
                            }
                        }
                    }
                    
                    // Nếu đã có thông số xịn (không phải cấu hình cơ bản) -> Bỏ qua
                    if (!$is_basic) {
                        $skippedCount++;
                        continue; 
                    }
                }
                $is_duplicate_but_need_specs = true;
            }

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

            $img_lower = strtolower($image_url);
            if ($image_url === $default_image || $image_url === 'https://ict.digiworld.com.vn/' || strlen($image_url) < 35 || strpos($img_lower, 'no-image') !== false || strpos($img_lower, 'placeholder') !== false) {
                $hiddenCount++;
                continue; 
            }

            // ==========================================
            // 🚀 ĐỘNG CƠ V6: CHỐNG CHẶN IP & ÉP KIỂU RAW TEXT
            // ==========================================
            $specs = [];
            $detail_url = "";

            $aTags = $xpath->query("ancestor::*[contains(@class, 'item') or contains(@class, 'product')][1]//a", $nameNode);
            if ($aTags !== false) {
                foreach($aTags as $a) {
                    $href = $a->getAttribute('href');
                    if (strpos($href, '.html') !== false) {
                        $detail_url = $href;
                        break;
                    }
                }
            }

            if (!empty($detail_url)) {
                $detail_url = trim($detail_url);
                if (strpos($detail_url, 'http') === false) {
                    $detail_url = "https://ict.digiworld.com.vn/" . ltrim($detail_url, '/');
                }

                // 🛑 BẢO MẬT: NGHỈ 0.5 GIÂY ĐỂ TRÁNH BỊ TƯỜNG LỬA DIGIWORLD CHẶN KẾT NỐI
                usleep(500000); 

                $detail_html = fetchHTML($detail_url);
                if ($detail_html) {
                    $detail_dom = new DOMDocument();
                    @$detail_dom->loadHTML(mb_convert_encoding($detail_html, 'HTML-ENTITIES', 'UTF-8'));
                    $detail_xpath = new DOMXPath($detail_dom);

                    // --- TẦNG 1: QUÉT BẢNG <table> CHUẨN ---
                    $rows = $detail_xpath->query("//table//tr");
                    foreach ($rows as $row) {
                        $th = $detail_xpath->query(".//th", $row);
                        $td = $detail_xpath->query(".//td", $row);
                        
                        $key = ""; $val = "";
                        if ($th->length > 0 && $td->length > 0) {
                            $key = trim(strip_tags($th->item(0)->nodeValue));
                            $val = trim(strip_tags($td->item(0)->nodeValue));
                        } else {
                            $cols = $detail_xpath->query(".//td", $row);
                            if ($cols->length >= 2) {
                                $key = trim(strip_tags($cols->item(0)->nodeValue));
                                $val = trim(strip_tags($cols->item(1)->nodeValue));
                            }
                        }
                        if (!empty($key) && !empty($val) && $key !== 'Thông số' && $key !== 'Đặc tính') {
                            $specs[$key] = $val;
                        }
                    }

                    // --- TẦNG 2: ÉP KIỂU VĂN BẢN THÔ TOÀN BỘ TRANG CHI TIẾT ---
                    if (empty($specs)) {
                        $clean_html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $detail_html);
                        $clean_html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $clean_html);
                        $clean_html = preg_replace('/<br\s*\/?>/i', "\n", $clean_html);
                        $clean_html = preg_replace('/<\/p>|<\/div>|<\/li>|<\/tr>/i', "\n", $clean_html);
                        $clean_text = strip_tags($clean_html);
                        
                        $lines = explode("\n", $clean_text);
                        $temp_specs = [];
                        foreach ($lines as $line) {
                            $line = trim(preg_replace('/\s+/', ' ', $line)); 
                            if (strpos($line, ':') !== false) {
                                $parts = explode(':', $line, 2);
                                $key = trim($parts[0]);
                                $val = trim($parts[1]);
                                
                                // Ràng buộc: Key chỉ từ 2 đến 45 ký tự, không chứa thẻ HTML
                                if (strlen($key) >= 2 && strlen($key) <= 45 && strlen($val) > 0) {
                                    if (!preg_match('/[{}<>]/', $key)) {
                                        $lower_key = mb_strtolower($key);
                                        $noise = ['hotline', 'email', 'fax', 'điện thoại', 'địa chỉ', 'liên hệ', 'website', 'trang chủ', 'chú ý'];
                                        if (!in_array($lower_key, $noise)) {
                                            $temp_specs[$key] = $val;
                                        }
                                    }
                                }
                            }
                        }
                        if (count($temp_specs) >= 4) {
                            $specs = $temp_specs;
                        }
                    }
                }
            }
            
            // --- TẦNG 3: FALLBACK TRANG CHỦ KHI LINK DETAIL CHẾT ---
            if (empty($specs)) {
                $container = $xpath->query("ancestor::*[contains(@class, 'item') or contains(@class, 'product')][1]", $nameNode);
                if ($container->length > 0) {
                    $all_text = $container->item(0)->nodeValue;
                    if (strpos($all_text, '/') !== false && (strpos($all_text, 'RAM') !== false || strpos($all_text, 'SSD') !== false || strpos($all_text, 'Intel') !== false || strpos($all_text, 'AMD') !== false)) {
                        $divs = $xpath->query(".//div | .//p", $container->item(0));
                        foreach($divs as $d) {
                            $t = trim($d->nodeValue);
                            if (substr_count($t, '/') >= 2) { 
                                $parts = explode('/', $t);
                                foreach($parts as $idx => $p) {
                                    $p = trim($p);
                                    if(!empty($p)) $specs["Cấu hình cơ bản ".($idx+1)] = $p;
                                }
                                break;
                            }
                        }
                    }
                }
            }
            
            $specs_json = !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;

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
            "updated_specifications" => $updatedCount,
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