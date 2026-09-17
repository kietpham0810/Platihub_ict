<?php
// api/bot_sync_anphatpc.php
// Endpoint HTTP: admin dán 1 link An Phát PC ở trang admin để cào thủ công.
// Logic cào thật sự nằm ở api/lib/anphatpc_crawler.php (dùng chung với
// cli/auto_crawl_anphat.php - script tự động cào theo lịch mỗi ngày).
require_once '../config/auth.php';
cors_allow_origin(['GET', 'POST', 'OPTIONS']);
require_admin_auth();

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

// CHỐNG SSRF: chỉ cho phép cào từ đúng domain An Phát PC, không cho server
// tự fetch URL tùy ý (nội bộ, localhost, cloud metadata...) do client gửi lên.
if (!is_allowed_crawl_host($target_url)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Chỉ hỗ trợ cào dữ liệu từ anphatpc.com.vn."]);
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
