<?php
// ── logout.php ────────────────────────────────────────────────
session_start();
session_unset();
session_destroy();

// Clear the session cookie from the browser
setcookie(
    session_name(),
    '',
    [
        'expires'  => time() - 3600,  // set to past = delete it
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,           // set false if not on HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]
);

header("Location: login.php");
exit;