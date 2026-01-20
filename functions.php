<?php
// functions.php: Helper functions for File Management System

// Suppress errors display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get user's base directory
 */
function getUserBaseDir($userId) {
    $baseDir = "file_storage/user_" . intval($userId) . "/";
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }
    return $baseDir;
}

/**
 * Get user's trash directory
 */
function getUserTrashDir($userId) {
    $trashDir = "file_storage/.trash/user_" . intval($userId) . "/";
    if (!is_dir($trashDir)) {
        mkdir($trashDir, 0777, true);
    }
    return $trashDir;
}

/**
 * Log activity
 */
function logActivity($conn, $userId, $action, $itemName = null, $itemPath = null, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, item_name, item_path, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $userId, $action, $itemName, $itemPath, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * Update user's storage used
 */
function updateStorageUsed($conn, $userId) {
    $baseDir = getUserBaseDir($userId);
    $size = getDirectorySize($baseDir);
    $stmt = $conn->prepare("UPDATE users SET storage_used = ? WHERE id = ?");
    $stmt->bind_param("ii", $size, $userId);
    $stmt->execute();
    $stmt->close();
    return $size;
}

/**
 * Get directory size recursively
 */
function getDirectorySize($dir) {
    $size = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    }
    return $size;
}

/**
 * Get user's storage info
 */
function getUserStorageInfo($conn, $userId) {
    $stmt = $conn->prepare("SELECT storage_quota, storage_used FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data ?: ['storage_quota' => 104857600, 'storage_used' => 0];
}

/**
 * Check if user can upload
 */
function canUpload($conn, $userId, $fileSize) {
    $storage = getUserStorageInfo($conn, $userId);
    return ($storage['storage_used'] + $fileSize) <= $storage['storage_quota'];
}

/**
 * Format file size
 */
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unitIndex = 0;
    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }
    return round($size, 2) . ' ' . $units[$unitIndex];
}

/**
 * Get file icon
 */
function getFileIcon($extension) {
    $icons = [
        'pdf' => '📄', 'doc' => '📝', 'docx' => '📝', 'txt' => '📄',
        'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️', 'bmp' => '🖼️', 'webp' => '🖼️',
        'mp4' => '🎥', 'avi' => '🎥', 'mov' => '🎥', 'mkv' => '🎥', 'webm' => '🎥',
        'mp3' => '🎵', 'wav' => '🎵', 'flac' => '🎵', 'ogg' => '🎵',
        'zip' => '📦', 'rar' => '📦', '7z' => '📦', 'tar' => '📦', 'gz' => '📦',
        'xls' => '📊', 'xlsx' => '📊', 'csv' => '📊',
        'ppt' => '📽️', 'pptx' => '📽️',
        'html' => '🌐', 'css' => '🎨', 'js' => '⚡', 'php' => '🐘', 'py' => '🐍',
        'exe' => '⚙️', 'msi' => '⚙️', 'dmg' => '⚙️'
    ];
    return $icons[strtolower($extension)] ?? '📄';
}

/**
 * Check if file is previewable
 */
function isPreviewable($extension) {
    $previewable = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'],
        'video' => ['mp4', 'webm', 'ogg'],
        'audio' => ['mp3', 'wav', 'ogg'],
        'text' => ['txt', 'md', 'css', 'js', 'html', 'php', 'py', 'json', 'xml', 'csv'],
        'pdf' => ['pdf']
    ];
    
    $ext = strtolower($extension);
    foreach ($previewable as $type => $extensions) {
        if (in_array($ext, $extensions)) {
            return $type;
        }
    }
    return false;
}

/**
 * Generate share token
 */
function generateShareToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Check if item is favorited
 */
function isFavorite($conn, $userId, $itemPath) {
    $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND item_path = ?");
    $stmt->bind_param("is", $userId, $itemPath);
    $stmt->execute();
    $result = $stmt->get_result();
    $isFav = $result->num_rows > 0;
    $stmt->close();
    return $isFav;
}

