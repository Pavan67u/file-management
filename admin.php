<?php
// admin.php: Enhanced Admin Panel with Storage Quota Management

session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php?error=access_denied");
    exit();
}

$csrfToken = generateCSRFToken();
$darkMode = getDarkMode($conn, $_SESSION['user_id']);
$message = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid security token.";
    } else {
        $action = $_POST['action'] ?? '';
        $userId = intval($_POST['user_id'] ?? 0);
        
        if ($action === 'delete' && $userId > 0) {
            if ($userId == $_SESSION['user_id']) {
                $error = "You cannot delete your own account.";
            } else {
                // Get user's storage directory before deletion
                $userDir = getUserBaseDir($userId);
                
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
                $stmt->bind_param("i", $userId);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    // Delete user's files
                    if (is_dir($userDir)) {
                        deleteDirectory($userDir);
                    }
                    // Delete from related tables
                    $conn->query("DELETE FROM activity_logs WHERE user_id = $userId");
                    $conn->query("DELETE FROM trash WHERE user_id = $userId");
                    $conn->query("DELETE FROM shared_links WHERE user_id = $userId");
                    $conn->query("DELETE FROM favorites WHERE user_id = $userId");
                    
                    $message = "User and all their data deleted successfully.";
                    logActivity($conn, $_SESSION['user_id'], 'admin_delete_user', "User ID: $userId");
                } else {
                    $error = "Failed to delete user or user is an admin.";
                }
                $stmt->close();
            }
        } elseif ($action === 'make_admin' && $userId > 0) {
            $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
            $stmt->bind_param("i", $userId);
            if ($stmt->execute()) {
                $message = "User promoted to admin.";
                logActivity($conn, $_SESSION['user_id'], 'admin_promote', "User ID: $userId");
            } else {
                $error = "Failed to update user role.";
            }
            $stmt->close();
        } elseif ($action === 'remove_admin' && $userId > 0) {
            if ($userId == $_SESSION['user_id']) {
                $error = "You cannot remove your own admin privileges.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET role = 'user' WHERE id = ?");
                $stmt->bind_param("i", $userId);
                if ($stmt->execute()) {
                    $message = "Admin privileges removed.";
                    logActivity($conn, $_SESSION['user_id'], 'admin_demote', "User ID: $userId");
                } else {
                    $error = "Failed to update user role.";
                }
                $stmt->close();
            }
        } elseif ($action === 'update_quota' && $userId > 0) {
            $newQuota = intval($_POST['quota'] ?? 0) * 1024 * 1024; // Convert MB to bytes
            if ($newQuota >= 0) {
                $stmt = $conn->prepare("UPDATE users SET storage_quota = ? WHERE id = ?");
                $stmt->bind_param("ii", $newQuota, $userId);
                if ($stmt->execute()) {
                    $message = "Storage quota updated.";
                    logActivity($conn, $_SESSION['user_id'], 'admin_update_quota', "User ID: $userId, Quota: " . formatFileSize($newQuota));
                } else {
                    $error = "Failed to update quota.";
                }
                $stmt->close();
            }
        } elseif ($action === 'reset_password' && $userId > 0) {
            $newPassword = $_POST['new_password'] ?? '';
            if (strlen($newPassword) >= 6) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashedPassword, $userId);
                if ($stmt->execute()) {
                    $message = "Password reset successfully.";
                    logActivity($conn, $_SESSION['user_id'], 'admin_reset_password', "User ID: $userId");
                } else {
                    $error = "Failed to reset password.";
                }
                $stmt->close();
            } else {
                $error = "Password must be at least 6 characters.";
            }
        }
    }
}

// Fetch all users with storage info
$users = [];
$result = $conn->query("SELECT id, username, email, role, storage_quota, storage_used, dark_mode, created_at FROM users ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Calculate actual storage if needed
        $userDir = getUserBaseDir($row['id']);
        if (is_dir($userDir)) {
            $actualUsed = getDirectorySize($userDir);
            if ($actualUsed != $row['storage_used']) {
                // Update the stored value
                $stmt = $conn->prepare("UPDATE users SET storage_used = ? WHERE id = ?");
                $stmt->bind_param("ii", $actualUsed, $row['id']);
                $stmt->execute();
                $stmt->close();
                $row['storage_used'] = $actualUsed;
            }
        }
        $users[] = $row;
    }
}

// Get statistics
$totalUsers = count($users);
$adminCount = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
$userCount = $totalUsers - $adminCount;
$totalStorage = array_sum(array_column($users, 'storage_used'));
$totalQuota = array_sum(array_column($users, 'storage_quota'));

// Get recent activities
$recentActivities = [];
$stmt = $conn->query("SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 10");
while ($row = $stmt->fetch_assoc()) {
    $recentActivities[] = $row;
}

