<?php
session_start();
include 'db_config.php';

// Directory for file storage
$fileStorageDir = "file_storage/";
if (!is_dir($fileStorageDir)) {
    mkdir($fileStorageDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $targetPath = $fileStorageDir . basename($file['name']);

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Insert file metadata into the database
        $stmt = $conn->prepare("INSERT INTO files (filename, uploaded_by) VALUES (?, ?)");
        $stmt->bind_param("si", $file['name'], $_SESSION['user_id']);
        $stmt->execute();

        echo "<p>File uploaded successfully!</p>";
    } else {
        echo "<p>Failed to upload file. Please try again.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="styles.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload File</title>
</head>
<body>
    <h1>Upload File</h1>
    <form action="" method="POST" enctype="multipart/form-data">
        <input type="file" name="file" required>
        <button type="submit">Upload</button>
    </form>
    <a href="dashboard.php">Back to Dashboard</a>
</body>
</html>
