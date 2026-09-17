<?php
require_once '../config/auth.php';
cors_allow_origin(['POST', 'OPTIONS']);
require_admin_auth();

// LOGIC CẬP NHẬT TRẠNG THÁI
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
