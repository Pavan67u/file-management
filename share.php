<?php
// share.php: Handle shared file downloads

require_once 'db_config.php';
require_once 'functions.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    showError('Invalid share link');
}

// Get share info
$stmt = $conn->prepare("SELECT sl.*, u.username FROM shared_links sl JOIN users u ON sl.user_id = u.id WHERE sl.share_token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$share = $result->fetch_assoc();
$stmt->close();

if (!$share) {
    showError('Share link not found or has been removed');
}

// Check if expired
if ($share['expires_at'] && strtotime($share['expires_at']) < time()) {
    showError('This share link has expired');
}

// Check max downloads
if ($share['max_downloads'] !== null && $share['download_count'] >= $share['max_downloads']) {
    showError('This share link has reached its maximum download limit');
}

// Check password
$passwordRequired = !empty($share['password']);
$passwordVerified = false;

if ($passwordRequired && isset($_POST['password'])) {
    if (password_verify($_POST['password'], $share['password'])) {
        $passwordVerified = true;
        $_SESSION['share_verified_' . $token] = true;
    }
}

if (!$passwordRequired || isset($_SESSION['share_verified_' . $token])) {
    $passwordVerified = true;
}

// Handle download
if (isset($_GET['download']) && $passwordVerified) {
    $baseDir = getUserBaseDir($share['user_id']);
    $filePath = $baseDir . $share['file_path'];
    
    if (file_exists($filePath) && is_file($filePath)) {
        // Update download count
        $stmt = $conn->prepare("UPDATE shared_links SET download_count = download_count + 1 WHERE id = ?");
        $stmt->bind_param("i", $share['id']);
        $stmt->execute();
        $stmt->close();
        
        // Log activity
        logActivity($conn, $share['user_id'], 'share_download', $share['file_name'], '', 'Downloaded via share link');
        
        // Send file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $share['file_name'] . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache');
        readfile($filePath);
        exit();
    } else {
        showError('File no longer exists');
    }
}

function showError($message) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Share Error</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .card {
                background: white;
                padding: 40px;
                border-radius: 15px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.2);
                text-align: center;
                max-width: 400px;
            }
            .icon { font-size: 4em; margin-bottom: 20px; }
            h2 { color: #dc3545; margin-bottom: 15px; }
            p { color: #666; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">❌</div>
            <h2>Error</h2>
            <p><?php echo htmlspecialchars($message); ?></p>
        </div>
    </body>
    </html>
    <?php
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download - <?php echo htmlspecialchars($share['file_name']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            text-align: center;
            max-width: 450px;
            width: 100%;
        }
        .icon { font-size: 4em; margin-bottom: 20px; }
        h2 { color: #333; margin-bottom: 10px; word-break: break-all; }
        .meta { color: #666; margin-bottom: 25px; font-size: 14px; }
        .meta span { display: block; margin: 5px 0; }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 15px;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        .error {
            color: #dc3545;
            margin-bottom: 15px;
        }
        .info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .shared-by {
            color: #888;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon"><?php echo getFileIcon(pathinfo($share['file_name'], PATHINFO_EXTENSION)); ?></div>
        <h2><?php echo htmlspecialchars($share['file_name']); ?></h2>
        
        <div class="info">
            <div class="meta">
                <?php if ($share['expires_at']): ?>
                    <span>⏰ Expires: <?php echo date('M j, Y g:i A', strtotime($share['expires_at'])); ?></span>
                <?php endif; ?>
                <?php if ($share['max_downloads'] !== null): ?>
                    <span>📥 Downloads: <?php echo $share['download_count']; ?> / <?php echo $share['max_downloads']; ?></span>
                <?php else: ?>
                    <span>📥 Downloads: <?php echo $share['download_count']; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($passwordRequired && !$passwordVerified): ?>
            <form method="POST">
                <?php if (isset($_POST['password'])): ?>
                    <div class="error">❌ Incorrect password</div>
                <?php endif; ?>
                <input type="password" name="password" placeholder="Enter password" required autofocus>
                <button type="submit" class="btn">🔓 Unlock & Download</button>
            </form>
        <?php else: ?>
            <a href="?token=<?php echo urlencode($token); ?>&download=1" class="btn">⬇️ Download File</a>
        <?php endif; ?>

        <div class="shared-by">Shared by <?php echo htmlspecialchars($share['username']); ?></div>
    </div>
</body>
</html>
