<?php
// tiktok_callback.php
// TikTok redirects here after user grants permission
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);


ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);
session_start();

require_once 'tiktok_config.php';
include 'dbconnect_hdb.php';

// ── Get admin_id and company_id from state (format: "hex|admin_id|company_id") ──
$state_raw   = $_GET['state'] ?? '';
$state_parts = explode('|', $state_raw);
$state_hex   = $state_parts[0] ?? '';
$admin_id    = (int)($state_parts[1] ?? 0);
$company_id  = (int)($state_parts[2] ?? 0);

// Fallback to session
if (!$admin_id) $admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$company_id) $company_id = (int)($_SESSION['company_id'] ?? 0);

error_log("TikTok Callback: state=$state_raw admin_id=$admin_id");

if (!$admin_id) {
    error_log("TikTok Callback: No admin_id");
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ── Check for errors ──
if (isset($_GET['error'])) {
    $err = htmlspecialchars($_GET['error']);
    $desc = htmlspecialchars($_GET['error_description'] ?? '');
    error_log("TikTok Callback: Error=$err desc=$desc");
    die("TikTok returned error: $err — $desc <br><a href='tiktok_connect.php'>Try again</a>");
}

// ── Get authorization code ──
$code = $_GET['code'] ?? '';
if (!$code) {
    error_log("TikTok Callback: No code");
    die('No authorization code. <a href="tiktok_connect.php">Try again</a>');
}

// ── Exchange code for tokens ──
error_log("TikTok Callback: Exchanging code for token...");

$ch = curl_init('https://open.tiktokapis.com/v2/oauth/token/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_key'    => TT_CLIENT_KEY,
        'client_secret' => TT_CLIENT_SECRET,
        'code'          => $code,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => TT_REDIRECT_URI,
    ]),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        'Cache-Control: no-cache',
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

error_log("TikTok Callback: Token exchange HTTP=$httpcode curl_err=$curl_err response=$response");

$token_data = json_decode($response, true);

if ($httpcode !== 200 || empty($token_data['access_token'])) {
    $err = $token_data['error_description'] ?? $token_data['message'] ?? 'Unknown error';
    error_log("TikTok Callback: Token failed: $err");
    die("Token exchange failed: $err <br><a href='tiktok_connect.php'>Try again</a>");
}

$access_token  = $token_data['access_token'];
$refresh_token = $token_data['refresh_token']        ?? '';
$expires_in    = (int)($token_data['expires_in']     ?? 86400);
$token_expiry  = date('Y-m-d H:i:s', time() + $expires_in);
$open_id       = $token_data['open_id']              ?? '';

// ── Get user profile info ──
error_log("TikTok Callback: Getting user info...");

$ch2 = curl_init('https://open.tiktokapis.com/v2/user/info/?fields=open_id,union_id,avatar_url,display_name');
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$user_response = curl_exec($ch2);
$user_http     = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

error_log("TikTok Callback: User info HTTP=$user_http response=$user_response");

$user_data    = json_decode($user_response, true);
$display_name = $user_data['data']['user']['display_name'] ?? 'TikTok User';
$channel_id   = $user_data['data']['user']['open_id']      ?? $open_id;

// ── Save to hdb_oauth_tokens ──
$now           = date('Y-m-d H:i:s');
$esc_access    = mysqli_real_escape_string($conn, $access_token);
$esc_refresh   = mysqli_real_escape_string($conn, $refresh_token);
$esc_expiry    = mysqli_real_escape_string($conn, $token_expiry);
$esc_chan_id   = mysqli_real_escape_string($conn, $channel_id);
$esc_chan_name = mysqli_real_escape_string($conn, $display_name);

$sql = "INSERT INTO hdb_oauth_tokens
    (company_id, admin_id, platform, access_token, refresh_token, token_expiry,
     channel_id, channel_name, created_at, updated_at)
VALUES
    ($company_id, $admin_id, 'tiktok', '$esc_access', '$esc_refresh', '$esc_expiry',
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
error_log("TikTok Callback: DB save ok=" . ($ok ? 'yes' : 'no: ' . mysqli_error($conn)));

if (!$ok) {
    die('DB save failed: ' . mysqli_error($conn));
}

// ── Set session and redirect ──
$_SESSION['admin_id'] = $admin_id;
error_log("TikTok Callback: Success! Redirecting...");
header('Location: tiktok_connect.php?connected=1');
exit;
?>
