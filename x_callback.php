<?php
/**
 * x_callback.php
 *
 * Receives ?code= from Twitter/X OAuth 2.0, exchanges code + PKCE verifier
 * for access token, fetches Twitter user info, saves to hdb_oauth_tokens,
 * then redirects back to vizard_scheduler.php.
 *
 * NOTE: Register https://videovizard.com/x_callback.php
 * as a valid callback URI in your Twitter/X Developer App settings.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg          = require __DIR__ . '/meta/config.php';
$clientId     = (string)($cfg['twitter']['client_id']     ?? '');
$clientSecret = (string)($cfg['twitter']['client_secret'] ?? '');
$redirectUri  = 'https://videovizard.com/x_callback.php';

function x_log(string $msg): void {
    file_put_contents(__DIR__ . '/a_errors.log',
        date('[Y-m-d H:i:s] ') . '[x_oauth] ' . $msg . "\n", FILE_APPEND);
}

function x_post_basic_auth(string $url, string $clientId, string $clientSecret, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return [$code, (string)$resp, is_array($data) ? $data : []];
}

function x_get(string $url, string $token): array {
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
    x_log('ERROR: ' . $msg);
    header('Location: vizard_scheduler.php?x_error=' . urlencode($msg));
    exit;
}

// Twitter error
if (isset($_GET['error'])) {
    bail($_GET['error_description'] ?? $_GET['error']);
}

// CSRF check
$state = $_GET['state'] ?? '';
if (!$state || $state !== ($_SESSION['x_oauth_state'] ?? '')) {
    bail('invalid_state');
}
unset($_SESSION['x_oauth_state']);

$code = $_GET['code'] ?? '';
if (!$code) {
    bail('no_code');
}

$codeVerifier = $_SESSION['x_code_verifier'] ?? '';
if (!$codeVerifier) {
    bail('code_verifier_missing');
}
unset($_SESSION['x_code_verifier']);

// Exchange code + PKCE verifier for access token
[, , $tokenData] = x_post_basic_auth(
    'https://api.twitter.com/2/oauth2/token',
    $clientId,
    $clientSecret,
    [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'code_verifier' => $codeVerifier,
    ]
);

$accessToken  = (string)($tokenData['access_token']  ?? '');
$refreshToken = (string)($tokenData['refresh_token'] ?? '');
$expiresIn    = (int)($tokenData['expires_in']        ?? 7200);

if (!$accessToken) {
    x_log('Token exchange failed. Response: ' . json_encode($tokenData));
    bail('token_exchange_failed');
}
x_log('Got access token');

// Fetch Twitter user info
$twitterUserId = '';
$twitterHandle = '';
[, , $userResp] = x_get('https://api.twitter.com/2/users/me', $accessToken);
if (!empty($userResp['data']['id'])) {
    $twitterUserId = (string)$userResp['data']['id'];
    $twitterHandle = (string)($userResp['data']['username'] ?? '');
}
x_log("Twitter user: @$twitterHandle (id=$twitterUserId)");

// Save to DB
$admin_id   = (int)($_SESSION['admin_id']        ?? 0);
$company_id = (int)($_SESSION['oauth_company_id'] ?? $_SESSION['company_id'] ?? 0);
unset($_SESSION['oauth_company_id']);
if ($admin_id) {
    include __DIR__ . '/dbconnect_hdb.php'; // provides $conn

    $expiry   = date('Y-m-d H:i:s', time() + $expiresIn);
    $now      = date('Y-m-d H:i:s');
    $tokenE   = mysqli_real_escape_string($conn, $accessToken);
    $refreshE = mysqli_real_escape_string($conn, $refreshToken);
    $userIdE  = mysqli_real_escape_string($conn, substr($twitterUserId, 0, 100));
    $handleE  = mysqli_real_escape_string($conn, substr('@' . $twitterHandle, 0, 200));
    $expiryE  = mysqli_real_escape_string($conn, $expiry);
    $nowE     = mysqli_real_escape_string($conn, $now);

    mysqli_query($conn,
        "INSERT INTO hdb_oauth_tokens
             (company_id,admin_id,platform,access_token,refresh_token,channel_id,channel_name,token_expiry,created_at,updated_at)
         VALUES ($company_id,$admin_id,'twitter','$tokenE','$refreshE','$userIdE','$handleE','$expiryE','$nowE','$nowE')
         ON DUPLICATE KEY UPDATE
             company_id='$company_id', access_token='$tokenE', refresh_token='$refreshE',
             channel_id='$userIdE', channel_name='$handleE', token_expiry='$expiryE', updated_at='$nowE'"
    );
    x_log("admin=$admin_id company=$company_id Twitter token saved to DB");
} else {
    x_log('Warning: no admin_id in session — DB save skipped');
}

$returnUrl = $_SESSION['x_oauth_return'] ?? 'vizard_scheduler.php?x_connected=1';
unset($_SESSION['x_oauth_return']);

header('Location: ' . $returnUrl);
exit;
