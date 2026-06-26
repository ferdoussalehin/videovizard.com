<?php
/**
 * ig_callback_vizard.php
 * Placed in: meta/ig_callback_vizard.php
 *
 * Receives ?code= from Instagram Login OAuth, exchanges code → short token,
 * upgrades to long-lived (60d) token, fetches IG user info, saves to
 * hdb_oauth_tokens (platform='instagram'), redirects back to vizard_scheduler.php.
 *
 * Endpoints used (Instagram API with Instagram Login, separate from Facebook Login):
 *   - api.instagram.com/oauth/access_token        (short-lived, 1h)
 *   - graph.instagram.com/access_token            (long-lived, 60d)
 *   - graph.instagram.com/v22.0/me                (user_id, username)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 15552000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$cfg        = require __DIR__ . '/config.php';
$igAppId    = (string)($cfg['meta']['instagram_app_id'] ?? '');
$igSecret   = (string)($cfg['meta']['instagram_secret'] ?? '');
$redirectUri = 'https://videovizard.com/meta/ig_callback_vizard.php';

// ── Logging ───────────────────────────────────────────────────────────────────
function ig_log(string $msg): void {
    file_put_contents(__DIR__ . '/../a_errors.log',
        date('[Y-m-d H:i:s] ') . '[ig_oauth] ' . $msg . "\n", FILE_APPEND);
}

// ── cURL helpers ──────────────────────────────────────────────────────────────
function ig_get(string $url): array {
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

function ig_post(string $url, array $fields): array {
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
function ig_bail(string $msg): void {
    ig_log('ERROR: ' . $msg);
    $url = '../vizard_scheduler.php?ig_error=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────────
if (isset($_GET['error'])) {
    ig_bail($_GET['error_description'] ?? $_GET['error']);
}

$state = $_GET['state'] ?? '';
if (!$state || $state !== ($_SESSION['ig_oauth_state'] ?? '')) {
    ig_bail('invalid_state');
}
unset($_SESSION['ig_oauth_state']);

$code = $_GET['code'] ?? '';
if (!$code) {
    ig_bail('no_code');
}

// ── Step 1: Exchange code → short-lived token (1h) ────────────────────────────
[, , $tokenData] = ig_post('https://api.instagram.com/oauth/access_token', [
    'client_id'     => $igAppId,
    'client_secret' => $igSecret,
    'grant_type'    => 'authorization_code',
    'redirect_uri'  => $redirectUri,
    'code'          => $code,
]);

$shortToken = (string)($tokenData['access_token'] ?? '');
$igUserId   = (string)($tokenData['user_id']      ?? '');
if (!$shortToken || !$igUserId) {
    ig_bail('code_exchange_failed');
}
ig_log('Got short-lived token, ig_user_id=' . $igUserId);

// ── Step 2: Exchange short → long-lived token (60d) ───────────────────────────
[, , $longData] = ig_get(
    'https://graph.instagram.com/access_token?' . http_build_query([
        'grant_type'    => 'ig_exchange_token',
        'client_secret' => $igSecret,
        'access_token'  => $shortToken,
    ])
);

$longToken = (string)($longData['access_token'] ?? $shortToken);
$expiresIn = (int)($longData['expires_in'] ?? (60 * 24 * 3600)); // default 60d
ig_log('Got long-lived token, expires_in=' . $expiresIn);

// ── Step 3: Fetch IG username for display ─────────────────────────────────────
[, , $meData] = ig_get(
    'https://graph.instagram.com/v22.0/me?' . http_build_query([
        'fields'       => 'user_id,username',
        'access_token' => $longToken,
    ])
);
$igUsername = (string)($meData['username'] ?? '');
ig_log('IG username=' . $igUsername);

// ── Step 4: Save to DB ────────────────────────────────────────────────────────
$admin_id   = (int)($_SESSION['admin_id']        ?? 0);
$company_id = (int)($_SESSION['oauth_company_id'] ?? 0);
unset($_SESSION['oauth_company_id']);
if ($admin_id) {
    $dbFile = __DIR__ . '/../dbconnect_hdb.php';
    if (file_exists($dbFile)) {
        include $dbFile; // provides $conn

        $expiry = date('Y-m-d H:i:s', time() + $expiresIn);
        $now    = date('Y-m-d H:i:s');

        // Wipe any stale instagram row before writing fresh.
        mysqli_query($conn, "DELETE FROM hdb_oauth_tokens WHERE admin_id=$admin_id AND company_id=$company_id AND platform='instagram'");

        $tokenE  = mysqli_real_escape_string($conn, $longToken);
        $idE     = mysqli_real_escape_string($conn, substr($igUserId, 0, 100));
        $nameE   = mysqli_real_escape_string($conn, substr($igUsername ?: ('ig_' . $igUserId), 0, 200));
        $expE    = mysqli_real_escape_string($conn, $expiry);
        $nowE    = mysqli_real_escape_string($conn, $now);

        mysqli_query($conn,
            "INSERT INTO hdb_oauth_tokens
                 (company_id,admin_id,platform,access_token,channel_id,channel_name,token_expiry,created_at,updated_at)
             VALUES ($company_id,$admin_id,'instagram','$tokenE','$idE','$nameE','$expE','$nowE','$nowE')
             ON DUPLICATE KEY UPDATE
                 company_id=$company_id, access_token='$tokenE', channel_id='$idE', channel_name='$nameE',
                 token_expiry='$expE', updated_at='$nowE'"
        );
        ig_log("admin=$admin_id company=$company_id instagram token saved to DB");
    }
} else {
    ig_log('Warning: no admin_id in session — DB save skipped');
}

// ── Step 5: Redirect back to scheduler ────────────────────────────────────────
$returnUrl = $_SESSION['ig_oauth_return'] ?? '../vizard_scheduler.php?ig_connected=1';
unset($_SESSION['ig_oauth_return']);

header('Location: ' . $returnUrl);
exit;
