<?php
// download_file.php: Enhanced Download File Script

session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];

if (isset($_GET['file'])) {
    // User-specific storage
    $baseDir = getUserBaseDir($userId);
    
    // Get current path from URL
    $currentPath = $_GET['path'] ?? '';
    $currentPath = str_replace(['..', '\\'], ['', '/'], $currentPath);
    $currentPath = trim($currentPath, '/');
    
    $fileStorageDir = $baseDir . ($currentPath ? $currentPath . '/' : '');
    $fileName = basename($_GET['file']); // Sanitize the filename
    $filePath = $fileStorageDir . $fileName;

    // Check if the file exists and is within the storage directory
    if (file_exists($filePath) && is_file($filePath) && realpath($filePath) === realpath($fileStorageDir . $fileName)) {
        // Get file information
        $fileSize = filesize($filePath);
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        
        // Log activity
        logActivity($conn, $userId, 'download', $fileName, $currentPath, formatFileSize($fileSize));
        
        // Set appropriate headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $fileSize);

        // Clear any previous output
        ob_clean();
        flush();

        // Read and output the file
        readfile($filePath);
        exit();
    } else {
        // File not found, redirect with error message
        header("Location: dashboard.php?error=" . urlencode("File not found or access denied."));
        exit();
    }
} else {
    // No file parameter provided
    header("Location: dashboard.php?error=" . urlencode("No file specified for download."));
    exit();
}
?>