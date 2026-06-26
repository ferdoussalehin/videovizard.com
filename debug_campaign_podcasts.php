<?php
session_start();
include 'dbconnect_hdb.php';

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)$_SESSION['company_id'];

echo "<pre>";

// Show all podcasts for this admin with their campaign_id
$res = mysqli_query($conn,
    "SELECT id, title, campaign_id, company_id, admin_id, video_status
     FROM hdb_podcasts
     WHERE admin_id = $admin_id
     ORDER BY campaign_id, id");

echo "=== ALL hdb_podcasts for admin=$admin_id ===\n";
$total = 0;
while ($r = mysqli_fetch_assoc($res)) {
    echo "  podcast_id={$r['id']}  campaign_id={$r['campaign_id']}  company_id={$r['company_id']}  status={$r['video_status']}  title=" . substr($r['title'],0,40) . "\n";
    $total++;
}
echo "Total: $total\n\n";

// Check column names in hdb_podcasts
$res2 = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcasts");
echo "=== hdb_podcasts COLUMNS ===\n";
while ($r = mysqli_fetch_assoc($res2)) {
    echo "  {$r['Field']} ({$r['Type']})\n";
}

echo "</pre>";
