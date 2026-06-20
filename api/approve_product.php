<?php
// BƯỚC 1: MỞ CỬA CORS CHO CẢ POST VÀ OPTIONS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// BƯỚC 2: CHẶN ĐỨNG PREFLIGHT
// Trình duyệt hỏi đường? Trả lời 200 OK và ngắt script ngay lập tức, không chạy logic bên dưới!
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// BƯỚC 3: LOGIC CẬP NHẬT TRẠNG THÁI
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Lấy dữ liệu từ body request (JSON)
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->id)) {
    $query = "UPDATE products SET status = 'approved' WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":id", $data->id);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "Sản phẩm đã được duyệt thành công."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Không thể cập nhật trạng thái sản phẩm."
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Dữ liệu không hợp lệ. Thiếu ID sản phẩm."
    ]);
}
?>