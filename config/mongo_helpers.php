<?php
// config/mongo_helpers.php
// Các hàm dùng chung để chuyển đổi giữa document MongoDB (BSON) và mảng PHP/JSON
// mà Frontend đang mong đợi (giữ nguyên hình dạng dữ liệu như hồi còn MySQL).

/**
 * Chuyển 1 document Mongo (BSONDocument) thành mảng assoc, đổi _id (ObjectId) thành id (string).
 */
function product_doc_to_array($doc): array {
    $arr = is_array($doc) ? $doc : $doc->getArrayCopy();

    if (isset($arr['_id'])) {
        $arr['id'] = (string) $arr['_id'];
        unset($arr['_id']);
    }

    if (isset($arr['created_at']) && $arr['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
        $arr['created_at'] = $arr['created_at']->toDateTime()->format('Y-m-d\TH:i:s.v\Z');
    }

    if (array_key_exists('price', $arr) && $arr['price'] !== null) {
        $arr['price'] = (int) (string) $arr['price'];
    }

    return $arr;
}

/**
 * Chuyển chuỗi id (string) từ Frontend thành MongoDB\BSON\ObjectId.
 * Trả về null nếu id không hợp lệ (thay vì throw Exception) để dễ xử lý lỗi 400.
 */
function to_object_id($id): ?MongoDB\BSON\ObjectId {
    try {
        return new MongoDB\BSON\ObjectId((string) $id);
    } catch (\Throwable $e) {
        return null;
    }
}
?>
