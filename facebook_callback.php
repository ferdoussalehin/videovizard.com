<?php
/**
 * facebook_callback.php  ← THE ONLY callback file (delete facebook-callback.php)
 *
 * Fixed vs old version:
 *  - session_start() called FIRST, before any require/include
 *  - CSRF state verification added
 *  - Graph API v18.0 → v21.0
 *  - All pages stored (not just index 0) — page selector can show them
 *  - pages_manage_engagement scope accepted
 *  - Clean error pages instead of bare die()
 *
 * Register this exact URL in Facebook App → Valid OAuth Redirect URIs:
 *   https://videovizard.com/facebook_callback.php
 */

// ── Session MUST come before any output or require ──────────────────
session_set_cookie_params(15552000);
session_start();

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

require_once 'config.php';
include   'dbconnect_hdb.php';

// ── Recover admin_id and company_id from state (format: "hex|admin_id|company_id") ────────────
$state_raw   = $_GET['state'] ?? '';
$state_parts = explode('|', $state_raw);
$state_hex   = $state_parts[0] ?? '';
$admin_id    = (int)($state_parts[1] ?? 0);
$company_id  = (int)($state_parts[2] ?? 0);

if (!$admin_id) $admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$company_id) $company_id = (int)($_SESSION['company_id'] ?? 0);

error_log("FB Callback: state=$state_raw admin_id=$admin_id");

if (!$admin_id) {
    error_log("FB Callback: No admin_id — redirecting to login");
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ── CSRF: verify state matches what we stored ────────────────────────
$stored_state = $_SESSION['fb_oauth_state'] ?? '';
if ($stored_state && !hash_equals($stored_state, $state_raw)) {
    error_log("FB Callback: State mismatch stored=$stored_state got=$state_raw");
    fb_error("State mismatch — possible CSRF attempt. Please try again.");
}
unset($_SESSION['fb_oauth_state']);

// ── Facebook error response ──────────────────────────────────────────
if (isset($_GET['error'])) {
    $err = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    error_log("FB Callback: Facebook error: $err");
    fb_error("Facebook returned an error: $err");
}

// ── Authorization code ───────────────────────────────────────────────
$code = $_GET['code'] ?? '';
if (!$code) fb_error("No authorization code received from Facebook.");

// ── Exchange code → short-lived token ───────────────────────────────
error_log("FB Callback: Exchanging code for token...");
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://graph.facebook.com/v21.0/oauth/access_token?"
                            . http_build_query([
                                'client_id'     => FB_APP_ID,
                                'redirect_uri'  => FB_REDIRECT_URI,
                                'client_secret' => FB_APP_SECRET,
                                'code'          => $code,
                              ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("FB Callback: Short token HTTP=$http response=$response");
$token_data = json_decode($response, true);

if ($http !== 200 || empty($token_data['access_token'])) {
    fb_error("Token exchange failed: " . ($token_data['error']['message'] ?? 'Unknown error'));
}
$short_token = $token_data['access_token'];

// ── Exchange → long-lived token (60 days) ───────────────────────────
error_log("FB Callback: Getting long-lived token...");
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://graph.facebook.com/v21.0/oauth/access_token?"
                            . http_build_query([
                                'grant_type'        => 'fb_exchange_token',
                                'client_id'         => FB_APP_ID,
                                'client_secret'     => FB_APP_SECRET,
                                'fb_exchange_token' => $short_token,
                              ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("FB Callback: Long token HTTP=$http response=$response");
$long_data    = json_decode($response, true);
$long_token   = $long_data['access_token'] ?? '';
$expires_in   = (int)($long_data['expires_in'] ?? 5184000);
$token_expiry = date('Y-m-d H:i:s', time() + $expires_in);

if (!$long_token) {
    fb_error("Long-lived token exchange failed: " . ($long_data['error']['message'] ?? 'Unknown error'));
}

// ── Get all Facebook Pages this user manages ─────────────────────────
error_log("FB Callback: Fetching Pages...");
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://graph.facebook.com/v21.0/me/accounts?access_token=" . urlencode($long_token),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$pages_resp = curl_exec($ch);
$pages_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("FB Callback: Pages HTTP=$pages_http response=$pages_resp");
$pages_data = json_decode($pages_resp, true);
$pages      = $pages_data['data'] ?? [];

if (empty($pages)) {
    fb_error("No Facebook Pages found for this account. You need at least one Page (not a personal profile) to post videos.");
}

// ── Save FIRST page to hdb_oauth_tokens (primary page) ──────────────
// All pages are also stored in session for UI display / future use
$page        = $pages[0];
$page_id     = $page['id'];
$page_name   = $page['name'];
$page_token  = $page['access_token'];

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
if (!$ok) {
    error_log("FB Callback: DB save failed: " . mysqli_error($conn));
    fb_error("Database save failed: " . mysqli_error($conn));
}

error_log("FB Callback: DB saved OK. page=$page_name ($page_id) expires=$token_expiry");

// Keep all pages + tokens in session for page selector in connect UI
$_SESSION['admin_id']         = $admin_id;
$_SESSION['fb_pages']         = $pages;       // full array with all page tokens
$_SESSION['fb_token_expiry']  = $token_expiry;

// ── Done ─────────────────────────────────────────────────────────────
error_log("FB Callback: Success! Redirecting...");
header('Location: facebook_connected.php?connected=1');
exit;

// ── Helper ───────────────────────────────────────────────────────────
function fb_error(string $msg): void {
    error_log("FB Callback ERROR: $msg");
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:40px'>";
    echo "<h2 style='color:#c0392b'>Facebook Connection Error</h2>";
    echo "<p>" . htmlspecialchars($msg) . "</p>";
    echo "<p style='margin-top:20px'><a href='facebook_connected.php'>← Try again</a></p>";
    echo "</body></html>";
    exit;
}
