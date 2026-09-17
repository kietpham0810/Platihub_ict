<?php
// api/update_product.php
require_once '../config/auth.php';
cors_allow_origin(['POST', 'OPTIONS']);
require_admin_auth();

require_once '../config/database.php';
require_once '../config/mongo_helpers.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    $collection = $db->products;

    // Nhận data từ body (RAW JSON)
    $data = json_decode(file_get_contents("php://input"), true);

    // Validation bắt buộc
    if (empty($data['id'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Thiếu ID sản phẩm."]);
        exit();
    }

    $objectId = to_object_id($data['id']);
    if ($objectId === null) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "ID sản phẩm không hợp lệ."]);
        exit();
    }

    $product_name = isset($data['product_name']) ? htmlspecialchars(strip_tags($data['product_name'])) : null;
    $manufacturer = isset($data['manufacturer']) ? htmlspecialchars(strip_tags($data['manufacturer'])) : null;
    $product_type = isset($data['product_type']) ? htmlspecialchars(strip_tags($data['product_type'])) : null;
    $image_url = isset($data['image_url']) ? htmlspecialchars(strip_tags($data['image_url'])) : null;
    $description = isset($data['description']) ? htmlspecialchars(strip_tags($data['description'])) : null;

    // Xử lý giá và trạng thái hiển thị giá (nếu Frontend có gửi)
    $price = isset($data['price']) && $data['price'] !== '' ? (int) $data['price'] : null;
    $is_price_visible = isset($data['is_price_visible']) ? (int)$data['is_price_visible'] : 0;

    // Xử lý specifications (JSON)
    $specs_json = null;
    if (isset($data['specifications']) && !empty($data['specifications'])) {
        // Nếu data gửi lên là mảng/object, encode lại thành chuỗi. Nếu đã là chuỗi, giữ nguyên.
        $specs_json = is_array($data['specifications']) ? json_encode($data['specifications'], JSON_UNESCAPED_UNICODE) : $data['specifications'];

        // Validate xem chuỗi JSON có hợp lệ không trước khi lưu
        json_decode($specs_json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Dữ liệu thông số kỹ thuật (JSON) không hợp lệ.");
        }
    }

    $result = $collection->updateOne(
        ['_id' => $objectId],
        ['$set' => [
            'product_name' => $product_name,
            'manufacturer' => $manufacturer,
            'product_type' => $product_type,
            'image_url' => $image_url,
            'description' => $description,
            'price' => $price,
            'is_price_visible' => $is_price_visible,
            'specifications' => $specs_json,
        ]]
    );

    if ($result->isAcknowledged()) {
        echo json_encode([
            "status" => "success",
            "message" => "Cập nhật sản phẩm thành công."
        ]);
    } else {
        throw new Exception("Không thể thực thi lệnh cập nhật.");
    }

} catch (Exception $e) {
    error_log('[update_product] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi máy chủ khi cập nhật."
    ]);
}
?>
