<?php
// upload_file.php: Enhanced Upload File Script with Drag & Drop and CSRF

session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' 
          || (isset($_POST['csrf_token']) && !isset($_POST['submit']));

// User-specific storage
$baseDir = getUserBaseDir($userId);

// Get current path from URL
$currentPath = $_GET['path'] ?? $_POST['path'] ?? '';
$currentPath = str_replace(['..', '\\'], ['', '/'], $currentPath);
$currentPath = trim($currentPath, '/');

$fileStorageDir = $baseDir . ($currentPath ? $currentPath . '/' : '');
if (!is_dir($fileStorageDir)) {
    mkdir($fileStorageDir, 0777, true);
}

$message = '';
$error = '';
$uploadedFiles = [];

// Configuration
$maxFileSize = 50 * 1024 * 1024; // 50MB per file
$allowedTypes = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico',  // Images
    'pdf', 'doc', 'docx', 'txt', 'rtf', 'odt',                  // Documents
    'xls', 'xlsx', 'csv', 'ods',                                 // Spreadsheets
    'ppt', 'pptx', 'odp',                                        // Presentations
    'zip', 'rar', '7z', 'tar', 'gz',                             // Archives
    'mp4', 'webm', 'avi', 'mov', 'mkv',                          // Videos
    'mp3', 'wav', 'flac', 'ogg', 'm4a',                          // Audio
    'html', 'css', 'js', 'json', 'xml',                          // Web
    'php', 'py', 'java', 'c', 'cpp', 'h', 'sql', 'sh', 'bat',    // Code
    'md', 'log', 'ini', 'conf', 'yml', 'yaml'                    // Config/Text
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit();
        }
        $error = "Invalid security token. Please try again.";
    } elseif (isset($_FILES['files'])) {
        $files = $_FILES['files'];
        
        $uploadCount = 0;
        $errorCount = 0;
        $errors = [];
        
        // Get storage info
        $storageInfo = getUserStorageInfo($conn, $userId);
        $availableSpace = $storageInfo['storage_quota'] - $storageInfo['storage_used'];
        
        // Calculate total size
        $totalUploadSize = 0;
        $fileList = [];
        
        if (is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $totalUploadSize += $files['size'][$i];
                    $fileList[] = [
                        'name' => $files['name'][$i],
                        'size' => $files['size'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i]
                    ];
                }
            }
        } else {
            if ($files['error'] === UPLOAD_ERR_OK) {
                $totalUploadSize = $files['size'];
                $fileList[] = [
                    'name' => $files['name'],
                    'size' => $files['size'],
                    'tmp_name' => $files['tmp_name'],
                    'error' => $files['error']
                ];
            }
        }
        
        // Check quota
        if ($totalUploadSize > $availableSpace) {
            $error = "Not enough storage space. Available: " . formatFileSize($availableSpace);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $error]);
                exit();
            }
        } else {
            // Process files
            foreach ($fileList as $file) {
                $fileName = basename($file['name']);
                $fileName = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '', $fileName); // Sanitize
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileSize = $file['size'];
                $tempPath = $file['tmp_name'];
                
                // Validate file extension
                if (!in_array($fileExtension, $allowedTypes)) {
                    $errors[] = "$fileName: File type not allowed.";
                    $errorCount++;
                    continue;
                }
                
                // Validate file size
                if ($fileSize > $maxFileSize) {
                    $errors[] = "$fileName: File too large (max 50MB).";
                    $errorCount++;
                    continue;
                }
                
                // Check if file already exists - create version if it does
                $targetPath = $fileStorageDir . $fileName;
                $relativePath = ($currentPath ? $currentPath . '/' : '') . $fileName;
                
                if (file_exists($targetPath)) {
                    // Create a version of the existing file before overwriting
                    // Wrapped in try-catch to prevent breaking uploads if versioning fails
                    try {
                        @createFileVersion($conn, $userId, $relativePath, $fileName, $targetPath);
                    } catch (Exception $e) {
                        // Silently continue - versioning is optional
                    }
                }
                
                if (move_uploaded_file($tempPath, $targetPath)) {
                    $uploadedFiles[] = [
                        'name' => $fileName,
                        'size' => $fileSize,
                        'type' => $fileExtension
                    ];
                    $uploadCount++;
                    
                    // Log activity
                    logActivity($conn, $userId, 'upload', $fileName, $currentPath, formatFileSize($fileSize));
                } else {
                    $errors[] = "$fileName: Failed to upload.";
                    $errorCount++;
                }
            }
            
            // Update user storage
            updateStorageUsed($conn, $userId);
            
            if ($uploadCount > 0) {
                $message = "$uploadCount file(s) uploaded successfully.";
            }
            
            if ($errorCount > 0) {
                $error = implode(' ', $errors);
            }
            
            // Return JSON for AJAX uploads
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => $uploadCount > 0,
                    'uploaded' => $uploadCount,
                    'errors' => $errors,
                    'files' => $uploadedFiles,
                    'message' => $message,
                    'error' => $error
                ]);
                exit();
            }
        }
    }
}

