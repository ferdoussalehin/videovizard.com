<?php
/**
 * ig_connect_vizard.php
 * Placed in: meta/ig_connect_vizard.php
 *
 * Server-side OAuth redirect for Instagram Login (Instagram API with
 * Instagram Login — separate from Facebook Login). User logs in directly
 * with their Instagram Business/Creator account; no Facebook page required.
 *
 * Redirects user to instagram.com OAuth dialog. Instagram redirects back to
 * ig_callback_vizard.php with ?code=...
 *
 * NOTE: Add https://videovizard.com/meta/ig_callback_vizard.php as a Valid
 * OAuth Redirect URI in your Instagram App Dashboard
 * (App → Instagram → API setup with Instagram login → Business login settings).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg      = require __DIR__ . '/config.php';
$igAppId  = (string)($cfg['meta']['instagram_app_id'] ?? '');

// CSRF state token
$state = bin2hex(random_bytes(16));
$_SESSION['ig_oauth_state']  = $state;
$_SESSION['ig_oauth_return'] = '../vizard_scheduler.php?ig_connected=1';
$_SESSION['oauth_company_id'] = (int)($_GET['company_id'] ?? $_SESSION['oauth_company_id'] ?? 0);

$redirectUri = 'https://videovizard.com/meta/ig_callback_vizard.php';

// Instagram Login API scopes (NOT the legacy instagram_basic / instagram_content_publish
// scopes which only work via Facebook Login).
$scope = implode(',', [
    'instagram_business_basic',
    'instagram_business_content_publish',
    'instagram_business_manage_insights',
]);

$url = 'https://www.instagram.com/oauth/authorize?' . http_build_query([
    'client_id'     => $igAppId,
    'redirect_uri'  => $redirectUri,
    'scope'         => $scope,
    'state'         => $state,
    'response_type' => 'code',
]);

header('Location: ' . $url);
exit;
