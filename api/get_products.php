<?php
require_once '../config/auth.php';
cors_allow_origin(['GET', 'OPTIONS']);

require_once '../config/database.php';
require_once '../config/mongo_helpers.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    $collection = $db->products;

    // Kiểm tra xem Frontend có yêu cầu lọc theo trạng thái không (ví dụ: ?status=pending)
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

    // Sản phẩm "pending" (chưa duyệt) chỉ admin mới được xem - trước đây ai
    // cũng gọi được ?status=pending mà không cần đăng nhập.
    if ($status_filter !== '' && $status_filter !== 'approved') {
        require_admin_auth();
    }

    // KIẾN TRÚC THÉP: Chặn rác ngay từ truy vấn (Fix BUG-01 & BUG-02)
    // Chỉ lấy những sản phẩm CÓ ảnh hợp lệ.
    $filter = [
        'image_url' => ['$exists' => true, '$nin' => [null, '']],
    ];

    if (!empty($status_filter)) {
        $filter['status'] = $status_filter;
    }

    // Sắp xếp sản phẩm mới nhất lên đầu
    $cursor = $collection->find($filter, ['sort' => ['created_at' => -1]]);

    $products = array();
    foreach ($cursor as $doc) {
        array_push($products, product_doc_to_array($doc));
    }

    // Trả về JSON chuẩn
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($products),
        "data" => $products
    ]);

} catch (Exception $e) {
    error_log('[get_products] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi truy xuất dữ liệu."
    ]);
}
?>
