<?php
// delete_file.php: Enhanced Delete File Script

session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Simple logging function
function logDeletion($message) {
    $logDir = "logs/";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $sessionId = session_id();
    
    $logEntry = "[{$timestamp}] {$message} (Session: {$sessionId})" . PHP_EOL;
    @file_put_contents($logDir . 'deletions.log', $logEntry, FILE_APPEND | LOCK_EX);
}

// Check if file parameter is provided
if (!isset($_GET['file']) || empty($_GET['file'])) {
    header("Location: dashboard.php?error=" . urlencode("No file specified for deletion."));
    exit();
}

$baseDir = "file_storage/";

// Get current path from URL
$currentPath = $_GET['path'] ?? '';
$currentPath = str_replace(['..', '\\'], ['', '/'], $currentPath);
$currentPath = trim($currentPath, '/');

$fileStorageDir = $baseDir . ($currentPath ? $currentPath . '/' : '');
$fileName = basename($_GET['file']); // Sanitize the filename
$filePath = $fileStorageDir . $fileName;

// Security check: Ensure the file is within the storage directory
$realFilePath = realpath($filePath);
$realStorageDir = realpath($baseDir);

$redirectPath = $currentPath ? "dashboard.php?path=" . urlencode($currentPath) . "&" : "dashboard.php?";

if (!$realFilePath || !$realStorageDir || strpos($realFilePath, $realStorageDir) !== 0) {
    header("Location: " . $redirectPath . "error=" . urlencode("Invalid file path."));
    exit();
}

// Check if the file exists
if (!file_exists($filePath) || !is_file($filePath)) {
    header("Location: " . $redirectPath . "error=" . urlencode("File not found: " . htmlspecialchars($fileName)));
    exit();
}

// Attempt to delete the file
if (unlink($filePath)) {
    // Log the deletion
    logDeletion("User deleted file: " . $fileName);
    
    header("Location: " . $redirectPath . "message=" . urlencode("File '" . $fileName . "' has been successfully deleted."));
} else {
    header("Location: " . $redirectPath . "error=" . urlencode("Failed to delete file '" . $fileName . "'. Please check file permissions."));
}

exit();
?>