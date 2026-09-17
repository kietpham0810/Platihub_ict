<?php
// config/database.php
require_once __DIR__ . '/../vendor/autoload.php';

// Nạp thông tin kết nối MongoDB local (không commit lên git). Trên môi
// trường hosting thật, các biến MONGO_URI / MONGO_DB_NAME nên được set thẳng
// làm biến môi trường của server thay vì dùng file này.
$envLocalFile = __DIR__ . '/env.local.php';
if (file_exists($envLocalFile)) {
    require_once $envLocalFile;
}

use MongoDB\Client;
use MongoDB\Database as MongoDatabase;

class Database {
    private $uri;
    private $db_name;
    public $conn;

    public function __construct() {
        $this->uri = $_SERVER['MONGO_URI'] ?? $_ENV['MONGO_URI'] ?? getenv('MONGO_URI') ?: "mongodb://localhost:27017";
        $this->db_name = $_SERVER['MONGO_DB_NAME'] ?? $_ENV['MONGO_DB_NAME'] ?? getenv('MONGO_DB_NAME') ?: "platihub_db";
    }

    /**
     * Trả về MongoDB\Database đã kết nối tới cluster.
     */
    public function getConnection(): MongoDatabase {
        try {
            $client = new Client($this->uri);
            // Ping để phát hiện lỗi kết nối ngay, tránh lỗi mù mờ ở query sau.
            $client->selectDatabase('admin')->command(['ping' => 1]);
            $this->conn = $client->selectDatabase($this->db_name);
        } catch (\Throwable $exception) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Lỗi kết nối Cơ sở dữ liệu MongoDB.",
                "error_detail" => $exception->getMessage()
            ]);
            exit();
        }
        return $this->conn;
    }
}
?>
