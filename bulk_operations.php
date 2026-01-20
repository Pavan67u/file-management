<?php
session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

$userId = $_SESSION['user_id'];
$baseDir = getUserBaseDir($userId);
$action = $_POST['action'] ?? '';
$items = $_POST['items'] ?? [];

// Validate items
if (!is_array($items) || empty($items)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No items selected']);
    exit();
}

header('Content-Type: application/json');

switch ($action) {
    case 'delete':
        bulkDelete($conn, $userId, $baseDir, $items);
        break;
    
    case 'move':
        $destination = $_POST['destination'] ?? '';
        bulkMove($conn, $userId, $baseDir, $items, $destination);
        break;
    
    case 'copy':
        $destination = $_POST['destination'] ?? '';
        bulkCopy($conn, $userId, $baseDir, $items, $destination);
        break;
    
    case 'download':
        bulkDownload($userId, $baseDir, $items);
        break;
    
    case 'favorite':
        bulkFavorite($conn, $userId, $items, true);
        break;
    
    case 'unfavorite':
        bulkFavorite($conn, $userId, $items, false);
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}

/**
 * Bulk delete files/folders to trash
 */
function bulkDelete($conn, $userId, $baseDir, $items) {
    $success = 0;
    $failed = 0;
    
    foreach ($items as $item) {
        $item = sanitizePath($item);
        $path = $baseDir . $item;
        
        if (file_exists($path)) {
            if (moveToTrash($conn, $userId, $item, basename($item), is_dir($path))) {
                logActivity($conn, $userId, 'bulk_delete', basename($item), $item);
                $success++;
            } else {
                $failed++;
            }
        } else {
            $failed++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "$success item(s) moved to trash" . ($failed > 0 ? ", $failed failed" : "")
    ]);
}

/**
 * Bulk move files/folders
 */
function bulkMove($conn, $userId, $baseDir, $items, $destination) {
    $destination = sanitizePath($destination);
    $destPath = $baseDir . $destination;
    
    if (!is_dir($destPath)) {
        echo json_encode(['success' => false, 'message' => 'Destination folder not found']);
        return;
    }
    
    $success = 0;
    $failed = 0;
    
    foreach ($items as $item) {
        $item = sanitizePath($item);
        $sourcePath = $baseDir . $item;
        $itemName = basename($item);
        $newPath = rtrim($destPath, '/') . '/' . $itemName;
        
        if (file_exists($sourcePath) && !file_exists($newPath)) {
            if (rename($sourcePath, $newPath)) {
                $newRelativePath = trim($destination, '/') . '/' . $itemName;
                logActivity($conn, $userId, 'bulk_move', $itemName, $item . ' -> ' . $newRelativePath);
                $success++;
            } else {
                $failed++;
            }
        } else {
            $failed++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "$success item(s) moved" . ($failed > 0 ? ", $failed failed" : "")
    ]);
}

/**
 * Bulk copy files/folders
 */
function bulkCopy($conn, $userId, $baseDir, $items, $destination) {
    $destination = sanitizePath($destination);
    $destPath = $baseDir . $destination;
    
    if (!is_dir($destPath)) {
        echo json_encode(['success' => false, 'message' => 'Destination folder not found']);
        return;
    }
    
    $success = 0;
    $failed = 0;
    
    foreach ($items as $item) {
        $item = sanitizePath($item);
        $sourcePath = $baseDir . $item;
        $itemName = basename($item);
        $newPath = rtrim($destPath, '/') . '/' . $itemName;
        
        if (file_exists($sourcePath) && !file_exists($newPath)) {
            if (is_dir($sourcePath)) {
                if (copyFolder($sourcePath, $newPath)) {
                    $success++;
                } else {
                    $failed++;
                }
            } else {
                if (copy($sourcePath, $newPath)) {
                    $success++;
                } else {
                    $failed++;
                }
            }
            if ($success > 0) {
                $newRelativePath = trim($destination, '/') . '/' . $itemName;
                logActivity($conn, $userId, 'bulk_copy', $itemName, $item . ' -> ' . $newRelativePath);
            }
        } else {
            $failed++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "$success item(s) copied" . ($failed > 0 ? ", $failed failed" : "")
    ]);
}

/**
 * Copy folder recursively
 */
function copyFolder($source, $dest) {
    if (!is_dir($dest)) {
        mkdir($dest, 0777, true);
    }
    
    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            $srcPath = $source . '/' . $file;
            $dstPath = $dest . '/' . $file;
            
            if (is_dir($srcPath)) {
                copyFolder($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }
    closedir($dir);
    
    return true;
}

/**
 * Bulk download as ZIP
 */
function bulkDownload($userId, $baseDir, $items) {
    if (!class_exists('ZipArchive')) {
        echo json_encode(['success' => false, 'message' => 'ZIP extension not available']);
        return;
    }
    
    // Create temporary ZIP file
    $tempDir = sys_get_temp_dir();
    $zipName = 'download_' . time() . '_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo json_encode(['success' => false, 'message' => 'Could not create ZIP file']);
        return;
    }
    
    $addedFiles = 0;
    foreach ($items as $item) {
        $item = sanitizePath($item);
        $sourcePath = $baseDir . $item;
        $itemName = basename($item);
        
        if (!file_exists($sourcePath)) continue;
        
        if (is_dir($sourcePath)) {
            addFolderToZip($zip, $sourcePath, $itemName);
        } else {
            $zip->addFile($sourcePath, $itemName);
        }
        $addedFiles++;
    }
    
    $zip->close();
    
    if ($addedFiles === 0) {
        @unlink($zipPath);
        echo json_encode(['success' => false, 'message' => 'No valid files to download']);
        return;
    }
    
    // Store ZIP path in session for download
    $_SESSION['bulk_download'] = [
        'path' => $zipPath,
        'name' => 'files_' . date('Y-m-d_H-i-s') . '.zip',
        'created' => time()
    ];
    
    echo json_encode([
        'success' => true,
        'download' => true,
        'downloadUrl' => 'bulk_download.php'
    ]);
}

/**
 * Add folder contents to ZIP
 */
function addFolderToZip($zip, $folderPath, $zipFolder) {
    $zip->addEmptyDir($zipFolder);
    
    $files = array_diff(scandir($folderPath), ['.', '..']);
    foreach ($files as $file) {
        $filePath = $folderPath . '/' . $file;
        $zipPath = $zipFolder . '/' . $file;
        
        if (is_dir($filePath)) {
            addFolderToZip($zip, $filePath, $zipPath);
        } else {
            $zip->addFile($filePath, $zipPath);
        }
    }
}

/**
 * Bulk add/remove favorites
 */
function bulkFavorite($conn, $userId, $items, $add = true) {
    $success = 0;
    
    foreach ($items as $item) {
        $item = sanitizePath($item);
        $itemName = basename($item);
        
        if ($add) {
            if (addToFavorites($conn, $userId, $item, $itemName, false)) {
                $success++;
            }
        } else {
            if (removeFromFavorites($conn, $userId, $item)) {
                $success++;
            }
        }
    }
    
    $action = $add ? 'added to' : 'removed from';
    echo json_encode([
        'success' => true,
        'message' => "$success item(s) $action favorites"
    ]);
}

$conn->close();
?>
