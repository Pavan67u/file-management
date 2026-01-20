-- Database update script for new features
-- Run this in phpMyAdmin or MySQL

-- Add storage quota and preferences to users table
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `storage_quota` BIGINT DEFAULT 104857600 COMMENT 'Storage quota in bytes (default 100MB)';
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `storage_used` BIGINT DEFAULT 0 COMMENT 'Storage used in bytes';
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `dark_mode` TINYINT(1) DEFAULT 0;

-- Create activity_logs table
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `action` VARCHAR(50) NOT NULL COMMENT 'upload, download, delete, rename, move, share, login, logout',
    `item_name` VARCHAR(255) DEFAULT NULL,
    `item_path` VARCHAR(500) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create trash table
CREATE TABLE IF NOT EXISTS `trash` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `original_path` VARCHAR(500) NOT NULL,
    `trash_name` VARCHAR(255) NOT NULL COMMENT 'Unique name in trash folder',
    `file_size` BIGINT DEFAULT 0,
    `is_folder` TINYINT(1) DEFAULT 0,
    `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create shared_links table
CREATE TABLE IF NOT EXISTS `shared_links` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `share_token` VARCHAR(64) NOT NULL UNIQUE,
    `password` VARCHAR(255) DEFAULT NULL COMMENT 'Optional password protection',
    `expires_at` DATETIME DEFAULT NULL COMMENT 'Optional expiry date',
    `download_count` INT DEFAULT 0,
    `max_downloads` INT DEFAULT NULL COMMENT 'Optional download limit',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_share_token` (`share_token`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create favorites table
CREATE TABLE IF NOT EXISTS `favorites` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `item_path` VARCHAR(500) NOT NULL,
    `item_name` VARCHAR(255) NOT NULL,
    `is_folder` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_favorite` (`user_id`, `item_path`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
