<?php
session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Not authenticated');
}

$userId = $_SESSION['user_id'];
$baseDir = getUserBaseDir($userId);
$folderPath = sanitizePath($_GET['folder'] ?? '');
$fullPath = $baseDir . $folderPath;

// Validate folder exists
if (empty($folderPath) || !is_dir($fullPath)) {
    header('HTTP/1.1 404 Not Found');
    exit('Folder not found');
}

// Check if ZipArchive is available
if (!class_exists('ZipArchive')) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('ZIP extension not available');
}

// Create temporary ZIP file
$tempDir = sys_get_temp_dir();
$folderName = basename($folderPath) ?: 'root';
$zipName = $folderName . '_' . date('Y-m-d_H-i-s') . '.zip';
$zipPath = $tempDir . '/' . $zipName;

// Create the ZIP file
if (!createFolderZip($fullPath, $zipPath)) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Failed to create ZIP file');
}

// Check if ZIP was created successfully
if (!file_exists($zipPath)) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('ZIP file not created');
}

// Log the activity
logActivity($conn, $userId, 'download_folder', $folderName, $folderPath);

// Send the ZIP file
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($zipPath);

// Delete the temporary ZIP file
@unlink($zipPath);

$conn->close();
exit();
?>
