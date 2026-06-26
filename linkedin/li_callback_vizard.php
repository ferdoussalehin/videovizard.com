<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg          = require __DIR__ . '/../meta/config.php';
$clientId     = (string)($cfg['linkedin']['client_id']     ?? '');
$clientSecret = (string)($cfg['linkedin']['client_secret'] ?? '');
$redirectUri  = 'https://videovizard.com/linkedin/li_callback_vizard.php';

function li_log(string $msg): void {
    file_put_contents(__DIR__ . '/../a_errors.log',
        date('[Y-m-d H:i:s] ') . '[li_oauth] ' . $msg . "\n", FILE_APPEND);
}

function li_post(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return [$code, (string)$resp, is_array($data) ? $data : []];
}

function li_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return [$code, (string)$resp, is_array($data) ? $data : []];
}

function bail(string $msg): void {
    li_log('ERROR: ' . $msg);
    header('Location: ../vizard_scheduler.php?li_error=' . urlencode($msg));
    exit;
}

// Error from LinkedIn
if (isset($_GET['error'])) {
    bail($_GET['error_description'] ?? $_GET['error']);
}

// CSRF check
$state = $_GET['state'] ?? '';
if (!$state || $state !== ($_SESSION['li_oauth_state'] ?? '')) {
    bail('invalid_state');
}
unset($_SESSION['li_oauth_state']);

$code = $_GET['code'] ?? '';
if (!$code) {
    bail('no_code');
}

// Exchange code for access token
[, , $tokenData] = li_post('https://www.linkedin.com/oauth/v2/accessToken', [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
]);

$accessToken = (string)($tokenData['access_token'] ?? '');
if (!$accessToken) {
    bail('token_exchange_failed');
}
$expiresIn = (int)($tokenData['expires_in'] ?? 5184000);
li_log('Got access token');

// Fetch user profile via OpenID Connect userinfo endpoint
[, , $profile] = li_get('https://api.linkedin.com/v2/userinfo', $accessToken);
$userName = trim((string)($profile['name'] ?? $profile['given_name'] ?? ''));
$userSub  = (string)($profile['sub'] ?? '');
li_log("User: $userName sub=$userSub");

// Save to DB
$admin_id   = (int)($_SESSION['admin_id']        ?? 0);
$company_id = (int)($_SESSION['oauth_company_id'] ?? $_SESSION['company_id'] ?? 0);
unset($_SESSION['oauth_company_id']);
if ($admin_id) {
    include __DIR__ . '/../dbconnect_hdb.php'; // provides $conn

    $expiry   = date('Y-m-d H:i:s', time() + $expiresIn);
    $now      = date('Y-m-d H:i:s');
    $tokenE   = mysqli_real_escape_string($conn, $accessToken);
    $nameE    = mysqli_real_escape_string($conn, substr($userName, 0, 200));
    $subE     = mysqli_real_escape_string($conn, substr($userSub, 0, 100));
    $expiryE  = mysqli_real_escape_string($conn, $expiry);
    $nowE     = mysqli_real_escape_string($conn, $now);

    mysqli_query($conn,
        "INSERT INTO hdb_oauth_tokens
             (company_id,admin_id,platform,access_token,channel_id,channel_name,token_expiry,created_at,updated_at)
         VALUES ($company_id,$admin_id,'linkedin','$tokenE','$subE','$nameE','$expiryE','$nowE','$nowE')
         ON DUPLICATE KEY UPDATE
             company_id=$company_id, access_token='$tokenE', channel_id='$subE', channel_name='$nameE',
             token_expiry='$expiryE', updated_at='$nowE'"
    );
    li_log("admin=$admin_id company=$company_id LinkedIn token saved to DB");
} else {
    li_log('Warning: no admin_id in session — DB save skipped');
}

$returnUrl = $_SESSION['li_oauth_return'] ?? '../vizard_scheduler.php?linkedin_connected=1';
unset($_SESSION['li_oauth_return']);

header('Location: ' . $returnUrl);
exit;
