<?php
// router.php - For PHP built-in server to handle pretty URLs

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files directly
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    // Return false to let PHP's built-in server handle the file
    return false;
}

// Route all other requests to index.html or appropriate PHP file
if ($uri === '/') {
    include __DIR__ . '/index.html';
} else {
    // Let PHP handle the request
    return false;
}
