<?php
// trash.php: Trash/Recycle Bin Management

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

$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid security token.";
    } else {
        $action = $_POST['action'];
        
        if ($action === 'restore') {
            $trashId = intval($_POST['trash_id'] ?? 0);
            if (restoreFromTrash($conn, $userId, $trashId)) {
                $message = "Item restored successfully.";
                logActivity($conn, $userId, 'restore', 'Restored from trash');
                updateStorageUsed($conn, $userId);
            } else {
                $error = "Failed to restore item.";
            }
        } elseif ($action === 'delete_permanent') {
            $trashId = intval($_POST['trash_id'] ?? 0);
            if (permanentDelete($conn, $userId, $trashId)) {
                $message = "Item permanently deleted.";
                logActivity($conn, $userId, 'permanent_delete', 'Permanently deleted from trash');
            } else {
                $error = "Failed to delete item.";
            }
        } elseif ($action === 'empty_trash') {
            if (emptyTrash($conn, $userId)) {
                $message = "Trash emptied successfully.";
                logActivity($conn, $userId, 'empty_trash', 'Emptied trash');
            } else {
                $error = "Failed to empty trash.";
            }
        } elseif ($action === 'restore_all') {
            $stmt = $conn->prepare("SELECT id FROM trash WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $restored = 0;
            while ($row = $result->fetch_assoc()) {
                if (restoreFromTrash($conn, $userId, $row['id'])) {
                    $restored++;
                }
            }
            $stmt->close();
            if ($restored > 0) {
                $message = "$restored items restored.";
                logActivity($conn, $userId, 'restore_all', "Restored $restored items from trash");
                updateStorageUsed($conn, $userId);
            } else {
                $error = "No items to restore.";
            }
        }
    }
}

// Get trash items
$trashItems = [];
$stmt = $conn->prepare("SELECT * FROM trash WHERE user_id = ? ORDER BY deleted_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $trashItems[] = $row;
}
$stmt->close();

// Calculate total size
$totalSize = array_sum(array_column($trashItems, 'size'));
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $darkMode ? 'dark' : 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash - File Manager</title>
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
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
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

        .btn-primary { background: var(--accent-color); }
        .btn-success { background: var(--success-color); }
        .btn-secondary { background: var(--text-secondary); }
        .btn-danger { background: var(--danger-color); }
        .btn-warning { background: var(--warning-color); color: #333; }

        .btn:hover { transform: translateY(-2px); opacity: 0.9; }

        .toolbar {
            background: var(--bg-secondary);
            padding: 15px 30px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .stats {
            color: var(--text-secondary);
            font-size: 14px;
        }

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

        .trash-table {
            width: 100%;
            border-collapse: collapse;
        }

        .trash-table th, .trash-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .trash-table th {
            background: var(--bg-secondary);
            font-weight: 600;
        }

        .trash-table tr:hover { background: var(--bg-secondary); }

        .trash-table .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .trash-table .file-icon { font-size: 1.5em; }

        .actions {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .restore-btn { background: var(--success-color); color: white; }
        .delete-btn { background: var(--danger-color); color: white; }
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

        .warning-box {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #ffeeba;
        }

        @media (max-width: 768px) {
            .header { flex-direction: column; text-align: center; }
            .toolbar { flex-direction: column; }
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗑️ Trash</h1>
            <div>
                <a href="dashboard.php" class="btn btn-secondary">← Back to Files</a>
            </div>
        </div>

        <div class="toolbar">
            <div class="stats">
                <?php echo count($trashItems); ?> items • <?php echo formatFileSize($totalSize); ?> total
            </div>
            <?php if (count($trashItems) > 0): ?>
                <div style="display: flex; gap: 10px;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="restore_all">
                        <button type="submit" class="btn btn-success">♻️ Restore All</button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to permanently delete all items? This cannot be undone!');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="empty_trash">
                        <button type="submit" class="btn btn-danger">🗑️ Empty Trash</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="content">
            <?php if ($message): ?>
                <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="warning-box">
                ⚠️ Items in trash will be permanently deleted after 30 days. Restore items if you need them.
            </div>

            <?php if (empty($trashItems)): ?>
                <div class="empty-state">
                    <div class="icon">🗑️</div>
                    <h3>Trash is empty</h3>
                    <p>Deleted files will appear here.</p>
                </div>
            <?php else: ?>
                <table class="trash-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Original Location</th>
                            <th>Size</th>
                            <th>Deleted</th>
                            <th>Days Left</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trashItems as $item): ?>
                            <?php
                            $deletedAt = strtotime($item['deleted_at']);
                            $daysLeft = 30 - floor((time() - $deletedAt) / 86400);
                            $daysLeft = max(0, $daysLeft);
                            ?>
                            <tr>
                                <td>
                                    <div class="file-info">
                                        <span class="file-icon"><?php echo $item['is_folder'] ? '📁' : getFileIcon(pathinfo($item['original_name'], PATHINFO_EXTENSION)); ?></span>
                                        <span><?php echo htmlspecialchars($item['original_name']); ?></span>
                                    </div>
                                </td>
                                <td style="color: var(--text-secondary);"><?php echo htmlspecialchars($item['original_path'] ?: 'Root'); ?></td>
                                <td><?php echo $item['is_folder'] ? '—' : formatFileSize($item['size']); ?></td>
                                <td><?php echo date('M j, Y g:i A', $deletedAt); ?></td>
                                <td>
                                    <span style="color: <?php echo $daysLeft <= 7 ? 'var(--danger-color)' : 'var(--text-secondary)'; ?>">
                                        <?php echo $daysLeft; ?> days
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="trash_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" class="action-btn restore-btn">♻️ Restore</button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete this item?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="delete_permanent">
                                            <input type="hidden" name="trash_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn">🗑️ Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
