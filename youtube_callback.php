<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

require_once 'youtube_config.php';
include 'dbconnect_hdb.php';

session_set_cookie_params(15552000);
session_start();

// ── Get admin_id and company_id from state (format: "hex|admin_id|company_id") ──
$state_raw   = $_GET['state'] ?? '';
$state_parts = explode('|', $state_raw);
$state_hex   = $state_parts[0] ?? '';
$admin_id    = (int)($state_parts[1] ?? 0);
$company_id  = (int)($state_parts[2] ?? 0);

// If no admin_id in state, try session
if (!$admin_id) $admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$company_id) $company_id = (int)($_SESSION['company_id'] ?? 0);

// Log for debugging
error_log("YT Callback: state=$state_raw admin_id=$admin_id code=" . substr($_GET['code'] ?? '', 0, 20));

if (!$admin_id) {
    error_log("YT Callback: No admin_id found");
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ── Check for errors from Google ──
if (isset($_GET['error'])) {
    $err = htmlspecialchars($_GET['error']);
    error_log("YT Callback: Google error=$err");
    die("Google returned error: $err. <a href='youtube_connect.php'>Try again</a>");
}

// ── Get authorization code ──
$code = $_GET['code'] ?? '';
if (!$code) {
    error_log("YT Callback: No code received");
    die('No authorization code. <a href="youtube_connect.php">Try again</a>');
}

// ── Exchange code for tokens ──
error_log("YT Callback: Exchanging code for token...");
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => YT_CLIENT_ID,
        'client_secret' => YT_CLIENT_SECRET,
        'redirect_uri'  => YT_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT    => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

error_log("YT Callback: Token exchange HTTP=$httpcode curl_err=$curl_err response=$response");

$token_data = json_decode($response, true);

if ($httpcode !== 200 || empty($token_data['access_token'])) {
    $err = $token_data['error_description'] ?? $token_data['error'] ?? 'Unknown';
    error_log("YT Callback: Token failed: $err");
    die("Token exchange failed: $err. <a href='youtube_connect.php'>Try again</a>");
}

$access_token  = $token_data['access_token'];
$refresh_token = $token_data['refresh_token'] ?? '';
$expires_in    = (int)($token_data['expires_in'] ?? 3600);
$token_expiry  = date('Y-m-d H:i:s', time() + $expires_in);

// ── Get channel info ──
error_log("YT Callback: Getting channel info...");
$ch2 = curl_init('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$ch_response  = curl_exec($ch2);
$ch_http      = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

error_log("YT Callback: Channel info HTTP=$ch_http response=$ch_response");

$ch_data      = json_decode($ch_response, true);
$channel_id   = $ch_data['items'][0]['id']               ?? '';
$channel_name = $ch_data['items'][0]['snippet']['title'] ?? 'My Channel';

// ── Save to hdb_oauth_tokens ──
$now           = date('Y-m-d H:i:s');
$esc_access    = mysqli_real_escape_string($conn, $access_token);
$esc_refresh   = mysqli_real_escape_string($conn, $refresh_token);
$esc_expiry    = mysqli_real_escape_string($conn, $token_expiry);
$esc_chan_id   = mysqli_real_escape_string($conn, $channel_id);
$esc_chan_name = mysqli_real_escape_string($conn, $channel_name);

$sql = "INSERT INTO hdb_oauth_tokens
    (company_id, admin_id, platform, access_token, refresh_token, token_expiry,
     channel_id, channel_name, created_at, updated_at)
VALUES
    ($company_id, $admin_id, 'youtube', '$esc_access', '$esc_refresh', '$esc_expiry',
     '$esc_chan_id', '$esc_chan_name', '$now', '$now')
ON DUPLICATE KEY UPDATE
    company_id    = $company_id,
    access_token  = '$esc_access',
    refresh_token = IF('$esc_refresh' != '', '$esc_refresh', refresh_token),
    token_expiry  = '$esc_expiry',
    channel_id    = '$esc_chan_id',
    channel_name  = '$esc_chan_name',
    updated_at    = '$now'";

$ok = mysqli_query($conn, $sql);
error_log("YT Callback: DB save ok=" . ($ok?'yes':'no: '.mysqli_error($conn)));

if (!$ok) {
    die('DB save failed: ' . mysqli_error($conn));
}

// ── Set session and redirect back to calling page ──
$_SESSION['admin_id'] = $admin_id;
$return_to = $_SESSION['yt_oauth_return'] ?? 'youtube_connect.php';
unset($_SESSION['yt_oauth_return']); // clear it
error_log("YT Callback: Success! Redirecting to $return_to");
header('Location: ' . $return_to . '?yt_connected=1');
exit;
?>