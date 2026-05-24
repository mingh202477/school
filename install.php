<?php
// 安装脚本
require_once 'vendor/autoload.php';

// 加载.env
if (file_exists('.env')) {
    $env = parse_ini_file('.env');
    foreach ($env as $key => $value) {
        putenv("$key=$value");
    }
}

use think\facade\Db;

try {
    // 创建users表
    Db::execute("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        reset_token VARCHAR(255),
        reset_expires DATETIME,
        create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // 创建admins表
    Db::execute("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        reset_token VARCHAR(255),
        reset_expires DATETIME,
        create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // 插入默认管理员
    $adminPassword = password_hash('admin123', PASSWORD_ARGON2ID);
    Db::execute("INSERT IGNORE INTO admins (username, email, password) VALUES ('admin', 'admin@example.com', '$adminPassword')");

    echo "数据库表创建成功，默认管理员用户名: admin, 密码: admin123\n";
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}