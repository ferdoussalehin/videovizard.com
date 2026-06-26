<?php
/**
 * tiktok_callback_vizard.php
 *
 * Receives ?code= from TikTok OAuth, exchanges code + PKCE verifier for
 * access token, fetches user display name, saves to hdb_oauth_tokens,
 * then redirects back to vizard_scheduler.php.
 *
 * NOTE: Add https://videovizard.com/tiktok_callback_vizard.php
 * as a valid redirect URI in your TikTok Developer App settings.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg          = require __DIR__ . '/meta/config.php';
$clientKey    = (string)($cfg['tiktok']['client_key']    ?? '');
$clientSecret = (string)($cfg['tiktok']['client_secret'] ?? '');
$redirectUri  = 'https://videovizard.com/tiktok_callback_vizard.php';

function tt_log(string $msg): void {
    file_put_contents(__DIR__ . '/a_errors.log',
        date('[Y-m-d H:i:s] ') . '[tiktok_oauth] ' . $msg . "\n", FILE_APPEND);
}

function tt_post(string $url, array $fields): array {
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

function tt_get(string $url, string $token): array {
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
    tt_log('ERROR: ' . $msg);
    header('Location: vizard_scheduler.php?tiktok_error=' . urlencode($msg));
    exit;
}

// TikTok error
if (isset($_GET['error'])) {
    bail($_GET['error_description'] ?? $_GET['error']);
}

// CSRF check
$state = $_GET['state'] ?? '';
if (!$state || $state !== ($_SESSION['tiktok_oauth_state'] ?? '')) {
    bail('invalid_state');
}
unset($_SESSION['tiktok_oauth_state']);

$code = $_GET['code'] ?? '';
if (!$code) {
    bail('no_code');
}

$codeVerifier = $_SESSION['tiktok_code_verifier'] ?? '';
if (!$codeVerifier) {
    bail('code_verifier_missing');
}
unset($_SESSION['tiktok_code_verifier']);

// Exchange code + PKCE verifier for access token
[, , $tokenData] = tt_post('https://open.tiktokapis.com/v2/oauth/token/', [
    'client_key'    => $clientKey,
    'client_secret' => $clientSecret,
    'code'          => $code,
    'grant_type'    => 'authorization_code',
    'redirect_uri'  => $redirectUri,
    'code_verifier' => $codeVerifier,
]);

$accessToken  = (string)($tokenData['access_token']  ?? '');
$refreshToken = (string)($tokenData['refresh_token'] ?? '');
$openId       = (string)($tokenData['open_id']       ?? '');
$expiresIn    = (int)($tokenData['expires_in']        ?? 86400);

if (!$accessToken) {
    tt_log('Token exchange failed. Response: ' . json_encode($tokenData));
    bail('token_exchange_failed');
}
tt_log("Got access token for open_id=$openId");

// Fetch display name
$displayName = '';
[, , $userResp] = tt_get(
    'https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name',
    $accessToken
);
if (!empty($userResp['data']['user']['display_name'])) {
    $displayName = (string)$userResp['data']['user']['display_name'];
}
tt_log("TikTok user: $displayName");

// Save to DB
$admin_id   = (int)($_SESSION['admin_id']        ?? 0);
$company_id = (int)($_SESSION['oauth_company_id'] ?? $_SESSION['company_id'] ?? 0);
unset($_SESSION['oauth_company_id']);
if ($admin_id) {
    include __DIR__ . '/dbconnect_hdb.php'; // provides $conn

    $expiry  = date('Y-m-d H:i:s', time() + $expiresIn);
    $now     = date('Y-m-d H:i:s');
    $tokenE  = mysqli_real_escape_string($conn, $accessToken);
    $refreshE = mysqli_real_escape_string($conn, $refreshToken);
    $openIdE = mysqli_real_escape_string($conn, substr($openId, 0, 100));
    $nameE   = mysqli_real_escape_string($conn, substr($displayName, 0, 200));
    $expiryE = mysqli_real_escape_string($conn, $expiry);
    $nowE    = mysqli_real_escape_string($conn, $now);

    mysqli_query($conn,
        "INSERT INTO hdb_oauth_tokens
             (company_id,admin_id,platform,access_token,refresh_token,channel_id,channel_name,token_expiry,created_at,updated_at)
         VALUES ($company_id,$admin_id,'tiktok','$tokenE','$refreshE','$openIdE','$nameE','$expiryE','$nowE','$nowE')
         ON DUPLICATE KEY UPDATE
             company_id=$company_id, access_token='$tokenE', refresh_token='$refreshE',
             channel_id='$openIdE', channel_name='$nameE', token_expiry='$expiryE', updated_at='$nowE'"
    );
    tt_log("admin=$admin_id company=$company_id TikTok token saved to DB");
} else {
    tt_log('Warning: no admin_id in session — DB save skipped');
}

$returnUrl = $_SESSION['tiktok_oauth_return'] ?? 'vizard_scheduler.php?tiktok_connected=1';
unset($_SESSION['tiktok_oauth_return']);

header('Location: ' . $returnUrl);
exit;
