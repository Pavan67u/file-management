<?php
// activity_log.php: View Activity Logs

session_start();
require_once 'db_config.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$csrfToken = generateCSRFToken();
$darkMode = getDarkMode($conn, $userId);

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filter
$filterUser = $_GET['user'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterDate = $_GET['date'] ?? '';

// Build query
$whereConditions = [];
$params = [];
$types = '';

if (!$isAdmin) {
    $whereConditions[] = "a.user_id = ?";
    $params[] = $userId;
    $types .= 'i';
} elseif ($filterUser) {
    $whereConditions[] = "a.user_id = ?";
    $params[] = intval($filterUser);
    $types .= 'i';
}

if ($filterAction) {
    $whereConditions[] = "a.action = ?";
    $params[] = $filterAction;
    $types .= 's';
}

if ($filterDate) {
    $whereConditions[] = "DATE(a.created_at) = ?";
    $params[] = $filterDate;
    $types .= 's';
}

$whereClause = count($whereConditions) > 0 ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM activity_logs a $whereClause";
$stmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalLogs = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$totalPages = ceil($totalLogs / $perPage);

// Get logs
$query = "SELECT a.*, u.username FROM activity_logs a 
          LEFT JOIN users u ON a.user_id = u.id 
          $whereClause 
          ORDER BY a.created_at DESC 
          LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get unique actions for filter
$actions = [];
$result = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
while ($row = $result->fetch_assoc()) {
    $actions[] = $row['action'];
}

// Get users for filter (admin only)
$users = [];
if ($isAdmin) {
    $result = $conn->query("SELECT id, username FROM users ORDER BY username");
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

function getActionIcon($action) {
    $icons = [
        'login' => '🔐',
        'logout' => '🚪',
        'upload' => '📤',
        'download' => '📥',
        'delete' => '🗑️',
        'permanent_delete' => '💀',
        'create_folder' => '📁',
        'rename' => '✏️',
        'move' => '📋',
        'copy' => '📄',
        'restore' => '♻️',
        'empty_trash' => '🗑️',
        'share' => '🔗',
        'share_download' => '📥',
        'preview' => '👁️',
        'favorite' => '⭐',
        'unfavorite' => '☆',
    ];
    return $icons[$action] ?? '📝';
}

function getActionColor($action) {
    $colors = [
        'login' => '#28a745',
        'logout' => '#6c757d',
        'upload' => '#17a2b8',
        'download' => '#007bff',
        'delete' => '#dc3545',
        'permanent_delete' => '#721c24',
        'create_folder' => '#ffc107',
        'rename' => '#17a2b8',
        'move' => '#fd7e14',
        'copy' => '#6f42c1',
        'restore' => '#28a745',
        'empty_trash' => '#dc3545',
        'share' => '#6f42c1',
        'share_download' => '#007bff',
        'preview' => '#17a2b8',
        'favorite' => '#ffc107',
        'unfavorite' => '#6c757d',
    ];
    return $colors[$action] ?? '#6c757d';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $darkMode ? 'dark' : 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - File Manager</title>
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-tertiary: #e9ecef;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --border-color: #dee2e6;
            --accent-color: #667eea;
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
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
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
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }

        .filters {
            background: var(--bg-secondary);
            padding: 20px 30px;
            border-bottom: 1px solid var(--border-color);
        }

        .filters form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filters select, .filters input {
            padding: 10px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .filters select:focus, .filters input:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        .content {
            padding: 20px 30px;
            min-height: 400px;
        }

        .stats {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        .log-table {
            width: 100%;
            border-collapse: collapse;
        }

        .log-table th, .log-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .log-table th {
            background: var(--bg-secondary);
            font-weight: 600;
        }

        .log-table tr:hover { background: var(--bg-secondary); }

        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
        }

        .file-path {
            color: var(--text-secondary);
            font-size: 12px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .pagination a, .pagination span {
            padding: 8px 14px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.3s;
        }

        .pagination a:hover {
            background: var(--accent-color);
            border-color: var(--accent-color);
            color: white;
        }

        .pagination .current {
            background: var(--accent-color);
            border-color: var(--accent-color);
            color: white;
        }

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

        @media (max-width: 768px) {
            .header { flex-direction: column; text-align: center; gap: 15px; }
            .filters form { flex-direction: column; }
            .filters select, .filters input { width: 100%; }
            .log-table { font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Activity Log</h1>
            <a href="dashboard.php" class="btn btn-secondary">← Back to Files</a>
        </div>

        <div class="filters">
            <form method="GET">
                <?php if ($isAdmin): ?>
                    <select name="user">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                
                <select name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?php echo $a; ?>" <?php echo $filterAction === $a ? 'selected' : ''; ?>>
                            <?php echo getActionIcon($a); ?> <?php echo ucfirst(str_replace('_', ' ', $a)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
                
                <button type="submit" class="btn btn-primary">🔍 Filter</button>
                
                <?php if ($filterUser || $filterAction || $filterDate): ?>
                    <a href="activity_log.php" class="btn btn-secondary">✖ Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="content">
            <div class="stats">
                Showing <?php echo count($logs); ?> of <?php echo $totalLogs; ?> activities
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <div class="icon">📋</div>
                    <h3>No activity found</h3>
                    <p>Activity will appear here as you use the file manager.</p>
                </div>
            <?php else: ?>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <?php if ($isAdmin): ?><th>User</th><?php endif; ?>
                            <th>Action</th>
                            <th>File/Folder</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('M j, Y g:i:s A', strtotime($log['created_at'])); ?></td>
                                <?php if ($isAdmin): ?>
                                    <td><?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?></td>
                                <?php endif; ?>
                                <td>
                                    <span class="action-badge" style="background-color: <?php echo getActionColor($log['action']); ?>">
                                        <?php echo getActionIcon($log['action']); ?>
                                        <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($log['file_name'] ?? '-'); ?>
                                    <?php if ($log['file_path']): ?>
                                        <div class="file-path">📁 <?php echo htmlspecialchars($log['file_path']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--text-secondary);"><?php echo htmlspecialchars($log['details'] ?? '-'); ?></td>
                                <td style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1<?php echo $filterUser ? "&user=$filterUser" : ''; ?><?php echo $filterAction ? "&action=$filterAction" : ''; ?><?php echo $filterDate ? "&date=$filterDate" : ''; ?>">« First</a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $filterUser ? "&user=$filterUser" : ''; ?><?php echo $filterAction ? "&action=$filterAction" : ''; ?><?php echo $filterDate ? "&date=$filterDate" : ''; ?>">‹ Prev</a>
                        <?php endif; ?>
                        
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?><?php echo $filterUser ? "&user=$filterUser" : ''; ?><?php echo $filterAction ? "&action=$filterAction" : ''; ?><?php echo $filterDate ? "&date=$filterDate" : ''; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $filterUser ? "&user=$filterUser" : ''; ?><?php echo $filterAction ? "&action=$filterAction" : ''; ?><?php echo $filterDate ? "&date=$filterDate" : ''; ?>">Next ›</a>
                            <a href="?page=<?php echo $totalPages; ?><?php echo $filterUser ? "&user=$filterUser" : ''; ?><?php echo $filterAction ? "&action=$filterAction" : ''; ?><?php echo $filterDate ? "&date=$filterDate" : ''; ?>">Last »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
