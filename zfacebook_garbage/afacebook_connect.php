<?php
/**
 * facebook_connect.php
 * Redirects the logged-in admin to Facebook OAuth.
 * Fixed: session_start() FIRST, v21.0 API, added pages_manage_engagement scope.
 */

session_set_cookie_params(15552000);
session_start();

require_once 'config.php';

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$admin_id) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// State carries admin_id and company_id so callback can recover them even if session expires
$company_id = (int)($_SESSION['company_id'] ?? 0);
$state = bin2hex(random_bytes(16)) . '|' . $admin_id . '|' . $company_id;
$_SESSION['fb_oauth_state'] = $state; // also store in session for CSRF check

$permissions = [
    'public_profile',
    'pages_manage_posts',
    'pages_read_engagement',
    'pages_show_list',
    'pages_manage_engagement',   // needed for video uploads
];

$url = "https://www.facebook.com/v21.0/dialog/oauth"
     . "?client_id="    . FB_APP_ID
     . "&redirect_uri=" . urlencode(FB_REDIRECT_URI)
     . "&state="        . urlencode($state)
     . "&scope="        . implode(',', $permissions);

header('Location: ' . $url);
exit;
