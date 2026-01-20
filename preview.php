<?php
// preview.php: File Preview Handler

session_start();
require_once 'db_config.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$userId = $_SESSION['user_id'];
$fileName = $_GET['file'] ?? '';
$currentPath = $_GET['path'] ?? '';
$currentPath = str_replace(['..', '\\'], ['', '/'], $currentPath);
$currentPath = trim($currentPath, '/');

if (empty($fileName)) {
    http_response_code(400);
    exit('No file specified');
}

$baseDir = getUserBaseDir($userId);
$fullPath = $baseDir . ($currentPath ? $currentPath . '/' : '') . $fileName;

if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    exit('File not found');
}

// Security: Ensure file is within user's directory
$realBase = realpath($baseDir);
$realFile = realpath($fullPath);

if ($realBase === false || $realFile === false || strpos($realFile, $realBase) !== 0) {
    http_response_code(403);
    exit('Access denied');
}

$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mimeTypes = [
    // Images
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'bmp' => 'image/bmp',
    'ico' => 'image/x-icon',
    // Videos
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'ogg' => 'video/ogg',
    'mov' => 'video/quicktime',
    // Audio
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'flac' => 'audio/flac',
    'm4a' => 'audio/mp4',
    // Documents
    'pdf' => 'application/pdf',
    // Text
    'txt' => 'text/plain',
    'html' => 'text/html',
    'css' => 'text/css',
    'js' => 'text/javascript',
    'json' => 'application/json',
    'xml' => 'text/xml',
    'md' => 'text/plain',
    'php' => 'text/plain',
    'py' => 'text/plain',
    'java' => 'text/plain',
    'c' => 'text/plain',
    'cpp' => 'text/plain',
    'h' => 'text/plain',
    'sql' => 'text/plain',
    'sh' => 'text/plain',
    'bat' => 'text/plain',
    'log' => 'text/plain',
    'ini' => 'text/plain',
    'conf' => 'text/plain',
    'yml' => 'text/plain',
    'yaml' => 'text/plain',
];

$mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

// Log preview activity
logActivity($conn, $userId, 'preview', $fileName, $currentPath);

// Set headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=3600');

// Output file
readfile($fullPath);
exit();
