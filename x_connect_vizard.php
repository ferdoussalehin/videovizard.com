<?php
/**
 * x_connect_vizard.php
 *
 * Initiates Twitter/X OAuth 2.0 with PKCE (S256).
 * Redirects user to Twitter login; Twitter returns to x_callback.php.
 *
 * NOTE: Register https://videovizard.com/x_callback.php
 * as a valid callback URI in your Twitter/X Developer App settings.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg      = require __DIR__ . '/meta/config.php';
$clientId = (string)($cfg['twitter']['client_id'] ?? '');

$redirectUri = 'https://videovizard.com/x_callback.php';

// CSRF state
$state = bin2hex(random_bytes(16));
$_SESSION['x_oauth_state']  = $state;
$_SESSION['x_oauth_return'] = 'vizard_scheduler.php?x_connected=1';
$_SESSION['oauth_company_id'] = (int)($_GET['company_id'] ?? $_SESSION['oauth_company_id'] ?? 0);

// PKCE S256
$chars        = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
$codeVerifier = '';
for ($i = 0; $i < 64; $i++) {
    $codeVerifier .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['x_code_verifier'] = $codeVerifier;
$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

$url = 'https://x.com/i/oauth2/authorize?' . http_build_query([
    'response_type'         => 'code',
    'client_id'             => $clientId,
    'redirect_uri'          => $redirectUri,
    'scope'                 => 'tweet.read tweet.write users.read media.write offline.access',
    'state'                 => $state,
    'code_challenge'        => $codeChallenge,
    'code_challenge_method' => 'S256',
]);

header('Location: ' . $url);
exit;
