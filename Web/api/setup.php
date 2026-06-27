<?php
/**
 * BitHabit - 数据库初始化脚本
 * 
 * 访问一次即创建 users 表（幂等，IF NOT EXISTS）
 * 使用后建议删除或限制访问
 */

require_once __DIR__ . '/db.php';

$conn = getDbConnection();

$tables = [
    'users' => "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(20) NOT NULL UNIQUE,
        nickname VARCHAR(12) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'homework' => "CREATE TABLE IF NOT EXISTS homework (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        subject VARCHAR(50) NOT NULL,
        task_type VARCHAR(50) NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        unit VARCHAR(20) NOT NULL,
        time_per_unit INT DEFAULT NULL,
        window_start DATE DEFAULT NULL,
        window_end DATE DEFAULT NULL,
        locked TINYINT(1) DEFAULT 0,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'schedule_weekly' => "CREATE TABLE IF NOT EXISTS schedule_weekly (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        day_of_week TINYINT NOT NULL,
        start_time TIME DEFAULT NULL,
        end_time TIME DEFAULT NULL,
        label VARCHAR(50) DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'schedule_special' => "CREATE TABLE IF NOT EXISTS schedule_special (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        date_from DATE NOT NULL,
        date_to DATE DEFAULT NULL,
        start_time TIME DEFAULT NULL,
        end_time TIME DEFAULT NULL,
        label VARCHAR(50) DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'plans' => "CREATE TABLE IF NOT EXISTS plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(100) DEFAULT '暑假作业计划',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        daily_start_time TIME DEFAULT '08:00:00',
        daily_end_time TIME DEFAULT '22:00:00',
        strategy VARCHAR(20) DEFAULT 'average',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'plan_tasks' => "CREATE TABLE IF NOT EXISTS plan_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        homework_id INT NOT NULL,
        date DATE NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        estimated_minutes INT NOT NULL,
        sort_order INT DEFAULT 0,
        completed TINYINT(1) DEFAULT 0,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
        FOREIGN KEY (homework_id) REFERENCES homework(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

$allOk = true;
foreach ($tables as $name => $sql) {
    if ($conn->query($sql)) {
        echo "✅ 数据库表 '$name' 创建成功（或已存在）\n";
    } else {
        echo "❌ 表 '$name' 创建失败: " . $conn->error . "\n";
        $allOk = false;
    }
}

echo $allOk ? "\n🎉 所有表就绪！\n" : "\n⚠️ 部分表创建失败，请检查\n";

$conn->close();
