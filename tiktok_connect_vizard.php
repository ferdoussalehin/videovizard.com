<?php
/**
 * tiktok_connect_vizard.php
 *
 * Initiates TikTok OAuth 2.0 with PKCE.
 * Redirects user to TikTok login; TikTok returns to tiktok_callback_vizard.php.
 *
 * NOTE: Add https://videovizard.com/tiktok_callback_vizard.php
 * as a valid redirect URI in your TikTok Developer App settings.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg       = require __DIR__ . '/meta/config.php';
$clientKey = (string)($cfg['tiktok']['client_key'] ?? '');

$redirectUri = 'https://videovizard.com/tiktok_callback_vizard.php';

// CSRF state
$state = bin2hex(random_bytes(16));
$_SESSION['tiktok_oauth_state']  = $state;
$_SESSION['tiktok_oauth_return'] = 'vizard_scheduler.php?tiktok_connected=1';
$_SESSION['oauth_company_id'] = (int)($_GET['company_id'] ?? $_SESSION['oauth_company_id'] ?? 0);

// PKCE code verifier + challenge
$chars        = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
$codeVerifier = '';
for ($i = 0; $i < 64; $i++) {
    $codeVerifier .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['tiktok_code_verifier'] = $codeVerifier;
$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

$scope = 'user.info.basic,video.upload,video.publish';

$url = 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
    'client_key'            => $clientKey,
    'scope'                 => $scope,
    'response_type'         => 'code',
    'redirect_uri'          => $redirectUri,
    'state'                 => $state,
    'code_challenge'        => $codeChallenge,
    'code_challenge_method' => 'S256',
]);

header('Location: ' . $url);
exit;
