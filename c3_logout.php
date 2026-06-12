<?php
// c3_logout.php - Logout Handler
session_start();
require_once __DIR__ . '/assets/config/db_config.php';
require_once __DIR__ . '/assets/auth/auth.php';

$auth = new Auth();
$auth->logout();

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destroy the session
session_destroy();

header('Location: c3_login.php');
exit;
?>