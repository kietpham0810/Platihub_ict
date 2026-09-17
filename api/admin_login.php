<?php
// api/admin_login.php
// Đăng nhập admin thật ở phía backend: so khớp username/password với biến
// môi trường ADMIN_USERNAME/ADMIN_PASSWORD (không nằm trong bundle JS như
// trước), trả về token ký HMAC có hạn dùng 12h.
require_once '../config/auth.php';

cors_allow_origin(['POST', 'OPTIONS']);

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) $data = [];

$username = isset($data['username']) ? (string) $data['username'] : '';
$password = isset($data['password']) ? (string) $data['password'] : '';

[$validUsername, $validPassword] = admin_credentials();

if ($validUsername === '' || $validPassword === '') {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Máy chủ chưa cấu hình tài khoản quản trị."]);
    exit();
}

$usernameMatches = hash_equals($validUsername, $username);
$passwordMatches = hash_equals($validPassword, $password);

if ($usernameMatches && $passwordMatches) {
    $issued = issue_admin_token();
    echo json_encode([
        "status" => "success",
        "token" => $issued['token'],
        "expires_at" => $issued['expires_at'],
    ]);
} else {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Tài khoản hoặc mật khẩu không chính xác!"]);
}
