<?php
session_start();
include 'db_config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Directory for file storage
$fileStorageDir = "file_storage/";
if (!is_dir($fileStorageDir)) {
    mkdir($fileStorageDir, 0777, true);
}

// Handle file upload
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $targetPath = $fileStorageDir . basename($file['name']);

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $message = "File uploaded successfully.";
    } else {
        $message = "Failed to upload file.";
    }
}

// Handle file search
$searchQuery = $_GET['search'] ?? '';
$files = array_diff(scandir($fileStorageDir), array('.', '..'));

if (!empty($searchQuery)) {
    $files = array_filter($files, function($file) use ($searchQuery) {
        return stripos($file, $searchQuery) !== false; // Case-insensitive search
    });
}

// Fetch username (optional)
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$username = $user['username'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <nav>
        <ul>
            <li><a href="dashboard.php">Dashboard</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
        <p class="user-info">Welcome, <?php echo htmlspecialchars($username); ?>!</p>
    </nav>

    <div class="container">
        <h1>File Management Dashboard</h1>

        <!-- File Upload Section -->
        <form action="dashboard.php" method="POST" enctype="multipart/form-data" class="upload-form">
            <label for="file">Upload File:</label>
            <input type="file" id="file" name="file" required>
            <button type="submit">Upload</button>
        </form>

        <?php if (!empty($message)): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <!-- File Search Section -->
        <form action="dashboard.php" method="GET" class="search-form">
    <input type="text" name="search" placeholder="Search files..." value="<?php echo htmlspecialchars($searchQuery); ?>">
    <button type="submit">Search</button>
    <?php if (!empty($searchQuery)): ?>
        <a href="dashboard.php" class="button back-button">Back</a>
    <?php endif; ?>
</form>


        <!-- File List Section -->
        <h2>Files</h2>
        <?php if (empty($files)): ?>
            <p>No files found<?php echo !empty($searchQuery) ? " for '$searchQuery'" : ''; ?>.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($files as $file): ?>
                    <li>
                        <?php echo htmlspecialchars($file); ?>
                        <a href="download_file.php?file=<?php echo urlencode($file); ?>" class="button">Download</a>
                        <a href="delete_file.php?file=<?php echo urlencode($file); ?>" class="button delete-button" onclick="return confirm('Are you sure you want to delete this file?');">Delete</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</body>
</html>
