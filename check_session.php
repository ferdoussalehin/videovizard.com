<?php
// check_session.php - Include this at the top of every protected page

// Set long session lifetime (must match login page)
$timeout = 30 * 24 * 60 * 60; // 30 days

// Set cookie params before session_start() on EVERY page
session_set_cookie_params([
    'lifetime' => $timeout,
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Strict'
]);

// Start the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user'])) { 
    // Not logged in - redirect to login page
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Optional: Update last activity time
$_SESSION['last_activity'] = time();

// Optional: Regenerate session ID periodically for security (every 30 minutes)
if (!isset($_SESSION['regenerated']) || (time() - $_SESSION['regenerated'] > 1800)) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}
?>