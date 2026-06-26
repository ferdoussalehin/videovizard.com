<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['podcasts' => [], 'error' => 'Not authenticated']);
    exit;
}
include 'dbconnect_hdb.php';

$admin_id    = (int)$_SESSION['admin_id'];
$company_id  = (int)($_GET['company_id'] ?? $_SESSION['company_id'] ?? 0);
$campaign_id = (int)($_GET['campaign_id'] ?? 0);

if (!$admin_id || !$campaign_id) {
    echo json_encode(['podcasts' => [], 'error' => 'Missing parameters']);
    exit;
}

$company_filter = $company_id ? "AND p.company_id = $company_id" : "";

$sql = "SELECT
            p.id,
            p.title,
            p.video_status,
            p.internal_status,
            p.lang_code,
            p.thumbnail,
            p.campaign_id,
            p.archived_flag,
            p.created_date
        FROM hdb_podcasts p
        WHERE p.campaign_id = $campaign_id
          AND p.admin_id    = $admin_id
          $company_filter
        ORDER BY p.id DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['podcasts' => [], 'error' => mysqli_error($conn)]);
    exit;
}

$podcasts = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Normalise internal_status: blank/null = draft
    if (empty($row['internal_status']) || $row['internal_status'] === 'draft') {
        $row['internal_status'] = 'draft';
    } else {
        $row['internal_status'] = 'ready';
    }
    $podcasts[] = $row;
}

echo json_encode(['podcasts' => $podcasts, 'count' => count($podcasts)]);
