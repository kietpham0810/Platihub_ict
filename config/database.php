<?php
// config/database.php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Ưu tiên lấy từ biến môi trường (Render), nếu không có mới fallback về localhost
        $this->host = getenv('DB_HOST') ?: "localhost";
        $this->db_name = getenv('DB_NAME') ?: "platihub_db";
        $this->username = getenv('DB_USER') ?: "root";
        $this->password = getenv('DB_PASS') ?: "";
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4", $this->username, $this->password);
            // Bật chế độ báo lỗi nghiêm ngặt để dễ debug trên server
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            // Trả về JSON lỗi thay vì in text thuần để Frontend không bị crash
            http_response_code(500);
            echo json_encode([
                "status" => "error", 
                "message" => "Lỗi kết nối Cơ sở dữ liệu Cloud.",
                "error_detail" => $exception->getMessage()
            ]);
            exit();
        }
        return $this->conn;
    }
}
?>