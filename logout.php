<?php
// logout.php: Enhanced Logout Script

session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Log the logout activity to database
if (isset($_SESSION['user_id'])) {
    logActivity($conn, $_SESSION['user_id'], 'logout', null, null, 'User logged out');
}

// Store user info for goodbye message
$username = $_SESSION['username'] ?? 'User';

// Clear all session data
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page with logout message
header("Location: login.php?message=logged_out");
exit();
?>