// If it's an AJAX request without files, redirect
if ($isAjax && empty($_FILES)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No files provided']);
    exit();
}

// Get CSRF token for form
$csrfToken = generateCSRFToken();
$darkMode = getDarkMode($conn, $userId);
$storageInfo = getUserStorageInfo($conn, $userId);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $darkMode ? 'dark' : 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Files - File Manager</title>
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --border-color: #dee2e6;
            --accent-color: #667eea;
            --success-color: #28a745;
            --danger-color: #dc3545;
        }

        [data-theme="dark"] {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --text-primary: #e9ecef;
            --text-secondary: #adb5bd;
            --border-color: #0f3460;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        [data-theme="dark"] body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: var(--bg-primary);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 { font-size: 1.5em; font-weight: 400; }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
        }

        .btn-secondary { background: #6c757d; }
        .btn-success { background: var(--success-color); }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }

        .content { padding: 30px; }

        .message {
            padding: 15px 20px;
            border-radius: 10px;
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

        .storage-info {
            background: var(--bg-secondary);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
        }

        .storage-info .label {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 8px;
        }

        .storage-bar {
            background: var(--border-color);
            height: 10px;
            border-radius: 5px;
            overflow: hidden;
        }

        .storage-fill {
            height: 100%;
            background: var(--accent-color);
            transition: width 0.3s;
        }

        .drop-zone {
            border: 3px dashed var(--border-color);
            border-radius: 15px;
            padding: 50px 20px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }

        .drop-zone.dragover {
            border-color: var(--accent-color);
            background: rgba(102, 126, 234, 0.1);
        }

        .drop-zone .icon { font-size: 4em; margin-bottom: 15px; }
        .drop-zone h3 { color: var(--text-primary); margin-bottom: 10px; }
        .drop-zone p { color: var(--text-secondary); margin-bottom: 20px; }

        .file-input { display: none; }

        .file-list {
            margin-top: 25px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 15px;
            background: var(--bg-secondary);
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .file-item .info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-item .name {
            color: var(--text-primary);
            font-weight: 500;
        }

        .file-item .size {
            color: var(--text-secondary);
            font-size: 12px;
        }

        .file-item .status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 15px;
        }

        .file-item .status.pending { background: #ffc107; color: #333; }
        .file-item .status.uploading { background: #17a2b8; color: white; }
        .file-item .status.success { background: var(--success-color); color: white; }
        .file-item .status.error { background: var(--danger-color); color: white; }

        .file-item .remove {
            background: var(--danger-color);
            color: white;
            border: none;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            cursor: pointer;
        }

        .upload-btn {
            width: 100%;
            padding: 15px;
            margin-top: 20px;
            font-size: 16px;
        }

        .uploaded-files {
            margin-top: 30px;
        }

        .uploaded-files h3 {
            color: var(--text-primary);
            margin-bottom: 15px;
        }

        .uploaded-file {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #d4edda;
            border-radius: 8px;
            margin-bottom: 10px;
            color: #155724;
        }

        @media (max-width: 768px) {
            .header { flex-direction: column; gap: 15px; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📤 Upload Files</h1>
            <a href="dashboard.php<?php echo $currentPath ? '?path=' . urlencode($currentPath) : ''; ?>" class="btn btn-secondary">← Back to Files</a>
        </div>

        <div class="content">
            <?php if ($message): ?>
                <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="storage-info">
                <div class="label">
                    Storage: <?php echo formatFileSize($storageInfo['storage_used']); ?> / <?php echo formatFileSize($storageInfo['storage_quota']); ?> used
                </div>
                <div class="storage-bar">
                    <div class="storage-fill" style="width: <?php echo min(100, ($storageInfo['storage_used'] / $storageInfo['storage_quota']) * 100); ?>%"></div>
                </div>
            </div>

            <?php if ($currentPath): ?>
                <div style="margin-bottom: 20px; color: var(--text-secondary);">
                    📁 Uploading to: <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($currentPath); ?></strong>
                </div>
            <?php endif; ?>

            <form id="uploadForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="drop-zone" id="dropZone">
                    <div class="icon">📤</div>
                    <h3>Drag & Drop files here</h3>
                    <p>or click to select files</p>
                    <p style="font-size: 12px; color: var(--text-secondary);">Max 50MB per file • Supported: images, documents, audio, video, archives</p>
                    <input type="file" name="files[]" id="fileInput" class="file-input" multiple>
                </div>

                <div class="file-list" id="fileList"></div>

                <button type="submit" class="btn btn-success upload-btn" id="uploadBtn" style="display: none;">
                    📤 Upload Files
                </button>
            </form>

            <?php if (!empty($uploadedFiles)): ?>
                <div class="uploaded-files">
                    <h3>✅ Recently Uploaded</h3>
                    <?php foreach ($uploadedFiles as $file): ?>
                        <div class="uploaded-file">
                            <span><?php echo getFileIcon($file['type']); ?></span>
                            <span><?php echo htmlspecialchars($file['name']); ?></span>
                            <span style="margin-left: auto; font-size: 12px;"><?php echo formatFileSize($file['size']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const uploadBtn = document.getElementById('uploadBtn');
        const uploadForm = document.getElementById('uploadForm');
        
        let selectedFiles = [];

        dropZone.addEventListener('click', () => fileInput.click());

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
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

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            addFiles(files);
        }

        function handleFiles(e) {
            addFiles(e.target.files);
        }

        function addFiles(files) {
            for (let i = 0; i < files.length; i++) {
                selectedFiles.push(files[i]);
            }
            updateFileList();
        }

        function removeFile(index) {
            selectedFiles.splice(index, 1);
            updateFileList();
        }

        function updateFileList() {
            fileList.innerHTML = '';
            
            if (selectedFiles.length === 0) {
                uploadBtn.style.display = 'none';
                return;
            }

            uploadBtn.style.display = 'block';

            selectedFiles.forEach((file, index) => {
                const item = document.createElement('div');
                item.className = 'file-item';
                item.innerHTML = `
                    <div class="info">
                        <span>${getFileIcon(file.name)}</span>
                        <div>
                            <div class="name">${file.name}</div>
                            <div class="size">${formatFileSize(file.size)}</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="status pending">Ready</span>
                        <button type="button" class="remove" onclick="removeFile(${index})">×</button>
                    </div>
                `;
                fileList.appendChild(item);
            });
        }

        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            const icons = {
                'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'webp': '🖼️',
                'pdf': '📕', 'doc': '📘', 'docx': '📘', 'txt': '📄',
                'xls': '📗', 'xlsx': '📗', 'csv': '📗',
                'mp4': '🎬', 'mov': '🎬', 'avi': '🎬',
                'mp3': '🎵', 'wav': '🎵', 'flac': '🎵',
                'zip': '📦', 'rar': '📦', '7z': '📦'
            };
            return icons[ext] || '📄';
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (selectedFiles.length === 0) {
                alert('Please select files to upload');
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
            
            selectedFiles.forEach(file => {
                formData.append('files[]', file);
            });

            const items = fileList.querySelectorAll('.file-item');
            items.forEach(item => {
                const status = item.querySelector('.status');
                status.className = 'status uploading';
                status.textContent = 'Uploading...';
                item.querySelector('.remove').style.display = 'none';
            });

            uploadBtn.disabled = true;
            uploadBtn.textContent = '⏳ Uploading...';

            fetch('upload_file.php<?php echo $currentPath ? '?path=' . urlencode($currentPath) : ''; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'dashboard.php<?php echo $currentPath ? '?path=' . urlencode($currentPath) . '&' : '?'; ?>message=' + encodeURIComponent(data.message);
                } else {
                    items.forEach(item => {
                        const status = item.querySelector('.status');
                        status.className = 'status error';
                        status.textContent = 'Failed';
                    });
                    uploadBtn.disabled = false;
                    uploadBtn.textContent = '📤 Upload Files';
                    alert('Upload failed: ' + data.error);
                }
            })
            .catch(err => {
                alert('Upload failed: ' + err.message);
                uploadBtn.disabled = false;
                uploadBtn.textContent = '📤 Upload Files';
            });
        });
    </script>
</body>
</html>
