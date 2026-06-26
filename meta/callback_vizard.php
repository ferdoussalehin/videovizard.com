<?php
/**
 * callback_vizard.php  (schema-corrected v2)
 * Placed in: meta/callback_vizard.php
 *
 * Reads tokens.json (written by callback.php/callback_patched.php)
 * → saves rows to hdb_oauth_tokens → redirects back to vizard_scheduler.php
 *
 * Your actual table schema:
 *   id            int(11)        AUTO_INCREMENT
 *   admin_id      int(11)
 *   platform      varchar(30)    ← keep values short: 'facebook', 'instagram'
 *   access_token  text
 *   refresh_token text
 *   token_expiry  varchar(30)    ← stored as 'Y-m-d H:i:s' string
 *   channel_id    varchar(100)   ← page_id or ig_business_id
 *   channel_name  varchar(200)
 *   created_at    varchar(30)
 *   updated_at    varchar(30)
 *
 * IMPORTANT: platform is only varchar(30) so we use 'facebook' (not
 * 'facebook_page_123456' which can exceed the limit). Pages are
 * distinguished by channel_id, which holds the page_id.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

// ── Logging (goes into your existing a_errors.log) ────────────────────────────
function cv_log(string $msg): void {
    $logFile = __DIR__ . '/../a_errors.log';
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . '[fb_oauth] ' . $msg . "\n", FILE_APPEND);
}

// ── Load tokens.json ──────────────────────────────────────────────────────────
$tokensPath = __DIR__ . '/tokens.json';
if (!file_exists($tokensPath)) {
    cv_log('tokens.json not found at ' . $tokensPath);
    header('Location: ../vizard_scheduler.php?fb_error=no_tokens');
    exit;
}

$tokens = json_decode((string)file_get_contents($tokensPath), true);
if (!is_array($tokens) || empty($tokens['pages'])) {
    cv_log('tokens.json invalid or no pages: ' . json_encode($tokens));
    header('Location: ../vizard_scheduler.php?fb_error=invalid_tokens');
    exit;
}

// ── Load DB ───────────────────────────────────────────────────────────────────
$dbFile = __DIR__ . '/../dbconnect_hdb.php';
if (!file_exists($dbFile)) {
    cv_log('dbconnect_hdb.php not found');
    header('Location: ../vizard_scheduler.php?fb_connected=1&fb_warning=no_db');
    exit;
}
include $dbFile; // provides $conn (mysqli)

// ── admin_id from session ─────────────────────────────────────────────────────
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (!$admin_id) {
    cv_log('No admin_id in session');
    header('Location: ../login.php');
    exit;
}

// ── Upsert — matches your exact column types/lengths ─────────────────────────
function upsert_token(
    mysqli $conn,
    int    $admin_id,
    string $platform,       // varchar(30) — use 'facebook' or 'instagram'
    string $access_token,   // text — full token
    string $channel_id,     // varchar(100) — page_id or ig_business_id
    string $channel_name,   // varchar(200)
    string $expiry          // varchar(30) — 'Y-m-d H:i:s'
): bool {
    $now = date('Y-m-d H:i:s');

    // Enforce column length limits to prevent silent truncation
    $p   = mysqli_real_escape_string($conn, substr($platform,     0, 30));
    $at  = mysqli_real_escape_string($conn, $access_token);            // text — no limit
    $ci  = mysqli_real_escape_string($conn, substr($channel_id,   0, 100));
    $cn  = mysqli_real_escape_string($conn, substr($channel_name, 0, 200));
    $ex  = mysqli_real_escape_string($conn, substr($expiry,       0, 30));
    $ne  = mysqli_real_escape_string($conn, $now);

    // Try UPDATE first (row already exists for this admin+platform+channel)
    mysqli_query($conn,
        "UPDATE hdb_oauth_tokens
         SET access_token  = '$at',
             channel_name  = '$cn',
             token_expiry  = '$ex',
             updated_at    = '$ne'
         WHERE admin_id  = $admin_id
           AND platform  = '$p'
           AND channel_id = '$ci'"
    );

    if (mysqli_affected_rows($conn) > 0) {
        return true; // row updated
    }

    // INSERT new row
    $ok = mysqli_query($conn,
        "INSERT INTO hdb_oauth_tokens
             (admin_id, platform, access_token, refresh_token,
              channel_id, channel_name, token_expiry, created_at, updated_at)
         VALUES
             ($admin_id, '$p', '$at', NULL,
              '$ci', '$cn', '$ex', '$ne', '$ne')"
    );

    if (!$ok) {
        cv_log("INSERT failed admin=$admin_id platform=$p channel_id=$ci : " . mysqli_error($conn));
    }
    return (bool)$ok;
}

// ── Facebook long-lived token expiry: 60 days ────────────────────────────────
$expiry_60d = date('Y-m-d H:i:s', strtotime('+60 days'));

// ── Iterate pages and save tokens ─────────────────────────────────────────────
$saved = $errors = [];

foreach ($tokens['pages'] as $page) {
    $page_id    = (string)($page['id'] ?? '');
    $page_name  = (string)($page['name'] ?? '');
    $page_token = (string)($page['access_token'] ?? '');

    if (!$page_id || !$page_token) continue;

    // Facebook: platform='facebook', channel_id=page_id
    // One row per page — the unique key is (admin_id, platform, channel_id)
    if (upsert_token($conn, $admin_id, 'facebook', $page_token, $page_id, $page_name, $expiry_60d)) {
        $saved[] = "FB page '$page_name' (id $page_id)";
    } else {
        $errors[] = "FB page '$page_name'";
    }

    // Instagram Business linked to this page
    $ig_id = (string)($page['ig_business_id'] ?? '');
    if ($ig_id) {
        // The page-level token is also used for instagram_content_publish API calls
        $ig_name = $page_name . ' (IG)';
        if (upsert_token($conn, $admin_id, 'instagram', $page_token, $ig_id, $ig_name, $expiry_60d)) {
            $saved[] = "IG via '$page_name' (ig_id $ig_id)";
        } else {
            $errors[] = "IG via '$page_name'";
        }
    }
}

cv_log(
    "admin=$admin_id | saved=[" . implode(', ', $saved) . ']'
    . (!empty($errors) ? ' | errors=[' . implode(', ', $errors) . ']' : '')
);

// ── Redirect back to scheduler ────────────────────────────────────────────────
$return_url = $_SESSION['fb_oauth_return'] ?? '../vizard_scheduler.php?fb_connected=1';
unset($_SESSION['fb_oauth_return']);

header('Location: ' . $return_url);
exit;
