<?php
// api/crawl_filtered.php
// Endpoint cho nút "Cào theo bộ lọc" ở trang admin: FE gửi URL danh mục (lấy
// từ cây danh mục configs.crawl_category_tree) + điều kiện lọc (giá, chip,
// RAM, số lượng cần lấy). Logic lọc thật sự nằm ở crawlAnPhatFiltered()
// trong api/lib/anphatpc_crawler.php.
//
// STREAM NDJSON: mỗi dòng echo ra là 1 object JSON độc lập (type="progress"
// nhiều dòng trong lúc chạy, rồi 1 dòng type="result" cuối cùng) - để FE đọc
// tiến độ theo thời gian thực qua ReadableStream thay vì chờ mù đến khi xong.
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/x-ndjson; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Cache-Control: no-cache");
header("X-Accel-Buffering: no"); // Tắt buffer nếu Render đặt sau proxy kiểu nginx

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Cào theo bộ lọc có thể phải quét/lọc qua nhiều sản phẩm mới đủ số lượng cần
// -> nới lỏng timeout giống các endpoint cào khác.
set_time_limit(0);
ini_set('memory_limit', '512M');
ignore_user_abort(true);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

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

$emitLine = function (array $payload) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    flush();
};

if (empty($target_url)) {
    http_response_code(400);
    $emitLine(["type" => "result", "status" => "error", "message" => "Thiếu URL danh mục cần cào."]);
    exit();
}

$log_lines = [];
$logger = function ($msg) use (&$log_lines) {
    $log_lines[] = $msg;
    error_log('[crawl_filtered] ' . $msg);
};

$progress = function (array $stats) use ($emitLine) {
    $emitLine(array_merge(["type" => "progress"], $stats));
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
    ], $quantity, $scanCap, $logger, $progress);

    $result['log'] = $log_lines;
    $emitLine(array_merge(["type" => "result"], $result));
} catch (\Throwable $e) {
    error_log('[crawl_filtered] LỖI NGHIÊM TRỌNG: ' . $e->getMessage());
    $emitLine([
        "type" => "result",
        "status" => "error",
        "message" => "Lỗi vận hành cào theo bộ lọc.",
        "log" => $log_lines,
    ]);
}
