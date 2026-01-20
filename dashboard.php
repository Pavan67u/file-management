<?php
// dashboard.php: Enhanced File Manager with All Features

session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$csrfToken = generateCSRFToken();
$darkMode = getDarkMode($conn, $userId);

// Handle dark mode toggle via AJAX
if (isset($_POST['toggle_dark_mode']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $newMode = !$darkMode;
    setDarkMode($conn, $userId, $newMode);
    echo json_encode(['success' => true, 'dark_mode' => $newMode]);
    exit();
}

// User-specific storage
$baseDir = getUserBaseDir($userId);

// Get current path from URL
$currentPath = $_GET['path'] ?? '';
$currentPath = str_replace(['..', '\\'], ['', '/'], $currentPath);
$currentPath = trim($currentPath, '/');

$fullPath = $baseDir . ($currentPath ? $currentPath . '/' : '');

// Ensure the directory exists
if (!is_dir($fullPath)) {
    header("Location: dashboard.php?error=Directory not found");
    exit();
}

$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid security token. Please try again.";
    } else {
        $action = $_POST['action'];
        
        if ($action === 'create_folder') {
            $folderName = trim($_POST['folder_name'] ?? '');
            $folderName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $folderName);
            
            if (empty($folderName)) {
                $error = "Please enter a valid folder name.";
            } else {
                $newFolderPath = $fullPath . $folderName;
                if (is_dir($newFolderPath)) {
                    $error = "A folder with this name already exists.";
                } elseif (@mkdir($newFolderPath, 0777, true)) {
                    $message = "Folder '$folderName' created successfully.";
                    logActivity($conn, $userId, 'create_folder', $folderName, $currentPath);
                } else {
                    $error = "Failed to create folder.";
                }
            }
        } elseif ($action === 'rename') {
            $oldName = $_POST['old_name'] ?? '';
            $newName = trim($_POST['new_name'] ?? '');
            $newName = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '', $newName);
            
            $oldPath = $fullPath . $oldName;
            $newPath = $fullPath . $newName;
            
            if (empty($newName)) {
                $error = "Please enter a valid name.";
            } elseif (!file_exists($oldPath)) {
                $error = "Item not found.";
            } elseif (file_exists($newPath)) {
                $error = "An item with this name already exists.";
            } elseif (@rename($oldPath, $newPath)) {
                $message = "Renamed successfully.";
                logActivity($conn, $userId, 'rename', $oldName, $currentPath, "Renamed to: $newName");
            } else {
                $error = "Failed to rename.";
            }
        } elseif ($action === 'delete_folder') {
            $folderName = $_POST['folder_name'] ?? '';
            $folderPath = $fullPath . $folderName;
            
            if (is_dir($folderPath)) {
                if (moveToTrash($conn, $userId, $folderPath, $folderName, true)) {
                    $message = "Folder moved to trash.";
                    logActivity($conn, $userId, 'delete', $folderName, $currentPath, 'Moved to trash');
                    updateStorageUsed($conn, $userId);
                } else {
                    $error = "Failed to delete folder.";
                }
            } else {
                $error = "Folder not found.";
            }
        } elseif ($action === 'delete_file') {
            $fileName = $_POST['file_name'] ?? '';
            $filePath = $fullPath . $fileName;
            
            if (is_file($filePath)) {
                if (moveToTrash($conn, $userId, $filePath, $fileName, false)) {
                    $message = "File moved to trash.";
                    logActivity($conn, $userId, 'delete', $fileName, $currentPath, 'Moved to trash');
                    updateStorageUsed($conn, $userId);
                } else {
                    $error = "Failed to delete file.";
                }
            } else {
                $error = "File not found.";
            }
        } elseif ($action === 'move') {
            $itemName = $_POST['item_name'] ?? '';
            $destination = $_POST['destination'] ?? '';
            $destination = str_replace(['..', '\\'], ['', '/'], $destination);
            
            $sourcePath = $fullPath . $itemName;
            $destPath = $baseDir . ($destination ? $destination . '/' : '') . $itemName;
            
            $sourceRelative = ($currentPath ? $currentPath . '/' : '') . $itemName;
            
            if (!file_exists($sourcePath)) {
                $error = "Item not found.";
            } elseif ($sourceRelative === $destination || strpos($destination . '/', $sourceRelative . '/') === 0) {
                $error = "Cannot move a folder into itself or its subfolder.";
            } elseif (file_exists($destPath)) {
                $error = "An item with this name already exists in the destination.";
            } elseif (@rename($sourcePath, $destPath)) {
                $message = "Moved successfully.";
                logActivity($conn, $userId, 'move', $itemName, $currentPath, "Moved to: $destination");
            } else {
                $error = "Failed to move item.";
            }
        } elseif ($action === 'copy') {
            $itemName = $_POST['item_name'] ?? '';
            $sourcePath = $fullPath . $itemName;
            
            if (is_file($sourcePath)) {
                $ext = pathinfo($itemName, PATHINFO_EXTENSION);
                $name = pathinfo($itemName, PATHINFO_FILENAME);
                $copyName = $name . '_copy' . ($ext ? '.' . $ext : '');
                $destPath = $fullPath . $copyName;
                
                $counter = 1;
                while (file_exists($destPath)) {
                    $copyName = $name . '_copy' . $counter . ($ext ? '.' . $ext : '');
                    $destPath = $fullPath . $copyName;
                    $counter++;
                }
                
                $fileSize = filesize($sourcePath);
                if (!canUpload($conn, $userId, $fileSize)) {
                    $error = "Storage quota exceeded. Cannot copy file.";
                } elseif (@copy($sourcePath, $destPath)) {
                    $message = "File copied as '$copyName'.";
                    logActivity($conn, $userId, 'copy', $itemName, $currentPath, "Copied as: $copyName");
                    updateStorageUsed($conn, $userId);
                } else {
                    $error = "Failed to copy file.";
                }
            } else {
                $error = "Can only copy files, not folders.";
            }
        } elseif ($action === 'toggle_favorite') {
            $itemName = $_POST['item_name'] ?? '';
            $isFolder = isset($_POST['is_folder']) && $_POST['is_folder'] === '1';
            $itemPath = ($currentPath ? $currentPath . '/' : '') . $itemName;
            
            $isFav = toggleFavorite($conn, $userId, $itemPath, $itemName, $isFolder);
            if (isset($_POST['ajax'])) {
                echo json_encode(['success' => true, 'is_favorite' => $isFav]);
                exit();
            }
            $message = $isFav ? "Added to favorites." : "Removed from favorites.";
        } elseif ($action === 'create_share') {
            $itemName = $_POST['item_name'] ?? '';
            $itemPath = ($currentPath ? $currentPath . '/' : '') . $itemName;
            $fullItemPath = $fullPath . $itemName;
            
            if (!is_file($fullItemPath)) {
                $error = "Can only share files.";
            } else {
                $shareToken = generateShareToken();
                $expiresAt = !empty($_POST['expires_days']) ? date('Y-m-d H:i:s', strtotime('+' . intval($_POST['expires_days']) . ' days')) : null;
                $password = !empty($_POST['share_password']) ? password_hash($_POST['share_password'], PASSWORD_DEFAULT) : null;
                $maxDownloads = !empty($_POST['max_downloads']) ? intval($_POST['max_downloads']) : null;
                
                $stmt = $conn->prepare("INSERT INTO shared_links (user_id, file_path, file_name, share_token, password, expires_at, max_downloads) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssssi", $userId, $itemPath, $itemName, $shareToken, $password, $expiresAt, $maxDownloads);
                
                if ($stmt->execute()) {
                    $shareUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/share.php?token=" . $shareToken;
                    $_SESSION['share_url'] = $shareUrl;
                    $message = "Share link created!";
                    logActivity($conn, $userId, 'share', $itemName, $currentPath);
                } else {
                    $error = "Failed to create share link.";
                }
                $stmt->close();
            }
        }
    }
}

