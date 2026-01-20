<?php
// favorites.php: View and manage favorite files/folders

session_start();
require_once 'db_config.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$csrfToken = generateCSRFToken();
$darkMode = getDarkMode($conn, $userId);
$baseDir = getUserBaseDir($userId);

$message = '';
$error = '';

// Handle remove from favorites
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid security token.";
    } else {
        if ($_POST['action'] === 'remove_favorite') {
            $favId = intval($_POST['fav_id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM favorites WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $favId, $userId);
            if ($stmt->execute()) {
                $message = "Removed from favorites.";
            }
            $stmt->close();
        }
    }
}

// Get favorites
$favorites = [];
$stmt = $conn->prepare("SELECT * FROM favorites WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $fullPath = $baseDir . $row['item_path'];
    $exists = file_exists($fullPath);
    $row['exists'] = $exists;
    $row['full_path'] = $fullPath;
    
    if ($exists && !$row['is_folder']) {
        $row['size'] = filesize($fullPath);
        $row['modified'] = filemtime($fullPath);
        $row['extension'] = pathinfo($row['item_name'], PATHINFO_EXTENSION);
        $row['previewable'] = isPreviewable($row['extension']);
    }
    
    $favorites[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $darkMode ? 'dark' : 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorites - File Manager</title>
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-tertiary: #e9ecef;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --border-color: #dee2e6;
            --accent-color: #667eea;
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
        }

        [data-theme="dark"] body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: var(--bg-primary);
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: #333;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 { font-size: 1.6em; font-weight: 400; }

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

        .btn-secondary { background: #6c757d; }
        .btn-primary { background: var(--accent-color); }
        .btn-danger { background: var(--danger-color); }
        .btn-success { background: var(--success-color); }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }

        .content {
            padding: 20px 30px;
            min-height: 400px;
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

        .favorites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }

        .fav-item {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .fav-item:hover {
            background: var(--bg-tertiary);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .fav-item.not-exists {
            opacity: 0.6;
            border-color: var(--danger-color);
        }

        .fav-item .star {
            position: absolute;
            top: 10px;
            left: 10px;
            font-size: 1.2em;
            color: var(--warning-color);
        }

        .fav-item .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--danger-color);
            color: white;
            border: none;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .fav-item:hover .remove-btn { opacity: 1; }

        .fav-item .icon {
            font-size: 3em;
            margin-bottom: 15px;
            display: block;
        }

        .fav-item .name {
            font-size: 0.9em;
            word-break: break-word;
            color: var(--text-primary);
            font-weight: 500;
            margin-bottom: 8px;
        }

        .fav-item .path {
            font-size: 0.75em;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .fav-item .meta {
            font-size: 0.75em;
            color: var(--text-secondary);
        }

        .fav-item .actions {
            margin-top: 15px;
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 5px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: white;
        }

        .open-btn { background: var(--accent-color); }
        .download-btn { background: var(--success-color); }
        .action-btn:hover { opacity: 0.8; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .empty-state .icon {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .not-exists-badge {
            background: var(--danger-color);
            color: white;
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .header { flex-direction: column; text-align: center; gap: 15px; }
            .favorites-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⭐ Favorites</h1>
            <a href="dashboard.php" class="btn btn-secondary">← Back to Files</a>
        </div>

        <div class="content">
            <?php if ($message): ?>
                <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (empty($favorites)): ?>
                <div class="empty-state">
                    <div class="icon">⭐</div>
                    <h3>No favorites yet</h3>
                    <p>Star files and folders to access them quickly from here.</p>
                    <a href="dashboard.php" class="btn btn-primary" style="margin-top: 20px;">Browse Files</a>
                </div>
            <?php else: ?>
                <div class="favorites-grid">
                    <?php foreach ($favorites as $fav): ?>
                        <div class="fav-item <?php echo !$fav['exists'] ? 'not-exists' : ''; ?>">
                            <span class="star">⭐</span>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="remove_favorite">
                                <input type="hidden" name="fav_id" value="<?php echo $fav['id']; ?>">
                                <button type="submit" class="remove-btn" title="Remove from favorites">✕</button>
                            </form>
                            
                            <span class="icon">
                                <?php if ($fav['is_folder']): ?>
                                    📁
                                <?php else: ?>
                                    <?php echo getFileIcon($fav['extension'] ?? ''); ?>
                                <?php endif; ?>
                            </span>
                            
                            <div class="name"><?php echo htmlspecialchars($fav['item_name']); ?></div>
                            
                            <?php 
                            $parentPath = dirname($fav['item_path']);
                            if ($parentPath && $parentPath !== '.'):
                            ?>
                                <div class="path">📁 <?php echo htmlspecialchars($parentPath); ?></div>
                            <?php endif; ?>
                            
                            <?php if (!$fav['exists']): ?>
                                <span class="not-exists-badge">File moved or deleted</span>
                            <?php else: ?>
                                <?php if (!$fav['is_folder']): ?>
                                    <div class="meta">
                                        <?php echo formatFileSize($fav['size']); ?> • 
                                        <?php echo date('M j, Y', $fav['modified']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="actions">
                                    <?php if ($fav['is_folder']): ?>
                                        <a href="dashboard.php?path=<?php echo urlencode($fav['item_path']); ?>" class="action-btn open-btn">📂 Open</a>
                                    <?php else: ?>
                                        <?php 
                                        $parentPath = dirname($fav['item_path']);
                                        $parentPath = $parentPath === '.' ? '' : $parentPath;
                                        ?>
                                        <a href="dashboard.php?path=<?php echo urlencode($parentPath); ?>" class="action-btn open-btn">📂 Location</a>
                                        <a href="download_file.php?file=<?php echo urlencode($fav['item_name']); ?>&path=<?php echo urlencode($parentPath); ?>" class="action-btn download-btn">⬇️</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
