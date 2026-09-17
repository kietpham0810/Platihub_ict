<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Bắt buộc nhúng lõi kết nối
require_once '../config/database.php';

// Khởi tạo kết nối
$database = new Database();
$db = $database->getConnection();
$collection = $db->configs;

// Đổ dữ liệu vào mảng
$configs = array();
$cursor = $collection->find();
foreach ($cursor as $row) {
    $configs[$row['meta_key']] = $row['meta_value'];
}

// Trả về JSON cho Frontend
http_response_code(200);
echo json_encode([
    "status" => "success",
    "data" => $configs
]);
?>