/**
 * Toggle favorite
 */
function toggleFavorite($conn, $userId, $itemPath, $itemName, $isFolder = false) {
    if (isFavorite($conn, $userId, $itemPath)) {
        $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND item_path = ?");
        $stmt->bind_param("is", $userId, $itemPath);
        $stmt->execute();
        $stmt->close();
        return false;
    } else {
        $stmt = $conn->prepare("INSERT INTO favorites (user_id, item_path, item_name, is_folder) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("issi", $userId, $itemPath, $itemName, $isFolder);
        $stmt->execute();
        $stmt->close();
        return true;
    }
}

/**
 * Get user's favorites
 */
function getFavorites($conn, $userId) {
    $favorites = [];
    $stmt = $conn->prepare("SELECT * FROM favorites WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $favorites[] = $row;
    }
    $stmt->close();
    return $favorites;
}

/**
 * Get user's dark mode preference
 */
function getDarkMode($conn, $userId) {
    $stmt = $conn->prepare("SELECT dark_mode FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data ? (bool)$data['dark_mode'] : false;
}

/**
 * Set user's dark mode preference
 */
function setDarkMode($conn, $userId, $enabled) {
    $val = $enabled ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET dark_mode = ? WHERE id = ?");
    $stmt->bind_param("ii", $val, $userId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Move item to trash
 */
function moveToTrash($conn, $userId, $sourcePath, $itemName, $isFolder = false) {
    $trashDir = getUserTrashDir($userId);
    $trashName = time() . '_' . $itemName;
    $trashPath = $trashDir . $trashName;
    
    $fileSize = 0;
    if ($isFolder) {
        $fileSize = getDirectorySize($sourcePath);
    } else {
        $fileSize = filesize($sourcePath);
    }
    
    // Get the relative path from user's base dir
    $baseDir = getUserBaseDir($userId);
    $relativePath = str_replace($baseDir, '', dirname($sourcePath) . '/');
    $relativePath = trim($relativePath, '/');
    $originalPath = ($relativePath ? $relativePath . '/' : '') . $itemName;
    
    if (@rename($sourcePath, $trashPath)) {
        $stmt = $conn->prepare("INSERT INTO trash (user_id, original_name, original_path, trash_name, file_size, is_folder) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssii", $userId, $itemName, $originalPath, $trashName, $fileSize, $isFolder);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    return false;
}

/**
 * Restore item from trash
 */
function restoreFromTrash($conn, $userId, $trashId) {
    $stmt = $conn->prepare("SELECT * FROM trash WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $trashId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    
    if (!$item) return false;
    
    $trashDir = getUserTrashDir($userId);
    $baseDir = getUserBaseDir($userId);
    
    $trashPath = $trashDir . $item['trash_name'];
    $restoreDir = $baseDir . dirname($item['original_path']);
    
    if ($restoreDir !== $baseDir && !is_dir($restoreDir)) {
        mkdir($restoreDir, 0777, true);
    }
    
    $restorePath = $baseDir . $item['original_path'];
    
    // If file exists at original location, add timestamp
    if (file_exists($restorePath)) {
        $ext = pathinfo($item['original_name'], PATHINFO_EXTENSION);
        $name = pathinfo($item['original_name'], PATHINFO_FILENAME);
        $newName = $name . '_restored_' . time() . ($ext ? '.' . $ext : '');
        $restorePath = dirname($restorePath) . '/' . $newName;
    }
    
    if (@rename($trashPath, $restorePath)) {
        $stmt = $conn->prepare("DELETE FROM trash WHERE id = ?");
        $stmt->bind_param("i", $trashId);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    return false;
}

/**
 * Permanently delete from trash
 */
function permanentDelete($conn, $userId, $trashId) {
    $stmt = $conn->prepare("SELECT * FROM trash WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $trashId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    
    if (!$item) return false;
    
    $trashDir = getUserTrashDir($userId);
    $trashPath = $trashDir . $item['trash_name'];
    
    $deleted = false;
    if ($item['is_folder']) {
        $deleted = deleteDirectory($trashPath);
    } else {
        $deleted = @unlink($trashPath);
    }
    
    if ($deleted) {
        $stmt = $conn->prepare("DELETE FROM trash WHERE id = ?");
        $stmt->bind_param("i", $trashId);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    return false;
}

/**
 * Delete directory recursively
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) return false;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? deleteDirectory($path) : @unlink($path);
    }
    return @rmdir($dir);
}

/**
 * Empty trash
 */
function emptyTrash($conn, $userId) {
    $trashDir = getUserTrashDir($userId);
    
    // Delete all files in trash directory
    if (is_dir($trashDir)) {
        $items = array_diff(scandir($trashDir), ['.', '..']);
        foreach ($items as $item) {
            $path = $trashDir . $item;
            if (is_dir($path)) {
                deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
    }
    
    // Clear trash table
    $stmt = $conn->prepare("DELETE FROM trash WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    
    return true;
}

/**
 * Get trash items
 */
function getTrashItems($conn, $userId) {
    $items = [];
    $stmt = $conn->prepare("SELECT * FROM trash WHERE user_id = ? ORDER BY deleted_at DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

/**
 * Get trash count
 */
function getTrashCount($conn, $userId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM trash WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data['count'] ?? 0;
}

/**
 * Generate random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Send email (basic implementation - configure SMTP for production)
 */
function sendEmail($to, $subject, $body) {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: File Manager <noreply@localhost>\r\n";
    
    return @mail($to, $subject, $body, $headers);
}

/**
 * Create email verification token
 */
function createEmailVerification($conn, $userId) {
    $token = generateToken();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Delete existing tokens for this user
    $stmt = $conn->prepare("DELETE FROM email_verifications WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    
    // Create new token
    $stmt = $conn->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $token, $expiresAt);
    $stmt->execute();
    $stmt->close();
    
    return $token;
}

/**
 * Verify email token
 */
function verifyEmailToken($conn, $token) {
    $stmt = $conn->prepare("SELECT user_id FROM email_verifications WHERE token = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $data = $result->fetch_assoc();
        $userId = $data['user_id'];
        $stmt->close();
        
        // Mark email as verified
        $stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        
        // Delete the token
        $stmt = $conn->prepare("DELETE FROM email_verifications WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $stmt->close();
        
        return $userId;
    }
    $stmt->close();
    return false;
}

/**
 * Create password reset token
 */
function createPasswordReset($conn, $email) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $userId = $user['id'];
        $stmt->close();
        
        $token = generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Delete existing tokens
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        
        // Create new token
        $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $userId, $token, $expiresAt);
        $stmt->execute();
        $stmt->close();
        
        return $token;
    }
    $stmt->close();
    return false;
}

/**
 * Verify password reset token
 */
function verifyPasswordResetToken($conn, $token) {
    $stmt = $conn->prepare("SELECT user_id FROM password_resets WHERE token = ? AND expires_at > NOW() AND used = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data['user_id'];
    }
    $stmt->close();
    return false;
}

/**
 * Use password reset token
 */
function usePasswordResetToken($conn, $token, $newPassword) {
    $userId = verifyPasswordResetToken($conn, $token);
    if ($userId) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashedPassword, $userId);
        $stmt->execute();
        $stmt->close();
        
        // Mark token as used
        $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $stmt->close();
        
        return true;
    }
    return false;
}

/**
 * Add file comment
 */
function addFileComment($conn, $userId, $filePath, $fileName, $comment) {
    $stmt = $conn->prepare("INSERT INTO file_comments (user_id, file_path, file_name, comment) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $userId, $filePath, $fileName, $comment);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Get file comments
 */
function getFileComments($conn, $userId, $filePath) {
    $comments = [];
    $stmt = $conn->prepare("SELECT * FROM file_comments WHERE user_id = ? AND file_path = ? ORDER BY created_at DESC");
    $stmt->bind_param("is", $userId, $filePath);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $comments[] = $row;
    }
    $stmt->close();
    return $comments;
}

/**
 * Delete file comment
 */
function deleteFileComment($conn, $userId, $commentId) {
    $stmt = $conn->prepare("DELETE FROM file_comments WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $commentId, $userId);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Create file version before overwriting
 */
function createFileVersion($conn, $userId, $filePath, $fileName, $sourcePath) {
    // Use absolute path based on script location
    $baseDir = dirname(__FILE__) . '/';
    $versionsDir = $baseDir . "file_storage/.versions/user_" . intval($userId) . "/";
    if (!is_dir($versionsDir)) {
        @mkdir($versionsDir, 0777, true);
    }
    
    // Get current version number
    $stmt = $conn->prepare("SELECT MAX(version_number) as max_ver FROM file_versions WHERE user_id = ? AND file_path = ?");
    if (!$stmt) {
        // Table might not exist, skip versioning
        return false;
    }
    $stmt->bind_param("is", $userId, $filePath);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    $versionNumber = ($data['max_ver'] ?? 0) + 1;
    $versionFileName = pathinfo($fileName, PATHINFO_FILENAME) . "_v" . $versionNumber . "." . pathinfo($fileName, PATHINFO_EXTENSION);
    $versionPath = $versionsDir . $versionFileName;
    
    // Copy file to versions directory
    if (copy($sourcePath, $versionPath)) {
        $fileSize = filesize($sourcePath);
        
        $stmt = $conn->prepare("INSERT INTO file_versions (user_id, file_path, file_name, version_number, version_path, file_size) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issisi", $userId, $filePath, $fileName, $versionNumber, $versionPath, $fileSize);
        $stmt->execute();
        $stmt->close();
        
        return $versionNumber;
    }
    return false;
}

/**
 * Get file versions
 */
function getFileVersions($conn, $userId, $filePath) {
    $versions = [];
    $stmt = $conn->prepare("SELECT * FROM file_versions WHERE user_id = ? AND file_path = ? ORDER BY version_number DESC");
    $stmt->bind_param("is", $userId, $filePath);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $versions[] = $row;
    }
    $stmt->close();
    return $versions;
}

/**
 * Restore file version
 */
function restoreFileVersion($conn, $userId, $versionId) {
    $stmt = $conn->prepare("SELECT * FROM file_versions WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $versionId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $version = $result->fetch_assoc();
        $stmt->close();
        
        $baseDir = getUserBaseDir($userId);
        $targetPath = $baseDir . $version['file_path'];
        
        // Create backup of current file
        if (file_exists($targetPath)) {
            createFileVersion($conn, $userId, $version['file_path'], $version['file_name'], $targetPath);
        }
        
        // Restore version
        if (copy($version['version_path'], $targetPath)) {
            return true;
        }
    }
    $stmt->close();
    return false;
}

/**
 * Create ZIP of folder
 */
function createFolderZip($folderPath, $zipPath) {
    if (!class_exists('ZipArchive')) {
        return false;
    }
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    
    $folderPath = rtrim($folderPath, '/\\');
    $baseName = basename($folderPath);
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folderPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        $filePath = $file->getRealPath();
        $relativePath = $baseName . '/' . substr($filePath, strlen($folderPath) + 1);
        
        if ($file->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($filePath, $relativePath);
        }
    }
    
    return $zip->close();
}

/**
 * Create thumbnail for image
 */
function createThumbnail($sourcePath, $thumbPath, $maxWidth = 150, $maxHeight = 150) {
    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) return false;
    
    $mime = $imageInfo['mime'];
    $width = $imageInfo[0];
    $height = $imageInfo[1];
    
    // Calculate new dimensions
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = round($width * $ratio);
    $newHeight = round($height * $ratio);
    
    // Create source image
    switch ($mime) {
        case 'image/jpeg':
            $source = @imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $source = @imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $source = @imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }
    
    if (!$source) return false;
    
    // Create thumbnail
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG and GIF
    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
        imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Save thumbnail
    $thumbDir = dirname($thumbPath);
    if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0777, true);
    }
    
    $result = false;
    switch ($mime) {
        case 'image/jpeg':
            $result = imagejpeg($thumb, $thumbPath, 85);
            break;
        case 'image/png':
            $result = imagepng($thumb, $thumbPath, 8);
            break;
        case 'image/gif':
            $result = imagegif($thumb, $thumbPath);
            break;
        case 'image/webp':
            $result = imagewebp($thumb, $thumbPath, 85);
            break;
    }
    
    imagedestroy($source);
    imagedestroy($thumb);
    
    return $result;
}

/**
 * Get thumbnail path for a file
 */
function getThumbnailPath($userId, $filePath) {
    $thumbDir = "file_storage/.thumbs/user_" . intval($userId) . "/";
    $hash = md5($filePath);
    return $thumbDir . $hash . ".jpg";
}

/**
 * Search files in user's directory
 */
function searchFiles($baseDir, $searchTerm, $currentPath = '') {
    $results = [];
    $searchTerm = strtolower($searchTerm);
    $dir = $baseDir . $currentPath;
    
    if (!is_dir($dir)) return $results;
    
    $items = @array_diff(scandir($dir), ['.', '..']);
    if (!$items) return $results;
    
    foreach ($items as $item) {
        $itemPath = $dir . $item;
        $relativePath = ($currentPath ? $currentPath : '') . $item;
        
        // Check if name matches
        if (stripos($item, $searchTerm) !== false) {
            $results[] = [
                'name' => $item,
                'path' => $relativePath,
                'is_folder' => is_dir($itemPath),
                'size' => is_file($itemPath) ? filesize($itemPath) : 0,
                'modified' => filemtime($itemPath)
            ];
        }
        
        // Recurse into subdirectories
        if (is_dir($itemPath)) {
            $subResults = searchFiles($baseDir, $searchTerm, $relativePath . '/');
            $results = array_merge($results, $subResults);
        }
    }
    
    return $results;
}

/**
 * Index files for search (update file_index table)
 */
function indexUserFiles($conn, $userId, $baseDir, $currentPath = '') {
    $dir = $baseDir . $currentPath;
    if (!is_dir($dir)) return;
    
    $items = @array_diff(scandir($dir), ['.', '..']);
    if (!$items) return;
    
    foreach ($items as $item) {
        $itemPath = $dir . $item;
        $relativePath = ($currentPath ? $currentPath : '') . $item;
        $isFolder = is_dir($itemPath) ? 1 : 0;
        $fileSize = $isFolder ? 0 : filesize($itemPath);
        $fileType = $isFolder ? 'folder' : strtolower(pathinfo($item, PATHINFO_EXTENSION));
        
        // Check if already indexed
        $stmt = $conn->prepare("SELECT id FROM file_index WHERE user_id = ? AND file_path = ?");
        $stmt->bind_param("is", $userId, $relativePath);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO file_index (user_id, file_path, file_name, file_type, file_size, is_folder) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssii", $userId, $relativePath, $item, $fileType, $fileSize, $isFolder);
            $stmt->execute();
        }
        $stmt->close();
        
        // Recurse into subdirectories
        if ($isFolder) {
            indexUserFiles($conn, $userId, $baseDir, $relativePath . '/');
        }
    }
}

/**
 * Search indexed files
 */
function searchIndexedFiles($conn, $userId, $searchTerm) {
    $results = [];
    $searchPattern = '%' . $searchTerm . '%';
    
    $stmt = $conn->prepare("SELECT * FROM file_index WHERE user_id = ? AND (file_name LIKE ? OR file_path LIKE ?) ORDER BY is_folder DESC, file_name ASC LIMIT 100");
    $stmt->bind_param("iss", $userId, $searchPattern, $searchPattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();
    
    return $results;
}
?>
