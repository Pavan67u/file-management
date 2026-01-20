<?php
session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $filePath = sanitizePath($_POST['file_path'] ?? '');
        $fileName = basename($filePath);
        $comment = trim($_POST['comment'] ?? '');
        
        if (empty($filePath) || empty($comment)) {
            echo json_encode(['success' => false, 'message' => 'File path and comment are required']);
            exit();
        }
        
        if (addFileComment($conn, $userId, $filePath, $fileName, $comment)) {
            logActivity($conn, $userId, 'comment_add', $fileName, $filePath);
            echo json_encode(['success' => true, 'message' => 'Comment added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add comment']);
        }
    } elseif ($action === 'delete') {
        $commentId = intval($_POST['comment_id'] ?? 0);
        
        if (deleteFileComment($conn, $userId, $commentId)) {
            echo json_encode(['success' => true, 'message' => 'Comment deleted']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete comment']);
        }
    } elseif ($action === 'get') {
        $filePath = sanitizePath($_POST['file_path'] ?? '');
        $comments = getFileComments($conn, $userId, $filePath);
        echo json_encode(['success' => true, 'comments' => $comments]);
    }
    
    exit();
}

// Display comments page for a specific file
$filePath = sanitizePath($_GET['file'] ?? '');
$baseDir = getUserBaseDir($userId);
$fullPath = $baseDir . $filePath;

if (empty($filePath) || !file_exists($fullPath)) {
    header('Location: dashboard.php');
    exit();
}

$fileName = basename($filePath);
$comments = getFileComments($conn, $userId, $filePath);
$csrfToken = generateCSRFToken();
$darkMode = isset($_COOKIE['darkMode']) && $_COOKIE['darkMode'] === 'true';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $darkMode ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comments - <?php echo htmlspecialchars($fileName); ?></title>
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
            max-width: 800px;
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
        .comment-form {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .comment-form h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
        }
        .comment-form textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
        }
        .comment-form textarea:focus {
            outline: none;
            border-color: var(--accent-color);
        }
        .comment-form button {
            margin-top: 10px;
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
        }
        .comment-form button:hover { opacity: 0.9; }
        .comments-list h3 {
            margin: 0 0 20px 0;
            font-size: 18px;
        }
        .comment-item {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .comment-date {
            color: var(--text-secondary);
            font-size: 12px;
        }
        .comment-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }
        .comment-delete:hover { background: #c82333; }
        .comment-text {
            line-height: 1.6;
            white-space: pre-wrap;
        }
        .no-comments {
            text-align: center;
            color: var(--text-secondary);
            padding: 40px;
            background: var(--bg-secondary);
            border-radius: 12px;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="dashboard.php?path=<?php echo urlencode(dirname($filePath)); ?>" class="back-btn">← Back</a>
            <div class="file-info">
                <h1>💬 <?php echo htmlspecialchars($fileName); ?></h1>
                <div class="path"><?php echo htmlspecialchars($filePath); ?></div>
            </div>
        </div>
        
        <div id="message"></div>
        
        <div class="comment-form">
            <h3>Add Comment</h3>
            <form id="commentForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($filePath); ?>">
                <textarea name="comment" placeholder="Write your comment here..." required></textarea>
                <button type="submit">Add Comment</button>
            </form>
        </div>
        
        <div class="comments-list">
            <h3>Comments (<?php echo count($comments); ?>)</h3>
            <div id="commentsContainer">
                <?php if (empty($comments)): ?>
                    <div class="no-comments">No comments yet. Be the first to add one!</div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item" data-id="<?php echo $comment['id']; ?>">
                            <div class="comment-header">
                                <span class="comment-date"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></span>
                                <button class="comment-delete" onclick="deleteComment(<?php echo $comment['id']; ?>)">Delete</button>
                            </div>
                            <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        const csrfToken = '<?php echo $csrfToken; ?>';
        const filePath = '<?php echo addslashes($filePath); ?>';
        
        document.getElementById('commentForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch('comments.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                showMessage(data.message, data.success ? 'success' : 'error');
                
                if (data.success) {
                    this.querySelector('textarea').value = '';
                    loadComments();
                }
            } catch (error) {
                showMessage('An error occurred', 'error');
            }
        });
        
        async function deleteComment(commentId) {
            if (!confirm('Delete this comment?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('comment_id', commentId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('comments.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                showMessage(data.message, data.success ? 'success' : 'error');
                
                if (data.success) {
                    document.querySelector(`.comment-item[data-id="${commentId}"]`)?.remove();
                    checkEmptyComments();
                }
            } catch (error) {
                showMessage('An error occurred', 'error');
            }
        }
        
        async function loadComments() {
            const formData = new FormData();
            formData.append('action', 'get');
            formData.append('file_path', filePath);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('comments.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const container = document.getElementById('commentsContainer');
                    if (data.comments.length === 0) {
                        container.innerHTML = '<div class="no-comments">No comments yet. Be the first to add one!</div>';
                    } else {
                        container.innerHTML = data.comments.map(c => `
                            <div class="comment-item" data-id="${c.id}">
                                <div class="comment-header">
                                    <span class="comment-date">${new Date(c.created_at).toLocaleString()}</span>
                                    <button class="comment-delete" onclick="deleteComment(${c.id})">Delete</button>
                                </div>
                                <div class="comment-text">${escapeHtml(c.comment).replace(/\n/g, '<br>')}</div>
                            </div>
                        `).join('');
                    }
                    document.querySelector('.comments-list h3').textContent = `Comments (${data.comments.length})`;
                }
            } catch (error) {
                console.error('Error loading comments:', error);
            }
        }
        
        function checkEmptyComments() {
            const container = document.getElementById('commentsContainer');
            if (container.children.length === 0) {
                container.innerHTML = '<div class="no-comments">No comments yet. Be the first to add one!</div>';
            }
            // Update count
            const count = container.querySelectorAll('.comment-item').length;
            document.querySelector('.comments-list h3').textContent = `Comments (${count})`;
        }
        
        function showMessage(text, type) {
            const messageDiv = document.getElementById('message');
            messageDiv.innerHTML = `<div class="message ${type}">${text}</div>`;
            setTimeout(() => { messageDiv.innerHTML = ''; }, 3000);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
