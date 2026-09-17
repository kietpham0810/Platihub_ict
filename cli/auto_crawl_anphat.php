<?php
// cli/auto_crawl_anphat.php
// Chạy tay để test logic cào tự động TRÊN MÁY LOCAL (XAMPP). Trên production
// (Render), dùng endpoint HTTP api/cron_auto_crawl.php thay vì file này -
// Render không chạy Windows Task Scheduler được.
//
// Chạy tay để test: php cli/auto_crawl_anphat.php
//
// Danh sách link danh mục lấy từ collection `configs`, document
// meta_key = 'anphat_auto_crawl_urls', meta_value = JSON array các URL.
// Muốn thêm/bớt danh mục cào tự động: sửa document đó trong MongoDB Atlas
// (Data Explorer) - KHÔNG cần sửa code.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Script này chỉ chạy qua CLI.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mongo_helpers.php';
require_once __DIR__ . '/../api/lib/anphatpc_crawler.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

function logToFileAndStdout($message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    echo $line . "\n";
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/auto_crawl_' . date('Y-m-d') . '.log';
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    runAutoCrawlAllCategories($db, 'logToFileAndStdout', 50, 5);
    exit(0);
} catch (\Throwable $e) {
    logToFileAndStdout("LỖI NGHIÊM TRỌNG: " . $e->getMessage());
    exit(1);
}
