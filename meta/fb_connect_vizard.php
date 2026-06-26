<?php
/**
 * fb_connect_vizard.php
 * Placed in: meta/fb_connect_vizard.php
 *
 * Server-side OAuth redirect — no FB JS SDK, no popups, no loops.
 * Sends user directly to Facebook's OAuth dialog.
 * Facebook redirects back to callback.php with ?code=...
 * BUT our callback.php expects ?access_token= (JS SDK flow).
 *
 * So we use a separate redirect_uri: fb_callback_vizard.php
 * which exchanges the code for a token, then saves to DB.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg       = require __DIR__ . '/config.php';
$appId     = (string)($cfg['meta']['app_id']     ?? '');
$appSecret = (string)($cfg['meta']['app_secret'] ?? '');

// CSRF state token
$state = bin2hex(random_bytes(16));
$_SESSION['fb_oauth_state']  = $state;
$_SESSION['fb_oauth_return'] = '../vizard_scheduler.php?fb_connected=1';
$_SESSION['oauth_company_id'] = (int)($_GET['company_id'] ?? $_SESSION['oauth_company_id'] ?? 0);

$redirectUri = 'https://187.124.249.46/videovizard.com/meta/fb_callback_vizard.php';

$scope = implode(',', [
    'public_profile',
    'pages_show_list',
    'pages_manage_metadata',
    'pages_read_engagement',
    'pages_manage_posts',
    'read_insights',
]);

// auth_type=rerequest forces Facebook to re-prompt for any scope the user
// previously denied — needed when adding read_insights to an existing connection.
$url = 'https://www.facebook.com/v24.0/dialog/oauth?' . http_build_query([
    'client_id'     => $appId,
    'redirect_uri'  => $redirectUri,
    'scope'         => $scope,
    'state'         => $state,
    'response_type' => 'code',
    'auth_type'     => 'rerequest',
]);

header('Location: ' . $url);
exit;
