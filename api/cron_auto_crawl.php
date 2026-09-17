<?php
// api/cron_auto_crawl.php
// Endpoint để 1 dịch vụ cron NGOÀI (vd cron-job.org, miễn phí) gọi mỗi ngày
// lúc 6h sáng trên PRODUCTION (Render) - Render không có Windows Task
// Scheduler nên không thể tự chạy lịch như máy local, phải để 1 bên ngoài
// "bấm nút" gọi HTTP vào endpoint này theo giờ.
//
// Bảo vệ bằng secret token (KHÔNG public như các endpoint khác) - phải khớp
// biến môi trường CRON_SECRET (set trên Render dashboard, KHÔNG commit vào
// git). Gọi bằng query string: /api/cron_auto_crawl.php?secret=xxx
//
// Cào TẤT CẢ danh mục cấu hình trong configs.anphat_auto_crawl_urls, giới hạn
// 50 sản phẩm mới/lần chạy (xem runAutoCrawlAllCategories trong
// api/lib/anphatpc_crawler.php - logic giống hệt cli/auto_crawl_anphat.php
// dùng khi test ở local).

header("Content-Type: application/json; charset=UTF-8");

$cron_secret = $_SERVER['CRON_SECRET'] ?? $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
$provided_secret = $_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';

if (empty($cron_secret) || !hash_equals($cron_secret, (string) $provided_secret)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

set_time_limit(0);
ini_set('memory_limit', '512M');
// Cho phép script chạy tiếp dù bên gọi (cron-job.org) đã ngắt kết nối do chờ
// phản hồi quá lâu - việc cào có thể mất vài phút.
ignore_user_abort(true);

require_once '../config/database.php';
require_once '../config/mongo_helpers.php';
require_once __DIR__ . '/lib/anphatpc_crawler.php';

$log_lines = [];
$logger = function ($msg) use (&$log_lines) {
    $log_lines[] = $msg;
    error_log('[cron_auto_crawl] ' . $msg);
};

try {
    $database = new Database();
    $db = $database->getConnection();

    $summary = runAutoCrawlAllCategories($db, $logger, 50, 5);

    echo json_encode([
        "status" => "success",
        "summary" => $summary,
        "log" => $log_lines,
    ]);
} catch (\Throwable $e) {
    error_log('[cron_auto_crawl] LỖI NGHIÊM TRỌNG: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi vận hành cron cào tự động.",
        "log" => $log_lines,
    ]);
}