// Get all items in current directory
$items = array_diff(scandir($fullPath), ['.', '..']);
$folders = [];
$files = [];

foreach ($items as $item) {
    $itemPath = $fullPath . $item;
    $relativePath = ($currentPath ? $currentPath . '/' : '') . $item;
    
    if (is_dir($itemPath)) {
        $folders[] = [
            'name' => $item,
            'type' => 'folder',
            'modified' => filemtime($itemPath),
            'path' => $itemPath,
            'relative_path' => $relativePath,
            'is_favorite' => isFavorite($conn, $userId, $relativePath)
        ];
    } else {
        $files[] = [
            'name' => $item,
            'size' => filesize($itemPath),
            'modified' => filemtime($itemPath),
            'type' => pathinfo($item, PATHINFO_EXTENSION),
            'path' => $itemPath,
            'relative_path' => $relativePath,
            'is_favorite' => isFavorite($conn, $userId, $relativePath),
            'previewable' => isPreviewable(pathinfo($item, PATHINFO_EXTENSION))
        ];
    }
}

// Sort
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'asc';

$sortFunc = function($a, $b) use ($sortBy, $sortOrder) {
    $result = 0;
    switch ($sortBy) {
        case 'name': $result = strcasecmp($a['name'], $b['name']); break;
        case 'size': $result = ($a['size'] ?? 0) - ($b['size'] ?? 0); break;
        case 'modified': $result = $a['modified'] - $b['modified']; break;
        default: $result = strcasecmp($a['name'], $b['name']);
    }
    return $sortOrder === 'asc' ? $result : -$result;
};

usort($folders, $sortFunc);
usort($files, $sortFunc);

// Search
$searchTerm = $_GET['search'] ?? '';
if ($searchTerm) {
    $folders = array_filter($folders, fn($f) => stripos($f['name'], $searchTerm) !== false);
    $files = array_filter($files, fn($f) => stripos($f['name'], $searchTerm) !== false);
}

// Breadcrumbs
$breadcrumbs = [];
if ($currentPath) {
    $parts = explode('/', $currentPath);
    $path = '';
    foreach ($parts as $part) {
        $path .= ($path ? '/' : '') . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $path];
    }
}

// Get all folders for move dialog
function getAllFoldersRecursive($dir, $base, $prefix = '') {
    $result = [];
    if (!is_dir($dir)) return $result;
    $items = @array_diff(scandir($dir), ['.', '..']);
    if (!$items) return $result;
    foreach ($items as $item) {
        $path = $dir . $item;
        if (is_dir($path)) {
            $relativePath = $prefix . $item;
            $result[] = ['name' => $relativePath, 'path' => $relativePath];
            $result = array_merge($result, getAllFoldersRecursive($path . '/', $base, $relativePath . '/'));
        }
    }
    return $result;
}
$allFolders = getAllFoldersRecursive($baseDir, $baseDir);

// Get storage info
$storageInfo = getUserStorageInfo($conn, $userId);
$storagePercent = $storageInfo['storage_quota'] > 0 ? round(($storageInfo['storage_used'] / $storageInfo['storage_quota']) * 100, 1) : 0;

// Get trash count
$trashCount = getTrashCount($conn, $userId);

function getSortUrl($column) {
    global $sortBy, $sortOrder, $searchTerm, $currentPath;
    $newOrder = ($sortBy === $column && $sortOrder === 'asc') ? 'desc' : 'asc';
    $params = ['sort' => $column, 'order' => $newOrder];
    if ($searchTerm) $params['search'] = $searchTerm;
    if ($currentPath) $params['path'] = $currentPath;
    return '?' . http_build_query($params);
}

