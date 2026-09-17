<?php
// api/add_product.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$collection = $db->products;

$data = json_decode(file_get_contents("php://input"));

// RÀNG BUỘC KỸ THUẬT: Bắt lỗi JSON parse
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Payload không phải là JSON hợp lệ."]);
    exit();
}

// Kiểm tra các trường bắt buộc
if (
    !empty($data->product_name) &&
    !empty($data->image_url) &&
    !empty($data->product_type)
) {
    // Xử lý dữ liệu sạch
    $desc = !empty($data->description) ? htmlspecialchars(strip_tags($data->description)) : "";
    $manufacturer = !empty($data->manufacturer) ? $data->manufacturer : "";

    // XỬ LÝ SPECIFICATIONS: Chuyển Object thành chuỗi JSON (Giữ nguyên Unicode tiếng Việt)
    $specs_json = NULL;
    if (isset($data->specifications) && is_object($data->specifications)) {
        $specs_json = json_encode($data->specifications, JSON_UNESCAPED_UNICODE);
    }

    try {
        $result = $collection->insertOne([
            'product_name' => $data->product_name,
            'image_url' => $data->image_url,
            'description' => $desc,
            'manufacturer' => $manufacturer,
            'product_type' => $data->product_type,
            'price' => null,
            'is_price_visible' => 0,
            'specifications' => $specs_json,
            'status' => 'approved',
            'source' => 'manual',
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]);

        if ($result->getInsertedCount() === 1) {
            http_response_code(201); // 201 Created
            echo json_encode([
                "status" => "success",
                "message" => "Sản phẩm mới đã được đăng thành công."
            ]);
        } else {
            throw new Exception("Execute failed.");
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Lỗi Database: Không thể thêm sản phẩm.",
            "error_detail" => $e->getMessage()
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Dữ liệu không hợp lệ. Vui lòng điền đầy đủ các thông tin bắt buộc."
    ]);
}
?>
