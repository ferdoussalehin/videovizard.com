<?php
// ============================================
// VideoVizard — Facebook Login Callback
// Exchanges OAuth code for token, fetches FB profile,
// finds or creates the user in hdb_users, then signs them
// in and redirects to vizard_dashboard.php.
// ============================================
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/a_errors.log');

require_once __DIR__ . '/session_config.php';
include __DIR__ . '/dbconnect_hdb.php';

if ($conn->connect_error) {
    die('Database connection failed.');
}
$conn->set_charset('utf8mb4');

function fb_login_log(string $msg): void {
    file_put_contents(__DIR__ . '/a_error_logs.txt',
        '[' . date('Y-m-d H:i:s') . "] [fb_login] $msg" . PHP_EOL,
        FILE_APPEND | LOCK_EX);
}

function fb_login_fail(string $msg): void {
    fb_login_log('ERROR: ' . $msg);
    header('Location: login.php?fb_error=' . urlencode($msg));
    exit;
}

// ── Credentials (from meta/config.php) ───────────────────────────────────────
$cfg       = require __DIR__ . '/meta/config.php';
$appId     = (string)($cfg['meta']['app_id']     ?? '');
$appSecret = (string)($cfg['meta']['app_secret'] ?? '');
$redirectUri = 'https://videovizard.com/facebook_login_callback.php';
// print_r($_GET['code']); die;
// ── Determine flow: JS-SDK (access_token param) or server-side code flow ─────
$accessToken = (string)($_GET['access_token'] ?? '');

if (!$accessToken) {
    // Server-side OAuth code flow: validate CSRF state + exchange code for token.
    if (isset($_GET['error'])) {
        fb_login_fail((string)($_GET['error_description'] ?? $_GET['error']));
    }

    $state = (string)($_GET['state'] ?? '');
    if (!$state || !hash_equals((string)($_SESSION['fb_login_state'] ?? ''), $state)) {
        fb_login_fail('invalid_state');
    }
    unset($_SESSION['fb_login_state']);

    $code = (string)($_GET['code'] ?? '');
    if (!$code) {
        fb_login_fail('no_code');
    }

    // Exchange code → access token
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://graph.facebook.com/v18.0/oauth/access_token?' . http_build_query([
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode((string)$resp, true) ?: [];
    $accessToken = (string)($tokenData['access_token'] ?? '');
    if (!$accessToken) {
        fb_login_fail('token_exchange_failed: ' . ($tokenData['error']['message'] ?? 'unknown'));
    }
} else {
    fb_login_log('JS-SDK flow: access_token received directly');
}

// ── Fetch user profile (id, name, email) ─────────────────────────────────────
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://graph.facebook.com/v18.0/me?' . http_build_query([
        'fields'       => 'id,name,first_name,last_name,email',
        'access_token' => $accessToken,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
curl_close($ch);
$profile = json_decode((string)$resp, true) ?: [];

$fbId    = (string)($profile['id']    ?? '');
$fbEmail = (string)($profile['email'] ?? '');
$fbFirst = (string)($profile['first_name'] ?? '');
$fbLast  = (string)($profile['last_name']  ?? '');
$fbName  = (string)($profile['name']  ?? trim($fbFirst . ' ' . $fbLast));
// print_r($fbName); die;
if (!$fbId) {
    fb_login_fail('profile_fetch_failed');
}

// Facebook may not return an email if user opted out. Fall back to a synthetic
// address so we still have a unique key for the account.
if (!$fbEmail) {
    $fbEmail = 'fb_' . $fbId . '@facebook.local';
}

// ── Find existing user by email ──────────────────────────────────────────────
$emailE = mysqli_real_escape_string($conn, $fbEmail);
$res = $conn->query("SELECT * FROM hdb_users WHERE email_id = '$emailE' LIMIT 1");
$row = ($res && $res->num_rows) ? $res->fetch_assoc() : null;

// ── Create the user if they don't exist yet ──────────────────────────────────
if (!$row) {
    $firstE = mysqli_real_escape_string($conn, $fbFirst ?: $fbName ?: 'User');
    $lastE  = mysqli_real_escape_string($conn, $fbLast);
    $passE  = mysqli_real_escape_string($conn, 'fb_' . bin2hex(random_bytes(8)));
    $trialExpiry = date('Y-m-d H:i:s', strtotime('+14 days'));

    $conn->query('START TRANSACTION');
    try {
        $sql_user = "INSERT INTO hdb_users
            (user_name, firstname, lastname, level_name, email_id, password,
             plan_type, role, credit_balance, client_id, phone_number, country,
             schedule_flag, max_videos_allowed, trial_period_expiry_dt,
             team_lead_id, last_company_id, game_tokens, credits_used,
             video_count, created_at, updated_at)
            VALUES
            ('$emailE', '$firstE', '$lastE', 'user', '$emailE', '$passE',
             'free_trial', 'Team Lead', 30, 0, '', '',
             0, 10, '$trialExpiry',
             0, 0, 0, 0,
             0, NOW(), NOW())";
        if (!$conn->query($sql_user)) {
            throw new Exception('User insert failed: ' . $conn->error);
        }
        $userId = (int)$conn->insert_id;

        $companyName = mysqli_real_escape_string($conn, ($fbFirst ?: $fbName) . "'s Studio");
        $sql_comp = "INSERT INTO hdb_companies (companyname, admin_id, company_type, created_at)
                     VALUES ('$companyName', $userId, 'internal', NOW())";
        if (!$conn->query($sql_comp)) {
            throw new Exception('Company insert failed: ' . $conn->error);
        }
        $companyId = (int)$conn->insert_id;

        $conn->query("UPDATE hdb_users SET company_id=$companyId, admin_id=$userId WHERE id=$userId");
        $conn->query('COMMIT');

        fb_login_log("Created FB user id=$userId email=$fbEmail");

        $res = $conn->query("SELECT * FROM hdb_users WHERE id = $userId LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
    } catch (Exception $e) {
        $conn->query('ROLLBACK');
        fb_login_fail($e->getMessage());
    }
}

if (!$row) {
    fb_login_fail('user_lookup_failed');
}

// ── Sign user in (mirrors the password-login flow in login.php) ──────────────
session_regenerate_id(true);

$adminId = (int)$row['id'];
$compRes = $conn->query("SELECT id FROM hdb_companies WHERE admin_id=$adminId ORDER BY id ASC LIMIT 1");
$compRow = $compRes ? $compRes->fetch_assoc() : null;
$companyId = $compRow ? (int)$compRow['id'] : 0;

$_SESSION['admin_id']      = $adminId;
$_SESSION['company_id']    = $companyId;
$_SESSION['level']         = $row['level_name'] ?? 'user';
$_SESSION['client_id']     = $row['client_id']  ?? 0;
$_SESSION['user']          = $row['user_name']  ?? $fbEmail;
$_SESSION['forward_url']   = $row['forward_url'] ?? '';
$_SESSION['created_at']    = time();
$_SESSION['last_activity'] = time();

setcookie(
    session_name(),
    session_id(),
    [
        'expires'  => time() + (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 15552000),
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]
);

fb_login_log("Login OK admin_id=$adminId email=$fbEmail");

header('Location: vizard_dashboard.php');
exit;