function getSortIcon($column) {
    global $sortBy, $sortOrder;
    if ($sortBy === $column) {
        return $sortOrder === 'asc' ? ' ↑' : ' ↓';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $darkMode ? 'dark' : 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager - <?php echo $currentPath ?: 'Home'; ?></title>
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-tertiary: #e9ecef;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --border-color: #dee2e6;
            --accent-color: #667eea;
            --accent-hover: #764ba2;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        [data-theme="dark"] {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --bg-tertiary: #0f3460;
            --text-primary: #e9ecef;
            --text-secondary: #adb5bd;
            --border-color: #0f3460;
            --shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            transition: all 0.3s ease;
        }

        [data-theme="dark"] body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--bg-primary);
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        [data-theme="dark"] .header {
            background: linear-gradient(135deg, #0f3460 0%, #16213e 100%);
        }

        .header h1 { font-size: 1.6em; font-weight: 400; }

        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .storage-bar {
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            padding: 8px 15px;
            min-width: 200px;
        }

        .storage-bar .label {
            font-size: 0.8em;
            margin-bottom: 5px;
        }

        .storage-bar .bar {
            background: rgba(255,255,255,0.3);
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
        }

        .storage-bar .fill {
            height: 100%;
            background: white;
            border-radius: 3px;
            transition: width 0.3s;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            color: white;
        }

        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .btn-success { background: var(--success-color); }
        .btn-secondary { background: var(--text-secondary); }
        .btn-danger { background: var(--danger-color); }
        .btn-warning { background: var(--warning-color); color: #333; }
        .btn-admin { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); }

        .btn:hover { transform: translateY(-2px); opacity: 0.9; }

        .theme-toggle {
            background: rgba(255,255,255,0.2);
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2em;
            transition: all 0.3s;
        }

        .theme-toggle:hover { background: rgba(255,255,255,0.3); transform: rotate(180deg); }

        .toolbar {
            background: var(--bg-secondary);
            padding: 15px 30px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .toolbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .search-bar {
            display: flex;
            gap: 8px;
        }

        .search-bar input {
            padding: 10px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            width: 250px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        /* Bulk Actions Toolbar */
        .bulk-toolbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 12px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            color: white;
        }
        
        .bulk-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .select-all-container {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .select-all-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .bulk-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .bulk-toolbar .btn {
            padding: 8px 14px;
            font-size: 13px;
        }
        
        /* Item Checkbox */
        .item-checkbox {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 22px;
            height: 22px;
            cursor: pointer;
            z-index: 10;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .file-item:hover .item-checkbox,
        .file-item.selected .item-checkbox,
        .selection-mode .item-checkbox {
            opacity: 1;
        }
        
        .item-checkbox:checked + .checkbox-visual,
        .checkbox-visual.checked {
            background: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        .checkbox-visual {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 22px;
            height: 22px;
            border: 2px solid var(--border-color);
            border-radius: 4px;
            background: var(--bg-primary);
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            z-index: 9;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .file-item:hover .checkbox-visual,
        .file-item.selected .checkbox-visual,
        .selection-mode .checkbox-visual {
            opacity: 1;
        }

        /* Keyboard shortcuts hint */
        .shortcuts-hint {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            z-index: 100;
            display: none;
            max-width: 300px;
        }
        
        .shortcuts-hint.active { display: block; }
        
        .shortcuts-hint h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: var(--text-primary);
        }
        
        .shortcuts-hint ul {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 12px;
        }
        
        .shortcuts-hint li {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            color: var(--text-secondary);
        }
        
        .shortcuts-hint kbd {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 11px;
        }
        
        .shortcuts-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--accent-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            box-shadow: 0 3px 15px rgba(0,0,0,0.3);
            z-index: 99;
        }
        
        .shortcuts-toggle:hover { transform: scale(1.1); }

        .breadcrumb {
            background: var(--bg-primary);
            padding: 12px 30px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .breadcrumb a {
            color: var(--accent-color);
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb span { color: var(--text-secondary); }
        .breadcrumb .current { color: var(--text-primary); font-weight: 600; }

        .stats-bar {
            background: var(--bg-tertiary);
            padding: 10px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
        }

        .stats { display: flex; gap: 25px; }

        .content {
            padding: 20px 30px;
            min-height: 400px;
            background: var(--bg-primary);
        }

        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .share-url {
            background: var(--bg-secondary);
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            word-break: break-all;
            font-family: monospace;
        }

        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }

        .file-item {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 20px 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .file-item:hover {
            background: var(--bg-tertiary);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .file-item.selected {
            border-color: var(--accent-color);
        }

        .file-item .icon {
            font-size: 3em;
            margin-bottom: 10px;
            display: block;
        }

        .file-item .name {
            font-size: 0.85em;
            word-break: break-word;
            color: var(--text-primary);
            font-weight: 500;
        }

        .file-item .meta {
            font-size: 0.75em;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        .file-item .favorite-star {
            position: absolute;
            top: 8px;
            left: 8px;
            font-size: 1.2em;
            cursor: pointer;
            opacity: 0.3;
            transition: all 0.3s;
        }

        .file-item .favorite-star.active,
        .file-item:hover .favorite-star { opacity: 1; }

        .file-item .actions {
            position: absolute;
            top: 5px;
            right: 5px;
            display: flex;
            gap: 3px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .file-item:hover .actions { opacity: 1; }

        .file-item .actions button {
            background: rgba(0,0,0,0.6);
            color: white;
            border: none;
            padding: 5px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .file-item .actions button:hover { background: rgba(0,0,0,0.8); }

        .file-item .preview-badge {
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: var(--accent-color);
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
        }

        /* List View */
        .file-list { display: none; }
        .file-list.active { display: block; }
        .file-grid.hidden { display: none; }

        .files-table {
            width: 100%;
            border-collapse: collapse;
        }

        .files-table th, .files-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .files-table th {
            background: var(--bg-secondary);
            font-weight: 600;
            cursor: pointer;
        }

        .files-table th:hover { background: var(--bg-tertiary); }
        .files-table tr:hover { background: var(--bg-secondary); }

        .files-table .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .files-table .file-icon { font-size: 1.5em; }

        .file-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .download-btn { background: var(--success-color); color: white; }
        .preview-btn { background: #17a2b8; color: white; }
        .share-btn { background: #6f42c1; color: white; }
        .rename-btn { background: #17a2b8; color: white; }
        .move-btn { background: var(--warning-color); color: #333; }
        .copy-btn { background: var(--text-secondary); color: white; }
        .delete-btn { background: var(--danger-color); color: white; }
        .fav-btn { background: #fd7e14; color: white; }
        .fav-btn.active { background: #ffc107; }

        .action-btn:hover { opacity: 0.8; transform: translateY(-1px); }

        .view-toggle {
            display: flex;
            gap: 5px;
        }

        .view-toggle button {
            padding: 8px 12px;
            border: 2px solid var(--border-color);
            background: var(--bg-primary);
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.3s;
        }

        .view-toggle button:first-child { border-radius: 8px 0 0 8px; }
        .view-toggle button:last-child { border-radius: 0 8px 8px 0; }

        .view-toggle button.active {
            background: var(--accent-color);
            border-color: var(--accent-color);
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active { display: flex; }

        .modal-content {
            background: var(--bg-primary);
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 450px;
            box-shadow: var(--shadow);
            color: var(--text-primary);
        }

        .modal-content h3 { margin-bottom: 20px; }

        .modal-content input,
        .modal-content select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 15px;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .modal-content input:focus,
        .modal-content select:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        .modal-content label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 14px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .no-files {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .no-files .icon {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Preview Modal */
        .preview-content {
            max-width: 90vw;
            max-height: 80vh;
        }

        .preview-content img {
            max-width: 100%;
            max-height: 70vh;
            object-fit: contain;
        }

        .preview-content video,
        .preview-content audio {
            max-width: 100%;
        }

        .preview-content pre {
            background: var(--bg-secondary);
            padding: 15px;
            border-radius: 8px;
            overflow: auto;
            max-height: 60vh;
            font-size: 14px;
        }

        .preview-content iframe {
            width: 100%;
            height: 70vh;
            border: none;
        }

        /* Drag & Drop */
        .drop-zone {
            border: 3px dashed var(--border-color);
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            margin-bottom: 20px;
            transition: all 0.3s;
            display: none;
        }

        .drop-zone.active {
            display: block;
        }

        .drop-zone.dragover {
            border-color: var(--accent-color);
            background: rgba(102, 126, 234, 0.1);
        }

        .drop-zone .icon { font-size: 3em; margin-bottom: 10px; }

        @media (max-width: 768px) {
            .header { flex-direction: column; text-align: center; }
            .toolbar { flex-direction: column; }
            .search-bar input { width: 100%; }
            .file-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
            .file-actions { flex-direction: column; }
            .storage-bar { min-width: 150px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📁 File Manager</h1>
            <div class="header-right">
                <div class="storage-bar">
                    <div class="label">Storage: <?php echo formatFileSize($storageInfo['storage_used']); ?> / <?php echo formatFileSize($storageInfo['storage_quota']); ?></div>
                    <div class="bar"><div class="fill" style="width: <?php echo min($storagePercent, 100); ?>%"></div></div>
                </div>
                <span>👤 <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <button class="theme-toggle" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                    <?php echo $darkMode ? '☀️' : '🌙'; ?>
                </button>
                <a href="trash.php" class="btn btn-secondary">🗑️ Trash<?php echo $trashCount > 0 ? " ($trashCount)" : ''; ?></a>
                <a href="activity_log.php" class="btn btn-secondary">📋 Activity</a>
                <a href="favorites.php" class="btn btn-warning">⭐ Favorites</a>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="admin.php" class="btn btn-admin">👑 Admin</a>
                <?php endif; ?>
                <a href="logout.php" class="btn btn-danger">🚪 Logout</a>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-actions">
                <button class="btn btn-success" onclick="openModal('createFolder')">📁 New Folder</button>
                <button class="btn btn-primary" onclick="document.getElementById('dropZone').classList.toggle('active')">📤 Upload</button>
                <a href="search.php" class="btn btn-secondary">🔍 Search</a>
                <?php if ($currentPath): ?>
                    <a href="dashboard.php<?php 
                        $parentPath = dirname($currentPath);
                        echo $parentPath && $parentPath !== '.' ? '?path=' . urlencode($parentPath) : '';
                    ?>" class="btn btn-secondary">⬆️ Up</a>
                    <a href="download_folder.php?folder=<?php echo urlencode($currentPath); ?>" class="btn btn-secondary">📦 Download ZIP</a>
                <?php endif; ?>
            </div>
            
            <div style="display: flex; gap: 15px; align-items: center;">
                <form class="search-bar" method="GET">
                    <?php if ($currentPath): ?>
                        <input type="hidden" name="path" value="<?php echo htmlspecialchars($currentPath); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <button type="submit" class="btn btn-primary">🔍</button>
                </form>
                
                <div class="view-toggle">
                    <button id="gridViewBtn" class="active" onclick="setView('grid')">▦</button>
                    <button id="listViewBtn" onclick="setView('list')">☰</button>
                </div>
            </div>
        </div>
        
        <!-- Bulk Actions Toolbar -->
        <div class="bulk-toolbar" id="bulkToolbar" style="display: none;">
            <div class="bulk-info">
                <label class="select-all-container">
                    <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)">
                    <span>Select All</span>
                </label>
                <span id="selectedCount">0 selected</span>
            </div>
            <div class="bulk-actions">
                <button class="btn btn-danger" onclick="bulkDelete()">🗑️ Delete</button>
                <button class="btn btn-primary" onclick="openBulkMoveModal()">📋 Move</button>
                <button class="btn btn-secondary" onclick="bulkDownload()">📦 Download ZIP</button>
                <button class="btn btn-warning" onclick="bulkFavorite(true)">⭐ Add to Favorites</button>
                <button class="btn btn-secondary" onclick="clearSelection()">✕ Clear</button>
            </div>
        </div>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="dashboard.php">🏠 Home</a>
            <?php foreach ($breadcrumbs as $crumb): ?>
                <span>/</span>
                <?php if ($crumb['path'] === $currentPath): ?>
                    <span class="current"><?php echo htmlspecialchars($crumb['name']); ?></span>
                <?php else: ?>
                    <a href="dashboard.php?path=<?php echo urlencode($crumb['path']); ?>">
                        <?php echo htmlspecialchars($crumb['name']); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Stats Bar -->
        <div class="stats-bar">
            <div class="stats">
                <span>📁 <?php echo count($folders); ?> folders</span>
                <span>📄 <?php echo count($files); ?> files</span>
                <span>💾 <?php echo formatFileSize(array_sum(array_column($files, 'size'))); ?></span>
            </div>
            <?php if ($searchTerm): ?>
                <a href="dashboard.php<?php echo $currentPath ? '?path=' . urlencode($currentPath) : ''; ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">Clear Search</a>
            <?php endif; ?>
        </div>

        <!-- Messages -->
        <div class="content">
            <?php if ($message): ?>
                <div class="message success">
                    ✅ <?php echo htmlspecialchars($message); ?>
                    <?php if (isset($_SESSION['share_url'])): ?>
                        <div class="share-url"><?php echo htmlspecialchars($_SESSION['share_url']); ?></div>
                        <button onclick="copyToClipboard('<?php echo htmlspecialchars($_SESSION['share_url']); ?>')" class="btn btn-primary" style="margin-top: 10px;">📋 Copy Link</button>
                        <?php unset($_SESSION['share_url']); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['message'])): ?>
                <div class="message success">✅ <?php echo htmlspecialchars($_GET['message']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="message error">❌ <?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <!-- Drop Zone -->
            <div class="drop-zone" id="dropZone">
                <div class="icon">📤</div>
                <h3>Drag & Drop files here</h3>
                <p>or click to select files</p>
                <input type="file" id="fileInput" multiple style="display: none;">
                <button class="btn btn-primary" onclick="document.getElementById('fileInput').click()" style="margin-top: 15px;">Select Files</button>
            </div>

            <?php if (empty($folders) && empty($files)): ?>
                <div class="no-files">
                    <div class="icon">📁</div>
                    <h3><?php echo $searchTerm ? 'No items found.' : 'This folder is empty.'; ?></h3>
                    <p>Create a folder or upload files to get started.</p>
                </div>
            <?php else: ?>
                <!-- Grid View -->
                <div class="file-grid" id="gridView">
                    <?php foreach ($folders as $folder): ?>
                        <div class="file-item" data-path="<?php echo htmlspecialchars(($currentPath ? $currentPath . '/' : '') . $folder['name']); ?>" data-name="<?php echo htmlspecialchars($folder['name']); ?>" data-type="folder" ondblclick="window.location='dashboard.php?path=<?php echo urlencode(($currentPath ? $currentPath . '/' : '') . $folder['name']); ?>'">
                            <input type="checkbox" class="item-checkbox" onclick="event.stopPropagation(); toggleItemSelection(this)">
                            <span class="checkbox-visual">✓</span>
                            <span class="favorite-star <?php echo $folder['is_favorite'] ? 'active' : ''; ?>" onclick="event.stopPropagation(); toggleFavorite('<?php echo htmlspecialchars(addslashes($folder['name'])); ?>', true)">⭐</span>
                            <div class="actions">
                                <button onclick="event.stopPropagation(); openRenameModal('<?php echo htmlspecialchars(addslashes($folder['name'])); ?>')" title="Rename">✏️</button>
                                <button onclick="event.stopPropagation(); openMoveModal('<?php echo htmlspecialchars(addslashes($folder['name'])); ?>')" title="Move">📋</button>
                                <button onclick="event.stopPropagation(); deleteFolder('<?php echo htmlspecialchars(addslashes($folder['name'])); ?>')" title="Delete">🗑️</button>
                            </div>
                            <span class="icon" style="color: #ffc107;">📁</span>
                            <div class="name"><?php echo htmlspecialchars($folder['name']); ?></div>
                            <div class="meta"><?php echo date('M j, Y', $folder['modified']); ?></div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php foreach ($files as $file): ?>
                        <div class="file-item" data-path="<?php echo htmlspecialchars(($currentPath ? $currentPath . '/' : '') . $file['name']); ?>" data-name="<?php echo htmlspecialchars($file['name']); ?>" data-type="file" <?php if ($file['previewable']): ?>ondblclick="openPreview('<?php echo htmlspecialchars(addslashes($file['name'])); ?>', '<?php echo $file['previewable']; ?>')"<?php endif; ?>>
                            <input type="checkbox" class="item-checkbox" onclick="event.stopPropagation(); toggleItemSelection(this)">
                            <span class="checkbox-visual">✓</span>
                            <span class="favorite-star <?php echo $file['is_favorite'] ? 'active' : ''; ?>" onclick="event.stopPropagation(); toggleFavorite('<?php echo htmlspecialchars(addslashes($file['name'])); ?>', false)">⭐</span>
                            <div class="actions">
                                <?php if ($file['previewable']): ?>
                                    <button onclick="event.stopPropagation(); openPreview('<?php echo htmlspecialchars(addslashes($file['name'])); ?>', '<?php echo $file['previewable']; ?>')" title="Preview">👁️</button>
                                <?php endif; ?>
                                <button onclick="event.stopPropagation(); openShareModal('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')" title="Share">🔗</button>
                                <button onclick="event.stopPropagation(); openRenameModal('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')" title="Rename">✏️</button>
                                <button onclick="event.stopPropagation(); copyFile('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')" title="Copy">📄</button>
                                <button onclick="event.stopPropagation(); deleteFile('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')" title="Delete">🗑️</button>
                                <a href="comments.php?file=<?php echo urlencode(($currentPath ? $currentPath . '/' : '') . $file['name']); ?>" onclick="event.stopPropagation();" title="Comments">💬</a>
                                <a href="versions.php?file=<?php echo urlencode(($currentPath ? $currentPath . '/' : '') . $file['name']); ?>" onclick="event.stopPropagation();" title="Versions">📜</a>
                            </div>
                            <span class="icon"><?php echo getFileIcon($file['type']); ?></span>
                            <div class="name"><?php echo htmlspecialchars($file['name']); ?></div>
                            <div class="meta"><?php echo formatFileSize($file['size']); ?></div>
                            <?php if ($file['previewable']): ?>
                                <span class="preview-badge">Preview</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- List View -->
                <div class="file-list" id="listView">
                    <table class="files-table">
                        <thead>
                            <tr>
                                <th>⭐</th>
                                <th><a href="<?php echo getSortUrl('name'); ?>" style="text-decoration:none;color:inherit;">Name<?php echo getSortIcon('name'); ?></a></th>
                                <th><a href="<?php echo getSortUrl('modified'); ?>" style="text-decoration:none;color:inherit;">Modified<?php echo getSortIcon('modified'); ?></a></th>
                                <th><a href="<?php echo getSortUrl('size'); ?>" style="text-decoration:none;color:inherit;">Size<?php echo getSortIcon('size'); ?></a></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($folders as $folder): ?>
                                <tr ondblclick="window.location='dashboard.php?path=<?php echo urlencode(($currentPath ? $currentPath . '/' : '') . $folder['name']); ?>'" style="cursor:pointer;">
                                    <td><span class="favorite-star <?php echo $folder['is_favorite'] ? 'active' : ''; ?>" onclick="event.stopPropagation(); toggleFavorite('<?php echo htmlspecialchars(addslashes($folder['name'])); ?>', true)" style="cursor:pointer;">⭐</span></td>
                                    <td>
                                        <div class="file-info">
                                            <span class="file-icon" style="color:#ffc107;">📁</span>
                                            <span><?php echo htmlspecialchars($folder['name']); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo date('M j, Y g:i A', $folder['modified']); ?></td>
                                    <td>—</td>
                                    <td>
                                        <div class="file-actions">
                                            <button class="action-btn rename-btn" onclick="event.stopPropagation(); openRenameModal('<?php echo htmlspecialchars(addslashes($folder['name'])); ?>')">✏️</button>
                                            <button class="action-btn move-btn" onclick="event.stopPropagation(); openMoveModal('<?php echo htmlspecialchars(addslashes($folder['name'])); ?>')">📋</button>
                                            <button class="action-btn delete-btn" onclick="event.stopPropagation(); deleteFolder('<?php echo htmlspecialchars(addslashes($folder['name'])); ?>')">🗑️</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php foreach ($files as $file): ?>
                                <tr>
                                    <td><span class="favorite-star <?php echo $file['is_favorite'] ? 'active' : ''; ?>" onclick="toggleFavorite('<?php echo htmlspecialchars(addslashes($file['name'])); ?>', false)" style="cursor:pointer;">⭐</span></td>
                                    <td>
                                        <div class="file-info">
                                            <span class="file-icon"><?php echo getFileIcon($file['type']); ?></span>
                                            <span><?php echo htmlspecialchars($file['name']); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo date('M j, Y g:i A', $file['modified']); ?></td>
                                    <td><?php echo formatFileSize($file['size']); ?></td>
                                    <td>
                                        <div class="file-actions">
                                            <a href="download_file.php?file=<?php echo urlencode($file['name']); ?>&path=<?php echo urlencode($currentPath); ?>" class="action-btn download-btn">⬇️</a>
                                            <?php if ($file['previewable']): ?>
                                                <button class="action-btn preview-btn" onclick="openPreview('<?php echo htmlspecialchars(addslashes($file['name'])); ?>', '<?php echo $file['previewable']; ?>')">👁️</button>
                                            <?php endif; ?>
                                            <button class="action-btn share-btn" onclick="openShareModal('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')">🔗</button>
                                            <button class="action-btn rename-btn" onclick="openRenameModal('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')">✏️</button>
                                            <button class="action-btn move-btn" onclick="openMoveModal('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')">📋</button>
                                            <button class="action-btn copy-btn" onclick="copyFile('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')">📄</button>
                                            <button class="action-btn delete-btn" onclick="deleteFile('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')">🗑️</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modals -->
    <!-- Create Folder Modal -->
    <div class="modal" id="createFolderModal">
        <div class="modal-content">
            <h3>📁 Create New Folder</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create_folder">
                <input type="text" name="folder_name" placeholder="Folder name" required autofocus>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createFolder')">Cancel</button>
                    <button type="submit" class="btn btn-success">Create</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rename Modal -->
    <div class="modal" id="renameModal">
        <div class="modal-content">
            <h3>✏️ Rename</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="old_name" id="renameOldName">
                <input type="text" name="new_name" id="renameNewName" placeholder="New name" required>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('rename')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Rename</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Move Modal -->
    <div class="modal" id="moveModal">
        <div class="modal-content">
            <h3>📋 Move To</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="item_name" id="moveItemName">
                <select name="destination" required>
                    <option value="">📁 Root folder</option>
                    <?php foreach ($allFolders as $folder): ?>
                        <option value="<?php echo htmlspecialchars($folder['path']); ?>">📁 <?php echo htmlspecialchars($folder['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('move')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Move</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Share Modal -->
    <div class="modal" id="shareModal">
        <div class="modal-content">
            <h3>🔗 Share File</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create_share">
                <input type="hidden" name="item_name" id="shareItemName">
                <label>Expires in (days, optional):</label>
                <input type="number" name="expires_days" placeholder="Leave empty for no expiry" min="1">
                <label>Password (optional):</label>
                <input type="password" name="share_password" placeholder="Leave empty for no password">
                <label>Max downloads (optional):</label>
                <input type="number" name="max_downloads" placeholder="Leave empty for unlimited" min="1">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('share')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Link</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Preview Modal -->
    <div class="modal" id="previewModal">
        <div class="modal-content preview-content" style="max-width: 900px;">
            <h3 id="previewTitle">Preview</h3>
            <div id="previewContainer" style="margin: 20px 0;"></div>
            <div class="modal-actions">
                <a href="#" id="previewDownload" class="btn btn-success">⬇️ Download</a>
                <button type="button" class="btn btn-secondary" onclick="closeModal('preview')">Close</button>
            </div>
        </div>
    </div>

    <!-- Bulk Move Modal -->
    <div class="modal" id="bulkMoveModal">
        <div class="modal-content">
            <h3>📋 Move Selected Items</h3>
            <select id="bulkMoveDestination">
                <option value="">📁 Root folder</option>
                <?php foreach ($allFolders as $folder): ?>
                    <option value="<?php echo htmlspecialchars($folder['path']); ?>">
                        📁 <?php echo htmlspecialchars($folder['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('bulkMove')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="executeBulkMove()">Move</button>
            </div>
        </div>
    </div>

    <!-- Hidden forms -->
    <form id="deleteFolderForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="delete_folder">
        <input type="hidden" name="folder_name" id="deleteFolderName">
    </form>

    <form id="deleteFileForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="delete_file">
        <input type="hidden" name="file_name" id="deleteFileName">
    </form>

    <form id="copyFileForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="copy">
        <input type="hidden" name="item_name" id="copyItemName">
    </form>

    <form id="favoriteForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="toggle_favorite">
        <input type="hidden" name="item_name" id="favoriteItemName">
        <input type="hidden" name="is_folder" id="favoriteIsFolder">
        <input type="hidden" name="ajax" value="1">
    </form>

    <script>
        const csrfToken = '<?php echo $csrfToken; ?>';
        const currentPath = '<?php echo addslashes($currentPath); ?>';

        // View toggle
        function setView(view) {
            const gridView = document.getElementById('gridView');
            const listView = document.getElementById('listView');
            const gridBtn = document.getElementById('gridViewBtn');
            const listBtn = document.getElementById('listViewBtn');
            
            if (view === 'grid') {
                gridView.classList.remove('hidden');
                listView.classList.remove('active');
                gridBtn.classList.add('active');
                listBtn.classList.remove('active');
            } else {
                gridView.classList.add('hidden');
                listView.classList.add('active');
                gridBtn.classList.remove('active');
                listBtn.classList.add('active');
            }
            localStorage.setItem('fileManagerView', view);
        }

        const savedView = localStorage.getItem('fileManagerView');
        if (savedView) setView(savedView);

        // Dark mode toggle
        function toggleDarkMode() {
            fetch('dashboard.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'toggle_dark_mode=1&csrf_token=' + csrfToken
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) location.reload();
            });
        }

        // Modal functions
        function openModal(type) {
            document.getElementById(type + 'Modal').classList.add('active');
        }

        function closeModal(type) {
            document.getElementById(type + 'Modal').classList.remove('active');
        }

        function openRenameModal(name) {
            document.getElementById('renameOldName').value = name;
            document.getElementById('renameNewName').value = name;
            openModal('rename');
            setTimeout(() => {
                document.getElementById('renameNewName').focus();
                document.getElementById('renameNewName').select();
            }, 100);
        }

        function openMoveModal(name) {
            document.getElementById('moveItemName').value = name;
            openModal('move');
        }

        function openShareModal(name) {
            document.getElementById('shareItemName').value = name;
            openModal('share');
        }

        function deleteFolder(name) {
            if (confirm('Move folder "' + name + '" to trash?')) {
                document.getElementById('deleteFolderName').value = name;
                document.getElementById('deleteFolderForm').submit();
            }
        }

        function deleteFile(name) {
            if (confirm('Move "' + name + '" to trash?')) {
                document.getElementById('deleteFileName').value = name;
                document.getElementById('deleteFileForm').submit();
            }
        }

        function copyFile(name) {
            document.getElementById('copyItemName').value = name;
            document.getElementById('copyFileForm').submit();
        }

        function toggleFavorite(name, isFolder) {
            document.getElementById('favoriteItemName').value = name;
            document.getElementById('favoriteIsFolder').value = isFolder ? '1' : '0';
            
            const formData = new FormData(document.getElementById('favoriteForm'));
            fetch('dashboard.php' + (currentPath ? '?path=' + encodeURIComponent(currentPath) : ''), {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) location.reload();
            });
        }

        function openPreview(name, type) {
            const container = document.getElementById('previewContainer');
            const title = document.getElementById('previewTitle');
            const downloadBtn = document.getElementById('previewDownload');
            
            title.textContent = '👁️ ' + name;
            downloadBtn.href = 'download_file.php?file=' + encodeURIComponent(name) + '&path=' + encodeURIComponent(currentPath);
            
            const fileUrl = 'preview.php?file=' + encodeURIComponent(name) + '&path=' + encodeURIComponent(currentPath);
            
            if (type === 'image') {
                container.innerHTML = '<img src="' + fileUrl + '" alt="Preview">';
            } else if (type === 'video') {
                container.innerHTML = '<video controls><source src="' + fileUrl + '"></video>';
            } else if (type === 'audio') {
                container.innerHTML = '<audio controls><source src="' + fileUrl + '"></audio>';
            } else if (type === 'pdf') {
                container.innerHTML = '<iframe src="' + fileUrl + '"></iframe>';
            } else if (type === 'text') {
                fetch(fileUrl)
                    .then(r => r.text())
                    .then(text => {
                        container.innerHTML = '<pre>' + escapeHtml(text) + '</pre>';
                    });
            }
            
            openModal('preview');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Link copied to clipboard!');
            });
        }

        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.classList.remove('active');
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
            }
        });

        // Drag & Drop
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');

        if (dropZone && fileInput) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
                document.body.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
            });

            dropZone.addEventListener('drop', handleDrop, false);
            fileInput.addEventListener('change', handleFiles, false);
        }

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles({target: {files: files}});
        }

        function handleFiles(e) {
            const files = e.target.files;
            if (files.length === 0) return;
            
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            
            for (let i = 0; i < files.length; i++) {
                formData.append('files[]', files[i]);
            }
            
            const dropZoneEl = document.getElementById('dropZone');
            if (dropZoneEl) {
                dropZoneEl.innerHTML = '<div class="icon">⏳</div><h3>Uploading...</h3>';
            }
            
            fetch('upload_file.php' + (currentPath ? '?path=' + encodeURIComponent(currentPath) : ''), {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Upload failed: ' + (data.error || 'Unknown error'));
                    location.reload();
                }
            })
            .catch(err => {
                console.error('Upload error:', err);
                alert('Upload failed. Check console for details.');
                location.reload();
            });
        }

        // ==========================================
        // BULK OPERATIONS
        // ==========================================
        let selectedItems = [];
        
        function toggleItemSelection(checkbox) {
            const item = checkbox.closest('.file-item');
            const path = item.dataset.path;
            const visual = item.querySelector('.checkbox-visual');
            
            if (checkbox.checked) {
                item.classList.add('selected');
                visual.classList.add('checked');
                if (!selectedItems.includes(path)) {
                    selectedItems.push(path);
                }
            } else {
                item.classList.remove('selected');
                visual.classList.remove('checked');
                selectedItems = selectedItems.filter(p => p !== path);
            }
            
            updateBulkToolbar();
        }
        
        function toggleSelectAll(checkbox) {
            const items = document.querySelectorAll('.file-item');
            items.forEach(item => {
                const itemCheckbox = item.querySelector('.item-checkbox');
                const visual = item.querySelector('.checkbox-visual');
                if (itemCheckbox) {
                    itemCheckbox.checked = checkbox.checked;
                    if (checkbox.checked) {
                        item.classList.add('selected');
                        visual.classList.add('checked');
                        const path = item.dataset.path;
                        if (!selectedItems.includes(path)) {
                            selectedItems.push(path);
                        }
                    } else {
                        item.classList.remove('selected');
                        visual.classList.remove('checked');
                    }
                }
            });
            
            if (!checkbox.checked) {
                selectedItems = [];
            }
            
            updateBulkToolbar();
        }
        
        function clearSelection() {
            selectedItems = [];
            document.querySelectorAll('.file-item').forEach(item => {
                item.classList.remove('selected');
                const checkbox = item.querySelector('.item-checkbox');
                const visual = item.querySelector('.checkbox-visual');
                if (checkbox) checkbox.checked = false;
                if (visual) visual.classList.remove('checked');
            });
            document.getElementById('selectAllCheckbox').checked = false;
            updateBulkToolbar();
        }
        
        function updateBulkToolbar() {
            const toolbar = document.getElementById('bulkToolbar');
            const countSpan = document.getElementById('selectedCount');
            
            if (selectedItems.length > 0) {
                toolbar.style.display = 'flex';
                countSpan.textContent = selectedItems.length + ' selected';
                document.body.classList.add('selection-mode');
            } else {
                toolbar.style.display = 'none';
                document.body.classList.remove('selection-mode');
            }
        }
        
        async function bulkDelete() {
            if (selectedItems.length === 0) return;
            if (!confirm(`Move ${selectedItems.length} item(s) to trash?`)) return;
            
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'delete');
            selectedItems.forEach(item => formData.append('items[]', item));
            
            try {
                const response = await fetch('bulk_operations.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                alert(data.message);
                if (data.success) location.reload();
            } catch (error) {
                alert('Operation failed');
            }
        }
        
        async function bulkDownload() {
            if (selectedItems.length === 0) return;
            
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'download');
            selectedItems.forEach(item => formData.append('items[]', item));
            
            try {
                const response = await fetch('bulk_operations.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success && data.download) {
                    window.location.href = data.downloadUrl;
                } else {
                    alert(data.message || 'Download failed');
                }
            } catch (error) {
                alert('Download failed');
            }
        }
        
        async function bulkFavorite(add) {
            if (selectedItems.length === 0) return;
            
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', add ? 'favorite' : 'unfavorite');
            selectedItems.forEach(item => formData.append('items[]', item));
            
            try {
                const response = await fetch('bulk_operations.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                alert(data.message);
                if (data.success) location.reload();
            } catch (error) {
                alert('Operation failed');
            }
        }
        
        function openBulkMoveModal() {
            if (selectedItems.length === 0) return;
            openModal('bulkMove');
        }
        
        async function executeBulkMove() {
            const destination = document.getElementById('bulkMoveDestination').value;
            
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'move');
            formData.append('destination', destination);
            selectedItems.forEach(item => formData.append('items[]', item));
            
            try {
                const response = await fetch('bulk_operations.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                alert(data.message);
                if (data.success) location.reload();
            } catch (error) {
                alert('Operation failed');
            }
        }

        // ==========================================
        // KEYBOARD SHORTCUTS
        // ==========================================
        document.addEventListener('keydown', (e) => {
            // Don't trigger shortcuts when typing in inputs
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                return;
            }
            
            // Ctrl+A: Select all
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                const selectAll = document.getElementById('selectAllCheckbox');
                selectAll.checked = true;
                toggleSelectAll(selectAll);
            }
            
            // Ctrl+F: Open search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                window.location.href = 'search.php';
            }
            
            // Delete: Delete selected
            if (e.key === 'Delete' && selectedItems.length > 0) {
                e.preventDefault();
                bulkDelete();
            }
            
            // Escape: Clear selection or close modal
            if (e.key === 'Escape') {
                if (selectedItems.length > 0) {
                    clearSelection();
                }
            }
            
            // N: New folder
            if (e.key === 'n' && !e.ctrlKey && !e.altKey) {
                e.preventDefault();
                openModal('createFolder');
            }
            
            // U: Upload
            if (e.key === 'u' && !e.ctrlKey && !e.altKey) {
                e.preventDefault();
                document.getElementById('dropZone').classList.add('active');
            }
            
            // Backspace: Go up
            if (e.key === 'Backspace' && currentPath) {
                e.preventDefault();
                const parentPath = currentPath.substring(0, currentPath.lastIndexOf('/'));
                window.location.href = 'dashboard.php' + (parentPath ? '?path=' + encodeURIComponent(parentPath) : '');
            }
            
            // ?: Show shortcuts
            if (e.key === '?') {
                e.preventDefault();
                toggleShortcutsPanel();
            }
        });
        
        function toggleShortcutsPanel() {
            const panel = document.getElementById('shortcutsPanel');
            panel.classList.toggle('active');
        }
    </script>
    
    <!-- Keyboard Shortcuts Panel -->
    <div class="shortcuts-hint" id="shortcutsPanel">
        <h4>⌨️ Keyboard Shortcuts</h4>
        <ul>
            <li><span>Select all</span> <kbd>Ctrl+A</kbd></li>
            <li><span>Search files</span> <kbd>Ctrl+F</kbd></li>
            <li><span>Delete selected</span> <kbd>Delete</kbd></li>
            <li><span>Clear selection</span> <kbd>Esc</kbd></li>
            <li><span>New folder</span> <kbd>N</kbd></li>
            <li><span>Upload files</span> <kbd>U</kbd></li>
            <li><span>Go up</span> <kbd>Backspace</kbd></li>
            <li><span>Show shortcuts</span> <kbd>?</kbd></li>
        </ul>
    </div>
    <button class="shortcuts-toggle" onclick="toggleShortcutsPanel()" title="Keyboard Shortcuts">⌨️</button>
</body>
</html>