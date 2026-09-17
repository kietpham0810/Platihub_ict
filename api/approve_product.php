<?php
// BƯỚC 1: MỞ CỬA CORS CHO CẢ POST VÀ OPTIONS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// BƯỚC 2: CHẶN ĐỨNG PREFLIGHT
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// BƯỚC 3: LOGIC CẬP NHẬT TRẠNG THÁI
require_once '../config/database.php';
require_once '../config/mongo_helpers.php';

$database = new Database();
$db = $database->getConnection();
$collection = $db->products;

// Lấy dữ liệu từ body request (JSON)
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->id)) {
    $objectId = to_object_id($data->id);

    if ($objectId === null) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "Dữ liệu không hợp lệ. Thiếu ID sản phẩm."
        ]);
        exit();
    }

    try {
        $result = $collection->updateOne(['_id' => $objectId], ['$set' => ['status' => 'approved']]);

        if ($result->isAcknowledged()) {
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Sản phẩm đã được duyệt thành công."
            ]);
        } else {
            throw new Exception("Update failed.");
        }
    } catch (Exception $e) {
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
