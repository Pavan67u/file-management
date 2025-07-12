<?php
// logout.php: Logout Script

session_start();

// Clear session data
session_unset();
session_destroy();

header("Location: login.php");
exit();
?>
