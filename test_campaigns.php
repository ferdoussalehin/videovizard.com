<?php
session_start();
include 'dbconnect_hdb.php';

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

$result = mysqli_query($conn,
    "SELECT id, campaign_name, niche, category, goal, reel_type,
            languages, total_videos, status, created_at, scheduled_at
     FROM hdb_campaigns
     WHERE admin_id = $admin_id AND company_id = $company_id
     ORDER BY COALESCE(scheduled_at, created_at) DESC"
);

$campaigns = [];
while ($row = mysqli_fetch_assoc($result)) $campaigns[] = $row;

echo "<pre>";
echo "admin_id=$admin_id company_id=$company_id\n";
echo "Campaigns found: " . count($campaigns) . "\n\n";
echo "RAW JSON OUTPUT:\n";
$json = json_encode(['success' => true, 'campaigns' => $campaigns]);
echo htmlspecialchars($json);
echo "\n\njson_last_error: " . json_last_error() . " (" . json_last_error_msg() . ")\n";
echo "</pre>";
