<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg      = require __DIR__ . '/meta/config.php';
$clientId = (string)($cfg['linkedin']['client_id'] ?? '');

if (!$clientId) {
    header('Location: vizard_scheduler.php?li_error=' . urlencode('LinkedIn client_id not configured'));
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['li_oauth_state']  = $state;
$_SESSION['li_oauth_return'] = '../vizard_scheduler.php?linkedin_connected=1';
$_SESSION['oauth_company_id'] = (int)($_GET['company_id'] ?? $_SESSION['oauth_company_id'] ?? 0);

$redirectUri = 'https://videovizard.com/linkedin/li_callback_vizard.php';

$url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'state'         => $state,
    'scope'         => 'openid profile email w_member_social',
]);

header('Location: ' . $url);
exit;
