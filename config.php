<?php
/**
 * config.php — Cấu hình chung
 * - Kết nối MySQL (PDO)
 * - Hằng số cấu hình
 * - Hàm tiện ích
 */

declare(strict_types=1);

// ============ CẤU HÌNH CSDL ============
const DB_HOST = '127.0.0.1';
const DB_NAME = 'like_system';
const DB_USER = 'root';
const DB_PASS = '';           // Đổi theo môi trường của bạn
const DB_CHARSET = 'utf8mb4';

// ============ CẤU HÌNH HỆ THỐNG ============
const COOKIE_NAME = 'like_verified';
const COOKIE_TTL  = 365 * 24 * 3600; // 1 năm

// Bật/tắt chế độ chặn theo IP (nếu NAT chung, có thể tắt = false)
const ENFORCE_UNIQUE_IP = true;

// ============ KẾT NỐI PDO ============
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// ============ LẤY IP THỰC ============
function get_client_ip(): string
{
    // Nếu chạy sau reverse proxy, cần tin tưởng X-Forwarded-For (cẩn thận)
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// ============ PHẢN HỒI JSON ============
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
