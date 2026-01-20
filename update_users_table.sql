-- SQL script to update the users table for registration functionality
-- Run this in your MySQL/phpMyAdmin to add the email column

-- Add email column to users table if it doesn't exist
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email` VARCHAR(100) DEFAULT NULL AFTER `username`;

-- Add unique index on email (if column exists and doesn't have index)
-- ALTER TABLE `users` ADD UNIQUE INDEX `email_unique` (`email`);

-- Update the admin user's password to be hashed (optional - for better security)
-- UPDATE `users` SET `password` = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE `username` = 'admin';
-- Note: The hashed password above is 'password'
