<?php
// BƯỚC 1: MỞ CỬA CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// BƯỚC 2: CHẶN PREFLIGHT
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $data = json_decode(file_get_contents("php://input"));

    if (!empty($data->id)) {
        // Chuyển trạng thái từ approved về lại pending
        $query = "UPDATE products SET status = 'pending' WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data->id);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Đã ẩn sản phẩm khỏi trang chủ."]);
        } else {
            http_response_code(503);
            echo json_encode(["status" => "error", "message" => "Máy chủ CSDL từ chối cập nhật trạng thái."]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Dữ liệu không hợp lệ. Thiếu ID sản phẩm."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Lỗi hệ thống: " . $e->getMessage()]);
}
?>