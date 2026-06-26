<?php

session_set_cookie_params(15552000);
session_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);
 
require_once 'config.php';
include 'dbconnect_hdb.php';



// ── Get admin_id and company_id from state (format: "hex|admin_id|company_id") ──
$state_raw   = $_GET['state'] ?? '';
$state_parts = explode('|', $state_raw);
$state_hex   = $state_parts[0] ?? '';
$admin_id    = (int)($state_parts[1] ?? 0);
$company_id  = (int)($state_parts[2] ?? 0);

// If no admin_id in state, try session
if (!$admin_id) $admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$company_id) $company_id = (int)($_SESSION['company_id'] ?? 0);

error_log("FB Callback: state=$state_raw admin_id=$admin_id code=" . substr($_GET['code'] ?? '', 0, 20));

if (!$admin_id) {
    error_log("FB Callback: No admin_id found");
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ── Check for errors from Facebook ──
if (isset($_GET['error'])) {
    $err = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    error_log("FB Callback: Facebook error=$err");
    die("Facebook returned error: $err. <a href='facebook_connect.php'>Try again</a>");
}

// ── Get authorization code ──
$code = $_GET['code'] ?? '';
if (!$code) {
    error_log("FB Callback: No code received");
    die('No authorization code. <a href="facebook_connect.php">Try again</a>');
}

// ── Exchange code for short-lived token ──
error_log("FB Callback: Exchanging code for token...");
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://graph.facebook.com/v18.0/oauth/access_token?"
                            . "client_id=" . FB_APP_ID
                            . "&redirect_uri=" . urlencode(FB_REDIRECT_URI)
                            . "&client_secret=" . FB_APP_SECRET
                            . "&code=" . $code,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

error_log("FB Callback: Token exchange HTTP=$httpcode curl_err=$curl_err response=$response");

$token_data = json_decode($response, true);

if ($httpcode !== 200 || empty($token_data['access_token'])) {
    $err = $token_data['error']['message'] ?? 'Unknown error';
    error_log("FB Callback: Token failed: $err");
    die("Token exchange failed: $err. <a href='facebook_connect.php'>Try again</a>");
}

$short_token = $token_data['access_token'];

// ── Exchange for long-lived token (60 days) ──
error_log("FB Callback: Getting long-lived token...");
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://graph.facebook.com/v18.0/oauth/access_token?"
                            . "grant_type=fb_exchange_token"
                            . "&client_id=" . FB_APP_ID
                            . "&client_secret=" . FB_APP_SECRET
                            . "&fb_exchange_token=" . $short_token,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("FB Callback: Long-lived token HTTP=$httpcode response=$response");

$long_data   = json_decode($response, true);
$long_token  = $long_data['access_token'];
$expires_in  = (int)($long_data['expires_in'] ?? 5184000); // 60 days
$token_expiry = date('Y-m-d H:i:s', time() + $expires_in);

// ── Get user's Facebook Pages ──
error_log("FB Callback: Getting Facebook Pages...");
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://graph.facebook.com/v18.0/me/accounts?"
                            . "access_token=" . $long_token,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$pages_response = curl_exec($ch);
$pages_http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("FB Callback: Pages HTTP=$pages_http response=$pages_response");

$pages_data  = json_decode($pages_response, true);
$pages       = $pages_data['data'] ?? [];
$page_id     = $pages[0]['id']    ?? '';
$page_name   = $pages[0]['name']  ?? 'My Page';
$page_token  = $pages[0]['access_token'] ?? $long_token;

// ── Save to hdb_oauth_tokens (same table as YouTube!) ──
$now              = date('Y-m-d H:i:s');
$esc_token        = mysqli_real_escape_string($conn, $long_token);
$esc_page_token   = mysqli_real_escape_string($conn, $page_token);
$esc_expiry       = mysqli_real_escape_string($conn, $token_expiry);
$esc_page_id      = mysqli_real_escape_string($conn, $page_id);
$esc_page_name    = mysqli_real_escape_string($conn, $page_name);

$sql = "INSERT INTO hdb_oauth_tokens
    (company_id, admin_id, platform, access_token, refresh_token, token_expiry,
     channel_id, channel_name, created_at, updated_at)
VALUES
    ($company_id, $admin_id, 'facebook', '$esc_token', '$esc_page_token', '$esc_expiry',
     '$esc_page_id', '$esc_page_name', '$now', '$now')
ON DUPLICATE KEY UPDATE
    company_id    = $company_id,
    access_token  = '$esc_token',
    refresh_token = '$esc_page_token',
    token_expiry  = '$esc_expiry',
    channel_id    = '$esc_page_id',
    channel_name  = '$esc_page_name',
    updated_at    = '$now'";

$ok = mysqli_query($conn, $sql);
error_log("FB Callback: DB save ok=" . ($ok ? 'yes' : 'no: ' . mysqli_error($conn)));

if (!$ok) {
    die('DB save failed: ' . mysqli_error($conn));
}

// ── Set session and redirect ──
$_SESSION['admin_id'] = $admin_id;
error_log("FB Callback: Success! Redirecting to facebook_connect.php");
header('Location: facebook_connect.php?connected=1');
exit;
?>