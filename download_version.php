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
$versionId = intval($_GET['id'] ?? 0);

// Get version info
$stmt = $conn->prepare("SELECT * FROM file_versions WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $versionId, $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $stmt->close();
    header('HTTP/1.1 404 Not Found');
    exit('Version not found');
}

$version = $result->fetch_assoc();
$stmt->close();

$versionPath = $version['version_path'];
$fileName = $version['file_name'];

if (!file_exists($versionPath)) {
    header('HTTP/1.1 404 Not Found');
    exit('Version file not found');
}

// Generate download name with version number
$ext = pathinfo($fileName, PATHINFO_EXTENSION);
$baseName = pathinfo($fileName, PATHINFO_FILENAME);
$downloadName = $baseName . '_v' . $version['version_number'] . '.' . $ext;

// Get MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $versionPath);
finfo_close($finfo);

// Send file
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($versionPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($versionPath);

$conn->close();
exit();
?>
