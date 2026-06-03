<?php
// Mở cổng giao tiếp CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Kiểm tra xem Frontend có yêu cầu lọc theo trạng thái không (ví dụ: ?status=pending)
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Xây dựng câu truy vấn SQL
$query = "SELECT * FROM products";
if (!empty($status_filter)) {
    $query .= " WHERE status = :status";
}
// Sắp xếp sản phẩm mới nhất lên đầu
$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);

// Gắn biến nếu có lọc
if (!empty($status_filter)) {
    $stmt->bindParam(":status", $status_filter);
}

$stmt->execute();
$products = array();

// Đổ dữ liệu vào mảng
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    array_push($products, $row);
}

// Trả về JSON
http_response_code(200);
echo json_encode([
    "status" => "success",
    "total" => count($products),
    "data" => $products
]);
?>