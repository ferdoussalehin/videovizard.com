<?php
/**
 * fb_callback_vizard.php
 * Placed in: meta/fb_callback_vizard.php
 *
 * Receives ?code= from Facebook server-side OAuth,
 * exchanges code → short token → long token,
 * fetches pages + IG accounts, saves to hdb_oauth_tokens, 
 * redirects back to vizard_scheduler.php
 *
 * NOTE: Add https://videovizard.com/meta/fb_callback_vizard.php
 * as a Valid OAuth Redirect URI in your Meta App Dashboard.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg       = require __DIR__ . '/config.php';
$appId     = (string)($cfg['meta']['app_id']     ?? '');
$appSecret = (string)($cfg['meta']['app_secret'] ?? '');

$redirectUri = 'https://187.124.249.46/videovizard.com/meta/fb_callback_vizard.php';

// ── Logging ───────────────────────────────────────────────────────────────────
function fb_log(string $msg): void {
    file_put_contents(__DIR__ . '/../a_errors.log',
        date('[Y-m-d H:i:s] ') . '[fb_oauth] ' . $msg . "\n", FILE_APPEND);
}

// ── cURL helper ───────────────────────────────────────────────────────────────
function fb_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return [$code, (string)$resp, is_array($data) ? $data : []];
}

function fb_post(string $url, array $fields): array {
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

// ── Error helper ──────────────────────────────────────────────────────────────
function bail(string $msg): void {
    fb_log('ERROR: ' . $msg);
    $url = '../vizard_scheduler.php?fb_error=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────────
if (isset($_GET['error'])) {
    bail($_GET['error_description'] ?? $_GET['error']);
}

$state = $_GET['state'] ?? '';
if (!$state || $state !== ($_SESSION['fb_oauth_state'] ?? '')) {
    bail('invalid_state');
}
unset($_SESSION['fb_oauth_state']);

$code = $_GET['code'] ?? '';
if (!$code) {
    bail('no_code');
}

// ── Step 1: Exchange code → short-lived token ─────────────────────────────────
[, , $tokenData] = fb_post('https://graph.facebook.com/v24.0/oauth/access_token', [
    'client_id'     => $appId,
    'client_secret' => $appSecret,
    'redirect_uri'  => $redirectUri,
    'code'          => $code,
]);

$shortToken = (string)($tokenData['access_token'] ?? '');
if (!$shortToken) {
    bail('code_exchange_failed');
}
fb_log('Got short-lived token');

// ── Step 2: Exchange short → long-lived token ─────────────────────────────────
[, , $longData] = fb_get(
    'https://graph.facebook.com/v24.0/oauth/access_token?' . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $appId,
        'client_secret'     => $appSecret,
        'fb_exchange_token' => $shortToken,
    ])
);

$longToken = (string)($longData['access_token'] ?? $shortToken);
fb_log('Got long-lived token');

// ── Step 3: Fetch pages ───────────────────────────────────────────────────────
[, , $accData] = fb_get(
    'https://graph.facebook.com/v24.0/me/accounts?' . http_build_query([
        'fields'       => 'id,name,access_token',
        'access_token' => $longToken,
    ])
);

$pages = $accData['data'] ?? [];
if (empty($pages)) {
    bail('no_pages_found');
}
fb_log('Found ' . count($pages) . ' page(s)');

// ── Step 4: Save tokens.json (keeps original tool working too) ────────────────
$pagesOut = [];
foreach ($pages as $p) {
    if (empty($p['id']) || empty($p['access_token'])) continue;
    $pagesOut[] = [
        'id'           => $p['id'],
        'name'         => $p['name'] ?? '',
        'access_token' => $p['access_token'],
        'picture'      => null,
    ];
}

$defaultPageId = !empty($pagesOut) ? $pagesOut[0]['id'] : null;

file_put_contents(__DIR__ . '/tokens.json', json_encode([
    'fb_user'          => ['id' => '', 'name' => '', 'picture' => null],
    'pages'            => $pagesOut,
    'selected_page_id' => $defaultPageId,
    'updated_at'       => date('Y-m-d H:i:s'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ── Step 5: Save to DB ────────────────────────────────────────────────────────
$admin_id   = (int)($_SESSION['admin_id']        ?? 0);
$company_id = (int)($_SESSION['oauth_company_id'] ?? 0);
unset($_SESSION['oauth_company_id']);
if ($admin_id) {
    $dbFile = __DIR__ . '/../dbconnect_hdb.php';
    if (file_exists($dbFile)) {
        include $dbFile; // provides $conn

        $expiry = date('Y-m-d H:i:s', strtotime('+60 days'));

        // Wipe every stale Facebook page row for this admin before writing
        // fresh ones — prevents old rows with wrong token type from surviving.
        // Instagram rows are managed separately by ig_callback_vizard.php.
        mysqli_query($conn, "DELETE FROM hdb_oauth_tokens WHERE admin_id=$admin_id AND company_id=$company_id AND platform LIKE 'facebook%'");
        fb_log("Cleared old facebook tokens for admin=$admin_id");

        foreach ($pagesOut as $page) {
            $pageId    = mysqli_real_escape_string($conn, substr($page['id'],   0, 100));
            $pageName  = mysqli_real_escape_string($conn, substr($page['name'], 0, 200));
            $pageToken = mysqli_real_escape_string($conn, $page['access_token']);
            $exp       = mysqli_real_escape_string($conn, $expiry);
            $now       = date('Y-m-d H:i:s');
            $nowE      = mysqli_real_escape_string($conn, $now);

            // Facebook page row — platform stored as 'facebook_page_{id}' so the
            // posting code (which filters LIKE 'facebook_page_%') finds the page token.
            $fbPlatE = mysqli_real_escape_string($conn, 'facebook_page_' . substr($page['id'], 0, 50));
            mysqli_query($conn,
                "INSERT INTO hdb_oauth_tokens
                     (company_id,admin_id,platform,access_token,channel_id,channel_name,token_expiry,created_at,updated_at)
                 VALUES ($company_id,$admin_id,'$fbPlatE','$pageToken','$pageId','$pageName','$exp','$nowE','$nowE')
                 ON DUPLICATE KEY UPDATE
                     company_id=$company_id, access_token='$pageToken', channel_name='$pageName',
                     token_expiry='$exp', updated_at='$nowE'"
            );
        }
        fb_log("admin=$admin_id company=$company_id tokens saved to DB");
    }
} else {
    fb_log('Warning: no admin_id in session — tokens.json saved but DB skipped');
}

// ── Step 6: Redirect back to scheduler ───────────────────────────────────────
$returnUrl = $_SESSION['fb_oauth_return'] ?? '../vizard_scheduler.php?fb_connected=1';
unset($_SESSION['fb_oauth_return']);

header('Location: ' . $returnUrl);
exit;
