<?php
require_once 'linkedin_handler.php';

// Initialize the handler
$linkedIn = new LinkedInCredentialHandler('http://localhost:8000');

// Check if there's an error parameter
if (isset($_GET['error'])) {
    header('Location: error.php?error=' . urlencode($_GET['error']));
    exit;
}

// Check if we have a code and state
if (!isset($_GET['code'])) {
    header('Location: error.php?error=no_code');
    exit;
}

// Set the code and proceed with authentication
try {
    $linkedIn->setCode($_GET['code']);
    header('Location: success.php');
} catch (Exception $e) {
    header('Location: error.php?error=' . urlencode($e->getMessage()));
}
exit; 