<?php
// api/crawl_filtered.php
// Endpoint cho nút "Cào theo bộ lọc" ở trang admin: FE gửi URL danh mục (lấy
// từ cây danh mục configs.crawl_category_tree) + điều kiện lọc (giá, chip,
// RAM, số lượng cần lấy). Logic lọc thật sự nằm ở crawlAnPhatFiltered()
// trong api/lib/anphatpc_crawler.php.
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Cào theo bộ lọc có thể phải quét/lọc qua nhiều sản phẩm mới đủ số lượng cần
// -> nới lỏng timeout giống các endpoint cào khác.
set_time_limit(0);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

require_once '../config/database.php';
require_once '../config/mongo_helpers.php';
require_once __DIR__ . '/lib/anphatpc_crawler.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$target_url = isset($input['url']) ? trim($input['url']) : '';
$quantity = isset($input['quantity']) ? max(1, min(50, intval($input['quantity']))) : 20;
$price_min = (isset($input['price_min']) && $input['price_min'] !== '' && $input['price_min'] !== null) ? intval($input['price_min']) : null;
$price_max = (isset($input['price_max']) && $input['price_max'] !== '' && $input['price_max'] !== null) ? intval($input['price_max']) : null;
$chip = isset($input['chip']) ? trim($input['chip']) : '';
$ram = isset($input['ram']) ? trim($input['ram']) : '';

if (empty($target_url)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Thiếu URL danh mục cần cào."]);
    exit();
}

$log_lines = [];
$logger = function ($msg) use (&$log_lines) {
    $log_lines[] = $msg;
    error_log('[crawl_filtered] ' . $msg);
};

try {
    $database = new Database();
    $db = $database->getConnection();

    // Quét tối đa gấp 6 lần số lượng cần (trần 150) để tránh chạy vô hạn khi
    // bộ lọc quá hẹp (vd giá quá thấp, chip hiếm) mà danh mục không đủ hàng.
    $scanCap = min(150, max($quantity * 6, 30));

    $result = crawlAnPhatFiltered($db, $target_url, [
        'price_min' => $price_min,
        'price_max' => $price_max,
        'chip' => $chip,
        'ram' => $ram,
    ], $quantity, $scanCap, $logger);

    $result['log'] = $log_lines;

    if ($result['status'] === 'error') {
        http_response_code(422);
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('[crawl_filtered] LỖI NGHIÊM TRỌNG: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi vận hành cào theo bộ lọc.",
        "log" => $log_lines,
    ]);
}
