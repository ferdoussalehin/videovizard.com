<?php
// ── auth_check.php ────────────────────────────────────────────
// Include at the top of every protected page.
// Usage:
//   $required_role = 'admin';   // optional — restrict to a role
//   require_once __DIR__ . '/auth_check.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['cts_user_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: /login.php?next=' . $redirect);
    exit;
}

// Optional role check — set $required_role before including this file
if (!empty($required_role)) {
    $allowed = is_array($required_role) ? $required_role : [$required_role];
    if (!in_array($_SESSION['cts_role'] ?? '', $allowed)) {
        header('Location: /login.php?error=no_permission');
        exit;
    }
}

// Convenience variables available on every page after include
$AUTH_USER_ID   = (int)$_SESSION['cts_user_id'];
$AUTH_FIRSTNAME = $_SESSION['cts_firstname'] ?? '';
$AUTH_LASTNAME  = $_SESSION['cts_lastname']  ?? '';
$AUTH_ROLE      = $_SESSION['cts_role']      ?? '';
$AUTH_LEVEL     = $_SESSION['cts_level']     ?? '';
$AUTH_CLIENT_ID = (int)($_SESSION['cts_client_id'] ?? 0);
$AUTH_PLAN      = $_SESSION['cts_plan']      ?? '';
$AUTH_INITIALS  = strtoupper(substr($AUTH_FIRSTNAME, 0, 1) . substr($AUTH_LASTNAME, 0, 1));
$AUTH_FULLNAME  = trim($AUTH_FIRSTNAME . ' ' . $AUTH_LASTNAME);
