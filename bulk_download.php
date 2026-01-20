<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Not authenticated');
}

// Check if download is prepared
if (!isset($_SESSION['bulk_download'])) {
    header('HTTP/1.1 404 Not Found');
    exit('No download prepared');
}

$download = $_SESSION['bulk_download'];
$zipPath = $download['path'];
$zipName = $download['name'];

// Check if file exists and is recent (within 10 minutes)
if (!file_exists($zipPath) || (time() - $download['created']) > 600) {
    unset($_SESSION['bulk_download']);
    header('HTTP/1.1 404 Not Found');
    exit('Download expired');
}

// Clear the download session
unset($_SESSION['bulk_download']);

// Send the file
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($zipPath);

// Delete the temporary file
@unlink($zipPath);
exit();
?>
