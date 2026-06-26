<?php
session_start();
include 'dbconnect_hdb.php';

$admin_id   = (int)($_SESSION['admin_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

echo "<pre>";
echo "SESSION admin_id:   $admin_id\n";
echo "SESSION company_id: $company_id\n\n";

// What companies does this admin have?
$res = mysqli_query($conn, "SELECT id, companyname FROM hdb_companies WHERE admin_id = $admin_id");
echo "=== hdb_companies for admin $admin_id ===\n";
while ($r = mysqli_fetch_assoc($res)) {
    echo "  id={$r['id']}  name={$r['companyname']}\n";
}

// What campaigns exist for this admin regardless of company?
$res2 = mysqli_query($conn, "SELECT id, campaign_name, company_id, admin_id FROM hdb_campaigns WHERE admin_id = $admin_id");
echo "\n=== hdb_campaigns for admin $admin_id (all companies) ===\n";
$total = 0;
while ($r = mysqli_fetch_assoc($res2)) {
    echo "  camp_id={$r['id']}  company_id={$r['company_id']}  name={$r['campaign_name']}\n";
    $total++;
}
if (!$total) echo "  (none found)\n";

// What does the exact query return?
$res3 = mysqli_query($conn, "SELECT COUNT(*) as total FROM hdb_campaigns WHERE admin_id = $admin_id AND company_id = $company_id");
$row3 = mysqli_fetch_assoc($res3);
echo "\n=== COUNT with admin=$admin_id AND company_id=$company_id ===\n";
echo "  Result: {$row3['total']} rows\n";

echo "</pre>";
