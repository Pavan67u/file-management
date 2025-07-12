<?php
// delete_file.php: Delete File Script

session_start();
include 'db_config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle file deletion
$message = '';
if (isset($_GET['file'])) {
    $fileStorageDir = "file_storage/";
    $file = $fileStorageDir . basename($_GET['file']);

    if (file_exists($file) && unlink($file)) {
        $message = "File deleted successfully.";
    } else {
        $message = "Failed to delete the file.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete File</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <nav>
        <ul>
            <li><a href="dashboard.php">Dashboard</a></li>
            <li><a href="upload_file.php">Upload File</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </nav>

    <div class="container">
        <h1>Delete File</h1>
        <?php if (!empty($message)): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <a href="dashboard.php" class="button">Back to Dashboard</a>
    </div>
</body>
</html>
