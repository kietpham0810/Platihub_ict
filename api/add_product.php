<?php
// api/add_product.php
require_once '../config/auth.php';
cors_allow_origin(['POST', 'OPTIONS']);
require_admin_auth();

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
    // Xử lý dữ liệu sạch (escape mọi field text để tránh stored XSS khi FE
    // render lại - trước đây chỉ description được escape, gây thiếu nhất
    // quán với update_product.php)
    $product_name = htmlspecialchars(strip_tags($data->product_name));
    $image_url = htmlspecialchars(strip_tags($data->image_url));
    $product_type = htmlspecialchars(strip_tags($data->product_type));
    $desc = !empty($data->description) ? htmlspecialchars(strip_tags($data->description)) : "";
    $manufacturer = !empty($data->manufacturer) ? htmlspecialchars(strip_tags($data->manufacturer)) : "";

    // XỬ LÝ SPECIFICATIONS: Chuyển Object thành chuỗi JSON (Giữ nguyên Unicode tiếng Việt)
    $specs_json = NULL;
    if (isset($data->specifications) && is_object($data->specifications)) {
        $specs_json = json_encode($data->specifications, JSON_UNESCAPED_UNICODE);
    }

    try {
        $result = $collection->insertOne([
            'product_name' => $product_name,
            'image_url' => $image_url,
            'description' => $desc,
            'manufacturer' => $manufacturer,
            'product_type' => $product_type,
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
        error_log('[add_product] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Lỗi Database: Không thể thêm sản phẩm."
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
