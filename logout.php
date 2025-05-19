<?php
/**
 * logout.php - Log out the user
 */

// Start session
session_start();

// Clear all session variables
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to the homepage
header("Location: index.php");
exit;