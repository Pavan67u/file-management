<?php
session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$baseDir = getUserBaseDir($userId);
$csrfToken = generateCSRFToken();
$darkMode = isset($_COOKIE['darkMode']) && $_COOKIE['darkMode'] === 'true';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'restore') {
        $versionId = intval($_POST['version_id'] ?? 0);
        
        if (restoreFileVersion($conn, $userId, $versionId)) {
            logActivity($conn, $userId, 'version_restore', 'Version #' . $versionId, '');
            echo json_encode(['success' => true, 'message' => 'Version restored successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to restore version']);
        }
    } elseif ($action === 'delete') {
        $versionId = intval($_POST['version_id'] ?? 0);
        
        // Get version info before deleting
        $stmt = $conn->prepare("SELECT * FROM file_versions WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $versionId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $version = $result->fetch_assoc();
            $stmt->close();
            
            // Delete physical file
            if (file_exists($version['version_path'])) {
                @unlink($version['version_path']);
            }
            
            // Delete from database
            $stmt = $conn->prepare("DELETE FROM file_versions WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $versionId, $userId);
            $stmt->execute();
            $stmt->close();
            
            echo json_encode(['success' => true, 'message' => 'Version deleted']);
        } else {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Version not found']);
        }
    } elseif ($action === 'get') {
        $filePath = sanitizePath($_POST['file_path'] ?? '');
        $versions = getFileVersions($conn, $userId, $filePath);
        echo json_encode(['success' => true, 'versions' => $versions]);
    }
    
    exit();
}

// Display versions for a specific file
$filePath = sanitizePath($_GET['file'] ?? '');
$fullPath = $baseDir . $filePath;

if (empty($filePath) || !file_exists($fullPath) || is_dir($fullPath)) {
    header('Location: dashboard.php');
    exit();
}

$fileName = basename($filePath);
$versions = getFileVersions($conn, $userId, $filePath);

// Get current file info
$currentFileSize = filesize($fullPath);
$currentFileModified = filemtime($fullPath);
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $darkMode ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Versions - <?php echo htmlspecialchars($fileName); ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        :root {
            --bg-primary: #f5f5f5;
            --bg-secondary: #ffffff;
            --text-primary: #333;
            --text-secondary: #666;
            --border-color: #e0e0e0;
            --accent-color: #667eea;
        }
        .dark {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --text-primary: #eee;
            --text-secondary: #aaa;
            --border-color: #0f3460;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        .back-btn {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }
        .back-btn:hover { opacity: 0.9; }
        .file-info h1 {
            margin: 0;
            font-size: 24px;
        }
        .file-info .path {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 5px;
        }
        .current-version {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #28a745;
        }
        .current-version h3 {
            margin: 0 0 10px 0;
            color: #28a745;
        }
        .version-meta {
            display: flex;
            gap: 20px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        .versions-section h2 {
            margin: 0 0 20px 0;
            font-size: 20px;
        }
        .version-item {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .version-info h4 {
            margin: 0 0 5px 0;
            font-size: 16px;
        }
        .version-info .meta {
            font-size: 13px;
            color: var(--text-secondary);
        }
        .version-actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
        }
        .btn-restore {
            background: #28a745;
            color: white;
        }
        .btn-restore:hover { background: #218838; }
        .btn-download {
            background: var(--accent-color);
            color: white;
        }
        .btn-download:hover { opacity: 0.9; }
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        .btn-delete:hover { background: #c82333; }
        .no-versions {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-secondary);
            border-radius: 12px;
            color: var(--text-secondary);
        }
        .no-versions .icon {
            font-size: 50px;
            margin-bottom: 15px;
        }
        .message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
        }
        .info-box {
            background: #e8f4fd;
            border: 1px solid #b8daff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .dark .info-box {
            background: #1e3a5f;
            border-color: #0f3460;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="dashboard.php?path=<?php echo urlencode(dirname($filePath)); ?>" class="back-btn">← Back</a>
            <div class="file-info">
                <h1>📜 Version History</h1>
                <div class="path"><?php echo htmlspecialchars($fileName); ?></div>
            </div>
        </div>
        
        <div id="message"></div>
        
        <div class="info-box">
            ℹ️ <strong>About Versioning:</strong> When you upload a file with the same name, the previous version is automatically saved. You can restore any previous version at any time.
        </div>
        
        <div class="current-version">
            <h3>📄 Current Version</h3>
            <div class="version-meta">
                <span>📦 <?php echo formatFileSize($currentFileSize); ?></span>
                <span>📅 <?php echo date('M j, Y g:i A', $currentFileModified); ?></span>
            </div>
        </div>
        
        <div class="versions-section">
            <h2>Previous Versions (<?php echo count($versions); ?>)</h2>
            
            <?php if (empty($versions)): ?>
                <div class="no-versions">
                    <div class="icon">📁</div>
                    <h3>No Previous Versions</h3>
                    <p>Previous versions will appear here when you overwrite this file.</p>
                </div>
            <?php else: ?>
                <div id="versionsList">
                    <?php foreach ($versions as $version): ?>
                        <div class="version-item" data-id="<?php echo $version['id']; ?>">
                            <div class="version-info">
                                <h4>Version <?php echo $version['version_number']; ?></h4>
                                <div class="meta">
                                    📦 <?php echo formatFileSize($version['file_size']); ?> • 
                                    📅 <?php echo date('M j, Y g:i A', strtotime($version['created_at'])); ?>
                                </div>
                            </div>
                            <div class="version-actions">
                                <button class="btn btn-restore" onclick="restoreVersion(<?php echo $version['id']; ?>)">
                                    ↩️ Restore
                                </button>
                                <a href="download_version.php?id=<?php echo $version['id']; ?>" class="btn btn-download">
                                    ⬇️ Download
                                </a>
                                <button class="btn btn-delete" onclick="deleteVersion(<?php echo $version['id']; ?>)">
                                    🗑️ Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        const csrfToken = '<?php echo $csrfToken; ?>';
        
        async function restoreVersion(versionId) {
            if (!confirm('Restore this version? The current file will be saved as a new version.')) return;
            
            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('version_id', versionId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('versions.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                showMessage(data.message, data.success ? 'success' : 'error');
                
                if (data.success) {
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (error) {
                showMessage('An error occurred', 'error');
            }
        }
        
        async function deleteVersion(versionId) {
            if (!confirm('Delete this version permanently?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('version_id', versionId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('versions.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                showMessage(data.message, data.success ? 'success' : 'error');
                
                if (data.success) {
                    document.querySelector(`.version-item[data-id="${versionId}"]`)?.remove();
                    updateCount();
                }
            } catch (error) {
                showMessage('An error occurred', 'error');
            }
        }
        
        function updateCount() {
            const count = document.querySelectorAll('.version-item').length;
            document.querySelector('.versions-section h2').textContent = `Previous Versions (${count})`;
            
            if (count === 0) {
                document.getElementById('versionsList').innerHTML = `
                    <div class="no-versions">
                        <div class="icon">📁</div>
                        <h3>No Previous Versions</h3>
                        <p>Previous versions will appear here when you overwrite this file.</p>
                    </div>
                `;
            }
        }
        
        function showMessage(text, type) {
            const messageDiv = document.getElementById('message');
            messageDiv.innerHTML = `<div class="message ${type}">${text}</div>`;
            setTimeout(() => { messageDiv.innerHTML = ''; }, 3000);
        }
    </script>
</body>
</html>
