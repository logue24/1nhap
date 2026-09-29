-- Tạo database
CREATE DATABASE IF NOT EXISTS like_system 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE like_system;

-- Bảng lưu lượt like
CREATE TABLE IF NOT EXISTS likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_hash VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    webrtc_data TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uniq_device (device_hash),
    UNIQUE KEY uniq_ip (ip_address),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng đếm tổng (tối ưu hiển thị realtime)
CREATE TABLE IF NOT EXISTS like_stats (
    id TINYINT PRIMARY KEY DEFAULT 1,
    total INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO like_stats (id, total) VALUES (1, 0);
