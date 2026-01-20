CREATE TABLE IF NOT EXISTS users (id int NOT NULL AUTO_INCREMENT, username varchar(50) NOT NULL, email varchar(100) DEFAULT NULL, password varchar(255) NOT NULL, role enum('admin','user') DEFAULT 'user', created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP, storage_quota bigint DEFAULT 104857600, storage_used bigint DEFAULT 0, dark_mode tinyint(1) DEFAULT 0, email_verified tinyint(1) DEFAULT 0, two_factor_enabled tinyint(1) DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY username (username));

INSERT INTO users (username, email, password, role, storage_quota) VALUES ('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 524288000) ON DUPLICATE KEY UPDATE username=username;

CREATE TABLE IF NOT EXISTS files (id int NOT NULL AUTO_INCREMENT, file_name varchar(255) NOT NULL, uploaded_by varchar(50) DEFAULT NULL, upload_date timestamp NULL DEFAULT CURRENT_TIMESTAMP, file_path varchar(255) NOT NULL, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS activity_logs (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, action varchar(50) NOT NULL, item_name varchar(255) DEFAULT NULL, item_path varchar(500) DEFAULT NULL, details text, ip_address varchar(45) DEFAULT NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS favorites (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, item_path varchar(500) NOT NULL, item_name varchar(255) NOT NULL, is_folder tinyint(1) DEFAULT 0, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS trash (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, original_name varchar(255) NOT NULL, original_path varchar(500) NOT NULL, trash_name varchar(255) NOT NULL, file_size bigint DEFAULT 0, is_folder tinyint(1) DEFAULT 0, deleted_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS shared_links (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, file_path varchar(500) NOT NULL, file_name varchar(255) NOT NULL, share_token varchar(64) NOT NULL, password varchar(255) DEFAULT NULL, expires_at datetime DEFAULT NULL, download_count int DEFAULT 0, max_downloads int DEFAULT NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY share_token (share_token));

CREATE TABLE IF NOT EXISTS file_versions (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, file_path varchar(500) NOT NULL, file_name varchar(255) NOT NULL, version_number int NOT NULL DEFAULT 1, version_path varchar(500) NOT NULL, file_size bigint DEFAULT 0, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS file_comments (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, file_path varchar(500) NOT NULL, file_name varchar(255) NOT NULL, comment text NOT NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS file_index (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, file_path varchar(500) NOT NULL, file_name varchar(255) NOT NULL, file_type varchar(50) DEFAULT NULL, file_size bigint DEFAULT 0, is_folder tinyint(1) DEFAULT 0, indexed_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS password_resets (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, token varchar(64) NOT NULL, expires_at datetime NOT NULL, used tinyint(1) DEFAULT 0, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS email_verifications (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, token varchar(64) NOT NULL, expires_at datetime NOT NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id));

CREATE TABLE IF NOT EXISTS two_factor_auth (id int NOT NULL AUTO_INCREMENT, user_id int NOT NULL, secret_key varchar(32) NOT NULL, is_enabled tinyint(1) DEFAULT 0, backup_codes text, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id));
