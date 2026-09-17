<?php
// api/bot_sync_anphatpc.php
// Endpoint HTTP: admin dán 1 link An Phát PC ở trang admin để cào thủ công.
// Logic cào thật sự nằm ở api/lib/anphatpc_crawler.php (dùng chung với
// cli/auto_crawl_anphat.php - script tự động cào theo lịch mỗi ngày).
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
require_once '../config/mongo_helpers.php';
require_once __DIR__ . '/lib/anphatpc_crawler.php';

// BẮT LINK TỪ FRONTEND TRUYỀN XUỐNG
$target_url = isset($_GET['url']) ? trim($_GET['url']) : '';
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
$batch_size = 5;

if (empty($target_url)) {
    echo json_encode(["status" => "error", "message" => "Vui lòng dán đường link An Phát PC cần cào dữ liệu!"]);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $result = crawlAnPhatPcBatch($db, $target_url, $offset, $batch_size);

    if ($result['status'] === 'error') {
        http_response_code(500);
    }

    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi vận hành máy cào: " . $e->getMessage()
    ]);
}
