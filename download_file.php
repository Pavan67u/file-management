<?php
session_start();
include 'db_config.php';

if (isset($_GET['file'])) {
    $fileName = $_GET['file'];
    $filePath = 'file_storage/' . $fileName;

    // Ensure the file exists
    if (file_exists($filePath)) {
        // Set headers to initiate a file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        // Read and output the file
        readfile($filePath);
        exit();
    } else {
        echo "File not found.";
    }
} else {
    echo "No file specified.";
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
