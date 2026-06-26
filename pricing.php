<?php
// pricing.php — Router: redirects to the correct pricing page based on user's plan
session_start();
require_once 'dbconnect_hdb.php';
require_once 'config.php';

$is_logged_in = isset($_SESSION['admin_id']);
$admin_id     = (int)($_SESSION['admin_id'] ?? 0);
$current_plan = 'free_trial';

if ($is_logged_in && $admin_id) {
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT plan_type FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $current_plan = $urow['plan_type'] ?? 'free_trial';
}

// Route to the correct pricing page
if ($current_plan == 'agency') {
    header('Location: pricing_agency.php');
    exit;
} else if ($current_plan == 'personal') {
    header('Location: pricing_personal.php');
    exit;
} else {
    // free_trial or anything else
    header('Location: pricing_free_trial.php');
    exit;
}
