<?php
// api/add_product.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

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
    !empty($data->manufacturer) &&
    !empty($data->product_type)
) {
    // Thêm cột specifications vào Query
    $query = "INSERT INTO products (product_name, image_url, description, manufacturer, product_type, status, source, specifications) 
              VALUES (:name, :image, :desc, :manufacturer, :type, 'approved', 'manual', :specs)";
              
    $stmt = $db->prepare($query);

    // Xử lý dữ liệu sạch
    $desc = !empty($data->description) ? htmlspecialchars(strip_tags($data->description)) : "";
    
    // XỬ LÝ SPECIFICATIONS: Chuyển Object thành chuỗi JSON (Giữ nguyên Unicode tiếng Việt)
    $specs_json = NULL;
    if (isset($data->specifications) && is_object($data->specifications)) {
        $specs_json = json_encode($data->specifications, JSON_UNESCAPED_UNICODE);
    }

    $stmt->bindParam(":name", $data->product_name);
    $stmt->bindParam(":image", $data->image_url);
    $stmt->bindParam(":desc", $desc);
    $stmt->bindParam(":manufacturer", $data->manufacturer);
    $stmt->bindParam(":type", $data->product_type);
    $stmt->bindParam(":specs", $specs_json);

    try {
        if ($stmt->execute()) {
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