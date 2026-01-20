-- Railway Database Setup for File Management System
-- Run this in Railway's MySQL Query tab

SET FOREIGN_KEY_CHECKS = 0;

-- Users table (must be created first - other tables reference it)
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `storage_quota` bigint(20) DEFAULT 104857600,
  `storage_used` bigint(20) DEFAULT 0,
  `dark_mode` tinyint(1) DEFAULT 0,
  `email_verified` tinyint(1) DEFAULT 0,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default admin user (password: admin123)
INSERT INTO `users` (`username`, `email`, `password`, `role`, `storage_quota`) 
VALUES ('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 524288000)
ON DUPLICATE KEY UPDATE username=username;

-- Files table
CREATE TABLE IF NOT EXISTS `files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `uploaded_by` varchar(50) DEFAULT NULL,
  `upload_date` timestamp NULL DEFAULT current_timestamp(),
  `file_path` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Activity logs table
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `item_path` varchar(500) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Favorites table
CREATE TABLE IF NOT EXISTS `favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `item_path` varchar(500) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `is_folder` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_favorite` (`user_id`,`item_path`(255)),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Trash table
CREATE TABLE IF NOT EXISTS `trash` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `original_path` varchar(500) NOT NULL,
  `trash_name` varchar(255) NOT NULL,
  `file_size` bigint(20) DEFAULT 0,
  `is_folder` tinyint(1) DEFAULT 0,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Shared links table
CREATE TABLE IF NOT EXISTS `shared_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `share_token` varchar(64) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `download_count` int(11) DEFAULT 0,
  `max_downloads` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_token` (`share_token`),
  KEY `idx_share_token` (`share_token`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- File versions table
CREATE TABLE IF NOT EXISTS `file_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `version_number` int(11) NOT NULL DEFAULT 1,
  `version_path` varchar(500) NOT NULL,
  `file_size` bigint(20) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_file_versions` (`user_id`,`file_path`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- File comments table
CREATE TABLE IF NOT EXISTS `file_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_file_path` (`user_id`,`file_path`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- File index for search
CREATE TABLE IF NOT EXISTS `file_index` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT 0,
  `is_folder` tinyint(1) DEFAULT 0,
  `indexed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  FULLTEXT KEY `idx_file_search` (`file_name`,`file_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Password resets table
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Email verifications table
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Two factor auth table
CREATE TABLE IF NOT EXISTS `two_factor_auth` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `secret_key` varchar(32) NOT NULL,
  `is_enabled` tinyint(1) DEFAULT 0,
  `backup_codes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Done! Tables created successfully.
