<?php
// Bắt buộc nhúng lõi kết nối
require_once '../config/database.php';

// Khởi tạo kết nối
$database = new Database();
$db = $database->getConnection();

// Viết truy vấn lấy dữ liệu
$query = "SELECT meta_key, meta_value FROM configs";
$stmt = $db->prepare($query);
$stmt->execute();

$configs = array();

// Đổ dữ liệu vào mảng
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $configs[$row['meta_key']] = $row['meta_value'];
}

// Trả về JSON cho Frontend
http_response_code(200);
echo json_encode([
    "status" => "success",
    "data" => $configs
]);
?>