// Helper function to delete directory
function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $darkMode ? 'dark' : 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - File Management System</title>
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
        }

        [data-theme="dark"] {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --bg-tertiary: #0f3460;
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
            max-width: 1400px;
            margin: 0 auto;
            background: var(--bg-primary);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
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

        .header h1 { font-size: 1.8em; font-weight: 400; }

        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

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

        .btn-secondary { background: rgba(255,255,255,0.2); }
        .btn-primary { background: var(--accent-color); }
        .btn-success { background: var(--success-color); }
        .btn-danger { background: var(--danger-color); }
        .btn-warning { background: var(--warning-color); color: #333; }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            padding: 25px 30px;
            background: var(--bg-secondary);
        }

        .stat-card {
            background: var(--bg-primary);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .stat-card .icon { font-size: 2em; margin-bottom: 8px; }
        .stat-card .number { font-size: 2em; font-weight: 700; color: var(--text-primary); }
        .stat-card .label { color: var(--text-secondary); font-size: 0.9em; margin-top: 5px; }

        .tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid var(--border-color);
            background: var(--bg-secondary);
            padding: 0 30px;
        }

        .tab {
            padding: 15px 25px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            color: var(--text-secondary);
            font-weight: 500;
            transition: all 0.3s;
        }

        .tab:hover { color: var(--text-primary); }
        .tab.active { color: var(--accent-color); border-bottom-color: var(--accent-color); }

        .tab-content { display: none; padding: 25px 30px; }
        .tab-content.active { display: block; }

        .content { min-height: 400px; }

        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th, .users-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .users-table th { background: var(--bg-secondary); font-weight: 600; }
        .users-table tr:hover { background: var(--bg-secondary); }

        .role-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .role-admin { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; }
        .role-user { background: var(--accent-color); color: white; }

        .storage-bar {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }

        .storage-fill {
            height: 100%;
            background: var(--accent-color);
            transition: width 0.3s;
        }

        .storage-fill.warning { background: var(--warning-color); }
        .storage-fill.danger { background: var(--danger-color); }

        .user-actions { display: flex; gap: 5px; flex-wrap: wrap; }

        .action-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 5px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s;
            color: white;
        }

        .action-btn:hover { transform: translateY(-1px); opacity: 0.9; }

        .activity-list { list-style: none; }

        .activity-item {
            padding: 15px;
            background: var(--bg-secondary);
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .activity-item .action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .activity-item .time {
            color: var(--text-secondary);
            font-size: 12px;
        }

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
            max-width: 400px;
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

        .modal-content input:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

        @media (max-width: 768px) {
            .header { flex-direction: column; text-align: center; }
            .users-table { font-size: 12px; }
            .user-actions { justify-content: center; }
            .tabs { overflow-x: auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👑 Admin Panel</h1>
            <div class="header-actions">
                <a href="activity_log.php" class="btn btn-secondary">📋 Full Activity Log</a>
                <a href="dashboard.php" class="btn btn-secondary">📁 File Manager</a>
                <a href="logout.php" class="btn btn-secondary">🚪 Logout</a>
            </div>
        </div>

        <div class="stats-container">
            <div class="stat-card">
                <div class="icon">👥</div>
                <div class="number"><?php echo $totalUsers; ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="icon">👑</div>
                <div class="number"><?php echo $adminCount; ?></div>
                <div class="label">Administrators</div>
            </div>
            <div class="stat-card">
                <div class="icon">👤</div>
                <div class="number"><?php echo $userCount; ?></div>
                <div class="label">Regular Users</div>
            </div>
            <div class="stat-card">
                <div class="icon">💾</div>
                <div class="number"><?php echo formatFileSize($totalStorage); ?></div>
                <div class="label">Total Storage Used</div>
            </div>
        </div>

        <div class="tabs">
            <div class="tab active" onclick="showTab('users')">👥 Users</div>
            <div class="tab" onclick="showTab('activity')">📋 Recent Activity</div>
        </div>

        <div class="content">
            <?php if ($message): ?>
                <div class="message success" style="margin: 20px 30px;">✅ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error" style="margin: 20px 30px;">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Users Tab -->
            <div class="tab-content active" id="usersTab">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Storage</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $storagePercent = $user['storage_quota'] > 0 ? ($user['storage_used'] / $user['storage_quota']) * 100 : 0;
                            $storageClass = $storagePercent > 90 ? 'danger' : ($storagePercent > 70 ? 'warning' : '');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                    <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                        <span style="color: var(--success-color);">(You)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--text-secondary);"><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo $user['role']; ?>">
                                        <?php echo $user['role'] === 'admin' ? '👑 Admin' : '👤 User'; ?>
                                    </span>
                                </td>
                                <td style="min-width: 150px;">
                                    <div style="font-size: 12px;">
                                        <?php echo formatFileSize($user['storage_used']); ?> / <?php echo formatFileSize($user['storage_quota']); ?>
                                    </div>
                                    <div class="storage-bar">
                                        <div class="storage-fill <?php echo $storageClass; ?>" style="width: <?php echo min(100, $storagePercent); ?>%"></div>
                                    </div>
                                </td>
                                <td style="color: var(--text-secondary); font-size: 13px;">
                                    <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                </td>
                                <td>
                                    <div class="user-actions">
                                        <button class="action-btn" style="background: var(--accent-color);" onclick="openQuotaModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', <?php echo $user['storage_quota'] / (1024*1024); ?>)">📊 Quota</button>
                                        <button class="action-btn" style="background: #17a2b8;" onclick="openPasswordModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">🔑 Reset</button>
                                        
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <?php if ($user['role'] !== 'admin'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="make_admin">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="action-btn" style="background: var(--warning-color); color: #333;">👑</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="remove_admin">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="action-btn" style="background: #6c757d;">👤</button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user and all their data?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="action-btn" style="background: var(--danger-color);">🗑️</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Activity Tab -->
            <div class="tab-content" id="activityTab">
                <h3 style="color: var(--text-primary); margin-bottom: 20px;">Recent Activity</h3>
                <?php if (empty($recentActivities)): ?>
                    <p style="color: var(--text-secondary);">No recent activity.</p>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($recentActivities as $activity): ?>
                            <li class="activity-item">
                                <div class="action">
                                    <span style="font-size: 1.2em;"><?php echo getActionIcon($activity['action']); ?></span>
                                    <div>
                                        <strong><?php echo htmlspecialchars($activity['username'] ?? 'Unknown'); ?></strong>
                                        <span style="color: var(--text-secondary);">
                                            <?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?>
                                        </span>
                                        <?php if ($activity['file_name']): ?>
                                            <span>- <?php echo htmlspecialchars($activity['file_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="time"><?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="activity_log.php" class="btn btn-primary" style="margin-top: 20px;">View All Activity</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quota Modal -->
    <div class="modal" id="quotaModal">
        <div class="modal-content">
            <h3>📊 Update Storage Quota</h3>
            <p id="quotaUsername" style="color: var(--text-secondary); margin-bottom: 15px;"></p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update_quota">
                <input type="hidden" name="user_id" id="quotaUserId">
                <label style="display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 14px;">Storage Quota (MB)</label>
                <input type="number" name="quota" id="quotaInput" min="0" step="100" required>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('quota')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Modal -->
    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <h3>🔑 Reset Password</h3>
            <p id="passwordUsername" style="color: var(--text-secondary); margin-bottom: 15px;"></p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="passwordUserId">
                <label style="display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 14px;">New Password</label>
                <input type="password" name="new_password" minlength="6" required placeholder="Minimum 6 characters">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('password')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            document.querySelector(`.tab[onclick="showTab('${tab}')"]`).classList.add('active');
            document.getElementById(tab + 'Tab').classList.add('active');
        }

        function openQuotaModal(userId, username, currentQuota) {
            document.getElementById('quotaUserId').value = userId;
            document.getElementById('quotaUsername').textContent = 'User: ' + username;
            document.getElementById('quotaInput').value = currentQuota;
            document.getElementById('quotaModal').classList.add('active');
        }

        function openPasswordModal(userId, username) {
            document.getElementById('passwordUserId').value = userId;
            document.getElementById('passwordUsername').textContent = 'User: ' + username;
            document.getElementById('passwordModal').classList.add('active');
        }

        function closeModal(type) {
            document.getElementById(type + 'Modal').classList.remove('active');
        }

        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.classList.remove('active');
            });
        });

        function getActionIcon(action) {
            const icons = {
                'login': '🔐', 'logout': '🚪', 'upload': '📤', 'download': '📥',
                'delete': '🗑️', 'create_folder': '📁', 'rename': '✏️', 'move': '📋',
                'copy': '📄', 'restore': '♻️', 'share': '🔗', 'preview': '👁️'
            };
            return icons[action] || '📝';
        }
    </script>
</body>
</html>
<?php

function getActionIcon($action) {
    $icons = [
        'login' => '🔐', 'logout' => '🚪', 'upload' => '📤', 'download' => '📥',
        'delete' => '🗑️', 'create_folder' => '📁', 'rename' => '✏️', 'move' => '📋',
        'copy' => '📄', 'restore' => '♻️', 'share' => '🔗', 'preview' => '👁️',
        'admin_delete_user' => '🗑️', 'admin_promote' => '👑', 'admin_demote' => '👤',
        'admin_update_quota' => '📊', 'admin_reset_password' => '🔑'
    ];
    return $icons[$action] ?? '📝';
}
?>
