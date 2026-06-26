<?php
// ── impersonate.php ───────────────────────────────────────────
// Admin clicks "Login As" → token generated → opens this page in new tab
// Validates token, creates a client session, redirects to dashboard.php
session_start();

require_once __DIR__ . '/dbconnect.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    die('Invalid request.');
}

$safe  = mysqli_real_escape_string($conn, $token);
$now   = date('Y-m-d H:i:s');

// Find client by token, not expired
$cl = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT c.*, u.user_name, u.firstname, u.lastname, u.email_id,
            u.role, u.level_name, u.plan_type, u.forward_to, u.id AS user_id
     FROM cts_clients c
     JOIN cts_users u ON u.client_id = c.id
     WHERE c.impersonate_token = '$safe'
       AND c.impersonate_expires > '$now'
     LIMIT 1"));

if (!$cl) {
    ?><!DOCTYPE html>
    <html><head><title>Link Expired</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f0e8;}
    .box{background:#fff;padding:40px;border-radius:16px;text-align:center;max-width:380px;}
    h2{color:#dc2626;margin-bottom:8px;}p{color:#64748b;font-size:14px;}
    a{color:#1a7a6e;font-weight:600;}</style></head>
    <body><div class="box">
      <h2>⚠ Link Expired</h2>
      <p>This impersonation link has expired or already been used. Links are valid for 15 minutes.</p>
      <p style="margin-top:16px;"><a href="admin/dashboard.php">← Back to Admin</a></p>
    </div></body></html>
    <?php
    exit;
}

// Clear the token so it can't be reused
mysqli_query($conn,
    "UPDATE cts_clients
     SET impersonate_token=NULL, impersonate_expires=NULL
     WHERE id={$cl['id']}");

// Store the original admin session so we can restore it later
$_SESSION['admin_backup'] = [
    'cts_user_id'   => $_SESSION['cts_user_id']   ?? null,
    'cts_user_name' => $_SESSION['cts_user_name'] ?? null,
    'cts_firstname' => $_SESSION['cts_firstname'] ?? null,
    'cts_lastname'  => $_SESSION['cts_lastname']  ?? null,
    'cts_role'      => $_SESSION['cts_role']      ?? null,
    'cts_level'     => $_SESSION['cts_level']     ?? null,
    'cts_client_id' => $_SESSION['cts_client_id'] ?? null,
    'cts_plan'      => $_SESSION['cts_plan']      ?? null,
    'forward_to'    => $_SESSION['forward_to']    ?? null,
];

// Set client session
session_regenerate_id(true);
$_SESSION['cts_user_id']    = (int)$cl['user_id'];
$_SESSION['cts_user_name']  = $cl['user_name'];
$_SESSION['cts_firstname']  = $cl['firstname'];
$_SESSION['cts_lastname']   = $cl['lastname'];
$_SESSION['cts_role']       = 'client';
$_SESSION['cts_level']      = 'client';
$_SESSION['cts_client_id']  = (int)$cl['id'];
$_SESSION['cts_plan']       = $cl['plan_type'];
$_SESSION['forward_to']     = 'dashboard.php';
$_SESSION['is_impersonating'] = true;
$_SESSION['impersonated_company'] = $cl['company_name'];

header('Location: dashboard.php');
exit;
