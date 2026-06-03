<?php
// api/update_product.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once '../config/database.php';

// Xử lý preflight request cho CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Nhận data từ body (RAW JSON)
    $data = json_decode(file_get_contents("php://input"), true);

    // Validation bắt buộc
    if (empty($data['id'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Thiếu ID sản phẩm."]);
        exit();
    }

    $id = htmlspecialchars(strip_tags($data['id']));
    $product_name = isset($data['product_name']) ? htmlspecialchars(strip_tags($data['product_name'])) : null;
    $manufacturer = isset($data['manufacturer']) ? htmlspecialchars(strip_tags($data['manufacturer'])) : null;
    $product_type = isset($data['product_type']) ? htmlspecialchars(strip_tags($data['product_type'])) : null;
    $image_url = isset($data['image_url']) ? htmlspecialchars(strip_tags($data['image_url'])) : null;
    $description = isset($data['description']) ? htmlspecialchars(strip_tags($data['description'])) : null;
    
    // Xử lý giá và trạng thái hiển thị giá (nếu Frontend có gửi)
    $price = isset($data['price']) && $data['price'] !== '' ? $data['price'] : null;
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

    // Xây dựng câu lệnh UPDATE động (chỉ update những trường có gửi lên)
    $query = "UPDATE products SET 
                product_name = :product_name, 
                manufacturer = :manufacturer, 
                product_type = :product_type, 
                image_url = :image_url, 
                description = :description,
                price = :price,
                is_price_visible = :is_price_visible,
                specifications = :specifications
              WHERE id = :id";

    $stmt = $db->prepare($query);

    // Bind data
    $stmt->bindParam(':product_name', $product_name);
    $stmt->bindParam(':manufacturer', $manufacturer);
    $stmt->bindParam(':product_type', $product_type);
    $stmt->bindParam(':image_url', $image_url);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':price', $price);
    $stmt->bindParam(':is_price_visible', $is_price_visible);
    $stmt->bindParam(':specifications', $specs_json);
    $stmt->bindParam(':id', $id);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success",
            "message" => "Cập nhật sản phẩm thành công."
        ]);
    } else {
        throw new Exception("Không thể thực thi lệnh cập nhật.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi máy chủ khi cập nhật.",
        "error_detail" => $e->getMessage()
    ]);
}
?>