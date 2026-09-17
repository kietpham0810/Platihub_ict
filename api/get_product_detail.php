<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/mongo_helpers.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    $collection = $db->products;

    $id = isset($_GET['id']) ? $_GET['id'] : die(json_encode(["status" => "error", "message" => "Thiếu ID sản phẩm."]));

    $objectId = to_object_id($id);
    if ($objectId === null) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Sản phẩm không tồn tại hoặc đã bị ẩn."]);
        exit();
    }

    // Chỉ lấy sản phẩm nếu nó đang được phép hiển thị (approved)
    $doc = $collection->findOne(['_id' => $objectId, 'status' => 'approved']);

    if ($doc) {
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "data" => product_doc_to_array($doc)
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "Sản phẩm không tồn tại hoặc đã bị ẩn."
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Lỗi hệ thống: " . $e->getMessage()]);
}
?>
