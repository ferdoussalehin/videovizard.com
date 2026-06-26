<?php
// ============================================================
// session_config.php — Include this at the TOP of every page
// Keeps users logged in for 1 year unless they log out
// ============================================================

define('SESSION_LIFETIME', 365 * 24 * 60 * 60); // 1 year in seconds

// Detect whether THIS request is over HTTPS. The `secure` cookie flag must
// match the scheme: a `secure` cookie set over plain HTTP is silently
// discarded by the browser, which breaks sessions (login loops back to
// login.php). This auto-corrects if SSL is enabled later.
define('COOKIE_SECURE', (
       (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
));

if (session_status() === PHP_SESSION_NONE) {

    // Tell PHP's garbage collector to keep sessions for 1 year
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

    // Set the cookie to last 1 year in the browser
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',            // leave blank = current domain
        'secure'   => COOKIE_SECURE, // only mark secure when actually on HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

// ── Refresh the cookie on every page load ──────────────────
// This is the key fix: every page visit resets the cookie timer
// so the user stays logged in as long as they visit within 1 year
if (isset($_SESSION['user']) || isset($_SESSION['admin_id'])) {
    setcookie(
        session_name(),
        session_id(),
        [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => COOKIE_SECURE,   // match the request scheme
            'httponly' => true,
            'samesite' => 'Strict',
        ]
    );
    // Update last activity so we know the session is alive
    $_SESSION['last_activity'] = time();
}
