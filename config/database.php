<?php
// config/database.php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Bọc lót 3 tầng để đảm bảo Docker/Apache bắt được biến môi trường trên mây
        $this->host = $_SERVER['DB_HOST'] ?? $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: "localhost";
        $this->db_name = $_SERVER['DB_NAME'] ?? $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: "platihub_db";
        $this->username = $_SERVER['DB_USER'] ?? $_ENV['DB_USER'] ?? getenv('DB_USER') ?: "root";
        $this->password = $_SERVER['DB_PASS'] ?? $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: "";
    }

    public function getConnection() {
        $this->conn = null;
        try {
            // Nếu phát hiện đang ở localhost thì gọi mặc định, còn có DB_HOST thật thì ép kết nối qua TCP/IP
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            http_response_code(500);
            echo json_encode([
                "status" => "error", 
                "message" => "Lỗi kết nối Cơ sở dữ liệu Cloud.",
                "host_debug" => $this->host, // In luôn cái Host ra xem nó đang bắt được gì để dễ debug
                "error_detail" => $exception->getMessage()
            ]);
            exit();
        }
        return $this->conn;
    }
}
?>