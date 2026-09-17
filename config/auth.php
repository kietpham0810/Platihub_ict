<?php
// config/auth.php
// Xác thực admin thật sự ở phía backend (thay cho việc chỉ so sánh
// username/password trong JS ở frontend - xem bug.txt mục Bảo mật #1, #2).
//
// Cơ chế: token stateless dạng "<payload_base64>.<hmac_base64>" ký bằng
// ADMIN_TOKEN_SECRET, payload chứa thời điểm hết hạn (exp). Không cần lưu
// session ở DB/file vì mọi thông tin cần thiết đã nằm trong token và được
// xác minh lại bằng HMAC mỗi request.

require_once __DIR__ . '/database.php'; // đảm bảo env.local.php (nếu có) đã được nạp

define('ADMIN_TOKEN_TTL_SECONDS', 12 * 60 * 60); // 12 giờ

function admin_credentials(): array {
    $username = $_SERVER['ADMIN_USERNAME'] ?? $_ENV['ADMIN_USERNAME'] ?? getenv('ADMIN_USERNAME') ?: '';
    $password = $_SERVER['ADMIN_PASSWORD'] ?? $_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: '';
    return [$username, $password];
}

function admin_token_secret(): string {
    $secret = $_SERVER['ADMIN_TOKEN_SECRET'] ?? $_ENV['ADMIN_TOKEN_SECRET'] ?? getenv('ADMIN_TOKEN_SECRET') ?: '';
    if ($secret === '') {
        // Không cấu hình secret -> không thể phát/xác minh token một cách an
        // toàn. Chặn cứng thay vì âm thầm dùng secret mặc định.
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Máy chủ chưa cấu hình ADMIN_TOKEN_SECRET."]);
        exit();
    }
    return $secret;
}

function allowed_origins(): array {
    $raw = $_SERVER['ALLOWED_ORIGINS'] ?? $_ENV['ALLOWED_ORIGINS'] ?? getenv('ALLOWED_ORIGINS') ?: '';
    $list = array_filter(array_map('trim', explode(',', $raw)));
    return $list;
}

/**
 * Set header CORS chỉ cho origin nằm trong whitelist (ALLOWED_ORIGINS),
 * thay vì mở toang "*" cho mọi domain như trước.
 */
function cors_allow_origin(array $methods = ['GET', 'POST', 'OPTIONS']): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = allowed_origins();

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Vary: Origin");
    } elseif (empty($allowed)) {
        // Chưa cấu hình ALLOWED_ORIGINS (thiếu env trên production) -> KHÔNG
        // âm thầm mở lại "*" (lỗi này từng khiến CORS mở toang trước đây).
        // Mặc định chặn (không set header CORS) và ghi log để dev biết mà
        // cấu hình, thay vì lộ lỗ hổng do quên .env.
        error_log('[CORS] ALLOWED_ORIGINS chưa được cấu hình - từ chối mọi cross-origin request.');
    }

    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: " . implode(', ', $methods));
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

function issue_admin_token(): array {
    $exp = time() + ADMIN_TOKEN_TTL_SECONDS;
    $payload = base64_encode(json_encode(['exp' => $exp]));
    $sig = base64_encode(hash_hmac('sha256', $payload, admin_token_secret(), true));
    return ['token' => "$payload.$sig", 'expires_at' => $exp];
}

function verify_admin_token(string $token): bool {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;
    [$payload, $sig] = $parts;

    $expectedSig = base64_encode(hash_hmac('sha256', $payload, admin_token_secret(), true));
    if (!hash_equals($expectedSig, $sig)) return false;

    $data = json_decode(base64_decode($payload), true);
    if (!is_array($data) || !isset($data['exp'])) return false;

    return (int) $data['exp'] >= time();
}

/**
 * Chặn request nếu không có token admin hợp lệ trong header
 * "Authorization: Bearer <token>". Gọi ở đầu mọi endpoint ghi/xóa dữ liệu.
 */
function require_admin_auth(): void {
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');

    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) || !verify_admin_token(trim($m[1]))) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Yêu cầu đăng nhập quản trị để thực hiện thao tác này."]);
        exit();
    }
}
