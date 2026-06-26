<?php
// payment_success.php — Post-payment confirmation landing page
// Place in: /home/syjy0p3q5yjb/public_html/videovizard.com/payment_success.php

session_start();
require_once 'dbconnect_hdb.php';
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$urow = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT plan_type, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$plan          = $urow['plan_type']      ?? 'free_trial';
$credit_balance = (int)($urow['credit_balance'] ?? 0);
$plan_label    = $plan === 'personal' ? 'Personal' : ($plan === 'agency' ? 'Agency' : ucfirst($plan));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to VideoVizard <?= htmlspecialchars($plan_label) ?>!</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',-apple-system,sans-serif;background:linear-gradient(135deg,#0f2a44,#1a4a7a);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.card{background:#fff;border-radius:24px;max-width:480px;width:100%;padding:48px 40px;text-align:center;box-shadow:0 32px 80px rgba(0,0,0,0.35);}
.ico{font-size:56px;margin-bottom:20px;}
h1{font-size:26px;font-weight:800;color:#0f2a44;margin-bottom:12px;}
p{font-size:15px;color:#64748b;line-height:1.7;margin-bottom:24px;}
.credits-pill{display:inline-flex;align-items:center;gap:8px;background:#d1fae5;color:#065f46;border-radius:40px;padding:10px 20px;font-size:14px;font-weight:700;margin-bottom:32px;}
.btn{display:inline-block;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;padding:16px 32px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;box-shadow:0 4px 16px rgba(59,130,246,.35);}
.btn:hover{opacity:.9;}
.note{font-size:12px;color:#94a3b8;margin-top:20px;}
</style>
</head>
<body>
<div class="card">
    <div class="ico">🎉</div>
    <h1>You're on the <?= htmlspecialchars($plan_label) ?> plan!</h1>
    <p>Your payment was successful and your credits have been added. Time to create some amazing videos.</p>
    <div class="credits-pill">
        ✓ <?= $credit_balance ?> credits available
    </div>
    <br>
    <a href="/vizard_scriptgen.php" class="btn">Start Creating →</a>
    <p class="note">Manage your subscription anytime from the <a href="/pricing" style="color:#3b82f6;">Billing Portal</a>.</p>
</div>
</body>
</html>
